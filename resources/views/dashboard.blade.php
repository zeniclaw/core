@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div class="space-y-6">

    {{-- Stats cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Total Agents</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ $agentCount }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-100 flex items-center justify-center text-2xl">🤖</div>
            </div>
        </div>
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Active Agents</p>
                    <p class="text-3xl font-bold text-green-600 mt-1">{{ $activeAgents }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center text-2xl">✅</div>
            </div>
        </div>
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Reminders</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ $reminderCount }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-yellow-100 flex items-center justify-center text-2xl">⏰</div>
            </div>
        </div>
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Pending</p>
                    <p class="text-3xl font-bold text-orange-500 mt-1">{{ $pendingReminders }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-orange-100 flex items-center justify-center text-2xl">⏳</div>
            </div>
        </div>
    </div>

    {{-- Main content: Chat + Recent Agents side by side --}}
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

        {{-- Web Chat Panel --}}
        <div class="lg:col-span-3 bg-gray-900 rounded-xl shadow-sm border border-gray-800 flex flex-col" style="min-height:500px;max-height:70vh;" x-data="dashboardChat()">
            {{-- Header --}}
            <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-800 flex-shrink-0" style="background:#0d1220;border-radius:12px 12px 0 0;">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center text-white font-bold text-sm" style="background:linear-gradient(135deg,#3b82f6,#8b5cf6,#ec4899);">Z</div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-gray-100">Web Chat</p>
                    <p class="text-xs text-gray-500" x-text="loading ? 'Thinking...' : 'Online'"></p>
                </div>
                <select x-model="agentId" class="bg-gray-950 border border-gray-700 text-gray-400 text-xs px-2 py-1 rounded-md max-w-[140px] focus:outline-none focus:border-indigo-500">
                    <option value="">Auto</option>
                    @foreach($agents as $a)
                        @if($a->status === 'active')
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endif
                    @endforeach
                </select>
                <button @@click="clearChat()" title="Clear chat" class="text-gray-500 hover:text-gray-300 transition-colors p-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
            </div>

            {{-- Messages --}}
            <div class="flex-1 overflow-y-auto px-5 py-4 space-y-3" x-ref="messages" style="scrollbar-width:thin;">
                <template x-for="(msg, i) in messages" :key="i">
                    <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                        <div class="max-w-[85%] px-3.5 py-2.5 rounded-2xl text-sm leading-relaxed"
                             :class="msg.role === 'user'
                                ? 'text-white rounded-br-sm'
                                : 'text-gray-100 border border-gray-700 rounded-bl-sm'"
                             :style="msg.role === 'user'
                                ? 'background:linear-gradient(135deg,#3b82f6,#8b5cf6);'
                                : 'background:#1a1f2e;'">
                            <div x-html="formatMsg(msg.text)"></div>
                            <template x-if="msg.files && msg.files.length > 0">
                                <div class="mt-2 space-y-1.5">
                                    <template x-for="(file, fi) in msg.files" :key="fi">
                                        <a :href="file.url" target="_blank" download
                                           class="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium transition-colors"
                                           style="background:rgba(255,255,255,0.1);color:#93c5fd;border:1px solid rgba(147,197,253,0.2);"
                                           onmouseover="this.style.background='rgba(255,255,255,0.15)'"
                                           onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                                            <span x-text="file.format === 'xlsx' ? '📊' : file.format === 'pdf' ? '📕' : '📝'"></span>
                                            <span x-text="file.name" class="truncate"></span>
                                            <svg class="w-3.5 h-3.5 flex-shrink-0 ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                            </svg>
                                        </a>
                                    </template>
                                </div>
                            </template>
                            <p class="mt-1 text-right text-gray-500" style="font-size:0.65rem;" x-text="msg.time"></p>
                        </div>
                    </div>
                </template>
                <div x-show="loading" class="flex justify-start">
                    <div class="px-4 py-3 rounded-2xl rounded-bl-sm border border-gray-700" style="background:#1a1f2e;">
                        <div class="flex gap-1">
                            <span class="w-2 h-2 rounded-full bg-gray-500" style="animation:dbounce 1.4s infinite;"></span>
                            <span class="w-2 h-2 rounded-full bg-gray-500" style="animation:dbounce 1.4s infinite 0.2s;"></span>
                            <span class="w-2 h-2 rounded-full bg-gray-500" style="animation:dbounce 1.4s infinite 0.4s;"></span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Input --}}
            <div class="px-4 py-3 flex-shrink-0 border-t border-gray-800" style="background:#0d1220;border-radius:0 0 12px 12px;">
                <form @@submit.prevent="send()" class="flex gap-2">
                    <input x-model="input" x-ref="chatInput" type="text" placeholder="Type a message..."
                           class="flex-1 text-sm rounded-xl px-4 py-2.5 bg-gray-950 border border-gray-700 text-gray-100 focus:outline-none focus:border-indigo-500"
                           :disabled="loading">
                    <button type="submit" :disabled="loading || !input.trim()"
                            class="flex items-center justify-center rounded-xl px-4 text-white border-none"
                            style="background:linear-gradient(135deg,#3b82f6,#8b5cf6,#ec4899);"
                            :style="(!input.trim() || loading) ? 'opacity:0.4;cursor:not-allowed;' : 'opacity:1;cursor:pointer;'">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    </button>
                </form>
            </div>
        </div>

        {{-- Recent Agents --}}
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 self-start">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-semibold text-gray-800">Recent Agents</h2>
                <a href="{{ route('agents.create') }}" class="text-sm bg-indigo-600 text-white px-3 py-1.5 rounded-lg hover:bg-indigo-700 transition-colors">+ New Agent</a>
            </div>
            @if($agents->isEmpty())
            <div class="px-6 py-10 text-center text-gray-400">
                <p class="text-4xl mb-2">🤖</p>
                <p>No agents yet. <a href="{{ route('agents.create') }}" class="text-indigo-600 hover:underline">Create your first agent</a>.</p>
            </div>
            @else
            <div class="divide-y divide-gray-50">
                @foreach($agents as $agent)
                <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50 transition-colors">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-indigo-100 flex items-center justify-center text-lg">🤖</div>
                        <div>
                            <p class="font-medium text-gray-900">{{ $agent->name }}</p>
                            <p class="text-xs text-gray-500">{{ $agent->model }} · {{ $agent->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="px-2 py-1 rounded-full text-xs font-medium
                            {{ $agent->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $agent->status }}
                        </span>
                        <a href="{{ route('agents.show', $agent) }}" class="text-indigo-600 text-sm hover:underline">View</a>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>

    </div>

</div>

<style>
@@keyframes dbounce { 0%, 80%, 100% { transform: translateY(0); } 40% { transform: translateY(-6px); } }
</style>

<script>
function dashboardChat() {
    const STORAGE_KEY = 'zeniclaw_chat_messages';
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');

    return {
        loading: false,
        input: '',
        agentId: '',
        messages: saved && saved.length > 0 ? saved : [
            { role: 'assistant', text: 'Hello! I\'m ZeniClaw AI. How can I help you today?', time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) }
        ],

        init() {
            this.$watch('messages', (val) => {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(val));
            });
            this.$nextTick(() => this.scrollBottom());
        },

        clearChat() {
            this.messages = [
                { role: 'assistant', text: 'Hello! I\'m ZeniClaw AI. How can I help you today?', time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) }
            ];
            localStorage.removeItem(STORAGE_KEY);
        },

        async send() {
            const text = this.input.trim();
            if (!text || this.loading) return;

            this.messages.push({ role: 'user', text, time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) });
            this.input = '';
            this.loading = true;
            this.$nextTick(() => this.scrollBottom());

            // Add placeholder assistant message for streaming
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
                        if (line.startsWith('event: ')) {
                            var eventType = line.substring(7).trim();
                        }
                        if (line.startsWith('data: ')) {
                            try {
                                const payload = JSON.parse(line.substring(6));
                                if (eventType === 'token' && payload.text) {
                                    this.messages[msgIndex].text += payload.text;
                                    this.$nextTick(() => this.scrollBottom());
                                } else if (eventType === 'done') {
                                    if (payload.full_reply) {
                                        this.messages[msgIndex].text = payload.full_reply;
                                    }
                                    if (payload.files && payload.files.length > 0) {
                                        this.messages[msgIndex].files = payload.files;
                                    }
                                    if (payload.sub_agent_id) {
                                        this.pollSubAgent(payload.sub_agent_id);
                                    }
                                } else if (eventType === 'error') {
                                    this.messages[msgIndex].text = '\u26a0 ' + (payload.message || 'Something went wrong');
                                }
                            } catch (e) {}
                        }
                    }
                }

                // If no text was received at all
                if (!this.messages[msgIndex].text) {
                    this.messages[msgIndex].text = '\u26a0 No response received';
                }
            } catch (e) {
                this.messages[msgIndex].text = '\u26a0 Network error: ' + e.message;
            }

            this.loading = false;
            this.$nextTick(() => { this.scrollBottom(); this.$refs.chatInput?.focus(); });
        },

        async pollSubAgent(id) {
            const poll = async () => {
                try {
                    const res = await fetch(`/api/subagent/${id}/status`);
                    const data = await res.json();
                    if (data.status === 'completed' || data.status === 'failed') {
                        const text = data.status === 'completed'
                            ? (data.findings || 'Tache terminee.')
                            : '⚠ Erreur: ' + (data.error || 'La tache a echoue.');
                        this.messages.push({ role: 'assistant', text, time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) });
                        this.$nextTick(() => this.scrollBottom());
                        return;
                    }
                    setTimeout(poll, 5000);
                } catch (e) { setTimeout(poll, 10000); }
            };
            setTimeout(poll, 5000);
        },

        scrollBottom() {
            const el = this.$refs.messages;
            if (el) el.scrollTop = el.scrollHeight;
        },

        formatMsg(text) {
            if (!text) return '';
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
@endsection
