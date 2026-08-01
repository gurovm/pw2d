{{--
    _faqs.blade.php

    Accessible FAQ disclosure using native <details>/<summary> — no Alpine/JS
    needed for a page that's meant to render server-side only.

    Expected variables: $page (LandingPage, ->faqs array of ['question','answer'])
--}}
@if (!empty($page->faqs))
    <section class="mt-10 mb-10" aria-labelledby="landing-faq-heading">
        <h2 id="landing-faq-heading" class="text-xl sm:text-2xl font-bold text-gray-900 mb-4">
            Frequently Asked Questions
        </h2>
        <div class="space-y-2">
            @foreach ($page->faqs as $faq)
                @continue(empty($faq['question']))
                <details class="group bg-white border border-gray-200 rounded-xl overflow-hidden [&_summary::-webkit-details-marker]:hidden">
                    <summary class="flex items-center justify-between gap-3 px-4 py-3 cursor-pointer select-none hover:bg-gray-50 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-tenant-primary">
                        <span class="text-sm sm:text-base font-medium text-gray-900">{{ $faq['question'] }}</span>
                        <svg class="w-5 h-5 text-gray-500 shrink-0 transition-transform duration-200 group-open:rotate-180"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </summary>
                    <div class="px-4 pb-4 text-sm text-gray-700 leading-relaxed">
                        {!! sanitize_ai_html($faq['answer'] ?? '') !!}
                    </div>
                </details>
            @endforeach
        </div>
    </section>
@endif
