@extends('layouts.app')
@section('title', 'Debug')

@section('content')
<div class="max-w-4xl space-y-6" x-data="debugPage()">

    <div class="flex items-center justify-between mb-2">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Debug Panel</h2>
            <p class="text-sm text-gray-500">System info, scheduled jobs, and diagnostics.</p>
        </div>
        <button @click="refreshSystemInfo()" class="px-3 py-1.5 rounded-lg text-xs font-medium text-gray-600 bg-white border border-gray-200 hover:bg-gray-50 transition-colors flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" :class="refreshing && 'animate-spin'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Refresh
        </button>
    </div>

    {{-- System Info --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            System Information
        </h3>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            {{-- Host --}}
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">Hostname</p>
                <p class="text-sm font-mono font-medium text-gray-900" x-text="sys.hostname">{{ $system['hostname'] ?? '-' }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">OS / Kernel</p>
                <p class="text-sm font-mono font-medium text-gray-900" x-text="sys.os">{{ $system['os'] ?? '-' }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">Architecture</p>
                <p class="text-sm font-mono font-medium text-gray-900" x-text="sys.arch">{{ $system['arch'] ?? '-' }}</p>
            </div>

            {{-- Versions --}}
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">App Version</p>
                <p class="text-sm font-mono font-medium text-indigo-600" x-text="'v' + sys.app_version">v{{ $system['app_version'] ?? '-' }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">PHP</p>
                <p class="text-sm font-mono font-medium text-gray-900" x-text="sys.php_version">{{ $system['php_version'] ?? '-' }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">Laravel</p>
                <p class="text-sm font-mono font-medium text-gray-900" x-text="sys.laravel_version">{{ $system['laravel_version'] ?? '-' }}</p>
            </div>

            {{-- CPU --}}
            <div class="bg-gray-50 rounded-lg p-3 col-span-2">
                <p class="text-xs text-gray-500 mb-1">CPU</p>
                <p class="text-sm font-mono font-medium text-gray-900" x-text="sys.cpu_model + ' (' + sys.cpu_cores + ' cores)'">{{ ($system['cpu_model'] ?? '-') . ' (' . ($system['cpu_cores'] ?? '?') . ' cores)' }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">Load Average</p>
                <p class="text-sm font-mono font-medium text-gray-900" x-text="sys.load_avg">{{ $system['load_avg'] ?? '-' }}</p>
            </div>

            {{-- Uptime --}}
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">Uptime</p>
                <p class="text-sm font-mono font-medium text-gray-900" x-text="sys.uptime">{{ $system['uptime'] ?? '-' }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">Container</p>
                <p class="text-sm font-mono font-medium" :class="sys.in_container ? 'text-green-600' : 'text-gray-600'" x-text="sys.in_container ? 'Yes' : 'No'">{{ ($system['in_container'] ?? false) ? 'Yes' : 'No' }}</p>
            </div>
        </div>

        {{-- Resource bars --}}
        <div class="mt-4 space-y-3">
            {{-- Memory --}}
            <div>
                <div class="flex justify-between text-xs text-gray-500 mb-1">
                    <span>Memory</span>
                    <span x-text="sys.memory_used + ' / ' + sys.memory_total">{{ ($system['memory_used'] ?? '-') . ' / ' . ($system['memory_total'] ?? '-') }}</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5">
                    <div class="h-2.5 rounded-full transition-all duration-500"
                         :class="sys.memory_percent > 85 ? 'bg-red-500' : sys.memory_percent > 60 ? 'bg-yellow-500' : 'bg-green-500'"
                         :style="'width: ' + sys.memory_percent + '%'"
                         style="width: {{ $system['memory_percent'] ?? 0 }}%"></div>
                </div>
            </div>

            {{-- Disk --}}
            <div>
                <div class="flex justify-between text-xs text-gray-500 mb-1">
                    <span>Disk</span>
                    <span x-text="sys.disk_used + ' / ' + sys.disk_total">{{ ($system['disk_used'] ?? '-') . ' / ' . ($system['disk_total'] ?? '-') }}</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5">
                    <div class="h-2.5 rounded-full transition-all duration-500"
                         :class="sys.disk_percent > 85 ? 'bg-red-500' : sys.disk_percent > 60 ? 'bg-yellow-500' : 'bg-green-500'"
                         :style="'width: ' + sys.disk_percent + '%'"
                         style="width: {{ $system['disk_percent'] ?? 0 }}%"></div>
                </div>
            </div>
        </div>

        {{-- Services --}}
        <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">Database</p>
                <div class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full" :class="sys.db_connection === 'OK' ? 'bg-green-500' : 'bg-red-500'"></span>
                    <p class="text-sm font-medium" x-text="sys.db_size || sys.db_connection">{{ $system['db_size'] ?? $system['db_connection'] ?? '-' }}</p>
                </div>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">Redis</p>
                <div class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full" :class="sys.redis_connection === 'OK' ? 'bg-green-500' : 'bg-red-500'"></span>
                    <p class="text-sm font-medium" x-text="sys.redis_memory || sys.redis_connection">{{ $system['redis_memory'] ?? $system['redis_connection'] ?? '-' }}</p>
                </div>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">Queue Pending</p>
                <p class="text-sm font-mono font-medium text-gray-900" x-text="sys.queue_pending">{{ $system['queue_pending'] ?? '-' }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-500 mb-1">Queue Failed</p>
                <p class="text-sm font-mono font-medium" :class="parseInt(sys.queue_failed) > 0 ? 'text-red-600' : 'text-gray-900'" x-text="sys.queue_failed">{{ $system['queue_failed'] ?? '-' }}</p>
            </div>
        </div>
    </div>

    {{-- Scheduled Jobs --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Scheduled Jobs
        </h3>

        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">Job</th>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">Schedule</th>
                    <th class="text-center px-4 py-2 font-medium text-gray-600">Status</th>
                    <th class="text-center px-4 py-2 font-medium text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($jobs as $job)
                <tr class="hover:bg-gray-50/50">
                    <td class="px-4 py-3 font-mono text-xs text-gray-900">{{ $job['name'] }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $job['schedule'] }}</td>
                    <td class="px-4 py-3 text-center">
                        @if($job['name'] === 'zeniclaw:auto-suggest')
                            <span x-show="autoSuggestEnabled" class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Active</span>
                            <span x-show="!autoSuggestEnabled" class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Disabled</span>
                        @elseif($job['name'] === 'zeniclaw:auto-improve-agents')
                            <span x-show="autoImproveEnabled" class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Active</span>
                            <span x-show="!autoImproveEnabled" class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Disabled</span>
                        @elseif($job['enabled'])
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Active</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Disabled</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if($job['name'] === 'zeniclaw:auto-suggest')
                            <button @click="toggleAutoSuggest()"
                                    class="px-3 py-1 rounded-lg text-xs font-medium transition-colors"
                                    :class="autoSuggestEnabled ? 'bg-red-50 text-red-600 hover:bg-red-100' : 'bg-green-50 text-green-600 hover:bg-green-100'">
                                <span x-text="autoSuggestEnabled ? 'Disable' : 'Enable'">{{ $autoSuggestEnabled ? 'Disable' : 'Enable' }}</span>
                            </button>
                        @elseif($job['name'] === 'zeniclaw:auto-improve-agents')
                            <div class="flex items-center gap-2">
                                <button @click="toggleAutoImprove()"
                                        class="px-3 py-1 rounded-lg text-xs font-medium transition-colors"
                                        :class="autoImproveEnabled ? 'bg-red-50 text-red-600 hover:bg-red-100' : 'bg-green-50 text-green-600 hover:bg-green-100'">
                                    <span x-text="autoImproveEnabled ? 'Disable' : 'Enable'">{{ $autoImproveEnabled ? 'Disable' : 'Enable' }}</span>
                                </button>
                                <button @click="triggerAutoImprove()" :disabled="triggeringImprove"
                                        class="px-3 py-1 rounded-lg text-xs font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100 transition-colors disabled:opacity-50">
                                    <span x-text="triggeringImprove ? 'Launching...' : 'Run Now'"></span>
                                </button>
                            </div>
                        @else
                            <span class="text-xs text-gray-400">-</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Recent Improvements --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Recent Auto-Improvements
        </h3>

        @if($recentImprovements->isEmpty())
            <p class="text-sm text-gray-400 text-center py-4">No improvements generated yet.</p>
        @else
            <div class="space-y-2">
                @foreach($recentImprovements as $imp)
                <a href="{{ route('improvements.show', $imp) }}" class="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50 transition-colors group">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate group-hover:text-indigo-600">{{ $imp->improvement_title ?? $imp->trigger_message }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $imp->created_at->diffForHumans() }}</p>
                    </div>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium flex-shrink-0 ml-3
                        @if($imp->status === 'completed') bg-green-100 text-green-700
                        @elseif($imp->status === 'in_progress') bg-blue-100 text-blue-700
                        @elseif($imp->status === 'failed') bg-red-100 text-red-700
                        @elseif($imp->status === 'rejected') bg-gray-100 text-gray-500
                        @else bg-yellow-100 text-yellow-700
                        @endif">
                        {{ $imp->status }}
                    </span>
                </a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Environment --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Environment
        </h3>

        <div class="grid grid-cols-2 gap-3 text-sm">
            <div class="flex justify-between bg-gray-50 rounded-lg px-3 py-2">
                <span class="text-gray-500">APP_ENV</span>
                <span class="font-mono text-gray-900">{{ config('app.env') }}</span>
            </div>
            <div class="flex justify-between bg-gray-50 rounded-lg px-3 py-2">
                <span class="text-gray-500">APP_DEBUG</span>
                <span class="font-mono" style="color: {{ config('app.debug') ? '#dc2626' : '#16a34a' }}">{{ config('app.debug') ? 'true' : 'false' }}</span>
            </div>
            <div class="flex justify-between bg-gray-50 rounded-lg px-3 py-2">
                <span class="text-gray-500">CACHE_DRIVER</span>
                <span class="font-mono text-gray-900">{{ config('cache.default') }}</span>
            </div>
            <div class="flex justify-between bg-gray-50 rounded-lg px-3 py-2">
                <span class="text-gray-500">SESSION_DRIVER</span>
                <span class="font-mono text-gray-900">{{ config('session.driver') }}</span>
            </div>
            <div class="flex justify-between bg-gray-50 rounded-lg px-3 py-2">
                <span class="text-gray-500">QUEUE_CONNECTION</span>
                <span class="font-mono text-gray-900">{{ config('queue.default') }}</span>
            </div>
            <div class="flex justify-between bg-gray-50 rounded-lg px-3 py-2">
                <span class="text-gray-500">LOG_CHANNEL</span>
                <span class="font-mono text-gray-900">{{ config('logging.default') }}</span>
            </div>
        </div>
    </div>

</div>

<script>
function debugPage() {
    return {
        autoSuggestEnabled: @json($autoSuggestEnabled),
        autoImproveEnabled: @json($autoImproveEnabled),
        triggeringImprove: false,
        refreshing: false,
        sys: @json($system),

        async toggleAutoSuggest() {
            const r = await fetch('{{ route("admin.debug.toggle-auto-suggest") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            });
            const d = await r.json();
            this.autoSuggestEnabled = d.enabled;
        },

        async toggleAutoImprove() {
            const r = await fetch('{{ route("admin.debug.toggle-auto-improve") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            });
            const d = await r.json();
            this.autoImproveEnabled = d.enabled;
        },

        async triggerAutoImprove() {
            this.triggeringImprove = true;
            try {
                const r = await fetch('{{ route("admin.debug.trigger-auto-improve") }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                });
                const d = await r.json();
                alert(d.message);
            } catch(e) { alert('Error: ' + e.message); }
            this.triggeringImprove = false;
        },

        async refreshSystemInfo() {
            this.refreshing = true;
            try {
                const r = await fetch('{{ route("admin.debug.system-info") }}');
                this.sys = await r.json();
            } catch(e) {}
            this.refreshing = false;
        }
    };
}
</script>
@endsection
