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

- [x] **S8: Tenant-scope `exists:categories,id` validation** -- Added `Rule::exists()->where('tenant_id', tenant('id'))` in 3 files.

- [x] **S9: Guard SitemapController on central domain** -- Added `abort(404)` when tenancy not initialized.

- [x] **S10: Route EditCategory AI calls through AiService** -- Added `generateCategoryContent()` and `generateCategoryImage()` to AiService.

- [x] **S11: Add rate limit on AI search** -- 10 calls/min per session in ProductCompare and GlobalSearch.

- [x] **S12: Fix `strip_tags` allowing `javascript:` URIs** -- Added `preg_replace` to strip `javascript:` hrefs.

- [x] **P1: Add `offers.store` eager loading everywhere** -- Added to visibleProducts, selectedProduct, SimilarProducts.

- [x] **P2: Debounce price slider** -- Changed to `wire:model.live.debounce.300ms`.

- [ ] **P3: Wrap batch import in transaction + bulk insert** -- 200+ individual INSERTs per batch. Use `insert()` inside `DB::transaction()`. *[Perf-API]*

- [x] **P4: Add HTTP retry for Gemini 429** -- 3 attempts with exponential backoff, 429-only.

- [x] **P5: Narrow negative matching decision purge** -- Scoped to same-brand only.

- [x] **P6: Cache ProblemProducts badge query** -- 120s TTL with tenant-scoped key.

- [x] **P7: Cache ProductStatsWidget queries** -- 60s TTL, consolidated 6 queries into 1 cached block.

- [x] **P8: Fix null-price offers surfacing as "best"** -- Added null filter before sort.

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

## Spec Tasks

- [x] **Spec 011: Problem Products store link & rescan action** -- Added Store badge column, changed product name to edit-page link, added Rescan Price row action. Extracted `scrapeOfferPrice()` as public static on SyncOfferPrices. Removed amazon_rating column.

- [x] **Spec 012: Fix AI matching brand dedup** -- Added `AiService::normalizeBrandForComparison()` (public static). Fixed heuristic query in `matchProduct()` to fuzzy-match brands via SQL REPLACE chain. Fixed `ProcessPendingProduct` to reuse existing brand by fuzzy match instead of `firstOrCreate`. Fixed negative cache invalidation to cover all brand spelling variants. Tightened brand normalization prompt. 14 new tests in `BrandNormalizationTest`.

- [x] **Spec 013: Enhance MergeDuplicateProducts command** -- Added `--category` option, Phase 2 brand-spelling dedup via `normalizeBrandForComparison()`, feature value transfer in `mergeDuplicate()`, `price_tier` recalculation after merges, and improved two-phase console output.

- [ ] **Chrome extension: fix Amazon reviews_count extraction** -- 88 Amazon products ended up with `amazon_reviews_count=0`, likely from an older extension version or Amazon layouts not covered by the current 5-strategy `extractReviewsCount()` in `chrome_extension/content.js:126`. Action items: (a) find a specific product URL where the extension currently returns 0 reviews, (b) inspect DOM to find the correct selector, (c) add as 6th strategy. Also fix `BatchImportController.php` lines 80/100 and `ProductImportController.php` lines 100/115 to store `null` instead of `0` when scraper sends missing reviews_count — so we can distinguish "missing data" from "zero reviews". *[Extension, API]*

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
