<?php

use App\Http\Controllers\Admin\DebugController;
use App\Http\Controllers\Admin\HealthDashboardController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentMemoryController;
use App\Http\Controllers\AgentSessionController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\CustomAgentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\ModelMirrorController;
use App\Http\Controllers\OllamaController;
use App\Http\Controllers\PublicChatController;
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
Route::post('/contact', [\App\Http\Controllers\ContactFormController::class, 'send'])->name('contact.send');

// Partner Portal (public, token-secured)
Route::prefix('partner/{token}')->name('partner.')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [\App\Http\Controllers\PartnerPortalController::class, 'show'])->name('show');
    Route::post('/documents', [\App\Http\Controllers\PartnerPortalController::class, 'uploadDocument'])->name('documents.upload');
    Route::post('/chat', [\App\Http\Controllers\PartnerPortalController::class, 'chat'])->name('chat');
    Route::post('/assist', [\App\Http\Controllers\PartnerPortalController::class, 'assistCreate'])->name('assist');
    Route::get('/progress', [\App\Http\Controllers\PartnerPortalController::class, 'progress'])->name('progress');
    Route::post('/credentials', [\App\Http\Controllers\PartnerPortalController::class, 'storeCredential'])->name('credentials.store');
    Route::delete('/credentials/{credential}', [\App\Http\Controllers\PartnerPortalController::class, 'destroyCredential'])->name('credentials.destroy');
    Route::post('/skills', [\App\Http\Controllers\PartnerPortalController::class, 'storeSkill'])->name('skills.store');
    Route::put('/skills/{skill}', [\App\Http\Controllers\PartnerPortalController::class, 'updateSkill'])->name('skills.update');
    Route::delete('/skills/{skill}', [\App\Http\Controllers\PartnerPortalController::class, 'destroySkill'])->name('skills.destroy');
    Route::post('/scripts', [\App\Http\Controllers\PartnerPortalController::class, 'storeScript'])->name('scripts.store');
    Route::put('/scripts/{script}', [\App\Http\Controllers\PartnerPortalController::class, 'updateScript'])->name('scripts.update');
    Route::post('/scripts/{script}/run', [\App\Http\Controllers\PartnerPortalController::class, 'runScript'])->name('scripts.run');
    Route::post('/scripts/{script}/run-stream', [\App\Http\Controllers\PartnerPortalController::class, 'runScriptStream'])->name('scripts.runStream');
    Route::post('/scripts/{script}/ai-edit', [\App\Http\Controllers\PartnerPortalController::class, 'aiEditScript'])->name('scripts.aiEdit');
    Route::delete('/scripts/{script}', [\App\Http\Controllers\PartnerPortalController::class, 'destroyScript'])->name('scripts.destroy');
});

// Private agent approval (public, token-secured)
Route::get('/approve/private/{token}', [AgentController::class, 'showPrivateApproval'])->name('approve.private.show');
Route::post('/approve/private/{token}', [AgentController::class, 'processPrivateApproval'])->name('approve.private.process');

// Model mirror (public — for offline/proxy installs)
Route::get('/models', [ModelMirrorController::class, 'index'])->name('models.index');
Route::get('/models/api', [ModelMirrorController::class, 'apiList'])->name('models.api');
Route::get('/models/download/{slug}', [ModelMirrorController::class, 'download'])->name('models.download');
Route::get('/models/import-script', function () {
    $script = file_get_contents(base_path('import-model.sh'));
    return response($script, 200, ['Content-Type' => 'text/plain']);
})->name('models.import-script');

// Public AI Chat (served on dedicated port, no auth for page, API key for messages)
Route::get('/chat', [PublicChatController::class, 'index'])->name('public-chat');
Route::post('/api/public-chat', [PublicChatController::class, 'send'])->name('api.public-chat');

