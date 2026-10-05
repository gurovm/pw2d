<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildVsComparison;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\VsPage;
use App\Support\SeoSchema;
use Illuminate\Support\Facades\Cache;

class VsPageController extends Controller
{
    /**
     * Render a published head-to-head page (Spec 043).
     *
     * Thin: resolve the published page or 404, then serve a 1h tenant-scoped cached
     * view-model. A page whose product was deleted, hidden, is pending or lost its
     * category 404s immediately (the liveness check runs outside the cache).
     */
    public function show(string $slug)
    {
        if (!tenancy()->initialized) {
            abort(404);
        }

        $page = VsPage::where('tenant_id', tenant('id'))
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();

        if (!$page) {
            abort(404);
        }

        $liveCount = Product::whereIn('id', [$page->product_a_id, $page->product_b_id])
            ->where('is_ignored', false)
            ->whereNull('status')
            ->whereNotNull('category_id')
            ->count();

        if ($liveCount !== 2) {
            abort(404);
        }

        $viewModel = Cache::remember(
            $page->cacheKey(),
            3600,
            fn () => $this->buildViewModel($page),
        );

        return view('vs.show', $viewModel);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewModel(VsPage $page): array
    {
        $category = $page->category()->with(['parent', 'features'])->first();

        $with = ['brand', 'offers.store', 'featureValues', 'category.features'];

        $a = Product::with($with)->findOrFail($page->product_a_id);
        $b = Product::with($with)->findOrFail($page->product_b_id);

        $comparison = (new BuildVsComparison())->handle($a, $b);

        return [
            'page'       => $page,
            'category'   => $category,
            'a'          => $a,
            'b'          => $b,
            'comparison' => $comparison,
            'bestPage'   => LandingPage::where('category_id', $category->id)->where('status', 'published')->first(),
            'seo'        => SeoSchema::forVsPage($page, $category, $a->name, $b->name),
        ];
    }
}
