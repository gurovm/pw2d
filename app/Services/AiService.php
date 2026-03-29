<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Central AI service — the ONLY place that builds prompts for the Gemini API.
 *
 * Callers pass structured data; this service constructs prompt templates
 * and delegates transport to GeminiService.
 */

use App\Models\AiMatchingDecision;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

class AiService
{
    public function __construct(private GeminiService $gemini) {}

    /**
     * Full product evaluation: quality gate + brand/name normalization + scoring.
     * Used by ProcessPendingProduct (new imports from Chrome extension).
     */
    public function evaluateProduct(
        string $productName,
        ?float $scrapedPrice,
        string $priceNote,
        string $ratingNote,
        string $categoryName,
        array $featureMap,
    ): array {
        $featureJson = json_encode($featureMap, JSON_PRETTY_PRINT);

        $prompt = "You are a ruthless, highly skeptical technology appraiser AND data architect for a premium comparison website.\n"
            . "Your primary job is to score this product using your WORLD KNOWLEDGE of the brand and model.\n"
            . "You are also the last line of defense against dirty, polluted data entering our database.\n\n"
            . "Product name: \"{$productName}\"\n"
            . "Scraped price: \${$scrapedPrice}\n"
            . "Price tier: {$priceNote}\n"
            . "Amazon rating: {$ratingNote}\n\n"
            . "Category features to score:\n{$featureJson}\n\n"
            . "=== STAGE 1: DATA QUALITY GATE ===\n\n"
            . "CRITICAL: Only ignore products that are CLEARLY not a main device in the \"{$categoryName}\" category.\n"
            . "When in doubt, SCORE the product — do NOT ignore it. False ignores are worse than scoring a marginal product.\n\n"
            . "IGNORE RULE A — ACCESSORIES ONLY: Ignore ONLY if the product is clearly NOT a standalone main device in \"{$categoryName}\":\n"
            . "  - Replacement parts, spare components, or consumables\n"
            . "  - Accessories, add-ons, stands, mounts, cases, or cleaning supplies\n"
            . "  - Cables, adapters, or converters\n"
            . "  - Multi-item bundles that are NOT centered on a single main device\n"
            . "DO NOT ignore: color/size variants, refurbished units, or products with verbose titles.\n"
            . "If the product functions as a standalone {$categoryName} device, you MUST score it.\n"
            . 'To ignore, return EXACTLY: {"status": "ignored", "reason": "accessory_or_bundle"}' . "\n\n"
            . "IGNORE RULE B — GENERIC / WHITE-LABEL: If the product has no recognizable, reputable brand.\n"
            . "This includes 'Generic', 'Unbranded', random Chinese model numbers as brands, and ultra-cheap no-name products.\n"
            . "Only ignore true no-name items with titles like 'Generic', 'Unbranded', or random model numbers as the brand.\n"
            . 'To ignore, return EXACTLY: {"status": "ignored", "reason": "generic_white_label"}' . "\n\n"
            . "=== STAGE 2: BRAND NORMALIZATION ===\n\n"
            . "Unify brand names to their most common, clean English-language form. Strict rules:\n"
            . "- Strip non-ASCII characters used as stylistic affectations: 'RØDE' → 'Rode', 'Beyerdynamic' stays.\n"
            . "- Remove subsidiary/division suffixes: 'AKG Professional' → 'AKG', 'Blue Microphones' → 'Blue'.\n"
            . "- Resolve umbrella brands: '512 Audio by Warm Audio' → 'Warm Audio'.\n"
            . "- Always use the parent consumer brand, not the Amazon storefront name.\n"
            . "- Capitalize correctly: 'BRANDNAME' → 'Brandname'.\n\n"
            . "=== STAGE 2.5: NAME NORMALIZATION ===\n\n"
            . "The raw Amazon title is verbose marketing copy. You MUST produce a clean, short product name:\n"
            . "- Keep ONLY: Brand + Model name + essential differentiator (e.g. color or size variant if it's the main SKU distinction).\n"
            . "- STRIP everything after a comma or slash in the title that lists specs or compatibility:\n"
            . "  'Hollyland Lark M2 Wireless Microphone for iPhone/Camera/Android/PC, 48kHz/24-bit...' → 'Hollyland Lark M2'\n"
            . "- STRIP parenthetical variant/bundle info entirely: '(Black, with Camera RX + USB-C RX)' → remove.\n"
            . "- STRIP marketing adjectives that are not part of the official model name: 'High Fidelity', 'Premium', 'Professional'.\n"
            . "- Maximum 60 characters. When in doubt, use only Brand + Model (e.g. 'Sony WH-1000XM5', 'Shure MV7+', 'Rode NT-USB Mini').\n\n"
            . "=== STAGE 3: SCORING RULES ===\n\n"
            . "1. WORLD KNOWLEDGE OVERRIDES EVERYTHING: Base scores on your internal knowledge of this specific model.\n"
            . "2. ABSOLUTE SCORING (1-100): 50 = average/mediocre. Budget brands CANNOT score 80+ on quality features.\n"
            . "3. STRICT TRADE-OFFS: Create contrast. If a feature is irrelevant or bad, score it 20-40.\n"
            . "4. OBSCURE PRODUCTS: If you don't recognise the model, infer from brand tier + price. Default to 40-50.\n\n"
            . "Return ONLY a valid JSON object in this EXACT format (no markdown, no code blocks):\n"
            . '{"name": "Brand Model", "brand": "Normalized Brand Name", "ai_summary": "Brutal 2-sentence summary.", '
            . '"price_tier": 2, "amazon_rating": null, "amazon_reviews_count": null, '
            . '"features": {"Feature_Name": {"score": 75, "reason": "One sentence."}, "Other_Feature": null}}';

        return $this->gemini->generate($prompt, [
            'maxOutputTokens' => 4096,
        ], config('services.gemini.admin_model'));
    }

