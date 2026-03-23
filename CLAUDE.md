# Pw2D (Power to Decide) - AI Project Rules & Context

## 1. Project Overview
Pw2D is a modern affiliate/recommendation platform targeted at the US market. Its core feature is "Compare with Intelligence" - an AI-driven search that takes natural language inputs from users (e.g., use case, budget, preferences), matches them to the right product category, and dynamically ranks items based on their specific needs.

## 2. Tech Stack & Architecture
- **Backend:** Laravel 11 (PHP 8.3)
- **Frontend:** Blade Templates, Tailwind CSS, compiled via Vite (Node.js v20)
- **Database:** MySQL
- **AI Integration:** Uses AI models to parse user prompts and rank database items.

## 3. Environments & Server Infrastructure
- **Local Environment:** Standard Laravel Valet/Serve development. Database is local.
- **Production Server:** - **IP:** 209.97.153.234 (DigitalOcean, Ubuntu 24.04 LTS)
  - **Web Server:** Nginx (`pw2d.com`, `www.pw2d.com`)
  - **Path:** `/var/www/pw2d`
  - **SSL:** Secured via Certbot.
- **Sub-system (TAU Chatbox):** There is a separate Python/FastAPI/Docker project running on the same server under `t.pw2d.com` (Port 8010). Do not confuse the Laravel monolithic codebase with the TAU Chatbox project.

### 4. STRICT Deployment Workflow (CRITICAL BOUNDARY)

🛑 **STOP! NEVER INITIATE DEPLOYMENT AUTOMATICALLY.** 🛑
Under NO circumstances should you execute deployment commands, SSH into the server, or run build scripts after finishing a coding task. Your job ends when the code is written and tested locally. Deployment is strictly handled via the `/deploy` command.

## 5. Coding Standards
- Write clean, modern PHP 8.3 (use strict types, match expressions, arrow functions).
- Frontend changes should utilize Tailwind utility classes; avoid writing custom CSS unless absolutely necessary.
- Measurements in recipes/specs should prioritize logical, standard metric/imperial units clearly. 
- Always review `routes/web.php` and Controller logic before proposing architecture changes.
## 6. Testing Requirements (TDD/Automated Tests)
- Every new feature, API endpoint, or core logic update MUST be accompanied by a corresponding test.
- Use Laravel's built-in testing tools (Pest or PHPUnit).
- Tests must cover the "happy path" as well as edge cases and error handling.
- Run `php artisan test` locally to verify functionality before completing a task.

## 7. Chrome Extension Integration
- **Location:** The source code for the extension is located in the `/chrome_extension` directory (Manifest V3).
- **Purpose:** Bulk-scrapes Amazon search results pages (SERP) or carousels. It NO LONGER opens individual product pages. It extracts lightweight data directly from the DOM: ASIN, product title, price, numeric rating, reviews count, and converts thumbnails to hi-res image URLs.
- **Authentication:** The extension authenticates API requests using the `X-Extension-Token` header.
- **API Dependencies:** The backend MUST strictly maintain and support the following API endpoints:
  1. `GET /api/categories`
  2. `GET /api/existing-asins?category_id={id}`
  3. `POST /api/products/batch-import`
- **Payload Structure:** The `POST /api/products/batch-import` endpoint expects an array of product objects (or a payload containing the array and `category_id`). Each product object MUST match this structure:
  `{ "asin": "...", "title": "...", "price": 54.99, "rating": 4.5, "reviews_count": 300, "image_url": "https://hi-res-link.jpg", "status": "pending_ai" }`
- **Important Rule:** NEVER alter the endpoint URLs in the backend without simultaneously updating the extension files (`popup.js` and `content.js`).

## 8. Database Structure & Relations

### Core Tables

**`categories`**
- `id`, `parent_id` (nullable → self, tree structure), `name`, `slug` (unique), `description`
- `image` (storage path, nullable)
- `buying_guide` (JSON: `{how_to_decide, the_pitfalls, key_jargon}`, nullable)
- Self-referential: a category can have a parent category (subcategory support).

**`brands`**
- `id`, `name`, `logo_path` (nullable)
- Created on-demand by `ProcessPendingProduct` via `Brand::firstOrCreate()`.

**`products`**
- `id`, `brand_id` → `brands` (nullable FK), `category_id` → `categories`, `name` (VARCHAR 255)
- `slug` (unique per product), `ai_summary` (text, AI-generated 2-sentence verdict)
- `price_tier` (int: 1=Budget <$50, 2=Mid $50–$150, 3=Premium >$150)
- `amazon_rating` (float 0–5, nullable), `amazon_reviews_count` (int)
- `image_path` (locally stored image, `storage/products/images/`), `external_image_path` (Amazon CDN URL)
- `affiliate_url` (nullable — if null, auto-generated from `external_id` as `amazon.com/dp/{ASIN}?tag=...`)
- `external_id` (ASIN, e.g. `B0ABC12345`)
- `is_ignored` (boolean, default false — AI-detected accessories suppressed from all site surfaces)
- `status` (nullable string: `null`=fully processed, `pending_ai`=queued for AI, `failed`=AI exhausted retries)
- Unique constraint: `(external_id, category_id)` — same ASIN can exist in multiple categories.

