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
    @foreach(['chat' => '💬 Chat', 'documents' => '📄 Documents', 'skills' => '⚡ Skills', 'scripts' => '💻 Scripts', 'credentials' => '🔐 Credentials', 'endpoints' => '📊 API Metier'] as $t => $l)
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
            <div>
              <div :class="msg.role === 'user' ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-100'"
                   class="max-w-[75%] rounded-2xl px-5 py-3 text-sm leading-relaxed whitespace-pre-wrap" x-text="msg.content"></div>
              <template x-if="msg.role === 'assistant' && msg.model">
                <div class="mt-1 text-xs text-gray-600 flex items-center gap-1.5">
                  <span class="inline-block w-1.5 h-1.5 rounded-full bg-gray-600"></span>
                  <span x-text="msg.model"></span>
                </div>
              </template>
            </div>
          </div>
        </template>
        <div x-show="loading" class="flex justify-start">
          <div class="bg-gray-800 rounded-2xl px-5 py-3 text-sm text-gray-400 max-w-[75%]">
            <div class="flex items-center gap-2 mb-1">
              <span class="inline-block w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
              <span class="font-medium text-gray-300" x-text="loadingStatus"></span>
            </div>
            <div class="text-xs text-gray-500" x-text="loadingDetail"></div>
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
  <div x-show="tab === 'skills'" x-data="assistantChat('skill')">
    <div class="grid gap-6 lg:grid-cols-5">
      {{-- AI Assistant + Manual form --}}
      <div class="lg:col-span-2 space-y-4">
        {{-- AI Assistant --}}
        <div class="bg-gray-900 rounded-2xl border border-purple-800/50 p-5">
          <h3 class="font-semibold text-purple-300 mb-3 flex items-center gap-2">🤖 Assistant IA — Creer une routine</h3>
          <p class="text-xs text-gray-400 mb-3">Decrivez ce que vous voulez et l'IA vous guidera pour creer la routine.</p>

          <div class="bg-gray-950 rounded-xl p-3 mb-3 min-h-[300px] max-h-[500px] overflow-y-auto space-y-2" x-ref="assistMsgsSkill">
            <template x-for="msg in assistMessages" :key="msg.id">
              <div :class="msg.role === 'user' ? 'text-right' : ''">
                <span :class="msg.role === 'user' ? 'bg-purple-700 text-white' : 'bg-gray-800 text-gray-200'"
                      class="inline-block rounded-xl px-3 py-2 text-xs max-w-[90%] whitespace-pre-wrap" x-text="msg.content"></span>
              </div>
            </template>
            <div x-show="assistLoading" class="text-xs text-gray-500">Reflexion...</div>
          </div>

          <form @submit.prevent="sendAssist()" class="space-y-2">
            <textarea x-model="assistInput" :disabled="assistLoading" rows="3" placeholder="Ex: je veux un briefing du matin qui resume les taches en cours, verifie les rappels et propose 3 priorites..."
                   class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500 outline-none focus:border-purple-500 resize-y"
                   @keydown.ctrl.enter="sendAssist()" @keydown.meta.enter="sendAssist()"></textarea>
            <div class="flex justify-between items-center">
              <span class="text-xs text-gray-600">Ctrl+Enter pour envoyer</span>
              <button type="submit" :disabled="assistLoading || !assistInput.trim()"
                      class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700 disabled:opacity-40">Envoyer</button>
            </div>
          </form>

          {{-- Auto-fill form when AI generates the skill --}}
          <template x-if="generatedData">
            <div class="mt-3 p-3 bg-green-900/30 border border-green-800 rounded-xl">
              <p class="text-xs text-green-400 font-medium mb-2">✅ Routine generee ! Verifiez et sauvegardez :</p>
              <button @click="fillForm()" class="w-full px-3 py-2 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700">Remplir le formulaire</button>
            </div>
          </template>
        </div>

        {{-- Manual form --}}
        <div class="bg-gray-900 rounded-2xl border border-gray-800 p-5">
          <h3 class="font-semibold text-gray-200 mb-3 text-sm">Ou creer manuellement</h3>
          <form method="POST" action="{{ route('partner.skills.store', $share->token) }}" class="space-y-3" x-ref="skillForm">
            @csrf
            <input type="text" name="name" required placeholder="Nom *" maxlength="150" x-ref="skillName" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
            <textarea name="description" rows="2" placeholder="Description" x-ref="skillDesc" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500"></textarea>
            <input type="text" name="trigger_phrase" placeholder="Phrase declencheur" x-ref="skillTrigger" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
            <textarea name="routine" required rows="6" placeholder='[{"type":"prompt","content":"..."}]' x-ref="skillRoutine"
                      class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500 mono"></textarea>
            <button type="submit" class="w-full px-4 py-2.5 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700">Sauvegarder</button>
          </form>
        </div>
      </div>

      {{-- Skills list --}}
      <div class="lg:col-span-3 space-y-3">
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
  <div x-show="tab === 'scripts'" x-data="assistantChat('script')">
    <div class="grid gap-6 lg:grid-cols-5">
      {{-- AI Assistant + Manual form --}}
      <div class="lg:col-span-2 space-y-4">
        {{-- AI Assistant --}}
        <div class="bg-gray-900 rounded-2xl border border-green-800/50 p-5">
          <h3 class="font-semibold text-green-300 mb-3 flex items-center gap-2">🤖 Assistant IA — Creer un script</h3>
          <p class="text-xs text-gray-400 mb-3">Decrivez ce que le script doit faire et l'IA generera le code.</p>

          <div class="bg-gray-950 rounded-xl p-3 mb-3 min-h-[300px] max-h-[500px] overflow-y-auto space-y-2" x-ref="assistMsgsScript">
            <template x-for="msg in assistMessages" :key="msg.id">
              <div :class="msg.role === 'user' ? 'text-right' : ''">
                <span :class="msg.role === 'user' ? 'bg-green-700 text-white' : 'bg-gray-800 text-gray-200'"
                      class="inline-block rounded-xl px-3 py-2 text-xs max-w-[90%] whitespace-pre-wrap" x-text="msg.content"></span>
              </div>
            </template>
            <div x-show="assistLoading" class="text-xs text-gray-500">Reflexion...</div>
          </div>

          <form @submit.prevent="sendAssist()" class="space-y-2">
            <textarea x-model="assistInput" :disabled="assistLoading" rows="3" placeholder="Ex: un script Python qui genere un rapport CSV a partir des conversations de la semaine..."
                   class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500 outline-none focus:border-green-500 resize-y"
                   @keydown.ctrl.enter="sendAssist()" @keydown.meta.enter="sendAssist()"></textarea>
            <div class="flex justify-between items-center">
              <span class="text-xs text-gray-600">Ctrl+Enter pour envoyer</span>
              <button type="submit" :disabled="assistLoading || !assistInput.trim()"
                      class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-40">Envoyer</button>
            </div>
          </form>

          <template x-if="generatedData">
            <div class="mt-3 p-3 bg-green-900/30 border border-green-800 rounded-xl">
              <p class="text-xs text-green-400 font-medium mb-2">✅ Script genere ! Verifiez et sauvegardez :</p>
              <button @click="fillForm()" class="w-full px-3 py-2 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700">Remplir le formulaire</button>
            </div>
          </template>
        </div>

        {{-- Manual form --}}
        <div class="bg-gray-900 rounded-2xl border border-gray-800 p-5">
          <h3 class="font-semibold text-gray-200 mb-3 text-sm">Ou creer manuellement</h3>
          <form method="POST" action="{{ route('partner.scripts.store', $share->token) }}" class="space-y-3" x-ref="scriptForm">
            @csrf
            <input type="text" name="name" required placeholder="Nom *" maxlength="150" x-ref="scriptName" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
            <textarea name="description" rows="2" placeholder="Description" x-ref="scriptDesc" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500"></textarea>
            <select name="language" required x-ref="scriptLang" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200">
              <option value="python">Python</option>
              <option value="php">PHP</option>
              <option value="bash">Bash</option>
              <option value="node">Node.js</option>
            </select>
            <textarea name="code" required rows="10" placeholder="# Code..." x-ref="scriptCode" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500 mono"></textarea>
            <button type="submit" class="w-full px-4 py-2.5 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">Sauvegarder</button>
          </form>
        </div>
      </div>

      {{-- Scripts list --}}
      <div class="lg:col-span-3 space-y-3">
        @forelse($scripts as $script)
        <div class="bg-gray-900 rounded-xl border border-gray-800 p-5" x-data="scriptCard_{{ $script->id }}()">
          <div class="flex items-start justify-between mb-2">
            <div>
              <h4 class="font-semibold text-gray-200">💻 {{ $script->name }}</h4>
              <span class="text-xs px-2 py-0.5 rounded bg-gray-800 text-gray-400 mono">{{ $script->language }}</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded text-xs {{ $script->is_active ? 'bg-green-900/50 text-green-400' : 'bg-gray-800 text-gray-500' }}">
                {{ $script->is_active ? 'Actif' : 'Inactif' }}
              </span>
              <button @click="editing = !editing" class="text-xs text-blue-400 hover:text-blue-300">Modifier</button>
              <form method="POST" action="{{ route('partner.scripts.destroy', [$share->token, $script]) }}" onsubmit="return confirm('Supprimer ?')">
                @csrf @method('DELETE')
                <button class="text-xs text-red-400 hover:text-red-300">Suppr.</button>
              </form>
            </div>
          </div>
          @if($script->description)
            <p class="text-sm text-gray-400 mb-2">{{ $script->description }}</p>
          @endif

          {{-- View mode --}}
          <div x-show="!editing">
            <pre class="text-xs bg-gray-800 rounded-lg p-3 text-gray-300 overflow-x-auto mono leading-relaxed max-h-[400px] overflow-y-auto" x-text="code"></pre>
          </div>

          {{-- Edit mode --}}
          <div x-show="editing" x-cloak>
            {{-- AI Edit --}}
            <div class="mb-3 flex gap-2">
              <input type="text" x-model="aiInstruction" placeholder="Instruction IA: ex. ajoute la gestion d'erreurs..."
                     class="flex-1 px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-xs text-gray-200 placeholder-gray-500 outline-none focus:border-purple-500">
              <button @click="aiEdit()" :disabled="aiLoading || !aiInstruction.trim()"
                      class="px-3 py-2 bg-purple-600 text-white rounded-lg text-xs font-medium hover:bg-purple-700 disabled:opacity-40 whitespace-nowrap flex items-center gap-1">
                <template x-if="aiLoading"><svg class="animate-spin h-3 w-3" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                <span x-text="aiLoading ? 'IA...' : '🤖 Modifier via IA'"></span>
              </button>
            </div>

            {{-- Code editor --}}
            <textarea x-model="code" rows="15" class="w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-lg text-xs text-green-300 mono leading-relaxed outline-none focus:border-green-500 resize-y"></textarea>

            <div class="flex items-center justify-between mt-2">
              <button @click="editing = false" class="px-3 py-1.5 text-xs text-gray-400 hover:text-gray-200">Annuler</button>
              <button @click="saveCode()" :disabled="saving"
                      class="px-4 py-2 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700 disabled:opacity-40">
                <span x-text="saving ? 'Sauvegarde...' : 'Sauvegarder'"></span>
              </button>
            </div>
          </div>

          {{-- Run section --}}
          <div class="mt-3 border-t border-gray-800 pt-3">
            <div class="flex items-center gap-2">
              <button @click="runScript()" :disabled="running"
                      class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-700 disabled:opacity-40 flex items-center gap-1">
                <template x-if="running"><svg class="animate-spin h-3 w-3" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                <span x-text="running ? 'Execution...' : '▶ Executer'"></span>
              </button>
              <input type="text" x-model="runArgs" placeholder="Arguments (optionnel)"
                     class="flex-1 px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-xs text-gray-200 placeholder-gray-500 outline-none focus:border-indigo-500">
              <select x-model="runTimeout" class="px-2 py-2 bg-gray-800 border border-gray-700 rounded-lg text-xs text-gray-400 outline-none">
                <option value="60">1min</option>
                <option value="300">5min</option>
                <option value="600" selected>10min</option>
                <option value="1800">30min</option>
                <option value="3600">1h</option>
              </select>
            </div>

            {{-- Output --}}
            <template x-if="runResult !== null">
              <div class="mt-2 rounded-lg overflow-hidden">
                <div class="flex items-center justify-between px-3 py-1.5 text-xs"
                     :class="runResult.success === null ? 'bg-blue-900/40 text-blue-400' : (runResult.success ? 'bg-green-900/40 text-green-400' : 'bg-red-900/40 text-red-400')">
                  <span x-text="runResult.success === null ? '⏳ En cours...' : (runResult.success ? '✓ Exit code: ' + runResult.exit_code : '✗ Exit code: ' + runResult.exit_code)"></span>
                  <button @click="runResult = null" class="text-gray-500 hover:text-gray-300" x-show="!running">✕</button>
                </div>
                <pre x-show="runResult.output" x-ref="scriptOutput"
                     class="text-xs bg-gray-950 p-3 text-gray-300 overflow-x-auto mono leading-relaxed max-h-[400px] overflow-y-auto"
                     x-text="runResult.output" x-effect="if($refs.scriptOutput) $refs.scriptOutput.scrollTop = $refs.scriptOutput.scrollHeight"></pre>
                <pre x-show="runResult.error_output"
                     class="text-xs bg-gray-950 p-3 text-red-400 overflow-x-auto mono leading-relaxed max-h-[150px] overflow-y-auto border-t border-gray-800"
                     x-text="runResult.error_output"></pre>
                <div x-show="runResult.ai_analysis" class="border-t border-gray-800 bg-indigo-950/40 p-4">
                  <div class="flex items-center gap-2 mb-2">
                    <span class="text-indigo-400 text-xs font-semibold uppercase tracking-wider">Analyse IA</span>
                  </div>
                  <div class="text-sm text-gray-200 leading-relaxed whitespace-pre-wrap" x-text="runResult.ai_analysis"></div>
                </div>
              </div>
            </template>
          </div>
        </div>
        <script>
        function scriptCard_{{ $script->id }}() {
          return {
            editing: false, saving: false, running: false,
            aiLoading: false, aiInstruction: '', runArgs: '', runTimeout: '600',
            code: @js($script->code),
            runResult: null,
            async runScript() {
              this.running = true;
              this.runResult = { success: null, exit_code: null, output: '', error_output: '', ai_analysis: '' };
              try {
                const res = await fetch('{{ route("partner.scripts.runStream", [$share->token, $script]) }}', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                  body: JSON.stringify({ args: this.runArgs, timeout: parseInt(this.runTimeout) }),
                });
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                while (true) {
                  const { done, value } = await reader.read();
                  if (done) break;
                  buffer += decoder.decode(value, { stream: true });
                  const lines = buffer.split('\n');
                  buffer = lines.pop();
                  for (const line of lines) {
                    if (line.startsWith('event: ')) { this._sseEvent = line.slice(7); continue; }
                    if (line.startsWith('data: ')) {
                      try {
                        const data = JSON.parse(line.slice(6));
                        if (this._sseEvent === 'stdout') this.runResult.output += data.text;
                        else if (this._sseEvent === 'stderr') this.runResult.error_output += data.text;
                        else if (this._sseEvent === 'status') this.runResult.output += '⏳ ' + data.message + '\n';
                        else if (this._sseEvent === 'error') this.runResult.error_output += '❌ ' + data.message + '\n';
                        else if (this._sseEvent === 'analysis') this.runResult.ai_analysis = data.text;
                        else if (this._sseEvent === 'done') {
                          this.runResult.exit_code = data.exit_code;
                          this.runResult.success = data.exit_code === 0;
                        }
                      } catch(e) {}
                    }
                  }
                }
              } catch(e) {
                this.runResult.error_output += e.message;
                this.runResult.success = false;
                this.runResult.exit_code = -1;
              }
              if (this.runResult.exit_code === null) { this.runResult.exit_code = -1; this.runResult.success = false; }
              this.running = false;
            },
            async saveCode() {
              this.saving = true;
              try {
                await fetch('{{ route("partner.scripts.update", [$share->token, $script]) }}', {
                  method: 'PUT',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                  body: JSON.stringify({ name: @js($script->name), language: @js($script->language), code: this.code, is_active: {{ $script->is_active ? 'true' : 'false' }} }),
                });
                this.editing = false;
              } catch(e) {}
              this.saving = false;
            },
            async aiEdit() {
              this.aiLoading = true;
              try {
                const res = await fetch('{{ route("partner.scripts.aiEdit", [$share->token, $script]) }}', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                  body: JSON.stringify({ instruction: this.aiInstruction }),
                });
                const data = await res.json();
                if (data.code) { this.code = data.code; this.aiInstruction = ''; }
              } catch(e) {}
              this.aiLoading = false;
            },
          };
        }
        </script>
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

  {{-- ══════ CREDENTIALS TAB ══════ --}}
  <div x-show="tab === 'credentials'">
    <div class="grid gap-6 lg:grid-cols-5">
      <div class="lg:col-span-2">
        <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6">
          <h3 class="font-semibold text-gray-200 mb-2">Ajouter un credential</h3>
          <p class="text-xs text-gray-400 mb-4">Les credentials sont chiffres (AES-256) et accessibles uniquement par cet agent. Utilisez-les pour les cles API, tokens, mots de passe de services externes.</p>

          <form method="POST" action="{{ route('partner.credentials.store', $share->token) }}" class="space-y-3">
            @csrf
            <div>
              <label class="block text-xs text-gray-500 mb-1">Cle (identifiant) *</label>
              <input type="text" name="key" required placeholder="ex: api_token, db_password, openai_key" maxlength="100" pattern="[a-zA-Z_][a-zA-Z0-9_]*"
                     class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500 mono">
              <p class="text-xs text-gray-600 mt-1">Lettres, chiffres et _ uniquement</p>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Valeur *</label>
              <textarea name="value" required rows="3" placeholder="La valeur secrete (sera chiffree)"
                        class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500 mono"></textarea>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Description (optionnel)</label>
              <input type="text" name="description" placeholder="A quoi sert ce credential" maxlength="200"
                     class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-gray-200 placeholder-gray-500">
            </div>
            <button type="submit" class="w-full px-4 py-2.5 bg-amber-600 text-white rounded-lg text-sm font-medium hover:bg-amber-700">Sauvegarder (chiffre)</button>
          </form>
        </div>
      </div>

      <div class="lg:col-span-3 space-y-3">
        <div class="bg-gray-900 rounded-2xl border border-gray-800">
          <div class="px-6 py-4 border-b border-gray-800">
            <h3 class="font-semibold text-gray-200">Credentials stockes ({{ $credentials->count() }})</h3>
            <p class="text-xs text-gray-500 mt-1">Les valeurs sont chiffrees — seul l'agent peut les lire a l'execution.</p>
          </div>
          @forelse($credentials as $cred)
          <div class="px-6 py-3 flex items-center gap-3 border-b border-gray-800/50 last:border-0">
            <span class="text-lg">🔑</span>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-200 mono">{{ $cred->key }}</p>
              <div class="flex items-center gap-2 text-xs text-gray-500 mt-0.5">
                @if($cred->description)<span>{{ $cred->description }}</span>@endif
                <span>{{ $cred->updated_at->diffForHumans() }}</span>
              </div>
            </div>
            <span class="px-2 py-1 bg-gray-800 rounded text-xs text-gray-400 mono">●●●●●●●●</span>
            <form method="POST" action="{{ route('partner.credentials.destroy', [$share->token, $cred]) }}" onsubmit="return confirm('Supprimer ce credential ?')">
              @csrf @method('DELETE')
              <button class="text-xs text-red-400 hover:text-red-300">Suppr.</button>
            </form>
          </div>
          @empty
          <div class="px-6 py-10 text-center text-gray-500">
            <p class="text-lg mb-1">🔐</p>
            <p class="text-sm">Aucun credential. Ajoutez des cles API ou tokens pour que l'agent puisse se connecter a des services externes.</p>
          </div>
          @endforelse
        </div>
      </div>
    </div>
  </div>

