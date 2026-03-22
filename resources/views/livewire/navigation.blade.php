<nav class="bg-white border-b border-gray-200 sticky top-0 z-[60] shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20">
            <!-- Logo or Brand Name -->
            <div class="flex-shrink-0">
                <a href="/" class="flex items-center">
                    @if(tenant('logo'))
                        <img src="{{ Storage::disk('public')->url(tenant('logo')) }}"
                             alt="{{ tenant('brand_name') ?? tenant('name') ?? 'Logo' }}"
                             class="h-10 md:h-14 w-auto" width="56" height="56" fetchpriority="high">
                    @elseif(tenant('brand_name'))
                        <span class="text-lg md:text-xl font-bold tracking-tight"
                              style="color: var(--color-text);">
                            {{ tenant('brand_name') }}
                        </span>
                    @else
                        <img src="{{ asset('images/logo.webp') }}" alt="pw2d Logo"
                             class="h-10 md:h-16 w-auto" width="242" height="64" fetchpriority="high">
                    @endif
                </a>
            </div>

            <!-- Global Search (Center) -->
            <div class="flex-1 flex justify-center px-4 md:px-8">
                <livewire:global-search />
            </div>

            <!-- Right Side: reserved for future features -->
            <div class="flex items-center"></div>
        </div>
    </div>
</nav>
