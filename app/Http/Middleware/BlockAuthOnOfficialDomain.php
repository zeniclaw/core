<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BlockAuthOnOfficialDomain
{
    private const OFFICIAL_DOMAINS = ['zeniclaw.io', 'www.zeniclaw.io'];

    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->getHost(), self::OFFICIAL_DOMAINS)) {
            return redirect('/');
        }

        return $next($request);
    }

    public static function isOfficialDomain(): bool
    {
        return in_array(request()->getHost(), self::OFFICIAL_DOMAINS);
    }
}
