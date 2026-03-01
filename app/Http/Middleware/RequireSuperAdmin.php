<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || $request->user()->role !== 'superadmin') {
            abort(403, 'Accès réservé aux super-admins.');
        }
        return $next($request);
    }
}
