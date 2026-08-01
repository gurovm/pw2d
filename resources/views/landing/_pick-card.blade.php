{{--
    _pick-card.blade.php

    Renders one ranked "Best X" pick.

    Expected variables (passed via @include from landing/show.blade.php):
    - $pick        array  ['role', 'headline', 'body', 'product']
    - $rank        int    1-based rank position
    - $roleLabel   string display label, e.g. "Best Overall", "Best for Budget Picks"
    - $isPreset    bool   whether this pick's role is a preset:{slug} role
    - $presetSlug  string|null  slug portion of a preset role
    - $isFirst     bool   true for the #1 pick — controls LCP image priority
    - $category    Category
--}}
@php
    /** @var \App\Models\Product $product */
    $product = $pick['product'];

    $roleBadgeClasses = match (true) {
        $pick['role'] === 'overall' => 'bg-tenant-primary text-white',
        $pick['role'] === 'budget' => 'bg-emerald-600 text-white',
        $pick['role'] === 'premium' => 'bg-purple-600 text-white',
        $isPreset => 'bg-sky-600 text-white',
        default => 'bg-gray-700 text-white',
    };

    // LandingPageController's render path doesn't run picks through
    // ProductScoringService (no weight context at render — the whole category's
    // products would be needed for range normalization), so there's no normalized
    // 0-100 `feature_scores` to show. List the raw feature values instead — real
    // data, honestly labeled, no fake/never-populated score branch.
    $highlights = collect($product->featureValues ?? [])
        ->filter(fn ($fv) => $fv->feature)
        ->map(fn ($fv) => (object) [
            'name' => $fv->feature->name,
            'raw_value' => $fv->raw_value,
            'unit' => $fv->feature->unit ?? null,
        ]);

    $topHighlights = $highlights->take(3);

    $comparePresetUrl = route('category.show', ['slug' => $category->slug]) . ($isPreset ? '?preset=' . $presetSlug : '');
@endphp

<article
    class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-[0_12px_40px_rgba(255,153,0,0.15)] transition-shadow duration-300"
    aria-labelledby="pick-{{ $rank }}-name"
>
    <div class="flex flex-col md:flex-row">
        {{-- Rank + image --}}
        <div class="relative shrink-0 md:w-64">
            <div class="absolute top-3 left-3 z-10 flex items-center justify-center w-9 h-9 rounded-full bg-white/95 border-2 border-tenant-primary text-tenant-primary font-black text-sm shadow-sm">
                #{{ $rank }}
            </div>
            <div class="absolute top-3 right-3 z-10">
                <span class="inline-block px-2.5 py-1 rounded-full text-[10px] md:text-xs font-black uppercase tracking-wide shadow-sm {{ $roleBadgeClasses }}">
                    {{ $roleLabel }}
                </span>
            </div>

            <a href="{{ route('product.show', ['product' => $product->slug]) }}"
               class="flex items-center justify-center h-56 md:h-full w-full bg-white overflow-hidden">
                @if ($product->image_url)
                    <img
                        src="{{ $product->image_url }}"
                        alt="{{ $product->name }}"
                        width="400"
                        height="400"
                        class="h-full w-auto object-contain mix-blend-multiply"
                        @if ($isFirst)
                            loading="eager"
                            fetchpriority="high"
                        @else
                            loading="lazy"
                        @endif
                    >
                @else
                    <svg class="w-14 h-14 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                @endif
            </a>
        </div>

        {{-- Content --}}
        <div class="flex-1 p-4 md:p-6 flex flex-col">
            <div class="flex flex-wrap items-start justify-between gap-2 mb-1">
                <div>
                    <p class="text-[10px] md:text-xs font-bold text-tenant-primary uppercase tracking-wider">
                        {{ $product->brand?->name }}
                    </p>
                    <h2 id="pick-{{ $rank }}-name" class="text-lg md:text-xl font-bold text-gray-900 leading-tight">
                        {{ $product->name }}
                    </h2>
                </div>

                @if ($product->estimated_price !== null)
                    <div class="text-right shrink-0">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 leading-none mb-0.5">Est. Price</p>
                        <p class="text-xl md:text-2xl font-black text-gray-900 leading-none">~${{ number_format($product->estimated_price) }}</p>
                    </div>
                @endif
            </div>

            @if (!empty($pick['headline']))
                <p class="text-sm md:text-base font-semibold text-gray-800 mt-2 mb-1">{{ $pick['headline'] }}</p>
            @endif

            @if (!empty($pick['body']))
                <div class="prose prose-sm max-w-none text-gray-600 leading-relaxed mt-1">
                    {!! sanitize_ai_html($pick['body']) !!}
                </div>
            @endif

            @if ($topHighlights->isNotEmpty())
                <ul class="flex flex-wrap gap-2 mt-3" aria-label="Feature highlights">
                    @foreach ($topHighlights as $highlight)
                        <li class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-tenant-secondary text-[11px] md:text-xs font-semibold text-tenant-text border border-gray-100">
                            <svg class="w-3 h-3 text-tenant-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>{{ $highlight->name }}</span>
                            @if ($highlight->raw_value !== null)
                                <span class="text-gray-500 font-bold">{{ rtrim(rtrim(number_format($highlight->raw_value, 1), '0'), '.') }}{{ $highlight->unit ? ' ' . $highlight->unit : '' }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-4 pt-4 border-t border-gray-100 flex flex-wrap items-center gap-3">
                @if ($product->affiliate_url)
                    <a href="{{ $product->affiliate_url }}" target="_blank" rel="noopener noreferrer"
                       aria-label="Check current price for {{ $product->name }}"
                       class="amazon-cta inline-block text-center bg-[#FF9900] text-gray-900 py-2.5 px-5 rounded-lg font-bold text-xs md:text-sm transition-all duration-200 hover:bg-[#E68A00]">
                        Check Current Price &rarr;
                    </a>
                @endif

                <a href="{{ route('product.show', ['product' => $product->slug]) }}"
                   class="text-xs md:text-sm font-semibold text-gray-600 hover:text-tenant-primary transition-colors">
                    Full product details
                </a>

                <a href="{{ $comparePresetUrl }}"
                   class="text-xs md:text-sm font-semibold text-gray-600 hover:text-tenant-primary transition-colors">
                    See how it compares &rarr;
                </a>
            </div>
        </div>
    </div>
</article>
