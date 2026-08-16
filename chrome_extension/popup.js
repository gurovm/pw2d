const API_CONFIG = {
    local: 'http://127.0.0.1:8003',
    production: 'https://pw2d.com'
};

let EXTENSION_TOKEN = ''; // Loaded from chrome.storage.local — set via popup settings
let TENANT_ID = ''; // Loaded from chrome.storage.local — set via popup settings

// State
let currentEnv = 'local';
let baseUrl = API_CONFIG.local;
let categories = [];
let selectedCategoryId = null;

// DOM Elements
const envSelect = document.getElementById('envSelect');
const categorySelect = document.getElementById('categorySelect');
const categorySearch = document.getElementById('categorySearch'); // New element
const scrapeBtn = document.getElementById('scrapeBtn');
const statusDiv = document.getElementById('status');

// Settings: tenant picker (v1.6). Replaced the free-text tenantInput +
// saveTenantBtn pair — the owner picks from the server's own list instead of
// looking exact ids up every time.
const tenantSelect = document.getElementById('tenantSelect');
const tenantHint = document.getElementById('tenantHint');

// Batch Mode Elements — the live SERP batch runs entirely in-popup.
// 029B-S1: the legacy background tab-walker (batchProgress/stop/resume/auto-next
// wiring) was deleted along with background.js's dead START_BATCH flow.
const scanPageBtn = document.getElementById('scanPageBtn');
const batchControls = document.getElementById('batchControls');
const foundCountSpan = document.getElementById('foundCount');
const startBatchBtn = document.getElementById('startBatchBtn');

// Rescan Mode Elements (Spec 029 B2; picks scope Spec 031 T2 shares this UI)
const startRescanBtn = document.getElementById('startRescanBtn');
const startPicksBtn = document.getElementById('startPicksBtn');
const rescanProgress = document.getElementById('rescanProgress');
const rescanLabelSpan = document.getElementById('rescanLabel');
const rescanCountSpan = document.getElementById('rescanCount');
const rescanSummarySpan = document.getElementById('rescanSummary');
const rescanGuidesSpan = document.getElementById('rescanGuides');
const pauseRescanBtn = document.getElementById('pauseRescanBtn');
const resumeRescanBtn = document.getElementById('resumeRescanBtn');
const stopRescanBtn = document.getElementById('stopRescanBtn');

// Server action → counter name for the in-popup SERP batch loop (audit B4).
// Same nine actions and the same bucketing as background.js's
// RESCAN_ACTION_BUCKET — see that table for the full rationale. Kept as a second
// literal rather than shared because popup.js and background.js are separate
// non-module scripts; if a third copy is ever needed, make them ES modules first.
const SERP_ACTION_BUCKET = {
    created: 'created',
    matched: 'matched',
    refreshed: 'refreshed',
    queued_new: 'created',
    queued_rescan: 'refreshed',
    flagged_condition: 'flagged',
    flagged_offer_condition: 'flagged',
    skipped_condition: 'skipped',
    skipped_ignored: 'skipped',
};

let scannedUrls = [];
let scannedNextPageUrl = null;
let extractedProducts = []; // Bulk SERP extraction results
// 029B-S1: batch↔rescan mutual exclusion. The in-popup batch loop only exists
// while this popup is open, so this local flag is authoritative for "a batch is
// running"; rescan state lives in background.js and is read via GET_STATUS.
let serpBatchRunning = false;

