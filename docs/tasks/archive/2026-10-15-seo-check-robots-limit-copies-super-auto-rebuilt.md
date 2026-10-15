# Archive — 2026-10-15 — SEO check, robots.txt and `?limit=` crawl fixes, super-automatic rebuilt

Completed this session (detail: `docs/summaries/2026-10-15-stale-price-bands-robots-404-limit-copies-super-auto-rebuilt.md`):

- [x] **Weekly picks run, both tenants** (4 days late) — c2d 44 offers incl. 7 WLL + 3 Clive, pw2d 35; 0 errors; audits run.
- [x] **`/seo-status`** — SEO checkpoint UPDATE 2026-10-15 + five addenda (indexing, `/vs/` + ChatGPT, guides → product
  pages, Product snippets drop, Merchant Center).
- [x] **robots.txt answered HTTP 404 on both sites — fixed on prod** (owner OK): nginx `location = /robots.txt` got
  `try_files $uri /index.php?$query_string;`; backup `/root/backups/nginx-pw2d.com.2026-10-15-before-robots-fix`.
- [x] **Product pages no longer link `?limit=` copies of themselves** — `894d727`, deployed via `/deploy`, verified live;
  5 tests, suite 991 passed. Crawl re-check ~10-29 stays in `todo.md`.
- [x] **c2d super-automatic end to end** — 4 WLL links replaced in place, Amazon brand searches (+8 machines), Gaggia
  Magenta Prestige un-hidden, category rescan (= monthly sweep), 72 models, wrong Amazon listing removed from
  Magnifica Evo LatteCrema, price bands recalculated (5 changed), guide rebuilt from an owner-approved draft and saved
  behind selection + price guards → FRESH. Drafts: `docs/drafts/2026-10-15-super-auto-*`.
