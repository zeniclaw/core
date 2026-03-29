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

    {{-- Timezone --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-1">🕐 Fuseau horaire</h2>
        <p class="text-sm text-gray-500 mb-4">Fuseau horaire utilise pour les rappels, todos, habitudes et toutes les heures affichees.</p>

        <form method="POST" action="{{ route('settings.timezone') }}" class="flex items-end gap-3">
            @csrf
            <div class="flex-1">
                <label for="app_timezone" class="block text-sm font-medium text-gray-700 mb-1">Fuseau horaire</label>
                <select name="app_timezone" id="app_timezone"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none bg-white">
                    @foreach(timezone_identifiers_list() as $tz)
                        <option value="{{ $tz }}" {{ $appTimezone === $tz ? 'selected' : '' }}>{{ $tz }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                Sauvegarder
            </button>
        </form>

        <p class="text-xs text-gray-400 mt-2">Actuellement : <strong>{{ $appTimezone }}</strong> — {{ now($appTimezone)->format('d/m/Y H:i') }}</p>
    </div>

    {{-- Change Password --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">🔒 Changer le mot de passe</h2>

        @if(session('status') === 'password-updated')
            <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
                Mot de passe mis à jour avec succès.
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Mot de passe actuel</label>
                <input type="password" name="current_password" id="current_password" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                @if($errors->updatePassword->has('current_password'))
                    <p class="text-xs text-red-600 mt-1">{{ $errors->updatePassword->first('current_password') }}</p>
                @endif
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe</label>
                <input type="password" name="password" id="password" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                @if($errors->updatePassword->has('password'))
                    <p class="text-xs text-red-600 mt-1">{{ $errors->updatePassword->first('password') }}</p>
                @endif
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirmer le nouveau mot de passe</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>

            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                Mettre à jour le mot de passe
            </button>
        </form>
    </div>

    {{-- LLM Providers --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-1">🧠 LLM Providers</h2>
        <p class="text-sm text-gray-500 mb-5">Configurez vos clés API pour utiliser les modèles IA.</p>

        <form method="POST" action="{{ route('settings.llm-keys') }}" class="space-y-5">
            @csrf

            {{-- Anthropic --}}
            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50" x-data="{ show: false, tab: 'subscription' }">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🟣</span>
                        <div>
                            <p class="font-medium text-sm text-gray-900">Anthropic (Claude)</p>
                            <p class="text-xs text-gray-500">Claude Opus, Sonnet, Haiku</p>
                        </div>
                    </div>
                    @if($hasAnthropicKey)
                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">✓ Configurée</span>
                    @else
                        <span class="px-2 py-1 bg-gray-200 text-gray-500 rounded-full text-xs">— Non configurée</span>
                    @endif
                </div>

                {{-- Tabs --}}
                <div class="flex gap-1 mb-3 bg-gray-200 rounded-lg p-0.5">
                    <button type="button" @click="tab = 'subscription'"
                            :class="tab === 'subscription' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                            class="flex-1 px-3 py-1.5 rounded-md text-xs font-medium transition-all">
                        🎫 Abonnement Max/Pro
                    </button>
                    <button type="button" @click="tab = 'apikey'"
                            :class="tab === 'apikey' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                            class="flex-1 px-3 py-1.5 rounded-md text-xs font-medium transition-all">
                        🔑 Clé API
                    </button>
                </div>

                {{-- Input field (same for both) --}}
                <div class="relative">
                    <input :type="show ? 'text' : 'password'" name="anthropic_api_key"
                           :placeholder="tab === 'subscription' ? '{{ $hasAnthropicKey ? '••••••••••••••••••••••••' : 'sk-ant-oat01-... (token subscription)' }}' : '{{ $hasAnthropicKey ? '••••••••••••••••••••••••' : 'sk-ant-api03-... (clé API)' }}'"
                           class="w-full px-3 py-2 pr-20 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono bg-white">
                    <button type="button" @click="show = !show"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-500 hover:text-gray-700 px-2 py-1">
                        <span x-text="show ? '🙈 Hide' : '👁 Show'"></span>
                    </button>
                </div>

                {{-- Tab: Subscription (Max/Pro) --}}
                <div x-show="tab === 'subscription'" class="mt-2 p-3 bg-purple-50 border border-purple-100 rounded-lg space-y-2">
                    <p class="text-xs text-purple-800 font-semibold">Utilisez votre abonnement Claude Max/Pro :</p>
                    <ol class="text-xs text-purple-800 list-decimal list-inside space-y-1.5">
                        <li>Connectez-vous en SSH sur le serveur</li>
                        <li>Lancez la commande :<br>
                            <code class="inline-block mt-1 px-2 py-1 bg-purple-100 rounded font-mono text-purple-900 select-all">claude setup-token</code>
                        </li>
                        <li>Suivez les instructions (login via navigateur)</li>
                        <li>Copiez le token affiché (<code class="bg-purple-100 px-1 rounded">sk-ant-oat01-...</code>)</li>
                        <li>Collez-le dans le champ ci-dessus</li>
                    </ol>
                    <div class="mt-1 p-2 bg-purple-100/50 rounded text-xs text-purple-700">
                        <strong>Inclus dans votre abonnement</strong> — aucun frais supplémentaire.
                    </div>
                </div>

                {{-- Tab: API Key --}}
                <div x-show="tab === 'apikey'" class="mt-2 p-3 bg-blue-50 border border-blue-100 rounded-lg space-y-2">
                    <p class="text-xs text-blue-800 font-semibold">Clé API (facturation à l'usage) :</p>
                    <ol class="text-xs text-blue-800 list-decimal list-inside space-y-1">
                        <li>Ouvrez <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener" class="underline font-medium">console.anthropic.com/settings/keys</a></li>
                        <li>Cliquez <strong>"Create Key"</strong></li>
                        <li>Copiez la clé (<code class="bg-blue-100 px-1 rounded">sk-ant-api03-...</code>) et collez-la ci-dessus</li>
                    </ol>
                    <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 mt-1 px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-medium hover:bg-blue-700 transition-colors">
                        🔗 Obtenir ma clé API
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                    <div class="mt-1 p-2 bg-blue-100/50 rounded text-xs text-blue-700">
                        Haiku ~0.001$/msg, Sonnet ~0.01$/msg, Opus ~0.05$/msg.
                    </div>
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
                <div class="mt-2">
                    <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-200 transition-colors">
                        🔗 Ouvrir platform.openai.com/api-keys
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                </div>
            </div>

            {{-- On-Prem (Ollama/vLLM) --}}
            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50" x-data="{ show: false }">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🖥️</span>
                        <div>
                            <p class="font-medium text-sm text-gray-900">Modeles On-Prem (Ollama / vLLM)</p>
                            <p class="text-xs text-gray-500">Qwen 2.5, DeepSeek Coder — API compatible OpenAI</p>
                        </div>
                    </div>
                    @if($hasOnPremUrl)
                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">✓ Configure</span>
                    @else
                        <span class="px-2 py-1 bg-gray-200 text-gray-500 rounded-full text-xs">— Non configure</span>
                    @endif
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">URL de l'API (Ollama, vLLM, LM Studio...)</label>
                        <input type="text" name="onprem_api_url"
                               value="{{ $onPremUrl ?? '' }}"
                               placeholder="http://ollama:11434"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Cle API (optionnel)</label>
                        <div class="relative">
                            <input :type="show ? 'text' : 'password'" name="onprem_api_key"
                                   placeholder="{{ $hasOnPremKey ? '••••••••••••••••••••••••' : 'optionnel' }}"
                                   class="w-full px-3 py-2 pr-20 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono bg-white">
                            <button type="button" @click="show = !show"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-500 hover:text-gray-700 px-2 py-1">
                                <span x-text="show ? '🙈 Hide' : '👁 Show'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Proxy warning for Ollama downloads --}}
                @if(!empty($proxyConfig['http']) || !empty($proxyConfig['https']))
                <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
                    <strong>Proxy detecte</strong> — Pour que Ollama telecharge les modeles via votre proxy, ajoutez dans votre fichier <code class="bg-amber-100 px-1 rounded">.env</code> :
                    <pre class="mt-1 text-xs bg-amber-100 p-2 rounded">HTTP_PROXY={{ $proxyConfig['http'] }}
HTTPS_PROXY={{ $proxyConfig['https'] }}
NO_PROXY=localhost,127.0.0.1,db,redis,waha,ollama,app</pre>
                    Puis relancez : <code class="bg-amber-100 px-1 rounded">docker compose up -d ollama</code>
                </div>
                @endif

                {{-- Ollama Service Status --}}
                <div class="mt-4" x-data="ollamaStatusApp()" x-init="check()">
                    <div class="flex items-center gap-3 p-3 bg-white border rounded-lg" :class="status === 'running' ? 'border-green-200' : status === 'exited' ? 'border-red-200' : 'border-gray-200'">
                        <div class="flex-shrink-0">
                            <span x-show="checking" class="inline-block w-3 h-3 rounded-full bg-gray-300 animate-pulse"></span>
                            <span x-show="!checking && status === 'running'" class="inline-block w-3 h-3 rounded-full bg-green-500"></span>
                            <span x-show="!checking && status === 'exited'" class="inline-block w-3 h-3 rounded-full bg-red-500"></span>
                            <span x-show="!checking && !status" class="inline-block w-3 h-3 rounded-full bg-gray-400"></span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900">
                                Service Ollama
                                <span x-show="version" class="text-xs font-mono text-gray-400 ml-1" x-text="'v' + version"></span>
                            </p>
                            <p class="text-xs" :class="status === 'running' ? 'text-green-600' : status === 'exited' ? 'text-red-600' : 'text-gray-500'"
                               x-text="status === 'running' ? 'En ligne — ' + url : status === 'exited' ? 'Arrete' : status === null ? 'Container non trouve (ollama non installe ?)' : 'Verification...'"></p>
                        </div>
                        <div class="flex-shrink-0">
                            <template x-if="!checking && status !== 'running'">
                                <button type="button" @@click="startOllama()" :disabled="starting"
                                        class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700 transition-colors disabled:opacity-50 flex items-center gap-1.5">
                                    <template x-if="starting">
                                        <svg class="animate-spin h-3 w-3" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                    </template>
                                    <span x-text="starting ? 'Demarrage...' : 'Demarrer Ollama'"></span>
                                </button>
                            </template>
                            <template x-if="!checking && status === 'running'">
                                <button type="button" @@click="check()" class="px-2 py-1 bg-gray-100 text-gray-600 rounded text-xs hover:bg-gray-200" title="Rafraichir">&#8635;</button>
                            </template>
                        </div>
                    </div>
                    <template x-if="error">
                        <p class="text-xs text-red-600 mt-1" x-text="error"></p>
                    </template>
                </div>
                <script>
                function ollamaStatusApp() {
                    return {
                        checking: true, starting: false, status: null, version: null, url: '', error: null,
                        async check() {
                            this.checking = true; this.error = null;
                            try {
                                var res = await fetch('{{ route("api.ollama.status") }}');
                                var data = await res.json();
                                this.status = data.running ? 'running' : (data.container_status || null);
                                this.version = data.version;
                                this.url = data.url;
                            } catch(e) { this.status = null; }
                            this.checking = false;
                        },
                        async startOllama() {
                            this.starting = true; this.error = null;
                            try {
                                var res = await fetch('{{ route("api.ollama.start") }}', {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
                                });
                                var data = await res.json();
                                if (!res.ok) { this.error = data.error || 'Erreur'; this.starting = false; return; }
                                // Wait for it to be healthy
                                await new Promise(r => setTimeout(r, 3000));
                                await this.check();
                            } catch(e) { this.error = e.message; }
                            this.starting = false;
                        },
                    };
                }
                </script>

                {{-- Loaded Models in Memory --}}
                <div class="mt-4" x-data="ollamaLoadedApp()" x-init="refresh()">
                    <div class="bg-white border border-gray-200 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                                <span class="text-base">🧠</span> Modeles charges en memoire
                            </h4>
                            <button type="button" @@click="refresh()" class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1 rounded hover:bg-gray-100">&#8635; Rafraichir</button>
                        </div>
                        <template x-if="loaded.length === 0 && !loading">
                            <p class="text-xs text-gray-400 py-2">Aucun modele charge — le premier appel sera plus lent (~10-30s).</p>
                        </template>
                        <template x-if="loading">
                            <p class="text-xs text-gray-400 py-2">Chargement...</p>
                        </template>
                        <div class="space-y-2">
                            <template x-for="m in loaded" :key="m.name">
                                <div class="flex items-center justify-between p-2 bg-green-50 border border-green-200 rounded-lg">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                                        <span class="text-sm font-medium text-gray-900" x-text="m.name"></span>
                                        <span class="text-[10px] text-gray-500" x-text="formatSize(m.size)"></span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] text-green-600 font-medium">En memoire</span>
                                        <button type="button" @@click="unloading = m.name; unloadModel(m.name).then(() => { unloading = ''; refresh(); })"
                                                :disabled="unloading === m.name"
                                                class="px-2 py-0.5 bg-red-50 text-red-600 border border-red-200 rounded text-[10px] font-medium hover:bg-red-100 transition-colors disabled:opacity-50 whitespace-nowrap">
                                            <span x-text="unloading === m.name ? 'Dechargement...' : 'Decharger'"></span>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                        {{-- Warmup button --}}
                        <template x-if="!loading">
                            <div class="mt-3 flex items-center gap-2" x-data="{ warmModel: '', warming: false }">
                                <select x-model="warmModel" class="flex-1 px-2 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-indigo-500 outline-none bg-white">
                                    <option value="">Charger un modele...</option>
                                    @foreach(\App\Services\ModelResolver::allModels() as $mId => $mLabel)
                                        @if(!str_starts_with($mId, 'claude-') && !str_starts_with($mId, 'gpt-'))
                                        <option value="{{ $mId }}">{{ $mLabel }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button type="button" @@click="if(warmModel){warming=true;warmup(warmModel).then(()=>{warming=false;refresh()})}"
                                        :disabled="!warmModel || warming"
                                        class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700 transition-colors disabled:opacity-50 whitespace-nowrap flex items-center gap-1">
                                    <template x-if="warming">
                                        <svg class="animate-spin h-3 w-3" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                    </template>
                                    <span x-text="warming ? 'Chargement...' : 'Charger en memoire'"></span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
                <script>
                function ollamaLoadedApp() {
                    return {
                        loaded: [], loading: false, unloading: '',
                        async refresh() {
                            this.loading = true;
                            try {
                                var res = await fetch('{{ route("api.ollama.loaded") }}');
                                var data = await res.json();
                                this.loaded = data.models || [];
                            } catch(e) { this.loaded = []; }
                            this.loading = false;
                        },
                        async unloadModel(model) {
                            try {
                                await fetch('{{ route("api.ollama.unload") }}', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                                    body: JSON.stringify({ model: model }),
                                });
                            } catch(e) {}
                        },
                        async warmup(model) {
                            try {
                                await fetch('{{ route("api.ollama.warmup") }}', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                                    body: JSON.stringify({ model: model }),
                                });
                            } catch(e) {}
                        },
                        formatSize(bytes) {
                            if (!bytes) return '';
                            var gb = bytes / 1024 / 1024 / 1024;
                            return gb >= 1 ? gb.toFixed(1) + ' Go' : (bytes / 1024 / 1024).toFixed(0) + ' Mo';
                        },
                    };
                }
                </script>

                {{-- Server Check + Dynamic Model Catalog --}}
                <div class="mt-4" id="server-check-container" x-data="serverCheckApp()">
                    <button type="button" @@click="runCheck()" :disabled="loading"
                            class="w-full px-4 py-3 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-2">
                        <span x-show="!loading">Analyser le serveur & modeles compatibles</span>
                        <span x-show="loading" class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Analyse en cours...
                        </span>
                    </button>

                    <template x-if="checked">
                    <div class="mt-4 space-y-4">
                        {{-- Hardware summary --}}
                        <div class="bg-white border border-gray-200 rounded-xl p-4">
                            <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
                                <span class="text-lg">🖥️</span> Configuration serveur
                            </h4>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                {{-- CPU --}}
                                <div class="text-center p-3 bg-gray-50 rounded-lg">
                                    <p class="text-2xl font-bold text-indigo-600" x-text="server.cpu_cores"></p>
                                    <p class="text-[10px] text-gray-500 mt-0.5">vCPU</p>
                                    <p class="text-[10px] text-gray-400 truncate" x-text="server.cpu_model" :title="server.cpu_model"></p>
                                </div>
                                {{-- RAM --}}
                                <div class="text-center p-3 bg-gray-50 rounded-lg">
                                    <p class="text-2xl font-bold" :class="server.ram_percent > 85 ? 'text-red-600' : server.ram_percent > 60 ? 'text-amber-600' : 'text-green-600'" x-text="server.ram_total_gb + ' Go'"></p>
                                    <p class="text-[10px] text-gray-500 mt-0.5">RAM totale</p>
                                    <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                                        <div class="h-1.5 rounded-full transition-all" :class="server.ram_percent > 85 ? 'bg-red-500' : server.ram_percent > 60 ? 'bg-amber-500' : 'bg-green-500'" :style="'width:' + server.ram_percent + '%'"></div>
                                    </div>
                                    <p class="text-[10px] text-gray-400 mt-0.5" x-text="server.ram_available_gb + ' Go dispo'"></p>
                                </div>
                                {{-- Disk --}}
                                <div class="text-center p-3 bg-gray-50 rounded-lg">
                                    <p class="text-2xl font-bold" :class="server.disk_percent > 85 ? 'text-red-600' : server.disk_percent > 70 ? 'text-amber-600' : 'text-green-600'" x-text="server.disk_free_gb + ' Go'"></p>
                                    <p class="text-[10px] text-gray-500 mt-0.5">Espace libre</p>
                                    <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                                        <div class="h-1.5 rounded-full transition-all" :class="server.disk_percent > 85 ? 'bg-red-500' : server.disk_percent > 70 ? 'bg-amber-500' : 'bg-green-500'" :style="'width:' + server.disk_percent + '%'"></div>
                                    </div>
                                    <p class="text-[10px] text-gray-400 mt-0.5" x-text="server.disk_total_gb + ' Go total'"></p>
                                </div>
                                {{-- GPU --}}
                                <div class="text-center p-3 bg-gray-50 rounded-lg">
                                    <template x-if="server.gpu">
                                        <div>
                                            <p class="text-lg font-bold text-green-600" x-text="server.gpu_vram_mb ? (server.gpu_vram_mb / 1024).toFixed(0) + ' Go' : 'Oui'"></p>
                                            <p class="text-[10px] text-gray-500 mt-0.5">GPU VRAM</p>
                                            <p class="text-[10px] text-gray-400 truncate" x-text="server.gpu"></p>
                                        </div>
                                    </template>
                                    <template x-if="!server.gpu">
                                        <div>
                                            <p class="text-2xl font-bold text-gray-400">—</p>
                                            <p class="text-[10px] text-gray-500 mt-0.5">GPU</p>
                                            <p class="text-[10px] text-gray-400">Non detecte</p>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            {{-- Load --}}
                            <div class="flex items-center gap-4 mt-3 text-xs text-gray-500">
                                <span>Charge CPU: <span class="font-mono" x-text="server.load_avg ? server.load_avg.join(' / ') : '—'"></span></span>
                                <span>Ollama: <span :class="server.ollama_connected ? 'text-green-600 font-medium' : 'text-red-500'" x-text="server.ollama_connected ? 'Connecte' : 'Non connecte'"></span></span>
                            </div>
                        </div>

                        {{-- Filter tabs --}}
                        <div class="flex gap-2">
                            <button type="button" @@click="filter = 'recommended'" :class="filter === 'recommended' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                                Recommandes <span class="ml-1 opacity-70" x-text="'(' + models.filter(m => m.status === \'ok\').length + ')'"></span>
                            </button>
                            <button type="button" @@click="filter = 'onprem'" :class="filter === 'onprem' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                                On-Prem <span class="ml-1 opacity-70" x-text="'(' + models.filter(m => m.type === \'onprem\').length + ')'"></span>
                            </button>
                            <button type="button" @@click="filter = 'cloud'" :class="filter === 'cloud' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                                Cloud <span class="ml-1 opacity-70" x-text="'(' + models.filter(m => m.type === \'cloud\').length + ')'"></span>
                            </button>
                            <button type="button" @@click="filter = 'all'" :class="filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">
                                Tous
                            </button>
                        </div>

                        {{-- Model list --}}
                        <div class="space-y-2">
                            <template x-for="m in filteredModels()" :key="m.id">
                            <div class="flex items-center gap-3 p-3 bg-white border rounded-lg transition-colors"
                                 :class="m.status === 'impossible' ? 'border-red-200 opacity-60' : m.status === 'warning' ? 'border-amber-200' : 'border-gray-200'"
                                 :id="'ollama-model-' + m.id.replace(/[.:]/g, '-')">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-sm font-medium text-gray-900" x-text="m.name"></p>
                                        {{-- Type badge --}}
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-medium"
                                              :class="m.type === 'cloud' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'"
                                              x-text="m.type === 'cloud' ? m.provider : 'On-Prem'"></span>
                                        {{-- Compatibility badge --}}
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-medium"
                                              :class="m.status === 'ok' ? 'bg-green-100 text-green-700' : m.status === 'warning' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'"
                                              x-text="m.status === 'ok' ? 'Compatible' : m.status === 'warning' ? 'Limite' : 'Impossible'"></span>
                                        {{-- Installed badge --}}
                                        <template x-if="isInstalled(m.id)">
                                            <span class="px-1.5 py-0.5 rounded-full text-[9px] font-medium bg-green-100 text-green-700">Installe</span>
                                        </template>
                                        {{-- Speed --}}
                                        <span class="text-[9px] text-gray-400" x-text="m.speed"></span>
                                    </div>
                                    <div class="flex items-center gap-3 mt-1 text-[10px] text-gray-500">
                                        <span class="font-mono" x-text="m.id"></span>
                                        <template x-if="m.type === 'onprem'">
                                            <span x-text="'~' + m.disk_gb + ' Go | ' + m.ram_gb + ' Go RAM | ' + m.min_cpu + ' CPU'"></span>
                                        </template>
                                    </div>
                                    <template x-if="m.warnings && m.warnings.length > 0">
                                        <div class="mt-1 space-y-0.5">
                                            <template x-for="w in m.warnings">
                                                <p class="text-[10px]" :class="m.status === 'impossible' ? 'text-red-500' : 'text-amber-600'" x-text="w"></p>
                                            </template>
                                        </div>
                                    </template>
                                    {{-- Tags --}}
                                    <div class="flex gap-1 mt-1.5">
                                        <template x-for="t in m.tags">
                                            <span class="px-1.5 py-0.5 bg-gray-100 text-gray-500 rounded text-[9px]" x-text="t"></span>
                                        </template>
                                    </div>
                                </div>
                                {{-- Action --}}
                                <div class="ollama-status flex-shrink-0">
                                    <template x-if="m.type === 'onprem' && m.compatible && !isInstalled(m.id)">
                                        <button type="button" @@click="pullModel(m.id)"
                                                class="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-700 transition-colors whitespace-nowrap">
                                            Telecharger
                                        </button>
                                    </template>
                                    <template x-if="m.type === 'onprem' && isInstalled(m.id)">
                                        <button type="button" @@click="pullModel(m.id, true)"
                                                class="px-2 py-1 bg-gray-100 text-gray-600 rounded text-xs hover:bg-gray-200" title="Retelecharger">
                                            &#8635;
                                        </button>
                                    </template>
                                    <template x-if="m.type === 'onprem' && !m.compatible">
                                        <span class="text-[10px] text-red-400">Serveur trop petit</span>
                                    </template>
                                    <template x-if="m.type === 'cloud'">
                                        <span class="px-2 py-1 bg-blue-50 text-blue-600 rounded text-[10px]">API</span>
                                    </template>
                                </div>
                            </div>
                            </template>
                        </div>
                    </div>
                    </template>

                    <p id="ollama-connection-error" class="text-xs text-red-600 mt-1 hidden"></p>
                </div>

                <script>
                function serverCheckApp() {
                    return {
                        loading: false,
                        checked: false,
                        server: {},
                        models: [],
                        installed: [],
                        filter: 'recommended',
                        pollers: {},

                        async runCheck() {
                            this.loading = true;
                            try {
                                // Auto-save URL first
                                var urlInput = document.querySelector('input[name="onprem_api_url"]');
                                var urlVal = urlInput ? urlInput.value.trim() : '';
                                if (urlVal) {
                                    await fetch('{{ route("api.ollama.save-url") }}', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                                        body: JSON.stringify({ url: urlVal }),
                                    });
                                }
                                var res = await fetch('{{ route("api.ollama.server-check") }}');
                                var data = await res.json();
                                this.server = data.server;
                                this.models = data.models;
                                this.installed = data.installed || [];
                                this.checked = true;

                                // Check pull status for all on-prem models
                                for (var m of this.models.filter(x => x.type === 'onprem')) {
                                    this.checkPulling(m.id);
                                }
                            } catch (e) {
                                alert('Erreur lors de l\'analyse: ' + e.message);
                            }
                            this.loading = false;
                        },

                        filteredModels() {
                            if (this.filter === 'recommended') return this.models.filter(m => m.status === 'ok');
                            if (this.filter === 'onprem') return this.models.filter(m => m.type === 'onprem');
                            if (this.filter === 'cloud') return this.models.filter(m => m.type === 'cloud');
                            return this.models;
                        },

                        isInstalled(modelId) {
                            var base = modelId.split(':')[0];
                            var tag = modelId.split(':')[1] || 'latest';
                            return this.installed.some(i => i === modelId || i === modelId + ':latest' || (i.startsWith(base + ':') && i.includes(tag)));
                        },

                        async pullModel(model, force) {
                            this.setProgress(model, 0, 'Demarrage...');
                            try {
                                var res = await fetch('{{ route("api.ollama.pull") }}', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                                    body: JSON.stringify({ model: model, force: !!force }),
                                });
                                if (!res.ok) {
                                    var err = await res.json();
                                    this.setError(model, err.error || 'Erreur');
                                    return;
                                }
                                this.startPolling(model);
                            } catch (e) {
                                this.setError(model, 'Erreur reseau');
                            }
                        },

                        async checkPulling(model) {
                            try {
                                var res = await fetch('{{ route("api.ollama.pull-status") }}?model=' + encodeURIComponent(model));
                                if (!res.ok) return;
                                var data = await res.json();
                                if (data.status === 'pulling') {
                                    this.setProgress(model, data.percent || 0, data.detail || '');
                                    this.startPolling(model);
                                } else if (data.status === 'error') {
                                    this.setError(model, data.detail || 'Erreur');
                                }
                            } catch(e) {}
                        },

                        setProgress(model, percent, detail) {
                            var el = this.getStatusEl(model);
                            if (!el) return;
                            el.innerHTML = '<div class="w-36"><div class="flex items-center justify-between text-xs text-gray-600 mb-0.5"><span>' +
                                (detail||'Telechargement...') + '</span><span>' + percent + '%</span></div>' +
                                '<div class="w-full bg-gray-200 rounded-full h-2"><div class="bg-indigo-600 h-2 rounded-full transition-all" style="width:' + percent + '%"></div></div></div>';
                        },

                        setError(model, detail) {
                            var self = this;
                            var el = this.getStatusEl(model);
                            if (!el) return;
                            var retryId = 'retry-' + model.replace(/[.:]/g, '-');
                            el.innerHTML = '<div class="flex items-center gap-2">' +
                                '<span class="text-xs text-red-600">' + detail + '</span>' +
                                '<button type="button" id="' + retryId + '" class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs hover:bg-red-200">Reessayer</button></div>';
                            document.getElementById(retryId).addEventListener('click', function() { self.pullModel(model, true); });
                        },

                        getStatusEl(model) {
                            var slug = model.replace(/[.:]/g, '-');
                            var el = document.getElementById('ollama-model-' + slug);
                            return el ? el.querySelector('.ollama-status') : null;
                        },

                        startPolling(model) {
                            var self = this;
                            if (self.pollers[model]) return;
                            self.pollers[model] = setInterval(async function() {
                                try {
                                    var res = await fetch('{{ route("api.ollama.pull-status") }}?model=' + encodeURIComponent(model));
                                    if (!res.ok) return;
                                    var data = await res.json();
                                    if (data.status === 'done') {
                                        if (!self.installed.includes(model)) self.installed.push(model);
                                        // Reset the status element to let Alpine re-render
                                        var el = self.getStatusEl(model);
                                        if (el) el.innerHTML = '';
                                        clearInterval(self.pollers[model]);
                                        delete self.pollers[model];
                                    } else if (data.status === 'error') {
                                        self.setError(model, data.detail || 'Erreur');
                                        clearInterval(self.pollers[model]);
                                        delete self.pollers[model];
                                    } else {
                                        self.setProgress(model, data.percent || 0, data.detail || '');
                                    }
                                } catch(e) {}
                            }, 2000);
                        },
                    };
                }
                </script>
            </div>

            {{-- Brave Search --}}
            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50" x-data="{ show: false }">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🔍</span>
                        <div>
                            <p class="font-medium text-sm text-gray-900">Brave Search API</p>
                            <p class="text-xs text-gray-500">Recherche web en temps reel — 2000 req/mois gratuit</p>
                        </div>
                    </div>
                    @if($hasBraveKey)
                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">✓ Configuree</span>
                    @else
                        <span class="px-2 py-1 bg-gray-200 text-gray-500 rounded-full text-xs">— Non configuree</span>
                    @endif
                </div>
                <div class="relative">
                    <input :type="show ? 'text' : 'password'" name="brave_search_api_key"
                           placeholder="{{ $hasBraveKey ? '••••••••••••••••••••••••' : 'BSA...' }}"
                           class="w-full px-3 py-2 pr-20 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono bg-white">
                    <button type="button" @@click="show = !show"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-500 hover:text-gray-700 px-2 py-1">
                        <span x-text="show ? '🙈 Hide' : '👁 Show'"></span>
                    </button>
                </div>
                <div class="mt-2">
                    <a href="https://api.search.brave.com/register" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 px-3 py-1.5 bg-orange-100 text-orange-700 rounded-lg text-xs font-medium hover:bg-orange-200 transition-colors">
                        🔗 Obtenir une cle gratuite (api.search.brave.com)
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                    <p class="text-xs text-gray-400 mt-1">Utilise par le WebSearchAgent + outil web_search du ChatAgent.</p>
                </div>
            </div>

            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                💾 Sauvegarder les cles
            </button>
        </form>
    </div>

    {{-- Model Roles --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-1">🎯 Roles de modeles</h2>
        <p class="text-sm text-gray-500 mb-5">Configurez quel modele utiliser pour chaque type de tache. Tous les agents utilisent ces roles automatiquement.</p>

        {{-- Presets --}}
        @php
            $onpremInstalled = collect($availableModels)->filter(fn($l, $k) => !str_starts_with($k, 'claude-') && !str_starts_with($k, 'gpt-'))->keys();
            $bestOnprem = $onpremInstalled->first(fn($m) => str_contains($m, '7b'))
                       ?? $onpremInstalled->first(fn($m) => str_contains($m, '3b'))
                       ?? $onpremInstalled->first();
            $lightOnprem = $onpremInstalled->first(fn($m) => str_contains($m, '1.5b') || str_contains($m, '0.5b'))
                        ?? $onpremInstalled->first(fn($m) => str_contains($m, '2b') || str_contains($m, '3b'))
                        ?? $bestOnprem;
        @endphp
        <div class="flex flex-wrap gap-2 mb-4" x-data>
            <span class="text-xs text-gray-500 self-center mr-1">Presets :</span>
            <button type="button" onclick="applyPreset('claude-haiku-4-5-20251001','claude-sonnet-4-6','claude-opus-4-6')"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium bg-blue-100 text-blue-700 hover:bg-blue-200 transition-colors">
                ☁️ Full Cloud
            </button>
            @if($bestOnprem)
            <button type="button" onclick="applyPreset('{{ $lightOnprem }}','{{ $bestOnprem }}','{{ $bestOnprem }}')"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium bg-purple-100 text-purple-700 hover:bg-purple-200 transition-colors">
                🖥️ Full On-Prem
            </button>
            <button type="button" onclick="applyPreset('{{ $lightOnprem }}','claude-sonnet-4-6','claude-opus-4-6')"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium bg-amber-100 text-amber-700 hover:bg-amber-200 transition-colors">
                ⚡ Hybride (on-prem rapide + cloud puissant)
            </button>
            @endif
        </div>
        <script>
        function applyPreset(fast, balanced, powerful) {
            document.querySelector('select[name="model_role_fast"]').value = fast;
            document.querySelector('select[name="model_role_balanced"]').value = balanced;
            document.querySelector('select[name="model_role_powerful"]').value = powerful;
        }
        </script>

        <form method="POST" action="{{ route('settings.model-roles') }}" class="space-y-4">
            @csrf

            @php
                $roles = [
                    'fast' => [
                        'label' => 'Rapide',
                        'icon' => '⚡',
                        'desc' => 'Classification, parsing JSON, extraction simple, intent detection',
                        'color' => 'green',
                    ],
                    'balanced' => [
                        'label' => 'Equilibre',
                        'icon' => '⚖️',
                        'desc' => 'Routing, analyse, resume, generation de contenu',
                        'color' => 'blue',
                    ],
                    'powerful' => [
                        'label' => 'Puissant',
                        'icon' => '🧠',
                        'desc' => 'Raisonnement complexe, generation de code, agents API',
                        'color' => 'purple',
                    ],
                ];
            @endphp

            @foreach($roles as $roleKey => $roleMeta)
            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">{{ $roleMeta['icon'] }}</span>
                        <div>
                            <p class="font-medium text-sm text-gray-900">{{ $roleMeta['label'] }}</p>
                            <p class="text-xs text-gray-500">{{ $roleMeta['desc'] }}</p>
                        </div>
                    </div>
                </div>
                <select name="model_role_{{ $roleKey }}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none bg-white">
                    @foreach($availableModels as $modelId => $modelLabel)
                        <option value="{{ $modelId }}" {{ ($modelRoles[$roleKey] ?? '') === $modelId ? 'selected' : '' }}>
                            {{ $modelLabel }}
                        </option>
                    @endforeach
                </select>
            </div>
            @endforeach

            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                Sauvegarder les roles
            </button>
        </form>
    </div>

    {{-- Sub-Agent Concurrency --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-1">🤖 Sub-Agents</h2>
        <p class="text-sm text-gray-500 mb-5">Configurez le nombre maximum de sub-agents pouvant s'executer en parallele.</p>

        <form method="POST" action="{{ route('settings.subagents') }}" class="space-y-4">
            @csrf

            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-lg">⚡</span>
                    <div>
                        <p class="font-medium text-sm text-gray-900">Executions paralleles</p>
                        <p class="text-xs text-gray-500">Nombre max de sub-agents executant du code en meme temps (1-10). Plus = plus rapide mais plus de RAM/CPU.</p>
                    </div>
                </div>
                <input type="number" name="max_concurrent_subagents" min="1" max="10"
                       value="{{ $maxConcurrentSubagents }}"
                       class="w-32 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none bg-white">
            </div>

            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                Sauvegarder
            </button>
        </form>
    </div>

    {{-- GitLab & Notifications --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-1">🔧 GitLab & Notifications</h2>
        <p class="text-sm text-gray-500 mb-5">Configurez l'acces GitLab et les notifications admin pour les SubAgents.</p>

        <form method="POST" action="{{ route('settings.gitlab') }}" class="space-y-5">
            @csrf

            {{-- GitLab Token --}}
            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50" x-data="{ show: false }">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🦊</span>
                        <div>
                            <p class="font-medium text-sm text-gray-900">GitLab Access Token</p>
                            <p class="text-xs text-gray-500">Token pour cloner et pousser sur les repos GitLab</p>
                        </div>
                    </div>
                    @if($hasGitlabToken)
                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">✓ Configure</span>
                    @else
                        <span class="px-2 py-1 bg-gray-200 text-gray-500 rounded-full text-xs">— Non configure</span>
                    @endif
                </div>
                <div class="relative">
                    <input :type="show ? 'text' : 'password'" name="gitlab_access_token"
                           placeholder="{{ $hasGitlabToken ? '••••••••••••••••••••••••' : 'glpat-...' }}"
                           class="w-full px-3 py-2 pr-20 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono bg-white">
                    <button type="button" @click="show = !show"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-500 hover:text-gray-700 px-2 py-1">
                        <span x-text="show ? '🙈 Hide' : '👁 Show'"></span>
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-2">Creez un token sur GitLab &gt; Settings &gt; Access Tokens avec les scopes <code class="bg-gray-100 px-1 rounded">read_repository</code> et <code class="bg-gray-100 px-1 rounded">write_repository</code>.</p>
            </div>

            {{-- Admin WhatsApp Phone --}}
            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50">
                <div class="flex items-center gap-2 mb-3">
                    <span class="text-lg">📱</span>
                    <div>
                        <p class="font-medium text-sm text-gray-900">Numero WhatsApp Admin</p>
                        <p class="text-xs text-gray-500">Numero qui recevra les notifications de nouvelles demandes</p>
                    </div>
                </div>
                <input type="text" name="admin_whatsapp_phone"
                       value="{{ $adminWhatsappPhone ?? '' }}"
                       placeholder="33612345678@c.us"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono bg-white">
                <p class="text-xs text-gray-400 mt-2">Format WAHA: <code class="bg-gray-100 px-1 rounded">33612345678@c.us</code> (indicatif pays sans +, suivi de @c.us)</p>
            </div>

            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                💾 Sauvegarder
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

                async init() {
                    await this.checkStatus();
                    // Auto-start polling if session is active but not yet connected
                    if (!this.connected && (this.status === 'SCAN_QR_CODE' || this.status === 'STARTING')) {
                        this.loading = true;
                        await this.fetchQr();
                        this.pollQr();
                    }
                },

                async checkStatus() {
                    try {
                        const r = await fetch('{{ route('channels.whatsapp.status') }}');
                        const d = await r.json();
                        this.connected = d.connected;
                        this.status = d.status;
                        if (d.phone) this.phone = d.phone;
                    } catch(e) { this.status = 'ERROR'; }
                },

                async start() {
                    this.loading = true;
                    this.qr = null;
                    try {
                        await fetch('{{ route('channels.whatsapp.start') }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                        });
                    } catch(e) {}
                    // Wait a moment for session to initialize, then poll
                    await new Promise(r => setTimeout(r, 2000));
                    await this.fetchQr();
                    this.pollQr();
                },

                async fetchQr() {
                    try {
                        const r = await fetch('{{ route('channels.whatsapp.qr') }}');
                        const d = await r.json();
                        if (d.connected) {
                            this.connected = true;
                            this.phone = d.phone;
                            this.qr = null;
                            this.loading = false;
                            if (this.pollInterval) clearInterval(this.pollInterval);
                        } else if (d.qr) {
                            this.qr = d.qr;
                            this.loading = false;
                        }
                        // If waiting (STARTING, etc.) keep loading=true, poll will retry
                    } catch(e) { /* keep polling */ }
                },

                async stop() {
                    if (!confirm('Déconnecter WhatsApp ?')) return;
                    await fetch('{{ route('channels.whatsapp.stop') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                    });
                    this.connected = false; this.qr = null; this.phone = null;
                    this.loading = false; this.status = 'STOPPED';
                    if (this.pollInterval) clearInterval(this.pollInterval);
                },

                pollQr() {
                    if (this.pollInterval) clearInterval(this.pollInterval);
                    this.pollInterval = setInterval(() => this.fetchQr(), 3000);
                }
             }">

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
                    <span x-show="!connected && !loading && status !== 'unknown'" class="px-3 py-1 bg-gray-200 text-gray-600 rounded-full text-sm">Déconnecté</span>
                    <span x-show="!connected && loading" class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full text-sm animate-pulse">En cours...</span>
                </div>
            </div>

            <div x-show="connected && phone" class="mb-3 text-sm text-gray-600">
                📞 Connecté en tant que : <strong x-text="phone"></strong>
            </div>

            <div x-show="qr" class="mb-4 flex flex-col items-center">
                <p class="text-sm text-gray-600 mb-2">Scannez ce QR code avec WhatsApp :</p>
                <img :src="qr" class="w-48 h-48 border border-gray-200 rounded-xl">
                <p class="text-xs text-gray-400 mt-2 animate-pulse">Actualisation automatique toutes les 3s...</p>
            </div>

            <div x-show="loading && !qr && !connected" class="mb-4 flex items-center gap-2 text-sm text-yellow-700">
                <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Initialisation de la session WhatsApp...
            </div>

            <div class="flex gap-3">
                <button x-show="!connected && !loading" @click="start()"
                        class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition-colors">
                    📱 Connecter WhatsApp
                </button>
                <button x-show="connected" @click="stop()"
                        class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 transition-colors">
                    🔌 Déconnecter
                </button>
            </div>
        </div>
    </div>

    {{-- Auto-Update --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-gray-900 mb-1">🔄 Mise à jour automatique</h2>
                <p class="text-sm text-gray-500">Vérifie et installe les mises à jour tous les jours à 3h du matin.</p>
            </div>
            <form method="POST" action="{{ route('settings.auto-update') }}">
                @csrf
                <button type="submit"
                        class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors {{ $autoUpdateEnabled ? 'bg-indigo-600' : 'bg-gray-300' }}">
                    <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform {{ $autoUpdateEnabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                </button>
            </form>
        </div>
    </div>

    {{-- Enterprise Proxy --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-1">🌐 Proxy Entreprise</h2>
        <p class="text-sm text-gray-500 mb-5">Configurez un proxy HTTP si votre installation est derriere un pare-feu d'entreprise. Affecte tous les appels sortants (API IA, GitLab, telechargement modeles, etc.).</p>

        <form method="POST" action="{{ route('settings.proxy') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">HTTP Proxy</label>
                    <input type="text" name="http_proxy" value="{{ $proxyConfig['http'] ?? '' }}"
                           placeholder="http://proxy.company.com:8080"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">HTTPS Proxy</label>
                    <input type="text" name="https_proxy" value="{{ $proxyConfig['https'] ?? '' }}"
                           placeholder="http://proxy.company.com:8080"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">No-Proxy (exclusions, separes par virgule)</label>
                <input type="text" name="no_proxy" value="{{ $proxyConfig['no_proxy'] ?? '' }}"
                       placeholder="localhost,127.0.0.1,db,redis,waha,ollama,app"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono">
                <p class="text-xs text-gray-400 mt-1">Les services internes (db, redis, waha, ollama) sont automatiquement exclus.</p>
            </div>

            @if($proxyConfig['http'] || $proxyConfig['https'])
            <div class="p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
                ✓ Proxy configure — tous les appels HTTP sortants passeront par le proxy.
            </div>
            @endif

            <div class="flex items-center gap-3">
                <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                    💾 Sauvegarder le proxy
                </button>
                @if($proxyConfig['http'] || $proxyConfig['https'])
                <button type="submit" name="clear_proxy" value="1" class="bg-red-50 text-red-600 px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-100 transition-colors border border-red-200">
                    Supprimer le proxy
                </button>
                @endif
            </div>
        </form>
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

    {{-- Public AI Chat Customization --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-1">💬 Page Chat IA Publique</h2>
        <p class="text-sm text-gray-500 mb-2">
            Personnalisez la page de chat IA accessible sur le port dedie
            @if(config('services.public_chat.api_key'))
                — <a href="{{ url('/chat') }}" target="_blank" class="text-indigo-600 underline">Ouvrir la page</a>
            @endif
        </p>

        @if(!config('services.public_chat.api_key'))
        <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-700">
            La variable <code class="bg-yellow-100 px-1 rounded">CHAT_API_KEY</code> n'est pas configuree dans le <code>.env</code>.
            La page de chat ne fonctionnera pas sans cle API.
        </div>
        @endif

        <form method="POST" action="{{ route('settings.public-chat') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Titre</label>
                    <input type="text" name="public_chat_title" value="{{ $publicChat['title'] ?? '' }}"
                           placeholder="ZeniClaw AI"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Sous-titre</label>
                    <input type="text" name="public_chat_subtitle" value="{{ $publicChat['subtitle'] ?? '' }}"
                           placeholder="Assistant IA"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Message d'accueil</label>
                <input type="text" name="public_chat_welcome" value="{{ $publicChat['welcome'] ?? '' }}"
                       placeholder="Bonjour ! Comment puis-je vous aider ?"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Couleur principale</label>
                    <div class="flex gap-2 items-center">
                        <input type="color" name="public_chat_color" value="{{ $publicChat['color'] ?? '#4f46e5' }}"
                               class="w-10 h-10 rounded border border-gray-300 cursor-pointer">
                        <span class="text-xs text-gray-400">{{ $publicChat['color'] ?? '#4f46e5' }}</span>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">URL du logo (optionnel)</label>
                    <input type="url" name="public_chat_logo" value="{{ $publicChat['logo'] ?? '' }}"
                           placeholder="https://example.com/logo.png"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Placeholder du champ de saisie</label>
                <input type="text" name="public_chat_placeholder" value="{{ $publicChat['placeholder'] ?? '' }}"
                       placeholder="Tapez votre message..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>
            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                💾 Sauvegarder la personnalisation
            </button>
        </form>
    </div>

</div>
@endsection