// Initialize
document.addEventListener('DOMContentLoaded', async () => {
    // Load saved token, tenant ID, and environment
    chrome.storage.local.get(['extensionToken', 'tenantId', 'env'], async (result) => {
        EXTENSION_TOKEN = result.extensionToken || '';
        TENANT_ID = result.tenantId || '';

        // Populate the settings inputs if they exist
        const tokenInput = document.getElementById('tokenInput');
        if (tokenInput && EXTENSION_TOKEN) {
            tokenInput.value = EXTENSION_TOKEN;
        }

        if (result.env && API_CONFIG[result.env]) {
            currentEnv = result.env;
            baseUrl = API_CONFIG[result.env];
        }
        if (envSelect) envSelect.value = currentEnv;

        // Tenant list first: it only reads TENANT_ID, never writes it, so the
        // category fetch below still uses whatever was already saved even if
        // this call fails outright.
        await fetchTenants();
        await fetchCategories();
        loadSavedCategory();
    });

    // Handle Environment Change
    if (envSelect) {
        envSelect.addEventListener('change', async (e) => {
            currentEnv = e.target.value;
            baseUrl = API_CONFIG[currentEnv];
            chrome.storage.local.set({ env: currentEnv });

            // Notify background script
            chrome.runtime.sendMessage({
                action: "UPDATE_ENV",
                env: currentEnv
            });

            statusDiv.textContent = `Switched to ${currentEnv === 'local' ? 'Local' : 'Production'}.`;
            statusDiv.className = 'success';
            setTimeout(() => { if (statusDiv.textContent.includes('Switched')) statusDiv.textContent = ''; }, 3000);

            // Fetch tenants + categories for new environment (each env has its
            // own tenant table, so the list must be re-read, not reused).
            await fetchTenants();
            categorySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
            await fetchCategories();
        });
    }

    // Check if a rescan is already running
    chrome.runtime.sendMessage({ action: "GET_STATUS" }, (response) => {
        if (response && response.rescan && response.rescan.active) {
            const r = response.rescan;
            showRescanProgress(r.processed, r.total, r.results, r.paused, r.scope);
            if (r.paused) {
                const noun = r.scope === 'picks' ? 'Picks verification' : 'Rescan';
                statusDiv.textContent = r.pausedReason === 'captcha'
                    ? `${noun} paused — solve the captcha in the tab, then press Resume.`
                    : `${noun} paused. Press Resume to continue.`;
                statusDiv.className = r.pausedReason === 'captcha' ? 'error' : '';
            }
        }
    });
});

// Scan Page Button — extracts full product data directly from current SERP
if (scanPageBtn) {
    scanPageBtn.addEventListener('click', async () => {
        statusDiv.textContent = 'Scanning page...';
        statusDiv.className = '';
        extractedProducts = [];

        try {
            const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
            if (!tab) return;

            // Detect domain and use appropriate extraction action
            const tabHost = new URL(tab.url).hostname.replace(/^www\./, '');
            const isAmazon = tabHost.includes('amazon.');
            const extractAction = isAmazon ? 'EXTRACT_SERP_PRODUCTS' : 'EXTRACT_STORE_LISTING';

            chrome.tabs.sendMessage(tab.id, { action: extractAction }, (res) => {
                if (chrome.runtime.lastError || !res || !res.success) {
                    showError('Could not scan page. Make sure you are on a supported store\'s product listing page.');
                    return;
                }

                extractedProducts = res.products || [];
                foundCountSpan.textContent = extractedProducts.length;
                batchControls.style.display = 'block';
                statusDiv.textContent = '';
                startBatchBtn.disabled = extractedProducts.length === 0;

                if (extractedProducts.length === 0) {
                    showError('No products found on this page.');
                }
            });
        } catch (e) {
            showError('Error: ' + e.message);
        }
    });
}

