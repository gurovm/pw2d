// Background Service Worker — Rescan Mode (Spec 029 B2; picks scope Spec 031 T2)
//
// 029B-S1: the legacy tab-walking batch importer (START_BATCH / SCRAPE_COMPLETE /
// RESUME_BATCH and friends) was removed — the live SERP batch flow runs entirely
// inside popup.js against /api/products/batch-import + /api/extension/ingest-offer,
// and the walker's /api/product-import payload no longer matched the server's
// validation. Batch↔rescan mutual exclusion now lives in popup.js, which is the
// only place either flow can be started: it checks rescan state (GET_STATUS)
// before starting a batch, and its own in-flight batch flag before START_RESCAN.
// 031-T2: the picks walk is the SAME run object, so it inherits both directions of
// that exclusion (batch⇄rescan and batch⇄picks) and is mutually exclusive with a
// category rescan too — START_RESCAN refuses whenever a run is already active.

const API_CONFIG = {
    local: 'http://127.0.0.1:8003',
    production: 'https://pw2d.com'
};
let currentEnv = 'local';
let baseUrl = API_CONFIG.local;
let EXTENSION_TOKEN = '';
let TENANT_ID = '';

// ─────────────────────────────────────────────────────────────
// Navigation allowlist (2026-08-16 security audit M3)
//
// The walk drives a real browser tab to `offer.url`, a value that reached the DB
// from an ingestion payload — the server validates it as a URL but constrains
// neither scheme nor host. Spec 031 turns that into an UNATTENDED weekly walk of
// ~100 rows, so the extension must not trust its own server here.
//
// The list is exactly the set `content.js::extractStoreProduct()` routes on
// (matched after stripping a leading `www.`). A row outside it could never have
// been extracted in the first place, so refusing to navigate loses nothing:
// off-allowlist rows are counted as `blocked` and reported, never silently
// dropped. Exact host match — `notamazon.com` and `amazon.com.evil.test` both
// fail, which a `endsWith`/`includes` test would not.
//
// ONE definition serves BOTH walk modes: the check lives in processNextRescan(),
// which category and picks runs share verbatim (scope only picks the work list).
// ─────────────────────────────────────────────────────────────
const RESCAN_ALLOWED_HOSTS = [
    'amazon.com',
    'clivecoffee.com',
    'seattlecoffeegear.com',
    'wholelattelove.com',
];

function isWalkableUrl(rawUrl) {
    let parsed;
    try {
        parsed = new URL(rawUrl);
    } catch {
        return false; // not a URL at all
    }
    if (parsed.protocol !== 'https:') return false; // no http/ftp/file/ws/…
    return RESCAN_ALLOWED_HOSTS.includes(parsed.hostname.toLowerCase().replace(/^www\./, ''));
}

// ----- Rescan Mode State (Spec 029 B2, extended by Spec 031 T2) -----
// Persisted to chrome.storage.local ('rescanRun') after every step so Resume
// works after the popup closes or the service worker restarts.
//
// 031-T2: ONE walk serves both modes. `scope` only changes where the work list
// came from and how the run is labelled/tallied — every mechanism below (delay,
// watchdog, reused worker tab, CAPTCHA auto-pause, generation counter,
// idempotent advance, persistence) is shared verbatim. Because both modes share
// this single state object they are also mutually exclusive for free: START_RESCAN
// refuses while `active`, and popup.js reads `active` before starting a batch.
let rescanRun = {
    active: false,
    paused: false,
    pausedReason: null,   // 'user' | 'captcha' | 'worker_restart'
    scope: 'category',    // 'category' (one category's offers) | 'picks' (live picks, all categories)
    categoryId: null,     // category mode only — picks rows carry their own category_id
    offers: [],           // [{offer_id, product_id, url, asin, last_scanned_at, category_id?, landing_page_slug?}]
    index: 0,
    tabId: null,          // reused worker tab (never persisted)
    results: emptyRescanResults(),
};
let rescanTimer = null;

