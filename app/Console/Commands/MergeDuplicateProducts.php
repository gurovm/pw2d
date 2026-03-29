<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiMatchingDecision;
use App\Models\Product;
use App\Models\ProductOffer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MergeDuplicateProducts extends Command
{
    protected $signature = 'pw2d:merge-duplicates {--dry-run : Preview without modifying}';

    protected $description = 'Merge duplicate products with identical (name, brand_id, category_id) into canonical records';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Find duplicate groups: same name, brand_id, category_id with 2+ active products
        $groups = Product::withoutGlobalScopes()
            ->select('name', 'brand_id', 'category_id', DB::raw('COUNT(*) as cnt'), DB::raw('MIN(id) as canonical_id'))
            ->where('is_ignored', false)
            ->whereNull('status')
            ->whereNotNull('category_id')
            ->groupBy('name', 'brand_id', 'category_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate product groups found.');
            return self::SUCCESS;
        }

        $this->info("Found {$groups->count()} duplicate groups.");

        $totalMerged = 0;
        $totalCanonical = 0;

        foreach ($groups as $group) {
            $canonicalId = $group->canonical_id;
            $duplicates = Product::withoutGlobalScopes()
                ->where('name', $group->name)
                ->where('brand_id', $group->brand_id)
                ->where('category_id', $group->category_id)
                ->where('is_ignored', false)
                ->whereNull('status')
                ->where('id', '!=', $canonicalId)
                ->get();

            if ($duplicates->isEmpty()) {
                continue;
            }

            $totalCanonical++;

            if ($dryRun) {
                $this->info("  [DRY RUN] Would merge {$duplicates->count()} duplicates into #{$canonicalId} \"{$group->name}\"");
                foreach ($duplicates as $dup) {
                    $this->info("    - Duplicate #{$dup->id}");
                }
                $totalMerged += $duplicates->count();
                continue;
            }

            DB::transaction(function () use ($canonicalId, $duplicates, &$totalMerged) {
                foreach ($duplicates as $duplicate) {
                    $this->mergeDuplicate($canonicalId, $duplicate);
                    $totalMerged++;
                }
            });
        }

        $label = $dryRun ? '[DRY RUN] Would have merged' : 'Merged';
        $this->info("{$label} {$totalMerged} duplicate products into {$totalCanonical} canonical records.");

        return self::SUCCESS;
    }

    private function mergeDuplicate(int $canonicalId, Product $duplicate): void
    {
        $offers = ProductOffer::withoutGlobalScopes()
            ->where('product_id', $duplicate->id)
            ->get();

        foreach ($offers as $offer) {
            $existingOffer = ProductOffer::withoutGlobalScopes()
                ->where('product_id', $canonicalId)
                ->where('store_id', $offer->store_id)
                ->first();

            if ($existingOffer) {
                // Store conflict: keep the lower price
                if ($offer->scraped_price !== null && ($existingOffer->scraped_price === null || (float) $offer->scraped_price < (float) $existingOffer->scraped_price)) {
                    $existingOffer->update([
                        'scraped_price' => $offer->scraped_price,
                        'url'           => $offer->url,
                        'raw_title'     => $offer->raw_title,
                        'image_url'     => $offer->image_url,
                    ]);
                }
                $offer->delete();
            } else {
                $offer->update(['product_id' => $canonicalId]);
            }
        }

        // Redirect ai_matching_decisions from the duplicate to canonical
        AiMatchingDecision::withoutGlobalScopes()
            ->where('existing_product_id', $duplicate->id)
            ->update(['existing_product_id' => $canonicalId]);

        $duplicate->forceDelete();

        Log::info('MergeDuplicates: merged duplicate into canonical', [
            'duplicate_id' => $duplicate->id,
            'canonical_id' => $canonicalId,
            'name'         => $duplicate->name,
        ]);

        $this->info("  Merged #{$duplicate->id} into #{$canonicalId} \"{$duplicate->name}\"");
    }
}
