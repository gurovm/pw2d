# Lessons Learned

Living document of quirks, gotchas, and mistakes worth not repeating. Add new entries at the top with the date.

---

## 2026-08-10 — Raw SQL against a JSON column must be reasoned about on MySQL separately; sqlite doesn't reproduce MySQL's JSON normalization

**Symptom:** `AuditLandingPageFreshnessJob::dispatchForProduct()` used `whereRaw('picks LIKE ?', ['%"product_id":' . $id . ',%'])` against `landing_pages.picks`, a native MySQL `json` column. Every test passed on sqlite. In production, the instant freshness-audit path (observer ignore-flip/detach/delete, `high_price` flag) would have dispatched **zero jobs** — only the nightly command would ever have caught staleness.

**Root cause:** MySQL stores JSON in an internal binary format and re-serializes it to a **normalized** string (spaces after every `:`/`,`) when coercing a `json` column to text for `LIKE`. The space-free pattern (`Laravel's raw json_encode` output) never matches. sqlite's `json()` column type is backed by plain `TEXT`, storing Laravel's exact space-free `json_encode()` output — so the space-free `LIKE` pattern matches perfectly in every test run, hiding the bug completely.

**Fix (Spec 029/030 audit fixes, 2026-08-10):** dropped the raw SQL containment entirely — load the tenant's own landing pages (bounded, a dozen-ish rows) and filter with a PHP `Collection::filter()`/`contains()` closure instead. Identical behavior on every connection, and now directly unit-testable (assert against a picks array) instead of requiring a live MySQL smoke test.

**Rule going forward:** any raw SQL fragment that touches a `json`-cast column (`LIKE`, string comparison, etc.) must be reasoned about — or smoke-tested — against MySQL specifically. A passing sqlite suite proves nothing about that fragment's behavior on the production database engine. Prefer filtering in PHP over a bounded result set, or a verified `whereJsonContains()`, over any raw string-matching against a JSON column.

---

## 2026-08-10 — `foreach ($arr['key'] ?? [] as &$item)` silently iterates a temporary copy, not the real array

**Symptom:** `EditLandingPage::mutateFormDataBeforeSave()` tried to restore stripped-by-dehydration keys (`product_id`, `role`, `est_price_snapshot`) into `$data['picks']` via:
```php
foreach ($data['picks'] ?? [] as $i => &$pick) {
    $pick['product_id'] = $stored[$i]['product_id'] ?? null;
    // ...
}
```
This ran without error, and a debug `Log::info()` right before the loop confirmed `$data['picks']` and `$stored` both had the expected shape — but the SAVED record's `picks` column came back with the restored keys **still missing**, as if the loop body never executed (it did — the mutation just never reached `$data`).

**Root cause:** `$data['picks'] ?? []` is an *expression*, not a plain variable/array-element lvalue. When the left operand of `??` is set (the common case here), PHP still evaluates the whole `??` expression to produce its result — and a by-reference `foreach` over an expression's result binds `&$pick` to elements of a **temporary array**, not to `$data['picks']` itself. Every assignment through `$pick` silently vanished with the temporary.

**Fix:** never combine `??`/ternaries with a by-reference `foreach`. Guard explicitly and iterate the real lvalue:
```php
if (isset($data['picks']) && is_array($data['picks'])) {
    foreach ($data['picks'] as $i => &$pick) {
        $pick['product_id'] = $stored[$i]['product_id'] ?? null;
    }
    unset($pick);
}
```
**Impact if ignored:** the bug is a pure no-op with zero errors/warnings — the exact shape of bug that only surfaces as a downstream data-integrity assertion failure (or, in prod, a silent feature that never actually did anything). Reviewed code that does `foreach ($x ?? [] as &$y)` anywhere in the codebase should be treated as suspect.

---

## 2026-06-19 — No bare `const`/`let` in Alpine `x-init`; republish Livewire assets after composer bumps

**Symptom:** A new compare-page Customize drawer (Spec 025) never auto-opened. Console showed two errors:
`Livewire: The published Livewire assets are out of date` and
`Alpine Expression Error: Unexpected token 'const'` pointing at an `x-init` that began with
`const AUTO_OPEN_KEY = '...'`. The syntax error **aborted the entire `x-init`**, so nothing inside ran
(the auto-open dispatch never fired — even in a fresh incognito session, which ruled out the
sessionStorage guard).

**Two compounding root causes:**
1. **Stale published Livewire assets.** Prod had `public/vendor/livewire/livewire.js` published once long ago
   and never refreshed; a later `composer install` bumped Livewire (to v3.7.15) but not the published JS.
   The stale `livewire.js` bundles an **older Alpine** that evaluates `x-init` by wrapping it as
   `return (<expr>)`. `return (const ... )` is a syntax error.
2. **A lexical declaration at the top of an `x-init` expression.** Even on newer Alpine this is fragile;
   `x-init` is an *expression* slot, not a statement block.

**Fixes:**
- **Never put a bare `const`/`let`/statement at the top of `x-init`.** Put the logic in an `x-data`
  *method* and call it: `x-data="{ ..., initAutoOpen() { let key = ...; ... } }" x-init="initAutoOpen()"`.
  Function bodies allow `let`/statements on *any* Alpine version — version-proof. (Inside the method use
  `this.someState` to mutate x-data.)
- **Always republish Livewire assets on deploy:** `php artisan vendor:publish --tag=livewire:assets --force`.
  Added as a step to `.claude/commands/deploy.md` (after `composer install`). Idempotent, cheap; prevents
  the stale-asset class of Alpine breakage (which can silently break *any* Alpine directive, not just this one).

