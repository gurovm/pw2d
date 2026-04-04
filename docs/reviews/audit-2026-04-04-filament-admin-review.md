# Review: Filament Admin Panel

**Date:** 2026-04-04
**Status:** Approved with comments

**Scope:** All Filament Resources (Product, Category, Feature, Preset, Brand, Store, Tenant, SearchLog, AiMatchingDecision), Pages (Settings, ProblemProducts), Widgets (ProductStatsWidget), and AdminPanelProvider.

---

## Critical Issues (must fix)

### C1. GeminiService called directly from EditCategory (violates AiService boundary)

**File:** `app/Filament/Resources/CategoryResource/Pages/EditCategory.php`, lines 43-50

The `callGeminiText()` helper instantiates `GeminiService` directly:

```php
$gemini = app(\App\Services\GeminiService::class);
$result = $gemini->generate($prompt, [...], config('services.gemini.admin_model'));
```

The project rules state: "All AI calls MUST go through AiService. Never call GeminiService directly from controllers, jobs, or Livewire components." The same applies to Filament pages. The `callGeminiImage()` method (line 61-117) makes a raw HTTP call to the Gemini API, bypassing both AiService and GeminiService entirely.

**Fix:** Add `generateCategoryContent()` and `generateCategoryImage()` domain methods to `AiService` and route these calls through it. This centralizes prompt management, model selection, error handling, and future logging.

### C2. Gemini API key exposed to browser in SearchLog Analyze Trends modal

**File:** `app/Filament/Resources/SearchLogResource/Pages/ListSearchLogs.php`, lines 43-55
**Template:** `resources/views/filament/pages/ai-report-modal.blade.php`, line 14

The API key is passed directly to a Blade view and embedded in client-side JavaScript:

```php
return [Placeholder::make('ai_client_interface')
    ->content(view('filament.pages.ai-report-modal', [
        'apiKey' => $apiKey
    ]))];
```

The JavaScript then calls the Gemini API directly from the browser with the key in the URL. Any admin user can inspect the DOM or network tab and extract the full Gemini API key.

**Fix:** Move the AI call server-side. Use a Livewire method or a Filament Action that calls `AiService` and streams the result back. The timeout concern can be addressed with a queued job and polling, or by increasing PHP timeout for this specific admin-only endpoint.

### C3. `is_higher_better` not persisted during AI feature generation

**File:** `app/Filament/Resources/CategoryResource/Pages/EditCategory.php`, lines 311-318 and 441-447

Both `generateFeaturesAction` and `generateAllAction` request `is_higher_better` in the AI prompt but never save it:

```php
Feature::firstOrCreate([
    'category_id' => $record->id,
    'name' => $featureData['name'],
], [
    'unit' => $featureData['unit'] ?? null,
    // Missing: 'is_higher_better' => $featureData['is_higher_better'] ?? true,
]);
```

All AI-generated features will default to `is_higher_better = true` (or whatever the DB default is), even when the AI correctly returns `false` for features like "Weight" or "Noise Level". This silently corrupts scoring normalization.

**Fix:** Add `'is_higher_better' => $featureData['is_higher_better'] ?? true` to the second argument of `firstOrCreate()` in both locations.

### C4. `Product::withoutGlobalScopes()` in Retry Failed leaks across tenants

**File:** `app/Filament/Resources/ProductResource/Pages/ListProducts.php`, lines 28 and 43

```php
$failedCount = Product::withoutGlobalScopes()->where('status', 'failed')->count();
```

This counts (and retries) failed products from ALL tenants, not just the currently selected one. An admin viewing Tenant A will see the failed count for all tenants combined, and clicking "Retry" will requeue products from every tenant.

**Fix:** Scope the query to the current tenant:

```php
$failedCount = Product::where('status', 'failed')->count();
// BelongsToTenant global scope handles tenant filtering automatically
```

If the intent was to show a cross-tenant count, that should be an explicit design decision with clear UI labeling.

---

## Suggestions (recommended improvements)

### S1. Category slug uniqueness validation not tenant-scoped in form

