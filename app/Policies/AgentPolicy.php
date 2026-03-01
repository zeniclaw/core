<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

class AgentPolicy
{
    public function view(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }

    public function update(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }

    public function delete(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }
}