// ─────────────────────────────────────────────────────────────
// Tally buckets. Every bucket is reported; nothing is ever folded into another.
//
//   updated           the server wrote what we sent, nothing wrong with the listing
//   flagged_condition a bad-listing verdict (product-level OR offer-level)
//   skipped           the server deliberately declined to write
//   unknown_action    success:true with an action string this build does not know
//   errors            transport/extraction failure, or success:false
//   blocked           refused to navigate: off-allowlist URL (security M3)
//   no_category       dropped before the walk started: no category_id (audit B3)
//   flagged_guides    {slug: count} — picks mode only (category rows carry no slug).
//                     Names the guide behind each flagged pick so the owner knows
//                     which category to rescan in full (Spec 031's response rule).
// ─────────────────────────────────────────────────────────────
function emptyRescanResults() {
    return {
        updated: 0,
        flagged_condition: 0,
        skipped: 0,
        unknown_action: 0,
        errors: 0,
        blocked: 0,
        no_category: 0,
        flagged_guides: {},
    };
}

// ─────────────────────────────────────────────────────────────
// Server action → tally bucket (2026-08-16 audit B4).
//
// EVERY action string the three ingestion controllers + OfferIngestionService can
// return is listed explicitly. There is deliberately no "everything else is fine"
// default: an unlisted action lands in `unknown_action` and is logged, because the
// bug this table replaces was exactly that — `flagged_offer_condition` was new,
// fell through an `else`, and was reported to the owner as a clean `updated`.
//
//   refreshed               OfferIngestionService — existing offer re-scraped     → updated
//   created                 OfferIngestionService — new product created           → updated
//   matched                 OfferIngestionService — AI-matched to an existing one → updated
//   queued_new              ProductImportController — new product queued for AI   → updated
//   queued_rescan           ProductImportController — existing product refreshed  → updated
//   flagged_condition       bad condition, NO clean sibling → product ignored     → flagged_condition
//   flagged_offer_condition bad condition, a clean sibling survives → offer only  → flagged_condition
//   skipped_condition       import refused outright, nothing written              → skipped
//   skipped_ignored         ProductImportController — product already ignored     → skipped
//
// `flagged_offer_condition` is the multi-store case Spec 029 §A2b exists for: the
// product stays visible because another store's listing is clean, so it MUST still
// count as flagged and MUST still name its guide — otherwise the exact scenario
// the fix was written for reports "flagged 0" with no guide named, and the weekly
// routine's response rule never fires.
//
// The rescan walk only ever POSTs to /api/extension/ingest-offer, so in practice
// it sees the first eight; the ProductImportController pair is listed so this
// table is the single answer to "what can `action` be" for the whole extension.
// ─────────────────────────────────────────────────────────────
const RESCAN_ACTION_BUCKET = {
    refreshed: 'updated',
    created: 'updated',
    matched: 'updated',
    queued_new: 'updated',
    queued_rescan: 'updated',
    flagged_condition: 'flagged_condition',
    flagged_offer_condition: 'flagged_condition',
    skipped_condition: 'skipped',
    skipped_ignored: 'skipped',
};

// Actions that are a bad-listing signal on a pick and must therefore name the
// guide. Broader than the `flagged_condition` bucket by one: `skipped_condition`
// writes nothing (so it is not "flagged") but still means the owner is looking at
// a condition-marked listing on a live pick — the response rule keys off ANY
// bad-listing signal. `skipped_ignored` is NOT here: it reports a pre-existing
// ignore, not a new observation about the listing.
const RESCAN_GUIDE_NAMING_ACTIONS = [
    'flagged_condition',
    'flagged_offer_condition',
    'skipped_condition',
];

// 029B-B1: exactly ONE page-load listener + watchdog may be armed at a time, and
// both must be cancellable on pause/stop — module-level refs make that possible.
let rescanLoadListener = null;   // active chrome.tabs.onUpdated listener
let rescanWatchdog = null;       // active 60s page-load watchdog timer
// 029B-B1: generation counter. Bumped on every offer (re)start, on pause, and when
// an offer's advance is consumed — every async step in an offer's chain (load
// listener, watchdog, extraction delay/retry, POST completion) captures it and
// bails if it no longer matches, so a pause→resume mid-flight can never leave two
// chains alive for the same offer (no double-POST, no double-count, no skip).
let rescanAttempt = 0;

