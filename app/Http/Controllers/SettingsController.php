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
        $tokens = $user->tokens()->latest()->get();
        return view('settings.index', compact('user', 'hasAnthropicKey', 'hasOpenAiKey', 'tokens'));
    }

    public function saveLlmKeys(Request $request)
    {
        $request->validate([
            'anthropic_api_key' => 'nullable|string',
            'openai_api_key'    => 'nullable|string',
        ]);

        if ($request->filled('anthropic_api_key')) {
            AppSetting::set('anthropic_api_key', $request->anthropic_api_key);
        }
        if ($request->filled('openai_api_key')) {
            AppSetting::set('openai_api_key', $request->openai_api_key);
        }

        return redirect()->route('settings.index')->with('success', 'Clés API sauvegardées.');
    }
}
