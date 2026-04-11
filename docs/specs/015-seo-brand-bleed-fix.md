# Spec 015: SEO Brand-Bleed Fix (Phase 1)

## Goal

Stop every non-pw2d tenant from being indexed as "pw2d". Make all `<head>` metadata, `og:*` tags, `twitter:*` tags, `robots.txt`, and structured-data JSON-LD **tenant-aware**, using values from `tenants.data` JSON instead of hardcoded `pw2d` strings.

Fixes all three HIGH findings from [docs/seo/audit-2026-04-08.md](../seo/audit-2026-04-08.md):

- **H1** — Hardcoded `pw2d` in titles + `og:site_name` on every tenant
- **H2** — `public/robots.txt` points all tenants at `pw2d.com/sitemap.xml`
- **H3** — `Home.php` sets no meta, falls through to pw2d defaults

Plus minor improvements (M2, M3, M5) that share the same code paths.

## Non-Goals

- **Not** building analytics / monitoring — that's [spec 014](./014-seo-monitoring-integration.md).
- **Not** installing new Composer packages.
- **Not** migrating any tables — all new config lives in the existing `tenants.data` JSON.
- **Not** fixing the `pw2d.com` central-vs-tenant resolution mismatch (see "Related Config Note" at the bottom — flagged for a separate ticket).

## Current State Summary

Three files contain the bleed:

1. [app/Support/SeoSchema.php](../../app/Support/SeoSchema.php) — hardcoded `| pw2d` suffix in three scenario builders (lines 126, 145, 166)
2. [resources/views/components/layouts/app.blade.php](../../resources/views/components/layouts/app.blade.php) — hardcoded title/description defaults (line 9–11, 15–17), hardcoded `og:site_name` (line 24), hardcoded `asset('images/logo.webp')` fallback (line 19)
3. [app/Livewire/Home.php](../../app/Livewire/Home.php) — `render()` passes no meta variables at all

Plus:
- [public/robots.txt](../../public/robots.txt) — static file, same contents on every domain
- [resources/views/sitemap.blade.php](../../resources/views/sitemap.blade.php) — missing `/about`, `/contact`, `/privacy-policy`, `/terms-of-service`

## Design

### Tenant configuration

Four new keys added to `tenants.data` JSON (no migration — stancl/tenancy stores arbitrary data via `VirtualColumn`):

| Key | Type | Required | Default fallback | Used by |
|---|---|---|---|---|
| `seo_title_suffix` | string | No | `brand_name` value | Suffix appended to category/preset titles (replaces hardcoded `\| pw2d`) |
| `seo_default_title` | string | No | `"{$brand_name} — AI Product Recommendations"` | Homepage `<title>` and fallback |
| `seo_default_description` | string | No | Empty → use a generic recommendation string with brand inserted | Homepage meta description + fallback |
| `seo_default_image` | string (URL or storage path) | No | `tenant('logo')` → `asset('images/logo.webp')` | Fallback `og:image` when no product image is available |

