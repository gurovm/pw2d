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
use App\Models\Preset;
use App\Models\Product;
use App\Support\BouncerRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        ?string $tenantId = null,
    ): array {
        $featureJson = json_encode($featureMap, JSON_PRETTY_PRINT);

        // Spec 039 T3 — the Stage 1/2/2.5/3 gate rules live in BouncerRules,
        // the single source of truth also consumed by the operator-session
        // export (App\Actions\ExportPendingProducts). This preamble +
        // BouncerRules::text() concatenation must stay byte-identical to the
        // prompt this replaced — pinned by
        // tests/Unit/Services/AiServicePromptSnapshotTest.php.
        $prompt = "You are a ruthless, highly skeptical technology appraiser AND data architect for a premium comparison website.\n"
            . "Your primary job is to score this product using your WORLD KNOWLEDGE of the brand and model.\n"
            . "You are also the last line of defense against dirty, polluted data entering our database.\n\n"
            . "Product name: \"{$productName}\"\n"
            . "Scraped price: \${$scrapedPrice}\n"
            . "Price tier: {$priceNote}\n"
            . "Amazon rating: {$ratingNote}\n\n"
            . "Category features to score:\n{$featureJson}\n\n"
            . BouncerRules::text($categoryName);

        return $this->gemini->generate($prompt, [
            'maxOutputTokens' => 8192,
            'thinkingConfig'  => ['thinkingBudget' => 128],
        ], config('services.gemini.admin_model'), purpose: 'evaluate_product', tenantId: $tenantId);
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
        ?string $tenantId = null,
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
            'maxOutputTokens' => 4096,
        ], config('services.gemini.admin_model'), purpose: 'rescan_features', tenantId: $tenantId);
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
        ], purpose: 'parse_search_query');
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
        ], purpose: 'chat_response');
    }

    /**
     * Normalize a brand name for comparison: lowercase, strip apostrophes/quotes/accents, collapse whitespace.
     *
     * Used for fuzzy brand matching in the heuristic and in ProcessPendingProduct brand reuse.
     * Must be public static so it can be called from jobs and other services.
     */
    public static function normalizeBrandForComparison(string $brand): string
    {
        // Transliterate accents (RØDE → RODE), strip apostrophes/quotes, lowercase
        $brand = transliterator_transliterate('Any-Latin; Latin-ASCII', $brand);
        $brand = preg_replace("/['\u{2019}`\"]/u", '', $brand);
        $brand = preg_replace('/\s+/', ' ', trim($brand));
        return mb_strtolower($brand);
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

        // STEP 2: Heuristic — if no products exist for this brand, nothing to match against.
        // Use fuzzy brand comparison to handle AI spelling variants ("De'Longhi" vs "DeLonghi").
        // SQL REPLACE strips: ASCII apostrophe, right single quote (U+2019), backtick, double quote.
        $existingProducts = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereHas('brand', fn($q) => $q->whereRaw(
                "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(name, '''', ''), '\u{2019}', ''), '`', ''), '\"', '')) = ?",
                [static::normalizeBrandForComparison($brand)]
            ))
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
            . "Does the scraped title refer to the EXACT SAME core product model as any item in the list?\n\n"
            . "MATCH RULES:\n"
            . "- Color/finish variants of the SAME model ARE matches (e.g., 'Black' vs 'Silver').\n"
            . "- Flow control / plumb variants of the same model ARE matches.\n"
            . "NOT MATCHES (return false):\n"
            . "- Different model numbers (e.g., BES880 vs BES881, FAST V vs FAST R).\n"
            . "- Different product lines even if names sound similar (e.g., 'Dedica' vs 'La Specialista').\n"
            . "- Different generations or successors (e.g., 'Synchronika' vs 'Synchronika II').\n"
            . "- Functionally different variants (e.g., 'Auto' vs 'Manual Paddle').\n"
            . "- Different sub-model names (e.g., 'Appartamento TCA' vs 'Appartamento Serie Nera').\n"
            . "- Limited/anniversary editions vs standard models.\n\n"
            . "When in doubt, return false. False negatives (missing a match) are far less harmful than false positives (merging different products).\n\n"
            . "Return ONLY a JSON object:\n"
            . '{"is_match": true, "matched_product_name": "Exact Name From List"}' . "\n"
            . "or\n"
            . '{"is_match": false}';

        $result = $this->gemini->generate($prompt, [
            'maxOutputTokens' => 1024,
            'thinkingConfig'  => ['thinkingBudget' => 128],
            'temperature'     => 0.1,
            'timeout'         => 15,
        ], config('services.gemini.site_model'), purpose: 'match_product', tenantId: $tenantId);

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
        ], config('services.gemini.site_model'), purpose: 'sweep_category');

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
     * Assign categories to uncategorized products in batch.
     *
     * Sends product names + available leaf categories to AI, returns assignments.
     *
     * @return array<array{id: int, category_id: int|null, reason: string}>
     */
    public function assignCategories(Collection $products, array $categoryOptions): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $productList = $products->map(fn($p) => ['id' => $p->id, 'name' => $p->name])->values()->toArray();
        $productJson = json_encode($productList, JSON_PRETTY_PRINT);
        $categoryJson = json_encode($categoryOptions, JSON_PRETTY_PRINT);

        $prompt = "You are a product categorization expert for a consumer comparison website.\n\n"
            . "Here are the available LEAF categories (products can only be assigned to these):\n{$categoryJson}\n\n"
            . "Here are uncategorized products:\n{$productJson}\n\n"
            . "For each product, assign the BEST matching category based on what the product actually is.\n"
            . "Use your WORLD KNOWLEDGE of these brands and models to determine the correct category.\n\n"
            . "=== RULES ===\n"
            . "1. Only assign a category if you are confident the product belongs there.\n"
            . "2. If a product is an accessory, bundle of unrelated items, consumable, or doesn't fit ANY category, set category_id to null.\n"
            . "3. Judge the SPECIFIC PRODUCT, not just the brand.\n"
            . "4. A keyboard+mouse bundle should go in the keyboard category if the keyboard is the primary item.\n"
            . "5. Espresso machines that use capsules/pods (Nespresso, Vertuo, Dolce Gusto) are NOT semi-automatic.\n\n"
            . "Return ONLY a JSON array with one entry per product:\n"
            . '[{"id": 123, "category_id": 5, "reason": "One sentence."}, {"id": 456, "category_id": null, "reason": "Accessory, not a main device."}]';

        $result = $this->gemini->generate($prompt, [
            'maxOutputTokens' => 4096,
            'temperature'     => 0.1,
            'timeout'         => 90,
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ], config('services.gemini.site_model'), purpose: 'assign_categories');

        $parsed = $result['parsed'];

        if (!is_array($parsed)) {
            return [];
        }

        $validProductIds = $products->pluck('id')->toArray();
        $validCategoryIds = array_column($categoryOptions, 'id');

        return collect($parsed)
            ->filter(fn($item) => isset($item['id'], $item['reason']) && in_array((int) $item['id'], $validProductIds, true))
            ->map(function ($item) use ($validCategoryIds) {
                $categoryId = isset($item['category_id']) ? (int) $item['category_id'] : null;
                if ($categoryId !== null && !in_array($categoryId, $validCategoryIds, true)) {
                    $categoryId = null;
                }
                return [
                    'id'          => (int) $item['id'],
                    'category_id' => $categoryId,
                    'reason'      => $item['reason'],
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Analyze recent search logs and return an actionable markdown report.
     *
     * Returns the raw markdown string from the AI (not parsed JSON).
     * Used by the ListSearchLogs "Analyze Trends" admin action.
     *
     * @param  Collection<int, \App\Models\SearchLog>  $logs
     */
    public function analyzeSearchTrends(Collection $logs): string
    {
        $formattedLogs = $logs->map(fn($log) => sprintf(
            "- [%s] query: '%s', results: %s, category: %s, summary: %s",
            $log->type,
            $log->query,
            $log->results_count ?? 'N/A',
            $log->category_name ?? 'N/A',
            mb_substr($log->response_summary ?? 'N/A', 0, 100),
        ))->implode("\n");

        $prompt = "You are an expert E-commerce Product Manager.\n"
            . "Analyze the following user search logs from our affiliate website.\n"
            . "Provide a concise, actionable report in Markdown format with the following sections:\n\n"
            . "1. Trending Intents (What are users trying to achieve?)\n"
            . "2. Missing Inventory / Zero Results (What products or categories do we need to scrape/add?)\n"
            . "3. AI Concierge Insights (Are users engaging well with the AI? Are there friction points?)\n"
            . "4. Actionable Recommendations (What exact 2-3 actions should I take tomorrow?)\n\n"
            . "**CRITICAL SYSTEM RULES FOR ANALYSIS:**\n"
            . "- For 'homepage_ai', the 'results' count represents the number of matching CATEGORIES found for "
            . "redirection (usually 1).\n"
            . "- For 'category_ai' and 'global_search', the 'results' count represents the number of actual PRODUCTS found.\n"
            . "- DO NOT compare homepage_ai counts to category_ai counts directly, as they measure different things.\n"
            . "- If 'results' is N/A or NULL, it simply means the count was not logged. It DOES NOT mean there were zero "
            . "results. Only flag 'Zero Results' if the results count is explicitly 0.\n\n"
            . "Here is the raw search log data:\n\n"
            . $formattedLogs;

        $result = $this->gemini->generate($prompt, [
            'temperature'    => 0.4,
            'maxOutputTokens' => 8192,
            'timeout'        => 120,
        ], config('services.gemini.admin_model'), purpose: 'analyze_search_trends');

        return $result['content'];
    }

    /**
     * Generate category content (buying guide, features, presets, etc.) from an admin prompt.
     * Used by EditCategory AI generator actions.
     *
     * Returns the raw text content string from the model.
     */
    public function generateCategoryContent(string $prompt): string
    {
        $result = $this->gemini->generate($prompt, [
            'timeout'         => 120,
            'maxOutputTokens' => 8000,
        ], config('services.gemini.admin_model'), purpose: 'category_content');

        return $result['content'];
    }

    /**
     * Generate a category hero image from a text prompt using the image model.
     * Used by EditCategory AI generator actions.
     *
     * Returns the base64-decoded image binary and its MIME type so the caller
     * can persist the file without this service touching the filesystem.
     *
     * @return array{data: string, mimeType: string}
     * @throws \Exception on API error or when the model returns no image.
     */
    public function generateCategoryImage(string $prompt): array
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            throw new \Exception('GEMINI_API_KEY is not set in your .env file.');
        }

        $model = config('services.gemini.image_model');

        $response = \Illuminate\Support\Facades\Http::timeout(120)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'contents'         => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'responseModalities' => ['TEXT', 'IMAGE'],
                ],
            ]);

        if ($response->failed()) {
            throw new \Exception('Image API failed: ' . $response->body());
        }

        $parts = $response->json('candidates.0.content.parts') ?? [];

        foreach ($parts as $part) {
            if (isset($part['inlineData'])) {
                return [
                    'data'     => base64_decode($part['inlineData']['data']),
                    'mimeType' => $part['inlineData']['mimeType'] ?? 'image/png',
                ];
            }
        }

        throw new \Exception('No image data returned from AI. The model may not support image generation with this prompt.');
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
        ], config('services.gemini.admin_model'), purpose: 'extract_product');
    }

    /**
     * Generate use-case-specific content for a single preset: intro paragraph + FAQs.
     *
     * Produces content scoped to the preset's use-case (e.g. "Streamer", "Remote Worker"),
     * referencing the preset's highest-weighted features and the top products under those weights.
     * Output is stored in `preset.seo_content` as `{ intro: string, faqs: [...] }`.
     *
     * @param  Preset $preset  Must have `category` (with `features`), and `presetFeatures.feature`
     *                         already eager-loaded. Products are resolved internally.
     * @return array{intro: string, faqs: list<array{question: string, answer: string}>}
     * @throws \InvalidArgumentException When the model returns malformed or incomplete JSON.
     * @throws \Exception               When the Gemini API call fails.
     */
    public function generatePresetContent(Preset $preset): array
    {
        $category     = $preset->category;
        $categoryName = $category->name;
        $presetName   = $preset->name;

        // --- Resolve the top 2-3 weighted features from the feature_preset pivot ---
        // presetFeatures is a HasMany on FeaturePreset (pivot model with a weight column).
        // Sort descending by weight, take up to 3, pull the feature name via relation.
        $topFeatures = $preset->presetFeatures
            ->sortByDesc('weight')
            ->take(3)
            ->filter(fn ($fp) => $fp->feature !== null)
            ->map(fn ($fp) => $fp->feature->name)
            ->values();

        $featuresClause = $topFeatures->isNotEmpty()
            ? 'Priority features for this use-case: ' . $topFeatures->implode(', ') . '.'
            : 'No specific feature weights defined for this preset.';

        // --- Resolve the top 5 products ranked under this preset's weights ---
        // Build a weight map: feature_id => weight (from pivot), defaulting to 50.
        $weightMap = $preset->presetFeatures
            ->pluck('weight', 'feature_id')
            ->toArray();

        // Score products: sum of (feature_raw_value * weight) for features in this preset.
        // We only need name for the prompt; load products with featureValues for scoring.
        $products = Product::where('category_id', $category->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->with('featureValues:id,product_id,feature_id,raw_value')
            ->get(['id', 'name']);

        $scored = $products->map(function (Product $p) use ($weightMap) {
            $score = $p->featureValues
                ->filter(fn ($fv) => isset($weightMap[$fv->feature_id]))
                ->sum(fn ($fv) => (float) $fv->raw_value * (float) $weightMap[$fv->feature_id]);
            return ['name' => $p->name, 'score' => $score];
        })
        ->sortByDesc('score')
        ->take(5)
        ->pluck('name')
        ->values();

        $productLines = $scored->isNotEmpty()
            ? $scored->map(fn ($name, $i) => '  ' . ($i + 1) . '. ' . $name)->implode("\n")
            : '  (No scored products available yet.)';

        $prompt = <<<PROMPT
You are a senior technology editor writing for a premium product-comparison website — think Wirecutter or RTINGS. Your job is to write use-case-specific content for ONE preset on a category compare page.

Category: {$categoryName}
Use-case / Preset name: {$presetName}
{$featuresClause}

Top products ranked under this preset's weights:
{$productLines}

=== YOUR TASK ===

Write TWO pieces of content specifically for buyers in the "{$presetName}" use-case. Every sentence must be relevant to THIS use-case — not generic category content.

Return ONLY a valid JSON object with EXACTLY these two keys — no markdown fences, no preamble, no explanation:

{
  "intro": "<p>180-280 word paragraph addressing the {$presetName} use-case directly. Start with 'If you are buying a {$categoryName} for {$presetName}...' or similar. Name the priority features. Reference the top 1-2 products by name if they are well-known. Use only <p> tags. 9th-grade reading level. Be concrete — no fluff.</p>",
  "faqs": [
    {"question": "A specific question a {$presetName} buyer would type into Google", "answer": "Direct, concrete 2-4 sentence answer referencing real trade-offs for the {$presetName} use-case. 40-100 words."},
    {"question": "...", "answer": "..."}
  ]
}

RULES:
- `intro`: ONE OR TWO <p> elements total. 180-280 words. Must name the preset's priority features. Must address the {$presetName} use-case directly. Hook the reader with a specific buying scenario.
- `faqs`: 3-4 entries. Each must have both "question" and "answer" as strings. Questions must be phrased as search queries a {$presetName} buyer would type. Answers must be concrete, 40-100 words each. Cover: the most important spec for this use-case, a common trade-off, and one misconception.
- Return ONLY the raw JSON. No ```json wrapper. No trailing text.
PROMPT;

        $raw = $this->gemini->generate($prompt, [
            // admin_model is a thinking model — reasoning tokens count against this
            // budget. 2000 left too little headroom and truncated on MAX_TOKENS, so
            // match the 8192 ceiling the other admin_model content calls use.
            'maxOutputTokens' => 8192,
            'timeout'         => 120,
        ], config('services.gemini.admin_model'), purpose: 'preset_content');

        $content = $raw['content'] ?? '';

        // Strip markdown code fences Gemini sometimes wraps around JSON responses.
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```\s*$/i', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(
                'AI returned malformed JSON: ' . substr($content, 0, 300)
            );
        }

        foreach (['intro', 'faqs'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new \InvalidArgumentException(
                    "AI response missing key \"{$key}\". Got keys: " . implode(', ', array_keys($decoded))
                );
            }
        }

        if (!is_string($decoded['intro']) || empty(trim($decoded['intro']))) {
            throw new \InvalidArgumentException('AI response "intro" must be a non-empty string.');
        }

        if (!is_array($decoded['faqs']) || empty($decoded['faqs'])) {
            throw new \InvalidArgumentException('AI response "faqs" must be a non-empty array.');
        }

        foreach ($decoded['faqs'] as $i => $faq) {
            if (!is_array($faq) || !isset($faq['question'], $faq['answer'])
                || !is_string($faq['question']) || !is_string($faq['answer'])) {
                throw new \InvalidArgumentException(
                    "AI response faqs[{$i}] must have string keys \"question\" and \"answer\"."
                );
            }
        }

        return [
            'intro' => (string) $decoded['intro'],
            'faqs'  => $decoded['faqs'],
        ];
    }

    /**
     * Generate compare-page content for a leaf category: intro, methodology, and FAQs.
     *
     * Produces authoritative buying-guide depth in the style of Wirecutter / RTINGS.
     * The returned array is merged directly into `category.buying_guide`.
     *
     * @param  Category $category  Must have `features` and `products` (≤5, ordered by
     *                             amazon_reviews_count desc) already eager-loaded.
     * @return array{intro: string, methodology: string, faqs: list<array{question: string, answer: string}>}
     * @throws \InvalidArgumentException When the model returns malformed or incomplete JSON.
     * @throws \Exception               When the Gemini API call fails.
     */
    public function generateCompareContent(Category $category): array
    {
        // --- Build prompt context ---

        $categoryName   = $category->name;
        $categorySlug   = $category->slug;
        $parentName     = $category->parent?->name ?? null;
        $parentClause   = $parentName ? "Parent category: {$parentName}" : 'Top-level category (no parent)';

        $featureNames = $category->features
            ->take(10)
            ->pluck('name')
            ->implode(', ');
        $featureClause = $featureNames
            ? "Key evaluation features: {$featureNames}"
            : 'No structured features defined yet.';

        $existingGuide = is_array($category->buying_guide)
            ? ($category->buying_guide['how_to_decide'] ?? '')
            : '';
        $guideClause = $existingGuide
            ? "Existing buying guide context (use as background, do NOT copy verbatim):\n---\n" . strip_tags($existingGuide) . "\n---"
            : 'No existing buying guide content.';

        $topProducts = $category->products->take(5);
        $productLines = $topProducts->map(function ($p) {
            $summary = $p->ai_summary ? strip_tags($p->ai_summary) : '(no summary)';
            return "  - {$p->name}: " . \Illuminate\Support\Str::limit($summary, 200);
        })->implode("\n");
        $productClause = $topProducts->isNotEmpty()
            ? "Top products by Amazon review volume:\n{$productLines}"
            : 'No products available yet.';

        $prompt = <<<PROMPT
You are a senior technology editor writing for a premium product-comparison website — think Wirecutter, RTINGS, or The Verge's buying guides. Your job is to produce authoritative, specific, genuinely useful compare-page content for one product category.

Category: {$categoryName}
Slug: {$categorySlug}
{$parentClause}
{$featureClause}

{$productClause}

{$guideClause}

=== YOUR TASK ===

Write three pieces of content for the "{$categoryName}" compare page. Be specific to THIS category and THESE products. Never use generic boilerplate that could apply to any product category. Write as a knowledgeable expert who has tested many products in this category.

Return ONLY a valid JSON object with EXACTLY these three keys — no markdown fences, no preamble, no explanation:

{
  "intro": "<p>100-150 word paragraph introducing the category, who needs it, what separates good products from bad ones, and why our AI-ranked comparison is worth the reader's time. Use only <p> tags. Write at a 9th-grade reading level. Be direct — no fluff.</p>",
  "methodology": "2-3 sentence plain-text explanation of how our AI ranks {$categoryName}. Name the most important 2-3 features by name. Mention that rankings are based on real Amazon review volume and a structured feature-scoring algorithm. Aim to build trust without sounding corporate.",
  "faqs": [
    {"question": "A specific, search-intent-driven question a buyer would type into Google", "answer": "A direct, specific 2-4 sentence answer that references real considerations for {$categoryName}. No hedging. No 'it depends' without explaining what it depends on."},
    {"question": "...", "answer": "..."}
  ]
}

RULES:
- `intro`: exactly ONE <p> element. 100-150 words. HTML-safe. Mention the category name naturally. Hook the reader.
- `methodology`: plain text (no HTML). 2-3 sentences. Reference the specific top features by name if available.
- `faqs`: 4-6 entries. Each must have both "question" and "answer" as strings. Questions must be distinct — cover: best beginner pick, budget vs. premium trade-offs, a common misconception, a spec that sounds important but isn't, and one use-case-specific question. Answers: concrete, 40-100 words each.
- Return ONLY the raw JSON. No ```json wrapper. No trailing text.
PROMPT;

        $raw = $this->gemini->generate($prompt, [
            'maxOutputTokens' => 4000,
            'timeout'         => 120,
        ], config('services.gemini.admin_model'), purpose: 'compare_content');

        $content = $raw['content'] ?? '';

        // Strip markdown code fences Gemini sometimes wraps around JSON responses.
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```\s*$/i', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(
                'AI returned malformed JSON: ' . substr($content, 0, 300)
            );
        }

        foreach (['intro', 'methodology', 'faqs'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new \InvalidArgumentException(
                    "AI response missing key \"{$key}\". Got keys: " . implode(', ', array_keys($decoded))
                );
            }
        }

        if (!is_array($decoded['faqs']) || empty($decoded['faqs'])) {
            throw new \InvalidArgumentException(
                'AI response "faqs" must be a non-empty array.'
            );
        }

        foreach ($decoded['faqs'] as $i => $faq) {
            if (!is_array($faq) || !isset($faq['question'], $faq['answer'])
                || !is_string($faq['question']) || !is_string($faq['answer'])) {
                throw new \InvalidArgumentException(
                    "AI response faqs[{$i}] must have string keys \"question\" and \"answer\"."
                );
            }
        }

        return [
            'intro'       => (string) $decoded['intro'],
            'methodology' => (string) $decoded['methodology'],
            'faqs'        => $decoded['faqs'],
        ];
    }

    /**
     * Generate "Best X" landing-page content (Spec 027): intro, per-pick headline/body,
     * FAQs, and a methodology note. Picks are chosen deterministically BEFORE this call
     * (see App\Actions\SelectLandingPagePicks) — the AI only writes prose about them.
     *
     * @param  Category $category             The leaf category this landing page targets.
     * @param  array<int, array{product_id: int, role: string, product: Product}> $picks
     *         Product must have `brand`, `featureValues.feature`, and `offers` already
     *         eager-loaded (offers needed for the `estimated_price` accessor).
     * @param  array<int, string> $excludeFaqQuestions
     *         Existing FAQ questions (category buying_guide + all preset seo_content) the
     *         AI must not duplicate or closely paraphrase.
     * @param  int $scoredProductCount
     *         True count of fully-processed, non-ignored products in this category (the
     *         real denominator the picks were chosen from) — grounds "we scored N X" claims
     *         in a real number instead of a vague/inflated one.
     * @return array{intro: string, picks: list<array{product_id: int, headline: string, body: string}>, faqs: list<array{question: string, answer: string}>, methodology_note: string}
     * @throws \InvalidArgumentException When the model returns malformed, incomplete, or mismatched JSON.
     * @throws \Exception               When the Gemini API call fails.
     */
    public function generateLandingPageContent(Category $category, array $picks, array $excludeFaqQuestions, int $scoredProductCount): array
    {
        $categoryName = $category->name;
        $pickCount    = count($picks);

        $cheapestPrice = collect($picks)
            ->map(fn (array $p) => $p['product']->estimated_price)
            ->filter(fn ($price) => $price !== null)
            ->min();

        $pickLines = collect($picks)->map(function (array $pick, int $i) use ($cheapestPrice) {
            /** @var Product $product */
            $product = $pick['product'];

            $roleLabel = match (true) {
                $pick['role'] === 'overall' => 'Overall pick',
                $pick['role'] === 'budget'  => 'Budget pick',
                $pick['role'] === 'premium' => 'Premium pick',
                str_starts_with($pick['role'], 'preset:') => 'Best for ' . str_replace('preset:', '', $pick['role']),
                default => $pick['role'],
            };

            $brandName = $product->brand?->name ?? '';
            $summary   = $product->ai_summary ? strip_tags($product->ai_summary) : '(no summary)';
            $tierLabel = match ((int) $product->price_tier) {
                1 => 'Budget',
                2 => 'Mid-range',
                3 => 'Premium',
                default => 'Unknown',
            };
            $price = $product->estimated_price;
            $priceLabel = $price !== null ? '$' . $price : 'N/A';

            $deltaLabel = 'N/A';
            if ($price !== null && $cheapestPrice !== null) {
                $delta      = $price - $cheapestPrice;
                $deltaLabel = $delta === 0 ? 'this is the cheapest pick' : ('+$' . $delta . ' vs. the cheapest pick in this list');
            }

            $featureLines = $product->featureValues
                ->filter(fn ($fv) => $fv->feature !== null)
                ->map(fn ($fv) => "{$fv->feature->name}: " . round((float) $fv->raw_value))
                ->implode(', ');

            return sprintf(
                "%d. [product_id=%d] %s %s — Role: %s | Tier: %s | Est. price: %s (%s)\n   Summary: %s\n   Feature scores: %s",
                $i + 1,
                $product->id,
                $brandName,
                $product->name,
                $roleLabel,
                $tierLabel,
                $priceLabel,
                $deltaLabel,
                Str::limit($summary, 220),
                $featureLines ?: '(none scored)',
            );
        })->implode("\n\n");

        $excludeJson = json_encode(array_values($excludeFaqQuestions), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
You are a knowledgeable friend with strong, data-backed opinions writing a "Best {$categoryName}" guide. You've actually looked at the numbers — you're not a marketing copywriter. The picks below were selected by a DATA-DRIVEN scoring algorithm, not by you — your job is to write honest, specific prose ABOUT the pre-selected picks, not to re-rank or second-guess them.

Category: {$categoryName}
Number of picks in this list: {$pickCount}
Total products scored in our database for this category: {$scoredProductCount}

=== PICKS (already selected, in rank order — prices and deltas are real, use them) ===
{$pickLines}

=== EXISTING FAQ QUESTIONS (already answered elsewhere on the site — DO NOT repeat or closely paraphrase these) ===
{$excludeJson}

=== STYLE CONTRACT (read this before writing — violations get the response discarded) ===

You are writing for a reader who has read a hundred "best X" listicles and can smell AI filler from the first sentence. Your job is to NOT sound like that. Before you finalize your answer, reread it and check it against the BANNED list below. If you find a banned phrase or pattern, rewrite that sentence. This matters more than sounding "professional."

BANNED — do not write these or close paraphrases of them:
- Stock openers: "Finding the right {$categoryName} can transform...", "In today's market...", "When it comes to {$categoryName}..."
- Cliché words and phrases — banned INDIVIDUALLY, anywhere in your answer, even describing something unrelated to the example (e.g. "robust build," "robust testing" are both banned just for using "robust"): "cut through the noise", "game-changer", "elevate your experience", "look no further", "packs a punch", "seamless", "boasts", "comprehensive", "delve", "robust", "stands out from the crowd", "takes X to the next level". Use a plainer word instead — "sturdy," "solid," or "well-built" instead of "robust"; "full-featured" instead of "comprehensive"; "has" instead of "boasts".
- The rule-of-three audience triad — anything shaped like "Whether you're a competitive pro, a dedicated hobbyist, or just looking for value." Ban "Whether you're..." constructions entirely, in any form.
- "not just X, but Y" constructions.
- Uniform sentence rhythm: every sentence medium-length and declarative, every paragraph exactly 3-4 sentences. That shape reads as machine-written even when the words are fine.
- Empty superlative self-praise: "demonstrably the best in their class," "impartial data," or any line that compliments the ranking method's own honesty instead of just being honest.

REQUIRED:
- Ground every claim in the ACTUAL data above. Use the real scored-product count ("we scored all {$scoredProductCount} {$categoryName} in our database" or similar — use the real number, not a rounded or vague one). Use real feature names from the feature scores. Use the real price deltas given above (e.g. "the {$categoryName} pick above costs \$X more than Y; here's what that buys you") instead of vague "it's pricier" language.
- Vary sentence length aggressively. Mix in a short one. A four-to-six-word sentence, occasionally. Fragments are fine, sparingly — used for emphasis, not as a tic.
- Each pick's `body` MUST contain at least one concrete tradeoff or drawback, stated plainly and specifically (e.g. "the companion software is clunky," "battery life claims assume the RGB is off," "the wrist rest is sold separately") — not a hedge like "may not be for everyone." It must also cite at least one specific fact pulled directly from that pick's data above (a feature score, the price delta, the tier).
- Voice: direct address ("you"), contractions ("it's", "you'll", "we'd"), mild honest hedges where warranted ("we'd skip it unless you specifically need..."). Write like you're telling a friend what to buy, not narrating a press release.
- Intro: MAX 2 short paragraphs. Get to the picks fast — don't build up to them. Explain the ranking method in ONE plain sentence (e.g. "we scored every feature 0-100 and weighted them to rank these") — state it, don't sell it as trustworthy.
- FAQs: answer like a person would answer a friend's text, not a knowledge-base entry. Where it's actually relevant, reference specific picks or prices from the list above instead of speaking in the abstract.

GROUNDING — every factual claim about a SPECIFIC product must come ONLY from the PICKS data above. This is the single most important rule; a fabricated fact is worse than a boring sentence:
- Price, condition, specs, and scores for a pick: use ONLY what's printed in that pick's line above. The payload does NOT include listing condition (new / renewed / refurbished / used / open-box) — do not mention, guess, or imply condition for any product, ever, under any circumstance.
- NEVER use outside/world knowledge to explain WHY a price is what it is (e.g. do not write "at this price it's likely a renewed/refurbished unit" or "that's a steal for this model because..."). If a price looks surprising given what you know about the brand or model from training data, say nothing about why — just state the price plainly, or cite the price delta already given.
- Do not invent or add a spec, feature, or score that isn't in that pick's data line, even if you "recognize" the product and know more about it. If it's not in the payload, it doesn't exist for this article.
- General world knowledge may ONLY be used for uncontroversial, category-level context that makes no claim about a SPECIFIC pick — e.g. explaining what a switch type generally is, what "TKL" means, what QMK/VIA is. It must NEVER be used to assert or imply a fact about one of these specific products (price reasoning, condition, unlisted specs, availability, release date).

=== YOUR TASK ===

Write listicle content for the "Best {$categoryName}" page, following the style contract above exactly. Every factual claim must be grounded in the pick data above (role, tier, price, price delta, summary, feature scores) — do not invent facts not implied by that data.

Return ONLY a valid JSON object with EXACTLY these keys — no markdown fences, no preamble, no explanation:

{
  "intro": "<p>Max 2 <p> paragraphs, ~100-160 words total. States the real scored-product count and how the ranking works in one plain sentence, then gets out of the way. Use only <p> tags. 9th-grade reading level. No methodology self-praise.</p>",
  "picks": [
    {"product_id": <id>, "headline": "5-8 word headline naming the specific reason this pick won its role — avoid generic superlatives", "body": "2-3 short paragraphs (120-180 words total, wrapped in <p> tags): why it won its role (cite a real feature score or price fact), who it's actually for, and one concrete, plainly-stated tradeoff or drawback."}
  ],
  "faqs": [
    {"question": "A specific, search-intent-driven question a buyer would type into Google — MUST NOT duplicate or closely paraphrase any question in the EXISTING FAQ QUESTIONS list above", "answer": "Direct 2-4 sentence answer (40-100 words) that sounds like a person answering, referencing a specific pick/price from this list where relevant."}
  ],
  "methodology_note": "1-2 sentence plain-text note appended to the static 'How we ranked' methodology block, specific to this category and these picks."
}

RULES:
- `picks`: MUST contain EXACTLY {$pickCount} entries, one per product_id listed above, in the SAME order. Every `product_id` must exactly match one from the PICKS list.
- `faqs`: 4-6 entries, all distinct from the EXISTING FAQ QUESTIONS list (different angle, different wording).
- `methodology_note`: plain text, no HTML.
- Before returning, reread EVERY field you wrote (intro, every headline, every pick body, every FAQ answer, the methodology note) and literally search it for each banned word/phrase, one at a time. A single banned word anywhere is a failure, even in an unrelated context (e.g. "robust build" fails on "robust" alone). Replace any hit with a plainer word before returning.
- Also reread every pick `body` for the GROUNDING rule specifically: does it state or imply anything about condition (new/renewed/refurbished/used/open-box)? Does it cite any spec, feature, or score NOT printed in that pick's data line above? If either is true, remove or rewrite that sentence before returning.
- Return ONLY the raw JSON. No ```json wrapper. No trailing text.
PROMPT;

        $raw = $this->gemini->generate($prompt, [
            // admin_model is a thinking model — reasoning tokens count against this
            // budget. Match the 8192 ceiling the other admin_model content calls use
            // (generatePresetContent's truncation lesson — never lower this).
            'maxOutputTokens' => 8192,
            'timeout'         => 120,
        ], config('services.gemini.admin_model'), purpose: 'landing_page_content');

        $content = $raw['content'] ?? '';

        // Strip markdown code fences Gemini sometimes wraps around JSON responses.
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```\s*$/i', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(
                'AI returned malformed JSON: ' . substr($content, 0, 300)
            );
        }

        foreach (['intro', 'picks', 'faqs', 'methodology_note'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new \InvalidArgumentException(
                    "AI response missing key \"{$key}\". Got keys: " . implode(', ', array_keys($decoded))
                );
            }
        }

        if (!is_string($decoded['intro']) || trim($decoded['intro']) === '') {
            throw new \InvalidArgumentException('AI response "intro" must be a non-empty string.');
        }

        if (!is_array($decoded['picks']) || count($decoded['picks']) !== $pickCount) {
            throw new \InvalidArgumentException("AI response \"picks\" must contain exactly {$pickCount} entries.");
        }

        $expectedIds = collect($picks)->map(fn ($p) => (int) $p['product_id'])->values()->all();

        foreach ($decoded['picks'] as $i => $pick) {
            if (!is_array($pick) || !isset($pick['product_id'], $pick['headline'], $pick['body'])
                || !is_string($pick['headline']) || !is_string($pick['body'])) {
                throw new \InvalidArgumentException(
                    "AI response picks[{$i}] must have \"product_id\", \"headline\", and \"body\"."
                );
            }

            if ((int) $pick['product_id'] !== $expectedIds[$i]) {
                throw new \InvalidArgumentException(
                    "AI response picks[{$i}].product_id ({$pick['product_id']}) does not match expected product_id ({$expectedIds[$i]})."
                );
            }
        }

        if (!is_array($decoded['faqs']) || empty($decoded['faqs'])) {
            throw new \InvalidArgumentException('AI response "faqs" must be a non-empty array.');
        }

        foreach ($decoded['faqs'] as $i => $faq) {
            if (!is_array($faq) || !isset($faq['question'], $faq['answer'])
                || !is_string($faq['question']) || !is_string($faq['answer'])) {
                throw new \InvalidArgumentException(
                    "AI response faqs[{$i}] must have string keys \"question\" and \"answer\"."
                );
            }
        }

        if (!is_string($decoded['methodology_note'])) {
            throw new \InvalidArgumentException('AI response "methodology_note" must be a string.');
        }

        return [
            'intro'            => (string) $decoded['intro'],
            'picks'            => $decoded['picks'],
            'faqs'             => $decoded['faqs'],
            'methodology_note' => (string) $decoded['methodology_note'],
        ];
    }
}
