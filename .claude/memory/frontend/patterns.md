# Frontend Patterns

## Spec 023 — Preset-Aware Content (2026-06-19)

### Intro block fallback chain (product-compare.blade.php ~line 92)
Use `@php` to resolve content before rendering — avoids nested `@if`/`@else` for a
priority-based fallback:

```blade
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
```

`{!! !!}` is acceptable when the source is AI-generated `<p>` markup only (not user input).

### Passing variables into @include explicitly
Even though Blade `@include` inherits parent scope, pass Livewire computed properties
explicitly for clarity and to make the contract visible:

```blade
@include('livewire.partials.compare-faqs', ['activePreset' => $activePreset])
```

### FAQ deduplication pattern (compare-faqs.blade.php)
When merging two FAQ arrays, deduplicate by lowercased/trimmed question string in `@php`:

```php
$presetQuestions = array_map(
    fn($f) => mb_strtolower(trim($f['question'] ?? '')),
    $presetFaqs,
);
$remainingCategoryFaqs = array_filter(
    $categoryFaqs,
    fn($f) => !in_array(mb_strtolower(trim($f['question'] ?? '')), $presetQuestions, true),
);
$allFaqs = array_values(array_merge($presetFaqs, $remainingCategoryFaqs));
```

Use `array_values()` after `array_filter()` to re-index before looping with numeric `$idx`
(Alpine.js openIndex comparison requires contiguous integers).

### Graceful degradation rule
Guard every nullable access with `!empty()` + `is_array()` checks before use. Never rely
on `??` alone for array-typed JSON columns — the column might exist as `null` or an empty
string rather than an absent key.

## Spec 025 — Above-Fold UX: content reorder + backdrop + session auto-open (2026-06-19)

### Content relocation pattern
When deep SEO content must move below the product grid, extract it verbatim into a partial
and `@include` it after the grid rather than rewriting it. This preserves all Alpine bindings
and `@php` blocks without risk of regression:

```blade
{{-- product-compare.blade.php — after the grid --}}
@include('livewire.partials.compare-content', ['category' => $category, 'activePreset' => $activePreset])
@include('livewire.partials.compare-faqs', ['activePreset' => $activePreset])
```

Partial lives at: `resources/views/livewire/partials/compare-content.blade.php`
Receives: `$category`, `$activePreset` (nullable — all blocks are guarded with `@if`/`!empty`).

### Backdrop transparency rule
When a side drawer must not obscure interactive content behind it, use a near-transparent
click-catcher rather than a blur/dim overlay:

```html
<!-- Before: bg-gray-900/40 backdrop-blur-[2px] — hides the grid -->
<!-- After:  bg-gray-900/10                      — click-catcher only -->
<div class="fixed inset-0 z-70 bg-gray-900/10" @click="showPreferences = false"></div>
```

Do NOT lock background scroll (`overflow-hidden` on body) when the intent is for the user
to see the page re-render behind the drawer.

### Session-gated auto-open pattern (Alpine x-init)
Use `sessionStorage` (not `localStorage`) for auto-open behaviour that should repeat on
each new browser session. Define the key as a named constant with an escalation comment:

```js
// per-session. To escalate: append ':' + the category slug for per-category, or remove the guard for every page.
const AUTO_OPEN_KEY = 'app_customize_autoopen';
if (!sessionStorage.getItem(AUTO_OPEN_KEY) && @js($autoOpen)) {
    sessionStorage.setItem(AUTO_OPEN_KEY, '1');
    setTimeout(() => {
        window.dispatchEvent(new CustomEvent('app-open-sidebar'));
        if (typeof posthog !== 'undefined') posthog.capture('customize_modal_autoopened', { category: '...' });
    }, 1500);
} else {
    teaser = true;
    setTimeout(() => teaser = false, 3500);
}
```

- `@js($autoOpen)` wires the Livewire prop (`public bool $autoOpen = true` in the component)
  so the drawer never auto-pops over an open product detail modal.
- Always guard `posthog` calls with `typeof posthog !== 'undefined'`.
- 1500ms delay: enough to clear first paint without feeling sluggish.

## Spec 027 — "Best X" Landing Pages (2026-07-18)