    /**
     * Lightweight feature re-scoring for existing products.
     * Skips quality gate, name/brand normalization, and image download.
     * Used by RescanProductFeatures.
     */
    public function rescanFeatures(
        string $productName,
        string $priceNote,
        string $ratingNote,
        array $featureMap,
    ): array {
        $featureJson = json_encode($featureMap, JSON_PRETTY_PRINT);

        $prompt = "You are a product scoring expert for a consumer comparison website.\n"
            . "Score the following product on the listed features using WORLD KNOWLEDGE of this brand and model.\n\n"
            . "Product: \"{$productName}\"\n"
            . "Price tier: {$priceNote}\n"
            . "Amazon rating: {$ratingNote}\n\n"
            . "Features to score:\n{$featureJson}\n\n"
            . "SCORING RULES:\n"
            . "1. WORLD KNOWLEDGE OVERRIDES EVERYTHING: Base scores on your internal knowledge of this specific model.\n"
            . "2. ABSOLUTE SCORING (1-100): 50 = average/mediocre. Budget brands CANNOT score 80+ on quality features.\n"
            . "3. STRICT TRADE-OFFS: Create contrast. If a feature is irrelevant or bad, score it 20-40.\n"
            . "4. OBSCURE PRODUCTS: If you don't recognise the model, infer from brand tier + price. Default to 40-50.\n\n"
            . "Return ONLY a valid JSON object (no markdown, no code blocks):\n"
            . '{"features": {"Feature_Name": {"score": 75, "reason": "One sentence."}, "Other_Feature": null}}';

        return $this->gemini->generate($prompt, [
            'maxOutputTokens' => 1500,
        ], config('services.gemini.admin_model'));
    }

