# Session handoff — 2026-08-28 → 29: Spec 038 + 039, headsets & lavalier rebuilt, session-scoring calibrated

## What this session became

It opened as an `/architect` boot with a routine reminder (weekly picks verification due) and a request to
make answers shorter and code-free — that produced the answer-format template now in memory. The second
question, *"let's check the AI logs — I think there's no problem"*, found two problems and the rest of the
session followed from them: a small fix bundle (Spec 038), the headsets page rebuild, a lavalier top-up that
hit Gemini's daily cap, and — from the owner's question *"can we use the Claude subscription for this?"* —
a new overflow path (Spec 039) that was specified, built, reviewed, deployed and calibrated on three models
before the session ended.

**Production is at `cd636cc` + docs commits.** Tests: 737 → 837. Both pw2d pages touched read FRESH.

## Shipped

### Spec 038 — AI usage log fix bundle (deployed 2026-08-28 11:21 UTC, `c31c602`)
- Queued AI calls now carry the tenant on `ai_usage` rows (audit A1); null-model hardening (A2); production
  models (`gemini-3.1-pro-preview`, `gemini-3.5-flash`) added to the price map — before this every prod row
  would have recorded a null cost; 28 products stuck at `pending_ai` (renewed listings the import correctly
  rejected but never cleared) fixed at the three import sites + a data migration.
- Reviewer caught one flaw in the spec itself (an existing-product path excluded on a wrong "in-flight job"
  argument) — fixed; lesson recorded.
- **Verified live on the lavalier import:** 435 calls, every row tenant-tagged and priced.

### Spec 039 — Bouncer overflow path, operator-session evaluations (deployed 2026-08-28 17:25 UTC, `cd636cc`)
- `ProductEvaluation` value object (one schema for Gemini and session output, tolerant of everything the
  old job accepted; new `wrong_category` verdict); `FinalizeProductEvaluation` action extracted from the
  job, behaviour-identical (21 branches traced by the reviewer); `pw2d:products:export-pending`,
  `pw2d:products:apply-evaluations` (tenant check, status guard, per-row transactions, dry-run, idempotent,
  refuses while queue jobs exist), `pw2d:ai:eval-model --from-file` (read-only diff harness with the gate).
- Two review passes, seven findings fixed (a `TypeError` escaping the job's catch; over-strict validation
  that would have failed real Gemini payloads; batch abort on one bad row; missing-feature gap; calibration
  contamination; a queue-overwrite race).
- Runbook: `docs/ops/bouncer-session.md` — sequence, subagent brief, calibrated scale, budget (~3k
  tokens/product), trust gate.

### Content
- **`/best/gaming-chat-headsets`** rebuilt 28 Aug (6/7 picks changed; the Stinger 2 had Amazon's "High price"
  flag, invisible to the extension's counter).
- **`/best/lavalier-wireless-systems`** rebuilt 29 Aug (4/7 changed; premium slot Sennheiser combo $1,070 →
  Shure GLXD14+/93 $690). Full Tier-3 cycle on lavalier: 277-row import, 91 stranded by Gemini's cap, cleared
  after the 07:00 UTC reset ($1.50), two sweeps removing 83 wrong-category products, rescan 192.

### Ops
- `/deploy` gained step **9b `queue:restart`** — workers had kept running old job code for up to an hour.
- Prod probe found Gemini scores shotgun mics into the lavalier category; the sweep model is inconsistent
  on handheld systems (removed XSW sets, kept GLXD24+/SM58). **Always follow a sweep with a name-pattern
  check** — 15 more handhelds/headsets were found that way.

## Findings worth keeping

- **Gemini's daily cap on the admin model is ~250 evaluations** (observed; unpublished). "2 SERP pages per
  phrase" on 13 phrases = 277 rows and blew it. Rule: one page per phrase, ≤200 products/day. Memory
  `gemini-daily-cap`.
- **Measured cost:** $0.0145/product evaluate + $0.004 match ≈ **1.9¢ all-in** on 3.1 Pro preview. Spec 037
  §1 baseline corrected (it had priced 2.5 Pro from the config default, not prod's `.env`).
- **The extension rescan makes no AI calls** — it is a health check. Only imports (and site AI searches)
  write to the cost log. I promised otherwise once; corrected in memory.
- **Session scoring calibration, 50 blind lavalier products vs stored Gemini answers.** Round 1 was a
  uniform −10 on every feature (the brief said "50 = average"; the site's real scale clusters high — 47% of
  stored scores are 80–100). With the calibrated brief: verdicts 100%, brands 100%, bias +1.7, MAD 7.1;
  84% of scores within ±10; batches within 2 points of each other. The residual is dominated by Gemini
  disagreeing with itself (passive wired lav Battery Endurance stored at 20, 20, 20, 85, 90, 99).
- **Owner decision:** overflow-path pass mark = verdicts ≥95%, brands ≥98%, |bias| ≤3, MAD ≤8 (the ≤5 rule
  stays for Spec 037 model swaps). **Three-way on identical inputs:** Opus 5 MAD 6.61, Fable 5 7.08, Sonnet 5
  7.58 — all pass, identical verdicts/brands on equal footing. **Opus 5 is the default scorer** (Fable's
  subscription allowance is half of Opus's; Fable reserved for planning). Memory `scoring-subagent-model`.
- **Reviewer corrections of the architect, twice:** Spec 038 B3's "in-flight job" exclusion and Spec 039 T1's
  over-strict value object. Both were mechanism claims written into a spec without a grep. Lesson entries
  2026-08-28 in `docs/lessons.md`.

## Open, in recommended order

1. **Next category: productivity-ergonomic-keyboards** (run sheet §3). One SERP page per phrase; stop ~200.
   Overflow → Spec 039 session path with `model: opus`.
2. **Coffee site weekly picks verification** — due 28 Aug, not run. ~7 min, tenant coffee2decide.
3. **#4649** Hollyland Lark MAX 2 OWS — one unchecked lavalier offer (rescan error). Single-scan.
4. Remaining pw2d pages STALE on `selection_drift`: mics, mechanical keyboards, ergonomic keyboards —
   each needs its own rescan before regeneration (never re-select from an unverified pool).
5. Spec 037 T2 (live-model replay runner) now has its diff core built (`CompareProductEvaluations`); T3
   (3.7 Flash) remains gated on it. Today's cap incident is the strongest argument for T3.
6. Spec 039 LOW/NIT leftovers and Spec 038's L2/N1–N4 — filed in `docs/tasks/todo.md`, none blocking.
7. Flaky `SelectLandingPicksTest` (order-dependent) — reproduce with `--order-by=random`.

## Housekeeping

- Memory files added: `answer-format-template`, `gemini-daily-cap`, `scoring-subagent-model`;
  `maintenance-cadence` updated (28/29 Aug state, no-AI-in-rescan fact).
- PostHog personal API key still dead (F37). Git remote PAT still needs rotating (from the 08-22 handoff).
