<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentLog;
use App\Models\Reminder;
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

        // ── Agent 1: Reminder Bot ────────────────────────────────────────────
        $agent1 = Agent::create([
            'user_id' => $admin->id,
            'name' => 'Reminder Bot',
            'description' => 'Sends daily reminders and follow-ups.',
            'system_prompt' => 'You are a helpful assistant that sends reminders and follow-up messages.',
            'model' => 'claude-sonnet-4-5',
            'status' => 'active',
        ]);

        AgentLog::create(['agent_id' => $agent1->id, 'level' => 'info', 'message' => 'Agent started successfully.', 'context' => ['version' => '1.0']]);
        AgentLog::create(['agent_id' => $agent1->id, 'level' => 'info', 'message' => 'Reminder processed: Daily standup.', 'context' => ['channel' => 'whatsapp']]);

        Reminder::create([
            'agent_id' => $agent1->id,
            'user_id' => $admin->id,
            'message' => 'Daily standup meeting in 15 minutes!',
            'channel' => 'whatsapp',
            'scheduled_at' => now()->addDay()->setHour(9)->setMinute(0),
            'status' => 'pending',
        ]);

        // ── Agent 2: Research Assistant ──────────────────────────────────────
        $agent2 = Agent::create([
            'user_id' => $admin->id,
            'name' => 'Research Assistant',
            'description' => 'Helps with research and summarization tasks.',
            'system_prompt' => 'You are a research assistant. Summarize information concisely and accurately.',
            'model' => 'claude-opus-4-5',
            'status' => 'active',
        ]);

        AgentLog::create(['agent_id' => $agent2->id, 'level' => 'info',  'message' => 'Research task completed.',         'context' => ['topic' => 'AI trends 2025']]);
        AgentLog::create(['agent_id' => $agent2->id, 'level' => 'warn',  'message' => 'Rate limit approaching.',           'context' => ['requests_remaining' => 10]]);
        AgentLog::create(['agent_id' => $agent2->id, 'level' => 'error', 'message' => 'API timeout on external request.', 'context' => ['url' => 'https://api.example.com', 'timeout' => 30]]);

        Reminder::create([
            'agent_id' => $agent2->id,
            'user_id' => $admin->id,
            'message' => 'Weekly research report due!',
            'channel' => 'email',
            'scheduled_at' => now()->addWeek(),
            'recurrence_rule' => 'FREQ=WEEKLY',
            'status' => 'pending',
        ]);

        // ── Agent 3: Coding Agent ────────────────────────────────────────────
        $agent3 = Agent::create([
            'user_id' => $admin->id,
            'name' => '🤖 Coding Agent',
            'description' => 'Agent expert en développement — Laravel, PHP, JavaScript, Docker. Aide à coder, déboguer et reviewer du code.',
            'system_prompt' => "Tu es un expert en développement logiciel spécialisé en Laravel, PHP, JavaScript, Alpine.js et Docker.\n\nTu réponds toujours en français sauf si on te parle dans une autre langue.\n\nTu es direct, concis et pragmatique. Tu fournis du code fonctionnel avec des explications claires.\n\nQuand tu reçois du code à reviewer, tu identifies les bugs, les problèmes de sécurité et les améliorations possibles.\n\nTu peux aussi aider à :\n- Architechter des fonctionnalités\n- Déboguer des erreurs\n- Écrire des tests\n- Optimiser les performances\n- Expliquer des concepts techniques",
            'model' => 'claude-sonnet-4-5',
            'status' => 'active',
        ]);

        AgentLog::create(['agent_id' => $agent3->id, 'level' => 'info', 'message' => 'Agent initialisé.',                              'context' => ['model' => 'claude-sonnet-4-5']]);
        AgentLog::create(['agent_id' => $agent3->id, 'level' => 'info', 'message' => 'Prêt à recevoir des questions de développement.', 'context' => []]);

        Reminder::create([
            'agent_id' => $agent3->id,
            'user_id' => $admin->id,
            'message' => 'Review du code PlatesNReps',
            'channel' => 'whatsapp',
            'scheduled_at' => now()->addDay()->setHour(9)->setMinute(0),
            'status' => 'pending',
        ]);
    }
}
