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

// ─────────────────────────────────────────────────────────────
// Listing health detection — Spec 029 B1 (Amazon product pages)
// ─────────────────────────────────────────────────────────────

/**
 * Map a raw listing title to a condition value, or null when clean.
 * Marker list mirrors App\Support\ProductConditionGuard::TITLE_MARKERS,
 * vocabulary mirrors App\Support\ListingHealth::CONDITIONS.
 * Pure function of its input string (testable).
 */
function conditionMarkerFromText(text) {
    if (!text) return null;
    const t = text.toLowerCase();
    if (/\brenewed\b/.test(t)) return 'renewed';
    if (/refurbish/.test(t)) return 'refurbished';       // refurbished / refurbishment / certified refurbished
    if (/open[\s-]?box/.test(t)) return 'open_box';
    if (/\bpre-?owned\b/.test(t)) return 'used';
    // 029B-S3: bare "used" only in parenthetical or leading position — "(Used)",
    // "(Certified Used)", "Used - Like New", leading "Used …". Mid-title verb
    // phrasing ("…can be used with…") must never mark a listing.
    if (/\(\s*(?:certified\s+)?used\b[^)]*\)/.test(t) || /^\s*used\b/.test(t)) return 'used';
    return null;
}

/**
 * Detect listing health {condition, listing_flags} from an Amazon product-page
 * Document. Pure function of `doc` (testable against saved DOM fixtures).
 *
 * ALL condition/flag selector + text logic lives in THIS one function —
 * Amazon DOM churn is expected, so there is exactly one place to fix.
 * Text-content scanning is preferred over brittle CSS classes.
 *
 * Markers checked (naming mirrors App\Support\ProductConditionGuard /
 * App\Support\ListingHealth):
 *   condition (new | renewed | refurbished | open_box | used | unknown):
 *     - product title parenthetical/phrase: "(Renewed)", "Refurbished",
 *       "Open Box", "Pre-Owned", "Used"
 *     - byline/brand link: "Amazon Renewed" / "Visit the Amazon Renewed Store"
 *     - standalone "Renewed" / "Open Box" / "Refurbished" badge near the title
 *     - 'new' ONLY when a normal buy-box affirmatively loaded and no marker hit;
 *       'unknown' when the page looks partial/blocked (checkForRobot() signals)
 *   listing_flags:
 *     - 'high_price': Amazon's buy-box "High price" label (exact leaf text,
 *       tooltip "We have recently seen better prices…", adjacent "Learn more"
 *       disclosure). Scoped to the buy-box / right-hand column so review text
 *       can never false-positive.
 *     - 'unavailable': the availability block stating "Currently unavailable"
 *       (#availability is the classic anchor; exact-leaf-text scan of the
 *       buy-box column is the DOM-churn fallback). NOT a condition — the
 *       product is fine, the listing just cannot be bought today.
 */
