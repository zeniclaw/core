<?php

use App\Http\Controllers\Admin\HealthDashboardController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentMemoryController;
use App\Http\Controllers\AgentSessionController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SelfImprovementController;
use App\Http\Controllers\SubAgentController;
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
    Route::get('/agents/{agent}/sub/{subAgent}', [AgentController::class, 'showSubAgent'])
        ->name('agents.sub-agent')
        ->where('subAgent', 'chat|dev|reminder|project|analysis|todo|music|mood_check|smart_context');

    // Reminders
    Route::get('/reminders', [ReminderController::class, 'index'])->name('reminders.index');
    Route::get('/reminders/create', [ReminderController::class, 'create'])->name('reminders.create');
    Route::post('/reminders', [ReminderController::class, 'store'])->name('reminders.store');
    Route::delete('/reminders/{reminder}', [ReminderController::class, 'destroy'])->name('reminders.destroy');

    // Conversations
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');

    // Contacts
    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts/{session}/toggle-whitelist', [ContactController::class, 'toggleWhitelist'])->name('contacts.toggle-whitelist');
    Route::post('/agents/{agent}/toggle-whitelist', [AgentController::class, 'toggleWhitelist'])->name('agents.toggle-whitelist');

    // Projects
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::post('/projects/{project}/approve', [ProjectController::class, 'approve'])->name('projects.approve');
    Route::post('/projects/{project}/reject', [ProjectController::class, 'reject'])->name('projects.reject');

    // API endpoints
    Route::get('/api/gitlab-projects', [ProjectController::class, 'apiGitlabProjects'])->name('api.gitlab-projects');
    Route::get('/api/contacts', [ProjectController::class, 'apiContacts'])->name('api.contacts');
    Route::get('/api/groups', [ProjectController::class, 'apiGroups'])->name('api.groups');
    Route::get('/api/group-members', [ProjectController::class, 'apiGroupMembers'])->name('api.group-members');

    // SubAgents
    Route::get('/subagents', [SubAgentController::class, 'index'])->name('subagents.index');
    Route::post('/subagents/default-timeout', [SubAgentController::class, 'updateDefaultTimeout'])->name('subagents.default-timeout');
    Route::get('/subagents/{subAgent}', [SubAgentController::class, 'show'])->name('subagents.show');
    Route::get('/subagents/{subAgent}/output', [SubAgentController::class, 'output'])->name('subagents.output');
    Route::post('/subagents/{subAgent}/kill', [SubAgentController::class, 'kill'])->name('subagents.kill');
    Route::post('/subagents/{subAgent}/retry', [SubAgentController::class, 'retry'])->name('subagents.retry');
    Route::post('/subagents/{subAgent}/relaunch', [SubAgentController::class, 'relaunch'])->name('subagents.relaunch');

    // Improvements (auto-amelioration)
    Route::get('/improvements', [SelfImprovementController::class, 'index'])->name('improvements.index');
    Route::get('/improvements/{improvement}', [SelfImprovementController::class, 'show'])->name('improvements.show');
    Route::post('/improvements/{improvement}/approve', [SelfImprovementController::class, 'approve'])->name('improvements.approve');
    Route::post('/improvements/{improvement}/reject', [SelfImprovementController::class, 'reject'])->name('improvements.reject');
    Route::put('/improvements/{improvement}', [SelfImprovementController::class, 'update'])->name('improvements.update');

    // Logs
    Route::get('/logs', [LogController::class, 'index'])->name('logs.index');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/llm-keys', [SettingsController::class, 'saveLlmKeys'])->name('settings.llm-keys');
    Route::post('/settings/gitlab', [SettingsController::class, 'saveGitlabSettings'])->name('settings.gitlab');

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
