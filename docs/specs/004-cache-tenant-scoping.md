# Spec 004: Tenant-Scoped Cache Keys

**Priority:** CRITICAL
**Audit refs:** Reviewer #3 / Performance #1 (Setting cache), Performance #1 (ProductCompare, SimilarProducts)

---

## Problem

Three cache call sites produce keys without tenant context. In production with multiple tenant domains, `Cache::remember('setting:gemini_model', ...)` serves Tenant A's setting to Tenant B.

### Affected locations:

1. **`app/Models/Setting.php:19`** — `"setting:{$key}"` cached forever
2. **`app/Livewire/ProductCompare.php:112`** — `"products:cat{id}:b{brand}:p{price}"`
3. **`app/View/Components/SimilarProducts.php:20`** — `"similar_products_{id}"`

## Solution

Create a tiny helper that prefixes all cache keys with the current tenant ID. When no tenant is active (central context), use `central` as prefix.

### 1. Helper function

**File:** `app/Helpers/cache.php` (new) or add to existing helpers

```php
function tenant_cache_key(string $key): string
{
    $tenantId = tenant('id') ?? 'central';
    return "t{$tenantId}:{$key}";
}
```

Register in `composer.json` autoload if using a helpers file. Alternatively, use a one-liner inline.

### 2. Apply to Setting model

**File:** `app/Models/Setting.php`

```php
public static function get(string $key, $default = null)
{
    $cacheKey = tenant_cache_key("setting:{$key}");
    return Cache::rememberForever($cacheKey, function () use ($key, $default) {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    });
}

public static function set(string $key, $value)
{
    Cache::forget(tenant_cache_key("setting:{$key}"));
    return static::updateOrCreate(['key' => $key], ['value' => $value]);
}
```

### 3. Apply to ProductCompare

**File:** `app/Livewire/ProductCompare.php` — line 112

```php
// BEFORE
$cacheKey = "products:cat{$this->category->id}:b{$this->filterBrand}:p{$this->selectedPrice}";

// AFTER
$cacheKey = tenant_cache_key("products:cat{$this->category->id}:b{$this->filterBrand}:p{$this->selectedPrice}");
```

### 4. Apply to SimilarProducts

**File:** `app/View/Components/SimilarProducts.php` — line 19-20

```php
// BEFORE
'similar_products_' . $product->id,

// AFTER
tenant_cache_key('similar_products_' . $product->id),
```

## Files Modified/Created

| File | Action |
|------|--------|
| `app/Helpers/cache.php` | **Create** — `tenant_cache_key()` helper |
| `composer.json` | Add helpers autoload (if not already present) |
| `app/Models/Setting.php` | Prefix cache keys |
| `app/Livewire/ProductCompare.php` | Prefix cache key |
| `app/View/Components/SimilarProducts.php` | Prefix cache key |

## Testing

- **Unit:** `tenant_cache_key('foo')` returns `'tcentral:foo'` when no tenant is active.
- **Unit:** `tenant_cache_key('foo')` returns `'t1:foo'` when tenant 1 is active.
- **Feature:** Two tenants with different settings return correct values (not cross-pollinated).
