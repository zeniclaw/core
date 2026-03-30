<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ZeniClaw — @yield('title', 'Dashboard')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        code, pre, .font-mono { font-family: 'JetBrains Mono', 'Fira Code', monospace; }
    </style>
</head>
<body class="dark-theme font-sans antialiased" x-data="{ sidebarOpen: false }">

{{-- Mobile overlay --}}
<div x-show="sidebarOpen" @click="sidebarOpen = false"
     class="fixed inset-0 bg-black/60 z-20 lg:hidden" x-transition></div>

<div class="flex h-screen overflow-hidden">

    {{-- ── Sidebar ────────────────────────────────────────────────────── --}}
    <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
           class="fixed lg:static inset-y-0 left-0 z-30 w-64 flex flex-col
                  transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:flex-shrink-0"
           style="background: #0d1220; border-right: 1px solid #1e293b;">

        <div class="px-6 py-5 flex items-center justify-between" style="border-bottom: 1px solid #1e293b;">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3 no-underline">
                <div style="width:32px;height:32px;background:linear-gradient(135deg,#3b82f6,#8b5cf6,#ec4899);border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;color:#fff;">Z</div>
                <span class="text-xl font-bold tracking-tight" style="color:#f1f5f9;">ZeniClaw</span>
            </a>
            <button @click="sidebarOpen = false" class="lg:hidden" style="color:#64748b;">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <p class="text-xs px-6 pb-2 pt-1" style="color:#4b5563;">AI Agent Platform</p>

        <nav class="flex-1 px-3 py-2 space-y-0.5 overflow-y-auto">
            @php
                $nav = [
                    ['route'=>'dashboard',            'icon'=>'&#x1F4CA;', 'label'=>'Dashboard',      'match'=>'dashboard'],
                    ['route'=>'agents.index',         'icon'=>'&#x1F916;', 'label'=>'Agents',         'match'=>'agents*'],
                    ['route'=>'conversations.index',  'icon'=>'&#x1F4AC;', 'label'=>'Conversations',  'match'=>'conversations*'],
                    ['route'=>'contacts.index',       'icon'=>'&#x1F465;', 'label'=>'Contacts',       'match'=>'contacts*'],
                    ['route'=>'projects.index',       'icon'=>'&#x1F4C1;', 'label'=>'Projects',       'match'=>'projects*'],
                    ['route'=>'subagents.index',      'icon'=>'&#x1F680;', 'label'=>'SubAgents',      'match'=>'subagents*'],
                    ['route'=>'workflows.index',       'icon'=>'&#x2699;',  'label'=>'Workflows',      'match'=>'workflows*'],
                    ['route'=>'improvements.index',   'icon'=>'&#x1F9E0;', 'label'=>'Improvements',   'match'=>'improvements*'],
                    ['route'=>'reminders.index',      'icon'=>'&#x23F0;',  'label'=>'Reminders',      'match'=>'reminders*'],
                    ['route'=>'logs.index',           'icon'=>'&#x1F4CB;', 'label'=>'Logs',           'match'=>'logs*'],
                    ['route'=>'settings.index',       'icon'=>'&#x2699;',  'label'=>'Settings',       'match'=>'settings*'],
                ];
            @endphp
            @foreach($nav as $n)
            <a href="{{ route($n['route']) }}" @click="sidebarOpen = false"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all no-underline"
               style="{{ request()->routeIs($n['match'])
                   ? 'background:linear-gradient(135deg,#3b82f6,#8b5cf6,#ec4899);color:#fff;box-shadow:0 2px 8px rgba(99,102,241,0.3);'
                   : 'color:#94a3b8;' }}"
               onmouseover="if(!this.style.background.includes('gradient'))this.style.background='#1a1f2e'"
               onmouseout="if(!this.style.background.includes('gradient'))this.style.background='transparent'">
                <span class="text-base">{!! $n['icon'] !!}</span>
                {{ $n['label'] }}
            </a>
            @endforeach

            @if(auth()->check() && auth()->user()->role === 'superadmin')
            <div class="pt-4 pb-1 px-3">
                <p class="text-xs font-semibold uppercase tracking-wider" style="color:#4b5563;">Admin</p>
            </div>
            <a href="{{ route('admin.update') }}" @click="sidebarOpen = false"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all no-underline"
               style="{{ request()->routeIs('admin.update*')
                   ? 'background:linear-gradient(135deg,#3b82f6,#8b5cf6,#ec4899);color:#fff;'
                   : 'color:#94a3b8;' }}"
               onmouseover="if(!this.style.background.includes('gradient'))this.style.background='#1a1f2e'"
               onmouseout="if(!this.style.background.includes('gradient'))this.style.background='transparent'">
                <span class="text-base">&#x1F504;</span>
                Updates
            </a>
            @endif
        </nav>

        <div class="px-5 py-3 text-xs" style="border-top:1px solid #1e293b;color:#4b5563;">
            ZeniClaw v{{ $appVersion }}
        </div>
    </aside>

    {{-- ── Main ────────────────────────────────────────────────────────── --}}
    <div class="flex-1 flex flex-col overflow-hidden min-w-0">

        {{-- Top bar --}}
        <header class="px-4 py-3 flex items-center justify-between flex-shrink-0"
                style="background:#111827;border-bottom:1px solid #1e293b;">
            <div class="flex items-center gap-3">
                <button @click="sidebarOpen = true" class="lg:hidden p-1.5 rounded-lg" style="color:#94a3b8;">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <h1 class="text-base font-semibold" style="color:#f1f5f9;">@yield('title', 'Dashboard')</h1>
            </div>
            <div class="flex items-center gap-3" x-data="{ open: false }">
                <span class="text-sm hidden md:block" style="color:#94a3b8;">{{ auth()->user()->name }}</span>
                <div class="relative">
                    <button @click="open = !open" class="flex items-center gap-1.5 focus:outline-none">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold"
                             style="background:linear-gradient(135deg,#3b82f6,#8b5cf6,#ec4899);">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </div>
                        <svg class="w-3.5 h-3.5 hidden sm:block" style="color:#64748b;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false" x-transition
                         class="absolute right-0 mt-2 w-48 rounded-xl z-50 py-1"
                         style="background:#1a1f2e;border:1px solid #1e293b;box-shadow:0 10px 15px rgba(0,0,0,0.5);">
                        <div class="px-4 py-2" style="border-bottom:1px solid #1e293b;">
                            <p class="text-xs font-medium" style="color:#f1f5f9;">{{ auth()->user()->name }}</p>
                            <p class="text-xs truncate" style="color:#64748b;">{{ auth()->user()->email }}</p>
                            <span class="inline-block mt-1 px-1.5 py-0.5 text-xs rounded" style="background:rgba(99,102,241,0.15);color:#818cf8;">{{ auth()->user()->role }}</span>
                        </div>
                        <a href="{{ route('settings.index') }}" class="block px-4 py-2 text-sm no-underline" style="color:#94a3b8;" onmouseover="this.style.background='#232940'" onmouseout="this.style.background='transparent'">&#x2699; Settings</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm" style="color:#fca5a5;" onmouseover="this.style.background='rgba(239,68,68,0.1)'" onmouseout="this.style.background='transparent'">&#x1F6AA; Logout</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        {{-- Flash messages --}}
        @if(session('success') || session('error'))
        <div class="px-4 pt-3">
            @if(session('success'))
            <div class="mb-2 px-4 py-3 rounded-lg text-sm flex items-center gap-2"
                 style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:#6ee7b7;">
                &#x2705; {{ session('success') }}
            </div>
            @endif
            @if(session('error'))
            <div class="mb-2 px-4 py-3 rounded-lg text-sm flex items-center gap-2"
                 style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;">
                &#x274C; {{ session('error') }}
            </div>
            @endif
        </div>
        @endif

        {{-- Page content --}}
        <main class="flex-1 overflow-y-auto p-4 pb-24 lg:pb-20">
            @yield('content')
        </main>

        {{-- ── Bottom Nav (mobile only) ──────────────────────────────── --}}
        <nav class="lg:hidden fixed bottom-0 inset-x-0 flex z-10 safe-pb"
             style="background:#111827;border-top:1px solid #1e293b;">
            @php
                $bottomNav = [
                    ['route'=>'dashboard',            'icon'=>'&#x1F4CA;', 'label'=>'Home',      'match'=>'dashboard'],
                    ['route'=>'agents.index',         'icon'=>'&#x1F916;', 'label'=>'Agents',    'match'=>'agents*'],
                    ['route'=>'conversations.index',  'icon'=>'&#x1F4AC;', 'label'=>'Convos',    'match'=>'conversations*'],
                    ['route'=>'logs.index',           'icon'=>'&#x1F4CB;', 'label'=>'Logs',      'match'=>'logs*'],
                    ['route'=>'settings.index',       'icon'=>'&#x2699;',  'label'=>'Settings',  'match'=>'settings*'],
                ];
            @endphp
            @foreach($bottomNav as $n)
            <a href="{{ route($n['route']) }}"
               class="flex-1 flex flex-col items-center justify-center py-2 text-xs no-underline"
               style="color: {{ request()->routeIs($n['match']) ? '#818cf8' : '#64748b' }};">
                <span class="text-xl leading-none mb-0.5">{!! $n['icon'] !!}</span>
                <span class="text-[10px]">{{ $n['label'] }}</span>
            </a>
            @endforeach
        </nav>
    </div>
