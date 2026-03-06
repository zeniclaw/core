<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\Habit;
use App\Models\HabitLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendHabitReminders extends Command
{
    protected $signature = 'habits:remind';
    protected $description = 'Send daily reminders for uncompleted habits';

    private string $wahaBase = 'http://waha:3000';
    private string $wahaApiKey = 'zeniclaw-waha-2026';

    public function handle(): void
    {
        $today = now(AppSetting::timezone())->toDateString();
        $dayOfWeek = now(AppSetting::timezone())->dayOfWeekIso; // 1=Monday, 7=Sunday

        $habits = Habit::whereNull('deleted_at')->get();

        // Group habits by user
        $byUser = $habits->groupBy('user_phone');

        foreach ($byUser as $phone => $userHabits) {
            $pending = [];

            foreach ($userHabits as $habit) {
                // Skip weekly habits if not the right day (default: Monday)
                if ($habit->frequency === 'weekly' && $dayOfWeek !== 1) {
                    continue;
                }

                $done = HabitLog::where('habit_id', $habit->id)
                    ->where('completed_date', $today)
                    ->exists();

                if (!$done) {
                    $pending[] = $habit->name;
                }
            }

            if (empty($pending)) {
                continue;
            }

            $count = count($pending);
            $list = implode("\n", array_map(fn($n, $i) => ($i + 1) . ". {$n}", $pending, array_keys($pending)));

            $text = "Bonjour ! Tu as {$count} habitude(s) a completer aujourd'hui :\n\n{$list}\n\n"
                . "Dis \"cocher [habitude]\" quand c'est fait !";

            $this->sendWhatsApp($phone, $text);
            $this->info("Reminder sent to {$phone}: {$count} pending habits");
        }

        $this->info('Habit reminders processed.');
    }

    private function sendWhatsApp(string $chatId, string $text): void
    {
        try {
            Http::timeout(10)
                ->withHeaders(['X-Api-Key' => $this->wahaApiKey])
                ->post("{$this->wahaBase}/api/sendText", [
                    'chatId' => $chatId,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to send habit reminder to {$chatId}: " . $e->getMessage());
        }
    }
}
