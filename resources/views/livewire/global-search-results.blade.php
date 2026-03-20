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
            <svg class="animate-spin h-5 w-5 text-indigo-500 shrink-0" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <div>
                <p class="text-sm font-semibold text-slate-800" x-text="phrases[idx]"></p>
                <p class="text-xs text-slate-400 mt-0.5">AI is finding the best match for you</p>
            </div>
        </div>
        <div class="mt-4 flex gap-1.5">
            <div class="h-1 flex-1 rounded-full bg-indigo-100 overflow-hidden">
                <div class="h-full bg-indigo-500 rounded-full animate-pulse" style="width:60%"></div>
            </div>
            <div class="h-1 flex-1 rounded-full bg-indigo-50 overflow-hidden">
                <div class="h-full bg-indigo-300 rounded-full animate-pulse" style="width:40%; animation-delay:.3s"></div>
            </div>
            <div class="h-1 flex-1 rounded-full bg-slate-100 overflow-hidden">
                <div class="h-full bg-indigo-200 rounded-full animate-pulse" style="width:25%; animation-delay:.6s"></div>
            </div>
        </div>
    </div>

{{-- ── AI suggestion: click to navigate ──────────────────────────────────── --}}
@elseif($aiSuggestion)
    <div class="px-4 pt-2 pb-1">
        <span class="text-[10px] font-bold uppercase tracking-widest text-gray-400">AI Match</span>
    </div>
    <a href="{{ $aiSuggestion['url'] }}"
       class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 transition-colors group">
        <span class="text-base">✨</span>
        <span class="text-sm font-medium text-gray-800 group-hover:text-indigo-600 transition-colors flex-1 truncate">
            {{ $aiSuggestion['category_name'] }}
            @if(!empty($aiSuggestion['preset_name']))
                <span class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-600">{{ $aiSuggestion['preset_name'] }}</span>
            @endif
        </span>
        <svg class="w-4 h-4 text-gray-300 group-hover:text-indigo-400 transition-colors shrink-0"
             fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
    </a>

{{-- ── AI error ────────────────────────────────────────────────────────────── --}}
@elseif($aiError)
    <div class="px-5 py-4 flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-red-50 flex items-center justify-center shrink-0">
            <svg class="w-4.5 h-4.5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <p class="text-sm text-slate-600">{{ $aiError }}</p>
    </div>

{{-- ── CTA: no DB results, AI not yet triggered ──────────────────────────── --}}
@else
    <div wire:click="triggerAiSearch"
         class="group flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-slate-50 transition-colors">
        <span class="text-base">✨</span>
        <span class="text-sm text-slate-500">No exact matches. Press
            <kbd class="px-1.5 py-0.5 text-[11px] font-medium text-slate-500 bg-slate-100 border border-slate-200 rounded">Enter</kbd>
            for AI search</span>
    </div>
@endif
