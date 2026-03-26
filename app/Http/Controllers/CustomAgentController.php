<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCustomAgentDocumentJob;
use App\Models\Agent;
use App\Models\CustomAgent;
use App\Models\CustomAgentChunk;
use App\Models\CustomAgentDocument;
use App\Services\AgentContext;
use App\Services\CustomAgentRunner;
use App\Services\KnowledgeChunker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CustomAgentController extends Controller
{
    /**
     * List all custom agents for the authenticated user's agent.
     */
    public function index(Agent $agent)
    {
        $this->authorizeAgent($agent);

        $customAgents = CustomAgent::where('agent_id', $agent->id)
            ->withCount(['documents', 'chunks'])
            ->orderBy('created_at', 'desc')
            ->get();

        return view('custom-agents.index', compact('agent', 'customAgents'));
    }

    /**
     * Show create form.
     */
    public function create(Agent $agent)
    {
        $this->authorizeAgent($agent);
        return view('custom-agents.create', compact('agent'));
    }

    /**
     * Store a new custom agent.
     */
    public function store(Request $request, Agent $agent)
    {
        $this->authorizeAgent($agent);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'system_prompt' => 'nullable|string|max:5000',
            'avatar' => 'nullable|string|max:10',
            'model' => 'nullable|string|max:100',
        ]);

        $customAgent = CustomAgent::create([
            'agent_id' => $agent->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'system_prompt' => $validated['system_prompt'] ?? null,
            'avatar' => $validated['avatar'] ?? '🤖',
            'model' => $validated['model'] ?? 'default',
        ]);

        return redirect()->route('custom-agents.show', [$agent, $customAgent])
            ->with('success', "Agent \"{$customAgent->name}\" créé ! Ajoutez des documents pour le former.");
    }

    /**
     * Show a custom agent with its documents and test chat.
     */
    public function show(Agent $agent, CustomAgent $customAgent)
    {
        $this->authorizeAgent($agent);
        $this->ensureOwnership($agent, $customAgent);

        $customAgent->loadCount(['documents', 'chunks']);
        $documents = $customAgent->documents()->orderBy('created_at', 'desc')->get();

        return view('custom-agents.show', compact('agent', 'customAgent', 'documents'));
    }

    /**
     * Update custom agent settings.
     */
    public function update(Request $request, Agent $agent, CustomAgent $customAgent)
    {
        $this->authorizeAgent($agent);
        $this->ensureOwnership($agent, $customAgent);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'system_prompt' => 'nullable|string|max:5000',
            'avatar' => 'nullable|string|max:10',
            'model' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $customAgent->update($validated);

        return redirect()->route('custom-agents.show', [$agent, $customAgent])
            ->with('success', 'Agent mis à jour.');
    }

    /**
     * Delete a custom agent and all its data.
     */
    public function destroy(Agent $agent, CustomAgent $customAgent)
    {
        $this->authorizeAgent($agent);
        $this->ensureOwnership($agent, $customAgent);

        $name = $customAgent->name;
        $customAgent->delete(); // Cascades to documents and chunks

        return redirect()->route('custom-agents.index', $agent)
            ->with('success', "Agent \"{$name}\" supprimé.");
    }

    /**
     * Toggle active/inactive state.
     */
    public function toggle(Request $request, Agent $agent, CustomAgent $customAgent)
    {
        $this->authorizeAgent($agent);
        $this->ensureOwnership($agent, $customAgent);

        $customAgent->update(['is_active' => !$customAgent->is_active]);

        return response()->json([
            'is_active' => $customAgent->is_active,
            'message' => $customAgent->is_active ? 'Agent activé' : 'Agent désactivé',
        ]);
    }

    /**
     * Update enabled tool groups.
     */
    public function updateTools(Request $request, Agent $agent, CustomAgent $customAgent)
    {
        $this->authorizeAgent($agent);
        $this->ensureOwnership($agent, $customAgent);

        $validGroups = array_keys(\App\Services\CustomAgentRunner::TOOL_GROUPS);
        $enabledTools = array_values(array_intersect($request->input('enabled_tools', []), $validGroups));

        $customAgent->update(['enabled_tools' => $enabledTools]);

        return redirect()->route('custom-agents.show', [$agent, $customAgent])
            ->with('success', empty($enabledTools)
                ? 'Mode Knowledge only — l\'agent utilise uniquement ses documents.'
                : count($enabledTools) . ' groupe(s) d\'outils activé(s).');
    }

    /**
     * Update access control (allowed peers).
     */
    public function updateAccess(Request $request, Agent $agent, CustomAgent $customAgent)
    {
        $this->authorizeAgent($agent);
        $this->ensureOwnership($agent, $customAgent);

        $raw = $request->input('allowed_peers', '');
        $peers = array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', $raw)),
            fn($p) => !empty($p)
        ));

        $customAgent->update(['allowed_peers' => $peers]);

        return redirect()->route('custom-agents.show', [$agent, $customAgent])
            ->with('success', empty($peers)
                ? 'Acces ouvert a tous.'
                : count($peers) . ' peer(s) autorise(s).');
    }

    // ── Documents ──────────────────────────────────────────────────

    /**
     * Upload a document (file or text) to train the agent.
     */
    public function uploadDocument(Request $request, Agent $agent, CustomAgent $customAgent)
    {
        $this->authorizeAgent($agent);
        $this->ensureOwnership($agent, $customAgent);

        $type = $request->input('type', 'file');

        if ($type === 'text') {
            $request->validate([
                'title' => 'required|string|max:200',
                'content' => 'required|string|min:10',
            ]);

            $document = CustomAgentDocument::create([
                'custom_agent_id' => $customAgent->id,
                'title' => $request->input('title'),
                'type' => 'text',
                'source' => 'saisie manuelle',
                'raw_content' => $request->input('content'),
                'status' => 'pending',
            ]);

            $this->processDocumentAsync($document);

            return back()->with('success', "Document texte ajouté, traitement lancé en arrière-plan...");
        }

        if ($type === 'url') {
            $request->validate([
                'url' => 'required|url|max:500',
                'title' => 'nullable|string|max:200',
            ]);

            $url = $request->input('url');
            $chunker = new KnowledgeChunker();
            $text = $chunker->extractFromUrl($url);

            if (!$text || mb_strlen($text) < 20) {
                return back()->with('error', "Impossible d'extraire le contenu de cette URL.");
            }

            $document = CustomAgentDocument::create([
                'custom_agent_id' => $customAgent->id,
                'title' => $request->input('title') ?: parse_url($url, PHP_URL_HOST),
                'type' => 'url',
                'source' => $url,
                'raw_content' => $text,
                'status' => 'pending',
            ]);

            $this->processDocumentAsync($document);

            return back()->with('success', "Contenu URL importé, traitement lancé en arrière-plan...");
        }

        // File upload
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'title' => 'nullable|string|max:200',
        ]);

        $file = $request->file('file');
        $path = $file->store('custom-agent-docs', 'local');
        $fullPath = storage_path('app/' . $path);
        $mime = $file->getMimeType();

        $chunker = new KnowledgeChunker();
        $text = $chunker->extractText($fullPath, $mime);

        if (!$text || mb_strlen($text) < 20) {
            @unlink($fullPath);
            return back()->with('error', "Impossible d'extraire le texte de ce fichier. Formats supportés : PDF, TXT, CSV, DOCX.");
        }

        $document = CustomAgentDocument::create([
            'custom_agent_id' => $customAgent->id,
            'title' => $request->input('title') ?: $file->getClientOriginalName(),
            'type' => 'file',
            'source' => $file->getClientOriginalName(),
            'raw_content' => $text,
            'status' => 'pending',
        ]);

        // Clean up uploaded file (text is now in DB)
        @unlink($fullPath);

        $this->processDocumentAsync($document);

        return back()->with('success', "Fichier importé, traitement lancé en arrière-plan...");
    }

    /**
     * Delete a training document.
     */
    public function destroyDocument(Agent $agent, CustomAgent $customAgent, CustomAgentDocument $document)
    {
        $this->authorizeAgent($agent);
        $this->ensureOwnership($agent, $customAgent);

        if ($document->custom_agent_id !== $customAgent->id) {
            abort(403);
        }

        $document->delete(); // Cascades to chunks

        return back()->with('success', 'Document supprimé.');
    }

    /**
     * Re-process a failed document.
     */
    public function reprocessDocument(Agent $agent, CustomAgent $customAgent, CustomAgentDocument $document)
    {
        $this->authorizeAgent($agent);
        $this->ensureOwnership($agent, $customAgent);

        if ($document->custom_agent_id !== $customAgent->id) {
            abort(403);
        }

        $this->processDocumentAsync($document);

        return back()->with('success', 'Retraitement lancé en arrière-plan...');
    }

    // ── Test Chat ──────────────────────────────────────────────────

    /**
     * Send a test message to the custom agent (AJAX).
     */
    public function testChat(Request $request, Agent $agent, CustomAgent $customAgent)
    {
        $this->authorizeAgent($agent);
        $this->ensureOwnership($agent, $customAgent);

        $request->validate(['message' => 'required|string|max:2000']);

        $sessionKey = 'web-custom-test-' . $customAgent->id;
        $session = $agent->sessions()->firstOrCreate(
            ['session_key' => $sessionKey],
            ['channel' => 'web', 'peer_id' => $sessionKey, 'last_message_at' => now()],
        );

        $context = new AgentContext(
            agent: $agent,
            session: $session,
            from: $sessionKey,
            senderName: Auth::user()->name ?? 'Test',
            body: $request->input('message'),
            hasMedia: false,
            mediaUrl: null,
            mimetype: null,
            media: null,
        );

        $runner = new CustomAgentRunner($customAgent);
        $result = $runner->handle($context);

        return response()->json([
            'reply' => $result->reply,
            'metadata' => $result->metadata,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Process document: try async (queue), fallback to sync if queue unavailable.
     */
    private function processDocumentAsync(CustomAgentDocument $document): void
    {
        try {
            ProcessCustomAgentDocumentJob::dispatch($document->id);
        } catch (\Throwable $e) {
            // Queue unavailable — process synchronously
            Log::warning("Queue unavailable, processing document sync: " . $e->getMessage());
            try {
                (new KnowledgeChunker())->processDocument($document);
            } catch (\Throwable $e2) {
                Log::error("Document processing failed: " . $e2->getMessage());
                $document->update(['status' => 'failed', 'error_message' => mb_substr($e2->getMessage(), 0, 500)]);
            }
        }
    }

    private function authorizeAgent(Agent $agent): void
    {
        if ($agent->user_id !== Auth::id()) {
            abort(403);
        }
    }

    private function ensureOwnership(Agent $agent, CustomAgent $customAgent): void
    {
        if ($customAgent->agent_id !== $agent->id) {
            abort(404);
        }
    }
}