**`features`**
- `id`, `category_id` → `categories`, `name`, `unit` (e.g. "dB", "grams", nullable)
- `is_higher_better` (boolean — drives normalization direction)
- `min_value`, `max_value` (floats, nullable — used by `ProductScoringService` to normalize 0–100)
- Each category defines its own scoring dimensions here.

**`product_feature_values`**
- `id`, `product_id` → `products`, `feature_id` → `features`
- `raw_value` (float, 0–100 AI score), `explanation` (text, AI one-sentence reason, nullable)
- Unique: `(product_id, feature_id)` — one value per feature per product.

**`presets`**
- `id`, `category_id` → `categories`, `name`
- Named weight configurations (e.g. "For Podcasting", "For Gaming").

**`feature_preset`** (pivot)
- `preset_id` → `presets`, `feature_id` → `features`, `weight` (int 0–100, default 50)
- Stores per-feature weight for each preset.

**`search_logs`**
- `id`, `type` (`global_search` | `homepage_ai` | `category_ai`), `query` (text)
- `category_name` (nullable string), `user_id` → `users` (nullable)
- `results` (JSON, matched product IDs/names), `summary` (text, AI explanation of ranking)

**`settings`**
- `key` (unique), `value` (text) — key/value store for site-wide config (e.g. AI model overrides).

### Laravel System Tables
- `users` — admin/staff accounts (Filament admin panel access)
- `jobs` — queue jobs (QUEUE_CONNECTION=database). Queue workers run via Supervisor (2 processes, `www-data` user).
- `failed_jobs` — exhausted queue retries land here (normally empty).
- `cache` — Redis is the cache driver (90s TTL on scored-products computations).

### Key Relations (summary)

```
categories ──< features ──< product_feature_values >── products >── brands
     │                                                      │
     └──< presets >──< feature_preset >──< features         └── category_id → categories
```

- `Category` has many `Feature`, `Product`, `Preset`
- `Product` belongs to `Brand` and `Category`; has many `ProductFeatureValue`
- `Feature` belongs to `Category`; pivots to `Preset` via `feature_preset`
- `ProductFeatureValue` links one `Product` to one `Feature` with a score + explanation
- `Preset` belongs to `Category`; carries per-feature weights for the comparison sliders

### AI Processing Pipeline (The AI Bouncer)
1. **Scrape & Send:** Chrome Extension scrapes Amazon SERP → POST `/api/products/batch-import`.
2. **Queue:** `BatchImportController` saves basic product stubs with `status='pending_ai'` and dispatches `ProcessPendingProduct` queue jobs.
3. **AI Bouncer (Quality Gate):** Worker calls Gemini API with the product title + price tier. Gemini relies on **world knowledge** (no raw text provided) and strictly enforces:
   - **Rejection:** Kills accessories, bundles, generic/white-label products, or Chinese model numbers (e.g., BM-800) by marking them as `status=ignored`.
   - **Normalization:** Cleans and normalizes brand names (e.g., "RØDE" → "Rode").
4. **Scoring:** For valid products, Gemini returns the cleaned name, brand, an AI summary (2 sentences), and feature scores (0–100) based on the specific category's parameters.
5. **Finalize:** Job creates/finds `Brand`, updates the `Product` record (sets `status=null`), saves `ProductFeatureValue` rows, and successfully downloads the hi-res image to local storage.

## Agent Team

This project uses a multi-agent system. Each agent has a defined role:

| Agent | Trigger | Responsibility |
|-------|---------|----------------|
| **architect** | "plan", "design", "architect" | Designs features, writes specs to `docs/specs/` |
| **builder** | "build", "implement", "code" | Implements specs, writes actual Laravel code |
| **reviewer** | "review", "check", "quality" | Reviews code quality and Laravel conventions |
| **tester** | "write tests", "test coverage" | Writes PHPUnit/Pest tests |
| **security** | "security check", "audit" | Audits for vulnerabilities |
| **documenter** | "document", "API docs" | Writes docs, PHPDoc, README |
| **performance** | "performance", "optimize", "slow", "N+1", "cache" | Audits for bottlenecks, N+1 queries, caching gaps |
| **frontend** | "blade", "view", "UI", "component", "tailwind", "page", "form", "alpine" | Builds Blade templates, Tailwind styling, Alpine.js interactivity |
