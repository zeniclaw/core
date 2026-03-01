<?php

namespace App\Http\Controllers;

use App\Models\AgentLog;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $agentIds = $request->user()->agents()->pluck('id');
        $level = $request->query('level');

        $logs = AgentLog::whereIn('agent_id', $agentIds)
            ->with('agent')
            ->when($level, fn($q) => $q->where('level', $level))
            ->latest('created_at')
            ->paginate(25);

        return view('logs.index', compact('logs', 'level'));
    }
}
