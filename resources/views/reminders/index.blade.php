@extends('layouts.app')
@section('title', 'Reminders')

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500">{{ $reminders->total() }} reminder(s)</p>
        <a href="{{ route('reminders.create') }}" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 transition-colors">+ New Reminder</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if($reminders->isEmpty())
            <div class="py-16 text-center text-gray-400">
                <p class="text-5xl mb-3">⏰</p>
                <p class="text-lg font-medium">No reminders yet</p>
                <a href="{{ route('reminders.create') }}" class="mt-3 inline-block text-indigo-600 hover:underline">Create your first reminder →</a>
            </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Message</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Agent</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Channel</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Scheduled</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($reminders as $reminder)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-4 text-gray-900 max-w-xs truncate">{{ $reminder->message }}</td>
                    <td class="px-6 py-4 text-gray-600 text-xs">{{ $reminder->agent->name }}</td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs">{{ $reminder->channel }}</span>
                    </td>
                    <td class="px-6 py-4 text-gray-500 text-xs">{{ $reminder->scheduled_at->setTimezone(\App\Models\AppSetting::timezone())->format('d M Y H:i') }}</td>
                    <td class="px-6 py-4">
                        @php
                            $colors = ['pending'=>'bg-orange-100 text-orange-700','sent'=>'bg-green-100 text-green-700','done'=>'bg-gray-100 text-gray-500','snoozed'=>'bg-yellow-100 text-yellow-700'];
                        @endphp
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium {{ $colors[$reminder->status] ?? 'bg-gray-100 text-gray-500' }}">
                            {{ $reminder->status }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <form method="POST" action="{{ route('reminders.destroy', $reminder) }}"
                              x-data @submit.prevent="if(confirm('Delete this reminder?')) $el.submit()">
                            @csrf @method('DELETE')
                            <button class="text-red-500 hover:text-red-700 text-xs px-2 py-1 rounded hover:bg-red-50">Delete</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-6 py-4 border-t border-gray-100">{{ $reminders->links() }}</div>
        @endif
    </div>
</div>
@endsection
