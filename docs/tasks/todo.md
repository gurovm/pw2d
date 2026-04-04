# Tasks: Full System Audit (2026-04-04)

Consolidated from 15 parallel agent audits (5 chunks x 3 agents). Deduplicated across reports.

## P0 -- Fix Immediately (Security + Data Integrity)

- [x] **S1: Remove Gemini API key from client-side JS** -- Moved to `AiService::analyzeSearchTrends()`. Deleted `ai-report-modal.blade.php`. Added 3 tests.

- [x] **S2: Fix cross-tenant MergeDuplicateProducts** -- Added `{tenant}` argument + `tenancy()->initialize()` + `tenant_id` in GROUP BY. Updated tests.

- [x] **S3: Scope "Retry Failed" to current tenant** -- Removed `withoutGlobalScopes()` from ListProducts.php lines 28/43.

- [x] **S4: Fix XSS via `->toJson()` in Alpine.js** -- Replaced with `@js()` in product-compare.blade.php.

- [x] **S5: Fix GeminiService `parts[0]` thinking bug** -- Now iterates parts, takes last non-`thought` part.

- [x] **S6: Fix broken observers** -- Updated to use actual columns + explicit `tenant_id` + idempotency guard.

- [x] **S7: Persist `is_higher_better` in AI feature generation** -- Added to both `Feature::firstOrCreate()` calls in EditCategory.php.

## P1 -- Fix Before Next Deploy (Security + Performance)

- [ ] **S8: Tenant-scope `exists:categories,id` validation** -- Bypasses Eloquent scope (3 locations). Add `->where('tenant_id', tenant('id'))` to `Rule::exists()`. *[Security-API, Security-Models]*

- [ ] **S9: Guard SitemapController on central domain** -- Leaks all tenants' data when tenancy not initialized. Add `if (!tenancy()->initialized) abort(404)`. *[Security-API]*

- [ ] **S10: Route EditCategory AI calls through AiService** -- Calls `GeminiService` directly + raw HTTP. Bypasses mandatory AiService layer. *[Review-Filament, Security-Filament]*

- [ ] **S11: Add rate limit on AI search** -- `analyzeUserNeeds()` and `triggerAiSearch()` have no rate limiting. Anonymous users can exhaust Gemini quota. *[Security-Frontend]*

- [ ] **S12: Fix `strip_tags` allowing `javascript:` URIs** -- Buying guide `strip_tags($html, '<a>')` permits `<a href="javascript:...">`. Sanitize href attribute. *[Security-Frontend, Review-Filament]*

- [ ] **P1: Add `offers.store` eager loading everywhere** -- Missing in `visibleProducts`, `selectedProduct`, `SimilarProducts`. ~36-48 extra queries per page render. *[Perf-Models, Perf-Frontend, Review-Models, Review-Frontend]*

- [ ] **P2: Debounce price slider** -- `wire:model.live` fires 20-50 requests per drag. Use `wire:model.live.debounce.300ms`. *[Perf-Frontend]*

- [ ] **P3: Wrap batch import in transaction + bulk insert** -- 200+ individual INSERTs per batch. Use `insert()` inside `DB::transaction()`. *[Perf-API]*

- [ ] **P4: Add HTTP retry for Gemini 429** -- Currently throws immediately, wasting job retries. Add `Http::retry(3, ...)` with 429-only when clause. *[Perf-AI]*

- [ ] **P5: Narrow negative matching decision purge** -- Deletes ALL negative decisions per tenant on every product. Scope to same-brand only. *[Perf-AI, Review-AI]*

- [ ] **P6: Cache ProblemProducts badge query** -- Heavy REGEXP query fires on every admin page render. Add `Cache::remember()` 120s. *[Perf-Filament]*

- [ ] **P7: Cache ProductStatsWidget queries** -- 6 uncached COUNT queries on every dashboard render. Consolidate + cache 60s. *[Perf-Filament]*

- [ ] **P8: Fix null-price offers surfacing as "best"** -- `bestOffer` accessor doesn't filter `scraped_price = null`. *[Review-Models]*