Built `resources/views/landing/show.blade.php` + partials (`_pick-card.blade.php`,
`_methodology.blade.php`, `_faqs.blade.php`) — a plain (non-Livewire) Blade page. Reusable
patterns for other future plain-controller pages:

### Passing a $seo array into `<x-layouts.app>` from a non-Livewire view
`components/layouts/app.blade.php` reads `$metaTitle`, `$metaDescription`, `$canonicalUrl`,
`$ogType`, `$ogImage`, `$schemasJson` as plain variables (no `@props` declared). Livewire
full-page components inject these via `->layoutData([...])`; a plain Blade view passes them
as component attributes instead — same variable names, same effect:

```blade
<x-layouts.app
    :metaTitle="$seo['title'] ?? null"
    :metaDescription="$seo['description'] ?? null"
    :canonicalUrl="$seo['canonical'] ?? null"
    :ogType="$seo['ogType'] ?? 'article'"
    :ogImage="$seo['ogImage'] ?? null"
    :schemasJson="collect($seo['schemas'] ?? [])->map(fn ($s) => json_encode($s))->all()"
>
    ...
</x-layouts.app>
```

### `use` imports are NOT valid inside `@php` blocks
`@php ... @endphp` compiles into inline PHP inside a method body (the compiled view is a
function), so a top-level `use Illuminate\Support\Str;` statement inside it is a fatal error
("not allowed at this location"). Always fully-qualify (`\Illuminate\Support\Str::slug(...)`)
inside `@php` blocks instead of importing. Verified by compiling every new/edited Blade file
through `Illuminate\View\Compilers\BladeCompiler::compileString()` + `php -l` before calling
the task done — cheap way to catch Blade/PHP syntax errors without booting a full page.

### Native `<details>`/`<summary>` FAQ disclosure (no Alpine)
For a page that's explicitly meant to skip the Livewire/JS payload (fastest page type on the
site), prefer native `<details>` over the Alpine `x-data="{ openIndex }"` pattern used in
`compare-faqs.blade.php`. Tailwind v4's `open:` / `group-open:` variants style the chevron
rotation with zero JS:

```blade
<details class="group ... [&_summary::-webkit-details-marker]:hidden">
    <summary class="cursor-pointer ...">
        {{ $faq['question'] }}
        <svg class="transition-transform group-open:rotate-180">...</svg>
    </summary>
    <div class="px-4 pb-4 ...">{!! $faq['answer'] ?? '' !!}</div>
</details>
```

### Deriving a display label from a compact role string
`picks[].role` is one of `overall|budget|premium|preset:{slug}` — no human-readable preset
name attached. Look up the category's presets **once**, outside the picks loop (not
per-pick — avoids N+1), to build a `slug => name` map, then fall back to `Str::headline()`
of the slug if the preset was since renamed/deleted:

```php
$presetNameBySlug = $category->presets
    ->mapWithKeys(fn ($p) => [\Illuminate\Support\Str::slug($p->name) => $p->name]);
// per pick: $presetNameBySlug[$presetSlug] ?? \Illuminate\Support\Str::headline(str_replace('-', ' ', $presetSlug))
```

### Defensive read of a maybe-attached dynamic Eloquent attribute
`ProductScoringService` attaches `$product->feature_scores` (array, feature_id => 0-100) as
a *runtime-only* Eloquent attribute (via `__set`/`setAttribute`, not a real DB column) when
Livewire's compare page scores products. A plain-controller page (like landing pages) may or
may not run that service before rendering. Read it defensively rather than assuming either
way, so the view degrades gracefully instead of erroring:

```php
$featureScores = is_array($product->feature_scores ?? null) ? $product->feature_scores : null;
// use $featureScores to rank/label highlights when present; fall back to raw featureValues
// (name + raw_value + unit, no numeric score) when it's null.
```

## Addendum A (Spec 027 owner QA, 2026-08-01) — `bg-tenant-*`/`text-tenant-*` were dead classes sitewide

**Scope correction:** the spec text conflated two things — the owner's actual complaint was
the empty/invisible role-badge pill (below), NOT the product image well. The `bg-white`
image-container markup in `_pick-card.blade.php` was left unchanged (no `bg-gray-50`/padding
edit shipped) — don't reintroduce that change without an explicit owner ask.