function detectListingHealth(doc) {
    doc = doc || document;
    const health = { condition: 'unknown', listing_flags: [] };

    // Partial/blocked page (same signals as checkForRobot()) → unknown.
    if (!doc.body) return health;
    if ((doc.title || '').includes('Robot Check') ||
        doc.body.innerText.includes('Enter the characters you see below')) {
        return health;
    }

    const titleEl = doc.querySelector('#productTitle');

    // ── Condition ────────────────────────────────────────────
    let condition = null;

    // (a) Title parenthetical/phrase markers
    if (titleEl) condition = conditionMarkerFromText(titleEl.textContent);

    // (b) Byline/brand link text
    if (!condition) {
        const byline = doc.querySelector('#bylineInfo, #bylineInfo_feature_div');
        if (byline && /amazon\s+renewed/i.test(byline.textContent)) condition = 'renewed';
    }

    // (c) Standalone badge near the title block — leaf elements whose ENTIRE
    //     text is the badge word (exact match avoids review/description hits)
    if (!condition) {
        const titleRegion = doc.querySelector('#title_feature_div, #titleSection, #centerCol');
        if (titleRegion) {
            for (const el of titleRegion.querySelectorAll('span, div, a')) {
                if (el.children.length > 0) continue; // leaf nodes only
                const text = el.textContent.trim();
                if (/^renewed$/i.test(text)) { condition = 'renewed'; break; }
                if (/^(certified\s+)?refurbished$/i.test(text)) { condition = 'refurbished'; break; }
                if (/^open[\s-]?box$/i.test(text)) { condition = 'open_box'; break; }
            }
        }
    }

    if (condition) {
        health.condition = condition;
    } else {
        // 'new' ONLY when the page affirmatively loaded a normal product +
        // buy-box; anything less stays 'unknown' (partial render, blocked, etc.)
        const buyBoxLoaded = !!(titleEl && doc.querySelector(
            '#add-to-cart-button, #buy-now-button, #buybox, #addToCart_feature_div, #availability, #outOfStock'
        ));
        health.condition = buyBoxLoaded ? 'new' : 'unknown';
    }

    // ── Flags ────────────────────────────────────────────────
    // "High price" buy-box label: exact leaf text, scoped to the buy-box /
    // right-hand column region only (never the review/description areas).
    const buyBoxRegion = doc.querySelector('#rightCol, #desktop_buybox, #buybox, #apex_desktop');
    if (buyBoxRegion) {
        for (const el of buyBoxRegion.querySelectorAll('span, h1, h2, h3, h4, h5, h6, div')) {
            if (el.children.length > 0) continue; // leaf nodes only
            if (el.textContent.trim() === 'High price') {
                health.listing_flags.push('high_price');
                break;
            }
        }
    }

    // "Currently unavailable" availability block → 'unavailable' flag.
    // #availability/#outOfStock is the classic anchor; the fallback is an
    // exact-leaf-text scan scoped to the buy-box column (same anti-false-
    // positive discipline as 'high_price' — review/description text never hits).
    const availEl = doc.querySelector('#availability, #outOfStock');
    if (availEl && /currently unavailable/i.test(availEl.textContent)) {
        health.listing_flags.push('unavailable');
    } else if (buyBoxRegion) {
        for (const el of buyBoxRegion.querySelectorAll('span, div')) {
            if (el.children.length > 0) continue; // leaf nodes only
            if (/^currently unavailable\.?$/i.test(el.textContent.trim())) {
                health.listing_flags.push('unavailable');
                break;
            }
        }
    }

    // An affirmatively detected "Currently unavailable" block IS a fully loaded
    // page — a titled, unavailable listing must not be demoted to 'unknown' just
    // because the buy-box has no buttons ('unknown' is stamp-only server-side and
    // would drop the flag, resurrecting the endless-rescan loop).
    if (health.condition === 'unknown' && titleEl && health.listing_flags.includes('unavailable')) {
        health.condition = 'new';
    }

    return health;
}

/**
 * Detect listing health {condition, listing_flags} for the NON-AMAZON storefronts
 * (Clive Coffee, Seattle Coffee Gear, Whole Latte Love) from a product-page
 * Document — the sibling of detectListingHealth() above. Pure function of `doc`
 * (+ an optional caller-supplied title hint), so it is testable against saved DOM
 * fixtures. ALL selector/text logic for these stores lives in THIS one function.
 *
 * Vocabulary mirrors App\Support\ListingHealth exactly:
 *   condition ∈ new | renewed | refurbished | open_box | used | unknown
 *   listing_flags ⊆ high_price | unavailable
 *
 * DELIBERATE SCOPE (Spec 029 B1, 2026-08-14):
 * - `condition` is only ever 'new' or 'unknown'. These are authorised dealers
 *   selling new goods — they do not sell renewed/refurb/open-box, so guessing a
 *   condition from their DOM would be noise, and silence is better than a guess.
 *   Reporting 'new' is honest AND load-bearing: it is what lets
 *   ListingHealthService stamp `health_checked_at` and CLEAR a previously-set
 *   `unavailable` flag when an item comes back in stock. Sending nothing (today's
 *   behaviour) makes apply() a no-op and the offer never gets stamped at all.
 * - 'unknown' whenever no product title was found: the page is partial/failed, so
 *   nothing on it is trustworthy — never fabricate 'new' for a page that did not
 *   load. Flags are withheld too (the server distrusts flags on 'unknown', and
 *   both POST paths strip the keys entirely — 029B-B3).
 * - NO `high_price`: that flag is Amazon's own buy-box label. These stores have
 *   no first-party equivalent, and inventing one from price comparison is not
 *   this function's job.
 *
 * UNAVAILABLE — LAYERED detection. These selectors could not be verified against
 * live Clive/WLL pages by the builder, so each layer stands alone: one theme
 * change cannot blind the detector. A POSITIVE match is always required —
 * absence of evidence means in stock, never 'unavailable'.
 *   (a) JSON-LD `application/ld+json` offers.availability (schema.org) — the most
 *       reliable signal on Shopify-style stores. DECISIVE when present: if ANY
 *       variant offer is purchasable, the product is NOT unavailable.
 *   (b) <meta property="product:availability"> / og:availability content.
 *   (c) Add-to-cart button state, scoped to the product form area: sold-out label
 *       text, or a disabled add-to-cart control whose label does not read like a
 *       normal "Add to cart" (that guard is what keeps a not-yet-hydrated theme
 *       from reading as sold out).
 *   (d) Explicit inventory/availability text: dedicated inventory elements, then
 *       an exact-leaf-text scan — same anti-false-positive discipline as the
 *       Amazon path, so a related-products "Sold out" badge can never hit.
 */