**File:** `app/Filament/Resources/CategoryResource.php`, line 51

```php
->unique(ignoreRecord: true)
```

The database has a composite unique index `(tenant_id, slug)`, but the Filament form validation uses a plain `unique` rule on slug alone. This means creating a category with the same slug in a different tenant will fail form validation even though the DB would allow it.

**Fix:** Use `->unique(table: 'categories', column: 'slug', ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', tenant('id')))` or rely on the `BelongsToTenant` scope by removing the unique rule (the DB constraint is the real guard).

The same applies to `StoreResource.php` line 36.

### S2. PresetResource feature dropdown fallback shows all features across tenants

**File:** `app/Filament/Resources/PresetResource.php`, line 45

```php
return \App\Models\Feature::pluck('name', 'id');
```

When no `category_id` is selected, the fallback shows all features (scoped to the current tenant by `BelongsToTenant`, which is acceptable). However, showing features from unrelated categories is confusing UX. Consider showing an empty list with helper text like "Select a category first" instead.

### S3. Dead SearchLog page classes that should not exist

**Files:**
- `app/Filament/Resources/SearchLogResource/Pages/CreateSearchLog.php`
- `app/Filament/Resources/SearchLogResource/Pages/EditSearchLog.php`

The `SearchLogResource` correctly disables `canCreate()` and `canEdit()`, but the corresponding page classes still exist. They are unreachable but add confusion. The `ViewSearchLog` page has a header action `EditAction::make()` which contradicts the `canEdit() => false` on the resource.

**Fix:** Delete `CreateSearchLog.php` and `EditSearchLog.php`. Remove the `EditAction` from `ViewSearchLog`'s header actions.

### S4. ProductStatsWidget runs 6 separate unscoped DB queries

**File:** `app/Filament/Widgets/ProductStatsWidget.php`, lines 17-22

Each stat runs an independent `Product::where(...)->count()` query plus a raw `DB::table('jobs')->count()`. These are not cached and fire on every dashboard page load.

Improvements:
- Combine into a single query using conditional aggregation: `SELECT COUNT(*) as total, SUM(CASE WHEN ...) as live, ...`.
- The `DB::table('jobs')->count()` is fine for admin visibility but should note that this is a global count (not tenant-scoped) since the `jobs` table is a Laravel system table.

### S5. ProblemProducts uses `whereRaw` with REGEXP -- MySQL-specific

**File:** `app/Filament/Pages/ProblemProducts.php`, lines 75 and 201

```php
->orWhereRaw('LOWER(name) REGEXP ?', [$regex])
```

This is MySQL-specific syntax. If tests run on SQLite (common in CI), this will fail. Not a production issue, but a testability concern.

### S6. ProductResource `best_price` column computes from already-loaded offers

**File:** `app/Filament/Resources/ProductResource.php`, line 157

```php
->getStateUsing(fn (Product $record) => $record->offers->min('scraped_price'))
```

This is correct since offers are eager-loaded on line 127. The sorting, however, uses `withMin('offers', 'scraped_price')` which adds a subquery. This is fine but slightly wasteful since the data is already loaded. Not a real performance issue at current scale.

### S7. Bulk "Mark as Ignored" in ProductResource updates records one-by-one

**File:** `app/Filament/Resources/ProductResource.php`, lines 248-252

```php
foreach ($records as $record) {
    $record->update(['is_ignored' => true, 'status' => null]);
}
```

The `ProblemProducts` page does this correctly with a single query:

```php
Product::whereIn('id', $records->pluck('id'))->update(['is_ignored' => true]);
```

**Fix:** Use the bulk query approach from ProblemProducts. Note the ProblemProducts version does not set `status => null` -- verify whether that is intentional.

### S8. FeaturesRelationManager has a no-op `mutateFormDataUsing`

**File:** `app/Filament/Resources/CategoryResource/RelationManagers/FeaturesRelationManager.php`, lines 65-68

```php
->mutateFormDataUsing(function (array $data): array {
    // Category is automatically set by the relation manager
    return $data;
}),
```