`brand_name` already exists and is already edited via [TenantResource.php:69](../../app/Filament/Resources/TenantResource.php#L69). The four new keys will be added to the same Filament form in a new `SEO` section.

### Flow

```
┌─────────────────┐
│ tenants.data    │ (brand_name, seo_title_suffix, seo_default_title, ...)
└────────┬────────┘
         │ tenant('key')
         ▼
┌────────────────────────────────────────────────────────┐
│ SeoSchema (new static: tenant-aware defaults())         │
│   forHomepage()        ← NEW                            │
│   forParentCategory()  ← uses tenant suffix             │
│   forLeafCategory()    ← uses tenant suffix             │
│   forSelectedProduct() ← uses tenant suffix             │
└────────┬───────────────────────────────────────────────┘
         │
         ▼
┌────────────────────────────────────────────────────────┐
│ app.blade.php (<head>)                                  │
│   $metaTitle ?? tenant_seo('default_title')             │
│   $metaDescription ?? tenant_seo('default_description') │
│   og:site_name = tenant('brand_name') ?? 'Pw2D'         │
│   $defaultImage = $ogImage ?? tenant_seo('default_img') │
└────────────────────────────────────────────────────────┘
```

### Helper function

Add one helper to the existing `app/helpers.php` (or wherever `tenant_cache_key()` lives):

```php
/**
 * Read a tenant SEO key with a computed fallback.
 *
 * Fallback chain:
 *   1. tenants.data[seo_<key>]        (explicit tenant value)
 *   2. computed default using tenant('brand_name')
 *   3. global fallback (empty string or logo asset)
 */
function tenant_seo(string $key): ?string
{
    $brand = tenant('brand_name') ?? 'Pw2D';

    return match ($key) {
        'title_suffix'        => tenant('seo_title_suffix') ?? $brand,
        'default_title'       => tenant('seo_default_title') ?? "{$brand} — AI Product Recommendations",
        'default_description' => tenant('seo_default_description')
            ?? "Discover the best products tailored to your exact needs using {$brand}'s AI-powered recommendation engine.",
        'default_image'       => tenant('seo_default_image') ?? tenant('logo') ?? asset('images/logo.webp'),
        default               => null,
    };
}
```

Rationale: keeps fallback logic in one place. `SeoSchema` and `app.blade.php` both call it. Matches the existing `tenant_cache_key()` helper pattern.

**Edge case — central domain (no tenant initialized):** `tenant()` returns `null`, the match arms hit their default strings. Homepage / sitemap / robots still work on the central domain without errors. Tests cover this.

## Changes by File

### 1. `app/helpers.php` — add `tenant_seo()`

See snippet above.

Find the file that already defines `tenant_cache_key()`:

```bash
grep -rn "function tenant_cache_key" app/
```

Add `tenant_seo()` immediately below. Keep both in the same file, same composer autoload entry — no new autoload change needed.

### 2. `app/Support/SeoSchema.php` — make tenant-aware

**Changes:**

**2a.** Replace hardcoded `| pw2d` in three places with `tenant_seo('title_suffix')`:

- Line 126 — `forParentCategory()`:
  ```diff
  - 'title' => "{$category->name} - Browse Categories | pw2d",
  + 'title' => "{$category->name} - Browse Categories | " . tenant_seo('title_suffix'),
  ```
- Line 145 — `forLeafCategory()`:
  ```diff
  - $title = "{$category->name} - Compare Best Models in {$currentYear} | pw2d";
  + $title = "{$category->name} - Compare Best Models in {$currentYear} | " . tenant_seo('title_suffix');
  ```
- Line 166 — preset override inside `forLeafCategory()`:
  ```diff
  - $title = "Best {$category->name} for {$activePreset->name} | pw2d";
  + $title = "Best {$category->name} for {$activePreset->name} | " . tenant_seo('title_suffix');
  ```

**2b.** Add new `forHomepage()` method (called by `Home.php`):

```php
/**
 * Build meta + schema for the tenant homepage.
 *
 * Homepage has no dynamic content — all values come from tenants.data
 * via tenant_seo() with sensible fallbacks.
 */
public static function forHomepage(): array
{
    $title       = tenant_seo('default_title');
    $description = tenant_seo('default_description');
    $canonical   = route('home');
    $brand       = tenant('brand_name') ?? 'Pw2D';

    $schema = [
        '@context'    => 'https://schema.org/',
        '@type'       => 'WebSite',
        'name'        => $brand,
        'description' => $description,
        'url'         => $canonical,
    ];

    return [
        'title'        => $title,
        'description'  => $description,
        'canonical'    => $canonical,
        'ogType'       => 'website',
        'ogImage'      => tenant_seo('default_image'),
        'schemas'      => [$schema],
        'activePreset' => null,
    ];
}
```

**2c.** Improve `ogImage` for category/preset pages (finding M2 from the audit):

In `forParentCategory()` and `forLeafCategory()`, set `ogImage` to the first available product image instead of `null`. In `forLeafCategory()`, the first item of `$visibleProducts` has `offers->first()->image_url` — use that. In `forParentCategory()`, there are no visible products so fall back to the category's own `image` column (if it exists) or `tenant_seo('default_image')`:

```php
// forLeafCategory()
$ogImage = $visibleProducts->first()?->offers?->first()?->image_url
        ?? tenant_seo('default_image');

// forParentCategory()
$ogImage = $category->image
    ? (str_starts_with($category->image, 'http') ? $category->image : url(Storage::url($category->image)))
    : tenant_seo('default_image');
```

**2d.** Add `hasPart` to parent category `CollectionPage` schema (finding M5):

```php
// forParentCategory() — expand the schema
$schema = [
    '@context'    => 'https://schema.org/',
    '@type'       => 'CollectionPage',
    'name'        => $category->name,
    'description' => $description,
    'url'         => $canonical,
    'hasPart'     => $subcategories->map(fn (Category $c) => [
        '@type' => 'CollectionPage',
        'name'  => $c->name,
        'url'   => route('category.show', ['slug' => $c->slug]),
    ])->values()->all(),
];
```

Requires accepting `Collection $subcategories` as a parameter of `forParentCategory()` — currently the caller passes it but it's unused. Plumbing is already in place in `forCategoryPage()`.

### 3. `resources/views/components/layouts/app.blade.php` — make defaults tenant-aware

**3a.** Replace hardcoded title/description defaults (lines 9–17):

```diff
- <title>{{ $metaTitle ?? 'pw2d - Power to Decide | AI Tech Recommendations' }}</title>
- <meta name="description"
-         content="{{ $metaDescription ?? 'Discover the best tech products tailored to your exact needs using our AI-powered recommendation engine.' }}">
+ <title>{{ $metaTitle ?? tenant_seo('default_title') }}</title>
+ <meta name="description" content="{{ $metaDescription ?? tenant_seo('default_description') }}">
  <link rel="canonical" href="{{ $canonicalUrl ?? request()->url() }}">

  @php
-     $defaultTitle = $metaTitle ?? 'pw2d - Power to Decide | AI Tech Recommendations';
-     $defaultDescription = $metaDescription ??
-         'Discover the best tech products tailored to your exact needs using our AI-powered recommendation engine.';
-     $defaultImage = $ogImage ?? asset('images/logo.webp');
+     $defaultTitle = $metaTitle ?? tenant_seo('default_title');
+     $defaultDescription = $metaDescription ?? tenant_seo('default_description');
+     $defaultImage = $ogImage ?? tenant_seo('default_image');
      $defaultUrl = $canonicalUrl ?? request()->url();
  @endphp
```

**3b.** Replace hardcoded `og:site_name` (line 24):

```diff
- <meta property="og:site_name" content="pw2d - Power to Decide">
+ <meta property="og:site_name" content="{{ tenant('brand_name') ?? 'Pw2D' }}">
```

### 4. `app/Livewire/Home.php` — pass meta to layout

Modify `render()` to call `SeoSchema::forHomepage()` and pass all meta keys to the view, mirroring the pattern already in [ProductCompare.php:497–522](../../app/Livewire/ProductCompare.php#L497):

```php
use App\Support\SeoSchema;

public function render()
{
    // ... existing $popularCategories / $samplePrompts / $searchHints code unchanged ...

    $seo = SeoSchema::forHomepage();

    return view('livewire.home', [
        'popularCategories' => $popularCategories,
        'samplePrompts'     => $samplePrompts,
        'searchHints'       => $searchHints,

        'metaTitle'         => $seo['title'],
        'metaDescription'   => $seo['description'],
        'canonicalUrl'      => $seo['canonical'],
        'ogType'            => $seo['ogType'],
        'ogImage'           => $seo['ogImage'],
        'schemaJson'        => json_encode($seo['schemas'][0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}
```

Verify `resources/views/livewire/home.blade.php` accepts these via the layout `<x-layouts.app>` tag and forwards them to the layout as slot variables. If not, add the `@section` / slot wiring. (Pattern already working in `product-compare.blade.php` — copy whatever it does.)

### 5. Replace `public/robots.txt` with a route

**Delete:** [public/robots.txt](../../public/robots.txt)

**Add to `routes/web.php`:**

```php
Route::get('/robots.txt', function () {
    $host    = request()->getSchemeAndHttpHost();
    $sitemap = "{$host}/sitemap.xml";

    $body = <<<TXT
User-agent: *
Disallow: /admin/
Disallow: /livewire/

Sitemap: {$sitemap}
TXT;

    return response($body, 200, [
        'Content-Type'  => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('robots');
```

**Nginx note:** the production nginx vhost may have a `location = /robots.txt` block that serves the static file and bypasses Laravel. After deploying, confirm `curl https://coffee2decide.com/robots.txt` now serves the dynamic version (should contain `https://coffee2decide.com/sitemap.xml`, not `pw2d.com`). If nginx is intercepting, the vhost config must be updated — note this in the `/deploy` checklist. **Do not** touch nginx config from this codebase.

### 6. `resources/views/sitemap.blade.php` — include static pages

Add between the homepage and categories sections:

```blade
{{-- Static pages --}}
@foreach (['about', 'contact', 'privacy-policy', 'terms-of-service'] as $staticPage)
<url>
    <loc>{{ route($staticPage) }}</loc>
    <changefreq>monthly</changefreq>
    <priority>0.3</priority>
</url>
@endforeach
```

### 7. `app/Http/Controllers/SitemapController.php` — cache response

Low-effort win related to audit finding L2. Wrap the query block in a `Cache::remember()` with a 10-minute TTL, keyed per tenant:

```php
public function index()
{
    if (!tenancy()->initialized) {
        abort(404);
    }

    $xml = Cache::remember(
        tenant_cache_key('sitemap:xml'),
        600,
        fn () => $this->buildSitemapXml(),
    );

    return response($xml, 200, [
        'Content-Type'  => 'text/xml; charset=utf-8',
        'Cache-Control' => 'public, max-age=600',
    ]);
}

private function buildSitemapXml(): string
{
    // existing Category / Product / Preset queries moved here,
    // return view('sitemap', ...)->render()
}
```

Note: `Product::cursor()` cannot be serialized into a cache — convert to `get()` or materialize inside `buildSitemapXml()` before returning the rendered view string. Since sitemap already held ~1,050 URLs for pw2d in memory for rendering, converting to `get()` is fine.

### 8. `app/Filament/Resources/TenantResource.php` — add SEO form section

After the existing `Branding` section (around line 66), add:

```php
Forms\Components\Section::make('SEO')
    ->description('Overrides for search-engine and social-media metadata. Leave blank to use brand_name-based defaults.')
    ->schema([
        Forms\Components\TextInput::make('seo_title_suffix')
            ->label('Title suffix')
            ->helperText('Appended to category page titles, e.g. " | Coffee2Decide". Defaults to brand name.')
            ->maxLength(60),
        Forms\Components\TextInput::make('seo_default_title')
            ->label('Homepage title')
            ->helperText('Full <title> tag for the homepage.')
            ->maxLength(70),
        Forms\Components\Textarea::make('seo_default_description')
            ->label('Default description')
            ->helperText('Used on homepage + as fallback. Keep under 160 characters.')
            ->rows(3)
            ->maxLength(200),
        Forms\Components\TextInput::make('seo_default_image')
            ->label('Default social image URL')
            ->helperText('Fallback og:image for pages with no product image. 1200×630 recommended.')
            ->url(),
    ])
    ->columns(1),
```

This gives Michael a UI to set all four keys per tenant without tinker.

### 9. Seed coffee2decide defaults

Add a one-off seeder `database/seeders/SeoDefaultsSeeder.php` that sets sensible defaults for both existing tenants so the fix has good values on day one:

```php
public function run(): void
{
    foreach (['pw2d', 'coffee2decide'] as $id) {
        $tenant = Tenant::find($id);
        if (!$tenant) continue;

        $brand = $tenant->brand_name ?? $id;

        // Only set keys that aren't already configured — don't clobber manual edits
        $tenant->seo_title_suffix       ??= $brand;
        $tenant->seo_default_title      ??= "{$brand} — AI Product Recommendations";
        $tenant->seo_default_description ??= "Discover the best products tailored to your exact needs using {$brand}'s AI-powered recommendation engine.";
        $tenant->save();
    }
}
```

Run via `php artisan db:seed --class=SeoDefaultsSeeder`. Listed in the deploy checklist.

For analytics settings (PostHog / GA4 / GSC verification) on coffee2decide — **leave for Michael to fill in via Filament** after merge. Not part of this spec's deliverable. These are tenant-scoped `Setting::get()` values, already wired in the layout.

## Test Plan

Pest, `RefreshDatabase` + `InitializesTenancy`-style setup. All tests in `tests/Feature/Seo/`.

### `SeoBrandBleedTest.php`

Run each assertion **twice**: once under `tenant('pw2d')`, once under a seeded test tenant `tenant('acme')` with `brand_name = 'Acme Shop'`. Assert none of the `acme` responses contain the string `pw2d` anywhere in the rendered HTML.

```php
test('homepage title uses tenant brand name', function () {
    withTenant('acme', function () {
        $res = get('/');
        $res->assertOk();
        $res->assertSee('<title>Acme Shop', false);
        $res->assertDontSee('pw2d', false);
        $res->assertDontSee('Power to Decide', false);
    });
});

test('parent category title uses tenant brand name', function () { /* ... */ });
test('leaf category title uses tenant brand name', function () { /* ... */ });
test('preset page title uses tenant brand name', function () { /* ... */ });
test('product page title uses tenant brand name', function () { /* ... */ });
test('og:site_name uses tenant brand name', function () { /* ... */ });
test('og:image falls back to tenant default, not pw2d logo', function () { /* ... */ });
```

### `RobotsTxtRouteTest.php`

```php
test('robots.txt uses current host for Sitemap directive', function () {
    withTenant('acme', function () {
        $res = get('/robots.txt');
        $res->assertOk();
        $res->assertSee('Sitemap: http://acme.lcl/sitemap.xml');
        $res->assertDontSee('pw2d.com');
    });
});

test('robots.txt has correct Content-Type', function () { /* ... */ });
```

### `SitemapContentsTest.php`

```php
test('sitemap includes static pages', function () {
    withTenant('acme', function () {
        $res = get('/sitemap.xml');
        $res->assertSee('/about');
        $res->assertSee('/contact');
        $res->assertSee('/privacy-policy');
        $res->assertSee('/terms-of-service');
    });
});
```

### `SeoSchemaTest.php` (unit-level)

```php
test('forHomepage returns tenant-scoped title', function () { /* ... */ });
test('forLeafCategory falls back to top product image for ogImage', function () { /* ... */ });
test('forParentCategory includes hasPart for subcategories', function () { /* ... */ });
test('tenant_seo helper returns brand-based defaults when keys are unset', function () { /* ... */ });
test('tenant_seo returns explicit value when key is set', function () { /* ... */ });
```

### Regression guard

Run the full existing test suite — `SeoSchema` is used by every `ProductCompare` test. Any breakage there is a signal the refactor changed behavior it shouldn't have.

## Manual Verification (after deploy)

Commands Michael can run post-deploy, in order:

```bash
# robots.txt now tenant-aware
curl -s https://coffee2decide.com/robots.txt | grep Sitemap
# expected: Sitemap: https://coffee2decide.com/sitemap.xml

# homepage title
curl -s https://coffee2decide.com/ | grep -oE '<title>[^<]*</title>'
# expected: Coffee2Decide in title, not pw2d

# og:site_name
curl -s https://coffee2decide.com/ | grep og:site_name
# expected: content="Coffee2Decide" (or whatever brand_name is set to)

# leaf category title
curl -s 'https://coffee2decide.com/compare/semi-automatic-manual-espresso-machines' | grep -oE '<title>[^<]*</title>'
# expected: ends with the tenant suffix, not "| pw2d"
```

**Post-deploy: request re-indexing in GSC** for each tenant. Google will not re-crawl immediately on its own — use Search Console's "URL Inspection → Request indexing" for the homepage + top 5 category pages per tenant.

## Delivery Plan

Single PR, single branch `feat/seo-phase-1-brand-bleed`. Builder sub-agent can execute all tasks sequentially — they touch different files except the two Blade edits:

| # | Task | Files | Agent |
|---|---|---|---|
| T1 | Add `tenant_seo()` helper + unit tests | `app/helpers.php`, `tests/Unit/TenantSeoHelperTest.php` | builder |
| T2 | Refactor `SeoSchema.php` (title suffixes, forHomepage, ogImage, hasPart) + tests | `app/Support/SeoSchema.php`, `tests/Feature/Seo/SeoSchemaTest.php` | builder |
| T3 | Update layout Blade with tenant-aware defaults | `resources/views/components/layouts/app.blade.php` | builder |
| T4 | Wire `Home.php::render()` through `SeoSchema::forHomepage()` | `app/Livewire/Home.php`, possibly `resources/views/livewire/home.blade.php` | builder |
| T5 | Replace static robots.txt with route | delete `public/robots.txt`, edit `routes/web.php` | builder |
| T6 | Add static pages to sitemap + 10-min cache | `resources/views/sitemap.blade.php`, `app/Http/Controllers/SitemapController.php` | builder |
| T7 | Add SEO form section to `TenantResource` | `app/Filament/Resources/TenantResource.php` | frontend |
| T8 | Write `SeoDefaultsSeeder` | `database/seeders/SeoDefaultsSeeder.php` | builder |
| T9 | Full brand-bleed Pest test suite (7 scenarios × 2 tenants) | `tests/Feature/Seo/SeoBrandBleedTest.php`, `tests/Feature/Seo/RobotsTxtRouteTest.php`, `tests/Feature/Seo/SitemapContentsTest.php` | tester |
| T10 | `reviewer` pass on entire PR | — | reviewer |

Architect will spawn T1 first (unblocks everything else), then T2+T3+T5+T6+T8 in parallel, then T4 after T2, then T7 standalone, finally T9 after all code tasks merge, then T10.

## Deploy Checklist

When `/deploy` is invoked after merge:

1. Pull latest
2. `composer install --no-dev -o`
3. `php artisan migrate --force` *(no migrations in this spec but always run)*
4. `php artisan db:seed --class=SeoDefaultsSeeder --force`
5. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
6. `php artisan cache:clear` *(flush sitemap cache so the new format is rebuilt)*
7. **Verify nginx is not intercepting `/robots.txt`** — `curl -s https://coffee2decide.com/robots.txt` should now show the tenant sitemap URL, not the static pw2d one. If it still shows the old file, the nginx vhost needs a config edit to remove `location = /robots.txt` block. **Flag to Michael.**
8. Run the 4 manual verification `curl` commands above
9. Re-request indexing in GSC for both tenants' top pages

## Success Criteria

- All tests in `tests/Feature/Seo/` pass, plus existing suite stays green
- On live `coffee2decide.com`:
  - `<title>` on every page type contains Coffee2Decide brand, zero `pw2d` strings
  - `og:site_name` equals the tenant brand
  - `robots.txt` points to `https://coffee2decide.com/sitemap.xml`
  - `og:image` on leaf category pages is a product image, not a logo
  - Sitemap contains `/about`, `/contact`, `/privacy-policy`, `/terms-of-service`
- Filament admin shows new SEO section in TenantResource edit form
- Reviewer agent signs off

---

## Related Config Note (NOT in this spec — separate ticket)

During the audit, `https://pw2d.com/sitemap.xml` returned HTTP 200 with 1,050+ URLs, despite [SitemapController::index()](../../app/Http/Controllers/SitemapController.php) having `abort(404)` on central domains, and [config/tenancy.php:17](../../config/tenancy.php#L17) listing `pw2d.com` in `central_domains`. This means **prod is resolving `pw2d.com` as a tenant domain**, not a central domain — either via a different `APP_CENTRAL_DOMAIN` env value or a different vhost setup.

This is a dev/prod config mismatch with real implications (e.g., tenancy middleware behavior on the main domain). Worth a separate investigation ticket — does not block this spec's delivery.
