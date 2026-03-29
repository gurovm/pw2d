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
        // 1. aria-label containing "N ratings" anywhere in the string
        // Handles: "2,567 ratings", "4.3 out of 5 stars 2,567 ratings", etc.
        const ratingEls = el.querySelectorAll('[aria-label*="rating"]');
        for (const r of ratingEls) {
            const label = r.getAttribute('aria-label');
            const m = label.match(/([\d,]+)\s+ratings?/i);
            if (m) {
                const n = parseInt(m[1].replace(/,/g, ''));
                if (n > 0) return n;
            }
        }

        // 2. data-csa-c-ratings attribute (newer Amazon SERP cards)
        const csaEl = el.querySelector('[data-csa-c-ratings]');
        if (csaEl) {
            const n = parseInt(csaEl.getAttribute('data-csa-c-ratings').replace(/,/g, ''));
            if (n > 0) return n;
        }

        // 3. Text that looks like "(1,234)" or "1,234" inside review count links/spans
        const reviewLinks = el.querySelectorAll(
            '.a-size-small .a-link-normal, .s-underline-text, .a-size-base .a-link-normal'
        );
        for (const r of reviewLinks) {
            const text = r.innerText.trim();
            const m = text.match(/^\(?([\d,]+)\)?$/);
            if (m) {
                const n = parseInt(m[1].replace(/,/g, ''));
                if (n > 0) return n;
            }
        }

        // 4. data-rt JSON on carousel cards (e.g. {"rt":"309","c":"309"})
        const rtSpan = el.querySelector('span[data-rt]');
        if (rtSpan) {
            const rt = JSON.parse(rtSpan.getAttribute('data-rt') || '{}');
            const n = parseInt(rt.rt || rt.c || 0);
            if (n > 0) return n;
        }

        // 5. aria-label that is purely a number ≥ 10 (avoids mistaking the star rating digit)
        const allLabeled = el.querySelectorAll('[aria-label]');
        for (const s of allLabeled) {
            const label = s.getAttribute('aria-label').replace(/,/g, '');
            if (/^\d{2,}$/.test(label)) {
                const n = parseInt(label);
                if (n >= 10) return n;
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

            // 1. Hidden full-text span Amazon includes for screen readers
            const fullSpan = el.querySelector('h2 .a-truncate-full');
            if (fullSpan) title = fullSpan.textContent.trim();

            // 2. The main product title link text (span.a-text-normal inside h2 > a)
            if (!title) {
                const titleSpan = el.querySelector('h2 a span.a-text-normal');
                if (titleSpan) title = titleSpan.textContent.trim();
            }

            // 3. Newer Amazon "recipe" card title
            if (!title) {
                const recipe = el.querySelector('[data-cy="title-recipe"] a span, [data-cy="title-recipe-title"]');
                if (recipe) title = recipe.textContent.trim();
            }

            // 4. Any span inside an anchor pointing to /dp/
            if (!title) {
                const dpLink = el.querySelector('a[href*="/dp/"] span.a-text-normal, a[href*="/dp/"] span');
                if (dpLink) title = dpLink.textContent.trim();
            }

            // 5. h2 full innerText (may contain just brand on newer layouts — used as fallback)
            if (!title) {
                const h2 = el.querySelector('h2');
                if (h2) {
                    const clone = h2.cloneNode(true);
                    clone.querySelectorAll('.a-truncate-full').forEach(s => s.remove());
                    title = clone.innerText.trim();
                }
            }

            // 6. Carousel-style truncated title spans
            if (!title) {
                const trunc = el.querySelector(
                    '.p13n-sc-truncate, .a-size-base-plus, .a-size-medium, [class*="truncate"]'
                );
                if (trunc) title = trunc.textContent.trim();
            }

            // 7. Last resort: image alt text (usually the full product name)
            if (!title) {
                const img = el.querySelector('img[alt]');
                if (img?.alt && img.alt.length > 5) title = img.alt.trim();
            }

            if (!title || title.length < 3) return; // no usable title — skip

            // ── Garbage filter ────────────────────────────────
            // Skip sponsored labels, ad widgets, and non-product cards
            const titleLower = title.toLowerCase();
            if (/^sponsored$/i.test(title)) return;
            if (/\b(refurbished|renewed|certified refurbished|package)\b/i.test(title)) return;
            if (titleLower.length < 10 && !/\d/.test(title)) return; // too short and no model number
            // Skip if the element is a tiny widget (no price, no reviews, small area)
            const rect = el.getBoundingClientRect();
            if (rect.height < 80 || rect.width < 100) return;
            // Deduplicate: Amazon sometimes concatenates the title with a truncated copy
            // e.g. "HIBREW H10Plus - Espresso Machine...HIBREW H10Plus - Espresso Machine..."
            // Strategy: find longest repeated prefix (min 20 chars) and keep just the first copy
            if (title.length > 60) {
                for (let len = Math.floor(title.length / 2); len >= 20; len--) {
                    const prefix = title.substring(0, len);
                    const rest = title.substring(len);
                    if (rest.startsWith(prefix.substring(0, Math.min(20, prefix.length)))) {
                        title = prefix.trim().replace(/[-–,]\s*$/, '').trim();
                        break;
                    }
                }
            }

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

            // Skip products with no reviews — likely new/fake listings with no real data
            if (!reviews_count || reviews_count < 5) return;

            products.push({ asin, title, price, rating, reviews_count, image_url, status: 'pending_ai' });

        } catch (e) {
            console.warn('PW2D: Skipped product due to extraction error:', e);
        }
    });

    console.log(`PW2D: Extracted ${products.length} products from SERP.`);
    return products;
}