// ── Authenticated ─────────────────────────────────────────────────────────────
Route::middleware(['auth'])->group(function () {

    // Web chat API
    Route::post('/api/chat', [ChannelController::class, 'webChat'])->name('api.chat');
    Route::post('/api/chat/stream', [\App\Http\Controllers\StreamController::class, 'stream'])->name('api.chat.stream');
    Route::get('/api/subagent/{id}/status', [ChannelController::class, 'subAgentStatus'])->name('api.subagent.status');

    // Context Memory Bridge debug endpoint
    Route::get('/api/context/{userId}', [AgentController::class, 'showContext'])->name('api.context.show');

    // Daily Brief preferences API
    Route::get('/api/brief-preferences/{phone}', [AgentController::class, 'getBriefPreferences'])->name('api.brief-preferences.show');
    Route::post('/api/brief-preferences/{phone}', [AgentController::class, 'updateBriefPreferences'])->name('api.brief-preferences.update');

    // Time Blocker API
    Route::post('/api/agents/time-blocker/apply-block', [AgentController::class, 'applyTimeBlock'])->name('api.time-blocker.apply');

    // AI Assistant stats API
    Route::get('/api/agents/stats', [AgentController::class, 'agentStats'])->name('api.agents.stats');

    // Skills marketplace (D12.3)
    Route::get('/api/agents/skills', [AgentController::class, 'skillsMarketplace'])->name('api.agents.skills');
    Route::post('/api/agents/{agent}/skills/import', [AgentController::class, 'importSkill'])->name('api.agents.skills.import');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Agents
    Route::resource('agents', AgentController::class);
    Route::delete('/agents/{agent}/memory/{memory}', [AgentMemoryController::class, 'destroy'])->name('agents.memory.destroy');
    Route::post('/agents/{agent}/memory/clear', [AgentMemoryController::class, 'clearAll'])->name('agents.memory.clear');
    Route::delete('/agents/{agent}/sessions/{session}', [AgentSessionController::class, 'destroy'])->name('agents.sessions.destroy');
    Route::get('/agents/{agent}/sub/{subAgent}', [AgentController::class, 'showSubAgent'])
        ->name('agents.sub-agent')
        ->where('subAgent', 'chat|dev|reminder|project|analysis|todo|music|mood_check|smart_context|finance|smart_meeting|hangman|flashcard|voice_command|code_review|screenshot|content_summarizer|event_reminder|habit|pomodoro|web_search|document|user_preferences|conversation_memory|streamline|interactive_quiz|content_curator|context_memory_bridge|game_master|budget_tracker|daily_brief|collaborative_task|recipe|time_blocker|assistant|zenibiz_docs');
    Route::post('/agents/{agent}/sub-agent-models', [AgentController::class, 'updateSubAgentModels'])->name('agents.sub-agent-models');
    Route::post('/agents/{agent}/toggle-sub-agent/{subAgent}', [AgentController::class, 'toggleSubAgent'])->name('agents.toggle-sub-agent');
    Route::post('/agents/{agent}/bulk-toggle-sub-agents', [AgentController::class, 'bulkToggleSubAgents'])->name('agents.bulk-toggle-sub-agents');
    Route::post('/agents/{agent}/private-agent-access', [AgentController::class, 'updatePrivateAgentAccess'])->name('agents.private-agent-access');
    Route::post('/agents/{agent}/private-agent-secrets', [AgentController::class, 'updatePrivateAgentSecrets'])->name('agents.private-agent-secrets');

    // Custom Agents (user-created AI agents with RAG training)
    Route::get('/agents/{agent}/custom-agents', [CustomAgentController::class, 'index'])->name('custom-agents.index');
    Route::get('/agents/{agent}/custom-agents/create', [CustomAgentController::class, 'create'])->name('custom-agents.create');
    Route::post('/agents/{agent}/custom-agents', [CustomAgentController::class, 'store'])->name('custom-agents.store');
    Route::get('/agents/{agent}/custom-agents/{customAgent}', [CustomAgentController::class, 'show'])->name('custom-agents.show');
    Route::put('/agents/{agent}/custom-agents/{customAgent}', [CustomAgentController::class, 'update'])->name('custom-agents.update');
    Route::delete('/agents/{agent}/custom-agents/{customAgent}', [CustomAgentController::class, 'destroy'])->name('custom-agents.destroy');
    Route::post('/agents/{agent}/custom-agents/{customAgent}/toggle', [CustomAgentController::class, 'toggle'])->name('custom-agents.toggle');
    Route::post('/agents/{agent}/custom-agents/{customAgent}/documents', [CustomAgentController::class, 'uploadDocument'])->name('custom-agents.documents.upload');
    Route::delete('/agents/{agent}/custom-agents/{customAgent}/documents/{document}', [CustomAgentController::class, 'destroyDocument'])->name('custom-agents.documents.destroy');
    Route::post('/agents/{agent}/custom-agents/{customAgent}/documents/{document}/reprocess', [CustomAgentController::class, 'reprocessDocument'])->name('custom-agents.documents.reprocess');
    Route::post('/agents/{agent}/custom-agents/{customAgent}/update-tools', [CustomAgentController::class, 'updateTools'])->name('custom-agents.update-tools');
    Route::post('/agents/{agent}/custom-agents/{customAgent}/update-access', [CustomAgentController::class, 'updateAccess'])->name('custom-agents.update-access');
    Route::post('/agents/{agent}/custom-agents/{customAgent}/test-chat', [CustomAgentController::class, 'testChat'])->name('custom-agents.test-chat');
    Route::post('/agents/{agent}/custom-agents/{customAgent}/shares', [CustomAgentController::class, 'createShare'])->name('custom-agents.shares.create');
    Route::post('/agents/{agent}/custom-agents/{customAgent}/credentials', [CustomAgentController::class, 'storeCredential'])->name('custom-agents.credentials.store');
    Route::delete('/agents/{agent}/custom-agents/{customAgent}/credentials/{credential}', [CustomAgentController::class, 'destroyCredential'])->name('custom-agents.credentials.destroy');
    Route::delete('/agents/{agent}/custom-agents/{customAgent}/shares/{share}', [CustomAgentController::class, 'revokeShare'])->name('custom-agents.shares.revoke');

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
    Route::post('/settings/public-chat', [SettingsController::class, 'savePublicChat'])->name('settings.public-chat');
    Route::post('/settings/proxy', [SettingsController::class, 'saveProxy'])->name('settings.proxy');
    Route::post('/settings/model-roles', [SettingsController::class, 'saveModelRoles'])->name('settings.model-roles');
    Route::post('/settings/subagents', [SettingsController::class, 'saveSubagents'])->name('settings.subagents');

    // Ollama model management
    Route::get('/api/ollama/models', [OllamaController::class, 'models'])->name('api.ollama.models');
    Route::post('/api/ollama/pull', [OllamaController::class, 'pull'])->name('api.ollama.pull');
    Route::get('/api/ollama/pull-status', [OllamaController::class, 'pullStatus'])->name('api.ollama.pull-status');
    Route::post('/api/ollama/save-url', [OllamaController::class, 'saveUrl'])->name('api.ollama.save-url');
    Route::get('/api/ollama/server-check', [OllamaController::class, 'serverCheck'])->name('api.ollama.server-check');
    Route::get('/api/ollama/status', [OllamaController::class, 'status'])->name('api.ollama.status');
    Route::post('/api/ollama/start', [OllamaController::class, 'start'])->name('api.ollama.start');
    Route::post('/api/ollama/warmup', [OllamaController::class, 'warmup'])->name('api.ollama.warmup');
    Route::get('/api/ollama/loaded', [OllamaController::class, 'loaded'])->name('api.ollama.loaded');
    Route::post('/api/ollama/unload', [OllamaController::class, 'unload'])->name('api.ollama.unload');

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
        Route::post('/debug/toggle-continuous-improve', [DebugController::class, 'toggleContinuousImprove'])->name('debug.toggle-continuous-improve');
        Route::post('/debug/trigger-continuous-improve', [DebugController::class, 'triggerContinuousImprove'])->name('debug.trigger-continuous-improve');
        Route::get('/debug/system-info', [DebugController::class, 'systemInfo'])->name('debug.system-info');

        // Monitoring & Observability (D15.2, D15.3, D14.3)
        Route::get('/monitoring', [\App\Http\Controllers\MonitoringController::class, 'dashboard'])->name('monitoring');
        Route::get('/monitoring/health', [\App\Http\Controllers\MonitoringController::class, 'healthCheck'])->name('monitoring.health');
        Route::get('/monitoring/alerts', [\App\Http\Controllers\MonitoringController::class, 'alerts'])->name('monitoring.alerts');
    });
});

require __DIR__.'/auth.php';
