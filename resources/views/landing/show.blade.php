{{--
    landing/show.blade.php

    "Best X" listicle landing page (Spec 027). Plain Blade, server-rendered,
    no Livewire — this page is meant to be the fastest, most linkable page
    type on the site.

    View-model contract (provided by LandingPageController):
    - $page:     LandingPage  ->title, ->intro, ->faqs, ->methodology_note, ->generated_at
    - $category: Category     ->parent, ->name, ->slug
    - $picks:    iterable of ['role', 'headline', 'body', 'product']
    - $seo:      array        title/description/canonical/ogType/ogImage/schemas
--}}
@php
    // Preset display names are looked up once (not per-pick) to render
    // "Best for {Preset}" role labels from role strings shaped `preset:{slug}`.
    $presetNameBySlug = $category->presets
        ->mapWithKeys(fn ($preset) => [\Illuminate\Support\Str::slug($preset->name) => $preset->name]);
@endphp

<x-layouts.app
    :metaTitle="$seo['title'] ?? null"
    :metaDescription="$seo['description'] ?? null"
    :canonicalUrl="$seo['canonical'] ?? null"
    :ogType="$seo['ogType'] ?? 'article'"
    :ogImage="$seo['ogImage'] ?? null"
    :schemasJson="collect($seo['schemas'] ?? [])->map(fn ($schema) => json_encode($schema))->all()"
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
                    <li class="text-gray-800 font-medium truncate max-w-[50vw]" aria-current="page">Best {{ $category->name }}</li>
                </ol>
            </nav>

            {{-- Hero --}}
            <header class="mb-10">
                @if ($page->generated_at)
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-tenant-secondary text-tenant-text text-[11px] md:text-xs font-bold uppercase tracking-wide mb-4">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Updated {{ $page->generated_at->format('F Y') }}
                    </span>
                @endif

                <h1 class="text-2xl sm:text-3xl md:text-4xl font-black tracking-tight text-gray-900 leading-tight mb-4">
                    {{ $page->title }}
                </h1>

                @if (!empty($page->intro))
                    <div class="prose prose-sm md:prose-base max-w-none text-gray-600 leading-relaxed">
                        {!! sanitize_ai_html($page->intro) !!}
                    </div>
                @endif
            </header>

            {{-- Ranked picks --}}
            @if (!empty($picks))
                <ol class="space-y-6 md:space-y-8" aria-label="Ranked picks">
                    @foreach ($picks as $pick)
                        @continue(empty($pick['product']))
                        @php
                            $rank = $loop->iteration;
                            $roleKey = $pick['role'] ?? 'overall';
                            $isPreset = str_starts_with($roleKey, 'preset:');
                            $presetSlug = $isPreset ? substr($roleKey, 7) : null;

                            $roleLabel = match (true) {
                                $roleKey === 'overall' => 'Best Overall',
                                $roleKey === 'budget' => 'Best Budget',
                                $roleKey === 'premium' => 'Best Premium',
                                $isPreset => 'Best for ' . ($presetNameBySlug[$presetSlug] ?? \Illuminate\Support\Str::headline(str_replace('-', ' ', $presetSlug))),
                                default => \Illuminate\Support\Str::headline($roleKey),
                            };
                        @endphp
                        <li>
                            @include('landing._pick-card', [
                                'pick' => $pick,
                                'rank' => $rank,
                                'roleLabel' => $roleLabel,
                                'isPreset' => $isPreset,
                                'presetSlug' => $presetSlug,
                                'isFirst' => $rank === 1,
                                'category' => $category,
                            ])
                        </li>
                    @endforeach
                </ol>
            @endif

            {{-- How we ranked this --}}
            @include('landing._methodology', ['page' => $page, 'category' => $category])

            {{-- FAQs --}}
            @include('landing._faqs', ['page' => $page])

            {{-- Footer CTA banner --}}
            <section class="mt-12 rounded-2xl bg-gradient-to-br from-tenant-primary/10 to-tenant-secondary p-6 md:p-8 text-center border border-gray-100">
                <h2 class="text-lg md:text-xl font-bold text-gray-900 mb-2">Want a pick tailored to you?</h2>
                <p class="text-sm md:text-base text-gray-600 mb-5 max-w-xl mx-auto">
                    Slide the priorities that matter to you &mdash; price, performance, whatever &mdash; and our
                    comparison tool re-ranks every {{ $category->name }} product in real time.
                </p>
                <a href="{{ route('category.show', ['slug' => $category->slug]) }}"
                   class="inline-flex items-center gap-2 bg-tenant-primary text-white font-bold text-sm md:text-base px-6 py-3 rounded-lg shadow-sm hover:opacity-90 transition-opacity">
                    Compare All {{ $category->name }}
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                    </svg>
                </a>
            </section>
        </div>
    </div>
</x-layouts.app>
