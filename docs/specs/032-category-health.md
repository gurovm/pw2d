# Spec 032 — Category health & freshness

**Status:** DRAFT (2026-08-20) · **Depends on:** 029 (listing health), 030 (freshness vocabulary), 031 (cadence)

## Why

Spec 031 defines a three-tier maintenance cadence but gives no way to *see* where each category
stands. Today the answer requires an SSH session and hand-written SQL against production. On
2026-08-20 the owner asked a one-sentence question — which categories are topped up, which is next —
and answering it took a prod round trip and three exchanges, because no surface in the product
reports pool, buyable, or last-checked state.

The second half of the failure was worse. The obvious proxy — the homepage's "Top N Picks" —
reads `withCount('products')` on an unfiltered `hasMany` ([`Home.php:30`](../../app/Livewire/Home.php)),
so it counts ignored and unprocessed rows. It reports **280** for podcast-studio-mics where 181 are
in the pool and 153 are buyable. `CategoryResource` ([line 143](../../app/Filament/Resources/CategoryResource.php))
has the identical bug, so the admin panel offers no better answer than the public site.

Every number needed is already derivable. `health_checked_at IS NULL` *is* the record of "imported
but never rescanned" — a computed tracker cannot fall out of date the way a hand-maintained one does.

### Non-goal

This spec does **not** automate any maintenance action. It reports; the owner decides. Same division
as Spec 030: detection is automatic, regeneration stays human-gated.

## The metrics

Per category, per tenant:

| Field | Definition |
|---|---|
| `total_rows` | every `products` row — what the UI shows today |
| `pool` | `is_ignored = 0 AND status IS NULL` — the AI-Bouncer-approved set |
| `buyable` | `pool` ∧ has ≥1 offer passing `ListingHealth::applyPurchasableOfferQuery()` |
| `unbuyable_pct` | `(pool - buyable) / pool` |
| `never_checked` | `pool` products with **zero** health-stamped offers — the import debt |
| `oldest_check` | `MIN(product_offers.health_checked_at)` across the pool |
| `newest_check` | `MAX(...)` — when the category was last touched at all |

`buyable` must reuse `applyPurchasableOfferQuery()` verbatim. The 2026-08-16 audit's root cause was
four copies of "is this offer purchasable" with three different definitions; this spec must not add
a fifth.

## The verdict

Reported as a **list**, matching Spec 030's `stale_reasons` vocabulary — several can apply at once.

| Reason | Trigger | Meaning |
|---|---|---|
| `import_debt` | `never_checked > 0` | Products imported, rescan not yet run. **Picks must not be selected** until cleared (Spec 031 Tier-3 rule). |
| `stale` | `oldest_check` > 30 days | Tier-2 monthly sweep overdue. |
| `aging` | `oldest_check` > 21 days | Sweep due within the week. |
| `thin` | `buyable < 45` | Tier-3 discovery threshold (Spec 031 §"Pool headroom"). |
| `churn` | `unbuyable_pct >= 20%` | Losing stock faster than pool size suggests — closes the open todo item asking that discovery trigger on unbuyable *share*, not just absolute count. |
| `no_data` | `pool = 0` | Empty or newly created category. |

Empty list → `HEALTHY`.

`import_debt` is the dangerous one and must sort first: it is the only reason that makes
`SelectLandingPagePicks` actively unsafe to run.

### Thresholds validated against production (2026-08-20)

Run against live data, the rules above fire on exactly the categories already known to need
attention — and on nothing else:

| Reason | Categories it flags today |
|---|---|
| `thin` (buyable < 45) | gooseneck-kettles 34, cold-brew-makers 36, super-automatic 41 |
| `churn` (≥20% unbuyable) | gaming-chat-headsets (79 pool → 59 buyable, **25%**) |
| `import_debt` | semi-auto 1, gaming-keyboards 1, mics 1 — independently rediscovering the three "unstamped stragglers" logged in `docs/tasks/todo.md` |
| `stale` / `aging` | **none** — every category was health-checked 2026-08-14→16, so the whole platform sits 4–6 days old against a 30-day threshold |

That last row is the useful negative result. On 2026-08-20 the rotation queue's *top* entry
(mechanical-gaming-keyboards, oldest check 6 days) was misread in conversation as overdue. Ordering a
queue by oldest-first says nothing about whether anything in it is due; the verdict column is what
separates "next" from "now", and its absence is precisely what caused the misread.

## Build

### 1. `App\Actions\AssessCategoryHealth`

Two entry points, because the two consumers need different things:

```php
/** For the CLI and any read-all caller. */
public function execute(Tenant $tenant): Collection;   // Collection<CategoryHealthRow>

/** For Filament — decorates a query it can still paginate and sort natively. */
public static function decorate(Builder $categories): Builder;
```

`decorate()` is the important half. Filament needs a **query**, not a collection, or sorting and
pagination stop working. It adds:

- three `withCount` closures → `pool_count`, `buyable_count`, `never_checked_count`
- two `addSelect` correlated subqueries → `oldest_check`, `newest_check`

`execute()` calls `decorate()` and maps rows through the DTO, so there is one definition of the SQL.

### 2. `App\Support\CategoryHealthRow`

`final readonly` DTO. Carries the raw metrics plus computed `reasons(): array` and
`isHealthy(): bool`. Thresholds live here as constants (`STALE_DAYS = 30`, `AGING_DAYS = 21`,
`THIN_BUYABLE = 45`, `CHURN_PCT = 0.20`) so the CLI and Filament cannot drift apart.

Precedent for the DTO shape: `app/Actions/Seo/PullSeoMetricsResult.php`.

### 3. `pw2d:categories:health` command

```
pw2d:categories:health {tenant?} {--due : only categories needing attention} {--json}
```

Mirrors `AuditLandingPagesCommand` — no tenant argument runs every tenant, `tenancy()->initialize()`
in a `try/finally`. Table sorted by **`oldest_check` ASC**, so row one is literally the next category
to sweep (Spec 031 drives Tier-2 rotation by oldest health check, not a fixed calendar).

**Exit code:** `FAILURE` iff a category backing a **published** landing page carries `import_debt`
or `stale`. Makes it cron-safe alongside the nightly `pw2d:landing-pages:audit`. A draft page's
category never fails the run.

`--json` so the extension or a future dashboard can consume it without scraping table output.

### 4. Filament `CategoryResource` columns

| Column | Notes |
|---|---|
| `Health` | badge list of reasons; `import_debt` red, `stale` red, `aging`/`thin`/`churn` amber, else green `HEALTHY` |
| `Buyable` | `buyable_count`, sortable — the number that actually matters |
| `Unchecked` | `never_checked_count`, amber badge when > 0, links to the filtered product list |
| `Oldest check` | date, red when > 30d |
| `Pool` | `pool_count`, toggleable, hidden by default |
| `Rows` | the existing `products_count`, **relabelled from "Products"**, toggleable, hidden by default — keeps the existing drill-down link but stops presenting a raw row count as inventory |

Default sort: `oldest_check` ASC, matching the CLI.

## Performance

The owner's question was whether this is too heavy for the admin list. It is not, but the reasoning
matters more than the verdict.

**Scale:** 11 categories, ~940 pool products, ~1,034 offers. `decorate()` is **one query** — the
`withCount` closures and `addSelect` subqueries are correlated subqueries inside the existing
Filament list query, not extra round trips. Row count does not change the query count.

**The real cost** is the `whereHas('offers', ...)` EXISTS inside `buyable_count`, which evaluates
per product per category. At ~940 products against an indexed `product_offers.product_id` FK this is
trivial. It would stop being trivial in the tens of thousands.

**No cache initially.** Measure first. If the list ever drags, wrap `execute()` in
`Cache::remember(tenant_cache_key('category_health'), 900, ...)` — but a cached admin health panel
that lags 15 minutes behind a rescan the owner just ran is a worse tool, so treat caching as a
concession, not a default.

**Optional index**, only if measurement justifies it:
`product_offers (product_id, health_checked_at)` — serves both the `never_checked` EXISTS and the
`MIN/MAX` subqueries. MySQL-only raw migration, verified separately from the sqlite suite (same
constraint as the deferred Perf M1 item).

## Deferred to a follow-up (owner: "fix homepage numbers later")

The unfiltered `products_count` appears in four places. This spec fixes only the `CategoryResource`
one, as a side effect of relabelling. The rest are a separate, deliberately deferred change:

- [`Home.php:30`](../../app/Livewire/Home.php) — "Top N Picks" on the public homepage. Needs the
  pool + purchasable filter and a bump of the `home:popular_categories` cache key.
- [`BrandResource.php:62`](../../app/Filament/Resources/BrandResource.php) — same pattern, admin-only, low impact.
- [`ProductCompare.php:99`](../../app/Livewire/ProductCompare.php) — brand-filter counts on the
  compare page. Counts ignored products, so a brand can show a count higher than the grid renders.

## Tests

- `AssessCategoryHealth`: each reason in isolation; several at once; `pool = 0` → `no_data`;
  ignored and `status != null` rows excluded from `pool`; an unbuyable-only-offer product counted in
  `pool` but not `buyable`; a product whose offers are all `health_checked_at = NULL` counted in
  `never_checked`; tenant isolation.
- `decorate()`: the query stays sortable and paginatable; aliases resolve.
- Command: table output, `--due` filtering, `--json` shape, unknown tenant → failure, exit code
  `FAILURE` only for published-page categories, `SUCCESS` when a *draft* page's category is stale.
- Regression: `buyable_count` agrees with `ProductCompare::scoredProducts()->count()` for the same
  category — the two must never drift, since both claim to answer "what can a reader buy here".

That last one is the test that matters. It is the assertion this whole spec exists to protect.