// Send to Server Button — checks existing ASINs for display, then POSTs ALL products to batch-import.
// The backend handles new vs existing: new ones are queued for AI, existing ones get a data refresh.
if (startBatchBtn) {
    startBatchBtn.addEventListener('click', async () => {
        if (!selectedCategoryId) {
            showError('Please select a category first.');
            return;
        }
        if (!extractedProducts.length) return;

        // 029B-S1: batch↔rescan mutual exclusion — never run the live SERP batch
        // while a rescan walk is driving the API (and possibly this same server).
        // 031-T2: a picks run is the same background run object, so this one check
        // covers both modes; the message just names the one that is running.
        const runState = await new Promise((resolve) => {
            chrome.runtime.sendMessage({ action: 'GET_STATUS' }, (res) =>
                resolve(res && res.rescan && res.rescan.active ? res.rescan : null));
        });
        if (runState) {
            showError(runState.scope === 'picks'
                ? 'Picks verification is running — stop it first.'
                : 'A rescan is running — stop it first.');
            return;
        }

        serpBatchRunning = true;
        statusDiv.textContent = 'Checking existing products...';
        statusDiv.className = '';
        startBatchBtn.disabled = true;

        try {
            // Fetch already-imported ASINs for display purposes only (not for filtering)
            const asinRes = await fetch(`${baseUrl}/api/existing-asins?category_id=${selectedCategoryId}`, {
                headers: {
                    'X-Extension-Token': EXTENSION_TOKEN,
                    'X-Tenant-Id': TENANT_ID,
                },
            });
            // Detect if these are Amazon products (have ASIN) or store products
            const isAmazonBatch = extractedProducts.some(p => p.asin);

            if (isAmazonBatch) {
                // Amazon flow: existing ASIN check + batch-import
                const asinData = await asinRes.json();
                const existingAsins = (asinData.success && asinData.asins) ? asinData.asins : [];
                const existingCount = extractedProducts.filter(p => existingAsins.includes(p.asin)).length;

                statusDiv.textContent = `Sending ${extractedProducts.length} products (${existingCount} will be refreshed)...`;

                const batchRes = await fetch(`${baseUrl}/api/products/batch-import`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Extension-Token': EXTENSION_TOKEN,
                        'X-Tenant-Id': TENANT_ID,
                    },
                    body: JSON.stringify({
                        category_id: selectedCategoryId,
                        products: extractedProducts,
                    }),
                });

                const result = await batchRes.json();

                if (batchRes.ok && result.success) {
                    const parts = [];
                    if (result.created > 0)   parts.push(`${result.created} new queued for AI`);
                    if (result.refreshed > 0) parts.push(`${result.refreshed} refreshed`);
                    statusDiv.textContent = parts.join(', ') + '. Done!';
                    statusDiv.className = 'success';
                    batchControls.style.display = 'none';
                    extractedProducts = [];
                } else {
                    showError('API Error: ' + (result.message || 'Unknown error'));
                    startBatchBtn.disabled = false;
                }
            } else {
                // Non-Amazon flow: send each product to ingest-offer API
                statusDiv.textContent = `Ingesting ${extractedProducts.length} products...`;
                const tally = { created: 0, matched: 0, refreshed: 0, flagged: 0, skipped: 0, unknown: 0, failed: 0 };

                for (const product of extractedProducts) {
                    try {
                        const res = await fetch(`${baseUrl}/api/extension/ingest-offer`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Extension-Token': EXTENSION_TOKEN,
                                'X-Tenant-Id': TENANT_ID,
                            },
                            body: JSON.stringify({
                                ...product,
                                category_id: selectedCategoryId,
                            }),
                        });
                        const data = await res.json();
                        if (data.success) {
                            // 029B-S4 / audit B4: every action is bucketed by table.
                            // An unrecognised one lands in its OWN `unknown` bucket —
                            // it used to fall into `skipped`, which is how
                            // `flagged_offer_condition` went unnoticed. The x/y
                            // progress line still always reaches the total.
                            const bucket = SERP_ACTION_BUCKET[data.action];
                            if (bucket) {
                                tally[bucket]++;
                            } else {
                                console.warn('Batch: unrecognised server action', data.action);
                                tally.unknown++;
                            }
                        } else { tally.failed++; }
                    } catch { tally.failed++; }

                    const done = Object.values(tally).reduce((a, b) => a + b, 0);
                    statusDiv.textContent = `Ingesting... ${done}/${extractedProducts.length}`;
                }

                const parts = [];
                if (tally.created > 0)   parts.push(`${tally.created} new queued for AI`);
                if (tally.matched > 0)   parts.push(`${tally.matched} matched to existing`);
                if (tally.refreshed > 0) parts.push(`${tally.refreshed} prices refreshed`);
                if (tally.flagged > 0)   parts.push(`${tally.flagged} flagged for condition`);
                if (tally.skipped > 0)   parts.push(`${tally.skipped} skipped`);
                if (tally.unknown > 0)   parts.push(`${tally.unknown} unknown action`);
                if (tally.failed > 0)    parts.push(`${tally.failed} failed`);
                statusDiv.textContent = parts.join(', ') + '. Done!';
                statusDiv.className = 'success';
                batchControls.style.display = 'none';
                extractedProducts = [];
            }

        } catch (e) {
            showError('Network Error: ' + e.message);
            startBatchBtn.disabled = false;
        } finally {
            serpBatchRunning = false; // 029B-S1: release the mutual-exclusion guard
        }
    });
}

// ── Rescan Mode (Spec 029 B2) + Picks Mode (Spec 031 T2) ─────
//
// 031-T2: both modes are the SAME flow — fetch a work list from
// /api/extension/rescan-list, hand it to background.js, watch the shared progress
// UI. Only the query string, the wording, and (for picks) a category_id preflight
// differ, so this is parameterised by `scope` rather than forked.

