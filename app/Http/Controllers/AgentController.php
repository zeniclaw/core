<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index(Request $request)
    {
        $agents = $request->user()->agents()->latest()->paginate(15);
        return view('agents.index', compact('agents'));
    }

    public function create()
    {
        return view('agents.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'system_prompt' => 'nullable|string',
            'model' => 'required|string',
            'status' => 'required|in:active,inactive',
        ]);

        $request->user()->agents()->create($validated);

        return redirect()->route('agents.index')->with('success', 'Agent created successfully.');
    }

    public function show(Request $request, Agent $agent)
    {
        $this->authorize('view', $agent);
        $logs = $agent->logs()->latest('created_at')->take(20)->get();
        $reminders = $agent->reminders()->latest()->take(10)->get();
        $secrets = $agent->secrets()->get();
        $memories = $agent->memory()->orderByDesc('date')->get();
        $sessions = $agent->sessions()->orderByDesc('last_message_at')->get();
        return view('agents.show', compact('agent', 'logs', 'reminders', 'secrets', 'memories', 'sessions'));
    }

    public function edit(Request $request, Agent $agent)
    {
        $this->authorize('update', $agent);
        return view('agents.edit', compact('agent'));
    }

    public function update(Request $request, Agent $agent)
    {
        $this->authorize('update', $agent);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'system_prompt' => 'nullable|string',
            'model' => 'required|string',
            'status' => 'required|in:active,inactive',
        ]);

        $agent->update($validated);

        return redirect()->route('agents.index')->with('success', 'Agent updated successfully.');
    }

    public function destroy(Request $request, Agent $agent)
    {
        $this->authorize('delete', $agent);
        $agent->delete();
        return redirect()->route('agents.index')->with('success', 'Agent deleted.');
    }
}
