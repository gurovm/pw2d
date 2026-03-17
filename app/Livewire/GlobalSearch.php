<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Category;
use App\Models\Preset;
use App\Models\Product;
use App\Models\SearchLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Component;

class GlobalSearch extends Component
{
    public string $query = '';
    public ?int $parentId = null;
    public string $parentName = '';
    public bool $open = false;
    public bool $isAiSearching = false;
    public ?array $aiSuggestion = null;
    public ?string $aiError = null;

    /** Flat result list [{type, name, url, ...}] populated by runDbSearch() */
    public array $dbResults = [];

    public function mount(?int $parentId = null): void
    {
        $this->parentId = $parentId;

        if ($parentId) {
            $this->parentName = Category::find($parentId)?->name ?? '';
        }
    }

    /**
     * Fires on every debounced keystroke (500 ms via wire:model.live.debounce.500ms).
     *
     * Phase 1 — instant DB search.
     * If DB returns nothing, flip $isAiSearching = true so the browser renders
     * the labor-illusion spinner immediately, then Alpine calls performAiSearch()
     * in a separate non-blocking round-trip.
     */
    public function updatedQuery(): void
    {
        $this->aiSuggestion  = null;
        $this->aiError       = null;
        $this->isAiSearching = false;

        if (mb_strlen(trim($this->query)) < 3) {
            $this->dbResults = [];
            $this->open      = false;
            return;
        }

        $this->open = true;
        $this->runDbSearch();

        if (empty($this->dbResults)) {
            $this->isAiSearching = true;
        }
    }