</div>

  {{-- ══════ API METIER TAB ══════ --}}
  <div x-show="tab === 'endpoints'" x-data="endpointManager()" class="space-y-6">

    {{-- Swagger Import --}}
    <div class="bg-gradient-to-r from-blue-900/30 to-purple-900/30 rounded-2xl border border-blue-800/50 p-6">
      <div class="flex items-center gap-3 mb-4">
        <span class="text-2xl">🔗</span>
        <div>
          <h2 class="text-lg font-bold">Import automatique depuis Swagger / OpenAPI</h2>
          <p class="text-sm text-gray-400">Collez l'URL de votre api-docs.json et l'IA configure tout automatiquement</p>
        </div>
      </div>

      <div class="flex gap-3">
        <input x-model="swaggerUrl" type="text" placeholder="https://api.example.com/api-docs.json ou https://api.example.com/openapi.yaml"
          class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-sm font-mono focus:border-blue-500 focus:outline-none">
        <button @click="analyzeSwagger()" :disabled="swaggerLoading"
          class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 disabled:bg-gray-700 rounded-xl text-sm font-medium transition-colors whitespace-nowrap flex items-center gap-2">
          <span x-show="!swaggerLoading">Analyser l'API</span>
          <span x-show="swaggerLoading" class="flex items-center gap-2">
            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            Analyse en cours...
          </span>
        </button>
      </div>

      {{-- Swagger analysis results --}}
      <template x-if="swaggerResult">
        <div class="mt-6 space-y-4">
          {{-- API info --}}
          <div class="flex items-center justify-between bg-gray-800/50 rounded-xl p-4">
            <div>
              <h3 class="font-bold" x-text="swaggerResult.spec_info?.title"></h3>
              <p class="text-sm text-gray-400">
                <span x-text="'v' + swaggerResult.spec_info?.version"></span> —
                <span x-text="swaggerResult.spec_info?.total_endpoints + ' endpoints detectes'"></span> —
                Base: <span class="font-mono text-xs" x-text="swaggerResult.spec_info?.base_url || '(auto)'"></span>
              </p>
              <p class="text-xs text-gray-500 mt-1" x-text="swaggerResult.spec_info?.description"></p>
            </div>
            <div class="text-right">
              <p class="text-xs text-gray-400">Auth detectee: <span class="text-blue-400 font-mono" x-text="swaggerResult.auth_scheme?.type"></span></p>
              <div class="mt-2">
                <label class="text-xs text-gray-400">Credential a utiliser:</label>
                <select x-model="swaggerAuthKey" class="ml-2 bg-gray-700 border border-gray-600 rounded px-2 py-1 text-xs">
                  <option value="">— Aucune —</option>
                  @foreach($credentials as $cred)
                    <option value="{{ $cred->key }}">{{ $cred->key }}</option>
                  @endforeach
                </select>
              </div>
            </div>
          </div>

          {{-- Endpoint selection --}}
          <div class="space-y-2">
            <div class="flex items-center justify-between mb-2">
              <p class="text-sm font-semibold text-gray-300">Selectionnez les endpoints a importer:</p>
              <div class="flex gap-2">
                <button @click="swaggerResult.endpoints.forEach(e => e.selected = true)" class="text-xs text-blue-400 hover:text-blue-300">Tout selectionner</button>
                <button @click="swaggerResult.endpoints.forEach(e => e.selected = false)" class="text-xs text-gray-400 hover:text-gray-300">Tout deselectionner</button>
              </div>
            </div>

            <template x-for="(ep, i) in swaggerResult.endpoints" :key="i">
              <div class="flex items-start gap-3 p-3 rounded-lg border transition-colors"
                :class="ep.selected ? 'bg-gray-800/70 border-blue-700/50' : 'bg-gray-900/50 border-gray-800 opacity-50'">
                <input type="checkbox" x-model="ep.selected" class="mt-1 rounded">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <span class="px-1.5 py-0.5 rounded text-xs font-mono font-bold"
                      :class="{
                        'bg-green-900/50 text-green-400': ep.method === 'GET',
                        'bg-blue-900/50 text-blue-400': ep.method === 'POST',
                        'bg-yellow-900/50 text-yellow-400': ep.method === 'PUT',
                        'bg-orange-900/50 text-orange-400': ep.method === 'PATCH',
                        'bg-red-900/50 text-red-400': ep.method === 'DELETE',
                      }" x-text="ep.method"></span>
                    <span class="font-semibold text-sm" x-text="ep.name"></span>
                    <span class="font-mono text-xs text-gray-500" x-text="ep.path"></span>
                  </div>
                  <p class="text-xs text-gray-400 mb-1" x-text="ep.description"></p>
                  <div class="flex flex-wrap gap-1">
                    <template x-for="phrase in ep.trigger_phrases" :key="phrase">
                      <span class="px-1.5 py-0.5 bg-blue-900/30 text-blue-300 rounded text-xs" x-text="'&quot;' + phrase + '&quot;'"></span>
                    </template>
                  </div>
                  <template x-if="ep.parameters?.length > 0">
                    <div class="flex flex-wrap gap-1 mt-1">
                      <template x-for="p in ep.parameters" :key="p.name">
                        <span class="px-1.5 py-0.5 bg-purple-900/30 text-purple-300 rounded text-xs">
                          <span x-text="p.name" class="font-mono"></span>:<span x-text="p.type"></span>
                        </span>
                      </template>
                    </div>
                  </template>
                </div>
              </div>
            </template>
          </div>

          {{-- Import button --}}
          <form method="POST" action="{{ route('partner.endpoints.swagger.import', $share->token) }}">
            @csrf
            <input type="hidden" name="auth_credential_key" :value="swaggerAuthKey">
            <input type="hidden" name="endpoints" :value="JSON.stringify(swaggerResult.endpoints)">
            <div class="flex items-center justify-between pt-2">
              <p class="text-sm text-gray-400">
                <span x-text="swaggerResult.endpoints.filter(e => e.selected).length"></span> endpoint(s) selectionne(s)
              </p>
              <button type="submit" :disabled="swaggerResult.endpoints.filter(e => e.selected).length === 0"
                class="px-6 py-2.5 bg-green-600 hover:bg-green-500 disabled:bg-gray-700 disabled:text-gray-500 rounded-xl text-sm font-medium transition-colors">
                Importer les endpoints selectionnes
              </button>
            </div>
          </form>
        </div>
      </template>

      {{-- Error display --}}
      <template x-if="swaggerError">
        <div class="mt-4 bg-red-900/20 border border-red-800 rounded-xl p-4 text-sm text-red-300" x-text="swaggerError"></div>
      </template>
    </div>

    {{-- Existing endpoints list (grouped by resource) --}}
    <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
          <h2 class="text-lg font-bold">Endpoints API configures</h2>
          @if($endpoints->count() > 0)
            <span class="px-2 py-0.5 bg-blue-900/30 text-blue-400 rounded-full text-xs font-medium">{{ $endpoints->count() }}</span>
          @endif
        </div>
        @if($endpoints->count() > 0)
          <form method="POST" action="{{ route('partner.endpoints.destroyAll', $share->token) }}" onsubmit="return confirm('Supprimer TOUS les {{ $endpoints->count() }} endpoints ? Cette action est irreversible.')">
            @csrf @method('DELETE')
            <button class="px-3 py-1.5 bg-red-900/30 hover:bg-red-900/50 border border-red-800 text-red-400 hover:text-red-300 rounded-lg text-sm transition-colors">Tout supprimer</button>
          </form>
        @endif
      </div>

      @if($endpoints->count() > 0)
        @php
          // Group endpoints by resource extracted from URL path
          $grouped = $endpoints->groupBy(function ($ep) {
              // Extract resource from path: /api/v1/invoices/... → Invoices
              if (preg_match('#/(?:api/)?(?:v\d+/)?([a-z_-]+)#i', parse_url($ep->url, PHP_URL_PATH) ?? '', $m)) {
                  return ucfirst(str_replace(['-', '_'], ' ', $m[1]));
              }
              return 'Autres';
          })->sortKeys();
        @endphp

        <div x-data="{ openGroup: null }" class="space-y-2">
          @foreach($grouped as $group => $groupEndpoints)
            <div class="border border-gray-700 rounded-xl overflow-hidden">
              {{-- Group header --}}
              <button @click="openGroup = openGroup === '{{ $group }}' ? null : '{{ $group }}'"
                class="w-full flex items-center justify-between px-4 py-3 bg-gray-800/50 hover:bg-gray-800 transition-colors text-left">
                <div class="flex items-center gap-3">
                  <svg class="w-4 h-4 text-gray-400 transition-transform" :class="openGroup === '{{ $group }}' ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                  <span class="font-semibold text-sm">{{ $group }}</span>
                  <span class="px-1.5 py-0.5 bg-gray-700 text-gray-300 rounded text-xs">{{ $groupEndpoints->count() }}</span>
                  <div class="flex gap-1">
                    @foreach($groupEndpoints->pluck('method')->unique() as $m)
                      <span class="px-1 py-0.5 rounded text-xs font-mono
                        {{ match($m) { 'GET' => 'bg-green-900/40 text-green-500', 'POST' => 'bg-blue-900/40 text-blue-500', 'PUT' => 'bg-yellow-900/40 text-yellow-500', 'PATCH' => 'bg-orange-900/40 text-orange-500', 'DELETE' => 'bg-red-900/40 text-red-500', default => 'bg-gray-700 text-gray-400' } }}">{{ $m }}</span>
                    @endforeach
                  </div>
                </div>
              </button>

              {{-- Group content --}}
              <div x-show="openGroup === '{{ $group }}'" x-collapse class="border-t border-gray-700">
                @foreach($groupEndpoints as $ep)
                  <div x-data="{ testing: false, testResult: null, testLoading: false, testParams: '{}' }" class="px-4 py-3 border-b border-gray-800 last:border-b-0 hover:bg-gray-800/30 transition-colors">
                    <div class="flex items-center justify-between mb-1">
                      <div class="flex items-center gap-2">
                        <span class="px-1.5 py-0.5 rounded text-xs font-mono font-bold
                          {{ match($ep->method) { 'GET' => 'bg-green-900/50 text-green-400', 'POST' => 'bg-blue-900/50 text-blue-400', 'PUT' => 'bg-yellow-900/50 text-yellow-400', 'PATCH' => 'bg-orange-900/50 text-orange-400', 'DELETE' => 'bg-red-900/50 text-red-400', default => 'bg-gray-700 text-gray-300' } }}">{{ $ep->method }}</span>
                        <span class="font-medium text-sm">{{ $ep->name }}</span>
                        @if(!$ep->is_active)
                          <span class="px-1.5 py-0.5 bg-gray-800 text-gray-500 rounded text-xs">Inactif</span>
                        @endif
                      </div>
                      <div class="flex items-center gap-2">
                        <button @click="testing = !testing; testResult = null" class="text-yellow-500/70 hover:text-yellow-400 text-xs">tester</button>
                        <form method="POST" action="{{ route('partner.endpoints.destroy', [$share->token, $ep->id]) }}" onsubmit="return confirm('Supprimer ?')">
                          @csrf @method('DELETE')
                          <button class="text-red-500/60 hover:text-red-400 text-xs">supprimer</button>
                        </form>
                      </div>
                    </div>
                    <p class="text-xs text-gray-500 font-mono mb-1">{{ $ep->url }}</p>
                    @if($ep->trigger_phrases)
                      <div class="flex flex-wrap gap-1 mt-1">
                        @foreach(array_slice($ep->trigger_phrases, 0, 4) as $phrase)
                          <span class="px-1.5 py-0.5 bg-blue-900/20 text-blue-400/80 rounded text-xs">"{{ $phrase }}"</span>
                        @endforeach
                        @if(count($ep->trigger_phrases) > 4)
                          <span class="text-xs text-gray-600">+{{ count($ep->trigger_phrases) - 4 }}</span>
                        @endif
                      </div>
                    @endif

                    {{-- Test panel --}}
                    <div x-show="testing" x-collapse class="mt-3 pt-3 border-t border-gray-700/50">
                      <div class="flex gap-2 items-end">
                        @if(!empty($ep->parameters))
                          <div class="flex-1">
                            <label class="text-xs text-gray-500 mb-1 block">Parametres (JSON) — @foreach($ep->parameters as $p){{ $p['name'] }}{{ !$loop->last ? ', ' : '' }}@endforeach</label>
                            <input x-model="testParams" type="text" placeholder='{"month": 3, "status": "paid"}'
                              class="w-full bg-gray-800 border border-gray-700 rounded px-2 py-1.5 text-xs font-mono focus:border-yellow-600 focus:outline-none">
                          </div>
                        @endif
                        <button @click="testLoading = true; testResult = null;
                          fetch('{{ route('partner.endpoints.test', [$share->token, $ep->id]) }}', {
                            method: 'POST',
                            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},
                            body: JSON.stringify({test_params: testParams})
                          }).then(r=>r.json()).then(d=>{testResult=d}).catch(e=>{testResult={success:false,error:e.message}}).finally(()=>{testLoading=false})"
                          :disabled="testLoading"
                          class="px-3 py-1.5 bg-yellow-600 hover:bg-yellow-500 disabled:bg-gray-700 rounded text-xs font-medium whitespace-nowrap transition-colors">
                          <span x-show="!testLoading">Lancer le test</span>
                          <span x-show="testLoading">...</span>
                        </button>
                      </div>

                      {{-- Test result --}}
                      <template x-if="testResult">
                        <div class="mt-2 rounded-lg border overflow-hidden"
                          :class="testResult.success ? 'border-green-800/50' : 'border-red-800/50'">
                          <div class="px-3 py-1.5 flex items-center justify-between text-xs"
                            :class="testResult.success ? 'bg-green-900/20 text-green-400' : 'bg-red-900/20 text-red-400'">
                            <span x-text="testResult.success ? 'OK' : 'Erreur'"></span>
                            <div class="flex gap-3 text-gray-500">
                              <span x-show="testResult.status_code" x-text="'HTTP ' + testResult.status_code"></span>
                              <span x-show="testResult.record_count !== undefined" x-text="testResult.record_count + ' enregistrements'"></span>
                            </div>
                          </div>
                          <template x-if="testResult.detected_fields?.length > 0">
                            <div class="px-3 py-1.5 border-t border-gray-800 bg-gray-800/20">
                              <div class="flex flex-wrap gap-1">
                                <template x-for="f in testResult.detected_fields" :key="f.name">
                                  <span class="px-1.5 py-0.5 bg-purple-900/20 text-purple-400/80 rounded text-xs font-mono" x-text="f.name + ':' + f.type"></span>
                                </template>
                              </div>
                            </div>
                          </template>
                          <div class="px-3 py-2 max-h-40 overflow-auto">
                            <pre class="text-xs text-gray-400 font-mono whitespace-pre-wrap" x-text="JSON.stringify(testResult.extracted_data ?? testResult.error, null, 2)"></pre>
                          </div>
                        </div>
                      </template>
                    </div>
                  </div>
                @endforeach
              </div>
            </div>
          @endforeach
        </div>
      @else
        <div class="text-gray-500 text-center py-8">
          <p class="text-3xl mb-2">📊</p>
          <p class="text-sm">Aucun endpoint configure. Importez depuis un Swagger ou ajoutez manuellement.</p>
        </div>
      @endif
    </div>

    {{-- Create/Edit form --}}
    <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6">
      <h2 class="text-lg font-bold mb-4">Nouvel endpoint API</h2>

      <form method="POST" action="{{ route('partner.endpoints.store', $share->token) }}" class="space-y-4">
        @csrf

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-gray-400 mb-1">Nom</label>
            <input x-ref="epName" name="name" type="text" placeholder="Lister les factures" required maxlength="150"
              class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
          </div>
          <div>
            <label class="block text-sm text-gray-400 mb-1">Description</label>
            <input name="description" type="text" placeholder="Recupere les factures depuis l'ERP" maxlength="500"
              class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
          </div>
        </div>

        <div class="grid grid-cols-4 gap-4">
          <div>
            <label class="block text-sm text-gray-400 mb-1">Methode</label>
            <select x-model="form.method" name="method" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
              <option value="GET">GET</option>
              <option value="POST">POST</option>
              <option value="PUT">PUT</option>
              <option value="PATCH">PATCH</option>
              <option value="DELETE">DELETE</option>
            </select>
          </div>
          <div class="col-span-3">
            <label class="block text-sm text-gray-400 mb-1">URL</label>
            <input x-model="form.url" name="url" type="url" placeholder="https://erp.example.com/api/invoices" required maxlength="1000"
              class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm font-mono focus:border-blue-500 focus:outline-none">
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-gray-400 mb-1">Type d'auth</label>
            <select x-model="form.auth_type" name="auth_type" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
              <option value="bearer">Bearer Token</option>
              <option value="header">Header (X-API-Key)</option>
              <option value="query">Query param (api_key=)</option>
              <option value="none">Aucune</option>
            </select>
          </div>
          <div>
            <label class="block text-sm text-gray-400 mb-1">Credential (cle enregistree)</label>
            <select x-model="form.auth_credential_key" name="auth_credential_key" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
              <option value="">— Aucune —</option>
              @foreach($credentials as $cred)
                <option value="{{ $cred->key }}">{{ $cred->key }}</option>
              @endforeach
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm text-gray-400 mb-1">Phrases declencheurs <span class="text-gray-600">(1 par ligne — le LLM utilisera ces phrases pour identifier l'intention)</span></label>
          <textarea x-model="form.trigger_phrases" name="trigger_phrases" rows="4" required
            placeholder="mes factures&#10;liste des factures&#10;factures du mois&#10;invoices"
            class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm font-mono focus:border-blue-500 focus:outline-none"></textarea>
        </div>

        {{-- Parameters builder --}}
        <div>
          <label class="block text-sm text-gray-400 mb-2">Parametres de filtre <span class="text-gray-600">(extraits du message par le LLM)</span></label>
          <div class="space-y-2 mb-2">
            <template x-for="(param, i) in params" :key="i">
              <div class="flex gap-2 items-center">
                <input x-model="param.name" placeholder="nom" class="flex-1 bg-gray-800 border border-gray-700 rounded px-2 py-1 text-sm font-mono">
                <select x-model="param.type" class="bg-gray-800 border border-gray-700 rounded px-2 py-1 text-sm">
                  <option value="string">string</option>
                  <option value="int">int</option>
                  <option value="float">float</option>
                  <option value="bool">bool</option>
                  <option value="date">date</option>
                  <option value="enum">enum</option>
                </select>
                <input x-show="param.type === 'enum'" x-model="param.values_str" placeholder="val1,val2,val3" class="flex-1 bg-gray-800 border border-gray-700 rounded px-2 py-1 text-sm font-mono">
                <input x-model="param.mapping" placeholder="?param=" class="w-28 bg-gray-800 border border-gray-700 rounded px-2 py-1 text-sm font-mono">
                <label class="flex items-center gap-1 text-xs text-gray-400">
                  <input type="checkbox" x-model="param.required" class="rounded"> Requis
                </label>
                <button type="button" @click="params.splice(i, 1)" class="text-red-500 hover:text-red-400 text-sm px-1">x</button>
              </div>
            </template>
          </div>
          <button type="button" @click="params.push({name:'', type:'string', mapping:'', required:false, values_str:''})" class="text-blue-400 hover:text-blue-300 text-sm">+ Ajouter un parametre</button>
          <input type="hidden" name="parameters" :value="buildParamsJson()">
        </div>

        {{-- Request body template for POST/PUT/PATCH --}}
        <div x-show="['POST','PUT','PATCH'].includes(form.method)">
          <label class="block text-sm text-gray-400 mb-1">Body template <span class="text-gray-600">(JSON — utilisez @{{param}} pour injecter les parametres)</span></label>
          <textarea x-model="form.body_template" name="request_body_template" rows="4"
            placeholder='{"filter": {"month": "@{{month}}", "status": "@{{status}}"}}'
            class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm font-mono focus:border-blue-500 focus:outline-none"></textarea>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-gray-400 mb-1">Chemin JSON de reponse <span class="text-gray-600">(ex: data.invoices)</span></label>
            <input x-model="form.response_path" name="response_path" type="text" placeholder="data.invoices" maxlength="300"
              class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm font-mono focus:border-blue-500 focus:outline-none">
          </div>
          <div>
            <label class="block text-sm text-gray-400 mb-1">Headers supplementaires <span class="text-gray-600">(JSON)</span></label>
            <input name="headers" type="text" placeholder='{"X-Custom": "value"}' maxlength="1000"
              class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm font-mono focus:border-blue-500 focus:outline-none">
          </div>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="button" @click="simulateEndpoint()" :disabled="simulating"
            class="px-5 py-2.5 bg-yellow-600 hover:bg-yellow-500 disabled:bg-gray-700 rounded-xl text-sm font-medium transition-colors flex items-center gap-2">
            <span x-show="!simulating">Simuler l'appel</span>
            <span x-show="simulating">Simulation en cours...</span>
          </button>
          <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 rounded-xl text-sm font-medium transition-colors">Enregistrer l'endpoint</button>
        </div>
      </form>

      {{-- Simulation results panel --}}
      <div x-show="simResult" x-cloak class="mt-6 border border-gray-700 rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-700 flex items-center justify-between"
          :class="simResult?.success ? 'bg-green-900/20' : 'bg-red-900/20'">
          <div class="flex items-center gap-2">
            <span x-text="simResult?.success ? '✅' : '❌'" class="text-lg"></span>
            <span class="font-semibold text-sm" x-text="simResult?.success ? 'Simulation reussie' : 'Echec de la simulation'"></span>
          </div>
          <div class="flex items-center gap-3 text-xs text-gray-400">
            <span x-show="simResult?.status_code" x-text="'HTTP ' + simResult?.status_code"></span>
            <span x-show="simResult?.record_count !== undefined" x-text="simResult?.record_count + ' enregistrements'"></span>
          </div>
        </div>

        {{-- Detected fields --}}
        <template x-if="simResult?.detected_fields?.length > 0">
          <div class="px-4 py-3 border-b border-gray-700 bg-gray-800/30">
            <p class="text-xs text-gray-400 mb-2 font-semibold">Champs detectes dans la reponse:</p>
            <div class="flex flex-wrap gap-1">
              <template x-for="f in simResult.detected_fields" :key="f.name">
                <span class="px-2 py-0.5 bg-purple-900/30 text-purple-300 rounded text-xs">
                  <span x-text="f.name" class="font-mono"></span>
                  <span class="text-purple-500" x-text="'(' + f.type + ')'"></span>
                  <span class="text-gray-500 ml-1" x-text="f.sample !== '[...]' ? '= ' + f.sample : ''"></span>
                </span>
              </template>
            </div>
          </div>
        </template>

        {{-- Suggested response_path --}}
        <template x-if="simResult?.success && !form.response_path && simResult?.raw_response">
          <div class="px-4 py-2 border-b border-gray-700 bg-blue-900/10">
            <p class="text-xs text-blue-300">
              Suggestion: definissez le chemin JSON pour cibler les bonnes donnees dans la reponse.
              <template x-for="key in suggestPaths(simResult.raw_response)" :key="key">
                <button type="button" @click="form.response_path = key" class="ml-1 underline hover:text-blue-200" x-text="key"></button>
              </template>
            </p>
          </div>
        </template>

        {{-- Extracted data preview --}}
        <div class="px-4 py-3 max-h-64 overflow-auto">
          <pre class="text-xs text-gray-300 font-mono whitespace-pre-wrap" x-text="JSON.stringify(simResult?.extracted_data ?? simResult?.error, null, 2)"></pre>
        </div>
      </div>
    </div>

    {{-- How it works --}}
    <div class="bg-gray-900/50 rounded-2xl border border-gray-800 p-6">
      <h3 class="font-bold text-sm text-gray-300 mb-3">Comment ca marche ?</h3>
      <div class="grid grid-cols-4 gap-4 text-center text-xs text-gray-400">
        <div class="p-3 bg-gray-800/50 rounded-xl">
          <p class="text-2xl mb-1">🗣</p>
          <p class="font-semibold text-gray-300">1. Comprendre</p>
          <p>Le LLM identifie l'intention et extrait les parametres du message</p>
        </div>
        <div class="p-3 bg-gray-800/50 rounded-xl">
          <p class="text-2xl mb-1">✅</p>
          <p class="font-semibold text-gray-300">2. Valider</p>
          <p>Les parametres sont verifies par schema (types, valeurs autorisees)</p>
        </div>
        <div class="p-3 bg-gray-800/50 rounded-xl">
          <p class="text-2xl mb-1">🔌</p>
          <p class="font-semibold text-gray-300">3. Appeler</p>
          <p>L'API est appelee avec les parametres valides — aucune invention possible</p>
        </div>
        <div class="p-3 bg-gray-800/50 rounded-xl">
          <p class="text-2xl mb-1">📋</p>
          <p class="font-semibold text-gray-300">4. Presenter</p>
          <p>Le LLM met en forme les donnees REELLES — il ne peut rien ajouter</p>
        </div>
      </div>
    </div>
  </div>

