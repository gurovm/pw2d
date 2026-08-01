<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\ProductConditionGuard;
use Illuminate\Console\Command;

/**
 * Audit command for Spec 027 Addendum A §2d — lists (and optionally flags is_ignored)
 * products whose offer raw_title or ai_summary matches a condition marker
 * (renewed/refurbished/open box/pre-owned/used). Read-only unless --ignore is passed;
 * the owner reviews the list first.
 *
 * `--urls --category={slug}` is a SEPARATE, read-only review mode: it does not fetch
 * anything (Amazon blocks direct server/CLI fetches — a live-fetch mode was tried and
 * dropped for that reason). It just prints each candidate's offer URL alongside its
 * name/est. price so a human (or the Chrome extension, later) can eyeball the actual
 * listing in a real browser — the only reliable way to catch a Renewed listing whose
 * stored raw_title/ai_summary are clean (an older scrape can predate a listing flipping
 * condition, or can have stored an already-cleaned title).
 */
class FlagConditionProducts extends Command
{
    protected $signature = 'pw2d:flag-condition-products
                            {tenant : The tenant ID}
                            {--ignore : Set is_ignored=true on every matched product}
                            {--urls : Print an offer-URL review table for one category (read-only) instead of the marker audit}
                            {--category= : Category slug — required with --urls}';

    protected $description = 'Audit (and optionally flag) products with renewed/refurbished/open-box/used condition markers (Spec 027 Addendum A §2)';

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');
        $tenant   = Tenant::find($tenantId);

        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return self::FAILURE;
        }

        $printUrls    = (bool) $this->option('urls');
        $categorySlug = $this->option('category');

        if ($printUrls && !$categorySlug) {
            $this->error('--urls requires --category={slug}.');
            $this->line("Example: php artisan pw2d:flag-condition-products {$tenantId} --urls --category=mechanical-gaming-keyboards");
            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            return $printUrls ? $this->printUrls($categorySlug) : $this->process();
        } finally {
            tenancy()->end();
        }
    }

    // =========================================================================
    // Marker audit (Spec 027 Addendum A §2d — unchanged)
    // =========================================================================

    private function process(): int
    {
        $shouldIgnore = (bool) $this->option('ignore');

        $products = Product::with(['category:id,name', 'offers:id,product_id,raw_title'])
            ->get();

        $matches = [];

        foreach ($products as $product) {
            $marker = ProductConditionGuard::summaryMarker($product->ai_summary);

            if ($marker !== null) {
                $matches[] = [$product, 'ai_summary', $marker];
                continue;
            }

            foreach ($product->offers as $offer) {
                $marker = ProductConditionGuard::titleMarker($offer->raw_title);

                if ($marker !== null) {
                    $matches[] = [$product, 'offer.raw_title', $marker];
                    break;
                }
            }
        }

        if (empty($matches)) {
            $this->info('No condition-marked products found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Category', 'Matched On', 'Marker'],
            collect($matches)->map(fn (array $m) => [
                $m[0]->id,
                $m[0]->name,
                $m[0]->category->name ?? 'n/a',
                $m[1],
                $m[2],
            ])->all()
        );

        $this->line(sprintf('%d condition-marked product(s) found.', count($matches)));

        if (!$shouldIgnore) {
            $this->comment('Read-only run — pass --ignore to set is_ignored=true on the products listed above.');
            return self::SUCCESS;
        }

        $ids = collect($matches)->map(fn (array $m) => $m[0]->id)->unique()->values();

        Product::whereIn('id', $ids)->update(['is_ignored' => true]);

        $this->info("Flagged {$ids->count()} product(s) as is_ignored=true.");

        return self::SUCCESS;
    }

    // =========================================================================
    // --urls review mode (owner follow-up: no live fetch, human-verifiable list)
    // =========================================================================

    private function printUrls(string $categorySlug): int
    {
        $category = Category::where('slug', $categorySlug)->first();

        if (!$category) {
            $this->error("No category found with slug \"{$categorySlug}\".");
            return self::FAILURE;
        }

        // Landing-page-eligible products (same core gate as SelectLandingPagePicks:
        // processed, not ignored, has an ai_summary) — with offers eager-loaded so we
        // can pick a representative URL per product without N+1 queries.
        $eligible = Product::where('category_id', $category->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->whereNotNull('ai_summary')
            ->with('offers:id,product_id,store_id,url')
            ->get()
            ->keyBy('id');

        if ($eligible->isEmpty()) {
            $this->info("No landing-page-eligible products found in \"{$category->name}\".");
            return self::SUCCESS;
        }

        // If a landing page already exists for this category, its current picks are
        // listed FIRST (in role order) — that's what's actually live right now and
        // the most important thing for a human to spot-check.
        $landingPage = LandingPage::where('category_id', $category->id)->first();

        $orderedIds = [];
        $roleById   = [];

        if ($landingPage) {
            foreach ($landingPage->picks as $pick) {
                if (isset($eligible[$pick['product_id']])) {
                    $orderedIds[]                  = $pick['product_id'];
                    $roleById[$pick['product_id']] = $pick['role'];
                }
            }
        }

        foreach ($eligible->keys() as $id) {
            if (!in_array($id, $orderedIds, true)) {
                $orderedIds[] = $id;
            }
        }

        $rows = collect($orderedIds)->map(function (int $id) use ($eligible, $roleById) {
            $product = $eligible[$id];
            $offer   = $product->offers->firstWhere('store_id', '!=', null) ?? $product->offers->first();

            return [
                $product->id,
                $product->name,
                $offer->url ?? 'n/a',
                $product->estimated_price !== null ? '$' . $product->estimated_price : 'N/A',
                $roleById[$id] ?? 'candidate',
            ];
        });

        $this->table(['ID', 'Name', 'Offer URL', 'Est. Price', 'Role'], $rows->all());

        $this->line(sprintf(
            '%d product(s) listed for "%s"%s.',
            $rows->count(),
            $category->name,
            $landingPage ? " ({$landingPage->status} landing page's picks first)" : ''
        ));
        $this->comment('Read-only — open each URL in a real browser to verify listing condition (Amazon blocks direct server/CLI fetches).');

        return self::SUCCESS;
    }
}
