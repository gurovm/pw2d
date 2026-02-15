<div>
    <div class="bg-gradient-to-br from-gray-50 to-white">
        <!-- Hero Section with AI Search -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
            <div class="text-center">
                <h1 class="text-5xl font-bold text-deep-blue mb-6">
                    Make Smarter Decisions
                </h1>
                <p class="text-xl text-gray-600 mb-12 max-w-3xl mx-auto">
                    Unlike traditional comparison sites, <span class="font-semibold text-electric-blue">pw2d</span> empowers you to rank products based on <span class="font-semibold">your personal priorities</span> using interactive sliders. Get a personalized Match Score for every product.
                </p>

                <!-- AI-Powered Search Bar -->
                <div class="max-w-2xl mx-auto mb-16">
                    <form wire:submit.prevent="searchCategory" class="relative">
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            <input 
                                type="text" 
                                wire:model="searchQuery"
                                placeholder="What are you looking to compare? (e.g., I need a wireless mouse for work)"
                                class="w-full pl-12 pr-32 py-4 text-lg border-2 border-gray-300 rounded-xl focus:border-electric-blue focus:ring-2 focus:ring-electric-blue/20 transition-all duration-200 outline-none"
                                :disabled="$wire.isSearching"
                            >
                            <div class="absolute inset-y-0 right-0 pr-2 flex items-center">
                                <button 
                                    type="submit"
                                    class="bg-electric-blue hover:bg-blue-600 text-white font-medium px-6 py-2 rounded-lg transition-colors duration-200 flex items-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    wire:loading.attr="disabled"
                                >
                                    <span wire:loading.remove wire:target="searchCategory">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                        </svg>
                                    </span>
                                    <span wire:loading wire:target="searchCategory">
                                        <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                    </span>
                                    <span wire:loading.remove wire:target="searchCategory">Search</span>
                                    <span wire:loading wire:target="searchCategory">Searching...</span>
                                </button>
                            </div>
                        </div>
                        
                        @if($searchError)
                            <div class="mt-3 p-3 bg-red-50 border border-red-200 rounded-lg flex items-start">
                                <svg class="w-5 h-5 text-red-500 mt-0.5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <p class="text-sm text-red-700">{{ $searchError }}</p>
                            </div>
                        @endif
                    </form>

                    <div class="mt-4 flex items-center justify-center space-x-2 text-sm text-gray-500">
                        <svg class="w-4 h-4 text-electric-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        <span>Powered by AI - Just describe what you need in natural language</span>
                    </div>
                </div>
            </div>

            <!-- Popular Categories Grid -->
            <div class="mt-8">
                <h2 class="text-2xl font-semibold text-deep-blue mb-8 text-center">
                    Popular Categories
                </h2>

                @if($popularCategories->count() > 0)
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        @foreach($popularCategories as $category)
                            <a 
                                href="{{ route('category.show', $category->slug) }}" 
                                wire:navigate
                                class="group bg-white rounded-xl shadow-md hover:shadow-xl transition-all duration-300 p-6 border border-gray-200 hover:border-electric-blue"
                            >
                                <div class="flex flex-col h-full">
                                    <div class="flex items-start justify-between mb-3">
                                        <h3 class="text-lg font-semibold text-deep-blue group-hover:text-electric-blue transition-colors duration-200 flex-1">
                                            {{ $category->name }}
                                        </h3>
                                        <svg class="w-5 h-5 text-electric-blue opacity-0 group-hover:opacity-100 transition-opacity duration-200 ml-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </div>
                                    
                                    @if($category->description)
                                        <p class="text-gray-600 text-sm mb-4 flex-1">
                                            {{ Str::limit($category->description, 80) }}
                                        </p>
                                    @endif

                                    <!-- Product Count Badge -->
                                    <div class="flex items-center justify-between mt-auto pt-3 border-t border-gray-100">
                                        <div class="flex items-center text-sm text-gray-500">
                                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                            </svg>
                                            {{ $category->products_count }} {{ Str::plural('product', $category->products_count) }}
                                        </div>
                                        <div class="text-xs font-medium text-electric-blue group-hover:underline">
                                            Compare →
                                        </div>
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
            </div>
        </div>

        <!-- Features Section -->
        <div class="bg-white border-t border-gray-200 py-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <!-- Feature 1 -->
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-electric-blue/10 rounded-full mb-4">
                            <svg class="w-8 h-8 text-electric-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-deep-blue mb-2">Personalized Sliders</h3>
                        <p class="text-gray-600">Adjust feature weights based on what matters most to you</p>
                    </div>

                    <!-- Feature 2 -->
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-electric-blue/10 rounded-full mb-4">
                            <svg class="w-8 h-8 text-electric-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-deep-blue mb-2">Real-Time Scoring</h3>
                        <p class="text-gray-600">See Match Scores update instantly as you adjust priorities</p>
                    </div>

                    <!-- Feature 3 -->
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-electric-blue/10 rounded-full mb-4">
                            <svg class="w-8 h-8 text-electric-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-deep-blue mb-2">AI-Powered Search</h3>
                        <p class="text-gray-600">Describe what you need and let AI find the right category</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
