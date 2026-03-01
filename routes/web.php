<?php

use App\Http\Controllers\Admin\HealthDashboardController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentMemoryController;
use App\Http\Controllers\AgentSessionController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// ── Public ────────────────────────────────────────────────────────────────────
Route::get('/', fn() => redirect()->route('dashboard'));
Route::get('/health', [HealthController::class, 'check'])->name('health');
Route::post('/webhook/whatsapp/{agent}', [ChannelController::class, 'whatsappWebhook'])->name('webhook.whatsapp');

// ── Authenticated ─────────────────────────────────────────────────────────────
Route::middleware(['auth'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Agents
    Route::resource('agents', AgentController::class);
    Route::delete('/agents/{agent}/memory/{memory}', [AgentMemoryController::class, 'destroy'])->name('agents.memory.destroy');
    Route::post('/agents/{agent}/memory/clear', [AgentMemoryController::class, 'clearAll'])->name('agents.memory.clear');
    Route::delete('/agents/{agent}/sessions/{session}', [AgentSessionController::class, 'destroy'])->name('agents.sessions.destroy');

    // Reminders
    Route::get('/reminders', [ReminderController::class, 'index'])->name('reminders.index');
    Route::get('/reminders/create', [ReminderController::class, 'create'])->name('reminders.create');
    Route::post('/reminders', [ReminderController::class, 'store'])->name('reminders.store');
    Route::delete('/reminders/{reminder}', [ReminderController::class, 'destroy'])->name('reminders.destroy');

    // Logs
    Route::get('/logs', [LogController::class, 'index'])->name('logs.index');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/llm-keys', [SettingsController::class, 'saveLlmKeys'])->name('settings.llm-keys');

    // API Tokens
    Route::post('/settings/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::delete('/settings/tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');

    // WhatsApp Channel
    Route::post('/channels/whatsapp/start', [ChannelController::class, 'startWhatsapp'])->name('channels.whatsapp.start');
    Route::get('/channels/whatsapp/qr', [ChannelController::class, 'getQr'])->name('channels.whatsapp.qr');
    Route::get('/channels/whatsapp/status', [ChannelController::class, 'statusWhatsapp'])->name('channels.whatsapp.status');
    Route::post('/channels/whatsapp/stop', [ChannelController::class, 'stopWhatsapp'])->name('channels.whatsapp.stop');

    // Admin (superadmin only)
    Route::middleware(['App\Http\Middleware\RequireSuperAdmin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/update', [UpdateController::class, 'index'])->name('update');
        Route::post('/update', [UpdateController::class, 'update'])->name('update.run');
        Route::get('/health', [HealthDashboardController::class, 'index'])->name('health');
    });
});

require __DIR__.'/auth.php';
