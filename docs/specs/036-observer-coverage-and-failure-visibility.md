# Spec 036 — Observer coverage on `is_ignored`, and failure visibility

**Status:** DRAFT (2026-08-21) · **Closes:** audit-2026-08-21 H-B and H-C · **Depends on:** 030, 035

## Why

The 2026-08-21 audit found Spec 035 shipped half a fix and left a documented invariant that isn't true.

### H-B — `ProductObserver` has two triggers; Spec 035 fixed only one

`ProductObserver::saved()` fires on `category_id` changed **or** `is_ignored` flipped to true. Spec 035
converted both `category_id` mass-updates to model-level saves and left every `is_ignored` one:

```php
AiAssignCategories.php:126   Product::where('id', $id)->update(['is_ignored' => true]);   // 11 lines below its own fix
FlagConditionProducts.php:123 Product::whereIn('id', $ids)->update(['is_ignored' => true]);
ProblemProducts.php:347       Product::whereIn('id', ...)->update(['is_ignored' => true]);
```

`Builder::update()` fires no model events, so none of these dispatch `AuditLandingPageFreshnessJob`. A
live pick hidden by any of them keeps rendering on its published guide until the nightly audit — the exact
"instant freshness path is dead for automated routes" defect Spec 035 exists to close.

`ProblemProducts` contains **both** patterns: `:325` (single record) fires the observer, `:347` (bulk)
does not. Same page, same button semantics, opposite behaviour, and `:347` is the triage path the owner
uses routinely.

**The reason this ranks above the security mediums** (per the security agent's independent sequencing):
`ProductObserver`'s docblock now *asserts* it covers ignore-flips and that Filament re-homes fire it.
Neither holds on the `is_ignored` path. An open gap is safer than a documented invariant that doesn't —
converting two of five sites and recording it as done is how the 2026-08-21 incident recurs.

### H-C — a failed rescan can leave a product with zero feature values

```php
// RescanProductFeatures.php:115-117
if ($this->attempts() < $this->tries) {
    throw $e; // trigger queue retry with backoff
}
```

On the **final** attempt the exception is swallowed, so Laravel marks the job **successful**. It never
reaches `failed_jobs`, so `queue:retry all` can never recover it.

Pre-035 that only meant stale scores. Post-035 `ProductObserver` deletes the old category's feature values
**first**, and the audit confirmed there is no transaction on the command path (`AiAssignCategories` opens
none, `Model::save()` opens none, `config/queue.php` sets `after_commit => false`) — so the delete is
durable before the job ever runs. An exhausted rescan leaves the product with **no** feature values,
silently. It still passes `SelectLandingPagePicks` eligibility, which never checks feature values, so it
can be published as a pick with an empty score profile.

Note the inversion: **Filament is the safe path** — `EditRecord::save()` wraps in a transaction and the
`database` queue driver shares the connection, so a rollback discards update, delete and job together. The
automated command is the unsafe one.

## Build

### 1. Always rethrow (H-C)

Delete the `if ($this->attempts() < $this->tries)` conditional in `RescanProductFeatures::handle()`'s catch
block; always `throw $e;`. Laravel's `$tries`/`$backoff` already gate retries — the guard is redundant
**and** it suppresses the failure record. Keep the `Log::error`.

**Do NOT** wrap the observer body in `DB::transaction()`. That changes semantics for every caller including
the sweep and Filament's already-transactional path, to defend a failure mode requiring a database fault.

### 2. Model-level saves at the three `is_ignored` sites (H-B)

Order by owner impact — `ProblemProducts.php:347` first:

```php
// ProblemProducts.php:347 — inside the BulkAction closure, $records is a Collection of Products
$records->each(fn (Product $p) => $p->update(['is_ignored' => true]));

// FlagConditionProducts.php:123
Product::whereIn('id', $ids)->cursor()->each(fn (Product $p) => $p->update(['is_ignored' => true]));

// AiAssignCategories.php:126 — already inside a per-item foreach
$product->is_ignored = true;
$product->save();
```

Fan-out is already collapsed by `AuditLandingPageFreshnessJob`'s `ShouldBeUnique` / `uniqueFor = 600`, so
this cannot reintroduce S9. `RescanProductFeatures` is **not** dispatched by the `is_ignored` branch — only
by `category_id` — so there is no new Gemini cost.

### 3. Close the todo item that instructs reintroducing this

`docs/tasks/todo.md` **Q11** reads: *"Use bulk update for Mark as Ignored — Loops individual
`$record->update()`. Use `whereIn()->update()`."* That advice is what produced `ProblemProducts.php:347`,
and applying it to `ProductResource.php:262-266` would break that site too. Mark it **WONTFIX** with the
reason: per-record saves are load-bearing here because `ProductObserver` is the freshness trigger.

## Tests

- **The invariant the docblock claims, actually pinned:** bulk-ignoring N products via each of the three
  sites dispatches `AuditLandingPageFreshnessJob` for pages referencing them. This is the regression that
  does not exist today, which is why the gap survived Spec 035.
- Single-record ignore (`ProblemProducts.php:325`) still behaves identically — guards against "fixing" it
  into a bulk update later.
- `RescanProductFeatures` throwing on all 3 attempts lands in `failed_jobs` (assert the failure is
  *recorded*, not merely that it throws).
- A product whose rescan exhausts its tries does not silently end with zero feature values *and* no
  failure record — the two-part condition is the actual bug.

## Not in scope

H-A (`modelKey()` false-merge), H-D (unstampable `unknown` offers), H-E (`ProcessPendingProduct` feature
wipe), H-F (queue starvation). Each needs its own decision; H-A needs a spec.
