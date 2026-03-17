{{--
  GlobalSearch — two visual variants, identical behaviour:
    400 ms debounce → DB search
    no results → 1 s idle wait → AI search → click-to-navigate result (no redirect)
--}}
<div x-data="{
         open: @entangle('open'),
         _aiTimer: null,
         init() {
             {{-- When isAiSearching flips true (DB returned nothing), wait 1 s
                  before calling the AI. Any new keystroke within that second
                  resets isAiSearching → false, which cancels the timer. --}}
             $watch('$wire.isAiSearching', val => {
                 clearTimeout(this._aiTimer);
                 if (val) {
                     this._aiTimer = setTimeout(() => {
                         if ($wire.isAiSearching) $wire.performAiSearch();
                     }, 1000);
                 }
             });
         }
     }"
     @click.outside="open = false; clearTimeout(_aiTimer)"
     @keydown.escape.window="open = false; clearTimeout(_aiTimer)"
     class="{{ $variant === 'hero' ? 'w-full flex flex-col items-center' : 'relative w-full max-w-lg z-50' }}">

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- HERO VARIANT — identical look to the homepage search bar          --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    @if($variant === 'hero')

        <form @submit.prevent="$wire.search()" class="search-wrapper">
            <div class="search-shadow"></div>

            <div class="search-box"
                 x-data="{
                     prompts: @js($samplePrompts),
                     typedText: '',
                     _pi: 0, _ci: 0, _del: false,
                     _tick() {
                         const p = this.prompts[this._pi];
                         if (!this._del) {
                             this.typedText = p.slice(0, ++this._ci);
                             if (this._ci >= p.length) {
                                 this._del = true;
                                 setTimeout(() => this._tick(), 2000);
                                 return;
                             }
                         } else {
                             this.typedText = p.slice(0, --this._ci);
                             if (this._ci <= 0) {
                                 this._del = false;
                                 this._pi = (this._pi + 1) % this.prompts.length;
                                 setTimeout(() => this._tick(), 150);
                                 return;
                             }
                         }
                         setTimeout(() => this._tick(), this._del ? 35 : 65);
                     }
                 }"
                 x-init="if (prompts.length && !$el._tw) { $el._tw = true; _tick(); }">

                <span class="search-ai-badge">
                    <span class="sm:hidden">AI</span>
                    <span class="hidden sm:inline">AI Search</span>
                </span>

                <input
                    type="text"
                    wire:model.live.debounce.400ms="query"
                    x-bind:placeholder="typedText"
                    autocomplete="off"
                    @focus="if ($wire.query.length >= 3) open = true"
                >

                <button type="submit" class="search-btn">
                    <span class="sm:hidden">Search</span>
                    <span class="hidden sm:inline">Find My Gear</span>
                </button>
            </div>

            {{-- Results panel: inline below the search-box, inherits wrapper width --}}
            @if(!empty($dbResults) || $isAiSearching || $aiSuggestion || $aiError)
                <div class="mt-3 w-full bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden relative z-10">
                    @include('livewire.global-search-results')
                </div>
            @endif
        </form>

    {{-- ══════════════════════════════════════════════════════════════════ --}}
    {{-- NAV VARIANT — compact pill with absolute dropdown                  --}}
    {{-- ══════════════════════════════════════════════════════════════════ --}}
    @else

        <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <input
                type="search"
                wire:model.live.debounce.400ms="query"
                placeholder="Search categories or products…"
                autocomplete="off"
                @focus="if ($wire.query.length >= 3) open = true"
                class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-full bg-gray-50
                       focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/30
                       focus:border-blue-400 shadow-sm text-sm transition-all duration-200"
            >
            <div wire:loading wire:target="updatedQuery"
                 class="absolute inset-y-0 right-0 pr-3 flex items-center">
                <svg class="animate-spin h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
            </div>
        </div>

        {{-- Absolute dropdown --}}
        <div x-show="open"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-1"
             style="display:none"
             class="absolute top-full mt-2 w-full bg-white rounded-2xl shadow-xl
                    border border-gray-100 overflow-hidden max-h-112 overflow-y-auto">
            @include('livewire.global-search-results')
        </div>

    @endif

</div>
