@extends('layouts.app')
@section('title', 'Conversation — ' . $conversation->displayName())

@section('content')
<div class="space-y-4" x-data="{ tab: 'messages' }">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('conversations.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-xs font-medium
                        {{ $conversation->isGroup() ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                        {{ $conversation->isGroup() ? 'Groupe' : 'DM' }}
                    </span>
                    {{ $conversation->display_name ?? $conversation->displayName() }}
                </h2>
                <p class="text-xs text-gray-400">
                    Agent: {{ $conversation->agent->name ?? '-' }}
                    &middot; {{ $conversation->message_count }} messages
                    &middot; {{ $conversation->last_message_at?->diffForHumans() ?? '-' }}
                    &middot; <span class="font-mono text-gray-300">{{ $conversation->peer_id }}</span>
                </p>
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 bg-gray-100 rounded-lg p-1 w-fit">
        <button @@click="tab = 'messages'"
                :class="tab === 'messages' ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 rounded-md text-sm font-medium transition-all">
            Messages
        </button>
        <button @@click="tab = 'memory'"
                :class="tab === 'memory' ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 rounded-md text-sm font-medium transition-all">
            Memoire <span class="text-xs text-gray-400">({{ count($memoryEntries) }})</span>
        </button>
        <button @@click="tab = 'debug'"
                :class="tab === 'debug' ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 rounded-md text-sm font-medium transition-all">
            Debug <span class="text-xs text-gray-400">({{ $debugLogs->count() }})</span>
        </button>
    </div>

    {{-- Messages tab --}}
    <div x-show="tab === 'messages'" class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($messages->isEmpty())
            <div class="py-16 text-center text-gray-400">
                <p class="text-5xl mb-3">💬</p>
                <p>Aucun message pour cette conversation.</p>
            </div>
        @else
        <div class="p-4 space-y-3 max-h-[600px] overflow-y-auto" id="chat-messages">
            @foreach($messages as $msg)
            <div class="flex {{ $msg['direction'] === 'out' ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[75%] {{ $msg['direction'] === 'out' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-800' }} rounded-2xl px-4 py-2.5">
                    @if($msg['direction'] === 'in')
                    <p class="text-xs font-semibold mb-0.5 {{ $msg['direction'] === 'out' ? 'text-indigo-200' : 'text-gray-500' }}">{{ $msg['sender'] }}</p>
                    @endif
                    @if(!empty($msg['has_media']) && !empty($msg['media_url']))
                        @if(str_starts_with($msg['media_type'] ?? '', 'image/'))
                            <img src="{{ $msg['media_url'] }}" alt="Image" class="rounded-lg max-w-full max-h-64 mb-1" loading="lazy" />
                        @elseif(($msg['media_type'] ?? '') === 'application/pdf')
                            <a href="{{ $msg['media_url'] }}" target="_blank" class="inline-flex items-center gap-1 text-sm underline {{ $msg['direction'] === 'out' ? 'text-indigo-200' : 'text-indigo-600' }} mb-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                Document PDF
                            </a>
                        @else
                            <p class="text-xs italic {{ $msg['direction'] === 'out' ? 'text-indigo-300' : 'text-gray-400' }} mb-1">
                                Media: {{ $msg['media_type'] ?? 'fichier' }}
                            </p>
                        @endif
                    @endif
                    @if(!empty($msg['body']))
                    <p class="text-sm whitespace-pre-wrap">{{ $msg['body'] }}</p>
                    @endif
                    <p class="text-xs mt-1 {{ $msg['direction'] === 'out' ? 'text-indigo-300' : 'text-gray-400' }} flex items-center gap-2">
                        <span>{{ $msg['timestamp']->format('d/m H:i') }}</span>
                        @if(!empty($msg['routed_agent']))
                            <span class="px-1.5 py-0.5 rounded-full font-semibold text-white text-[10px]"
                                  style="background:{{ match($msg['routed_agent']) {
                                      'chat' => '#3b82f6', 'dev' => '#8b5cf6', 'document' => '#0ea5e9',
                                      'reminder' => '#f59e0b', 'project' => '#10b981', 'todo' => '#14b8a6',
                                      'finance' => '#22c55e', 'habit' => '#22c55e', 'music' => '#ec4899',
                                      'analysis' => '#ef4444', 'code_review' => '#3b82f6', 'mood_check' => '#f97316',
                                      'content_summarizer' => '#06b6d4', 'screenshot' => '#64748b',
                                      'event_reminder' => '#eab308', 'pomodoro' => '#dc2626',
                                      'hangman' => '#a855f7', 'flashcard' => '#2563eb',
                                      'smart_meeting' => '#059669', 'web_search' => '#f43f5e',
                                      default => '#6b7280'
                                  } }}">{{ $msg['routed_agent'] }}</span>
                        @endif
                        @if(!empty($msg['model']))
                            <span class="px-1.5 py-0.5 rounded {{ $msg['direction'] === 'out' ? 'bg-indigo-500/30 text-indigo-200' : 'bg-gray-200 text-gray-500' }} text-[10px] font-mono">{{ $msg['model'] }}</span>
                        @endif
                    </p>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Memory tab --}}
    <div x-show="tab === 'memory'" x-cloak class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if(empty($memoryEntries))
            <div class="py-16 text-center text-gray-400">
                <p class="text-5xl mb-3">🧠</p>
                <p>Aucune memoire enregistree.</p>
            </div>
        @else
        <div class="divide-y divide-gray-50">
            @foreach($memoryEntries as $entry)
            <div class="p-4 hover:bg-gray-50 transition-colors">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        @if(!empty($entry['summary']))
                        <p class="text-sm font-medium text-gray-800 mb-1">{{ $entry['summary'] }}</p>
                        @endif
                        <div class="text-xs text-gray-500 space-y-0.5">
                            <p><span class="font-medium text-gray-600">{{ $entry['sender'] ?? 'inconnu' }}:</span> {{ Str::limit($entry['sender_message'] ?? '', 120) }}</p>
                            <p><span class="font-medium text-indigo-600">ZeniClaw:</span> {{ Str::limit($entry['agent_reply'] ?? '', 120) }}</p>
                        </div>
                    </div>
                    <span class="text-xs text-gray-400 whitespace-nowrap flex-shrink-0">
                        {{ \Carbon\Carbon::parse($entry['timestamp'])->format('d/m H:i') }}
                    </span>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Debug tab --}}
    <div x-show="tab === 'debug'" x-cloak class="space-y-4">

        {{-- Routing decisions --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                <h3 class="text-sm font-semibold text-gray-700">Router Decisions ({{ $routingLogs->count() }})</h3>
            </div>
            @if($routingLogs->isEmpty())
                <div class="p-4 text-sm text-gray-400">Aucune decision de routage.</div>
            @else
            <div class="divide-y divide-gray-50 max-h-[400px] overflow-y-auto">
                @foreach($routingLogs as $rlog)
                    @php
                        $routing = $rlog->context['routing'] ?? [];
                        $body = $rlog->context['body'] ?? '';
                    @endphp
                <div class="p-3 hover:bg-gray-50 text-xs">
                    <div class="flex items-center gap-2 mb-1.5">
                        <span class="text-gray-400 font-mono">{{ $rlog->created_at->format('d/m H:i:s') }}</span>
                        <span class="px-2 py-0.5 rounded-full font-semibold text-white text-[10px]"
                              style="background:{{ match($routing['agent'] ?? '') {
                                  'chat' => '#3b82f6', 'dev' => '#8b5cf6', 'document' => '#0ea5e9',
                                  'reminder' => '#f59e0b', 'project' => '#10b981', 'todo' => '#14b8a6',
                                  'finance' => '#22c55e', 'habit' => '#22c55e', 'music' => '#ec4899',
                                  'analysis' => '#ef4444', 'code_review' => '#3b82f6',
                                  'web_search' => '#f43f5e', default => '#6b7280'
                              } }}">
                            {{ $routing['agent'] ?? '?' }}
                        </span>
                        <span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 font-mono">{{ $routing['model'] ?? '?' }}</span>
                        <span class="px-1.5 py-0.5 rounded font-medium
                            {{ match($routing['complexity'] ?? '') {
                                'simple' => 'bg-green-100 text-green-700',
                                'medium' => 'bg-yellow-100 text-yellow-700',
                                'complex' => 'bg-red-100 text-red-700',
                                default => 'bg-gray-100 text-gray-500'
                            } }}">
                            {{ $routing['complexity'] ?? '?' }}
                        </span>
                        <span class="px-1.5 py-0.5 rounded {{ ($routing['autonomy'] ?? '') === 'auto' ? 'bg-blue-100 text-blue-600' : 'bg-orange-100 text-orange-600' }}">
                            {{ $routing['autonomy'] ?? '?' }}
                        </span>
                        @if(isset($routing['confidence']))
                        <span class="px-1.5 py-0.5 rounded font-mono font-medium
                            {{ ($routing['confidence'] ?? 0) >= 85 ? 'bg-green-100 text-green-700' :
                               (($routing['confidence'] ?? 0) >= 65 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                            {{ $routing['confidence'] }}%
                        </span>
                        @endif
                    </div>
                    <div class="text-gray-600 mb-1">
                        <span class="text-gray-400">Message:</span> {{ Str::limit($body, 150) }}
                    </div>
                    @if(!empty($routing['reasoning']))
                    <div class="text-gray-400 italic">
                        {{ $routing['reasoning'] }}
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
            @endif
        </div>

        {{-- Full agent logs --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                <h3 class="text-sm font-semibold text-gray-700">Agent Logs ({{ $debugLogs->count() }})</h3>
            </div>
            <div class="divide-y divide-gray-50 max-h-[500px] overflow-y-auto">
                @foreach($debugLogs as $dlog)
                <div class="p-3 hover:bg-gray-50 text-xs" x-data="{ open: false }">
                    <div class="flex items-start gap-2 cursor-pointer" @@click="open = !open">
                        <span class="text-gray-400 font-mono whitespace-nowrap">{{ $dlog->created_at->format('d/m H:i:s') }}</span>
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-medium whitespace-nowrap
                            {{ match($dlog->level) {
                                'error' => 'bg-red-100 text-red-700',
                                'warn', 'warning' => 'bg-yellow-100 text-yellow-700',
                                'debug' => 'bg-gray-100 text-gray-500',
                                default => 'bg-blue-100 text-blue-700'
                            } }}">
                            {{ $dlog->level }}
                        </span>
                        <span class="text-gray-800 font-medium truncate">{{ $dlog->message }}</span>
                        <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0 ml-auto transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </div>
                    <div x-show="open" x-cloak class="mt-2 p-2 bg-gray-900 text-gray-300 rounded-lg font-mono text-[11px] overflow-x-auto whitespace-pre-wrap">{{ json_encode($dlog->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</div>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Session info --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                <h3 class="text-sm font-semibold text-gray-700">Session Info</h3>
            </div>
            <div class="p-4 text-xs font-mono space-y-1 text-gray-600">
                <p><span class="text-gray-400">session_id:</span> {{ $conversation->id }}</p>
                <p><span class="text-gray-400">session_key:</span> {{ $conversation->session_key }}</p>
                <p><span class="text-gray-400">agent_id:</span> {{ $conversation->agent_id }}</p>
                <p><span class="text-gray-400">channel:</span> {{ $conversation->channel }}</p>
                <p><span class="text-gray-400">peer_id:</span> {{ $conversation->peer_id }}</p>
                <p><span class="text-gray-400">display_name:</span> {{ $conversation->display_name ?? 'null' }}</p>
                <p><span class="text-gray-400">message_count:</span> {{ $conversation->message_count }}</p>
                <p><span class="text-gray-400">last_message_at:</span> {{ $conversation->last_message_at }}</p>
                <p><span class="text-gray-400">active_project_id:</span> {{ $conversation->active_project_id ?? 'null' }}</p>
                <p><span class="text-gray-400">whitelisted:</span> {{ $conversation->whitelisted ? 'true' : 'false' }}</p>
                @if($conversation->pending_agent_context)
                <p><span class="text-gray-400">pending_context:</span></p>
                <pre class="mt-1 p-2 bg-gray-900 text-gray-300 rounded-lg overflow-x-auto">{{ json_encode($conversation->pending_agent_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const el = document.getElementById('chat-messages');
        if (el) el.scrollTop = el.scrollHeight;
    });
</script>
@endsection