async function beginRescanRun(scope) {
    const picks = scope === 'picks';
    const startBtn = picks ? startPicksBtn : startRescanBtn;

    // Picks mode is category-wide by design — no selector to satisfy.
    if (!picks && !selectedCategoryId) {
        showError('Please select a category first.');
        return;
    }

    // 029B-S1: batch↔rescan mutual exclusion — the live SERP batch runs in
    // this popup, so its in-flight flag is the authoritative batch state.
    if (serpBatchRunning) {
        showError('A batch import is running — wait for it to finish first.');
        return;
    }

    statusDiv.textContent = picks ? 'Fetching live picks...' : 'Fetching rescan list...';
    statusDiv.className = '';
    startBtn.disabled = true;

    const query = picks ? 'scope=picks' : `category_id=${encodeURIComponent(selectedCategoryId)}`;

    try {
        const res = await fetch(`${baseUrl}/api/extension/rescan-list?${query}`, {
            headers: {
                'X-Extension-Token': EXTENSION_TOKEN,
                'X-Tenant-Id': TENANT_ID,
            },
        });

        if (res.status === 404) {
            showError(picks
                ? 'This server does not support picks mode yet — deploy Spec 031 first.'
                : 'This server does not support rescan yet — deploy Spec 029 Phase A first.');
            startBtn.disabled = false;
            return;
        }

        const data = await res.json();
        if (!res.ok || !data.success) {
            showError('API Error: ' + (data.message || data.error || `HTTP ${res.status}`));
            startBtn.disabled = false;
            return;
        }

        const offers = data.offers || [];
        if (offers.length === 0) {
            showError(picks ? 'No live picks to verify.' : 'No offers to rescan in this category.');
            startBtn.disabled = false;
            return;
        }

        // 031-T2 preflight, revised per the 2026-08-16 audit (B3).
        //
        // /api/extension/ingest-offer REQUIRES category_id, and a picks run spans
        // categories, so a row without one could only 422. But a pick whose product
        // was detached by an AI sweep legitimately has `category_id: null`, and that
        // is a first-class staleness reason this very pass exists to catch — so ONE
        // such row must not cost the owner the whole ~100-offer weekly walk.
        //
        // Skip those rows, count them, report them; abort only when NOTHING is
        // usable, which is the genuinely-outdated-server case the check was written
        // for. Correct whether or not the server also stops emitting such rows.
        let workList = offers;
        let noCategoryCount = 0;

        if (picks) {
            workList = offers.filter((o) => o.category_id);
            noCategoryCount = offers.length - workList.length;

            if (workList.length === 0) {
                showError(`Server returned ${offers.length} pick(s), none with a category_id — the picks list must include it. Update the server first.`);
                startBtn.disabled = false;
                return;
            }
        }

        chrome.runtime.sendMessage(
            {
                action: 'START_RESCAN',
                offers: workList,
                scope,
                categoryId: picks ? null : selectedCategoryId,
                noCategoryCount,
            },
            (resp) => {
                if (!resp || !resp.success) {
                    showError(resp?.message || (picks ? 'Could not start picks verification.' : 'Could not start rescan.'));
                    startBtn.disabled = false;
                    return;
                }
                showRescanProgress(0, workList.length, null, false, scope);
                const skipNote = noCategoryCount > 0
                    ? ` (${noCategoryCount} skipped: no category)`
                    : '';
                statusDiv.textContent = picks
                    ? `Verifying ${workList.length} live pick(s) across all guides${skipNote}...`
                    : `Rescanning ${workList.length} offer(s)...`;
                statusDiv.className = '';
            }
        );
    } catch (e) {
        showError('Network Error: ' + e.message);
        startBtn.disabled = false;
    }
}

if (startRescanBtn) {
    startRescanBtn.addEventListener('click', () => beginRescanRun('category'));
}

if (startPicksBtn) {
    startPicksBtn.addEventListener('click', () => beginRescanRun('picks'));
}

if (pauseRescanBtn) {
    pauseRescanBtn.addEventListener('click', () => {
        chrome.runtime.sendMessage({ action: 'PAUSE_RESCAN' }, () => {
            pauseRescanBtn.style.display = 'none';
            resumeRescanBtn.style.display = 'block';
            statusDiv.textContent = 'Paused.';
            statusDiv.className = '';
        });
    });
}

if (resumeRescanBtn) {
    resumeRescanBtn.addEventListener('click', () => {
        chrome.runtime.sendMessage({ action: 'RESUME_RESCAN' }, () => {
            resumeRescanBtn.style.display = 'none';
            pauseRescanBtn.style.display = 'block';
            statusDiv.textContent = 'Resuming...';
            statusDiv.className = '';
        });
    });
}