**Coverage gap that let it ship:** PHPUnit / `Livewire::test()` / `$this->get()` render HTML but **never
execute Alpine JS**, so this class of runtime bug is invisible to the test suite — exactly how it reached
prod with a green suite. When a change touches Alpine `x-init`/`x-data` logic, **manually load the page and
watch the browser console**; the automated suite cannot vouch for JS behaviour. (The project has no Dusk/
browser tests; if Alpine logic grows, that's the gap to close.)

**Impact if ignored:** a single malformed `x-init` (or a stale `livewire.js`) silently disables Alpine
behaviour site-wide with only a console error to show for it — no PHP error, no failing test, no 500.

---

## 2026-04-11 — Always re-fetch after `Tenant::create()` with a string PK

**Symptom:** `Tenant::create(['id' => 'acme', ...])` returns a `Tenant` object whose `$id` attribute is the sqlite rowid (`'1'`), not `'acme'`. The row in the DB is stored correctly — `Tenant::find('acme')` returns it — but the in-memory object from `create()` is corrupt. Downstream, `tenancy()->initialize($corruptTenant)` uses the wrong key, and every `BelongsToTenant` child insert fails with `FOREIGN KEY constraint failed (tenant_id = '1')`.

**Discovered during:** Spec 015 (SEO Phase 1) test suite, where 10+ tests created a test tenant and then tried to create Category/Product factories under it. All failed with FK violations on `tenant_id`.

**Root cause:** `Stancl\Tenancy\Database\Concerns\GeneratesIds::getIncrementing()` unconditionally overrides Eloquent's `$incrementing` property. Even when a subclass explicitly declares `public $incrementing = false`, the trait returns `!app()->bound(UniqueIdentifierGenerator::class)`. Since pw2d's `config/tenancy.php` has `'id_generator' => null`, the generator is never bound, so `getIncrementing()` returns `true`. Laravel's `performInsert()` then calls `lastInsertId()` and overwrites the model's primary key with the sqlite rowid. Full analysis at [docs/bug-reports/stancl-tenancy-pk-leak.md](bug-reports/stancl-tenancy-pk-leak.md).

**Workaround (use everywhere):**

```php
// ❌ Broken — $tenant->id is '1', not 'acme'
$tenant = Tenant::create(['id' => 'acme', 'name' => 'Acme']);
tenancy()->initialize($tenant);

// ✅ Correct — re-fetch after create
Tenant::create(['id' => 'acme', 'name' => 'Acme']);
$tenant = Tenant::find('acme');
tenancy()->initialize($tenant);
```

Already in use in `tests/Feature/SitemapCursorTest.php` and all `tests/Feature/Seo/*Test.php` setup methods. When adding a new tenant-aware test, copy from those.

**Follow-up:** Upstream bug report prepared at [docs/bug-reports/stancl-tenancy-pk-leak.md](bug-reports/stancl-tenancy-pk-leak.md). Michael to file at https://github.com/archtechx/tenancy/issues/new and update this entry with the issue URL once filed. F4 in `docs/tasks/todo.md` proposes a shared `InitializesTestTenant` trait to DRY up the workaround across test files.

**Impact if ignored:** Every new test that uses `Tenant::create()` directly and uses the returned object will fail with confusing FK errors. The error message never mentions the real cause — you see "tenant_id = '1' violates FK" and spend hours debugging a non-bug in your own code.

## 2026-08-22 — Relaying a sub-agent's reasoning without verifying it

The builder justified omitting `BelongsToTenant` from `AiUsage` with a claim that sounded better than
the spec's own instruction, and the architect relayed it to the owner as "better than mine" without
checking it. Three audit agents then independently found it was backwards: the trait stamps `tenant_id`
only `if (tenancy()->initialized)` — the same condition that makes `tenant('id')` return null — so on
the queue path both produce an identical NULL. The reasoning was confident, plausible, cited a real
precedent (`SeoMetric`), and was wrong on the one axis that mattered: that precedent's column is
NOT NULL and its writers take the tenant as an argument.

**Rule:** when a sub-agent's report contradicts or "improves on" the spec, verify the claim against the
actual source before relaying it. It took two greps. Verify the *mechanism*, not the plausibility.

**Second-order lesson, worth more:** the acceptance query in Spec 037 §2 T1 (`GROUP BY purpose`) could
not have detected the defect — no tenant column, so it aggregates over the NULL bucket and returns
plausible numbers. When writing an acceptance criterion, ask what failure it would *fail to see*.

## 2026-08-28 — A "leave it alone" rationale in a spec is a claim; verify it like any other

Spec 038 B3 told the builder to clear the stuck `pending_ai` status for brand-new products only, and gave
a reason: an existing product "may have a job in flight". The reviewer checked the mechanism: the job
overwrites `status` on every outcome (`ProcessPendingProduct.php:81/:153/:182/:236`), so clearing it can
never strand anything — and `ProductImportController:134` re-writes `pending_ai` on every re-import, so
the excluded path recreated the exact bug the migration was clearing. The builder, correctly following
the spec, then wrote a test that *pinned the bug as intended behaviour*.

**Rule:** when a spec scopes something *out* with a safety argument, that argument is a mechanism claim
and needs the same two-grep verification as anything a sub-agent reports (see 2026-08-22 entry). An
unverified exclusion is worse than an unverified inclusion — it ships with a test defending it.
