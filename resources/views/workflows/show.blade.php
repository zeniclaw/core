@extends('layouts.app')
@section('title', 'Workflow: ' . $workflow->name)

@section('content')
<div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('workflows.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Workflows</a>
            <h2 class="text-lg font-semibold text-gray-900 mt-1">{{ $workflow->name }}</h2>
            <p class="text-sm text-gray-500">
                {{ $workflow->is_active ? 'Actif' : 'Inactif' }} &middot;
                {{ $workflow->run_count }} execution(s) &middot;
                Dernier: {{ $workflow->last_run_at ? $workflow->last_run_at->format('d/m/Y H:i') : 'jamais' }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('workflows.trigger', $workflow) }}">
                @csrf
                <button type="submit" class="text-sm bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition-colors">Lancer</button>
            </form>
            <form method="POST" action="{{ route('workflows.toggle', $workflow) }}">
                @csrf
                <button type="submit" class="text-sm bg-gray-100 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-200 transition-colors">
                    {{ $workflow->is_active ? 'Desactiver' : 'Activer' }}
                </button>
            </form>
            <form method="POST" action="{{ route('workflows.destroy', $workflow) }}" onsubmit="return confirm('Supprimer ce workflow ?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-sm bg-red-50 text-red-600 px-4 py-2 rounded-lg hover:bg-red-100 transition-colors">Supprimer</button>
            </form>
        </div>
    </div>

    @if($workflow->description)
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="text-sm font-medium text-gray-700 mb-1">Description</h3>
        <p class="text-sm text-gray-600">{{ $workflow->description }}</p>
    </div>
    @endif

    {{-- Execution result flash --}}
    @if(session('execution_result'))
        @php $execResult = session('execution_result'); @endphp
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="text-sm font-medium text-gray-700 mb-2">Dernier resultat d'execution</h3>
            <div class="text-xs font-mono bg-gray-50 p-3 rounded-lg whitespace-pre-wrap">{{ \App\Services\WorkflowExecutor::formatResults($execResult) }}</div>
        </div>
    @endif

    {{-- Steps --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="text-sm font-medium text-gray-700 mb-3">Etapes ({{ count($workflow->steps ?? []) }})</h3>
        <div class="space-y-3">
            @foreach($workflow->steps as $i => $step)
            <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                <span class="flex-shrink-0 w-6 h-6 bg-indigo-100 text-indigo-600 rounded-full flex items-center justify-center text-xs font-bold">{{ $i + 1 }}</span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-xs font-mono bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded">{{ $step['agent'] ?? 'auto' }}</span>
                        @if(!empty($step['condition']))
                            <span class="text-xs text-orange-500">Condition: {{ $step['condition'] }}</span>
                        @endif
                        @if(!empty($step['on_error']))
                            <span class="text-xs text-red-400">on_error: {{ $step['on_error'] }}</span>
                        @endif
                    </div>
                    <p class="text-sm text-gray-700">{{ $step['message'] ?? '' }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Info --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="text-sm font-medium text-gray-700 mb-2">Informations</h3>
        <dl class="grid grid-cols-2 gap-2 text-sm">
            <dt class="text-gray-400">Utilisateur</dt>
            <dd class="text-gray-700">{{ $workflow->user_phone }}</dd>
            <dt class="text-gray-400">Agent ID</dt>
            <dd class="text-gray-700">{{ $workflow->agent_id ?? '-' }}</dd>
            <dt class="text-gray-400">Cree le</dt>
            <dd class="text-gray-700">{{ $workflow->created_at->format('d/m/Y H:i') }}</dd>
            <dt class="text-gray-400">Modifie le</dt>
            <dd class="text-gray-700">{{ $workflow->updated_at->format('d/m/Y H:i') }}</dd>
        </dl>
    </div>
</div>
@endsection