// ── Single product page data extraction ───────────────────────

function extractProductPageData() {
    const asin = getASIN();
    if (!asin) return null;

    // Check if product is unavailable
    const availEl = document.querySelector('#availability, #outOfStock');
    if (availEl) {
        const text = availEl.textContent.toLowerCase();
        if (text.includes('currently unavailable') || text.includes('out of stock')) {
            return { unavailable: true, asin };
        }
    }

    // Title — from #productTitle or meta title
    let title = null;
    const titleEl = document.querySelector('#productTitle');
    if (titleEl) title = titleEl.textContent.trim();
    if (!title) {
        const metaTitle = document.querySelector('meta[name="title"]');
        if (metaTitle) title = metaTitle.getAttribute('content')?.trim();
    }
    if (!title) title = document.title.replace(/ *: *Amazon.*$/i, '').trim();

    // Price — scoped to main product area to avoid carousel/sidebar prices
    let price = null;
    // Strategy 1: Look inside known price containers
    const priceContainers = [
        '#corePrice_feature_div',
        '#apex_offerDisplay_desktop',
        '#priceblock_ourprice',
        '#priceblock_dealprice',
        '#price_inside_buybox',
        '#newBuyBoxPrice',
        '#tp_price_block_total_price_ww',
        '.priceToPay',
    ];
    for (const sel of priceContainers) {
        if (price) break;
        const container = document.querySelector(sel);
        if (!container) continue;
        const offscreen = container.querySelector('.a-offscreen');
        if (offscreen) {
            const p = parseFloat(offscreen.textContent.replace(/[^0-9.]/g, ''));
            if (p > 0) { price = p; break; }
        }
        const whole = container.querySelector('.a-price-whole');
        if (whole) {
            const w = whole.textContent.replace(/[^0-9]/g, '');
            const f = container.querySelector('.a-price-fraction')?.textContent.replace(/[^0-9]/g, '') || '00';
            const p = parseFloat(`${w}.${f}`);
            if (p > 0) { price = p; break; }
        }
    }
    // Strategy 2: Find .a-price inside the main centerCol but NOT inside carousels/comparisons
    if (!price) {
        const mainCol = document.querySelector('#centerCol, #ppd, #dp');
        if (mainCol) {
            const allPrices = mainCol.querySelectorAll('.a-price .a-offscreen');
            for (const el of allPrices) {
                // Skip if inside a carousel or comparison widget
                if (el.closest('#sims-consolidated-1_feature_div, #sims-consolidated-2_feature_div, [class*="carousel"], [class*="sims"]')) continue;
                const p = parseFloat(el.textContent.replace(/[^0-9.]/g, ''));
                if (p > 0) { price = p; break; }
            }
        }
    }
    // Strategy 3: Last resort — meta tag or structured data
    if (!price) {
        const metaPrice = document.querySelector('meta[itemprop="price"], input#attach-base-product-price');
        if (metaPrice) {
            const p = parseFloat(metaPrice.getAttribute('content') || metaPrice.getAttribute('value') || '');
            if (p > 0) price = p;
        }
    }

    // Rating
    let rating = null;
    const ratingEl = document.querySelector('#acrPopover, [data-action="acrStars498-popover"]');
    if (ratingEl) {
        const m = (ratingEl.getAttribute('title') || ratingEl.textContent).match(/([\d.]+)\s+out\s+of\s+5/i);
        if (m) rating = parseFloat(m[1]);
    }
    if (!rating) {
        const starSpan = document.querySelector('.a-icon-star .a-icon-alt, .a-icon-star-small .a-icon-alt');
        if (starSpan) {
            const m = starSpan.textContent.match(/([\d.]+)/);
            if (m) rating = parseFloat(m[1]);
        }
    }

    // Reviews count
    let reviews_count = 0;
    const reviewEl = document.querySelector('#acrCustomerReviewText');
    if (reviewEl) {
        const m = reviewEl.textContent.match(/([\d,]+)/);
        if (m) reviews_count = parseInt(m[1].replace(/,/g, ''));
    }

    // Image
    const image_url = getImageUrl();

    return { asin, title, price, rating, reviews_count, image_url, status: 'pending_ai' };
}