function detectStoreAvailability(doc, titleFound) {
    doc = doc || document;
    const health = { condition: 'unknown', listing_flags: [] };
    if (!doc.body) return health;

    // ── Condition ────────────────────────────────────────────
    // A product title is the one affirmative "this page actually loaded" signal.
    // Callers pass what they already know; the selector union is the fallback so
    // the function stays self-contained for fixture tests.
    const hasTitle = (typeof titleFound === 'boolean') ? titleFound : !!doc.querySelector(
        'media-gallery[data-product-title], h1.product__title, h1.font-display, h1.product-name, ' +
        'h1.page-title, .product-single__title, .product__info-container h1, h1[itemprop="name"]'
    );
    if (!hasTitle) return health; // 'unknown', no flags — page unverified
    health.condition = 'new';

    // Shared vocabulary for layers (c) and (d).
    // [\s_-]* as the word separator so every spelling these themes use is caught:
    // "Sold out", "sold-out" (data attributes / class names), "OutOfStock",
    // "out_of_stock". Same separator is used in layers (a) and (b) below.
    const SOLD_OUT_TEXT = /sold[\s_-]*out|out[\s_-]*of[\s_-]*stock|notify me when|email when available|currently unavailable|temporarily unavailable|no longer available|discontinued/i;
    const PURCHASABLE_TEXT = /add to (cart|bag|basket)|buy (it )?now|pre-?order|add to order/i;

    const flagUnavailable = () => {
        health.listing_flags.push('unavailable');
        return health;
    };

    // ── (a) JSON-LD offers.availability ──────────────────────
    // Recursive walk: availability can sit under @graph, offers[], or a nested
    // offers.offers[] depending on the theme/app — key-name matching survives all
    // of those shapes without hard-coding one.
    const availabilityValues = [];
    const collect = (node, depth) => {
        if (!node || depth > 8 || typeof node !== 'object') return;
        if (Array.isArray(node)) { node.forEach(n => collect(n, depth + 1)); return; }
        for (const [key, value] of Object.entries(node)) {
            if (key.toLowerCase() === 'availability') {
                if (typeof value === 'string') availabilityValues.push(value);
                else if (value && typeof value === 'object' && typeof value['@id'] === 'string') {
                    availabilityValues.push(value['@id']);
                }
            } else {
                collect(value, depth + 1);
            }
        }
    };
    doc.querySelectorAll('script[type="application/ld+json"]').forEach(node => {
        try { collect(JSON.parse(node.textContent), 0); } catch (e) { /* malformed block — ignore */ }
    });

    if (availabilityValues.length) {
        // schema.org tokens: InStock / OutOfStock / SoldOut / Discontinued /
        // PreOrder / BackOrder / LimitedAvailability. \s* tolerates both
        // "OutOfStock" and "out of stock" spellings.
        const purchasable = availabilityValues.some(v => /in[\s_-]*stock|pre[\s_-]*order|pre[\s_-]*sale|back[\s_-]*order|limited[\s_-]*availability/i.test(v));
        const soldOut = availabilityValues.some(v => /out[\s_-]*of[\s_-]*stock|sold[\s_-]*out|discontinued/i.test(v));
        // Any buyable variant means the offer still exists — that beats a
        // sold-out sibling variant. Only an all-sold-out product is unavailable.
        if (purchasable) return health;
        if (soldOut) return flagUnavailable();
        // Neither token (e.g. InStoreOnly) — not decisive, fall through.
    }

    // ── (b) meta availability ────────────────────────────────
    const metaValues = [];
    doc.querySelectorAll(
        'meta[property="product:availability"], meta[name="product:availability"], ' +
        'meta[property="og:availability"], meta[name="og:availability"], meta[itemprop="availability"]'
    ).forEach(el => {
        const content = el.getAttribute('content');
        if (content) metaValues.push(content);
    });
    if (metaValues.length) {
        // "\bavailable\b" cannot match inside "unavailable" (no word boundary), so
        // the purchasable test is safe to run against the same strings.
        if (metaValues.some(v => /out[\s_-]*of[\s_-]*stock|sold[\s_-]*out|discontinued|^\s*oos\s*$/i.test(v))) return flagUnavailable();
        if (metaValues.some(v => /in[\s_-]*stock|\bavailable\b|pre[\s_-]*order|back[\s_-]*order/i.test(v))) return health;
    }

    // ── Product form scope for layers (c) + (d) ──────────────
    // Everything below is scoped: an unscoped scan would hit "Sold out" badges in
    // related-product carousels. No scope found → skip both layers rather than
    // risk a false 'unavailable'.
    let scope = null;
    for (const sel of [
        'form[action*="/cart/add"]', 'product-form', '.product-form', '#product-form',
        '[data-product-form]', '.product__info-container', '.product__info-wrapper',
        '.product-single__meta', '.product__purchase', '[itemscope][itemtype*="Product"]',
    ]) {
        scope = doc.querySelector(sel);
        if (scope) break;
    }
    if (!scope) return health;

    // ── (c) Add-to-cart button state ─────────────────────────
    for (const btn of scope.querySelectorAll('button, input[type="submit"], [data-add-to-cart], .product-form__submit, .add-to-cart')) {
        const label = (btn.tagName === 'INPUT' ? (btn.getAttribute('value') || '') : btn.textContent || '').trim();
        if (SOLD_OUT_TEXT.test(label)) return flagUnavailable();

        const classes = btn.classList ? Array.from(btn.classList) : [];
        const isDisabled = btn.hasAttribute('disabled')
            || btn.getAttribute('aria-disabled') === 'true'
            || classes.some(c => /disabled|sold-?out/i.test(c));
        const isAddToCart = btn.getAttribute('name') === 'add'
            || btn.hasAttribute('data-add-to-cart')
            || btn.getAttribute('type') === 'submit'
            || classes.some(c => /add-?to-?cart|product-form__submit|btn--add/i.test(c));
        // A disabled ATC control counts, but only when its label does NOT still
        // read "Add to cart" — a theme that renders disabled until JS hydrates
        // keeps the normal label, and that must not be read as sold out.
        if (isDisabled && isAddToCart && label && !PURCHASABLE_TEXT.test(label)) return flagUnavailable();
    }

    // ── (d) Explicit inventory / availability text ───────────
    for (const el of scope.querySelectorAll('[data-inventory-status], [data-availability], .product__inventory, .product-form__inventory, .product-inventory, .inventory')) {
        if (SOLD_OUT_TEXT.test(el.textContent || '')) return flagUnavailable();
        const attr = el.getAttribute('data-inventory-status') || el.getAttribute('data-availability') || '';
        if (SOLD_OUT_TEXT.test(attr)) return flagUnavailable();
    }
    for (const el of scope.querySelectorAll('span, div, p, strong, em, label, h2, h3, h4')) {
        if (el.children.length > 0) continue;      // leaf nodes only
        if (el.closest('select')) continue;        // variant <option> labels are not the page's state
        if (/^(sold[\s_-]*out|out[\s_-]*of[\s_-]*stock|currently unavailable|temporarily unavailable|temporarily out of stock|no longer available|discontinued)[.!]?$/i.test((el.textContent || '').trim())) {
            return flagUnavailable();
        }
    }

    return health;
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
 * Extract reviews count from a SERP product-card element OR a full
 * product-page Document (pass `document`). Pure function of its scope
 * (testable). Tries strategies in order; returns NULL when nothing
 * matches — never 0 (Spec 029 B3: null means "unknown", 0 is a claim).
 */
function extractReviewsCount(el) {
    try {
        // 0a. Product page: #acrCustomerReviewText ("2,567 ratings") —
        //     the canonical element next to the title stars
        const acr = el.querySelector('#acrCustomerReviewText');
        if (acr) {
            const m = acr.textContent.match(/([\d,]+)/);
            if (m) {
                const n = parseInt(m[1].replace(/,/g, ''));
                if (n > 0) return n;
            }
        }

        // 0b. Product page reviews section: [data-hook="total-review-count"]
        //     ("1,234 global ratings")
        const hook = el.querySelector('[data-hook="total-review-count"]');
        if (hook) {
            const m = hook.textContent.match(/([\d,]+)/);
            if (m) {
                const n = parseInt(m[1].replace(/,/g, ''));
                if (n > 0) return n;
            }
        }

        // 0c. Product page: #averageCustomerReviews subtree aria-labels/text
        //     containing "N ratings" (newer layouts drop #acrCustomerReviewText)
        const avg = el.querySelector('#averageCustomerReviews');
        if (avg) {
            for (const cand of [avg, ...avg.querySelectorAll('[aria-label]')]) {
                const text = (cand.getAttribute && cand.getAttribute('aria-label')) || cand.textContent || '';
                const m = text.match(/([\d,]+)\s+(?:global\s+)?ratings?/i);
                if (m) {
                    const n = parseInt(m[1].replace(/,/g, ''));
                    if (n > 0) return n;
                }
            }
        }

        // 0d. Any anchor to the reviews section whose text says "N ratings"
        const reviewAnchor = el.querySelector('a[href*="#customerReviews"], a[href*="customerReviews"]');
        if (reviewAnchor) {
            const m = reviewAnchor.textContent.match(/([\d,]+)\s+(?:global\s+)?ratings?/i);
            if (m) {
                const n = parseInt(m[1].replace(/,/g, ''));
                if (n > 0) return n;
            }
        }

        // 029B-B2: product-page strategies (0a–0d) exhausted. On a full-page
        // Document the SERP-card heuristics below (1–5) would scan the ENTIRE
        // page — including related-product carousels — and return ANOTHER
        // product's count. Card heuristics are card-scoped only: an honest null
        // ("unknown") is the only correct answer for a zero-review product page.
        if (el.nodeType === 9 /* Node.DOCUMENT_NODE */) return null;

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

    return null; // unknown — never claim 0 (server keeps the stored value)
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
            // Skip sponsored labels, ad widgets, and non-product cards.
            // Spec 029 B4: condition-marked titles (renewed/refurbished/…) are
            // NO LONGER skipped client-side — they're sent with an explicit
            // `condition` so the server can flag/heal the listing.
            const titleLower = title.toLowerCase();
            if (/^sponsored$/i.test(title)) return;
            if (/\bpackage\b/i.test(title)) return;
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

            // Spec 029 B4: title stays untouched raw; when it carries a condition
            // marker, report it explicitly so the server flags instead of guessing
            const condition = conditionMarkerFromText(title);

            products.push({
                asin, title, price, rating, reviews_count, image_url,
                status: 'pending_ai',
                ...(condition ? { condition } : {}),
            });

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

    // Derive stock_status from the availability block. An unavailable/out-of-stock
    // page NO LONGER early-returns (Spec 029 `unavailable` flag): #productTitle,
    // image and reviews are all still present on those pages, so extraction
    // proceeds normally and the rescan POSTs them like any other page — the server
    // stamps health_checked_at and flags the offer instead of the old client-side
    // skip that left these pages heading the rescan list forever.
    let stock_status = null;
    let isUnavailable = false;
    const availEl = document.querySelector('#availability, #outOfStock');
    if (availEl) {
        const text = availEl.textContent.toLowerCase();
        if (text.includes('currently unavailable') || text.includes('out of stock')) {
            stock_status = 'out_of_stock';
            isUnavailable = true;
        } else if (text.includes('in stock')) {
            stock_status = 'in_stock';
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
    // An unavailable listing has no buy-box price — anything the fallback
    // strategies caught (stale meta tag, carousel leftovers) is not THIS
    // listing's price today. Send null.
    if (isUnavailable) price = null;

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

    // Reviews count — shared multi-strategy extractor; null when absent (B3)
    const reviews_count = extractReviewsCount(document);

    // Image
    const image_url = getImageUrl();

    return { asin, title, price, rating, reviews_count, image_url, stock_status, status: 'pending_ai' };
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

    } else if (request.action === 'RESCAN_EXTRACT') {
        // Spec 029 B2: full-field refresh extraction for the rescan walk.
        // EVERY store now carries condition/listing_flags: Amazon via
        // detectListingHealth() inside extractAmazonProduct(), the other three via
        // detectStoreAvailability() (availability only — v1.3, 2026-08-14).
        if (checkForRobot()) { sendResponse({ success: false, robot: true }); return; }
        sendResponse(extractStoreProduct());

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

    // Listing health (Spec 029 B1) — DOM-verified condition + flags
    const health = detectListingHealth(document);

    return {
        url,
        store_slug: 'amazon',
        asin: data.asin,
        raw_title: data.title,
        brand,
        scraped_price: data.price,
        image_url: data.image_url,
        stock_status: data.stock_status ?? null,
        rating: data.rating,
        reviews_count: data.reviews_count,
        condition: health.condition,
        listing_flags: health.listing_flags,
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

    // Reviews count — try stars widget title attribute first ("59 reviews"),
    // then fall back to dedicated review count elements.
    let reviews_count = null;
    if (starsEl) {
        const m = starsEl.getAttribute('title')?.match(/(\d+)\s+reviews?/i);
        if (m) reviews_count = parseInt(m[1]);
    }
    if (reviews_count === null) {
        const reviewsEl = document.querySelector('.stars-scale__reviews_count, [itemprop="votes"]');
        if (reviewsEl) {
            const m = reviewsEl.textContent.match(/(\d+)/);
            if (m) reviews_count = parseInt(m[1]);
        }
    }

    // Listing health (Spec 029 B1, 2026-08-14) — availability only; see
    // detectStoreAvailability() for the deliberate no-condition/no-high_price scope.
    const health = detectStoreAvailability(document, true);
    const unavailable = health.listing_flags.includes('unavailable');
    // Amazon precedent (extractProductPageData): never ship a bogus number for a
    // listing you cannot buy. But Shopify-style stores normally keep showing the
    // real price when sold out, so only drop a missing/placeholder value.
    if (unavailable && !(price > 0)) price = null;

    return {
        url,
        store_slug: 'clive-coffee',
        raw_title: title,
        brand,
        scraped_price: price,
        image_url: image,
        stock_status: unavailable ? 'out_of_stock' : 'in_stock',
        rating,
        reviews_count,
        condition: health.condition,
        listing_flags: health.listing_flags,
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

    // Listing health (Spec 029 B1, 2026-08-14) — availability only.
    const health = detectStoreAvailability(document, true);
    const unavailable = health.listing_flags.includes('unavailable');
    if (unavailable && !(price > 0)) price = null;

    return {
        url,
        store_slug: 'seattle-coffee-gear',
        raw_title: title,
        brand,
        scraped_price: price,
        image_url: image,
        stock_status: unavailable ? 'out_of_stock' : 'in_stock',
        rating: null,
        reviews_count: null,
        condition: health.condition,
        listing_flags: health.listing_flags,
    };
}

// ── Whole Latte Love extractors ───────────────────────────────

/**
 * Extract rating + reviews_count from a WLL card or product page.
 * Handles Yotpo widgets (aria-label/title on stars span, or visible "N Reviews" text),
 * stamped.io, judge.me, and generic data-rating/data-number-of-reviews attributes.
 * Returns { rating, reviews_count } with null values if not found.
 */
function extractWllRatingAndReviews(scope) {
    scope = scope || document;
    let rating = null;
    let reviews_count = null;

    // 1. Yotpo: any element with aria-label/title matching "X out 5 stars ... N reviews"
    //    The attribute may be on the button (product page) or on a nested span (listing page).
    const yotpoSelectors = [
        '[aria-label*="stars rating in total"]',
        '[title*="stars rating in total"]',
        '[aria-label*="out 5 stars"]',
        '[title*="out 5 stars"]',
    ];
    for (const sel of yotpoSelectors) {
        const el = scope.querySelector(sel);
        if (!el) continue;
        const label = el.getAttribute('aria-label') || el.getAttribute('title') || '';
        const rm = label.match(/([\d.]+)\s+out\s+(?:of\s+)?5/i);
        if (rm) rating = parseFloat(rm[1]);
        const cm = label.match(/(\d[\d,]*)\s+reviews?/i);
        if (cm) reviews_count = parseInt(cm[1].replace(/,/g, ''));
        if (rating !== null || reviews_count !== null) break;
    }

    // 2. Yotpo: visible "N Reviews" text
    if (reviews_count === null) {
        const yotpoText = scope.querySelector('.yotpo-sr-bottom-line-text');
        if (yotpoText) {
            const m = yotpoText.textContent.match(/(\d[\d,]*)\s+reviews?/i);
            if (m) reviews_count = parseInt(m[1].replace(/,/g, ''));
        }
    }

    // 3. Legacy: stamped/judge.me/SPR data attributes
    if (rating === null) {
        const ratingEl = scope.querySelector('[data-rating], .stamped-badge [data-rating], .jdgm-prev-badge [data-average-rating]');
        if (ratingEl) rating = parseFloat(ratingEl.getAttribute('data-rating') || ratingEl.getAttribute('data-average-rating'));
    }
    if (reviews_count === null) {
        const reviewsEl = scope.querySelector('[data-number-of-reviews], .stamped-badge-caption, .jdgm-prev-badge [data-number-of-reviews]');
        if (reviewsEl) {
            const m = (reviewsEl.getAttribute('data-number-of-reviews') || reviewsEl.textContent).match(/(\d[\d,]*)/);
            if (m) reviews_count = parseInt(m[1].replace(/,/g, ''));
        }
    }

    return { rating, reviews_count };
}

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

    // Rating + reviews count (Yotpo, stamped, judge.me, etc.)
    const { rating, reviews_count } = extractWllRatingAndReviews(document);

    // Listing health (Spec 029 B1, 2026-08-14) — availability only.
    const health = detectStoreAvailability(document, true);
    const unavailable = health.listing_flags.includes('unavailable');
    if (unavailable && !(price > 0)) price = null;

    return {
        url,
        store_slug: 'whole-latte-love',
        raw_title: title,
        brand,
        scraped_price: price,
        image_url: image,
        stock_status: unavailable ? 'out_of_stock' : 'in_stock',
        rating,
        reviews_count,
        condition: health.condition,
        listing_flags: health.listing_flags,
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

            // Title: prefer heading or image alt over raw link text
            // (raw link text can be UI chrome like "View 1 more material option")
            let title = null;
            const headingEl = card.querySelector('h2 a, h3 a, h2.card__heading, h3.card__heading, .card__heading, .product-listing__title');
            if (headingEl) title = headingEl.textContent.trim();
            if (!title || title.length < 3) {
                title = imgEl.getAttribute('alt')?.trim();
            }
            if (!title || title.length < 3) {
                title = linkEl.textContent.trim();
            }
            if (!title || title.length < 3) return;

            // Skip UI chrome that matched as a product link (swatch labels, "View more" links)
            if (/^view\s+\d+\s+more|^see\s+all|^show\s+more|^\+$/i.test(title)) return;

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

            // Rating + reviews count scoped to this card (Yotpo widget)
            const { rating, reviews_count } = extractWllRatingAndReviews(card);

            products.push({
                url: fullUrl,
                store_slug: 'whole-latte-love',
                raw_title: title,
                brand,
                scraped_price: price,
                image_url: image,
                rating,
                reviews_count,
            });
        } catch (e) {
            console.warn('PW2D: Skipped WLL product:', e);
        }
    });

    console.log(`PW2D: Extracted ${products.length} products from Whole Latte Love listing.`);
    return products;
}
