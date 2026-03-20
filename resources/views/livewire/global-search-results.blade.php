{{--
  Inner content for the GlobalSearch results panel.
  The wrapping <div> (with positioning / shadow / x-show) lives in the parent blade.
  This partial just outputs the rows.
--}}

{{-- ── DB results ────────────────────────────────────────────────────────── --}}
@if(!empty($dbResults))
    @php $types = collect($dbResults)->groupBy('type'); @endphp

    {{-- Categories --}}
    @if($types->has('category'))
        <div class="px-4 pt-3 pb-1">
            <span class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Categories</span>
        </div>
        @foreach($types['category'] as $row)
            <a href="{{ $row['url'] }}"
               class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 transition-colors group">
                <div class="w-7 h-7 rounded-lg bg-blue-50 flex items-center justify-center shrink-0">
                    <svg class="w-3.5 h-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-800 group-hover:text-blue-600 transition-colors flex-1">
                    {{ $row['name'] }}
                </span>
                <svg class="w-4 h-4 text-gray-300 group-hover:text-blue-400 transition-colors shrink-0"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        @endforeach
    @endif

    {{-- Presets --}}
    @if($types->has('preset'))
        <div class="px-4 pt-3 pb-1 {{ $types->has('category') ? 'border-t border-gray-50' : '' }}">
            <span class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Presets</span>
        </div>
        @foreach($types['preset'] as $row)
            <a href="{{ $row['url'] }}"
               class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 transition-colors group">
                <div class="w-7 h-7 rounded-lg bg-amber-50 flex items-center justify-center shrink-0">
                    <svg class="w-3.5 h-3.5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-800 group-hover:text-amber-600 transition-colors">
                        {{ $row['name'] }}
                    </div>
                    <div class="text-xs text-gray-400 truncate">in {{ $row['category_name'] }}</div>
                </div>
                <svg class="w-4 h-4 text-gray-300 group-hover:text-amber-400 transition-colors shrink-0"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        @endforeach
    @endif

    {{-- Products --}}
    @if($types->has('product'))
        <div class="px-4 pt-3 pb-1 border-t border-gray-50">
            <span class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Products</span>
        </div>
        @foreach($types['product'] as $row)
            <a href="{{ $row['url'] }}"
               class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 transition-colors group">
                <div class="w-8 h-8 rounded-lg bg-gray-100 overflow-hidden shrink-0">
                    @if(!empty($row['image']))
                        <img src="{{ $row['image'] }}" alt="{{ $row['name'] }}"
                             class="w-full h-full object-contain">
                    @else
                        <div class="w-full h-full flex items-center justify-center">
                            <svg class="w-4 h-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                        </div>
                    @endif
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-800 group-hover:text-blue-600 truncate transition-colors">
                        {{ $row['name'] }}
                    </div>
                    @if(!empty($row['category_name']))
                        <div class="text-xs text-gray-400">in {{ $row['category_name'] }}</div>
                    @endif
                </div>
                <svg class="w-4 h-4 text-gray-300 group-hover:text-blue-400 transition-colors shrink-0"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        @endforeach
    @endif

{{-- ── AI searching: labor illusion ─────────────────────────────────────── --}}
@elseif($isAiSearching)
    <div class="px-5 py-5"
         x-data="{
             phrases: [
                 'Analyzing your request…',
                 'Scanning product categories…',
                 'Matching to your needs…',
                 'Calculating best fit…',
                 'Almost there…'
             ],
             idx: 0,
             init() { setInterval(() => this.idx = (this.idx + 1) % this.phrases.length, 2000) }
         }">
        <div class="flex items-center gap-3">
            <svg class="animate-spin h-5 w-5 text-blue-500 shrink-0" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <div>
                <p class="text-sm font-semibold text-gray-800" x-text="phrases[idx]"></p>
                <p class="text-xs text-gray-400 mt-0.5">AI is finding the best match for you</p>
            </div>
        </div>
        <div class="mt-4 flex gap-1.5">
            <div class="h-1 flex-1 rounded-full bg-blue-100 overflow-hidden">
                <div class="h-full bg-blue-500 rounded-full animate-pulse" style="width:60%"></div>
            </div>
            <div class="h-1 flex-1 rounded-full bg-blue-50 overflow-hidden">
                <div class="h-full bg-blue-300 rounded-full animate-pulse" style="width:40%; animation-delay:.3s"></div>
            </div>
            <div class="h-1 flex-1 rounded-full bg-gray-100 overflow-hidden">
                <div class="h-full bg-blue-200 rounded-full animate-pulse" style="width:25%; animation-delay:.6s"></div>
            </div>
        </div>
    </div>

{{-- ── AI suggestion: click to navigate ──────────────────────────────────── --}}
@elseif($aiSuggestion)
    <div class="p-4">
        <div class="flex items-center gap-2 mb-3">
            <span class="text-[10px] font-bold uppercase tracking-widest text-gray-400">AI Best Match</span>
            <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-600 text-[10px] font-semibold px-2 py-0.5 rounded-full">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                </svg>
                AI
            </span>
        </div>
        <a href="{{ $aiSuggestion['url'] }}"
           class="flex items-start gap-3.5 p-3.5 rounded-xl
                  bg-gradient-to-br from-blue-50 to-indigo-50
                  border border-blue-100 hover:border-blue-300 hover:shadow-md
                  transition-all duration-200 group">
            <div class="w-9 h-9 rounded-xl bg-white border border-blue-100 flex items-center justify-center shrink-0 shadow-sm">
                <span class="text-lg">🎯</span>
            </div>
            <div class="flex-1 min-w-0">
                <div class="font-semibold text-gray-900 text-sm leading-tight">
                    {{ $aiSuggestion['category_name'] }}
                    @if($aiSuggestion['preset_name'])
                        <span class="text-blue-600"> · {{ $aiSuggestion['preset_name'] }}</span>
                    @endif
                </div>
                @if($aiSuggestion['reasoning'])
                    <div class="text-xs text-gray-500 mt-1 leading-relaxed">{{ $aiSuggestion['reasoning'] }}</div>
                @endif
            </div>
            <svg class="w-5 h-5 text-blue-400 group-hover:text-blue-600 group-hover:translate-x-0.5 transition-all shrink-0 self-center"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    </div>

{{-- ── AI error ────────────────────────────────────────────────────────────── --}}
@elseif($aiError)
    <div class="px-4 py-5 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm text-gray-600">{{ $aiError }}</p>
    </div>

{{-- ── CTA: no DB results, AI not yet triggered ──────────────────────────── --}}
@else
    <div wire:click="triggerAiSearch"
         class="px-5 py-4 flex items-center gap-3 cursor-pointer hover:bg-gray-50 transition-colors">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center shrink-0">
            <svg class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm text-gray-700">
                No exact matches. Press
                <kbd class="inline-flex items-center px-1.5 py-0.5 bg-gray-100 border border-gray-200 rounded text-[10px] font-semibold text-gray-500 mx-0.5">Enter</kbd>
                to let AI find the best match for:
            </p>
            <p class="text-sm font-semibold text-blue-600 truncate mt-0.5">"{{ $query }}"</p>
        </div>
        <svg class="w-4 h-4 text-gray-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
    </div>
@endif
