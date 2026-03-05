<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        $admin = User::create([
            'name' => 'Guillaume',
            'email' => 'admin@zeniclaw.io',
            'password' => Hash::make('password'),
            'role' => 'superadmin',
            'email_verified_at' => now(),
        ]);

        // ── Main Agent ─────────────────────────────────────────────────────
        $agent = Agent::create([
            'user_id' => $admin->id,
            'name' => 'ZeniClaw Main',
            'description' => 'Agent principal — assistant polyvalent WhatsApp avec rappels, dev, projets, recherche et plus.',
            'system_prompt' => "Tu es ZeniClaw, un assistant IA polyvalent accessible via WhatsApp.\n\nTu es direct, concis et utile. Tu reponds en francais sauf si on te parle dans une autre langue.\n\nTu peux aider avec :\n- Conversations generales et questions\n- Rappels et notifications\n- Gestion de projets et to-do lists\n- Developpement (code, debug, review)\n- Recherche et synthese d'informations\n- Suivi d'habitudes et bien-etre\n\nAdapte ton ton au contexte : professionnel pour le travail, decontracte pour le reste.",
            'model' => 'claude-sonnet-4-5',
            'status' => 'active',
        ]);

        AgentLog::create(['agent_id' => $agent->id, 'level' => 'info', 'message' => 'Agent initialized.', 'context' => ['version' => '1.0']]);
    }
}
