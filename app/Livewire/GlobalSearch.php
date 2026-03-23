<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Category;
use App\Models\Preset;
use App\Models\Product;
use App\Models\SearchLog;
use Illuminate\Support\Facades\Cache;
use App\Services\GeminiService;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class GlobalSearch extends Component
{
    public string $query = '';
    public string $variant = 'nav';   // 'nav' | 'hero'
    public array  $samplePrompts = [];
    public ?int   $parentId = null;
    public string $parentName = '';
    public ?int   $browsingCategoryId = null; // Category the user is currently viewing (for search boost)
    public bool   $open = false;
    public bool   $isAiSearching = false;
    public ?array $aiSuggestion = null;
    public ?string $aiError = null;
    public array  $dbResults = [];

    public function mount(
        string $variant = 'nav',
        ?int $parentId = null,
        array $samplePrompts = []
    ): void {
        $this->variant       = $variant;
        $this->samplePrompts = $samplePrompts;
        $this->parentId      = $parentId;

        if ($parentId) {
            $this->parentName = Category::find($parentId)?->name ?? '';
        }

        // Resolve current browsing category once at mount (initial page load).
        // On category pages (/compare/{slug}), this lets us boost matching products in search.
        $slug = request()->segment(2);
        if ($slug && request()->segment(1) === 'compare') {
            $this->browsingCategoryId = Category::where('slug', $slug)->value('id');
        }
    }

    /**
     * Called by hint-chip buttons on the home/parent-category pages.
     * Hint chips represent complete user intent, so we run DB search
     * first, and auto-fire AI if no DB results are found.
     */
    #[On('set-search-query')]
    public function setQuery(string $query): void
    {
        $this->query = $query;
        $this->search();

        if (empty($this->dbResults)) {
            $this->triggerAiSearch();
        }
    }

    /**
     * Livewire lifecycle hook — fires automatically when wire:model.live
     * syncs the $query property. Delegates to search() so Alpine can also
     * call $wire.search() directly without hitting the lifecycle-hook guard.
     */
    public function updatedQuery(): void
    {
        $this->search();
    }

    /**
     * Phase 1 — instant DB search (reactive, fires on every debounced keystroke).
     * Never triggers AI automatically.
     */
    public function search(): void
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
    }

    /**
     * Explicit AI trigger — called by Enter key or CTA click.
     * Validates query, shows labor-illusion, then runs AI.
     */
    public function triggerAiSearch(): void
    {
        if (mb_strlen(trim($this->query)) < 3 || $this->isAiSearching) {
            return;
        }

        $this->dbResults     = [];
        $this->aiSuggestion  = null;
        $this->aiError       = null;
        $this->isAiSearching = true;
        $this->open          = true;

        $this->performAiSearch();
    }

    /**
     * Phase 2 — AI fallback. Called by triggerAiSearch().
     */
    public function performAiSearch(): void
    {
        if (!$this->isAiSearching || mb_strlen(trim($this->query)) < 3) {
            return;
        }

        try {
            $categories = Cache::remember(
                tenant_cache_key('search:categories_with_presets'),
                3600,
                fn () => Category::with('presets:id,category_id,name')
                    ->get(['id', 'name', 'slug', 'description'])
            );

            $categoryContext = $categories->map(fn (Category $c) => [
                'name'        => $c->name,
                'slug'        => $c->slug,
                'description' => $c->description,
                'presets'     => $c->presets->map(fn (Preset $p) => [
                    'name' => $p->name,
                    'slug' => Str::slug($p->name),
                ])->values()->toArray(),
            ])->values()->toArray();

            $gemini = app(GeminiService::class);
            $result = $gemini->generate(
                $this->buildPrompt($categoryContext),
                [
                    'maxOutputTokens' => 1024,
                    'thinkingConfig'  => ['thinkingBudget' => 0],
                    'timeout'         => 15,
                ]
            );
            $parsed = $result['parsed'];

            // Fallback: if simple fence stripping failed, try robust JSON extraction
            if ($parsed === null && !empty($result['content'])) {
                $raw = $result['content'];
                if (preg_match('/(\{.*\})/s', $raw, $m)) {
                    $parsed = json_decode(trim($m[1]), true);
                }
                if ($parsed === null) {
                    \Log::error('GlobalSearch: failed to parse AI JSON', ['raw' => $raw]);
                }
            }

            $categorySlug = $parsed['suggested_category_slug']
                ?? $parsed['category_slug']
                ?? $parsed['slug']
                ?? '';

            if (empty($categorySlug)) {
                throw new \Exception('No match found. Try a more specific phrase.');
            }

            $category = Category::with('presets:id,category_id,name')
                ->where('slug', $categorySlug)
                ->first();

            if (!$category) {
                throw new \Exception('AI returned an unknown category.');
            }

            $presetSlug = null;
            $presetName = null;
            $parsedPresetSlug = $parsed['suggested_preset_slug']
                ?? $parsed['preset_slug']
                ?? '';
            if (!empty($parsedPresetSlug)) {
                $preset = $category->presets->first(
                    fn (Preset $p) => Str::slug($p->name) === $parsedPresetSlug
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

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function runDbSearch(): void
    {
        $term    = str_replace(['%', '_'], ['\%', '\_'], $this->query);
        $results = [];

        $catQ = Category::where('name', 'like', "%{$term}%");
        if ($this->parentId) {
            $catQ->where('parent_id', $this->parentId);
        }
        foreach ($catQ->limit(4)->get(['id', 'name', 'slug']) as $cat) {
            $results[] = ['type' => 'category', 'name' => $cat->name, 'url' => route('category.show', $cat->slug)];
        }

        $presetQ = Preset::with('category:id,name,slug')->where('name', 'like', "%{$term}%");
        if ($this->parentId) {
            $childIds = Category::where('parent_id', $this->parentId)->pluck('id');
            $presetQ->whereIn('category_id', $childIds);
        }
        foreach ($presetQ->limit(3)->get() as $preset) {
            $results[] = [
                'type'          => 'preset',
                'name'          => $preset->name,
                'category_name' => $preset->category->name,
                'url'           => route('category.show', $preset->category->slug) . '?preset=' . Str::slug($preset->name),
            ];
        }

        $productQ = Product::with('category:id,name,slug')
            ->whereNull('status')
            ->where('is_ignored', false)
            ->where('name', 'like', "%{$term}%");
        if ($this->parentId) {
            $productQ->whereHas('category', fn ($q) => $q->where('parent_id', $this->parentId));
        }

        // Boost products from the category the user is currently browsing
        if ($this->browsingCategoryId) {
            $productQ->orderByRaw('category_id = ? DESC', [$this->browsingCategoryId]);
        }

        foreach ($productQ->limit(4)->get(['id', 'name', 'slug', 'category_id', 'external_image_path']) as $product) {
            $results[] = [
                'type'          => 'product',
                'name'          => $product->name,
                'category_name' => $product->category?->name,
                'image'         => $product->external_image_path,
                'url'           => route('category.show', $product->category?->slug ?? '#') . '?focus=' . $product->slug,
            ];
        }

        $this->dbResults = $results;
    }

    private function buildPrompt(array $categoryContext): string
    {
        $contextBlock = $this->parentName
            ? "CONTEXT: The user is currently browsing the \"{$this->parentName}\" section. " .
              "Strongly prioritize categories and presets within this section. " .
              "However, if the query clearly describes a different product type, route globally.\n\n"
            : '';

        $json = json_encode($categoryContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are a smart search router for pw2d, a product comparison website.

{$contextBlock}Available categories and their presets:
{$json}

User query: "{$this->query}"

Identify the single best matching category. If a specific preset is a strong fit, include it.

You MUST respond with ONLY a raw JSON object — no markdown, no backticks, no explanation text before or after.
Use EXACTLY these key names (no variations):
{
  "suggested_category_slug": "the-exact-slug-from-the-list-above",
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
