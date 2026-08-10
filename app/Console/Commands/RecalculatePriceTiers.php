<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Services\PriceTierRecalculator;
use Illuminate\Console\Command;

class RecalculatePriceTiers extends Command
{
    protected $signature = 'products:recalculate-tiers
                            {--category= : Limit to a specific category ID}';

    protected $description = 'Recalculate price_tier for all products using each category\'s budget_max / midrange_max thresholds.';

    public function __construct(private readonly PriceTierRecalculator $recalculator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $categoryId = $this->option('category');

        $query = Category::whereNotNull('budget_max')
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
            $this->line("<fg=cyan>{$category->name}</> (budget≤\${$category->budget_max} / mid≤\${$category->midrange_max})");

            $result = $this->recalculator->recalculateForCategory(
                $category,
                function (Product $product, ?int $old, int $new) {
                    $this->line("  <fg=yellow>Updated</> {$product->name}  {$old}→{$new}  (\${$product->best_price})");
                }
            );

            $fixed   += $result['fixed'];
            $skipped += $result['skipped'];
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
