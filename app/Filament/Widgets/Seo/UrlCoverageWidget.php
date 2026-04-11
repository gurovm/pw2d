<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Seo;

use App\Models\Category;
use App\Models\Preset;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shows URL coverage stats: how many sitemap URLs have been seen in GSC
 * and how many have never appeared.
 *
 * The sitemap URL set is rebuilt from the same data the SitemapController
 * uses (Category + Product + Preset). This duplicates that logic for now
 * and is tracked as an F7 follow-up to refactor into a shared service.
 *
 * All seo_metrics queries are explicitly filtered by tenant_id.
 */
class UrlCoverageWidget extends BaseWidget
{
    protected static bool $isLazy = true;

    // SEO data only changes once per day at 03:00 when pw2d:seo:pull runs.
    // Without this override, this widget would re-run SitemapController's
    // query logic every 5s against the main products/categories tables.
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $tenant = filament()->getTenant();

        if ($tenant === null) {
            return [];
        }

        $tenantId = $tenant->getTenantKey();

        // Build the set of expected URLs from the sitemap query logic.
        $sitemapUrls = $this->buildSitemapUrlSet();

        // URLs that appear in GSC data for this tenant (all time).
        $gscUrls = DB::table('seo_metrics')
            ->where('tenant_id', $tenantId)
            ->where('source', 'gsc')
            ->distinct()
            ->pluck('url')
            ->map(fn (string $u) => rtrim($u, '/'))
            ->flip(); // key lookup is O(1)

        $sitemapUrlsNormalised = $sitemapUrls->map(fn (string $u) => rtrim($u, '/'));

        $inSitemapAndGsc    = $sitemapUrlsNormalised->filter(fn ($u) => $gscUrls->has($u))->count();
        $inSitemapNotInGsc  = $sitemapUrlsNormalised->filter(fn ($u) => ! $gscUrls->has($u))->count();

        // URLs GSC has seen but that are not in the sitemap (may be indexed via other means
        // or may be stale URLs worth investigating).
        $indexedNotInSitemap = collect($gscUrls->keys())
            ->filter(fn (string $u) => ! $sitemapUrlsNormalised->contains($u))
            ->count();

        $total = $sitemapUrls->count();

        return [
            Stat::make('URLs in Sitemap', $total)
                ->description('Total URLs submitted for indexing')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('info'),

            Stat::make('URLs with GSC Data', $inSitemapAndGsc)
                ->description('Indexed + seen in search results')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('In Sitemap, Never Seen in GSC', $inSitemapNotInGsc)
                ->description('Not yet indexed or zero impressions')
                ->descriptionIcon('heroicon-m-eye-slash')
                ->color($inSitemapNotInGsc > 0 ? 'warning' : 'success'),

            Stat::make('Indexed but Not in Sitemap', $indexedNotInSitemap)
                ->description('GSC data for URLs not in your sitemap')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color($indexedNotInSitemap > 0 ? 'warning' : 'gray'),
        ];
    }

    /**
     * Build the set of canonical sitemap URLs for the current tenant.
     *
     * Mirrors SitemapController::buildSitemapXml() — see F7 follow-up note.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function buildSitemapUrlSet(): \Illuminate\Support\Collection
    {
        $base       = request()->getSchemeAndHttpHost();
        $categories = Category::select(['id', 'slug'])->get();
        $products   = Product::where('is_ignored', false)
            ->whereNull('status')
            ->select(['slug'])
            ->get();

        $leafCategoryIds = Category::doesntHave('children')->pluck('id');
        $categorySlugMap = $categories->pluck('slug', 'id');

        $presets = Preset::whereIn('category_id', $leafCategoryIds)
            ->select(['category_id', 'name'])
            ->get();

        $urls = collect([$base . '/']);

        foreach ($categories as $cat) {
            $urls->push("{$base}/compare/{$cat->slug}");
        }

        foreach ($products as $product) {
            $urls->push("{$base}/product/{$product->slug}");
        }

        foreach ($presets as $preset) {
            $catSlug = $categorySlugMap[$preset->category_id] ?? null;
            if ($catSlug !== null) {
                $presetSlug = Str::slug($preset->name);
                $urls->push("{$base}/compare/{$catSlug}?preset={$presetSlug}");
            }
        }

        foreach (['about', 'contact', 'privacy-policy', 'terms-of-service'] as $page) {
            $urls->push("{$base}/{$page}");
        }

        return $urls->unique()->values();
    }
}
