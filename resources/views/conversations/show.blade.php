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
                    {{ $conversation->displayName() }}
                </h2>
                <p class="text-xs text-gray-400">
                    Agent: {{ $conversation->agent->name ?? '-' }}
                    &middot; {{ $conversation->message_count }} messages
                    &middot; {{ $conversation->last_message_at?->diffForHumans() ?? '-' }}
                </p>
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 bg-gray-100 rounded-lg p-1 w-fit">
        <button @click="tab = 'messages'"
                :class="tab === 'messages' ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 rounded-md text-sm font-medium transition-all">
            Messages
        </button>
        <button @click="tab = 'memory'"
                :class="tab === 'memory' ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-2 rounded-md text-sm font-medium transition-all">
            Memoire <span class="text-xs text-gray-400">({{ count($memoryEntries) }})</span>
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
</div>

<script>
    // Auto-scroll to bottom of chat messages
    document.addEventListener('DOMContentLoaded', function() {
        const el = document.getElementById('chat-messages');
        if (el) el.scrollTop = el.scrollHeight;
    });
</script>
@endsection
