<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Product;
use App\Support\SeoSchema;
use Illuminate\Support\Facades\Cache;

class LandingPageController extends Controller
{
    /**
     * Render a published "Best X" landing page (Spec 027).
     *
     * Thin: resolve the published page or 404, then serve a 1h tenant-scoped
     * cached view-model. Deleted/detached/ignored picks are silently skipped —
     * all the actual selection/composition logic lives in buildViewModel().
     */
    public function show(string $slug)
    {
        if (!tenancy()->initialized) {
            abort(404);
        }

        $page = LandingPage::where('tenant_id', tenant('id'))
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();

        if (!$page) {
            abort(404);
        }

        // $page->cacheKey() is deterministic (derived from the row's own tenant_id,
        // not ambient tenancy — Spec 027 review S1), so this already builds/forgets
        // the same key regardless of what invalidated it.
        $viewModel = Cache::remember(
            $page->cacheKey(),
            3600,
            fn () => $this->buildViewModel($page),
        );

        return view('landing.show', $viewModel);
    }

    /**
     * @return array{page: LandingPage, category: \App\Models\Category, picks: \Illuminate\Support\Collection, seo: array}
     */
    private function buildViewModel(LandingPage $page): array
    {
        // 'presets' eager-loaded for the view's role-label lookup (preset:{slug} → preset name).
        $category = $page->category()->with(['parent', 'presets'])->first();

        $productIds = collect($page->picks)->pluck('product_id')->filter()->values();

        // Eligibility mirrors the render-time skip rule (Spec 027 §4): deleted rows
        // simply won't be in this result set; detached/ignored/pending are explicitly
        // excluded so a stale pick never resurrects a product the AI bouncer rejected.
        $products = Product::whereIn('id', $productIds)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->whereNotNull('category_id')
            ->with(['brand', 'offers.store', 'featureValues.feature'])
            ->get()
            ->keyBy('id');

        $picks = collect($page->picks)
            ->filter(fn (array $pick) => isset($pick['product_id']) && $products->has($pick['product_id']))
            ->map(fn (array $pick) => [
                'role'     => $pick['role'] ?? 'overall',
                'headline' => $pick['headline'] ?? '',
                'body'     => $pick['body'] ?? '',
                'product'  => $products[$pick['product_id']],
            ])
            ->values();

        // S2: the H1/<title>/ItemList name must reflect the FILTERED render-time
        // pick count, not the stored picks JSON count — set BEFORE building SEO so
        // the accessor and the schema builder (same $page instance, see S4 below)
        // agree with what actually renders.
        $page->renderedPickCount = $picks->count();

        // S4: forLandingPage() reads $page->category — set the relation to the
        // already-loaded instance so it doesn't lazy-load category+parent again
        // and builds the schema from the exact same category the view receives.
        $page->setRelation('category', $category);

        $seo = SeoSchema::forLandingPage($page, $picks->pluck('product'));

        return [
            'page'     => $page,
            'category' => $category,
            'picks'    => $picks,
            'seo'      => $seo,
        ];
    }
}
