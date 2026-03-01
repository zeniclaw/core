<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function index(Request $request)
    {
        $reminders = Reminder::whereIn('agent_id', $request->user()->agents()->pluck('id'))
            ->with('agent')
            ->latest()
            ->paginate(15);
        return view('reminders.index', compact('reminders'));
    }

    public function create(Request $request)
    {
        $agents = $request->user()->agents()->where('status', 'active')->get();
        return view('reminders.create', compact('agents'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'message' => 'required|string',
            'channel' => 'required|string',
            'scheduled_at' => 'required|date',
            'recurrence_rule' => 'nullable|string',
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['status'] = 'pending';

        Reminder::create($validated);

        return redirect()->route('reminders.index')->with('success', 'Reminder created.');
    }

    public function destroy(Request $request, Reminder $reminder)
    {
        abort_unless($reminder->user_id === $request->user()->id, 403);
        $reminder->delete();
        return redirect()->route('reminders.index')->with('success', 'Reminder deleted.');
    }
}
