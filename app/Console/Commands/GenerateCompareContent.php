<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateCompareContent extends Command
{
    protected $signature = 'pw2d:generate-compare-content
                            {tenant : The tenant ID}
                            {--category= : Slug of a single category to process (leave blank for all leaf categories)}
                            {--regenerate : Overwrite existing content (default: skip categories that already have intro + methodology + faqs)}
                            {--dry-run : Print the generated JSON without saving to the database}';

    protected $description = 'AI-generate intro, methodology, and FAQs for leaf-category compare pages (Spec 021)';

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
        $categorySlug = $this->option('category');
        $regenerate   = (bool) $this->option('regenerate');
        $isDryRun     = (bool) $this->option('dry-run');

        // Build the query for leaf categories (no children) with eager-loaded relations.
        $query = Category::doesntHave('children')
            ->with([
                'features',
                'products' => fn ($q) => $q->orderByDesc('amazon_reviews_count')->limit(5),
            ]);

        if ($categorySlug) {
            $query->where('slug', $categorySlug);
        }

        $categories = $query->get();

        if ($categories->isEmpty()) {
            $this->warn(
                $categorySlug
                    ? "No leaf category found with slug: {$categorySlug}"
                    : 'No leaf categories found for this tenant.'
            );
            return self::SUCCESS;
        }

        $processed = 0;
        $skipped   = 0;
        $errored   = 0;

        foreach ($categories as $category) {
            $guide = is_array($category->buying_guide) ? $category->buying_guide : [];

            // Skip if all three keys are already populated and --regenerate is not set.
            if (!$regenerate
                && !empty($guide['intro'])
                && !empty($guide['methodology'])
                && !empty($guide['faqs'])
            ) {
                $this->line("  SKIP  {$category->slug} (already has content — use --regenerate to overwrite)");
                $skipped++;
                continue;
            }

            $this->line("  GEN   {$category->slug}...");

            try {
                $newContent = $this->ai->generateCompareContent($category);
            } catch (\Throwable $e) {
                $this->warn("  ERROR {$category->slug}: {$e->getMessage()}");
                Log::error('GenerateCompareContent: AI call failed', [
                    'category_slug' => $category->slug,
                    'error'         => $e->getMessage(),
                ]);
                $errored++;
                continue;
            }

            if ($isDryRun) {
                $this->line('  [DRY RUN] Generated JSON:');
                $this->line(json_encode($newContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $category->buying_guide = array_merge($guide, $newContent);
                $category->save();
                $this->info("  SAVED {$category->slug}");
            }

            $processed++;
        }

        // Summary table.
        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Processed', $processed],
                ['Skipped',   $skipped],
                ['Errored',   $errored],
            ]
        );

        if ($isDryRun) {
            $this->warn('[DRY RUN] No data was saved.');
        }

        return $errored === 0 ? self::SUCCESS : self::FAILURE;
    }
}
