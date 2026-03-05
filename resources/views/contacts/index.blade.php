@extends('layouts.app')
@section('title', 'Contacts')

@section('content')
<div class="max-w-5xl" x-data="{ showAddModal: false }">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Contacts</h2>
            <p class="text-sm text-gray-500">All contacts that have communicated with ZeniClaw via WhatsApp.</p>
        </div>
        <button @click="showAddModal = true"
                class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 transition-colors flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add Contact
        </button>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-green-100 text-green-700 text-sm border border-green-200">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-100 text-red-700 text-sm border border-red-200">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-100 text-red-700 text-sm border border-red-200">
            @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex gap-2 mb-4">
        <a href="{{ route('contacts.index') }}"
           class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
            All <span class="ml-1 text-xs opacity-75">{{ $allCount }}</span>
        </a>
        <a href="{{ route('contacts.index', ['type' => 'dm']) }}"
           class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $filter === 'dm' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
            DM <span class="ml-1 text-xs opacity-75">{{ $dmCount }}</span>
        </a>
        <a href="{{ route('contacts.index', ['type' => 'group']) }}"
           class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors {{ $filter === 'group' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
            Groups <span class="ml-1 text-xs opacity-75">{{ $groupCount }}</span>
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($contacts->isEmpty())
            <div class="px-6 py-12 text-center text-gray-400">
                <p class="text-4xl mb-3">👥</p>
                <p class="text-sm">No contacts yet.</p>
                <p class="text-xs text-gray-400 mt-1">Contacts appear when someone sends a message via WhatsApp, or you can add them manually.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Name</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">WhatsApp ID</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Type</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Messages</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Last Activity</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Projects</th>
                        <th class="text-center px-4 py-3 font-medium text-gray-600">Whitelist</th>
                        <th class="text-center px-4 py-3 font-medium text-gray-600">Actions</th>
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
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700">Group</span>
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
                                    {{ $contact->project_count }} project{{ $contact->project_count > 1 ? 's' : '' }}
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
                                    {{ $contact->whitelisted ? 'Allowed' : 'Blocked' }}
                                </button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <form method="POST" action="{{ route('contacts.destroy', $contact->id) }}"
                                  onsubmit="return confirm('Delete this contact?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-2 py-1 rounded text-xs text-red-500 hover:bg-red-50 hover:text-red-700 transition-colors" title="Delete">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Add Contact Modal --}}
    <div x-show="showAddModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center"
         @keydown.escape.window="showAddModal = false">
        <div class="absolute inset-0 bg-black/50" @click="showAddModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6" @click.stop>
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Add WhatsApp Contact</h3>

            <form method="POST" action="{{ route('contacts.store') }}">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                        <input type="text" name="phone" required placeholder="+33612345678"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                               pattern="\+?[0-9]{7,15}">
                        <p class="text-xs text-gray-400 mt-1">International format with country code (e.g. +33612345678)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-gray-400">(optional)</span></label>
                        <input type="text" name="name" placeholder="John Doe"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" @click="showAddModal = false"
                            class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 transition-colors">
                        Add Contact
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
