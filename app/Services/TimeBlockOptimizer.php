<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Todo;

class TimeBlockOptimizer
{
    /**
     * Gather all user data (todos, reminders, projects) for time block analysis.
     * Accesses TodoAgent and ProjectAgent data in real-time via their models.
     */
    public function gatherUserData(AgentContext $context): array
    {
        $prefs = PreferencesManager::getPreferences($context->from);
        $timezone = $prefs['timezone'] ?? AppSetting::timezone()->getName();

        $todos = $this->getActiveTodos($context);
        $reminders = $this->getTodayReminders($context, $timezone);
        $projects = $this->getActiveProjects($context);

        return [
            'todos' => $todos,
            'reminders' => $reminders,
            'projects' => $projects,
            'timezone' => $timezone,
        ];
    }

    /**
     * Analyze task urgency based on deadlines, keywords and priority.
     * Returns tasks sorted by urgency score (0-100).
     */
    public function analyzeTaskUrgency(array $todos): array
    {
        $scored = [];
        foreach ($todos as $todo) {
            $urgency = 50; // default medium

            $title = mb_strtolower($todo['title'] ?? '');

            // Keyword-based urgency boosting
            if (preg_match('/\b(urgent|asap|critique|critical|important|prioritaire)\b/iu', $title)) {
                $urgency += 30;
            }
            if (preg_match('/\b(aujourd|today|maintenant|now|ce\s+matin|ce\s+soir)\b/iu', $title)) {
                $urgency += 20;
            }
            if (preg_match('/\b(deadline|echeance|livraison|release|demo|presentation)\b/iu', $title)) {
                $urgency += 15;
            }
            if (preg_match('/\b(bug|fix|erreur|error|broken|casse)\b/iu', $title)) {
                $urgency += 10;
            }

            // Priority-based boosting
            $priority = $todo['priority'] ?? 'normal';
            $urgency += match ($priority) {
                'high', 'haute' => 20,
                'medium', 'moyenne' => 10,
                default => 0,
            };

            $todo['urgency_score'] = min(100, $urgency);
            $scored[] = $todo;
        }

        usort($scored, fn($a, $b) => ($b['urgency_score'] ?? 0) - ($a['urgency_score'] ?? 0));

        return $scored;
    }

    /**
     * Calculate optimal time blocks based on tasks and energy patterns.
     * Assigns high-urgency tasks to morning peak energy, lower tasks to afternoon.
     */
    public function calculateOptimalBlocks(array $tasks, string $startTime = '08:00', string $endTime = '18:00'): array
    {
        $blocks = [];
        $currentTime = $this->parseTime($startTime);
        $end = $this->parseTime($endTime);

        $highEnergyTasks = array_filter($tasks, fn($t) => ($t['urgency_score'] ?? 50) >= 70);
        $normalTasks = array_filter($tasks, fn($t) => ($t['urgency_score'] ?? 50) < 70);

        // Morning: high-energy blocks (2h focus + 15min break)
        foreach ($highEnergyTasks as $task) {
            if ($currentTime >= $end) break;

            $blocks[] = [
                'start' => $this->formatTime($currentTime),
                'end' => $this->formatTime($currentTime + 120),
                'type' => 'focus',
                'label' => $task['title'],
                'urgency' => $task['urgency_score'] ?? 50,
            ];
            $currentTime += 120;

            // Add break
            $blocks[] = [
                'start' => $this->formatTime($currentTime),
                'end' => $this->formatTime($currentTime + 15),
                'type' => 'pause',
                'label' => 'Pause courte',
            ];
            $currentTime += 15;
        }

        // Lunch break around 12:00-13:00
        if ($currentTime <= 720 && $currentTime < $end) {
            $lunchStart = max($currentTime, 720);
            $blocks[] = [
                'start' => $this->formatTime($lunchStart),
                'end' => $this->formatTime($lunchStart + 60),
                'type' => 'dejeuner',
                'label' => 'Pause dejeuner',
            ];
            $currentTime = $lunchStart + 60;
        }

        // Afternoon: normal tasks (1h30 focus + 15min break)
        foreach ($normalTasks as $task) {
            if ($currentTime >= $end) break;

            $blocks[] = [
                'start' => $this->formatTime($currentTime),
                'end' => $this->formatTime($currentTime + 90),
                'type' => 'focus',
                'label' => $task['title'],
                'urgency' => $task['urgency_score'] ?? 50,
            ];
            $currentTime += 90;

            $blocks[] = [
                'start' => $this->formatTime($currentTime),
                'end' => $this->formatTime($currentTime + 15),
                'type' => 'pause',
                'label' => 'Pause',
            ];
            $currentTime += 15;
        }

        return $blocks;
    }

