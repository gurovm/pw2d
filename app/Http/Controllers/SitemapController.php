<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Preset;
use App\Models\Product;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    public function index()
    {
        if (!tenancy()->initialized) {
            abort(404);
        }

        $categories = Category::select(['id', 'slug', 'updated_at'])->get();
        $products   = Product::where('is_ignored', false)
                        ->whereNull('status')
                        ->select(['slug', 'updated_at'])
                        ->cursor();

        // Preset pages: each preset generates a distinct ?preset= URL worth indexing.
        // Only include leaf categories (no children) — parent hub pages have no sliders.
        $leafCategoryIds = Category::doesntHave('children')->pluck('id');
        $categorySlugMap = $categories->pluck('slug', 'id');

        $presets = Preset::whereIn('category_id', $leafCategoryIds)
                        ->select(['category_id', 'name', 'updated_at'])
                        ->get()
                        ->map(fn($p) => [
                            'category_slug' => $categorySlugMap[$p->category_id] ?? null,
                            'preset_slug'   => Str::slug($p->name),
                            'updated_at'    => $p->updated_at,
                        ])
                        ->filter(fn($p) => $p['category_slug'] !== null);

        return response()
            ->view('sitemap', compact('categories', 'products', 'presets'))
            ->header('Content-Type', 'text/xml');
    }
}