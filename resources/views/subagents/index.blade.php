@extends('layouts.app')
@section('title', 'SubAgents')

@section('content')
<div class="max-w-5xl">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">SubAgents</h2>
            <p class="text-sm text-gray-500">Agents autonomes executant des modifications de code.</p>
        </div>

        {{-- Default timeout setting --}}
        <form method="POST" action="{{ route('subagents.default-timeout') }}" class="flex items-center gap-2">
            @csrf
            <label class="text-xs text-gray-500 whitespace-nowrap">Timeout par defaut:</label>
            <input type="number" name="timeout_minutes" value="{{ $defaultTimeout }}" min="1" max="120"
                   class="w-16 px-2 py-1 border border-gray-300 rounded-lg text-sm text-center focus:ring-2 focus:ring-indigo-500 outline-none">
            <span class="text-xs text-gray-400">min</span>
            <button type="submit" class="px-2 py-1 bg-indigo-600 text-white rounded-lg text-xs hover:bg-indigo-700 transition-colors">OK</button>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($subAgents->isEmpty())
            <div class="px-6 py-12 text-center text-gray-400">
                <p class="text-4xl mb-3">🚀</p>
                <p class="text-sm">Aucun SubAgent pour le moment.</p>
                <p class="text-xs text-gray-400 mt-1">Les SubAgents sont crees quand un projet est approuve.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">ID</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Projet</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Status</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Timeout</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Appels API</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Debut</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Fin</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($subAgents as $sa)
                    <tr class="hover:bg-gray-50/50">
                        <td class="px-4 py-3 font-mono text-gray-900">#{{ $sa->id }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('projects.show', $sa->project_id) }}"
                               class="text-indigo-600 hover:text-indigo-800 font-medium">
                                {{ $sa->project->name ?? 'Projet #' . $sa->project_id }}
                            </a>
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $saColors = [
                                    'queued' => 'bg-gray-100 text-gray-600',
                                    'running' => 'bg-purple-100 text-purple-700',
                                    'completed' => 'bg-green-100 text-green-700',
                                    'failed' => 'bg-red-100 text-red-700',
                                    'killed' => 'bg-orange-100 text-orange-700',
                                ];
                            @endphp
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $saColors[$sa->status] ?? 'bg-gray-100 text-gray-600' }}
                                   {{ $sa->status === 'running' ? 'animate-pulse' : '' }}">
                                {{ $sa->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $sa->timeout_minutes ?? 10 }} min</td>
                        <td class="px-4 py-3 text-gray-600">{{ $sa->api_calls_count }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $sa->started_at?->format('d/m H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $sa->completed_at?->format('d/m H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right flex items-center justify-end gap-2">
                            @if(in_array($sa->status, ['running', 'queued']))
                            <form method="POST" action="{{ route('subagents.kill', $sa) }}" class="inline"
                                  x-data @submit.prevent="if(confirm('Arreter ce SubAgent ?')) $el.submit()">
                                @csrf
                                <button class="px-2 py-1 bg-red-50 text-red-600 rounded text-xs font-medium hover:bg-red-100 transition-colors">
                                    Arreter
                                </button>
                            </form>
                            @endif
                            <a href="{{ route('subagents.show', $sa) }}"
                               class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">Voir</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-100">
                {{ $subAgents->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
