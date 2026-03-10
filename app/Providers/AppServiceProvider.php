<?php

namespace App\Providers;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
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

        // Enterprise proxy: apply globally to all Laravel HTTP client calls
        $this->configureProxy();
    }

    private function configureProxy(): void
    {
        // Priority: AppSetting (UI) > env vars
        try {
            $httpProxy = AppSetting::get('proxy_http') ?: env('HTTP_PROXY', '');
            $httpsProxy = AppSetting::get('proxy_https') ?: env('HTTPS_PROXY', '');
            $noProxy = AppSetting::get('proxy_no_proxy') ?: env('NO_PROXY', 'localhost,127.0.0.1,db,redis,waha,ollama,app');
        } catch (\Exception $e) {
            // DB not ready yet (migrations, etc.)
            $httpProxy = env('HTTP_PROXY', '');
            $httpsProxy = env('HTTPS_PROXY', '');
            $noProxy = env('NO_PROXY', 'localhost,127.0.0.1,db,redis,waha,ollama,app');
        }

        if ($httpProxy || $httpsProxy) {
            $proxyConfig = [];
            if ($httpProxy) $proxyConfig['http'] = $httpProxy;
            if ($httpsProxy) $proxyConfig['https'] = $httpsProxy;

            $noProxyList = array_map('trim', explode(',', $noProxy));

            Http::globalOptions([
                'proxy' => $proxyConfig,
                'no_proxy' => $noProxyList,
            ]);

            // Also set env vars so curl_exec and other tools pick them up
            if ($httpProxy) putenv("HTTP_PROXY={$httpProxy}");
            if ($httpsProxy) putenv("HTTPS_PROXY={$httpsProxy}");
            if ($noProxy) putenv("NO_PROXY={$noProxy}");
        }
    }
}
