<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Spec 039 T3 — the Stage 1 / 2 / 2.5 / 3 gate-rules text, extracted verbatim
 * (byte-for-byte, pinned by tests/Unit/Services/AiServicePromptSnapshotTest.php)
 * from what used to be inlined directly in {@see \App\Services\AiService::evaluateProduct()}.
 * That method now builds its prompt as its own preamble (persona + the specific
 * product's name/price/rating/feature-list context) followed by
 * `self::text($categoryName)` — nothing about the rules themselves changed.
 *
 * One source of truth for the gate rules, consumed by two callers:
 *   - `AiService::evaluateProduct()` — feeds it straight into the Gemini prompt.
 *   - `App\Actions\ExportPendingProducts` (Spec 039 T3) — feeds it into the
 *     `rules` field of the operator-session export file, WITH
 *     {@see self::sessionAddendum()} appended. That addendum must never reach
 *     the Gemini prompt — `wrong_category` is a session-only vocabulary
 *     addition (see {@see \App\Support\ProductEvaluation}'s docblock).
 */
final class BouncerRules
{
    /**
     * Byte-identical to the text formerly inlined in `evaluateProduct()`
     * starting at "=== STAGE 1" through the closing JSON-shape instruction.
     * Do not reformat/reflow this string — the snapshot test compares the
     * assembled prompt byte-for-byte against a fixture captured before this
     * extraction.
     */
    public static function text(string $categoryName): string
    {
        return "=== STAGE 1: DATA QUALITY GATE ===\n\n"
            . "CRITICAL: Only ignore products that are CLEARLY not a main device in the \"{$categoryName}\" category.\n"
            . "When in doubt, SCORE the product — do NOT ignore it. False ignores are worse than scoring a marginal product.\n\n"
            . "IGNORE RULE A — ACCESSORIES ONLY: Ignore ONLY if the product is clearly NOT a standalone main device in \"{$categoryName}\":\n"
            . "  - Replacement parts, spare components, or consumables\n"
            . "  - Accessories, add-ons, stands, mounts, cases, or cleaning supplies\n"
            . "  - Cables, adapters, or converters\n"
            . "  - Multi-item bundles that are NOT centered on a single main device\n"
            . "DO NOT ignore: color/size variants or products with verbose titles.\n"
            . "If the product functions as a standalone {$categoryName} device, you MUST score it.\n"
            . 'To ignore, return EXACTLY: {"status": "ignored", "reason": "accessory_or_bundle"}' . "\n\n"
            . "IGNORE RULE B — GENERIC / WHITE-LABEL: If the product has no recognizable, reputable brand.\n"
            . "This includes 'Generic', 'Unbranded', random Chinese model numbers as brands, and ultra-cheap no-name products.\n"
            . "Only ignore true no-name items with titles like 'Generic', 'Unbranded', or random model numbers as the brand.\n"
            . 'To ignore, return EXACTLY: {"status": "ignored", "reason": "generic_white_label"}' . "\n\n"
            . "IGNORE RULE C — LISTING CONDITION: Ignore if the product name, title, or any provided context "
            . "indicates the listing is Renewed, Refurbished, Open Box, or otherwise not brand-new/first-party.\n"
            . 'To ignore, return EXACTLY: {"status": "ignored", "reason": "renewed_or_refurbished"}' . "\n\n"
            . "=== STAGE 2: BRAND NORMALIZATION ===\n\n"
            . "Unify brand names to their most common, clean English-language form. Strict rules:\n"
            . "- Strip non-ASCII characters used as stylistic affectations: 'RØDE' → 'Rode', 'Beyerdynamic' stays.\n"
            . "- Remove subsidiary/division suffixes: 'AKG Professional' → 'AKG', 'Blue Microphones' → 'Blue'.\n"
            . "- Resolve umbrella brands: '512 Audio by Warm Audio' → 'Warm Audio'.\n"
            . "- Always use the parent consumer brand, not the Amazon storefront name.\n"
            . "- Capitalize correctly: 'BRANDNAME' → 'Brandname'.\n"
            . "- Apostrophe handling: KEEP apostrophes that are part of the standard English brand name. \"De'Longhi\" stays \"De'Longhi\". Only strip non-ASCII stylistic characters.\n"
            . "- Use the Wikipedia article title as the canonical brand spelling. Be consistent across calls.\n\n"
            . "=== STAGE 2.5: NAME NORMALIZATION ===\n\n"
            . "The raw Amazon title is verbose marketing copy. You MUST produce a clean, short product name:\n"
            . "- Keep ONLY: Brand + Model name + essential differentiator (e.g. color or size variant if it's the main SKU distinction).\n"
            . "- STRIP everything after a comma or slash in the title that lists specs or compatibility:\n"
            . "  'Hollyland Lark M2 Wireless Microphone for iPhone/Camera/Android/PC, 48kHz/24-bit...' → 'Hollyland Lark M2'\n"
            . "- STRIP parenthetical variant/bundle info entirely: '(Black, with Camera RX + USB-C RX)' → remove.\n"
            . "- STRIP marketing adjectives that are not part of the official model name: 'High Fidelity', 'Premium', 'Professional'.\n"
            . "- Maximum 60 characters. When in doubt, use only Brand + Model (e.g. 'Sony WH-1000XM5', 'Shure MV7+', 'Rode NT-USB Mini').\n"
            . "- NAME RULE: \"name\" must be the concise product identity — brand + model/series + key variant only, MAXIMUM 8 words. "
            . "Strip marketing descriptors, feature lists, compatibility lists, pack counts, and specs "
            . "(e.g. 'Keychron K6' not 'Keychron K6 Bluetooth 5.1 Wireless Mechanical Keyboard with ... 68 Keys Compact ...').\n\n"
            . "=== STAGE 3: SCORING RULES ===\n\n"
            . "1. WORLD KNOWLEDGE OVERRIDES EVERYTHING: Base scores on your internal knowledge of this specific model.\n"
            . "2. ABSOLUTE SCORING (1-100): 50 = average/mediocre. Budget brands CANNOT score 80+ on quality features.\n"
            . "3. STRICT TRADE-OFFS: Create contrast. If a feature is irrelevant or bad, score it 20-40.\n"
            . "4. OBSCURE PRODUCTS: If you don't recognise the model, infer from brand tier + price. Default to 40-50.\n\n"
            . "Return ONLY a valid JSON object in this EXACT format (no markdown, no code blocks):\n"
            . '{"name": "Brand Model", "brand": "Normalized Brand Name", "ai_summary": "Brutal 2-sentence summary.", '
            . '"price_tier": 2, "amazon_rating": null, "amazon_reviews_count": null, '
            . '"features": {"Feature_Name": {"score": 75, "reason": "One sentence."}, "Other_Feature": null}}';
    }

    /**
     * Spec 039 §2 T1/T3 — describes the one session-only ignore reason,
     * `wrong_category`, that Gemini's Stage 1 never emits. Appended to
     * {@see self::text()} for the operator-session export's `rules` field
     * ONLY — never concatenated into the Gemini prompt.
     */
    public static function sessionAddendum(): string
    {
        return "=== SESSION-ONLY: WRONG CATEGORY ===\n\n"
            . "You are evaluating in an operator session, not as the automated Bouncer, so one additional "
            . "ignore reason is available to you: `wrong_category`. Use it when the product is a real, "
            . "sellable, correctly-branded item — it simply does not belong in THIS category at all "
            . "(e.g. a shotgun/boom microphone submitted under a \"Lavalier & Wireless Systems\" category). "
            . "Do not use it for anything that IGNORE RULE A/B/C above already covers — those still apply first.\n"
            . 'To flag this, return: {"status": "ignored", "reason": "wrong_category"}' . "\n";
    }
}
