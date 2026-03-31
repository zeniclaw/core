<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCustomAgentDocumentJob;
use App\Models\CustomAgentDocument;
use App\Models\CustomAgentShare;
use App\Models\CustomAgentSkill;
use App\Models\CustomAgentScript;
use App\Services\AgentContext;
use App\Services\LLMClient;
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
        $credentials = $customAgent->credentials()->orderBy('key')->get();

        return view('partner.portal', compact('share', 'customAgent', 'documents', 'skills', 'scripts', 'credentials'));
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

    /**
     * Poll progress of current agent processing.
     */
    public function progress(string $token)
    {
        $share = CustomAgentShare::where('token', $token)->first();
        if (!$share || !$share->isValid()) {
            return response()->json(['status' => 'idle']);
        }

        $sessionKey = 'partner-' . $share->id;
        $cache = \Illuminate\Support\Facades\Cache::getFacadeRoot();

        // Check if there's a timed progress running
        $startTime = $cache->get("agent_progress:{$sessionKey}:start");
        if ($startTime) {
            $elapsed = time() - $startTime;
            $stepLabel = $cache->get("agent_progress:{$sessionKey}:label", 'Execution...');

            // Find the right phase
            $phases = [0,3,6,10,15,20,30,45,60,90];
            $phase = "En cours...";
            foreach ($phases as $sec) {
                if ($elapsed >= $sec) {
                    $phase = $cache->get("agent_progress:{$sessionKey}:phase:{$sec}", $phase);
                }
            }

            return response()->json([
                'status' => 'skill',
                'step' => $stepLabel,
                'detail' => "{$phase} ({$elapsed}s)",
            ]);
        }

        $progress = $cache->get("agent_progress:{$sessionKey}", [
            'status' => 'idle',
            'step' => '',
            'detail' => '',
        ]);

        return response()->json($progress);
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
     * Execute a script in a sandboxed environment and return output.
     */
    public function runScript(Request $request, string $token, CustomAgentScript $script)
    {
        $share = $this->resolveShare($token);
        if ($script->custom_agent_id !== $share->custom_agent_id) abort(403);

        if (!$script->is_active) {
            return response()->json(['error' => 'Script inactif'], 422);
        }

        $timeout = min((int) $request->input('timeout', 300), 3600);
        $args = $request->input('args', '') ?? '';

        $cmd = match ($script->language) {
            'python' => 'python3',
            'php' => 'php',
            'bash' => 'bash',
            'node' => 'node',
            default => null,
        };

        if (!$cmd) {
            return response()->json(['error' => 'Langage non supporté'], 422);
        }

        // Write script to temp file
        $ext = match ($script->language) {
            'python' => '.py', 'php' => '.php', 'bash' => '.sh', 'node' => '.js', default => '.txt',
        };
        $tmpDir = '/tmp/zscript_' . uniqid();
        @mkdir($tmpDir, 0755, true);
        $tmpFile = $tmpDir . '/script' . $ext;
        file_put_contents($tmpFile, $script->code);
        chmod($tmpFile, 0755);

        // Detect if script needs host network (nmap, netifaces, socket scanning)
        $needsHostNetwork = (bool) preg_match('/\b(nmap|netifaces|scapy|socket\.connect|ping)\b/', $script->code);

        if ($needsHostNetwork) {
            // Use storage dir (volume-mounted, visible from host) for temp files
            $storageTmpDir = storage_path('app/tmp_scripts/' . uniqid());
            @mkdir($storageTmpDir, 0755, true);
            file_put_contents($storageTmpDir . '/script' . $ext, $script->code);
            chmod($storageTmpDir . '/script' . $ext, 0755);
            \Illuminate\Support\Facades\Process::run("rm -rf {$tmpDir}");
            return $this->runScriptInHostNetwork($script, $storageTmpDir, $storageTmpDir . '/script' . $ext, $cmd, $args, $timeout);
        }

        // Auto-install dependencies before execution
        $this->installDependencies($script->language, $script->code, $tmpDir);

        // Use venv python if available
        if ($script->language === 'python' && file_exists($tmpDir . '/venv/bin/python')) {
            $cmd = $tmpDir . '/venv/bin/python';
        }

        try {
            $result = \Illuminate\Support\Facades\Process::timeout($timeout)
                ->path($tmpDir)
                ->env(['SCRIPT_ARGS' => $args, 'NODE_PATH' => $tmpDir . '/node_modules'])
                ->run("{$cmd} {$tmpFile} {$args}");

            $output = mb_substr($result->output(), 0, 10000);
            $analysis = $result->successful() ? $this->analyzeScriptOutput($output, $script) : null;

            return response()->json([
                'success' => $result->successful(),
                'exit_code' => $result->exitCode(),
                'output' => $output,
                'error_output' => mb_substr($result->errorOutput(), 0, 5000),
                'duration_ms' => null,
                'ai_analysis' => $analysis,
            ]);
        } catch (\Illuminate\Process\Exceptions\ProcessTimedOutException $e) {
            return response()->json([
                'success' => false,
                'exit_code' => -1,
                'output' => '',
                'error_output' => "Timeout après {$timeout}s",
                'duration_ms' => $timeout * 1000,
            ]);
        } finally {
            // Clean up temp directory
            \Illuminate\Support\Facades\Process::run("rm -rf {$tmpDir}");
        }
    }

    /**
     * Execute a script with live SSE streaming output.
     */
    public function runScriptStream(Request $request, string $token, CustomAgentScript $script)
    {
        $share = $this->resolveShare($token);
        if ($script->custom_agent_id !== $share->custom_agent_id) abort(403);
        if (!$script->is_active) abort(422, 'Script inactif');

        $timeout = min((int) $request->input('timeout', 300), 3600);
        $args = $request->input('args', '') ?? '';

        $cmd = match ($script->language) {
            'python' => 'python3', 'php' => 'php', 'bash' => 'bash', 'node' => 'node', default => null,
        };
        if (!$cmd) abort(422, 'Langage non supporté');

        $ext = match ($script->language) {
            'python' => '.py', 'php' => '.php', 'bash' => '.sh', 'node' => '.js', default => '.txt',
        };

        $needsHostNetwork = (bool) preg_match('/\b(nmap|netifaces|scapy|socket\.connect|ping)\b/', $script->code);

        return new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($script, $cmd, $ext, $args, $timeout, $needsHostNetwork) {
            if (ob_get_level()) ob_end_clean();

            $sendSSE = function (string $event, $data) {
                echo "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            };

            $sendSSE('status', ['message' => "Preparation du script {$script->language}..."]);

            if ($needsHostNetwork) {
                $sendSSE('status', ['message' => 'Mode reseau hote detecte — lancement dans un container dedie...']);
                $storageTmpDir = storage_path('app/tmp_scripts/' . uniqid());
                @mkdir($storageTmpDir, 0755, true);
                file_put_contents($storageTmpDir . '/script' . $ext, $script->code);
                chmod($storageTmpDir . '/script' . $ext, 0755);

                // Requirements
                preg_match_all('/^\s*(?:import|from)\s+([a-zA-Z_][a-zA-Z0-9_]*)/m', $script->code, $matches);
                $imports = array_unique($matches[1] ?? []);
                $stdlib = ['os', 'sys', 'json', 'csv', 'datetime', 'time', 'math', 'random', 're',
                    'pathlib', 'collections', 'itertools', 'functools', 'io', 'string', 'typing',
                    'subprocess', 'shutil', 'glob', 'tempfile', 'hashlib', 'base64', 'urllib',
                    'http', 'socket', 'ssl', 'concurrent', 'threading', 'argparse', 'struct',
                ];
                $nameMap = ['cv2' => 'opencv-python', 'PIL' => 'Pillow', 'sklearn' => 'scikit-learn',
                    'bs4' => 'beautifulsoup4', 'yaml' => 'pyyaml', 'nmap' => 'python-nmap',
                    'dotenv' => 'python-dotenv', 'dateutil' => 'python-dateutil',
                ];
                $packages = array_map(fn($p) => $nameMap[$p] ?? $p, array_diff($imports, $stdlib));
                file_put_contents($storageTmpDir . '/requirements.txt', implode("\n", $packages));

                $storageBase = storage_path();
                $relativePath = str_replace($storageBase, '', $storageTmpDir);
                $runtime = \Illuminate\Support\Facades\Process::run('which podman')->successful() ? 'podman' : 'docker';
                $inspectCmd = "{$runtime} inspect zeniclaw_app --format '{{ range .Mounts }}{{ if eq .Destination \"/var/www/html/storage\" }}{{ .Source }}{{ end }}{{ end }}'";
                $volumeSource = trim(shell_exec($inspectCmd) ?? '');
                $hostDir = $volumeSource ? rtrim($volumeSource, '/') . $relativePath : $storageTmpDir;

                $image = 'python:3-slim';
                $sendSSE('status', ['message' => 'Installation des dependances systeme (nmap, gcc)...']);

                $installCmd = 'echo ">>> Installation systeme..." && apt-get update -qq && apt-get install -y -qq nmap gcc python3-dev iproute2 iputils-ping net-tools > /dev/null 2>&1 && echo ">>> OK"; '
                    . (count($packages) > 0 ? 'echo ">>> Installation packages Python: ' . implode(', ', $packages) . '..." && pip install --no-cache-dir -q -r /work/requirements.txt && echo ">>> Dependances OK"; ' : '');

                $fullCmd = sprintf(
                    '%s run --rm --network=host --privileged --cap-add=NET_RAW --cap-add=NET_ADMIN -v %s:/work %s bash -c %s',
                    $runtime,
                    escapeshellarg($hostDir),
                    escapeshellarg($image),
                    escapeshellarg("cd /work && {$installCmd}echo '>>> Lancement du script...' && python3 -u /work/script{$ext} {$args}")
                );
                $cleanupDir = $storageTmpDir;
            } else {
                $tmpDir = '/tmp/zscript_' . uniqid();
                @mkdir($tmpDir, 0755, true);
                $tmpFile = $tmpDir . '/script' . $ext;
                file_put_contents($tmpFile, $script->code);
                chmod($tmpFile, 0755);

                $this->installDependencies($script->language, $script->code, $tmpDir);

                if ($script->language === 'python' && file_exists($tmpDir . '/venv/bin/python')) {
                    $cmd = $tmpDir . '/venv/bin/python';
                }
                $fullCmd = "{$cmd} -u {$tmpFile} {$args}";
                $cleanupDir = $tmpDir;
            }

            $sendSSE('status', ['message' => 'Execution...']);

            // Stream with proc_open
            $process = proc_open($fullCmd, [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            if (!is_resource($process)) {
                $sendSSE('error', ['message' => 'Impossible de lancer le script']);
                $sendSSE('done', ['exit_code' => -1]);
                return;
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $outputBuffer = '';
            $startTime = time();
            while (true) {
                $stdout = fgets($pipes[1]);
                $stderr = fgets($pipes[2]);

                if ($stdout !== false && $stdout !== '') {
                    $sendSSE('stdout', ['text' => $stdout]);
                    $outputBuffer .= $stdout;
                }
                if ($stderr !== false && $stderr !== '') {
                    $sendSSE('stderr', ['text' => $stderr]);
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    // Drain remaining output
                    while (($line = fgets($pipes[1])) !== false) { $sendSSE('stdout', ['text' => $line]); $outputBuffer .= $line; }
                    while (($line = fgets($pipes[2])) !== false) $sendSSE('stderr', ['text' => $line]);
                    break;
                }

                if ((time() - $startTime) > $timeout) {
                    proc_terminate($process, 9);
                    $sendSSE('error', ['message' => "Timeout après {$timeout}s"]);
                    break;
                }

                usleep(50000); // 50ms
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $sendSSE('done', ['exit_code' => $exitCode]);

            // AI analysis of successful output
            if ($exitCode === 0 && mb_strlen(trim($outputBuffer)) >= 20) {
                $sendSSE('status', ['message' => 'Analyse IA en cours...']);
                $analysis = $this->analyzeScriptOutput(mb_substr($outputBuffer, 0, 10000), $script);
                if ($analysis) {
                    $sendSSE('analysis', ['text' => $analysis]);
                }
            }

            // Cleanup
            if (!empty($cleanupDir)) {
                \Illuminate\Support\Facades\Process::run("rm -rf {$cleanupDir}");
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Run script in a temporary container with host network access.
     */
    private function runScriptInHostNetwork($script, string $tmpDir, string $tmpFile, string $cmd, string $args, int $timeout)
    {
        // Detect container runtime (inside container, docker CLI talks to podman socket)
        $runtime = 'docker';
        if (\Illuminate\Support\Facades\Process::run('which podman')->successful()) {
            $runtime = 'podman';
        }

        // Build a requirements.txt from imports
        $reqFile = $tmpDir . '/requirements.txt';
        preg_match_all('/^\s*(?:import|from)\s+([a-zA-Z_][a-zA-Z0-9_]*)/m', $script->code, $matches);
        $imports = array_unique($matches[1] ?? []);
        $stdlib = ['os', 'sys', 'json', 'csv', 'datetime', 'time', 'math', 'random', 're',
            'pathlib', 'collections', 'itertools', 'functools', 'io', 'string', 'typing',
            'subprocess', 'shutil', 'glob', 'tempfile', 'hashlib', 'base64', 'urllib',
            'http', 'socket', 'ssl', 'concurrent', 'threading', 'argparse', 'struct',
        ];
        $nameMap = ['cv2' => 'opencv-python', 'PIL' => 'Pillow', 'sklearn' => 'scikit-learn',
            'bs4' => 'beautifulsoup4', 'yaml' => 'pyyaml', 'nmap' => 'python-nmap',
            'dotenv' => 'python-dotenv', 'dateutil' => 'python-dateutil',
        ];
        $packages = array_map(fn($p) => $nameMap[$p] ?? $p, array_diff($imports, $stdlib));
        file_put_contents($reqFile, implode("\n", $packages));

        // Build the docker/podman run command
        $image = 'python:3-slim';
        $installCmd = 'apt-get update -qq && apt-get install -y -qq nmap gcc python3-dev iproute2 iputils-ping net-tools > /dev/null 2>&1; '
            . (count($packages) > 0 ? 'pip install --no-cache-dir -r /work/requirements.txt; ' : '');

        // Resolve host path: storage volume is mounted from host
        // Container path: /var/www/html/storage/... -> Host: volume source + relative path
        $storageBase = storage_path();
        $relativePath = str_replace($storageBase, '', $tmpDir);
        $inspectCmd = "{$runtime} inspect zeniclaw_app --format '{{ range .Mounts }}{{ if eq .Destination \"/var/www/html/storage\" }}{{ .Source }}{{ end }}{{ end }}'";
        $volumeSource = trim(shell_exec($inspectCmd) ?? '');
        $hostDir = $volumeSource ? rtrim($volumeSource, '/') . $relativePath : $tmpDir;

        $containerCmd = sprintf(
            '%s run --rm --network=host --privileged --cap-add=NET_RAW --cap-add=NET_ADMIN -v %s:/work %s bash -c %s',
            $runtime,
            escapeshellarg($hostDir),
            escapeshellarg($image),
            escapeshellarg("cd /work && {$installCmd}python3 /work/script.py {$args}")
        );

        try {
            $result = \Illuminate\Support\Facades\Process::timeout($timeout + 60) // extra time for pip install
                ->run($containerCmd);

            $output = mb_substr($result->output(), 0, 10000);
            $analysis = $result->successful() ? $this->analyzeScriptOutput($output, $script) : null;

            return response()->json([
                'success' => $result->successful(),
                'exit_code' => $result->exitCode(),
                'output' => $output,
                'error_output' => mb_substr($result->errorOutput(), 0, 5000),
                'duration_ms' => null,
                'ai_analysis' => $analysis,
            ]);
        } catch (\Illuminate\Process\Exceptions\ProcessTimedOutException $e) {
            return response()->json([
                'success' => false,
                'exit_code' => -1,
                'output' => '',
                'error_output' => "Timeout après {$timeout}s",
                'duration_ms' => $timeout * 1000,
            ]);
        } finally {
            \Illuminate\Support\Facades\Process::run("rm -rf {$tmpDir}");
        }
    }

    /**
     * Analyze script output with AI and return a summary.
     */
    private function analyzeScriptOutput(string $output, CustomAgentScript $script): ?string
    {
        if (mb_strlen(trim($output)) < 20) {
            return null;
        }

        try {
            $claude = new LLMClient();
            $prompt = "Tu es un expert en analyse de resultats de scripts. "
                . "Voici la sortie du script \"{$script->name}\" ({$script->language}):\n\n"
                . "```\n{$output}\n```\n\n"
                . "Fais un compte rendu concis et utile en francais:\n"
                . "- Resume les resultats cles\n"
                . "- Signale tout ce qui est notable, anormal ou merite attention\n"
                . "- Donne des recommandations si pertinent\n"
                . "- Utilise des emojis pour la lisibilite\n"
                . "Reponds directement, sans introduction.";

            $response = $claude->chat($prompt, model: 'claude-sonnet-4-20250514');

            return $response ?: null;
        } catch (\Throwable $e) {
            Log::warning('AI analysis failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Detect and install dependencies for a script.
     */
    private function installDependencies(string $language, string $code, string $tmpDir): void
    {
        try {
            match ($language) {
                'python' => $this->installPythonDeps($code, $tmpDir),
                'node' => $this->installNodeDeps($code, $tmpDir),
                'php' => null, // PHP extensions are pre-installed
                'bash' => null, // System packages need manual install
                default => null,
            };
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('installDependencies failed: ' . $e->getMessage());
        }
    }

    private function installPythonDeps(string $code, string $tmpDir): void
    {
        // Extract imports from code
        preg_match_all('/^\s*(?:import|from)\s+([a-zA-Z_][a-zA-Z0-9_]*)/m', $code, $matches);
        $imports = array_unique($matches[1] ?? []);

        // Standard library modules to skip
        $stdlib = ['os', 'sys', 'json', 'csv', 'datetime', 'time', 'math', 'random', 're',
            'pathlib', 'collections', 'itertools', 'functools', 'io', 'string', 'typing',
            'subprocess', 'shutil', 'glob', 'tempfile', 'hashlib', 'base64', 'urllib',
            'http', 'socket', 'ssl', 'email', 'html', 'xml', 'logging', 'unittest',
            'argparse', 'configparser', 'sqlite3', 'copy', 'pprint', 'textwrap',
            'struct', 'enum', 'abc', 'contextlib', 'threading', 'multiprocessing',
            'asyncio', 'concurrent', 'queue', 'signal', 'traceback', 'inspect',
            'importlib', 'pkgutil', 'zipfile', 'gzip', 'bz2', 'lzma', 'tarfile',
            'uuid', 'secrets', 'hmac', 'decimal', 'fractions', 'statistics',
            'operator', 'dataclasses', 'heapq', 'bisect', 'array', 'weakref',
            'types', 'warnings', 'platform', 'ctypes', 'codecs', 'locale',
            'gettext', 'unicodedata', 'pdb', 'profile', 'timeit', 'dis',
        ];

        $toInstall = array_diff($imports, $stdlib);
        if (empty($toInstall)) return;

        // Map common import names to pip package names
        $nameMap = [
            'cv2' => 'opencv-python', 'PIL' => 'Pillow', 'sklearn' => 'scikit-learn',
            'bs4' => 'beautifulsoup4', 'yaml' => 'pyyaml', 'dotenv' => 'python-dotenv',
            'gi' => 'PyGObject', 'attr' => 'attrs', 'dateutil' => 'python-dateutil',
        ];

        $packages = array_map(fn($p) => $nameMap[$p] ?? $p, $toInstall);

        // Install to temp directory using pip
        // Create venv and install packages
        $venvDir = $tmpDir . '/venv';
        \Illuminate\Support\Facades\Process::timeout(30)->run("python3 -m venv {$venvDir} 2>&1");
        $pkgList = implode(' ', array_map('escapeshellarg', $packages));
        \Illuminate\Support\Facades\Process::timeout(60)
            ->run("{$venvDir}/bin/pip install {$pkgList} 2>&1");
    }

    private function installNodeDeps(string $code, string $tmpDir): void
    {
        // Extract require/import statements
        preg_match_all('/(?:require\s*\(\s*[\'"]([^\.\/][^\'"]+)[\'"]\s*\)|from\s+[\'"]([^\.\/][^\'"]+)[\'"])/m', $code, $matches);
        $packages = array_unique(array_filter(array_merge($matches[1] ?? [], $matches[2] ?? [])));

        // Built-in Node modules to skip
        $builtins = ['fs', 'path', 'os', 'http', 'https', 'url', 'querystring', 'crypto',
            'stream', 'util', 'events', 'child_process', 'cluster', 'net', 'dns',
            'readline', 'zlib', 'buffer', 'assert', 'tls', 'dgram',
        ];

        $toInstall = array_diff($packages, $builtins);
        if (empty($toInstall)) return;

        $pkgList = implode(' ', array_map('escapeshellarg', $toInstall));
        \Illuminate\Support\Facades\Process::timeout(60)
            ->path($tmpDir)
            ->run("npm install --no-save {$pkgList} 2>&1");
    }

    /**
     * AI-assisted script editing: send instruction + current code, get modified code back.
     */
    public function aiEditScript(Request $request, string $token, CustomAgentScript $script)
    {
        $share = $this->resolveShare($token);
        if ($script->custom_agent_id !== $share->custom_agent_id) abort(403);

        $request->validate(['instruction' => 'required|string|max:2000']);

        $prompt = "Tu es un developpeur expert. Modifie ce script {$script->language} selon l'instruction.\n\n"
            . "INSTRUCTION: {$request->input('instruction')}\n\n"
            . "CODE ACTUEL:\n```{$script->language}\n{$script->code}\n```\n\n"
            . "Reponds UNIQUEMENT avec le code modifie, sans explication, sans ```markdown. Juste le code brut.";

        $claude = new \App\Services\LLMClient();
        $model = \App\Services\ModelResolver::balanced();
        $result = $claude->chat($prompt, $model);

        if (!$result) {
            return response()->json(['error' => 'Erreur IA'], 502);
        }

        // Clean markdown fences if present
        $code = preg_replace('/^```\w*\n?/', '', trim($result));
        $code = preg_replace('/\n?```$/', '', $code);

        return response()->json(['code' => $code]);
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
        $client = new \App\Services\LLMClient();
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

    // ── Credentials ────────────────────────────────────────────

    public function storeCredential(Request $request, string $token)
    {
        $share = $this->resolveShare($token);

        $validated = $request->validate([
            'key' => 'required|string|max:100|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'value' => 'required|string|max:5000',
            'description' => 'nullable|string|max:200',
        ]);

        $customAgent = $share->customAgent;

        // Upsert: update if key exists, create if not
        $cred = $customAgent->credentials()->where('key', $validated['key'])->first();
        if ($cred) {
            $cred->update([
                'value' => $validated['value'],
                'description' => $validated['description'] ?? $cred->description,
            ]);
        } else {
            \App\Models\CustomAgentCredential::create([
                'custom_agent_id' => $customAgent->id,
                'key' => $validated['key'],
                'value' => $validated['value'],
                'description' => $validated['description'] ?? null,
            ]);
        }

        return back()->with('success', "Credential \"{$validated['key']}\" sauvegarde (chiffre AES-256).");
    }

    public function destroyCredential(string $token, \App\Models\CustomAgentCredential $credential)
    {
        $share = $this->resolveShare($token);
        if ($credential->custom_agent_id !== $share->custom_agent_id) abort(403);

        $key = $credential->key;
        $credential->delete();
        return back()->with('success', "Credential \"{$key}\" supprime.");
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
