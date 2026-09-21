---
description: Close the session — commit the work, write the session summary, enforce task-file hygiene, upsert the weekly roll-up, push, and leave the tree clean
---

Close the session so the next one inherits the context instead of rediscovering it.

**End state, non-negotiable:** `git status --short` is empty and `origin/main` is up to date.
The command is not done until both are true.

**This command never deploys.** Pushing to `origin/main` does not touch production — prod only
changes through `/deploy`. If code was pushed but not deployed, say so in the summary header.

## Step 1: Commit the work first

`git status --short`. If anything is uncommitted — code, tests, specs, `.claude/`, docs — commit it
**now, before writing the summary**, so the summary can cite the hashes.

- Group into logical commits with real messages (`feat:` / `fix:` / `docs:` / `test:` / `chore:`),
  not one `wip`. Look at every file before staging it — never `git add -A` blind.
- **Ask before committing** anything ambiguous: a data export, a SQL dump, a scratch file, a large
  file. Never commit `.env`, the Google service-account JSON, or anything holding a key.
- Code goes in its own commit(s), separate from docs, so `/deploy` history stays readable.
- If the user says a file must stay uncommitted, leave it — and name it in the summary's
  "What changed" so the next session knows it is there.

## Step 2: Production loose ends (read-only checks)

Work here happens directly against prod (`ssh root@209.97.153.234`, app at `/var/www/pw2d`), so a
session can leave things behind that git never sees. Check, do not fix silently:

1. **Deployed vs pushed:** `git -C /var/www/pw2d log --oneline -1` on prod against local
   `origin/main`. If prod is behind and the gap contains code (not only docs), the summary header
   says **"code pushed, NOT deployed"** with the hashes. Never deploy from this command.
2. **Scratch files:** `ls /tmp/*.php /tmp/*.json 2>/dev/null` on prod — anything this session put
   there (tinker scripts, payload exports) is removed. Anything not ours is left alone and named.
3. **Backups made today:** `ls -la /root/backups | grep $(date -u +%Y-%m-%d)` and the dated
   `landing_page_*` files — list them in the summary (path + what they protect). They are the undo
   path for the session's prod writes; the next session must be able to find them.
4. **Queue and failures:** `SELECT COUNT(*) FROM jobs;` and failed jobs since midnight. A
   non-empty queue or a failed job is a line in "What is still unknown", not something to hide.

## Step 3: The session summary file

Write `docs/summaries/YYYY-MM-DD-short-kebab-slug.md`. The slug names **what was learned or
decided**, never "worked on pw2d" or "session handoff". Template:

```markdown
# Session Summary — YYYY-MM-DD — Headline stating the actual finding

**Commits:** `abc1234` (what) · **Suite:** 837 → 858 passed (0 failures)
**Prod:** deployed `a667f2d` · or · code pushed, NOT deployed (`abc1234..def5678`)
**Prod writes:** what was mutated, how many rows, backup path · or · none (read-only session)
**Spec:** docs/specs/…

> **The one-line lesson:** the single most important thing a reader should take away,
> stated so it survives being read alone.

## 1. What changed
## 2. What was measured
## 3. What is still unknown
```

Rules that make it worth writing:
- **Findings, not activities.** "The nightly GA4 pull stored 42% of pw2d's sessions" is a summary;
  "looked at analytics" is not.
- **Every number carries its provenance** — prod or local (the local DB is empty; a local figure is
  never quoted), the date, the window, and whether it is measured, a what-if, or an estimate.
