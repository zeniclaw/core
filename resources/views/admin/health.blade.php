@extends('layouts.app')
@section('title', 'Santé du système')

@section('content')
<div class="max-w-3xl space-y-6">

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between mb-5">
            <h2 class="font-semibold text-gray-900">🏥 État des services</h2>
            <span class="text-xs text-gray-400">ZeniClaw v{{ $version }}</span>
        </div>

        <div class="space-y-3">
            @foreach($checks as $key => $check)
            <div class="flex items-center justify-between px-4 py-3 rounded-xl
                {{ $check['status'] === 'ok' ? 'bg-green-50 border border-green-100' : ($check['status'] === 'warn' ? 'bg-yellow-50 border border-yellow-100' : 'bg-red-50 border border-red-100') }}">
                <div class="flex items-center gap-3">
                    <span class="text-lg">
                        @if($check['status'] === 'ok') ✅
                        @elseif($check['status'] === 'warn') ⚠️
                        @else ❌
                        @endif
                    </span>
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ $check['label'] }}</p>
                        @if(isset($check['error']))
                            <p class="text-xs text-gray-500">{{ $check['error'] }}</p>
                        @endif
                    </div>
                </div>
                @if($check['ms'])
                <span class="text-xs font-mono text-gray-500 bg-white px-2 py-1 rounded">{{ $check['ms'] }}ms</span>
                @endif
            </div>
            @endforeach
        </div>
    </div>

    {{-- Recent errors --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">🚨 Erreurs récentes</h2>
        @if($recentErrors->isEmpty())
            <p class="text-sm text-gray-400 text-center py-6">✅ Aucune erreur récente.</p>
        @else
        <div class="space-y-2">
            @foreach($recentErrors as $log)
            <div class="flex items-start gap-3 px-4 py-3 bg-red-50 border border-red-100 rounded-xl">
                <span class="mt-0.5 px-1.5 py-0.5 bg-red-100 text-red-700 rounded text-xs font-medium">ERROR</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-red-900">{{ $log->message }}</p>
                    <p class="text-xs text-red-500 mt-0.5">{{ $log->agent->name }} · {{ $log->created_at->diffForHumans() }}</p>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Quick actions --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">⚡ Actions rapides</h2>
        <div class="flex gap-3 flex-wrap">
            <a href="{{ route('health') }}" target="_blank"
               class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                🔗 GET /health (JSON)
            </a>
            <a href="{{ route('admin.update') }}"
               class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                🔄 Mises à jour
            </a>
            <a href="{{ route('logs.index', ['level' => 'error']) }}"
               class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                📋 Voir erreurs
            </a>
        </div>
    </div>

</div>
@endsection
