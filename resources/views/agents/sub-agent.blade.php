@extends('layouts.app')
@section('title', $meta['label'] . ' — ' . $agent->name)

@php
    $colorMap = [
        'blue'   => ['bg' => 'bg-blue-50',   'border' => 'border-blue-200', 'icon_bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'badge' => 'bg-blue-100 text-blue-600', 'stat' => 'text-blue-600'],
        'purple' => ['bg' => 'bg-purple-50', 'border' => 'border-purple-200', 'icon_bg' => 'bg-purple-100', 'text' => 'text-purple-700', 'badge' => 'bg-purple-100 text-purple-600', 'stat' => 'text-purple-600'],
        'orange' => ['bg' => 'bg-orange-50', 'border' => 'border-orange-200', 'icon_bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'badge' => 'bg-orange-100 text-orange-600', 'stat' => 'text-orange-600'],
        'green'  => ['bg' => 'bg-green-50',  'border' => 'border-green-200', 'icon_bg' => 'bg-green-100', 'text' => 'text-green-700', 'badge' => 'bg-green-100 text-green-600', 'stat' => 'text-green-600'],
        'red'    => ['bg' => 'bg-red-50',    'border' => 'border-red-200', 'icon_bg' => 'bg-red-100', 'text' => 'text-red-700', 'badge' => 'bg-red-100 text-red-600', 'stat' => 'text-red-600'],
        'amber'  => ['bg' => 'bg-amber-50',  'border' => 'border-amber-200', 'icon_bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'badge' => 'bg-amber-100 text-amber-600', 'stat' => 'text-amber-600'],
    ];
    $colors = $colorMap[$meta['color']];
    $complexityColors = [
        'simple' => 'bg-green-100 text-green-700',
        'medium' => 'bg-yellow-100 text-yellow-700',
        'complex' => 'bg-red-100 text-red-700',
    ];
@endphp

@section('content')
<div class="space-y-6">

    {{-- Header --}}
    <div class="{{ $colors['bg'] }} border {{ $colors['border'] }} rounded-xl p-6">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-xl {{ $colors['icon_bg'] }} flex items-center justify-center text-3xl">{{ $meta['icon'] }}</div>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-xl font-bold text-gray-900">{{ $meta['label'] }}</h2>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $colors['badge'] }}">virtual</span>
                    </div>
                    <p class="text-gray-500 text-sm mt-0.5">{{ $meta['description'] }}</p>
                    <p class="text-xs text-gray-400 mt-1">Parent : {{ $agent->name }}</p>
                </div>
            </div>
            <a href="{{ route('agents.show', $agent) }}" class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-white transition-colors">
                ← Retour à l'agent
            </a>
        </div>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Messages routés</p>
            <p class="text-3xl font-bold {{ $colors['stat'] }}">{{ $totalRouted }}</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Par modèle</p>
            @forelse($modelStats->sortDesc() as $model => $count)
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-sm text-gray-700">{{ $model }}</span>
                <span class="text-sm font-medium text-gray-600">{{ $count }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-400">Aucune donnée</p>
            @endforelse
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Par complexité</p>
            @forelse($complexityStats->sortDesc() as $complexity => $count)
            <div class="flex items-center justify-between mb-1.5">
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $complexityColors[$complexity] ?? 'bg-gray-100 text-gray-600' }}">{{ $complexity }}</span>
                </div>
                <span class="text-sm font-medium text-gray-600">{{ $count }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-400">Aucune donnée</p>
            @endforelse
        </div>
    </div>

    {{-- Tabs --}}
    <div x-data="{ tab: 'routing' }">
        <div class="flex gap-1 bg-white rounded-xl shadow-sm border border-gray-100 p-1 mb-4">
            <button @click="tab = 'routing'"
                    :class="tab === 'routing' ? 'bg-indigo-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'"
                    class="flex-1 px-3 py-2 rounded-lg text-sm font-medium transition-all">Routing History</button>
            <button @click="tab = 'logs'"
                    :class="tab === 'logs' ? 'bg-indigo-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'"
                    class="flex-1 px-3 py-2 rounded-lg text-sm font-medium transition-all">Agent Logs</button>
        </div>

        {{-- ROUTING HISTORY --}}
        <div x-show="tab === 'routing'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800">Historique de routage</h3>
                </div>
                <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Message</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Modèle</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Complexité</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Raisonnement</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse($routingHistory as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2.5 text-xs text-gray-500 whitespace-nowrap">{{ $row->created_at->format('d/m H:i') }}</td>
                                <td class="px-4 py-2.5 text-gray-700 max-w-[200px] truncate" title="{{ $row->body }}">{{ Str::limit($row->body, 60) }}</td>
                                <td class="px-4 py-2.5 text-xs font-mono text-gray-600">{{ $row->model }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $complexityColors[$row->complexity] ?? 'bg-gray-100 text-gray-600' }}">{{ $row->complexity }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-gray-500 max-w-[250px] truncate" title="{{ $row->reasoning }}">{{ $row->reasoning }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-sm text-gray-400 text-center">Aucune décision de routage enregistrée.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- AGENT LOGS --}}
        <div x-show="tab === 'logs'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800">Logs [{{ $subAgent }}]</h3>
                </div>
                @forelse($agentLogs as $log)
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
                <p class="px-5 py-8 text-sm text-gray-400 text-center">Aucun log pour cet agent.</p>
                @endforelse
            </div>
        </div>
    </div>

</div>
@endsection
