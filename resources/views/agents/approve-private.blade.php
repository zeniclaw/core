<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approbation Agent Prive</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-lg border border-gray-100 max-w-md w-full overflow-hidden">
        @if($expired)
            {{-- Expired / invalid token --}}
            <div class="p-8 text-center">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Lien expire</h2>
                <p class="text-gray-500 text-sm">Ce lien d'approbation a expire ou a deja ete utilise.</p>
                <p class="text-gray-400 text-xs mt-2">Demandez un nouveau lien via WhatsApp avec <code class="bg-gray-100 px-1 rounded">/private</code></p>
            </div>

        @elseif($approved)
            {{-- Successfully approved --}}
            <div class="p-8 text-center">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-gray-900 mb-2">Acces approuve !</h2>
                <div class="bg-gray-50 rounded-xl p-4 mt-4 text-left space-y-2">
                    <div class="flex items-center gap-2">
                        <span class="text-2xl">{{ $data['agent_icon'] }}</span>
                        <span class="font-semibold text-gray-900">{{ $data['agent_label'] }}</span>
                    </div>
                    <div class="text-xs text-gray-500">
                        <span class="font-medium">Utilisateur :</span> {{ $data['peer_name'] }}
                    </div>
                    <div class="text-xs text-gray-400 font-mono">{{ $data['peer_id'] }}</div>
                </div>
                <p class="text-gray-500 text-sm mt-4">L'utilisateur peut maintenant utiliser cet agent depuis WhatsApp.</p>
            </div>

        @else
            {{-- Approval form --}}
            <div class="bg-amber-50 border-b border-amber-100 px-6 py-4">
                <div class="flex items-center gap-3">
                    <span class="text-3xl">{{ $data['agent_icon'] }}</span>
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">{{ $data['agent_label'] }}</h2>
                        <p class="text-xs text-amber-700 font-medium">Agent prive — demande d'acces</p>
                    </div>
                </div>
            </div>

            <div class="p-6 space-y-4">
                <div class="bg-gray-50 rounded-xl p-4 space-y-3">
                    <div>
                        <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Demandeur</span>
                        <p class="text-sm font-semibold text-gray-900 mt-0.5">{{ $data['peer_name'] }}</p>
                    </div>
                    <div>
                        <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Peer ID</span>
                        <p class="text-xs font-mono text-gray-600 mt-0.5">{{ $data['peer_id'] }}</p>
                    </div>
                    <div>
                        <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">Demande le</span>
                        <p class="text-xs text-gray-600 mt-0.5">{{ \Carbon\Carbon::parse($data['requested_at'])->format('d/m/Y H:i') }}</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('approve.private.process', $token) }}">
                    @csrf
                    <button type="submit"
                        class="w-full py-3 bg-amber-600 hover:bg-amber-700 text-white font-semibold rounded-xl transition-colors text-sm">
                        Approuver l'acces
                    </button>
                </form>

                <p class="text-center text-xs text-gray-400">Ce lien expire dans 30 minutes</p>
            </div>
        @endif
    </div>
</body>
</html>
