<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $customAgent->name }} — ZeniClaw Partner Portal</title>
<link rel="icon" href="/favicon.ico">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<style>
  body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
  .mono { font-family: 'Courier New', monospace; }
</style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen" x-data="partnerPortal()">

{{-- Header --}}
<header class="border-b border-gray-800 bg-gray-950/80 backdrop-blur sticky top-0 z-50">
  <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
    <div class="flex items-center gap-4">
      <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-2xl">{{ $customAgent->avatar }}</div>
      <div>
        <h1 class="text-lg font-bold">{{ $customAgent->name }}</h1>
        <p class="text-sm text-gray-400">{{ $customAgent->description ?: 'Agent IA personnalise' }}</p>
      </div>
    </div>
    <div class="flex items-center gap-4 text-sm">
      <span class="px-3 py-1 rounded-full {{ $customAgent->is_active ? 'bg-green-900/50 text-green-400' : 'bg-gray-800 text-gray-500' }}">
        {{ $customAgent->is_active ? 'Actif' : 'Inactif' }}
      </span>
      <span class="text-gray-500">{{ $customAgent->documents_count }} docs</span>
      <span class="text-gray-500">{{ $customAgent->chunks_count }} chunks</span>
      @if($share->partner_name)
        <span class="text-gray-500">{{ $share->partner_name }}</span>
      @endif
    </div>
  </div>
</header>

{{-- Flash messages --}}
<div class="max-w-6xl mx-auto px-6 mt-4">
  @if(session('success'))
    <div class="bg-green-900/30 border border-green-800 text-green-300 px-4 py-3 rounded-xl text-sm mb-4">{{ session('success') }}</div>
  @endif
  @if(session('error'))
    <div class="bg-red-900/30 border border-red-800 text-red-300 px-4 py-3 rounded-xl text-sm mb-4">{{ session('error') }}</div>
  @endif
</div>