</div>

{{-- Footer --}}
<footer class="border-t border-gray-800 mt-12 py-6 text-center text-gray-600 text-sm">
  Powered by <a href="/" class="text-blue-500 hover:text-blue-400">ZeniClaw</a> — ZeniBiz &copy; 2026
</footer>

<script>
function assistantChat(mode) {
  return {
    assistMessages: [],
    assistInput: '',
    assistLoading: false,
    assistMsgId: 0,
    generatedData: null,
    mode: mode,

    async sendAssist() {
      const msg = this.assistInput.trim();
      if (!msg) return;

      this.assistMessages.push({ id: ++this.assistMsgId, role: 'user', content: msg });
      this.assistInput = '';
      this.assistLoading = true;

      try {
        const history = this.assistMessages.map(m => ({ role: m.role, content: m.content }));
        const res = await fetch('{{ route("partner.assist", $share->token) }}', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
          body: JSON.stringify({ message: msg, mode: this.mode, history: JSON.stringify(history.slice(0, -1)) }),
        });
        const data = await res.json();
        const reply = data.reply || 'Erreur.';

        this.assistMessages.push({ id: ++this.assistMsgId, role: 'assistant', content: reply });

        // Check if AI generated a ready skill/script
        const marker = this.mode === 'skill' ? '---SKILL_READY---' : '---SCRIPT_READY---';
        if (reply.includes(marker)) {
          const jsonStr = reply.split(marker)[1].trim();
          try {
            this.generatedData = JSON.parse(jsonStr);
          } catch(e) {
            // Try to extract JSON from the response
            const match = jsonStr.match(/\{[\s\S]*\}/);
            if (match) {
              try { this.generatedData = JSON.parse(match[0]); } catch(e2) {}
            }
          }
        }
      } catch (e) {
        this.assistMessages.push({ id: ++this.assistMsgId, role: 'assistant', content: 'Erreur de communication.' });
      }

      this.assistLoading = false;
    },

    fillForm() {
      if (!this.generatedData) return;
      const d = this.generatedData;

      if (this.mode === 'skill') {
        if (this.$refs.skillName) this.$refs.skillName.value = d.name || '';
        if (this.$refs.skillDesc) this.$refs.skillDesc.value = d.description || '';
        if (this.$refs.skillTrigger) this.$refs.skillTrigger.value = d.trigger_phrase || '';
        if (this.$refs.skillRoutine) this.$refs.skillRoutine.value = JSON.stringify(d.routine || [], null, 2);
      } else {
        if (this.$refs.scriptName) this.$refs.scriptName.value = d.name || '';
        if (this.$refs.scriptDesc) this.$refs.scriptDesc.value = d.description || '';
        if (this.$refs.scriptLang) this.$refs.scriptLang.value = d.language || 'python';
        if (this.$refs.scriptCode) this.$refs.scriptCode.value = d.code || '';
      }
      this.generatedData = null;
    }
  };
}