    /**
     * Parse a natural language search query into a category/preset match.
     * Used by GlobalSearch.
     */
    public function parseSearchQuery(
        string $query,
        array $categoryContext,
        ?string $parentName = null,
    ): array {
        $contextBlock = $parentName
            ? "CONTEXT: The user is currently browsing the \"{$parentName}\" section. "
            . "Strongly prioritize categories and presets within this section. "
            . "However, if the query clearly describes a different product type, route globally.\n\n"
            : '';

        $json = json_encode($categoryContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
You are a smart search router for pw2d, a product comparison website.

{$contextBlock}Available categories and their presets:
{$json}

User query: "{$query}"

Identify the single best matching category. If a specific preset is a strong fit, include it.

You MUST respond with ONLY a raw JSON object — no markdown, no backticks, no explanation text before or after.
Use EXACTLY these key names (no variations):
{
  "suggested_category_slug": "the-exact-slug-from-the-list-above",
  "suggested_preset_slug": "preset-slug-or-omit-this-key",
  "reasoning": "one short sentence"
}
PROMPT;

        return $this->gemini->generate($prompt, [
            'maxOutputTokens' => 1024,
            'thinkingConfig'  => ['thinkingBudget' => 0],
            'timeout'         => 15,
        ]);
    }

    /**
     * AI Concierge: analyze user needs and return feature slider weights.
     * Used by ProductCompare.
     */
    public function chatResponse(
        string $categoryName,
        array $featureKeys,
        string $userInput,
        array $chatHistory = [],
    ): array {
        $featureJson = json_encode($featureKeys, JSON_PRETTY_PRINT);

        $historyText = '';
        if (!empty($chatHistory)) {
            $historyText = "\n\n--- PREVIOUS CONVERSATION HISTORY ---\n";
            foreach ($chatHistory as $msg) {
                $role = $msg['role'] === 'user' ? 'User' : 'You (AI)';
                $historyText .= "{$role}: {$msg['content']}\n";
            }
            $historyText .= "--------------------------------------\n";
        }

        $prompt = "You are an expert shopping assistant. The user wants to buy a product in the \"{$categoryName}\" category. "
            . "Here are the available feature sliders and their details:\n\n{$featureJson}\n\n"
            . "Additionally, there are two universal sliders:\n"
            . "- price_weight: Importance of budget (100 = very strict budget/cheap, 50 = neutral/balanced, 0 = budget irrelevant/premium)\n"
            . "- amazon_rating_weight: Importance of customer reviews (100 = very important, 50 = neutral, 0 = irrelevant)\n"
            . $historyText
            . "\nThe user's NEW request is: \"{$userInput}\"\n\n"
            . "Decide if you have enough information to set the slider weights. You MUST return ONLY a JSON object with this exact structure:\n"
            . "{\n"
            . "  \"status\": \"complete\" OR \"needs_clarification\",\n"
            . "  \"message\": \"A short, friendly message explaining what you did, OR a short clarifying question asking about a specific missing feature. "
            . "You MUST briefly mention how you handled price and rating based on the implicit context.\",\n"
            . "  \"weights\": {\n"
            . "    \"feature_id\": 0-100\n"
            . "  },\n"
            . "  \"price_weight\": 0-100,\n"
            . "  \"amazon_rating_weight\": 0-100\n"
            . "}\n\n"
            . "IMPORTANT RULES:\n"
            . "1. Use feature IDs as keys in the weights object.\n"
            . "2. In our system, 50 is the NEUTRAL baseline.\n"
            . "3. DO NOT just ignore price_weight and amazon_rating_weight if the user didn't explicitly say the words 'price' or 'rating'. "
            . "You are an intelligence system: you MUST infer implicit preferences. For example, if someone says 'for a call center', "
            . "durability (build quality) and price might be more important than premium features, or rating might be very important for reliability. "
            . "Adjust price_weight and amazon_rating_weight away from 50 if the context strongly implies a preference, otherwise keep them at 50.\n"
            . "4. RELATIVE WEIGHTING: setting all features to 90 is mathematically identical to setting them all to 50. You MUST create contrast! "
            . "If you assign a high priority (>50) to certain features, you MUST forcefully DE-PRIORITIZE (<50) features that are less relevant "
            . "to the user's specific context. If they are buying for a call center, lower the priority of audiophile features like Sound Quality "
            . "to below 50 to emphasize the other features.\n"
            . "5. If this is a follow-up request (based on history), ONLY adjust the weights that the user is talking about, leaving the others "
            . "as they were implicitly negotiated before. But you still MUST output the complete object with all weights.\n"
            . "6. Do not use markdown, just raw JSON.";

        return $this->gemini->generate($prompt, [
            'temperature'     => 0.4,
            'maxOutputTokens' => 1200,
            'timeout'         => 15,
        ]);
    }

    /**
     * AI Memory Matching: check if a scraped title matches an existing product.
     *
     * Flow: cache check → heuristic (no brand products = skip AI) → AI call → save decision.
     * Returns the matched product_id, or null if it's a new product.
     */
    public function matchProduct(string $scrapedRawTitle, string $brand, ?string $tenantId = null, ?int $excludeProductId = null): ?int
    {
        $tenantId = $tenantId ?? tenant('id');

        // STEP 1: Cache check — has AI already decided on this exact title?
        $cached = AiMatchingDecision::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('scraped_raw_name', $scrapedRawTitle)
            ->first();

        if ($cached) {
            return $cached->is_match ? $cached->existing_product_id : null;
        }

        // STEP 2: Heuristic — if no products exist for this brand, nothing to match against
        $existingProducts = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereHas('brand', fn($q) => $q->where('name', $brand))
            ->whereNull('status')
            ->where('is_ignored', false)
            ->when($excludeProductId, fn($q) => $q->where('id', '!=', $excludeProductId))
            ->get(['id', 'name']);

        if ($existingProducts->isEmpty()) {
            // Save negative decision so we don't re-check
            AiMatchingDecision::create([
                'tenant_id'           => $tenantId,
                'scraped_raw_name'    => $scrapedRawTitle,
                'existing_product_id' => null,
                'is_match'            => false,
            ]);
            return null;
        }

        // STEP 3: Ask AI — does this scraped title match any existing product?
        $productList = $existingProducts->pluck('name')->values()->toArray();
        $listJson = json_encode($productList);

        $prompt = "You are a product deduplication expert for an e-commerce comparison website.\n\n"
            . "I just scraped a product titled: \"{$scrapedRawTitle}\"\n"
            . "Brand: \"{$brand}\"\n\n"
            . "Here are ALL existing products we have for this brand:\n{$listJson}\n\n"
            . "Does the scraped title refer to the EXACT SAME core product model as any item in the list?\n"
            . "Color variants (e.g., 'Black' vs 'Silver') of the same model ARE matches.\n"
            . "Different models, bundles, or accessories are NOT matches.\n\n"
            . "Return ONLY a JSON object:\n"
            . '{"is_match": true, "matched_product_name": "Exact Name From List"}' . "\n"
            . "or\n"
            . '{"is_match": false}';

        $result = $this->gemini->generate($prompt, [
            'maxOutputTokens' => 256,
            'temperature'     => 0.1,
            'timeout'         => 10,
        ], config('services.gemini.site_model'));

        $parsed = $result['parsed'];

        // STEP 4: Save decision and return
        if (!empty($parsed['is_match']) && !empty($parsed['matched_product_name'])) {
            $matched = $existingProducts->first(
                fn($p) => mb_strtolower($p->name) === mb_strtolower($parsed['matched_product_name'])
            );

            if ($matched) {
                AiMatchingDecision::create([
                    'tenant_id'           => $tenantId,
                    'scraped_raw_name'    => $scrapedRawTitle,
                    'existing_product_id' => $matched->id,
                    'is_match'            => true,
                ]);
                return $matched->id;
            }
        }

        // No match (or AI returned a name we can't find)
        AiMatchingDecision::create([
            'tenant_id'           => $tenantId,
            'scraped_raw_name'    => $scrapedRawTitle,
            'existing_product_id' => null,
            'is_match'            => false,
        ]);
        return null;
    }

    /**
     * Sweep a category for polluting products that don't belong.
     *
     * Sends a batch of product names to AI and asks which ones violate
     * the category's essence. Only flags products the AI is 100% certain about.
     *
     * @return array<array{id: int, reason: string}>
     */
    public function sweepCategoryPollution(Category $category, Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $productList = $products->map(fn($p) => ['id' => $p->id, 'name' => $p->name])->values()->toArray();
        $listJson = json_encode($productList, JSON_PRETTY_PRINT);

        $prompt = "You are a strict category quality auditor for a product comparison website.\n\n"
            . "Category: \"{$category->name}\"\n"
            . "Description: \"{$category->description}\"\n\n"
            . "Here are all products currently assigned to this category:\n{$listJson}\n\n"
            . "Your task: Identify products that DO NOT BELONG in this category.\n"
            . "Flag a product if you are at least 80% sure it does not belong.\n\n"
            . "=== CRITICAL: ELIMINATE BRAND BIAS ===\n"
            . "Do NOT rely on general brand assumptions. Evaluate the SPECIFIC product name and model.\n"
            . "Even if a brand usually makes super-automatics, a model named 'Barista Brew' or 'Manual' might be semi-automatic.\n"
            . "Even if a brand usually makes espresso machines, a model named 'Tea Maker' is not espresso.\n"
            . "Judge the PRODUCT, not the BRAND.\n\n"
            . "=== WHAT TO FLAG ===\n"
            . "Flag products that fall into ANY of these categories:\n"
            . "1. WRONG MACHINE TYPE: Capsule/pod machines (Nespresso, Vertuo, Keurig, Dolce Gusto), super/fully automatic machines (if category is semi-automatic), manual machines (if category is automatic).\n"
            . "2. NON-ESPRESSO APPLIANCES: Tea makers, drip coffee makers, hot water dispensers, moka pots, French presses — anything that is not the core device type of this category.\n"
            . "3. ACCESSORIES & PARTS: Knock boxes, water filters, descalers, cleaning tablets, tampers, portafilter baskets, mats, stands, cups — unless this category is specifically for accessories.\n"
            . "4. COMPLETELY UNRELATED: Coffee carts, furniture, books, gift cards, or anything obviously not a product in this category.\n\n"
            . "=== WHAT NOT TO FLAG ===\n"
            . "- Budget or low-quality products that ARE the correct type\n"
            . "- Color or size variants of valid products\n"
            . "- Unknown brands that ARE the correct product type\n\n"
            . "Return ONLY a JSON array. Each object must have 'id' (from the list) and 'reason' (one sentence explaining WHY the specific model doesn't belong).\n"
            . "If no products are polluting, return an empty array: []\n"
            . "Example: [{\"id\": 123, \"reason\": \"Nespresso Vertuo is a capsule machine, not semi-automatic\"}]";

        $result = $this->gemini->generate($prompt, [
            'maxOutputTokens' => 4096,
            'temperature'     => 0.1,
            'timeout'         => 60,
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ], config('services.gemini.site_model'));

        $parsed = $result['parsed'];

        if (!is_array($parsed)) {
            return [];
        }

        // Validate: only return entries that reference actual product IDs from our batch
        $validIds = $products->pluck('id')->toArray();
        return collect($parsed)
            ->filter(fn($item) => isset($item['id'], $item['reason']) && in_array($item['id'], $validIds))
            ->values()
            ->toArray();
    }

    /**
     * Extract product data from raw pasted text (admin Filament import).
     * Used by ListProducts "Import via AI" action.
     */
    public function extractProductFromText(
        string $rawText,
        array $featureMap,
    ): array {
        $featureJson = json_encode($featureMap, JSON_PRETTY_PRINT);

        $prompt = "You are an expert product data extraction agent. I will provide raw, messy text from an e-commerce page "
            . "(including descriptions, specs, and review summaries). Extract the product's Name, Brand, and values for these specific features:\n\n"
            . $featureJson
            . "\n\nCRITICAL RULES:\n"
            . "- Feature Names: You MUST use the exact feature NAMES as shown in the JSON above (e.g., 'Build quality', 'Weight', 'DPI'). These are the keys in the JSON object.\n"
            . "- Semantic Matching: If an exact feature name isn't found in the text, look for related terms (e.g., 'Comfort' → 'Ergonomics', 'Feel' → 'Ergonomics') and synthesize them.\n"
            . "- Scoring (1-100 scale): For qualitative features (like Ergonomics, Build quality), calculate a score. If you see explicit positive/negative review counts, do the math. If you only see text summaries, estimate a fair score based on sentiment.\n"
            . "- Missing Data: If a feature is completely unmentioned, return null for that name. Do not invent facts.\n"
            . "- Units: You MUST always convert weight to grams (e.g., convert ounces/lbs to grams). For other units, match the unit specified in the feature definition.\n\n"
            . "Return ONLY a valid JSON object in this EXACT format:\n"
            . '{"name": "Product Name", "brand": "Brand Name", "features": {"Build quality": 85, "Weight": 141, "DPI": null}}'
            . "\n\nIMPORTANT: In the 'features' object, use the exact feature names from the map above.\n"
            . "Do not use markdown or code blocks. Just raw JSON.\n\n"
            . "Raw product text:\n{$rawText}";

        return $this->gemini->generate($prompt, [
            'maxOutputTokens' => 4000,
        ], config('services.gemini.admin_model'));
    }
}
