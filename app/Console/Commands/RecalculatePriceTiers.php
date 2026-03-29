<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;

class RecalculatePriceTiers extends Command
{
    protected $signature = 'products:recalculate-tiers
                            {--category= : Limit to a specific category ID}';

    protected $description = 'Recalculate price_tier for all products using each category\'s budget_max / midrange_max thresholds.';

    public function handle(): int
    {
        $categoryId = $this->option('category');

        $query = Category::with(['products.offers'])
            ->whereNotNull('budget_max')
            ->whereNotNull('midrange_max');

        if ($categoryId) {
            $query->where('id', $categoryId);
        }

        $categories = $query->get();

        if ($categories->isEmpty()) {
            $this->warn('No categories with price thresholds found' . ($categoryId ? " (ID: {$categoryId})" : '') . '.');
            $this->line('Run the AI category generator first to populate budget_max / midrange_max.');
            return self::FAILURE;
        }

        $fixed   = 0;
        $skipped = 0;

        foreach ($categories as $category) {
            $rows = $category->products->filter(fn (Product $p) => $p->best_price !== null);

            if ($rows->isEmpty()) {
                continue;
            }

            $this->line("<fg=cyan>{$category->name}</> ({$rows->count()} products, budget≤\${$category->budget_max} / mid≤\${$category->midrange_max})");

            foreach ($rows as $product) {
                $correct = $category->priceTierFor((float) $product->best_price);

                if ($correct === null) {
                    $skipped++;
                    continue;
                }

                if ($correct === $product->price_tier) {
                    continue;
                }

                $old = $product->price_tier;
                $product->update(['price_tier' => $correct]);
                $fixed++;

                $this->line("  <fg=yellow>Updated</> {$product->name}  {$old}→{$correct}  (\${$product->best_price})");
            }
        }

        if (!$categoryId) {
            $unconfigured = Category::whereNull('budget_max')->orWhereNull('midrange_max')->count();
            if ($unconfigured > 0) {
                $this->newLine();
                $this->warn("{$unconfigured} category/categories skipped (no thresholds set yet — run AI generator first).");
            }
        }

        $this->newLine();
        $this->info("Done. {$fixed} product(s) updated, {$skipped} skipped (no price).");

        return self::SUCCESS;
    }
}