// Initialize from storage
chrome.storage.local.get(['env', 'extensionToken', 'tenantId', 'rescanRun'], (result) => {
    if (result.env && API_CONFIG[result.env]) {
        currentEnv = result.env;
        baseUrl = API_CONFIG[result.env];
    }
    EXTENSION_TOKEN = result.extensionToken || '';
    TENANT_ID = result.tenantId || '';

    // Restore an interrupted rescan run as PAUSED — the user resumes explicitly
    if (result.rescanRun && result.rescanRun.active) {
        rescanRun = {
            ...rescanRun,
            ...result.rescanRun,
            tabId: null,
            paused: true,
            pausedReason: result.rescanRun.pausedReason || 'worker_restart',
            // A run persisted by an older build has fewer counters than this one.
            // Merging over the full default set keeps every `results.x++` a number
            // instead of NaN after an upgrade mid-run.
            results: { ...emptyRescanResults(), ...(result.rescanRun.results || {}) },
        };
    }
});

// Sync token/tenant whenever popup saves new values
chrome.storage.onChanged.addListener((changes) => {
    if (changes.extensionToken) EXTENSION_TOKEN = changes.extensionToken.newValue || '';
    if (changes.tenantId) TENANT_ID = changes.tenantId.newValue || '';
});

// Listen for messages from Popup
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === "UPDATE_ENV") {
        if (request.env && API_CONFIG[request.env]) {
            currentEnv = request.env;
            baseUrl = API_CONFIG[request.env];
        }
        // Reload token and tenant in case they changed
        chrome.storage.local.get(['extensionToken', 'tenantId'], (r) => {
            EXTENSION_TOKEN = r.extensionToken || '';
            TENANT_ID = r.tenantId || '';
        });
        sendResponse({ success: true });
        return true;
    } else if (request.action === "START_RESCAN") {
        if (rescanRun.active) {
            sendResponse({ success: false, message: `A ${runLabel()} is already running.` });
            return true;
        }
        startRescan(request.offers, request.categoryId, request.scope, request.noCategoryCount);
        sendResponse({ success: true });
    } else if (request.action === "PAUSE_RESCAN") {
        pauseRescan('user');
        sendResponse({ success: true });
    } else if (request.action === "RESUME_RESCAN") {
        resumeRescan();
        sendResponse({ success: true });
    } else if (request.action === "STOP_RESCAN") {
        const results = { ...rescanRun.results };
        stopRescan();
        sendResponse({ success: true, results });
    } else if (request.action === "GET_STATUS") {
        sendResponse({
            rescan: {
                active: rescanRun.active,
                paused: rescanRun.paused,
                pausedReason: rescanRun.pausedReason,
                scope: rescanRun.scope,
                processed: rescanRun.index,
                total: rescanRun.offers.length,
                results: rescanRun.results,
            },
        });
    }
    return true; // Keep channel open for async responses if needed
});

// ─────────────────────────────────────────────────────────────
// Rescan Mode (Spec 029 B2) — walk stored offer URLs, re-extract full data +
// listing health, POST refresh payloads to /api/extension/ingest-offer with a
// 3–5s polite delay between URLs.
//
// 031-T2: the work list is either one category's offers (scope 'category') or
// every live pick across all guides (scope 'picks'). The walk itself does not
// branch on scope — popup.js decides which list to fetch, this file walks it.
// ─────────────────────────────────────────────────────────────

function startRescan(offers, categoryId, scope, noCategoryCount) {
    const results = emptyRescanResults();
    // Audit B3: rows the popup dropped before starting (no category_id — they could
    // only 422 at ingest-offer). Seeded here so the count survives the popup being
    // closed and still shows up in the end-of-run summary.
    results.no_category = Number(noCategoryCount) || 0;

    rescanRun = {
        active: true,
        paused: false,
        pausedReason: null,
        scope: scope === 'picks' ? 'picks' : 'category',
        categoryId: categoryId || null,
        offers: offers || [],
        index: 0,
        tabId: null,
        results,
    };
    persistRescan();
    processNextRescan();
}

// 031-T2: user-facing noun for the active run. Only affects wording — a picks run
// is the same walk over a different work list.
function runLabel(capitalised = false) {
    if (rescanRun.scope === 'picks') return capitalised ? 'Picks verification' : 'picks verification';
    return capitalised ? 'Rescan' : 'rescan';
}

