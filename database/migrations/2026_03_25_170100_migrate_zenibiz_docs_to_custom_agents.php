<?php

use App\Models\Agent;
use App\Models\CustomAgent;
use Illuminate\Database\Migrations\Migration;

/**
 * Migrate ZenibizDocsAgent from hardcoded sub-agent to custom/private agent system.
 * Copies access control from agent.private_sub_agents['zenibiz_docs'] → custom_agent.allowed_peers.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Agent::all() as $agent) {
            $privateAccess = $agent->private_sub_agents ?? [];
            $zenibizPeers = $privateAccess['zenibiz_docs'] ?? [];

            // Only create if this agent had zenibiz_docs configured
            if (empty($zenibizPeers)) {
                continue;
            }

            CustomAgent::firstOrCreate(
                [
                    'agent_id' => $agent->id,
                    'agent_class' => 'App\\Services\\Agents\\ZenibizDocsAgent',
                ],
                [
                    'name' => 'ZENIBIZ DOCS',
                    'description' => 'Gestion documentaire ZENIBIZ via API REST. Categories, documents, recherche, photo-to-PDF.',
                    'avatar' => '📚',
                    'model' => 'default',
                    'is_active' => true,
                    'allowed_peers' => $zenibizPeers,
                    'enabled_tools' => [],
                ]
            );

            // Clean up old private_sub_agents entry
            unset($privateAccess['zenibiz_docs']);
            $agent->update(['private_sub_agents' => $privateAccess]);
        }
    }

    public function down(): void
    {
        $customs = CustomAgent::where('agent_class', 'App\\Services\\Agents\\ZenibizDocsAgent')->get();
        foreach ($customs as $ca) {
            $agent = Agent::find($ca->agent_id);
            if ($agent) {
                $privateAccess = $agent->private_sub_agents ?? [];
                $privateAccess['zenibiz_docs'] = $ca->allowed_peers ?? [];
                $agent->update(['private_sub_agents' => $privateAccess]);
            }
            $ca->delete();
        }
    }
};