{{-- Tabs --}}
<div class="max-w-6xl mx-auto px-6 mt-4">
  <div class="flex gap-1 bg-gray-900 rounded-xl p-1 mb-6">
    @foreach(['chat' => '💬 Chat', 'documents' => '📄 Documents', 'skills' => '⚡ Skills', 'scripts' => '💻 Scripts'] as $t => $l)
    <button @click="tab = '{{ $t }}'"
            :class="tab === '{{ $t }}' ? 'bg-blue-600 text-white shadow-lg' : 'text-gray-400 hover:text-gray-200'"
            class="flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-all">{{ $l }}</button>
    @endforeach
  </div>

  {{-- ══════ CHAT TAB ══════ --}}
  <div x-show="tab === 'chat'" class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden" style="height: 650px;">
    <div class="flex flex-col h-full">
      <div class="px-6 py-3 border-b border-gray-800 bg-gray-900/50">
        <div class="flex items-center gap-3">
          <span class="text-xl">{{ $customAgent->avatar }}</span>
          <span class="font-semibold text-sm">{{ $customAgent->name }}</span>
          <span class="text-xs text-gray-500">— Chat en direct</span>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto p-6 space-y-4" x-ref="chatMessages">
        <div x-show="messages.length === 0" class="text-center py-16 text-gray-500">
          <div class="text-4xl mb-3">{{ $customAgent->avatar }}</div>
          <p class="font-medium">Posez votre premiere question</p>
          <p class="text-sm mt-1">L'agent repondra en utilisant ses connaissances</p>
        </div>
        <template x-for="msg in messages" :key="msg.id">
          <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
            <div :class="msg.role === 'user' ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-100'"
                 class="max-w-[75%] rounded-2xl px-5 py-3 text-sm leading-relaxed whitespace-pre-wrap" x-text="msg.content"></div>
          </div>
        </template>
        <div x-show="loading" class="flex justify-start">
          <div class="bg-gray-800 rounded-2xl px-5 py-3 text-sm text-gray-400">
            <span class="inline-flex gap-1">
              <span class="animate-bounce" style="animation-delay: 0s">.</span>
              <span class="animate-bounce" style="animation-delay: 0.2s">.</span>
              <span class="animate-bounce" style="animation-delay: 0.4s">.</span>
            </span>
          </div>
        </div>
      </div>

      <div class="px-6 py-4 border-t border-gray-800">
        <form @submit.prevent="sendMessage()" class="flex gap-3">
          <input type="text" x-model="input" :disabled="loading"
                 class="flex-1 px-5 py-3 bg-gray-800 border border-gray-700 rounded-xl text-sm text-gray-100 placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none"
                 placeholder="Posez une question...">
          <button type="submit" :disabled="loading || !input.trim()"
                  class="px-6 py-3 bg-blue-600 text-white rounded-xl text-sm font-medium hover:bg-blue-700 disabled:opacity-40 transition-colors">
            Envoyer
          </button>
        </form>
      </div>
    </div>
  </div>

  {{-- ══════ DOCUMENTS TAB ══════ --}}
  <div x-show="tab === 'documents'">
    <div class="grid gap-6 lg:grid-cols-3">
      {{-- Upload --}}
      <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6 lg:col-span-1">
        <h3 class="font-semibold text-gray-200 mb-4">Ajouter un document</h3>
        <div x-data="{ uploadType: 'file' }" class="space-y-4">
          <div class="flex gap-1 bg-gray-800 rounded-lg p-1">
            @foreach(['file'=>'Fichier', 'text'=>'Texte', 'url'=>'URL'] as $ut => $ul)
            <button @click="uploadType = '{{ $ut }}'" type="button"
                    :class="uploadType === '{{ $ut }}' ? 'bg-gray-700 text-white' : 'text-gray-400'"
                    class="flex-1 px-2 py-1.5 rounded-md text-xs font-medium transition-all">{{ $ul }}</button>
            @endforeach
          </div>

          <form x-show="uploadType === 'file'" method="POST" action="{{ route('partner.documents.upload', $share->token) }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="hidden" name="type" value="file">
            <input type="file" name="file" required accept=".pdf,.txt,.csv,.docx,.doc,.json,.xml,.md"
                   class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-600 file:text-white hover:file:bg-blue-700">
            <p class="text-xs text-gray-500">PDF, TXT, CSV, DOCX, JSON, XML (max 50 Mo)</p>
            <input type="text" name="title" placeholder="Titre (optionnel)" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
            <button type="submit" class="w-full px-4 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Importer</button>
          </form>

          <form x-show="uploadType === 'text'" method="POST" action="{{ route('partner.documents.upload', $share->token) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="type" value="text">
            <input type="text" name="title" required placeholder="Titre *" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
            <textarea name="content" required rows="6" placeholder="Collez votre texte..." class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500"></textarea>
            <button type="submit" class="w-full px-4 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Ajouter</button>
          </form>

          <form x-show="uploadType === 'url'" method="POST" action="{{ route('partner.documents.upload', $share->token) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="type" value="url">
            <input type="url" name="url" required placeholder="https://..." class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
            <input type="text" name="title" placeholder="Titre (optionnel)" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
            <button type="submit" class="w-full px-4 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Importer l'URL</button>
          </form>
        </div>
      </div>

      {{-- Document list --}}
      <div class="bg-gray-900 rounded-2xl border border-gray-800 lg:col-span-2">
        <div class="px-6 py-4 border-b border-gray-800">
          <h3 class="font-semibold text-gray-200">Documents ({{ $documents->count() }})</h3>
        </div>
        @forelse($documents as $doc)
        <div class="px-6 py-3 flex items-center gap-3 border-b border-gray-800/50 last:border-0">
          <span class="text-lg">@switch($doc->type) @case('pdf') 📕 @break @case('url') 🌐 @break @case('text') 📝 @break @default 📄 @endswitch</span>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-200 truncate">{{ $doc->title }}</p>
            <div class="flex items-center gap-2 text-xs text-gray-500 mt-0.5">
              <span>{{ $doc->type }}</span>
              <span>{{ $doc->chunk_count ?? 0 }} chunks</span>
              <span>{{ $doc->created_at->diffForHumans() }}</span>
            </div>
          </div>
          <span class="px-2 py-1 rounded-full text-xs font-medium
            {{ $doc->status === 'ready' ? 'bg-green-900/50 text-green-400' : ($doc->status === 'failed' ? 'bg-red-900/50 text-red-400' : 'bg-yellow-900/50 text-yellow-400') }}">
            @if($doc->status === 'ready') ✅ pret @elseif($doc->status === 'failed') ❌ echec @else ⏳ {{ $doc->status }} @endif
          </span>
        </div>
        @empty
        <p class="px-6 py-10 text-sm text-gray-500 text-center">Aucun document. Ajoutez des fichiers pour former l'agent.</p>
        @endforelse
      </div>
    </div>
  </div>

  {{-- ══════ SKILLS TAB ══════ --}}
  <div x-show="tab === 'skills'">
    <div class="grid gap-6 lg:grid-cols-3">
      {{-- Create skill --}}
      <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6 lg:col-span-1">
        <h3 class="font-semibold text-gray-200 mb-4">Nouvelle routine</h3>
        <form method="POST" action="{{ route('partner.skills.store', $share->token) }}" class="space-y-3">
          @csrf
          <input type="text" name="name" required placeholder="Nom de la routine *" maxlength="150" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
          <textarea name="description" rows="2" placeholder="Description (optionnel)" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500"></textarea>
          <input type="text" name="trigger_phrase" placeholder="Phrase declencheur (ex: briefing du matin)" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
          <div>
            <label class="block text-xs text-gray-500 mb-1">Routine (JSON)</label>
            <textarea name="routine" required rows="6" placeholder='[{"type":"prompt","content":"Resume les derniers messages"}]'
                      class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500 mono"></textarea>
          </div>
          <button type="submit" class="w-full px-4 py-2.5 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700">Creer la routine</button>
        </form>
      </div>

      {{-- Skills list --}}
      <div class="lg:col-span-2 space-y-3">
        @forelse($skills as $skill)
        <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
          <div class="flex items-start justify-between mb-2">
            <div>
              <h4 class="font-semibold text-gray-200">⚡ {{ $skill->name }}</h4>
              @if($skill->trigger_phrase)
                <span class="text-xs text-purple-400 mono">"{{ $skill->trigger_phrase }}"</span>
              @endif
            </div>
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded text-xs {{ $skill->is_active ? 'bg-green-900/50 text-green-400' : 'bg-gray-800 text-gray-500' }}">
                {{ $skill->is_active ? 'Actif' : 'Inactif' }}
              </span>
              <form method="POST" action="{{ route('partner.skills.destroy', [$share->token, $skill]) }}" onsubmit="return confirm('Supprimer ?')">
                @csrf @method('DELETE')
                <button class="text-xs text-red-400 hover:text-red-300">Suppr.</button>
              </form>
            </div>
          </div>
          @if($skill->description)
            <p class="text-sm text-gray-400 mb-2">{{ $skill->description }}</p>
          @endif
          <pre class="text-xs bg-gray-800 rounded-lg p-3 text-gray-300 overflow-x-auto mono">{{ json_encode($skill->routine, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
        @empty
        <div class="bg-gray-900 rounded-xl border border-gray-800 p-10 text-center text-gray-500">
          <p class="text-lg mb-1">⚡</p>
          <p class="text-sm">Aucune routine. Creez des skills que l'agent pourra executer.</p>
        </div>
        @endforelse
      </div>
    </div>
  </div>

  {{-- ══════ SCRIPTS TAB ══════ --}}
  <div x-show="tab === 'scripts'">
    <div class="grid gap-6 lg:grid-cols-3">
      {{-- Create script --}}
      <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6 lg:col-span-1">
        <h3 class="font-semibold text-gray-200 mb-4">Nouveau script</h3>
        <form method="POST" action="{{ route('partner.scripts.store', $share->token) }}" class="space-y-3">
          @csrf
          <input type="text" name="name" required placeholder="Nom du script *" maxlength="150" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
          <textarea name="description" rows="2" placeholder="Description (optionnel)" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500"></textarea>
          <select name="language" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200">
            <option value="python">Python</option>
            <option value="php">PHP</option>
            <option value="bash">Bash</option>
            <option value="node">Node.js</option>
          </select>
          <textarea name="code" required rows="10" placeholder="# Votre code ici..." class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500 mono"></textarea>
          <button type="submit" class="w-full px-4 py-2.5 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">Creer le script</button>
        </form>
      </div>

      {{-- Scripts list --}}
      <div class="lg:col-span-2 space-y-3">
        @forelse($scripts as $script)
        <div class="bg-gray-900 rounded-xl border border-gray-800 p-5">
          <div class="flex items-start justify-between mb-2">
            <div>
              <h4 class="font-semibold text-gray-200">💻 {{ $script->name }}</h4>
              <span class="text-xs px-2 py-0.5 rounded bg-gray-800 text-gray-400 mono">{{ $script->language }}</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded text-xs {{ $script->is_active ? 'bg-green-900/50 text-green-400' : 'bg-gray-800 text-gray-500' }}">
                {{ $script->is_active ? 'Actif' : 'Inactif' }}
              </span>
              <form method="POST" action="{{ route('partner.scripts.destroy', [$share->token, $script]) }}" onsubmit="return confirm('Supprimer ?')">
                @csrf @method('DELETE')
                <button class="text-xs text-red-400 hover:text-red-300">Suppr.</button>
              </form>
            </div>
          </div>
          @if($script->description)
            <p class="text-sm text-gray-400 mb-2">{{ $script->description }}</p>
          @endif
          <pre class="text-xs bg-gray-800 rounded-lg p-3 text-gray-300 overflow-x-auto mono leading-relaxed">{{ $script->code }}</pre>
        </div>
        @empty
        <div class="bg-gray-900 rounded-xl border border-gray-800 p-10 text-center text-gray-500">
          <p class="text-lg mb-1">💻</p>
          <p class="text-sm">Aucun script. Ajoutez du code que l'agent pourra executer.</p>
        </div>
        @endforelse
      </div>
    </div>
  </div>

</div>

{{-- Footer --}}
<footer class="border-t border-gray-800 mt-12 py-6 text-center text-gray-600 text-sm">
  Powered by <a href="/" class="text-blue-500 hover:text-blue-400">ZeniClaw</a> — ZeniBiz &copy; 2026
</footer>

<script>
function partnerPortal() {
  return {
    tab: 'chat',
    messages: [],
    input: '',
    loading: false,
    msgId: 0,

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
        const res = await fetch('{{ route("partner.chat", $share->token) }}', {
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
</body>
</html>