</div>

{{-- ── Chat Widget ──────────────────────────────────────────────────── --}}
<div x-data="chatWidget()" x-cloak>
    {{-- Toggle button --}}
    <button @click="toggle()" class="fixed z-50 flex items-center justify-center"
            :class="open ? 'bottom-[440px] lg:bottom-[480px]' : 'bottom-6'"
            style="right:1.5rem;width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6,#ec4899);color:#fff;border:none;cursor:pointer;box-shadow:0 4px 15px rgba(99,102,241,0.4);transition:all 0.3s;">
        <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        <svg x-show="open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>

    {{-- Chat panel --}}
    <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed z-40 flex flex-col"
         style="bottom:1.5rem;right:1.5rem;width:400px;max-width:calc(100vw - 2rem);height:420px;max-height:70vh;background:#111827;border:1px solid #1e293b;border-radius:16px;box-shadow:0 20px 40px rgba(0,0,0,0.5);overflow:hidden;">

        {{-- Header --}}
        <div class="flex items-center gap-3 px-4 py-3 flex-shrink-0" style="border-bottom:1px solid #1e293b;background:#0d1220;">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#3b82f6,#8b5cf6,#ec4899);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;">Z</div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold" style="color:#f1f5f9;">ZeniClaw AI</p>
                <p class="text-xs" style="color:#64748b;" x-text="loading ? 'Thinking...' : 'Online'"></p>
            </div>
            <select x-model="agentId" style="background:#0a0e17;border:1px solid #1e293b;color:#94a3b8;font-size:0.75rem;padding:4px 8px;border-radius:6px;max-width:120px;">
                <option value="">Auto</option>
                @foreach(auth()->user()->agents()->where('status','active')->get() as $a)
                <option value="{{ $a->id }}">{{ $a->name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Messages --}}
        <div class="flex-1 overflow-y-auto px-4 py-3 space-y-3" x-ref="messages" style="scrollbar-width:thin;">
            <template x-for="(msg, i) in messages" :key="i">
                <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                    <div class="max-w-[85%] px-3 py-2 rounded-xl text-sm" style="line-height:1.5;"
                         :style="msg.role === 'user'
                            ? 'background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;border-radius:16px 16px 4px 16px;'
                            : 'background:#1a1f2e;color:#f1f5f9;border:1px solid #1e293b;border-radius:16px 16px 16px 4px;'">
                        <div x-html="formatMessage(msg.text)"></div>
                        <p class="mt-1 text-right" style="font-size:0.65rem;opacity:0.5;" x-text="msg.time"></p>
                    </div>
                </div>
            </template>
            <div x-show="loading" class="flex justify-start">
                <div class="px-4 py-3 rounded-xl" style="background:#1a1f2e;border:1px solid #1e293b;border-radius:16px 16px 16px 4px;">
                    <div class="flex gap-1">
                        <span class="w-2 h-2 rounded-full" style="background:#64748b;animation:bounce 1.4s infinite;"></span>
                        <span class="w-2 h-2 rounded-full" style="background:#64748b;animation:bounce 1.4s infinite 0.2s;"></span>
                        <span class="w-2 h-2 rounded-full" style="background:#64748b;animation:bounce 1.4s infinite 0.4s;"></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Input --}}
        <div class="px-3 py-3 flex-shrink-0" style="border-top:1px solid #1e293b;background:#0d1220;">
            <form @submit.prevent="send()" class="flex gap-2">
                <input x-model="input" x-ref="chatInput" type="text" placeholder="Type a message..."
                       class="flex-1 text-sm rounded-xl px-4 py-2.5"
                       style="background:#0a0e17;border:1px solid #1e293b;color:#f1f5f9;outline:none;"
                       :disabled="loading"
                       @focus="$el.style.borderColor='#3b82f6'" @blur="$el.style.borderColor='#1e293b'">
                <button type="submit" :disabled="loading || !input.trim()" class="flex items-center justify-center rounded-xl px-4"
                        style="background:linear-gradient(135deg,#3b82f6,#8b5cf6,#ec4899);color:#fff;border:none;cursor:pointer;opacity:0.9;"
                        :style="(!input.trim() || loading) ? 'opacity:0.4;cursor:not-allowed;' : 'opacity:1;'">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </button>
            </form>
        </div>
    </div>
