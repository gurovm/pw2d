<div>
    <div class="bg-gradient-to-br from-gray-50 to-white min-h-screen">
        <!-- Category Header -->
        <div class="bg-white border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-deep-blue">{{ $category->name }}</h1>
                        @if($category->description)
                            <p class="text-gray-600 mt-2">{{ $category->description }}</p>
                        @endif
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-gray-500">Comparing</div>
                        <div class="text-2xl font-bold text-electric-blue">{{ $products->count() }}</div>
                        <div class="text-sm text-gray-500">products</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            @if($products->count() > 0 && $features->count() > 0)
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Left Sidebar: Interactive Sliders -->
                    <div class="lg:col-span-1">
                        <div class="bg-white rounded-xl shadow-md p-6 sticky top-24">
                            <h2 class="text-xl font-semibold text-deep-blue mb-2">Your Priorities</h2>
                            <p class="text-gray-600 text-sm mb-6">
                                Adjust sliders to rank products based on what matters most to you.
                            </p>

                            <!-- AI Concierge Chat -->
                            @if($showAiChat)
                                <div class="mb-6 p-4 bg-gradient-to-br from-electric-blue/5 to-blue-50 rounded-lg border border-electric-blue/20">
                                    <div class="flex items-start mb-3">
                                        <div class="flex-shrink-0">
                                            <div class="w-8 h-8 bg-electric-blue rounded-full flex items-center justify-center">
                                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="ml-3 flex-1">
                                            <p class="text-xs font-medium text-electric-blue mb-1">AI Concierge</p>
                                            @if($aiMessage)
                                                <p class="text-sm text-gray-700">{{ $aiMessage }}</p>
                                            @else
                                                <p class="text-sm text-gray-500 italic">Analyzing your needs...</p>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Chat Input -->
                                    <form wire:submit.prevent="sendMessage" class="mt-3">
                                        <div class="flex space-x-2">
                                            <input 
                                                type="text" 
                                                wire:model="userInput"
                                                placeholder="Tell me more or ask a question..."
                                                class="flex-1 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:border-electric-blue focus:ring-1 focus:ring-electric-blue outline-none transition-colors"
                                                :disabled="$wire.isAiProcessing"
                                            >
                                            <button 
                                                type="submit"
                                                class="px-4 py-2 bg-electric-blue hover:bg-blue-600 text-white text-sm font-medium rounded-lg transition-colors duration-200 flex items-center space-x-1 disabled:opacity-50 disabled:cursor-not-allowed"
                                                wire:loading.attr="disabled"
                                                wire:target="sendMessage"
                                            >
                                                <span wire:loading.remove wire:target="sendMessage">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                                                    </svg>
                                                </span>
                                                <span wire:loading wire:target="sendMessage">
                                                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                </span>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            @endif

                            <!-- Feature Sliders -->
                            <div class="space-y-6">
                                @foreach($features as $feature)
                                    <div class="pb-4 border-b border-gray-200">
                                        <div class="flex justify-between items-start mb-3">
                                            <div class="flex-1">
                                                <label class="text-sm font-medium text-deep-blue block">
                                                    {{ $feature->name }}
                                                </label>
                                                @if($feature->unit)
                                                    <span class="text-xs text-gray-500">({{ $feature->unit }})</span>
                                                @endif
                                            </div>
                                            <div class="flex items-center ml-2">
                                                @if($feature->is_higher_better)
                                                    <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" title="Higher is better">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                                                    </svg>
                                                @else
                                                    <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" title="Lower is better">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path>
                                                    </svg>
                                                @endif
                                            </div>
                                        </div>
                                        
                                        <!-- Range Slider -->
                                        <input 
                                            type="range" 
                                            min="0" 
                                            max="100" 
                                            step="1"
                                            wire:model.live="weights.{{ $feature->id }}"
                                            class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer slider-electric-blue"
                                        >
                                        
                                        <div class="flex justify-between items-center mt-2">
                                            <span class="text-xs text-gray-500">Not Important</span>
                                            <span class="text-sm font-semibold text-electric-blue">{{ $weights[$feature->id] ?? 50 }}%</span>
                                            <span class="text-xs text-gray-500">Very Important</span>
                                        </div>
                                    </div>
                                @endforeach

                                <!-- Amazon Rating (Virtual Feature) -->
                                <div class="pb-4">
                                    <div class="flex justify-between items-start mb-3">
                                        <div class="flex-1">
                                            <label class="text-sm font-medium text-deep-blue block">
                                                Wisdom of the Crowds
                                            </label>
                                            <span class="text-xs text-gray-500">(Amazon Rating)</span>
                                        </div>
                                        <svg class="w-4 h-4 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                        </svg>
                                    </div>
                                    
                                    <input 
                                        type="range" 
                                        min="0" 
                                        max="100" 
                                        step="1"
                                        wire:model.live="amazonRatingWeight"
                                        class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer slider-electric-blue"
                                    >
                                    
                                    <div class="flex justify-between items-center mt-2">
                                        <span class="text-xs text-gray-500">Not Important</span>
                                        <span class="text-sm font-semibold text-electric-blue">{{ $amazonRatingWeight }}%</span>
                                        <span class="text-xs text-gray-500">Very Important</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Info Box -->
                            <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-electric-blue mt-0.5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <p class="text-xs text-gray-700">
                                        Products are automatically re-ranked as you adjust sliders. Match Scores update in real-time!
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Content: Product Grid -->
                    <div class="lg:col-span-2">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            @foreach($products as $product)
                                <div class="bg-white rounded-xl shadow-md hover:shadow-xl transition-all duration-300 overflow-hidden">
                                    <!-- Product Image -->
                                    @if($product->image_path)
                                        <div class="aspect-w-16 aspect-h-9 bg-gray-100">
                                            <img src="{{ Storage::url($product->image_path) }}" alt="{{ $product->name }}" class="w-full h-48 object-cover">
                                        </div>
                                    @else
                                        <div class="w-full h-48 bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                                            <svg class="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                            </svg>
                                        </div>
                                    @endif

                                    <!-- Product Info -->
                                    <div class="p-6">
                                        <div class="flex items-start justify-between mb-3">
                                            <div>
                                                <h3 class="text-lg font-semibold text-deep-blue">{{ $product->name }}</h3>
                                                <p class="text-sm text-gray-600">{{ $product->brand->name }}</p>
                                            </div>
                                            @if($product->amazon_rating)
                                                <div class="flex items-center">
                                                    <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                                    </svg>
                                                    <span class="ml-1 text-sm font-medium text-gray-700">{{ number_format($product->amazon_rating, 1) }}</span>
                                                </div>
                                            @endif
                                        </div>

                                        <!-- Match Score -->
                                        @php
                                            $matchScore = $matchScores[$product->id] ?? 0;
                                            $scoreColor = $matchScore >= 75 ? 'from-green-500 to-emerald-600' : 
                                                         ($matchScore >= 50 ? 'from-electric-blue to-blue-500' : 
                                                         'from-gray-400 to-gray-500');
                                        @endphp
                                        <div class="mb-4 p-3 bg-gradient-to-r {{ $scoreColor }} rounded-lg transition-all duration-500">
                                            <div class="flex items-center justify-between">
                                                <span class="text-white text-sm font-medium">Match Score</span>
                                                <span class="text-white text-2xl font-bold">{{ number_format($matchScore, 1) }}%</span>
                                            </div>
                                        </div>

                                        <!-- Feature Values -->
                                        <div class="space-y-2 mb-4">
                                            @foreach($product->featureValues as $featureValue)
                                                <div class="flex justify-between text-sm">
                                                    <span class="text-gray-600">{{ $featureValue->feature->name }}:</span>
                                                    <span class="font-medium text-deep-blue">
                                                        {{ $featureValue->raw_value }}{{ $featureValue->feature->unit ? ' ' . $featureValue->feature->unit : '' }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>

                                        <!-- CTA Button -->
                                        @if($product->affiliate_url)
                                            <a 
                                                href="{{ $product->affiliate_url }}" 
                                                target="_blank"
                                                class="block w-full text-center bg-electric-blue hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200"
                                            >
                                                View Product
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <!-- Empty State -->
                <div class="text-center py-16">
                    <svg class="w-20 h-20 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                    </svg>
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">No Products Available</h3>
                    <p class="text-gray-600">
                        @if($features->count() === 0)
                            Please add features for this category in the admin panel first.
                        @else
                            Please add products for this category in the admin panel.
                        @endif
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
