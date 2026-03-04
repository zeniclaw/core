@extends('layouts.app')
@section('title', 'Contacts')

@section('content')
<div class="max-w-5xl">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Contacts</h2>
            <p class="text-sm text-gray-500">Tous les contacts qui ont communique avec ZeniClaw via WhatsApp.</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex gap-2 mb-4">
        <a href="{{ route('contacts.index') }}"
           class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
            Tous <span class="ml-1 text-xs opacity-75">{{ $allCount }}</span>
        </a>
        <a href="{{ route('contacts.index', ['type' => 'dm']) }}"
           class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $filter === 'dm' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
            DM <span class="ml-1 text-xs opacity-75">{{ $dmCount }}</span>
        </a>
        <a href="{{ route('contacts.index', ['type' => 'group']) }}"
           class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $filter === 'group' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
            Groupes <span class="ml-1 text-xs opacity-75">{{ $groupCount }}</span>
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($contacts->isEmpty())
            <div class="px-6 py-12 text-center text-gray-400">
                <p class="text-4xl mb-3">👥</p>
                <p class="text-sm">Aucun contact pour le moment.</p>
                <p class="text-xs text-gray-400 mt-1">Les contacts apparaissent quand quelqu'un envoie un message via WhatsApp.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Nom</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">WhatsApp ID</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Type</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Messages</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Derniere activite</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Projets</th>
                        <th class="text-center px-4 py-3 font-medium text-gray-600">Whitelist</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($contacts as $contact)
                    <tr class="hover:bg-gray-50/50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $contact->name }}</td>
                        <td class="px-4 py-3 text-xs font-mono text-gray-500">{{ $contact->peer_id }}</td>
                        <td class="px-4 py-3">
                            @if($contact->type === 'dm')
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">DM</span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700">Groupe</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $contact->message_count }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs">
                            {{ $contact->last_message_at ? $contact->last_message_at->diffForHumans() : '-' }}
                        </td>
                        <td class="px-4 py-3">
                            @if($contact->project_count > 0)
                                <a href="{{ route('projects.index') }}?q={{ urlencode($contact->peer_id) }}"
                                   class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">
                                    {{ $contact->project_count }} projet{{ $contact->project_count > 1 ? 's' : '' }}
                                </a>
                            @else
                                <span class="text-gray-400 text-xs">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <form method="POST" action="{{ route('contacts.toggle-whitelist', $contact->id) }}">
                                @csrf
                                <button type="submit" class="px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                    {{ $contact->whitelisted ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}">
                                    {{ $contact->whitelisted ? 'Autorise' : 'Bloque' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