// 031-T2: record which guide a flagged pick belongs to. Category-mode rows carry
// no `landing_page_slug`, so this is a no-op there — no branching required.
// Deliberately broader than `results.flagged_condition`: the owner's response rule
// keys off ANY bad-listing signal (condition verdict, high_price, unavailable).
function noteFlaggedGuide(offer) {
    const slug = offer && offer.landing_page_slug;
    if (!slug) return;
    if (!rescanRun.results.flagged_guides) rescanRun.results.flagged_guides = {};
    rescanRun.results.flagged_guides[slug] = (rescanRun.results.flagged_guides[slug] || 0) + 1;
}

// 029B-B1: cancel the armed page-load listener + watchdog together. Called on
// pause/stop, at the top of every processNextRescan(), and when a load settles —
// so re-arming always starts from a clean slate (never two listeners alive).
function clearRescanPageHandlers() {
    if (rescanLoadListener) {
        chrome.tabs.onUpdated.removeListener(rescanLoadListener);
        rescanLoadListener = null;
    }
    if (rescanWatchdog) { clearTimeout(rescanWatchdog); rescanWatchdog = null; }
}

function pauseRescan(reason) {
    if (!rescanRun.active) return;
    rescanRun.paused = true;
    rescanRun.pausedReason = reason;
    if (rescanTimer) { clearTimeout(rescanTimer); rescanTimer = null; }
    // 029B-B1: cancel the in-flight page-load listener + watchdog and invalidate
    // every async step of the current offer's chain — Resume restarts this SAME
    // offer from scratch, so nothing from the pre-pause attempt may tally/advance.
    clearRescanPageHandlers();
    rescanAttempt++;
    persistRescan();
    broadcastRescan(reason === 'captcha'
        ? "Paused — solve the captcha in the tab, then press Resume."
        : `${runLabel(true)} paused.`);
}

function resumeRescan() {
    if (!rescanRun.active || !rescanRun.paused) return;
    rescanRun.paused = false;
    rescanRun.pausedReason = null;
    persistRescan();
    broadcastRescan(`Resuming ${runLabel()}...`);
    processNextRescan();
}

function stopRescan() {
    rescanRun.active = false;
    rescanRun.paused = false;
    if (rescanTimer) { clearTimeout(rescanTimer); rescanTimer = null; }
    clearRescanPageHandlers();
    rescanAttempt++;
    closeRescanTab();
    chrome.storage.local.remove('rescanRun');
}

async function processNextRescan() {
    if (!rescanRun.active || rescanRun.paused) return;
    if (rescanTimer) { clearTimeout(rescanTimer); rescanTimer = null; }
    // 029B-B1: start this offer's attempt from a clean slate — any previously
    // armed listener/watchdog (e.g. from a pause mid-load) is cancelled first,
    // so exactly ONE listener + ONE watchdog exist for the current offer.
    clearRescanPageHandlers();
    const attempt = ++rescanAttempt;

    if (rescanRun.index >= rescanRun.offers.length) {
        finishRescan();
        return;
    }

    const offer = rescanRun.offers[rescanRun.index];

    // Security M3 — check BEFORE any chrome.tabs call. Both walk modes reach this
    // line, so one gate covers the category rescan and the unattended weekly picks
    // walk alike. A poisoned or simply stale row must never steer the owner's
    // browser off the four stores the extension can actually read.
    if (!isWalkableUrl(offer.url)) {
        console.warn('Rescan: refusing to navigate to off-allowlist URL', offer.url);
        rescanRun.results.blocked++;
        advanceRescan(attempt);
        return;
    }

    broadcastRescan(rescanRun.scope === 'picks'
        ? `Verifying pick ${rescanRun.index + 1}/${rescanRun.offers.length}...`
        : `Rescanning ${rescanRun.index + 1}/${rescanRun.offers.length}...`);

    try {
        // Reuse a single background worker tab; create it on first use
        if (rescanRun.tabId !== null) {
            try {
                await chrome.tabs.update(rescanRun.tabId, { url: offer.url, active: false });
            } catch (e) {
                rescanRun.tabId = null; // tab was closed — recreate below
            }
        }
        if (rescanRun.tabId === null) {
            const tab = await chrome.tabs.create({ url: offer.url, active: false });
            rescanRun.tabId = tab.id;
        }

        if (attempt !== rescanAttempt) return; // paused/stopped during tab navigation

        const targetTabId = rescanRun.tabId;

        rescanLoadListener = (tabId, info) => {
            if (attempt !== rescanAttempt) return; // stale — superseded by pause/advance
            if (tabId === targetTabId && info.status === 'complete') {
                clearRescanPageHandlers(); // consume listener + watchdog together
                // Let dynamic buy-box content render before extraction
                setTimeout(() => extractRescanData(targetTabId, offer, attempt), 3000);
            }
        };
        chrome.tabs.onUpdated.addListener(rescanLoadListener);

        // Watchdog: never let a hung page stall the whole run. Cancelled on
        // pause/settle (B1), and guarded so it can never fire for a stale attempt.
        rescanWatchdog = setTimeout(() => {
            if (attempt !== rescanAttempt || !rescanRun.active || rescanRun.paused) return;
            clearRescanPageHandlers();
            console.warn("Rescan: page load timed out", offer.url);
            rescanRun.results.errors++;
            advanceRescan(attempt);
        }, 60000);

    } catch (error) {
        console.error("Rescan: tab navigation failed:", error);
        if (attempt !== rescanAttempt) return;
        rescanRun.results.errors++;
        advanceRescan(attempt);
    }
}