function endpointManager() {
  return {
    swaggerUrl: '',
    swaggerLoading: false,
    swaggerResult: null,
    swaggerError: null,
    swaggerAuthKey: '',

    async analyzeSwagger() {
      if (!this.swaggerUrl.trim()) { alert('URL requise'); return; }
      this.swaggerLoading = true;
      this.swaggerResult = null;
      this.swaggerError = null;

      try {
        const res = await fetch('{{ route("partner.endpoints.swagger.analyze", $share->token) }}', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
          body: JSON.stringify({ swagger_url: this.swaggerUrl }),
        });
        const data = await res.json();
        if (data.success) {
          this.swaggerResult = data;
        } else {
          this.swaggerError = data.error || 'Erreur inconnue.';
        }
      } catch (e) {
        this.swaggerError = 'Erreur de connexion: ' + e.message;
      }
      this.swaggerLoading = false;
    },

    form: {
      method: 'GET',
      url: '',
      auth_type: 'bearer',
      auth_credential_key: '',
      trigger_phrases: '',
      response_path: '',
      body_template: '',
    },
    params: [],
    simulating: false,
    simResult: null,

    buildParamsJson() {
      const valid = this.params.filter(p => p.name.trim());
      return JSON.stringify(valid.map(p => ({
        name: p.name.trim(),
        type: p.type,
        mapping: p.mapping.trim() || p.name.trim(),
        required: p.required,
        ...(p.type === 'enum' ? { values: p.values_str.split(',').map(v => v.trim()).filter(Boolean) } : {}),
      })));
    },

    async simulateEndpoint() {
      if (!this.form.url) { alert('URL requise'); return; }
      this.simulating = true;
      this.simResult = null;

      try {
        const res = await fetch('{{ route("partner.endpoints.simulate", $share->token) }}', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
          body: JSON.stringify({
            method: this.form.method,
            url: this.form.url,
            auth_type: this.form.auth_type,
            auth_credential_key: this.form.auth_credential_key || null,
            response_path: this.form.response_path || null,
            headers: null,
            request_body_template: this.form.body_template || null,
            test_params: '{}',
            parameters: this.buildParamsJson(),
          }),
        });
        this.simResult = await res.json();
      } catch (e) {
        this.simResult = { success: false, error: 'Erreur de connexion: ' + e.message };
      }

      this.simulating = false;
    },

    suggestPaths(obj, prefix = '') {
      const paths = [];
      if (!obj || typeof obj !== 'object') return paths;
      for (const key of Object.keys(obj)) {
        const path = prefix ? prefix + '.' + key : key;
        if (Array.isArray(obj[key])) { paths.push(path); }
        else if (typeof obj[key] === 'object' && obj[key] !== null) {
          paths.push(...this.suggestPaths(obj[key], path));
        }
      }
      return paths.slice(0, 5);
    },
  };
}

