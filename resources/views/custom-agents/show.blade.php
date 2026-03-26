@extends('layouts.app')
@section('title', $customAgent->name)

@section('content')
<div class="space-y-6" x-data="customAgentPage()">

    {{-- Header --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-xl bg-purple-100 flex items-center justify-center text-3xl">{{ $customAgent->avatar }}</div>
                <div>
                    <h2 class="text-xl font-bold text-gray-900">{{ $customAgent->name }}</h2>
                    <p class="text-gray-500 text-sm">{{ $customAgent->description ?: 'Pas de description' }}</p>
                    <div class="flex items-center gap-2 mt-2">
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium {{ $customAgent->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $customAgent->is_active ? 'Actif' : 'Inactif' }}
                        </span>
                        <span class="px-2 py-1 bg-gray-100 rounded text-xs font-mono text-gray-600">{{ $customAgent->model }}</span>
                        <span class="px-2 py-1 bg-purple-50 rounded text-xs text-purple-600">{{ $customAgent->documents_count }} docs / {{ $customAgent->chunks_count }} chunks</span>
                        @if($customAgent->agent_class)
                        <span class="px-2 py-1 bg-amber-50 rounded text-xs text-amber-700 font-mono">Agent code</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex gap-2 items-center">
                <a href="{{ route('custom-agents.index', $agent) }}" class="px-3 py-2 border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-gray-50">&larr; Retour</a>
                <button @@click="toggleActive()" class="px-3 py-2 border rounded-lg text-sm font-medium transition-colors"
                    :class="isActive ? 'border-green-200 text-green-700 bg-green-50 hover:bg-green-100' : 'border-gray-200 text-gray-500 bg-gray-50 hover:bg-gray-100'">
                    <span x-text="isActive ? 'Actif' : 'Inactif'"></span>
                </button>
                <form method="POST" action="{{ route('custom-agents.destroy', [$agent, $customAgent]) }}"
                      x-data @@submit.prevent="if(confirm('Supprimer cet agent et toutes ses donnees ?')) $el.submit()">
                    @csrf @method('DELETE')
                    <button class="px-3 py-2 border border-red-200 rounded-lg text-sm text-red-600 hover:bg-red-50">Supprimer</button>
                </form>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm">{{ session('error') }}</div>
    @endif

    {{-- Tabs --}}
    <div x-data="{ tab: 'documents' }">
        <div class="flex gap-1 bg-white rounded-xl shadow-sm border border-gray-100 p-1 mb-4">
            @foreach(['documents'=>'📄 Documents', 'tools'=>'🛠️ Outils', 'access'=>'🔒 Acces', 'chat'=>'💬 Test Chat', 'settings'=>'⚙️ Parametres'] as $t => $l)
            <button @@click="tab = '{{ $t }}'"
                    :class="tab === '{{ $t }}' ? 'bg-indigo-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'"
                    class="flex-1 px-3 py-2 rounded-lg text-sm font-medium transition-all">{{ $l }}</button>
            @endforeach
        </div>

        {{-- DOCUMENTS tab --}}
        <div x-show="tab === 'documents'">
            <div class="grid gap-4 lg:grid-cols-3">

                {{-- Upload panel --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:col-span-1">
                    <h3 class="font-semibold text-gray-800 mb-4">Ajouter un document</h3>

                    <div x-data="{ uploadType: 'file' }" class="space-y-4">
                        <div class="flex gap-1 bg-gray-50 rounded-lg p-1">
                            @foreach(['file'=>'Fichier', 'text'=>'Texte', 'url'=>'URL'] as $ut => $ul)
                            <button @@click="uploadType = '{{ $ut }}'" type="button"
                                    :class="uploadType === '{{ $ut }}' ? 'bg-white shadow text-gray-800' : 'text-gray-500'"
                                    class="flex-1 px-2 py-1.5 rounded-md text-xs font-medium transition-all">{{ $ul }}</button>
                            @endforeach
                        </div>

                        {{-- File upload --}}
                        <form x-show="uploadType === 'file'" method="POST" action="{{ route('custom-agents.documents.upload', [$agent, $customAgent]) }}" enctype="multipart/form-data" class="space-y-3">
                            @csrf
                            <input type="hidden" name="type" value="file">
                            <div>
                                <input type="file" name="file" required accept=".pdf,.txt,.csv,.docx,.doc,.json,.xml,.md"
                                       class="w-full text-sm text-gray-500 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-600 hover:file:bg-indigo-100">
                                <p class="text-xs text-gray-400 mt-1">PDF, TXT, CSV, DOCX, JSON, XML (max 50 Mo)</p>
                            </div>
                            <input type="text" name="title" placeholder="Titre (optionnel)" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                            <button type="submit" class="w-full px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">Importer</button>
                        </form>

                        {{-- Text input --}}
                        <form x-show="uploadType === 'text'" method="POST" action="{{ route('custom-agents.documents.upload', [$agent, $customAgent]) }}" class="space-y-3">
                            @csrf
                            <input type="hidden" name="type" value="text">
                            <input type="text" name="title" required placeholder="Titre du document *" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                            <textarea name="content" required rows="6" placeholder="Collez votre texte ici..." class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm"></textarea>
                            <button type="submit" class="w-full px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">Ajouter</button>
                        </form>

                        {{-- URL input --}}
                        <form x-show="uploadType === 'url'" method="POST" action="{{ route('custom-agents.documents.upload', [$agent, $customAgent]) }}" class="space-y-3">
                            @csrf
                            <input type="hidden" name="type" value="url">
                            <input type="url" name="url" required placeholder="https://exemple.com/page" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                            <input type="text" name="title" placeholder="Titre (optionnel)" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                            <button type="submit" class="w-full px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">Importer l'URL</button>
                        </form>
                    </div>
                </div>

                {{-- Documents list --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 lg:col-span-2">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h3 class="font-semibold text-gray-800">Documents de formation ({{ $documents->count() }})</h3>
                    </div>
                    @forelse($documents as $doc)
                    <div class="px-5 py-3 flex items-center gap-3 border-b border-gray-50 last:border-0">
                        <span class="text-lg flex-shrink-0">
                            @switch($doc->type)
                                @case('pdf') 📕 @break
                                @case('url') 🌐 @break
                                @case('text') 📝 @break
                                @default 📄
                            @endswitch
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 truncate">{{ $doc->title }}</p>
                            <div class="flex items-center gap-2 text-xs text-gray-400 mt-0.5">
                                <span>{{ $doc->type }}</span>
                                @if($doc->source)<span class="truncate max-w-[200px]">{{ $doc->source }}</span>@endif
                                <span>{{ $doc->chunk_count }} chunks</span>
                                <span>{{ $doc->created_at->diffForHumans() }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="px-2 py-1 rounded-full text-xs font-medium
                                {{ $doc->status === 'ready' ? 'bg-green-100 text-green-700' : ($doc->status === 'failed' ? 'bg-red-100 text-red-700' : (in_array($doc->status, ['processing', 'pending']) ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-500')) }}">
                                @if($doc->status === 'pending') &#x23F3; en attente
                                @elseif($doc->status === 'processing') &#x2699; traitement...
                                @elseif($doc->status === 'ready') &#x2705; pret
                                @elseif($doc->status === 'failed') &#x274C; echec
                                @else {{ $doc->status }}
                                @endif
                            </span>
                            @if($doc->status === 'failed' && $doc->error_message)
                            <span class="text-xs text-red-400 max-w-[200px] truncate" title="{{ $doc->error_message }}">{{ $doc->error_message }}</span>
                            @endif
                            @if(in_array($doc->status, ['failed', 'pending']))
                            <form method="POST" action="{{ route('custom-agents.documents.reprocess', [$agent, $customAgent, $doc]) }}">
                                @csrf
                                <button class="px-2 py-1 text-xs bg-indigo-50 text-indigo-600 rounded-lg hover:bg-indigo-100 font-medium transition-colors">Relancer</button>
                            </form>
                            @endif
                            <form method="POST" action="{{ route('custom-agents.documents.destroy', [$agent, $customAgent, $doc]) }}"
                                  x-data @@submit.prevent="if(confirm('Supprimer ce document ?')) $el.submit()">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:underline">Suppr.</button>
                            </form>
                        </div>
                    </div>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">Aucun document. Ajoutez des fichiers, du texte ou des URLs pour former votre agent.</p>
                    @endforelse
                </div>

            </div>
        </div>

        {{-- TOOLS tab --}}
        <div x-show="tab === 'tools'">
            <form method="POST" action="{{ route('custom-agents.update-tools', [$agent, $customAgent]) }}" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                @csrf
                <div class="mb-4">
                    <h3 class="font-semibold text-gray-800">Capacites de l'agent</h3>
                    <p class="text-sm text-gray-500 mt-1">Activez les outils que votre agent peut utiliser. Sans outils, il repond uniquement avec ses connaissances (documents).</p>
                </div>

                <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                    @php $enabledTools = $customAgent->enabled_tools ?? []; @endphp
                    @foreach(\App\Services\CustomAgentRunner::TOOL_GROUPS as $groupKey => $group)
                    <label class="flex items-start gap-3 p-4 border rounded-xl cursor-pointer transition-all hover:border-indigo-300"
                           :class="document.querySelector('#tool_{{ $groupKey }}')?.checked ? 'border-indigo-400 bg-indigo-50/50' : 'border-gray-200'">
                        <input type="checkbox" name="enabled_tools[]" value="{{ $groupKey }}" id="tool_{{ $groupKey }}"
                               {{ in_array($groupKey, $enabledTools) ? 'checked' : '' }}
                               class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                               onchange="this.closest('label').classList.toggle('border-indigo-400', this.checked); this.closest('label').classList.toggle('bg-indigo-50/50', this.checked);">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-lg">{{ $group['icon'] }}</span>
                                <span class="font-medium text-gray-800 text-sm">{{ $group['label'] }}</span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">{{ $group['description'] }}</p>
                            <p class="text-xs text-gray-400 mt-1 font-mono">{{ implode(', ', $group['tools']) }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>

                <div class="flex justify-between items-center mt-6 pt-4 border-t border-gray-100">
                    <p class="text-xs text-gray-400">
                        @if(empty($enabledTools))
                            Mode actuel : <span class="font-medium text-gray-600">Knowledge only</span> (repond avec ses documents)
                        @else
                            Mode actuel : <span class="font-medium text-indigo-600">Knowledge + {{ count($enabledTools) }} groupe(s) d'outils</span>
                        @endif
                    </p>
                    <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">Sauvegarder</button>
                </div>
            </form>
        </div>

        {{-- ACCESS tab --}}
        <div x-show="tab === 'access'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-semibold text-gray-800 mb-2">Controle d'acces</h3>
                <p class="text-sm text-gray-500 mb-4">Definissez quels contacts WhatsApp peuvent utiliser cet agent. Laissez vide pour autoriser tout le monde.</p>

                <form method="POST" action="{{ route('custom-agents.update-access', [$agent, $customAgent]) }}">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Peers autorises (un par ligne)</label>
                            <textarea name="allowed_peers" rows="5"
                                      class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                      placeholder="33612345678@@s.whatsapp.net&#10;33698765432@@s.whatsapp.net&#10;120363012345@@g.us">{{ implode("\n", $customAgent->allowed_peers ?? []) }}</textarea>
                            <p class="text-xs text-gray-400 mt-1">Format: numero@@s.whatsapp.net (DM) ou id@@g.us (groupe)</p>
                        </div>

                        @if($agent->sessions->count() > 0)
                        <div>
                            <p class="text-sm font-medium text-gray-700 mb-2">Ajout rapide depuis les sessions connues :</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($agent->sessions()->orderByDesc('last_message_at')->take(20)->get() as $sess)
                                <button type="button"
                                        onclick="let ta = document.querySelector('textarea[name=allowed_peers]'); if(!ta.value.includes('{{ $sess->peer_id }}')) { ta.value = ta.value.trim() + (ta.value.trim() ? '\n' : '') + '{{ $sess->peer_id }}'; }"
                                        class="px-2 py-1 border border-gray-200 rounded-lg text-xs text-gray-600 hover:bg-gray-50 transition-colors">
                                    {{ str_contains($sess->peer_id, '@g.us') ? '👥' : '📱' }}
                                    {{ $sess->display_name ?: substr($sess->peer_id, 0, 20) }}
                                </button>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        <div class="flex justify-end pt-2">
                            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">Sauvegarder</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- TEST CHAT tab --}}
        <div x-show="tab === 'chat'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden" style="height: 600px;">
                <div class="flex flex-col h-full">
                    {{-- Chat header --}}
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                        <div class="flex items-center gap-2">
                            <span class="text-xl">{{ $customAgent->avatar }}</span>
                            <span class="font-semibold text-gray-800 text-sm">{{ $customAgent->name }}</span>
                            <span class="text-xs text-gray-400">— Test en direct</span>
                        </div>
                    </div>

                    {{-- Messages area --}}
                    <div class="flex-1 overflow-y-auto p-5 space-y-4" id="chatMessages" x-ref="chatMessages">
                        <template x-for="msg in messages" :key="msg.id">
                            <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                                <div :class="msg.role === 'user' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-800'"
                                     class="max-w-[80%] rounded-xl px-4 py-2.5 text-sm whitespace-pre-wrap" x-text="msg.content"></div>
                            </div>
                        </template>
                        <div x-show="loading" class="flex justify-start">
                            <div class="bg-gray-100 rounded-xl px-4 py-2.5 text-sm text-gray-400">En train de reflechir...</div>
                        </div>
                    </div>

                    {{-- Input --}}
                    <div class="px-5 py-3 border-t border-gray-100">
                        <form @@submit.prevent="sendMessage()" class="flex gap-2">
                            <input type="text" x-model="input" :disabled="loading"
                                   class="flex-1 px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                   placeholder="Posez une question a votre agent...">
                            <button type="submit" :disabled="loading || !input.trim()"
                                    class="px-4 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                Envoyer
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- SETTINGS tab --}}
        <div x-show="tab === 'settings'">
            <form method="POST" action="{{ route('custom-agents.update', [$agent, $customAgent]) }}" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">
                @csrf @method('PUT')

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                    <input type="text" name="name" value="{{ $customAgent->name }}" required maxlength="100"
                           class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2" maxlength="500"
                              class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">{{ $customAgent->description }}</textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Avatar (emoji)</label>
                    <input type="text" name="avatar" value="{{ $customAgent->avatar }}" maxlength="10"
                           class="w-20 px-4 py-2.5 border border-gray-200 rounded-lg text-2xl text-center focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">System Prompt</label>
                    <textarea name="system_prompt" rows="8" maxlength="5000"
                              class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">{{ $customAgent->system_prompt }}</textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Modele LLM</label>
                    <select name="model" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="default" {{ $customAgent->model === 'default' ? 'selected' : '' }}>Par defaut (modele de l'agent parent)</option>
                        @foreach(\App\Services\ModelResolver::allModels() as $modelId => $modelLabel)
                        <option value="{{ $modelId }}" {{ $customAgent->model === $modelId ? 'selected' : '' }}>{{ $modelLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">Sauvegarder</button>
                </div>
            </form>
        </div>

    </div>

</div>

<script>
function customAgentPage() {
    return {
        isActive: {{ $customAgent->is_active ? 'true' : 'false' }},
        messages: [],
        input: '',
        loading: false,
        msgId: 0,

        toggleActive() {
            fetch('{{ route("custom-agents.toggle", [$agent, $customAgent]) }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            })
            .then(r => r.json())
            .then(data => { this.isActive = data.is_active; });
        },

        async sendMessage() {
            const msg = this.input.trim();
            if (!msg) return;

            this.messages.push({ id: ++this.msgId, role: 'user', content: msg });
            this.input = '';
            this.loading = true;

            this.$nextTick(() => {
                this.$refs.chatMessages.scrollTop = this.$refs.chatMessages.scrollHeight;
            });

            try {
                const res = await fetch('{{ route("custom-agents.test-chat", [$agent, $customAgent]) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ message: msg }),
                });
                const data = await res.json();
                this.messages.push({ id: ++this.msgId, role: 'assistant', content: data.reply || 'Pas de reponse.' });
            } catch (e) {
                this.messages.push({ id: ++this.msgId, role: 'assistant', content: 'Erreur de communication.' });
            }

            this.loading = false;
            this.$nextTick(() => {
                this.$refs.chatMessages.scrollTop = this.$refs.chatMessages.scrollHeight;
            });
        }
    };
}
</script>
@endsection
