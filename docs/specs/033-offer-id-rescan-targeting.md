# Spec 033 — `offer_id` rescan targeting

**Status:** DRAFT (2026-08-20) · **Blocks:** the super-automatic-espresso Tier-3 top-up · **Depends on:** 029 (rescan v2), 031 (cadence)

## Why

`OfferIngestionService::processIncomingOffer()` re-resolves the offer being rescanned by
`(store_id, url)` and takes `->first()` with no ordering. When two products in different categories
share an ASIN — the same Amazon URL under the same store — the **lowest-id row always absorbs the
update** and its twin is never health-stamped. The twin cannot be reached by any rescan, from any
category, ever.

Found on `productivity-ergonomic-keyboards` (2026-08-14): 16 rows structurally unreachable, one of
them a live landing-page pick.

### Why this blocks super-auto specifically

`super-automatic-espresso-machines` and `semi-automatic-manual-espresso-machines` are the one
**confirmed** cross-category duplicate pair (product 3476 ↔ 3411). A Tier-3 import adds products to
a category that already overlaps its sibling, and a SERP import can mint fresh twins. Running the
top-up rescan before this fix means some fraction of the new rows silently update the wrong product
and report success — the rescan output would be untrustworthy exactly where we most need to trust it.

The work-list already carries what's needed: `rescanList()` returns `offer_id` on every row
([`OfferIngestionController`](../../app/Http/Controllers/Api/OfferIngestionController.php)), and
`popup.js` passes rows to `background.js` unmodified. The value is simply never echoed back.

## Build

### 1. Server — accept and prefer `offer_id`

**`OfferIngestionController::ingest()`** — one validation rule:

```php
'offer_id' => ['nullable', 'integer', Rule::exists('product_offers', 'id')->where('tenant_id', tenant('id'))],
```

**This rule is the security boundary, not a convenience.** `offer_id` is client-supplied under the
shared, non-rotating extension token (open security item H3). Without the `tenant_id` clause,
`offer_id` becomes a **new cross-tenant write primitive**, and a strictly worse one than the URL path
it replaces: URL matching at least required knowing a real URL, whereas an integer id is trivially
enumerable. The explicit `tenant_id` scope is required here even though `ProductOffer` carries
`BelongsToTenant` — the CLAUDE.md rule for API controllers running outside domain tenancy middleware.

**`OfferIngestionService::processIncomingOffer()`** — resolution precedence:

1. `offer_id` supplied → load that offer, explicitly scoped `where('tenant_id', tenant('id'))`.
2. Resolved offer's `store_id` **must** match the store resolved from `store_slug`. On mismatch:
   ignore the `offer_id`, fall back to URL, and `Log::warning`.
3. No `offer_id`, or it resolved to nothing → today's `(store_id, url)` lookup, unchanged.

**Never hard-fail on an unresolvable `offer_id`.** An offer legitimately deleted between
`rescan-list` and the POST must degrade to the URL path, not 422 and stall a ~100-offer walk.

### 2. Server — surface the duplicates instead of hiding them

Two changes to the URL-fallback branch, both cheap:

- Add `->orderBy('id')` to the existing lookup. It does not fix anything, but it converts
  accidental lowest-id-wins into *documented* behaviour.
- When the URL lookup matches **more than one** offer, `Log::warning` with the matched offer ids.

That second line is the durable win. Cross-category duplicates are currently invisible — they present
as a successful rescan. Logging them turns an unobservable bug class into a greppable signal, and
gives the still-open "duplicate rows also occur WITHIN a category" investigation real data.

### 3. Extension — echo it back

`background.js`, in the rescan refresh payload (~line 476):

```js
offer_id: offer.offer_id ?? null,
```

`offer.offer_id` is already present on every row (documented at `background.js:75`) and `popup.js`
filters rather than maps, so nothing else needs to change. Bump `manifest.json` **1.7 → 1.8**.

**Not touched:** `popup.js:263` and `popup.js:871` are new-product ingestion from a product page,
where no `offer_id` exists. They correctly keep the URL path.

**CLAUDE.md endpoint rule:** no endpoint URL changes in this spec, so the popup/content simultaneous-
update rule is not triggered. Stated explicitly so the check is consciously cleared, not skipped.

### Deploy ordering — none required

Both directions degrade safely, so server and extension can ship independently:

| | Old server | New server |
|---|---|---|
| **Old extension** | today | URL fallback — today's behaviour |
| **New extension** | `offer_id` not in `$validated`, silently dropped | targeted |

## Tests

- Two products in different categories sharing one `(store_id, url)`: POST with the **higher-id**
  `offer_id` updates that row and leaves the lower-id twin untouched. This is the regression the
  whole spec exists for — assert `health_checked_at` lands on the right row.
- `offer_id` belonging to another tenant → **422** (never a silent cross-tenant write).
- `offer_id` whose `store_id` disagrees with `store_slug` → falls back to URL, warning logged.
- `offer_id` pointing at a deleted/nonexistent offer → 422 at validation; **absent** `offer_id` →
  URL path, behaviour byte-identical to today (guards the ~1,000-offer existing flow).
- URL lookup matching multiple offers logs a warning naming the ids.

## Follow-up, not in scope

This makes duplicates *reachable*; it does not remove them. The open items — `pw2d:merge-duplicates`
being category-scoped and blind to cross-category twins, and its failure to catch the four
same-category super-auto duplicates — remain separate work.
