<?php

namespace App\Services;

use App\Models\PomodoroSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PomodoroSessionManager
{
    public function startSession(string $userPhone, int $agentId, int $duration = 25): PomodoroSession
    {
        // End any active session first
        $active = $this->getActiveSession($userPhone, $agentId);
        if ($active) {
            $this->endSession($userPhone, $agentId, null);
        }

        $session = PomodoroSession::create([
            'agent_id' => $agentId,
            'user_phone' => $userPhone,
            'duration' => $duration,
            'started_at' => now(),
        ]);

        Cache::put("pomodoro:active:{$userPhone}:{$agentId}", $session->id, $duration * 60 + 300);

        return $session;
    }

    public function pauseSession(string $userPhone, int $agentId): ?PomodoroSession
    {
        $session = $this->getActiveSession($userPhone, $agentId);
        if (!$session) {
            return null;
        }

        if ($session->paused_at) {
            // Already paused, resume
            $session->update(['paused_at' => null]);
        } else {
            $session->update(['paused_at' => now()]);
        }

        return $session->fresh();
    }

    public function endSession(string $userPhone, int $agentId, ?int $focusQuality): ?PomodoroSession
    {
        $session = $this->getActiveSession($userPhone, $agentId);
        if (!$session) {
            return null;
        }

        $session->update([
            'ended_at' => now(),
            'is_completed' => true,
            'focus_quality' => $focusQuality,
        ]);

        Cache::forget("pomodoro:active:{$userPhone}:{$agentId}");

        return $session->fresh();
    }

    public function stopSession(string $userPhone, int $agentId): ?PomodoroSession
    {
        $session = $this->getActiveSession($userPhone, $agentId);
        if (!$session) {
            return null;
        }

        $session->update([
            'ended_at' => now(),
            'is_completed' => false,
        ]);

        Cache::forget("pomodoro:active:{$userPhone}:{$agentId}");

        return $session->fresh();
    }

    public function getActiveSession(string $userPhone, int $agentId): ?PomodoroSession
    {
        $cachedId = Cache::get("pomodoro:active:{$userPhone}:{$agentId}");
        if ($cachedId) {
            $session = PomodoroSession::find($cachedId);
            if ($session && !$session->ended_at) {
                return $session;
            }
            Cache::forget("pomodoro:active:{$userPhone}:{$agentId}");
        }

        return PomodoroSession::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();
    }

    public function getPomodoroStats(string $userPhone, int $agentId): array
    {
        $now = now('Europe/Paris');
        $startOfWeek = $now->copy()->startOfWeek();

        $sessionsThisWeek = PomodoroSession::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->where('is_completed', true)
            ->where('started_at', '>=', $startOfWeek)
            ->count();

        $totalDuration = PomodoroSession::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->where('is_completed', true)
            ->sum('duration');

        $avgFocus = PomodoroSession::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->where('is_completed', true)
            ->whereNotNull('focus_quality')
            ->avg('focus_quality');

        $streakDays = $this->calculateDayStreak($userPhone, $agentId);

        $totalSessions = PomodoroSession::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->where('is_completed', true)
            ->count();

        return [
            'sessions_this_week' => $sessionsThisWeek,
            'total_sessions' => $totalSessions,
            'total_duration_minutes' => (int) $totalDuration,
            'avg_focus_quality' => $avgFocus ? round($avgFocus, 1) : null,
            'streak_days' => $streakDays,
        ];
    }

    private function calculateDayStreak(string $userPhone, int $agentId): int
    {
        $today = now('Europe/Paris')->toDateString();
        $streak = 0;
        $date = Carbon::parse($today, 'Europe/Paris');

        $todayDone = PomodoroSession::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->where('is_completed', true)
            ->whereDate('started_at', $date->toDateString())
            ->exists();

        if (!$todayDone) {
            $date->subDay();
        }

        while (true) {
            $exists = PomodoroSession::where('user_phone', $userPhone)
                ->where('agent_id', $agentId)
                ->where('is_completed', true)
                ->whereDate('started_at', $date->toDateString())
                ->exists();

            if (!$exists) break;

            $streak++;
            $date->subDay();
        }

        return $streak;
    }
}
