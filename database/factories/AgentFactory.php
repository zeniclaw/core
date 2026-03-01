<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'name'          => $this->faker->words(3, true),
            'description'   => $this->faker->sentence(),
            'system_prompt' => 'You are a helpful assistant.',
            'model'         => 'claude-sonnet-4-5',
            'status'        => 'active',
        ];
    }
}
