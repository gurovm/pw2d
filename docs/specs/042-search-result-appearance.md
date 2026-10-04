# Spec 042 — Search-result appearance: our own rating, no price-less offer, product titles

**Status:** Phase A APPROVED 2026-10-04 (build started). The owner chose "replace Amazon's rating with our own" (option 1) and asked that the
offer-block removal and the product-title work go into this spec. No manual actions on either site (owner
checked 2026-10-04), so this is compliance work done before Google acts, not a fix for an action already taken.

## Goal

Keep the gold stars in Google results, but earn them with a rating that is ours and visible on the page. Stop
every product page from reading as an invalid merchant listing. Then, once the effect of that change has been
measured, improve pw2d's product titles.

## Evidence (prod and GSC API, 2026-10-04)

- **Stars sit on product pages only, and those results get most of the clicks** (28 days):

  | tenant | product pages, all | with stars (REVIEW_SNIPPET) | without stars | CTR with / without |
  |---|---|---|---|---|
  | c2d | 4,774 impr / 30 clicks | 2,922 / 26 | 1,852 / 4 | **0.89% / 0.22%** |
  | pw2d | 1,828 / 15 | 1,432 / 14 | 396 / 1 | **0.98% / 0.25%** |

  Compare and best-of pages get no stars (0 REVIEW_SNIPPET rows).
- **The stars come from Amazon.** `review` has no `reviewRating` (Spec 022 §5.2), so the only rating Google
  can use is `aggregateRating`, which carries Amazon's rating and review count
  (`SeoSchema.php:233` on product pages, `:582` on every rated pick in compare/best ItemLists). Google's
  review-snippet guidelines (read 2026-10-04): *"Don't aggregate reviews or ratings from other websites"*
  and *"Ratings must be sourced directly from users"*. A breach can lead to a manual action.
- **Every product page is an invalid merchant listing.** `forSelectedProduct` emits an `Offer` without a
  `price` (Spec 019). URL Inspection on 3 product pages, including a control page not yet in the report,
  shows the same result on all three: indexed; Product snippets valid with price warnings; **Merchant listings
  ERROR**. The block earns nothing: PRODUCT_SNIPPETS gives c2d 216 impressions and 0 clicks, pw2d 19 and 0.
- **The candidate rating is the average of our own feature scores.** No category feature has a unit, so every
  `raw_value` is our absolute AI score from 1 to 100 (Stage 3: "50 = average"). The average does not depend
  on Amazon, on price, or on the other products in the pool. Live products, average per product:
  pw2d n=851, mean 68.8 (range 32–92.6); c2d n=401, mean 67.1. Share at 80 or above: pw2d 18%, c2d 19%.
  **Shown as stars that is ≈ 3.4 on average, against Amazon's 4.4**, so our stars will read about one star
  lower. That is the price of compliance, and it is stated here so nobody is surprised by it.
- **Why not the match score in the ring?** It weights Amazon's rating equally with the features, gives
  premium-tier products 0 for price, and changes with the reader's sliders ("Personal Match Score"). That
  last point is the "the score is X but actually Y" problem Spec 022 §5.2 raised.

## Design

### Phase A — compliance (both tenants, one deploy)

1. **`Product::editorialScore(): ?float`.** The mean of `raw_value` over the product's category features,
   divided by 10 and rounded to one decimal (scale 0–10). It returns `null` unless **every** category
   feature has a value. 38 pw2d products currently lack one or more, so they get no rating rather than a
   partial one. Plain PHP over the loaded `featureValues` relation; the caller eager-loads, so no N+1.
2. **Visible on the product page.** In the selected-product header of `product-compare.blade.php`, beside the
   existing match-score ring: **"{Brand} score: 6.8 / 10"**, using the tenant brand name, with a one-line
   tooltip: "Average of our {n} feature scores. Not affected by price, Amazon rating or your sliders." It
   renders only when `editorialScore()` is non-null. The markup must show exactly the number on the page.
