// ─────────────────────────────────────────────────────────────
// PW2D Content Script
// ─────────────────────────────────────────────────────────────

// ── Single-product page helpers (used by "extract_all") ──────

function getASIN() {
    const urlMatch = window.location.href.match(/(?:\/dp\/|\/gp\/product\/)(B[0-9A-Z]{9})/);
    if (urlMatch) return urlMatch[1];
    const asinInput = document.querySelector('#ASIN');
    if (asinInput?.value) return asinInput.value;
    return null;
}

function getImageUrl() {
    const selectors = [
        '#landingImage', '#imgBlkFront', '#main-image',
        '.imgTagWrapper img', '#imageBlock img',
        '[data-a-image-name="landingImage"]',
    ];
    for (const sel of selectors) {
        const img = document.querySelector(sel);
        if (img?.src) {
            let src = img.src.replace(/\._[A-Z]{2}\d+_\./, '.');
            if (img.getAttribute('data-old-hires')) {
                src = img.getAttribute('data-old-hires');
            } else if (img.getAttribute('data-a-dynamic-image')) {
                try {
                    const urls = Object.keys(JSON.parse(img.getAttribute('data-a-dynamic-image')));
                    if (urls.length) src = urls[0];
                } catch (e) {}
            }
            return src;
        }
    }
    return null;
}

function checkForRobot() {
    const title = document.title;
    const bodyText = document.body.innerText;
    if (title.includes('Robot Check') || bodyText.includes('Enter the characters you see below')) {
        console.warn('PW2D: Robot Check Detected!');
        const continueBtn = document.querySelector('button[type="submit"]') || document.querySelector('#a-autoid-0-announce');
        const hasCaptchaImg = document.querySelector('img[src*="captcha"]');
        if (continueBtn && !hasCaptchaImg) {
            continueBtn.click();
            return true;
        }
        return true;
    }
    return false;
}

// ── SERP / Listing page helpers ───────────────────────────────

function extractProductLinks() {
    const seen = new Set();
    const urls = [];
    document.querySelectorAll('[data-asin]').forEach(el => {
        const asin = el.getAttribute('data-asin');
        if (asin?.match(/^B[0-9A-Z]{9}$/) && !seen.has(asin)) {
            seen.add(asin);
            urls.push(`https://www.amazon.com/dp/${asin}`);
        }
    });
    document.querySelectorAll('a[href*="/dp/"]').forEach(link => {
        const m = link.href.match(/(?:\/dp\/|\/gp\/product\/)(B[0-9A-Z]{9})/);
        if (m?.[1] && !seen.has(m[1])) {
            seen.add(m[1]);
            urls.push(`https://www.amazon.com/dp/${m[1]}`);
        }
    });
    return urls;
}

function extractNextPageUrl() {
    const btn = document.querySelector('.s-pagination-next:not(.s-pagination-disabled)');
    return btn?.href || null;
}

// ── NEW: Bulk SERP extraction (returns structured product data) ─

/**
 * Extract price from a product card element.
 * Tries four strategies in order, returns null if all fail.
 */
function extractPrice(el) {
    try {
        // 1. .a-price .a-offscreen — most reliable, already formatted "$54.99"
        const offscreen = el.querySelector('.a-price .a-offscreen');
        if (offscreen) {
            const p = parseFloat(offscreen.innerText.replace(/[^0-9.]/g, ''));
            if (p > 0) return p;
        }

        // 2. Whole + fraction parts (e.g. "54" + "99")
        const whole    = el.querySelector('.a-price-whole');
        const fraction = el.querySelector('.a-price-fraction');
        if (whole) {
            const w = whole.innerText.replace(/[^0-9]/g, '');
            const f = fraction ? fraction.innerText.replace(/[^0-9]/g, '').padEnd(2, '0') : '00';
            const p = parseFloat(`${w}.${f}`);
            if (p > 0) return p;
        }

        // 3. Any element whose text contains a "$" price (e.g. spans like "$54.99")
        const allEls = el.querySelectorAll('span, div');
        for (const s of allEls) {
            if (s.children.length > 0) continue; // leaf nodes only
            const text = s.innerText.trim();
            if (/^\$[\d,]+(\.\d{1,2})?$/.test(text)) {
                const p = parseFloat(text.replace(/[^0-9.]/g, ''));
                if (p > 0) return p;
            }
        }
    } catch (e) {}

    return null;
}

/**
 * Extract reviews count from a product card element.
 * Tries four strategies in order, returns 0 if all fail.
 */
