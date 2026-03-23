# Spec 003: XSS Output Escaping in Blade Templates

**Priority:** CRITICAL
**Audit refs:** Security #5 (unescaped hero_headline), Security #6 (CSS injection via tenant colors), Security #20 (buying guide {!! !!})

---

## Problem

### 1. `home.blade.php:8` — XSS via `tenant('hero_headline')`
```blade
<h1>{!! tenant('hero_headline') ?? 'Compare with <span>Intelligence</span>' !!}</h1>
```
If a tenant admin sets `hero_headline` to `<script>alert('xss')</script>`, it executes in every visitor's browser.

### 2. Layout `<style>` block — CSS injection via tenant colors
Tenant color values (e.g., `tenant('color_primary')`) are interpolated directly into a `<style>` tag without sanitization. A malicious value like `red; } body { display:none } .x {` breaks the CSS context.

### 3. Buying guide rendered with `{!! !!}`
The `buying_guide` JSON contains HTML that's rendered unescaped. While this is admin-entered content, in a multi-tenant system the tenant admin is not fully trusted.

## Changes Required

### 1. Hero headline: Allow only `<span>` tags

**File:** `resources/views/livewire/home.blade.php` — line 8

```blade
{{-- BEFORE --}}
<h1>{!! tenant('hero_headline') ?? 'Compare with <span>Intelligence</span>' !!}</h1>

{{-- AFTER --}}
<h1>{!! strip_tags(tenant('hero_headline') ?? 'Compare with <span>Intelligence</span>', '<span><br><em><strong>') !!}</h1>
```

`strip_tags()` with an allowlist is simple and sufficient here — the headline needs `<span>` for styling but nothing else.

### 2. Tenant colors: Validate as hex/rgb only

**File:** `app/Models/Tenant.php` or wherever tenant colors are set/stored.

Add a mutator or validation rule that strips anything that isn't a valid CSS color value:

```php
public static function sanitizeColor(?string $value): string
{
    // Only allow hex colors (#fff, #ffffff), rgb(), hsl()
    if ($value && preg_match('/^(#[0-9a-fA-F]{3,8}|rgb\(\d{1,3},\s?\d{1,3},\s?\d{1,3}\)|hsl\(\d{1,3},\s?\d{1,3}%,\s?\d{1,3}%\))$/', $value)) {
        return $value;
    }
    return '#6366f1'; // fallback to default
}
```

In the layout Blade where colors are interpolated:
```blade
<style>
    :root {
        --color-primary: {{ \App\Models\Tenant::sanitizeColor(tenant('color_primary')) }};
    }
</style>
```

### 3. Buying guide: Sanitize HTML

Use `strip_tags()` with an allowlist of safe formatting tags, or better, use a library like `mews/purifier` (HTML Purifier) for robust sanitization:

```blade
{{-- BEFORE --}}
{!! $category->buying_guide['how_to_decide'] !!}

{{-- AFTER --}}
{!! clean($category->buying_guide['how_to_decide']) !!}
```

If adding a dependency is undesirable, use `strip_tags()` with `<p><br><ul><ol><li><strong><em><h3><h4><a>`.

## Files Modified

| File | Action |
|------|--------|
| `resources/views/livewire/home.blade.php` | `strip_tags()` on hero_headline |
| Layout Blade (e.g., `app.blade.php`) | Sanitize tenant color values |
| `app/Models/Tenant.php` | Add `sanitizeColor()` static method |
| Buying guide Blade partial | Sanitize HTML output |

## Testing

- **Unit:** `sanitizeColor()` rejects CSS injection payloads, accepts valid hex/rgb.
- **Feature:** Render home page with XSS payload in `hero_headline` → script tags stripped.
- **Feature:** Render layout with CSS injection in color → falls back to default color.
