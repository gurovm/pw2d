<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Spec 039 T3 — the Stage 1 / 2 / 2.5 / 3 gate-rules text, extracted from what
 * used to be inlined directly in {@see \App\Services\AiService::evaluateProduct()}.
 * That method builds its prompt as its own preamble (persona + the specific
 * product's name/price/rating/feature-list context) followed by
 * `self::text($categoryName, $categoryNotes)`.
 *
 * One source of truth for the gate rules, consumed by two callers:
 *   - `AiService::evaluateProduct()` — feeds it straight into the Gemini prompt.
 *   - `App\Actions\ExportPendingProducts` (Spec 039 T3) — feeds it into the
 *     `rules` field of the operator-session export file.
 *
 * Spec 041: `wrong_category` (rule D) now lives in the shared text, so the
 * former session-only addendum is gone and both producers see identical rules.
 * The assembled prompt is pinned by tests/Unit/Services/AiServicePromptSnapshotTest.php;
 * re-capture the fixture deliberately when the text changes.
 */
final class BouncerRules
{
    /**
     * "=== STAGE 1" through the closing JSON-shape instruction. Do not
     * reformat/reflow this string — the snapshot test compares the assembled
     * prompt byte-for-byte against a fixture.
     *
     * @param ?string $categoryNotes Per-category owner-approved notes
     *   (`categories.bouncer_notes`): what does not belong, and what counts as
     *   a different model. Emitted right after rule D; a blank value leaves the
     *   line out entirely.
     */
    public static function text(string $categoryName, ?string $categoryNotes = null): string
    {
        $notes = trim((string) $categoryNotes);
        $notesBlock = $notes === '' ? '' : "Category-specific rules: {$notes}\n\n";

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
            . "IGNORE RULE D — WRONG PRODUCT TYPE: Ignore if the product is clearly a different kind of product than \"{$categoryName}\" "
            . "(e.g. a mouse in a keyboard category, a shotgun microphone in a lavalier category), or matches the category-specific rules below. "
            . "It may be a real, well-branded item; it simply does not belong in this category.\n"
            . 'To ignore, return EXACTLY: {"status": "ignored", "reason": "wrong_category"}' . "\n\n"
            . $notesBlock
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
            . "It MUST start with the brand exactly as you return it in \"brand\". "
            . "Leave out category nouns ('Manual Coffee Grinder', 'Wireless Mechanical Keyboard'): the page title already adds the category. "
            . "Strip marketing descriptors, feature lists, compatibility lists, pack counts, and specs "
            . "(e.g. 'Keychron K6' not 'Keychron K6 Bluetooth 5.1 Wireless Mechanical Keyboard with ... 68 Keys Compact ...').\n"
            . "- MODEL RULE: \"model\" is the model identity a buyer would name, WITHOUT the brand and WITHOUT colour, size, material, finish, "
            . "bundle contents, pack count or region. KEEP tier (Pro, Max, Mini, Plus, X, SE), generation (V3, Gen 2, 5th Gen) and form factor (TKL, 75%). "
            . "Different models must get different values; the same model in another colour or size must get the same value. Examples:\n"
            . "  'Razer BlackWidow V3 Pro' → 'BlackWidow V3 Pro' (NOT the same model as 'Huntsman V3 Pro')\n"
            . "  'Jabra Evolve2 50 UC Stereo' → 'Evolve2 50' (NOT the same model as 'Evolve2 55')\n"
            . "  'Hario V60 Ceramic Coffee Dripper 02, White' → 'V60 Dripper' (every V60 size and material)\n"
            . "  'Breville Oracle Jet Espresso Machine, Black Truffle' → 'Oracle Jet' (every colourway)\n"
            . "  'Logitech Wave Keys Wireless Ergonomic Keyboard, Graphite' → 'Wave Keys'\n\n"
            . "=== STAGE 3: SCORING RULES ===\n\n"
            . "1. WORLD KNOWLEDGE OVERRIDES EVERYTHING: Base scores on your internal knowledge of this specific model.\n"
            . "2. ABSOLUTE SCORING (1-100): 50 = average/mediocre. Budget brands CANNOT score 80+ on quality features.\n"
            . "3. STRICT TRADE-OFFS: Create contrast. If a feature is irrelevant or bad, score it 20-40.\n"
            . "4. OBSCURE PRODUCTS: If you don't recognise the model, infer from brand tier + price. Default to 40-50.\n\n"
            . "Return ONLY a valid JSON object in this EXACT format (no markdown, no code blocks):\n"
            . '{"name": "Brand Model", "brand": "Normalized Brand Name", "model": "Model Without Brand", "ai_summary": "Brutal 2-sentence summary.", '
            . '"price_tier": 2, "amazon_rating": null, "amazon_reviews_count": null, '
            . '"features": {"Feature_Name": {"score": 75, "reason": "One sentence."}, "Other_Feature": null}}';
    }
}