function partnerPortal() {
  return {
    tab: 'chat',
    messages: [],
    input: '',
    loading: false,
    msgId: 0,
    loadingStatus: 'Reflexion en cours...',
    loadingDetail: '',
    progressInterval: null,

    startProgressPolling() {
      this.loadingStatus = 'Connexion au modele IA...';
      this.loadingDetail = '';
      this.progressInterval = setInterval(async () => {
        try {
          const res = await fetch('{{ route("partner.progress", $share->token) }}');
          const data = await res.json();
          if (data.status === 'thinking') {
            this.loadingStatus = data.step || 'Reflexion en cours...';
            this.loadingDetail = data.detail || '';
          } else if (data.status === 'skill') {
            this.loadingStatus = data.step || 'Execution de la routine...';
            this.loadingDetail = data.detail || '';
          }
        } catch(e) {}
      }, 2000);
    },

    stopProgressPolling() {
      if (this.progressInterval) {
        clearInterval(this.progressInterval);
        this.progressInterval = null;
      }
    },

    async sendMessage() {
      const msg = this.input.trim();
      if (!msg) return;

      this.messages.push({ id: ++this.msgId, role: 'user', content: msg });
      this.input = '';
      this.loading = true;
      this.startProgressPolling();

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
        this.messages.push({ id: ++this.msgId, role: 'assistant', content: data.reply || 'Pas de reponse.', model: data.metadata?.model || null });
      } catch (e) {
        this.messages.push({ id: ++this.msgId, role: 'assistant', content: 'Erreur de communication.' });
      }

      this.stopProgressPolling();
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
