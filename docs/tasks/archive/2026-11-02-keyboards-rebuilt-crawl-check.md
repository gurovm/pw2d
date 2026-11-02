# Archived 2026-11-02 — completed in the session of 2026-10-29 → 11-02

Detail: `docs/summaries/2026-11-02-keyboards-rebuilt-bot-queries-inflate-impressions.md`

- [x] **pw2d mechanical gaming keyboards: rebuilt and saved 11-01** — swept 10-29, clean-up applied 10-29 (189 models, 11 hidden, 5 brands, 2 renames), guide saved behind four guards, audit FRESH; backups `/root/backups/mech-keyboards-products-before-cleanup-2026-10-29.sql`, `/root/backups/2026-11-01-mech-keyboards/` → `docs/drafts/2026-10-29-mech-keyboards-guide-draft.md`; Wireless preset left off (all 4 presets sort_order 0, owner OK)
- [x] **Done 10-29: "Request indexing" for 5 pw2d product pages** — 4 FAIL on old Merchant listings markup (URL Inspection 10-29): `aula-f99s-purple-gradient-vxbgn`, `keychron-k8-he-tkl-black-3b750`, `keychron-k8-he-tkl-nqjld`, `razer-blackwidow-v4-tkl-hyperspeed-wireless-kvs0b` (last crawled 08-31 → 09-19); `keychron-k8-he-tkl-white-r9bes` is unknown to Google → SEO checkpoint addendum 2026-10-29
- [x] **Crawl check `?limit=` copies — done 10-29**: 853 → 170 per two weeks, draining; plain product fetches unchanged (~60/day), so total crawl halved instead of moving to real pages → SEO checkpoint addendum 2026-10-29
- [x] **`/seo-status` 2026-11-02** — done; bot queries inflated impressions on both sites, clicks c2d 78 → 103, pw2d 14 → 11; wait a week → SEO checkpoint UPDATE 2026-11-02
- [x] ~~**Out-of-stock listings pass the picker**~~ — owner-approved 10-29, cancelled before any code: the label was stale (`stock_status` keeps its last value), the extension sends no price from an out-of-stock page → backlog "`stock_status = out_of_stock` sticks", lesson 2026-10-29
