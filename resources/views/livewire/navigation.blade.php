<nav class="bg-white border-b border-gray-200 sticky top-0 z-50 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <!-- Logo -->
            <div class="flex-shrink-0">
                <a href="/" class="flex items-center">
                    <img src="{{ asset('images/logo.png') }}" alt="pw2d Logo" class="h-10 w-auto">
                </a>
            </div>

            <!-- Categories Dropdown (Center) -->
            <div class="flex-1 flex justify-center">
                <div class="relative" x-data="{ open: false }">
                    <button 
                        @click="open = !open"
                        @click.away="open = false"
                        class="inline-flex items-center px-6 py-2 text-deep-blue hover:text-electric-blue transition-colors duration-200 font-medium"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                        Categories
                        <svg class="w-4 h-4 ml-2 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <!-- Dropdown Menu -->
                    <div 
                        x-show="open"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 transform scale-95"
                        x-transition:enter-end="opacity-100 transform scale-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 transform scale-100"
                        x-transition:leave-end="opacity-0 transform scale-95"
                        class="absolute left-1/2 transform -translate-x-1/2 mt-2 w-64 bg-white rounded-lg shadow-xl border border-gray-200 py-2"
                        style="display: none;"
                    >
                        @forelse($rootCategories as $category)
                            <a 
                                href="{{ route('category.show', $category->slug) }}" 
                                class="block px-4 py-3 text-deep-blue hover:bg-gray-50 hover:text-electric-blue transition-colors duration-150"
                                wire:navigate
                            >
                                <div class="font-medium">{{ $category->name }}</div>
                                @if($category->description)
                                    <div class="text-sm text-gray-500 mt-1">{{ Str::limit($category->description, 60) }}</div>
                                @endif
                            </a>
                        @empty
                            <div class="px-4 py-3 text-gray-500 text-sm">
                                No categories available
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Right Side (Future: User Menu, Search, etc.) -->
            <div class="flex items-center space-x-4">
                <!-- Placeholder for future features -->
            </div>
        </div>
    </div>
</nav>