3. **`forSelectedProduct` schema:**
   - `review.reviewRating` = `{@type: Rating, ratingValue: 6.8, bestRating: 10, worstRating: 0}`, only when
     the score is non-null;
   - **remove `aggregateRating`** (Amazon's);
   - **remove the `offers` block entirely.** The Product stays valid through `review` ("Product snippets
     require either review or aggregateRating or offers"). With no Offer, the page is no longer a
     merchant-listing candidate.
4. **List pages (`buildListItem`):** remove the nested `aggregateRating`, so every pick becomes the URL-only
   ListItem that the existing rating-less branch already emits. Product rich results do not support
   multi-product pages, and these lists never earned stars.
5. **On-page Amazon rating (★ 4.4 on cards and in the "Customer Rating" slider) stays.** It is visible
   information, not markup. Only the structured-data claim moves.
6. **Tests (Pest):** `SeoSchemaTest` drops the Offer and aggregateRating assertions and asserts their
   **absence**, plus `reviewRating` present only when every feature is scored (all-scored, one-missing,
   none). A unit test pins `editorialScore()` rounding. A render test checks that the page text and the
   markup carry the same number.

### Phase B — pw2d product titles (after Phase A has been measured)

Gated on Phase A's read (≥ 3 weeks after deploy, so Google has re-crawled the product pages; they recrawl
every 1–3 weeks). Shipping both at once would make any CTR change impossible to attribute.

1. **The 88 brandless or junk-ended names** (Spec 041, decision 1): Claude drafts old → new in
   `docs/drafts/`, shaped by `ProductNameShaper`, with the model taken from the Spec 041 backfill. The
   owner reviews, then per-record `update()`. Slugs unchanged.
2. **Title template, pw2d only:** today it is `{name} {category} — AI Review & Match Score`. The proposal is
   `{name} Review: {score}/10 — {category}` where a score exists, otherwise the current template. Decide
   from Phase A data. c2d stays on the current template as the comparison.

## File structure

| File | Change |
|---|---|
| `app/Models/Product.php` | `editorialScore(): ?float` (category feature ids taken from the loaded `category.features`, or passed in, never a query per call). |
| `app/Support/SeoSchema.php` | `forSelectedProduct`: add `review.reviewRating`, remove `aggregateRating` and `offers`. `buildListItem`: remove `aggregateRating`. Update the Spec 019 / 026 comments. |
| `app/Livewire/ProductCompare.php` | Make sure the selected product has `featureValues` and `category.features` loaded (check what is already loaded first). |
| `resources/views/livewire/product-compare.blade.php` | The visible score line and tooltip. |
| `tests/Feature/Seo/SeoSchemaTest.php`, new `tests/Unit/Models/ProductEditorialScoreTest.php` | As above. |

No migration. No new table. Multi-tenant impact: the brand name in the label comes from `tenant('brand_name')`.
The score is per product and therefore already tenant-scoped.

## Rollout and acceptance

1. Build, run `php artisan test`, then `/deploy` on the owner's go (view and schema only; no job code, so no
   worker restart is strictly needed, but the deploy runs it anyway).
2. **Right after deploy:** curl 3 product pages (one with a rating, one without, one pw2d) and check that the
   JSON-LD has no `offers` and no `aggregateRating`, and that `reviewRating` equals the number on the page.
3. **After Google recrawls (~2–3 weeks):** run URL Inspection on the same 3 product URLs plus the KINGrinder
   P2 and Epeios kettle pages. Expect no "Merchant listings" item, and "Review snippets" valid with a rating.
   The Merchant listings report should then drain to 0. That takes weeks and cannot be forced from the API.
4. **The number that matters: REVIEW_SNIPPET impressions and CTR on product pages,** compared with the
   baseline table above, read weekly in `/seo-status`.
   - Stars kept and CTR within ~25% of baseline → done; Phase B can start.
   - Stars gone (REVIEW_SNIPPET impressions near 0 four weeks after recrawl) → Google does not accept our
     rating. We are then where option 2 would have left us: compliant, without stars. Report it; there is
     no fallback to Amazon's rating.
   - *What this would fail to see:* a CTR change caused by something else in the same weeks. Read it against
     the non-star product CTR, which this spec does not touch.

## Risks

- **Lower stars may cost clicks.** Our average is ≈ 3.4 against Amazon's 4.4. The 0.9% vs 0.2% CTR gap is
  with and without stars; nothing measures 3.4 stars against 4.4.
- **Google may not show stars for an AI-scored editorial rating.** Nothing in the guidelines bans it, but
  eligibility is Google's call. The outcome is then option 2, which is still compliant.
- **The score moves when a rescan re-scores features.** That is acceptable: the page and the markup move
  together.

## Memory to update on build

`seo-schema-policy`: no `aggregateRating` from Amazon (Google's guidelines); no price-less `Offer`; our rating
is `review.reviewRating` = the feature average, 0–10, shown on the page. When PA-API arrives, `offers` with a
real price comes back.

## Open questions

None blocking. Phase B's title template is decided from Phase A data.
