@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div class="space-y-6">

    {{-- Stats cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Total Agents</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ $agentCount }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-100 flex items-center justify-center text-2xl">🤖</div>
            </div>
        </div>
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Active Agents</p>
                    <p class="text-3xl font-bold text-green-600 mt-1">{{ $activeAgents }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center text-2xl">✅</div>
            </div>
        </div>
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Reminders</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ $reminderCount }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-yellow-100 flex items-center justify-center text-2xl">⏰</div>
            </div>
        </div>
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Pending</p>
                    <p class="text-3xl font-bold text-orange-500 mt-1">{{ $pendingReminders }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-orange-100 flex items-center justify-center text-2xl">⏳</div>
            </div>
        </div>
    </div>

    {{-- Recent Agents --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800">Recent Agents</h2>
            <a href="{{ route('agents.create') }}" class="text-sm bg-indigo-600 text-white px-3 py-1.5 rounded-lg hover:bg-indigo-700 transition-colors">+ New Agent</a>
        </div>
        @if($agents->isEmpty())
        <div class="px-6 py-10 text-center text-gray-400">
            <p class="text-4xl mb-2">🤖</p>
            <p>No agents yet. <a href="{{ route('agents.create') }}" class="text-indigo-600 hover:underline">Create your first agent</a>.</p>
        </div>
        @else
        <div class="divide-y divide-gray-50">
            @foreach($agents as $agent)
            <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50 transition-colors">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-xl bg-indigo-100 flex items-center justify-center text-lg">🤖</div>
                    <div>
                        <p class="font-medium text-gray-900">{{ $agent->name }}</p>
                        <p class="text-xs text-gray-500">{{ $agent->model }} · {{ $agent->created_at->diffForHumans() }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="px-2 py-1 rounded-full text-xs font-medium
                        {{ $agent->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $agent->status }}
                    </span>
                    <a href="{{ route('agents.show', $agent) }}" class="text-indigo-600 text-sm hover:underline">View →</a>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

</div>
@endsection
