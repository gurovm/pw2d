# Database Structure & Relations

## Core Tables

**`categories`**
- `id`, `parent_id` (nullable → self, tree structure), `name`, `slug` (unique), `description`
- `image` (storage path, nullable)
- `buying_guide` (JSON: `{how_to_decide, the_pitfalls, key_jargon}`, nullable)
- Self-referential: a category can have a parent category (subcategory support).

**`brands`**
- `id`, `name`, `logo_path` (nullable)
- Created on-demand by `ProcessPendingProduct` via `Brand::firstOrCreate()`.

**`products`** *(The "Platonic Ideal" — one canonical record per real-world product)*
- `id`, `brand_id` → `brands` (nullable FK), `category_id` → `categories`, `name` (VARCHAR 255)
- `slug` (unique per product), `ai_summary` (text, AI-generated 2-sentence verdict)
- `price_tier` (int: 1=Budget, 2=Mid-range, 3=Premium — thresholds set per category via `budget_max`/`midrange_max`)
- `amazon_rating` (float 0–5, nullable), `amazon_reviews_count` (int)
- `image_path` (locally stored WebP image, `storage/products/images/`)
- `affiliate_url` (nullable — if null, auto-generated from best offer URL + affiliate tag)
- `is_ignored` (boolean, default false — AI-detected accessories suppressed from all site surfaces)
- `status` (nullable string: `null`=fully processed, `pending_ai`=queued for AI, `failed`=AI exhausted retries)
- **Key accessors:** `best_price` (lowest offer price), `best_offer` (cheapest offer record), `estimated_price` (obfuscated for display)
- Vendor-specific data (price, URL, ASIN, raw title) lives in `product_offers`, NOT on this table.

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

**`product_offers`** *(Multi-Vendor Aggregator — store-specific data)*
- `id`, `product_id` → `products` (cascade), `tenant_id` → `tenants` (cascade)
- `store_name` (string 100, e.g. 'Amazon', 'Clive Coffee')
- `url` (text, full product URL at this store — for Amazon: `amazon.com/dp/{ASIN}`)
- `scraped_price` (decimal 10,2, nullable), `raw_title` (string 500, exact scraped title)
- `image_url` (text, nullable — external CDN image URL from this store)
- `stock_status` (string 50, nullable — 'in_stock', 'out_of_stock')
- Unique: `(product_id, store_name)` — one offer per store per product.
- **This is where all vendor-specific data lives.** Products table holds only canonical/AI-processed data.

**`ai_matching_decisions`** *(AI Memory Layer)*
- `id`, `tenant_id` → `tenants` (cascade)
- `scraped_raw_name` (string 500, the raw title from store)
- `existing_product_id` → `products` (nullable, nullOnDelete)
- `is_match` (boolean)
- Index: `(tenant_id, scraped_raw_name)` — fast lookup before calling AI.
- Purpose: caches AI decisions on whether a scraped title matches an existing product, preventing duplicate API calls.

**`settings`**
- `key` (unique), `value` (text) — key/value store for site-wide config (e.g. AI model overrides).

> **Legacy fields DROPPED (Phase 2 complete):** `products.scraped_price`, `products.external_id`, `products.external_image_path` have been migrated to `product_offers` and removed from the products table.

## Laravel System Tables
- `users` — admin/staff accounts (Filament admin panel access)
- `jobs` — queue jobs (QUEUE_CONNECTION=database). Queue workers run via Supervisor (2 processes, `www-data` user).
- `failed_jobs` — exhausted queue retries land here (normally empty).
- `cache` — Redis is the cache driver (90s TTL on scored-products computations).

## Key Relations (summary)

```
categories ──< features ──< product_feature_values >── products >── brands
     │                                                   │   │
     └──< presets >──< feature_preset >──< features      │   └── category_id → categories
                                                         │
                                                         └──< product_offers (store-specific prices/URLs)
                                                         └──< ai_matching_decisions (dedup cache)
```

- `Category` has many `Feature`, `Product`, `Preset`
- `Product` belongs to `Brand` and `Category`; has many `ProductFeatureValue`, `ProductOffer`
- `ProductOffer` belongs to `Product` — one per store per product (Amazon, Clive Coffee, etc.)
- `Feature` belongs to `Category`; pivots to `Preset` via `feature_preset`
- `ProductFeatureValue` links one `Product` to one `Feature` with a score + explanation
- `Preset` belongs to `Category`; carries per-feature weights for the comparison sliders
- `AiMatchingDecision` caches AI dedup results — prevents re-asking Gemini for known title→product mappings
