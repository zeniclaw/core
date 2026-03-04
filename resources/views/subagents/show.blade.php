@extends('layouts.app')
@section('title', 'SubAgent #' . $subAgent->id)

@section('content')
<div class="max-w-4xl space-y-6">

    {{-- Back link --}}
    <a href="{{ route('subagents.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
        &larr; Retour aux SubAgents
    </a>

    {{-- Header --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">SubAgent #{{ $subAgent->id }}</h2>
                <p class="text-sm text-gray-500 mt-1">
                    Projet:
                    <a href="{{ route('projects.show', $subAgent->project_id) }}"
                       class="text-indigo-600 hover:text-indigo-800">{{ $subAgent->project->name }}</a>
                </p>
            </div>
            @php
                $saColors = [
                    'queued' => 'bg-gray-100 text-gray-600',
                    'running' => 'bg-purple-100 text-purple-700',
                    'completed' => 'bg-green-100 text-green-700',
                    'failed' => 'bg-red-100 text-red-700',
                ];
            @endphp
            <div class="flex items-center gap-2">
                @php
                    $saColors['killed'] = 'bg-orange-100 text-orange-700';
                @endphp
                <span class="px-3 py-1 rounded-full text-sm font-medium {{ $saColors[$subAgent->status] ?? 'bg-gray-100 text-gray-600' }}
                       {{ $subAgent->status === 'running' ? 'animate-pulse' : '' }}">
                    {{ $subAgent->status }}
                </span>
                @if(in_array($subAgent->status, ['running', 'queued']))
                <form method="POST" action="{{ route('subagents.kill', $subAgent) }}" class="inline"
                      x-data @submit.prevent="if(confirm('Arreter ce SubAgent et tous ses processus ?')) $el.submit()">
                    @csrf
                    <button class="px-3 py-1 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition-colors">
                        Arreter
                    </button>
                </form>
                @endif
                @if(in_array($subAgent->status, ['failed', 'killed']))
                <form method="POST" action="{{ route('subagents.retry', $subAgent) }}" class="inline"
                      x-data @submit.prevent="if(confirm('Relancer ce SubAgent avec la meme tache ?')) $el.submit()">
                    @csrf
                    <button class="px-3 py-1 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                        Relancer
                    </button>
                </form>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div>
                <span class="text-gray-500">Appels API:</span>
                <span class="ml-1 font-medium" x-data x-text="typeof apiCalls !== 'undefined' ? apiCalls : '{{ $subAgent->api_calls_count }}'">{{ $subAgent->api_calls_count }}</span>
            </div>
            <div>
                <span class="text-gray-500">Timeout:</span>
                <span class="ml-1 font-medium">{{ $subAgent->timeout_minutes ?? 10 }} min</span>
            </div>
            @if($subAgent->branch_name)
            <div>
                <span class="text-gray-500">Branche:</span>
                <span class="ml-1 font-mono text-xs font-medium">{{ $subAgent->branch_name }}</span>
            </div>
            @endif
            @if($subAgent->commit_hash)
            <div>
                <span class="text-gray-500">Commit:</span>
                <span class="ml-1 font-mono text-xs font-medium">{{ $subAgent->commit_hash }}</span>
            </div>
            @endif
            @if($subAgent->started_at)
            <div>
                <span class="text-gray-500">Debut:</span>
                <span class="ml-1">{{ $subAgent->started_at->format('d/m/Y H:i:s') }}</span>
            </div>
            @endif
            @if($subAgent->completed_at)
            <div>
                <span class="text-gray-500">Fin:</span>
                <span class="ml-1">{{ $subAgent->completed_at->format('d/m/Y H:i:s') }}</span>
            </div>
            @endif
        </div>

        {{-- Task description --}}
        <div class="bg-gray-50 rounded-lg p-4 mt-4">
            <p class="text-xs text-gray-500 mb-1 font-medium">Tache</p>
            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $subAgent->task_description }}</p>
        </div>

        {{-- Error message --}}
        @if($subAgent->error_message)
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mt-4">
            <p class="text-xs text-red-600 mb-1 font-medium">Erreur</p>
            <p class="text-sm text-red-800 font-mono whitespace-pre-wrap">{{ $subAgent->error_message }}</p>
        </div>
        @endif
    </div>

    {{-- Relaunch with custom prompt --}}
    @if(in_array($subAgent->status, ['completed', 'failed', 'killed']) && in_array(auth()->user()->role, ['superadmin', 'admin']))
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-900 mb-3">Relancer avec un nouveau prompt</h3>
        <form method="POST" action="{{ route('subagents.relaunch', $subAgent) }}">
            @csrf
            <textarea name="prompt" rows="3" placeholder="Ex: La page /manager/notifications fait une 404, verifie la route et corrige..."
                      class="w-full border border-gray-200 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 resize-y">{{ old('prompt') }}</textarea>
            <div class="flex items-center justify-end mt-3">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                    Lancer
                </button>
            </div>
        </form>
    </div>
    @endif

    {{-- Terminal output --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6"
         x-data="{
            log: @js($subAgent->output_log ?? ''),
            status: '{{ $subAgent->status }}',
            apiCalls: {{ $subAgent->api_calls_count }},
            polling: null,

            init() {
                if (this.status === 'running' || this.status === 'queued') {
                    this.startPolling();
                }
                this.$nextTick(() => this.scrollToBottom());
            },

            startPolling() {
                this.polling = setInterval(async () => {
                    try {
                        const r = await fetch('{{ route('subagents.output', $subAgent) }}');
                        const d = await r.json();
                        this.log = d.output_log || '';
                        this.status = d.status;
                        this.apiCalls = d.api_calls_count;
                        this.$nextTick(() => this.scrollToBottom());

                        if (d.status === 'completed' || d.status === 'failed') {
                            clearInterval(this.polling);
                            // Reload page to show final state
                            setTimeout(() => location.reload(), 1000);
                        }
                    } catch(e) {}
                }, 3000);
            },

            scrollToBottom() {
                const el = this.$refs.terminal;
                if (el) el.scrollTop = el.scrollHeight;
            },

            destroy() {
                if (this.polling) clearInterval(this.polling);
            }
         }">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold text-gray-900">Output</h3>
            <div x-show="status === 'running'" class="flex items-center gap-2 text-sm text-purple-600">
                <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                En cours... (polling 3s)
            </div>
        </div>
        <div x-ref="terminal"
             class="bg-gray-900 rounded-lg p-3 sm:p-4 h-[60vh] sm:h-96 overflow-y-auto font-mono text-[11px] sm:text-xs leading-relaxed">
            <pre class="text-green-400 whitespace-pre-wrap" x-text="log || 'En attente de logs...'"></pre>
        </div>
    </div>

</div>
@endsection
