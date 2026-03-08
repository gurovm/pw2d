<div>
    @if($subcategories->isNotEmpty())
        {{-- Parent category: show subcategory grid, no comparison UI --}}
        <div class="bg-gradient-to-br from-gray-50 to-white min-h-screen">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
                <div class="mb-8">
                    <div class="section-label">{{ $category->name }}</div>
                    <h1 class="section-title">Browse Categories</h1>
                    @if($category->description)
                        <p class="text-slate-500 mt-2 max-w-2xl">{{ $category->description }}</p>
                    @endif
                </div>
                <div class="categories-grid">
                    @foreach($subcategories as $sub)
                        <a href="{{ route('category.show', $sub->slug) }}" wire:navigate class="cat-card">
                            <div class="cat-img">
                                @if($sub->image)
                                    <img src="{{ Storage::url($sub->image) }}" alt="{{ $sub->name }}">
                                @else
                                    <div class="w-full h-full bg-gradient-to-br from-blue-600 to-blue-800"></div>
                                @endif
                            </div>
                            <div class="cat-body">
                                <h3>{{ $sub->name }}</h3>
                                <p class="text-slate-500 text-sm mb-4 line-clamp-2">{{ $sub->description ?? 'Compare top products in ' . strtolower($sub->name) . ' based on your personal priorities.' }}</p>
                                <div class="cat-meta mt-auto">
                                    <span class="cat-count">Top {{ $sub->products_count }} Picks</span>
                                    <div class="cat-arrow">→</div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        {{-- Leaf category: show full comparison UI --}}
        <div class="bg-gradient-to-br from-gray-50 to-white min-h-screen">
                <livewire:comparison-header :features="$features" :weights="$weights" :priceWeight="$priceWeight" :amazonRatingWeight="$amazonRatingWeight"
                        :categoryId="$category->id" :autoOpen="!$selectedProductSlug" />

                <div class="bg-white border-b border-gray-200">
                        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                                <div class="cat-header-grid">
                                        <div class="cat-header-left">
                                                @if ($category->image)
                                                        <img src="{{ asset('storage/' . $category->image) }}"
                                                                alt="{{ $category->name }}"
                                                                class="w-full h-full object-cover rounded-2xl">
                                                @else
                                                        <div
                                                                class="w-full h-full bg-gradient-to-br from-indigo-100 to-blue-50 rounded-2xl flex items-center justify-center">
                                                                <svg class="w-16 h-16 text-indigo-300" fill="none"
                                                                        stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round"
                                                                                stroke-linejoin="round"
                                                                                stroke-width="1.5"
                                                                                d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4">
                                                                        </path>
                                                                </svg>
                                                        </div>
                                                @endif
                                        </div>

                                        <div class="cat-header-right">
                                                <h1
                                                        class="text-2xl sm:text-3xl font-black text-gray-900 tracking-tight mb-3">
                                                        {{ $category->name }}</h1>

                                                @if ($category->buying_guide && is_array($category->buying_guide))
                                                        @php
                                                                $sections = [
                                                                    'how_to_decide' => [
                                                                        'title' => 'How to Decide',
                                                                        'icon' =>
                                                                            '<svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>',
                                                                        'activeColor' => 'text-yellow-500',
                                                                        'content' =>
                                                                            $category->buying_guide['how_to_decide'] ??
                                                                            '',
                                                                    ],
                                                                    'the_pitfalls' => [
                                                                        'title' => 'The Pitfalls',
                                                                        'icon' =>
                                                                            '<svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
                                                                        'activeColor' => 'text-rose-500',
                                                                        'content' =>
                                                                            $category->buying_guide['the_pitfalls'] ??
                                                                            '',
                                                                    ],
                                                                    'key_jargon' => [
                                                                        'title' => 'Key Jargon',
                                                                        'icon' =>
                                                                            '<svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>',
                                                                        'activeColor' => 'text-indigo-500',
                                                                        'content' =>
                                                                            $category->buying_guide['key_jargon'] ?? '',
                                                                    ],
                                                                ];

                                                                $defaultTab = 'how_to_decide';
                                                                if (empty($sections[$defaultTab]['content'])) {
                                                                    foreach ($sections as $k => $section) {
                                                                        if (!empty($section['content'])) {
                                                                            $defaultTab = $k;
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                        @endphp
                                                        <div x-data="{ expanded: false, activeTab: '{{ $defaultTab }}' }">
                                                                <div
                                                                        class="bg-gradient-to-br from-slate-50 via-white to-indigo-50/30 rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                                                                        <div
                                                                                class="flex items-center gap-2 px-4 pt-3 pb-1.5 border-b border-gray-100/80 bg-white/60">
                                                                                <div
                                                                                        class="flex space-x-1 overflow-x-auto scrollbar-hide w-full pb-1">
                                                                                        @foreach ($sections as $key => $data)
                                                                                                @if (!empty($data['content']))
                                                                                                        <button @click="activeTab = '{{ $key }}'; expanded = true"
                                                                                                                class="whitespace-nowrap flex items-center gap-1.5 px-3 py-1.5 text-[13px] sm:text-[14px] transition-all focus:outline-none shrink-0 rounded-lg hover:bg-gray-100/60 cursor-pointer"
                                                                                                                :class="activeTab === '{{ $key }}'
                                                                                                                    ?
                                                                                                                    'text-gray-900 font-bold bg-gray-50/50' :
                                                                                                                    'text-gray-500 font-semibold hover:text-gray-800'">
                                                                                                                <span
                                                                                                                        :class="activeTab === '{{ $key }}' ? '{{ $data['activeColor'] }}' : 'text-gray-400'">
                                                                                                                        {!! $data['icon'] !!}
                                                                                                                </span>
                                                                                                                {{ $data['title'] }}
                                                                                                        </button>
                                                                                                @endif
                                                                                        @endforeach
                                                                                </div>
                                                                        </div>

                                                                        <div class="relative overflow-hidden"
                                                                                :style="expanded ? 'max-height: 2000px' :
                                                                                    'max-height: 5.5rem'"
                                                                                style="max-height: 5.5rem; transition: max-height 0.5s ease-in-out;">

                                                                                @foreach ($sections as $key => $data)
                                                                                        @if (!empty($data['content']))
                                                                                                <div x-show="activeTab === '{{ $key }}'"
                                                                                                        x-transition:enter="transition ease-out duration-300 transform"
                                                                                                        x-transition:enter-start="opacity-0 translate-y-2"
                                                                                                        x-transition:enter-end="opacity-100 translate-y-0"
                                                                                                        style="display: none;"
                                                                                                        class="px-4 py-4 prose prose-sm max-w-none text-gray-600
                                                            [&_ul]:space-y-2 [&_ul]:my-3 [&_li]:text-[12px] sm:[&_li]:text-[13px] [&_li]:text-gray-600 [&_li]:leading-relaxed [&_li]:ml-4 [&_li]:list-disc [&_li]:pl-1
                                                            [&_p]:text-[12px] sm:[&_p]:text-[13px] [&_p]:leading-relaxed [&_p]:text-gray-600 [&_p]:mb-3 [&_p]:last:mb-0
                                                            [&_strong]:text-gray-900 [&_strong]:font-semibold">
                                                                                                        {!! $data['content'] !!}
                                                                                                </div>
                                                                                        @endif
                                                                                @endforeach
                                                                        </div>

                                                                        <div class="relative">
                                                                                <div x-show="!expanded"
                                                                                        class="absolute -top-10 left-0 right-0 h-10 bg-gradient-to-t from-white via-white/80 to-transparent pointer-events-none transition-opacity duration-300">
                                                                                </div>
                                                                                <div
                                                                                        class="px-4 pb-3 pt-1.5 border-t border-gray-100/50 flex justify-between items-center bg-white/50 backdrop-blur-sm">
                                                                                        <button @click="expanded = !expanded"
                                                                                                class="text-xs font-bold text-indigo-600 hover:text-indigo-800 transition-colors flex items-center gap-1.5 group select-none cursor-pointer">
                                                                                                <span
                                                                                                        x-text="expanded ? 'Show less' : 'Read full guide'"></span>
                                                                                                <svg class="w-3.5 h-3.5 transition-transform duration-300"
                                                                                                        :class="expanded ?
                                                                                                            'rotate-180' :
                                                                                                            'group-hover:translate-y-0.5'"
                                                                                                        fill="none"
                                                                                                        stroke="currentColor"
                                                                                                        viewBox="0 0 24 24">
                                                                                                        <path stroke-linecap="round"
                                                                                                                stroke-linejoin="round"
                                                                                                                stroke-width="2.5"
                                                                                                                d="M19 9l-7 7-7-7">
                                                                                                        </path>
                                                                                                </svg>
                                                                                        </button>
                                                                                </div>
                                                                        </div>
                                                                </div>
                                                        </div>

                                                        <style>
                                                                .scrollbar-hide::-webkit-scrollbar {
                                                                        display: none;
                                                                }

                                                                .scrollbar-hide {
                                                                        -ms-overflow-style: none;
                                                                        scrollbar-width: none;
                                                                }
                                                        </style>
                                                @endif
                                        </div>
                                </div>
                        </div>
                </div>



                <div class="max-w-7xl mx-auto px-2 sm:px-6 lg:px-8 py-4">

                        <div class="mb-6 flex flex-wrap items-center gap-3">

                                <div x-data="{
                                    open: false,
                                    search: '',
                                    selectedBrandId: @entangle('filterBrand').live,
                                    brands: {{ $this->availableBrands->map(function ($b) {return ['id' => $b->id, 'name' => $b->name, 'count' => $b->products_count];})->toJson() }},
                                    get filteredBrands() {
                                        if (this.search === '') return this.brands;
                                        return this.brands.filter(b => b.name.toLowerCase().includes(this.search.toLowerCase()));
                                    },
                                    get selectedBrandName() {
                                        if (!this.selectedBrandId) return 'All Brands';
                                        let b = this.brands.find(b => b.id == this.selectedBrandId);
                                        return b ? b.name : 'All Brands';
                                    }
                                }" @click.away="open = false" class="relative">

                                        <button @click="open = !open" type="button"
                                                class="flex items-center justify-between min-w-[140px] appearance-none bg-white border border-gray-200 text-gray-700 text-sm rounded-full px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer font-medium hover:bg-gray-50 transition-colors">
                                                <span x-text="selectedBrandName"></span>
                                                <svg class="h-4 w-4 ml-2 text-gray-400" fill="none"
                                                        stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                </svg>
                                        </button>

                                        <div x-show="open" x-transition.opacity.duration.200ms style="display: none;"
                                                class="absolute z-20 mt-2 w-56 rounded-xl bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none overflow-hidden">

                                                <div class="p-2 border-b border-gray-100 bg-gray-50/50">
                                                        <div class="relative">
                                                                <div
                                                                        class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none">
                                                                        <svg class="h-3.5 w-3.5 text-gray-400"
                                                                                fill="none" stroke="currentColor"
                                                                                viewBox="0 0 24 24">
                                                                                <path stroke-linecap="round"
                                                                                        stroke-linejoin="round"
                                                                                        stroke-width="2"
                                                                                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z">
                                                                                </path>
                                                                        </svg>
                                                                </div>
                                                                <input x-model="search" type="text"
                                                                        placeholder="Search brands..."
                                                                        class="w-full bg-white border border-gray-200 text-gray-800 text-xs rounded-lg pl-8 pr-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 transition-shadow">
                                                        </div>
                                                </div>

                                                <div
                                                        class="max-h-60 overflow-y-auto overscroll-contain pb-1 custom-scrollbar">
                                                        <button @click="selectedBrandId = ''; open = false; search = ''"
                                                                class="w-full text-left px-4 py-2.5 text-sm hover:bg-gray-50 flex items-center justify-between transition-colors"
                                                                :class="!selectedBrandId ?
                                                                    'bg-blue-50/50 text-blue-700 font-semibold' :
                                                                    'text-gray-700'">
                                                                <span>All Brands</span>
                                                                <span
                                                                        class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full font-medium">{{ $this->availableBrands->sum('products_count') }}</span>
                                                        </button>

                                                        <template x-for="brand in filteredBrands"
                                                                :key="brand.id">
                                                                <button @click="selectedBrandId = brand.id; open = false;"
                                                                        class="w-full text-left px-4 py-2.5 text-sm hover:bg-gray-50 flex items-center justify-between transition-colors border-t border-gray-50"
                                                                        :class="selectedBrandId == brand.id ?
                                                                            'bg-blue-50/50 text-blue-700 font-semibold' :
                                                                            'text-gray-700'">
                                                                        <span x-text="brand.name"></span>
                                                                        <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                                                                :class="selectedBrandId == brand.id ?
                                                                                    'bg-blue-100 text-blue-700' :
                                                                                    'bg-gray-100 text-gray-500'"
                                                                                x-text="brand.count"></span>
                                                                </button>
                                                        </template>

                                                        <div x-show="filteredBrands.length === 0"
                                                                class="px-4 py-4 text-center text-sm text-gray-500">
                                                                No brands found
                                                        </div>
                                                </div>
                                        </div>
                                </div>

                                <div class="relative">
                                        <select wire:model.live="filterPrice"
                                                class="appearance-none bg-white border border-gray-200 text-gray-700 text-sm rounded-full px-4 py-2 pr-8 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer font-medium hover:bg-gray-50 transition-colors">
                                                <option value="">Any Price</option>
                                                <option value="1">Budget ($)</option>
                                                <option value="2">Mid-Range ($$)</option>
                                                <option value="3">Premium ($$$)</option>
                                        </select>
                                        <div
                                                class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-400">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                </svg>
                                        </div>
                                </div>

                                @if ($filterBrand || $filterPrice)
                                        <button wire:click="clearFilters"
                                                class="text-sm font-medium text-gray-500 hover:text-gray-900 px-2 flex items-center gap-1 transition-colors group">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                                Clear filters
                                        </button>
                                @endif
                        </div>

                        @if ($this->scoredProducts->count() > 0 && $features->count() > 0)
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-1.5 md:gap-5" x-data
                                        x-init="import('https://cdn.jsdelivr.net/npm/@formkit/auto-animate').then(module => module.default($el))">
                    @foreach ($this->visibleProducts as $product)
                        <div wire:key="product-{{ $product->id }}" class="product-card bg-white rounded-2xl shadow-sm hover:shadow-[0_12px_40px_rgba(255,153,0,0.2)] transition-all duration-300 overflow-hidden flex flex-col h-full border hover:border-amber-400 group relative {{ $loop->first ? 'border-2 border-amber-400 shadow-[0_4px_20px_rgba(245,158,11,0.15)]' : 'border-gray-100' }}">
                            @if ($loop->first)
                                <div class="absolute top-2.5 left-2.5 z-10 bg-amber-500 text-white text-[10px] md:text-xs font-black px-2.5 py-1 rounded-full shadow-md tracking-wide">
                                    ⭐ Best Match
                                </div>
                            @endif
                            @if ($product->image_url)
                                <a href="/product/{{ $product->slug }}"
                                   wire:click.prevent="openProduct('{{ $product->slug }}')"
                                   @click="window.history.pushState({}, '', '/product/{{ $product->slug }}')"
                                   class="h-44 md:h-52 w-full flex justify-center items-center bg-white overflow-hidden group-hover:bg-gray-50/50 transition-colors block outline-none">
                                    <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="h-full w-auto object-contain p-4 mix-blend-multiply">
                                </a>
                            @else
                                <a href="/product/{{ $product->slug }}"
                                   wire:click.prevent="openProduct('{{ $product->slug }}')"
                                   @click="window.history.pushState({}, '', '/product/{{ $product->slug }}')"
                                   class="h-44 md:h-52 w-full flex justify-center items-center bg-gradient-to-br from-gray-50 to-gray-100 overflow-hidden block outline-none">
                                    <svg class="w-14 h-14 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </a>
                            @endif

                            <div class="p-2.5 md:p-4 flex-1 flex flex-col">
                                <div class="flex justify-between items-center mb-1.5">
                                    <p class="text-[10px] md:text-xs font-bold text-amber-700 uppercase tracking-wider">{{ $product->brand->name }}</p>
                                    @if ($product->amazon_rating)
                                        <div class="flex items-center gap-0.5 text-[10px] md:text-xs font-bold text-gray-600">
                                            <span class="text-amber-500">★</span> {{ number_format($product->amazon_rating, 1) }}
                                        </div>
                                    @endif
                                </div>

                                <a href="/product/{{ $product->slug }}"
                                   wire:click.prevent="openProduct('{{ $product->slug }}')"
                                   @click="window.history.pushState({}, '', '/product/{{ $product->slug }}')"
                                   class="block outline-none">
                                    <h3 class="text-[11px] md:text-sm font-semibold text-gray-900 mb-3 leading-tight line-clamp-2 min-h-[2rem] md:min-h-[2.5rem]">{{ $product->name }}</h3>
                                </a>

                                @php
                                    $matchScore = $product->match_score ?? 0;
                                    $scoreColor = $matchScore >= 85 ? 'bg-green-500' :
                                                 ($matchScore >= 70 ? 'bg-emerald-500' :
                                                 ($matchScore >= 50 ? 'bg-blue-500' : 'bg-gray-400'));
                                @endphp
                                <div class="mb-3">
                                    <div class="flex justify-between items-end mb-1">
                                        <span class="text-[10px] md:text-xs font-medium text-gray-400">Personal Match Score</span>
                                        <span class="text-base md:text-lg font-black {{ $matchScore >= 85 ? 'text-green-600' : 'text-gray-800' }}">{{ number_format($matchScore, 0) }}%</span>
                                    </div>
                                    <div class="h-1.5 w-full bg-gray-100 rounded-full overflow-hidden">
                                        <div class="h-full {{ $scoreColor }} transition-all duration-500 rounded-full" style="width: {{ $matchScore }}%"></div>
                                    </div>
                                </div>

                                <div class="mt-auto pt-2 border-t border-gray-100">
                                    @if ($product->price_tier)
                                        <div class="text-xs md:text-sm font-extrabold flex items-center gap-1">
                                            @if ($product->price_tier == 1)
                                                <span class="text-green-600">$<span class="opacity-20">$$</span></span>
                                                <span class="text-green-600">Budget</span>
                                            @elseif($product->price_tier == 2)
                                                <span class="text-blue-600">$$<span class="opacity-20">$</span></span>
                                                <span class="text-blue-600">Mid Range</span>
                                            @else
                                                <span class="text-amber-600">$$$</span>
                                                <span class="text-amber-600">Premium</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if ($product->affiliate_url)
                                <a href="{{ $product->affiliate_url }}" target="_blank" rel="noopener noreferrer"
                                   class="amazon-cta block w-full text-center bg-[#FF9900] text-white py-2.5 md:py-3 font-bold text-xs md:text-sm transition-all duration-200 hover:bg-[#E68A00]">
                                    View on Amazon →
                                </a>
                            @endif
                        </div> @endforeach
                        </div>

                        @if($this->scoredProducts->count() > $displayLimit)
                        <div class="mt-12 flex justify-center pb-8">
                                        <button wire:click="loadMore"
                                                class="px-8 py-3 bg-white border border-gray-200 rounded-full text-sm font-semibold text-gray-700 hover:bg-gray-50 hover:border-gray-300 hover:shadow-sm transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-gray-200">
                                                Show More Matches
                                        </button>
                                </div>
                        @endif
                @else
                        <div class="text-center py-16 bg-white rounded-xl shadow-sm border border-gray-100">
                                <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4">
                                        </path>
                                </svg>
                                <h3 class="text-lg font-medium text-gray-900 mb-1">No products found</h3>
                                <p class="text-gray-500">Try adjusting your filters or search criteria.</p>
                        </div>
                        @endif
                </div>
        </div>

        @if ($this->selectedProduct)
                <div class="fixed inset-0 z-50 flex items-center justify-center p-4 pt-20 sm:p-6 sm:pt-20"
                        x-data="{ show: false }" x-init="setTimeout(() => show = true, 50)" x-transition.opacity
                        @keyup.escape.window="window.history.pushState({}, '', '/compare/{{ $category->slug }}'); $wire.closeProduct()"
                        style="display: none;" x-show="show">

                        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-md transition-opacity"
                                @click="window.history.pushState({}, '', '/compare/{{ $category->slug }}'); $wire.closeProduct()">
                        </div>

                        <div class="relative w-full max-w-5xl bg-white rounded-2xl shadow-2xl overflow-y-auto md:overflow-hidden flex flex-col md:flex-row max-h-[85vh] z-10"
                                x-show="show" x-transition:enter="transition ease-out duration-300 transform"
                                x-transition:enter-start="opacity-0 translate-y-8 sm:translate-y-0 sm:scale-95"
                                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                                x-transition:leave="transition ease-in duration-200 transform"
                                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">

                                <button type="button"
                                        @click="window.history.pushState({}, '', '/compare/{{ $category->slug }}'); $wire.closeProduct()"
                                        class="absolute top-4 right-4 z-50 p-2.5 bg-white/80 hover:bg-white backdrop-blur-md rounded-full text-gray-500 hover:text-gray-900 transition-all focus:outline-none focus:ring-2 focus:ring-gray-300 shadow-sm border border-gray-100">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                </button>

                                <div
                                        class="w-full md:w-[40%] bg-gray-50 flex flex-col items-center justify-between p-4 md:p-8 relative h-auto md:sticky md:top-0 border-r border-gray-100 shrink-0">
                                        <div
                                                class="flex-1 flex items-center justify-center w-full min-h-[200px] md:min-h-[250px] mb-4 md:mb-8">
                                                @if ($this->selectedProduct->image_url)
                                                        <img src="{{ $this->selectedProduct->image_url }}"
                                                                alt="{{ $this->selectedProduct->name }}"
                                                                class="w-full max-h-[300px] object-contain mix-blend-multiply drop-shadow-xl hover:scale-105 transition-transform duration-700 ease-out">
                                                @else
                                                        <svg class="w-24 h-24 text-gray-300" fill="none"
                                                                stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                        stroke-width="1.5"
                                                                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z">
                                                                </path>
                                                        </svg>
                                                @endif
                                        </div>

                                        @if ($this->selectedProduct->affiliate_url)
                                                <div
                                                        class="w-full flex flex-col items-center gap-3 md:gap-4 bg-white p-4 md:p-6 rounded-2xl shadow-sm border border-gray-100">
                                                        <div class="flex flex-col items-center text-center">
                                                                <span
                                                                        class="text-[10px] md:text-xs text-gray-400 font-semibold uppercase tracking-wider mb-0.5 md:mb-1">Market
                                                                        Tier</span>
                                                                @if ($this->selectedProduct->price_tier)
                                                                        <div
                                                                                class="text-lg md:text-2xl font-bold tracking-tight text-gray-900">
                                                                                @if ($this->selectedProduct->price_tier == 1)
                                                                                        <span
                                                                                                class="text-emerald-500 mr-0.5 text-base md:text-lg">$</span>
                                                                                        Budget
                                                                                @elseif($this->selectedProduct->price_tier == 2)
                                                                                        <span
                                                                                                class="text-emerald-500 mr-0.5 text-base md:text-lg">$$</span>
                                                                                        Mid-Range
                                                                                @else
                                                                                        <span
                                                                                                class="text-emerald-500 mr-0.5 text-base md:text-lg">$$$</span>
                                                                                        Premium
                                                                                @endif
                                                                        </div>
                                                                @endif
                                                        </div>
                                                        <a href="{{ $this->selectedProduct->affiliate_url }}"
                                                                target="_blank" rel="noopener noreferrer"
                                                                class="w-full bg-gradient-to-r from-gray-900 to-black text-white shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300 rounded-xl py-2.5 px-4 md:py-3.5 md:px-6 text-sm md:text-base font-semibold flex items-center justify-center gap-2">
                                                                <span>View on Amazon</span>
                                                                <svg class="w-4 h-4" fill="none"
                                                                        stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round"
                                                                                stroke-linejoin="round"
                                                                                stroke-width="2.5"
                                                                                d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                                                                </svg>
                                                        </a>
                                                </div>
                                        @endif
                                </div>

                                <div
                                        class="w-full md:w-[60%] md:overflow-y-auto p-5 sm:p-6 md:p-10 custom-scrollbar flex flex-col relative bg-white">
                                        <style>
                                                .custom-scrollbar::-webkit-scrollbar {
                                                        width: 6px;
                                                }

                                                .custom-scrollbar::-webkit-scrollbar-track {
                                                        background: transparent;
                                                }

                                                .custom-scrollbar::-webkit-scrollbar-thumb {
                                                        background-color: #e5e7eb;
                                                        border-radius: 20px;
                                                }
                                        </style>

                                        <div class="flex items-center space-x-3 mb-3 md:mb-4">
                                                <span
                                                        class="px-3 py-1 bg-gray-100 text-gray-800 text-xs font-bold uppercase tracking-widest rounded-full">{{ $this->selectedProduct->brand->name }}</span>
                                                @if ($this->selectedProduct->amazon_rating)
                                                        <div
                                                                class="flex items-center text-sm font-semibold text-gray-700 shrink-0">
                                                                <svg class="w-4 h-4 text-yellow-500 mr-1"
                                                                        fill="currentColor" viewBox="0 0 20 20">
                                                                        <path
                                                                                d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z">
                                                                        </path>
                                                                </svg>
                                                                {{ number_format($this->selectedProduct->amazon_rating, 1) }}
                                                                <span
                                                                        class="text-gray-400 font-normal ml-1">({{ number_format($this->selectedProduct->amazon_reviews_count) }})</span>
                                                        </div>
                                                @endif
                                        </div>

                                        <h2
                                                class="text-2xl md:text-4xl font-extrabold text-gray-900 tracking-tight leading-tight mb-4 md:mb-6">
                                                {{ $this->selectedProduct->name }}</h2>

                                        @if ($this->selectedProduct->ai_summary)
                                                <div
                                                        class="bg-indigo-50 border-l-4 border-indigo-500 p-4 rounded-r-lg mb-6 shadow-sm">
                                                        <div class="flex items-center gap-2.5 mb-2">
                                                                <svg class="w-4 h-4 text-indigo-600" fill="none"
                                                                        stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round"
                                                                                stroke-linejoin="round"
                                                                                stroke-width="2"
                                                                                d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                                                </svg>
                                                                <h4
                                                                        class="text-xs font-bold text-indigo-900 uppercase tracking-widest">
                                                                        The Verdict</h4>
                                                        </div>
                                                        <p
                                                                class="text-indigo-900 text-sm leading-relaxed font-medium relative z-10 whitespace-pre-line">
                                                                {{ $this->selectedProduct->ai_summary }}</p>
                                                </div>
                                        @endif

                                        @php
                                                $modalProduct = $this->scoredProducts->firstWhere(
                                                    'id',
                                                    $this->selectedProduct->id,
                                                );
                                                $matchScore = $modalProduct ? $modalProduct->match_score : 0;
                                                $scoreColor =
                                                    $matchScore >= 85
                                                        ? 'text-emerald-500'
                                                        : ($matchScore >= 70
                                                            ? 'text-blue-500'
                                                            : ($matchScore >= 50
                                                                ? 'text-yellow-500'
                                                                : 'text-rose-500'));
                                        @endphp
                                        <div
                                                class="mb-8 md:mb-10 flex items-center gap-4 md:gap-6 p-4 md:p-6 bg-gray-50/50 rounded-2xl border border-gray-100">
                                                <div
                                                        class="relative w-16 h-16 md:w-24 md:h-24 flex items-center justify-center shrink-0">
                                                        <svg class="w-full h-full transform -rotate-90"
                                                                viewBox="0 0 36 36">
                                                                <path class="text-gray-200"
                                                                        d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                                                        fill="none" stroke="currentColor"
                                                                        stroke-width="3" stroke-linecap="round">
                                                                </path>
                                                                <path class="{{ $scoreColor }} transition-all duration-[1500ms] ease-out"
                                                                        x-data="{ score: 0 }"
                                                                        x-intersect="setTimeout(() => { score = {{ $matchScore }} }, 100)"
                                                                        :stroke-dasharray="score + ', 100'"
                                                                        d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                                                        fill="none" stroke="currentColor"
                                                                        stroke-width="3" stroke-linecap="round">
                                                                </path>
                                                        </svg>
                                                        <div
                                                                class="absolute inset-0 flex flex-col items-center justify-center">
                                                                <span
                                                                        class="text-2xl font-bold text-gray-900 tracking-tighter">{{ number_format($matchScore, 0) }}<span
                                                                                class="text-sm font-semibold">%</span></span>
                                                        </div>
                                                </div>
                                                <div class="flex-1">
                                                        <h4 class="text-sm font-bold text-gray-900 mb-1">Personalized
                                                                Match</h4>
                                                        <p class="text-sm text-gray-500 leading-relaxed">Based on your
                                                                precise slider configurations, this product has been
                                                                objectively rated at <strong
                                                                        class="text-gray-900">{{ number_format($matchScore, 1) }}%</strong>
                                                                compatibility for your exact needs.</p>
                                                </div>
                                        </div>

                                        <div class="mb-12">
                                                <h4
                                                        class="text-sm font-bold text-gray-900 border-b border-gray-100 pb-3 mb-6">
                                                        Technical Specifications</h4>
                                                <div class="space-y-5">
                                                        @foreach ($features as $feature)
                                                                @php
                                                                        $normalizedScore = $modalProduct
                                                                            ? $modalProduct->feature_scores[
                                                                                    $feature->id
                                                                                ] ?? null
                                                                            : null;
                                                                        if ($normalizedScore === null) {
                                                                            continue;
                                                                        }

                                                                        $featureValueObj = $this->selectedProduct->featureValues
                                                                            ->where('feature_id', $feature->id)
                                                                            ->first();
                                                                        $rawValue = $featureValueObj
                                                                            ? $featureValueObj->raw_value
                                                                            : '';
                                                                        $explanation = $featureValueObj
                                                                            ? $featureValueObj->explanation
                                                                            : null;

                                                                        $hexColor =
                                                                            $normalizedScore >= 80
                                                                                ? '#10b981'
                                                                                : ($normalizedScore >= 60
                                                                                    ? '#eab308'
                                                                                    : '#f43f5e');
                                                                @endphp

                                                                <div class="group">
                                                                        <div
                                                                                class="flex justify-between items-baseline mb-2">
                                                                                <span
                                                                                        class="text-sm font-semibold text-gray-700">{{ $feature->name }}</span>
                                                                                <span
                                                                                        class="text-sm font-bold text-gray-900">{{ $rawValue }}<span
                                                                                                class="text-gray-400 font-medium text-xs ml-0.5">{{ $feature->unit }}</span></span>
                                                                        </div>
                                                                        <div
                                                                                class="w-full bg-gray-100 h-2 rounded-full overflow-hidden">
                                                                                <div class="h-full rounded-full transition-all duration-700 ease-out shadow-sm"
                                                                                        style="width: {{ $normalizedScore }}%; background-color: {{ $hexColor }};">
                                                                                </div>
                                                                        </div>
                                                                        @if ($explanation)
                                                                                <p
                                                                                        class="text-sm text-gray-600 italic border-l-2 border-gray-200 pl-3 mt-2">
                                                                                        {{ $explanation }}</p>
                                                                        @endif
                                                                </div>
                                                        @endforeach
                                                </div>
                                        </div>
                                </div>
                        </div>
                </div>
        @endif

        <script>
                document.addEventListener('livewire:initialized', () => {
                        Livewire.on('ai_concierge_submitted', (event) => {
                                let data = Array.isArray(event) ? event[0] : event;
                                if (typeof posthog !== 'undefined') {
                                        posthog.capture('ai_concierge_submitted', {
                                                location: data.location,
                                                category: data.category,
                                                query: data.query
                                        });
                                }
                        });
                });
        </script>
        </div>{{-- end comparison UI bg-div --}}
    @endif{{-- end @else (leaf category) --}}
</div>