if (stopRescanBtn) {
    stopRescanBtn.addEventListener('click', () => {
        chrome.runtime.sendMessage({ action: 'STOP_RESCAN' }, (resp) => {
            resetRescanUI();
            const guides = formatFlaggedGuides(resp?.results);
            statusDiv.textContent = 'Stopped. ' + formatRescanResults(resp?.results)
                + (guides ? ' — ' + guides : '');
            statusDiv.className = guides ? 'warn' : '';
        });
    });
}

// 031-T2: one progress panel for both modes — `scope` only re-labels it, and BOTH
// start buttons hide while any run is active (a second run can never be started).
function showRescanProgress(processed, total, results, paused, scope) {
    rescanProgress.style.display = 'block';
    startRescanBtn.style.display = 'none';
    if (startPicksBtn) startPicksBtn.style.display = 'none';
    if (rescanLabelSpan) rescanLabelSpan.textContent = scope === 'picks' ? 'Verifying picks' : 'Rescanning';
    rescanCountSpan.textContent = `${processed}/${total}`;
    rescanSummarySpan.textContent = formatRescanResults(results);
    if (rescanGuidesSpan) rescanGuidesSpan.textContent = formatFlaggedGuides(results);
    pauseRescanBtn.style.display = paused ? 'none' : 'block';
    resumeRescanBtn.style.display = paused ? 'block' : 'none';
}

function resetRescanUI() {
    rescanProgress.style.display = 'none';
    if (rescanGuidesSpan) rescanGuidesSpan.textContent = '';
    startRescanBtn.style.display = 'block';
    startRescanBtn.disabled = false;
    if (startPicksBtn) {
        startPicksBtn.style.display = 'block';
        startPicksBtn.disabled = false;
    }
}

// The four core buckets always render (a zero is information: "flagged 0" is the
// line the owner reads to decide no follow-up is needed, so it must be trustworthy
// — see audit B4). The exception buckets render only when non-zero, so an ordinary
// clean run keeps the one-line shape it has always had.
function formatRescanResults(r) {
    if (!r) return '';

    const parts = [
        `updated ${r.updated ?? 0}`,
        `flagged ${r.flagged_condition ?? 0}`,
        `skipped ${r.skipped ?? 0}`,
        `errors ${r.errors ?? 0}`,
    ];

    // Audit B3 — detached picks dropped before the walk started.
    if (r.no_category > 0) parts.push(`${r.no_category} skipped: no category`);
    // Security M3 — rows refused because their URL is not one of the four stores.
    if (r.blocked > 0) parts.push(`${r.blocked} blocked: bad URL`);
    // Audit B4 — the server answered with an action this build does not know.
    if (r.unknown_action > 0) parts.push(`${r.unknown_action} unknown action`);

    return parts.join(' · ');
}

// 031-T2: end-of-run line for either mode, with any flagged guides appended.
function runCompleteText(scope, results) {
    const head = scope === 'picks' ? 'Picks verification complete: ' : 'Rescan complete: ';
    const guides = formatFlaggedGuides(results);
    return head + formatRescanResults(results) + (guides ? ' — ' + guides : '');
}

// 031-T2: name the guides behind flagged picks — the owner's next action is a full
// rescan of exactly those categories, so the slug is the actionable part.
function formatFlaggedGuides(r) {
    const guides = r && r.flagged_guides;
    if (!guides) return '';
    const slugs = Object.keys(guides);
    if (slugs.length === 0) return '';
    return 'flagged: ' + slugs.map((s) => (guides[s] > 1 ? `${s} (${guides[s]})` : s)).join(', ');
}

// Listen for Progress Updates from Background (rescan only — 029B-S1: the
// legacy walker's BATCH_PROGRESS channel is gone; the live batch reports inline)
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === "RESCAN_PROGRESS") {
        if (request.done) {
            resetRescanUI();
            statusDiv.textContent = runCompleteText(request.scope, request.results);
            // Flagged picks are not a clean "success" — they demand a full rescan
            // of the named guide (Spec 031 response rule).
            statusDiv.className = formatFlaggedGuides(request.results) ? 'warn' : 'success';
            return;
        }
        showRescanProgress(request.processed, request.total, request.results, request.paused, request.scope);
        if (request.message) {
            statusDiv.textContent = request.message;
            statusDiv.className = (request.paused && request.pausedReason === 'captcha') ? 'error' : '';
        }
    }
});

