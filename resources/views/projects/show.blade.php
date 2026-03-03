@extends('layouts.app')
@section('title', 'Projet: ' . $project->name)

@section('content')
<div class="max-w-3xl space-y-6">

    {{-- Back link --}}
    <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
        &larr; Retour aux projets
    </a>

    {{-- Header --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ $project->name }}</h2>
                <p class="text-sm text-gray-500 mt-1">
                    Demande de {{ $project->requester_name }}
                    &middot; {{ $project->created_at->diffForHumans() }}
                </p>
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
            <span class="px-3 py-1 rounded-full text-sm font-medium {{ $statusColors[$project->status] ?? 'bg-gray-100 text-gray-600' }}">
                {{ $project->status }}
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm mb-4">
            <div>
                <span class="text-gray-500">URL GitLab:</span>
                <a href="{{ $project->gitlab_url }}" target="_blank" rel="noopener"
                   class="ml-1 text-indigo-600 hover:text-indigo-800 font-mono text-xs break-all">
                    {{ $project->gitlab_url }}
                </a>
            </div>
            <div>
                <span class="text-gray-500">Demandeur:</span>
                <span class="ml-1 font-medium">{{ $project->requester_name }}</span>
                <span class="text-xs text-gray-400 ml-1">({{ $project->requester_phone }})</span>
            </div>
            <div>
                <span class="text-gray-500">Agent:</span>
                <span class="ml-1 font-medium">{{ $project->agent->name }}</span>
            </div>
            @if($project->approver)
            <div>
                <span class="text-gray-500">Approuve par:</span>
                <span class="ml-1 font-medium">{{ $project->approver->name }}</span>
                <span class="text-xs text-gray-400 ml-1">{{ $project->approved_at?->diffForHumans() }}</span>
            </div>
            @endif
        </div>

        {{-- Description --}}
        <div class="bg-gray-50 rounded-lg p-4 mt-4">
            <p class="text-xs text-gray-500 mb-1 font-medium">Description de la demande</p>
            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $project->request_description }}</p>
        </div>

        {{-- Rejection reason --}}
        @if($project->status === 'rejected' && $project->rejection_reason)
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mt-4">
            <p class="text-xs text-red-600 mb-1 font-medium">Raison du rejet</p>
            <p class="text-sm text-red-800">{{ $project->rejection_reason }}</p>
        </div>
        @endif
    </div>

    {{-- Approve / Reject buttons (pending only) --}}
    @if($project->status === 'pending' && in_array(auth()->user()->role, ['superadmin', 'admin']))
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6" x-data="{ showReject: false }">
        <h3 class="font-semibold text-gray-900 mb-4">Actions</h3>
        <div class="flex items-center gap-3">
            <form method="POST" action="{{ route('projects.approve', $project) }}">
                @csrf
                <button type="submit"
                        class="bg-green-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition-colors"
                        onclick="return confirm('Approuver ce projet et lancer le SubAgent ?')">
                    Approuver
                </button>
            </form>
            <button @click="showReject = !showReject"
                    class="bg-red-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-red-700 transition-colors">
                Rejeter
            </button>
        </div>
        <div x-show="showReject" x-transition class="mt-4">
            <form method="POST" action="{{ route('projects.reject', $project) }}" class="space-y-3">
                @csrf
                <textarea name="rejection_reason" rows="2" placeholder="Raison du rejet (optionnel)..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 outline-none"></textarea>
                <button type="submit"
                        class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 transition-colors">
                    Confirmer le rejet
                </button>
            </form>
        </div>
    </div>
    @endif

    {{-- SubAgents list --}}
    @if($project->subAgents->isNotEmpty())
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-900 mb-4">SubAgents</h3>
        <div class="space-y-3">
            @foreach($project->subAgents as $sa)
            <a href="{{ route('subagents.show', $sa) }}"
               class="block border border-gray-100 rounded-lg p-4 hover:bg-gray-50 transition">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-sm font-medium text-gray-900">SubAgent #{{ $sa->id }}</span>
                        @if($sa->branch_name)
                            <span class="text-xs text-gray-400 ml-2 font-mono">{{ $sa->branch_name }}</span>
                        @endif
                    </div>
                    @php
                        $saColors = [
                            'queued' => 'bg-gray-100 text-gray-600',
                            'running' => 'bg-purple-100 text-purple-700',
                            'completed' => 'bg-green-100 text-green-700',
                            'failed' => 'bg-red-100 text-red-700',
                        ];
                    @endphp
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $saColors[$sa->status] ?? 'bg-gray-100 text-gray-600' }}
                           {{ $sa->status === 'running' ? 'animate-pulse' : '' }}">
                        {{ $sa->status }}
                    </span>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    {{ $sa->api_calls_count }} appels API
                    @if($sa->started_at) &middot; Debut: {{ $sa->started_at->format('d/m H:i') }} @endif
                    @if($sa->completed_at) &middot; Fin: {{ $sa->completed_at->format('d/m H:i') }} @endif
                </p>
            </a>
            @endforeach
        </div>
    </div>
    @endif

</div>
@endsection
