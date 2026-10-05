# Spec 043 — Head-to-head ("A vs B") pages and a "Compared with" block

**Status:** approved for build (2026-10-05) — pilot pairs approved; open questions 1–4 take the recommended answer unless the owner objects. Owner asked for it; build after the current semi-automatic work.

## Goal

Rank for head-to-head searches ("lelit bianca vs profitec drive") with a page per pair that a buyer can decide from:
our feature scores side by side, preset fit, prices and store links, and grounded prose on who should buy which.
High-ticket pairs first: one Clive / Whole Latte Love sale is worth ~100 Amazon kettle sales
(memory `content-priority-high-ticket`).

**Evidence — Phase 0 done 2026-10-05, weaker than first thought** (`docs/drafts/2026-10-05-vs-queries.md`): the
full GSC pull (both sites, 16 months) has ~20 real pair searches with 1–18 impressions each and no clicks; 40 of 59
"vs" rows are junk at position 200+. Google does associate our product and compare pages with pairs ("bezzera hobby
vs rancilio silvia" 18 impressions, "keychron k2 vs aula f75" 17, "hario switch 02 vs 03" 10), but the demand that
reaches us today cannot justify pages on its own. Market demand for known rivalries is real but unmeasured: our data
cannot see searches we never appear for. **Consequence:** Phase 1 (cheap, on pages that already carry the traffic)
goes first and is worth building regardless; Phase 2 starts as a 5-page pilot whose pairs are chosen by measured
search volume (open question 5), not by our own GSC rows.

**What it is not:** programmatic pages for every pair. Scaled thin pages are a Google policy risk and dilute the
site. Start with a 5-page pilot on coffee2decide, measure six weeks, then decide.

## Phases

### Phase 0 — demand data (Claude, in session, read-only; no build)
1. One-off GSC pull per tenant, last 16 months, `dimensions = [query, page]`, filter `query contains " vs "`
   (also `" or "` and `"versus"`), through the existing `GoogleSearchConsoleService` client. Output: query,
   impressions, clicks, average position, landing URL. Saved to `docs/drafts/YYYY-MM-DD-vs-queries.md`.
2. Candidate pairs from our pool (query below), then a manual demand check per candidate: Google search
   suggestions for "{A} vs". Google Trends reports "not enough data" for most model pairs — not used.
3. Claude proposes the first 5 pairs with the reason and measured volume for each; the owner approves the list.

**Pair rules** (all must hold unless demand data overrides 3–4):
1. Same leaf category; both products live (`is_ignored = 0`, `status IS NULL`), every category feature scored,
   and a pick-eligible offer (`ListingHealth::isPickEligible`).
2. Different models (`ModelIdentity` — never two colours or the flow-control option of one machine).
3. A real choice on price: the dearer one costs ≤ ~1.4× the cheaper.
4. The scores split: each product wins at least one feature by ≥ 5 points. A pair where one wins everything is
   allowed only with proven demand, and its page says so plainly.
5. High-ticket first (c2d semi-automatic, super-automatic), then the rest.

### Phase 1 — "Compared with" block on product pages (pipeline; independent of Phase 2)
On `/product/{slug}`: the 2–3 closest rivals — same category, live, pick-eligible, different model, nearest by
price (±40%), tie-break by editorial score. Each row: name, our score (`Product::editorialScore()`), estimated
price, link to its product page; plus a "Read the comparison" link when a published VS page exists for the pair.
Product pages carry ~70% of impressions and every store click (snapshot "SEO checkpoint 2026-08-17"), so this adds
depth and internal links where the traffic already is, and gives Phase 2 pages links from day one. It partly
answers the long-pending product-page depth item (todo "Spec 028 candidate").

### Phase 2 — VS pages (Claude-written prose, owner-reviewed, guarded)
Route `GET /vs/{slug}`, slug = `{a-name}-vs-{b-name}` from the product names (no random suffix), products ordered
alphabetically so "A vs B" and "B vs A" never become two pages. `/compare/{slug}` is the category route, so the
pair pages get their own prefix.

**Page content (top to bottom):** H1 "{A} vs {B}"; two product cards (image, name, our score, estimated price,
store buttons from `bestOffer`); feature table — the category's features, both scores, winner marked; preset row
("better for The Purist: A"), computed from preset weights exactly as `SelectLandingPagePicks` does; prose —
intro, "where A wins", "where B wins", "who should buy which"; verdict; FAQ (2–4); links to both product pages, the
category's `/best/` guide and `/compare/` page. All numbers in the prose come from the score table (grounding).

**Writing and saving.** Prose is written by Claude in session under the landing-page style and grounding contract
(memory `ai-content-style-bar`; Gemini's admin model times out on long-form), drafted to `docs/drafts/` for owner
review, then saved by a command with guards:
- **selection guard** — the pair still satisfies pair rules 1–2 at save time;
- **price guard** — each product's `estimated_price` is stored as a snapshot; the save refuses if it moved > 10%
  since the draft was written (the prose quotes prices);
- **style guard** — the banned-phrase list, moved out of the `AiService` prompt text (`AiService.php:958-1003`)
  into one shared class used by both the prompt and this check.

**Freshness:** the nightly landing-page audit also audits VS pages with the same reasons (`pick_ineligible`,
`price_drift`) and sets `stale_reasons`. A product that is deleted or hidden makes the page 404 and drops it from
the sitemap (open question 2).

**SEO plumbing:** `<title>` "{A} vs {B}: scores, price and which to buy"; meta description from the verdict;
canonical; BreadcrumbList (Home › category › "A vs B"). **No `Offer`, no `aggregateRating`** (Spec 042 policy,
memory `seo-schema-policy`). Published pages in `sitemap.xml`. The category's `/best/` page gets a "Head-to-head"
link list when it has published VS pages.

## File structure

| File | Purpose |
|---|---|
| `database/migrations/2026_10_xx_create_vs_pages_table.php` | `vs_pages` table |
| `app/Models/VsPage.php` | `BelongsToTenant`; `productA()`, `productB()`, `category()` |
| `app/Actions/SelectRivals.php` | Phase 1 rival selection for one product |
| `app/Actions/BuildVsComparison.php` | Feature table + preset winners for a pair (shared by page and save command) |
| `app/Actions/AuditVsPageFreshness.php` | Stale reasons for one page (reuses `AuditLandingPageFreshness` checks) |
| `app/Support/ProseStyle.php` | Banned phrases + `violations(string $html): list<string>` |
| `app/Console/Commands/SaveVsPage.php` | `pw2d:vs-pages:save {tenant} {file} {--publish}` with the three guards |
| `app/Http/Controllers/VsPageController.php` | Thin: resolve published page or 404, cache 1h tenant-scoped |
| `resources/views/vs/show.blade.php` | The page |
| `resources/views/components/compared-with.blade.php` | Phase 1 block, included by `livewire/product-compare.blade.php` in product mode |
| `app/Filament/Resources/VsPageResource.php` | List, status toggle, stale reasons (read-mostly) |
| `routes/web.php` | `Route::get('/vs/{slug}', …)->name('vs.show')` |
| `app/Http/Controllers/SitemapController.php`, `resources/views/sitemap.blade.php` | Published VS pages |
| `app/Console/Commands/AuditLandingPagesCommand.php` | Also audits VS pages |

## Class contracts

```php
final class SelectRivals {
    /** @return Collection<int, Product> 0–3 rivals, nearest price first */
    public function handle(Product $product, int $limit = 3): Collection;
}
final class BuildVsComparison {
    /** @return array{features: list<array{feature: string, a: int, b: int, winner: 'a'|'b'|null}>,
     *                presets: list<array{preset: string, winner: 'a'|'b'|null}>, a_wins: int, b_wins: int} */
    public function handle(Product $a, Product $b): array;
}
final class AuditVsPageFreshness {
    /** @return list<string> stale reasons, empty when fresh */
    public function handle(VsPage $page): array;
}
final class ProseStyle {
    /** @return list<string> banned phrases found (case-insensitive, whole words) */
    public static function violations(string $html): array;
}
```

## Database

`vs_pages`: `id`; `tenant_id` string nullable FK → tenants; `category_id` FK; `product_a_id`, `product_b_id` FK →
products (cascade on delete); `slug` string; `title` string; `intro`, `verdict` text; `sections` json (where A wins,
where B wins, who should buy which); `faqs` json; `price_snapshot_a`, `price_snapshot_b` int nullable; `status`
enum draft/published; `generated_at`, `freshness_checked_at` timestamp; `stale_reasons` json; timestamps.
Indexes: `unique(tenant_id, slug)`, `unique(tenant_id, product_a_id, product_b_id)` (the save orders the pair by product name, so A is alphabetically
first, and refuses if the swapped pair already exists),
`index(tenant_id, category_id)`, `index(tenant_id, status)`. `down()` drops the table.

## Multi-tenant impact

Every query scoped by tenant (`BelongsToTenant`); cache keys carry `tenant('id')` (Spec 004); the save command
takes the tenant as an argument and refuses product ids from another tenant; both products must share one category.

## Testing (Pest, `RefreshDatabase`)

Rivals: excludes same model, hidden, ineligible, other category; price window; order. Comparison: winners,
ties, preset winners match `SelectLandingPagePicks` weighting. Save command: each guard refuses (and writes
nothing); slug order is alphabetical; duplicate pair refused. Controller: published 200, draft 404, other tenant
404, hidden product 404. Sitemap lists published only. Schema has no `Offer`/`aggregateRating`. Audit: price drift
and ineligible product produce the reasons. `ProseStyle`: hits, whole-word, case.

## Rollout

1. Phase 0 — done 2026-10-05: GSC pull + Keyword Planner volumes for 19 pairs (`docs/drafts/2026-10-05-vs-queries.md`).
   **Pilot approved by the owner 2026-10-05**, all 100–1K searches/month in the US: **Profitec GO vs Rancilio Silvia**, **Jura
   E4 vs E6**, **Jura E6 vs E8**, **Jura E8 vs S8**, **Breville Barista Express vs Barista Pro**. Reserves: La Specialista
   vs Barista Express, Ninja Luxe Café vs Barista Express, Barista Express vs Impress. Three Jura pages need the
   super-automatic sweep + model backfill first.
2. Build Phase 1 + Phase 2 in one build; `php artisan test`; `/deploy` on the owner's go (no job code changes).
3. Ship the "Compared with" block first (visible at once on every product page).
4. Write 5 VS pages as drafts → owner review → save + publish → request indexing for the 5 URLs.
5. **Gate after 6 weeks:** per page impressions, position, Google clicks, store clicks (GA4 outbound). Expand to
   more pairs and pw2d only if at least 2 of 5 reach page one for their pair query.

## Dependencies

Spec 027/030 (landing pages, freshness audit), Spec 034 (pick diversity), Spec 041 (`ModelIdentity`,
`isPickEligible`, model backfill per category — **a category's backfill must be done before its VS pages**, or
colour copies can pass rule 2), Spec 042 (schema policy). No new packages.

## Open questions

1. URL prefix `/vs/` (recommended) or `/compare/{a}-vs-{b}`.
2. A product disappears: 404 the page (recommended for v1, simple) or keep it with a notice and `noindex`.
3. First 5 all on coffee2decide high-ticket (recommended), or include pw2d's strongest signals (OD303 vs SM58,
   TC-Helicon MP-75 vs MP-85).
4. Phase 1 before Phase 2 as a separate deploy, or together (recommended together — one test pass, one deploy).
5. **How to measure pair demand.** (Answered 2026-10-05: Keyword Planner on the owner's existing Ads account.) Google Ads Keyword Planner gives monthly volumes free but needs a Google Ads
   account (the owner creates it; no ads need to run). Without it: Google's search suggestions ("{A} vs …") show
   which pairs people type, not how often. Recommended: Keyword Planner, one session, ~15 candidate pairs.
6. **Our scores bunch at the high end** (88–100 on prosumer machines; Bianca vs Drive within 4 points everywhere,
   Barista Pro vs Touch identical). Tie pairs are excluded from the pilot. If high-ticket VS pages prove worth it,
   the real differences (flow paddle, boiler type, warm-up) need grounded spec facts, which we do not store today —
   a separate decision after the gate.
7. **Brand vs brand pages** ("delonghi vs breville" 1K–10K/month, "jura vs delonghi" 100–1K). Bigger volume than any
   product pair, but a different template (brand-level averages, range overview) and partly Nespresso intent we do not
   cover. Recommended: decide after the pilot gate.
