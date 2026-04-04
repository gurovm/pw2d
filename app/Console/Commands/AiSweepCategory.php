<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiCategoryRejection;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

class AiSweepCategory extends Command implements Isolatable
{
    protected $signature = 'pw2d:ai-sweep-category
                            {tenant : The tenant ID}
                            {category_slug : The slug of the category to sweep}
                            {--dry-run : Preview flagged products without making changes}';

    protected $description = 'Use AI to identify and remove products that don\'t belong in a category';

    public function handle(AiService $aiService): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if (!$tenant) {
            $this->error("Tenant not found: {$this->argument('tenant')}");
            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        $category = Category::where('slug', $this->argument('category_slug'))->first();

        if (!$category) {
            $this->error("Category not found: {$this->argument('category_slug')}");
            return self::FAILURE;
        }

        $isDryRun = $this->option('dry-run');
        $prefix = $isDryRun ? '[DRY RUN] ' : '';

        $count = Product::where('category_id', $category->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->count();

        if ($count === 0) {
            $this->info('No active products in this category.');
            return self::SUCCESS;
        }

        $this->info("{$prefix}Sweeping \"{$category->name}\" ({$count} products)...");

        $totalFlagged = 0;
        $failedChunks = 0;

        Product::where('category_id', $category->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->select(['id', 'name'])
            ->chunkById(25, function ($chunk) use ($aiService, $category, $isDryRun, &$totalFlagged, &$failedChunks) {
                try {
                    $flagged = $aiService->sweepCategoryPollution($category, $chunk);
                } catch (\Exception $e) {
                    $this->error("  AI call failed: {$e->getMessage()}");
                    $failedChunks++;
                    return;
                }

                foreach ($flagged as $item) {
                    $product = $chunk->firstWhere('id', (int) $item['id']);
                    if (!$product) continue;

                    $totalFlagged++;

                    if ($isDryRun) {
                        $this->line("  <fg=yellow>WOULD REMOVE</> #{$item['id']} {$product->name}");
                        $this->line("    <fg=gray>Reason: {$item['reason']}</>");
                    } else {
                        AiCategoryRejection::firstOrCreate(
                            ['product_id' => (int) $item['id'], 'category_id' => $category->id],
                            ['rejection_reason' => $item['reason']]
                        );

                        Product::where('id', (int) $item['id'])->update(['category_id' => null]);

                        $this->line("  <fg=red>REMOVED</> #{$item['id']} {$product->name}");
                        $this->line("    <fg=gray>Reason: {$item['reason']}</>");
                    }
                }
            });

        $this->newLine();
        $action = $isDryRun ? 'would be removed' : 'removed';
        $this->info("{$prefix}{$totalFlagged} product(s) {$action} from \"{$category->name}\".");

        if ($failedChunks > 0) {
            $this->warn("{$failedChunks} chunk(s) failed. Some products may not have been processed.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
