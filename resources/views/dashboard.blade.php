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
    return {
        loading: false,
        input: '',
        agentId: '',
        messages: [
            { role: 'assistant', text: 'Hello! I\'m ZeniClaw AI. How can I help you today?', time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) }
        ],

        async send() {
            const text = this.input.trim();
            if (!text || this.loading) return;

            this.messages.push({ role: 'user', text, time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) });
            this.input = '';
            this.loading = true;
            this.$nextTick(() => this.scrollBottom());

            try {
                const res = await fetch('{{ route("api.chat") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ message: text, agent_id: this.agentId || null })
                });
                const data = await res.json();

                if (data.ok) {
                    this.messages.push({ role: 'assistant', text: data.reply, time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) });
                    // If a SubAgent was dispatched, poll for completion
                    if (data.sub_agent_id) {
                        this.pollSubAgent(data.sub_agent_id);
                    }
                } else {
                    this.messages.push({ role: 'assistant', text: '\u26a0 ' + (data.error || 'Something went wrong'), time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) });
                }
            } catch (e) {
                this.messages.push({ role: 'assistant', text: '\u26a0 Network error: ' + e.message, time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) });
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
