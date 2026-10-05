{{--
    vs/show.blade.php

    Head-to-head "A vs B" page (Spec 043). Plain Blade, server-rendered.
    SEO policy (Spec 042): breadcrumb schema only; no Offer, aggregateRating or Product markup.

    View-model (VsPageController): $page, $category, $a, $b (Product), $comparison
    (BuildVsComparison output), $bestPage (published LandingPage|null), $seo.
--}}
@php
    $sections = $page->sections ?? [];
    $faqPage  = (object) ['faqs' => collect($page->faqs ?? [])
        ->map(fn ($faq) => ['question' => $faq['q'] ?? '', 'answer' => $faq['a'] ?? ''])
        ->all()];
    $brandName = tenant('brand_name') ?: 'Pw2D';
@endphp

<x-layouts.app
    :metaTitle="$seo['title'] ?? null"
    :metaDescription="$seo['description'] ?? null"
    :canonicalUrl="$seo['canonical'] ?? null"
    :ogType="$seo['ogType'] ?? 'article'"
    :ogImage="$seo['ogImage'] ?? null"
    :schemasJson="\App\Support\SeoSchema::encodeSchemasForScriptTag($seo['schemas'] ?? [])"
>
    <div class="bg-gradient-to-br from-gray-50 to-white min-h-screen">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 md:py-12">

            {{-- Breadcrumb --}}
            <nav aria-label="Breadcrumb" class="mb-6">
                <ol class="flex flex-wrap items-center gap-1.5 text-xs md:text-sm text-gray-500">
                    <li><a href="{{ route('home') }}" class="hover:text-tenant-primary transition-colors">Home</a></li>
                    <li aria-hidden="true">/</li>
                    @if ($category->parent)
                        <li><a href="{{ route('category.show', ['slug' => $category->parent->slug]) }}" class="hover:text-tenant-primary transition-colors">{{ $category->parent->name }}</a></li>
                        <li aria-hidden="true">/</li>
                    @endif
                    <li><a href="{{ route('category.show', ['slug' => $category->slug]) }}" class="hover:text-tenant-primary transition-colors">{{ $category->name }}</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-gray-800 font-medium truncate max-w-[50vw]" aria-current="page">{{ $a->name }} vs {{ $b->name }}</li>
                </ol>
            </nav>

            <header class="mb-8">
                @if ($page->generated_at)
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-tenant-secondary text-tenant-text text-[11px] md:text-xs font-bold uppercase tracking-wide mb-4">
                        Updated {{ $page->generated_at->format('F Y') }}
                    </span>
                @endif

                <h1 class="text-2xl sm:text-3xl md:text-4xl font-black tracking-tight text-gray-900 leading-tight mb-4">
                    {{ $a->name }} vs {{ $b->name }}
                </h1>

                @if (!empty($page->intro))
                    <div class="prose prose-sm md:prose-base max-w-none text-gray-600 leading-relaxed">
                        {!! sanitize_ai_html($page->intro) !!}
                    </div>
                @endif
            </header>

            {{-- Two product cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-6 mb-10">
                @foreach ([$a, $b] as $product)
                    <article class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col" aria-labelledby="vs-card-{{ $loop->index }}">
                        <a href="{{ route('product.show', ['product' => $product->slug]) }}"
                           class="flex items-center justify-center h-48 w-full bg-white overflow-hidden">
                            @if ($product->image_url)
                                <img src="{{ $product->image_url }}" alt="{{ $product->name }}" width="400" height="400"
                                     class="max-h-full max-w-full object-contain mix-blend-multiply"
                                     @if ($loop->first) loading="eager" fetchpriority="high" @else loading="lazy" @endif>
                            @endif
                        </a>
                        <div class="p-4 flex-1 flex flex-col">
                            <p class="text-[10px] md:text-xs font-bold text-tenant-primary uppercase tracking-wider">{{ $product->brand?->name }}</p>
                            <h2 id="vs-card-{{ $loop->index }}" class="text-lg font-bold text-gray-900 leading-tight mb-3">
                                <a href="{{ route('product.show', ['product' => $product->slug]) }}" class="hover:text-tenant-primary transition-colors">{{ $product->name }}</a>
                            </h2>

                            <dl class="flex items-end justify-between gap-3 mb-4">
                                @php $score = $product->editorialScore(); @endphp
                                @if ($score !== null)
                                    <div>
                                        <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">{{ $brandName }} score</dt>
                                        <dd class="text-xl font-black text-gray-900">{{ number_format($score, 1) }} / 10</dd>
                                    </div>
                                @endif
                                @if ($product->estimated_price !== null)
                                    <div class="text-right">
                                        <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Est. price</dt>
                                        <dd class="text-xl font-black text-gray-900">~${{ number_format($product->estimated_price) }}</dd>
                                    </div>
                                @endif
                            </dl>

                            @if ($product->affiliate_url)
                                <a href="{{ $product->affiliate_url }}" target="_blank" rel="noopener noreferrer"
                                   aria-label="Check current price for {{ $product->name }}"
                                   class="amazon-cta mt-auto inline-block text-center bg-[#FF9900] text-gray-900 py-2.5 px-5 rounded-lg font-bold text-xs md:text-sm transition-all duration-200 hover:bg-[#E68A00]">
                                    Check Current Price &rarr;
                                </a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            {{-- Feature table --}}
            @if (!empty($comparison['features']))
                <section class="mb-10" aria-labelledby="vs-features-heading">
                    <h2 id="vs-features-heading" class="text-xl sm:text-2xl font-bold text-gray-900 mb-4">Feature scores</h2>
                    <div class="overflow-x-auto bg-white rounded-2xl border border-gray-100 shadow-sm">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-100">
                                    <th scope="col" class="px-4 py-3 font-bold">Feature</th>
                                    <th scope="col" class="px-4 py-3 font-bold text-center">{{ $a->name }}</th>
                                    <th scope="col" class="px-4 py-3 font-bold text-center">{{ $b->name }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach ($comparison['features'] as $row)
                                    <tr>
                                        <th scope="row" class="px-4 py-3 font-semibold text-gray-800 text-left">{{ $row['feature'] }}</th>
                                        <td class="px-4 py-3 text-center {{ $row['winner'] === 'a' ? 'font-black text-tenant-primary' : 'text-gray-600' }}">
                                            {{ $row['a'] }}@if ($row['winner'] === 'a') <span class="sr-only">(wins)</span><span aria-hidden="true"> &#10003;</span>@endif
                                        </td>
                                        <td class="px-4 py-3 text-center {{ $row['winner'] === 'b' ? 'font-black text-tenant-primary' : 'text-gray-600' }}">
                                            {{ $row['b'] }}@if ($row['winner'] === 'b') <span class="sr-only">(wins)</span><span aria-hidden="true"> &#10003;</span>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">A win is a gap of {{ \App\Actions\BuildVsComparison::WIN_MARGIN }} points or more.</p>
                </section>
            @endif

            {{-- Preset fit --}}
            @if (!empty($comparison['presets']))
                <section class="mb-10" aria-labelledby="vs-presets-heading">
                    <h2 id="vs-presets-heading" class="text-xl sm:text-2xl font-bold text-gray-900 mb-4">Which fits which buyer</h2>
                    <ul class="space-y-2">
                        @foreach ($comparison['presets'] as $row)
                            <li class="bg-white border border-gray-100 rounded-xl px-4 py-3 text-sm text-gray-800">
                                Better for {{ $row['preset'] }}:
                                <span class="font-bold">{{ match ($row['winner']) { 'a' => $a->name, 'b' => $b->name, default => 'Tie' } }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Prose --}}
            @foreach ([
                'a_wins'         => 'Where ' . $a->name . ' wins',
                'b_wins'         => 'Where ' . $b->name . ' wins',
                'who_should_buy' => 'Who should buy which',
            ] as $key => $heading)
                @if (!empty($sections[$key]))
                    <section class="mb-8">
                        <h2 class="text-xl sm:text-2xl font-bold text-gray-900 mb-3">{{ $heading }}</h2>
                        <div class="prose prose-sm md:prose-base max-w-none text-gray-700 leading-relaxed">
                            {!! sanitize_ai_html($sections[$key]) !!}
                        </div>
                    </section>
                @endif
            @endforeach

            {{-- Verdict --}}
            @if (!empty($page->verdict))
                <section class="mb-10 rounded-2xl bg-gradient-to-br from-tenant-primary/10 to-tenant-secondary p-6 border border-gray-100">
                    <h2 class="text-xl sm:text-2xl font-bold text-gray-900 mb-3">Verdict</h2>
                    <div class="prose prose-sm md:prose-base max-w-none text-gray-800 leading-relaxed">
                        {!! sanitize_ai_html($page->verdict) !!}
                    </div>
                </section>
            @endif

            {{-- FAQs (landing partial expects ->faqs of question/answer) --}}
            @include('landing._faqs', ['page' => $faqPage])

            {{-- Related links --}}
            <nav aria-label="Related pages" class="mt-10 flex flex-wrap gap-x-6 gap-y-2 text-sm font-semibold text-gray-700">
                <a href="{{ route('product.show', ['product' => $a->slug]) }}" class="hover:text-tenant-primary transition-colors">About the {{ $a->name }} &rarr;</a>
                <a href="{{ route('product.show', ['product' => $b->slug]) }}" class="hover:text-tenant-primary transition-colors">About the {{ $b->name }} &rarr;</a>
                @if ($bestPage)
                    <a href="{{ route('landing.show', ['slug' => $bestPage->slug]) }}" class="hover:text-tenant-primary transition-colors">Best {{ $category->name }} &rarr;</a>
                @endif
                <a href="{{ route('category.show', ['slug' => $category->slug]) }}" class="hover:text-tenant-primary transition-colors">Compare all {{ $category->name }} &rarr;</a>
            </nav>
        </div>
    </div>
</x-layouts.app>
