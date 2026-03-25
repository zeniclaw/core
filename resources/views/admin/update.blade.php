@extends('layouts.app')
@section('title', 'Update')

@section('content')
<div class="max-w-2xl space-y-6">

    {{-- Version card --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">Version</h2>
        <div class="grid grid-cols-2 gap-4">
            <div class="bg-gray-50 rounded-xl p-4 text-center">
                <p class="text-xs text-gray-500 mb-1">Current version</p>
                <p class="text-2xl font-bold text-gray-900">v{{ $currentVersion }}</p>
            </div>
            <div class="bg-indigo-50 rounded-xl p-4 text-center">
                <p class="text-xs text-gray-500 mb-1">Latest version</p>
                <p class="text-2xl font-bold text-indigo-700">{{ $latestVersion ?: '—' }}</p>
            </div>
        </div>
        @if($upToDate)
        <div class="mt-4 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded-lg text-sm">
            Up to date
        </div>
        @else
        <div class="mt-4 px-4 py-3 bg-orange-50 border border-orange-200 text-orange-800 rounded-lg text-sm">
            Update available: {{ $latestVersion }}
        </div>
        @endif
    </div>

    {{-- Changelog --}}
    @if(count($commits))
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">Recent commits</h2>
        <div class="space-y-3">
            @foreach($commits as $commit)
            <div class="flex items-start gap-3">
                <code class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded font-mono flex-shrink-0">
                    {{ substr($commit['id'], 0, 7) }}
                </code>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-gray-900">{{ $commit['title'] }}</p>
                    <p class="text-xs text-gray-400">{{ $commit['author_name'] }} &middot; {{ \Carbon\Carbon::parse($commit['created_at'])->diffForHumans() }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Update button + live rebuild log --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6"
         x-data="{
            phase: 'idle',
            updateLog: '',
            rebuildLog: '',
            rebuildPolling: null,
            error: false,

            async runUpdate() {
                if (!confirm('Start update? The application will briefly restart.')) return;
                this.phase = 'updating';
                this.updateLog = 'Starting update...\n';
                this.rebuildLog = '';
                this.error = false;

                try {
                    const r = await fetch('{{ route('admin.update.run') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json'
                        }
                    });
                    const d = await r.json();
                    if (d.success) {
                        this.updateLog += d.output || '';
                        this.updateLog += '\n--- Git pull & migrations done ---\n';
                        this.phase = 'rebuilding';
                        this.startRebuildPolling();
                    } else {
                        this.updateLog += 'Error: ' + d.message + '\n' + (d.output || '');
                        this.error = true;
                        this.phase = 'done';
                    }
                } catch(e) {
                    // Network error = container is restarting (expected during update)
                    this.updateLog += 'Container is restarting for rebuild...\n';
                    this.phase = 'rebuilding';
                    this.startRebuildPolling();
                }
            },

            startRebuildPolling() {
                this.rebuildLog = 'Waiting for container rebuild...\n';
                let attempts = 0;
                let wasDown = false;
                let noLogCount = 0;
                this.rebuildPolling = setInterval(async () => {
                    attempts++;
                    try {
                        const r = await fetch('{{ route('admin.update.rebuild-status') }}');
                        if (r.ok) {
                            const d = await r.json();
                            if (wasDown) {
                                // Container came back after being down
                                clearInterval(this.rebuildPolling);
                                this.rebuildPolling = null;
                                this.rebuildLog += (d.log || '') + '\nContainer restarted successfully.\n';
                                this.phase = 'done';
                                this.error = false;
                                return;
                            }
                            if (d.log) {
                                this.rebuildLog = d.log;
                                noLogCount = 0;
                            } else {
                                noLogCount++;
                            }
                            if (d.finished) {
                                clearInterval(this.rebuildPolling);
                                this.rebuildPolling = null;
                                this.phase = 'done';
                                this.error = !d.success;
                            } else if (noLogCount >= 10) {
                                // No rebuild log after 30s+ and container still up = rebuild happened via restart
                                clearInterval(this.rebuildPolling);
                                this.rebuildPolling = null;
                                this.rebuildLog += '\nRebuild complete (container restarted).\n';
                                this.phase = 'done';
                                this.error = false;
                            }
                        } else {
                            wasDown = true;
                            this.rebuildLog = 'Container is rebuilding and restarting...\n';
                        }
                    } catch(e) {
                        wasDown = true;
                        this.rebuildLog = 'Container is rebuilding and restarting...\n';
                    }
                    // Safety: stop after 5 minutes
                    if (attempts > 100) {
                        clearInterval(this.rebuildPolling);
                        this.rebuildPolling = null;
                        this.rebuildLog += '\n[Timeout — check container logs manually]\n';
                        this.phase = 'done';
                    }
                }, 3000);
            },

            get combinedLog() {
                let log = this.updateLog;
                if (this.rebuildLog) {
                    log += '\n=== Container Rebuild ===\n' + this.rebuildLog;
                }
                return log;
            }
         }"
         x-init="$watch('combinedLog', () => { $nextTick(() => { const el = $refs.logArea; if(el) el.scrollTop = el.scrollHeight; }) })">

        <h2 class="font-semibold text-gray-900 mb-4">Update</h2>

        <button @click="runUpdate()" :disabled="phase === 'updating' || phase === 'rebuilding'"
                class="bg-indigo-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors disabled:opacity-50 flex items-center gap-2">
            <span x-show="phase === 'idle' || phase === 'done'">Update to {{ $latestVersion ?: 'latest' }}</span>
            <span x-show="phase === 'updating'" x-cloak class="flex items-center gap-2">
                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                Pulling code & running migrations...
            </span>
            <span x-show="phase === 'rebuilding'" x-cloak class="flex items-center gap-2">
                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                Container rebuild in progress...
            </span>
        </button>

        {{-- Progress steps --}}
        <div x-show="phase !== 'idle'" x-cloak class="mt-4 flex items-center gap-3 text-xs">
            <div class="flex items-center gap-1.5" :class="phase === 'updating' ? 'text-indigo-600 font-medium' : (phase === 'rebuilding' || phase === 'done') ? 'text-green-600' : 'text-gray-400'">
                <template x-if="phase === 'updating'"><svg class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                <template x-if="phase !== 'updating'"><svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg></template>
                Git pull & migrations
            </div>
            <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <div class="flex items-center gap-1.5" :class="phase === 'rebuilding' ? 'text-indigo-600 font-medium' : phase === 'done' ? (error ? 'text-red-600' : 'text-green-600') : 'text-gray-400'">
                <template x-if="phase === 'rebuilding'"><svg class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                <template x-if="phase === 'done' && !error"><svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg></template>
                <template x-if="phase === 'done' && error"><svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg></template>
                Container rebuild
            </div>
            <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <div class="flex items-center gap-1.5" :class="phase === 'done' && !error ? 'text-green-600 font-medium' : 'text-gray-400'">
                Restart
            </div>
        </div>

        {{-- Live log --}}
        <div x-show="combinedLog" x-cloak class="mt-4">
            <pre x-ref="logArea" x-text="combinedLog"
                 class="w-full px-4 py-3 bg-gray-900 text-green-400 rounded-xl font-mono text-xs border-0 outline-none overflow-auto max-h-80 whitespace-pre-wrap"></pre>
        </div>

        {{-- Status banners --}}
        <div x-show="phase === 'done' && !error" x-cloak class="mt-3 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded-lg text-sm flex items-center justify-between">
            <span>Update completed! Container will restart automatically.</span>
            <button @click="window.location.reload()" class="text-xs bg-green-600 text-white px-3 py-1 rounded hover:bg-green-700">Reload page</button>
        </div>
        <div x-show="phase === 'done' && error" x-cloak class="mt-3 px-4 py-3 bg-red-50 border border-red-200 text-red-800 rounded-lg text-sm space-y-2">
            <p class="font-semibold">Update failed. Check the log above for details.</p>
            <template x-if="updateLog.includes('No container runtime found')">
                <div class="bg-red-100 rounded p-3 text-xs font-mono space-y-1">
                    <p class="font-semibold text-red-900 mb-1">Docker CLI missing in container. Run manually on the server:</p>
                    <p class="select-all">cd /path/to/zeniclaw</p>
                    <p class="select-all">git pull origin main</p>
                    <p class="select-all">docker compose build app --no-cache</p>
                    <p class="select-all">docker compose up -d app</p>
                    <p class="mt-1 text-red-700 font-sans">After this one-time fix, UI updates will work normally.</p>
                </div>
            </template>
        </div>
    </div>

</div>
@endsection