// ── Message router ────────────────────────────────────────────

chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {

    if (request.action === 'EXTRACT_PRODUCT_PAGE') {
        if (checkForRobot()) { sendResponse({ success: false, robot: true }); return; }
        const data = extractProductPageData();
        if (data) {
            sendResponse({ success: true, product: data });
        } else {
            sendResponse({ success: false, error: 'Could not extract product data. Is this an Amazon product page?' });
        }

    } else if (request.action === 'extract_all') {
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

    } else if (request.action === 'EXTRACT_STORE_PRODUCT') {
        const data = extractStoreProduct();
        sendResponse(data);

    } else if (request.action === 'EXTRACT_STORE_LISTING') {
        const data = extractStoreListing();
        sendResponse(data);
    }

    return true; // keep message channel open for async responses
});

// ── Domain Router: Store-specific product extractors ──────────

/**
 * Detects which store we're on and extracts product data using store-specific selectors.
 * Returns a unified payload for the ingest-offer API.
 */
function extractStoreProduct() {
    const host = window.location.hostname.replace(/^www\./, '');
    const url = window.location.href;

    const extractors = {
        'amazon.com': extractAmazonProduct,
        'clivecoffee.com': extractCliveCoffeeProduct,
        'seattlecoffeegear.com': extractSeattleCoffeeGearProduct,
        'wholelattelove.com': extractWholeLatteLoveProduct,
    };

    const extractor = extractors[host];
    if (!extractor) {
        return { success: false, error: `Unsupported store: ${host}` };
    }

    try {
        const product = extractor(url);
        if (!product || !product.raw_title) {
            return { success: false, error: 'Could not extract product data from this page.' };
        }
        return { success: true, product };
    } catch (e) {
        return { success: false, error: e.message };
    }
}

function extractAmazonProduct(url) {
    const data = extractProductPageData();
    if (!data) return null;
    if (data.unavailable) return { unavailable: true };

    // Extract brand from the "Visit the X Store" link or brand table row
    let brand = null;
    const storeLink = document.querySelector('#bylineInfo');
    if (storeLink) {
        const m = storeLink.textContent.match(/(?:Visit the|Brand:)\s*(.+?)(?:\s*Store)?$/i);
        if (m) brand = m[1].trim();
    }
    if (!brand) {
        const brandRow = document.querySelector('tr.po-brand td:last-child span, [data-csa-c-brand]');
        if (brandRow) brand = brandRow.textContent?.trim() || brandRow.getAttribute('data-csa-c-brand');
    }

    return {
        url,
        store_slug: 'amazon',
        asin: data.asin,
        raw_title: data.title,
        brand,
        scraped_price: data.price,
        image_url: data.image_url,
        rating: data.rating,
        reviews_count: data.reviews_count,
    };
}

