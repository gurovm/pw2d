// Background Service Worker for Batch Scraping

let queue = [];
let currentCategoryId = null;
let isProcessing = false;
let processedCount = 0;
let totalCount = 0;
let currentTabId = null;
let nextPageUrl = null;

const API_CONFIG = {
    local: 'http://127.0.0.1:8003',
    production: 'https://pw2d.com'
};
let currentEnv = 'local';
let baseUrl = API_CONFIG.local;
let EXTENSION_TOKEN = '';
let TENANT_ID = '';

// Initialize from storage
chrome.storage.local.get(['env', 'extensionToken', 'tenantId'], (result) => {
    if (result.env && API_CONFIG[result.env]) {
        currentEnv = result.env;
        baseUrl = API_CONFIG[result.env];
    }
    EXTENSION_TOKEN = result.extensionToken || '';
    TENANT_ID = result.tenantId || '';
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
    } else if (request.action === "START_BATCH") {
        startBatch(request.urls, request.categoryId, request.nextPageUrl);
        sendResponse({ success: true, message: "Batch started" });
    } else if (request.action === "STOP_BATCH") {
        stopBatch();
        sendResponse({ success: true, message: "Batch stopped" });
    } else if (request.action === "GET_STATUS") {
        sendResponse({
            isProcessing,
            processedCount,
            totalCount,
            queueLength: queue.length,
            nextPageUrl: nextPageUrl
        });
    } else if (request.action === "SCRAPE_COMPLETE") {
        if (request.payload && request.payload.robot) {
            handleRobotDetected(sender.tab.id);
        } else {
            handleScrapeComplete(request.payload);
        }
    } else if (request.action === "RESUME_BATCH") {
        if (!isProcessing && queue.length > 0) {
            isProcessing = true;
            broadcastStatus("Resuming Batch...");
            // Retry the current tab or move to next?
            // Better to close current (user solved it) and process next,
            // OR re-scrape current if user solved it on that tab.
            // Let's assume user solved it and wants to retry scraping THIS tab.
            // But getting back to the product page might be tricky.
            // Safest: Close current tab and Retry same URL (push back to front of queue).

            // Actually, if we just call processNext(), it takes from queue.
            // We need to re-add the current URL if it wasn't processed.
            // But wait, processNext SHIFTS from queue. So we need to PUT IT BACK if it failed.

            processNext();
        }
    }
    return true; // Keep channel open for async responses if needed
});

function handleRobotDetected(tabId) {
    isProcessing = false;
    broadcastStatus("⚠️ Robot Detected! Batch Paused.");

    // 1. Focus the tab
    chrome.tabs.update(tabId, { active: true });

    // 2. Put the current URL back to the front of the queue?
    // We haven't popped it yet?
    // processNext pops it effectively.
    // We should store 'processingUrl' to put it back.
    // Let's rely on user to reload/fix.

    // Ideally, we re-queue the current URL.
    // We don't have it easily available here unless we stored it globally.
    // But 'currentTabId' corresponds to it.

    // Let's just pause. The user can close tab and Resume (which will pick next).
    // Or if they fix it, they can Resume and we should retry scraping?
    // Let's keep it simple: Pause.
}

// Start the Batch Process
function startBatch(urls, categoryId, nextUrl = null) {
    if (isProcessing) return;

    queue = [...urls];
    totalCount += urls.length; // Append to total if continuing
    currentCategoryId = categoryId;
    nextPageUrl = nextUrl;
    isProcessing = true;

    processNext();
}

// Stop the Batch
function stopBatch() {
    isProcessing = false;
    queue = [];
    if (currentTabId) {
        chrome.tabs.remove(currentTabId).catch(() => { });
        currentTabId = null;
    }
}

// Process Next Item in Queue
async function processNext() {
    if (!isProcessing) return;

    if (queue.length === 0) {
        if (nextPageUrl) {
            handleNextPage();
            return;
        }

        isProcessing = false;
        broadcastStatus("Batch Complete!");
        nextPageUrl = null;
        totalCount = 0; // Reset for next brand new batch
        processedCount = 0;
        return;
    }

    const url = queue.shift();
    broadcastStatus(`Processing ${processedCount + 1}/${totalCount}...`);

    try {
        // 1. Create Tab
        const tab = await chrome.tabs.create({ url: url, active: false });
        currentTabId = tab.id;

        // 2. Wait for Tab to Load
        chrome.tabs.onUpdated.addListener(function listener(tabId, info) {
            if (tabId === currentTabId && info.status === 'complete') {
                chrome.tabs.onUpdated.removeListener(listener);

                // 3. Inject Scraper (wait longer for dynamic content/scripts)
                // Amazon is heavy, 5s is safer than 2s to ensure DOM is ready
                setTimeout(() => {
                    injectScraper(tabId);
                }, 5000);
            }
        });

    } catch (error) {
        console.error("Tab creation failed:", error);
        processedCount++; // Skip this one
        scheduleNext();
    }
}

