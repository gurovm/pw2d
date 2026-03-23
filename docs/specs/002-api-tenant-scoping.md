# Spec 002: API Routes Tenant Scoping

**Priority:** CRITICAL
**Audit refs:** Security #2 (unscoped batch-import), Security #3 (cross-tenant ASINs), Security #4 (all-tenant categories), Security #8 (missing tenant_id on SearchLog)

---

## Problem

All API routes in `routes/api.php` run outside the tenancy middleware. The `Category::findOrFail()` in `BatchImportController` and the `Category::withCount()` in `ProductImportController::categories()` are globally unscoped — any authenticated extension user can read/write data across all tenants.

`SearchLog::create()` in `GlobalSearch.php` (lines 219, 231) also omits `tenant_id`.

## Architecture Decision

API routes are used exclusively by the Chrome Extension, which operates in a **central admin context** (the operator scraping products for a specific tenant). Rather than moving API routes under domain-based tenancy (which would require the extension to target each tenant domain), we'll:

1. **Add a `tenant_id` parameter** to the API payload.
2. **Manually initialize tenancy** in a lightweight middleware so `BelongsToTenant` scoping kicks in.
3. **Scope all unscoped queries** that the audit flagged.

## Changes Required

### 1. New middleware: `InitializeTenancyFromPayload`

**File:** `app/Http/Middleware/InitializeTenancyFromPayload.php` (new)

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyFromPayload
{
    public function handle(Request $request, Closure $next)
    {
        $tenantId = $request->header('X-Tenant-Id')
            ?? $request->input('tenant_id');

        if (!$tenantId) {
            return response()->json(['error' => 'Tenant ID required.'], 422);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => 'Invalid tenant.'], 404);
        }

        tenancy()->initialize($tenant);

        return $next($request);
    }
}
```

### 2. Apply middleware to API routes

**File:** `routes/api.php`

```php
Route::middleware([
    'App\Http\Middleware\VerifyExtensionToken',
    'App\Http\Middleware\InitializeTenancyFromPayload',
    'throttle:60,1',
])->group(function () {
    Route::get('/categories', [ProductImportController::class, 'categories']);
    Route::get('/existing-asins', [ProductImportController::class, 'existingAsins']);
});

Route::middleware([
    'App\Http\Middleware\VerifyExtensionToken',
    'App\Http\Middleware\InitializeTenancyFromPayload',
    'throttle:30,1',
])->group(function () {
    Route::post('/product-import', [ProductImportController::class, 'import']);
    Route::post('/import-product', [ProductImportController::class, 'import']);
    Route::post('/products/batch-import', [BatchImportController::class, 'import']);
});
```

Once tenancy is initialized, `BelongsToTenant` on `Category`, `Product`, `Brand` etc. automatically scopes all Eloquent queries. No changes needed to the controller query logic itself.

### 3. Scope `SearchLog::create()` calls

**File:** `app/Livewire/GlobalSearch.php` — lines 219 and 231

Add `'tenant_id' => tenant('id')` to both `SearchLog::create()` calls. Alternatively, add `BelongsToTenant` trait to the `SearchLog` model (preferred — automatic scoping).

**File:** `app/Models/SearchLog.php`

```php
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class SearchLog extends Model
{
    use BelongsToTenant;
    // ... existing code
}
```

Ensure the `search_logs` table has a `tenant_id` column (check migration, add if missing).

### 4. Update Chrome Extension to send tenant context

**File:** `chrome_extension/popup.js`

Add a tenant selector dropdown in the popup (populated from a new `/api/tenants` endpoint or hardcoded for now). Send tenant ID in request headers:

```js
headers: {
    'X-Extension-Token': EXTENSION_TOKEN,
    'X-Tenant-Id': selectedTenantId,
}
```

## Files Modified/Created

| File | Action |
|------|--------|
| `app/Http/Middleware/InitializeTenancyFromPayload.php` | **Create** |
| `routes/api.php` | Add new middleware to route groups |
| `app/Models/SearchLog.php` | Add `BelongsToTenant` trait |
| `chrome_extension/popup.js` | Send `X-Tenant-Id` header |
| `chrome_extension/popup.html` | Add tenant selector dropdown |
| Migration (if needed) | Add `tenant_id` to `search_logs` |

## Testing

- **Feature:** POST `/api/products/batch-import` without `X-Tenant-Id` → 422.
- **Feature:** POST with valid tenant → products created with correct `tenant_id`.
- **Feature:** GET `/api/categories` with tenant A → only tenant A's categories returned.
- **Feature:** GET `/api/existing-asins` with tenant A → only tenant A's ASINs returned.
- **Unit:** `SearchLog` created via Livewire has correct `tenant_id`.
