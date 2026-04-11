<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Preset;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    public function index()
    {
        if (!tenancy()->initialized) {
            abort(404);
        }

        $xml = Cache::remember(
            tenant_cache_key('sitemap:xml'),
            600,
            fn () => $this->buildSitemapXml(),
        );

        return response($xml, 200, [
            'Content-Type'  => 'text/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=600',
        ]);
    }

    /**
     * Build the sitemap XML string.
     *
     * Uses get() instead of cursor() so the result can be passed into a cached
     * closure — LazyCollections from cursor() cannot be serialized or re-iterated
     * after the closure returns.
     *
     * @return string  Rendered XML
     */
    private function buildSitemapXml(): string
    {
        $categories = Category::select(['id', 'slug', 'updated_at'])->get();
        $products   = Product::where('is_ignored', false)
                        ->whereNull('status')
                        ->select(['slug', 'updated_at'])
                        ->get();

        // Preset pages: each preset generates a distinct ?preset= URL worth indexing.
        // Only include leaf categories (no children) — parent hub pages have no sliders.
        $leafCategoryIds = Category::doesntHave('children')->pluck('id');
        $categorySlugMap = $categories->pluck('slug', 'id');

        $presets = Preset::whereIn('category_id', $leafCategoryIds)
                        ->select(['category_id', 'name', 'updated_at'])
                        ->get()
                        ->map(fn ($p) => [
                            'category_slug' => $categorySlugMap[$p->category_id] ?? null,
                            'preset_slug'   => Str::slug($p->name),
                            'updated_at'    => $p->updated_at,
                        ])
                        ->filter(fn ($p) => $p['category_slug'] !== null);

        return view('sitemap', compact('categories', 'products', 'presets'))->render();
    }
}