This does nothing. Remove the entire `mutateFormDataUsing` call.

### S9. Missing `$navigationGroup` on PresetResource

**File:** `app/Filament/Resources/PresetResource.php`

Unlike every other resource in the Product Management group, `PresetResource` has no `$navigationGroup`. It will appear as an ungrouped item in the sidebar.

**Fix:** Add `protected static ?string $navigationGroup = 'Product Management';` and an appropriate `$navigationSort`.

### S10. BrandResource and CategoryResource `DeleteBulkAction` without safeguards

Deleting a Brand or Category in bulk will cascade-delete or orphan associated Products. Consider adding a confirmation modal that shows the count of affected products, or disabling bulk delete for records with associated products.

### S11. Hardcoded Gemini model in AI report modal template

**File:** `resources/views/filament/pages/ai-report-modal.blade.php`, line 14

```javascript
fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=' + this.apiKey,
```

The model name `gemini-2.5-pro` is hardcoded in JavaScript. The rest of the codebase uses `config('services.gemini.admin_model')`. If the model name changes in `.env`, this template will still use the old one.

---

## Praise (what was done well)

1. **Excellent Filament-stancl tenancy bridge.** The `TenantSet` event listener in `AdminPanelProvider::boot()` correctly bridges Filament's tenant context to stancl's `tenancy()->initialize()`. The `TenantResource` correctly opts out of tenant scoping with `$isScopedToTenant = false`. The `User` model correctly implements `HasTenants` with cached tenant list.

2. **Authorization is properly gated.** `canAccessPanel()` restricts access to `@pw2d.com` emails. `canAccessTenant()` returns `true` which is correct for a single-admin-team model. The `UserPolicy` delegates back to `canAccessPanel()`.

3. **ProductResource table is well-optimized.** The `modifyQueryUsing` on line 125-128 uses explicit `select()` and targeted `with()` eager loading including only needed columns. The offers relation is loaded with just `id,product_id,scraped_price` -- exactly what the table needs.

4. **ProblemProducts page is a strong admin tool.** The keyword-based detection, multiple problem types, and combination of SQL and PHP detection logic is well-thought-out. The filter system with custom query closures is clean.

5. **OffersRelationManager correctly sets tenant_id.** The `mutateFormDataUsing` on the CreateAction (line 92-94) explicitly injects `tenant_id` -- this is the correct safety net pattern for relation managers where the tenant context might not auto-propagate.

6. **AI Generator actions on EditCategory are well-structured.** The decomposition into helper methods (`syncPresetsToRecord`, `saveSamplePrompts`, `savePriceTiers`) avoids duplication between Generate Presets and Generate All. The `ImageOptimizer::toWebp()` call after image generation ensures consistent image format. Toggle for "clear existing" before regeneration is a good UX choice.

7. **Clean read-only pattern on SearchLogResource and AiMatchingDecisionResource.** Properly disables create/edit where it makes no sense. The AI Matching QA table has clear delete semantics ("Clear cached decision").

8. **Dynamic branding in TenantResource.** The form correctly handles VirtualColumn-backed fields (brand_name, primary_color, etc.) and the `CreateTenant` page handles the SQLite rowid edge case.

---

## Summary of Action Items

| Priority | Item | File(s) |
|----------|------|---------|
| Critical | C1: Route AI calls through AiService | EditCategory.php |
| Critical | C2: Remove API key from client JS | ListSearchLogs.php, ai-report-modal.blade.php |
| Critical | C3: Persist `is_higher_better` from AI | EditCategory.php (2 locations) |
| Critical | C4: Remove `withoutGlobalScopes` from Retry Failed | ListProducts.php |
| Suggested | S1: Tenant-scope slug uniqueness validation | CategoryResource.php, StoreResource.php |
| Suggested | S3: Delete dead SearchLog page classes | CreateSearchLog.php, EditSearchLog.php |
| Suggested | S7: Use bulk update for Mark as Ignored | ProductResource.php |
| Suggested | S9: Add navigationGroup to PresetResource | PresetResource.php |