// Inject Scraper Script
function injectScraper(tabId, retry = 0) {
    if (!isProcessing) return;

    // Send message to trigger scrape
    chrome.tabs.sendMessage(tabId, { action: "extract_all" }, (response) => {
        if (chrome.runtime.lastError) {
            console.error("Scraper injection failed:", chrome.runtime.lastError);

            // Retry once after 2s if failed (maybe script wasn't ready)
            if (retry === 0) {
                setTimeout(() => injectScraper(tabId, 1), 2000);
                return;
            }

            // If failed twice, skip
            if (isProcessing) {
                processedCount++;
                closeCurrentTab();
                scheduleNext(); // Skip bad page
            }
            return;
        }

        if (response) {
            handleScrapeComplete(response);
        } else {
            console.error("Scraper returned null response");
            if (isProcessing) {
                processedCount++;
                closeCurrentTab();
                scheduleNext();
            }
        }
    });
}

// Handle Data from Content Script
async function handleScrapeComplete(payload) {
    if (!isProcessing) return;

    try {
        // 4. Upload to API
        const response = await fetch(`${baseUrl}/api/product-import`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Extension-Token': EXTENSION_TOKEN,
                'X-Tenant-Id': TENANT_ID,
            },
            body: JSON.stringify({
                raw_text: payload.rawText,
                image_url: payload.imageUrl,
                product_url: payload.productUrl,
                external_id: payload.external_id,
                category_id: currentCategoryId
            })
        });

        const data = await response.json();
        console.log("Import Result:", data);

    } catch (error) {
        console.error("API Upload failed:", error);
    } finally {
        processedCount++;
        closeCurrentTab();
        scheduleNext();
    }
}

function closeCurrentTab() {
    if (currentTabId) {
        chrome.tabs.remove(currentTabId).catch(() => { });
        currentTabId = null;
    }
}

// Schedule Next with Random Delay
function scheduleNext() {
    if (!isProcessing) return;

    const delay = Math.floor(Math.random() * (15000 - 5000 + 1) + 5000); // 5-15 seconds
    broadcastStatus(`Waiting ${Math.round(delay / 1000)}s...`);

    // Use Alarms API or simple timeout
    setTimeout(() => {
        processNext();
    }, delay);
}

// Broadcast Status to Popup
function broadcastStatus(msg) {
    chrome.runtime.sendMessage({
        action: "BATCH_PROGRESS",
        processed: processedCount,
        total: totalCount,
        message: msg
    }).catch(() => { }); // Ignore error if popup is closed
}

// ----- Auto Pagination Logic -----
async function handleNextPage() {
    if (!nextPageUrl) return;

    broadcastStatus(`Navigating to Next Page...`);
    const targetUrl = nextPageUrl;
    nextPageUrl = null; // Clear it so we don't loop infinitely if extraction fails

    try {
        const tab = await chrome.tabs.create({ url: targetUrl, active: false });
        currentTabId = tab.id;

        chrome.tabs.onUpdated.addListener(function listener(tabId, info) {
            if (tabId === currentTabId && info.status === 'complete') {
                chrome.tabs.onUpdated.removeListener(listener);

                // Wait for page to settle
                setTimeout(() => scanNextPage(tabId), 5000);
            }
        });
    } catch (e) {
        console.error("Next page navigation failed:", e);
        isProcessing = false;
        broadcastStatus("Failed to load next page.");
    }
}

function scanNextPage(tabId) {
    broadcastStatus(`Scanning next page...`);
    chrome.tabs.sendMessage(tabId, { action: "SCAN_PAGE" }, async (response) => {
        if (chrome.runtime.lastError || !response || !response.success) {
            console.error("Scan next page failed:", chrome.runtime.lastError);
            isProcessing = false;
            broadcastStatus("Stopped: Could not scan next page.");
            closeCurrentTab();
            return;
        }

        const newLinks = response.links;
        const newNextPageUrl = response.nextPageUrl;

        if (newLinks.length === 0) {
            isProcessing = false;
            broadcastStatus("No products found on next page. Complete.");
            closeCurrentTab();
            return;
        }

        broadcastStatus(`Filtering existing products...`);
        try {
            // Check existing ASINs
            const apiRes = await fetch(`${baseUrl}/api/existing-asins?category_id=${currentCategoryId}`, {
                headers: {
                    'X-Extension-Token': EXTENSION_TOKEN,
                    'X-Tenant-Id': TENANT_ID,
                }
            });
            const data = await apiRes.json();

            let existingAsins = [];
            if (data.success && data.asins) {
                existingAsins = data.asins;
            }

            const newUrls = newLinks.filter(url => {
                const match = url.match(/(?:\/dp\/|\/gp\/product\/)(B[0-9A-Z]{9})/);
                if (match && match[1]) {
                    return !existingAsins.includes(match[1]);
                }
                return true;
            });

            closeCurrentTab(); // Close the search tab, we have the URLs

            if (newUrls.length === 0) {
                // Try next page immediately if all existed
                nextPageUrl = newNextPageUrl;
                if (nextPageUrl) {
                    broadcastStatus(`All existing. Skipping to next page...`);
                    setTimeout(handleNextPage, 3000);
                } else {
                    isProcessing = false;
                    broadcastStatus("Batch Complete (All products existed).");
                }
                return;
            }

            // Continue Batch
            queue = newUrls;
            totalCount += newUrls.length;
            nextPageUrl = newNextPageUrl;

            scheduleNext();

        } catch (e) {
            console.error("Async check failed on next page", e);
            isProcessing = false;
            broadcastStatus("Error checking existing products on next page.");
            closeCurrentTab();
        }
    });
}