// Search Listener
if (categorySearch) {
    // ... existing search listener logic ...
    categorySearch.addEventListener('input', (e) => {
        const term = e.target.value.toLowerCase();
        const filtered = categories.filter(cat =>
            cat.name.toLowerCase().includes(term) ||
            (cat.slug && cat.slug.toLowerCase().includes(term))
        );
        populateCategorySelect(filtered);
    });
}

// Fetch Categories
async function fetchCategories() {
    try {
        const response = await fetch(`${baseUrl}/api/categories`, {
            headers: {
                'X-Extension-Token': EXTENSION_TOKEN,
                'X-Tenant-Id': TENANT_ID,
            },
        });
        const data = await response.json();

        if (data.success && data.categories) {
            categories = data.categories;
            populateCategorySelect(categories);
        } else {
            console.error('Categories API response:', response.status, data);
            showError(data.error || 'Failed to load categories.');
        }
    } catch (error) {
        showError('Could not connect to PW2D API. Is the server running?');
        console.error(error);
    }
}

// Populate Select
function populateCategorySelect(cats) {
    categorySelect.innerHTML = '<option value="" disabled selected>Select a Category...</option>';

    if (!cats || cats.length === 0) {
        const option = document.createElement('option');
        option.text = "No categories found";
        option.disabled = true;
        categorySelect.appendChild(option);
    } else {
        cats.forEach(cat => {
            const option = document.createElement('option');
            option.value = cat.id;
            // Highlight match if needed, but simple text is fine
            option.textContent = cat.name + (cat.features_count ? ` (${cat.features_count} features)` : '');
            categorySelect.appendChild(option);
        });
    }

    // Restore selection if it exists in the current list
    if (selectedCategoryId) {
        categorySelect.value = selectedCategoryId;
    }

    categorySelect.disabled = false;
}

// Load Saved Category from Storage
function loadSavedCategory() {
    chrome.storage.local.get(['lastCategoryId'], (result) => {
        if (result.lastCategoryId) {
            selectedCategoryId = result.lastCategoryId;
            categorySelect.value = selectedCategoryId;

            // If category exists in list, enable button
            if (categorySelect.value) {
                scrapeBtn.disabled = false;
            }
        }
    });
}

// Handle Selection Change
categorySelect.addEventListener('change', (e) => {
    selectedCategoryId = e.target.value;
    scrapeBtn.disabled = !selectedCategoryId;

    // Save to storage
    chrome.storage.local.set({ lastCategoryId: selectedCategoryId });
});

// Helper: Show Error
function showError(msg) {
    statusDiv.textContent = msg;
    statusDiv.className = 'error';
}

// Settings Toggle
const settingsToggle = document.getElementById('settingsToggle');
const settingsPanel = document.getElementById('settingsPanel');
if (settingsToggle && settingsPanel) {
    settingsToggle.addEventListener('click', () => {
        const isHidden = settingsPanel.style.display === 'none';
        settingsPanel.style.display = isHidden ? 'block' : 'none';
    });
}

// Save Token
const saveTokenBtn = document.getElementById('saveTokenBtn');
if (saveTokenBtn) {
    saveTokenBtn.addEventListener('click', () => {
        const tokenInput = document.getElementById('tokenInput');
        const val = tokenInput ? tokenInput.value.trim() : '';
        EXTENSION_TOKEN = val;
        chrome.storage.local.set({ extensionToken: val }, async () => {
            statusDiv.textContent = 'API token saved.';
            statusDiv.className = 'success';
            setTimeout(() => { if (statusDiv.textContent === 'API token saved.') statusDiv.textContent = ''; }, 3000);

            // The tenant list 403s until a valid token exists, so the popup-open
            // attempt has usually already failed by now — retry it here.
            await fetchTenants();
        });
    });
}

// ── Tenant picker (v1.6) ─────────────────────────────────────────────────────
// Replaces the free-text Tenant ID input. TENANT_ID stays the single source of
// truth for every X-Tenant-Id header in this file; the picker only ever assigns
// to it from an explicit user choice.
//
// Hard rule throughout: a failed, empty or forbidden fetch NEVER clears the
// saved tenantId. Losing the saved id would silently break every scrape while
// offline or against an older server, which is strictly worse than showing a
// stale list.

