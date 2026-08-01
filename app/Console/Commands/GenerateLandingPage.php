<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SelectLandingPagePicks;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateLandingPage extends Command
{
    protected $signature = 'pw2d:generate-landing-page
                            {tenant : The tenant ID}
                            {category-slug : Slug of the leaf category to generate a landing page for}
                            {--regenerate : Overwrite an existing landing page (keeps its slug and status)}
                            {--dry-run : Print selected picks + a prompt summary, with ZERO AI calls}
                            {--publish : Publish the page immediately instead of leaving it as a draft}';

    protected $description = 'Deterministically select picks and AI-generate a "Best X" landing page (Spec 027)';

    public function __construct(private AiService $ai)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');
        $tenant   = Tenant::find($tenantId);

        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            return $this->process();
        } finally {
            tenancy()->end();
        }
    }

    private function process(): int
    {
        $categorySlug = $this->argument('category-slug');
        $regenerate   = (bool) $this->option('regenerate');
        $isDryRun     = (bool) $this->option('dry-run');
        $publish      = (bool) $this->option('publish');

        $category = Category::where('slug', $categorySlug)->doesntHave('children')->first();

        if (!$category) {
            $this->error("No leaf category found with slug \"{$categorySlug}\" (parent/hub categories cannot have a landing page).");
            return self::FAILURE;
        }

        $existing = LandingPage::where('category_id', $category->id)->first();

        if ($existing && !$regenerate && !$isDryRun) {
            $this->warn("Landing page already exists for \"{$category->slug}\" (status: {$existing->status}). Use --regenerate to overwrite.");
            return self::SUCCESS;
        }

        $this->line("Selecting picks for \"{$category->name}\"...");

        try {
            $selected = (new SelectLandingPagePicks())->execute($category);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $productIds = collect($selected)->pluck('product_id');

        $products = Product::whereIn('id', $productIds)
            ->with(['brand', 'featureValues.feature', 'offers'])
            ->get()
            ->keyBy('id');

        $picks = collect($selected)
            ->map(fn (array $pick) => [
                'product_id' => $pick['product_id'],
                'role'       => $pick['role'],
                'product'    => $products[$pick['product_id']],
            ])
            ->values()
            ->all();

        $this->table(
            ['#', 'Role', 'Product', 'Tier', 'Est. Price'],
            collect($picks)->map(fn (array $p, int $i) => [
                $i + 1,
                $p['role'],
                $p['product']->name,
                $p['product']->price_tier ?? 'n/a',
                $p['product']->estimated_price !== null ? '$' . $p['product']->estimated_price : 'N/A',
            ])->all()
        );

        $excludeFaqQuestions = $this->collectExcludeFaqQuestions($category);

        // The true denominator the picks were chosen from — same eligibility filter
        // ProductCompare's scoredProducts() uses (processed + not ignored). Grounds
        // "we scored N X" prompt claims in a real number instead of a vague one.
        $scoredProductCount = Product::where('category_id', $category->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->count();

        if ($isDryRun) {
            $this->warn('[DRY RUN] Zero AI calls made.');
            $this->newLine();
            $this->line("Category: {$category->name} ({$category->slug})");
            $this->line("Scored product count: {$scoredProductCount}");
            $this->line('Picks: ' . collect($picks)->map(fn (array $p) => "{$p['role']}:{$p['product']->name}")->implode(', '));
            $this->line('Exclude FAQ questions (' . count($excludeFaqQuestions) . '): ' . implode(' | ', $excludeFaqQuestions));
            return self::SUCCESS;
        }

        $this->line('Generating AI content (admin_model)...');

        try {
            $content = $this->ai->generateLandingPageContent($category, $picks, $excludeFaqQuestions, $scoredProductCount);
        } catch (\Throwable $e) {
            $this->error("AI generation failed: {$e->getMessage()}");
            Log::error('GenerateLandingPage: AI call failed', [
                'category_slug' => $category->slug,
                'error'         => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        $aiPicksById = collect($content['picks'])->keyBy('product_id');

        $finalPicks = collect($picks)->map(function (array $p) use ($aiPicksById) {
            $aiPick = $aiPicksById[$p['product_id']] ?? null;

            return [
                'product_id' => $p['product_id'],
                'role'       => $p['role'],
                'headline'   => $aiPick['headline'] ?? '',
                'body'       => $aiPick['body'] ?? '',
            ];
        })->values()->all();

        $landingPage = $existing ?? new LandingPage([
            'tenant_id'   => tenant('id'),
            'category_id' => $category->id,
            'slug'        => Str::slug($category->name),
            'status'      => 'draft',
        ]);

        $landingPage->fill([
            'intro'            => $content['intro'],
            'picks'            => $finalPicks,
            'faqs'             => $content['faqs'],
            'methodology_note' => $content['methodology_note'],
            'generated_at'     => now(),
        ]);

        // --publish always publishes; otherwise a regeneration keeps the page's
        // existing status (a live page is not silently unpublished by a refresh).
        if ($publish) {
            $landingPage->status = 'published';
        }

        $landingPage->save();

        $this->info("SAVED landing page \"{$landingPage->slug}\" (status: {$landingPage->status}).");

        return self::SUCCESS;
    }

    /**
     * Existing FAQ questions the new landing-page FAQs must not duplicate:
     * the category's buying_guide FAQs plus every preset's seo_content FAQs.
     *
     * @return array<int, string>
     */
    private function collectExcludeFaqQuestions(Category $category): array
    {
        $questions = [];

        if (is_array($category->buying_guide['faqs'] ?? null)) {
            foreach ($category->buying_guide['faqs'] as $faq) {
                if (!empty($faq['question'])) {
                    $questions[] = $faq['question'];
                }
            }
        }

        foreach ($category->presets()->get() as $preset) {
            if (is_array($preset->seo_content['faqs'] ?? null)) {
                foreach ($preset->seo_content['faqs'] as $faq) {
                    if (!empty($faq['question'])) {
                        $questions[] = $faq['question'];
                    }
                }
            }
        }

        return array_values(array_unique($questions));
    }
}
