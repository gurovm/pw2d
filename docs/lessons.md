# Lessons Learned

Living document of quirks, gotchas, and mistakes worth not repeating. Add new entries at the top with the date.

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
