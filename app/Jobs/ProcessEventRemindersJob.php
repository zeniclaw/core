<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\EventReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessEventRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $now = Carbon::now(AppSetting::timezone());

        $events = EventReminder::where('status', 'active')
            ->where('event_date', '>=', $now->toDateString())
            ->get();

        foreach ($events as $event) {
            try {
                $this->processEvent($event, $now);
            } catch (\Throwable $e) {
                Log::warning('ProcessEventRemindersJob: failed for event #' . $event->id . ': ' . $e->getMessage());
            }
        }

        // Auto-complete past events
        EventReminder::where('status', 'active')
            ->where('event_date', '<', $now->toDateString())
            ->update(['status' => 'completed']);
    }

    private function processEvent(EventReminder $event, Carbon $now): void
    {
        $eventDatetime = $event->getEventDatetime();

        if ($eventDatetime->isPast()) {
            return;
        }

        $minutesUntil = $now->diffInMinutes($eventDatetime, false);
        $reminderTimes = $event->reminder_times ?? [30, 60, 1440];

        foreach ($reminderTimes as $reminderMinutes) {
            // Check if we're within a 1-minute window of this reminder time
            $diff = abs($minutesUntil - $reminderMinutes);
            if ($diff <= 1) {
                $this->sendNotification($event, $reminderMinutes);
                break; // Only send one notification per run
            }
        }
    }

    private function sendNotification(EventReminder $event, int $minutesBefore): void
    {
        $label = $this->minutesToLabel($minutesBefore);
        $timeFormatted = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : '';
        $dateFormatted = $event->event_date->format('d/m/Y');

        $text = "Rappel : *{$event->event_name}* dans {$label} !\n"
            . "Date : {$dateFormatted}" . ($timeFormatted ? " a {$timeFormatted}" : '') . "\n";

        if ($event->location) {
            $text .= "Lieu : {$event->location}\n";
        }

        if ($event->participants && count($event->participants) > 0) {
            $text .= "Participants : " . implode(', ', $event->participants) . "\n";
        }

        if ($event->notification_escalation && $minutesBefore <= 30) {
            $text .= "\n*L'evenement approche !*";
        }

        $this->sendWhatsApp($event->user_phone, $text);

        Log::info('EventReminder notification sent', [
            'event_id' => $event->id,
            'user' => $event->user_phone,
            'minutes_before' => $minutesBefore,
        ]);
    }

    private function minutesToLabel(int $minutes): string
    {
        if ($minutes >= 1440) {
            $days = intdiv($minutes, 1440);
            return "{$days} jour" . ($days > 1 ? 's' : '');
        }
        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            return "{$hours}h";
        }
        return "{$minutes} min";
    }

    private function sendWhatsApp(string $chatId, string $text): void
    {
        try {
            Http::timeout(10)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->post('http://waha:3000/api/sendText', [
                    'chatId' => $chatId,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::warning('ProcessEventRemindersJob: failed to send WhatsApp: ' . $e->getMessage());
        }
    }
}
