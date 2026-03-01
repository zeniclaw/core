<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentMemory;
use Illuminate\Http\Request;

class AgentMemoryController extends Controller
{
    public function destroy(Request $request, Agent $agent, AgentMemory $memory)
    {
        abort_unless($agent->user_id === $request->user()->id, 403);
        abort_unless($memory->agent_id === $agent->id, 403);
        $memory->delete();
        return redirect()->route('agents.show', $agent)->with('success', 'Memory entry deleted.');
    }

    public function clearAll(Request $request, Agent $agent)
    {
        abort_unless($agent->user_id === $request->user()->id, 403);
        $type = $request->input('type'); // daily or longterm or null (all)
        $query = $agent->memory();
        if ($type) $query->where('type', $type);
        $query->delete();
        return redirect()->route('agents.show', $agent)->with('success', 'Memory cleared.');
    }
}
