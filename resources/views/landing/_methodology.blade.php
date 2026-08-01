{{--
    _methodology.blade.php

    "How we ranked this" static methodology block, optionally extended with
    $page->methodology_note (AI one-liner).

    Expected variables: $page (LandingPage), $category (Category)
--}}
<section class="bg-gradient-to-br from-amber-50 to-orange-50/50 border border-amber-100 rounded-2xl p-5 md:p-6 mt-10" aria-labelledby="methodology-heading">
    <div class="flex items-start gap-3">
        <div class="shrink-0 w-9 h-9 rounded-full bg-amber-100 flex items-center justify-center" aria-hidden="true">
            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>
        <div class="flex-1">
            <h2 id="methodology-heading" class="text-base md:text-lg font-bold text-gray-900 mb-2">How We Ranked This</h2>
            <div class="text-sm text-gray-700 leading-relaxed space-y-2">
                <p>
                    Every product on this list was scored on a 0&ndash;100 scale across the features that matter
                    most for this category &mdash; things like performance, durability, and value &mdash; each
                    weighted by importance. Rankings are pulled directly from that data, not editorial opinion:
                    the AI only writes the explanations, the picks themselves come from the numbers.
                </p>

                @if (!empty($page->methodology_note))
                    {{-- methodology_note is prompt-prescribed plain text (validated as a
                         string, not HTML, by AiService::generateLandingPageContent) —
                         render escaped, not raw. --}}
                    <p>{{ $page->methodology_note }}</p>
                @endif

                <p>
                    Want to weight the features yourself &mdash; prioritize battery life over price, or vice
                    versa? Use our
                    <a href="{{ route('category.show', ['slug' => $category->slug]) }}"
                       class="font-semibold text-tenant-primary hover:underline">
                        interactive comparison tool
                    </a>
                    to build a personalized ranking.
                </p>
            </div>
        </div>
    </div>
</section>
