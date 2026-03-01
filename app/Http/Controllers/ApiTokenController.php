<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:100']);

        ['token' => $token, 'plain' => $plain] = ApiToken::generate($request->user()->id, $request->name);

        return redirect()->route('settings.index')
            ->with('new_token', $plain)
            ->with('success', 'Token created — copy it now, it will not be shown again.');
    }

    public function destroy(Request $request, ApiToken $apiToken)
    {
        abort_unless($apiToken->user_id === $request->user()->id, 403);
        $apiToken->delete();
        return redirect()->route('settings.index')->with('success', 'Token revoked.');
    }
}
