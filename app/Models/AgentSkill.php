<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSkill extends Model
{
    protected $fillable = [
        'agent_id',
        'sub_agent',
        'skill_key',
        'title',
        'instructions',
        'examples',
        'taught_by',
        'active',
    ];

    protected $casts = [
        'examples' => 'array',
        'active' => 'boolean',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Get all active skills for a given agent + sub-agent.
     */
    public static function forAgent(int $agentId, string $subAgent): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('agent_id', $agentId)
            ->where('sub_agent', $subAgent)
            ->where('active', true)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get all active skills for an agent (all sub-agents).
     */
    public static function allForAgent(int $agentId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('agent_id', $agentId)
            ->where('active', true)
            ->orderBy('sub_agent')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Teach a new skill or update an existing one.
     */
    public static function teach(
        int $agentId,
        string $subAgent,
        string $skillKey,
        string $title,
        string $instructions,
        ?array $examples = null,
        ?string $taughtBy = null,
    ): self {
        return static::updateOrCreate(
            [
                'agent_id' => $agentId,
                'sub_agent' => $subAgent,
                'skill_key' => $skillKey,
            ],
            [
                'title' => $title,
                'instructions' => $instructions,
                'examples' => $examples,
                'taught_by' => $taughtBy,
                'active' => true,
            ],
        );
    }

    /**
     * Format skills as a prompt section.
     */
    public static function formatForPrompt(int $agentId, string $subAgent): string
    {
        $skills = static::forAgent($agentId, $subAgent);

        if ($skills->isEmpty()) {
            return '';
        }

        $lines = ["COMPETENCES APPRISES (retenues des conversations precedentes):"];
        foreach ($skills as $skill) {
            $lines[] = "- [{$skill->title}]: {$skill->instructions}";
            if (!empty($skill->examples)) {
                foreach ($skill->examples as $ex) {
                    $lines[] = "  Exemple: {$ex}";
                }
            }
        }

        return implode("\n", $lines);
    }
}
