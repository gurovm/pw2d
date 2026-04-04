<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiMatchingDecision;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MergeDuplicateProducts extends Command implements Isolatable
{
    protected $signature = 'pw2d:merge-duplicates
                            {tenant : The tenant ID}
                            {--category= : Limit to a specific category slug}
                            {--dry-run : Preview without modifying}';

    protected $description = 'Merge duplicate products with identical (name, brand_id, category_id) into canonical records';

    /** @var array<int> Canonical product IDs that received merged offers — used for price_tier recalculation. */
    private array $affectedCanonicalIds = [];

    public function handle(): int
    {
        $tenant = Tenant::find($this->argument('tenant'));
        if (!$tenant) {
            $this->error("Tenant '{$this->argument('tenant')}' not found.");
            return self::FAILURE;
        }
        tenancy()->initialize($tenant);

        $dryRun      = $this->option('dry-run');
        $categorySlug = $this->option('category');

        // ── Phase 1: Exact duplicates (name + brand_id + category_id) ────────────
        $this->info('Phase 1: Exact duplicates (name + brand_id + category_id)');

        $groups = Product::withoutGlobalScopes()
            ->select('tenant_id', 'name', 'brand_id', 'category_id', DB::raw('COUNT(*) as cnt'), DB::raw('MIN(id) as canonical_id'))
            ->where('tenant_id', $tenant->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->whereNotNull('category_id')
            ->when($categorySlug, fn ($q, $slug) => $q->whereHas('category', fn ($cq) => $cq->where('slug', $slug)))
            ->groupBy('tenant_id', 'name', 'brand_id', 'category_id')
            ->having('cnt', '>', 1)
            ->get();

        $phase1Merged    = 0;
        $phase1Canonical = 0;

        foreach ($groups as $group) {
            $canonicalId = (int) $group->canonical_id;
            $duplicates  = Product::withoutGlobalScopes()
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

            $phase1Canonical++;

            if ($dryRun) {
                foreach ($duplicates as $dup) {
                    $this->line("  [DRY RUN] Would merge #{$dup->id} into #{$canonicalId} \"{$group->name}\"");
                }
                $phase1Merged += $duplicates->count();
                continue;
            }

            DB::transaction(function () use ($canonicalId, $duplicates, &$phase1Merged) {
                foreach ($duplicates as $duplicate) {
                    $this->mergeDuplicate($canonicalId, $duplicate);
                    $phase1Merged++;
                }
            });
        }

        $this->line("  Found {$phase1Canonical} exact duplicate groups.");

        // ── Phase 2: Brand-spelling duplicates ───────────────────────────────────
        $this->info('');
        $this->info('Phase 2: Brand-spelling duplicates (same name, different brand spelling)');

        $remaining = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->whereNotNull('category_id')
            ->when($categorySlug, fn ($q, $slug) => $q->whereHas('category', fn ($cq) => $cq->where('slug', $slug)))
            ->with('brand:id,name')
            ->get(['id', 'name', 'brand_id', 'category_id']);

        $fuzzyGroups = $remaining->groupBy(function ($p) {
            $normalizedBrand = AiService::normalizeBrandForComparison($p->brand?->name ?? '');
            return $normalizedBrand . '|' . mb_strtolower($p->name) . '|' . $p->category_id;
        })->filter(fn ($group) => $group->count() > 1);

        $phase2Merged    = 0;
        $phase2Canonical = 0;

        foreach ($fuzzyGroups as $fuzzyGroup) {
            // Keeper is the product with the lowest id
            $sorted      = $fuzzyGroup->sortBy('id');
            $keeper      = $sorted->first();
            $canonicalId = (int) $keeper->id;
            $duplicates  = $sorted->slice(1)->values();

            $phase2Canonical++;

            if ($dryRun) {
                foreach ($duplicates as $dup) {
                    $this->line("  [DRY RUN] Would merge #{$dup->id} \"{$dup->name}\" into #{$canonicalId} \"{$keeper->name}\"");
                }
                $phase2Merged += $duplicates->count();
                continue;
            }

            DB::transaction(function () use ($canonicalId, $duplicates, &$phase2Merged) {
                foreach ($duplicates as $duplicate) {
                    // Re-fetch the full model so forceDelete() has all attributes
                    $fullDuplicate = Product::withoutGlobalScopes()->find($duplicate->id);
                    if (!$fullDuplicate) {
                        continue;
                    }
                    $this->mergeDuplicate($canonicalId, $fullDuplicate);
                    $phase2Merged++;
                }
            });
        }

        $this->line("  Found {$phase2Canonical} brand-spelling duplicate groups.");

        // ── Recalculate price_tier for all affected canonical products ────────────
        if (!$dryRun && $this->affectedCanonicalIds !== []) {
            $this->info('');
            $this->info('Recalculating price_tier for ' . count($this->affectedCanonicalIds) . ' affected products...');

            foreach (array_unique($this->affectedCanonicalIds) as $id) {
                $product = Product::withoutGlobalScopes()->with('category')->find($id);
                if (!$product?->category) {
                    continue;
                }

                $bestPrice = ProductOffer::withoutGlobalScopes()
                    ->where('product_id', $id)
                    ->whereNotNull('scraped_price')
                    ->min('scraped_price');

                if ($bestPrice !== null) {
                    $newTier = $product->category->priceTierFor((float) $bestPrice);
                    if ($newTier !== null) {
                        $product->update(['price_tier' => $newTier]);
                    }
                }
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────────
        $this->info('');
        $totalMerged    = $phase1Merged + $phase2Merged;
        $totalCanonical = $phase1Canonical + $phase2Canonical;
        $label          = $dryRun ? '[DRY RUN] Would have merged' : 'Total: merged';
        $this->info("{$label} {$totalMerged} duplicates into {$totalCanonical} canonical products.");

        if ($dryRun) {
            $this->line('[DRY RUN] No changes made. Remove --dry-run to execute.');
        }

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

        // Transfer feature values the canonical doesn't already have
        $canonicalFeatureIds = ProductFeatureValue::where('product_id', $canonicalId)
            ->pluck('feature_id');

        ProductFeatureValue::where('product_id', $duplicate->id)
            ->whereNotIn('feature_id', $canonicalFeatureIds)
            ->update(['product_id' => $canonicalId]);

        // Delete remaining (overlapping) feature values on the duplicate
        ProductFeatureValue::where('product_id', $duplicate->id)->delete();

        $duplicate->forceDelete();

        // Track this canonical for price_tier recalculation
        $this->affectedCanonicalIds[] = $canonicalId;

        Log::info('MergeDuplicates: merged duplicate into canonical', [
            'duplicate_id' => $duplicate->id,
            'canonical_id' => $canonicalId,
            'name'         => $duplicate->name,
        ]);

        $this->line("  Merged #{$duplicate->id} into #{$canonicalId} \"{$duplicate->name}\"");
    }
}