function extractReviewsCount(el) {
    try {
        // 1. aria-label="309 ratings" or "1,234 ratings" on star/review link elements
        const ratingEls = el.querySelectorAll('[aria-label*="rating"]');
        for (const r of ratingEls) {
            const label = r.getAttribute('aria-label');
            const m = label.match(/^([\d,]+)\s+rating/i);
            if (m) {
                const n = parseInt(m[1].replace(/,/g, ''));
                if (n > 0) return n;
            }
        }

        // 2. Text that looks like "(1,234)" inside review count links/spans
        const reviewLinks = el.querySelectorAll(
            '.a-size-small .a-link-normal, .s-underline-text, .a-size-base .a-link-normal'
        );
        for (const r of reviewLinks) {
            const text = r.innerText.trim();
            // Matches "(1,234)" or "1,234"
            const m = text.match(/^\(?([\d,]+)\)?$/);
            if (m) {
                const n = parseInt(m[1].replace(/,/g, ''));
                if (n > 0) return n;
            }
        }

        // 3. data-rt JSON on carousel cards (e.g. {"rt":"309","c":"309"})
        const rtSpan = el.querySelector('span[data-rt]');
        if (rtSpan) {
            const rt = JSON.parse(rtSpan.getAttribute('data-rt') || '{}');
            const n = parseInt(rt.rt || rt.c || 0);
            if (n > 0) return n;
        }

        // 4. aria-label that is purely a number (e.g. aria-label="1234")
        const allLabeled = el.querySelectorAll('[aria-label]');
        for (const s of allLabeled) {
            const label = s.getAttribute('aria-label').replace(/,/g, '');
            if (/^\d{2,}$/.test(label)) { // at least 2 digits to avoid rating "5"
                const n = parseInt(label);
                if (n > 0) return n;
            }
        }
    } catch (e) {}

    return 0;
}

function extractSerpProducts() {
    const seen  = new Set();
    const products = [];

    document.querySelectorAll('[data-asin]:not([data-asin=""])').forEach(el => {
        try {
            const asin = el.getAttribute('data-asin');
            if (!asin || !/^B[0-9A-Z]{9}$/.test(asin) || seen.has(asin)) return;
            seen.add(asin);

            // ── Title (fallback chain) ────────────────────────
            let title = null;
            // Prefer the hidden full-text span Amazon includes for screen readers
            // (avoids the duplicated text that results from reading h2.innerText
            //  when both .a-truncate-full and .a-truncate-cut are present)
            const fullSpan = el.querySelector('h2 .a-truncate-full');
            if (fullSpan) {
                title = fullSpan.textContent.trim();
            }
            if (!title) {
                const h2 = el.querySelector('h2');
                if (h2) {
                    // Strip any hidden child spans to avoid duplication
                    const clone = h2.cloneNode(true);
                    clone.querySelectorAll('.a-truncate-full').forEach(s => s.remove());
                    title = clone.innerText.trim();
                }
            }
            if (!title) {
                const trunc = el.querySelector('.a-size-base-plus, .a-size-medium');
                if (trunc) title = trunc.innerText.trim();
            }
            if (!title) {
                const img = el.querySelector('img[alt]');
                if (img?.alt) title = img.alt.trim();
            }
            if (!title) return; // no usable title — skip

            // ── Price ─────────────────────────────────────────
            const price = extractPrice(el);

            // ── Rating ────────────────────────────────────────
            let rating = null;
            try {
                // Prefer aria-label "X out of 5 stars" anywhere in the card
                const ariaEl = el.querySelector('[aria-label*="out of 5 stars"], [aria-label*="out of 5 Stars"]');
                if (ariaEl) {
                    const m = ariaEl.getAttribute('aria-label').match(/([\d.]+)\s+out\s+of\s+5/i);
                    if (m) rating = parseFloat(m[1]);
                }
                // Fallback: star icon class name (e.g. a-star-mini-4-5)
                if (!rating) {
                    const starEl = el.querySelector('.a-icon-star-mini, .a-icon-star-small, .a-icon-star');
                    if (starEl) {
                        const cls = [...starEl.classList].join(' ').match(/a-star-(?:mini-|small-)?(\d)-?(\d)?/);
                        if (cls) rating = parseFloat(`${cls[1]}.${cls[2] || 0}`);
                    }
                }
            } catch (e) {}

            // ── Reviews count ─────────────────────────────────
            const reviews_count = extractReviewsCount(el);

            // ── Image URL (hi-res) ────────────────────────────
            let image_url = null;
            try {
                const img = el.querySelector('img[src*="amazon"]') || el.querySelector('img');
                if (img) {
                    // data-old-hires is already full-res on product pages; src on SERP needs cleaning
                    let src = img.getAttribute('data-old-hires') || img.src || '';
                    // Strip dynamic sizing params: ._AC_SR322,134_CB... → nothing
                    src = src.replace(/\._.*?_\./, '.');
                    image_url = src || null;
                }
            } catch (e) {}

            products.push({ asin, title, price, rating, reviews_count, image_url, status: 'pending_ai' });

        } catch (e) {
            console.warn('PW2D: Skipped product due to extraction error:', e);
        }
    });

    console.log(`PW2D: Extracted ${products.length} products from SERP.`);
    return products;
}

// ── Message router ────────────────────────────────────────────

chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {

    if (request.action === 'extract_all') {
        if (checkForRobot()) { sendResponse({ robot: true }); return; }
        sendResponse({
            rawText:    document.body.innerText,
            imageUrl:   getImageUrl(),
            productUrl: window.location.href,
            external_id: getASIN(),
        });

    } else if (request.action === 'SCAN_PAGE') {
        sendResponse({
            success:     true,
            links:       extractProductLinks(),
            nextPageUrl: extractNextPageUrl(),
        });

    } else if (request.action === 'EXTRACT_SERP_PRODUCTS') {
        const products = extractSerpProducts();
        sendResponse({ success: true, products });
    }

    return true; // keep message channel open for async responses
});
