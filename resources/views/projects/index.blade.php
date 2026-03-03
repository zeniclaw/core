@extends('layouts.app')
@section('title', 'Projets')

@section('content')
<div class="max-w-5xl">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Projets</h2>
            <p class="text-sm text-gray-500">Demandes de modification GitLab recues via WhatsApp.</p>
        </div>
        <a href="{{ route('projects.create') }}"
           class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
            + Nouveau projet
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($projects->isEmpty())
            <div class="px-6 py-12 text-center text-gray-400">
                <p class="text-4xl mb-3">📁</p>
                <p class="text-sm">Aucun projet pour le moment.</p>
                <p class="text-xs text-gray-400 mt-1">Les projets apparaissent quand quelqu'un envoie une URL GitLab via WhatsApp.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Nom</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">URL GitLab</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Demandeur</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Status</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($projects as $project)
                    <tr class="hover:bg-gray-50/50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $project->name }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ $project->gitlab_url }}" target="_blank" rel="noopener"
                               class="text-indigo-600 hover:text-indigo-800 text-xs font-mono truncate block max-w-[200px]">
                                {{ parse_url($project->gitlab_url, PHP_URL_PATH) }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $project->requester_name }}</td>
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
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $statusColors[$project->status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ $project->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $project->created_at->format('d/m H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('projects.show', $project) }}"
                               class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">Voir</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-100">
                {{ $projects->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
