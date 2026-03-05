<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class EventReminder extends Model
{
    protected $fillable = [
        'user_phone',
        'event_name',
        'event_date',
        'event_time',
        'location',
        'participants',
        'description',
        'reminder_times',
        'notification_escalation',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'participants' => 'array',
            'reminder_times' => 'array',
            'notification_escalation' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_phone', 'phone');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', 'active')
            ->where('event_date', '>=', Carbon::today());
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'active')
            ->where('event_date', '<', Carbon::today());
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getEventDatetime(): Carbon
    {
        $date = $this->event_date->format('Y-m-d');
        $time = $this->event_time ?? '00:00:00';
        return Carbon::parse("{$date} {$time}", 'Europe/Paris');
    }

    public function calculateNextReminder(): ?Carbon
    {
        $eventDatetime = $this->getEventDatetime();
        $now = Carbon::now('Europe/Paris');

        if ($eventDatetime->isPast()) {
            return null;
        }

        $reminderMinutes = $this->reminder_times ?? [30, 60, 1440];
        rsort($reminderMinutes); // largest first

        foreach ($reminderMinutes as $minutes) {
            $reminderTime = $eventDatetime->copy()->subMinutes($minutes);
            if ($reminderTime->isAfter($now)) {
                continue;
            }
            // This reminder time has passed or is now — check if within a 2-minute window
            if ($reminderTime->diffInMinutes($now, false) <= 2 && $reminderTime->diffInMinutes($now, false) >= 0) {
                return $reminderTime;
            }
        }

        return null;
    }

    public function shouldNotifyNow(): bool
    {
        return $this->calculateNextReminder() !== null;
    }

    public function timeUntilEvent(): string
    {
        $eventDatetime = $this->getEventDatetime();
        $now = Carbon::now('Europe/Paris');

        if ($eventDatetime->isPast()) {
            return 'passe';
        }

        return $eventDatetime->diffForHumans($now, ['parts' => 2, 'join' => ' et ']);
    }
}
