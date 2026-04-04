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

        if (in_array($hostname, $centralDomains)) {
            // Filament admin handles its own tenancy — don't interfere
            if ($request->is('admin', 'admin/*')) {
                return $next($request);
            }

            // Central domain with a matching tenant → initialize for frontend routes
            try {
                $tenant = app(DomainTenantResolver::class)->resolve($hostname);
                tenancy()->initialize($tenant);
            } catch (\Exception) {
                // No tenant for this central domain — continue without tenancy
                return $next($request);
            }

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
