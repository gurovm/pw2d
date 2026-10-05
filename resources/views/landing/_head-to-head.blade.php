{{--
    _head-to-head.blade.php (Spec 043)

    Links to the published "A vs B" pages of this category. Renders nothing when there are none.
    Expected variables: $vsPages (Collection of VsPage with productA/productB loaded)
--}}
@if (!empty($vsPages) && $vsPages->isNotEmpty())
    <section class="mt-10" aria-labelledby="landing-h2h-heading">
        <h2 id="landing-h2h-heading" class="text-xl sm:text-2xl font-bold text-gray-900 mb-4">Head-to-head</h2>
        <ul class="space-y-2">
            @foreach ($vsPages as $vs)
                <li>
                    <a href="{{ route('vs.show', ['slug' => $vs->slug]) }}"
                       class="block bg-white border border-gray-200 rounded-xl px-4 py-3 text-sm sm:text-base font-medium text-gray-900 hover:text-tenant-primary hover:border-tenant-primary transition-colors">
                        {{ $vs->productA->name }} vs {{ $vs->productB->name }} &rarr;
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
