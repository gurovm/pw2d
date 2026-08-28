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

- [x] **Q1: Fix OfferIngestionService unique constraint** -- `ProductOffer::create()` without checking `(product_id, store_id)` uniqueness. Use `updateOrCreate`. Fixed as part of Spec 029 Phase A (T1) — every raw `ProductOffer::create()` across `OfferIngestionService`, `BatchImportController`, and `ProductImportController` replaced with `updateOrCreate` keyed on `(product_id, store_id)`. *[Review-API]*

- [ ] **Q2: Create OfferIngestionRequest Form Request** -- Inline `$request->validate()` in controller. Extract to Form Request. *[Review-API]*

- [ ] **Q3: Extract BatchImportService** -- 115 lines of business logic in controller. *[Review-API]*

- [ ] **Q4: Add `BelongsToTenant` to AiCategoryRejection + ProductFeatureValue** -- Missing tenant trait. *[Security-Models]*

- [ ] **Q5: Fix ASIN validation** -- Accepts arbitrary strings (`string|max:20`). Add `regex:/^[A-Z0-9]{10}$/i`. *[Security-API]*

- [ ] **Q6: Validate URL schemes as HTTPS** -- `url` and `image_url` accept `data:`, `file:`. Use `url:https`. *[Security-API]*

- [ ] **Q7: Add `tenant_id` to observer Feature creation** -- Features created without explicit `tenant_id`. *[Review-Models, Security-Models]*

- [ ] **Q8: Fix `addslashes()` JS escaping** -- Multiple templates use `addslashes()` instead of `@js()`. *[Security-Frontend]*

- [ ] **Q9: Remove fabricated reviewCount in SeoSchema** -- Falls back to `reviewCount: 50`. Violates Google guidelines. *[Review-Frontend]*

- [ ] **Q10: Replace hardcoded Amazon orange** -- `bg-[#FF9900]` on CTA buttons. Use `var(--color-primary)`. *[Review-Frontend]*

- [x] **Q11: Use bulk update for Mark as Ignored** -- WONTFIX (Spec 036 §3, 2026-08-21). Per-record saves are
  load-bearing: `ProductObserver` is the freshness-audit trigger for `is_ignored` flips, and `Builder::update()`
  fires no Eloquent events. This exact advice is what produced the `ProblemProducts.php:347` bug (H-B,
  audit-2026-08-21) — applying it to `ProductResource.php:262-266` would break that site too. *[Review-Filament]*

- [ ] **Q12: Add `url_hash` column to product_offers** -- TEXT column can't be indexed for equality. Add CHAR(64) hash. *[Perf-API]*

- [x] **Q13: Fix RecalculatePriceTiers memory** -- Loads all products+offers into memory. Use `chunkById()`. Fixed as part of Spec 029 Phase A (T1): extracted `App\Services\PriceTierRecalculator::recalculateForCategory()` using `Product::where(...)->chunkById(200, ...)`, shared by the `products:recalculate-tiers` command and the new `RecalculateCategoryPriceTiers` job. *[Perf-AI]*

- [ ] **Q14: Add CDN Subresource Integrity** -- `@formkit/auto-animate` loaded without SRI. *[Security-Frontend]*

## Spec Tasks

- [x] **Spec 015: SEO brand-bleed fix (Phase 1)** -- Threaded `tenant('brand_name')` + 4 new `seo_*` JSON keys through SeoSchema + layout. Added `SeoSchema::forHomepage()`. Wired `Home.php` meta via `layoutData()`. Replaced static `public/robots.txt` with tenant-aware route emitting per-host Sitemap directive. Added static pages to sitemap. Cached sitemap XML for 10 min per tenant. Added SEO form section to TenantResource. Made `SeoSchema::forSelectedProduct()` public so `ProductCompare::openProduct()` reuses it (eliminated `| pw2d` brand bleed in JS dispatch event). `tenant_seo()` helper handles empty-string fallbacks via `filled()`. Renamed two `pw2d_*` JS namespace identifiers to `app_*` in `comparison-header.blade.php`. SeoDefaultsSeeder + 30 new Pest tests. See `docs/specs/015-seo-brand-bleed-fix.md` and `docs/seo/audit-2026-04-08.md`.

- [~] **Spec 014: SEO monitoring integration (Phase 2a)** -- Code complete on `feat/seo-phase-2a-monitoring`, 21 new Pest tests passing. Migration + SeoMetric model + factory. config/seo.php. GoogleSearchConsoleService + GoogleAnalyticsService (makeClient() injectable for tests). PullGscMetrics + PullGa4Metrics actions (upsert via unique constraint). PullSeoMetrics orchestrator (tenancy init/end in finally). PullSeoMetricsCommand (pw2d:seo:pull, --date=yesterday|today|YYYY-MM-DD). Scheduled at 03:00 nightly. TenantResource SEO section extended with gsc_site_url, ga4_property_id, seo_enabled toggle. tenant_seo_enabled() helper for safe bool cast. SeoDashboard Filament page + 5 widgets (KpiCards, TopMovers, UrlCoverage, QueryExplorer, PageTypeBreakdown). All widget queries use explicit tenant_id. Fixture JSON files + Pest tests for all actions/commands/dashboard. top_query left null (F7 follow-up). Pending: reviewer/security/perf specialist passes, PR merge, deploy, operational setup (service account JSON on prod, tenant SEO fields filled in via Filament). See `docs/specs/014-seo-monitoring-integration.md`.

### Reviewer follow-ups from spec 015 (file as small tickets)

- [ ] **F1: Sitemap cache invalidation observer** -- `Cache::remember(tenant_cache_key('sitemap:xml'))` lives 10 min with no `Cache::forget()` on Product/Category save. Add a `ProductObserver` / `CategoryObserver` that forgets the key on save/delete so editors don't see stale URLs after publishing. *[Models, Cache]*

- [ ] **F2: Sitemap chunking / sitemap-index for large tenants** -- Current `Product::cursor() → ::get()` change loads all products into memory before render. Fine for ~1k–5k URLs. For tenants growing past 5k, split into `sitemap-categories.xml` / `sitemap-products.xml` / `sitemap-presets.xml` under a `sitemap.xml` index file (Google standard). *[Perf-Frontend]*

- [ ] **F3: Extract `SitemapBuilder` service** -- `SitemapController::buildSitemapXml()` is now ~25 lines of query+map+filter inside the controller, borderline-violating standards.md "no business logic in controllers". Move to `app/Services/Seo/SitemapBuilder.php` for unit testability and separation of concerns. *[Review-API]*

- [ ] **F4: `Tests\Concerns\InitializesTestTenant` trait** -- The 4-line `Tenant::create() → find()` workaround appears verbatim in 4 SEO test files. DRY into a trait or `tests/Pest.php` helper. The duplicated comment block is the smell. *[Test]*

- [~] **F5: Upstream stancl/tenancy bug report** -- Root cause isolated: `Stancl\Tenancy\Database\Concerns\GeneratesIds::getIncrementing()` unconditionally overrides the subclass `$incrementing` property, returning `!app()->bound(UniqueIdentifierGenerator::class)` — which is `true` when `config('tenancy.id_generator') === null`. Filing-ready bug report at `docs/bug-reports/stancl-tenancy-pk-leak.md` with minimal reproduction. Lesson documented in `docs/lessons.md`. **TODO:** Michael to file at https://github.com/archtechx/tenancy/issues/new and update `docs/lessons.md` with the issue URL. *[Vendor]*

- [ ] **F6: `route('home')` decoupling in `SeoSchema::forHomepage()`** -- Currently calls `route('home')` which depends on an active HTTP request context. If the method is ever called from a queue/console job (e.g., to pre-warm the sitemap cache), it'll crash. Pass the URL in as a parameter or use `url('/')`. Minor. *[Review-Support]*

### Follow-ups from spec 014 (Phase 2a build)

- [ ] **F7: GSC per-URL top_query lookup** -- Current `GoogleSearchConsoleService::fetchUrlMetrics()` leaves `gsc_top_query` null for all rows. Builder's judgment call to avoid ballooning API calls. Add a secondary grouped-by-[page,query] query with row_limit=1 per page, or a single grouped-by-[page,query] query that we post-process in PHP to pick the top query per URL. *[Seo, API]*

- [ ] **F8: `TopMoversWidget` as proper paginated Table widget** -- Currently implemented as a `StatsOverviewWidget` because Filament 3's Table widget expects an Eloquent Builder, and raw DB subquery joins don't map cleanly. Research the right pattern (maybe a `Model`-backed raw query, or construct rows in `getTableQuery()` via subquery). Spec 014 asked for a sortable paginated table with columns: URL, position 7d ago, position now, delta. *[Filament, Frontend]*

- [ ] **F9: `PageTypeBreakdownWidget` as proper Table widget** -- Same constraint as F8. Currently a StatsOverview. Spec asked for a table with columns: bucket, URL count, total impressions 28d, total clicks 28d, avg position. *[Filament, Frontend]*

- [ ] **F10: `QueryExplorerWidget` free-text URL filter** -- Currently uses a Filament built-in dropdown with 5 fixed page-type prefixes. Spec asked for arbitrary URL prefix filtering. Replace dropdown with a Livewire text input that drives the chart data. *[Filament, Frontend]*

- [ ] **F11: `UrlCoverageWidget` duplicates `SitemapController::buildSitemapXml()` query logic** -- Both generate the "all known URLs for this tenant" list independently. Should share a `SitemapBuilder` service. Already partially tracked as F3 (extract SitemapBuilder) — this is the same refactor hitting a second caller. *[Refactor, Services]*

- [ ] **F12: `ProblemProducts::getNavigationBadge()` uses raw `REGEXP` SQL** -- Blocks any Filament admin HTTP test against sqlite (no REGEXP function). Discovered while writing `SeoDashboardTest::test_admin_can_access_seo_dashboard` — had to skip that test. Port `app/Filament/Pages/ProblemProducts.php:86,227` and helpers to portable `LIKE`-chain queries that work on both MySQL and sqlite. Once fixed, un-skip the two tests in `tests/Feature/Seo/SeoDashboardTest.php`. Pre-existing issue, not introduced by spec 014 — but spec 014's test suite was the first to expose it. *[Filament, Portability]*

### Follow-ups from spec 014 reviewer/security/perf pass

- [ ] **F13: `SeoDashboard` missing `view_seo_dashboard` gate** -- Spec 014 §"Filament Dashboard" required a `view_seo_dashboard` gate for access control. Not wired — zero existing Filament pages in the project use gates, so adding one requires establishing gate infrastructure (Gate::define in AuthServiceProvider + permission seeding + role→permission mapping). Out of scope for the spec 014 build. Current state: any authenticated admin can access `/admin/seo`, matching every other admin page in the project. Either build the gate infrastructure system-wide (covers all admin pages), or edit spec 014 to drop the requirement. *[Filament, Auth]*

- [x] **F14: `SeoMetric::$guarded = []` is vestigial** -- Resolved in Spec 016 audit follow-up (2026-05-14). Rewrote model with explicit `$fillable`, `$casts` for decimal precision (`decimal:4` for ctr/bounce, `decimal:2` for position), and `metric_date => 'date:Y-m-d'` (load-bearing for whereBetween).

- [ ] **F15: GSC/GA4 timezone mismatch** -- `GoogleSearchConsoleService` passes `CarbonImmutable::yesterday('UTC')` to both APIs. GSC operates in UTC (correct). GA4 operates in the **property's** configured timezone. For a GA4 property set to US/Pacific, "yesterday UTC" can off-by-one the first 8 hours of the day. Fix: look up the GA4 property's timezone via the Data API and normalize, OR document that the property must be set to UTC, OR accept the edge case for pw2d (single property, admin-controlled). *[Review-Seo]*

- [ ] **F16: Unused imports in widgets** -- `app/Filament/Widgets/Seo/PageTypeBreakdownWidget.php:7` imports `TextColumn`, `TopMoversWidget.php:9` imports `Collection`, `PullSeoMetricsResult.php` imports `Collection`. None are used. Trivial cleanup. *[Review]*

