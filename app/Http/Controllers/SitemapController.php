<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;

class SitemapController extends Controller
{
    public function index()
    {
        $categories = Category::select(['slug', 'updated_at'])->get();
        $products   = Product::where('is_ignored', false)->select(['slug', 'updated_at'])->get();

        return response()
            ->view('sitemap', compact('categories', 'products'))
            ->header('Content-Type', 'text/xml');
    }
}