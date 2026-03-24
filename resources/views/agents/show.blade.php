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
            <div class="flex gap-2 items-center">
                <form method="POST" action="{{ route('agents.toggle-whitelist', $agent) }}">
                    @csrf
                    <button type="submit" class="px-3 py-2 rounded-lg text-sm font-medium transition-colors border
                        {{ $agent->whitelist_enabled ? 'bg-green-50 border-green-200 text-green-700 hover:bg-green-100' : 'bg-gray-50 border-gray-200 text-gray-500 hover:bg-gray-100' }}"
                        title="{{ $agent->whitelist_enabled ? 'Whitelist active : seuls les contacts autorisés peuvent interagir' : 'Whitelist désactivée : tout le monde peut interagir' }}">
                        {{ $agent->whitelist_enabled ? 'Whitelist ON' : 'Whitelist OFF' }}
                    </button>
                </form>
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
            @foreach(['logs'=>'📋 Logs', 'reminders'=>'⏰ Reminders', 'memory'=>'🧠 Mémoire', 'sessions'=>'💬 Sessions', 'orchestrator'=>'🧠 Orchestrator', 'private'=>'🔒 Agents Privés'] as $t => $l)
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

        {{-- ORCHESTRATOR tab --}}
        <div x-show="tab === 'orchestrator'">
            {{-- Stats cards --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                {{-- Total routed --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Messages routés</p>
                    <p class="text-3xl font-bold text-indigo-600">{{ $totalRouted }}</p>
                </div>

                {{-- Agent distribution --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Par agent</p>
                    @php
                        $agentColors = [
                            'chat' => 'bg-blue-500',
                            'dev' => 'bg-purple-500',
                            'reminder' => 'bg-orange-500',
                            'project' => 'bg-green-500',
                            'analysis' => 'bg-red-500',
                            'todo' => 'bg-teal-500',
                        ];
                    @endphp
                    @forelse($agentStats->sortDesc() as $name => $count)
                    <div class="flex items-center justify-between mb-1.5">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full {{ $agentColors[$name] ?? 'bg-gray-400' }}"></span>
                            <span class="text-sm text-gray-700 capitalize">{{ $name }}</span>
                        </div>
                        <span class="text-sm font-medium text-gray-600">{{ $count }} <span class="text-xs text-gray-400">({{ $totalRouted ? round($count / $totalRouted * 100) : 0 }}%)</span></span>
                    </div>
                    @empty
                    <p class="text-sm text-gray-400">Aucune donnée</p>
                    @endforelse
                </div>

                {{-- Model distribution --}}
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
            </div>

            {{-- Routing history table --}}
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
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Agent</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Modèle</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Complexité</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Raisonnement</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @php
                                $agentBadgeColors = [
                                    'chat' => 'bg-blue-100 text-blue-700',
                                    'dev' => 'bg-purple-100 text-purple-700',
                                    'reminder' => 'bg-orange-100 text-orange-700',
                                    'project' => 'bg-green-100 text-green-700',
                                    'analysis' => 'bg-red-100 text-red-700',
                                    'todo' => 'bg-teal-100 text-teal-700',
                                ];
                                $complexityColors = [
                                    'simple' => 'bg-green-100 text-green-700',
                                    'medium' => 'bg-yellow-100 text-yellow-700',
                                    'complex' => 'bg-red-100 text-red-700',
                                ];
                            @endphp
                            @forelse($routingHistory as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2.5 text-xs text-gray-500 whitespace-nowrap">{{ $row->created_at->format('d/m H:i') }}</td>
                                <td class="px-4 py-2.5 text-gray-700 max-w-[200px] truncate" title="{{ $row->body }}">{{ Str::limit($row->body, 60) }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $agentBadgeColors[$row->agent] ?? 'bg-gray-100 text-gray-600' }}">{{ $row->agent }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs font-mono text-gray-600">{{ $row->model }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $complexityColors[$row->complexity] ?? 'bg-gray-100 text-gray-600' }}">{{ $row->complexity }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-gray-500 max-w-[250px] truncate" title="{{ $row->reasoning }}">{{ $row->reasoning }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-sm text-gray-400 text-center">Aucune décision de routage enregistrée.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>


        {{-- PRIVATE AGENTS tab --}}
        <div x-show="tab === 'private'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800">🔒 Agents Prives — Acces par session</h3>
                    <p class="text-xs text-gray-500 mt-1">Configurez quels contacts/sessions peuvent utiliser chaque agent prive. Entrez les peer IDs separes par des virgules.</p>
                </div>

                @php
                    $privateAgents = collect(\App\Http\Controllers\AgentController::getPrivateSubAgents());
                    $currentAccess = $agent->private_sub_agents ?? [];
                    $allSessions = $agent->sessions()->orderByDesc('last_message_at')->get();
                @endphp

                @if($privateAgents->isEmpty())
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">Aucun agent prive disponible.</p>
                @else
                <form method="POST" action="{{ route('agents.private-agent-access', $agent) }}">
                    @csrf
                    <div class="divide-y divide-gray-50">
                        @foreach($privateAgents as $key => $meta)
                        @php
                            $allowedPeers = $currentAccess[$key] ?? [];
                        @endphp
                        <div class="px-5 py-4">
                            <div class="flex items-center gap-3 mb-3">
                                <span class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center text-lg">{{ $meta['icon'] }}</span>
                                <div>
                                    <span class="font-semibold text-sm text-gray-900">{{ $meta['label'] }}</span>
                                    <span class="ml-2 px-1.5 py-0.5 rounded text-[10px] font-mono bg-amber-100 text-amber-600">v{{ $meta['version'] }}</span>
                                    <span class="ml-2 px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-amber-50 text-amber-700">PRIVE</span>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mb-3">{{ $meta['description'] }}</p>

                            <div class="space-y-2">
                                <label class="block text-xs font-medium text-gray-600">Sessions autorisees (peer IDs)</label>
                                <textarea name="private_sub_agents[{{ $key }}]" rows="2"
                                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none"
                                    placeholder="254936424145066@lid, web-1, ...">{{ implode(', ', $allowedPeers) }}</textarea>

                                {{-- Quick-add buttons from existing sessions --}}
                                @if($allSessions->isNotEmpty())
                                <div class="flex flex-wrap gap-1 mt-1">
                                    <span class="text-[10px] text-gray-400 mr-1 self-center">Ajouter :</span>
                                    @foreach($allSessions->take(10) as $sess)
                                    <button type="button"
                                        class="px-2 py-0.5 text-[10px] rounded border border-gray-200 hover:bg-amber-50 hover:border-amber-300 text-gray-600 transition-colors"
                                        onclick="addPeer(this, '{{ $key }}', '{{ $sess->peer_id }}')"
                                        title="{{ $sess->channel }} — {{ $sess->peer_id }}">
                                        {{ $sess->channel === 'whatsapp' ? '📱' : ($sess->channel === 'web' ? '🌐' : '💬') }}
                                        {{ Str::limit($sess->peer_id, 20) }}
                                    </button>
                                    @endforeach
                                </div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex justify-end">
                        <button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg text-xs font-medium hover:bg-amber-700 transition-colors">
                            Sauvegarder les acces
                        </button>
                    </div>
                </form>
                @endif
            </div>
            {{-- Private agent secrets --}}
            @php
                $requiredSecrets = \App\Http\Controllers\AgentController::getPrivateAgentSecrets();
                $existingSecrets = $agent->secrets()->pluck('key_name')->toArray();
            @endphp

            @if(!empty($requiredSecrets))
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mt-4">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800">🔑 Secrets — Configuration des agents prives</h3>
                    <p class="text-xs text-gray-500 mt-1">Configurez les cles API et secrets requis par chaque agent prive.</p>
                </div>

                <form method="POST" action="{{ route('agents.private-agent-secrets', $agent) }}">
                    @csrf
                    <div class="divide-y divide-gray-50">
                        @foreach($requiredSecrets as $agentKey => $secrets)
                        @php
                            $agentMeta = $privateAgents[$agentKey] ?? null;
                        @endphp
                        @if($agentMeta)
                        <div class="px-5 py-4">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-lg">{{ $agentMeta['icon'] }}</span>
                                <span class="font-semibold text-sm text-gray-900">{{ $agentMeta['label'] }}</span>
                            </div>

                            @foreach($secrets as $secret)
                            <div class="mb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <label class="block text-xs font-medium text-gray-600">{{ $secret['label'] }}</label>
                                    @if(in_array($secret['key'], $existingSecrets))
                                        <span class="px-1.5 py-0.5 rounded-full text-[9px] font-medium bg-green-100 text-green-700">configure</span>
                                    @else
                                        <span class="px-1.5 py-0.5 rounded-full text-[9px] font-medium bg-red-100 text-red-700">manquant</span>
                                    @endif
                                </div>
                                <p class="text-[11px] text-gray-400 mb-1">{{ $secret['description'] }}</p>
                                <input type="password" name="agent_secrets[{{ $secret['key'] }}]"
                                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none"
                                    placeholder="{{ in_array($secret['key'], $existingSecrets) ? '••••••••  (laisser vide pour ne pas changer)' : 'Entrer la valeur...' }}">
                            </div>
                            @endforeach
                        </div>
                        @endif
                        @endforeach
                    </div>

                    <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex justify-end">
                        <button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg text-xs font-medium hover:bg-amber-700 transition-colors">
                            Sauvegarder les secrets
                        </button>
                    </div>
                </form>
            </div>
            @endif

        </div>

        <script>
        function addPeer(btn, agentKey, peerId) {
            const textarea = document.querySelector(`textarea[name="private_sub_agents[${agentKey}]"]`);
            const current = textarea.value.split(',').map(s => s.trim()).filter(Boolean);
            if (!current.includes(peerId)) {
                current.push(peerId);
                textarea.value = current.join(', ');
            }
            btn.classList.add('bg-amber-100', 'border-amber-400');
        }
        </script>
    </div>
</div>
@endsection