/**
 * Render the select from a (possibly empty) tenant list, preserving TENANT_ID.
 * @param {Array<{id: string, name: string}>} tenants
 * @param {string} hint Inline message under the select; '' clears it.
 */
function renderTenantOptions(tenants, hint) {
    if (!tenantSelect) return;

    tenantSelect.innerHTML = '';

    const rows = Array.isArray(tenants) ? tenants.filter(t => t && t.id) : [];
    const savedIsKnown = rows.some(t => String(t.id) === TENANT_ID);

    if (!TENANT_ID) {
        // Deliberately no auto-select of the first tenant: silently pointing
        // imports at an arbitrary tenant is the one failure this UI must not
        // introduce. Make the owner choose once.
        const placeholder = new Option('Select a tenant...', '');
        placeholder.disabled = true;
        placeholder.selected = true;
        tenantSelect.add(placeholder);
    } else if (!savedIsKnown) {
        // Saved id the server did not return — fetch failed, wrong environment,
        // or the tenant was deleted. Keep it listed AND selected so the popup
        // still reflects what the headers actually send.
        tenantSelect.add(new Option(`${TENANT_ID} (saved)`, TENANT_ID, true, true));
    }

    // new Option() sets text as a text node, so tenant names cannot inject markup.
    rows.forEach((tenant) => {
        const id = String(tenant.id);
        const label = tenant.name && tenant.name !== id ? `${tenant.name} (${id})` : id;
        tenantSelect.add(new Option(label, id, false, id === TENANT_ID));
    });

    if (tenantHint) tenantHint.textContent = hint || '';
}

/**
 * Load the tenant directory from the server. Quiet by design — before a token
 * is saved this call is expected to fail, and that is a normal first-run state,
 * not an error worth shouting about in the shared status line.
 */
async function fetchTenants() {
    if (!tenantSelect) return;

    if (!EXTENSION_TOKEN) {
        renderTenantOptions([], 'Save your API token below to load the tenant list.');
        return;
    }

    tenantSelect.innerHTML = '<option value="" disabled selected>Loading tenants...</option>';
    if (tenantHint) tenantHint.textContent = '';

    try {
        // Token only — no X-Tenant-Id: this endpoint exists to discover ids
        // before one is chosen, and is registered outside the tenancy middleware.
        const response = await fetch(`${baseUrl}/api/extension/tenants`, {
            headers: { 'X-Extension-Token': EXTENSION_TOKEN }
        });

        if (!response.ok) {
            renderTenantOptions([], response.status === 403
                ? 'Tenant list unavailable — check your API token.'
                : `Tenant list unavailable (HTTP ${response.status}). Saved tenant still in use.`);
            return;
        }

        const data = await response.json();
        const tenants = Array.isArray(data.tenants) ? data.tenants : [];

        renderTenantOptions(tenants, tenants.length ? '' : 'No tenants returned by this environment.');
    } catch (error) {
        // Offline, wrong env, or a server predating this route.
        renderTenantOptions([], 'Tenant list unavailable — server unreachable. Saved tenant still in use.');
    }
}

if (tenantSelect) {
    tenantSelect.addEventListener('change', () => {
        const val = tenantSelect.value;
        if (!val || val === TENANT_ID) return;

        TENANT_ID = val;
        chrome.storage.local.set({ tenantId: val }, async () => {
            const msg = `Tenant set to ${val}.`;
            statusDiv.textContent = msg;
            statusDiv.className = 'success';
            setTimeout(() => { if (statusDiv.textContent === msg) statusDiv.textContent = ''; }, 3000);

            // Categories are tenant-scoped — the old list belongs to the old tenant.
            categorySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
            await fetchCategories();
        });
    });
}

