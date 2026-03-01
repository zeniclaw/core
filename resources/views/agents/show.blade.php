@extends('layouts.app')
@section('title', $agent->name)

@section('content')
<div class="space-y-6">

    {{-- Header --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-xl bg-indigo-100 flex items-center justify-center text-3xl">🤖</div>
                <div>
                    <h2 class="text-xl font-bold text-gray-900">{{ $agent->name }}</h2>
                    <p class="text-gray-500 text-sm">{{ $agent->description }}</p>
                    <div class="flex items-center gap-2 mt-2">
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium
                            {{ $agent->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $agent->status }}
                        </span>
                        <span class="px-2 py-1 bg-gray-100 rounded text-xs font-mono text-gray-600">{{ $agent->model }}</span>
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('agents.edit', $agent) }}" class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">Edit</a>
                <form method="POST" action="{{ route('agents.destroy', $agent) }}"
                      x-data @submit.prevent="if(confirm('Delete this agent?')) $el.submit()">
                    @csrf @method('DELETE')
                    <button class="px-4 py-2 border border-red-200 rounded-lg text-sm text-red-600 hover:bg-red-50 transition-colors">Delete</button>
                </form>
            </div>
        </div>

        @if($agent->system_prompt)
        <div class="mt-4 p-4 bg-gray-50 rounded-xl">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">System Prompt</p>
            <pre class="text-xs text-gray-700 whitespace-pre-wrap font-mono">{{ $agent->system_prompt }}</pre>
        </div>
        @endif
    </div>

    {{-- Tabs --}}
    <div x-data="{ tab: 'logs' }">
        <div class="flex gap-1 bg-white rounded-xl shadow-sm border border-gray-100 p-1 mb-4">
            @foreach(['logs'=>'📋 Logs', 'reminders'=>'⏰ Reminders', 'memory'=>'🧠 Mémoire', 'sessions'=>'💬 Sessions'] as $t => $l)
            <button @click="tab = '{{ $t }}'"
                    :class="tab === '{{ $t }}' ? 'bg-indigo-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'"
                    class="flex-1 px-3 py-2 rounded-lg text-sm font-medium transition-all">{{ $l }}</button>
            @endforeach
        </div>

        {{-- LOGS tab --}}
        <div x-show="tab === 'logs'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="font-semibold text-gray-800">Recent Logs</h3>
                    <a href="{{ route('logs.index') }}" class="text-xs text-indigo-600 hover:underline">All logs →</a>
                </div>
                @forelse($logs as $log)
                <div class="px-5 py-3 flex items-start gap-3 border-b border-gray-50 last:border-0">
                    <span class="mt-0.5 px-1.5 py-0.5 rounded text-xs font-medium
                        {{ $log->level === 'error' ? 'bg-red-100 text-red-700' : ($log->level === 'warn' ? 'bg-yellow-100 text-yellow-700' : 'bg-blue-100 text-blue-700') }}">
                        {{ strtoupper($log->level) }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-700">{{ $log->message }}</p>
                        @if($log->context)<p class="text-xs text-gray-400 font-mono truncate">{{ json_encode($log->context) }}</p>@endif
                        <p class="text-xs text-gray-400 mt-0.5">{{ $log->created_at->diffForHumans() }}</p>
                    </div>
                </div>
                @empty
                <p class="px-5 py-8 text-sm text-gray-400 text-center">No logs yet.</p>
                @endforelse
            </div>
        </div>

        {{-- REMINDERS tab --}}
        <div x-show="tab === 'reminders'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="font-semibold text-gray-800">Reminders</h3>
                    <a href="{{ route('reminders.create') }}" class="text-xs text-indigo-600 hover:underline">+ Add</a>
                </div>
                @forelse($reminders as $reminder)
                <div class="px-5 py-3 flex items-center justify-between border-b border-gray-50 last:border-0">
                    <div>
                        <p class="text-sm text-gray-800">{{ Str::limit($reminder->message, 60) }}</p>
                        <p class="text-xs text-gray-400">{{ $reminder->scheduled_at->format('d M Y H:i') }} · {{ $reminder->channel }}</p>
                    </div>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium
                        {{ $reminder->status === 'sent' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700' }}">
                        {{ $reminder->status }}
                    </span>
                </div>
                @empty
                <p class="px-5 py-8 text-sm text-gray-400 text-center">No reminders.</p>
                @endforelse
            </div>
        </div>

        {{-- MEMORY tab --}}
        <div x-show="tab === 'memory'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="font-semibold text-gray-800">🧠 Mémoire de l'agent</h3>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('agents.memory.clear', $agent) }}"
                              x-data @submit.prevent="if(confirm('Effacer la mémoire quotidienne ?')) $el.submit()">
                            @csrf
                            <input type="hidden" name="type" value="daily">
                            <button class="text-xs px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 text-gray-600">Effacer daily</button>
                        </form>
                        <form method="POST" action="{{ route('agents.memory.clear', $agent) }}"
                              x-data @submit.prevent="if(confirm('Effacer toute la mémoire ?')) $el.submit()">
                            @csrf
                            <button class="text-xs px-2 py-1 border border-red-200 rounded hover:bg-red-50 text-red-600">Effacer tout</button>
                        </form>
                    </div>
                </div>

                {{-- Daily notes --}}
                @php
                    $dailyMemories = $memories->where('type', 'daily')->sortByDesc('date')->take(7);
                    $longterm = $memories->where('type', 'longterm')->first();
                @endphp

                <div class="p-5 space-y-4">
                    @if($longterm)
                    <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-4">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-semibold text-indigo-700 uppercase tracking-wide">📚 Mémoire long-terme</p>
                            <form method="POST" action="{{ route('agents.memory.destroy', [$agent, $longterm]) }}"
                                  x-data @submit.prevent="if(confirm('Effacer ?')) $el.submit()">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:text-red-700">Effacer</button>
                            </form>
                        </div>
                        <pre class="text-xs text-indigo-900 whitespace-pre-wrap">{{ $longterm->content }}</pre>
                    </div>
                    @endif

                    @if($dailyMemories->isEmpty() && !$longterm)
                    <p class="text-sm text-gray-400 text-center py-6">Aucune mémoire enregistrée.</p>
                    @endif

                    @foreach($dailyMemories as $mem)
                    <div class="bg-gray-50 border border-gray-100 rounded-xl p-4">
                        <div class="flex justify-between items-start mb-2">
                            <p class="text-xs font-semibold text-gray-600">📅 {{ $mem->date?->format('d M Y') }}</p>
                            <form method="POST" action="{{ route('agents.memory.destroy', [$agent, $mem]) }}"
                                  x-data @submit.prevent="if(confirm('Effacer ?')) $el.submit()">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:text-red-700">Effacer</button>
                            </form>
                        </div>
                        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ $mem->content }}</pre>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- SESSIONS tab --}}
        <div x-show="tab === 'sessions'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800">💬 Sessions actives</h3>
                </div>
                @forelse($sessions as $session)
                <div class="px-5 py-3 flex items-center justify-between border-b border-gray-50 last:border-0">
                    <div>
                        <p class="text-sm font-mono text-gray-800">{{ $session->session_key }}</p>
                        <p class="text-xs text-gray-400">
                            {{ $session->channel }} · peer: {{ $session->peer_id ?? '—' }}
                            · {{ $session->message_count }} messages
                            @if($session->last_message_at) · last: {{ $session->last_message_at->diffForHumans() }}@endif
                        </p>
                    </div>
                    <form method="POST" action="{{ route('agents.sessions.destroy', [$agent, $session]) }}"
                          x-data @submit.prevent="if(confirm('Reset this session?')) $el.submit()">
                        @csrf @method('DELETE')
                        <button class="text-xs text-red-500 hover:text-red-700 px-2 py-1 border border-red-100 rounded hover:bg-red-50">Reset</button>
                    </form>
                </div>
                @empty
                <p class="px-5 py-8 text-sm text-gray-400 text-center">No active sessions.</p>
                @endforelse
            </div>
        </div>

    </div>
</div>
@endsection
