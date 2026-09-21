# Items closed during the 2026-09-21 todo split

The old `todo.md` carried 152 unticked items. 47 of them were not open any more — shipped, superseded, a
duplicate, or a watch that had already resolved — and were never ticked. They are listed here with the
reason, so nothing disappears silently. Full original text: `2026-09-21-todo-monolith-snapshot.md`
(search for the bold title).

## Shipped, never ticked (verified 2026-09-21)

| Item | Why it is closed |
|---|---|
| **Spec 014: SEO monitoring integration** `[~]` | Live for months: nightly `pw2d:seo:pull`, the SEO dashboard and its widgets all run in prod (the KPI widget was extended today). |
| **F7: GSC per-URL top_query lookup** | Superseded by F30, shipped in Spec 022. |
| **F19: nightly GSC backfill window** `[~]` | `pw2d:seo:pull` has `--gsc-window-days=4` as its default. |
| **F21: `pw2d:seo:status` command** | `app/Console/Commands/Seo/SeoStatusCommand.php` exists and is run at every SEO check. |
| **F22: SEO operations runbook** | `docs/seo/operations.md` exists. |
| **F31: compare-page weight / CWV** `[~]` | Shipped as Spec 024 (deployed 2026-06-27). |
| **B24-2: verify compare-page CLS** | The Spec 024 deploy note records PSI CLS 0.015 and says it closes B24-2. |
| **Q9: fabricated `reviewCount: 50`** | `SeoSchema` now emits the real `amazon_reviews_count` behind a gate (Spec 026); the literal fallback is gone. |
| **F37 (07-19): extension "verify condition" mode** `[~]` | Delivered by Spec 029's listing-health rescan; it flagged a refurbished listing during today's sweep. |
| **Spec 029: Extension Rescan v2** `[~]` and **Phase C: mass rescan** | Rolled out and completed 2026-08-12 → 16 (all 11 categories). |
| **NEXT: rescan both espresso categories, then regenerate** · **super-auto regeneration deferred pending Spec 034** · **manual-coffee-grinders went STALE** | All six c2d pages were rebuilt 2026-08-21 after Spec 034 shipped. |
| **A1: queued Bouncer calls record `tenant_id = NULL`** | Fixed by Spec 038 (`c31c602`), cost log verified 2026-08-28. |
| **Cost log has 0 rows — expected** | Verified with real rows on 2026-08-28. |
| **Lavalier pool polluted** · **Lavalier page STALE (08-28)** | Swept and rebuilt 2026-08-29 (`12c6064`). Stale again since — tracked as a new todo line. |
| **Spec 039 calibration rounds 1 and 2** | Resolved with owner decisions in the 2026-08-28→29 session (`12c6064`); see that handoff. |
| **Spec 040** · **GA4 undercount** · **GSC top-query overflow** · **c2d PostHog key empty** · **2026-09-21 pool-pollution review** | All done on 2026-09-21; see `docs/summaries/2026-09-21-*`. |

## Watches that resolved

| Item | Outcome |
|---|---|
| **WATCH: `/best/manual-coffee-grinders` zero GSC rows** (08-17 and 09-01 entries) | First row 2026-09-07 (10 impressions, 1 click). |
| **Watch: pw2d stable-cohort position decline** | Reversed 27.6 → 9.9; see the 2026-09-21 SEO checkpoint. |
| **Compare-page cleanup impact — read on 08-23** | Closed 2026-09-01 as unmeasurable (2–3 impressions a week). |
| **First pw2d preset-compare click** | Logged 2026-09-01, explicitly not actionable. |
| **`/compare/productivity-ergonomic-keyboards` has slid three weeks** | The page behind it was rebuilt 2026-09-21; the weekly SEO check carries it from here. |
| **F33: ergonomic-keyboard preset ranking gap** `[~]` | Subsumed by Spec 023 (shipped); `rsi-sufferer` is now a tracked query in every SEO check. |
| **F37 (08-24, 09-01): PostHog key is dead** | Never dead — wrong region. Closed 2026-09-21; lesson logged. |
| **First PostHog engagement read is now viable on c2d** (08-17) | c2d was not tracked at all until 2026-09-21; replaced by a dated todo line. |

## Superseded, duplicate, or a narrative rather than a task

| Item | Note |
|---|---|
| **All 5 pw2d landing pages are STALE** (08-24) · **Remaining pw2d pages still STALE** (08-28) · **Sweep + rebuild the three pw2d categories** · **pw2d page status after 2026-09-21** | Replaced by one todo line per remaining page. |
| **Ergonomic page picks two listings of the same keyboard** | Resolved the same day; both listings were unavailable and the page was rebuilt. |
| **Editorial: repeated products within/across pages** | Folded into the import-quality todo line (same-model guard). |
| **Unstamped stragglers** · **Two unchecked stragglers keep `import_debt` red** | The keyboards one cleared with today's sweep; the mics one rides with the podcast-mics todo line. |
| **S7: Q2/Q3 are overdue** · **N3: missing `strict_types`** | Duplicates of Q2/Q3 and L7, which are in the backlog. |
| **S8: test gaps** `[~]` | Its own text marks every sub-item done. |
| **Gemini daily cap ≈ 250 evaluate calls** | A fact, not a task; kept in the `gemini-daily-cap` memory. |
