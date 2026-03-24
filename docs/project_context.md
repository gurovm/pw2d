# Project Context: Pw2D (Power to Decide)

**CRITICAL INSTRUCTION FOR ALL AGENTS:** Before designing, building, testing, or reviewing any feature, you MUST read and understand this document. It defines the business logic, architectural constraints, and technical stack of the Pw2D platform.

## 1. Business Model & Product Vision
Pw2D is an **AI-driven affiliate/recommendation platform** for the US market. It runs multiple niche comparison websites from a single codebase.
* **Core Feature — "Compare with Intelligence":** Users describe their needs in natural language (e.g., "best mic for noisy rooms under $200"). The AI identifies the right product category and dynamically ranks items using personalized priority sliders.
* **Revenue Model:** Amazon Associates affiliate commissions. Every product links to Amazon with an affiliate tag.
* **The AI Bouncer Pipeline:** Products are bulk-scraped from Amazon via a Chrome Extension, then run through an AI quality gate (Gemini API) that rejects accessories/junk, normalizes brand names, generates 2-sentence verdicts, and scores each product on category-specific features (0-100).
* **Dynamic Scoring:** Users adjust priority sliders (e.g., "Sound Quality: 90%, Price: 30%") and the product grid re-ranks instantly. No page reload — all computation happens server-side via Livewire computed properties.

## 2. Multi-Tenant Architecture
The platform uses **Single Database Multi-Tenancy** via `stancl/tenancy`.
* **Strategy:** One MySQL database, multiple domains. Each tenant (niche site) is identified by domain.
* **Package:** `stancl/tenancy` v3 with `BelongsToTenant` trait on all core models.
* **Tenant Identification:** `InitializeTenancyIfApplicable` middleware checks the request hostname against `config('tenancy.central_domains')`. Central domains skip tenancy; tenant domains resolve via `DomainTenantResolver` (cached, TTL 3600s).
* **Scoped Models:** `Category`, `Product`, `Brand`, `Feature`, `Preset`, `SearchLog`, `Setting` — all have `tenant_id` (nullable string FK to `tenants.id`).
* **Central Domain:** `pw2d.com` — hosts the main site and the Filament admin panel.
* **Tenant Domains:** e.g., `coffee2decide.com`, `best-mics.com` — each shows only its own categories/products.
* **Admin Panel:** Filament v3 with native tenancy. A dropdown in the header switches between tenants. The `TenantSet` event bridges Filament's tenant context to stancl's `tenancy()->initialize()`.
* **Dynamic Branding:** Each tenant has custom colors (`primary_color`, `secondary_background_color`, `text_color`), logo, brand name, and hero copy — stored in the tenant's JSON `data` column via VirtualColumn trait. CSS variables (`--color-primary`, etc.) are injected in the layout `<head>` and mapped to Tailwind via `tenant.*` color tokens.
* **Routing:** Single set of named routes in `web.php`. The `InitializeTenancyIfApplicable` middleware (appended to the `web` group) handles tenant initialization transparently. No duplicate route declarations.
* **Rule:** NEVER suggest multi-database solutions. Single DB with `BelongsToTenant` only.

## 3. Core Data Model

### Entities
* **Tenant:** String primary key (e.g., `'pw2d'`, `'coffee-decide'`). Has many domains. Branding fields stored in JSON `data` column.
* **Category:** Tree structure (`parent_id` self-reference). Has `buying_guide` (JSON), `sample_prompts`, `image`. Each leaf category defines its own `Feature` set.
* **Product:** Belongs to `Brand` and `Category`. Has `external_id` (ASIN), `ai_summary`, `price_tier` (1=Budget, 2=Mid, 3=Premium), `scraped_price`, Amazon rating/reviews. Unique constraint: `(tenant_id, external_id, category_id)`.
* **Feature:** Category-specific scoring dimensions (e.g., "Sound Quality", "Build Quality"). `is_higher_better` drives normalization. Each product gets a `ProductFeatureValue` (score 0-100 + AI explanation).
* **Preset:** Named weight configurations per category (e.g., "For Podcasting"). Stores per-feature weights via `feature_preset` pivot.
* **Brand:** Created on-demand during AI processing. Scoped per tenant.

