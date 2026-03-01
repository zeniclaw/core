<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $agentCount = $user->agents()->count();
        $activeAgents = $user->agents()->where('status', 'active')->count();
        $reminderCount = $user->reminders()->count();
        $pendingReminders = $user->reminders()->where('status', 'pending')->count();
        $agents = $user->agents()->latest()->take(5)->get();

        return view('dashboard', compact('agentCount', 'activeAgents', 'reminderCount', 'pendingReminders', 'agents'));
    }
}
