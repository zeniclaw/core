@extends('layouts.app')
@section('title', 'Mises à jour')

@section('content')
<div class="max-w-2xl space-y-6">

    {{-- Version card --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">📦 Version</h2>
        <div class="grid grid-cols-2 gap-4">
            <div class="bg-gray-50 rounded-xl p-4 text-center">
                <p class="text-xs text-gray-500 mb-1">Version actuelle</p>
                <p class="text-2xl font-bold text-gray-900">v{{ $currentVersion }}</p>
            </div>
            <div class="bg-indigo-50 rounded-xl p-4 text-center">
                <p class="text-xs text-gray-500 mb-1">Dernière version</p>
                <p class="text-2xl font-bold text-indigo-700">{{ $latestVersion ?: '—' }}</p>
            </div>
        </div>
        @if($upToDate)
        <div class="mt-4 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded-lg text-sm">
            ✅ Vous êtes à jour !
        </div>
        @else
        <div class="mt-4 px-4 py-3 bg-orange-50 border border-orange-200 text-orange-800 rounded-lg text-sm">
            🔄 Mise à jour disponible : {{ $latestVersion }}
        </div>
        @endif
    </div>

    {{-- Changelog --}}
    @if(count($commits))
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">📝 Derniers commits</h2>
        <div class="space-y-3">
            @foreach($commits as $commit)
            <div class="flex items-start gap-3">
                <code class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded font-mono flex-shrink-0">
                    {{ substr($commit['id'], 0, 7) }}
                </code>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-gray-900">{{ $commit['title'] }}</p>
                    <p class="text-xs text-gray-400">{{ $commit['author_name'] }} · {{ \Carbon\Carbon::parse($commit['created_at'])->diffForHumans() }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Update button --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6"
         x-data="{
            updating: false,
            log: '',
            done: false,
            error: false,
            async runUpdate() {
                if (!confirm('Lancer la mise à jour ? L\'application sera brièvement indisponible.')) return;
                this.updating = true;
                this.log = 'Démarrage de la mise à jour...\n';
                this.done = false;
                this.error = false;
                try {
                    const r = await fetch('{{ route('admin.update.run') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json'
                        }
                    });
                    const d = await r.json();
                    if (d.success) {
                        this.log += d.output || '';
                        this.log += '\n✅ ' + d.message + ' (v' + d.version + ')';
                        this.done = true;
                    } else {
                        this.log += '❌ Erreur: ' + d.message;
                        this.error = true;
                    }
                } catch(e) {
                    this.log += '❌ Erreur réseau: ' + e.message;
                    this.error = true;
                }
                this.updating = false;
            }
         }">
        <h2 class="font-semibold text-gray-900 mb-4">🚀 Mettre à jour</h2>

        <button @click="runUpdate()" :disabled="updating"
                class="bg-indigo-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors disabled:opacity-50 flex items-center gap-2">
            <span x-show="!updating">🚀 Mettre à jour vers {{ $latestVersion ?: 'la dernière version' }}</span>
            <span x-show="updating" class="animate-pulse">⏳ Mise à jour en cours...</span>
        </button>

        <div x-show="log" class="mt-4">
            <textarea x-model="log" readonly rows="12"
                      class="w-full px-3 py-3 bg-gray-900 text-green-400 rounded-xl font-mono text-xs border-0 outline-none resize-none"></textarea>
        </div>

        <div x-show="done" class="mt-3 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded-lg text-sm flex items-center justify-between">
            <span>✅ Mise à jour effectuée !</span>
            <button @click="window.location.reload()" class="text-xs bg-green-600 text-white px-3 py-1 rounded hover:bg-green-700">Recharger</button>
        </div>
        <div x-show="error" class="mt-3 px-4 py-3 bg-red-50 border border-red-200 text-red-800 rounded-lg text-sm">
            ❌ La mise à jour a échoué. Consultez les logs ci-dessus.
        </div>
    </div>

</div>
@endsection
