<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initializes tenancy context from the API request payload.
 *
 * Used on Chrome Extension API routes that operate in a central admin context
 * rather than under domain-based tenancy. Reads tenant ID from the X-Tenant-Id
 * header (preferred) or the tenant_id request parameter (fallback).
 */
class InitializeTenancyFromPayload
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant-Id')
            ?? $request->input('tenant_id');

        if (! $tenantId) {
            return response()->json(['error' => 'Tenant ID required.'], 422);
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return response()->json(['error' => 'Invalid tenant.'], 404);
        }

        tenancy()->initialize($tenant);

        return $next($request);
    }
}
