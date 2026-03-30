<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Services\AiService;
use Illuminate\Console\Command;

class AiAssignCategories extends Command
{
    protected $signature = 'pw2d:ai-assign-categories
                            {--dry-run : Preview assignments without making changes}
                            {--ignore-unmatched : Mark products that don\'t fit any category as ignored}';

    protected $description = 'Use AI to assign categories to uncategorized products';

    public function handle(AiService $aiService): int
    {
        $isDryRun = $this->option('dry-run');
        $ignoreUnmatched = $this->option('ignore-unmatched');
        $prefix = $isDryRun ? '[DRY RUN] ' : '';

        $products = Product::whereNull('category_id')
            ->where('is_ignored', false)
            ->get(['id', 'name']);

        if ($products->isEmpty()) {
            $this->info('No uncategorized products found.');
            return self::SUCCESS;
        }

        $this->info("{$prefix}Found {$products->count()} uncategorized product(s).");

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

        $products->chunk(10)->each(function ($chunk) use (
            $aiService, $leafCategories, $isDryRun, $ignoreUnmatched, $prefix,
            &$assigned, &$ignored, &$unmatched
        ) {
            $results = $aiService->assignCategories($chunk, $leafCategories);

            foreach ($results as $item) {
                $product = $chunk->firstWhere('id', $item['id']);
                if (!$product) continue;

                if ($item['category_id']) {
                    $categoryName = collect($leafCategories)->firstWhere('id', $item['category_id'])['name'] ?? '?';
                    $assigned++;

                    if ($isDryRun) {
                        $this->line("  <fg=green>WOULD ASSIGN</> #{$item['id']} {$product->name}");
                        $this->line("    <fg=gray>→ {$categoryName}: {$item['reason']}</>");
                    } else {
                        Product::where('id', $item['id'])->update(['category_id' => $item['category_id']]);
                        $this->line("  <fg=green>ASSIGNED</> #{$item['id']} {$product->name}");
                        $this->line("    <fg=gray>→ {$categoryName}: {$item['reason']}</>");
                    }
                } else {
                    if ($ignoreUnmatched) {
                        $ignored++;
                        if ($isDryRun) {
                            $this->line("  <fg=yellow>WOULD IGNORE</> #{$item['id']} {$product->name}");
                        } else {
                            Product::where('id', $item['id'])->update(['is_ignored' => true]);
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
        $action = $isDryRun ? 'would be' : '';
        $this->info("{$prefix}{$assigned} product(s) {$action} assigned to categories.");
        if ($ignored) {
            $this->info("{$prefix}{$ignored} product(s) {$action} marked as ignored.");
        }
        if ($unmatched) {
            $this->warn("{$unmatched} product(s) could not be matched (use --ignore-unmatched to suppress them).");
        }

        return self::SUCCESS;
    }
}
