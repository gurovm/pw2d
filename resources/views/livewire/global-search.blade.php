<div class="relative w-full max-w-lg z-50">
    <!-- Search Input -->
    <div class="relative">
        <input 
            type="search" 
            wire:model.live.debounce.750ms="search" 
            placeholder="Search categories or products..." 
            class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-electric-blue focus:border-transparent shadow-sm text-sm transition-shadow duration-200"
            autocomplete="off"
        >
        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <!-- Search Icon -->
            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
        </div>
        
        <!-- Loading Indicator -->
        <div wire:loading class="absolute inset-y-0 right-0 pr-3 flex items-center">
            <svg class="animate-spin h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>
    </div>

    <!-- Dropdown Results -->
    @if(strlen($search) >= 2)
        <div class="absolute mt-2 w-full bg-white rounded-xl shadow-xl border border-gray-100 overflow-hidden max-h-96 overflow-y-auto">
            
            @if(count($this->results['categories']) > 0)
                <div class="px-4 py-2 bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                    Categories
                </div>
                <ul>
                    @foreach($this->results['categories'] as $category)
                        <li>
                            <a href="{{ route('category.show', $category->slug) }}" class="block px-4 py-2 hover:bg-gray-50 transition duration-150 ease-in-out border-b border-gray-50 last:border-b-0">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-gray-900">{{ $category->name }}</span>
                                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if(count($this->results['products']) > 0)
                <div class="px-4 py-2 bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider border-t border-gray-100">
                    Products
                </div>
                <ul>
                    @foreach($this->results['products'] as $product)
                        <li>
                            <!-- Link to parent category compare page -->
                            <a href="{{ route('category.show', $product->category->slug ?? '#') }}" class="block px-4 py-2 hover:bg-gray-50 transition duration-150 ease-in-out border-b border-gray-50 last:border-b-0">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            {{ $product->name }} 
                                            @if($product->brand)
                                                <span class="text-gray-500">- {{ $product->brand->name }}</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            in <span class="text-electric-blue font-medium">{{ $product->category->name ?? 'Uncategorized' }}</span>
                                        </div>
                                    </div>
                                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                    </svg>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
            
            @if(count($this->results['categories']) === 0 && count($this->results['products']) === 0)
                 <div class="px-4 py-6 text-sm text-gray-500 text-center">
                    No results found for "<span class="font-medium text-gray-900">{{ $search }}</span>"
                </div>
            @endif
        </div>
    @endif

    <!-- PostHog Tracking for Global Search -->
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('global_search_used', (event) => {
                let data = Array.isArray(event) ? event[0] : event;
                if (typeof posthog !== 'undefined') {
                    posthog.capture('global_search_used', { query: data.query });
                }
            });
        });
    </script>
</div>