- [ ] **F17: `SeoDashboardTest` cross-tenant tests don't exercise widget code paths** -- Raised by security agent as M1 and reviewer as N8. The three "isolation" tests in `tests/Feature/Seo/SeoDashboardTest.php` lines 71-223 re-implement the widget's SQL inline and assert that reimplementation is tenant-scoped. Proves nothing about `KpiCardsWidget::getStats()` etc. Fix: use `Livewire::test(WidgetClass::class)` to render widgets in isolation (bypasses the Filament admin layout and F12's REGEXP blocker), OR expose `getStats()` via reflection from a test helper. *[Test, Security]*

- [ ] **F18: `GA4 fixture is a plausible synthetic shape, not a real API capture** -- `tests/Fixtures/Seo/ga4-sample-response.json` uses short `dimensions`/`metrics` keys instead of the real `dimensionValues[{value:...}]` / `metricValues[{value:...}]` shape GA4 returns. Works today because `GoogleAnalyticsService::fetchLandingPageMetrics()` reads via a flexible array fallback. Rename to `ga4-sample-response-synthetic.json` or add a header comment so future devs don't copy it as a real-shape reference. *[Test, Docs]*

- [~] **F19: Nightly cron should pull a GSC backfill window, not just yesterday** -- Spec'd in `docs/specs/016-seo-health-pass.md`. Implementation pending. *[Scheduler, Seo]*

- [ ] **F20: Test coverage gap — service classes' live API paths are untested** -- `GoogleSearchConsoleService::makeClient()` and `GoogleAnalyticsService::makeClient()` + their `fetchUrlMetrics()` / `fetchLandingPageMetrics()` methods have zero test coverage of their actual Google SDK interaction. The existing tests mock the entire service via container binding, so any bug in the Google-SDK-facing code (wrong namespace, wrong method signature, missing field, API shape drift) goes completely uncaught until hitting real production. The GA4 client namespace bug discovered on 2026-04-11 is exactly this class of bug. Fix: add a lightweight smoke-test command like `pw2d:seo:test-connection {tenant}` that actually hits both APIs and reports success/failure — used manually after any SDK upgrade or first-time tenant setup. OR add VCR-style recorded-response integration tests. (Note: F21's `pw2d:seo:status` is a partial mitigation — surfaces stale/missing data — but does not exercise the SDK code paths.) *[Test, Seo]*

- [ ] **F21: Ship `pw2d:seo:status` diagnostic command** -- Spec'd in `docs/specs/016-seo-health-pass.md`. Read-only artisan command printing per-tenant per-source health (HEALTHY / STALE / NO_DATA / UNCONFIGURED) with non-zero exit code on issues. Replaces the "open dashboard and squint at counts" current diagnostic. *[Seo, Tooling]*

- [ ] **F22: SEO operations runbook (`docs/seo/operations.md`)** -- Spec'd in `docs/specs/016-seo-health-pass.md` §6. On-call runbook: dashboard tour, status command interpretation, manual backfill commands, common failure modes, tenant onboarding checklist. *[Docs, Seo]*

### Follow-ups from spec 016 audit (2026-05-14)

- [x] **F23: GSC single-call range request** -- Shipped in Spec 017 (2026-05-14). `GoogleSearchConsoleService::fetchUrlMetricsForRange()` now pulls the whole window in one paginated API call; `PullGscMetrics::execute()` signature changed to take an array of dates and uses the ranged service method. GSC is now atomic per window (lost per-date error isolation — documented trade-off).

- [x] **F24: `SeoStatusCommand` SQL optimizations** -- Shipped in Spec 017 (2026-05-14). (a) `whereIn` now only applied when a tenant arg is supplied. (b) Refactored to two-subquery LEFT JOIN via `DB::query()->fromSub($leftSub, 'a')->leftJoinSub($rightSub, 'b', ...)`. Initial `DB::table(DB::raw())->mergeBindings()` attempt had a binding-order bug surfaced by tests — fixed by switching to `fromSub`.

- [x] **F25: `pw2d:seo:pull` exit code rule** -- Shipped in Spec 017 (2026-05-14). Exit code now reflects errors only: any `result->hasErrors()` → FAILURE, otherwise SUCCESS regardless of upsert counts. Empty `tenants` collection still returns FAILURE (config problem).

- [x] **F26: Document the system cron hook requirement in deployment + runbook** -- Shipped in Spec 017 (2026-05-14).

### Follow-ups from spec 018 (SEO content audit on 2026-06-05)

After 3 weeks of cron data: 1,226 products in DB → only 85 (7%) had GSC impressions. 276 impressions / 28d / 0 clicks across the whole site. Spec 018 addresses 3 P0 schema bugs (relative product image URL, missing Offer block, compare-page meta = buying-guide dump). These remain open:

- [x] **F27: BreadcrumbList schema on product + compare pages** -- Shipped in Spec 020 (2026-06-05). New `SeoSchema::buildBreadcrumbList()` helper emits Home → (Parent) → Category → (Product) chain on both `forSelectedProduct` and `forLeafCategory`. Uses `url('/')` for home (not `route('home')`).
- [x] **F28: Better product page title pattern** -- Shipped in Spec 020 (2026-06-05). `forSelectedProduct` title now `"{name} {category} — AI Review & Match Score"` with em-dash separator; falls back to `"{name} — AI Review & Match Score"` when category is null. No tenant_suffix appended (length budget).

- [ ] **F29: Duplicate product variant cleanup** -- ItemList on `/compare/podcast-studio-mics` shows Shure SM58, SM58-LC, SM58 (2-Pack), SM58S, multiple SM58-LC ASINs — all separate Product rows that Google likely treats as near-duplicates. The AI Bouncer (Spec 012) was supposed to match these via fuzzy brand+name; clearly missed. Action: (a) run `pw2d:merge-duplicates --category=podcast-studio-mics --dry-run` to enumerate; (b) tighten `AiService::matchProduct()` prompt to merge size-variant + pack-variant SKUs more aggressively; (c) maybe surface a Filament "review duplicates" page. *[Seo, AI]*

- [x] **F30: F7 follow-up — implement per-URL top_query** -- Shipped in Spec 022 (2026-06-06). New `GoogleSearchConsoleService::fetchTopQueriesForRange()` makes a separate ranged call with `['date', 'page', 'query']` dimensions and post-processes to pick the max-impressions query per (date, url). `PullGscMetrics::execute()` merges the lookup map into the upsert batch with its own try/catch so a top-query API failure logs a warning but doesn't block the main pull. GSC API calls per tenant per night = 2 (was 1 post-F23) — growth-trigger only at >100 tenants.

- [~] **F31: Compare-page weight (167 KB) — Core Web Vitals** -- **Spec'd as Spec 024 (2026-06-19), sequenced fast-follow to Spec 023.** Note from code review: `visibleProducts()` already caps at `displayLimit=12`, images already lazy — the lever is cutting the *initial* server-rendered card count (12→6) with on-scroll hydration. *[Frontend, Perf]*

- [ ] **F32: Compare-page `seo_description` field** -- Spec 018 fixes the meta description via a template. Per-category override would be cleaner long-term: add a `seo_description` column to `categories`, expose in Filament CategoryResource, use as the first fallback in `forLeafCategory` ahead of the template. *[Models, Filament, Seo]* (a) `.claude/commands/deploy.md` now has step 9 verifying `crontab -l | grep schedule:run` and prints a WARNING if missing. (b) `docs/seo/operations.md` has a new "System cron hook (required)" subsection and a new failure-mode row distinguishing scheduler registration from firing. (c) 34-day backfill (April 10 → May 13) completed manually via `pw2d:seo:pull pw2d --gsc-window-days=35 --ga4-window-days=35` — 107 GSC + 1,272 GA4 rows recovered.

- [ ] **Investigate pw2d.com central-vs-tenant resolution mismatch** -- Prod returns 200 for `pw2d.com/sitemap.xml` despite `SitemapController` aborting on central domains and `tenancy.php` listing `pw2d.com` as central. Either `APP_CENTRAL_DOMAIN` env differs or vhost setup differs. Small investigation ticket, not urgent. *[Config]*

### Follow-ups from 2026-06-13 SEO status check (signal arriving — see docs/summaries/2026-06-13-seo-status-checkpoint.md)

- [~] **F33: Ergonomic-keyboard preset content/ranking gap** -- **2026-06-19 update: partially self-healed** as the site aged — "best minimalist keyboard" climbed pos 23→10.3, "best ergonomic keyboard for productivity 2026" at pos 11. Only `rsi-sufferer` still stuck (pos 44). Dedicated ergonomic spec no longer warranted; **subsumed by Spec 023** (preset-aware content) which targets ALL preset pages including these. Close once 023 ships and rsi-sufferer is re-checked. *[Seo, Content]*

### Spec 023 / 024 — preset-aware content depth + CWV (2026-06-19, trend confirmed)

Trend confirmed across 3 checkpoints (impr 276→516→928; pos 17.4→15.2→13.7). Bottleneck = preset pages stuck at pos ~10 serving generic category content. Owner approved "both, sequenced."

- [x] **Spec 023: Preset-aware compare content depth (backend)** -- Migration `2026_06_19_000001_add_seo_content_to_presets_table` ran. `Preset` model: `seo_content` in `$fillable` + `'array'` cast; `BelongsToTenant` confirmed present. `AiService::generatePresetContent(Preset)` added (admin_model, validates intro+faqs shape). Command `pw2d:generate-preset-content {tenant} {--category=} {--preset=} {--dry-run}` added. `SeoSchema::forLeafCategory`: FAQPage now merges preset FAQs first + dedupes; meta description chain updated (seo_description → seo_content intro stripped → category fallback). `ProductCompare::activePreset()` computed property added (Str::slug match, eager-loads presetFeatures). Filament: `seo_content.intro` textarea + `seo_content.faqs` repeater added to both `PresetResource` and `PresetsRelationManager`. *[Seo, AI, Frontend, Models]*

- [x] **Spec 023: DEPLOYED + POPULATED (2026-06-19)** -- Live at prod `40869a9` (feature `7fb1b4b` + token-budget hotfix `40869a9`: `generatePresetContent` maxOutputTokens 2000→8192 — admin_model thinking truncated on MAX_TOKENS, caught at dry-run). All 20 leaf-category presets populated via `pw2d:generate-preset-content pw2d` (0 errors; 4 non-leaf presets correctly skipped). Verified live: streamer page renders preset intro in body + 8-question merged FAQPage. **Measure ~2026-07-03:** did streamer/remote-worker/minimalist cross pos-10→top-5 + CTR off 0%?
- [x] **Spec 023: frontend + tests + review** -- Frontend: `product-compare.blade.php` intro priority-chain (preset → category) + `partials/compare-faqs.blade.php` preset-first case-insensitive deduped FAQs. Tester: 36 new tests (`tests/Feature/Ai/GeneratePresetContentTest.php`, `tests/Feature/Commands/GeneratePresetContentCommandTest.php`, `tests/Feature/Compare/PresetContentDepthTest.php`) + `PresetFactory::seoContent()` state — all green, 0 bugs. Reviewer: SHIP / 0 blockers (`docs/reviews/023-preset-content-review.md`). Fixes applied: S1 (dropped redundant active-preset query via optional `?Preset` param on SeoSchema), N2 (aligned FAQ dedup case-insensitive across schema+Blade), N3 (`strict_types` on Preset). Final: 232 `Seo|Ai|Compare` tests pass. **POST-DEPLOY:** deploy runs the migration; then `pw2d:generate-preset-content pw2d --dry-run` → review copy for streamer/remote-worker/minimalist → populate for real. *[Seo, AI, Frontend, Models]*

- [x] **Spec 024: Compare-page CWV initial-render weight cut (F31) — DEPLOYED + VERIFIED (2026-06-27, prod `170b405`)** -- Live: 6 cards render (was 12), schema decoupled (ItemList=12 intact), initial HTML 185KB→152KB (18%). PSI mobile (2026-06-27): **Perf 81, LCP 3.1s, CLS 0.015 (excellent — closes B24-2), SEO 100 / structured-data-valid**. Honest result: card-cut helped HTML/DOM but LCP is gated by render-blocking + LCP image (not cards), so LCP barely moved. *[Frontend, Perf]*

- [x] **F35 (CLOSED 2026-07-10 — verdict: constraint is authority, not CWV): Focused LCP pass for compare pages** -- PSI 2026-06-27 bottlenecks: render-blocking requests (~1,100ms), LCP image delivery (155 KiB — preload + right-size WebP + fetchpriority), unused/legacy JS (187+29 KiB — Vite modern build target), cache lifetimes (142 KiB, repeat-visit only). DECISION (owner, 2026-06-27): do NOT invest now — CWV is a tiebreaker signal; at pos-10 on a low-authority site the real constraint is authority/off-page, not 0.6s of LCP. Revisit ONLY if the ~Jul 3-10 check shows pages STILL stuck at pos 10 after content(023)+page-experience(024); if so, weigh this against off-page/authority work (likely the bigger lever). If pages climb → CWV wasn't the constraint, close this. *[Frontend, Perf]* See `docs/specs/024-seo-compare-page-cwv-weight.md`. Component: `renderLimit=6`, `revealMore()`, `hasMoreToReveal()` (H2H/pinned exempt — tester caught a sentinel-leak bug, fixed), `schemaProducts()` (B24-1 blocker fix). Frontend: `[data-reveal-sentinel]` + `x-intersect.once="$wire.revealMore()"` + 6 CLS-safe skeletons matching the real card grid. 19 new tests, 218 green. Reviewer SHIP after B24-1 (`docs/reviews/024-cwv-weight-review.md`). **POST-DEPLOY: B24-2 (verify CLS in DevTools) + PageSpeed before/after + confirm 3 JSON-LD blocks still emit + ItemList=12.** *[Frontend, Perf]*

- [x] **B24-1 (BLOCKER): ItemList schema + meta description regressed to 6 products by the renderLimit cut** -- Fixed: added `schemaProducts()` returning top `displayLimit` products with `with(['brand','offers'])` (no `featureValues.feature` — cheaper than visibleProducts' full eager-load). `render()` now passes `$this->schemaProducts()` to `SeoSchema::forCategoryPage()`. Two regression tests added to `CompareRenderLimitTest`. 218 tests green. *[Seo, Frontend]*

- [ ] **B24-2 (SHOULD-FIX, do at deploy): verify compare-page CLS empirically** -- Skeleton sentinel uses a fixed `h-85 md:h-97.5` while real cards are content-height; grid cols/gaps match (good) but heights only approximate. The suite cannot measure layout shift (same blind spot as the Spec 025 Alpine lesson). Before/at deploy, load `/compare/mechanical-gaming-keyboards?preset=streamer`, scroll to trigger reveal, and watch Chrome DevTools Layout Shift Regions / CLS. If it shifts, match skeleton height to a measured card or set a `min-h` on the card row. Also consider capping skeleton count at `min(6, displayLimit - renderLimit, scoredCount - renderLimit)` to avoid collapsing unused skeleton slots. *[Frontend, Perf, QA]*

- [x] **Spec 025: Compare above-fold UX — DEPLOYED + VERIFIED (2026-06-19, prod `97cac8b`)** -- Reorder + un-blur backdrop + session auto-open all live and owner-confirmed ("drawer opens, looks better"). **Post-deploy bug found in browser console & fixed (`97cac8b`):** auto-open `x-init` used a top-level `const` which a STALE published `livewire.js` (older bundled Alpine) couldn't parse → `Unexpected token 'const'` aborted the whole `x-init`. Fix: moved logic into an `x-data` method `initAutoOpen()` (version-proof) + republished Livewire assets on prod (`vendor:publish --tag=livewire:assets --force`) + switched gate to **per-category** (owner choice). Lesson logged in `docs/lessons.md`; `/deploy` hardened with a livewire-assets republish step (PENDING — see below). Coverage gap noted: PHPUnit/Livewire tests don't run Alpine JS. *[Frontend, UX]*

- [ ] **Harden `/deploy`: add Livewire asset republish step** -- Add `php artisan vendor:publish --tag=livewire:assets --force` after `composer install` in `.claude/commands/deploy.md` so stale `livewire.js` (→ Alpine breakage) can't recur after a Livewire composer bump. Edit was BLOCKED by the command-file self-modification guard on 2026-06-19; owner to apply (exact diff provided) or grant permission. *[DevOps, Tooling]* See `docs/specs/025-compare-abovefold-ux.md`. (1) reorder via new `partials/compare-content.blade.php` (intro/tabs/methodology moved BELOW grid; H1+hook stay above); (2) backdrop `bg-gray-900/40 backdrop-blur-[2px]` → `bg-gray-900/10` (no blur) so grid re-ranks visibly; (3) auto-open once per SESSION (`sessionStorage['app_customize_autoopen']`, 1500ms, respects `$autoOpen`, `customize_modal_autoopened` posthog event). **Tester caught + fixed a render-fatal bug:** `$categoryName` referenced in `x-init` attr before its `@php` assignment in the div body → `Undefined variable` on every compare page; fixed by moving `@php` above the `<div>`. 10 new tests, 199 passed / 0 failed. Frontend-only, no migration. **POST-DEPLOY: manual QA checklist** (auto-open ~1.5s, live re-rank no-blur, reload→teaser, new session→reopens, no auto-pop over product). *[Frontend, UX]*

- [ ] **F34: GA4 STALE status is a low-traffic false-positive** -- `pw2d:seo:status` reports GA4 STALE because GA4 only writes landing-page rows for URLs with sessions; near-zero traffic → some days have 0 qualifying rows → "latest date" lags the staleness threshold even though the cron runs fine (verified: no GA4 errors in log, GSC + top_query advancing normally). Consider relaxing the GA4 staleness threshold OR documenting that GA4 STALE ≠ pipeline failure at low traffic, to avoid alarm on future checks. *[Seo, Tooling]*

- [x] **Spec 011: Problem Products store link & rescan action** -- Added Store badge column, changed product name to edit-page link, added Rescan Price row action. Extracted `scrapeOfferPrice()` as public static on SyncOfferPrices. Removed amazon_rating column.

- [x] **Spec 012: Fix AI matching brand dedup** -- Added `AiService::normalizeBrandForComparison()` (public static). Fixed heuristic query in `matchProduct()` to fuzzy-match brands via SQL REPLACE chain. Fixed `ProcessPendingProduct` to reuse existing brand by fuzzy match instead of `firstOrCreate`. Fixed negative cache invalidation to cover all brand spelling variants. Tightened brand normalization prompt. 14 new tests in `BrandNormalizationTest`.

- [x] **Spec 013: Enhance MergeDuplicateProducts command** -- Added `--category` option, Phase 2 brand-spelling dedup via `normalizeBrandForComparison()`, feature value transfer in `mergeDuplicate()`, `price_tier` recalculation after merges, and improved two-phase console output.

- [x] **Prevent verbose-title product names/slugs at the source (2026-07-04)** -- ~30 coffee2decide + 272 pw2d products previously got 100+ char slugs because the AI Bouncer sometimes echoed the raw Amazon marketing title as "name", and slug generation had no length cap. Fixed in 3 places: (1) `AiService::evaluateProduct()` prompt — added explicit `NAME RULE` (max 8 words) to STAGE 2.5. (2) `ProcessPendingProduct` — new private `capProductName()` helper (truncates at first comma/paren, falls back to first 8 words, strips a trailing stopword) applied to the AI name (and its raw-title fallback) before it becomes the stored `name` and slug stem, so name/slug always agree. (3) Stub-slug generation in `BatchImportController`, `ProductImportController`, `OfferIngestionService` changed from `Str::slug(Str::limit($title, 80))` (still up to ~80 chars) to `Str::slug(Str::words($title, 8, ''))` (first 8 words). Data already fixed manually — this is prevention-only, no existing rows touched. 4 new tests (`ProductNameSlugCapTest` + 1 in `ProductImportControllerTest`); 397 total tests green.

- [~] **Chrome extension: fix Amazon reviews_count extraction** -- 88 Amazon products ended up with `amazon_reviews_count=0`, likely from an older extension version or Amazon layouts not covered by the current 5-strategy `extractReviewsCount()` in `chrome_extension/content.js:126`. Action items: (a) find a specific product URL where the extension currently returns 0 reviews, (b) inspect DOM to find the correct selector, (c) add as 6th strategy — **still open, Phase B (extension) work, Spec 029 B3**. Server-side half done as part of Spec 029 Phase A (T1): `BatchImportController.php`, `ProductImportController.php`, and `OfferIngestionService.php` (all 3 ingestion paths, not just the 2 named here) now store `null` instead of `0` when reviews_count is missing — required a migration making `products.amazon_reviews_count` nullable. *[Extension, API]*

## P3 -- Low Priority (Polish)

- [ ] **L1: Add N+1 eager loading in Filament resources** -- AiMatchingDecisionResource, CategoryResource, FeatureValuesRelationManager. *[Perf-Filament]*
- [x] **L2: Extract duplicated feature-score parsing** -- Identical in ProcessPendingProduct and RescanProductFeatures. Closed by Spec 039 T2: both now call `FinalizeProductEvaluation::applyFeatureScores()`. *[Review-AI]*
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

## SEO checkpoint 2026-07-10 — verdict logged

- [x] **C2D-1: coffee2decide GSC + GA4 service-account grants (OWNER, 5 min)** -- `pw2d:seo:pull coffee2decide` fails with `forbidden` (GSC, sc-domain:coffee2decide.com) and `PERMISSION_DENIED` (GA4). Pipeline has NO_DATA since connect — the Jul-3 smoke-test claim was wrong or the grant didn't persist. Add `pw2d-seo-reader@pw2d-407419.iam.gserviceaccount.com` as GSC Restricted user + GA4 Viewer, then backfill `php artisan pw2d:seo:pull coffee2decide --gsc-window-days=10 --ga4-window-days=10`. *[Seo, Owner]*
- [x] **Q1 priority bump: offer unique-constraint violation CONFIRMED in prod** -- laravel.log shows repeated `Duplicate entry 'product_offers_product_id_store_id_unique'` from WLL ingestion (Apr 5). Q1's `updateOrCreate` fix is no longer theoretical. Fixed as part of Spec 029 Phase A (T1). *[API]*
- 2026-07-10 verdict: **on-page NOT the lever → pivot to off-page/authority.** F35 CLOSED (not-the-constraint). Spec-027 (product-page content depth) is the optional parallel code track. Full data + reasoning in `docs/summaries/2026-06-13-seo-status-checkpoint.md` §2026-07-10.

## Spec 026 (2026-07-05) — GSC Product-snippet fix

- [x] **F36: Rating-less products in compare ItemList emit invalid Product entities** -- First GSC error on coffee2decide (JURA X10, pos 12, super-automatic-espresso-machines). Fixed: rating-less items downgraded to URL-only ListItem (summary-page style) in `SeoSchema::buildItemListSchema()`; rated items byte-identical. See `docs/specs/026-schema-ratingless-itemlist.md`. Built + tested 2026-07-05: 4 new tests in `tests/Feature/Seo/SeoSchemaTest.php`, suite 401 passed / 0 failed. **AWAITING `/deploy`. POST-DEPLOY: Rich Results Test on the affected URL + GSC "Validate Fix".** *[Seo, Schema]*

## Spec 027 (2026-07-19) — Best-X landing pages (authority play, code track)

- [x] **Spec 027 build** -- `/best/{slug}` landing-page system: `landing_pages` table, deterministic pick selection, `AiService::generateLandingPageContent`, `pw2d:generate-landing-page` command, plain-Blade controller (no Livewire), ItemList w/ 026 rating gate, sitemap + compare-page cross-link, Filament resource. Spec: `docs/specs/027-best-x-landing-pages.md`. T1-T4 complete, reviewer SHIP + all SHOULD-FIX applied. **Remaining: rollout (owner-gated)** — generate + review 4 draft pages (pw2d keyboards ×2, c2d super-auto + manual grinders), publish via the new `LandingPageResource`, `/deploy`, request indexing. *[Seo, Backend, Frontend]*
  - [x] **T1 (builder): backend** -- Migration `landing_pages` (tenant-scoped, `unique(tenant_id,category_id)` + `unique(tenant_id,slug)`). `LandingPage` model (`BelongsToTenant`, `title` accessor, `cacheKey()`, cache-forget on saved/deleted incl. sitemap key). `App\Actions\SelectLandingPagePicks` (reuses `ProductScoringService` default-weight path for overall/budget/premium; mirrors `generatePresetContent`'s raw weighted-sum for preset picks; min 5 picks or throws). `AiService::generateLandingPageContent()` (admin_model, 8192 tokens, full shape validation). `pw2d:generate-landing-page {tenant} {category-slug} {--regenerate} {--dry-run} {--publish}`. `LandingPageController::show` (thin, tenant-scoped 1h cache, skips deleted/detached/ignored picks). Route `GET /best/{slug}`. `SeoSchema::forLandingPage()` (extracted shared `buildListItem()` so the Spec 026 rating gate isn't duplicated) + FAQPage + BreadcrumbList, zero price/priceCurrency/offers. Sitemap includes published pages only. Also wired `ProductCompare::publishedLandingPage()` into `compare-content.blade.php`'s pre-built (T2) contextual-link guard, which was otherwise dead code. Full pipeline smoke-tested end-to-end in a rolled-back DB transaction (pick selection → dry-run → publish → controller render → sitemap → draft 404). `php artisan test`: 401 passed / 9 skipped (baseline unchanged, no new tests — tester's job). Decisions logged in `docs/questions.md`. *[Backend]*
  - [x] **T2 (frontend): Blade view + partials** -- `resources/views/landing/show.blade.php` + `_pick-card.blade.php` + `_methodology.blade.php` + `_faqs.blade.php` already built against the T1 contract (found complete on disk when T1 landed — built in parallel). Compare-page contextual link partial also pre-built, defensively guarded on `$landingPage` (T1 supplied the variable). *[Frontend]*
  - [x] **T3 (tester): §9 test suite** -- 44 tests across `tests/Feature/LandingPage/*`, `tests/Feature/Commands/GenerateLandingPageCommandTest.php`, `tests/Feature/Compare/LandingPageCompareLinkTest.php`, `tests/Feature/Seo/LandingPageSchemaTest.php`. Suite at 433 passed / 9 skipped after T3. *[Test]*
  - [x] **T4 (reviewer): correctness + tenant-scoping + N+1 + schema-policy pass** -- SHIP, 0 blockers, 5 SHOULD-FIX. `docs/reviews/027-landing-pages-review.md`. *[Review]*
  - [x] **T4 follow-up (builder): S1-S5 + 2 nits applied** -- **S1** `LandingPage::cacheKey()`/`booted()` now derive the cache key from the row's own `tenant_id` column (`tenantScopedCacheKey()` private helper), not ambient `tenant_cache_key()` — correct even when saved from tinker/a job outside initialized tenancy; key FORMAT unchanged (`t{tenant}:...`) so existing entries stay addressable. **S2** added a real, non-persisted `LandingPage::$renderedPickCount` property (bypasses Eloquent's attribute system entirely — never dirty, never saved) that `LandingPageController::buildViewModel()` sets to the FILTERED pick count before both the view and `SeoSchema::forLandingPage()` read `$page->title`, so H1/`<title>`/ItemList `name` always agree with what actually renders. **S3** new `sanitize_ai_html()` global helper (`app/Helpers/html.php`, registered in `composer.json` autoload `files`) — same allowlist convention as `compare-content.blade.php:119` — applied to `intro`/pick `body`/FAQ `answer`; `methodology_note` switched to `{{ }}` (prompt-prescribed plain text). **S4** `buildViewModel()` now calls `$page->setRelation('category', $category)` before `forLandingPage()` — no redundant lazy-load, schema built from the same instance the view gets. **S5** new minimal `LandingPageResource` (`app/Filament/Resources/LandingPageResource.php` + 3 pages) under "Product Management" (no "Content" group exists; matches how `CategoryResource` etc. are grouped) — list (category/slug/status badge/generated_at), edit form (intro textarea, picks repeater with `product_id`/`role` disabled-but-dehydrated so they survive save while only headline/body/faqs/methodology_note/publish-toggle are editable — no add/delete/reorder on picks), publish toggle writes `status` directly with **zero AI calls** (verified by a throwing `AiService` spy in the test). **Nits addressed:** FAQPage schema now `array_filter`s malformed question/answer entries before emitting; `_pick-card.blade.php`'s dead `feature_scores` branch removed (never populated by the render path — highlights now always show the honest raw feature value, no fake/never-executing score path). **Testing note:** `LandingPageResourceTest` uses `Livewire::test()` on the resource Page classes directly (not `$this->get('/admin/...')`) — full HTTP panel requests are blocked by the pre-existing F12 bug (`ProblemProducts::getNavigationBadge()` raw REGEXP SQL, unsupported on sqlite, crashes on ANY panel page); `Livewire::test()` on a Page component doesn't render the panel layout/nav, sidestepping F12 (same technique F17 established for widgets) — confirmed empirically. Added `<ini name="memory_limit" value="512M"/>` to `phpunit.xml` (Filament panel/resource discovery pushed the full-suite peak past the default 128M). Full suite: **441 passed / 9 skipped** (433 + 2 cache-key tests + 1 pick-count test + 5 Filament resource tests, all new). *[Backend, Filament, Test]*
  - [x] **T4 follow-up 2 (builder): AI writing-style overhaul** -- Owner feedback on the first local page (mechanical-gaming-keyboards): prose read unmistakably AI-written. Rewrote `AiService::generateLandingPageContent()`'s prompt with an explicit STYLE CONTRACT section (BANNED tells: stock openers, cliché words/phrases including single-word bans like "robust"/"seamless"/"boasts", the "Whether you're..." audience-triad, "not just X but Y", uniform sentence rhythm, methodology self-praise; REQUIRED: ground claims in real numbers, vary sentence length, one concrete tradeoff + one specific data fact per pick body, direct-address/contraction voice, ≤2-paragraph intro that states the ranking method in one plain sentence). Added a new required 4th param `int $scoredProductCount` (true count of processed+non-ignored products in the category — `GenerateLandingPage` command computes it with the same filter `ProductCompare::scoredProducts()` uses) so the prompt can ground "we scored N X" claims in a real number instead of inventing one; also compute each pick's price delta vs. the cheapest pick inline in the prompt builder (no new param needed — derived from picks already passed) so pick bodies can cite real price gaps instead of vague "it's pricier" language. JSON response schema unchanged (same 4 top-level keys). Updated 3 `AiService`-extending test spies (`GenerateLandingPageCommandTest` ×2, `LandingPageResourceTest` ×1) to accept the new optional 4th param. Regenerated the local `mechanical-gaming-keyboards` page (`--regenerate`, stays published): 1st pass had a single banned-word slip ("robust build"); tightened the BANNED bullet to state single words are banned individually/out-of-context (not just as exact phrases) and made the pre-return self-check literal ("search for each banned word one at a time"); regenerated once more (2 AI calls total, the agreed max) — 0 banned-list hits on an automated scan of the full page text. Verified live via `curl http://pw2d.lcl:8000/best/mechanical-gaming-keyboards` (200, correct H1, 3 JSON-LD blocks). Full suite: **444 passed / 9 skipped**. *[AI, Content]*
  - [x] **T4 follow-up 3 (builder): GROUNDING clause — fixed a fabricated "renewed" claim** -- Owner caught pick #1's body claiming the Logitech G915 TKL was "an estimated $130 for a renewed model" — no condition data exists anywhere in our payload; the model rationalized the price against its own world-knowledge retail price. Added a GROUNDING section to the STYLE CONTRACT in `AiService::generateLandingPageContent()`: every product-specific claim (price context, condition, specs, scores) must come ONLY from that pick's data line; condition (new/renewed/refurbished/used/open-box) must never be mentioned since the payload has none; prices are stated plainly, never "explained" via outside knowledge; world knowledge is allowed only for uncontroversial category-level context (what a switch type is, what TKL means), never a specific-product claim. Also strengthened the pre-return self-check to explicitly ask the model to reread every pick body for condition-mentions and for specs/scores not present in that pick's own data line. No signature change. Regenerated once (`--regenerate`, 1 AI call) — rescanned: (a) style-banned-list — 1 residual hit ("not just a gimmick, but..." in an FAQ answer, a `not-just-X-but-Y` construction — flagged for the coordinator, not auto-fixed since this round's budget was one AI call); (b) condition words — 0 real hits (2 raw "used" substring matches were both the verb "used," not the condition adjective, confirmed by context); (c) 3 numeric spot-checks against the real payload (Wireless Performance 96, Gaming Responsiveness 95, $75 price delta = $130 − $55) — all 3 matched exactly. **Separate finding surfaced during verification** (not fixed, logged in `docs/questions.md`): the fabricated "renewed" claim was traceable to Product #2723's own `ai_summary` field (from the UNRELATED `evaluateProduct()` AI Bouncer pipeline, not the landing-page generator) — it says "even if renewed" and is fed into the landing-page prompt as pick context AND rendered directly into the ItemList JSON-LD `description` on every page listing that product. The GROUNDING fix correctly stopped the landing-page prose from repeating it even with the polluted input, but the root fabrication lives upstream in `evaluateProduct()`'s output — out of scope for this task, logged for a future pass. Full suite: **444 passed / 9 skipped** (no test changes needed). *[AI, Content]*
  - [x] **Addendum A §2/§3 (builder): condition-marker + duplicate-name defense in depth** -- Fixed the root fabrication logged in the T4 follow-up 3 note above (Product #2723's polluted `ai_summary`) plus the Keychron Q6 Max near-duplicate double-pick, at every layer: (a) new shared `App\Support\ProductConditionGuard` — single definition of condition markers, two sets (`TITLE_MARKERS` incl. "used" for raw listing titles; `SUMMARY_MARKERS` excludes bare "used" for AI prose, so "designed to be used with..." doesn't over-match). (b) `SelectLandingPagePicks` now rejects condition-marked products (offer raw_title OR ai_summary) from the eligible pool, AND rejects any candidate whose normalized name (lowercase, alphanumeric-only) is ≥85% `similar_text` or a substring-match of an already-picked product's normalized name — tries the next-best candidate at every pick site (overall/budget/premium/preset/fill-in) rather than leaving the slot empty. (c) server-side import guards added to all three ingestion paths sharing the same helper: `BatchImportController` (new `skipped` counter in the JSON response), `ProductImportController` (`action: skipped_condition`), `OfferIngestionService` (`action: skipped_condition`, checked before the existing-offer refresh branch too, so a listing that flips to Renewed on a later scrape doesn't silently re-pollute a clean product's offer). (d) `AiService::evaluateProduct()` prompt gained IGNORE RULE C (renewed/refurbished/open-box → `is_ignored`) and the old RULE A's "DO NOT ignore ... refurbished units" carve-out was removed (it directly contradicted the new rule). (e) new audit command `pw2d:flag-condition-products {tenant} {--ignore}` — tenant-scoped, tries offers first then ai_summary, table output (id/name/category/matched-on/marker), read-only unless `--ignore` (sets `is_ignored=true`, prints count). Ran read-only against `pw2d`: **33 condition-marked products** found (1 by offer raw_title, 32 by ai_summary — matches the addendum's ~35 estimate) — includes Product #2723, the previously-fabricated pick. **Not yet flagged** (`--ignore` deliberately not run — owner reviews the list first). Regenerated `mechanical-gaming-keyboards` (`--regenerate`, 1 AI call): #2723 no longer appears anywhere in the 7 picks (replaced in the fill-in slot by #2704, a distinct clean "Logitech G915 TKL Tactile" row); the Keychron Q6 Max hobbyist/streamer double-pick is gone (hobbyist → Keychron Q6 Max Black #2742, streamer → distinct Keychron Q5 Pro Wireless #2691). New tests: 4 in `SelectLandingPagePicksTest` (title-marker exclusion, ai_summary-marker exclusion, plain-"used"-does-not-over-match regression, duplicate-name-skips-to-next-best), 1 per ingestion path (`BatchImportControllerTest` — MySQL-gated like its siblings, skips on sqlite; `ProductImportControllerTest`; new `tests/Feature/Services/OfferIngestionServiceTest.php`), 5 in new `tests/Feature/Commands/FlagConditionProductsCommandTest.php` (list-only default, ai_summary match, `--ignore` flips the flag, unknown-tenant failure, tenant isolation). Full suite: **455 passed / 10 skipped** (444+12 new, 1 of the 12 skips on sqlite exactly like the pre-existing MySQL-gated BatchImport tests — net +11 passed / +1 skipped vs. baseline). *[Backend, AI, Test]*
  - [x] **Addendum A follow-up (builder): marker-blind renewed pick (#2704) — live-fetch attempt built then dropped, `--urls` review mode shipped instead** -- Owner caught pick #7 (#2704, "Logitech G915 TKL Tactile") pointing to an Amazon Renewed listing DESPITE clean stored data (`raw_title`/`ai_summary` had no markers) — a scrape can predate a listing flipping condition, or store an already-cleaned title, so the marker guard is structurally blind to this class. First attempt: extended `pw2d:flag-condition-products` with `--live --category={slug}` to fetch each candidate's live Amazon offer HTML (same UA/header/timeout conventions as `SyncOfferPrices::scrapeOfferPrice()`, 2-4s polite delay between every fetch across the whole run) and scan it with a new `ProductConditionGuard::detectInHtml()` (parenthetical "(Renewed)", "Amazon Renewed" brand/badge text, "Refurbished", "Open Box"); a fetch failure was reported as a third "unverified" source (never auto-ignorable) distinct from "marker"/"live". **Owner correction: Amazon blocks direct server/CLI fetches — dropped entirely** (command code, the `--live`/`--category` options, and `ProductConditionGuard::detectInHtml()` all removed; zero dead code left behind). Replaced with a read-only `--urls --category={slug}` mode: prints a review table (id/name/offer URL/est. price/role) of the category's landing-page-eligible products, with a published/draft landing page's CURRENT picks listed first (in role order, `role` column shows the pick role vs. `candidate`) — so a human (or later the Chrome extension) can open each URL in a real browser, since that's the only reliable ground truth Amazon allows. Stopgap for #2704 specifically: `is_ignored=true` set directly via tinker (verified `Product::where('id', 2704)->update(...)` affected exactly 1 row before AND after — logged in the builder report). Regenerated `mechanical-gaming-keyboards` once more (`--regenerate`, 1 AI call): pick #7 is now #2929 (a distinct, genuinely different "Logitech G715 White Mist" product row/ASIN — spot-checked its `ai_summary`/offer `raw_title` are clean and it's NOT the same row as the marker-flagged #2776 which happens to share the display name); confirmed none of the 7 final pick ids intersect the marker-audit list or #2704. New tests: 2 in `FlagConditionProductsCommandTest` (`--urls` prints the right tenant/category's URLs and excludes another category's; `--urls` without `--category` fails) — no live-fetch tests exist (never built against real Amazon, per instruction). Full suite: **457 passed / 10 skipped** (455 baseline + 2 new `--urls` tests; no skip regressions). *[Backend, Test]*

- [~] **F37: Chrome-extension "verify condition" mode (the only reliable renewed-detector)** -- Amazon blocks server-side fetches; renewed listings can be invisible in our stored data (product 2704: clean title, clean summary, live page = "Amazon Renewed"). Add an extension mode that takes the `pw2d:flag-condition-products --urls` list (or a GET endpoint), visits each offer URL in the owner's real browser session, detects Renewed/Refurbished/Open-Box markers in the DOM, and POSTs results to a new API endpoint that flags offers/products. Requires popup.js + content.js + API changes IN SYNC (CLAUDE.md extension rule). Until built: pick URLs are verified MANUALLY during Filament draft review (added to Spec 027 rollout runbook). **Server-side half shipped as Spec 029 Phase A (T1):** `GET /api/extension/rescan-list?category_id={id}` (the "GET endpoint" option) and the ingest-offer/import endpoints now accept `condition`/`listing_flags` and act on them (flag+ignore / high_price / clear). **Still open (Phase B, extension code):** `detectListingHealth()` in content.js + rescan-walk mode in popup.js/background.js — see Spec 029 §B1-B2. *[Extension, API, Seo]*

## 2026-08-09 checkpoint session — batch rollout + leaf #5 decision

- [x] **Aug 9 status check DONE** — logged in `docs/summaries/2026-06-13-seo-status-checkpoint.md` §2026-08-09. Headlines: /best/ pages crawled-in on both tenants in 2-4 days, zero cannibalization, net-new "best X" query surface; pw2d flat (authority verdict stands); c2d consolidating at wpos ~30, CTR 0.54%; product pages are c2d's strongest surface (Spec-028 evidence).
- [x] **Remaining-categories batch COMPLETE — all 7 drafts saved (2026-08-09)** — pick selection succeeded for all 7 (no <5 aborts). `podcast-studio-mics` was Gemini-generated (owner already published it same day after QA). The other 6 hit a sustained Gemini 3.1-pro-preview 503 outage (~1h, large requests only), so **Claude authored those 6 directly** (owner-approved): exact `generateLandingPageContent` payload exported from prod (deterministic picks, feature scores, price deltas, excluded FAQs, scored counts), content written under the same STYLE+GROUNDING contract, machine-checked for banned words/condition words/pick-ID order (2 "not just X" hits found+fixed), then imported via a tinker script that re-ran `SelectLandingPagePicks` server-side and verified ID match before saving. All 6 saved as `draft`, 7 picks + 4 FAQs each. **Owner review next: Filament draft review + pick-URL condition check (`pw2d:flag-condition-products {tenant} --urls --category={slug}`) before publish. Publish is DB-only (no /deploy); then request GSC indexing per URL.** Note for the record: these 6 pages' prose provenance is Claude (claude-fable-5), not the Gemini pipeline.
  - Review flags from pick tables: (a) podcast-studio-mics picks include BOTH "Shure SM58-LC Three-Mic-Pack" and "Shure SM58 (2-Pack)" — pack variants slipped the ≥85% similarity guard (F29-adjacent; consider a pack-variant rule); (b) gaming-chat-headsets budget pick is "Logitech G PRO Gaming Headset for Oculus Quest 2" — VR-specific product on a general headsets page; (c) semi-auto busy-commuter pick "Breville Oracle Jet, Olive Tapenade" — is this one of the 4 detached-from-super-auto units already re-homed, or a duplicate row? Comma in name = pre-cap legacy name.
- [x] **Owner QA round 2 on /best/gaming-chat-headsets (published, then 3 problems found+fixed 2026-08-09)** — (1) TWO renewed picks with completely clean stored data: #1638 HyperX Cloud II Red (was Best Overall!) + #1655 Logitech G Pro X Wired — both `is_ignored=true` via tinker (verified 2 rows). Marker-blind cases #3-4 after #2704. **New structural finding: their `product_offers.raw_title` values EQUAL the cleaned product names — that import path stored cleaned titles, not raw Amazon titles, so the title-marker guard can never fire on those rows. Investigate which import path did this and whether raw titles are recoverable (extension re-scan).** (2) #1702 Logitech G PRO for Oculus Quest 2 detached (`category_id=null`, joins the re-home list) — VR-specific, doesn't belong in general headsets. (3) Page re-selected (98 eligible) + re-authored + updated in place (status kept published, verified live: Cloud III overall, no renewed traces). **Owner: re-verify the NEW pick URLs** (Kaira X #1674, Arctis Nova 7P #1781 / 7X #1795 — note these two are Destiny-2-edition siblings, judgment call whether both stay) **and do the same URL check on the other 5 drafts before publishing them.** *[Data, Content]*
- [x] **Duplicate "Best Overall" badge on fill-in overall picks — FIXED, AWAITING /deploy** — `SelectLandingPagePicks` reuses role 'overall' for fill-in picks (by design, per its docblock); `show.blade.php` labeled every one "Best Overall". Fix: `$overallSeen` flag — first 'overall' → "Best Overall", subsequent → "Also Great" (badge styling unchanged; SeoSchema confirmed role-label-free). +1 regression test (page with two 'overall' picks → exactly one "Best Overall"). Suite 458 passed / 10 skipped. Affects all /best/ pages incl. published podcast + keyboards pages once deployed. *[Frontend]*
### Post-audit finding (2026-08-16) — unbuyable products still render on compare pages

**99 non-ignored products have NO purchasable offer** (every offer is flagged / renewed / unavailable / priceless).
They keep their feature scores, so they still rank and display on compare pages — but with **no "Check Current
Price" button and no explanation**. Live example: `pw2d.com/compare/gaming-chat-headsets` renders 6 cards with
only 2 CTAs. Per category: podcast-studio-mics 28, gaming-chat-headsets 20, mechanical-gaming-keyboards 13,
productivity-ergonomic-keyboards 13, lavalier-wireless-systems 10, semi-auto 4, cold-brew 4, kettles 3,
super-auto 2, pour-over 2.

Landing pages are unaffected — `SelectLandingPagePicks` excludes them. This is compare-page-only, and the
2026-08-16 audit's C1 fix corrected price/scoring consistency but deliberately did not change visibility.

- [x] **DECIDE (owner): how should compare pages treat an unbuyable product?** **Resolved 2026-08-16 — owner:
  "hide products with no offer — the main target is to make the user click the button."** Option (a) shipped.
  **Built:** `ListingHealth::applyPurchasableOfferQuery()` — a SQL-level twin of `isPurchasable()`'s three
  conditions (price > 0, condition not in `NEGATIVE_CONDITIONS`, no `PICK_EXCLUDING_FLAGS`; the `listing_flags`
  JSON check turned out to be sargable via `whereJsonDoesntContain()` + a `whereNull` guard for MySQL/SQLite
  NULL-safety — verified on both grammars). Applied as `whereHas('offers', ...)` (filtered in SQL, not
  loaded-then-discarded) to:
  - `ProductCompare::scoredProducts()` — the compare-grid product pool (cache key bumped `v2` → `v3`).
  - `ProductCompare::mount()`'s `maxPrice` slider-bound query — an unbuyable-only-offer product's price no
    longer stretches the slider range.
  - `SimilarProducts` — the "Similar Products" rail has the identical CTA-per-card problem (cache key bumped
    `similar_products_` → `similar_products_v2_`).
  `schemaProducts()` needed no change — it derives its product IDs from the same (now-filtered) `scoredProducts`,
  so the ItemList JSON-LD automatically agrees with the rendered grid.
  **Deliberately NOT filtered** (per-surface judgment, logged in `docs/questions.md`):
  - The product page itself (`/product/{slug}` via `ProductCompare::mount($product)`/`openProduct`/
    `selectedProduct`) — a legitimate Google landing target; the CTA is already conditionally rendered
    (`@if ($product->affiliate_url)`) so it degrades gracefully with no button, no broken layout.
  - Filament admin (`ProblemProducts`, `ProductResource`) — the owner triages flagged/unbuyable products there;
    hiding them would break that workflow.
  - `SitemapController` — unchanged (see the new follow-up item below).
  - `GlobalSearch`'s product search results and `ProductCompare::mount()`'s `?focus=` auto-pin query — left
    unfiltered; an unbuyable focused product now simply doesn't appear in the (already-filtered) grid, a
    harmless no-op rather than a broken card.
  Empty-state check: the Blade's existing `@else` branch ("No products found" — `product-compare.blade.php`
  ~line 356) already covers `scoredProducts->count() === 0`, so an (currently hypothetical) all-unbuyable
  category degrades to that message rather than a broken grid; no Blade change was needed.
  6 new tests in `ProductCompareTest` (hidden/present/zero-price/schema-match/slider-bound) + 2 new tests in a
  new `SimilarProductsTest`; 3 existing test files (`ProductCompareIntegrationTest`,
  `CompareRenderLimitTest`, `CompareContentOrderTest`) updated to give their fixture products a purchasable
  offer, since their assertions (grid content, card counts, ItemList counts) now require it. Full suite
  635 passed/19 skipped → **641 passed/19 skipped** (+6 net new, no regressions).
- [ ] **SEO follow-up (owner decision needed, flagged not fixed):** hiding a product from the compare grid
  while its `/product/{slug}` page stays live and in the sitemap (`SitemapController` still lists every
  non-ignored/processed product regardless of buyability) creates an orphaned page — reachable from Google,
  but with no CTA and no longer linked from the category grid or (per the filter above) the Similar Products
  rail on other products' pages. Options: (a) exclude unbuyable products from the sitemap entirely; (b) leave
  in the sitemap but add `noindex` while unbuyable (auto-clears on a clean rescan, matching how `listing_flags`
  already clear); (c) leave as-is, since stock/condition issues are often transient and the page is still
  useful to a reader who lands on it. Needs the owner — SEO risk tolerance, not a data question. *[SEO, Product]*
- [ ] **Signal for Tier-3 discovery:** 25% of the headsets pool and ~15% of mics being unbuyable is a stronger
  argument for the quarterly import than pool size alone. Consider triggering discovery on
  *unbuyable share*, not just absolute pool count. *[Data, Content]*

### Rescan rollout — pw2d COMPLETE (2026-08-14)

All 5 pw2d categories rescanned by owner; all 5 published pages re-selected from health-verified pools, re-authored (Claude — Gemini admin_model still timing out on this call), updated in place, and verified live. `pw2d:landing-pages:audit` → **"All published landing pages are fresh."** Bad listings surfaced: headsets 36 · gaming keyboards 30 · lavalier 10 · mics 24 · ergonomic 16 ≈ **116**, plus large price corrections (Q5 Pro $220→$150, ECM77B $350→$290, Kaira X $40→$99.99, one mic $75→$100). Pool sizes after cleanup: 79 / 162 / 76 / 181 / 99.

- [x] **BUG: rescan updates land on the wrong product row for cross-category duplicates** — `OfferIngestionService` re-resolves the offer by URL, so when two products in different categories share an ASIN the FIRST match (lowest id) absorbs the update and the twin is never health-stamped. Found on ergonomic keyboards: 16 rows structurally unreachable, one of them a live pick. **Fixed by Spec 033** (2026-08-20) — code complete + tested, **AWAITING /deploy AND extension reload to 1.8**. The bug is still live in production until both ship; do not trust a rescan of a duplicate-bearing category before then. See below. *[API, Extension]*
- [x] **Cross-category duplicate cleanup (ergonomic)** — 16 rows in `productivity-ergonomic-keyboards` were exact-ASIN duplicates of `mechanical-gaming-keyboards` rows (mostly gaming boards miscategorised into ergonomic: G815, G PRO X TKL, ROG Strix, AULA ×3, Redragon, Q5 Pro ×3, K2, K4, C1, C2, K8 HE). Owner approved ignoring all 16 (2026-08-14, reversible in Filament); pool 115→99 and the office-professional slot moved off an unverifiable row. **Other categories not yet audited for the same pattern**; `pw2d:merge-duplicates` is category-scoped and cannot see cross-category dupes — needs a cross-category mode or a Filament "duplicate ASIN" report. *[Data, Tooling]*
- [ ] **Extension popup undercounts flags** — the run summary's `flagged` tally counts only `flagged_condition` (condition→ignored) and ignores `listing_flags` hits, so runs that flagged 10 and 24 offers both reported "flagged 0". Cosmetic but actively misleading during QA. *[Extension]*
- [ ] **Editorial: repeated products within/across pages** — Keychron Q11 appears twice on the ergonomic page (two distinct live ASINs, same board, same $210); K8 HE appears on both keyboard pages in different colourways; SM58 two-pack + SM58S both on the mics page. Each page's prose now says so plainly, but the ≥85% similarity guard catches none of these (different ASINs / colourways / pack sizes). Consider a variant rule. *[Content, AI]*
- [ ] **Category-intent skew (podcast-studio-mics)** — the weighting rewards background-noise rejection, so live handheld vocal mics (SM58 family, XS 1, MD 445, KSM8) dominate a list readers expect to be broadcast mics. Defensible for untreated rooms and now stated openly in the intro + an FAQ; decide whether to reweight sound warmth for this category. *[Content, Category]*
- [ ] **Unstamped stragglers** (one single-scan each clears them): Keychron C1 `B08CNBL4Z4` (gaming keyboards), Shure SM7dB + MVX2U `B0CX4STCXQ` (mics). *[QA]*
- [x] **coffee2decide rescan rollout COMPLETE (2026-08-16)** — all 6 categories rescanned and all 6 pages rebuilt from health-verified pools: cold-brew-makers (40 verified, 3 bad), super-automatic (56 across Amazon+WLL, 5 bad, 5 dupes ignored), gooseneck-kettles (37, 3 bad), manual-coffee-grinders (33, **0 bad — first fully clean category**), semi-automatic (151 across all 3 stores, 11 bad, 5 dupes ignored, pool 130→125), pour-over (59, 2 bad, picks unchanged). **Both tenants now fully verified: 11/11 landing pages FRESH.** Non-Amazon detection proved out at scale — 53 WLL + 30 Clive offers health-stamped in the semi-auto sweep alone. *[QA, Content]*
- [ ] **Clive Coffee price extraction misses some in-stock products** — post-rescan, 2 of 31 checked Clive offers have `scraped_price = NULL` while `stock_status = in_stock` and no `unavailable` flag, i.e. the page loaded and read as purchasable but no price parsed. One of them (Ascaso Steel DUO, `clivecoffee.com/products/ascaso-steel-duo-programmable…`, product 3627) was a semi-auto landing-page pick and silently dropped out of eligibility as a result. WLL is clean (0/80). Inspect that page's price markup and extend `extractCliveCoffeeProduct()`. *[Extension]*
- [ ] **Rescan error 2026-08-16: Eureka Costanza R (product 3568, WLL)** — the single `errors 1` from the semi-auto walk; offer never health-stamped, `condition`/`listing_flags` still NULL. Its stored URL is collection-scoped (`/collections/semi-automatic-espresso…`). Single-scan it to clear, and check whether collection-scoped WLL URLs are a systematic extractor problem. *[Extension, QA]*
- [ ] **Product 4052 (ECM Estetika, Clive) stuck at `status = failed`** — created during 2026-08-14 QA; `failed` products are excluded from `rescan-list`, so it can never be re-verified. Either re-run its AI evaluation or ignore it. Check for other `status = failed` rows in both tenants. *[Data]*
- [ ] **`price_drift` threshold (15%) is too loose for high-value categories** — the super-auto page read FRESH while its prose quoted a Jura E8 at $2,800 that now sells for $2,550 (−9%) and a De'Longhi at $1,800 now $2,000 (+11%). At espresso-machine prices a sub-threshold drift is still hundreds of dollars of wrong information. Suggest an absolute-dollar arm alongside the percentage (e.g. stale when |Δ| > 15% **or** > ~$100), or a per-category threshold. *[Seo, Freshness]*
- [ ] **Duplicate rows also occur WITHIN a category** — super-auto had 4 same-category exact-ASIN duplicates (3478/3352, 3487/3350, 3489/3354, 3500/3346) plus 1 cross-category (3476 ↔ semi-auto 3411); all 5 ignored 2026-08-15 (pool 48→43). `pw2d:merge-duplicates` is category-scoped and should have caught the same-category four — investigate why it didn't (URL-identical but names differ enough for the fuzzy matcher?). *[Data, Tooling]*
- [ ] **Product 3352 carries feature values from two categories** — the De'Longhi Eletta Explore has both semi-auto features (Espresso Quality, Steam Wand, Heat-Up…) and super-auto features (Beverage Quality, Milk Frothing…), presumably left over from a re-home. Pick-card highlights show the first three feature values, so a re-homed product can display the wrong category's features. Audit for other re-homed products and clear stale `product_feature_values`. *[Data]*
- [ ] **Popup flag tally now misses a third action** — besides not counting `listing_flags` hits, the summary also ignores the new `flagged_offer_condition` (offer flagged, product kept). The super-auto run reported "flagged 0" while finding 1 renewed, 2 refurbished, 1 high-price and 1 unavailable. *[Extension]*

- [x] **Spec 030 BUILT (2026-08-10): Landing-page freshness engine** — `landing_pages.stale_reasons`/`freshness_checked_at` + `est_price_snapshot` in picks JSON (stamped by `GenerateLandingPage`, backfillable via `--backfill-snapshots`). `App\Actions\AuditLandingPageFreshness` computes the 4-reason contract (pick_ineligible/selection_drift/price_drift/render_short); `App\Jobs\AuditLandingPageFreshnessJob` runs it with explicit tenancy init, dispatched (never inline) from a new `ProductObserver` (ignore-flip/category-change/delete) and from `ListingHealthService`'s `high_price` branch. Nightly `pw2d:landing-pages:audit {tenant?}` (also `--backfill-snapshots`) scheduled after `pw2d:seo:pull`; exits FAILURE only on a stale PUBLISHED page. Filament `LandingPageResource`: FRESH/STALE badge column + reasons tooltip, stale-published-first default sort, tenant-scoped cached nav badge. Regeneration (`GenerateLandingPage` save) resets `stale_reasons` to `[]`. 39 new tests, full suite 527 passed / 12 skipped (was 488/12) — no regressions. See `docs/specs/030-landing-page-freshness.md`.
- [~] **Spec 029 (2026-08-09, amended 08-10): Extension Rescan v2 — full-field refresh + listing-health detection** — amendment: Amazon's buy-box "High price" label (DOM-only, kills affiliate conversion) added to detection as `listing_flags` on the OFFER (product stays visible; excluded from landing picks only — unlike condition, which ignores the product). Clean rescans clear flags both ways. — bundles F37 + F38 + Q1 + reviews_count fix + the cleaned-raw_title blindness fix. Driven by: 4 marker-blind renewed picks across 2 QA sessions AND offer staleness data (pw2d 651/652 offers >90d old; c2d 0/418 <30d — the `$$$` tier badges ride on March-April prices). See `docs/specs/029-extension-rescan-condition.md`.
  - [x] **Phase A (builder): server** -- Migrations: `product_offers.{condition,listing_flags,health_checked_at}` + `products.amazon_reviews_count` made nullable (judgment call, logged in `docs/questions.md`). `App\Support\ListingHealth` (condition/flag vocabulary constants) + `App\Services\ListingHealthService::apply()` (single source of truth for condition→ignore / high_price→offer-only-flag / clean→clear, used identically by all 3 ingestion paths; logs a warning naming the landing-page slug the FIRST time a product transitions to ignored while it's a current pick). `OfferIngestionService`: raw `ProductOffer::create()` → `updateOrCreate` keyed on `(product_id, store_id)` (Q1) in all 3 branches; refresh always overwrites `raw_title`, refreshes `image_url`/`stock_status` only when provided non-null (F38), `amazon_reviews_count` refreshes to latest non-null value (never coerced to 0). Same refresh semantics ported to `BatchImportController` (ASIN-dedup branch, +`flagged`/`stock_status`/condition validation) and `ProductImportController`. `SelectLandingPagePicks` excludes products whose BEST offer carries `listing_flags` containing `high_price` (next-best-candidate fallback already existed, reused as-is). `GET /api/extension/rescan-list?category_id={id}` added to `OfferIngestionController`, same middleware group/token as `ingest-offer` — returns the category's non-ignored/processed offers oldest `COALESCE(health_checked_at, updated_at)` first. `App\Services\PriceTierRecalculator` (extracted, chunked — fixes Q13) + `App\Jobs\RecalculateCategoryPriceTiers` (explicit tenancy re-init, guarded against the nested-tenancy gotcha) dispatched once per request after a price updates on an already-existing offer. 32 new tests (`OfferIngestionServiceTest`, `SelectLandingPagePicksTest`, `RescanListControllerTest` — new file, incl. cross-tenant + 403 + ordering, `RecalculateCategoryPriceTiersTest` — new file, `BatchImportControllerTest`/`ProductImportControllerTest` additions), 2 pre-existing tests updated for the new correct behavior (`OfferIngestionTest::existing_offer_url_refreshes_price` now expects the tier-recalc dispatch; `ProductImportControllerTest::import_without_optional_fields_succeeds` now expects `null` reviews_count, not `0`). Full suite: **488 passed / 12 skipped** (458+30 new passed baseline... see exact delta in the builder report; 2 of the 12 skips are new MySQL-gated BatchImportControllerTest cases, matching the pre-existing skip pattern). Decisions logged in `docs/questions.md`. Q1/Q13/F38 closed; reviews_count half of the standing extension ticket closed server-side. *[API, Backend]*
  - [x] **Phase B (frontend/extension): popup.js + content.js + background.js IN SYNC** -- `detectListingHealth()` DOM detection, rescan-walk mode, reviews_count 6th strategy. Payload fields are additive/optional — current extension keeps working unchanged against the now-live Phase A endpoints. *[Extension]* **Reviewed 2026-08-10 (`docs/reviews/029b-extension-review.md`, contract-sync pass, verdict SHIP-WITH-FIXES: 4 blockers + 4 suggestions, all small) — all 8 fixes applied same day (below). Suite 540 passed/13 skipped → 543 passed/14 skipped (+4 new B3/B4 tests, 1 MySQL-gated skip matching the sibling pattern), no regressions; `node --check` clean on all three extension files.**
    - [x] **029B-B1:** background.js — module-level refs to the active `onUpdated` listener + 60s watchdog, both cleared in `pauseRescan()`/`stopRescan()`/top of `processNextRescan()` (`clearRescanPageHandlers()`); a `rescanAttempt` generation counter (bumped on offer start, pause, and advance-consume) invalidates every stale async step, and `advanceRescan(attempt)` is idempotent per offer and never advances while paused. Prevents double-POST/double-count + offer skip on pause→resume mid-load; Resume genuinely retries the SAME offer.
    - [x] **029B-B2:** content.js — `extractReviewsCount()` returns `null` after strategy 0d when the scope is a Document (`el.nodeType === 9`) — card heuristics 1–5 never run document-wide, so a zero-review product page can no longer report a related-product carousel's count.
    - [x] **029B-B3:** BOTH sides. Extension: `condition`/`listing_flags` omitted whenever condition is `'unknown'` — rescan (`handleRescanExtract`), single Amazon import (batch-import payload), and the non-Amazon ingest-offer path (stripped defensively). Server hardening (`ListingHealthService::apply()`): a reported `'unknown'` is now stamp-only — stores `'unknown'` + `health_checked_at` but never clears `listing_flags`, never counts as clean, never dispatches the freshness audit (supersedes audit item "Reviewer S4"'s store-as-is behavior). 2 tests in `OfferIngestionServiceTest` (stamp-only; flags survive + no audit dispatch).
    - [x] **029B-B4 (server, Phase A file):** `ProductConditionGuard::titleCondition()` — single total map from every raw title marker to canonical `ListingHealth` vocabulary (`refurbish→refurbished`, `open box|open-box→open_box`, `pre-owned→used`; unmapped future markers default to `used`, never leak verbatim) — used at all three coercion sites (`OfferIngestionService`, `BatchImportController`, `ProductImportController`). 1 test per path proving a marker-titled existing-offer rescan flags the canonical condition + ignores the product ("Refurbished …"/"(Open Box)"/"(Pre-Owned)").
    - [x] **029B-S1:** dead legacy `START_BATCH` tab-walker deleted from background.js (incl. `SCRAPE_COMPLETE`/`RESUME_BATCH`/`handleRobotDetected`/`handleNextPage`/`scanNextPage` and popup's walker-UI wiring + never-read `autoNextPageCheck`) — its `/api/product-import` payload would 422 today. Batch↔rescan mutual exclusion now covers the LIVE in-popup SERP batch in both directions: popup checks rescan state (GET_STATUS) before starting a batch, and checks its in-flight `serpBatchRunning` flag before START_RESCAN.
    - [x] **029B-S2:** popup.js single Amazon import now sends `stock_status` from `extractAmazonProduct()` — the A1/F38 stock refresh works on that path.
    - [x] **029B-S3:** content.js `conditionMarkerFromText()` — bare `used` marker tightened to parenthetical/leading forms (`(Used)`, `(Certified Used)`, leading `Used …`); "…can be used with…" titles never match. Server-side guard behavior deliberately unchanged.
    - [x] **029B-S4:** popup.js — non-Amazon batch loop tallies `flagged_condition` + `skipped_condition` (unknown future actions land in `skipped`, so the x/y progress line always reaches the total); single-import `actionLabels` covers `flagged_condition`/`skipped_condition`/`skipped_ignored`, and `result.action` is only read from responses that carry it (the Amazon batch-import counter response is mapped flagged/skipped-first).
  - [x] **2026-08-12: `unavailable` listing-flag implemented end-to-end (A2's "room for later", pulled forward — owner found real "Currently unavailable" listings; the old build 422'd the flag server-side and counted those pages `skipped` extension-side, so they never got `health_checked_at` stamped and headed the rescan list forever).** Server: `ListingHealth::RECOGNIZED_FLAGS` += `unavailable` (all 3 validations vocabulary-driven, no rule edits; unknown flags still 422) + new `PICK_EXCLUDING_FLAGS` const — `SelectLandingPagePicks` and `AuditLandingPageFreshness::pick_ineligible` both refactored from hardcoded `high_price` to a flags-intersection over it; `ListingHealthService::apply()` gained a `$payloadStockStatus` param and coerces the offer to `out_of_stock` when the flag arrives without an explicit stock_status (explicit payload value always wins; all 6 call sites pass it); `BatchImportController`'s empty-price delisting heuristic now exempts `unavailable`-flagged payloads (null price is exactly the legitimate unavailable case — product must stay visible). Extension (v1.1 → **1.2**, 3 files in sync): `detectListingHealth()` detects the `#availability` "Currently unavailable" block (buy-box leaf-text fallback) as a FLAG (never a condition; a titled unavailable page also counts as verified, not 'unknown'); `extractProductPageData()` no longer early-returns `{unavailable}` — root cause of the skip — and extracts title/image/reviews normally with price null + stock `out_of_stock`; background.js/popup.js unavailable→skipped special cases removed (rescan counts these as updated). Spec 029 A2/B1/QA updated (new owner QA step 8). 7 new tests (flag stored + stock coercion per path, explicit-stock wins, clean-clear covers unavailable, picks exclusion, freshness pick_ineligible, ingest-offer 422). Suite 543 passed/14 skipped → **550 passed/15 skipped** (+7 new, +1 MySQL-gated skip matching the sibling pattern), no regressions; `node --check` clean on all three extension files. Judgment calls in `docs/questions.md`. *[API, Extension]*
  - [ ] **Phase C (owner): mass rescan** -- ~1,070 offers category-by-category once Phase B ships.
  - [x] **Audit fixes applied (2026-08-10): consolidated review + security + performance pass on the uncommitted Spec 029 Phase A / Spec 030 work** -- three parallel audits (`docs/reviews/audit-2026-08-10-review.md`, `docs/security/audit-2026-08-10-security.md`, `docs/performance/audit-2026-08-10-performance.md`), 3 blockers + 3 medium security + 2 high-perf + assorted mediums/lows, all fixed same day. Baseline 527 passed/12 skipped → **540 passed/13 skipped** (net +13 passed, +1 new MySQL-gated skip matching the existing sibling-test skip pattern), no regressions.
    - [x] **B1 (BLOCKER, Spec 030):** replaced the `picks LIKE` JSON containment in `AuditLandingPageFreshnessJob::dispatchForProduct()` with a PHP-side filter over the tenant's landing pages — the space-free `LIKE` pattern never matches MySQL's normalized JSON string form, so the instant path dispatched nothing in prod (only sqlite's TEXT-backed `json()` column hid this). Removed the "product_id first key" coupling comment in `GenerateLandingPage`. sqlite/MySQL JSON lesson logged in `docs/lessons.md`. **Post-deploy: tinker-verify one dispatch on prod MySQL** (still outstanding — cannot be done pre-deploy).
    - [x] **B2 (BLOCKER, Spec 029):** moved the `ProductConditionGuard::matchesTitle` early skip to after the existing-offer lookup (and after the AI/heuristic-match attempt) in all three ingestion paths — an existing offer or a matched product now always goes through refresh + `ListingHealthService::apply()` (title-marker-as-condition-evidence coercion when the payload didn't supply an explicit `condition`), never `skipped_condition`. The skip now fires only on the genuinely-new-product create path (and is bypassed there too when an explicit `condition` was supplied). Tests per path (`OfferIngestionServiceTest`, `BatchImportControllerTest`, `ProductImportControllerTest`).
    - [x] **B3 / Security M3 / Perf M3 (one coherent Filament fix, Spec 030):** picks Repeater's `product_id`/`role` switched from `disabled()->dehydrated()` (client-tamperable) to `disabled()->dehydrated(false)`; `EditLandingPage::mutateFormDataBeforeSave()` force-restores `product_id`/`role`/`est_price_snapshot`/`product_name` from the stored record by index (repeater still disallows add/delete/reorder) — a Filament save can no longer change pick identity or wipe the price-drift baseline, even via a crafted Livewire payload. `itemLabel` now reads a `product_name` key persisted into picks JSON at generation time (`GenerateLandingPage`) — zero queries per render — falling back to a TENANT-SCOPED `Product::find()` (never `withoutGlobalScopes()`, which was a cross-tenant name-disclosure risk) only for pre-existing pages. Regression tests: tampered-payload save cannot change identity/wipe snapshot; label shows the persisted name without a cross-tenant lookup.
    - [x] **Security M1:** `ProductImportController` re-import/refresh no longer resets `is_ignored => false` — an already-ignored product now returns `action: skipped_ignored` before any update, honoring Spec 029's explicit non-goal (reversal stays a human Filament decision).
    - [x] **Security M2:** `OfferIngestionService`'s AI-match existence check now scopes by `tenant_id` (was `withoutGlobalScopes()` with no tenant filter) — a poisoned/stale `ai_matching_decisions` cache row can no longer attach this tenant's offer to another tenant's product. Cross-tenant regression test added.
    - [x] **Perf H1 / Reviewer S5:** `RecalculateCategoryPriceTiers` gained `ShouldBeUnique` (`uniqueId(): tenant:category`, `uniqueFor` 600) and `OfferIngestionService`/`ProductImportController` now dispatch it only when the refreshed offer's `scraped_price` actually `wasChanged()` — a redundant rescan no longer queues a job. `BatchImportController` already batched to once/request; left as-is per the perf audit's own note.
    - [x] **Perf H2:** `AuditLandingPageFreshnessJob` gained `ShouldBeUnique` keyed on the landing page id, `uniqueFor` 600 — a burst of flag events on the same page's picks no longer queues one redundant full audit per event.
    - [x] **Perf M2 / Reviewer N4:** `SelectLandingPagePicks`'s offers eager-load now includes `store_id` + eager `offers.store` — `best_offer`'s commission/priority tiebreak was silently inert without it, risking a price-tie disagreement with `AuditLandingPageFreshness` (which already loaded `offers.store`).
    - [x] **Reviewer S1:** `ListingHealthService::apply()` now dispatches the instant freshness audit on ANY material `listing_flags`/`condition` change (`$offer->wasChanged(...)`) in both clean branches — both directions (a flag being set AND a flag clearing, e.g. a `high_price` listing recovering) — not only on a fresh `high_price` set.
    - [x] **Reviewer S2:** `BatchImportController`/`ProductImportController` refresh branches now guard `amazon_rating` exactly like `OfferIngestionService` (`!empty($rating) && empty($product->amazon_rating)`) — a rating-less rescan no longer nulls a previously known rating.
    - [x] **Reviewer S4:** the clean-report branch in `ListingHealthService` now stores the REPORTED condition value (`new` or `unknown`) as-is instead of coercing `unknown` → `new` — an `unknown` report no longer overstates what was actually verified.
    - [x] **Security L1:** `LandingPageResource::getNavigationBadge()` returns `null` early when `tenant('id')` is null (central context) instead of computing an unscoped all-tenants count under a degenerate shared cache key.
    - [x] **Security L2:** `listing_flags` capped at `max:5` + `distinct` in all three request/validation definitions (`OfferIngestionController::ingest`, `BatchImportRequest`, `ProductImportRequest`).
    - [x] **Security L3:** `rescan-list` capped at `->limit(500)` — the extension walks sequentially and can simply re-request as `health_checked_at` advances, so this never loses coverage.
    - [x] **Security L7:** dropped the dead `'updated_at' => $now` mass-assignment inside `BatchImportController`'s offer `update()` (silently discarded — not fillable; Eloquent touches the timestamp anyway).
    - [x] **Reviewer N-item:** migration `2026_08_10_000002`'s `down()` now backfills `NULL → 0` on `amazon_reviews_count` before re-adding the `NOT NULL DEFAULT 0` constraint, so a rollback no longer fails on MySQL once any row has a null value.
    - Deferred (not done, logged for later): **Reviewer S6** (Form Request extraction for `OfferIngestionController::ingest`/`rescanList`) folded into the existing Q2 Form-Request-extraction backlog item. **Perf M1** (`(store_id, url(120))` prefix index on `product_offers` — the hottest ingest-lookup query) needs MySQL-only migration care (raw `ALTER TABLE`, no portable Laravel schema-builder equivalent for a column-prefix index) — logged as a standalone todo, not attempted here.
    - [ ] **Perf M1 (deferred, todo):** add a `(store_id, url(120))` MySQL prefix index on `product_offers` (`OfferIngestionService`'s URL-dedup lookup scans the full store's offers on every POST) — needs a MySQL-only raw-statement migration, verify separately from the sqlite test suite before writing it.
    - Decisions logged in `docs/questions.md` (B2 guard-placement tradeoff, guard-bypass-on-explicit-condition applied uniformly across all 3 paths, `product_name` picks-JSON shape addition, index-position-based restore in `mutateFormDataBeforeSave`). Reusable patterns + the two new gotchas (raw SQL vs JSON columns; `foreach ($x ?? [] as &$y)` binding to a temporary) logged in `.claude/memory/builder/patterns.md` and `docs/lessons.md`.
- [ ] **c2d leaf #5: Electric Burr Grinders (owner decision 2026-08-09; slot #6 stays open)** — launch checklist: (1) create leaf category in Filament (name/slug/parent, budget_max/midrange_max); (2) AI-generate features + buying guide via EditCategory; (3) attach the 11 detached seed products (category assign) + feature rescan; (4) presets (2-3, e.g. espresso-focused / filter-brewer / low-effort); (5) verify compare page renders + sitemap pickup; (6) landing page only AFTER the category has GSC signal (don't publish /best/ on day one).
- [x] **Owner QA on live /best/podcast-studio-mics — 2 fixes applied (AWAITING /deploy)** — (1) pick image unbounded on desktop (`md:h-full` never resolved → tall portrait images stretched the card): image column now `md:flex md:items-center`, anchor `h-56 md:h-64`, img `max-h-full max-w-full object-contain`. (2) "Full product details" stranded users on /compare after drawer close (2 Backs to return): link now opens in a new tab (`target="_blank" rel="noopener noreferrer"`), matching the affiliate CTA; "See how it compares" unchanged. +1 assertion in `LandingPageControllerTest`. Suite 457 passed / 10 skipped. *[Frontend]*
- [x] **Cannibalization watch — CLOSED 2026-08-17, no cannibalization.** Over the six weeks spanning the /best/ launch, `/compare/super-automatic-espresso-machines` held ~90 impr/wk and its weighted position *improved* 63→48, while `/best/` added 51 impressions of separate surface. Both URLs coexist on the cluster with no measurable transfer. No internal-link nudge needed. Original note: c2d "best super automatic espresso machine" is served by BOTH /compare (~pos 61) and /best/ (~pos 75-90) — /best/ should win it as it ages; if /compare keeps it long-term, consider a canonical-ish internal-link nudge (NOT a redirect).

- [x] **F38: Offer-refresh paths drop image_url (owner hit this on G715)** -- Extension re-scan of an existing offer updates price only: `OfferIngestionService` URL-dedup branch and the batch-import ASIN-dedup branch ignore the incoming `image_url` (and stock_status). Fix: on refresh, also update `image_url`/`stock_status` when provided. Fixed as part of Spec 029 Phase A (T1) — see below. Related root cause found same day (still open, not part of Spec 029): 8 products had `image_path` pointing at files missing from disk (image_url accessor prefers local path without existence check) — data-fixed by nulling; consider a `pw2d:verify-images` integrity command or re-download from offer image_url. *[API, Extension-adjacent]*
- [x] **2026-08-12: Fix pick-eligibility null-best-offer blind spot in `SelectLandingPagePicks`/`AuditLandingPageFreshness` (live prod incident)** — a category rescan set two products' only offers to `listing_flags: ["unavailable"]` + `scraped_price: NULL`; both products still got selected as landing-page picks (one ranked #1 "Best Overall") because `hasPickExcludingFlag()` read flags off `$product->best_offer`, and `Product::bestOffer` itself excludes null-price offers — a product whose ONLY offer was flagged + priceless had no `best_offer` at all, so the flag check had nothing to inspect and silently passed it as eligible. Fixed with a single coherent rule in both files, checked across EVERY offer (never skippable by best-offer absence): a product is pick-eligible only if it has at least one offer that is simultaneously priced (`scraped_price` non-null and > 0), free of `ListingHealth::PICK_EXCLUDING_FLAGS`, and (Audit only — Select's condition check stays the separate, unchanged, product-level `ProductConditionGuard` text-marker check) free of `ListingHealth::NEGATIVE_CONDITIONS`. +4 new tests (Select: null-price+unavailable-only-offer excluded, multi-offer partial-eligibility selectable, all-offers-null-price excluded; Audit: stored pick's only offer going flagged+priceless now fires `pick_ineligible`). Test-fixture ripple: `SelectLandingPagePicksTest` and `GenerateLandingPageCommandTest`'s "eligible product" helpers previously defaulted `scraped_price` to `NULL` (harmless under the old price-blind rule) — both now default to a buyable `scraped_price => 100` so existing "eligible" fixtures stay eligible under the new price requirement. Suite 550 passed/17 skipped → **554 passed/17 skipped** (+4 new), no regressions. Judgment calls (semantics widened from "best/cheapest offer only" to "any offer"; condition-column check folded into Audit's per-offer test) in `docs/questions.md`. *[API]*
- [x] **2026-08-12: Fix BatchImportController's no-price delisting heuristic firing before condition/flag evidence (live QA, product 1744/B09Z6PM1PV)** — a RENEWED re-listing with no extractable price hit the dead-listing heuristic first, got `is_ignored=true` for the wrong reason, counted as `refreshed` (popup said "Price refreshed"), and `continue`d past the offer heal/health stamp/`ListingHealthService::apply()` — the offer never recorded WHY it was ignored and stayed at the head of the rescan list forever. Fix: hoisted `$effectiveCondition` (payload `condition` ?? `ProductConditionGuard::titleCondition($title)`) above the heuristic and narrowed its trigger to `empty(price) && effectiveCondition not in ListingHealth::NEGATIVE_CONDITIONS && empty(listing_flags)` — any negative condition or recognized flag now falls through to the normal refresh path so `ListingHealthService::apply()` runs and reports `flagged_condition`. +2 regression tests (renewed-condition-with-no-price now heals + flags; high_price-flag-with-no-price no longer wrongly delisted) + strengthened the existing plain-dead-listing test (asserts `refreshed`/offer untouched). Verified against a real local MySQL DB (not just sqlite) — surfaced 3 pre-existing, unrelated MySQL-only test failures in this file (not caused by this fix); logged in `docs/questions.md`, left untouched (out of scope). Full suite: 550 passed / 17 skipped (was 550/15, +2 new MySQL-gated tests), no regressions. *[API]*
- [x] **2026-08-14: Availability (out-of-stock) detection for the three non-Amazon store extractors — Chrome extension v1.3 (Spec 029 B1b)** — c2d has 116 rescannable non-Amazon offers (86 wholelattelove.com, 30 clivecoffee.com) whose extractors sent no `condition` and no `listing_flags`, so `ListingHealthService::apply()` returned immediately: those offers refreshed price/title/image but never got `health_checked_at` stamped, and a sold-out/discontinued listing was never flagged — it could still be selected as a landing-page pick. Added one shared `detectStoreAvailability(doc, titleFound)` helper in `content.js` (all selector/text logic in a single commented function, mirroring `detectListingHealth()`), wired into `extractCliveCoffeeProduct`/`extractSeattleCoffeeGearProduct`/`extractWholeLatteLoveProduct`; each now also sends `stock_status` (`out_of_stock`/`in_stock`). Deliberate scope: `condition` is only ever `new` (title found — authorised dealers selling new goods; this is what lets the server stamp `health_checked_at` and CLEAR a stale flag on restock) or `unknown` (no title — never fabricate `new` for a page that didn't load); NO renewed/refurb guessing, NO `high_price` (Amazon-specific buy-box label). `unavailable` uses 4 independent layers — JSON-LD `offers.availability` (recursive key walk; decisive: any purchasable variant wins), `product:availability`/`og:availability` meta, add-to-cart button state (with a hydration guard: a disabled ATC still labelled "Add to cart" is NOT sold out), and scoped inventory/exact-leaf text — because live-page selectors could not be verified by the builder. Positive match always required. Price nulled only when absent/placeholder, never when the page genuinely displays one. Amazon path untouched; `popup.js`/`background.js` needed NO change (verified — both already forward `condition`/`listing_flags` store-agnostically and tally `unavailable` as `updated`). manifest 1.2→1.3, no new permissions. Verification: `node --check` on all three JS files + manifest JSON parses; built a throwaway jsdom fixture harness (scratchpad, not committed) — 64 assertions covering every layer, kebab-case spellings, multi-variant JSON-LD, the hydration guard, the related-products-badge false positive, `<option>` exclusion, full payload shapes, and 4 Amazon regressions. It caught one real bug pre-commit (`sold-out`/`out-of-stock` kebab-case missed by a `\s*` separator → now `[\s_-]*` everywhere). No PHP changes; full suite **554 passed / 17 skipped**, matching baseline. **Live-selector verification against real Clive/WLL pages is owner QA (new Phase B QA step 9) — the builder cannot load those sites.** Judgment calls in `docs/questions.md`. *[Extension]*
- [x] **2026-08-15: Condition-marker detection for the three non-Amazon store extractors — Chrome extension v1.4 (Spec 029 B1b)** — v1.3 shipped `detectStoreAvailability()` reporting a blanket `condition: 'new'` whenever a product title was found, on the assumption that Clive/SCG/WLL are authorised dealers selling only new goods. **Live owner QA disproved it: Whole Latte Love sells a refurbished line with "Refurbished" in the product title** (`/products/refurbished-lelit-anna-espresso-machine`). `new` is the server's CLEAN branch, so v1.3 was telling the server those listings were verified clean and clearing any prior condition flag. Fix: `detectStoreAvailability(doc, title)` now takes the raw title string (was a boolean) and reports markers, reusing the extension's single shared `conditionMarkerFromText()` — the same list the Amazon path uses, mirroring `ProductConditionGuard::TITLE_MARKERS` naming and mapping into `ListingHealth::CONDITIONS` (`renewed`, `refurbish*`→`refurbished`, `open box`/`open-box`→`open_box`, `pre-owned`/`used`→`used`). Bare "used" stays positionally guarded exactly as on Amazon (parenthetical or leading only, so "can be used with…" never fires); "Refurbished" matches anywhere (WLL prefixes it). Three marker sources in trust order: title → structured first-party condition (schema.org `itemCondition` + `<meta property="product:condition">`, collected in the SAME single JSON-LD walk as `availability`, so no second parse) → product-type/badge/breadcrumb text (badges scoped to the product container; >120-char blobs ignored). `new` only when a title was found AND nothing matched; `unknown` (flags withheld) when no title. No Amazon-specific `high_price` for these stores; all v1.3 availability behaviour unchanged (the product-form scope lookup was hoisted so both the badge scan and layers c/d share it). manifest 1.3→1.4, no new permissions. `popup.js`/`background.js` needed NO change (re-verified — both forward `condition`/`listing_flags` store-agnostically and already label/tally `flagged_condition`). Verification: `node --check` on all three JS files + manifest parses; scratchpad jsdom harness, **25 assertions** (the live WLL refurbished case, "can be used with" false-positive guard, every marker form, structured `itemCondition`, meta, breadcrumb, in-scope badge vs related-products badge, no-title→unknown, refurbished+sold-out together, legacy boolean callers, plus 6 v1.3 availability regressions) — all pass. No PHP touched (parallel agent owns the server side). **Live-selector confirmation is owner QA (new Phase B QA step 10: single-scan the Refurbished Lelit Anna URL, expect `condition = refurbished` + product ignored, and one ordinary WLL machine, expect `new`).** Judgment calls in `docs/questions.md`. *[Extension]*
- [x] **2026-08-15: Three server-side listing-health holes closed (companion to the v1.4 extension fix above) — Spec 029 §A2** — same live QA (Whole Latte Love's refurbished line, product 3500 De'Longhi ECAM22110SB with a clean $670 Amazon offer + a $720 refurbished WLL offer, product 3648 Gaggia Magenta Prestige with ONLY a refurbished WLL offer) exposed three server holes the extension fix alone couldn't close:
  - **Fix 1 — explicit payload `'new'` no longer overrides a title marker.** All 3 ingestion coercion sites (`OfferIngestionService`, `BatchImportController`, `ProductImportController` existing-offer branches) did `$condition ?? ProductConditionGuard::titleCondition($title)` — an explicit `'new'` skipped the title guard entirely, so a "Refurbished …" title sent with `condition: 'new'` would store `new`, clear any flag, and stamp itself verified-clean. New shared `ProductConditionGuard::resolveEffectiveCondition($condition, $title)` (one helper, all 3 call sites): a negative title marker wins over payload `'new'`; an explicit negative payload condition wins over everything; `'unknown'` is never overridden by the title (unchanged stamp-only behavior).
  - **Fix 2 — a negative condition flags the OFFER; the PRODUCT is ignored only when no clean offer survives.** `ListingHealthService::apply()` always stores the condition on the offer now, then ignores the product only if `hasCleanOffer()` (priced >0, condition not negative, no `PICK_EXCLUDING_FLAGS`) finds no other purchasable offer — so a refurbished WLL listing next to a clean Amazon one flags the WLL offer without deleting the product from the catalogue. New `ACTION_FLAGGED_OFFER_CONDITION` (`'flagged_offer_condition'`) distinguishes this from the existing product-level `ACTION_FLAGGED_CONDITION`; all 3 controllers/service updated to use the constants (AI dispatch/flagged-counter logic keys off which one fired). Reversal still stays a human decision (Spec 029 non-goal, unchanged) — a rescan that clears the last bad condition on an already-ignored product does NOT auto-un-ignore, but now logs a `Log::notice` naming the product so the owner can spot and review it.
  - **Fix 3 — `Product::bestOffer` (and `best_price`/`affiliate_url`/`estimated_price`, which all derive from it) never links to a bad listing.** Added the same `NEGATIVE_CONDITIONS`/`PICK_EXCLUDING_FLAGS` exclusion `SelectLandingPagePicks`/`AuditLandingPageFreshness` already use, alongside the existing null-price filter — a cheaper refurbished/flagged offer can no longer win the "best" slot over a pricier clean one. `bestPrice` changed from an independent `offers->min('scraped_price')` to `$this->best_offer?->scraped_price`, so the displayed price and the linked affiliate URL always describe the SAME offer (previously they could silently disagree — the exact "price shown, link elsewhere" bug this was called in to fix). Audited every `bestOffer`/`best_price` caller repo-wide for narrow-select and independent-computation assumptions this could break; fixed two callers whose eager-loaded `offers:` column list omitted `condition`/`listing_flags` (`PriceTierRecalculator`, `SelectLandingPagePicks`) — an unselected column silently reads as null/clean, which would have made the exclusion inert there. Left `ProductCompare::scoredProducts()`'s own independent price computation (cached raw-array scoring/filter path, not the accessor) and 2 Filament admin diagnostic pages (`ProductResource`, `ProblemProducts`, deliberately showing raw min-price across ALL offers for admin review) untouched — consistent with the standing Spec 029 precedent ("Compare pages unchanged for now … revisit if CTR data says otherwise").
  - 28 new tests (`ProductConditionGuardTest` new file — precedence matrix; `ProductBestOfferTest` — condition/flag exclusion, best_price/affiliate_url derive from the same excluded offer; `OfferIngestionServiceTest`, `ProductImportControllerTest`, `BatchImportControllerTest` — Fix 1/2 end-to-end incl. the exact product-3500/3648 shapes). Full suite: 554 passed/17 skipped → **580 passed/19 skipped** (+26 passed, +2 new MySQL-gated `BatchImportControllerTest` skips matching the sibling pattern), no regressions. Judgment calls in `docs/questions.md`; Spec 029 §A2 updated with the offer-vs-product distinction and the title-marker precedence rule. No `chrome_extension/` changes (parallel agent owns it). *[API, Backend]*
- [x] **2026-08-16: "Verify Live Picks" picks-only rescan mode — Chrome extension v1.5 (Spec 031 T2)** — the weekly Tier 1 cadence pass: one button that walks every live pick on every guide (~100 offers, ~17 min) with **no category selection**, so the routine covers all 11 landing pages in a single run. Built by *parameterising* the existing Spec 029 B2 rescan walk rather than forking it: `rescanRun.scope` (`'category' | 'picks'`) changes only (a) the work-list URL (`/api/extension/rescan-list?scope=picks` vs `?category_id=`), (b) the wording, and (c) the flagged-guide tally. The 3–5s polite delay, 60s page-load watchdog, single reused background worker tab, CAPTCHA auto-pause, `rescanAttempt` generation-counter guards, idempotent `advanceRescan()`, and `chrome.storage.local` persistence/restore-as-paused are shared verbatim — Pause/Resume/Stop and service-worker-restart recovery behave identically in both modes. popup.js's two Start buttons both call one `beginRescanRun(scope)`; popup.html gains a *Verify Live Picks* section and the progress panel is now shared (label switches to *Verifying picks*, both Start buttons hide while any run is active). Mutual exclusion holds in both directions and now covers three flows: the SERP batch reads `GET_STATUS.active` (message names which mode is running), `START_RESCAN` refuses whenever a run is active, and because picks/category share ONE run object they exclude each other for free. Flagged picks are reported by guide — each row's `landing_page_slug` is tallied into `results.flagged_guides` on a condition verdict (`flagged_condition`/`skipped_condition`) **or** a `high_price`/`unavailable` listing flag, rendered as `flagged: gaming-chat-headsets` (count appended when >1) and coloured amber, because the owner's next action is a full rescan of exactly that category (Spec 031 response rule). Deliberately broader than the adjacent `flagged` counter, whose undercount is an explicit Spec 031 out-of-scope item — left untouched. manifest 1.4→1.5; **no new permissions** (verified: the picks fetch hits the same `baseUrl` origins already in `host_permissions`, and the walk uses the same tab APIs as today). Verification: `node --check` passes on popup.js/background.js/content.js, manifest.json parses, and every DOM id popup.js touches exists in popup.html. **A live end-to-end run is owner QA (new "Owner QA — Verify Live Picks" section in Spec 031) and is BLOCKED on a server follow-up:** T1 as landed does not return `category_id` on `scope=picks` rows, but `/api/extension/ingest-offer` validates it as `required` and a picks run spans categories — so the extension preflights the work list and refuses to start with a naming error rather than walking ~100 pages that would each 422. Logged as an open T1 follow-up in Spec 031's task breakdown. No `app/` or `tests/` files touched (parallel agent owns the server side). Judgment calls in `docs/questions.md`. *[Extension]*
- [x] **2026-08-16: `scope=picks` picks-only rescan mode on `GET /api/extension/rescan-list` — server (Spec 031 T1)** — enables the weekly Tier 1 cadence pass: `rescanList()` now branches on an optional `scope` param (`nullable`, `Rule::in(['category','picks'])`, default `category`); `categoryScopeOffers()` is today's query extracted verbatim (unchanged behaviour, all its tests pass unmodified); new `picksScopeOffers()` returns **every offer** (not just `best_offer` — a store can flip which one is "best" between health checks) of **every product that is a pick on ANY of this tenant's landing pages**, any status (draft included — a draft is about to publish) and any category, same auth/tenant-scoping/`limit(500)`/`COALESCE(health_checked_at, updated_at)` ordering as today. Pick product IDs are collected by a new `pickProductSlugs()`, filtered **in PHP** over the tenant's own bounded landing-page set — never a `LIKE` against the `picks` JSON column, reusing `AuditLandingPageFreshnessJob::dispatchForProduct()`'s established pattern (Spec 030 §B1: MySQL re-serializes a native `json` column to a space-padded string on cast to text, so a space-free `LIKE '%"product_id":N,%'` pattern silently matches nothing in production while passing on sqlite). Two builder decisions, both logged in `docs/questions.md`: (1) `category_id` under `scope=picks` is **ignored, not validated** — dropped from the validation rules array entirely, per the owner's stated preference that a stale client param must never silently narrow the sweep; (2) when a product is a pick on more than one landing page, the row reports the **first landing page by `slug` ASC** (deterministic, arbitrary) rather than duplicating the offer row per page. `scope=picks` deliberately does NOT filter by `is_ignored`/`status` the way `scope=category` does — a pick that has drifted into either state is exactly the drift this pass exists to surface. Each row also gains `landing_page_slug` (so the extension can name the affected guide) and, after a same-day fix below, `category_id`. **Same-day fix:** the parallel T2 (extension) agent's own preflight check in `popup.js` caught that `POST /api/extension/ingest-offer` requires `category_id`, and a picks run has no single category to supply — `picksScopeOffers()` was missing it. Fixed by eager-loading `product:id,category_id` and mapping the offer's own product's `category_id` into every row (never the ignored request param); +1 regression test. 14 new tests in `tests/Feature/RescanListControllerTest.php` (19 total in the file): exact pick-offer set, multi-store offers, draft-page picks, ignored/pending picks still returned, non-pick siblings excluded, oldest-first ordering, `landing_page_slug` correctness + multi-page tie-break, `category_id` correctness, cross-tenant isolation, `category_id` fully ignored under `scope=picks` (two categories' picks both returned regardless of which `category_id` — including a nonexistent one — is supplied), invalid `scope` value → 422, 403 without a valid extension token, existing `scope=category` behaviour/tests untouched. Full suite: 580 passed/19 skipped → **594 passed/19 skipped** (+14 new), no regressions. No `chrome_extension/` files touched (parallel agent owns it). *[API]*
- [x] **2026-08-16: Tenant picker — `GET /api/extension/tenants` + Chrome extension v1.6** — the free-text "Tenant ID" input in the popup's settings panel is now a populated `<select>`, so the owner picks a tenant instead of looking the exact id up every time. **Server:** new `App\Http\Controllers\Api\TenantListController::index()` returning `{success: true, tenants: [{id, name}]}` for all tenants, ordered by the same string it displays (`COALESCE(NULLIF(name, ''), id)` — `tenants.name` is a real, non-null column per `Tenant::getCustomColumns()`, so no `data`-JSON/VirtualColumn access is needed and the branding column is never exposed). Registered in `routes/api.php` under `VerifyExtensionToken` + `throttle:60,1` **only** — deliberately NOT behind `InitializeTenancyFromPayload`, which 422s "Tenant ID required." without the header; the endpoint exists precisely to discover ids *before* one is chosen. **Extension:** `tenantInput`/`saveTenantBtn` replaced by `tenantSelect` + a `tenantHint` inline message line; `fetchTenants()`/`renderTenantOptions()` populate on popup open, after a token save, and on environment switch. Same `chrome.storage.local.tenantId` key (existing installs unaffected), saves on change, and `TENANT_ID` remains the single source of truth for all ~8 `X-Tenant-Id` fetches, which are untouched. A failed/403/empty fetch **never** clears the saved id — it stays listed and selected as `"<id> (saved)"`. manifest 1.5 → 1.6, no new permissions (endpoint rides `${baseUrl}`, already in `host_permissions`). 10 new tests (`ExtensionTenantListTest` — shape, ordering, id-fallback label, 403 without/with wrong/unconfigured token, and the regression that matters: **succeeds with no `X-Tenant-Id` header**, and with an unknown one). Full suite: 594 passed/19 skipped → **604 passed/19 skipped**, no regressions. `node --check` clean on all three extension JS files; every `getElementById` in popup.js verified to resolve against popup.html. Decisions in `docs/questions.md`. *[API, Extension]*
- [x] **Extension-side fixes from the 2026-08-16 audit — B4, B3, security M3 (manifest 1.6 → 1.7)** *[Extension]* — **B4 (the one that mattered):** the new `flagged_offer_condition` action was tallied as `updated` and never reached `noteFlaggedGuide()`, so the exact multi-store case Spec 029 §A2b exists for reported "flagged 0" with **no guide named** — meaning `flagged_guides`, the line Spec 031's response rule keys off, had the same blind spot as the counter it was introduced to cover for. Replaced both `else` fallthroughs (`background.js` rescan walk + `popup.js` SERP batch loop) with explicit action→bucket tables covering all nine actions the three ingestion controllers + `OfferIngestionService` can return; an unrecognised action now lands in its own `unknown_action` bucket, is `console.warn`ed, **and names its guide** rather than being silently misclassified as clean. Mapping documented in `docs/specs/031` and in a comment at both tables. Also added the missing `flagged_offer_condition` entry to `popup.js`'s `actionLabels` (audit N1). **B3:** one detached pick (`category_id: null`, a legitimate staleness finding) made the picks preflight refuse the whole ~100-offer weekly run with "Update the server first". Now skips such rows individually, counts them, passes the count to the background run so it survives the popup closing, and reports `3 skipped: no category` in the summary — aborting only when *no* row has a `category_id` (the genuinely-outdated-server case). Correct independently of whether the server also stops emitting those rows. **Security M3:** `background.js` navigated an unattended weekly walk of ~100 tabs to server-supplied `offer.url` with no scheme or host check. Added one allowlist (`https:` + `amazon.com`/`clivecoffee.com`/`seattlecoffeegear.com`/`wholelattelove.com`, `www.` stripped — exactly `extractStoreProduct()`'s routing set), checked in the shared `processNextRescan()` so both walk modes are covered by construction; off-allowlist rows counted as `blocked`, never navigated. Summary line gained three counters that render only when non-zero, so a clean run's shape is unchanged. Verified: `node --check` clean on all three JS files, `manifest.json` parses at 1.7, all 25 `getElementById` in popup.js resolve in popup.html, and a throwaway Node harness exercised the extracted pure logic (55 assertions — allowlist accept/reject incl. `amazon.com.evil.test` and `notamazon.com`, all nine actions bucketed, both tables cover the same action set, every label present, summary rendering incl. a legacy results object). **No live behaviour verified — there is no JS test harness; the first real picks run is owner QA.** Server-side blockers B1/B2 and the rest of the security findings are a parallel agent's; nothing under `app/`, `routes/` or `tests/` was touched. Decisions in `docs/questions.md`.

- [x] **2026-08-16: SERVER-side fixes from the three-agent audit (review + security + performance) — `app/` and `tests/` only, `chrome_extension/` owned by a parallel agent** *[API, Backend, Filament]* — see `docs/reviews/audit-2026-08-16-review.md`, `docs/security/audit-2026-08-16-security.md`, `docs/performance/audit-2026-08-16-performance.md`. Full suite 604 passed/19 skipped baseline → **see exact count in the builder session report** (no regressions).
  - **B1 (LANDMINE, Review):** `ProductConditionGuard`'s bare `used` marker was a naive `str_contains()` scan — matched "fo**cused**", "h**oused**", "**unused**", "aro**used**". Fix 1's precedence rule (2026-08-15) had just given a title marker veto power over an explicit `condition: 'new'`, so the next mass rescan of any long mic/product title containing one of those words would have silently stored `condition='used'` and (for a single-offer product) ignored it. Ported the extension's already-correct positional guard (`content.js::conditionMarkerFromText`) into a `MARKER_PATTERNS` regex map (`\brenewed\b`, `refurbish` unanchored, `open[\s-]?box`, `\bpre-?owned\b`, and `used` only parenthetical/leading) — every OTHER marker's matching behavior is byte-identical to before. 19 new tests in `ProductConditionGuardTest` (0 → 19; the file had zero tests before this fix) covering every false-positive word from the audit table plus the true positives `(Used)`, `Used - Like New`, and mid-title `Renewed`.
  - **B2 / M3 (Review + Perf):** `SelectLandingPagePicks::hasEligibleOffer()` was the only one of four "is this offer purchasable" copies that omitted `ListingHealth::NEGATIVE_CONDITIONS` — a product whose only eligible offer went `renewed`/`refurbished` with a clean title (extension v1.4's non-title condition sources) was selectable as a pick, rendered with no price/CTA, and left the page `pick_ineligible` forever because Select kept re-picking the same product. Fixed as part of the S2 predicate extraction below. Regression tests: a negative-condition-only-offer product is excluded even with a clean title/summary; every returned pick resolves to a non-null `best_offer`.
  - **S2 (Review) root-cause fix — one shared predicate:** extracted `ListingHealth::isPurchasable(ProductOffer $offer, bool $requirePositivePrice = true): bool` (priced > 0, not `NEGATIVE_CONDITIONS`, no `PICK_EXCLUDING_FLAGS` flag, and — when `offers.store` happens to be eager-loaded — `Store::is_active`, see Sec M2 below) + a `ListingHealth::OFFER_HEALTH_COLUMNS = ['condition','listing_flags']` constant. `Product::bestOffer`, `ListingHealthService::hasCleanOffer()`, `SelectLandingPagePicks::hasEligibleOffer()`, and `AuditLandingPageFreshness::hasEligibleOffer()` all now call the one predicate — this is what fixes B2 and prevents it from recurring a fifth time. Every narrow `offers:col1,col2,...` eager-load site (`ProductCompare`, `PriceTierRecalculator`, `SelectLandingPagePicks`, `ProductResource`, `FlagConditionProducts`) now builds its column list off `OFFER_HEALTH_COLUMNS` instead of a hand-typed literal.
  - **S3 (Review):** `Product::bestOffer` accepted `scraped_price = 0` (a `~$0` pick card) while its three mirrors required `> 0` — fixed for free by `isPurchasable()`'s `$requirePositivePrice = true` default. 2 new tests (`ProductBestOfferTest`).
  - **Sec M2 (Security):** `Product::bestOffer` ignored `Store::is_active` — decided to HONOUR it (the audit's own suggested incident response for a bad/rogue store) via `isPurchasable()`, but only when `store` is actually eager-loaded (`$offer->store === null` — absent FK or no eager-load — is treated as "unknown, don't exclude," never a lazy per-offer query). `ListingHealthService::hasCleanOffer()` now eager-loads `store:id,is_active` explicitly (rare call path, one extra query acceptable); `SelectLandingPagePicks`/`AuditLandingPageFreshness` already eager-load `offers.store` so the check is live there for free; `ProductCompare`/`PriceTierRecalculator`/`ProductResource` deliberately do NOT select `store_id` (Perf H2 — would cost a Store query per offer for a check those surfaces don't need), so `is_active` is a no-op there by design, not an oversight. 2 new tests. Logged as a judgment call in `docs/questions.md`.
  - **Perf C1 / Review S1 (Perf):** `ProductCompare::scoredProducts()` eager-loaded `offers:id,product_id,scraped_price` (no health columns) and computed `'best_price' => $p->offers->min('scraped_price')` directly, bypassing the accessor — a product whose only priced offer was flagged/negative-condition still ranked, still passed the price slider, and rendered with **no "Check Current Price" CTA at all** (the same card's `affiliate_url` already used the filtered `best_offer`). Fixed: added the health columns, switched to `$p->best_price`, mirrored the `NEGATIVE_CONDITIONS` exclusion into the `whereHas` price-slider filter (with a `whereNull('condition')` escape hatch so a never-DOM-checked offer isn't wrongly excluded — the flags JSON check isn't sargable and is left to the PHP-side `best_price`, per the audit's own accepted compromise), and bumped the 90s cache key to `products:v2:...` so no stale pre-fix entry survives the deploy. 2 new tests (`ProductCompareTest`) — a flagged-only-offer product scores with `estimated_price === null` matching its null `affiliate_url`; a $50 refurbished offer does not admit the product through a $100 slider.
  - **Perf H1 (Perf):** `AuditLandingPageFreshness::execute()` always called `$page->update()`, whose `saved` hook forgets the 1h landing-page view-model cache AND the tenant sitemap cache — 11 forced cold rebuilds every night (plus one per instant-path no-op audit during a picks pass) for pages that hadn't changed. Now compares the freshly-computed `$reasons` against the stored `stale_reasons` and calls `updateQuietly()` (still persists `freshness_checked_at`) when nothing changed. 2 new tests — a no-op re-audit does not forget either cache key; a genuine stale transition still busts the view-model cache.
  - **Perf H2 (Perf):** `bestPrice` reading `$o->store` for the commission/priority tiebreak turned three `offers`-only eager loads into an O(N) Store lazy-load per offer: `OfferIngestionService.php`'s matched-offer ingest hot path, `RescanProductFeatures.php`, and `AuditLandingPagesCommand.php`'s `--backfill-snapshots` path (no eager load at all there). All three now eager-load `offers.store`. 1 new query-count regression test (`OfferIngestionServiceTest`) that fails at `stores` query count 6 pre-fix (3 pre-existing offers → 3 lazy loads) and passes at ≤4 post-fix — verified by temporarily reverting the fix and confirming the test catches it.
  - **Perf M1:** `FlagConditionProducts --urls`'s offers eager-load omitted `scraped_price`, so `estimated_price` was unconditionally `N/A` in the human review table. Added the health + price columns (+ `offers.store:id,is_active` since `store_id` is already selected there for the URL-role logic, avoiding a new N+1 from the Sec M2 `is_active` check). 1 new test.
  - **Perf M2:** `ProductResource`'s Filament `best_price` column read a raw, unfiltered `$record->offers->min('scraped_price')` via `getStateUsing` — disagreed with the public site whenever the cheapest offer was flagged/negative-condition, during exactly the flag-triage work the column exists for. Now reads `$record->best_price` (health columns added to the eager load; `store_id` deliberately still omitted — no tiebreak needed for a displayed price, and adding it would cost a Store query per offer). `sortable(query:)` intentionally left on the raw SQL min (not sargable without duplicating the exclusion in SQL; admin sort order, not a customer-facing price). 1 new Filament test (`ProductResourceTest`, using the established `Livewire::test()`-on-the-Page-class F12 workaround).
  - **Sec H1 (Security), both halves:** (a) `store_slug` gained a strict format rule (`regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/`) at `OfferIngestionController` — the only endpoint that accepts a caller-supplied store slug (batch/product-import always use the fixed `'amazon'` slug). (b) New `SeoSchema::encodeSchemasForScriptTag()` — `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` instead of the old `JSON_UNESCAPED_SLASHES`, so a literal `</script>` in ANY schema field (a Store name is the cited vector, but this is a general fix, not store-name-specific) can never break out of the `<script type="application/ld+json">` block. Applied at all three JSON-LD sinks found repo-wide: `ProductCompare.php`, `Home.php` (both cited by the audit), and `resources/views/landing/show.blade.php` (not cited — the audit noted it wasn't vulnerable today because plain `json_encode()` escapes slashes by default, but every `{!! !!}` schema sink now goes through the one hardened helper for defense in depth). Verified the hex-escaping does NOT corrupt `json_decode()`-ability or break existing HTML substring-match tests (`"@type":"ItemList"` etc. survive — PHP's `JSON_HEX_*` flags only escape characters inside encoded string VALUES, never `json_encode()`'s own structural quotes). 5 new tests: 2 in `SeoSchemaTest` unit-testing the encoder directly, 1 full HTTP render test posting a `</script><script>...` store name through a real compare-page render and asserting it never appears unescaped in the response HTML, 3 in `OfferIngestionTest` for the `store_slug` regex (HTML payload rejected, underscores/uppercase rejected, valid hyphenated slug still accepted).
  - **Sec M1 (Security):** `listing_flags` validation bounded array element COUNT (`max:5`) but not keys — an associative payload `{"<huge string>": "high_price"}` passed every rule and persisted the attacker-chosen key verbatim via the JSON-cast round-trip. Added `array_values()` normalization in `prepareForValidation()` (both Form Requests) / directly in the controller (`OfferIngestionController`, which validates inline) BEFORE validation runs, plus a belt-and-braces `array_is_list()` rule. 2 new tests confirm an associative payload is silently normalized (attacker key never reaches the DB) rather than merely rejected — `array_values()` strips it before the validator or the write ever see it.
  - **B3, B4 (Review, extension-side):** fixed by the parallel extension agent in the same session — see the "Extension-side fixes from the 2026-08-16 audit" entry directly above this one. Not touched here.
  - **Deferred, not attempted this session (all logged in the original task instructions as explicit scope cuts):** Sec H3 (per-tenant extension token scoping — architectural/owner decision), the rest of Sec H2 (exact-title + `Store::firstOrCreate` offer-takeover path — needs design), Sec M3 (extension URL allowlist — client-side, and per the entry above the extension agent already shipped this independently), Sec M4 (dead-listing replay), Review S4–S9 (unknown-branch condition downgrade; instant-path blind spot for `flagged_offer_condition`; price-null-sibling escalation; controller-thinness Form-Request extraction, already tracked as Q2/Q3; audit-dispatch volume during a rescan walk), Perf M3 (Filament repeater `find()` per pick, already tracked from 2026-08-10), Perf M4, Perf M5 (both explicitly "keep as written" in the audit itself, no action needed).
  - Judgment calls (Sec M2's is_active threading, the `store_slug`/JSON-LD defense-in-depth scope, B3/B4 left to the parallel agent) logged in `docs/questions.md`.

## Review 2026-08-16 — post-029b audit (merged from `docs/tasks/todo-2026-08-16-review-fixes.md`, since deleted)

- [x] **B1 (BLOCKER, Spec 029 Fix 1): `ProductConditionGuard`'s bare `used` marker is a substring match** — fixed above (anchored `MARKER_PATTERNS`, 19 new tests). Ops follow-up (spot-check the ~150 listings removed 2026-08-12→16 for false-ignore `condition='used'` rows) is a manual owner task, not automatable — flagging here so it isn't lost.
- [x] **B2 (BLOCKER, Spec 029 Fix 3 fallout): `SelectLandingPagePicks` can still pick a product whose `best_offer` is `null`** — fixed above via the shared `ListingHealth::isPurchasable()` predicate (S2).
- [x] **B3 (BLOCKER, Spec 031): one detached pick aborts the entire weekly "Verify Live Picks" run** — fixed extension-side (manifest 1.7) by the parallel agent: skips rows with no `category_id` individually, tallies them, only aborts if every row is unusable.
- [x] **B4 (BLOCKER, Spec 029 Fix 2 × Spec 031): a `flagged_offer_condition` pick is reported as clean and never names its guide** — fixed extension-side (manifest 1.7) by the parallel agent: explicit action→bucket tables in both `background.js` and `popup.js`, `flagged_offer_condition` added to `noteFlaggedGuide()`'s condition set and to `popup.js`'s `actionLabels`.
- [x] **S1** `ProductCompare::scoredProducts()`'s independent price definition — fixed above (Perf C1).
- [x] **S2** extract one `ListingHealth::isPurchasable()` + `OFFER_HEALTH_COLUMNS` — fixed above.
- [x] **S3** `Product::bestOffer` accepts `scraped_price = 0` — fixed above.
- [ ] **S4** the `unknown` branch overwrites a stored negative condition with `unknown`, re-admitting the offer to `bestOffer`/`hasCleanOffer`/`hasEligibleOffer` while the product stays ignored, and returns before `logIfRecoveringWhileIgnored()` can fire. *Deferred — not in this session's task list.* *[API]*
- [ ] **S5** the negative-condition branch dispatches no freshness audit and trips no observer, so a best-offer swap on a multi-store pick is invisible to the instant path (nightly audit covers it). *Deferred.* *[API]*
- [ ] **S6** `hasCleanOffer()`'s `> 0` requirement turns the known Clive price-extraction gap into product-level ignores only a human can reverse. *Deferred.* *[API]*
- [ ] **S7** Q2/Q3 (Form Requests, `BatchImportService`) are overdue — extract a `RescanWorkList` query object first. *Already tracked as Q2/Q3 above; not actioned this session.* *[API]*
- [~] **S8** test gaps: marker false positive (✅ done, B1), pick ⇒ non-null `best_offer` (✅ done, B2/S2), detached-pick row (✅ done, extension-side per B3), "narrow `offers:` select still applies the exclusion" (✅ done — `OFFER_HEALTH_COLUMNS` constant makes this mechanical; no dedicated regression test for "forgetting the constant" specifically, since the constant itself is now the single point of truth). `flagged_offer_condition` tallying — extension-side, no PHP test possible (per the original note).
- [ ] **S9** up to one `AuditLandingPageFreshnessJob` per rescanned offer, each re-running full pick selection over the category. *Deferred.* *[API]*

## SEO checkpoint 2026-08-17 — new items

- [ ] **Spec-028 (product content depth) — PROMOTE to next spec.** Three consecutive checkpoints now converge: product pages carry **68% of impressions, 72% of clicks, and the best weighted position (17.8 pw2d / 18.8 c2d)** across both tenants, and every single click is a model-number query landing at pos 4–9 (`aoc gk330` ×4, `kingrinder k7 review`, `mhw-3bomber r3 pro review`, coletti crag ×3). Compare presets remain click-dead at pos 10–15. Gate to clear first: the `evaluateProduct` grounding guardrail + the ~32 polluted `ai_summaries`. *[SEO, Content, Architect]*

- [ ] **WATCH ~2026-08-23: `/best/manual-coffee-grinders` has zero GSC rows in 13+ days.** Live since ~Aug 1 on c2d's #2 demand surface (83 impr/14d, with clicks) while its two Aug-1 siblings both indexed within 2–4 days. Sitemap verified correct on both domains, so this is not plumbing. The other 7 post-Aug-9 `/best/` pages have only had ~3–5 eligible days against a 3-day GSC lag and are legitimately too early to judge — manual-grinders is not. If it is still empty on the Aug-23 check while siblings index, treat as a page-level indexing problem (thin/duplicate content, canonical, or pick-table credibility) rather than crawl latency. *[SEO]*

- [ ] **Orphaned `/product/{slug}` — data now favours `noindex`, still an owner call.** Product pages are the top click surface on both tenants, so an orphaned page with no CTA converts a hard-won pos-4–9 click into a dead end. `noindex` while unbuyable (auto-clearing on a clean rescan, matching how `listing_flags` already clear) preserves the surface for products that come back; sitemap exclusion alone leaves the page indexed and clickable. Supersedes the neutral framing of the earlier entry. *[SEO, Product]*

- [ ] **Compare-page cleanup impact — read on 2026-08-23.** The unbuyable-product filter shipped ~Aug 12–16; GSC ends Aug 14, so this week was unreadable. pw2d weekly impression baseline for the diff — headsets 12/9/7/5, mics 111/123/80/79, lavalier 16/6/6/4 across wk 202629→202632 (202632 is 6 of 7 days). Headsets and lavalier were already drifting down *before* the cleanup, so attribution will be muddy; judge on 202633–202634. *[SEO]*

- [ ] **First PostHog engagement read is now viable on c2d** (15 clicks in 28d clears the floor that blocked this in June). Key in local `.env` `POSTHOG_PERSONAL_API_KEY`, project 133580. *[SEO, Analytics]*

## Session 2026-08-20 — category health tooling

- [x] **Spec 032 — category health & freshness** (`docs/specs/032-category-health.md`, APPROVED, built 2026-08-20).
  `AssessCategoryHealth` action + `CategoryHealthRow` DTO + `pw2d:categories:health` command + health
  columns on the Filament `CategoryResource` list. Replaces the "SSH into prod and write SQL" answer to
  *what's topped up / what's next* with a computed one. Reasons vocabulary mirrors Spec 030's
  `stale_reasons`: `import_debt` / `stale` / `aging` / `thin` / `churn` / `no_data`. Reuses
  `ListingHealth::applyPurchasableOfferQuery()` verbatim for `buyable_count` — no fifth definition of
  "purchasable". 24 new tests (`AssessCategoryHealthTest`, `CategoriesHealthCommandTest`,
  `CategoryResourceHealthTest`), including a regression asserting `buyable_count` agrees with
  `ProductCompare::scoredProducts()->count()`. Judgment calls (stale/aging mutual exclusivity, no_data
  short-circuit, explicit tenant filter in `execute()`) logged in `docs/questions.md`.
  *[Architect, Filament, Tooling]*

- [ ] **Fix unfiltered `products_count` — owner: "remember to fix homepage numbers later" (2026-08-20).**
  Four callers count raw `products` rows with no `is_ignored` / `status` / buyability filter, so the UI
  overstates inventory — podcast-studio-mics advertises **"Top 280 Picks"** against a 181 pool and **153
  buyable**; gooseneck-kettles reads "Top 70" against 34 buyable. This is what made the homepage
  unusable as a top-up check on 2026-08-20. Sites: `app/Livewire/Home.php:30` (public homepage — highest
  impact, needs the `home:popular_categories` cache key bumped), `app/Filament/Resources/CategoryResource.php:143`
  (fixed as part of Spec 032's relabel), `app/Filament/Resources/BrandResource.php:62`,
  `app/Livewire/ProductCompare.php:99` (brand-filter counts can exceed what the grid renders).
  `ListingHealth::applyPurchasableOfferQuery()` already exists — this is filter application, not new logic.
  *[Frontend, Filament, Data]*

- [x] **Tier-3 standings verified against prod (2026-08-20)** — only `manual-coffee-grinders` has been
  topped up (63 rows added 2026-08-16, pool 33→58, 0% unbuyable). All other 10 categories: **zero** rows
  added since 08-15; every buyable figure matches the Aug-16 baseline exactly. Buyable pools —
  kettles 34 · cold-brew 36 · super-auto 41 · pour-over 57 · grinders 58 · headsets 59 · lavalier 66 ·
  ergonomic 86 · semi-auto 121 · keyboards 149 · mics 153. Oldest health check platform-wide is
  2026-08-14 (6 days), so **no Tier-2 sweep is due until ~2026-09-13**. `never_health_checked` = 3,
  matching the known straggler list above. Query kept in Spec 032's rationale. *[QA, Data]*

- [x] **Spec 033 — `offer_id` rescan targeting** (`docs/specs/033-offer-id-rescan-targeting.md`) — implemented (AWAITING /deploy + extension reload to 1.8)
  2026-08-20. Closes the cross-category duplicate absorption bug: `OfferIngestionService` resolves the
  rescanned offer by `(store_id, url)` + unordered `->first()`, so the lowest-id twin absorbs every update
  and its sibling is structurally unreachable (16 such rows found on ergonomic keyboards, one a live pick).
  Server accepts + prefers `offer_id` (tenant-scoped `Rule::exists` — it is a security boundary under the
  shared extension token, not a convenience) via `resolveExistingOffer()`; extension echoes back the
  `offer_id` `rescan-list` already returns (`background.js`); URL fallback gains `orderBy('id')` + a
  multi-match warning that makes duplicates observable. Manifest 1.7 → 1.8. No endpoint URL change, so the
  CLAUDE.md popup/content sync rule was not triggered. 6 new tests in
  `tests/Feature/OfferIdRescanTargetingTest.php` (647 passed / 21 skipped, up from 641/21, 0 failures).
  **Unblocks the super-auto Tier-3 top-up** — super-auto ↔ semi-auto is the confirmed duplicate pair.
  *[API, Extension, Security]*

## 2026-08-20 — super-auto Tier-3 top-up + wrong-category recovery

- [x] **Super-auto top-up complete despite a wrong-page import.** Owner imported an Amazon SERP batch
  (27 products) plus two Whole Latte Love pages — one of them **semi-automatic by mistake**. Net result:
  super-auto pool **43 → 64**, buyable **41 → 58**, clearing the `thin` threshold (45). Semi-auto gained
  the re-homed rows: pool 125 → 137, buyable 121 → 132.
- [x] **The AI pipeline self-healed 9 of 19 mis-filed rows on its own.** `AiService::matchProduct()` is
  brand-scoped but NOT category-scoped, so WLL semi-auto offers for brands already present in semi-auto
  (Profitec MOVE, ECM Synchronika II, ECM Classika PID) merged onto the **existing correctly-categorised
  product** and 6 duplicate stubs were deleted. Only brands with no processed products in the tenant
  (Arkel, Stone) bypassed matching entirely — the documented heuristic short-circuit. Worth remembering:
  a wrong-category import is partially self-correcting, so **let the queue drain before cleaning up**.
- [x] **12 products swept out of super-auto and re-homed to semi-auto (category 16).**
  `pw2d:ai-sweep-category` flagged all 12 with accurate per-product reasons and zero false positives
  across 76 products — including 2 the owner and I both missed from the *Amazon* batch (#4172 Ninja Luxe
  Café Pro ES701, #4181 Luxe Café Mini ES301 — assisted-manual portafilter machines, not bean-to-cup).
  It correctly KEPT #4169 Ninja AutoBarista, which is a true super-auto. Each product's 6 stale
  super-auto `product_feature_values` were cleared and re-scored against semi-auto features before
  anything else touched them — avoiding the Product 3352 two-category feature bug. Verified post-run:
  all now carry Espresso Quality / Steam Wand / Heat-Up, no super-auto leftovers.
- [ ] **FINDING: `pw2d:ai-assign-categories` would pollute `manual-coffee-grinders` with grain mills.**
  Its tenant-wide dry run proposed 20 assignments — our 12 (all correct) plus 8 pre-existing detached
  products, **three of which are not coffee grinders**: #3853 Corona Manual Grain Mill, #4098 Estrella
  Cast Iron Manual **Corn** Grinder, #4108 Victoria Cast Iron **Grain** Mill. The AI's rationale ("can be
  used for grinding coffee") is technically true and editorially wrong; all three would have become
  pick-eligible in the one category that is 0% unbuyable and feeds a live `/best/` page. **The blanket
  command was NOT run** — the 12 were re-homed directly instead. Before this command is ever run
  tenant-wide it needs a guard (category-specific negative examples in the prompt, or an owner-review
  gate). *[AI, Tooling, Data]*
- [ ] **Owner decision: the other 8 detached products.** Left untouched. Four look right (#3474 Philips
  Barista Brew, #3485/#3486 Breville Barista Touch → semi-auto; #3986 Takeya → cold-brew), three are the
  grain mills above, and #3503 Breville Oracle Jet → super-auto is a genuine judgment call (auto
  grind/dose/tamp, but a portafilter machine). *[Data, Content]*
- [ ] **NEXT: rescan both espresso categories, then regenerate.** super-auto 21 unchecked, semi-auto 13.
  Requires the extension at **1.8** first (Spec 033), or the rescan won't send `offer_id` — and super-auto
  ↔ semi-auto is the exact cross-category duplicate pair that fix exists for. Sequence per Spec 031:
  rescan → regenerate → review → publish. The super-auto page also has sub-threshold price drift to fix
  (Jura E8 quoted $2,800, now $2,550). *[QA, Content]*

- [x] **Spec 034 — pick diversity: model-identity variants + brand cap** (`docs/specs/034-pick-diversity.md`, DRAFT→building).
  The super-auto top-up grew the pool 41→62 buyable and made the page WORSE: `--dry-run` returned the
  Jura Z10 in two slots (Gen 1 as *overall*, Aluminum White as *premium*) and **6 of 7 picks from one
  brand**, displacing the only mid-price option ($2,000 De'Longhi) so the page jumped $680 → $3,800.
  **The ≥85% `similar_text` guard cannot be tuned into correctness** — measured on the real names, the
  true duplicate scores **43.3%** while two genuinely different machines (X10 Dark Inox vs J10 Twin)
  score **82.1%**. The metric is inverted relative to truth. Fix: identity via **model token**
  (`z10`, `giga10`, `ecam29043sb`) keyed with `brand_id`, similarity kept only as the null-token
  fallback; plus a **soft** `MAX_PICKS_PER_BRAND = 3` that may be exceeded rather than leave a slot
  empty. Partly addresses the open "repeated products within/across pages" item (Keychron Q11, K8 HE,
  SM58 family). **Changes selection on all 11 pages — regenerate one at a time with review, never a
  mass rebuild.** *[AI, Content, Architect]*
  **BUILT 2026-08-21:** landed entirely in `SelectLandingPagePicks`'s `$isDuplicateOfPicked` (model-key
  is AUTHORITATIVE once both sides have one — equal keys duplicate, different keys don't, full stop;
  similarity runs only when at least one side has no model token, exactly per spec) and a new
  `$pickBrandAware` resolver every pick site now funnels through (soft cap, `Log::info` on overflow).
  Model-key algorithm needed one addition beyond the spec's prose to match its own verified table: the
  join-candidate token itself must also be ≤5 chars, or a long self-identifying SKU like `ECAM29043SB`
  gets wrongly prefixed with its line name (`evo`) — logged as a judgment call in `docs/questions.md`.
  Coordinator caught and reverted a first-pass widening (running similarity even when both keys were
  non-null-but-different) that reintroduced the exact over-merge failure Spec 034 exists to fix —
  Philips 4400 vs 1200 (95.7% similar_text, different machines, both live in the pool) would have been
  wrongly rejected as a duplicate; fixed by reverting to the spec's literal rule and fixing the
  Keychron test's fixture (pin both rows to the same brand) instead of the production logic. 8 new
  tests (real names from the spec's table plus the Philips 4400/1200 regression, twin of X10/J10 in the
  opposite direction); full suite 672→680 passed, 21 skipped, all green. Regeneration of the two
  espresso pages is still the separate next step below, deliberately not done in this pass per the
  spec's "ship the rule, then regenerate one page at a time with review" sequencing.
- [ ] **`/best/super-automatic-espresso-machines` regeneration DEFERRED pending Spec 034.** Page is
  STALE on `selection_drift` only; its picks remain buyable and correctly priced, so there is no
  reader-facing harm today. Regenerating now would publish a 6/7-single-brand page. Also still carries
  the sub-threshold price drift (Jura E8 quoted $2,800, now $2,550) to fix in the same pass.
  Semi-auto is in the same position and has the same brand-heavy WLL inventory. *[Content]*
- [x] **CORRECTED + RESOLVED: #3568 Eureka Costanza R was DELISTED, not a URL-shape problem.** Earlier
  logged as a "confirmed systematic extractor gap" with collection-scoped WLL URLs. That was wrong on
  both counts, verified 2026-08-21: a sibling collection-scoped URL
  (`/collections/semi-automatic-espresso-machines/products/profitec-go-espresso-machine`) returns **200**,
  and most collection-scoped offers in the table are health-checked successfully — the URL shape is fine.
  #3568's own page **404s** and WLL's site search returns no Costanza product: the listing is gone.
  The extension's "error" on both 08-16 and 08-20 was a dead page, reported accurately. Resolved by
  flagging its only offer `unavailable` + `out_of_stock` and health-stamping it, which is the state a
  successful scrape would have produced; it clears itself if WLL ever relists. *[Data]*
- [ ] **FINDING: pick eligibility does not require `health_checked_at`.** `ListingHealth::isPurchasable()`
  reads NULL `condition`/`listing_flags` as clean, so an offer that was **never verified** is
  indistinguishable from one **verified clean**. Spec 031's Tier-3 rule ("never select picks from an
  unverified pool") is therefore a human process rule with no code enforcement — #3568 above is a live
  example. Consider requiring `health_checked_at IS NOT NULL` for pick eligibility, or surfacing
  unverified picks as a distinct `AuditLandingPageFreshness` reason. *[API, Content]*

- [ ] **Spec 034 GAP: model-less product names still rely on the unreliable similarity guard.**
  `modelKey()` returns null when a name contains no digit-bearing token, falling back to `similar_text`.
  Live example found 2026-08-21: **four** `Breville Oracle Jet` rows exist (3292 Brushed Stainless,
  3433 Damson Blue, 3461 Olive Tapenade, 3503 detached) — the same machine in colourways. Pairwise
  similarity is only **61.5% / 64.7% / 66.7%**, so the fallback misses all of them and three are
  simultaneously pick-eligible on semi-auto. Today's dry run picked just one, but by score ordering,
  not by the guard — two topping different roles would reproduce the Z10 double-pick on a live page.
  Same exposure for any word-named model (`La Marzocco Linea Mini`, `Breville Bambino`).
  Naive fixes fail: a colour stoplist can't cover open-ended names like "Olive Tapenade", and a
  common-prefix ratio would wrongly merge `Bambino` with `Bambino Plus`.
  **The better framing:** Spec 034 stops duplicates reaching the page; it does not stop them existing.
  The four Oracle Jet rows are a data-cleanup job for `pw2d:merge-duplicates` (which is category-scoped
  and did not catch them), not a picker workaround. *[AI, Content, Data]*
- [ ] **Merge the 4 Breville Oracle Jet rows + 5 Jura Z10-family rows.** Concrete instances of the above.
  #3503 is additionally detached and unchecked (2 offers, never health-stamped). *[Data]*

## 2026-08-21 — super-auto page regenerated; stale-feature backlog cleared

- [x] **`/best/super-automatic-espresso-machines` regenerated and live.** First page rebuilt under Spec 034.
  Picks went from 6/7 Jura (with the Z10 in two slots) to **3 Jura / 2 De'Longhi / 2 Philips**, and the
  price ladder is now continuous — $300 · $680 · $1,080 · $1,970 · $4,300 · $5,500 · $10,000, closing the
  old $680→$3,800 hole. Prose authored by Claude to the style + grounding contract (Gemini's admin model
  still times out on `generateLandingPageContent`), machine-checked clean against banned + condition
  words, every figure traced to the pick payload. Five new FAQs, all distinct from the 17 excluded; the
  old "Why is almost this entire list Jura?" FAQ retired as no longer true. Page reads FRESH.
  Row backed up to `/tmp/sa_backup_20260821_115413.json` on prod before the write. *[Content]*
- [x] **Stale cross-category `product_feature_values` cleared — 309 rows, 55 products, 6 categories.**
  The "Product 3352 carries feature values from two categories" item was logged as one product; it was
  platform-wide (super-auto 120 rows, gaming-keyboards 90, semi-auto 66, lavalier 15, ergonomic 12,
  mics 6). Affected products carried 12 feature values where their category defines 6. Deleted every
  row whose `feature.category_id <> product.category_id` — unambiguous, since a Feature belongs to
  exactly one category. Affects display and generated prose, not ranking (scoring joins through the
  category's own features). 0 remaining. *[Data]*
- [x] **ROOT CAUSE: nothing clears feature values on re-home.** Fixed by Spec 035 (below) —
  `ProductObserver::saved()` now deletes foreign-category feature values and dispatches
  `RescanProductFeatures` on every category change, command or Filament. *[API, Data]*
- [x] **CORRECTED: the "landing-page cache is not busted" finding was wrong.** `LandingPage::booted()`
  already registers `saved`/`deleted` hooks that forget both `cacheKey()` and the sitemap cache. The stale
  page seen on 2026-08-21 was caused by the ad-hoc regeneration script writing via
  `DB::table('landing_pages')->update()` — a mass update, which fires no Eloquent events. Operator tooling
  bug, not an application one. Any future ad-hoc content script must write through the model. Same root
  cause as Spec 035; see that spec's Correction section. *[Docs]*
- [ ] **`/best/manual-coffee-grinders` went STALE on `selection_drift`** — expected fallout of Spec 034
  changing selection platform-wide. Regenerate one page at a time with review; do not mass-rebuild.
  Semi-auto still stale too, and still blocked on merging the 4 Breville Oracle Jet rows. *[Content]*
- [x] **Spec 035 — re-home integrity** (`docs/specs/035-rehome-integrity.md`, built 2026-08-21).
  `Product::where(...)->update(...)` in `AiSweepCategory:88` and `AiAssignCategories:104` is a MASS update
  and fires no model events, so `ProductObserver` never runs for either. Two consequences, both live:
  **Spec 030's instant freshness path is dead for the AI sweep** (nightly audit has been silently covering
  it), and neither path clears the old category's `product_feature_values` — the mechanism that produced
  today's 309-row backlog. Fixed: both commands now use model-level saves (also now `select()`ing
  `tenant_id`, not just `id`/`name` — an unselected column comes back null and silently turns
  `AuditLandingPageFreshnessJob::dispatchForProduct()`'s tenant lookup into a no-op, a second latent bug
  this surfaced); feature-clearing moved into `ProductObserver::saved()` so Filament re-homes are covered
  too (`whereHas`/EXISTS delete, not a per-product feature-row load); `AiAssignCategories`' old manual
  `RescanProductFeatures::dispatch()->delay()` call removed in favour of the observer's single definition.
  Queue-collapse guard (the open S9 concern) needed no new code — `AuditLandingPageFreshnessJob` already
  had `ShouldBeUnique` (`uniqueFor: 600s`, keyed on landing page id) from Spec 030; it was just never
  exercised for command-driven re-homes because the mass update never reached it. Test: 6 swept products
  across 2 pages produce exactly 2 audit jobs, not 6. 7 new tests, `php artisan test` 687 passed / 21
  skipped (was 680/21). *[API, Data, Perf]*
- [x] **Breville Oracle Jet family consolidated (2026-08-21).** Four rows, one machine. #3503 turned out
  to share ASIN `B0DYGDKQ85` with #3292 — a true duplicate, not a colourway — and being detached it was
  structurally unreachable by any category rescan, which is why it was the only never-health-checked row.
  It also held the family's only Whole Latte Love offer. Resolved: moved that WLL offer onto canonical
  #3292 (no unique-constraint clash), deleted #3503's duplicate-ASIN Amazon offer, and ignored #3503 plus
  the two colourway rows #3433 Damson Blue / #3461 Olive Tapenade (both $1,999.95 against #3292's
  $1,974). **#3292 is now a genuine multi-store product** — Amazon $1,974 + WLL $1,999.95 — which is
  better than any of the four rows was alone. `pw2d:merge-duplicates` could never have caught these: it
  matches on *identical* `(name, brand_id, category_id)` and all four names differ. *[Data]*
- [ ] **Owner decision: are the two Jura Z10 rows one machine or two generations?** #3507 "Jura Z10
  Aluminum White" (Amazon $4,199 + WLL $4,499) and #4243 "JURA Z10 Super-Automatic — Gen 1" (WLL $4,299,
  currently the *overall* pick on the live super-auto page). Whole Latte Love lists them at two distinct
  product URLs, one explicitly "-gen-1", so this may be a real generational split rather than a
  colourway duplicate. **Not merged** — needs product knowledge I don't have. If they are one machine,
  merging would consolidate three offers onto one row and change the super-auto page's top pick.
  *[Data, Content]*
- [ ] **#3292's Whole Latte Love offer is unchecked** (moved from #3503, which had never been scanned).
  One single-scan clears it. Note it does NOT show as `import_debt`: Spec 032's `never_checked` is a
  PRODUCT-level metric (products with *zero* health-stamped offers), so a product with one checked and
  one unchecked offer reads as clean. Worth knowing when reading that column — offer-level gaps hide
  behind it. *[QA, Tooling]*

## 2026-08-21 — Tier-3 discovery complete; all 6 c2d pages rebuilt

- [x] **Every coffee2decide landing page is FRESH, rebuilt from a freshly verified pool.** super-auto
  (twice — once after the Z10 merge changed its top pick), manual-grinders, semi-auto, cold-brew and
  gooseneck-kettles all regenerated 2026-08-21; pour-over unchanged and still fresh. Prose authored by
  Claude to the style + grounding contract, machine-checked clean against banned + condition words, every
  figure traced to the pick payload. Each row backed up on prod (`/tmp/{sa,mg,se,cb,gk}_backup_*.json`)
  before writing. *[Content]*
- [x] **Tier-3 discovery objective met — no c2d category is `thin` any more.** Buyable pools, start → end
  of the two-day push: super-auto **41 → 61**, gooseneck-kettles **34 → 48**, cold-brew **36 → 51**.
  The kettle and cold-brew end figures are *post-sweep*; raw import numbers were higher (56 and 53) before
  miscategorised rows were removed, so quote the swept figures.
- [x] **The `iced coffee maker` / `electric kettle` warning proved correct — sweeps caught 15 strays,
  2 of them live picks.** Cold-brew: 6 removed, including a **Keurig K-Brew+Chill sitting in the premium
  slot** and a Primula iced *tea* maker. Kettles: 9 removed, including the **Fellow Corvo EKG Pro in the
  tea-drinker slot** — a traditional-spout kettle whose gooseneck sibling is the Fellow Stagg, an easy
  confusion — plus a Fire-Maple camping pot. **The AI Bouncer wrote accurate summaries and admitted them
  anyway** ("a standard hot pod brewer masquerading as an iced coffee machine"): `evaluateProduct` gates
  quality, not category fit. **Always run `pw2d:ai-sweep-category --dry-run` after a Tier-3 import,
  before regenerating.** Worth adding to Spec 031's Tier-3 sequence: import → **sweep** → rescan →
  regenerate. *[Content, AI]*
- [x] **Spec 035 validated in production.** The cold-brew sweep detached 6 products and the kettle sweep 9;
  stale cross-category `product_feature_values` stayed at **0** throughout (the observer cleared them
  automatically — pre-035 these would have orphaned ~90 rows), freshness audits fired, and the
  `ShouldBeUnique` guard held with no queue flood. *[QA]*
- [ ] **Spec 031 amendment: add the sweep step to the Tier-3 sequence.** Currently documented as
  "import → rescan → regenerate". Today proved a sweep belongs between import and rescan — otherwise you
  rescan products that are about to be removed, and risk publishing them. *[Docs, Architect]*

## Audit 2026-08-21 — three-agent review of Specs 032–035 (all live in production)

Reports: `docs/reviews/audit-2026-08-21-review.md`, `docs/security/audit-2026-08-21-security.md`,
`docs/performance/audit-2026-08-21-performance.md`. **0 critical, 6 high, 9 medium.** No new cross-tenant
read or write, no injection, no mass-assignment gap — the `offer_id` primitive is correctly scoped on both
paths (verified: `Rule::exists`'s presence verifier uses the query builder, so `BelongsToTenant` would NOT
apply and the explicit `where('tenant_id')` is load-bearing).

- [ ] **H-A (Spec 034, WORST — my design defect, live on 6 pages): `modelKey()` false-merges distinct
  products.** It takes the first digit-bearing token as identity, which breaks three ways, all confirmed
  against production data 2026-08-21:
  (1) **a brand name containing a digit becomes the key** — all **6** `1Zpresso` grinders (K-Ultra, Q Air,
  J, X-Ultra, Q, J-Ultra) collapse to `291:1zpresso`, and the X-Ultra is the *live overall pick* on
  `/best/manual-coffee-grinders`, so five siblings were silently excluded from every other slot;
  (2) **size/quantity tokens read as model identity** — `Bodum Chambord 8 Cup` = `Bodum Brazil 8 Cup` =
  `1:8`; `Takeya Deluxe 2 Quart` = `Takeya Glass 2 Qt`; `Chemex Classic 8-Cup` = `Chemex Glass Handle 8-Cup`;
  (3) **decimals split** — `Kettle 0.8L` and `0.9L` both key to `0`; `Speed Boil 1.7L` keys to `boil1`.
  Worst single case: **9** Hario V60 pour-over products (sizes 01/02/03, metal, ceramic, decanter, glass
  set) all collapse to `266:v60`. Variant rejection **logs nothing**, so this leaves no trace, and the key
  comparison short-circuits so the similarity guard cannot rescue it. Escalation path found by the
  reviewer: on a thin category this can drop the pool below `MIN_PICKS`, `execute()` throws,
  `AuditLandingPageFreshness::hasSelectionDrift()` catches and returns true, and the page is stamped
  `selection_drift` on every audit forever, busting page + sitemap cache each time.
  **Fix direction:** exclude brand-name tokens from *candidate selection* (not just from joining, which is
  all Spec 034 specified); reject bare numeric tokens followed by a unit/quantity word (cup, quart, qt, oz,
  l, ml, g, pack); handle decimals as one token. A real model code almost always mixes letters and digits
  (`z10`, `bes870xl`, `c40`, `a53`, `v60`) — a bare number is usually a size. Needs a spec, not a patch.
  *[AI, Content, Architect]*
- [x] **H-B (Spec 035 incomplete — mine): 3 of 5 mass-update sites survive.** `ProductObserver` has TWO
  triggers; Spec 035 fixed both `category_id` sites and left every `is_ignored` one:
  `AiAssignCategories.php:126` (**eleven lines below its own fix**), `FlagConditionProducts.php:123`,
  `ProblemProducts.php:347`. Note `ProblemProducts.php:325` (single-record) does fire the observer while
  `:347` (bulk) does not — **same page, same button semantics, opposite behaviour**, and `:347` is the
  triage path the owner actually uses. Fix is the same one-line treatment. **Also: todo item Q11 is now an
  instruction to REINTRODUCE this bug into `ProductResource.php:262-266` — close it WONTFIX or amend it.**
  *[API, Filament]*
  **Closed by Spec 036 §2 (2026-08-21):** all three sites converted to model-level saves
  (`ProblemProducts.php:347` — `$records->each(fn ($p) => $p->update(...))`; `FlagConditionProducts.php:123` —
  `cursor()->each(...)`; `AiAssignCategories.php:126` — `$product->is_ignored = true; $product->save();`).
  Regression pinned in `tests/Feature/Filament/ProblemProductsBulkIgnoreTest.php`,
  `tests/Feature/Commands/FlagConditionProductsCommandTest.php`, and
  `tests/Feature/Commands/AiAssignCategoriesCommandTest.php`. Q11 closed WONTFIX.
- [x] **H-C (Spec 035 × pre-existing): a failed rescan can leave a product with ZERO feature values,
  silently.** `RescanProductFeatures.php:115-117` swallows the exception on the final attempt, so the job is
  marked *successful* and never reaches `failed_jobs` — `queue:retry` can never recover it. Pre-035 that
  only meant stale scores; post-035 the observer has already **committed** the delete first. Confirmed no
  transaction on the command path (`config/queue.php` has `after_commit => false`), so this is reachable in
  practice. Such a product still passes pick eligibility, which never checks feature values.
  **Inversion worth knowing: Filament is the SAFE path** (`EditRecord::save()` wraps in a transaction and
  the `database` queue driver shares the connection); the automated command is the unsafe one.
  **Fix: delete the conditional so it always rethrows** — one line, ship before the next
  `pw2d:ai-assign-categories`. Do NOT wrap the observer in a transaction. *[API]*
  **Closed by Spec 036 §1 (2026-08-21):** the `if ($this->attempts() < $this->tries)` guard deleted; catch
  block now always rethrows, no transaction added. Regression pinned in
  `tests/Feature/Jobs/RescanProductFeaturesTest.php` (drives a real `database`-connection `queue:work` run to
  a genuine 3rd-of-3 attempt — `QUEUE_CONNECTION=sync` can't reproduce this bug since `SyncJob::attempts()` is
  hardcoded to 1).
- [ ] **H-D: `import_debt` may be un-clearable, making the cron exit code permanently red.**
  `background.js:499` never sends `condition: 'unknown'`, so `ListingHealthService::apply()` early-returns
  at the `$condition === null` check and never stamps `health_checked_at` — but `content.js:154` sets
  `'unknown'` whenever the Amazon buy box didn't load. Those offers can never be stamped, so their category
  reports `import_debt` forever and `pw2d:categories:health` exits FAILURE nightly, while Spec 031 tells the
  operator picks must not be selected there. **The three single-product `import_debt` stragglers may be
  unstampable rows rather than genuine debt — check before treating them as a scanning backlog.**
  *[Extension, API]*
- [ ] **H-E (Spec 035 blast radius not enumerated): `ProcessPendingProduct.php:153` destroys all feature
  values on the hottest `category_id` write path in the system**, and `ListProducts.php:44-47` bulk-dispatches
  it. A 50-product "Retry Failed" wipes every previously-rejected product's feature values with no rescan
  queued. Owner decision: accept, or narrow the null branch using `getOriginal('category_id')`. *[API]*
- [ ] **H-F (Spec 035 × H2): queue starvation.** All jobs share `default` with 2 workers, so a 100-product
  assign blocks `ProcessPendingProduct` — the extension's live ingest path — for ~12 min, and
  `ai-assign-categories` runs *during* a Tier-3 import by design. Fix is `public string $queue = 'bulk';`
  on `RescanProductFeatures` + `--queue=default,bulk` in supervisor, NOT a rate limiter (2 workers already
  cap Gemini at ~5-15 req/min against a 150 RPM tier). Also covers the pre-existing Filament bulk action.
  *[Perf, DevOps]*

**Mediums** (detail in the reports): `offer_id` honoured without checking the payload `url` matches the
targeted row (M-1/MEDIUM-2 — fix restores "you must know the URL" as a precondition); a store-mismatch +
URL miss silently **creates** a product and the extension buckets `created` as `updated`, so a rescan that
minted a duplicate reports a clean pass (M-2); omitting `scraped_price` nulls the stored price via
undefined-array-key (M-4); Spec 032's `no_data` categories sort FIRST because MySQL sorts NULL first on ASC,
defeating the spec's own "row one is the next to sweep" purpose (M2/LOW); the new "Unchecked" deep-link uses
`tableFilters[categories]` while `ProductResource` declares `SelectFilter::make('category')`, so it opens
unfiltered (MEDIUM-3, copy-pasted from the pre-existing "Rows" column); `AssessCategoryHealth::execute(Tenant)`
returns silent all-zero rows if its argument disagrees with ambient tenancy (MEDIUM-4); Spec 032's docblock
credits the `category_id` FK pin when `BelongsToTenant`'s global scope is the actual control (L-1).

**Validated:** Spec 032's one-query claim holds and its invariance test asserts the right thing; the
no-cache decision holds; declining the `(product_id, health_checked_at)` index was right (scaling knee is
~25k pool products, currently 940); no Gemini rate-limiting middleware warranted; `ShouldBeUnique` genuinely
collapses the Spec 035 fan-out; `buyable_count` and `ProductCompare::scoredProducts()` apply byte-identical
filters. Spec 033 is a net **performance win** and partially retires the deferred `product_offers.url` index.
- [x] **Spec 036 — observer coverage on `is_ignored` + failure visibility** (`docs/specs/036-observer-coverage-and-failure-visibility.md`,
  built 2026-08-21). Closes audit H-B and H-C: always rethrow in `RescanProductFeatures` so an exhausted
  rescan lands in `failed_jobs` instead of being marked successful; model-level saves at the three
  surviving `is_ignored` mass-update sites so `ProductObserver` actually fires; closed todo Q11 WONTFIX.
  *[API, Filament]*

### Spec 037 — AI model strategy (drafted 2026-08-22, AWAITING OWNER APPROVAL)

`docs/specs/037-ai-model-strategy.md`. Triggered by the owner asking whether $10/category of AI spend
justifies switching models or providers. **The $10 figure was wrong** — it came from a hardcoded
`~$0.03/product` string in `ListProducts.php:39`; measured cost is ~$0.0103/product, so ~$3.59/category.

- [x] **T1: Usage instrumentation** — `GeminiService` discards `usageMetadata` entirely, so **no token
  count or cost has ever been recorded on this platform**. Every figure in Spec 037 is an estimate from
  prompt length. Add an `ai_usage` table (`tenant_id`-led composite index), thread a `$purpose` string
  from `AiService` through to the transport, compute cost from a `config/services.php` price table.
  Usage-write failure must never fail the AI call. **No prerequisite — do this first.** *[API, Data]*
  Done 2026-08-22: `AiUsage` model + migration (deliberately not `BelongsToTenant` — mirrors
  `SeoMetric`, see model docblock), `AiUsageService` (cost math + the two safety rules),
  `purpose` threaded through all 13 `AiService` → `GeminiService::generate()` call sites. Found and
  fixed a real bug along the way: `config("services.gemini.pricing.{$model}")` silently mis-parsed
  model names with dots (`gemini-2.5-flash`) as nested keys — fixed by indexing the pricing array
  directly. 15 new tests, 710 passed/21 skipped (was 695/21), zero regressions.
- [ ] **T2: `pw2d:ai:eval-model` replay harness** — re-runs `evaluateProduct()` for N already-scored
  products against a candidate model and diffs `is_ignored` agreement, brand normalization, and feature
  score deltas against stored output. Read-only. Ship gate for T3: ≥95% `is_ignored` agreement, ≥98%
  brand exact-match, ≤5pt feature MAD. Must be run **against prod** — the golden set only exists there.
  *[API, Tooling, Test]*
- [ ] **T3: `admin_model` 2.5 Pro → 3.7 Flash** — gated on T2. 2.5 Pro bills output at $10/M, the
  highest on the board bar 3.1 Pro, and is a generation old; 3.7 Flash is newer and 58% cheaper.
  Change is one `.env` line + `thinkingBudget`→`thinking_level` translation **at the transport boundary
  only** (Gemini 3 rejects both parameters together; do not touch the 15+ `AiService` call sites).
  Re-validate all 8 `admin_model` callers. **Watch for the upside: `generateLandingPageContent`
  currently times out on 2.5 Pro — if Flash fixes it, that retires the standing hand-authoring
  workaround.** *[API, AI]*
- [x] **T3b: fix the stale cost string** — `ListProducts.php:39` tells the operator "~$0.03 in Gemini
  API usage" per retried product. ~3× too high. Drive it from the T1 pricing config. *[Filament]*
  Done 2026-08-22 alongside T1: now reads `AiUsageService::estimateProductEvaluationCost()`, which
  renders "~$0.0103" from the live pricing config against the spec's measured 1800in/800out shape.

**Provider switch — investigated and DECLINED (§1.2/§5).** Anthropic is the most expensive option at
every tier for this workload: Haiku 4.5 ($1/$5) costs 34% more than Gemini 3.7 Flash and 2.8× gpt-5-mini;
Anthropic has no nano/lite tier. OpenAI's gpt-5-nano is nominally cheapest but beats Gemini Flash-Lite by
$0.04/category. Meanwhile `GeminiService` is Gemini-shaped throughout and `AiService` leaks
`maxOutputTokens`/`thinkingConfig` across the seam at 15+ sites — a real provider swap needs an
`AiTransport` interface and a prompt re-validation, versus one `.env` line to change models within Gemini.
**Batch API (50% off, all three providers) also declined** — would split the ingestion path in two to save
~$1/category.

- [ ] **Deferred (decide after T3): Claude for `generateLandingPageContent` only.** ~11 calls/quarter at
  ~3k in / 6k out ≈ **$1.10/quarter on Sonnet 5** to automate prose the owner currently writes by hand
  every content cycle. Needs the `AiTransport` interface that §5 says doesn't exist yet — and T3 may
  moot it by fixing the timeout. *[AI, Content, Architect]*
- [ ] **T1 gap: `generateCategoryImage()` bypasses `GeminiService` entirely**, so image-generation spend
  is the one AI cost the new `ai_usage` table cannot see. Pre-existing inconsistency (it calls the Gemini
  HTTP API on its own path), surfaced while wiring T1's `purpose` attribution through the other 13 call
  sites. Also a standing violation of the CLAUDE.md rule that all AI calls go through `AiService`/
  `GeminiService`. Low volume (category hero images only), so low cost impact — but it means
  "total AI spend" from `ai_usage` is an undercount, and any future cost dashboard should say so.
  *[API, AI]*

### Spec 037 T1 audit — three-agent, 2026-08-22 (`03d68d3`, already deployed)

Reports: `docs/reviews/audit-2026-08-22-review.md`, `docs/security/audit-2026-08-22-security.md`,
`docs/performance/audit-2026-08-22-performance.md`. **1 critical, 3 high, 8 medium.** No rollback —
nothing exploitable, no cross-tenant read path, no secret leakage, no injection.

- [ ] **A1 (CRITICAL — found independently by ALL THREE agents): every queued Bouncer call records
  `tenant_id = NULL`.** `evaluate_product` (the dominant cost, ~350 rows/category), `rescan_features`,
  and job-originated `match_product` are all unattributable. **The docblock's justification is factually
  wrong:** `BelongsToTenant` stamps `tenant_id` only `if (tenancy()->initialized)` — the same condition
  that makes `tenant('id')` return null — so on the queue the trait and the explicit resolution produce
  an *identical* NULL. Dropping the trait bought nothing on the write side. Verified: `config/tenancy.php`
  registers zero bootstrappers; `ProcessPendingProduct` never initializes tenancy.
  Sharpest case: `AiService::matchProduct()` resolves `$tenantId` at `:261`, uses it for every DB query
  in the method, then calls `generate()` at `:323` without forwarding it.
  **The `SeoMetric` precedent was misapplied** — `seo_metrics.tenant_id` is NOT NULL *and* its writers
  take the tenant as an argument, so a null row there is a DB error. `ai_usage` copied the trait
  omission and dropped both safeguards; Spec 037 §2 T1 said "`tenant_id` required" and the migration
  made it nullable.
  **Fix (~5 lines, plumbing already exists — `record()` already accepts `?string $tenantId`):** add
  `?string $tenantId = null` to `GeminiService::generate()` and forward to `record()`; forward the
  existing `$tenantId` in `matchProduct()`; add the param to `evaluateProduct()`/`rescanFeatures()` and
  pass `$product->tenant_id` from the two jobs, as `ProcessPendingProduct:108` already does.
  **Do NOT fix by calling `tenancy()->initialize()` in the jobs** — that flips `handle()` from
  explicit-`tenant_id` to global-scope semantics and breaks the deliberate `withoutGlobalScopes()` calls.
  Backfill of existing NULL rows is approximate; accept the gap and record the cut-over date.
  *[API, Data]*
- [ ] **A2 (fold into A1's fix): a null model string escapes the "accounting can never break the AI
  call" guarantee entirely.** Under `declare(strict_types=1)` a `TypeError` on `record(string $model,…)`
  is raised at the **call boundary**, so `record()`'s own try/catch cannot see it (verified empirically).
  `GeminiService:36` does `$model = $model ?? config(...)`, and `env('AGENT_ADMIN_MODEL', …)` resolves to
  null if `.env` holds the literal `null`. Two failures: every `admin_model` call dies from the
  accounting layer, and `ListProducts.php:32` **500s the entire Filament Products list page** — the
  operator's primary console — on a new call path this commit introduced at header-render time.
  Fix: `(string) config(...)` at both sites. Related: `estimateCost()` is called *inside* the try block,
  so a malformed (quoted-string) price yields **no row at all** rather than a null-cost row, discarding
  the token counts too. Compute cost before the try. *[API, Filament]*
- [ ] **A3 (HIGH): 11 of the 13 `purpose` strings have zero test coverage** — the field the whole table
  exists for is unpinned. Also untested: recording *before* the `MAX_TOKENS` throw (the commit's one
  non-obvious design decision), and T3b entirely. **And the existing tenant test validates the path that
  works, not the one that is broken** — `AiUsageInstrumentationTest.php:152-170` manually initializes
  tenancy before a `purpose: 'evaluate_product'` call, a state that never holds there, so it passes and
  gives false confidence; `AiUsageServiceTest.php:171-179` labels its null case `sweep_category`, a
  purpose that *does* have a tenant in production — the mapping is inverted. Add a test dispatching the
  real `ProcessPendingProduct` asserting `AiUsage::sole()->tenant_id === $product->tenant_id`: fails
  today, passes after A1. *[Test]*
- [ ] **A4 (MEDIUM): the swallow log line cannot diagnose or reconstruct what was lost.** Carries only
  `purpose`, `model`, `getMessage()`. Scenario: `migrate --force` half-fails, `ai_usage` missing, a
  350-product import runs looking perfectly healthy while `laravel.log` gains 700 identical
  `Base table not found` warnings nobody reads — and the token counts, the only irreplaceable data,
  were never logged. Add `tenant_id`, the three token counts, and `get_class($e)`; consider `Log::error`
  over `warning`. *[API]*
- [ ] **A5 (MEDIUM): add an explicit query contract to `AiUsage`** so the safe form is the easy form —
  `scopeForTenant()` + a documented `scopeAllTenants()`. Zero non-test readers exist today, so no live
  exposure, but the first widget written will be unscoped by default. *[Models]*
- [ ] **A6 (LOW, pre-existing, one-line): `ListProducts.php:72` passes an eager
  `Category::pluck('name','id')` to `Select::options()`**, executing a query on every page load, sort,
  keystroke and paginate to populate a monthly-use modal. Wrap in `fn () =>`. *[Filament, Perf]*

**Validated as fine — recorded so it is not re-litigated.** The synchronous INSERT on user-facing AI
paths is a **non-issue by ~3 orders of magnitude**: ~1ms against a 700–2500ms Gemini call (~0.08%), and
it sits at the transport boundary so `matchProduct()`'s cache hit and heuristic short-circuit pay zero;
these same requests already write a `SearchLog` row. No AI call runs inside a DB transaction. **Index:
leave it alone** — a full scan is 2–10ms and nothing degrades below ~500k rows (~40 years at pipeline
volume); fix attribution first, index work before that is wasted. **Column sizing: premature** — InnoDB
VARCHAR is length-prefixed, so `'evaluate_product'` costs 17 bytes at any declared length. `decimal(10,6)`
is the right money type; `const UPDATED_AT = null` is right for an append-only log; the
default-instantiated `AiUsageService` is harmless. Both spec safety rules are correctly implemented and
tested. The `config(...)['model']` dot-notation workaround is correct and well documented.

**Cross-check between agents:** security's M3 claimed the public AI entry points have no rate limit
(grepped only for `RateLimiter`). **False negative — a 10/min per session/IP limit does exist**, built on
a cache counter at `GlobalSearch.php:108` and `ProductCompare.php:572` (this is shipped S11). M3
downgrades to a low-priority `MassPrunable` suggestion.

**A1 — the repair window closes when the next top-up runs.** `ai_usage` rows carry no `product_id` and
no job back-reference, so nothing written before the fix can ever be retro-attributed. Worse, **Spec 037
§2 T1's own acceptance query masks the defect**: `SELECT purpose, COUNT(*), AVG(...) ... GROUP BY purpose`
has no tenant column, so it aggregates happily over the NULL bucket and returns entirely plausible
numbers. Reading it would produce false confidence that T1 works. Either fix A1 before the pw2d Tier-3
top-up, or accept that ~600 rows/category are permanently tenant-unattributed and note the cut-over date.
Token counts and costs are recorded correctly either way, so **T2's validation of the §1 cost model is
unaffected** — only per-tenant attribution is lost.

Also from the reviewer, settled and not to be re-litigated: `decimal(10,6)` needs ~1 billion output
tokens to overflow and `round(…, 6)` runs before insert — **leave it**. Record-before-`MAX_TOKENS` is
correct, and the throw paths I suspected are all fine: the 429 retry works despite `throw: false`, and
non-2xx correctly records nothing because those calls are unbilled; recording above the parts loop means
billed-but-useless 200s (`SAFETY`, `RECITATION`, `blockReason`) are still captured. `tenant('id')` is
safe on the central domain — it returns null, never throws. The `purpose` audit is **complete**: grep for
`generativelanguage.googleapis.com` across `app/` returns exactly two hits, so `generateCategoryImage()`
is the only bypass — and note `gemini-2.5-flash-image` is also absent from the pricing map, making that
gap doubly invisible.

## SEO checkpoint 2026-08-24 — new items

Full read: `docs/summaries/2026-06-13-seo-status-checkpoint.md` (UPDATE — 2026-08-24). Verdict:
**pw2d authority verdict unchanged and now positively confirmed** — c2d's preset-compare pages earn
clicks at pos 6-22 while pw2d's identical pages earn none at pos 15-21. Same code, opposite outcome,
separated only by rank. No new on-page specs for pw2d presets.

- [ ] **F36: `/best/` landing pages are near-orphaned — one internal link each, none from either
  homepage.** 8 of 11 `/best/` pages have **never** recorded a single GSC row; `/best/manual-coffee-grinders`
  is at 23 days, the other 7 at 15. Ruled out this session: HTTP 200, present in both sitemaps,
  self-referential canonical, no `noindex`, robots.txt clean — so this is crawl/indexation rationing,
  not a page-level block. Verified against rendered HTML: neither homepage links to any `/best/` URL,
  and the only inbound link found was `/compare/manual-coffee-grinders` → its own `/best/` page (that
  compare page itself sits at wpos 45). Not proven as *the* cause — super-auto indexed and
  manual-grinders did not, despite identical linking — but it is the weakest possible crawl signal and
  **the only code-shaped lever available on an otherwise authority-bound problem.** Needs a spec:
  homepage module linking all published `/best/` pages, plus reciprocal links from category/product
  pages. *[SEO, Frontend, Architect]*

- [ ] **F37: PostHog personal API key is dead — engagement measurement blocked on a credential, not on
  traffic.** c2d's 21 clicks/28d finally cleared the volume floor that has blocked this read since June,
  and the read failed anyway: `POSTHOG_PERSONAL_API_KEY` in local `.env` is well-formed (`phx_` prefix,
  52 chars) but PostHog returns `authentication_failed` on both `us.posthog.com` and `app.posthog.com`.
  **Owner action (~5 min):** mint a new personal API key in PostHog → Settings → Personal API keys,
  update local `.env`. Project 133580. *[Owner, Analytics]*

- [x] **Cleanup-impact watch (opened 2026-08-17): CLOSED as unmeasurable.** headsets/mics/lavalier
  compare pages run 2-3 impressions/week and were already declining *before* the Aug 12-16 unbuyable
  filter shipped. No attribution is possible at that volume, now or later. **Also a data correction:
  the baseline table recorded on 08-17 is wrong for mics** — it recorded 111/123/80/79 impressions for
  weeks 202629-32 where the actual figures are 4/3/2/2. Lavalier matches exactly and headsets is within
  GSC's normal revision, so the error is specific to that row; most likely the query matched `%mic%`,
  which catches every *microphone product page* slug rather than the `podcast-studio-mics` compare page.
  Do not diff against that row. *[SEO]*

- [ ] **All 5 pw2d landing pages are STALE (`selection_drift`) as of the 2026-08-24 audit; all 6 c2d
  pages FRESH.** pw2d pages last generated 08-14/16, c2d regenerated 08-21. **Verified genuine drift,
  NOT the H-A phantom** — checked `mechanical-gaming-keyboards` directly against prod:
  `SelectLandingPagePicks::execute()` returns 7 picks without throwing, and 2 of 7 stored picks really
  differ (slots 5 and 6: `2742→2799`, `2691→2722`). So H-A's "stamped `selection_drift` forever with
  nothing actually wrong" failure mode is **not** what is happening here; H-A remains open and separate.
  **Do not regenerate yet** — pw2d's pool is unverified (weekly pick verification has never been run for
  this tenant), and the response rule forbids re-selecting from an unverified pool. Folds into the
  existing Tier-3 top-up run sheet: verify picks → sweep → rescan → regenerate.
  *[Content, SEO]*

## 2026-08-28 — AI cost log check + pre-top-up fix bundle (AWAITING OWNER GO)

**Log check result:** `ai_usage` is empty because **no AI call has run since the 22 Aug deploy** — the
headsets import (117 rows, 96 accepted, cat 9) ran 13:16–13:23 that day, *before* T1 went live. Queue
healthy (2 workers, 0 jobs, 0 failed_jobs), all services active, all sites 200. 26 Aug 06:14 log burst =
`unattended-upgrade` restarting MySQL/Redis (3 harmless errors). One Livewire "array offset on null"
on 25 Aug = stale browser tab after deploy. **Nothing wrong with the log itself.** But two real findings:

- [x] **FINDING 1 — production `.env` runs models the price table doesn't know.** FIXED by Spec 038 B2 (deploy pending). Prod (unchanged since
  2026-07-21): `AGENT_ADMIN_MODEL=gemini-3.1-pro-preview`, `AGENT_SITE_MODEL=gemini-3.5-flash`,
  `AGENT_IMAGE_MODEL=gemini-3-pro-image`. `config/services.php` pricing map has none of them, so **every
  row the log ever writes on prod will carry `estimated_cost_usd = NULL`** (tokens still recorded). Spec 037
  §1.1 priced "current" as 2.5 Pro from the config *default*, never from prod's `.env`. Per the spec's own
  table the real per-product cost is ~$0.0132 (3.1 Pro), ~$4.62/category, and 3.7 Flash saves ~67% not
  58%. Also site_model is 3.5 Flash ($1.50/$9.00) — near Pro pricing for the user-facing model; the spec's
  "`matchProduct` is ~$0.001, not the problem" rests on it being 2.5 Flash. *[Config, AI, Architect]*
- [x] **FINDING 2 — 28 products stuck at `status='pending_ai'` forever (21 pw2d headsets, 7 c2d espresso).** FIXED by Spec 038 B3 + data migration (deploy pending).
  All are **renewed listings** the extension reported with a DOM-verified `condition: renewed`.
  `BatchImportController:208`: `ListingHealthService::apply()` returned `ACTION_FLAGGED_CONDITION` →
  product ignored, job *deliberately* not dispatched (correct) — but `status` is never cleared from
  `pending_ai` (bug). Harmless to the site (ignored products don't render), misleading in every
  "pending" count, and un-clearable by any retry path (`ListProducts` retry filters `status='failed'`).
  Same shape to check at `ProductImportController:203` and `OfferIngestionService:302`. *[API]*

### Fix bundle — one builder pass + one tester pass, deploy BEFORE the next import

- [x] **B1: A1 + A2 as specified above** (tenant attribution through `GeminiService::generate()`;
  `(string) config(...)` at `GeminiService:36` + `AiUsageService::estimateProductEvaluationCost()`
  (covers `ListProducts:32`'s call site); compute cost *before* the try in `AiUsageService::record()`).
  Built 2026-08-28 per `docs/specs/038-ai-usage-fix-bundle.md`. *[API]*
- [x] **B2: price map completeness.** Added `gemini-3.1-pro-preview` (2.00/12.00) and `gemini-3.5-flash`
  (1.50/9.00) from Spec 037 §1.1. Skipped the image model (bypasses `GeminiService`, T1 gap). In
  `AiUsageService::record()`, `Log::warning` once per unpriced model name, instance-scoped
  (`$warnedModels`); failed-write log upgraded to `Log::error` with tenant_id + all 3 token counts +
  `get_class($e)`. *[Config, API]*
- [x] **B3: guard-ignored products get `status = null`** at all three import sites (`BatchImportController`,
  `ProductImportController`, `OfferIngestionService`) when the listing guard ignores a brand-new product
  (never dispatched → never "pending"). Existing products deliberately untouched (see `docs/questions.md`
  2026-08-28 entry re: `ProductImportController`'s existing-product rescan path, out of scope). Idempotent
  data migration `2026_08_28_000001_clear_status_on_guard_ignored_products.php` shipped. *[API, Data]*
- [x] **T-bundle (builder-authored, per spec §2 "builder writes these with the code"):** A3's real-dispatch
  test added (`ProcessPendingProduct`/`RescanProductFeatures`/`matchProduct()` → `AiUsage` row carries the
  explicit tenant, no `tenancy()->initialize()`; verified fails-before/passes-after against the pre-fix
  code); the two inverted tests (`AiUsageInstrumentationTest.php` tenant-isolation test,
  `AiUsageServiceTest.php` null-tenant test) rewritten, not duplicated; unpriced-model → null-cost row +
  exactly-one warning per model per instance; B2 exact-value cost assertions for both new models; A2 null
  site_model / null admin_model / malformed pricing-value paths all covered; B3 batch-import + product-import
  + offer-ingestion `condition: renewed` coverage extended with `status === null` + `Queue::assertNothingPushed()`;
  migration test (only-the-guard-ignored-row, leaves-other-statuses-alone, idempotent-on-rerun). 15 new
  tests, `php artisan test`: 725 passed / 21 skipped (baseline 710/21, zero regressions). Tester pass still
  to extend/harden per normal workflow. *[Test]*
- [x] **Architect: correct Spec 037 §1 baseline** — correction note added under §1 on 2026-08-28; full table rewrite when T2 lands.
- [x] **Review pass (2026-08-28):** reviewer verdict SHIP, 0 critical/0 high/1 medium (`docs/reviews/review-2026-08-28-spec-038.md`). M1 fixed — `ProductImportController` now clears `pending_ai` for existing products too (the "in-flight job" exclusion in the spec was wrong; lesson recorded in `docs/lessons.md`). L1 fixed — `AiUsageService::record()` body wrapped in an outer never-throw guard so a logging failure cannot escape. Tester added 11 tests (10 remaining `purpose` strings + record-before-`MAX_TOKENS`). **Final: 737 passed / 21 skipped** (baseline 710). LOW/NIT leftovers filed below. **DEPLOYED 2026-08-28 11:21 UTC, prod `c31c602`** — migration DONE (28 stuck rows cleared, 0 remain), queue workers restarted (fresh PIDs), all sites 200, no log errors. `ai_usage` still 0 rows: the headsets rescan is the first live test.

**Then:** `/deploy` → headsets **rescan** (82 unchecked buyable offers ≈ 12 min; the 22 Aug import means
headsets needs a rescan, not another import) → first `ai_usage` rows appear, attributed to pw2d, priced →
continue run sheet (lavalier, ergonomic) → regenerate the 5 STALE pw2d pages.

### Spec 038 review follow-ups (reviewer 2026-08-28, `docs/reviews/review-2026-08-28-spec-038.md`) — all LOW/NIT, none block deploy

- [ ] **L2: unpriced-model warning dedupes per `AiUsageService` instance, and the service is not a
  singleton** — both jobs resolve `app(AiService::class)` per `handle()`, so on a worker the "once per
  model" becomes once per job. Only matters if prod `.env` drifts to an unpriced model again; then it is
  ~1 warning/product. Fix: bind `AiUsageService` as a singleton (and flip the "new instance warns again"
  test). *[API]*
- [ ] **N1:** `GeminiService.php:99` passes `$result['usageMetadata'] ?? []` without an `is_array()` guard —
  under `strict_types` a non-array value would TypeError at `record(array $usageMetadata)`, outside the
  guard. One-line cast. *[API]*
- [ ] **N2:** missing `@param ?string $tenantId` PHPDoc on `evaluateProduct()`/`rescanFeatures()`. *[Docs]*
- [ ] **N3 (pre-existing):** no `declare(strict_types=1)` in `ProcessPendingProduct`, `RescanProductFeatures`,
  `BatchImportController` — already covered by L7 above. *[Multiple]*
- [ ] **N4:** five other `AiService` methods hold a tenant model and could forward `tenant_id` explicitly
  for defence-in-depth; today the `tenant('id')` fallback covers them because they run with tenancy
  initialized. Do it if any of them ever moves onto the queue. *[API]*

### 2026-08-28 — headsets page rebuilt and live; pw2d top-up progress

- [x] **`/best/gaming-chat-headsets` regenerated and LIVE (14:29 UTC).** Full category rescan first (161
  buyable offers, 0 unchecked, 0 errors), then `pick_ineligible` traced to the Stinger 2's Amazon "High
  price" flag (popup counter showed 0 — the known undercount). Dry-run selection, Claude-authored prose,
  owner-reviewed via artifact, written through the model with a selection guard; backup
  `/tmp/headsets_backup_20260828_142919.json` on prod. Audit FRESH. 6/7 picks changed. *[Content]*
- [ ] **Remaining pw2d pages still STALE on `selection_drift`:** lavalier, mechanical-keyboards, mics,
  ergonomic-keyboards. Do NOT regenerate until each category is rescanned (never re-select from an
  unverified pool). Follow the run sheet: lavalier next (import → rescan → regenerate). *[Content]*
- [ ] **Two unchecked stragglers keep `import_debt` red:** 1 in mechanical-gaming-keyboards, 1 in
  podcast-studio-mics (the known Keychron C1 / Shure SM7dB+MVX2U rows). One single-scan each. *[QA]*
- [ ] **Cost log (`ai_usage`) still has 0 rows — expected.** The extension health rescan makes NO AI calls
  (`RescanProductFeatures` is dispatched only from Filament and `ProductObserver`); the first rows come
  from the next import (`evaluate_product`) or a site AI search. Verify with the Spec 038 §4 query after
  the lavalier import. *[Data]*

### 2026-08-28 — lavalier top-up: cost log VERIFIED, Gemini daily cap hit, pool polluted

- [x] **Spec 038 verified live.** First import after deploy: 435 `ai_usage` rows, **every one `tenant_id = pw2d`,
  zero null cost.** `evaluate_product` on `gemini-3.1-pro-preview`: 252 calls, avg 1416 in / 315 out / 659
  thinking → **$0.0145/product**; `match_product` on `gemini-3.5-flash`: 183 calls → $0.004/product.
  **All-in ≈ $0.0185/product, $4.39 for the batch.** Spec 037 §1's 3.1 Pro row estimated $0.0132 — output
  is heavier than assumed (thinking tokens ≈ 2× answer tokens). Feed into T2. *[Data, AI]*
- [ ] **Gemini daily cap on the admin model ≈ 250 evaluate calls (observed 2026-08-28).** Throughput
  46/57/50/45/44 per 5 min from 15:10, then 9, then 1; last 10 min = 23 completed vs 232 `429`s. 93
  lavalier products still `pending_ai` with 93 queued jobs (68 at attempt 1, 25 at attempt 2). Each will
  exhaust `tries=3` and land at `status='failed'` (the known swallow — not in `failed_jobs`). **Recovery:**
  after the quota resets (~07:00 UTC, midnight Pacific), Filament → Products → "Retry Failed" (they all
  have `category_id`, so the filter keeps them). Site-facing AI (3.5 Flash) is a separate quota, unaffected.
  **Planning rule: ≤ ~200 new products/day on the current admin model; "2 SERP pages per search" on 13
  phrases = 277 rows and blew it.** Confirm the exact limit in the Google AI Studio console — it is not
  published. This is Spec 037 T3's strongest argument yet. *[AI, Ops]*
- [ ] **Lavalier pool polluted by shotgun / on-camera mics — needs the sweep step before anything else.**
  15 processed, non-ignored products match shotgun|videomic|on-camera|bundle|case (Sony ECM-B10/G1/M1/
  VG1/674, RØDE VideoMicro II, VideoMic Me-C, MKE 600, "Mic 2 Bundle | Case"…), all created today. The
  dry-run selector already puts three of them in the top slots. Sequence once processing completes:
  `pw2d:ai-sweep-category lavalier-wireless-systems --dry-run` → review → sweep → rescan the ~190 unchecked
  (99 processed-unchecked + 93 pending; ~30 min in the extension) → dry-run → regenerate. **Do NOT
  regenerate before that.** Also confirms the Spec 031 amendment ("add the sweep step to Tier-3"). *[Content, QA]*
- [ ] **Lavalier page now STALE on `selection_drift` + `price_drift`** (audit 15:40). Expected; clears on regenerate. *[Content]*

### Spec 039 — Bouncer overflow path: operator-session evaluations (drafted 2026-08-28, AWAITING OWNER GO)

`docs/specs/039-bouncer-in-session.md`. Owner's call: use the idle Claude Code subscription on weekend
top-ups to finish what Gemini's daily quota leaves behind — interactively, never headless. v1 = overflow
path only; imports keep dispatching Gemini as today.

- [x] **T1: `ProductEvaluation` value object** — one validated schema for both producers; adds
  `wrong_category` reason (maps to sweep semantics). Gemini parse path constructs it; existing tests
  unchanged. `app/Support/ProductEvaluation.php` + `app/Exceptions/InvalidProductEvaluation.php`.
  `status` compatibility note: Gemini's real "scored" payload has no `status` key at all — `fromArray()`
  treats anything other than a literal `'ignored'` as scored (including the key being absent), matching
  `ProcessPendingProduct`'s pre-existing check exactly; this is documented on the class. *[API]*
- [x] **T2: extract `FinalizeProductEvaluation` action** from `ProcessPendingProduct` (move, not rewrite);
  `applyFeatureScores()` shared with `RescanProductFeatures` (closes L2). Retry/`failed` semantics untouched.
  `app/Actions/FinalizeProductEvaluation.php` + `app/Enums/FinalizeOutcome.php`. `wrong_category` (a
  `reason` under `status=ignored`, not a 3rd status) routes to a new `rejectFromCategory()` branch:
  `AiCategoryRejection::firstOrCreate`, `category_id`/`status` null via model-level `update()` (Spec 035:
  fires `ProductObserver`), `is_ignored` untouched. `php artisan test`: 764 passed / 21 skipped (737
  baseline + 27 new; zero regressions — the one pre-existing `SelectLandingPagePicksTest` failure seen
  running the FULL suite alone is Faker-seed order-dependent, confirmed by re-running that file in
  isolation, unrelated to this change). Reviewer pass after this step. *[API]*
- [x] **T3: `pw2d:products:export-pending`** — read-only JSON export of pending/failed products with
  category features, tenant brand list, N scoring anchors (deterministic: highest `amazon_reviews_count`
  first, id tiebreak), and the gate rules via new `App\Support\BouncerRules::text()` (extracted from the
  Gemini prompt — single source; `AiService::evaluateProduct()` now calls it, byte-identical prompt pinned
  by `tests/Unit/Services/AiServicePromptSnapshotTest.php` against a pre-refactor fixture). `--status=
  processed` = blind calibration export (no stored scores/brand/ai_summary/price_tier — price_note is
  recomputed from raw price + category thresholds, never the stored column). No slug → every leaf category
  with matching products, each under its own `category`/`rules`/`anchors`/`products` block in a top-level
  `categories` array (rules text is category-name-dependent, so it can't be hoisted like `brands` can).
  `app/Console/Commands/ExportPendingProducts.php` + `app/Actions/ExportPendingProducts.php` +
  `app/Support/BouncerRules.php`. *[Tooling]*
- [x] **T4: `pw2d:products:apply-evaluations`** — validates (schema → explicit `tenant_id` check, not just
  the global scope → status IN pending_ai/failed → feature names known to category), runs
  `FinalizeProductEvaluation::execute()` per product in its own `DB::transaction()`, records `ai_usage`
  with `model=claude-code-session` at exactly `0.0` (not NULL — required a small, documented
  `AiUsageService::estimateCost()` addition: a zero-priced model now short-circuits to `0.0` before the
  pre-existing "no token data → null cost" rule, which otherwise fired regardless of pricing since the
  call site passes `[]`/all-null tokens on purpose — see docs/questions.md). Idempotent (non-pending rows
  skip — verified by re-applying the same file twice), `--dry-run` (steps 1–3 only, zero writes, zero AI
  calls, predicts ignored/rejected deterministically and labels the scored/merged split as
  live-run-only since `matchProduct()` is unknowable without an AI call), exit 1 iff `errors > 0`.
  `app/Console/Commands/ApplyProductEvaluations.php` + `app/Actions/ApplyProductEvaluations.php`.
  `php artisan test`: 788 passed / 21 skipped (764 baseline + 24 new; zero regressions). Reviewer pass
  after this step. *[Tooling, API]*
- [x] **T5: harness `--from-file`** on `pw2d:ai:eval-model` (Spec 037 T2) — session scores measured against
  stored ones with the same gate (≥95% ignore agreement, ≥98% brand, ≤5pt MAD). **Session path not
  trusted for first-pass evaluation until it passes on a 50-product calibration export.** Built as the
  read-only diff core (Spec 037 T2's `eval-model` command didn't exist yet): `App\Actions\
  CompareProductEvaluations` diffs a T4-shaped evaluations file against each product's stored state
  (explicit `withoutGlobalScopes()->where('tenant_id', …)`, requires `status IS NULL`, else `unmatched`).
  `is_ignored` agreement treats a sweep-detached product (`category_id` null + `AiCategoryRejection`
  present) as "ignored" even though its own `is_ignored` column is false — documented on
  `storedIgnored()`. Brand compared both raw-exact and normalized-exact (`AiService::
  normalizeBrandForComparison()` on both sides). Feature MAD/max-delta over candidate-scored ∩
  stored-`ProductFeatureValue` pairs only; missing-side pairs counted as skipped, not deltaed.
  `ai_summary` condition-word hits via `ProductConditionGuard::summaryMarker()` (prose-shaped marker
  set, not `titleCondition()` — no separate landing-page banned-word class exists in `app/`, confirmed by
  grep). `App\Console\Commands\EvalModelCommand` ships `pw2d:ai:eval-model {tenant} {--from-file=}
  {--json=}` only — `--model=`/`--category=`/`--limit=` (the live-model runner) are out of scope here and
  documented as a follow-up in the class docblock; `--from-file` is required for now. Read-only — writes
  nothing. `app/Actions/CompareProductEvaluations.php` + `app/Support/EvaluationComparison.php` +
  `app/Console/Commands/EvalModelCommand.php`. `php artisan test`: 830 passed / 21 skipped (811 baseline +
  19 new; zero regressions). *[Tooling, Test]*
- [x] **T6: runbook `docs/ops/bouncer-session.md`** (drafted 2026-08-28 by architect; flags verified against the shipped commands) — operator sequence, subagent prompt template, budget
  note, dry-run-first rule, boundary. *[Docs]*
- [ ] **Flaky test (pre-existing, order-dependent): `SelectLandingPicksTest`** — the Spec 039 T1+T2 builder saw one failure in a full-suite run *before* touching anything; the file passes alone and did not recur. Likely shared state / ordering (cache or static). Reproduce with `php artisan test --order-by=random --seed=<n>` until it fails, then fix the isolation. *[Test]*
- [x] **Spec 039 T1+T2 review fix pass (2026-08-28, `docs/reviews/review-2026-08-28-spec-039-t1-t2.md`).**
  HIGH-1 fixed: `ProductEvaluation::fromArray(mixed $raw)` now throws `InvalidProductEvaluation('payload', …)`
  for a non-array payload instead of letting a `TypeError` escape the job's `catch (\Exception)` — reproduced
  the TypeError against pre-fix code with a throwaway test (confirmed failing), then confirmed the fix with a
  permanent job-level test (`ProcessPendingProductEvaluationTest::a_non_json_gemini_reply_throws_on_attempt_one_and_fails_the_product_after_three_attempts`,
  driven on the real `database` queue connection since `SyncJob::attempts()` is hardcoded to 1). MEDIUM-2:
  `buildIgnored()` now accepts any non-empty (trimmed) reason, defaulting a missing one to `''` exactly like
  the old job's `?? ''`; the four-value enum (`ProductEvaluation::VALID_REASONS`, now `public`) moved to
  `ApplyProductEvaluations` (T4 file rows only — an off-list reason there is an authoring mistake, not model
  drift). MEDIUM-3: `ai_summary`/feature `reason` truncated with `mb_substr()` at their cap instead of
  rejected; feature `reason` nullable; bare numeric feature entries and numeric-string scores accepted
  (mirrors the old loop's `is_array($value) ? … : (float) $value`); score `0` valid at the VO level (the
  `score > 0` write-guard in `applyFeatureScores()` still skips it). LOW: `price_tier` coerces out-of-range/
  non-numeric/non-integral values to `null` instead of throwing; the image-download helper's
  `catch (\Exception)` → `catch (\Throwable)` (the `parse_url()` consumers are `TypeError`s under
  `strict_types=1`); `$source` threaded into `FinalizeProductEvaluation`'s own `Log::info` context (was
  accepted-but-unused). Two job-level test gaps closed: merge (offer transfer, same-store-cheaper rule,
  `forceDelete`, via a seeded `AiMatchingDecision` cache hit — no HTTP) and pre-existing `AiCategoryRejection`
  (product detached before any scored write) now run through `ProcessPendingProduct::handle()`, not just
  traced; `FinalizeProductEvaluationTest`'s docblock corrected to stop claiming they were already covered.
  `php artisan test`: **811 passed / 21 skipped** (788 baseline + 23 net new; 3 pre-existing strict-contract
  tests flipped to the new behaviour, not left duplicated; zero failures). *[API, Test]*
- [x] **Spec 039 T3/T4 review (2026-08-28, `docs/reviews/review-2026-08-28-spec-039-t3-t4.md`): verdict SHIP,
  four MEDIUMs landed (fix pass 2026-08-28):** M1 per-row `try/catch` so one failing
  row cannot abort the batch; M2 enforce "every category feature present" on scored rows (explicit null ok);
  M3 blind calibration export must exclude exported ids from anchors; M4 apply refuses while `jobs` > 0
  (a queued Gemini job re-processes regardless of status and would overwrite session results) unless
  `--force`. Runbook step 2 corrected accordingly. Verified correct: tenant ownership check, status guard,
  enum at apply time, per-product transactions, dry-run writes nothing, idempotent re-run, `ai_usage` row at
  $0 with tenant, prompt byte-identical (fixture provenance proven), export read-only + tenant-scoped +
  eager-loaded, all six T1/T2 fix items, no locks held across the `matchProduct()` HTTP call. *[API, Tooling]*
- [x] **M1: per-row `try/catch` around `DB::transaction()`** in `ApplyProductEvaluations::applyRow()` — a
  throw (e.g. `matchProduct()`'s Gemini call failing) now becomes that row's `error` outcome (`Log::error`
  with `product_id`/`source`/exception class) instead of aborting the whole batch. *[API]*
- [x] **M2: "every category feature present" enforced at apply time** for scored file rows — a category
  feature name entirely absent from the raw row's `features` map is an `error` ("missing feature …"); an
  explicit `null` still counts as present ("not applicable"). *[API]*
- [x] **M3: blind `--status=processed` export excludes exported ids from its own anchor set** —
  `ExportPendingProducts::buildAnchors()` takes an exclude-ids list, populated with the exported product ids
  only in blind-calibration mode, so a product under test can never also appear as its own scoring anchor.
  *[Tooling]*
- [x] **M4: `apply-evaluations` refuses while the `jobs` queue is non-empty** (before doing anything,
  including `--dry-run`) unless `--force` is passed — a still-queued `ProcessPendingProduct` job ignores
  product status and can overwrite a product the session just finalized. Exit code 2, no table rendered.
  `docs/ops/bouncer-session.md`'s "What can go wrong" table updated with this refusal. *[API, Docs]*