- **Record what was falsified or corrected** — including our own wrong claims ("the PostHog key is
  dead" was the wrong region). A killed theory stops the next session re-proposing it.
- **Be honest about unknowns.** Overstating certainty is worse than no summary.
- **Every prod write is listed** under "What changed" with its backup path and how to undo it.
- If no code / tests / prod data were touched, say so in the header lines rather than omitting them.
- The SEO checkpoint log (`docs/summaries/2026-06-13-seo-status-checkpoint.md`) is maintained by
  `/seo-status`, not here. If an SEO check ran this session, link its dated UPDATE section — do
  not copy its tables.

## Step 4: Task-file hygiene

The layout this command enforces:

- `docs/tasks/todo.md` — **active work only.** One line per item:
  `- [ ] **ID or short name** — gist → pointer to the summary/spec that holds the detail`.
- `docs/tasks/backlog.md` — deferred and unscheduled, same one-line shape.
- `docs/tasks/archive/YYYY-MM-DD-slug.md` — completed items and narratives, moved at session end.

Do, in order:

1. From "What is still unknown" / next steps: items **scheduled** as next work → one line each in
   `todo.md`; deferred / unscheduled → one line in `backlog.md`. Detail lives in the summary file;
   the task line carries only name + gist + pointer. Check for duplicates first.
2. Move every completed `[x]` item and every session narrative added this session out of `todo.md`
   into `docs/tasks/archive/YYYY-MM-DD-slug.md`. After this command runs, nothing this session
   wrote into `todo.md` may still be a `[x]` or a paragraph.
3. Owner actions ("needs you, 2 min") stay in `todo.md` under one `## Owner` heading so they are
   seen at boot, not buried.

## Step 5: Standing records this project keeps outside git history

1. **`docs/lessons.md`** — if the user corrected anything this session, or a claim of ours turned
   out wrong, the rule must already be there. If it is not, add it now (CLAUDE.md: self-improvement
   loop). A lesson is about how we work; a fact about the product belongs in the docs, not here.
2. **Maintenance cadence** — if the session ran a weekly picks run, a category sweep, a top-up
   import, or rebuilt a page, update the last-completed dates in the `maintenance-cadence` memory
   and state what is due next and when (weekly picks + `/seo-status`, monthly sweep, quarterly
   discovery). The next `/architect` boot reminds the owner from it.
3. **Memory index** — any memory written this session has its one-line pointer in `MEMORY.md`;
   a memory that turned out wrong is corrected or deleted, not left to mislead.
4. **`docs/drafts/`** — a draft whose page was saved stays as the record of what the owner
   approved. A draft that was abandoned is deleted, or named in the summary with the reason.

## Step 6: Weekly roll-up (UPSERT, do NOT regenerate)

Update `docs/summaries/weekly-{SUN}-to-{SAT}.md`, where `{SUN}` is the most recent Sunday
on/before today and `{SAT}` = that Sunday + 6 days (both `YYYY-MM-DD`; the week runs
Sunday→Saturday). APPEND-and-refresh, never rebuild:

- If the file doesn't exist: create with skeleton — `# Weekly Summary — {SUN} → {SAT}`,
  `**Headline:**` (one line, the week's arc), `## By session (newest first)`,
  `## Net outcomes`, `## Carry-forward (open)`.
- **Reconcile against the daily files of this week:** glob `docs/summaries/YYYY-MM-DD-*.md`
  for dates `{SUN}`..`{SAT}`. Every daily file must have exactly one bullet under
  "By session". For any that is missing (a weekly created mid-week, or a session that skipped
  this command), add its bullet from the file's **first line only** (`head -1` — the H1 carries
  the date and the finding); do **not** read those files in full.
- Prepend ONE bullet for THIS session under "By session":
  `- **{date} — {gist}** → [{filename}]({filename})` + 1–3 sentence synopsis with key commits.
- Refresh Headline / Net outcomes / Carry-forward to reflect this session.
- **Carry-forward always ends with the cadence line:** what is due and when — weekly picks,
  `/seo-status`, the categories whose monthly sweep is overdue, the pages still STALE.
- **Length discipline:** the weekly is a thin index — one bullet per session, links for
  detail, never a second copy of the summaries. It is meant to be read at `/architect` boot, so
  "Carry-forward (open)" must be complete enough to stand alone.

## Step 7: Final commit, push, verify clean

1. Show the user the summary **in the short form they asked for** (the `answer-format-template`
   memory: fixed sections, plain names instead of codes, under ~200 words, in the language the
   user is writing in). The full detail is in the file; the chat gets the short version and the link.
2. Commit the summary + the weekly + task-file changes (`docs: session summary YYYY-MM-DD — …`).
3. `git push origin main`.
4. **Verify:** `git status --short` must print nothing and `git log origin/main..HEAD` must be
   empty. If either is not — something was created after Step 1, a push failed — fix it now.
   Anything deliberately left uncommitted (Step 1) is listed explicitly to the user, by name,
   with the reason. Nothing is left silently.
5. Last line to the user: whether production matches `origin/main`, and if not, that `/deploy`
   is theirs to run.
