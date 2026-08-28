# Builder Patterns

## Preset slug derivation (load-bearing, Spec 023 §10)
Always use `Str::slug($preset->name)` — there is NO `slug` column on the `presets` table.
This derivation must be identical in three places or the preset join breaks:
1. `SeoSchema::forLeafCategory` — resolves the active preset from URL param
2. `ProductCompare::activePreset()` — computed property for Blade view
3. `pw2d:generate-preset-content` command — `--preset=` filter

## Preset content JSON shape
`preset.seo_content` (nullable JSON, cast to array) stores:
```json
{ "intro": "<p>...</p>", "faqs": [{"question": "...", "answer": "..."}] }
```
Access: `$preset->seo_content['intro']` and `$preset->seo_content['faqs']`.

## AiService::generatePresetContent validation pattern
Mirrors `generateCompareContent` exactly:
1. Call `$this->gemini->generate(...)` — trust its output (no double fence-strip).
   Exception: the fence-strip in `generateCompareContent` is intentional; replicate it.
2. `json_decode($content, true)` — throw `InvalidArgumentException` if not array.
3. Check key presence (`intro`, `faqs`) — throw on missing keys.
4. Check `intro` is non-empty string.
5. Check `faqs` is non-empty array.
6. Foreach faq: check it's an array with string `question` and `answer`.

## FAQPage schema merge pattern (SeoSchema::forLeafCategory)
When a preset is active: preset FAQs first, then category FAQs deduplicated by question string.
When no preset: category FAQs only. Either path uses the same `$mergedFaqs` variable feeding
into a single `if (!empty($mergedFaqs))` FAQPage emission block — no duplication of schema code.

## Artisan command exit-code rule (F25 / pw2d:seo:pull pattern)
Any per-item `try/catch` loop: increment `$errored`, log + continue.
Final: `return $errored === 0 ? self::SUCCESS : self::FAILURE`.

## Filament JSON sub-key fields
Filament dot-notation works for JSON columns: `Forms\Components\Textarea::make('seo_content.intro')`.
For repeaters inside JSON: `Forms\Components\Repeater::make('seo_content.faqs')`.
No custom accessor needed — Laravel's `'seo_content' => 'array'` cast handles serialization.

## BelongsToTenant check result (Spec 023)
`App\Models\Preset` DOES use `BelongsToTenant` (confirmed line 14 of Preset.php).
No action required — the trait is present.

## Spec 024 pattern: ALWAYS decouple schema/meta from render-capped collections (CRITICAL)
When a component caps what it renders for performance, JSON-LD schema and meta description
must be fed from the FULL intended set, not the render-capped collection. Googlebot sees only
the initial server response — it never fires x-intersect or JS-driven reveals.
- Add a SEPARATE `schemaProducts()` method returning the top `displayLimit` products with only
  the relations the schema actually reads (e.g. `with(['brand','offers'])`).
  Do NOT copy `featureValues.feature` from visibleProducts if the schema doesn't need it.
- Pass `$this->schemaProducts()` (not `$this->visibleProducts`) to `SeoSchema::forCategoryPage()`.
- Regression test MUST use `$this->get('/compare/{slug}')` and parse JSON-LD from HTML.
  `Livewire::test()` does NOT exercise the layout and will miss this class of bug entirely.

## Spec 024 pattern: render-window layered under displayLimit (CWV)
When you need to cut initial server-rendered HTML without breaking URL-state pagination:
- Add a SEPARATE `public int $renderLimit = 6` (NOT #[Url]) alongside the existing `#[Url] public int $displayLimit`.
- In `visibleProducts()`, cap with `min($renderLimit, $displayLimit)` on the NORMAL path only.
- Exempt all modes that must render the full set (H2H Arena, pinned-staging) by checking those
  conditions FIRST and returning early with `take($displayLimit)`.
- Add `revealMore(): void { $renderLimit = min($renderLimit + 6, $displayLimit); }` for x-intersect.
- Add `hasMoreToReveal(): bool` (NOT #[Computed]) that reads both mutable properties.
- Pass `$hasMoreToReveal` into view via `render()` so the Blade can conditionally render the sentinel.
- `loadMore()` bumps `displayLimit += 12`; `renderLimit` stays — `hasMoreToReveal()` naturally re-arms
  the sentinel (renderLimit < displayLimit again), no extra reset needed.
- `displayLimit` is clamped to min 12 in `mount()`, so `renderLimit=6` always starts below it.

## Name/slug length capping at ingestion (prevention fix, 2026-07-04)
Two independent layers, both needed — the AI prompt is advisory only; the job-level cap is what
actually prevents recurrence regardless of what Gemini returns:
1. **Prompt-level (best effort):** `AiService::evaluateProduct()` STAGE 2.5 has an explicit
   `NAME RULE: ... MAXIMUM 8 words` line. Gemini can still ignore this.
2. **Code-level (authoritative):** `ProcessPendingProduct::capProductName()` — private helper.
   Truncate at first `,` or `(` (marketing titles list specs/compat/bundles after these),
   fall back to first 8 words, strip a trailing stopword (with/for/and/the/of/in) left dangling
   by truncation. Apply to `$aiName` AFTER the existing "AI returned just the brand, keep original
   scraped title" fallback guard (that guard can itself reintroduce a long raw title — must be
   capped too). Use the SAME capped variable for both `name` and the `Str::slug()` stem so they
   never disagree.
3. **Stub-slug generation** (BatchImportController, ProductImportController, OfferIngestionService,
   all create a `Product` stub before AI runs): replace `Str::slug(Str::limit($title, 80))` with
   `Str::slug(Str::words($title, 8, ''))`. `Str::limit` truncates by *character count* (still long,
   can cut mid-word); `Str::words($value, $n, '')` truncates by *word count* with no trailing
   ellipsis — the right primitive whenever a title/name needs a word-based cap.

## Spec 027 pattern: extracting a shared per-item schema builder instead of duplicating it
When two SEO scenarios need the same ItemList entry logic (e.g. `buildItemListSchema` for
category/preset pages and a new `forLandingPage()`), pull the per-product `ListItem` builder
into a `private static function buildListItem(Product $product, int $position): array` and have
BOTH callers loop + call it. Keeps the Spec 026 rating gate (rated → nested Product +
aggregateRating; unrated → URL-only ListItem) defined in exactly one place. Never copy the
rating-gate `if` block into a second schema builder.

## Spec 027 pattern: reusing ProductScoringService outside ProductCompare
`ProductScoringService::scoreAllProducts($products, $features, $weights, $amazonRatingWeight, $priceWeight)`
works on any `Collection` of `Product` models that have `featureValues` eager-loaded (with
`feature_id`/`raw_value`) plus `amazon_rating`/`price_tier` columns — it doesn't require the
ProductCompare-specific stdClass conversion (that conversion is a cache-serialization optimization,
not a scoring requirement). To reproduce "ProductCompare's default-weight scoring path" elsewhere
(e.g. `SelectLandingPagePicks`), just build `$weights = $features->mapWithKeys(fn($f) => [$f->id => 50])`
and call `scoreAllProducts($products, $features, $weights, amazonRatingWeight: 50, priceWeight: 50)`,
then `->sortByDesc('match_score')`.

## Spec 027 pattern: cache invalidation via model `booted()` hooks, keyed by a model method
For a model whose cached view relies on `tenant_cache_key()`, don't hardcode the cache key string
in two places (model + controller). Define `public function cacheKey(): string` on the model and
have the controller's `Cache::remember($page->cacheKey(), ...)` call the SAME method the
`static::saved`/`static::deleted` hooks use to `Cache::forget()`. `tenant_cache_key()` resolves the
*ambient* tenancy context (`tenant('id')`), not a column on the model — safe because every code path
touching a `BelongsToTenant` model already requires tenancy to be initialized.

## Gotcha: nested `tenancy()->initialize()`/`->end()` across Artisan::call boundaries
Commands that follow the `tenancy()->initialize($tenant); try { ... } finally { tenancy()->end(); }`
pattern (GeneratePresetContent, GenerateCompareContent, GenerateLandingPage, etc.) are safe when
run standalone via CLI — but if you ever invoke one via `Artisan::call(...)` from code that is
ITSELF already inside a `tenancy()->initialize()` block (e.g. an ad hoc tinker smoke-test script,
or a future command that shells out to another), the inner command's `finally` `tenancy()->end()`
terminates the OUTER context too (tenancy isn't reference-counted/nested). Re-initialize after the
`Artisan::call()` if the caller needs tenancy to remain active. Not a bug in the commands themselves
— just a sharp edge when composing them in-process.

## Pattern: transient non-persisted override for an Eloquent accessor (Spec 027 review S2)
When a computed accessor (e.g. `title`) needs to reflect a value only known at RENDER time
(e.g. a post-filter count) rather than the STORED value, don't mutate a real column/attribute
(risks accidental persistence via `save()`) and don't add a new parameter to every method that
reads the accessor. Instead declare a real, typed, non-cast PHP property on the model
(`public ?int $renderedPickCount = null;`). A real declared property short-circuits Eloquent's
`__get`/`__set` magic entirely — setting it never touches `$attributes`, never marks the model
dirty, and is never written by `save()`. The accessor falls back to the stored value when the
transient property is null (`$this->renderedPickCount ?? count($this->picks)`), so every existing
caller that doesn't set it keeps working unchanged. The controller sets it once, before passing
the SAME `$page` instance to both the view and any schema/service builder that also reads the
accessor — single source of truth, no signature changes needed anywhere.

## Pattern: deterministic tenant-scoped cache keys on `BelongsToTenant` models (Spec 027 review S1)
`tenant_cache_key()` (app/Helpers/cache.php) resolves the AMBIENT `tenancy()` context — correct
for request-scoped code, wrong for a model's own `saved`/`deleted` cache-invalidation hooks, which
must work even when the model is touched outside initialized tenancy (tinker, a queued job, a
Filament path where `TenantSet` didn't fire). Any model that owns its own cache key should build
it from ITS OWN `tenant_id` column instead: a private static helper
`tenantScopedCacheKey(?string $tenantId, string $key): string { return 't' . ($tenantId ?? 'central') . ":{$key}"; }`
reproduces `tenant_cache_key()`'s exact key FORMAT (so existing cached entries built under
ambient tenancy stay addressable) but is deterministic. Controllers that call `$model->cacheKey()`
need no changes — they inherit the fix automatically by delegating instead of building the key
themselves.

## Pattern: shared allowlist sanitizer for raw `{!! !!}` AI-content Blade renders (Spec 027 review S3)
`compare-content.blade.php` established the convention inline:
`strip_tags($html, '<p><br><ul><ol><li><strong><em><h3><h4><a>')` + a `javascript:` href
`preg_replace`. The moment a SECOND view needs the same treatment, extract it — don't copy the
inline snippet again. Global helper `sanitize_ai_html(?string $html): string` in
`app/Helpers/html.php`, registered in `composer.json`'s `autoload.files` (same pattern as
`app/Helpers/cache.php`), run `composer dump-autoload` after adding a new helpers file. Anything
that's genuinely plain text (validated as a string, not HTML, by the AI response validator —
e.g. `methodology_note`) should render via `{{ }}`, not go through the sanitizer at all.

