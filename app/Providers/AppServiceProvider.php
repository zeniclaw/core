<?php

namespace App\Providers;

use App\Models\Agent;
use App\Policies\AgentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(Agent::class, AgentPolicy::class);
        $versionFile = storage_path('app/version.txt');
        $appVersion = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : '1.0.0';
        View::share('appVersion', $appVersion);
    }
}
