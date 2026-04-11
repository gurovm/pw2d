# Lessons Learned

Living document of quirks, gotchas, and mistakes worth not repeating. Add new entries at the top with the date.

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
