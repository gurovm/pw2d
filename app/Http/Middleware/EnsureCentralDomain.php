<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCentralDomain
{
    public function handle(Request $request, Closure $next)
    {
        $hostname = $request->getHost();
        $centralDomains = config('tenancy.central_domains', []);

        if (!in_array($hostname, $centralDomains)) {
            abort(404);
        }

        return $next($request);
    }
}
