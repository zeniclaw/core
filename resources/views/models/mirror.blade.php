<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ZeniClaw — Miroir Modeles IA</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-4xl mx-auto py-12 px-4">
        <div class="text-center mb-10">
            <h1 class="text-3xl font-bold text-gray-900">Miroir Modeles IA</h1>
            <p class="mt-2 text-gray-600">Modeles Ollama pre-exportes pour installation hors-ligne (proxy/firewall)</p>
        </div>

        @if(empty($models))
            <div class="bg-white rounded-xl shadow-sm border p-8 text-center">
                <p class="text-gray-500 text-lg">Aucun modele exporte pour le moment.</p>
                <p class="text-gray-400 text-sm mt-2">Lancez <code class="bg-gray-100 px-2 py-1 rounded">php artisan ollama:export</code> sur le serveur source.</p>
            </div>
        @else
            <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
                <div class="px-6 py-4 border-b bg-gray-50">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-700">{{ count($models) }} modele(s) disponible(s)</span>
                        <span class="text-xs text-gray-400">Import: <code class="bg-gray-100 px-1 rounded">./import-model.sh URL</code></span>
                    </div>
                </div>

                <div class="divide-y">
                    @foreach($models as $m)
                    <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50 transition-colors">
                        <div>
                            <h3 class="font-semibold text-gray-900">{{ $m['model'] }}</h3>
                            <p class="text-sm text-gray-500">
                                {{ $m['size_mb'] }} MB
                                &middot; {{ $m['layers'] }} couche(s)
                                &middot; Exporte le {{ \Carbon\Carbon::parse($m['exported_at'])->format('d/m/Y H:i') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <a href="{{ route('models.download', $m['slug']) }}"
                               class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                                Telecharger
                            </a>
                            <button onclick="copyImportCmd('{{ url('/models/download/' . $m['slug']) }}', '{{ $m['model'] }}')"
                                    class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition-colors"
                                    title="Copier la commande d'import">
                                Copier cmd
                            </button>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="mt-8 bg-white rounded-xl shadow-sm border p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-3">Installation sur un serveur client</h2>
                <div class="space-y-3 text-sm text-gray-600">
                    <p><strong>Option 1</strong> — Script automatique (telecharge + importe) :</p>
                    <pre class="bg-gray-900 text-green-400 rounded-lg p-4 overflow-x-auto text-xs">curl -fsSL {{ url('/models/import-script') }} | bash -s -- {{ url('/') }}</pre>

                    <p class="mt-4"><strong>Option 2</strong> — Manuel :</p>
                    <pre class="bg-gray-900 text-green-400 rounded-lg p-4 overflow-x-auto text-xs"># Telecharger le modele
curl -o model.tar.gz {{ url('/models/download/' . ($models[0]['slug'] ?? 'MODEL')) }}

# Importer dans Ollama
docker cp model.tar.gz zeniclaw_ollama:/tmp/model.tar.gz
docker exec zeniclaw_ollama tar xzf /tmp/model.tar.gz -C /root/.ollama
docker exec zeniclaw_ollama rm /tmp/model.tar.gz

# Verifier
docker exec zeniclaw_ollama ollama list</pre>
                </div>
            </div>
        @endif
    </div>

    <script>
    function copyImportCmd(url, model) {
        var cmd = 'curl -o model.tar.gz "' + url + '" && '
            + 'docker cp model.tar.gz zeniclaw_ollama:/tmp/model.tar.gz && '
            + 'docker exec zeniclaw_ollama tar xzf /tmp/model.tar.gz -C /root/.ollama && '
            + 'docker exec zeniclaw_ollama rm /tmp/model.tar.gz && '
            + 'echo "' + model + ' imported OK"';
        navigator.clipboard.writeText(cmd);
        alert('Commande copiee !');
    }
    </script>
</body>
</html>
