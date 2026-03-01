<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Force HTTPS when behind Cloudflare/nginx proxy
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            URL::forceScheme('https');
        }

        $versionFile = storage_path('app/version.txt');
        $appVersion = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : '1.0.0';
        View::share('appVersion', $appVersion);
    }
}
