{{--
    compare-content.blade.php
    Deep SEO content relocated below the product grid (Spec 025, Change 1).
    Variables required: $category, $activePreset
--}}

@php
    $introContent = !empty($activePreset?->seo_content['intro'])
        ? $activePreset->seo_content['intro']
        : ($category->buying_guide['intro'] ?? null);
@endphp
@if (!empty($introContent))
    <div class="prose prose-sm max-w-none mb-4 text-gray-700 leading-relaxed">
        {!! $introContent !!}
    </div>
@endif

@if ($category->buying_guide && is_array($category->buying_guide))
        @php
                $sections = [
                    'how_to_decide' => [
                        'title' => 'How to Decide',
                        'icon' =>
                            '<svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>',
                        'activeColor' => 'text-yellow-500',
                        'content' =>
                            $category->buying_guide['how_to_decide'] ??
                            '',
                    ],
                    'the_pitfalls' => [
                        'title' => 'The Pitfalls',
                        'icon' =>
                            '<svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
                        'activeColor' => 'text-rose-500',
                        'content' =>
                            $category->buying_guide['the_pitfalls'] ??
                            '',
                    ],
                    'key_jargon' => [
                        'title' => 'Key Jargon',
                        'icon' =>
                            '<svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>',
                        'activeColor' => 'text-indigo-500',
                        'content' =>
                            $category->buying_guide['key_jargon'] ?? '',
                    ],
                ];

                $defaultTab = 'how_to_decide';
                if (empty($sections[$defaultTab]['content'])) {
                    foreach ($sections as $k => $section) {
                        if (!empty($section['content'])) {
                            $defaultTab = $k;
                            break;
                        }
                    }
                }
        @endphp
        <div x-data="{ expanded: false, activeTab: '{{ $defaultTab }}' }">
                <div
                        class="bg-gradient-to-br from-slate-50 via-white to-indigo-50/30 rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                        <div
                                class="flex items-center gap-1 px-2 sm:px-4 pt-2 sm:pt-3 pb-1.5 border-b border-gray-100/80 bg-white/60">
                                <div
                                        class="flex space-x-0.5 overflow-x-auto scrollbar-hide w-full pb-1">
                                        @foreach ($sections as $key => $data)
                                                @if (!empty($data['content']))
                                                        <button @click="activeTab = '{{ $key }}'; expanded = true"
                                                                class="whitespace-nowrap flex items-center gap-1 px-2 py-1 sm:gap-1.5 sm:px-3 sm:py-1.5 text-[12px] sm:text-[14px] transition-all focus:outline-none shrink-0 rounded-lg hover:bg-gray-100/60 cursor-pointer"
                                                                :class="activeTab === '{{ $key }}'
                                                                    ?
                                                                    'text-gray-900 font-bold bg-gray-50/50' :
                                                                    'text-gray-500 font-semibold hover:text-gray-800'">
                                                                <span
                                                                        class="hidden sm:inline"
                                                                        :class="activeTab === '{{ $key }}' ? '{{ $data['activeColor'] }}' : 'text-gray-400'">
                                                                        {!! $data['icon'] !!}
                                                                </span>
                                                                {{ $data['title'] }}
                                                        </button>
                                                @endif
                                        @endforeach
                                </div>
                        </div>

                        <div class="relative overflow-hidden"
                                :style="expanded ? 'max-height: 2000px' :
                                    'max-height: 5.5rem'"
                                style="max-height: 5.5rem; transition: max-height 0.5s ease-in-out;">

                                @foreach ($sections as $key => $data)
                                        @if (!empty($data['content']))
                                                <div x-show="activeTab === '{{ $key }}'"
                                                        x-transition:enter="transition ease-out duration-300 transform"
                                                        x-transition:enter-start="opacity-0 translate-y-2"
                                                        x-transition:enter-end="opacity-100 translate-y-0"
                                                        style="display: none;"
                                                        class="px-4 py-4 prose prose-sm max-w-none text-gray-600
                                    [&_ul]:space-y-2 [&_ul]:my-3 [&_li]:text-[12px] sm:[&_li]:text-[13px] [&_li]:text-gray-600 [&_li]:leading-relaxed [&_li]:ml-4 [&_li]:list-disc [&_li]:pl-1
                                    [&_p]:text-[12px] sm:[&_p]:text-[13px] [&_p]:leading-relaxed [&_p]:text-gray-600 [&_p]:mb-3 [&_p]:last:mb-0
                                    [&_strong]:text-gray-900 [&_strong]:font-semibold">
                                                        {!! preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', '', strip_tags($data['content'], '<p><br><ul><ol><li><strong><em><h3><h4><a>')) !!}
                                                </div>
                                        @endif
                                @endforeach
                        </div>

                        <div class="relative">
                                <div x-show="!expanded"
                                        class="absolute -top-10 left-0 right-0 h-10 bg-gradient-to-t from-white via-white/80 to-transparent pointer-events-none transition-opacity duration-300">
                                </div>
                                <div
                                        class="px-4 pb-3 pt-1.5 border-t border-gray-100/50 flex justify-between items-center bg-white/50 backdrop-blur-sm">
                                        <button @click="expanded = !expanded"
                                                class="text-xs font-bold text-indigo-600 hover:text-indigo-800 transition-colors flex items-center gap-1.5 group select-none cursor-pointer">
                                                <span
                                                        x-text="expanded ? 'Show less' : 'Read full guide'"></span>
                                                <svg class="w-3.5 h-3.5 transition-transform duration-300"
                                                        :class="expanded ?
                                                            'rotate-180' :
                                                            'group-hover:translate-y-0.5'"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2.5"
                                                                d="M19 9l-7 7-7-7">
                                                        </path>
                                                </svg>
                                        </button>
                                </div>
                        </div>
                </div>
        </div>

        <style>
                .scrollbar-hide::-webkit-scrollbar {
                        display: none;
                }

                .scrollbar-hide {
                        -ms-overflow-style: none;
                        scrollbar-width: none;
                }
        </style>
@endif

@if (!empty($category->buying_guide['methodology']))
    <div class="bg-gradient-to-br from-amber-50 to-orange-50/50 border border-amber-100 rounded-2xl p-4 mt-4">
        <div class="flex items-start gap-3">
            <div class="shrink-0 w-8 h-8 rounded-full bg-amber-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-1">How We Rank</h3>
                <div class="text-sm text-gray-700 leading-relaxed">
                    {!! $category->buying_guide['methodology'] !!}
                </div>
            </div>
        </div>
    </div>
@endif
