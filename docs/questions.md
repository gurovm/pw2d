# Questions / Findings from Test Engineering

## Bug: BatchImportController uses MySQL-only SUBSTRING_INDEX

**File:** `app/Http/Controllers/Api/BatchImportController.php` (line 43)

**Issue:** The duplicate-ASIN detection query uses `SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/dp/', -1), '?', 1)`, which is a MySQL-specific SQL function. This works correctly in production (MySQL) but will throw `SQLSTATE[HY000]: General error: 1 no such function: SUBSTRING_INDEX` in any SQLite environment (local dev with non-MySQL DB, CI/CD test databases).

The function is not available in SQLite, PostgreSQL, or other common development databases. This also means 7 of the BatchImportController tests are automatically skipped on SQLite and only run on MySQL.

**Recommendation:** Replace the `SUBSTRING_INDEX` approach with PHP-level ASIN extraction after a simpler `LIKE '%/dp/%'` query, or use a stored URL format that makes the ASIN directly queryable (e.g. store the ASIN as a separate column on `product_offers`).

---

## Note: Tenant model GeneratesIds incompatibility with string PKs in SQLite

**File:** `vendor/stancl/tenancy/src/Database/Concerns/GeneratesIds.php`

**Issue:** Because `config('tenancy.id_generator')` is `null`, `GeneratesIds::getIncrementing()` returns `true`. This causes Eloquent to call `lastInsertId()` after any `Tenant::create()` call and overwrite the string PK (e.g. `'best-mics'`) with the integer SQLite rowid (`1`). The result is that `$tenant->id` after creation equals `1`, not `'best-mics'`.

This affects any test that calls `tenancy()->initialize($tenant)` after `Tenant::create()` — `tenant()->getTenantKey()` returns `1`, not `'best-mics'`, causing FK violations when BelongsToTenant models are created.

**Workaround used in tests:** Use `app()->instance(TenantContract::class, $mock)` with a mock tenant object (same technique as `TenantCacheKeyTest`) instead of calling `tenancy()->initialize()` directly.

**Note:** This does not affect production (MySQL uses string PKs without auto-increment interference) or existing HTTP-level tests (where tenant ID lookup goes through the DB, not through the in-memory model).