## P2 -- Fix Soon (Code Quality + Medium Security)

- [ ] **Q1: Fix OfferIngestionService unique constraint** -- `ProductOffer::create()` without checking `(product_id, store_id)` uniqueness. Use `updateOrCreate`. *[Review-API]*

- [ ] **Q2: Create OfferIngestionRequest Form Request** -- Inline `$request->validate()` in controller. Extract to Form Request. *[Review-API]*

- [ ] **Q3: Extract BatchImportService** -- 115 lines of business logic in controller. *[Review-API]*

- [ ] **Q4: Add `BelongsToTenant` to AiCategoryRejection + ProductFeatureValue** -- Missing tenant trait. *[Security-Models]*

- [ ] **Q5: Fix ASIN validation** -- Accepts arbitrary strings (`string|max:20`). Add `regex:/^[A-Z0-9]{10}$/i`. *[Security-API]*

- [ ] **Q6: Validate URL schemes as HTTPS** -- `url` and `image_url` accept `data:`, `file:`. Use `url:https`. *[Security-API]*

- [ ] **Q7: Add `tenant_id` to observer Feature creation** -- Features created without explicit `tenant_id`. *[Review-Models, Security-Models]*

- [ ] **Q8: Fix `addslashes()` JS escaping** -- Multiple templates use `addslashes()` instead of `@js()`. *[Security-Frontend]*

- [ ] **Q9: Remove fabricated reviewCount in SeoSchema** -- Falls back to `reviewCount: 50`. Violates Google guidelines. *[Review-Frontend]*

- [ ] **Q10: Replace hardcoded Amazon orange** -- `bg-[#FF9900]` on CTA buttons. Use `var(--color-primary)`. *[Review-Frontend]*

- [ ] **Q11: Use bulk update for Mark as Ignored** -- Loops individual `$record->update()`. Use `whereIn()->update()`. *[Review-Filament]*

- [ ] **Q12: Add `url_hash` column to product_offers** -- TEXT column can't be indexed for equality. Add CHAR(64) hash. *[Perf-API]*

- [ ] **Q13: Fix RecalculatePriceTiers memory** -- Loads all products+offers into memory. Use `chunkById()`. *[Perf-AI]*

- [ ] **Q14: Add CDN Subresource Integrity** -- `@formkit/auto-animate` loaded without SRI. *[Security-Frontend]*

## P3 -- Low Priority (Polish)

- [ ] **L1: Add N+1 eager loading in Filament resources** -- AiMatchingDecisionResource, CategoryResource, FeatureValuesRelationManager. *[Perf-Filament]*
- [ ] **L2: Extract duplicated feature-score parsing** -- Identical in ProcessPendingProduct and RescanProductFeatures. *[Review-AI]*
- [ ] **L3: Extract duplicated price-note builder** -- Same block in both jobs. *[Review-AI]*
- [ ] **L4: Extract typewriter animation** -- Copy-pasted Alpine.js in 2 templates. *[Review-Frontend]*
- [ ] **L5: Remove DB query from ComparisonHeader Blade** -- `Category::find()` in template. *[Review-Frontend]*
- [ ] **L6: Make static pages tenant-aware** -- Hardcoded "Pw2D" in about/privacy/terms. *[Review-Frontend]*
- [ ] **L7: Add missing `declare(strict_types=1)`** -- ~15 files. *[Multiple]*
- [ ] **L8: Add 6 missing database indexes** -- SQL in performance reports. *[Perf-Models, Perf-API]*
- [ ] **L9: Delete dead SearchLog page classes** -- CreateSearchLog.php, EditSearchLog.php. *[Review-Filament]*
- [ ] **L10: Delete `welcome.blade.php`** -- Dead Laravel scaffold. *[Review-Frontend]*
- [ ] **L11: Tenant-scope slug uniqueness in Filament** -- CategoryResource, StoreResource. *[Review-Filament]*
- [ ] **L12: Setting::get() default value caching bug** -- First caller's default cached forever. *[Perf-Models]*

---

## Completed (2026-03-22 audit)

All 17 tasks from the March 22 code quality review are complete. See git history.
