<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ZeniClaw — @yield('title', 'Dashboard')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-100 font-sans antialiased" x-data="{ sidebarOpen: false }">

{{-- Mobile overlay --}}
<div x-show="sidebarOpen" @click="sidebarOpen = false"
     class="fixed inset-0 bg-black/50 z-20 lg:hidden" x-transition></div>

<div class="flex h-screen overflow-hidden">

    {{-- ── Sidebar ────────────────────────────────────────────────────── --}}
    <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
           class="fixed lg:static inset-y-0 left-0 z-30 w-64 bg-gray-900 text-white flex flex-col
                  transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:flex-shrink-0">

        <div class="px-6 py-5 border-b border-gray-800 flex items-center justify-between">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                <span class="text-2xl">🦅</span>
                <span class="text-xl font-bold tracking-tight">ZeniClaw</span>
            </a>
            <button @click="sidebarOpen = false" class="lg:hidden text-gray-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <p class="text-xs text-gray-500 px-6 pb-2 pt-1">AI Agent Platform</p>

        <nav class="flex-1 px-3 py-2 space-y-0.5 overflow-y-auto">
            @php
                $nav = [
                    ['route'=>'dashboard',      'icon'=>'📊', 'label'=>'Dashboard',  'match'=>'dashboard'],
                    ['route'=>'agents.index',   'icon'=>'🤖', 'label'=>'Agents',     'match'=>'agents*'],
                    ['route'=>'reminders.index','icon'=>'⏰', 'label'=>'Reminders',  'match'=>'reminders*'],
                    ['route'=>'logs.index',     'icon'=>'📋', 'label'=>'Logs',       'match'=>'logs*'],
                    ['route'=>'settings.index', 'icon'=>'⚙️', 'label'=>'Settings',   'match'=>'settings*'],
                ];
            @endphp
            @foreach($nav as $n)
            <a href="{{ route($n['route']) }}" @click="sidebarOpen = false"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all
                      {{ request()->routeIs($n['match']) ? 'bg-indigo-600 text-white shadow' : 'text-gray-400 hover:bg-gray-800 hover:text-white' }}">
                <span class="text-base">{{ $n['icon'] }}</span>
                {{ $n['label'] }}
            </a>
            @endforeach

            @if(auth()->check() && auth()->user()->role === 'superadmin')
            <div class="pt-4 pb-1 px-3">
                <p class="text-xs font-semibold text-gray-600 uppercase tracking-wider">Admin</p>
            </div>
            <a href="{{ route('admin.update') }}" @click="sidebarOpen = false"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all
                      {{ request()->routeIs('admin.update*') ? 'bg-indigo-600 text-white shadow' : 'text-gray-400 hover:bg-gray-800 hover:text-white' }}">
                <span class="text-base">🔄</span>
                Mises à jour
            </a>
            @endif
        </nav>

        <div class="px-5 py-3 border-t border-gray-800 text-xs text-gray-600">
            ZeniClaw v{{ $appVersion }}
        </div>
    </aside>

    {{-- ── Main ────────────────────────────────────────────────────────── --}}
    <div class="flex-1 flex flex-col overflow-hidden min-w-0">

        {{-- Top bar --}}
        <header class="bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-3">
                {{-- Hamburger (mobile) --}}
                <button @click="sidebarOpen = true" class="lg:hidden p-1.5 rounded-lg text-gray-500 hover:bg-gray-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <h1 class="text-base font-semibold text-gray-800">@yield('title', 'Dashboard')</h1>
            </div>
            <div class="flex items-center gap-3" x-data="{ open: false }">
                <span class="text-sm text-gray-500 hidden md:block">{{ auth()->user()->name }}</span>
                <div class="relative">
                    <button @click="open = !open" class="flex items-center gap-1.5 focus:outline-none">
                        <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center text-white text-xs font-bold">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </div>
                        <svg class="w-3.5 h-3.5 text-gray-400 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false" x-transition
                         class="absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-lg border border-gray-100 z-50 py-1">
                        <div class="px-4 py-2 border-b border-gray-100">
                            <p class="text-xs font-medium text-gray-900">{{ auth()->user()->name }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ auth()->user()->email }}</p>
                            <span class="inline-block mt-1 px-1.5 py-0.5 text-xs rounded bg-indigo-100 text-indigo-700">{{ auth()->user()->role }}</span>
                        </div>
                        <a href="{{ route('settings.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">⚙️ Settings</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50">🚪 Logout</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        {{-- Flash messages --}}
        @if(session('success') || session('error'))
        <div class="px-4 pt-3">
            @if(session('success'))
            <div class="mb-2 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded-lg text-sm flex items-center gap-2">
                ✅ {{ session('success') }}
            </div>
            @endif
            @if(session('error'))
            <div class="mb-2 px-4 py-3 bg-red-50 border border-red-200 text-red-800 rounded-lg text-sm flex items-center gap-2">
                ❌ {{ session('error') }}
            </div>
            @endif
        </div>
        @endif

        {{-- Page content --}}
        <main class="flex-1 overflow-y-auto p-4 pb-20 lg:pb-6">
            @yield('content')
        </main>

        {{-- ── Bottom Nav (mobile only) ──────────────────────────────── --}}
        <nav class="lg:hidden fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 flex z-10 safe-pb">
            @php
                $bottomNav = [
                    ['route'=>'dashboard',      'icon'=>'📊', 'label'=>'Home',     'match'=>'dashboard'],
                    ['route'=>'agents.index',   'icon'=>'🤖', 'label'=>'Agents',   'match'=>'agents*'],
                    ['route'=>'reminders.index','icon'=>'⏰', 'label'=>'Reminders','match'=>'reminders*'],
                    ['route'=>'logs.index',     'icon'=>'📋', 'label'=>'Logs',     'match'=>'logs*'],
                    ['route'=>'settings.index', 'icon'=>'⚙️', 'label'=>'Settings', 'match'=>'settings*'],
                ];
            @endphp
            @foreach($bottomNav as $n)
            <a href="{{ route($n['route']) }}"
               class="flex-1 flex flex-col items-center justify-center py-2 text-xs
                      {{ request()->routeIs($n['match']) ? 'text-indigo-600' : 'text-gray-500' }}">
                <span class="text-xl leading-none mb-0.5">{{ $n['icon'] }}</span>
                <span class="text-[10px]">{{ $n['label'] }}</span>
            </a>
            @endforeach
        </nav>
    </div>
</div>
</body>
</html>
