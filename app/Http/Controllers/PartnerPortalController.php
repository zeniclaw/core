<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCustomAgentDocumentJob;
use App\Models\CustomAgentDocument;
use App\Models\CustomAgentShare;
use App\Models\CustomAgentSkill;
use App\Models\CustomAgentScript;
use App\Services\AgentContext;
use App\Services\CustomAgentRunner;
use App\Services\KnowledgeChunker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PartnerPortalController extends Controller
{
    private function resolveShare(string $token): CustomAgentShare
    {
        $share = CustomAgentShare::where('token', $token)
            ->with(['customAgent.agent', 'customAgent.documents', 'customAgent.skills', 'customAgent.scripts'])
            ->first();

        if (!$share || !$share->isValid()) {
            abort(404, 'Ce lien a expiré ou a été révoqué.');
        }

        $share->recordAccess();
        return $share;
    }

    public function show(string $token)
    {
        $share = $this->resolveShare($token);
        $customAgent = $share->customAgent;
        $customAgent->loadCount(['documents', 'chunks']);
        $documents = $customAgent->documents()->orderByDesc('created_at')->get();
        $skills = $customAgent->skills()->orderByDesc('created_at')->get();
        $scripts = $customAgent->scripts()->orderByDesc('created_at')->get();

        return view('partner.portal', compact('share', 'customAgent', 'documents', 'skills', 'scripts'));
    }

    public function uploadDocument(Request $request, string $token)
    {
        $share = $this->resolveShare($token);
        $customAgent = $share->customAgent;

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
                'source' => 'partenaire: ' . ($share->partner_name ?? 'lien partage'),
                'raw_content' => $request->input('content'),
                'status' => 'pending',
            ]);

            $this->processAsync($document);
            return back()->with('success', 'Document texte ajouté, traitement en cours...');
        }

        if ($type === 'url') {
            $request->validate([
                'url' => 'required|url|max:500',
                'title' => 'nullable|string|max:200',
            ]);

            $url = $request->input('url');
            $text = (new KnowledgeChunker())->extractFromUrl($url);

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

            $this->processAsync($document);
            return back()->with('success', 'URL importée, traitement en cours...');
        }

        // File upload
        $request->validate([
            'file' => 'required|file|max:51200',
            'title' => 'nullable|string|max:200',
        ]);

        $file = $request->file('file');
        $path = $file->store("custom-agents/{$customAgent->id}/docs", 'local');
        $fullPath = storage_path('app/private/' . $path);
        $mime = $file->getMimeType();

        $text = (new KnowledgeChunker())->extractText($fullPath, $mime);

        if (!$text || mb_strlen($text) < 20) {
            @unlink($fullPath);
            return back()->with('error', "Impossible d'extraire le texte. Formats: PDF, TXT, CSV, DOCX.");
        }

        $document = CustomAgentDocument::create([
            'custom_agent_id' => $customAgent->id,
            'title' => $request->input('title') ?: $file->getClientOriginalName(),
            'type' => 'file',
            'source' => $file->getClientOriginalName(),
            'raw_content' => $text,
            'status' => 'pending',
        ]);

        @unlink($fullPath);
        $this->processAsync($document);
        return back()->with('success', 'Fichier importé, traitement en cours...');
    }

    public function chat(Request $request, string $token)
    {
        $share = $this->resolveShare($token);
        $customAgent = $share->customAgent;
        $agent = $customAgent->agent;

        $request->validate(['message' => 'required|string|max:2000']);

        $sessionKey = 'partner-' . $share->id;
        $session = $agent->sessions()->firstOrCreate(
            ['session_key' => $sessionKey],
            ['channel' => 'web', 'peer_id' => $sessionKey, 'last_message_at' => now()],
        );

        $context = new AgentContext(
            agent: $agent,
            session: $session,
            from: $sessionKey,
            senderName: $share->partner_name ?? 'Partenaire',
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

    public function storeSkill(Request $request, string $token)
    {
        $share = $this->resolveShare($token);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'trigger_phrase' => 'nullable|string|max:200',
            'routine' => 'required|string', // JSON string
        ]);

        $routine = json_decode($validated['routine'], true);
        if (!is_array($routine)) {
            return back()->with('error', 'Le format de la routine est invalide (JSON attendu).');
        }

        CustomAgentSkill::create([
            'custom_agent_id' => $share->customAgent->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'trigger_phrase' => $validated['trigger_phrase'] ?? null,
            'routine' => $routine,
            'created_by_share_id' => $share->id,
        ]);

        return back()->with('success', 'Skill créé !');
    }

    public function updateSkill(Request $request, string $token, CustomAgentSkill $skill)
    {
        $share = $this->resolveShare($token);
        if ($skill->custom_agent_id !== $share->custom_agent_id) abort(403);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'trigger_phrase' => 'nullable|string|max:200',
            'routine' => 'required|string',
            'is_active' => 'nullable|boolean',
        ]);

        $routine = json_decode($validated['routine'], true);
        if (!is_array($routine)) {
            return back()->with('error', 'Format JSON invalide.');
        }

        $skill->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'trigger_phrase' => $validated['trigger_phrase'] ?? null,
            'routine' => $routine,
            'is_active' => $validated['is_active'] ?? $skill->is_active,
        ]);

        return back()->with('success', 'Skill mis à jour.');
    }

    public function destroySkill(string $token, CustomAgentSkill $skill)
    {
        $share = $this->resolveShare($token);
        if ($skill->custom_agent_id !== $share->custom_agent_id) abort(403);

        $skill->delete();
        return back()->with('success', 'Skill supprimé.');
    }

    public function storeScript(Request $request, string $token)
    {
        $share = $this->resolveShare($token);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'language' => 'required|in:python,php,bash,node',
            'code' => 'required|string|max:50000',
        ]);

        CustomAgentScript::create([
            'custom_agent_id' => $share->customAgent->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'language' => $validated['language'],
            'code' => $validated['code'],
            'created_by_share_id' => $share->id,
        ]);

        return back()->with('success', 'Script créé !');
    }

    public function updateScript(Request $request, string $token, CustomAgentScript $script)
    {
        $share = $this->resolveShare($token);
        if ($script->custom_agent_id !== $share->custom_agent_id) abort(403);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'language' => 'required|in:python,php,bash,node',
            'code' => 'required|string|max:50000',
            'is_active' => 'nullable|boolean',
        ]);

        $script->update($validated);
        return back()->with('success', 'Script mis à jour.');
    }

    public function destroyScript(string $token, CustomAgentScript $script)
    {
        $share = $this->resolveShare($token);
        if ($script->custom_agent_id !== $share->custom_agent_id) abort(403);

        $script->delete();
        return back()->with('success', 'Script supprimé.');
    }

    /**
     * AI Assistant: help partner create skills/scripts via conversation.
     */
    public function assistCreate(Request $request, string $token)
    {
        $share = $this->resolveShare($token);
        $customAgent = $share->customAgent;

        $request->validate([
            'message' => 'required|string|max:3000',
            'mode' => 'required|in:skill,script',
            'history' => 'nullable|string', // JSON array of previous messages
        ]);

        $mode = $request->input('mode');
        $history = json_decode($request->input('history', '[]'), true) ?: [];

        $systemPrompt = $this->buildAssistantPrompt($mode, $customAgent);

        // Build conversation for the LLM
        $messages = $systemPrompt . "\n\n";
        foreach ($history as $msg) {
            $role = $msg['role'] === 'user' ? 'Utilisateur' : 'Assistant';
            $messages .= "{$role}: {$msg['content']}\n";
        }
        $messages .= "Utilisateur: {$request->input('message')}";

        $model = \App\Services\ModelResolver::fast();
        $client = new \App\Services\AnthropicClient();
        $reply = $client->chat($messages, $model, '', 1500);

        if (!$reply) {
            return response()->json(['reply' => "Erreur de communication avec l'IA. Réessayez."]);
        }

        return response()->json(['reply' => $reply]);
    }

    private function buildAssistantPrompt(string $mode, $customAgent): string
    {
        if ($mode === 'skill') {
            return "Tu es un assistant specialise dans la creation de routines (skills) pour un agent IA nomme \"{$customAgent->name}\".

L'agent a cette description: " . ($customAgent->description ?: 'Agent IA personnalise') . "

Tu dois aider l'utilisateur a creer une routine en lui posant des questions:
1. Quel est l'objectif de la routine ?
2. Quelles etapes doit-elle suivre ?
3. Quel sera le declencheur (phrase pour l'activer) ?

Quand tu as assez d'infos, genere le JSON final EXACTEMENT dans ce format (et RIEN d'autre apres le JSON):
---SKILL_READY---
{
  \"name\": \"Nom de la routine\",
  \"description\": \"Description claire\",
  \"trigger_phrase\": \"phrase declencheur\",
  \"routine\": [{\"type\":\"prompt\",\"content\":\"Instruction pour l'etape 1\"},{\"type\":\"prompt\",\"content\":\"Instruction pour l'etape 2\"}]
}

Guide l'utilisateur etape par etape. Sois concis et pratique. Reponds en francais.";
        }

        return "Tu es un assistant specialise dans la creation de scripts pour un agent IA nomme \"{$customAgent->name}\".

L'agent a cette description: " . ($customAgent->description ?: 'Agent IA personnalise') . "

Tu dois aider l'utilisateur a creer un script en lui posant des questions:
1. Que doit faire le script ?
2. Quel langage prefere-t-il ? (Python, PHP, Bash, Node.js)
3. Quelles donnees en entree/sortie ?

Quand tu as assez d'infos, genere le code final EXACTEMENT dans ce format (et RIEN d'autre apres):
---SCRIPT_READY---
{
  \"name\": \"Nom du script\",
  \"description\": \"Description claire\",
  \"language\": \"python\",
  \"code\": \"# le code ici...\"
}

Guide l'utilisateur etape par etape. Sois concis et pratique. Reponds en francais.";
    }

    private function processAsync(CustomAgentDocument $document): void
    {
        try {
            ProcessCustomAgentDocumentJob::dispatch($document->id);
        } catch (\Throwable $e) {
            Log::warning("Queue unavailable, processing sync: " . $e->getMessage());
            try {
                (new KnowledgeChunker())->processDocument($document);
            } catch (\Throwable $e2) {
                $document->update(['status' => 'failed', 'error_message' => mb_substr($e2->getMessage(), 0, 500)]);
            }
        }
    }
}
