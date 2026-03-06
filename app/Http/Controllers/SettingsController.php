<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $hasAnthropicKey = AppSetting::has('anthropic_api_key');
        $hasOpenAiKey = AppSetting::has('openai_api_key');
        $hasGitlabToken = AppSetting::has('gitlab_access_token');
        $hasOnPremUrl = AppSetting::has('onprem_api_url');
        $hasOnPremKey = AppSetting::has('onprem_api_key');
        $onPremUrl = AppSetting::get('onprem_api_url');
        $adminWhatsappPhone = AppSetting::get('admin_whatsapp_phone');
        $autoUpdateEnabled = AppSetting::get('auto_update_enabled') !== 'false';
        $appTimezone = AppSetting::timezone();
        $tokens = $user->tokens()->latest()->get();
        return view('settings.index', compact('user', 'hasAnthropicKey', 'hasOpenAiKey', 'hasGitlabToken', 'hasOnPremUrl', 'hasOnPremKey', 'onPremUrl', 'adminWhatsappPhone', 'autoUpdateEnabled', 'appTimezone', 'tokens'));
    }

    public function saveLlmKeys(Request $request)
    {
        $request->validate([
            'anthropic_api_key' => 'nullable|string',
            'openai_api_key'    => 'nullable|string',
            'onprem_api_url'    => 'nullable|string|url',
            'onprem_api_key'    => 'nullable|string',
        ]);

        if ($request->filled('anthropic_api_key')) {
            AppSetting::set('anthropic_api_key', $request->anthropic_api_key);
        }
        if ($request->filled('openai_api_key')) {
            AppSetting::set('openai_api_key', $request->openai_api_key);
        }
        if ($request->filled('onprem_api_url')) {
            AppSetting::set('onprem_api_url', $request->onprem_api_url);
        }
        if ($request->filled('onprem_api_key')) {
            AppSetting::set('onprem_api_key', $request->onprem_api_key);
        }

        return redirect()->route('settings.index')->with('success', 'Clés API sauvegardées.');
    }

    public function saveGitlabSettings(Request $request)
    {
        $request->validate([
            'gitlab_access_token' => 'nullable|string',
            'admin_whatsapp_phone' => 'nullable|string|max:50',
        ]);

        if ($request->filled('gitlab_access_token')) {
            AppSetting::set('gitlab_access_token', $request->gitlab_access_token);
        }
        if ($request->filled('admin_whatsapp_phone')) {
            AppSetting::set('admin_whatsapp_phone', $request->admin_whatsapp_phone);
        }

        return redirect()->route('settings.index')->with('success', 'Parametres GitLab sauvegardes.');
    }

    public function saveTimezone(Request $request)
    {
        $request->validate([
            'app_timezone' => 'required|string|timezone',
        ]);

        AppSetting::set('app_timezone', $request->app_timezone);

        return redirect()->route('settings.index')->with('success', 'Fuseau horaire mis à jour : ' . $request->app_timezone);
    }

    public function toggleAutoUpdate()
    {
        $current = AppSetting::get('auto_update_enabled') !== 'false';
        AppSetting::set('auto_update_enabled', $current ? 'false' : 'true');

        $status = !$current ? 'activée' : 'désactivée';
        return redirect()->route('settings.index')->with('success', "Mise à jour automatique {$status}.");
    }
}