function extractCliveCoffeeProduct(url) {
    // Title: h1 with font-display class, or fallback to any h1 in product area
    const title = document.querySelector('h1.font-display, h1.product-name, h1.page-title, .product__purchase h1')?.textContent?.trim();
    if (!title) return null;

    // Price: Clive uses .price-item--regular inside .price__regular
    let price = null;
    const priceEl = document.querySelector('.price__regular .price-item--regular, .price-item--sale, [data-price-amount]');
    if (priceEl) {
        const m = (priceEl.getAttribute('data-price-amount') || priceEl.textContent).match(/\$([\d,]+(?:\.\d{2})?)/);
        if (m) price = parseFloat(m[1].replace(/,/g, ''));
    }

    // Brand: not explicitly shown on Clive product pages — extract from title (first word before space)
    let brand = null;
    const brandEl = document.querySelector('[itemprop="brand"] [itemprop="name"], .product-brand, .vendor');
    if (brandEl) brand = brandEl.textContent?.trim();

    // Image: first image in the product gallery
    let image = null;
    const imgEl = document.querySelector('.product-gallery__media, .product__media img, .product-image img');
    if (imgEl) {
        image = imgEl.getAttribute('src') || '';
        if (image.startsWith('//')) image = 'https:' + image;
        // Try to get highest res from srcset
        const srcset = imgEl.getAttribute('srcset');
        if (srcset) {
            const parts = srcset.split(',').map(s => s.trim());
            const last = parts[parts.length - 1];
            const m = last.match(/^(https?:\/\/[^\s]+|\/\/[^\s]+)/);
            if (m) {
                image = m[1];
                if (image.startsWith('//')) image = 'https:' + image;
            }
        }
    }

    // Rating from stars widget
    let rating = null;
    const starsEl = document.querySelector('[data-reviews-average]');
    if (starsEl) rating = parseFloat(starsEl.getAttribute('data-reviews-average'));

    // Reviews count
    let reviews_count = null;
    const reviewsEl = document.querySelector('.stars-scale__reviews_count, [itemprop="votes"]');
    if (reviewsEl) {
        const m = reviewsEl.textContent.match(/(\d+)/);
        if (m) reviews_count = parseInt(m[1]);
    }

    return {
        url,
        store_slug: 'clive-coffee',
        raw_title: title,
        brand,
        scraped_price: price,
        image_url: image,
        rating,
        reviews_count,
    };
}

// ── Store Listing Extractors (product grid pages) ─────────────

function extractStoreListing() {
    const host = window.location.hostname.replace(/^www\./, '');

    const extractors = {
        'clivecoffee.com': extractCliveCoffeeListing,
        'seattlecoffeegear.com': extractShopifyListing,
        'wholelattelove.com': extractWholeLatteLoveListing,
    };

    const extractor = extractors[host];
    if (!extractor) {
        return { success: false, error: `No listing extractor for: ${host}` };
    }

    try {
        const products = extractor();
        return { success: true, products };
    } catch (e) {
        return { success: false, error: e.message };
    }
}

function extractCliveCoffeeListing() {
    const products = [];
    const seen = new Set();

    document.querySelectorAll('.product-listing').forEach(card => {
        try {
            const linkEl = card.querySelector('.product-listing__link');
            if (!linkEl) return;

            const href = linkEl.getAttribute('href');
            if (!href || seen.has(href)) return;
            seen.add(href);

            const title = linkEl.textContent.trim();
            if (!title) return;

            // Price — handle "From $X,XXX" and "$X,XXX.XX"
            let price = null;
            const priceDiv = card.querySelector('.text-muted div:first-child');
            if (priceDiv) {
                const m = priceDiv.textContent.match(/\$([\d,]+(?:\.\d{2})?)/);
                if (m) price = parseFloat(m[1].replace(/,/g, ''));
            }

            // Image
            let image = null;
            const img = card.querySelector('.product-listing__media img');
            if (img) {
                image = img.getAttribute('src') || '';
                if (image.startsWith('//')) image = 'https:' + image;
                // Get highest quality from srcset
                const srcset = img.getAttribute('srcset');
                if (srcset) {
                    const parts = srcset.split(',').map(s => s.trim());
                    const last = parts[parts.length - 1];
                    const srcMatch = last.match(/^(https?:\/\/[^\s]+|\/\/[^\s]+)/);
                    if (srcMatch) {
                        image = srcMatch[1];
                        if (image.startsWith('//')) image = 'https:' + image;
                    }
                }
            }

            // Rating from data attribute
            let rating = null;
            const starsEl = card.querySelector('[data-reviews-average]');
            if (starsEl) {
                rating = parseFloat(starsEl.getAttribute('data-reviews-average'));
            }

            // Reviews count from title attribute
            let reviews_count = null;
            if (starsEl) {
                const m = starsEl.getAttribute('title')?.match(/(\d+)\s+reviews?/i);
                if (m) reviews_count = parseInt(m[1]);
            }

            // Build full URL
            const fullUrl = href.startsWith('http') ? href : `https://clivecoffee.com${href}`;
            // Strip ref param for clean canonical URL
            const cleanUrl = fullUrl.split('?')[0];

            products.push({
                url: cleanUrl,
                store_slug: 'clive-coffee',
                raw_title: title,
                brand: null, // Will be extracted by AI
                scraped_price: price,
                image_url: image,
                rating,
                reviews_count,
            });
        } catch (e) {
            console.warn('PW2D: Skipped Clive Coffee product:', e);
        }
    });

    console.log(`PW2D: Extracted ${products.length} products from Clive Coffee listing.`);
    return products;
}

