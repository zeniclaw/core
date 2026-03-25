@extends('layouts.app')
@section('title', 'Agents prives - ' . $agent->name)

@section('content')
<div class="space-y-6">

    {{-- Header --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center text-2xl">🧪</div>
                <div>
                    <h2 class="text-xl font-bold text-gray-900">Agents prives</h2>
                    <p class="text-gray-500 text-sm">Creez et formez vos propres agents IA pour {{ $agent->name }}</p>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('agents.show', $agent) }}" class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">Retour</a>
                <a href="{{ route('custom-agents.create', $agent) }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">+ Nouvel agent</a>
            </div>
        </div>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl text-sm">{{ session('success') }}</div>
    @endif

    {{-- Agent List --}}
    @if($customAgents->isEmpty())
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
        <div class="text-5xl mb-4">🧪</div>
        <h3 class="text-lg font-semibold text-gray-800 mb-2">Aucun agent prive</h3>
        <p class="text-gray-500 mb-6">Creez votre premier agent IA, formez-le avec vos documents, et il repondra selon vos connaissances.</p>
        <a href="{{ route('custom-agents.create', $agent) }}" class="px-6 py-3 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 transition-colors">Creer un agent</a>
    </div>
    @else
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @foreach($customAgents as $ca)
        <a href="{{ route('custom-agents.show', [$agent, $ca]) }}" class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow group">
            <div class="flex items-start gap-3">
                <div class="w-11 h-11 rounded-xl bg-purple-50 flex items-center justify-center text-2xl flex-shrink-0">{{ $ca->avatar }}</div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <h3 class="font-semibold text-gray-900 truncate group-hover:text-indigo-600 transition-colors">{{ $ca->name }}</h3>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $ca->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $ca->is_active ? 'Actif' : 'Inactif' }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 mt-1 line-clamp-2">{{ $ca->description ?: 'Pas de description' }}</p>
                    <div class="flex items-center gap-3 mt-3 text-xs text-gray-400">
                        <span>{{ $ca->documents_count }} doc{{ $ca->documents_count > 1 ? 's' : '' }}</span>
                        <span>{{ $ca->chunks_count }} chunks</span>
                        <span>{{ $ca->created_at->diffForHumans() }}</span>
                    </div>
                </div>
            </div>
        </a>
        @endforeach
    </div>
    @endif

</div>
@endsection