    /**
     * Phase 2 — AI fallback.
     * Called by Alpine's $watch when isAiSearching flips to true, so the
     * labor-illusion UI is already visible before this slow round-trip begins.
     */
    public function performAiSearch(): void
    {
        if (!$this->isAiSearching || mb_strlen(trim($this->query)) < 3) {
            return;
        }

        try {
            $categories = Category::with('presets:id,category_id,name')
                ->get(['id', 'name', 'slug', 'description']);

            $categoryContext = $categories->map(fn (Category $c) => [
                'name'        => $c->name,
                'slug'        => $c->slug,
                'description' => $c->description,
                'presets'     => $c->presets->map(fn (Preset $p) => [
                    'name' => $p->name,
                    'slug' => Str::slug($p->name),
                ])->values()->toArray(),
            ])->values()->toArray();

            $apiKey   = config('services.gemini.api_key');
            $model    = config('services.gemini.site_model');
            $response = Http::timeout(15)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                [
                    'contents'         => [['parts' => [['text' => $this->buildPrompt($categoryContext)]]]],
                    'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 300],
                ]
            );

            if (!$response->successful()) {
                throw new \Exception($response->status() === 429
                    ? 'AI rate limit hit. Please try again in a moment.'
                    : 'AI service unavailable.');
            }

            $raw    = $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $raw    = trim(preg_replace('/^```json\s*|\s*```$/m', '', trim($raw)));
            $parsed = json_decode($raw, true);

            if (empty($parsed['suggested_category_slug'])) {
                throw new \Exception('No match found. Try a more specific phrase.');
            }

            $category = Category::with('presets:id,category_id,name')
                ->where('slug', $parsed['suggested_category_slug'])
                ->first();

            if (!$category) {
                throw new \Exception('AI returned an unknown category.');
            }

            $presetSlug = null;
            $presetName = null;
            if (!empty($parsed['suggested_preset_slug'])) {
                $preset = $category->presets->first(
                    fn (Preset $p) => Str::slug($p->name) === $parsed['suggested_preset_slug']
                );
                if ($preset) {
                    $presetSlug = Str::slug($preset->name);
                    $presetName = $preset->name;
                }
            }

            $this->aiSuggestion = [
                'category_name' => $category->name,
                'category_slug' => $category->slug,
                'preset_name'   => $presetName,
                'preset_slug'   => $presetSlug,
                'reasoning'     => $parsed['reasoning'] ?? '',
                'url'           => route('category.show', $category->slug)
                                    . ($presetSlug ? "?preset={$presetSlug}" : ''),
            ];

            // Flash for AI Concierge pick-up on the destination category page
            session()->flash('ai_initial_prompt', $this->query);

            SearchLog::create([
                'type'          => 'global_search',
                'query'         => $this->query,
                'category_name' => $category->name,
                'results_count' => 1,
                'user_id'       => auth()->id(),
            ]);

        } catch (\Throwable $e) {
            \Log::error('GlobalSearch AI error', ['msg' => $e->getMessage(), 'query' => $this->query]);
            $this->aiError = $e->getMessage();

            SearchLog::create([
                'type'          => 'global_search',
                'query'         => $this->query,
                'category_name' => null,
                'results_count' => 0,
                'user_id'       => auth()->id(),
            ]);
        } finally {
            $this->isAiSearching = false;
        }
    }

    public function close(): void
    {
        $this->open          = false;
        $this->isAiSearching = false;
        $this->aiSuggestion  = null;
        $this->aiError       = null;
        $this->dbResults     = [];
        $this->query         = '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function runDbSearch(): void
    {
        $term    = $this->query;
        $results = [];

        // ── Categories ────────────────────────────────────────────────────────
        $catQ = Category::where('name', 'like', "%{$term}%");
        if ($this->parentId) {
            $catQ->where('parent_id', $this->parentId);
        }
        foreach ($catQ->limit(4)->get(['id', 'name', 'slug']) as $cat) {
            $results[] = [
                'type' => 'category',
                'name' => $cat->name,
                'url'  => route('category.show', $cat->slug),
            ];
        }

        // ── Presets ───────────────────────────────────────────────────────────
        $presetQ = Preset::with('category:id,name,slug')
            ->where('name', 'like', "%{$term}%");
        if ($this->parentId) {
            $childIds = Category::where('parent_id', $this->parentId)->pluck('id');
            $presetQ->whereIn('category_id', $childIds);
        }
        foreach ($presetQ->limit(3)->get() as $preset) {
            $results[] = [
                'type'          => 'preset',
                'name'          => $preset->name,
                'category_name' => $preset->category->name,
                'url'           => route('category.show', $preset->category->slug)
                                    . '?preset=' . Str::slug($preset->name),
            ];
        }

        // ── Products ──────────────────────────────────────────────────────────
        $productQ = Product::with('category:id,name,slug')
            ->whereNull('status')
            ->where('is_ignored', false)
            ->where('name', 'like', "%{$term}%");
        if ($this->parentId) {
            $productQ->whereHas('category', fn ($q) => $q->where('parent_id', $this->parentId));
        }
        foreach ($productQ->limit(4)->get(['id', 'name', 'slug', 'category_id', 'external_image_path']) as $product) {
            $results[] = [
                'type'          => 'product',
                'name'          => $product->name,
                'category_name' => $product->category?->name,
                'image'         => $product->external_image_path,
                'url'           => route('category.show', $product->category?->slug ?? '#'),
            ];
        }

        $this->dbResults = $results;
    }

    /**
     * Prompt instructs Gemini to return a single best-match category + optional
     * preset slug, with smart scoping when a parent context is present.
     */
    private function buildPrompt(array $categoryContext): string
    {
        $contextBlock = $this->parentName
            ? "CONTEXT: The user is currently browsing the \"{$this->parentName}\" section. " .
              "Strongly prioritize categories and presets within this section. " .
              "However, if the user's query clearly describes a different product type " .
              "(e.g., asking about keyboards while in an audio section), act as a smart " .
              "global router and return the best match regardless of current section.\n\n"
            : '';

        $json = json_encode($categoryContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are a smart search router for pw2d, a product comparison website.

{$contextBlock}Available categories and their presets:
{$json}

User query: "{$this->query}"

Identify the single best matching category. If a specific preset is a strong fit, include it.

Return ONLY valid JSON with no markdown fences:
{
  "suggested_category_slug": "the-slug",
  "suggested_preset_slug": "preset-slug-or-omit-this-key",
  "reasoning": "one short sentence"
}
PROMPT;
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.global-search');
    }
}