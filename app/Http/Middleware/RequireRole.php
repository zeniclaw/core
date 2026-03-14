<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Role-based access control middleware (D7.4).
 * Usage: Route::middleware('role:admin,superadmin')
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Authentication required.');
        }

        $userRole = $user->role ?? 'user';

        // Superadmin always has access
        if ($userRole === 'superadmin') {
            return $next($request);
        }

        if (!in_array($userRole, $roles)) {
            abort(403, 'Accès non autorisé pour votre rôle.');
        }

        return $next($request);
    }
}