    /**
     * Suggest break timing based on circadian energy patterns.
     */
    public function suggestBreakTiming(string $currentTime): array
    {
        $hour = (int) explode(':', $currentTime)[0];

        $suggestions = [];

        if ($hour >= 9 && $hour < 10) {
            $suggestions[] = ['time' => '10:00', 'type' => 'short', 'reason' => 'Pause cafe apres le premier bloc focus'];
        }
        if ($hour >= 12 && $hour < 13) {
            $suggestions[] = ['time' => '12:30', 'type' => 'lunch', 'reason' => 'Dejeuner - pic de fatigue digestive a eviter'];
        }
        if ($hour >= 14 && $hour < 15) {
            $suggestions[] = ['time' => '14:30', 'type' => 'short', 'reason' => 'Creux post-dejeuner - marche ou etirement'];
        }
        if ($hour >= 16 && $hour < 17) {
            $suggestions[] = ['time' => '16:00', 'type' => 'short', 'reason' => 'Regain d\'energie - bon moment pour taches creatives'];
        }

        return $suggestions;
    }

    /**
     * Estimate energy dips throughout the day based on circadian rhythms.
     */
    public function estimateEnergyDips(): array
    {
        return [
            ['time' => '06:00-08:00', 'level' => 'rising', 'emoji' => '🌅', 'advice' => 'Reveil progressif, routine matinale'],
            ['time' => '08:00-11:00', 'level' => 'peak', 'emoji' => '⚡', 'advice' => 'Pic d\'energie - taches complexes et creatives'],
            ['time' => '11:00-12:00', 'level' => 'high', 'emoji' => '🔋', 'advice' => 'Encore productif - finir les taches en cours'],
            ['time' => '12:00-14:00', 'level' => 'dip', 'emoji' => '😴', 'advice' => 'Creux digestif - taches legeres ou pause'],
            ['time' => '14:00-16:00', 'level' => 'recovering', 'emoji' => '🔄', 'advice' => 'Reprise progressive - reunions, emails, admin'],
            ['time' => '16:00-18:00', 'level' => 'second_wind', 'emoji' => '💨', 'advice' => 'Second souffle - bon pour collaboration et revues'],
            ['time' => '18:00-20:00', 'level' => 'declining', 'emoji' => '🌇', 'advice' => 'Energie en baisse - taches legeres uniquement'],
        ];
    }

    private function getActiveTodos(AgentContext $context): array
    {
        return Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_done', false)
            ->orderBy('id')
            ->take(20)
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'priority' => 'normal',
            ])
            ->toArray();
    }

    private function getTodayReminders(AgentContext $context, string $timezone): array
    {
        $startOfDay = now()->setTimezone($timezone)->startOfDay()->setTimezone('UTC');
        $endOfDay = now()->setTimezone($timezone)->endOfDay()->setTimezone('UTC');

        return Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->whereBetween('scheduled_at', [$startOfDay, $endOfDay])
            ->orderBy('scheduled_at')
            ->take(15)
            ->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'message' => $r->message,
                'time' => $r->scheduled_at->setTimezone($timezone)->format('H:i'),
            ])
            ->toArray();
    }

    private function getActiveProjects(AgentContext $context): array
    {
        $activeProjectId = $context->session->active_project_id ?? null;

        return Project::whereIn('status', ['approved', 'in_progress'])
            ->orderByDesc('updated_at')
            ->take(5)
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'status' => $p->status,
                'is_active' => $p->id === $activeProjectId,
            ])
            ->toArray();
    }

    private function parseTime(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);
        return ((int) $hours * 60) + (int) $minutes;
    }

    private function formatTime(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }
}
