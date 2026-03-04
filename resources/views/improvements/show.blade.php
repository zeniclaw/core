@extends('layouts.app')
@section('title', 'Amelioration: ' . $improvement->improvement_title)

@section('content')
<div class="max-w-3xl space-y-6">

    {{-- Back link --}}
    <a href="{{ route('improvements.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
        &larr; Retour aux ameliorations
    </a>

    {{-- Header --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ $improvement->improvement_title }}</h2>
                <p class="text-sm text-gray-500 mt-1">
                    Agent: {{ $improvement->agent->name ?? '-' }}
                    &middot; Route: {{ $improvement->routed_agent }}
                    &middot; {{ $improvement->created_at->diffForHumans() }}
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
            <span class="px-3 py-1 rounded-full text-sm font-medium {{ $statusColors[$improvement->status] ?? 'bg-gray-100 text-gray-600' }}">
                {{ $improvement->status }}
            </span>
        </div>

        {{-- Trigger message --}}
        <div class="bg-gray-50 rounded-lg p-4 mt-4">
            <p class="text-xs text-gray-500 mb-1 font-medium">Message declencheur</p>
            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $improvement->trigger_message }}</p>
        </div>

        {{-- Agent response --}}
        <div class="bg-blue-50 rounded-lg p-4 mt-3">
            <p class="text-xs text-blue-600 mb-1 font-medium">Reponse de l'agent</p>
            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $improvement->agent_response }}</p>
        </div>

        {{-- Analysis --}}
        @if($improvement->analysis)
        <div class="bg-amber-50 rounded-lg p-4 mt-3">
            <p class="text-xs text-amber-600 mb-1 font-medium">Analyse Claude</p>
            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $improvement->analysis['analysis'] ?? json_encode($improvement->analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</p>
        </div>
        @endif

        {{-- Development plan --}}
        <div class="bg-green-50 rounded-lg p-4 mt-3">
            <p class="text-xs text-green-600 mb-1 font-medium">Plan de developpement</p>
            <div class="text-sm text-gray-800 whitespace-pre-wrap">{{ $improvement->development_plan }}</div>
        </div>

        {{-- Admin notes --}}
        @if($improvement->admin_notes)
        <div class="bg-indigo-50 rounded-lg p-4 mt-3">
            <p class="text-xs text-indigo-600 mb-1 font-medium">Notes admin</p>
            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $improvement->admin_notes }}</p>
        </div>
        @endif
    </div>

    {{-- Actions (pending only) --}}
    @if($improvement->status === 'pending' && in_array(auth()->user()->role, ['superadmin', 'admin']))
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6" x-data="{ showReject: false, showEdit: false }">
        <h3 class="font-semibold text-gray-900 mb-4">Actions</h3>
        <div class="flex items-center gap-3">
            <form method="POST" action="{{ route('improvements.approve', $improvement) }}">
                @csrf
                <button type="submit"
                        class="bg-green-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition-colors"
                        onclick="return confirm('Approuver et lancer un SubAgent pour implementer cette amelioration ?')">
                    Approuver
                </button>
            </form>
            <button @click="showReject = !showReject; showEdit = false"
                    class="bg-red-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-red-700 transition-colors">
                Rejeter
            </button>
            <button @click="showEdit = !showEdit; showReject = false"
                    class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                Modifier
            </button>
        </div>

        {{-- Reject form --}}
        <div x-show="showReject" x-transition class="mt-4">
            <form method="POST" action="{{ route('improvements.reject', $improvement) }}" class="space-y-3">
                @csrf
                <textarea name="admin_notes" rows="2" placeholder="Raison du rejet (optionnel)..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 outline-none"></textarea>
                <button type="submit"
                        class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 transition-colors">
                    Confirmer le rejet
                </button>
            </form>
        </div>

        {{-- Edit form --}}
        <div x-show="showEdit" x-transition class="mt-4">
            <form method="POST" action="{{ route('improvements.update', $improvement) }}" class="space-y-3">
                @csrf
                @method('PUT')
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Titre</label>
                    <input type="text" name="improvement_title" value="{{ $improvement->improvement_title }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Plan de developpement</label>
                    <textarea name="development_plan" rows="6"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono">{{ $improvement->development_plan }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Notes admin</label>
                    <textarea name="admin_notes" rows="2" placeholder="Notes supplementaires..."
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">{{ $improvement->admin_notes }}</textarea>
                </div>
                <button type="submit"
                        class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                    Sauvegarder
                </button>
            </form>
        </div>
    </div>
    @endif

    {{-- SubAgent tracking --}}
    @if($improvement->subAgent)
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-900 mb-4">Execution</h3>
        <a href="{{ route('subagents.show', $improvement->subAgent) }}"
           class="block border border-gray-100 rounded-lg p-4 hover:bg-gray-50 transition">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-sm font-medium text-gray-900">SubAgent #{{ $improvement->subAgent->id }}</span>
                    <span class="text-xs text-gray-400 ml-2">sur <code class="bg-gray-100 px-1 py-0.5 rounded">/home/ubuntu/zeniclaw/</code></span>
                </div>
                @php
                    $saColors = [
                        'queued' => 'bg-gray-100 text-gray-600',
                        'running' => 'bg-purple-100 text-purple-700',
                        'completed' => 'bg-green-100 text-green-700',
                        'failed' => 'bg-red-100 text-red-700',
                    ];
                @endphp
                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $saColors[$improvement->subAgent->status] ?? 'bg-gray-100 text-gray-600' }}
                       {{ $improvement->subAgent->status === 'running' ? 'animate-pulse' : '' }}">
                    {{ $improvement->subAgent->status }}
                </span>
            </div>
            <p class="text-xs text-gray-500 mt-1">
                {{ $improvement->subAgent->api_calls_count }} appels API
                @if($improvement->subAgent->started_at) &middot; Debut: {{ $improvement->subAgent->started_at->format('d/m H:i') }} @endif
                @if($improvement->subAgent->completed_at) &middot; Fin: {{ $improvement->subAgent->completed_at->format('d/m H:i') }} @endif
            </p>
        </a>
    </div>
    @endif

</div>
@endsection
