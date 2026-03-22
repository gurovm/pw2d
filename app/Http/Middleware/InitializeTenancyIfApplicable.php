<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

class InitializeTenancyIfApplicable
{
    public function handle(Request $request, Closure $next)
    {
        $hostname = $request->getHost();
        $centralDomains = config('tenancy.central_domains', []);

        // Central domains skip tenancy initialization entirely
        if (in_array($hostname, $centralDomains)) {
            return $next($request);
        }

        // Tenant domain — resolve by hostname and initialize
        try {
            $tenant = app(DomainTenantResolver::class)->resolve($hostname);
            tenancy()->initialize($tenant);
        } catch (\Exception) {
            abort(404);
        }

        return $next($request);
    }
}
