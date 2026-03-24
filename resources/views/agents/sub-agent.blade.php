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
                        @if($isPrivate)
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">PRIVE</span>
                        @endif
                    </div>
                    <p class="text-gray-500 text-sm mt-0.5">{{ $meta['description'] }}</p>
                    <p class="text-xs text-gray-400 mt-1">Parent : {{ $agent->name }}</p>
                </div>
            </div>
            <a href="{{ route('agents.show', $agent) }}" class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-white transition-colors">
                ← Retour a l'agent
            </a>
        </div>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Messages routes</p>
            <p class="text-3xl font-bold {{ $colors['stat'] }}">{{ $totalRouted }}</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Par modele</p>
            @forelse($modelStats->sortDesc() as $model => $count)
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-sm text-gray-700">{{ $model }}</span>
                <span class="text-sm font-medium text-gray-600">{{ $count }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-400">Aucune donnee</p>
            @endforelse
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Par complexite</p>
            @forelse($complexityStats->sortDesc() as $complexity => $count)
            <div class="flex items-center justify-between mb-1.5">
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $complexityColors[$complexity] ?? 'bg-gray-100 text-gray-600' }}">{{ $complexity }}</span>
                </div>
                <span class="text-sm font-medium text-gray-600">{{ $count }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-400">Aucune donnee</p>
            @endforelse
        </div>
    </div>

    {{-- Tabs --}}
    <div x-data="{ tab: '{{ $isPrivate ? 'access' : 'routing' }}' }">
        <div class="flex gap-1 bg-white rounded-xl shadow-sm border border-gray-100 p-1 mb-4">
            @if($isPrivate)
            <button @click="tab = 'access'"
                    :class="tab === 'access' ? 'bg-amber-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'"
                    class="flex-1 px-3 py-2 rounded-lg text-sm font-medium transition-all">WhatsApp Access</button>
            @endif
            <button @click="tab = 'routing'"
                    :class="tab === 'routing' ? 'bg-indigo-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'"
                    class="flex-1 px-3 py-2 rounded-lg text-sm font-medium transition-all">Routing History</button>
            <button @click="tab = 'logs'"
                    :class="tab === 'logs' ? 'bg-indigo-600 text-white shadow' : 'text-gray-500 hover:text-gray-800'"
                    class="flex-1 px-3 py-2 rounded-lg text-sm font-medium transition-all">Agent Logs</button>
        </div>

        {{-- WHATSAPP ACCESS tab --}}
        @if($isPrivate)
        <div x-show="tab === 'access'">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800">WhatsApp — Conversations autorisees</h3>
                    <p class="text-xs text-gray-500 mt-1">Gerez les conversations WhatsApp (DM ou groupes) autorisees a interagir avec <strong>{{ $meta['label'] }}</strong>.</p>
                </div>

                <form method="POST" action="{{ route('agents.private-agent-access', $agent) }}">
                    @csrf
                    <div class="p-5 space-y-4">

                        {{-- Current authorized peers --}}
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Peers autorises</label>
                            @if(count($allowedPeers) > 0)
                            <div class="flex flex-wrap gap-2 mb-3" id="peer-tags">
                                @foreach($allowedPeers as $peer)
                                <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-mono border
                                    {{ str_contains($peer, '@g.us') ? 'bg-green-50 border-green-200 text-green-700' : 'bg-blue-50 border-blue-200 text-blue-700' }}">
                                    {{ str_contains($peer, '@g.us') ? '👥' : '📱' }}
                                    {{ $peer }}
                                    <button type="button" onclick="removePeer('{{ $subAgent }}', '{{ $peer }}')"
                                        class="ml-1 text-gray-400 hover:text-red-500 transition-colors">&times;</button>
                                </span>
                                @endforeach
                            </div>
                            @else
                            <p class="text-sm text-gray-400 mb-3">Aucune conversation autorisee. Ce sub-agent est bloque pour tout le monde.</p>
                            @endif
                        </div>

                        {{-- Textarea for editing --}}
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Modifier la liste (peer IDs separes par des virgules)</label>
                            <textarea name="private_sub_agents[{{ $subAgent }}]" rows="3" id="peers-textarea"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none"
                                placeholder="123456789@s.whatsapp.net, 120363044@g.us, ...">{{ implode(', ', $allowedPeers) }}</textarea>
                            <p class="text-[10px] text-gray-400 mt-1">
                                Format : <code>numero@s.whatsapp.net</code> pour un DM, <code>numero@g.us</code> ou <code>numero@lid</code> pour un groupe.
                            </p>
                        </div>

                        {{-- Quick-add from existing sessions --}}
                        @php
                            $whatsappSessions = $allSessions->filter(fn($s) => $s->channel === 'whatsapp');
                        @endphp
                        @if($whatsappSessions->isNotEmpty())
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Ajouter depuis les sessions connues</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @foreach($whatsappSessions as $sess)
                                @php
                                    $isAuthorized = in_array($sess->peer_id, $allowedPeers);
                                    $isGroup = str_contains($sess->peer_id ?? '', '@g.us') || str_contains($sess->peer_id ?? '', '@lid');
                                @endphp
                                <div class="flex items-center justify-between p-3 rounded-lg border {{ $isAuthorized ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200' }}">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="text-lg">{{ $isGroup ? '👥' : '📱' }}</span>
                                        <div class="min-w-0">
                                            <p class="text-xs font-mono text-gray-800 truncate">{{ $sess->peer_id }}</p>
                                            <p class="text-[10px] text-gray-400">
                                                {{ $sess->display_name ?? '—' }}
                                                · {{ $sess->message_count }} msgs
                                                @if($sess->last_message_at) · {{ $sess->last_message_at->diffForHumans() }}@endif
                                            </p>
                                        </div>
                                    </div>
                                    @if($isAuthorized)
                                    <span class="px-2 py-1 rounded-full text-[10px] font-medium bg-green-100 text-green-700 whitespace-nowrap">Autorise</span>
                                    @else
                                    <button type="button"
                                        onclick="addPeerFromSession('{{ $subAgent }}', '{{ $sess->peer_id }}')"
                                        class="px-2 py-1 rounded-lg text-[10px] font-medium bg-amber-100 text-amber-700 hover:bg-amber-200 transition-colors whitespace-nowrap">
                                        + Autoriser
                                    </button>
                                    @endif
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        {{-- Preserve other private agents' config --}}
                        @php
                            $otherPrivateAccess = collect($agent->private_sub_agents ?? [])->except($subAgent);
                        @endphp
                        @foreach($otherPrivateAccess as $otherKey => $otherPeers)
                        <input type="hidden" name="private_sub_agents[{{ $otherKey }}]" value="{{ implode(', ', $otherPeers) }}">
                        @endforeach
                    </div>

                    <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
                        <p class="text-[10px] text-gray-400">Les modifications prennent effet immediatement pour les prochains messages.</p>
                        <button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg text-xs font-medium hover:bg-amber-700 transition-colors">
                            Sauvegarder les acces
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @endif

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
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Modele</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Complexite</th>
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
                                <td colspan="5" class="px-5 py-8 text-sm text-gray-400 text-center">Aucune decision de routage enregistree.</td>
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

@if($isPrivate)
<script>
function removePeer(agentKey, peerId) {
    const textarea = document.getElementById('peers-textarea');
    const current = textarea.value.split(',').map(s => s.trim()).filter(Boolean);
    textarea.value = current.filter(p => p !== peerId).join(', ');
    // Remove the tag visually
    const tags = document.getElementById('peer-tags');
    if (tags) {
        const spans = tags.querySelectorAll('span');
        spans.forEach(span => {
            if (span.textContent.includes(peerId)) span.remove();
        });
    }
}

function addPeerFromSession(agentKey, peerId) {
    const textarea = document.getElementById('peers-textarea');
    const current = textarea.value.split(',').map(s => s.trim()).filter(Boolean);
    if (!current.includes(peerId)) {
        current.push(peerId);
        textarea.value = current.join(', ');
    }
    // Submit the form automatically
    textarea.closest('form').submit();
}
</script>
@endif
@endsection
