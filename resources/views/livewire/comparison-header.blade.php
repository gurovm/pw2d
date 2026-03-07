<div
    x-data="{ showPreferences: false, teaser: false }"
    x-init="
        if (!localStorage.getItem('pw2d_customize_seen')) {
            showPreferences = true;
            localStorage.setItem('pw2d_customize_seen', '1');
        } else {
            teaser = true;
            setTimeout(() => teaser = false, 3500);
        }
    "
    @keyup.escape.window="showPreferences = false"
>
    @php $categoryName = \App\Models\Category::find($categoryId)->name ?? 'Unknown Category'; @endphp
    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- FLOATING ACTION BUTTON (FAB)                           -->
    <!-- ═══════════════════════════════════════════════════════ -->
    
    <div class="fixed bottom-6 right-6 z-40 flex flex-col items-end gap-2.5"
         x-data="{ showTip: true }"
         x-init="setTimeout(() => showTip = false, 4000)"
    >
        <!-- Tooltip Bubble -->
        <div x-show="showTip && !showPreferences"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="bg-[#7C3AED] text-white text-xs font-bold px-4 py-1.5 rounded-xl shadow-lg pointer-events-none"
        >
            ✦ Adjust rankings to your needs
        </div>

        <!-- FAB Button -->
        <div class="relative">
            <!-- Teaser ping ring (returning visitors) -->
            <span
                x-show="teaser && !showPreferences"
                class="absolute inset-0 rounded-2xl bg-[#7C3AED] animate-ping opacity-30 pointer-events-none"
                style="display:none;"
            ></span>
            <button
                @click="showPreferences = true; teaser = false; showTip = false; if(typeof posthog !== 'undefined') posthog.capture('customize_modal_opened', { category: '{{ addslashes($categoryName) }}' });"
                :class="teaser && !showPreferences ? 'animate-bounce' : ''"
                class="w-14 h-14 md:w-16 md:h-16 rounded-2xl bg-[#7C3AED] text-white flex items-center justify-center shadow-[0_8px_24px_rgba(124,58,237,0.4)] hover:scale-110 hover:bg-[#6D28D9] active:scale-95 transition-all duration-300 cursor-pointer"
            >
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            </button>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- PREFERENCES SIDE PANEL                                 -->
    <!-- ═══════════════════════════════════════════════════════ -->
    
    <!-- Backdrop -->
    <div
        x-show="showPreferences"
        x-transition.opacity.duration.300ms
        @click="showPreferences = false"
        class="fixed inset-0 z-50 bg-gray-900/40 backdrop-blur-[2px]"
        style="display: none;"
    ></div>

    <!-- Side Panel (slides from right) -->
    <div
        x-show="showPreferences"
        x-transition:enter="transition ease-out duration-400"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-300"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="fixed top-0 right-0 bottom-0 z-50 w-[400px] max-w-[90vw] bg-white border-l border-gray-200 shadow-[-10px_0_30px_rgba(0,0,0,0.05)] flex flex-col"
        style="display: none;"
    >
        <!-- Panel Header -->
        <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100 shrink-0">
            <div>
                <h3 class="text-xl font-black text-gray-900">Customize Your Priorities</h3>
                <p class="text-xs text-gray-500 mt-0.5">Drag the sliders to tell us what matters most to you</p>
            </div>
            <button @click="showPreferences = false" class="p-2 hover:bg-gray-100 rounded-lg transition-colors text-gray-400 hover:text-gray-600 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- Panel Body (Scrollable) -->
        <div class="flex-1 overflow-y-auto px-6 py-5" x-data="{ panelTab: 'weights' }">

            <!-- Tab Nav -->
            <div class="flex bg-gray-100 p-1 rounded-[14px] mb-5">
                <button @click="panelTab = 'weights'" :class="panelTab === 'weights' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" class="flex-1 py-2.5 px-3 rounded-[10px] text-[13px] font-bold transition-all cursor-pointer">Weights</button>
                <button @click="panelTab = 'presets'" :class="panelTab === 'presets' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" class="flex-1 py-2.5 px-3 rounded-[10px] text-[13px] font-bold transition-all cursor-pointer">Presets</button>
                <button @click="panelTab = 'ai'" :class="panelTab === 'ai' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" class="flex-1 py-2.5 px-3 rounded-[10px] text-[13px] font-bold transition-all cursor-pointer">AI Help</button>
            </div>

            <!-- TAB: Weights (Sliders) -->
            <div x-show="panelTab === 'weights'" x-transition.opacity.duration.200ms>
                <!-- Intro Text -->
                <div class="text-sm text-gray-500 leading-relaxed mb-5 p-3 bg-slate-50 rounded-xl border-l-[3px] border-[#7C3AED]">
                    Drag the sliders to set your priorities. Products are re-ranked instantly based on what matters most <b>to you</b>.
                </div>

                {{-- Alpine.js owns slider state for instant display; Livewire is only notified on mouseup (one round-trip per gesture, not per pixel) --}}
                <div class="space-y-5"
                     x-data="{
                         w: @js($weights),
                         price: {{ (int) $priceWeight }},
                         rating: {{ (int) $amazonRatingWeight }},
                         fire() {
                             Livewire.dispatch('weights-updated', { weights: this.w, priceWeight: this.price, amazonRatingWeight: this.rating });
                         }
                     }"
                     @alpine-weights-sync.window="w = { ...$event.detail.weights }; price = $event.detail.priceWeight; rating = $event.detail.amazonRatingWeight">

                    <!-- Price Slider -->
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-semibold text-gray-700">Price</span>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full"
                                  :style="`background-color: hsl(${price * 1.2}, 85%, 93%); color: hsl(${price * 1.2}, 85%, 30%)`"
                                  x-text="price + '%'"></span>
                        </div>
                        <input type="range" min="0" max="100"
                               x-model.number="price"
                               @change="fire(); if(typeof posthog !== 'undefined') posthog.capture('sliders_manually_adjusted', { category: '{{ addslashes($categoryName) }}', feature: 'Price' })"
                               class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600 focus:outline-none"
                               :style="`accent-color: hsl(${price * 1.2}, 85%, 45%)`">
                    </div>

                    <!-- Rating Slider -->
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-semibold text-gray-700">Amazon Rating</span>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full"
                                  :style="`background-color: hsl(${rating * 1.2}, 85%, 93%); color: hsl(${rating * 1.2}, 85%, 30%)`"
                                  x-text="rating + '%'"></span>
                        </div>
                        <input type="range" min="0" max="100"
                               x-model.number="rating"
                               @change="fire(); if(typeof posthog !== 'undefined') posthog.capture('sliders_manually_adjusted', { category: '{{ addslashes($categoryName) }}', feature: 'Amazon Rating' })"
                               class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                               :style="`accent-color: hsl(${rating * 1.2}, 85%, 45%)`">
                    </div>

                    <div class="h-px bg-gray-100"></div>

                    <!-- Category Features -->
                    @foreach($features as $feature)
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-semibold text-gray-700">{{ $feature->label ?? $feature->name }}</span>
                                <span class="text-xs font-bold px-2 py-0.5 rounded-full"
                                      :style="`background-color: hsl(${w[{{ $feature->id }}] * 1.2}, 85%, 93%); color: hsl(${w[{{ $feature->id }}] * 1.2}, 85%, 30%)`"
                                      x-text="w[{{ $feature->id }}] + '%'"></span>
                            </div>
                            <input type="range" min="0" max="100"
                                   :value="w[{{ $feature->id }}]"
                                   @input="w[{{ $feature->id }}] = parseInt($event.target.value)"
                                   @change="fire(); if(typeof posthog !== 'undefined') posthog.capture('sliders_manually_adjusted', { category: '{{ addslashes($categoryName) }}', feature: '{{ addslashes($feature->name) }}' })"
                                   class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                   :style="`accent-color: hsl(${w[{{ $feature->id }}] * 1.2}, 85%, 45%)`">
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- TAB: Presets -->
            <div x-show="panelTab === 'presets'" x-transition.opacity.duration.200ms style="display: none;">
                <div class="text-sm text-gray-500 leading-relaxed mb-5 p-3 bg-slate-50 rounded-xl border-l-[3px] border-[#7C3AED]">
                    Choose a preset to instantly adjust all sliders to a recommended configuration.
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <!-- Balanced (Default) -->
                    <button wire:click="applyPreset('balanced')" 
                            class="p-4 rounded-xl border-2 text-left transition-all cursor-pointer hover:shadow-md {{ $selectedPreset === 'balanced' ? 'border-[#7C3AED] bg-purple-50' : 'border-gray-200 bg-white hover:border-gray-300' }}">
                        <div class="text-sm font-bold text-gray-900 mb-1">⚖️ Balanced</div>
                        <div class="text-xs text-gray-500">Equal weight on all features</div>
                    </button>

                    <!-- Dynamic Presets from DB -->
                    @foreach($presets as $preset)
                        <button wire:click="applyPreset('{{ $preset->id }}')" 
                                class="p-4 rounded-xl border-2 text-left transition-all cursor-pointer hover:shadow-md {{ $selectedPreset == $preset->id ? 'border-[#7C3AED] bg-purple-50' : 'border-gray-200 bg-white hover:border-gray-300' }}">
                            <div class="text-sm font-bold text-gray-900 mb-1">✨ {{ $preset->name }}</div>
                            <div class="text-xs text-gray-500">{{ $preset->description ?? 'AI-generated preset' }}</div>
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- TAB: AI Help -->
            <div x-show="panelTab === 'ai'" x-transition.opacity.duration.200ms style="display: none;">
                <!-- Bot Intro -->
                <div class="p-4 bg-indigo-50 rounded-xl text-sm text-indigo-800 mb-5 leading-relaxed">
                    <div class="flex items-start gap-2">
                        <span class="shrink-0 text-lg">🤖</span>
                        <p>Describe what you need! I'll automatically adjust the weights to find your perfect product.</p>
                    </div>
                </div>

                <!-- AI Response (if any) -->
                @if($aiMessage && !$isThinking)
                    <div class="p-3 bg-indigo-50 border border-indigo-100/50 rounded-xl text-sm text-indigo-900 shadow-sm mb-4">
                        <div class="flex items-start gap-2">
                            <span class="shrink-0 text-indigo-500 mt-0.5">✨</span>
                            <p class="leading-relaxed">{{ $aiMessage }}</p>
                        </div>
                    </div>
                @endif

                <!-- Loading -->
                @if($isThinking)
                    <div class="p-3 bg-indigo-50/70 border border-indigo-100/50 rounded-xl text-sm text-indigo-900 shadow-sm flex items-center gap-3 mb-4">
                        <svg class="animate-spin h-5 w-5 text-indigo-500 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <p class="animate-pulse font-medium text-indigo-800">Analyzing your request...</p>
                    </div>
                @endif

                <!-- Chat Input -->
                <form wire:submit.prevent="submitAiPrompt" x-data @submit="$refs.aiInput.blur()" class="flex items-center gap-2">
                    <input 
                        x-ref="aiInput"
                        type="text" 
                        wire:model="aiPrompt"
                        placeholder="I'm a teacher recording lectures..." 
                        class="flex-1 border border-gray-200 bg-gray-50 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-400 focus:bg-white outline-none transition-all"
                    >
                    <button 
                        type="submit"
                        @if($isThinking) disabled @endif
                        class="px-5 py-3 bg-gradient-to-r from-indigo-600 to-blue-600 text-white rounded-xl text-sm font-bold hover:shadow-md transition-all shrink-0 cursor-pointer @if($isThinking) opacity-75 cursor-not-allowed @endif"
                    >
                        Ask
                    </button>
                </form>
            </div>
        </div>

        <!-- Panel Footer -->
        <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/50 shrink-0">
            <button 
                @click="showPreferences = false"
                class="w-full py-3 bg-gradient-to-r from-indigo-600 to-blue-600 text-white font-bold rounded-xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 text-sm flex items-center justify-center gap-2 cursor-pointer"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                Apply & Rank
            </button>
        </div>
    </div>
</div>