## Gotcha: `composer dump-autoload` triggers `filament:upgrade` via `post-autoload-dump`, touching vendored public assets
This project's `composer.json` `post-autoload-dump` script runs `@php artisan filament:upgrade`,
which republishes Filament's compiled JS/CSS into `public/`. Running `composer dump-autoload` after
adding a new `autoload.files` entry (or any autoload change) silently dirties
`public/js/filament/filament/app.js` (and similar) with a re-minified rebuild — unrelated to your
actual change, pure noise in the diff. Check `git status` after any `composer dump-autoload` and
`git checkout -- <path>` the incidental asset diff before finishing.

## Pattern: testing Filament resources despite the F12 REGEXP/sqlite navigation-badge blocker
`ProblemProducts::getNavigationBadge()` uses raw REGEXP SQL that crashes on the sqlite test
connection — this fires on EVERY full panel page render (`$this->get('/admin/...')`), which is why
`SeoDashboardTest`'s two HTTP tests are skipped (documented as F12 in `docs/tasks/todo.md`).
`Livewire::test(SomeFilamentPage::class, ['record' => ...])` on an individual Resource Page class
(List/Create/Edit) does NOT render the panel layout/navigation and sidesteps F12 entirely —
confirmed empirically for Spec 027's `LandingPageResourceTest` (same technique F17 already
established for testing widgets in isolation). Setup needed: `$this->actingAs($admin)` +
`Filament::setTenant($tenant)` (this project bridges Filament's `TenantSet` event to
`tenancy()->initialize()` in `AdminPanelProvider::boot()`, so you get stancl tenancy for free —
still call `tenancy()->initialize($tenant)` yourself too, before creating `BelongsToTenant`
fixtures, since factory creation needs it independent of Filament's event timing). Disabled repeater
fields (e.g. read-only `product_id`/`role` in a picks repeater) need `->dehydrated()` explicitly —
Filament v3 does NOT dehydrate `disabled()` fields by default, so without it the value is silently
dropped from the save payload.

## Pattern: killing AI writing "tells" in generated prose (Spec 027 landing pages)
When owner feedback says AI content "reads unmistakably AI-written," a generic "sound more human"
instruction in the prompt doesn't work reliably — Gemini needs an explicit, enumerated contract:
1. **BANNED list of literal words/phrases**, not just patterns — include single words banned
   OUT OF CONTEXT (state this explicitly: "'robust' is banned even in 'robust build,' an
   unrelated-seeming use — use 'sturdy'/'solid'/'well-built' instead"). A vague ban ("avoid
   clichés like robust") is not enough; the model will still drop the exact word in occasionally.
   Give a replacement word for each ban so it has somewhere to go.
2. **A literal pre-return self-check instruction**: "search for each banned word ONE AT A TIME
   across every field you wrote" — reread-and-check phrasing alone was insufficient; naming the
   mechanism (search field-by-field, word-by-word) measurably reduced the miss rate.
3. **Force real numbers into the prompt payload** so claims can't be vague: pass the TRUE
   scored-product count (not the pick count) as a new required param, and compute per-pick price
   deltas vs. the cheapest pick inline in the prompt builder (derived from data already passed in,
   no new param needed if the picks array already carries `estimated_price`).
4. **Require one concrete, plainly-stated tradeoff per pick** ("the software is clunky," not "may
   not be for everyone") AND one specific data citation (a feature score, a price delta) — this
   single rule does more to kill generic listicle voice than any tone instruction.
5. Validate empirically: after generating, `strip_tags()` the concatenated intro/bodies/faqs and
   grep (case-insensitive) for the literal banned strings — don't just eyeball it. Budget for a
   SECOND generation pass (still cheap — one admin_model call) if the scan finds a hit; tighten the
   specific banned-item's wording in the prompt (make it "even out of context" explicit) rather
   than re-writing the whole style section.
- Response JSON schema (the keys the caller parses) can and should stay UNCHANGED when only the
  prose-quality instructions change — don't couple a style pass to a data-contract change.
- Any `AiService`-method-extending test spy (anonymous `extends AiService { public function
  someMethod(...) }`) breaks with a PHP Fatal "Declaration ... must be compatible" error the
  moment the parent method gains a new REQUIRED parameter — grep the whole test suite for
  `extends AiService` (not just the obviously-related test file) before adding a param, and give
  the override a default value (`int $newParam = 0`) even though the real base method doesn't have
  one; overriding methods are allowed to add defaults to a parent's required params.

## Gotcha: an upstream AI-generated field fed into a prompt as "context" can itself be fabricated
`generateLandingPageContent()` passes each pick's `ai_summary` (generated much earlier by the
UNRELATED `evaluateProduct()` AI Bouncer pipeline) into the prompt as descriptive context. That
field is not ground truth — it's a different model's earlier, unverified output, and it CAN already
contain a fabrication (e.g. `ai_summary` inventing "even if renewed" for a product with no condition
data anywhere in our schema, because `evaluateProduct()`'s own prompt has the same
world-knowledge-vs-scraped-price failure mode). A GROUNDING instruction that only says "use the
data provided" is not enough if part of "the data provided" is itself an untrusted upstream AI
output — be explicit about which specific claims are OFF LIMITS regardless of what any input text
implies (here: "never state or imply listing condition, ever, regardless of what other context
suggests"). This should be it's own line in the prompt, not folded into "ground claims in the data,"
because a naive reading of "the data" includes the polluted field. When debugging a fabrication,
grep every UPSTREAM field passed into the prompt (not just the new prompt's own instructions) for
the fabricated claim — the fastest fix is often "stop trusting an input field for this specific
claim type," not "add a stronger ban."

## Pattern: one shared marker-detection class for a cross-cutting data-quality rule (Spec 027 Addendum A)
When the SAME substring-marker check (condition words: renewed/refurbished/open box/pre-owned/used)
needs to run in 5+ unrelated places (a selection Action, 3 separate ingestion controllers/services,
an AI prompt, an audit command), don't inline the marker array anywhere — one static class
(`app/Support/ProductConditionGuard.php`) with `titleMarker()`/`summaryMarker()` (returns the
matched string or null) is the single source of truth every caller imports. Two DIFFERENT marker
sets under one class when the same word over-matches in different fields: raw listing titles can
safely include the bare word "used" (title marker set), but AI-generated prose cannot — "designed to
be used with..." is a plain verb, not a condition claim — so the ai_summary-facing marker set
deliberately omits "used". Don't try to solve this with a single marker list + a "is this prose or a
title" flag; two `private const` arrays on the same class is simpler and every caller is explicit
about which one it means.

## Pattern: "next-best candidate" duplicate guard in a deterministic multi-slot selector
When a selector picks N winners into named roles/slots (Spec 027 `SelectLandingPagePicks`) and needs
to reject near-duplicate candidates (same product under two un-merged rows) WITHOUT leaving a slot
empty, don't just filter the final winner and re-check — thread the duplicate predicate into every
single `first()`/`addPick()` call site so the search naturally continues to the next-highest-scored
candidate. Concretely: build one `$isDuplicateOfPicked` closure (captures `&$pickedIds` by reference)
comparing normalized names (`preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name))` — strips spaces/
hyphens/punctuation so "Q6 Max Black" and "Q6 Max - Black" collapse to the identical string) via
`str_contains()` both directions PLUS a `similar_text()` percentage threshold (85%) as a fuzzy
backstop for near-but-not-identical normalized forms. Fold this same closure into `$addPick`'s own
guard (belt-and-suspenders — cheap, since duplicates are rare) AND into every `->first(fn(...) =>
...)` predicate used to locate a candidate for a specific role, so a duplicate never gets returned as
"the best candidate" in the first place — you don't need a wrapping retry loop, `Collection::first()`
with a predicate already IS the "try next-best" mechanism once the predicate itself excludes
duplicates.

## Gotcha: Amazon blocks direct server/CLI HTTP fetches — don't build a "live-verify by scraping" audit mode
Built (then had to fully revert) a `--live` mode for `pw2d:flag-condition-products` that fetched each
candidate's Amazon offer URL server-side to catch a Renewed listing invisible in our stored data. The
owner corrected: Amazon blocks direct server/CLI fetches outright (this is a known constraint of the
whole pipeline — `SyncOfferPrices` already accepts silent per-offer failures as normal/expected, not
an edge case to eventually fix). Don't propose "just live-fetch it to verify" for ANY audit/verification
task in this codebase — the correct pattern is a read-only REVIEW table (id/name/offer URL/relevant
context) that lets a human open the URL in a real browser, or routes through the Chrome extension
(client-side, not server-side) later. When reverting an abandoned server-fetch attempt, remove ALL of
it in one pass — the command options, the HTTP-calling method, AND any "pure" detection helper that
existed only to support it (e.g. an HTML-marker-scanning function) — a detection function with zero
remaining callers is still dead code even though it doesn't itself do networking.

## Gotcha: ProductFactory does not set `slug` or `image`-related fields
`database/factories/ProductFactory.php` has no `slug` in its `definition()` (confirmed again while
smoke-testing Spec 027) — any ad hoc/manual `Product::factory()->create()` for a script or smoke
test MUST pass `slug` explicitly or `route('product.show', ['product' => $product->slug])` throws
`UrlGenerationException: Missing required parameter`. Also has no `image_path`; if the code under
test gates on "has an image" via `image_url` (local → best offer → any offer), create a
`ProductOffer` with `image_url` set, not just any offer.

## Pattern: one shared "apply cross-cutting field semantics" service used by 3+ ingestion paths (Spec 029)
This codebase has 3 independent product/offer ingestion entry points (`OfferIngestionService`,
`BatchImportController`, `ProductImportController`) that each hand-roll their own `Product`/
`ProductOffer` creates/updates — there's no shared base ingestion class. When a NEW cross-cutting
rule needs to apply identically everywhere (Spec 029's condition/listing_flags handling), don't
inline the logic 3x — extract a small stateless-ish service (`App\Services\ListingHealthService`)
that takes the already-resolved `ProductOffer`/`Product` instances + the raw incoming
condition/flags, mutates them, and returns an optional "action override" string the caller can
splice into its own response shape. Each of the 3 call sites stays in control of ITS OWN
create/update/response-building (they genuinely differ — batch loops with counters, single-import
returns a different action vocabulary) while the actual RULE (condition → ignore, high_price →
offer-only flag, clean → clear) lives in exactly one place. Same technique as the pre-existing
`ProductConditionGuard` (title-marker matching) — this codebase's established way to share a
cross-cutting ingestion rule is a small `App\Support`/`App\Services` class with a narrow, explicit
method signature, NOT a shared base controller/trait.

## Gotcha: `Dispatchable::dispatch()` constructs the job SYNCHRONOUSLY even under `Queue::fake()`
`SomeJob::dispatch($arg1, $arg2)` calls `new static(...$arguments)` before ever touching the queue
connection — a typed non-nullable constructor param that receives `null` (e.g. `$category->tenant_id`
when a test creates a `Category` without ever initializing tenancy) throws a `TypeError` at dispatch
time, and `Queue::fake()` does NOT protect against this (the fake only intercepts what happens
AFTER construction). If a job's constructor arg is naturally sometimes-null in this codebase's own
test conventions (e.g. `tenant_id` in tests that bypass `InitializeTenancyFromPayload` and never call
`tenancy()->initialize()`), make the param nullable (`?string $tenantId`) and handle `null` explicitly
in `handle()` (e.g. skip the explicit re-init and rely on whatever ambient scoping already exists)
rather than assuming production's non-null invariant holds in every test context.

## Pattern: queued job that must (re-)initialize tenancy explicitly (Spec 029 `RecalculateCategoryPriceTiers`)
`config('tenancy.bootstrappers')` is empty in this project (single-DB tenancy, see `config/tenancy.php`
comment) — there is NO queue tenancy bootstrapper, so a real `QUEUE_CONNECTION=database` worker process
has zero ambient tenant context when a queued job's `handle()` runs. Any NEW job that touches
`BelongsToTenant` models must capture the tenant ID at DISPATCH time (constructor param) and
explicitly `tenancy()->initialize()`/`tenancy()->end()` around its own work — mirrors the pattern
Console commands already use (`GenerateLandingPage::handle()`, `GeneratePresetContent`). Guard with
`$alreadyInitialized = tenancy()->initialized` checked BEFORE initializing, and only call
`tenancy()->end()` in the `finally` block if THIS job was the one that initialized it — otherwise
(the `sync` queue driver used in tests, where the job runs inline inside an already-tenant-scoped
request/test) you'd tear down tenancy out from under the caller that dispatched you. Existing async
jobs in this codebase (`ProcessPendingProduct`, `RescanProductFeatures`) do NOT do this — don't take
that as precedent; they may have a latent multi-tenancy gap in a real `database`-queue deployment,
out of scope to fix opportunistically. New jobs should do it correctly from the start.

## Pattern: extracting a chunked recalculation service so a Console Command AND a queued Job can share it (Q13)
When an existing Console Command loads an entire dataset via an unbounded `->get()` (memory risk) and
a NEW feature needs to trigger the "same recalculation" reactively from application code (not just
manually via CLI), don't `Artisan::call()` the command from inside a job (composing tenancy-initializing
Artisan commands from within other tenancy-initializing contexts double-inits/tears-down tenancy — see
the earlier "Gotcha: nested tenancy()->initialize()/->end() across Artisan::call boundaries" entry).
Instead extract the core logic into a plain service class using `Model::where(...)->chunkById(N, ...)`,
have the Job call the service directly (no Artisan bridge, no nested-tenancy risk), and have the
Console Command become a thin wrapper that also calls the service — passing an optional
`?callable $onUpdate` callback lets the interactive command keep its per-row `$this->line(...)` output
without the service needing to know about console I/O at all.

## CORRECTED 2026-08-10 (was wrong — do not use): raw SQL `LIKE` against a JSON-cast column is DEAD on MySQL
The pattern previously documented here (`whereRaw('picks LIKE ?', ['%"product_id":' . $id . ',%'])`
against a native MySQL `json` column) shipped in Spec 030 and passed every sqlite test, but is a
real production bug (audit B1, 2026-08-10): MySQL re-serializes JSON to a NORMALIZED string (spaces
after every `:`/`,`) when coercing a `json` column to text for `LIKE`, so the space-free pattern
never matches on MySQL. sqlite's `json()` column is backed by plain `TEXT` and happens to preserve
Laravel's raw space-free `json_encode()` output — which is exactly why the test suite couldn't catch
this. **Correct approach:** for a bounded row count (a dozen-ish landing pages, etc.), just load the
rows and filter in PHP — `LandingPage::where('tenant_id', $t)->get(['id','picks'])->filter(fn ($p) =>
collect($p->picks ?? [])->contains(fn ($pick) => (int) ($pick['product_id'] ?? 0) === $id))`. Same
technique `ListingHealthService::warnIfLandingPagePick()` already used — this is now the ONLY
sanctioned approach for "does this row's JSON column contain X" at this project's scale. **Rule:**
any raw SQL fragment touching a `json`-cast column must be reasoned about (or smoke-tested) against
MySQL specifically — a green sqlite suite proves nothing about that fragment on the real engine. See
`docs/lessons.md` (2026-08-10 entry) for the full writeup.

## Pattern: shared static `dispatchForX()` on the Job class itself, called from multiple unrelated trigger sites (Spec 030)
When the SAME "find affected records, dispatch one job per record" query needs to fire from two
different places for two different reasons (a model Observer's ignore-flip/detach/delete, AND a
service's offer-level flag change that the Observer structurally cannot see), don't duplicate the
query. Put a `public static function dispatchForProduct(Product $product): void` directly on the
Job class (`AuditLandingPageFreshnessJob`) that does the "find pages, `self::dispatch(...)` per
page" work, and have both call sites (`ProductObserver::saved()/deleted()`, `ListingHealthService::
apply()`) call the ONE static method. Keeps the query in exactly one place, and both trigger sites
stay a one-line call — cheaper than extracting a separate finder service class for a single query.

## Gotcha: `SelectLandingPagePicks`' eligibility filters and `LandingPageController`'s render filter are NOT the same set — but render's is a strict subset of select's
`SelectLandingPagePicks` requires: not is_ignored, `status` null, category attached, `ai_summary`
present, image present, no condition-marker text, no `high_price` best-offer flag. The controller's
render-time filter only checks: not is_ignored, `status` null, category attached. Every condition
the render filter checks, select also checks — so anything that fails render ALSO fails re-selection
(cascading `render_short` + `selection_drift` together), but NOT vice versa: a product that fails
select for a render-invisible reason (missing `ai_summary`, a condition-marker in prose, `high_price`)
still renders fine today (the render filter doesn't know about those). When building a "does this
still render vs. would still be selected" comparison (Spec 030's `AuditLandingPageFreshness`), don't
assume the two filters are interchangeable or that either reason can always be isolated from the
other in a test — `status` (pending_ai) is the one criterion in BOTH filters that ISN'T part of a
narrower "pick_ineligible"-style contract if that contract is spec'd to only mention deleted/
detached/is_ignored/condition — check the exact reason contract text before assuming a broader
"anything ineligible" rule.

## Pattern: Filament Repeater identity fields must be `dehydrated(false)` + force-restored in `mutateFormDataBeforeSave()`, never just `disabled()` (2026-08-10 audit fix)
`disabled()` alone only stops the browser from rendering an editable input — Filament's own docs
warn a `disabled()` field is still client-tamperable via a crafted Livewire update payload, and
`disabled()->dehydrated()` (the pattern originally used here) actively re-adds the tampered value
back into the saved `$data`. The correct, structurally-safe pattern for "this field must NEVER be
attacker- or admin-editable via this form, even though it needs to be visible/exist in state for
labels": declare it `->disabled()->dehydrated(false)` (excludes it from `$data` entirely) AND, in
the owning `EditRecord` page, override `mutateFormDataBeforeSave(array $data): array` to force-
restore the real values from `$this->record` (the pre-edit DB row) by array index — safe only when
the Repeater also disallows add/delete/reorder (`addable(false)/deletable(false)/reorderable(false)`),
since index alignment between stored and submitted picks is otherwise not guaranteed. This makes
tampering structurally impossible rather than merely inconvenient (see `LandingPageResource` picks
Repeater + `EditLandingPage::mutateFormDataBeforeSave()`).

## Gotcha: `foreach ($arr['key'] ?? [] as &$item)` silently binds to a temporary copy — mutations never reach `$arr`
Discovered writing the `mutateFormDataBeforeSave()` fix above: a by-reference `foreach` requires its
subject to be a real array lvalue. `$data['picks'] ?? []` is an *expression* (the `??` operator), so
even when `$data['picks']` is set and the expression evaluates to it, PHP iterates a TEMPORARY copy
by reference — every `$pick['x'] = ...` assignment vanishes with that temporary, with zero errors or
warnings. Silent, hard to spot in review, easy to miss even with a debug `Log::info()` right before
the loop (the logged pre-loop values look completely correct). Always guard explicitly instead:
`if (isset($arr['key']) && is_array($arr['key'])) { foreach ($arr['key'] as &$item) { ... } unset($item); }`.
Full writeup in `docs/lessons.md` (2026-08-10).

## Gotcha: `assertDatabaseHas` against a `json`-cast column is unreliable on real MySQL — assert the array-cast accessor instead
Discovered verifying the BatchImportController no-price-delisting fix (2026-08-12) against a real
local MySQL DB instead of the default sqlite test run: `assertDatabaseHas('product_offers', ['listing_flags'
=> json_encode(['high_price'])])` FAILS on MySQL 9.3 even when the stored value is visibly identical
(`SELECT` shows `["high_price"]`). Reproduced outside Laravel entirely via raw PDO: `WHERE
listing_flags = ?` (bound param, exactly what the query builder generates) returns 0 rows, while
`WHERE listing_flags = CAST(? AS JSON)` returns the match. sqlite's `json`-typed column is backed by
plain TEXT and does a byte-string compare, so the assertion always "works" there — same shape of
"MySQL's JSON handling differs from sqlite's, and sqlite hides it" as the `LIKE`-against-JSON lesson
from 2026-08-10. **Never `assertDatabaseHas` a raw json_encode()'d value against a `json`-cast
column.** Instead fetch the model (`$offer->fresh()`) and assert against the Eloquent `array`-cast
accessor (`$this->assertSame(['high_price'], $offer->listing_flags)`) — this is already the pattern
`OfferIngestionServiceTest` uses throughout; `BatchImportControllerTest`'s condition/listing_flags
tests predate this discovery and still use the broken pattern (left as-is, logged in
`docs/questions.md`, not this task's scope to fix repo-wide).

## Pattern: verify MySQL-gated tests (`requireMysql()` skip guard) against a REAL local MySQL DB before calling a fix done
This project's `phpunit.xml` forces `DB_CONNECTION=sqlite` for the default `php artisan test` run
(without `force="true"` on the `<env>` tags) — so any `requireMysql()`-gated test in
`BatchImportControllerTest` (or similar) silently skips locally and the "N passed / M skipped"
baseline never actually proves those tests pass. Because phpunit's `<env>` only wins when nothing
already set the variable, exporting `DB_CONNECTION`/`DB_DATABASE`/etc. at the SHELL level before
`php artisan test` overrides it and runs the real body: `DB_CONNECTION=mysql
DB_DATABASE=<throwaway_test_db> DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= php artisan test
--filter=SomeTest`. Create the throwaway DB first (`mysql -uroot -e "CREATE DATABASE IF NOT EXISTS
<name>"`), never point it at `.env`'s real dev database name, and `DROP DATABASE` it when done —
`RefreshDatabase` migrates/rolls back per test but still needs a real schema to migrate into. This
is how the BatchImportController no-price-delisting fix (2026-08-12) actually validated its two new
regression tests pass for real, and how it discovered the `assertDatabaseHas`-on-`json`-column and
`Queue::assertNothingPushed()` gotchas above/below (both pre-existing, unrelated to that fix).

## Gotcha: `$product->best_offer` (or any "best X" accessor with its own eligibility filter) silently returns null when the LAST eligible offer stops being eligible — never build an exclusion check on it alone
Root cause of a live prod incident (2026-08-12): `Product::bestOffer` (lowest-price offer) filters
out `scraped_price === null` offers before picking the cheapest. `SelectLandingPagePicks`/
`AuditLandingPageFreshness` both built their pick-eligibility exclusion checks as "does `best_offer`
carry a bad flag/condition?" — a reasonable-looking check that quietly assumes `best_offer` always
exists. When a product's ONLY offer went flagged + null-priced (a delisted Amazon listing), `best_offer`
became `null`, the flag/condition check had literally nothing to inspect, and both `?->` / early
`return false` null-handling treated "nothing to check" as "nothing wrong" — the product passed as
eligible and got picked/ranked #1 on a live page with no purchasable offer. **The general shape of
this bug: any accessor that itself applies a filter (here, "exclude null-price offers") is not a safe
base for a SEPARATE exclusion check downstream** — the downstream check's null-branch needs to mean
"ineligible" whenever the reason the accessor is null is "no candidate survived its OWN filter",
not "no candidates exist at all". The fix: don't special-case `best_offer`'s null path at all — iterate
the full `offers` collection directly and require at least one offer to independently satisfy every
condition (priced AND flag-clean AND [condition-clean, where applicable]) in a single `contains()` /
`Collection::contains()` call. This can never be silently skipped by a filtered-accessor's absence.
Test the false-negative case explicitly: a fixture where the ONLY offer fails the SAME filter the
"best" accessor itself applies (here: null price) is the case that proves the bug, not a fixture
with multiple offers where at least one survives.

## Gotcha: a narrow eager-load column allowlist (`with(['rel:id,col1,col2'])`) silently defeats an accessor's exclusion logic — an unselected column reads as `null`, never an error
Found while extending `Product::bestOffer` to exclude negative-condition/pick-excluding-flag offers
(2026-08-15). Eloquent does NOT throw when code reads an attribute that wasn't in the query's SELECT
list — it just returns `null`. An accessor exclusion check like `!in_array($o->condition,
NEGATIVE_CONDITIONS)` or `array_intersect(PICK_EXCLUDING_FLAGS, $o->listing_flags ?? [])` then
evaluates every offer as "clean" whenever the caller's eager load omitted `condition`/`listing_flags`
from its column list — not a crash, just a silently inert filter. Two real call sites had this trap:
`PriceTierRecalculator` (`with(['offers:id,product_id,scraped_price'])`) and `SelectLandingPagePicks`
(`with(['offers:id,...,listing_flags'])`, missing `condition` specifically). **Whenever you add a new
exclusion/filter condition to a model accessor, grep every narrow `with(['relation:col1,col2,...'])`
eager load anywhere that accessor (or anything derived from it, e.g. `image_url` falling back to
`best_offer`) gets called, and confirm the new column is in the allowlist** — `grep -rn
"offers:id,product_id"` is the fast way to find every narrow offers-select in this codebase. A test
against a fully-hydrated model (no column restriction) will NEVER catch this class of bug, since the
accessor logic is genuinely correct — only the caller's SELECT is wrong.

## Pattern: strongest-signal-wins precedence table for competing condition/health signals, as ONE shared static helper
`ProductConditionGuard::resolveEffectiveCondition($condition, $title)` (Fix 1, 2026-08-15) replaced a
naive `$condition ?? titleCondition($title)` coalesce that let an explicit-but-weaker payload value
(`'new'`) silently skip a stronger textual signal (a title marker). When two signals of different
trust levels can each independently claim a value, resist collapsing the logic to a single `??` or
ternary — write out the actual precedence as an explicit if/else chain (strongest-wins first, weakest
last) in ONE shared static method, and call it identically from every site that used to duplicate the
naive coalesce (grep for the literal duplicated expression across the codebase — it's the tell that a
shared helper is overdue, not a fresh design decision). Unit-test the precedence table directly (pure
function, no DB) — cheaper and clearer than only exercising it through 3 separate HTTP/service
integration tests.

## Pattern: "flag the child, ignore the parent only if no sibling survives" — query fresh from DB, don't trust an eager-loaded collection that predates the write you just made
`ListingHealthService::hasCleanOffer()` (Fix 2, 2026-08-15) needed to check "does this product have
ANY OTHER purchasable offer" immediately after updating the exact offer being flagged, inside the same
method call. Several callers eager-load `$product->offers` (or `Product::with('offers')`) BEFORE
calling into `apply()`, so the in-memory collection can still reflect the flagged offer's OLD (clean)
condition at the moment of the check — enough to wrongly conclude "a clean offer survives" when the
only "clean" one is the very offer that just went bad. Fix: query `$product->offers()->get([...])`
(the relation *method*, not the already-loaded *property*) to force a fresh DB read that reflects the
`update()` call that just committed moments earlier. Rule of thumb: any "does a sibling record still
satisfy X" check performed in the SAME request/method as a write to one of those siblings must re-query,
never trust a collection that could have been loaded before the write.

## Pattern: one endpoint, two scopes — branch the validation rules array itself, not just the query, and extract the pre-existing branch verbatim
`OfferIngestionController::rescanList()` (Spec 031 T1, 2026-08-16) added `scope=picks` alongside the
existing `scope=category` (default) behaviour, with a requirement that today's `category_id` validation
and its tests stay byte-for-byte intact. The safe shape: read the raw scope value straight off the
request BEFORE validating (`$request->input('scope', 'category')`), build the `$rules` array
conditionally (`category_id`'s whole rule entry is only added when scope isn't `picks`), THEN call
`$request->validate($rules)` once. This means a param that should be "ignored, not merely optional"
under one branch is never even inspected by the validator in that branch — nothing to 422 on, nothing
to coerce, nothing for a crafted/foreign value to probe. The pre-existing query became its own private
method (`categoryScopeOffers()`) moved verbatim (no refactor-while-extending) so its existing test suite
proves zero behavioural drift, and the new branch (`picksScopeOffers()`) is a separate method entirely
— resist the urge to parametrize one query with `if`s in the middle of the builder chain once the two
scopes' filters genuinely diverge (one filters by `is_ignored`/`status`/`category_id`, the other
deliberately does neither).

## Gotcha: a concurrent parallel-agent spec edit can reveal a cross-cutting contract your task description didn't mention — re-read the shared spec file before declaring done
While building Spec 031 T1 (server), the parallel T2 (extension) agent's own build surfaced — via its
own client-side preflight check — that `POST /api/extension/ingest-offer` requires `category_id`, and a
`scope=picks` response spans every category, so each row needs its own `category_id` to complete the
round trip. Neither my task description nor the spec text as originally read called this out; it only
became visible because the shared spec markdown file was being edited by both agents in the same
window and a `Read`-before-`Edit` on it returned a "modified on disk since you last read it" warning
with the other agent's new section already written in. **When two agents build complementary halves of
one contract concurrently, the shared spec doc is itself a signal channel — a mid-task disk-modified
warning on it is worth actually reading in full, not just re-applying your own edit over it.** Fixed by
eager-loading the missing column (`product:id,category_id`) rather than reworking the query shape, and
logged the cross-agent finding explicitly in `docs/questions.md` (distinct from a builder judgment call
— this was an external contract gap, not a spec ambiguity).

## Pattern: when 3+ copies of "is X purchasable/valid" drift, extract ONE predicate PLUS a companion
## eager-load-columns constant (2026-08-16 audit S2)
`ListingHealth::isPurchasable(ProductOffer $offer, bool $requirePositivePrice = true): bool` replaced
four independently-drifted copies of the same rule (`Product::bestOffer`, `ListingHealthService::
hasCleanOffer`, `SelectLandingPagePicks::hasEligibleOffer`, `AuditLandingPageFreshness::hasEligibleOffer`)
— one of the four had silently lost a clause (B2), which is exactly the failure mode a shared predicate
prevents by construction. Ship it alongside a matching `ListingHealth::OFFER_HEALTH_COLUMNS = ['condition',
'listing_flags']` constant and have every narrow `offers:id,product_id,...` eager-load string build its
column list via `implode(',', ListingHealth::OFFER_HEALTH_COLUMNS)` instead of a hand-typed literal — this
is what makes the existing "narrow-select silently defeats an accessor's exclusion" gotcha (logged above,
2026-08-15) mechanical instead of something a reviewer has to remember to check on every future column
addition. `grep -rn "offers:id,product_id"` still finds every site; the difference is that adding a 3rd
health column now means editing ONE constant, not auditing N call sites by hand. When a predicate needs a
relation that not every caller wants to pay for (here: `$offer->store` for an `is_active` check), make it
read-only-if-present rather than force an eager load — `$offer->store === null` should mean "unknown,
don't exclude" so callers that skip the eager load for performance get a safe default, and callers that DO
eager-load it get the check for free, with zero extra code at either call site.

## Gotcha: a "belt-and-braces" validation rule placed AFTER a `prepareForValidation()` normalization step
## will basically never fire — that's fine, the normalization is the actual fix
Sec M1 (2026-08-16 audit): `listing_flags` needed to reject an associative payload (`{"<huge
key>":"high_price"}`) that could otherwise persist an attacker-controlled STRING KEY verbatim into a JSON
column (the prior fix only bounded VALUE count via `max:5`). The audit's suggested fix pairs an
`array_values()` normalization in `prepareForValidation()`/`$request->merge()` with an `array_is_list()`
validation rule — but once `array_values()` has already stripped any non-list keys, the array the
validator SEES is always a list, so the `array_is_list` rule can basically never fail in the normal
request path. That's correct, not a wasted rule: the actual security fix is the normalization (attacker
key never reaches the DB, request still validates as 200 OK with only the VALUES kept), and the rule is
genuine defense-in-depth for a future code path that might call the FormRequest's `rules()` without going
through `prepareForValidation()` first. Write the regression test to assert the NORMALIZED-success
behavior (200 OK, stored value is the stripped list, attacker string absent from the DB) — asserting a 422
here is testing for behavior the fix doesn't actually produce.

## Pattern: anchored regex per-marker map, not a shared substring list, when ONE marker in an otherwise-safe
## list is ambiguous (2026-08-16 audit B1)
`ProductConditionGuard`'s bare `'used'` marker was a `str_contains()` false-positive magnet ("fo**cused**",
"h**oused**", "**unused**") while every OTHER marker in the same list (`renewed`, `refurbish`, `open box`,
`pre-owned`) was genuinely safe as a plain substring check. Don't reach for one blanket fix (e.g. wrapping
every marker in `\b...\b` word boundaries) when only ONE marker needs it and the others have already-correct,
already-tested behavior you don't want to risk perturbing. Instead: a `private const MARKER_PATTERNS =
['marker_string' => '/regex/', ...]` map keyed by the SAME marker strings already in the `TITLE_MARKERS`/
`SUMMARY_MARKERS` lists, with `firstMatch()` doing `preg_match(MARKER_PATTERNS[$marker], $lower)` instead of
`str_contains($lower, $marker)`. Every marker gets an explicit, independently-reasoned-about pattern (most
are still a bare unanchored substring regex, byte-equivalent to the old `str_contains` behavior — only
`used`'s pattern is actually different), and the two markers this codebase already keeps in TWO SEPARATE
lists (title vs. summary, see the earlier "one shared marker-detection class" pattern) can freely diverge in
their regex strictness too, since summary's marker set never even includes the ambiguous one. When porting a
positional/anchored guard that already exists correctly elsewhere (here: the Chrome extension's
`conditionMarkerFromText()`), port the exact regex rather than re-deriving an equivalent one — it's
already been shaped by real false-positive/true-positive cases the other implementation encountered.

## Gotcha: `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` does NOT escape `json_encode()`'s own
## structural quotes — only characters inside encoded string VALUES
When hardening a `{!! json_encode(...) !!}` JSON-LD sink against stored XSS (Sec H1, 2026-08-16 audit),
adding `JSON_HEX_QUOT` looks like it should break every existing test that substring-matches the raw JSON
output (e.g. `assertStringContainsString('"@type":"ItemList"', $html)`) — it does not. Verified empirically:
`json_encode(['@type' => 'ItemList', 'name' => 'a "quoted" & <script>'], JSON_HEX_TAG|JSON_HEX_QUOT|...)`
produces `{"@type":"ItemList","name":"a "quoted" & <script>"}` — the delimiting
`{`/`"`/`:`/`,`/`}` characters `json_encode()` itself emits are never touched; only `"`/`<`/`>`/`&`/`'`
characters that appear AS PART OF an encoded string's actual content get hex-escaped. This means adding
these flags to a JSON-LD encoder is safe to do without auditing every downstream consumer that parses via
substring-match instead of `json_decode()` — but confirm this empirically (a one-line `php -r` check) before
relying on it, don't just trust the PHP manual's phrasing. Also drop `JSON_UNESCAPED_SLASHES` in the same
change if present — it was doing the OPPOSITE of what's needed (unescaping `/` is what let `</script>`
survive as `</script>` instead of `<\/script>`); the two hardening changes belong together, not as separate
follow-ups.

## Pattern: `whereJsonDoesntContain()` IS sargable on both MySQL and SQLite — but needs a `whereNull` guard
## to match a PHP predicate's "absent = clean" semantics (2026-08-16, "hide unbuyable products" task)
Don't assume a JSON-column exclusion check "isn't sargable" just because an earlier comment says so —
verify empirically first (a throwaway table, both grammars, dropped immediately after). `ListingHealth`
already had a PHP predicate (`isPurchasable()`) treating `$offer->listing_flags ?? []` — i.e. NULL is
treated as "no flags, clean" — but the naive SQL twin, `whereJsonDoesntContain('listing_flags', $flag)`,
compiles to `NOT (JSON_CONTAINS(...))` on MySQL, and `JSON_CONTAINS(NULL, ...)` returns SQL NULL, which a
`WHERE` clause treats as non-matching — so a genuinely clean, never-flagged offer (`listing_flags IS NULL`)
would be silently EXCLUDED, the opposite of the PHP predicate's behavior. Fix: wrap it —
`$q->where(fn ($fq) => $fq->whereNull('listing_flags')->orWhere(fn ($sub) => /* the whereJsonDoesntContain
chain */))`. Verified correct against both a real MySQL `json` column and an in-memory SQLite connection
(SQLite compiles to a `json_each` `NOT EXISTS` subquery — different mechanism, same wrapper needed and
same correct result). When mirroring an existing PHP "is this row eligible" predicate as a SQL `whereHas`
constraint, check EVERY branch's NULL behavior against the PHP version's null-coalescing/`??`/`in_array`
defaults individually — they don't automatically agree just because the non-NULL cases match.

## Pattern: when a visibility-gating change makes existing test fixtures fail, the fixtures were asserting
## the OLD (now-intentionally-wrong) behavior — update them, don't work around the new filter
Hiding unbuyable products from `ProductCompare::scoredProducts()` broke ~20 pre-existing tests across 4
files whose fixtures created `Product::factory()` rows with NO offer at all, then asserted the product was
visible/counted in the grid (`CompareRenderLimitTest`'s `scaffoldCategory()` helper, `CompareContentOrderTest`'s
`makeProduct()`, `ProductCompareIntegrationTest`'s header test, plus one `ProductCompareTest` case that
explicitly asserted a flagged-only-offer product STAYS visible — the exact premise this task reverses).
None of these tests were "about" buyability; they were about renderLimit pagination, schema counts, content
ordering, header wiring. The fix is to give the fixture helper a `Store` + purchasable `ProductOffer` (one
`Store::create()` per test/category, one cheap `ProductOffer::create()` per product) so the test's ACTUAL
subject continues to exercise real behavior — not to special-case the new filter in test setup, and not to
leave the old assertion in place expecting a card that will never render again. Grep broadly
(`grep -rl "ProductCompare\|scoredProducts\|visibleProducts" tests`) before trusting a single test file's
green run — a change to a shared computed property's base query touches every consumer transitively, and
`php artisan test <single-file>` passing proves nothing about the other 15 files that render the same grid.

## Pattern: client-supplied entity-id targeting under a shared/non-rotating token needs TWO independent
## tenant scopes, not one (Spec 033, `offer_id` rescan targeting)
When a client-supplied integer id (here `offer_id`) is added to short-circuit an existing fuzzy lookup
(here `(store_id, url)`), scope it to the tenant in BOTH layers even though it looks redundant:
1. The Form Request/controller validation rule — `Rule::exists('table', 'id')->where('tenant_id', tenant('id'))`
   — this is what turns a foreign-tenant id into a 422 instead of a 200 that silently no-ops or (worse)
   resolves.
2. The service that actually loads the row — `Model::where('id', $id)->where('tenant_id', $tenantId)->first()`
   — even though the model already has `BelongsToTenant` and even though the controller already validated
   it. This project's API controllers for the Chrome extension run under `InitializeTenancyFromPayload`
   (tenant resolved per-request from an `X-Tenant-Id` header sent alongside a shared, non-rotating token —
   see `docs/questions.md` H3), not domain-based tenancy, so nothing guarantees every future caller of the
   service goes through that one controller's validation. A bare integer id is strictly WORSE to leave
   unscoped than the URL it's replacing — trivially enumerable, whereas a URL at least required knowing a
   real one. Treat this as the default shape for any future "let the client target one row by id" endpoint
   on a shared-token API, not a one-off.

## Pattern: "prefer the targeted row, gracefully degrade to the old fuzzy lookup" — 3-branch precedence,
## never a hard failure at the service layer (Spec 033)
`OfferIngestionService::resolveExistingOffer()` is the template: (a) id supplied → load it, tenant-scoped;
if its foreign key (here `store_id`) disagrees with a value independently resolved earlier in the same
request (here `store_id` from `store_slug`), that's a STALE hint, not a hard error — log a warning naming
both values and fall through to (c); (b) id supplied but resolves to nothing (row deleted between the
list endpoint and this POST) — same silent fall-through, no exception, no log (this is the expected/normal
race, not an anomaly); (c) no id, or fell through — the original lookup, now with an explicit `orderBy('id')`
so multi-match behavior is documented rather than accidental, and a `Log::warning` naming every matched id
when there's more than one (a previously-invisible bug class made greppable for free). The controller's
`Rule::exists` still 422s the common case (a genuinely nonexistent id at request-validation time) — the
service's never-hard-fail behavior is what protects direct/programmatic callers and the same-request race
window the controller's validation can't cover. Both layers are correct simultaneously; they are not in
tension. Test this precedence at the HTTP layer (via `postJson`), not just the service — the tenant-scoping
matters most exactly at the boundary where an untrusted id crosses in.

## Pattern: testing a real cross-tenant HTTP flow needs `withoutMiddleware` in `setUp()` PLUS a
## per-test `withMiddleware([InitializeTenancyFromPayload::class])` + `X-Tenant-Id` header
Established in `RescanListControllerTest`, reused for `OfferIdRescanTargetingTest`: disable
`VerifyExtensionToken`/`InitializeTenancyFromPayload` globally in `setUp()` so plain `postJson()`/`getJson()`
calls don't need a token, but for any test that must prove tenant isolation, explicitly
`$this->withMiddleware([InitializeTenancyFromPayload::class])` and send `X-Tenant-Id`. Build fixtures for
each tenant inside `tenancy()->initialize($tenant)` / `tenancy()->end()` pairs (factories + `Model::create()`
calls auto-fill `tenant_id` from the active tenant via `BelongsToTenant` — never pass `tenant_id` by hand),
then issue the actual request with the header. This is the only way in this codebase to get a real,
request-scoped `tenant('id')` for testing a `Rule::exists(...)->where('tenant_id', tenant('id'))` boundary —
calling the service directly (as `OfferIngestionServiceTest` does) skips the controller's validation rule
entirely and cannot exercise the 422 path.

## Pattern: DTO's `reasons()` computed list is the ONE place threshold logic lives — CLI and Filament both
## build the DTO from an already-decorated Eloquent record instead of duplicating the thresholds (Spec 032)
`CategoryHealthRow` (`final readonly`, `App\Support`) owns `STALE_DAYS`/`AGING_DAYS`/`THIN_BUYABLE`/
`CHURN_PCT` as `public const`s plus a `reasons(): array` method. `AssessCategoryHealth::decorate(Builder
$categories): Builder` is the SQL half (three `withCount` closures aliased via `'relation as alias' =>
fn ($q) => ...` — the documented way to count the same relation multiple times with different constraints
— plus two `addSelect(['alias' => $subqueryBuilder])` correlated subqueries; Laravel resolves an array
value that's a Builder as a scalar subselect automatically, no `selectSub()` needed). `AssessCategoryHealth::
fromRecord(Category $category): CategoryHealthRow` (kept `public`, not just used internally by `execute()`)
maps ONE already-decorated record to the DTO — both the CLI (`execute()` calls `decorate()` then
`fromRecord()` on each row) and Filament's column closures (`CategoryResource::healthRow()` just calls
`AssessCategoryHealth::fromRecord($record)` on the record Filament's own `modifyQueryUsing(fn ($q) =>
AssessCategoryHealth::decorate($q))` already produced) go through the identical mapping function. Never let
a Resource's column closures re-derive `reasons()` from raw `_count` attributes directly — that's the second
copy of the exact threshold logic this whole pattern exists to prevent.

## Pattern: correlated `addSelect` subquery for "aggregate across a filtered child relation, scoped to the
## parent's own tenant" needs NO explicit `tenant_id` join condition — the child model's own global scope
## already provides it, AS LONG AS the parent row is already tenant-scoped (Spec 032)
`AssessCategoryHealth::checkedAtSubquery()` builds `Product::query()->join('product_offers', ...)
->whereColumn('products.category_id', 'categories.id')->selectRaw('MIN(product_offers.health_checked_at)')`
with no `where('products.tenant_id', ...)` anywhere in the subquery. This is safe (not an oversight) because
(a) `Product` still carries its own `BelongsToTenant` global scope, which applies to this `Product::query()`
subquery exactly as it would to any other, and (b) `categories.id` in the correlating `whereColumn` is itself
already a specific, tenant-owned row (the outer query is always either a `Category::query()->where('tenant_id',
...)` or a Filament table query scoped by the active panel tenant) — a product's `category_id` FK inherently
pins one exact category row regardless of cross-tenant id collisions, since ids are globally unique
auto-increments shared across the one physical `categories` table. This is the same trust boundary
`AuditLandingPageFreshness` already relies on (see its own doc-comment). Don't add a redundant
`product_offers.tenant_id = ?` join condition "for extra safety" — it adds a column to reason about with zero
behavioral change, since the FK-pinning argument already closes the gap.

## Gotcha: `Livewire::test(SomeComponent::class, [...])` triggers a FULL render (not just `mount()`) — a
## computed property that's cheap to call directly can still blow up on missing test fixture data the
## render path needs but the property itself doesn't (Spec 032 regression test against `ProductCompare`)
Calling `Livewire::test(ProductCompare::class, ['slug' => $category->slug])->instance()->scoredProducts` to
reuse an existing computed property as a cross-check oracle in an unrelated test (Spec 032's "buyable_count
must agree with ProductCompare" regression) throws `UrlGenerationException: Missing required parameter
[Route: product.show]` from deep inside `SeoSchema` — NOT from `scoredProducts()` itself, but from the
Blade render Livewire performs as part of `test()`. Root cause: `ProductFactory` doesn't set `slug` (see the
existing "ProductFactory does not set slug" gotcha above), and the render path builds `product.show` URLs for
schema/canonical purposes that a raw call to the computed property alone would never touch. Fix: give every
`Product::factory()->create([...])` in a test that goes through `Livewire::test()` (as opposed to constructing
the Livewire class manually or calling a service directly) an explicit unique `slug`, even when the test's own
assertions never read it. Prefer `->instance()->scoredProducts` (the memoized computed PROPERTY access,
established in `ProductCompareTest`) over calling `->scoredProducts()` as a plain method — both work, but the
property form matches every existing precedent in this codebase and reads as "use the same public surface a
Blade view would".

## Gotcha: an ad hoc `php artisan tinker --execute=...` sanity check runs against the REAL `.env` database
## connection, NOT the test suite's sqlite — never use it to "quickly check" a tenant-scoped Action/query
`phpunit.xml` only forces `DB_CONNECTION=sqlite`/`DB_DATABASE=:memory:` for `php artisan test`; `php artisan
tinker` reads the project's real `.env` (MySQL, the actual dev/prod-shaped database) with no such override.
A `tinker --execute` one-liner meant purely as a "does this Action's SQL even run" smoke check will create
real rows in the real `pw2d` database on ANY successful `Tenant::create()`/`Model::create()` call before a
later line fails — an FK violation or exception three lines later does not roll back the earlier inserts
inside the same `--execute` invocation (each statement commits independently; there's no implicit
transaction wrapping the whole script). If this happens, immediately `mysql ... -e "DELETE FROM <table> WHERE
id = '<throwaway-id>'"` and verify a `SELECT COUNT(*) ... WHERE id LIKE '%<marker>%'` returns 0 before
continuing — don't just abandon the row and move on. The correct way to "quickly check" a query is a real
Pest/PHPUnit test file (even a throwaway one, since the sqlite DB is isolated and reset automatically by
`RefreshDatabase`), not `tinker`.

## Gotcha: `php artisan test`'s reported baseline can be a moving target when a second agent session is
## concurrently landing its own new test file in the same working tree — isolate with `git stash` before
## trusting a "before" number that doesn't match what you were told to expect (Spec 032, 2026-08-20)
Told to expect "baseline 641 passed / 21 skipped" but an early clean run (after a `composer dump-autoload -o`
fixed an unrelated stale-autoload false failure) read 641 once, then 647 on every subsequent run — including
runs where NONE of this task's own files existed yet. Cause: a concurrent builder session on a different spec
(033) was actively adding its own new test file (`tests/Feature/OfferIdRescanTargetingTest.php`, +6 tests) to
the SAME shared working directory in parallel, and its own `docs/questions.md` entry independently reported
the exact same "641 → 647" transition. Don't assume a stated baseline number is still accurate by the time you
run the suite — if your own "before" run disagrees with what you were told, `git stash push -u -m "..." --
<only your own new/modified files>` (leave everything else in the tree untouched, including any other
in-flight agent's uncommitted work — do NOT `git stash` the whole working directory), re-run the suite to get
the TRUE immediate-prior baseline, `git stash pop`, and report the reconciled numbers with the explanation
rather than silently using whichever number happens to match what you were told.

## Pattern: when a spec's prose under-specifies an algorithm but ships a "verified by hand" table,
## treat the table as the actual spec and re-derive the missing rule from it (Spec 034, 2026-08-21)
`SelectLandingPagePicks::modelKey()` (Spec 034 §1) describes joining a short "qualifier" token onto a
digit-bearing "candidate" token (e.g. `GIGA` + `10` → `giga10`) and states the length limit only on the
qualifier side ("≤5 chars"). Read literally, that rule wrongly prefixes an already-self-identifying long SKU
token (`ECAM29043SB`, 11 chars) with an unrelated preceding word, producing `evoecam29043sb` — but the spec's
own hand-verified table says the answer is `ecam29043sb`. The fix (apply the same ≤5-char gate to the
candidate token too) is the ONLY rule that reproduces every row of the table at once, so it's a safe,
confident inference rather than a guess — when a real-world verification table is provided, treat every row
of it as a hard constraint the implementation must satisfy, and if the prose and the table disagree, the table
wins (it's presumably what got hand-checked against production data); log the gap and the reasoning in
`docs/questions.md` rather than silently picking one interpretation.

## Pattern: a new "soft" selection cap that needs a two-pass (prefer X, fall back to any) search belongs in
## ONE shared resolver closure next to the existing single-candidate `$addPick`-style gate, not duplicated
## inline at every pick site (Spec 034 §2, `SelectLandingPagePicks::$pickBrandAware`)
When several pick sites each currently do `$collection->first(fn ($p) => <predicate>)` then hand the single
result to a shared `$addPick`-style acceptor, and a new rule needs "prefer a candidate matching an EXTRA
condition, but if none exists take the best one that doesn't — and log when that happens" — don't bolt the
extra condition onto each site's existing predicate plus a duplicated fallback search. Extract ONE closure
(`fn (Collection $candidates, callable $predicate, string $role): ?Product`) that does the two-pass search
+ conditional log once, sharing the same `use (&...)` state (here: `$pickedIds`, `$brandCounts`) as the
acceptor closure, and have every site call `$addPick($resolver($candidates, $predicate, $role), $role)`. Keeps
the acceptor's own contract/signature completely unchanged (safer for "preserve existing behaviour exactly"
instructions) while avoiding 5x copy-pasted two-pass-search-with-logging boilerplate — the actual DRY reading
of "this must be a single chokepoint."

## Gotcha: a Factory's default relationship (e.g. `'brand_id' => Brand::factory()`) creates a FRESH,
## unrelated row on every `Model::factory()->create()` call unless explicitly overridden — existing test
## fixtures that never pinned that FK can silently break when new logic starts keying off it. WHEN THAT
## HAPPENS, FIX THE FIXTURE, NOT THE PRODUCTION RULE (Spec 034, corrected 2026-08-21)
`ProductFactory::definition()` has `'brand_id' => \App\Models\Brand::factory()`. A pre-existing test in
`SelectLandingPagePicksTest` built two "near-duplicate" products (e.g. "Keychron Q6 Max Black" vs
"Keychron Q6 Max - Black") via two separate `makeEligibleProduct()` calls and got two DIFFERENT, unrelated
Brand rows by accident — harmless under the old name-similarity-only duplicate guard (which never looked
at `brand_id`), but broke the moment new logic started keying identity off `brand_id` (Spec 034's
`modelKey()`, which treats the model key as AUTHORITATIVE once both sides have one).
**First instinct was wrong and got caught in review:** widened the production rule so a confirmed
model-key MISMATCH would still fall through to the old similarity check "just in case," reasoning the
fixture's mismatched brands proved a fallback was still needed. That widening reintroduced the exact bug
the spec exists to fix — real, genuinely-different products with high `similar_text` (Philips 4400 vs
1200, 95.7% similar) started getting wrongly merged again, because a correct model-key "not a duplicate"
verdict was being overruled by a stale string-similarity signal. **The correct fix was the fixture, not
the rule:** the two Keychron rows are the SAME physical keyboard in the real world, so they should share
one brand — pin both to `Brand::factory()->create(['name' => 'Keychron'])` explicitly, and the existing
production rule (same brand + same model token = duplicate) handles it correctly with zero code changes.
General lesson: when a NEW identity/business rule starts failing an OLD test because that test's fixture
took an implicit factory default that happens not to match real-world data shape, the fixture is almost
always the thing that's wrong (it was never asserting on that default, just relying on it by accident) —
fix the fixture to represent reality accurately, and treat "I need to loosen the new rule to keep an old
fixture passing" as a signal to stop and check whether the fixture itself is unrealistic before touching
production logic.

## Gotcha: `Product::where(...)->update([...])` (a mass Builder update) fires NO Eloquent events —
## this codebase leans on observers for freshness, cache-busting, and feature-value integrity, so this
## silently disables all three (Spec 035, 2026-08-21 incident)
Any `SomeModel::where(...)->update([...])` (query-builder-level, not `$model->update([...])` on an
already-loaded instance) skips `saving`/`saved`/`updating`/`updated` entirely — Eloquent only fires
these from `Model::save()`. In this codebase that means: `ProductObserver::saved()` (instant freshness
audit + Spec 035 feature-value cleanup) never runs, and any model with a `booted()` cache-forget hook
(e.g. `LandingPage`) never busts its cache. Both bugs found in the same 2026-08-21 session had this SAME
root cause in two different places (an AI command's bulk update, and an ad-hoc `DB::table(...)->update()`
regeneration script) — "the cache isn't busting" and "category re-homes leave stale feature values" read
like two unrelated findings but were one lesson. **Fix pattern:** inside a `chunkById`/`each` loop that's
already iterating model instances one at a time (the common case for admin/AI commands), there is no new
N+1 cost to switching `Model::where('id', $id)->update([...])` to `$instance->attr = $value; $instance->
save();` — the loop already has the instance in hand. **Rule of thumb for this codebase:** any FUTURE
bulk-mutation code path (command, ad-hoc script, tinker one-off) that touches a model with an `Observer`
or a cache-busting `booted()` hook must go through `save()`/`delete()` on the model, never a raw
`Builder::update()`/`DB::table(...)->update()` — grep for `Observers/` and model `booted()` static hooks
before assuming a bulk update is "just faster and equivalent."

## Gotcha: `->select(['id', 'name'])` on a chunked query silently breaks any Observer logic that reads a
## column NOT in that select list — the attribute comes back `null`, not "not loaded" (Spec 035)
`Product::where(...)->select(['id', 'name'])->chunkById(...)` is a common pattern here to keep AI-prompt
payloads small. It's safe for columns the loop only WRITES (`$product->category_id = $x; $product->
save();` works fine even though `category_id` was never selected — see the `wasChanged()` note below).
It is NOT safe for any column an Observer subsequently READS on that same instance:
`AuditLandingPageFreshnessJob::dispatchForProduct($product)` reads `$product->tenant_id` directly, and an
unselected column silently evaluates to `null` rather than throwing or lazy-loading. Worse, this specific
failure is easy to miss because it fails QUIETLY: `LandingPage::where('tenant_id', $product->tenant_id)`
with a null value gets auto-converted by Laravel's query builder to `whereNull('tenant_id', ...)` (see
`Illuminate\Database\Query\Builder::where()` — value === null on operator '=' short-circuits to
`whereNull`), which combined with `LandingPage`'s own `BelongsToTenant` global scope (`tenant_id = '<the
real tenant>'`) produces an always-false `AND`, so the query returns zero rows and dispatches nothing —
no error, no exception, just silence. **Rule:** before trusting a `->select([...])` list on a query whose
resulting instances get passed through `save()`/`delete()` (and therefore through any registered
Observer), check what columns that Observer's methods actually READ (not just write) and include them.
**Related nuance confirmed by reading Eloquent internals, not assumed:** `wasChanged('category_id')`
still correctly returns `true` after `$product->category_id = $x; $product->save();` even when
`category_id` was never selected — `Model::getDirty()`'s `originalIsEquivalent()` treats an attribute
key that doesn't exist at all in `$this->original` as unconditionally dirty (`array_key_exists` check
first), so the "did this change" signal survives a narrow select; only DIRECT reads of an unselected
column's value (like `dispatchForProduct()` reading `tenant_id`) are the actual risk.

## Pattern: verify `ShouldBeUnique` actually gates BEFORE assuming a "no code change needed" call
When a spec's queue-flood-prevention requirement turns out to already be satisfied by an existing
`ShouldBeUnique` job (found via `git log -- <job>` — it shipped in an earlier spec, e.g. Spec 030's
`AuditLandingPageFreshnessJob`), don't just cite the interface and move on — confirm the dedup actually
holds under THIS project's specific test/deploy config, because `ShouldBeUnique` silently no-ops if its
cache store lacks atomic-lock support. Checked here: `CACHE_STORE=array` (phpunit.xml) →
`Illuminate\Cache\ArrayStore implements LockProvider` ✓; production `CACHE_STORE=database` (.env) →
`Illuminate\Cache\DatabaseStore implements LockProvider` ✓. Also confirmed the gating point is
`Illuminate\Foundation\Bus\PendingDispatch::shouldDispatch()`, called from `__destruct()` BEFORE the job
ever reaches the real queue connection or `Queue::fake()`'s `push()` — so `Queue::assertPushed($job, N)`
count assertions in tests reflect real dedup behaviour, not an artifact of faking. Worth this level of
verification because "the interface is present" and "the dedup actually fires in this test/prod config"
are different claims, and the whole point of a queue-flood test is trusting the count assertion.

## Gotcha: `QUEUE_CONNECTION=sync` (phpunit.xml default) CANNOT reproduce an "exhausted retries" bug —
## `Illuminate\Queue\Jobs\SyncJob::attempts()` is hardcoded to return 1 (Spec 036, H-C)
Any job logic that branches on `$this->attempts()` (e.g. the pre-fix `RescanProductFeatures` guard
`if ($this->attempts() < $this->tries) throw $e;`) behaves identically on every dispatch under the sync
driver, because `SyncJob::attempts()` never reads a real attempt counter — it's a constant `1`. A job with
`$tries = 3` therefore always sees `1 < 3` and always rethrows under sync, which is exactly backwards from
the bug (final attempt swallowing the exception) this needed to catch. **To actually test "N attempts,
final one lands in `failed_jobs`":** switch `config(['queue.default' => 'database'])` for the test, dispatch
for real, then drive it to exhaustion with N iterations of `Artisan::call('queue:work', ['connection' =>
'database', '--once' => true, '--queue' => 'default', '--sleep' => 0])` — resetting
`DB::table('jobs')->update(['available_at' => now()->getTimestamp()])` before each iteration to skip past
the job's own `$backoff` delay (otherwise the next `pop()` finds nothing available and silently no-ops).
`Illuminate\Queue\DatabaseQueue::markJobAsReserved()` increments `attempts` on every real pop, so this is
the only way `$job->attempts()` (and therefore `$this->attempts()` inside `handle()`) reflects a genuine
Nth-of-M state. `connection` is a positional ARGUMENT on `queue:work`, not a `--connection` option — passing
`'--connection' => 'database'` throws `InvalidOptionException`. Verified the whole technique by writing the
test BEFORE the fix and confirming it reproduced the exact silent-swallow symptom (`jobs` count 0, AND
`failed_jobs` count 0 — the job just vanishes, "succeeded" per Laravel), then re-ran unchanged after
applying the one-line fix and watched it go to `jobs=0, failed_jobs=1`.

## Gotcha: `$this->assertDatabaseCount($table, $count, $message)` — the 3rd positional arg is a
## CONNECTION NAME, not a PHPUnit failure message. Passing a string message there throws
## `InvalidArgumentException: Database connection [<your message>] not configured.`
`Illuminate\Foundation\Testing\Concerns\InteractsWithDatabase::assertDatabaseCount(string $table, int
$count, $connection = null)` — there is no message parameter. Put explanatory text in a `//` comment above
the assertion instead (matches this codebase's existing convention of bare `$this->assertSame($expected,
$actual, 'message')` — note `assertSame`/`assertTrue`/etc. DO take a message as their last arg; it's
specifically the `assertDatabase*` Laravel testing helpers that don't).

## Pattern: driving a real Filament `BulkAction`/`Action` closure (not reimplementing its body) when the
## page's own base table query hits F12 (unsupported `REGEXP` on sqlite) — `ProblemProducts` specifically
`ProblemProducts::problemQuery()` (the page's OWN `->query(...)`, not just a filter) unconditionally
includes a `whereRaw('LOWER(name) REGEXP ?', ...)` clause, so `Livewire::test(ProblemProducts::class)` fails
immediately on mount with `SQLSTATE[HY000]: ... no such function: REGEXP` under the sqlite test driver —
confirmed by writing a scratch test and running it before assuming impracticality. Three existing Filament
tests (`ProductResourceTest`, `CategoryResourceHealthTest`, `LandingPageResourceTest`) route around F12 by
testing a DIFFERENT, unaffected Livewire component instead (e.g. `ListProducts` rather than a full-page HTTP
request that renders `ProblemProducts::getNavigationBadge()` in the nav). That option doesn't exist when
`ProblemProducts` itself is the page under test (Spec 036's bulk-ignore test). **Working fix, test-file-only,
touches zero app code:** register a REGEXP callback on the sqlite PDO connection in `setUp()` —
`DB::connection()->getPdo()->sqliteCreateFunction('REGEXP', fn ($p, $s) => preg_match('/'.$p.'/i', (string)
$s) === 1 ? 1 : 0, 2);`. Production runs MySQL, which supports `REGEXP` natively, so this changes nothing
about app behavior — it only fills a sqlite capability gap so `Livewire::test()->callTableBulkAction(...)`/
`->callTableAction(...)` can drive the REAL closures defined in `ProblemProducts::table()`. Confirmed this
actually exercises production code (not a reimplementation) by reverting the app-code fix and rerunning:
the test failed with the exact pre-fix symptom (`AuditLandingPageFreshnessJob` never pushed). Also: Filament
notification assertions use `Notification::assertNotified('exact title')`, exposed on the `Testable` mixin
as `$component->assertNotified(...)` via `Filament\Notifications\Testing\TestsNotifications` — no bespoke
event-dispatch inspection needed. `landing_pages` is unique on `(tenant_id, category_id)`, so multiple test
landing pages referencing different products each need their OWN `Category`, not a shared one.

## Gotcha: `config("services.gemini.pricing.{$model}")` silently mis-parses when `$model` itself
## contains a literal `.` — Gemini model names do (`gemini-2.5-flash`, `gemini-3.1-flash-lite`)
Laravel's `config()` dot-notation helper splits the WHOLE string on `.`, with no way to escape a dot
inside a segment. `config("services.gemini.pricing.gemini-2.5-flash")` is parsed as the nested path
`['services','gemini','pricing','gemini-2','5-flash']`, not `['services','gemini','pricing']['gemini-2.5-flash']`
— it silently resolves to `null` instead of throwing, so this bug produces "unknown model, cost is null"
for EVERY real Gemini model, and a naive test written against the same buggy helper won't catch it (I
initially wrote the test with `assertNull`-shaped expectations by accident and had to double back once
I computed the expected non-null value by hand). Fix: fetch the whole config array once —
`config('services.gemini.pricing')[$model] ?? null` — and index into it with the literal PHP array key.
Any future `config()` lookup keyed by a Gemini/OpenAI/Anthropic model string needs the same treatment.

## Gotcha: adding a new parameter to a widely-subclassed service method is a breaking change even
## though PHP has no interface here — `GeminiService::generate()` had 5 hand-written anonymous
## `extends GeminiService { public function generate(...) }` overrides across 2 test files
PHP enforces child-method signature compatibility for CONCRETE class inheritance too, not just
interfaces/abstracts — confirmed empirically: a child override with fewer parameters than a parent that
has trailing optional params is a **fatal `Declaration ... must be compatible`** error at class-load
time, not a warning, not something caught only when the extra param is actually used. Before adding
`string $purpose = '...'` as a 4th param to `GeminiService::generate()` (spec 037 T1), grepped
`extends GeminiService` across `app/` AND `tests/` (`app(GeminiService::class)`/PHPUnit `createMock()`
call sites are safe — those build proxies against the CURRENT signature at mock-generation time; only
hand-written subclasses are at risk) and updated all 5 override signatures to match. Default parameter
VALUES don't need to match between parent/child, only the parameter's presence/type — confirmed with a
throwaway `php -r` repro before touching real files, cheaper than trial-and-error editing.
Also confirmed `new GeminiService()` (17+ call sites, zero-arg) stays valid when adding a new
constructor with a defaulted, container-resolvable dependency: `public function __construct(private
readonly AiUsageService $usage = new AiUsageService()) {}` — PHP 8.1 "new in initializers" plus Laravel's
container preferring to `make()` a typed dependency over falling back to the literal default expression
either way produces a working instance, so this is a fully backward-compatible way to add DI to a class
with many pre-existing no-arg instantiations in tests.

## Precedent found and reused: when a new tenant-scoped table is really a cross-tenant accounting/
## observability log, do NOT add `BelongsToTenant` — `SeoMetric` already establishes this exact pattern
`app/Models/SeoMetric.php` has a docblock explaining it intentionally skips `BelongsToTenant` because
"the status command and dashboard widgets query across all tenants from the central (console) context;
automatic tenant scoping would break those cross-tenant reads." Reused this reasoning verbatim for the
new `AiUsage` model (spec 037 T1): its dominant writer, `evaluateProduct()` via `ProcessPendingProduct`,
runs on a queued worker where `tenancy()->initialized` is always false (no `QueueTenancyBootstrapper` is
configured in `config/tenancy.php`) even though the `Product` being scored has a real `tenant_id` — so
`BelongsToTenant`'s auto-populate-on-create hook (`bootBelongsToTenant`) would have silently written
`tenant_id = null` for the platform's single dominant AI cost, which is exactly backwards for a table
whose entire purpose is per-tenant cost attribution. Resolved `tenant_id` explicitly via the `tenant('id')`
helper (safe to call unconditionally — returns `null`, doesn't throw, when tenancy isn't initialized;
confirmed by reading `vendor/stancl/tenancy/src/helpers.php` and `TenancyServiceProvider::register()`)
inside the service that writes the row, matching the existing "explicit tenant_id assignment" convention
already used for API controllers and queued jobs elsewhere (project_context.md §11: "safety net for
non-tenancy middleware routes"). Before assuming a job runs centrally, verified it by reading
`ProcessPendingProduct::handle()` itself — it uses `Brand::withoutGlobalScopes()->where('tenant_id', ...)`
rather than relying on the tenant global scope, which is itself evidence the codebase already knows
queued jobs don't have live tenancy context.

## Spec 038 B1 fix confirms the exact bug the SeoMetric-precedent reasoning above predicted: `tenant('id')`
## and an initialized-tenancy-only trait are IDENTICAL on a queue worker — both resolve to null
The `AiUsage` docblock's stated justification for skipping `BelongsToTenant` was itself half-wrong in a
subtle way: `tenant('id')` inside `AiUsageService::record()` is "safe" (doesn't throw) but is NULL under
the exact same condition (`tenancy()->initialized === false`) that would have made `BelongsToTenant`'s
auto-populate hook a no-op too — so dropping the trait bought nothing on the write side for job-originated
calls. The actual fix has nothing to do with the model/trait layer: it's threading the ALREADY-KNOWN
tenant id (`$product->tenant_id`, read straight off the Eloquent model instance that's already loaded)
as an explicit parameter through the whole call chain — `ProcessPendingProduct`/`RescanProductFeatures`
→ `AiService::evaluateProduct()`/`rescanFeatures()`/`matchProduct()` → `GeminiService::generate($tenantId)`
→ `AiUsageService::record($tenantId)` (which still falls back to `tenant('id')` for request-context
callers where that value IS live). General lesson: when a docblock says "X is used instead of Y because Y
is unsafe here," verify X actually behaves differently from Y in the failure case that motivated the
comment — `tenant('id')` and an initialized-tenancy-gated auto-populate hook can both silently return/set
null under the identical precondition, so swapping one null-producer for another isn't a fix.

## Pattern reinforced: EVERY console command that calls into `AiService` initializes tenancy first —
## so "no active tenant" test cases need a purpose that ISN'T one of the 13 real purpose strings
Checked all 6 AI-calling console commands (`AiSweepCategory`, `AiAssignCategories`,
`GenerateCompareContent`, `GenerateLandingPage`, `GeneratePresetContent`, `MergeDuplicateProducts`) plus
the single Filament panel (`AdminPanelProvider::boot()` bridges Filament's OWN tenant-switcher into
`tenancy()->initialize()` on the `TenantSet` event) — every real, named `purpose` string in the codebase
DOES have a tenant in production except the 3 job-originated ones (`evaluate_product`, `rescan_features`,
job-triggered `match_product`), which Spec 038 B1 just fixed. So a test asserting "no tenant → null
tenant_id" must NOT reuse any of the 13 real purpose values (audit A3 caught exactly this: the original
test used `'sweep_category'`, which DOES have a tenant via the console command) — use `'unspecified'`,
`GeminiService::generate()`'s own default parameter value, which structurally never maps to a real
production call site. Before picking a "no tenant" test fixture purpose string, grep every AI-calling
console command AND Filament panel provider for `tenancy()->initialize`, don't assume from the purpose
name alone whether it's tenant-bound.

## Gotcha (recurrence of the pattern two entries up): adding an Nth optional trailing parameter to
## `AiService::rescanFeatures()` (spec 038 B1, `?string $tenantId = null`) broke 3 MORE hand-written
## anonymous-subclass overrides that weren't caught by the original `extends GeminiService` grep
The earlier lesson only covered `extends GeminiService`; `rescanFeatures()` lives on `AiService`, a
DIFFERENT class, so it needed its own grep (`grep -rn "function rescanFeatures" tests/ app/`) — found 3
hand-written `extends AiService { public function rescanFeatures(...) }` overrides across
`tests/Feature/Jobs/RescanProductFeaturesTest.php` (×2) and
`tests/Feature/Commands/AiAssignCategoriesCommandTest.php` (×1), confirmed empirically with a real
`php artisan test` run BEFORE fixing them (`PHP Fatal error: Declaration ... must be compatible`), fixed
by adding the same trailing `?string $tenantId = null` to each override, then reran to confirm green.
General rule going forward: any time a method signature gains a new parameter (even trailing + optional),
grep `function {methodName}` across the ENTIRE `tests/` and `app/` tree for that exact method name — not
just `extends {ClassName}` — since a subclass can override without literally spelling "extends" on the
same line as the method (anonymous classes, especially), and different methods on the same widely-mocked
service need independent greps.
