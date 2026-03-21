<div
    x-data="{ showPreferences: false, teaser: false }"
    x-init="
        if (!localStorage.getItem('pw2d_customize_seen')) {
            localStorage.setItem('pw2d_customize_seen', '1');
            setTimeout(() => window.dispatchEvent(new CustomEvent('pw2d-open-sidebar')), 3000);
        } else {
            teaser = true;
            setTimeout(() => teaser = false, 3500);
        }
    "
    x-on:pw2d-open-sidebar.window="showPreferences = true"
    @keyup.escape.window="showPreferences = false"
>
    @php $categoryName = \App\Models\Category::find($categoryId)->name ?? 'Unknown Category'; @endphp

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- FLOATING ACTION BUTTON (FAB)                           -->
    <!-- ═══════════════════════════════════════════════════════ -->

    <div class="fixed bottom-6 right-6 z-40 flex flex-col items-end gap-2.5"
         x-data="{ showTip: true }"
         x-init="setTimeout(() => showTip = false, 4000)">

        <!-- Tooltip Bubble -->
        <div x-show="showTip && !showPreferences"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="bg-[#7C3AED] text-white text-xs font-bold px-4 py-1.5 rounded-xl shadow-lg pointer-events-none">
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
                aria-label="Customize ranking preferences"
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
        class="fixed top-0 sm:top-20 right-0 bottom-0 z-50 w-100 max-w-[82vw] bg-white border-l border-gray-200 shadow-[-10px_0_30px_rgba(0,0,0,0.05)] flex flex-col"
        style="display: none;"
    >
        <!-- Panel Header -->
        <div class="flex items-center justify-between px-4 py-3 sm:px-6 sm:py-5 border-b border-gray-100 shrink-0">
            <div>
                <h3 class="text-base sm:text-xl font-black text-gray-900 leading-tight">Customize Your Priorities</h3>
                <p class="text-xs text-gray-500 mt-0.5">Presets, AI, and sliders all work together</p>
            </div>
            <button @click="showPreferences = false" class="p-2 hover:bg-gray-100 rounded-lg transition-colors text-gray-400 hover:text-gray-600 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- Panel Body — Single unified panel, no tabs -->
        <div class="flex-1 overflow-y-auto px-4 py-3 space-y-4 sm:px-6 sm:py-5 sm:space-y-7">

            <!-- ─────────────────────────────────────────────── -->
            <!-- SECTION 1: AI CONCIERGE                        -->
            <!-- ─────────────────────────────────────────────── -->
            <div>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2 sm:mb-3">✦ Tell us what you need</p>

                <!-- AI Response -->
                @if($aiMessage && !$isThinking)
                    <div class="p-3 bg-indigo-50 border border-indigo-100/50 rounded-xl text-sm text-indigo-900 shadow-sm mb-3">
                        <div class="flex items-start gap-2">
                            <span class="shrink-0 text-indigo-500 mt-0.5">✨</span>
                            <p class="leading-relaxed">{{ $aiMessage }}</p>
                        </div>
                    </div>
                @endif

                <!-- Loading -->
                @if($isThinking)
                    <div class="p-3 bg-indigo-50/70 border border-indigo-100/50 rounded-xl text-sm text-indigo-900 shadow-sm flex items-center gap-3 mb-3">
                        <svg class="animate-spin h-5 w-5 text-indigo-500 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <p class="animate-pulse font-medium text-indigo-800">Analyzing your request...</p>
                    </div>
                @endif

                <!-- Chat Input -->
                <form wire:submit.prevent="submitAiPrompt" @submit="$refs.aiInput.blur()"
                      x-data="{
                          prompts: @js($samplePrompts),
                          typedText: '',
                          _pi: 0,
                          _ci: 0,
                          _del: false,
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
                      x-init="if (prompts.length && !$el._tw) { $el._tw = true; _tick(); }"
                      class="flex items-center gap-2">
                    <input
                        x-ref="aiInput"
                        type="text"
                        wire:model="aiPrompt"
                        x-bind:placeholder="typedText"
                        class="flex-1 border border-gray-200 bg-gray-50 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-400 focus:bg-white outline-none transition-all"
                    >
                    <button
                        type="submit"
                        @if($isThinking) disabled @endif
                        class="px-5 py-3 bg-gradient-to-r from-indigo-600 to-blue-600 text-white rounded-xl text-sm font-bold hover:shadow-md transition-all shrink-0 cursor-pointer @if($isThinking) opacity-75 cursor-not-allowed @endif"
                    >Ask</button>
                </form>
            </div>

            <!-- ─────────────────────────────────────────────── -->
            <!-- SECTION 2: QUICK PRESETS (Pill chips)          -->
            <!-- ─────────────────────────────────────────────── -->
            <div>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2 sm:mb-3">⚡ Quick Presets</p>
                <div class="flex flex-wrap gap-2">

                    @forelse($presets as $preset)
                        <button
                            wire:click="applyPreset('{{ $preset->id }}')"
                            class="px-3.5 py-1.5 rounded-full text-[13px] font-semibold border transition-all duration-200 cursor-pointer {{ $presetSlug === Str::slug($preset->name) ? 'bg-purple-100 border-[#7C3AED] text-[#7C3AED]' : 'bg-gray-100 border-transparent text-gray-600 hover:bg-gray-200' }}"
                        >✨ {{ $preset->name }}</button>
                    @empty
                        <p class="text-xs text-gray-400 italic">No presets defined for this category yet.</p>
                    @endforelse

                </div>
            </div>

            <!-- ─────────────────────────────────────────────── -->
            <!-- SECTION 3: SLIDERS (always visible)            -->
            <!-- ─────────────────────────────────────────────── -->
            <div>
                {{--
                    wire:ignore: Alpine owns this subtree entirely. Without it, Livewire's DOM morph
                    (triggered when fire() dispatches weights-updated) re-initialises x-data from the
                    server-rendered values (price: 50) and snaps the sliders back after mouse release.
                    wire:ignore tells the morphing algorithm to leave this div untouched; Alpine's
                    window event listeners continue working normally because they are not DOM bindings.

                    isDirty tracks whether any slider has been changed from the neutral 50% state,
                    either by manual drag (fire()) or by a preset/AI (alpine-sliders-dirty event).
                    It is cleared by alpine-sliders-reset, dispatched from resetWeights() on the server.
                    This drives the Reset button visibility entirely within Alpine — no Blade @if needed.
                --}}
                <div wire:ignore
                     x-data="{
                         w: @js($weights),
                         price: {{ (int) $priceWeight }},
                         rating: {{ (int) $amazonRatingWeight }},
                         isDirty: false,
                         presetCleared: false,

                         fire() {
                             this.isDirty = true;
                             // One-shot: clear the active preset the first time a slider is dragged.
                             // $wire.clearPreset() is called only once per drag session to avoid
                             // a Livewire round-trip on every slider tick.
                             if (!this.presetCleared) {
                                 this.presetCleared = true;
                                 $wire.clearPreset();
                             }
                             Livewire.dispatch('weights-updated', { weights: this.w, priceWeight: this.price, amazonRatingWeight: this.rating });
                         },

                         animateValue(obj, key, target, duration = 500) {
                             const start = obj[key] ?? 50;
                             const startTime = performance.now();
                             const easeInOut = t => t < 0.5
                                 ? 2 * t * t
                                 : 1 - Math.pow(-2 * t + 2, 2) / 2;
                             const step = (now) => {
                                 const t = Math.min((now - startTime) / duration, 1);
                                 obj[key] = Math.round(start + (target - start) * easeInOut(t));
                                 if (t < 1) requestAnimationFrame(step);
                                 else obj[key] = target;
                             };
                             requestAnimationFrame(step);
                         },

                         syncAndAnimate(weights, price, rating) {
                             for (const [id, val] of Object.entries(weights)) {
                                 this.animateValue(this.w, id, Number(val));
                             }
                             this.animateValue(this, 'price', Number(price));
                             this.animateValue(this, 'rating', Number(rating));
                         }
                     }"
                     x-on:alpine-weights-sync.window="syncAndAnimate($event.detail.weights, $event.detail.priceWeight, $event.detail.amazonRatingWeight)"
                     x-on:alpine-sliders-dirty.window="isDirty = true"
                     x-on:alpine-sliders-reset.window="isDirty = false"
                     x-on:alpine-preset-applied.window="presetCleared = false">

                    <!-- Section header: Reset button visibility is driven by Alpine isDirty, not Blade -->
                    <div class="flex items-center justify-between mb-2 sm:mb-4">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">🎚 Your Priorities</p>
                        <button
                            x-show="isDirty"
                            x-transition.opacity.duration.200ms
                            wire:click="resetWeights"
                            style="display:none;"
                            class="flex items-center gap-1 text-[11px] text-gray-400 hover:text-gray-700 transition-colors cursor-pointer"
                        >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                            Reset
                        </button>
                    </div>

                    <div class="space-y-3 sm:space-y-5">

                    <!-- Price Slider -->
                    <div>
                        <div class="flex justify-between items-center mb-1 sm:mb-2">
                            <span class="text-sm font-semibold text-gray-700">Price</span>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full transition-all duration-300"
                                  :style="`background-color: hsl(${price * 1.2}, 85%, 93%); color: hsl(${price * 1.2}, 85%, 30%)`"
                                  x-text="price + '%'"></span>
                        </div>
                        <input type="range" min="0" max="100"
                               x-model.number="price"
                               @change="fire(); if(typeof posthog !== 'undefined') posthog.capture('sliders_manually_adjusted', { category: '{{ addslashes($categoryName) }}', feature: 'Price' })"
                               class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer focus:outline-none"
                               :style="`accent-color: hsl(${price * 1.2}, 85%, 45%)`">
                    </div>

                    <!-- Amazon Rating Slider -->
                    <div>
                        <div class="flex justify-between items-center mb-1 sm:mb-2">
                            <span class="text-sm font-semibold text-gray-700">Amazon Rating</span>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full transition-all duration-300"
                                  :style="`background-color: hsl(${rating * 1.2}, 85%, 93%); color: hsl(${rating * 1.2}, 85%, 30%)`"
                                  x-text="rating + '%'"></span>
                        </div>
                        <input type="range" min="0" max="100"
                               x-model.number="rating"
                               @change="fire(); if(typeof posthog !== 'undefined') posthog.capture('sliders_manually_adjusted', { category: '{{ addslashes($categoryName) }}', feature: 'Amazon Rating' })"
                               class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer focus:outline-none"
                               :style="`accent-color: hsl(${rating * 1.2}, 85%, 45%)`">
                    </div>

                    <div class="h-px bg-gray-100"></div>

                    <!-- Category Feature Sliders -->
                    @foreach($features as $feature)
                        <div>
                            <div class="flex justify-between items-center mb-1 sm:mb-2">
                                <span class="text-sm font-semibold text-gray-700">{{ $feature->label ?? $feature->name }}</span>
                                <span class="text-xs font-bold px-2 py-0.5 rounded-full transition-all duration-300"
                                      :style="`background-color: hsl(${w[{{ $feature->id }}] * 1.2}, 85%, 93%); color: hsl(${w[{{ $feature->id }}] * 1.2}, 85%, 30%)`"
                                      x-text="w[{{ $feature->id }}] + '%'"></span>
                            </div>
                            <input type="range" min="0" max="100"
                                   :value="w[{{ $feature->id }}]"
                                   @input="w[{{ $feature->id }}] = parseInt($event.target.value)"
                                   @change="fire(); if(typeof posthog !== 'undefined') posthog.capture('sliders_manually_adjusted', { category: '{{ addslashes($categoryName) }}', feature: '{{ addslashes($feature->name) }}' })"
                                   class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer focus:outline-none"
                                   :style="`accent-color: hsl(${w[{{ $feature->id }}] * 1.2}, 85%, 45%)`">
                        </div>
                    @endforeach

                    </div>{{-- /space-y-5 --}}
                </div>{{-- /wire:ignore --}}
            </div>{{-- /sliders section --}}

        </div>

        <!-- Panel Footer -->
        <div class="px-4 py-3 sm:px-6 sm:py-4 border-t border-gray-100 bg-gray-50/50 shrink-0">
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
