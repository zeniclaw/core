<?php

use App\Http\Controllers\Admin\DebugController;
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
use App\Http\Controllers\OllamaController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SelfImprovementController;
use App\Http\Controllers\SubAgentController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

// ── Public ────────────────────────────────────────────────────────────────────
Route::get('/', fn() => view('welcome'));
Route::get('/health', [HealthController::class, 'check'])->name('health');

// Dynamic robots.txt — allow bots only on the official domain
Route::get('/robots.txt', function () {
    $officialDomain = 'www.zeniclaw.io';
    $host = request()->getHost();

    if ($host === $officialDomain || $host === 'zeniclaw.io') {
        $content = "User-agent: *\nAllow: /\n\nSitemap: https://www.zeniclaw.io/sitemap.xml\n";
    } else {
        $content = "User-agent: *\nDisallow: /\n";
    }

    return response($content, 200, ['Content-Type' => 'text/plain']);
});
Route::post('/webhook/whatsapp/{agent}', [ChannelController::class, 'whatsappWebhook'])->name('webhook.whatsapp');

// ── Authenticated ─────────────────────────────────────────────────────────────
Route::middleware(['auth'])->group(function () {

    // Web chat API
    Route::post('/api/chat', [ChannelController::class, 'webChat'])->name('api.chat');
    Route::get('/api/subagent/{id}/status', [ChannelController::class, 'subAgentStatus'])->name('api.subagent.status');

    // Context Memory Bridge debug endpoint
    Route::get('/api/context/{userId}', [AgentController::class, 'showContext'])->name('api.context.show');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Agents
    Route::resource('agents', AgentController::class);
    Route::delete('/agents/{agent}/memory/{memory}', [AgentMemoryController::class, 'destroy'])->name('agents.memory.destroy');
    Route::post('/agents/{agent}/memory/clear', [AgentMemoryController::class, 'clearAll'])->name('agents.memory.clear');
    Route::delete('/agents/{agent}/sessions/{session}', [AgentSessionController::class, 'destroy'])->name('agents.sessions.destroy');
    Route::get('/agents/{agent}/sub/{subAgent}', [AgentController::class, 'showSubAgent'])
        ->name('agents.sub-agent')
        ->where('subAgent', 'chat|dev|reminder|project|analysis|todo|music|mood_check|smart_context|finance|smart_meeting|hangman|flashcard|voice_command|code_review|screenshot|content_summarizer|event_reminder|habit|pomodoro|web_search|document|user_preferences|conversation_memory|streamline|interactive_quiz|content_curator|context_memory_bridge');
    Route::post('/agents/{agent}/sub-agent-models', [AgentController::class, 'updateSubAgentModels'])->name('agents.sub-agent-models');

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
    Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::delete('/contacts/{session}', [ContactController::class, 'destroy'])->name('contacts.destroy');
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

    // Workflows
    Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
    Route::post('/workflows', [WorkflowController::class, 'store'])->name('workflows.store');
    Route::get('/workflows/{workflow}', [WorkflowController::class, 'show'])->name('workflows.show');
    Route::delete('/workflows/{workflow}', [WorkflowController::class, 'destroy'])->name('workflows.destroy');
    Route::post('/workflows/{workflow}/trigger', [WorkflowController::class, 'trigger'])->name('workflows.trigger');
    Route::post('/workflows/{workflow}/toggle', [WorkflowController::class, 'toggle'])->name('workflows.toggle');

    // Workflow API endpoints
    Route::get('/api/workflows', [WorkflowController::class, 'apiList'])->name('api.workflows');
    Route::post('/api/workflows', [WorkflowController::class, 'apiStore'])->name('api.workflows.store');
    Route::post('/api/workflows/{workflow}/trigger', [WorkflowController::class, 'apiTrigger'])->name('api.workflows.trigger');
    Route::delete('/api/workflows/{workflow}', [WorkflowController::class, 'apiDestroy'])->name('api.workflows.destroy');

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
    Route::post('/settings/auto-update', [SettingsController::class, 'toggleAutoUpdate'])->name('settings.auto-update');
    Route::post('/settings/timezone', [SettingsController::class, 'saveTimezone'])->name('settings.timezone');

    // Ollama model management
    Route::get('/api/ollama/models', [OllamaController::class, 'models'])->name('api.ollama.models');
    Route::post('/api/ollama/pull', [OllamaController::class, 'pull'])->name('api.ollama.pull');
    Route::get('/api/ollama/pull-status', [OllamaController::class, 'pullStatus'])->name('api.ollama.pull-status');
    Route::post('/api/ollama/save-url', [OllamaController::class, 'saveUrl'])->name('api.ollama.save-url');

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
        Route::get('/update/rebuild-status', [UpdateController::class, 'rebuildStatus'])->name('update.rebuild-status');
        Route::get('/health', [HealthDashboardController::class, 'index'])->name('health');
        Route::get('/debug', [DebugController::class, 'index'])->name('debug');
        Route::post('/debug/toggle-auto-suggest', [DebugController::class, 'toggleAutoSuggest'])->name('debug.toggle-auto-suggest');
        Route::post('/debug/toggle-auto-improve', [DebugController::class, 'toggleAutoImprove'])->name('debug.toggle-auto-improve');
        Route::post('/debug/trigger-auto-improve', [DebugController::class, 'triggerAutoImprove'])->name('debug.trigger-auto-improve');
        Route::get('/debug/system-info', [DebugController::class, 'systemInfo'])->name('debug.system-info');
    });
});

require __DIR__.'/auth.php';