function extractRescanData(tabId, offer, attempt, retry = 0) {
    if (!rescanRun.active || rescanRun.paused || attempt !== rescanAttempt) return;

    chrome.tabs.sendMessage(tabId, { action: "RESCAN_EXTRACT" }, (response) => {
        if (attempt !== rescanAttempt) return; // paused/stopped while extracting
        if (chrome.runtime.lastError || !response) {
            // Content script may not be ready — retry once after 2s
            if (retry === 0) {
                setTimeout(() => extractRescanData(tabId, offer, attempt, 1), 2000);
                return;
            }
            console.error("Rescan: extraction failed:", chrome.runtime.lastError, offer.url);
            rescanRun.results.errors++;
            advanceRescan(attempt);
            return;
        }

        if (response.robot) {
            // CAPTCHA — surface the tab and auto-pause; Resume retries this offer
            chrome.tabs.update(tabId, { active: true }).catch(() => { });
            pauseRescan('captcha');
            return;
        }

        handleRescanExtract(offer, response, attempt);
    });
}

async function handleRescanExtract(offer, response, attempt) {
    if (!rescanRun.active || rescanRun.paused || attempt !== rescanAttempt) return;

    const product = response.success ? response.product : null;

    if (!product) {
        rescanRun.results.errors++;
        advanceRescan(attempt);
        return;
    }
    if (!product.raw_title) {
        // Partial page — nothing trustworthy to refresh with. ("Currently
        // unavailable" pages are NOT this case anymore: extractProductPageData()
        // extracts them normally with stock_status 'out_of_stock' + the
        // 'unavailable' listing flag, and they POST like any other page.)
        rescanRun.results.skipped++;
        advanceRescan(attempt);
        return;
    }

    // Refresh payload — field names mirror OfferIngestionController::ingest()
    // validation. CRITICAL: `url` must be the DB-stored offer URL (from
    // rescan-list), NOT the tab's post-redirect URL — the server matches the
    // existing offer by exact URL.
    const payload = {
        url: offer.url,
        store_slug: product.store_slug,
        raw_title: product.raw_title,               // raw as scraped — heals cleaned-title rows
        brand: product.brand ?? null,
        scraped_price: product.scraped_price ?? null,
        image_url: product.image_url ?? null,
        stock_status: product.stock_status ?? null,
        rating: product.rating ?? null,
        reviews_count: product.reviews_count ?? null, // null = unknown, never 0
        // 031-T2: ingest-offer REQUIRES category_id. Category mode has one for the
        // whole run; a picks run spans categories, so each row carries its own.
        category_id: offer.category_id ?? rescanRun.categoryId,
    };
    // Amazon pages carry DOM-verified listing health (Spec 029 B1).
    // 029B-B3: 'unknown' means the page could NOT be verified — omit the keys
    // entirely (server treats absent as a true no-op), never report it as if it
    // were a health observation.
    if (product.condition && product.condition !== 'unknown') {
        payload.condition = product.condition;
        payload.listing_flags = product.listing_flags || [];
    }

    try {
        const res = await fetch(`${baseUrl}/api/extension/ingest-offer`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Extension-Token': EXTENSION_TOKEN,
                'X-Tenant-Id': TENANT_ID,
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        // 029B-B1: a pause/stop while the POST was in flight invalidates this
        // chain — Resume retries this SAME offer, so no tally and no advance here.
        if (attempt !== rescanAttempt) return;

        if (res.ok && data.success) {
            // Audit B4: table lookup, never an `else` fallthrough. An action this
            // build has not been taught is counted separately and logged, so a new
            // server-side action can no longer masquerade as a clean `updated`.
            const bucket = RESCAN_ACTION_BUCKET[data.action];
            if (bucket) {
                rescanRun.results[bucket]++;
            } else {
                console.warn('Rescan: unrecognised server action', data.action, offer.url);
                rescanRun.results.unknown_action++;
            }

            // 031-T2: name the guide behind a bad pick. Condition verdicts come from
            // the server's action; high_price/unavailable come from the DOM we just
            // read (the server stores those flags without changing `action`).
            // Read from `payload`, NOT `product`: on an unverified page (029B-B3
            // 'unknown' condition) the flags were deliberately not reported, so they
            // are not an observation and must not name a guide either.
            //
            // Audit B4: an unrecognised action names the guide too. If this build
            // cannot classify the verdict, the safe reading is "something happened
            // to this pick" — under-reporting is what made B4 invisible.
            const flags = Array.isArray(payload.listing_flags) ? payload.listing_flags : [];
            if (RESCAN_GUIDE_NAMING_ACTIONS.includes(data.action)
                || !bucket
                || flags.includes('high_price')
                || flags.includes('unavailable')) {
                noteFlaggedGuide(offer);
            }
        } else {
            console.error("Rescan: API rejected refresh:", data, offer.url);
            rescanRun.results.errors++;
        }
    } catch (error) {
        if (attempt !== rescanAttempt) return;
        console.error("Rescan: API upload failed:", error);
        rescanRun.results.errors++;
    }

    advanceRescan(attempt);
}

// 029B-B1: idempotent per offer — the attempt guard makes a second call for the
// same offer (stale watchdog, duplicate chain) a no-op, and consuming the attempt
// below closes the window until the next offer starts. Never advances while
// paused: Resume must retry the SAME offer.
function advanceRescan(attempt) {
    if (!rescanRun.active || rescanRun.paused) return;
    if (attempt !== rescanAttempt) return; // stale chain — this offer already advanced
    rescanAttempt++; // consume: any other callback still holding this attempt is now stale

    rescanRun.index++;
    persistRescan();
    broadcastRescan();

    // Polite randomized delay between URLs: 3–5s
    const delay = 3000 + Math.floor(Math.random() * 2001);
    rescanTimer = setTimeout(processNextRescan, delay);
}

function finishRescan() {
    const summary = { ...rescanRun.results };
    const total = rescanRun.offers.length;
    const scope = rescanRun.scope;
    rescanRun.active = false;
    rescanRun.paused = false;
    closeRescanTab();
    chrome.storage.local.remove('rescanRun');
    chrome.runtime.sendMessage({
        action: "RESCAN_PROGRESS",
        done: true,
        scope,
        processed: total,
        total: total,
        results: summary,
        paused: false,
    }).catch(() => { });
}

function closeRescanTab() {
    if (rescanRun.tabId) {
        chrome.tabs.remove(rescanRun.tabId).catch(() => { });
        rescanRun.tabId = null;
    }
}

function persistRescan() {
    const { tabId, ...persistable } = rescanRun;
    chrome.storage.local.set({ rescanRun: persistable });
}

function broadcastRescan(msg) {
    chrome.runtime.sendMessage({
        action: "RESCAN_PROGRESS",
        scope: rescanRun.scope,
        processed: rescanRun.index,
        total: rescanRun.offers.length,
        results: rescanRun.results,
        paused: rescanRun.paused,
        pausedReason: rescanRun.pausedReason,
        message: msg,
    }).catch(() => { }); // popup may be closed
}
