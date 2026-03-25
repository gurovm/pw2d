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

// Batch Mode Elements
const scanPageBtn = document.getElementById('scanPageBtn');
const batchControls = document.getElementById('batchControls');
const foundCountSpan = document.getElementById('foundCount');
const startBatchBtn = document.getElementById('startBatchBtn');
const batchProgress = document.getElementById('batchProgress');
const progressCountSpan = document.getElementById('progressCount');
const stopBatchBtn = document.getElementById('stopBatchBtn');
const resumeBatchBtn = document.getElementById('resumeBatchBtn'); // New
const autoNextPageCheck = document.getElementById('autoNextPageCheck');

let scannedUrls = [];
let scannedNextPageUrl = null;
let extractedProducts = []; // Bulk SERP extraction results

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

    // Check if batch is already running
    chrome.runtime.sendMessage({ action: "GET_STATUS" }, (response) => {
        if (response && response.isProcessing) {
            showBatchProgress(response.processedCount, response.totalCount);
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

            chrome.tabs.sendMessage(tab.id, { action: 'EXTRACT_SERP_PRODUCTS' }, (res) => {
                if (chrome.runtime.lastError || !res || !res.success) {
                    showError('Could not scan page. Make sure you are on an Amazon search results page.');
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
            const asinData = await asinRes.json();
            const existingAsins = (asinData.success && asinData.asins) ? asinData.asins : [];
            const existingCount = extractedProducts.filter(p => existingAsins.includes(p.asin)).length;

            statusDiv.textContent = `Sending ${extractedProducts.length} products (${existingCount} will be refreshed)...`;

            // Send ALL products — backend differentiates new vs existing
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

        } catch (e) {
            showError('Network Error: ' + e.message);
            startBatchBtn.disabled = false;
        }
    });
}

// Resume Batch Button
if (resumeBatchBtn) {
    resumeBatchBtn.addEventListener('click', () => {
        chrome.runtime.sendMessage({ action: "RESUME_BATCH" }, (response) => {
            statusDiv.textContent = 'Resuming batch...';
            resumeBatchBtn.style.display = 'none';
            stopBatchBtn.style.display = 'inline-block';
        });
    });
}

// Stop Batch Button
if (stopBatchBtn) {
    stopBatchBtn.addEventListener('click', () => {
        chrome.runtime.sendMessage({ action: "STOP_BATCH" }, (response) => {
            resetBatchUI();
            statusDiv.textContent = 'Batch stopped.';
        });
    });
}

// Listen for Progress Updates from Background
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === "BATCH_PROGRESS") {
        showBatchProgress(request.processed, request.total);
        if (request.message) {
            statusDiv.textContent = request.message;

            // Check for Robot message
            if (request.message.includes("Robot Detected")) {
                statusDiv.className = 'error';
                stopBatchBtn.style.display = 'none';
                resumeBatchBtn.style.display = 'block';
            } else {
                statusDiv.className = '';
                stopBatchBtn.style.display = 'block';
                resumeBatchBtn.style.display = 'none';
            }
        }
        if (request.processed >= request.total) {
            statusDiv.className = 'success';
        }
    }
});

function showBatchProgress(processed, total) {
    batchControls.style.display = 'none';
    scanPageBtn.style.display = 'none';
    scrapeBtn.style.display = 'none';
    batchProgress.style.display = 'block';
    progressCountSpan.textContent = `${processed}/${total}`;

    // Default button state
    stopBatchBtn.style.display = 'block';
    resumeBatchBtn.style.display = 'none';
}

function resetBatchUI() {
    batchProgress.style.display = 'none';
    scanPageBtn.style.display = 'block';
    scrapeBtn.style.display = 'block';
    batchControls.style.display = 'none';
}

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

// Import Single Product — extracts lightweight data from the current product page
// and sends it to the batch-import API (same as SERP scan, but for 1 product).
// If the ASIN already exists, its price/rating/reviews are refreshed.
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

        chrome.tabs.sendMessage(tab.id, { action: 'EXTRACT_PRODUCT_PAGE' }, async (response) => {
            if (chrome.runtime.lastError) {
                showError('Error: ' + chrome.runtime.lastError.message + '. Try refreshing the Amazon page.');
                return;
            }

            if (!response?.success || !response.product) {
                showError(response?.error || 'Could not extract product data. Make sure you are on an Amazon product page.');
                return;
            }

            const product = response.product;

            if (product.unavailable) {
                showError('Product is currently unavailable on Amazon — skipped.');
                return;
            }

            statusDiv.textContent = `Sending "${product.title?.substring(0, 40)}..." to PW2D...`;

            try {
                const apiResponse = await fetch(`${baseUrl}/api/products/batch-import`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Extension-Token': EXTENSION_TOKEN,
                        'X-Tenant-Id': TENANT_ID,
                    },
                    body: JSON.stringify({
                        category_id: selectedCategoryId,
                        products: [product],
                    })
                });

                const result = await apiResponse.json();

                if (apiResponse.ok && result.success) {
                    const action = result.created > 0 ? 'Queued for AI' : 'Price refreshed';
                    statusDiv.textContent = `${action}: ${product.title?.substring(0, 50)}`;
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
