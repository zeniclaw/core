<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentSession;
use Illuminate\Http\Request;

class AgentSessionController extends Controller
{
    public function destroy(Request $request, Agent $agent, AgentSession $session)
    {
        abort_unless($agent->user_id === $request->user()->id, 403);
        abort_unless($session->agent_id === $agent->id, 403);
        $session->delete();
        return redirect()->route('agents.show', $agent)->with('success', 'Session reset.');
    }
}
