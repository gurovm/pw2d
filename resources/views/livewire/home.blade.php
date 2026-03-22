<div>
    <section class="hero">
        <div class="hero-glow"></div>
        <div class="hero-dots"></div>

        <div class="hero-eyebrow">✦ The Smart Way to Shop</div>

        <h1>{!! tenant('hero_headline') ?? 'Compare with <span>Intelligence</span>' !!}</h1>

        <p class="hero-sub">
            {{ tenant('hero_subheadline') ?? 'Stop digging through endless spec sheets. Tell our AI what you\'re doing, and we\'ll instantly find the right category and rank items for you.' }}
        </p>

        <livewire:global-search variant="hero" :sample-prompts="$samplePrompts" />

        @if(!empty($searchHints))
            <div class="search-hints hidden sm:flex">
                @foreach($searchHints as $hint)
                    <button type="button" class="hint-chip" wire:click="setQueryAndSearch('{{ addslashes($hint) }}')"><b>→</b> {{ $hint }}</button>
                @endforeach
            </div>
        @endif
    </section>

    <section class="how-section">
        <div class="section-label">How it Works</div>
        <h2 class="section-title">Beyond simple lists</h2>
        <div class="steps-grid">
            <div class="step-card">
                <div class="step-icon-strip">💬</div>
                <div class="step-body">
                    <div class="step-header">
                        <div class="step-num">1</div>
                        <h3>Natural Input</h3>
                    </div>
                    <p>Describe your needs in plain English. Our AI analyzes your use case, budget, and style to
                        identify the perfect category.</p>
                </div>
            </div>
            <div class="step-card">
                <div class="step-icon-strip">🎛️</div>
                <div class="step-body">
                    <div class="step-header">
                        <div class="step-num">2</div>
                        <h3>Priority Sliders</h3>
                    </div>
                    <p>Every user is different. Drag priorities on our custom sliders to tell us what matters: price,
                        quality, or specific features.</p>
                </div>
            </div>
            <div class="step-card">
                <div class="step-icon-strip">🎯</div>
                <div class="step-body">
                    <div class="step-header">
                        <div class="step-num">3</div>
                        <h3>Dynamic Ranking</h3>
                    </div>
                    <p>Watch the list update instantly. We recalculate every product's match score based on YOUR
                        specific needs.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="categories-section">
        <div class="section-label">Popular Categories</div>
        <h2 class="section-title">Ready to Compare</h2>

        @if($popularCategories->count() > 0)
            <div class="categories-grid">
                @foreach($popularCategories as $category)
                    <a href="{{ route('category.show', $category->slug) }}" wire:navigate class="cat-card">
                        <div class="cat-img">
                            @if($category->image)
                                <img src="{{ Storage::url($category->image) }}" alt="{{ $category->name }}">
                            @else
                                <div class="w-full h-full" style="background: linear-gradient(135deg, var(--color-primary), var(--color-text));"></div>
                            @endif
                        </div>
                        <div class="cat-body">
                            <h3>{{ $category->name }}</h3>
                            <p class="text-slate-500 text-sm mb-4 line-clamp-2">{{ $category->description ?? 'Compare top products in ' . strtolower($category->name) . ' based on your personal priorities.' }}</p>
                            <div class="cat-meta mt-auto">
                                <span class="cat-count">Top {{ $category->products_count }} Picks</span>
                                <div class="cat-arrow">→</div>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="text-center py-12">
                 <svg class="w-16 h-16 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                 </svg>
                 <h3 class="text-lg font-medium text-gray-900 mb-2">No Categories Yet</h3>
                 <p class="text-gray-600">Categories will appear here once they're added via the admin panel.</p>
            </div>
        @endif
    </section>

    <!-- PostHog Tracking -->
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('ai_search_submitted', (event) => {
                let data = Array.isArray(event) ? event[0] : event;
                if (typeof posthog !== 'undefined') {
                    posthog.capture('ai_search_submitted', { location: data.location, query: data.query });
                }
            });
        });
    </script>
</div>
