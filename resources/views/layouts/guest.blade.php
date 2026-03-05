<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'ZeniClaw') }}</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        </style>
    </head>
    <body class="dark-theme font-sans antialiased" style="background:#0a0e17;">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0"
             style="background:radial-gradient(ellipse at 30% 20%, rgba(59,130,246,0.08) 0%, transparent 50%),
                    radial-gradient(ellipse at 70% 60%, rgba(139,92,246,0.06) 0%, transparent 50%),
                    radial-gradient(ellipse at 50% 80%, rgba(236,72,153,0.04) 0%, transparent 50%);">
            <div>
                <a href="/" class="flex items-center gap-3 no-underline">
                    <div style="width:48px;height:48px;background:linear-gradient(135deg,#3b82f6,#8b5cf6,#ec4899);border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:24px;color:#fff;">Z</div>
                    <span style="font-size:1.5rem;font-weight:700;color:#f1f5f9;">ZeniClaw</span>
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-6 overflow-hidden sm:rounded-xl"
                 style="background:#1a1f2e;border:1px solid #1e293b;box-shadow:0 10px 25px rgba(0,0,0,0.4);">
                {{ $slot }}
            </div>

            <p class="mt-6 text-sm" style="color:#4b5563;">
                <a href="/" style="color:#64748b;text-decoration:none;" onmouseover="this.style.color='#94a3b8'" onmouseout="this.style.color='#64748b'">&larr; Back to homepage</a>
            </p>
        </div>
    </body>
</html>
