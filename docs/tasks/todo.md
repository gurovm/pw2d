# Tasks: Code Quality Fixes from Full Codebase Review (2026-03-22)

## Critical Priority

- [x] **C1: Extract GeminiService** -- Create `app/Services/GeminiService.php` to encapsulate API URL construction, generation config, response parsing (fence stripping + JSON decode), and error handling. Replace direct HTTP calls in `ProcessPendingProduct`, `RescanProductFeatures`, `ProductImportController`, `ProductCompare`, and `GlobalSearch`.

- [x] **C2: Fix Setting cache to be tenant-aware** -- Include tenant ID in cache keys in `Setting::get()` and `Setting::set()`. Current cache key `setting:{key}` causes cross-tenant cache pollution.

- [x] **C3: Refactor ProductImportController::import()** -- Either deprecate this endpoint (BatchImportController + ProcessPendingProduct already handle the modern flow) or extract shared logic into a service. Current method is 310 lines of business logic in a controller.

- [x] **C4: Fix LIKE wildcard injection in GlobalSearch** -- Escape `%` and `_` characters in user search input before using in LIKE queries (lines 252, 260, 277 of GlobalSearch.php).

- [x] **C5: Create Form Request classes** -- Create `BatchImportRequest` and `ProductImportRequest` to extract validation from API controllers.

- [x] **C6: Extract SSRF allowed-hosts to config** -- Move duplicated Amazon CDN domain allowlists from `ProductImportController` and `ProcessPendingProduct` to `config/services.php` under `amazon.allowed_image_hosts`.

- [ ] **C7: Restrict or remove public registration** -- The `Register` Livewire component allows unrestricted account creation. Either add `@pw2d.com` email validation, disable registration, or verify these auth routes are not actually routed.

## Medium Priority

- [ ] **W2: Extract sample prompts fallback chain** -- The 3-priority sample prompts resolution is duplicated in `ProductCompare`, `ComparisonHeader`, and `Home`. Extract to a helper.

- [ ] **W7: Remove duplicate API route** -- `/import-product` and `/product-import` both route to `ProductImportController::import()`. Deprecate one after verifying Chrome Extension usage.

- [ ] **W8: Remove dead code in ProductScoringService** -- Verify `calculateMatchScore()`, `normalizeFeatureValue()`, `calculateFeatureRange()`, and `updateFeatureRange()` have no callers, then remove.

- [ ] **W10: Audit Tenant model interfaces** -- Verify whether `TenantWithDatabase` and `HasDatabase` are required for single-DB mode. Remove if not needed.

## Low Priority

- [ ] **W5: Add return types to Livewire methods** -- Add PHP 8.3 return type declarations to all public methods in Livewire components.

- [ ] **W6: Add missing factories** -- Create factories for `Preset`, `SearchLog`, `ProductFeatureValue`, and `FeaturePreset`.

- [ ] **W9: Add return types to Preset model relationships** -- Add `: BelongsTo`, `: BelongsToMany`, `: HasMany` return types.

- [ ] **W12: Write tests for uncovered critical paths** -- `BatchImportController`, `ProcessPendingProduct`, `RescanProductFeatures`, `GlobalSearch`, `SitemapController`, `Setting` caching, and tenant isolation.

- [ ] **W4: Reduce ProductCompare complexity** -- Extract SEO schema generation from `render()` into a helper class. Consider extracting AI concierge into a separate Livewire component.

- [ ] **W11: Audit orphaned auth components** -- Verify whether `Login`, `Register`, `Profile` Livewire components are routed. Remove if unused.
