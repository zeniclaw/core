@extends('layouts.app')
@section('title', 'Agents')

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500">{{ $agents->total() }} agent(s)</p>
        <a href="{{ route('agents.create') }}" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 transition-colors">+ New Agent</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($agents->isEmpty())
            <div class="py-16 text-center text-gray-400">
                <p class="text-5xl mb-3">🤖</p>
                <p class="text-lg font-medium">No agents yet</p>
                <a href="{{ route('agents.create') }}" class="mt-3 inline-block text-indigo-600 hover:underline">Create your first agent →</a>
            </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Model</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Created</th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($agents as $agent)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-4 font-medium text-gray-900">
                        <a href="{{ route('agents.show', $agent) }}" class="hover:text-indigo-600">{{ $agent->name }}</a>
                        @if($agent->description)
                            <p class="text-xs text-gray-400 truncate max-w-xs">{{ $agent->description }}</p>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-gray-600">
                        <span class="px-2 py-1 bg-gray-100 rounded text-xs font-mono">{{ $agent->model }}</span>
                    </td>
                    <td class="px-6 py-4">
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium
                            {{ $agent->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $agent->status }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-gray-500 text-xs">{{ $agent->created_at->format('d M Y') }}</td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('agents.show', $agent) }}" class="text-gray-500 hover:text-indigo-600 text-xs px-2 py-1 rounded hover:bg-indigo-50">View</a>
                            <a href="{{ route('agents.edit', $agent) }}" class="text-gray-500 hover:text-indigo-600 text-xs px-2 py-1 rounded hover:bg-indigo-50">Edit</a>
                            <form method="POST" action="{{ route('agents.destroy', $agent) }}"
                                  x-data
                                  @submit.prevent="if(confirm('Delete agent {{ addslashes($agent->name) }}?')) $el.submit()">
                                @csrf @method('DELETE')
                                <button class="text-red-500 hover:text-red-700 text-xs px-2 py-1 rounded hover:bg-red-50">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-6 py-4 border-t border-gray-100">{{ $agents->links() }}</div>
        @endif
    </div>
</div>
@endsection
