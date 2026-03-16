@if($similar->isNotEmpty())
<div class="border-t border-gray-100 pt-6 mt-2 px-4 md:px-8 pb-6">
    <h2 class="text-sm font-bold text-gray-900 uppercase tracking-widest mb-4">Similar Products</h2>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        @foreach($similar as $similarProduct)
        <div class="bg-white rounded-2xl shadow-sm hover:shadow-[0_12px_40px_rgba(255,153,0,0.2)] transition-all duration-300 overflow-hidden flex flex-col border border-gray-100 hover:border-amber-400 group">

            {{-- Image --}}
            @if($similarProduct->image_url)
                <a href="/product/{{ $similarProduct->slug }}"
                   wire:click.prevent="openProduct('{{ $similarProduct->slug }}')"
                   @click="window.history.pushState({ returnUrl: window.location.href }, '', '/product/{{ $similarProduct->slug }}')"
                   class="h-32 w-full flex justify-center items-center bg-white overflow-hidden group-hover:bg-gray-50/50 transition-colors outline-none">
                    <img src="{{ $similarProduct->image_url }}" alt="{{ $similarProduct->name }}" class="h-full w-auto object-contain mix-blend-multiply">
                </a>
            @else
                <a href="/product/{{ $similarProduct->slug }}"
                   wire:click.prevent="openProduct('{{ $similarProduct->slug }}')"
                   @click="window.history.pushState({ returnUrl: window.location.href }, '', '/product/{{ $similarProduct->slug }}')"
                   class="h-32 w-full flex justify-center items-center bg-linear-to-br from-gray-50 to-gray-100 overflow-hidden outline-none">
                    <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                </a>
            @endif

            {{-- Info --}}
            <div class="p-2.5 flex-1 flex flex-col">
                <div class="flex justify-between items-center mb-1">
                    <p class="text-[10px] font-bold text-amber-700 uppercase tracking-wider truncate">{{ $similarProduct->brand?->name }}</p>
                    @if($similarProduct->amazon_rating)
                        <span class="text-[10px] font-bold text-gray-600 shrink-0 ml-1">
                            <span class="text-amber-500">★</span> {{ number_format($similarProduct->amazon_rating, 1) }}
                        </span>
                    @endif
                </div>

                <a href="/product/{{ $similarProduct->slug }}"
                   wire:click.prevent="openProduct('{{ $similarProduct->slug }}')"
                   @click="window.history.pushState({ returnUrl: window.location.href }, '', '/product/{{ $similarProduct->slug }}')"
                   class="block outline-none">
                    <h3 class="text-[11px] font-semibold text-gray-900 leading-tight line-clamp-2 mb-2">{{ $similarProduct->name }}</h3>
                </a>

                <div class="mt-auto pt-2 border-t border-gray-100">
                    @if($similarProduct->price_tier)
                        <div class="flex items-center gap-px leading-none">
                            <span class="text-base font-black text-gray-900">$</span>
                            <span class="text-base font-black {{ $similarProduct->price_tier >= 2 ? 'text-gray-900' : 'text-gray-300' }}">$</span>
                            <span class="text-base font-black {{ $similarProduct->price_tier >= 3 ? 'text-gray-900' : 'text-gray-300' }}">$</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- CTA --}}
            @if($similarProduct->affiliate_url)
                <a href="{{ $similarProduct->affiliate_url }}" target="_blank" rel="noopener noreferrer"
                   class="block w-full text-center bg-[#FF9900] hover:bg-[#E68A00] text-white py-2 font-bold text-xs transition-colors">
                    Check Price →
                </a>
            @endif
        </div>
        @endforeach
    </div>
</div>
@endif
