@php
    $presetFaqs   = !empty($activePreset?->seo_content['faqs']) && is_array($activePreset->seo_content['faqs'])
                        ? $activePreset->seo_content['faqs']
                        : [];
    $categoryFaqs = !empty($category->buying_guide['faqs']) && is_array($category->buying_guide['faqs'])
                        ? $category->buying_guide['faqs']
                        : [];

    // Collect preset question strings for deduplication (case-insensitive trim).
    $presetQuestions = array_map(
        fn($f) => mb_strtolower(trim($f['question'] ?? '')),
        $presetFaqs,
    );

    // Keep only category FAQs whose question did not already appear in the preset set.
    $remainingCategoryFaqs = array_filter(
        $categoryFaqs,
        fn($f) => !in_array(mb_strtolower(trim($f['question'] ?? '')), $presetQuestions, true),
    );

    $allFaqs = array_values(array_merge($presetFaqs, $remainingCategoryFaqs));
@endphp

@if (!empty($allFaqs))
    <section class="mt-8 mb-8" aria-labelledby="faq-heading">
        <h2 id="faq-heading" class="text-xl sm:text-2xl font-bold text-gray-900 mb-4">
            Frequently Asked Questions
        </h2>
        <div class="space-y-2" x-data="{ openIndex: null }">
            @foreach ($allFaqs as $idx => $faq)
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <button
                        @click="openIndex = (openIndex === {{ $idx }} ? null : {{ $idx }})"
                        class="w-full flex items-center justify-between gap-3 px-4 py-3 text-left hover:bg-gray-50 transition-colors focus:outline-none focus:bg-gray-50"
                        :aria-expanded="openIndex === {{ $idx }} ? 'true' : 'false'"
                    >
                        <span class="text-sm sm:text-base font-medium text-gray-900">{{ $faq['question'] }}</span>
                        <svg
                            class="w-5 h-5 text-gray-500 shrink-0 transition-transform"
                            :class="openIndex === {{ $idx }} ? 'rotate-180' : ''"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div
                        x-show="openIndex === {{ $idx }}"
                        x-transition
                        class="px-4 pb-4 text-sm text-gray-700 leading-relaxed"
                        x-cloak
                    >
                        {!! $faq['answer'] !!}
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif
