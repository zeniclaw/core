@extends('layouts.app')
@section('title', 'Agents')

@section('content')
<div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Agents</h2>
            <p class="text-sm text-gray-500">{{ $agents->total() }} agent(s) configures</p>
        </div>
        <a href="{{ route('agents.create') }}" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 transition-colors">+ Nouvel Agent</a>
    </div>

    {{-- Agent cards --}}
    @if($agents->isEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 py-16 text-center">
            <p class="text-5xl mb-3">🤖</p>
            <p class="text-lg font-medium text-gray-500">Aucun agent</p>
            <a href="{{ route('agents.create') }}" class="mt-3 inline-block text-indigo-600 hover:underline text-sm">Creer votre premier agent →</a>
        </div>
    @else
        @foreach($agents as $agent)
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-center justify-between mb-1">
                <div class="flex items-center gap-3">
                    <a href="{{ route('agents.show', $agent) }}" class="text-base font-semibold text-gray-900 hover:text-indigo-600 transition-colors">{{ $agent->name }}</a>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono text-gray-500 bg-gray-100">{{ $agent->model }}</span>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-medium
                        {{ $agent->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $agent->status }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('agents.show', $agent) }}" class="text-gray-500 hover:text-indigo-600 text-xs px-2 py-1 rounded hover:bg-gray-100 transition-colors">Voir</a>
                    <a href="{{ route('agents.edit', $agent) }}" class="text-gray-500 hover:text-indigo-600 text-xs px-2 py-1 rounded hover:bg-gray-100 transition-colors">Editer</a>
                    <form method="POST" action="{{ route('agents.destroy', $agent) }}"
                          x-data @submit.prevent="if(confirm('Supprimer {{ addslashes($agent->name) }} ?')) $el.submit()">
                        @csrf @method('DELETE')
                        <button class="text-red-500 hover:text-red-700 text-xs px-2 py-1 rounded hover:bg-red-50 transition-colors">Supprimer</button>
                    </form>
                </div>
            </div>
            @if($agent->description)
                <p class="text-xs text-gray-500 mb-0.5">{{ $agent->description }}</p>
            @endif
            <p class="text-xs text-gray-400">Cree le {{ $agent->created_at->format('d/m/Y') }}</p>
        </div>
        @endforeach

        <div>{{ $agents->links() }}</div>
    @endif

    {{-- Sub-Agents list --}}
    @if($agents->isNotEmpty())
    @php
        $colorMap = [
            'blue'   => ['bg' => 'bg-blue-100',   'text' => 'text-blue-700',   'border' => 'border-blue-200', 'badge' => 'bg-blue-100 text-blue-600'],
            'purple' => ['bg' => 'bg-purple-100', 'text' => 'text-purple-700', 'border' => 'border-purple-200', 'badge' => 'bg-purple-100 text-purple-600'],
            'orange' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'border' => 'border-orange-200', 'badge' => 'bg-orange-100 text-orange-600'],
            'green'  => ['bg' => 'bg-green-100',  'text' => 'text-green-700',  'border' => 'border-green-200', 'badge' => 'bg-green-100 text-green-600'],
            'red'    => ['bg' => 'bg-red-100',    'text' => 'text-red-700',    'border' => 'border-red-200', 'badge' => 'bg-red-100 text-red-600'],
            'teal'   => ['bg' => 'bg-teal-100',   'text' => 'text-teal-700',   'border' => 'border-teal-200', 'badge' => 'bg-teal-100 text-teal-600'],
            'indigo' => ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-700', 'border' => 'border-indigo-200', 'badge' => 'bg-indigo-100 text-indigo-600'],
            'pink'   => ['bg' => 'bg-pink-100',   'text' => 'text-pink-700',   'border' => 'border-pink-200', 'badge' => 'bg-pink-100 text-pink-600'],
            'cyan'   => ['bg' => 'bg-cyan-100',   'text' => 'text-cyan-700',   'border' => 'border-cyan-200', 'badge' => 'bg-cyan-100 text-cyan-600'],
            'amber'  => ['bg' => 'bg-amber-100',  'text' => 'text-amber-700',  'border' => 'border-amber-200', 'badge' => 'bg-amber-100 text-amber-600'],
        ];
        $defaultColor = $colorMap['blue'];
    @endphp

    @php
        $availableModels = array_merge(
            ['default' => 'Par defaut (agent)'],
            \App\Services\ModelResolver::allModels()
        );
    @endphp

    @foreach($agents as $agent)
    <div>
        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">Sub-Agents — {{ $agent->name }}</h3>

        {{-- Stats summary --}}
        @php
            $totalMessages = ($subAgentData[$agent->id]['counts'] ?? collect())->sum();
        @endphp
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-center">
                <p class="text-2xl font-bold text-indigo-600">{{ count($subAgentMeta) }}</p>
                <p class="text-xs text-gray-500 mt-1">Sub-agents</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ $totalMessages }}</p>
                <p class="text-xs text-gray-500 mt-1">Messages routes</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-center">
                @php
                    $disabledCount = count($agent->disabled_sub_agents ?? []);
                    $enabledCount = count($subAgentMeta) - $disabledCount;
                @endphp
                <p class="text-2xl font-bold text-green-600">{{ $enabledCount }}</p>
                <p class="text-xs text-gray-500 mt-1">Agents actifs</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-center">
                @php
                    $lastGlobal = collect($subAgentData[$agent->id]['lastActivity'] ?? [])->filter()->sortDesc()->first();
                @endphp
                <p class="text-sm font-bold text-gray-600">{{ $lastGlobal ? $lastGlobal->diffForHumans() : '—' }}</p>
                <p class="text-xs text-gray-500 mt-1">Derniere activite</p>
            </div>
        </div>

        {{-- Sub-agent list with model config --}}
        <form method="POST" action="{{ route('agents.sub-agent-models', $agent) }}">
            @csrf
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                {{-- Bulk toggle buttons --}}
                <div class="flex items-center justify-between px-5 py-3 bg-gray-50 border-b border-gray-100">
                    <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Sub-agents</span>
                    <div class="flex items-center gap-2" x-data>
                        <button type="button" @click="bulkToggle('{{ route('agents.bulk-toggle-sub-agents', $agent) }}', 'enable_all')"
                                class="px-3 py-1.5 text-xs font-medium text-green-700 bg-green-100 rounded-lg hover:bg-green-200 transition-colors">
                            Tout activer
                        </button>
                        <button type="button" @click="bulkToggle('{{ route('agents.bulk-toggle-sub-agents', $agent) }}', 'disable_all')"
                                class="px-3 py-1.5 text-xs font-medium text-red-700 bg-red-100 rounded-lg hover:bg-red-200 transition-colors">
                            Tout desactiver
                        </button>
                    </div>
                </div>

                {{-- Header --}}
                <div class="hidden sm:grid grid-cols-12 gap-2 px-5 py-3 bg-gray-50 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                    <div class="col-span-1 text-center">Actif</div>
                    <div class="col-span-3">Agent</div>
                    <div class="col-span-2">Description</div>
                    <div class="col-span-2 text-center">Modele</div>
                    <div class="col-span-1 text-center">Version</div>
                    <div class="col-span-1 text-center">Msgs</div>
                    <div class="col-span-2 text-center">Activite</div>
                </div>

                @foreach($subAgentMeta as $key => $meta)
                @php
                    $colors = $colorMap[$meta['color']] ?? $defaultColor;
                    $count = ($subAgentData[$agent->id]['counts'] ?? collect())->get($key, 0);
                    $lastAt = $subAgentData[$agent->id]['lastActivity'][$key] ?? null;
                    $currentModel = data_get($agent->sub_agent_models, $key, 'default');
                    $isDisabled = $agent->isSubAgentDisabled($key);
                @endphp
                <div class="border-b border-gray-100 last:border-0 hover:bg-gray-50 transition-colors">

                    {{-- Mobile layout --}}
                    <div class="sm:hidden p-4 space-y-2" x-data="{ enabled: {{ $isDisabled ? 'false' : 'true' }} }">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <button type="button" @click="toggleAgent('{{ route('agents.toggle-sub-agent', [$agent, $key]) }}', $data)" class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none" :class="enabled ? 'bg-green-500' : 'bg-gray-300'">
                                    <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out" :class="enabled ? 'translate-x-4' : 'translate-x-0'"></span>
                                </button>
                                <a href="{{ route('agents.sub-agent', [$agent, $key]) }}" class="flex items-center gap-2.5">
                                    <span class="w-8 h-8 rounded-lg {{ $colors['bg'] }} flex items-center justify-center text-lg">{{ $meta['icon'] }}</span>
                                    <div>
                                        <span class="font-semibold text-sm" :class="enabled ? 'text-gray-900' : 'text-gray-400 line-through'">{{ $meta['label'] }}</span>
                                        <span class="ml-1.5 px-1.5 py-0.5 rounded text-[10px] font-mono {{ $colors['badge'] }}">v{{ $meta['version'] }}</span>
                                    </div>
                                </a>
                            </div>
                            <span class="{{ $colors['text'] }} font-semibold text-sm">{{ $count }}</span>
                        </div>
                        <p class="text-xs text-gray-500">{{ $meta['description'] }}</p>
                        <div class="flex items-center gap-2">
                            <select name="sub_agent_models[{{ $key }}]"
                                    class="flex-1 px-2 py-1 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-indigo-500 outline-none bg-white">
                                @foreach($availableModels as $modelValue => $modelLabel)
                                    <option value="{{ $modelValue }}" {{ $currentModel === $modelValue ? 'selected' : '' }}>{{ $modelLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex items-center gap-3 text-[10px] text-gray-400">
                            <span>MAJ {{ \Carbon\Carbon::parse($meta['updated_at'])->format('d/m/Y') }}</span>
                            <span>{{ $lastAt ? $lastAt->diffForHumans() : 'Aucune activite' }}</span>
                        </div>
                    </div>

                    {{-- Desktop layout --}}
                    <div class="hidden sm:grid grid-cols-12 gap-2 px-5 py-3 items-center" x-data="{ enabled: {{ $isDisabled ? 'false' : 'true' }} }">
                        <div class="col-span-1 text-center">
                            <button type="button" @click="toggleAgent('{{ route('agents.toggle-sub-agent', [$agent, $key]) }}', $data)"
                                    class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
                                    :class="enabled ? 'bg-green-500' : 'bg-gray-300'">
                                <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                      :class="enabled ? 'translate-x-4' : 'translate-x-0'"></span>
                            </button>
                        </div>
                        <div class="col-span-3">
                            <a href="{{ route('agents.sub-agent', [$agent, $key]) }}" class="flex items-center gap-3 group">
                                <span class="w-8 h-8 rounded-lg {{ $colors['bg'] }} flex items-center justify-center text-lg flex-shrink-0" :class="!enabled && 'opacity-40'">{{ $meta['icon'] }}</span>
                                <div class="min-w-0">
                                    <span class="font-semibold text-sm group-hover:text-indigo-600 transition-colors" :class="enabled ? 'text-gray-900' : 'text-gray-400 line-through'">{{ $meta['label'] }}</span>
                                    @if(!empty($meta['is_private']))
                                    <span class="ml-1 px-1.5 py-0.5 rounded-full text-[9px] font-medium bg-amber-100 text-amber-700">PRIVE</span>
                                    @endif
                                </div>
                            </a>
                        </div>
                        <div class="col-span-2 text-xs text-gray-500 truncate" :class="!enabled && 'opacity-40'" title="{{ $meta['description'] }}">{{ $meta['description'] }}</div>
                        <div class="col-span-2 text-center">
                            <select name="sub_agent_models[{{ $key }}]"
                                    class="w-full px-1.5 py-1 border border-gray-200 rounded text-[10px] focus:ring-2 focus:ring-indigo-500 outline-none bg-white"
                                    :disabled="!enabled" :class="!enabled && 'opacity-40'">
                                @foreach($availableModels as $modelValue => $modelLabel)
                                    <option value="{{ $modelValue }}" {{ $currentModel === $modelValue ? 'selected' : '' }}>{{ $modelLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-span-1 text-center">
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono {{ $colors['badge'] }}" :class="!enabled && 'opacity-40'">v{{ $meta['version'] }}</span>
                        </div>
                        <div class="col-span-1 text-center">
                            <span class="font-semibold text-sm {{ $count > 0 ? $colors['text'] : 'text-gray-400' }}" :class="!enabled && 'opacity-40'">{{ $count }}</span>
                        </div>
                        <div class="col-span-2 text-center text-[10px] text-gray-400" :class="!enabled && 'opacity-40'">{{ $lastAt ? $lastAt->diffForHumans(short: true) : '—' }}</div>
                    </div>
                </div>
                @endforeach

                {{-- Save button --}}
                <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex justify-end">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-700 transition-colors">
                        Sauvegarder les modeles
                    </button>
                </div>
            </div>
        </form>
    </div>
    @endforeach
    @endif
</div>


<script>
function toggleAgent(url, data) {
    fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
    }).then(r => r.json()).then(d => {
        if (d.success) {
            data.enabled = d.enabled;
        }
    });
}

function bulkToggle(url, action) {
    const label = action === 'enable_all' ? 'activer' : 'desactiver';
    if (!confirm(`Voulez-vous ${label} tous les sub-agents ?`)) return;

    fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ action }),
    }).then(r => r.json()).then(d => {
        if (d.success) {
            window.location.reload();
        }
    });
}
</script>
@endsection
