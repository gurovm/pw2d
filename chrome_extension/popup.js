const API_CONFIG = {
    local: 'http://127.0.0.1:8003',
    production: 'https://pw2d.com'
};

const EXTENSION_TOKEN = '626f897ea3ed362449c7c06625633db8a2e7405e88ec2cac8ad7152ea9d619f9'; // Must match .env CHROME_EXTENSION_KEY

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
    // Load saved environment first
    chrome.storage.local.get(['env'], async (result) => {
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
                headers: { 'X-Extension-Token': EXTENSION_TOKEN },
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
            headers: { 'X-Extension-Token': EXTENSION_TOKEN },
        });
        const data = await response.json();

        if (data.success && data.categories) {
            categories = data.categories;
            populateCategorySelect(categories);
        } else {
            showError('Failed to load categories.');
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

// Scrape Button Click
scrapeBtn.addEventListener('click', async () => {
    if (!selectedCategoryId) {
        showError('Please select a category first.');
        return;
    }

    statusDiv.textContent = 'Scraping...';
    statusDiv.className = '';

    try {
        const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });

        if (!tab) {
            showError('Error: No active tab found');
            return;
        }

        // Send message to content script
        chrome.tabs.sendMessage(tab.id, { action: "extract_all" }, async (response) => {
            if (chrome.runtime.lastError) {
                showError('Error: ' + chrome.runtime.lastError.message + '. Try refreshing the page.');
                return;
            }

            if (!response) {
                showError('Error: No response from content script.');
                return;
            }

            statusDiv.textContent = 'Sending to PW2D...';

            try {
                const apiResponse = await fetch(`${baseUrl}/api/product-import`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Extension-Token': EXTENSION_TOKEN,
                    },
                    body: JSON.stringify({
                        raw_text: response.rawText, // Changed from response.title + ...
                        image_url: response.imageUrl, // Changed from response.image
                        product_url: response.productUrl, // Changed from response.url
                        external_id: response.external_id,
                        category_id: selectedCategoryId
                    })
                });

                const result = await apiResponse.json();

                if (apiResponse.ok && result.success) {
                    statusDiv.textContent = 'Success! Product imported.';
                    statusDiv.className = 'success';
                } else {
                    showError('API Error: ' + (result.message || 'Unknown error'));
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