function extractShopifyListing() {
    // Generic Shopify listing extractor — works for most Shopify themes
    const products = [];
    const seen = new Set();

    document.querySelectorAll('.product-listing, .product-card, .grid__item .card').forEach(card => {
        try {
            const linkEl = card.querySelector('a[href*="/products/"]');
            if (!linkEl) return;

            const href = linkEl.getAttribute('href');
            if (!href || seen.has(href)) return;
            seen.add(href);

            const titleEl = card.querySelector('h3, h2, .product-listing__title, .card__heading');
            const title = titleEl?.textContent?.trim();
            if (!title) return;

            let price = null;
            const priceEl = card.querySelector('.price .money, .price-item, [data-price]');
            if (priceEl) {
                const m = (priceEl.getAttribute('data-price') || priceEl.textContent).match(/\$([\d,]+(?:\.\d{2})?)/);
                if (m) price = parseFloat(m[1].replace(/,/g, ''));
            }

            let image = null;
            const img = card.querySelector('img');
            if (img) image = img.getAttribute('src') || '';
            if (image?.startsWith('//')) image = 'https:' + image;

            const host = window.location.hostname.replace(/^www\./, '');
            const fullUrl = href.startsWith('http') ? href : `https://${host}${href}`;

            products.push({
                url: fullUrl.split('?')[0],
                store_slug: host.replace(/\.com$/, '').replace(/\./g, '-'),
                raw_title: title,
                brand: null,
                scraped_price: price,
                image_url: image,
                rating: null,
                reviews_count: null,
            });
        } catch (e) {}
    });

    return products;
}

function extractSeattleCoffeeGearProduct(url) {
    const title = document.querySelector('h1.product-name, h1.page-title, h1[itemprop="name"]')?.textContent?.trim();
    if (!title) return null;

    let price = null;
    const priceEl = document.querySelector('[data-price-amount], .price .money, .product-price, meta[itemprop="price"]');
    if (priceEl) {
        const raw = priceEl.getAttribute('data-price-amount') || priceEl.getAttribute('content') || priceEl.textContent;
        price = parseFloat(raw.replace(/[^0-9.]/g, ''));
        if (isNaN(price)) price = null;
    }

    let brand = null;
    const brandEl = document.querySelector('[itemprop="brand"] [itemprop="name"], .product-brand, .vendor');
    if (brandEl) brand = brandEl.textContent?.trim();

    let image = null;
    const imgEl = document.querySelector('.product-image img, [itemprop="image"], .gallery-image img');
    if (imgEl) image = imgEl.getAttribute('src') || imgEl.getAttribute('data-src');

    return {
        url,
        store_slug: 'seattle-coffee-gear',
        raw_title: title,
        brand,
        scraped_price: price,
        image_url: image,
        rating: null,
        reviews_count: null,
    };
}

// ── Whole Latte Love extractors ───────────────────────────────

