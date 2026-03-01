@extends('layouts.app')
@section('title', 'New Reminder')

@section('content')
<div class="max-w-xl">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-6">Create Reminder</h2>
        <form method="POST" action="{{ route('reminders.store') }}" class="space-y-5">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Agent <span class="text-red-500">*</span></label>
                <select name="agent_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none bg-white" required>
                    <option value="">Select an agent...</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" {{ old('agent_id') == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                    @endforeach
                </select>
                @error('agent_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Message <span class="text-red-500">*</span></label>
                <textarea name="message" rows="3" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none"
                    placeholder="Reminder message...">{{ old('message') }}</textarea>
                @error('message')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Channel</label>
                    <select name="channel" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none bg-white">
                        <option value="whatsapp">📱 WhatsApp</option>
                        <option value="email">📧 Email</option>
                        <option value="slack">💬 Slack</option>
                        <option value="discord">🎮 Discord</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Scheduled At <span class="text-red-500">*</span></label>
                    <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none" required>
                    @error('scheduled_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Recurrence Rule <span class="text-gray-400 font-normal">(optional, iCal RRULE)</span></label>
                <input type="text" name="recurrence_rule" value="{{ old('recurrence_rule') }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono"
                    placeholder="FREQ=DAILY or FREQ=WEEKLY;BYDAY=MO,WE,FR">
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                    Create Reminder
                </button>
                <a href="{{ route('reminders.index') }}" class="px-5 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