</div>

<style>
    [x-cloak] { display: none !important; }
    @keyframes bounce { 0%, 80%, 100% { transform: translateY(0); } 40% { transform: translateY(-6px); } }
</style>

<script>
function chatWidget() {
    return {
        open: false,
        loading: false,
        input: '',
        agentId: '',
        messages: [
            { role: 'assistant', text: 'Hello! I\'m ZeniClaw AI. How can I help you today?', time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) }
        ],

        toggle() {
            this.open = !this.open;
            if (this.open) this.$nextTick(() => this.$refs.chatInput?.focus());
        },

        async send() {
            const text = this.input.trim();
            if (!text || this.loading) return;

            this.messages.push({ role: 'user', text, time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) });
            this.input = '';
            this.loading = true;
            this.$nextTick(() => this.scrollBottom());

            const assistantMsg = { role: 'assistant', text: '', time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) };
            this.messages.push(assistantMsg);
            const msgIndex = this.messages.length - 1;

            try {
                const res = await fetch('{{ route("api.chat.stream") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ message: text, agent_id: this.agentId || null })
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
                        if (line.startsWith('event: ')) var eventType = line.substring(7).trim();
                        if (line.startsWith('data: ')) {
                            try {
                                const payload = JSON.parse(line.substring(6));
                                if (eventType === 'token' && payload.text) {
                                    this.messages[msgIndex].text += payload.text;
                                    this.$nextTick(() => this.scrollBottom());
                                } else if (eventType === 'done' && payload.full_reply) {
                                    this.messages[msgIndex].text = payload.full_reply;
                                } else if (eventType === 'error') {
                                    this.messages[msgIndex].text = '\u26a0 ' + (payload.message || 'Error');
                                }
                            } catch (e) {}
                        }
                    }
                }
                if (!this.messages[msgIndex].text) {
                    this.messages[msgIndex].text = '\u26a0 No response';
                }
            } catch (e) {
                this.messages[msgIndex].text = '\u26a0 Network error: ' + e.message;
            }

            this.loading = false;
            this.$nextTick(() => { this.scrollBottom(); this.$refs.chatInput?.focus(); });
        },

        scrollBottom() {
            const el = this.$refs.messages;
            if (el) el.scrollTop = el.scrollHeight;
        },

        formatMessage(text) {
            if (!text) return '';
            // Basic markdown: bold, italic, code, newlines
            return text
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/`([^`]+)`/g, '<code style="background:#0a0e17;padding:1px 5px;border-radius:4px;font-size:0.85em;">$1</code>')
                .replace(/\n/g, '<br>');
        }
    };
}
</script>
</body>
</html>