**Root cause of the "empty white badge pill" bug:** this project runs **Tailwind v4**
(`@tailwindcss/vite`), which is CSS-first and does **not** load `tailwind.config.js` unless
a `@config "..."` directive is added to the entry CSS. `tailwind.config.js`'s
`theme.extend.colors.tenant` block (defining `tenant-primary`/`tenant-secondary`/`tenant-text`)
was therefore dead code — confirmed by grepping the compiled
`public/build/assets/app-*.css` for `tenant-` and finding **zero matches**, even though 6
Blade files across the codebase (nav, comparison-header, compare-content, landing partials)
already used `bg-tenant-primary`/`text-tenant-primary`/etc. Any element using
`bg-tenant-primary text-white` silently got no background rule at all → white text on a
white/transparent pill → invisible. `budget`/`premium`/preset badges (`bg-emerald-600`,
`bg-purple-600`, `bg-sky-600`) were unaffected because those are Tailwind v4's built-in
default palette, not custom tokens.

**Fix (root cause, not a per-page workaround):** register the tenant tokens in Tailwind v4's
CSS-native `@theme` block in `resources/css/app.css`, pointing them at the *existing* runtime
CSS custom properties already set per-tenant in `components/layouts/app.blade.php`'s `<head>`
inline `<style>` (`--color-primary`/`--color-secondary`/`--color-text` on `:root`):

```css
@theme {
    --color-tenant-primary: var(--color-primary);
    --color-tenant-secondary: var(--color-secondary);
    --color-tenant-text: var(--color-text);
}
```

This must live under the `--color-*` namespace (not any other prefix) for Tailwind v4 to
generate the full utility set (`bg-`, `text-`, `border-`, plus **opacity modifiers** like
`bg-tenant-secondary/50` via `color-mix()`) for the token. Declaration order relative to the
tenant `<style>` block in `<head>` doesn't matter — `var()` resolves lazily at paint time, not
at CSS-parse time — so it's fine that the compiled stylesheet loads *after* the inline tenant
palette in the document.

**Lesson:** when `tailwind.config.js` exists in a v4 project, don't assume it's live —
grep the actual compiled CSS for a suspect class before debugging further upstream (Blade
logic, tenant data, etc). `grep -c "tenant-primary" public/build/assets/app-*.css` is a
5-second sanity check that would have caught this immediately.

### Bug fix (2026-08-09) — duplicate "Best Overall" badges on /best/ pages
`SelectLandingPagePicks` reuses `role: 'overall'` for BOTH the #1 pick AND every trailing
fill-in pick beyond the named roles (see its own docblock — this is documented, intentional
behavior in the Action, not a bug there). The view's `match(true)` role→label mapping treated
every `'overall'` role identically, so two (or more) different products on one page could both
show a "Best Overall" pill. Fixed with a seen-flag tracked across the `@foreach` in
`show.blade.php` (NOT the pick index/rank — rank 1 happens to always be role `'overall'` per
the Action, but the fix must not assume that positional coincidence):

```php
@php
    $overallSeen = false; // set once, before the loop
@endphp
@foreach ($picks as $pick)
    @php
        $roleLabel = match (true) {
            $roleKey === 'overall' && !$overallSeen => 'Best Overall',
            $roleKey === 'overall' => 'Also Great',
            // ...other roles unchanged
        };
        if ($roleKey === 'overall') { $overallSeen = true; }
    @endphp
@endforeach
```
`_pick-card.blade.php` needed no change — it only renders `$roleLabel` verbatim, already
computed by the caller. `SeoSchema::forLandingPage()` needed no change either — confirmed by
grep that it consumes only `$picks->pluck('product')` (names/URLs/ratings), never `role` or
any role label, so JSON-LD was never affected by this bug.

### Sitewide fixed "Amazon orange" CTA is intentional, not a tenant-color violation
The `bg-[#FF9900]` / `hover:bg-[#E68A00]` "Check Current Price →" button (from
`product-compare.blade.php`'s grid card) is a deliberate, sitewide, tenant-independent
convention (`.amazon-cta` class in `app.css`) — mirrors Amazon's own buy-button color for
trust/conversion, and is reused verbatim (not tenant-token-ized) on every new product CTA,
including the landing-page pick cards. Don't "fix" this to `bg-tenant-primary` — it's
correct as-is.
