@extends('layouts.app')
@section('title', 'Ameliorations')

@section('content')
<div class="max-w-5xl">

    <div class="mb-6">
        <h2 class="text-lg font-semibold text-gray-900">Auto-ameliorations</h2>
        <p class="text-sm text-gray-500">Suggestions d'amelioration generees automatiquement.</p>
    </div>

    {{-- Filters (scrollable on mobile) --}}
    <div class="flex gap-2 overflow-x-auto pb-2 mb-4 -mx-1 px-1">
        @php
            $currentStatus = request('status');
            $filters = ['' => 'Tous', 'pending' => 'En attente', 'in_progress' => 'En cours', 'completed' => 'Termines', 'failed' => 'Echoues', 'rejected' => 'Rejetes'];
        @endphp
        @foreach($filters as $value => $label)
            <a href="{{ route('improvements.index', $value ? ['status' => $value] : []) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap transition-colors flex-shrink-0
                      {{ $currentStatus === ($value ?: null) ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if($improvements->isEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-6 py-12 text-center text-gray-400">
            <p class="text-4xl mb-3">🧠</p>
            <p class="text-sm">Aucune amelioration pour le moment.</p>
        </div>
    @else
        {{-- Card list (mobile-friendly) --}}
        <div class="space-y-3">
            @foreach($improvements as $improvement)
            <a href="{{ route('improvements.show', $improvement) }}"
               class="block bg-white rounded-xl shadow-sm border border-gray-100 p-4 hover:bg-gray-50/50 transition">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ $improvement->improvement_title }}</p>
                        <div class="flex flex-wrap items-center gap-2 mt-1.5">
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                {{ $improvement->routed_agent }}
                            </span>
                            <span class="text-xs text-gray-400">{{ $improvement->created_at->format('d/m H:i') }}</span>
                        </div>
                    </div>
                    @php
                        $statusColors = [
                            'pending' => 'bg-yellow-100 text-yellow-700',
                            'approved' => 'bg-blue-100 text-blue-700',
                            'rejected' => 'bg-red-100 text-red-700',
                            'in_progress' => 'bg-purple-100 text-purple-700',
                            'completed' => 'bg-green-100 text-green-700',
                            'failed' => 'bg-red-100 text-red-700',
                        ];
                    @endphp
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium flex-shrink-0 {{ $statusColors[$improvement->status] ?? 'bg-gray-100 text-gray-600' }}">
                        {{ $improvement->status }}
                    </span>
                </div>
                @if($improvement->subAgent)
                <div class="mt-2 flex items-center gap-2 text-xs text-gray-400">
                    <span>SubAgent #{{ $improvement->subAgent->id }}</span>
                    <span>&middot;</span>
                    <span>{{ $improvement->subAgent->api_calls_count }} API calls</span>
                    @if($improvement->subAgent->status === 'running')
                        <span class="inline-block w-2 h-2 rounded-full bg-purple-500 animate-pulse"></span>
                    @endif
                </div>
                @endif
            </a>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $improvements->withQueryString()->links() }}
        </div>
    @endif
</div>
@endsection
