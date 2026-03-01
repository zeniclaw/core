@extends('layouts.app')
@section('title', 'Agent Logs')

@section('content')
<div class="space-y-4">
    {{-- Level filter --}}
    <div class="flex items-center gap-2">
        @foreach([''=>'All', 'info'=>'Info', 'warn'=>'Warn', 'error'=>'Error'] as $val => $label)
        <a href="{{ route('logs.index', $val ? ['level'=>$val] : []) }}"
           class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors
               {{ $level === $val ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50' }}">
            {{ $label }}
        </a>
        @endforeach
        <span class="ml-auto text-sm text-gray-500">{{ $logs->total() }} entries</span>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($logs->isEmpty())
            <div class="py-16 text-center text-gray-400">
                <p class="text-5xl mb-3">📋</p>
                <p>No logs found.</p>
            </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Level</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Agent</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Message</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Context</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">When</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50 font-mono">
                @foreach($logs as $log)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-3">
                        <span class="px-2 py-0.5 rounded text-xs font-medium font-sans
                            {{ $log->level === 'error' ? 'bg-red-100 text-red-700' : ($log->level === 'warn' ? 'bg-yellow-100 text-yellow-700' : 'bg-blue-100 text-blue-700') }}">
                            {{ strtoupper($log->level) }}
                        </span>
                    </td>
                    <td class="px-6 py-3 text-xs text-gray-600 font-sans">{{ $log->agent->name }}</td>
                    <td class="px-6 py-3 text-xs text-gray-800 max-w-sm truncate">{{ $log->message }}</td>
                    <td class="px-6 py-3 text-xs text-gray-400 max-w-xs truncate">
                        @if($log->context){{ json_encode($log->context) }}@endif
                    </td>
                    <td class="px-6 py-3 text-xs text-gray-400 font-sans whitespace-nowrap">{{ $log->created_at->diffForHumans() }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-6 py-4 border-t border-gray-100">{{ $logs->links() }}</div>
        @endif
    </div>
</div>
@endsection
