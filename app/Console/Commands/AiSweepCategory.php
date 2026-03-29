<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiCategoryRejection;
use App\Models\Category;
use App\Models\Product;
use App\Services\AiService;
use Illuminate\Console\Command;

class AiSweepCategory extends Command
{
    protected $signature = 'pw2d:ai-sweep-category
                            {category_slug : The slug of the category to sweep}
                            {--dry-run : Preview flagged products without making changes}';

    protected $description = 'Use AI to identify and remove products that don\'t belong in a category';

    public function handle(AiService $aiService): int
    {
        $category = Category::where('slug', $this->argument('category_slug'))->first();

        if (!$category) {
            $this->error("Category not found: {$this->argument('category_slug')}");
            return self::FAILURE;
        }

        $isDryRun = $this->option('dry-run');
        $prefix = $isDryRun ? '[DRY RUN] ' : '';

        $products = Product::where('category_id', $category->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->get(['id', 'name']);

        if ($products->isEmpty()) {
            $this->info('No active products in this category.');
            return self::SUCCESS;
        }

        $this->info("{$prefix}Sweeping \"{$category->name}\" ({$products->count()} products)...");

        $totalFlagged = 0;

        $products->chunk(25)->each(function ($chunk) use ($aiService, $category, $isDryRun, &$totalFlagged) {
            $flagged = $aiService->sweepCategoryPollution($category, $chunk);

            foreach ($flagged as $item) {
                $product = $chunk->firstWhere('id', $item['id']);
                if (!$product) continue;

                $totalFlagged++;

                if ($isDryRun) {
                    $this->line("  <fg=yellow>WOULD REMOVE</> #{$item['id']} {$product->name}");
                    $this->line("    <fg=gray>Reason: {$item['reason']}</>");
                } else {
                    AiCategoryRejection::firstOrCreate(
                        ['product_id' => $item['id'], 'category_id' => $category->id],
                        ['rejection_reason' => $item['reason']]
                    );

                    Product::where('id', $item['id'])->update(['category_id' => null]);

                    $this->line("  <fg=red>REMOVED</> #{$item['id']} {$product->name}");
                    $this->line("    <fg=gray>Reason: {$item['reason']}</>");
                }
            }
        });

        $this->newLine();
        $action = $isDryRun ? 'would be removed' : 'removed';
        $this->info("{$prefix}{$totalFlagged} product(s) {$action} from \"{$category->name}\".");

        return self::SUCCESS;
    }
}
