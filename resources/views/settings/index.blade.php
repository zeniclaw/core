@extends('layouts.app')
@section('title', 'Settings')

@section('content')
<div class="max-w-3xl space-y-6">

    {{-- Show newly created token --}}
    @if(session('new_token'))
    <div class="bg-yellow-50 border border-yellow-300 rounded-xl p-4" x-data>
        <p class="font-semibold text-yellow-800 mb-2">🔑 New API Token — copy it now!</p>
        <p class="text-xs text-yellow-700 mb-2">This token will not be shown again.</p>
        <div class="flex items-center gap-2">
            <code class="flex-1 bg-white border border-yellow-200 rounded px-3 py-2 text-sm font-mono break-all">{{ session('new_token') }}</code>
            <button @click="navigator.clipboard.writeText('{{ session('new_token') }}')"
                    class="px-3 py-2 bg-yellow-600 text-white rounded text-xs hover:bg-yellow-700">Copy</button>
        </div>
    </div>
    @endif

    {{-- Profile --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">👤 Profil</h2>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">Name:</span> <span class="font-medium">{{ $user->name }}</span></div>
            <div><span class="text-gray-500">Email:</span> <span class="font-medium">{{ $user->email }}</span></div>
            <div><span class="text-gray-500">Role:</span> <span class="px-2 py-0.5 bg-indigo-100 text-indigo-700 rounded text-xs font-medium">{{ $user->role }}</span></div>
            <div><span class="text-gray-500">Membre depuis:</span> <span class="font-medium">{{ $user->created_at->format('d M Y') }}</span></div>
        </div>
    </div>

    {{-- LLM Providers --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-1">🧠 LLM Providers</h2>
        <p class="text-sm text-gray-500 mb-5">Configurez vos clés API pour utiliser les modèles IA.</p>

        <form method="POST" action="{{ route('settings.llm-keys') }}" class="space-y-5">
            @csrf

            {{-- Anthropic --}}
            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50" x-data="{ show: false }">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🟣</span>
                        <div>
                            <p class="font-medium text-sm text-gray-900">Anthropic API Key</p>
                            <p class="text-xs text-gray-500">Pour Claude Opus, Sonnet, Haiku (Claude Max)</p>
                        </div>
                    </div>
                    @if($hasAnthropicKey)
                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">✓ Configurée</span>
                    @else
                        <span class="px-2 py-1 bg-gray-200 text-gray-500 rounded-full text-xs">— Non configurée</span>
                    @endif
                </div>
                <div class="relative">
                    <input :type="show ? 'text' : 'password'" name="anthropic_api_key"
                           placeholder="{{ $hasAnthropicKey ? '••••••••••••••••••••••••' : 'sk-ant-api03-...' }}"
                           class="w-full px-3 py-2 pr-20 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono bg-white">
                    <button type="button" @click="show = !show"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-500 hover:text-gray-700 px-2 py-1">
                        <span x-text="show ? '🙈 Hide' : '👁 Show'"></span>
                    </button>
                </div>
                <div class="mt-2 p-3 bg-blue-50 border border-blue-100 rounded-lg">
                    <p class="text-xs text-blue-800">
                        💡 <strong>Claude Max subscribers:</strong> Si vous avez un abonnement Claude Max, utilisez votre clé API Anthropic directement. L'accès API est inclus dans l'abonnement Claude Max.
                    </p>
                </div>
            </div>

            {{-- OpenAI --}}
            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50" x-data="{ show: false }">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🟢</span>
                        <div>
                            <p class="font-medium text-sm text-gray-900">OpenAI API Key</p>
                            <p class="text-xs text-gray-500">Pour GPT-4o, GPT-4o Mini</p>
                        </div>
                    </div>
                    @if($hasOpenAiKey)
                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">✓ Configurée</span>
                    @else
                        <span class="px-2 py-1 bg-gray-200 text-gray-500 rounded-full text-xs">— Non configurée</span>
                    @endif
                </div>
                <div class="relative">
                    <input :type="show ? 'text' : 'password'" name="openai_api_key"
                           placeholder="{{ $hasOpenAiKey ? '••••••••••••••••••••••••' : 'sk-...' }}"
                           class="w-full px-3 py-2 pr-20 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono bg-white">
                    <button type="button" @click="show = !show"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-500 hover:text-gray-700 px-2 py-1">
                        <span x-text="show ? '🙈 Hide' : '👁 Show'"></span>
                    </button>
                </div>
            </div>

            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                💾 Sauvegarder les clés
            </button>
        </form>
    </div>

    {{-- Canaux / WhatsApp --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-1">📱 Canaux</h2>
        <p class="text-sm text-gray-500 mb-5">Connectez vos canaux de communication.</p>

        <div class="border border-gray-100 rounded-xl p-5 bg-gray-50"
             x-data="{
                connected: false,
                loading: false,
                qr: null,
                phone: null,
                status: 'unknown',
                pollInterval: null,

                async checkStatus() {
                    try {
                        const r = await fetch('{{ route('channels.whatsapp.status') }}');
                        const d = await r.json();
                        this.connected = d.connected;
                        this.status = d.status;
                        if (d.phone) this.phone = d.phone;
                    } catch(e) { this.status = 'WAHA_UNAVAILABLE'; }
                },

                async start() {
                    this.loading = true;
                    try {
                        await fetch('{{ route('channels.whatsapp.start') }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                        });
                    } catch(e) {}
                    // Fetch QR immediately then poll
                    await this.fetchQr();
                    this.pollQr();
                },

                async fetchQr() {
                    try {
                        const r = await fetch('{{ route('channels.whatsapp.qr') }}');
                        const d = await r.json();
                        if (d.connected) {
                            this.connected = true; this.phone = d.phone; this.qr = null;
                            this.loading = false;
                            if (this.pollInterval) clearInterval(this.pollInterval);
                        } else if (d.qr) {
                            this.qr = d.qr;
                            this.loading = false;
                        }
                    } catch(e) {}
                },

                async stop() {
                    if (!confirm('Déconnecter WhatsApp ?')) return;
                    await fetch('{{ route('channels.whatsapp.stop') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                    });
                    this.connected = false; this.qr = null; this.phone = null;
                    if (this.pollInterval) clearInterval(this.pollInterval);
                },

                pollQr() {
                    if (this.pollInterval) clearInterval(this.pollInterval);
                    this.pollInterval = setInterval(() => this.fetchQr(), 5000);
                }
             }"
             x-init="checkStatus()">

            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">📱</span>
                    <div>
                        <p class="font-medium text-gray-900">WhatsApp via WAHA</p>
                        <p class="text-xs text-gray-500">WhatsApp HTTP API (open-source)</p>
                    </div>
                </div>
                <div>
                    <span x-show="connected" class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm font-medium">✅ Connecté</span>
                    <span x-show="!connected && status !== 'unknown'" class="px-3 py-1 bg-gray-200 text-gray-600 rounded-full text-sm">Déconnecté</span>
                </div>
            </div>

            <div x-show="connected && phone" class="mb-3 text-sm text-gray-600">
                📞 Connecté en tant que : <strong x-text="phone"></strong>
            </div>

            <div x-show="qr" class="mb-4 flex flex-col items-center">
                <p class="text-sm text-gray-600 mb-2">Scannez ce QR code avec WhatsApp :</p>
                <img :src="qr" class="w-48 h-48 border border-gray-200 rounded-xl">
                <p class="text-xs text-gray-400 mt-2 animate-pulse">Actualisation automatique toutes les 5s...</p>
            </div>

            <div class="flex gap-3">
                <button x-show="!connected" @click="start()" :disabled="loading"
                        class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition-colors disabled:opacity-50">
                    <span x-show="!loading">📱 Connecter WhatsApp</span>
                    <span x-show="loading" class="animate-pulse">⏳ Démarrage...</span>
                </button>
                <button x-show="connected" @click="stop()"
                        class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 transition-colors">
                    🔌 Déconnecter
                </button>
            </div>
        </div>
    </div>

    {{-- API Tokens --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-1">🔑 API Tokens</h2>
        <p class="text-sm text-gray-500 mb-5">Tokens pour authentifier les webhooks et l'API externe.</p>

        @if($tokens->isNotEmpty())
        <div class="mb-4 divide-y divide-gray-50 border border-gray-100 rounded-xl overflow-hidden">
            @foreach($tokens as $token)
            <div class="flex items-center justify-between px-4 py-3 bg-gray-50">
                <div>
                    <p class="text-sm font-medium text-gray-900">{{ $token->name }}</p>
                    <p class="text-xs text-gray-400">
                        Créé {{ $token->created_at->diffForHumans() }}
                        @if($token->last_used_at) · Utilisé {{ $token->last_used_at->diffForHumans() }}@endif
                    </p>
                </div>
                <form method="POST" action="{{ route('tokens.destroy', $token) }}"
                      x-data @submit.prevent="if(confirm('Révoquer ce token ?')) $el.submit()">
                    @csrf @method('DELETE')
                    <button class="text-red-500 text-xs hover:text-red-700 px-2 py-1 rounded hover:bg-red-50">Révoquer</button>
                </form>
            </div>
            @endforeach
        </div>
        @endif

        <form method="POST" action="{{ route('tokens.store') }}" class="flex gap-3">
            @csrf
            <input type="text" name="name" placeholder="Nom du token (ex: webhook-prod)"
                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none" required>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 transition-colors">
                Générer
            </button>
        </form>
    </div>

</div>
@endsection
