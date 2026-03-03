@extends('layouts.app')
@section('title', 'Conversations')

@section('content')
<div class="space-y-4">
    {{-- Filter --}}
    <div class="flex items-center gap-2">
        @foreach(['' => 'Tous', 'dm' => 'DM', 'group' => 'Groupes'] as $val => $label)
        <a href="{{ route('conversations.index', $val ? ['filter' => $val] : []) }}"
           class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors
               {{ $filter === $val ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
            {{ $label }}
        </a>
        @endforeach
        <span class="ml-auto text-sm text-gray-500">{{ $conversations->total() }} conversations</span>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($conversations->isEmpty())
            <div class="py-16 text-center text-gray-400">
                <p class="text-5xl mb-3">💬</p>
                <p>Aucune conversation pour le moment.</p>
            </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Contact</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Agent</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider text-center">Messages</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Derniere activite</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($conversations as $conv)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-3">
                        <span class="px-2 py-0.5 rounded text-xs font-medium
                            {{ $conv->isGroup() ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                            {{ $conv->isGroup() ? 'Groupe' : 'DM' }}
                        </span>
                    </td>
                    <td class="px-6 py-3">
                        <a href="{{ route('conversations.show', $conv) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">
                            {{ $nameMap[$conv->peer_id] ?? $conv->displayName() }}
                        </a>
                    </td>
                    <td class="px-6 py-3 text-xs text-gray-600">{{ $conv->agent->name ?? '-' }}</td>
                    <td class="px-6 py-3 text-center">
                        <span class="inline-flex items-center justify-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                            {{ $conv->message_count }}
                        </span>
                    </td>
                    <td class="px-6 py-3 text-xs text-gray-400 whitespace-nowrap">
                        {{ $conv->last_message_at ? $conv->last_message_at->diffForHumans() : '-' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-6 py-4 border-t border-gray-100">{{ $conversations->links() }}</div>
        @endif
    </div>
</div>
@endsection
