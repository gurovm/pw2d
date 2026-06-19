<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Preset;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeneratePresetContent extends Command
{
    protected $signature = 'pw2d:generate-preset-content
                            {tenant : The tenant ID}
                            {--category= : Slug of a single category to process (leave blank for all leaf categories)}
                            {--preset= : Slug of a single preset to process (leave blank for all presets in scope)}
                            {--dry-run : Print would-be content without saving to the database}';

    protected $description = 'AI-generate use-case intro and FAQs for presets on compare pages (Spec 023)';

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
        $presetSlug   = $this->option('preset');
        $isDryRun     = (bool) $this->option('dry-run');

        // Build category query — leaf categories only (no children), with preset relations.
        $query = Category::doesntHave('children')
            ->with([
                'presets.presetFeatures.feature',
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

        // Collect all matching presets across categories.
        $presets = $categories->flatMap(function (Category $category) use ($presetSlug) {
            return $category->presets->filter(function (Preset $preset) use ($presetSlug) {
                // IMPORTANT: use Str::slug($preset->name) consistently — this is the same
                // derivation used in SeoSchema::forLeafCategory and ProductCompare::activePreset().
                // If two presets in a category slugify identically, this is a pre-existing latent
                // bug that must be flagged (see Spec 023 §10) — we don't paper over it here.
                return $presetSlug === null || Str::slug($preset->name) === $presetSlug;
            })->each(fn (Preset $p) => $p->setRelation('category', $category));
        });

        if ($presets->isEmpty()) {
            $this->warn(
                $presetSlug
                    ? "No preset found with slug: {$presetSlug}"
                    : 'No presets found for the given category filter.'
            );
            return self::SUCCESS;
        }

        // Cost guard: log estimated AI calls before running.
        $count = $presets->count();
        $this->info("Estimated admin_model calls: {$count} (one per preset).");

        if ($isDryRun) {
            $this->warn('[DRY RUN] No data will be saved.');
        }

        $this->newLine();

        $processed = 0;
        $errored   = 0;

        foreach ($presets as $preset) {
            $slug  = Str::slug($preset->name);
            $label = "{$preset->category->slug} / {$slug}";

            $this->line("  GEN   {$label}...");

            try {
                $newContent = $this->ai->generatePresetContent($preset);
            } catch (\Throwable $e) {
                $this->warn("  ERROR {$label}: {$e->getMessage()}");
                Log::error('GeneratePresetContent: AI call failed', [
                    'preset_id'     => $preset->id,
                    'preset_name'   => $preset->name,
                    'category_slug' => $preset->category->slug,
                    'error'         => $e->getMessage(),
                ]);
                $errored++;
                continue;
            }

            if ($isDryRun) {
                $this->line('  [DRY RUN] Generated JSON:');
                $this->line(json_encode($newContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $preset->seo_content = $newContent;
                $preset->save();
                $this->info("  SAVED {$label}");
            }

            $processed++;
        }

        // Summary table.
        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Processed', $processed],
                ['Errored',   $errored],
            ]
        );

        if ($isDryRun) {
            $this->warn('[DRY RUN] No data was saved.');
        }

        // Non-zero exit if any preset failed — mirrors pw2d:seo:pull exit-code rule (F25).
        return $errored === 0 ? self::SUCCESS : self::FAILURE;
    }
}