// Import Single Product — domain-aware extraction.
// Amazon product pages use the batch-import API (ASIN-based flow).
// Non-Amazon stores use the universal ingest-offer API (AI matching flow).
scrapeBtn.addEventListener('click', async () => {
    if (!selectedCategoryId) {
        showError('Please select a category first.');
        return;
    }

    statusDiv.textContent = 'Extracting product data...';
    statusDiv.className = '';

    try {
        const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
        if (!tab) { showError('No active tab found.'); return; }

        // Use the domain-aware extractor
        chrome.tabs.sendMessage(tab.id, { action: 'EXTRACT_STORE_PRODUCT' }, async (response) => {
            if (chrome.runtime.lastError) {
                showError('Error: ' + chrome.runtime.lastError.message + '. Try refreshing the page.');
                return;
            }

            if (!response?.success || !response.product) {
                showError(response?.error || 'Could not extract product data from this page.');
                return;
            }

            const product = response.product;

            statusDiv.textContent = `Sending "${product.raw_title?.substring(0, 40)}..." to PW2D...`;

            try {
                let apiResponse;

                if (product.store_slug === 'amazon' && product.asin) {
                    // Amazon: use existing batch-import (ASIN dedup)
                    apiResponse = await fetch(`${baseUrl}/api/products/batch-import`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Extension-Token': EXTENSION_TOKEN,
                            'X-Tenant-Id': TENANT_ID,
                        },
                        body: JSON.stringify({
                            category_id: selectedCategoryId,
                            products: [{
                                asin: product.asin || getAsinFromUrl(product.url),
                                title: product.raw_title,
                                price: product.scraped_price,
                                rating: product.rating,
                                reviews_count: product.reviews_count,
                                image_url: product.image_url,
                                // 029B-S2: extractAmazonProduct() supplies stock_status —
                                // without it the A1/F38 stock refresh never fires here.
                                stock_status: product.stock_status ?? null,
                                // Spec 029 B1: DOM-verified listing health.
                                // 029B-B3: 'unknown' = unverified page — omit the keys
                                // entirely (absent is the server's documented no-op).
                                ...(product.condition && product.condition !== 'unknown'
                                    ? {
                                        condition: product.condition,
                                        listing_flags: product.listing_flags ?? [],
                                    }
                                    : {}),
                            }],
                        })
                    });
                } else {
                    // Non-Amazon: use universal ingest-offer API (AI matching).
                    // 029B-B3: strip an 'unknown' condition (and its flags) so an
                    // unverified page stays a true server-side no-op on this path too.
                    const offerPayload = { ...product, category_id: selectedCategoryId };
                    if (offerPayload.condition === 'unknown') {
                        delete offerPayload.condition;
                        delete offerPayload.listing_flags;
                    }
                    apiResponse = await fetch(`${baseUrl}/api/extension/ingest-offer`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Extension-Token': EXTENSION_TOKEN,
                            'X-Tenant-Id': TENANT_ID,
                        },
                        body: JSON.stringify(offerPayload)
                    });
                }

                const result = await apiResponse.json();

                if (apiResponse.ok && result.success) {
                    // Audit B4/N1: one label per action string the server can
                    // return — the same nine covered by background.js's
                    // RESCAN_ACTION_BUCKET table. An unlisted action falls back to
                    // the raw string below, which is ugly on purpose: it is visibly
                    // wrong rather than quietly mislabelled.
                    const actionLabels = {
                        'created': 'Queued for AI',
                        'matched': 'Matched to existing product',
                        'refreshed': 'Price refreshed',
                        'queued_new': 'Queued for AI',
                        'queued_rescan': 'Price refreshed',
                        'flagged_condition': 'Flagged: non-new condition — product ignored',
                        'flagged_offer_condition': 'Flagged: non-new condition on this listing — offer excluded, product kept (another store is clean)',
                        'skipped_condition': 'Skipped: condition-marked listing',
                        'skipped_ignored': 'Skipped: product is ignored — un-ignore in Filament first',
                    };
                    // 029B-S4: only read `action` from responses that carry it — the
                    // Amazon batch-import path answers with {created, refreshed,
                    // skipped, flagged} counters instead (flagged/skipped first: they
                    // overlap created/refreshed and are the more telling outcome).
                    let action;
                    if (result.action) {
                        action = actionLabels[result.action] || result.action;
                    } else if (result.flagged > 0) {
                        action = actionLabels.flagged_condition;
                    } else if (result.skipped > 0) {
                        action = actionLabels.skipped_condition;
                    } else if (result.created > 0) {
                        action = actionLabels.created;
                    } else {
                        action = actionLabels.refreshed;
                    }
                    statusDiv.textContent = `${action}: ${product.raw_title?.substring(0, 50)}`;
                    statusDiv.className = 'success';
                } else {
                    showError('API Error: ' + (result.message || result.error || 'Unknown error'));
                }
            } catch (error) {
                showError('Network Error: Is the server running?');
                console.error(error);
            }
        });
    } catch (err) {
        showError('Error: ' + err.message);
    }
});

function getAsinFromUrl(url) {
    const m = url?.match(/\/dp\/([A-Z0-9]{10})/);
    return m ? m[1] : null;
}