### Key Relations
```
tenants ──< categories ──< features ──< product_feature_values >── products >── brands
                │                                                      │
                └──< presets >──< feature_preset >──< features         └── category_id → categories
```

## 4. AI Integration
* **Provider:** Google Gemini API (configurable model via `GEMINI_SITE_MODEL` env var).
* **Two AI Surfaces:**
  1. **Global Search AI:** Parses natural language queries on the homepage, matches to the best category + preset, redirects the user.
  2. **Category AI Concierge:** Inside a category page, adjusts the priority sliders based on the user's description (e.g., "I need a mic for a noisy call center").
* **AI Bouncer (Queue Job):** `ProcessPendingProduct` — processes scraped products through Gemini. Rejects accessories, scores features, generates summaries. Runs via Supervisor (2 workers, `www-data` user).
* **Image Generation:** Filament admin action generates category hero images via Gemini's image API, auto-optimized to WebP via `ImageOptimizer::toWebp()`.

## 5. Chrome Extension Integration
* **Location:** `/chrome_extension` directory (Manifest V3).
* **Purpose:** Bulk-scrapes Amazon SERP pages. Extracts ASIN, title, price, rating, reviews count, hi-res image URL.
* **API Endpoints (MUST be maintained):**
  1. `GET /api/categories`
  2. `GET /api/existing-asins?category_id={id}`
  3. `POST /api/products/batch-import`
* **Tenant Inheritance:** Imported products inherit `tenant_id` from the target category.

## 6. Technology Stack & Coding Standards
* **Backend:** Laravel 12 (PHP 8.3+). Strict types, thin controllers, Service classes for business logic.
* **Frontend:** Blade Templates + Livewire v3 + Alpine.js. Tailwind CSS compiled via Vite.
* **Database:** MySQL (production + local). Always use migrations with `up()` and `down()`.
* **Testing:** PHPUnit/Pest. Every feature must have tests. Run `php artisan test` before completing any task.
* **Cache:** File driver locally, Redis on production. 90s TTL on scored-products computations.
* **Queue:** Database driver. Supervisor manages 2 workers on production.
* **Image Optimization:** `ImageOptimizer` service converts to WebP (800px max width, quality 80) via `cwebp`. Auto-runs on AI-generated images and scraped product images.

## 7. Environments & Infrastructure
* **Production Server:** 209.97.153.234 (DigitalOcean, Ubuntu 24.04 LTS)
  * Web Server: Nginx
  * Path: `/var/www/pw2d`
  * SSL: Certbot
  * PHP: 8.3-FPM
* **Deployment:** Strictly via `/deploy` command. Never auto-deploy after coding.
  * Sequence: `git pull` → `composer install --no-dev` → `migrate --force` → `npm run build` → `optimize:clear` → restart php-fpm

## 8. Key Livewire Components
* **`ProductCompare`:** The main category page. Computes scored products server-side, supports H2H comparison mode (session-persisted), focus-and-bump from search, SEO metadata injection.
* **`ComparisonHeader`:** Side panel with AI concierge, preset pills, and priority sliders. All dynamic colors via CSS variables.
* **`GlobalSearch`:** Instant DB search + explicit AI trigger. Context-aware boosting (products from the current category rank first). Used in both nav bar and hero variants.
* **`Home`:** Landing page with dynamic hero copy, search hints derived from categories, popular category grid.

## 9. Agent-Specific Directives
* **@architect:** Always respect single-DB multi-tenancy. Every new table needs `tenant_id`. Composite indexes must lead with `tenant_id`. Never break the tenant/central domain separation.
* **@builder:** Use `var(--color-primary)` / `bg-tenant-primary` for dynamic colors, never hardcode brand colors. Ensure `BelongsToTenant` is added to any new model. Mobile-first responsive design.
* **@tester:** Tests run on central domain context (localhost). Use `Livewire::test()` for component tests. Factories must set `slug` explicitly for products. Use `RefreshDatabase` trait.
* **@reviewer:** Check for N+1 queries, missing tenant scoping, hardcoded colors, and explicit `tenant_id` in API controllers (safety net for non-tenancy-middleware routes).