function extractWholeLatteLoveProduct(url) {
    // Title: from data attribute on gallery, or h1, or product__title
    let title = document.querySelector('media-gallery[data-product-title]')?.getAttribute('data-product-title');
    if (!title) title = document.querySelector('h1.product__title, h1.page-title, .product-single__title, .product__info-container h1')?.textContent?.trim();
    if (!title) return null;

    // Price: .price-item inside the product info section
    let price = null;
    const priceContainer = document.querySelector('.product__info-container .price, .product__info-wrapper .price');
    if (priceContainer) {
        const priceEl = priceContainer.querySelector('.price-item--regular, .price-item--sale');
        if (priceEl) {
            const m = priceEl.textContent.match(/\$([\d,]+(?:\.\d{2})?)/);
            if (m) price = parseFloat(m[1].replace(/,/g, ''));
        }
    }
    if (!price) {
        const anyPrice = document.querySelector('.price-item--regular, .price-item--sale');
        if (anyPrice) {
            const m = anyPrice.textContent.match(/\$([\d,]+(?:\.\d{2})?)/);
            if (m) price = parseFloat(m[1].replace(/,/g, ''));
        }
    }

    // Brand/vendor
    let brand = null;
    const vendorEl = document.querySelector('.product__text a[href*="/collections/vendors"], .product__vendor a, [itemprop="brand"]');
    if (vendorEl) brand = vendorEl.textContent?.trim();

    // Image: first product gallery image (skip 3D model previews)
    let image = null;
    const imgEl = document.querySelector('.product__media-item--variant.is-active img, .product__media-item:first-child img, .product__media img');
    if (imgEl) {
        image = imgEl.getAttribute('src') || '';
        if (image.startsWith('//')) image = 'https:' + image;
    }

    // Rating from stamped/judge.me/SPR review badges
    let rating = null;
    const ratingEl = document.querySelector('[data-rating], .stamped-badge [data-rating], .jdgm-prev-badge [data-average-rating]');
    if (ratingEl) rating = parseFloat(ratingEl.getAttribute('data-rating') || ratingEl.getAttribute('data-average-rating'));

    let reviews_count = null;
    const reviewsEl = document.querySelector('[data-number-of-reviews], .stamped-badge-caption, .jdgm-prev-badge [data-number-of-reviews]');
    if (reviewsEl) {
        const m = (reviewsEl.getAttribute('data-number-of-reviews') || reviewsEl.textContent).match(/(\d+)/);
        if (m) reviews_count = parseInt(m[1]);
    }

    return {
        url,
        store_slug: 'whole-latte-love',
        raw_title: title,
        brand,
        scraped_price: price,
        image_url: image,
        rating,
        reviews_count,
    };
}

function extractWholeLatteLoveListing() {
    const products = [];
    const seen = new Set();
    const host = window.location.hostname.replace(/^www\./, '');

    // Find all product links, then walk up to find their parent card container
    document.querySelectorAll('a[href*="/products/"]').forEach(linkEl => {
        try {
            const href = linkEl.getAttribute('href');
            if (!href || !href.includes('/products/')) return;

            // Normalize and deduplicate
            const cleanHref = href.split('?')[0].split('#')[0];
            if (seen.has(cleanHref)) return;

            // Skip tiny links (nav, footer, breadcrumbs) — only want product cards
            // Walk up to find the card container
            const card = linkEl.closest('.card, .product-card, .grid__item, li, article');
            if (!card) return;

            // Skip if this card has no image (likely a nav/text link)
            const imgEl = card.querySelector('img');
            if (!imgEl) return;

            seen.add(cleanHref);

            // Title: from the link text, or card heading, or image alt
            let title = linkEl.textContent.trim();
            if (!title || title.length < 3) {
                const headingEl = card.querySelector('h3, h2, .card__heading');
                title = headingEl?.textContent?.trim();
            }
            if (!title || title.length < 3) {
                title = imgEl.getAttribute('alt')?.trim();
            }
            if (!title || title.length < 3) return;

            // Price
            let price = null;
            const priceEl = card.querySelector('.price-item--regular, .price-item--sale, .price .money, [data-price]');
            if (priceEl) {
                const m = priceEl.textContent.match(/\$([\d,]+(?:\.\d{2})?)/);
                if (m) price = parseFloat(m[1].replace(/,/g, ''));
            }

            // Brand
            let brand = null;
            const brandEl = card.querySelector('.card__badge, .caption-with-letter-spacing, .product-card__vendor, .vendor');
            if (brandEl) {
                const text = brandEl.textContent.trim();
                if (text && !['sale', 'sold out', 'new', '10%'].some(kw => text.toLowerCase().includes(kw))) {
                    brand = text;
                }
            }

            // Image — best quality from srcset
            let image = imgEl.getAttribute('src') || '';
            if (image.startsWith('//')) image = 'https:' + image;
            const srcset = imgEl.getAttribute('srcset');
            if (srcset) {
                const parts = srcset.split(',').map(s => s.trim());
                const last = parts[parts.length - 1];
                const m = last.match(/^(https?:\/\/[^\s]+|\/\/[^\s]+)/);
                if (m) {
                    image = m[1];
                    if (image.startsWith('//')) image = 'https:' + image;
                }
            }

            const fullUrl = cleanHref.startsWith('http') ? cleanHref : `https://${host}${cleanHref}`;

            products.push({
                url: fullUrl,
                store_slug: 'whole-latte-love',
                raw_title: title,
                brand,
                scraped_price: price,
                image_url: image,
                rating: null,
                reviews_count: null,
            });
        } catch (e) {
            console.warn('PW2D: Skipped WLL product:', e);
        }
    });

    console.log(`PW2D: Extracted ${products.length} products from Whole Latte Love listing.`);
    return products;
}
