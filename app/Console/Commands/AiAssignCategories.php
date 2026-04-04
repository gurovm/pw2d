<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RescanProductFeatures;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

class AiAssignCategories extends Command implements Isolatable
{
    protected $signature = 'pw2d:ai-assign-categories
                            {tenant : The tenant ID (e.g., "coffee-decide")}
                            {--dry-run : Preview assignments without making changes}
                            {--ignore-unmatched : Mark products that don\'t fit any category as ignored}';

    protected $description = 'Use AI to assign categories to uncategorized products';

    public function handle(AiService $aiService): int
    {
        $tenant = Tenant::find($this->argument('tenant'));
        if (!$tenant) {
            $this->error("Tenant not found: {$this->argument('tenant')}");
            return self::FAILURE;
        }
        tenancy()->initialize($tenant);

        $isDryRun = $this->option('dry-run');
        $ignoreUnmatched = $this->option('ignore-unmatched');
        $prefix = $isDryRun ? '[DRY RUN] ' : '';

        $count = Product::whereNull('category_id')
            ->where('is_ignored', false)
            ->count();

        if ($count === 0) {
            $this->info('No uncategorized products found.');
            return self::SUCCESS;
        }

        $this->info("{$prefix}Found {$count} uncategorized product(s).");

        // Build leaf category options (only categories without children)
        $leafCategories = Category::doesntHave('children')
            ->get(['id', 'name', 'description'])
            ->map(fn($c) => [
                'id'          => $c->id,
                'name'        => $c->name,
                'description' => $c->description,
            ])
            ->values()
            ->toArray();

        if (empty($leafCategories)) {
            $this->error('No leaf categories found.');
            return self::FAILURE;
        }

        $this->info("{$prefix}Available categories: " . collect($leafCategories)->pluck('name')->join(', '));
        $this->newLine();

        $assigned = 0;
        $ignored = 0;
        $unmatched = 0;
        $failedChunks = 0;

        // Build lookup map once to avoid O(n) collection searches per item
        $categoryNameMap = collect($leafCategories)->pluck('name', 'id')->toArray();

        // chunkById avoids loading all rows into memory (AI prompt token limit drives chunk size of 10)
        Product::whereNull('category_id')
            ->where('is_ignored', false)
            ->select(['id', 'name'])
            ->chunkById(10, function ($chunk) use (
                $aiService, $leafCategories, $categoryNameMap,
                $isDryRun, $ignoreUnmatched, $prefix,
                &$assigned, &$ignored, &$unmatched, &$failedChunks
            ) {
                try {
                    $results = $aiService->assignCategories($chunk, $leafCategories);
                } catch (\Exception $e) {
                    $this->error("  AI call failed: {$e->getMessage()}");
                    $failedChunks++;
                    return;
                }

                foreach ($results as $item) {
                    $product = $chunk->firstWhere('id', $item['id']);
                    if (!$product) continue;

                    if ($item['category_id']) {
                        $categoryName = $categoryNameMap[$item['category_id']] ?? '?';
                        $assigned++;

                        if ($isDryRun) {
                            $this->line("  <fg=green>WOULD ASSIGN</> #{$item['id']} {$product->name}");
                            $this->line("    <fg=gray>→ {$categoryName}: {$item['reason']}</>");
                        } else {
                            Product::where('id', (int) $item['id'])->update(['category_id' => (int) $item['category_id']]);
                            // Stagger dispatches 5 s apart to avoid flooding workers and Gemini rate limits
                            RescanProductFeatures::dispatch((int) $item['id'], (int) $item['category_id'])
                                ->delay(now()->addSeconds($assigned * 5));
                            $this->line("  <fg=green>ASSIGNED + QUEUED RESCAN</> #{$item['id']} {$product->name}");
                            $this->line("    <fg=gray>→ {$categoryName}: {$item['reason']}</>");
                        }
                    } else {
                        if ($ignoreUnmatched) {
                            $ignored++;
                            if ($isDryRun) {
                                $this->line("  <fg=yellow>WOULD IGNORE</> #{$item['id']} {$product->name}");
                            } else {
                                Product::where('id', (int) $item['id'])->update(['is_ignored' => true]);
                                $this->line("  <fg=yellow>IGNORED</> #{$item['id']} {$product->name}");
                            }
                        } else {
                            $unmatched++;
                            $this->line("  <fg=red>NO MATCH</> #{$item['id']} {$product->name}");
                        }
                        $this->line("    <fg=gray>Reason: {$item['reason']}</>");
                    }
                }
            });

        $this->newLine();

        $verb = $isDryRun ? 'would be assigned' : 'assigned';
        $this->info("{$prefix}{$assigned} product(s) {$verb} to categories.");

        if ($ignored) {
            $verbIgnored = $isDryRun ? 'would be marked' : 'marked';
            $this->info("{$prefix}{$ignored} product(s) {$verbIgnored} as ignored.");
        }

        if ($unmatched) {
            $this->warn("{$unmatched} product(s) could not be matched (use --ignore-unmatched to suppress them).");
        }

        return $failedChunks > 0 ? self::FAILURE : self::SUCCESS;
    }
}
