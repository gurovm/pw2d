<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Tenant directory for the Chrome extension's settings panel.
 *
 * Deliberately NOT tenant-scoped. The extension calls this to DISCOVER the ids
 * it will subsequently send in `X-Tenant-Id`, so by definition it runs before
 * any tenancy context exists — putting it behind InitializeTenancyFromPayload
 * would 422 ("Tenant ID required.") on exactly the request that needs to work.
 * See the middleware note in routes/api.php.
 *
 * Security: this exposes the full tenant id list to any holder of the extension
 * token. That token already grants read/write on ANY tenant by simply setting
 * the `X-Tenant-Id` header, so this discloses no authority the token lacked —
 * it only saves the holder from guessing ids. Nothing beyond id + display name
 * is returned; the `data` JSON column (branding, settings) is not exposed.
 */
class TenantListController extends Controller
{
    public function index(): JsonResponse
    {
        // Order by the same string that is displayed, so the select box the
        // extension renders is actually in the order this endpoint promises.
        $tenants = Tenant::query()
            ->orderByRaw("COALESCE(NULLIF(name, ''), id)")
            ->get()
            ->map(function (Tenant $tenant): array {
                $id = (string) $tenant->getTenantKey();

                return [
                    'id'   => $id,
                    'name' => filled($tenant->name) ? (string) $tenant->name : $id,
                ];
            })
            ->values();

        return response()->json(['success' => true, 'tenants' => $tenants]);
    }
}
