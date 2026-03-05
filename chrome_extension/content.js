// Extract ASIN from URL or hidden input
function getASIN() {
    // 1. Try URL regex
    const urlRegex = /(?:\/dp\/|\/gp\/product\/)(B[0-9A-Z]{9})/;
    const urlMatch = window.location.href.match(urlRegex);
    if (urlMatch && urlMatch[1]) {
        return urlMatch[1];
    }

    // 2. Try hidden input
    const asinInput = document.querySelector('#ASIN');
    if (asinInput && asinInput.value) {
        return asinInput.value;
    }

    return null;
}

// Find best image URL
function getImageUrl() {
    const imageSelectors = [
        '#landingImage',           // Main product image
        '#imgBlkFront',            // Alternative main image
        '#main-image',             // Another common ID
        '.imgTagWrapper img',      // Image wrapper
        '#imageBlock img',         // Image block
        '[data-a-image-name="landingImage"]', // Data attribute
    ];

    for (const selector of imageSelectors) {
        const img = document.querySelector(selector);
        if (img && img.src) {
            let src = img.src;
            // Get full resolution by removing size params
            src = src.replace(/\._[A-Z]{2}\d+_\./, '.');

            // Try data-old-hires
            if (img.getAttribute('data-old-hires')) {
                src = img.getAttribute('data-old-hires');
            } else if (img.getAttribute('data-a-dynamic-image')) {
                try {
                    const dynamicImages = JSON.parse(img.getAttribute('data-a-dynamic-image'));
                    const urls = Object.keys(dynamicImages);
                    if (urls.length > 0) src = urls[0];
                } catch (e) { }
            }
            return src;
        }
    }
    return null;
}

// Listen for messages from popup
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === "extract_all") {

        // 0. Check for Robot/Captcha Page
        if (checkForRobot()) {
            sendResponse({ robot: true });
            return;
        }

        const asin = getASIN();
        const imageUrl = getImageUrl();
        const rawText = document.body.innerText;

        const payload = {
            title: document.title, // Keep title separate if needed, but rawText covers it
            rawText: rawText,
            imageUrl: imageUrl,
            productUrl: window.location.href,
            external_id: asin
        };

        sendResponse(payload);
    } else if (request.action === "SCAN_PAGE") {
        const links = extractProductLinks();
        const nextPage = extractNextPageUrl();
        sendResponse({ success: true, links: links, nextPageUrl: nextPage });
    }
    return true; // Keep channel open
});

// Check for Robot/Captcha
function checkForRobot() {
    const title = document.title;
    const bodyText = document.body.innerText;

    if (title.includes("Robot Check") || bodyText.includes("Enter the characters you see below")) {
        console.warn("PW2D: Robot Check Detected!");

        // Try to click "Continue" button if it's just a click-through
        const continueBtn = document.querySelector('button[type="submit"]') || document.querySelector('#a-autoid-0-announce');

        // Only click if it's NOT a captcha (no image input)
        const hasCaptchaImg = document.querySelector('img[src*="captcha"]');

        if (continueBtn && !hasCaptchaImg) {
            console.log("PW2D: Attempting to auto-click Continue...");
            continueBtn.click();
            return true; // Page will reload, scraping will retry
        }

        // If captcha or no button, fail
        return true;
    }
    return false;
}

// Extract Product Links from Listing Page
function extractProductLinks() {
    const uniqueAsins = new Set();
    const productUrls = [];

    // Strategy 1: Directly query all result items with a valid ASIN in data-asin attribute.
    // This is the most reliable method - Amazon adds data-asin to virtually every product card
    // regardless of layout type: organic, sponsored, editorial picks, "Overall Pick" badges, etc.
    document.querySelectorAll('[data-asin]').forEach(item => {
        const asin = item.getAttribute('data-asin');
        if (asin && asin.match(/^B[0-9A-Z]{9}$/) && !uniqueAsins.has(asin)) {
            uniqueAsins.add(asin);
            productUrls.push(`https://www.amazon.com/dp/${asin}`);
        }
    });

    // Strategy 2: Fallback — scrape all links on the page with /dp/ in the href.
    // Catches any remaining products from unusual layouts (best sellers, carousels, etc.)
    document.querySelectorAll('a[href*="/dp/"]').forEach(link => {
        const match = link.href.match(/(?:\/dp\/|\/gp\/product\/)(B[0-9A-Z]{9})/);
        if (match && match[1] && !uniqueAsins.has(match[1])) {
            uniqueAsins.add(match[1]);
            productUrls.push(`https://www.amazon.com/dp/${match[1]}`);
        }
    });

    console.log(`PW2D: Found ${productUrls.length} products.`);
    return productUrls;
}

// Extract Next Page URL
function extractNextPageUrl() {
    // Amazon usually uses classes like .s-pagination-next
    const nextBtn = document.querySelector('.s-pagination-next:not(.s-pagination-disabled)');
    if (nextBtn && nextBtn.href) {
        return nextBtn.href;
    }
    return null;
}
