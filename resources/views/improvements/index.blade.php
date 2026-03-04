@extends('layouts.app')
@section('title', 'Ameliorations')

@section('content')
<div class="max-w-5xl">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Auto-ameliorations</h2>
            <p class="text-sm text-gray-500">Suggestions d'amelioration generees automatiquement par l'analyse des echanges.</p>
        </div>
        <div class="flex items-center gap-2">
            @php
                $currentStatus = request('status');
                $filters = ['' => 'Tous', 'pending' => 'En attente', 'approved' => 'Approuves', 'rejected' => 'Rejetes', 'in_progress' => 'En cours', 'completed' => 'Termines', 'failed' => 'Echoues'];
            @endphp
            @foreach($filters as $value => $label)
                <a href="{{ route('improvements.index', $value ? ['status' => $value] : []) }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors
                          {{ $currentStatus === ($value ?: null) ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($improvements->isEmpty())
            <div class="px-6 py-12 text-center text-gray-400">
                <p class="text-4xl mb-3">🧠</p>
                <p class="text-sm">Aucune amelioration pour le moment.</p>
                <p class="text-xs text-gray-400 mt-1">Les suggestions apparaissent automatiquement apres analyse des echanges.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Titre</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Agent</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Route</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Status</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($improvements as $improvement)
                    <tr class="hover:bg-gray-50/50">
                        <td class="px-4 py-3 font-medium text-gray-900 max-w-[250px] truncate">{{ $improvement->improvement_title }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $improvement->agent->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                {{ $improvement->routed_agent }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
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
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $statusColors[$improvement->status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ $improvement->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $improvement->created_at->format('d/m H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('improvements.show', $improvement) }}"
                               class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">Voir</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-100">
                {{ $improvements->withQueryString()->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
