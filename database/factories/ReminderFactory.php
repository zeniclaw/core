<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_id'     => Agent::factory(),
            'user_id'      => User::factory(),
            'message'      => $this->faker->sentence(),
            'channel'      => 'whatsapp',
            'scheduled_at' => now()->addDays(rand(1, 7)),
            'status'       => 'pending',
        ];
    }
}
