@extends('layouts.app')
@section('title', 'Workflows')

@section('content')
<div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Workflows</h2>
            <p class="text-sm text-gray-500">{{ $workflows->total() }} workflow(s) enregistre(s)</p>
        </div>
    </div>

    {{-- Workflow list --}}
    @if($workflows->isEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 py-16 text-center">
            <p class="text-5xl mb-3">&#9881;&#65039;</p>
            <p class="text-lg font-medium text-gray-500">Aucun workflow</p>
            <p class="text-sm text-gray-400 mt-1">Cree un workflow via WhatsApp: <code class="bg-gray-100 px-1 rounded">/workflow create [nom] [etapes]</code></p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($workflows as $workflow)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-3">
                        <a href="{{ route('workflows.show', $workflow) }}" class="text-base font-semibold text-gray-900 hover:text-indigo-600 transition-colors">{{ $workflow->name }}</a>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-medium {{ $workflow->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $workflow->is_active ? 'Actif' : 'Inactif' }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('workflows.trigger', $workflow) }}">
                            @csrf
                            <button type="submit" class="text-xs bg-indigo-50 text-indigo-600 px-3 py-1 rounded hover:bg-indigo-100 transition-colors">Lancer</button>
                        </form>
                        <form method="POST" action="{{ route('workflows.toggle', $workflow) }}">
                            @csrf
                            <button type="submit" class="text-xs bg-gray-50 text-gray-600 px-3 py-1 rounded hover:bg-gray-100 transition-colors">
                                {{ $workflow->is_active ? 'Desactiver' : 'Activer' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('workflows.destroy', $workflow) }}" onsubmit="return confirm('Supprimer ce workflow ?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs bg-red-50 text-red-600 px-3 py-1 rounded hover:bg-red-100 transition-colors">Supprimer</button>
                        </form>
                    </div>
                </div>

                @if($workflow->description)
                    <p class="text-sm text-gray-500 mb-2">{{ $workflow->description }}</p>
                @endif

                <div class="flex items-center gap-4 text-xs text-gray-400">
                    <span>{{ count($workflow->steps ?? []) }} etape(s)</span>
                    <span>{{ $workflow->run_count }} execution(s)</span>
                    <span>Dernier: {{ $workflow->last_run_at ? $workflow->last_run_at->diffForHumans() : 'jamais' }}</span>
                    <span>{{ $workflow->user_phone }}</span>
                </div>

                {{-- Steps preview --}}
                <div class="mt-3 space-y-1">
                    @foreach($workflow->steps as $i => $step)
                        <div class="flex items-center gap-2 text-xs">
                            <span class="text-gray-400 font-mono w-5">{{ $i + 1 }}.</span>
                            <span class="bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded font-mono">{{ $step['agent'] ?? 'auto' }}</span>
                            <span class="text-gray-600 truncate">{{ Str::limit($step['message'] ?? '', 80) }}</span>
                            @if(!empty($step['condition']))
                                <span class="text-orange-500 text-[10px]">[si: {{ $step['condition'] }}]</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>

        {{ $workflows->links() }}
    @endif
</div>
@endsection
