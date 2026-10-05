{{--
    compared-with.blade.php (Spec 043 Phase 1)

    "Compared with" block on a product page: the 2-3 closest rivals (SelectRivals),
    each with our score, estimated price and a link, plus "Read the comparison"
    when a published VS page exists for the pair (either order).
    Renders nothing when there are no rivals.

    Props: $product (Product with category.features + featureValues loaded)
--}}
@props(['product'])

@php
    $rivals = app(\App\Actions\SelectRivals::class)->handle($product);
    $rivalIds = $rivals->pluck('id')->all();

    // One query for every pair, either order.
    $vsSlugByRival = [];
    if ($rivalIds !== []) {
        \App\Models\VsPage::where('status', 'published')
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('product_a_id', $product->id)->whereIn('product_b_id', $rivalIds))
                ->orWhere(fn ($p) => $p->where('product_b_id', $product->id)->whereIn('product_a_id', $rivalIds)))
            ->get(['slug', 'product_a_id', 'product_b_id'])
            ->each(function ($vs) use ($product, &$vsSlugByRival) {
                $other = $vs->product_a_id === $product->id ? $vs->product_b_id : $vs->product_a_id;
                $vsSlugByRival[$other] = $vs->slug;
            });
    }

    $brandName = tenant('brand_name') ?: 'Pw2D';
@endphp

@if ($rivals->isNotEmpty())
    <section class="border-t border-gray-100 pt-6 mt-2 px-4 md:px-8 pb-6" aria-labelledby="compared-with-heading">
        <h2 id="compared-with-heading" class="text-sm font-bold text-gray-900 uppercase tracking-widest mb-4">Compared with</h2>

        <ul class="divide-y divide-gray-100 bg-white rounded-2xl border border-gray-100">
            @foreach ($rivals as $rival)
                @php $score = $rival->editorialScore(); @endphp
                <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3">
                    <div class="min-w-0">
                        <a href="{{ route('product.show', ['product' => $rival->slug]) }}"
                           class="text-sm font-semibold text-gray-900 hover:text-tenant-primary transition-colors">{{ $rival->comparisonName() }}</a>
                        <p class="text-xs text-gray-500">
                            @if ($score !== null)
                                {{ $brandName }} score {{ number_format($score, 1) }} / 10
                            @endif
                            @if ($score !== null && $rival->estimated_price !== null)
                                &middot;
                            @endif
                            @if ($rival->estimated_price !== null)
                                ~${{ number_format($rival->estimated_price) }}
                            @endif
                        </p>
                    </div>
                    @if (isset($vsSlugByRival[$rival->id]))
                        <a href="{{ route('vs.show', ['slug' => $vsSlugByRival[$rival->id]]) }}"
                           class="text-xs font-bold text-tenant-primary hover:underline">Read the comparison &rarr;</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
