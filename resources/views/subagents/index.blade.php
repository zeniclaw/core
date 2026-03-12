@extends('layouts.app')
@section('title', 'SubAgents')

@section('content')
<div class="max-w-5xl">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">SubAgents</h2>
            <p class="text-sm text-gray-500">Agents autonomes executant des modifications de code.</p>
        </div>

        <form method="POST" action="{{ route('subagents.default-timeout') }}" class="flex items-center gap-2">
            @csrf
            <label class="text-xs text-gray-500 whitespace-nowrap">Timeout:</label>
            <input type="number" name="timeout_minutes" value="{{ $defaultTimeout }}" min="1" max="120"
                   class="w-16 px-2 py-1 border border-gray-300 rounded-lg text-sm text-center focus:ring-2 focus:ring-indigo-500 outline-none">
            <span class="text-xs text-gray-400">min</span>
            <button type="submit" class="px-2 py-1 bg-indigo-600 text-white rounded-lg text-xs hover:bg-indigo-700 transition-colors">OK</button>
        </form>
    </div>

    @if($subAgents->isEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-6 py-12 text-center text-gray-400">
            <p class="text-4xl mb-3">🚀</p>
            <p class="text-sm">Aucun SubAgent pour le moment.</p>
        </div>
    @else
        {{-- Card list (mobile-friendly) --}}
        <div class="space-y-3">
            @foreach($subAgents as $sa)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-mono font-medium text-gray-900">#{{ $sa->id }}</span>
                            @if($sa->project_id && $sa->project)
                                <a href="{{ route('projects.show', $sa->project_id) }}"
                                   class="text-sm text-indigo-600 hover:text-indigo-800 font-medium truncate">
                                    {{ $sa->project->name }}
                                </a>
                            @else
                                <span class="text-sm text-purple-600 font-medium">{{ $sa->type === 'research' ? '🔍 Recherche' : 'Tache' }}</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 mt-1 line-clamp-2">{{ $sa->task_description }}</p>
                    </div>
                    @php
                        $saColors = [
                            'queued' => 'bg-gray-100 text-gray-600',
                            'running' => 'bg-purple-100 text-purple-700',
                            'completed' => 'bg-green-100 text-green-700',
                            'failed' => 'bg-red-100 text-red-700',
                            'killed' => 'bg-orange-100 text-orange-700',
                        ];
                    @endphp
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium flex-shrink-0 {{ $saColors[$sa->status] ?? 'bg-gray-100 text-gray-600' }}
                           {{ $sa->status === 'running' ? 'animate-pulse' : '' }}">
                        {{ $sa->status }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-xs text-gray-400">
                    <span>{{ $sa->timeout_minutes ?? 10 }} min</span>
                    <span>{{ $sa->api_calls_count }} API calls</span>
                    @if($sa->started_at)
                        <span>{{ $sa->started_at->format('d/m H:i') }}</span>
                    @endif
                    @if($sa->completed_at)
                        <span>→ {{ $sa->completed_at->format('d/m H:i') }}</span>
                    @endif
                    @if($sa->branch_name)
                        <span class="font-mono">{{ $sa->branch_name }}</span>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-2 mt-3">
                    @if(in_array($sa->status, ['running', 'queued']))
                    <form method="POST" action="{{ route('subagents.kill', $sa) }}" class="inline"
                          x-data @submit.prevent="if(confirm('Arreter ce SubAgent ?')) $el.submit()">
                        @csrf
                        <button class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg text-xs font-medium hover:bg-red-100 transition-colors">
                            Arreter
                        </button>
                    </form>
                    @endif
                    <a href="{{ route('subagents.show', $sa) }}"
                       class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-200 transition-colors">
                        Voir
                    </a>
                </div>
            </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $subAgents->links() }}
        </div>
    @endif
</div>
@endsection
