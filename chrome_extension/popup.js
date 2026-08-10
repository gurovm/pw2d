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

// Batch Mode Elements — the live SERP batch runs entirely in-popup.
// 029B-S1: the legacy background tab-walker (batchProgress/stop/resume/auto-next
// wiring) was deleted along with background.js's dead START_BATCH flow.
const scanPageBtn = document.getElementById('scanPageBtn');
const batchControls = document.getElementById('batchControls');
const foundCountSpan = document.getElementById('foundCount');
const startBatchBtn = document.getElementById('startBatchBtn');

// Rescan Mode Elements (Spec 029 B2)
const startRescanBtn = document.getElementById('startRescanBtn');
const rescanProgress = document.getElementById('rescanProgress');
const rescanCountSpan = document.getElementById('rescanCount');
const rescanSummarySpan = document.getElementById('rescanSummary');
const pauseRescanBtn = document.getElementById('pauseRescanBtn');
const resumeRescanBtn = document.getElementById('resumeRescanBtn');
const stopRescanBtn = document.getElementById('stopRescanBtn');

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

        const tenantInput = document.getElementById('tenantInput');
        if (tenantInput && TENANT_ID) {
            tenantInput.value = TENANT_ID;
        }

        if (result.env && API_CONFIG[result.env]) {
            currentEnv = result.env;
            baseUrl = API_CONFIG[result.env];
        }
        if (envSelect) envSelect.value = currentEnv;

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

            // Fetch categories for new environment
            categorySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
            await fetchCategories();
        });
    }

    // Check if a rescan is already running
    chrome.runtime.sendMessage({ action: "GET_STATUS" }, (response) => {
        if (response && response.rescan && response.rescan.active) {
            const r = response.rescan;
            showRescanProgress(r.processed, r.total, r.results, r.paused);
            if (r.paused) {
                statusDiv.textContent = r.pausedReason === 'captcha'
                    ? 'Rescan paused — solve the captcha in the tab, then press Resume.'
                    : 'Rescan paused. Press Resume to continue.';
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
        const rescanActive = await new Promise((resolve) => {
            chrome.runtime.sendMessage({ action: 'GET_STATUS' }, (res) =>
                resolve(!!(res && res.rescan && res.rescan.active)));
        });
        if (rescanActive) {
            showError('A rescan is running — stop it first.');
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
                let created = 0, matched = 0, refreshed = 0, flagged = 0, skipped = 0, failed = 0;

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
                            // 029B-S4: tally every action the server can return —
                            // unknown/future actions land in `skipped` so the x/y
                            // progress line always reaches the total.
                            if (data.action === 'created') created++;
                            else if (data.action === 'matched') matched++;
                            else if (data.action === 'refreshed') refreshed++;
                            else if (data.action === 'flagged_condition') flagged++;
                            else skipped++; // skipped_condition + any future action
                        } else { failed++; }
                    } catch { failed++; }

                    statusDiv.textContent = `Ingesting... ${created + matched + refreshed + flagged + skipped + failed}/${extractedProducts.length}`;
                }

                const parts = [];
                if (created > 0)   parts.push(`${created} new queued for AI`);
                if (matched > 0)   parts.push(`${matched} matched to existing`);
                if (refreshed > 0) parts.push(`${refreshed} prices refreshed`);
                if (flagged > 0)   parts.push(`${flagged} flagged for condition`);
                if (skipped > 0)   parts.push(`${skipped} skipped`);
                if (failed > 0)    parts.push(`${failed} failed`);
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

// ── Rescan Mode (Spec 029 B2) ────────────────────────────────

// Start Rescan — fetch the category's work list, hand it to background.js
if (startRescanBtn) {
    startRescanBtn.addEventListener('click', async () => {
        if (!selectedCategoryId) {
            showError('Please select a category first.');
            return;
        }

        // 029B-S1: batch↔rescan mutual exclusion — the live SERP batch runs in
        // this popup, so its in-flight flag is the authoritative batch state.
        if (serpBatchRunning) {
            showError('A batch import is running — wait for it to finish first.');
            return;
        }

        statusDiv.textContent = 'Fetching rescan list...';
        statusDiv.className = '';
        startRescanBtn.disabled = true;

        try {
            const res = await fetch(`${baseUrl}/api/extension/rescan-list?category_id=${selectedCategoryId}`, {
                headers: {
                    'X-Extension-Token': EXTENSION_TOKEN,
                    'X-Tenant-Id': TENANT_ID,
                },
            });

            if (res.status === 404) {
                showError('This server does not support rescan yet — deploy Spec 029 Phase A first.');
                startRescanBtn.disabled = false;
                return;
            }

            const data = await res.json();
            if (!res.ok || !data.success) {
                showError('API Error: ' + (data.message || data.error || `HTTP ${res.status}`));
                startRescanBtn.disabled = false;
                return;
            }

            const offers = data.offers || [];
            if (offers.length === 0) {
                showError('No offers to rescan in this category.');
                startRescanBtn.disabled = false;
                return;
            }

            chrome.runtime.sendMessage(
                { action: 'START_RESCAN', offers, categoryId: selectedCategoryId },
                (resp) => {
                    if (!resp || !resp.success) {
                        showError(resp?.message || 'Could not start rescan.');
                        startRescanBtn.disabled = false;
                        return;
                    }
                    showRescanProgress(0, offers.length, null, false);
                    statusDiv.textContent = `Rescanning ${offers.length} offer(s)...`;
                    statusDiv.className = '';
                }
            );
        } catch (e) {
            showError('Network Error: ' + e.message);
            startRescanBtn.disabled = false;
        }
    });
}

if (pauseRescanBtn) {
    pauseRescanBtn.addEventListener('click', () => {
        chrome.runtime.sendMessage({ action: 'PAUSE_RESCAN' }, () => {
            pauseRescanBtn.style.display = 'none';
            resumeRescanBtn.style.display = 'block';
            statusDiv.textContent = 'Rescan paused.';
            statusDiv.className = '';
        });
    });
}

if (resumeRescanBtn) {
    resumeRescanBtn.addEventListener('click', () => {
        chrome.runtime.sendMessage({ action: 'RESUME_RESCAN' }, () => {
            resumeRescanBtn.style.display = 'none';
            pauseRescanBtn.style.display = 'block';
            statusDiv.textContent = 'Resuming rescan...';
            statusDiv.className = '';
        });
    });
}

if (stopRescanBtn) {
    stopRescanBtn.addEventListener('click', () => {
        chrome.runtime.sendMessage({ action: 'STOP_RESCAN' }, (resp) => {
            resetRescanUI();
            statusDiv.textContent = 'Rescan stopped. ' + formatRescanResults(resp?.results);
            statusDiv.className = '';
        });
    });
}

function showRescanProgress(processed, total, results, paused) {
    rescanProgress.style.display = 'block';
    startRescanBtn.style.display = 'none';
    rescanCountSpan.textContent = `${processed}/${total}`;
    rescanSummarySpan.textContent = formatRescanResults(results);
    pauseRescanBtn.style.display = paused ? 'none' : 'block';
    resumeRescanBtn.style.display = paused ? 'block' : 'none';
}

function resetRescanUI() {
    rescanProgress.style.display = 'none';
    startRescanBtn.style.display = 'block';
    startRescanBtn.disabled = false;
}

function formatRescanResults(r) {
    if (!r) return '';
    return `updated ${r.updated} · flagged ${r.flagged_condition} · skipped ${r.skipped} · errors ${r.errors}`;
}

// Listen for Progress Updates from Background (rescan only — 029B-S1: the
// legacy walker's BATCH_PROGRESS channel is gone; the live batch reports inline)
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === "RESCAN_PROGRESS") {
        if (request.done) {
            resetRescanUI();
            statusDiv.textContent = 'Rescan complete: ' + formatRescanResults(request.results);
            statusDiv.className = 'success';
            return;
        }
        showRescanProgress(request.processed, request.total, request.results, request.paused);
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
        chrome.storage.local.set({ extensionToken: val }, () => {
            statusDiv.textContent = 'API token saved.';
            statusDiv.className = 'success';
            setTimeout(() => { if (statusDiv.textContent === 'API token saved.') statusDiv.textContent = ''; }, 3000);
        });
    });
}

// Save Tenant ID
const saveTenantBtn = document.getElementById('saveTenantBtn');
if (saveTenantBtn) {
    saveTenantBtn.addEventListener('click', () => {
        const tenantInput = document.getElementById('tenantInput');
        const val = tenantInput ? tenantInput.value.trim() : '';
        TENANT_ID = val;
        chrome.storage.local.set({ tenantId: val }, async () => {
            statusDiv.textContent = 'Tenant ID saved.';
            statusDiv.className = 'success';
            setTimeout(() => { if (statusDiv.textContent === 'Tenant ID saved.') statusDiv.textContent = ''; }, 3000);

            // Re-fetch categories for the new tenant
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

            if (product.unavailable) {
                showError('Product is currently unavailable — skipped.');
                return;
            }

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
                    const actionLabels = {
                        'created': 'Queued for AI',
                        'matched': 'Matched to existing product',
                        'refreshed': 'Price refreshed',
                        'queued_new': 'Queued for AI',
                        'queued_rescan': 'Price refreshed',
                        'flagged_condition': 'Flagged: non-new condition — product ignored',
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
