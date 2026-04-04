# Spec 011: Problem Products — Add Store Link & Rescan Action

## Goal
Make it easy for admins to investigate "No price" products by showing the store URL and allowing single-product price rescan directly from the Problem Products page.

## File to modify
`app/Filament/Pages/ProblemProducts.php`

## Changes

### 1. Remove Rating column
Delete the `amazon_rating` TextColumn (and remove `amazon_rating` from the `->select()` array in `modifyQueryUsing`).

### 2. Add "Store" column (after Category, before Price)
- New `TextColumn::make('store')` using `getStateUsing`
- State: `$record->offers->first()?->store?->name ?? '—'`
- Render as a **badge** (`.badge()`)
- Clickable: `.url(fn (Product $record) => $record->offers->first()?->url)` + `.openUrlInNewTab()`
- Color: `'info'`

Note: `offers.store` is already eager-loaded on line 125.

### 3. Change product name link
Currently links to `$record->offers->first()?->url`. Change to link to the product edit page:
```php
->url(fn (Product $record) => route('filament.admin.resources.products.edit', [
    'tenant' => tenant('id'),
    'record' => $record,
]))
```

### 4. Add "Rescan Price" row action
Add a new action between "Ignore" and "Edit":
```php
Action::make('rescanPrice')
    ->label('Rescan Price')
    ->icon('heroicon-o-arrow-path')
    ->color('warning')
    ->visible(fn (Product $record) => $record->offers->isNotEmpty())
    ->action(function (Product $record) {
        // For each offer on this product, dispatch or inline the price scrape
        // Reuse SyncOfferPrices logic — call the scrapePrice + update flow
        // Show notification with result
    })
```

Implementation approach for the rescan action:
- Read `SyncOfferPrices` command to understand the `scrapePrice()` method
- Extract the per-offer scrape logic into a reusable static method or call it inline
- For each of the product's offers: HTTP fetch the URL, parse price, update `scraped_price`
- Recalculate `price_tier` on the product after updating
- Show success notification: "Rescanned {n} offers. Price: ${x}" or "No price found"
- If the scrape logic is tightly coupled to the command, the simplest approach is to extract a `scrapeOfferPrice(ProductOffer $offer)` method to a service or trait, then call it from both the command and this action. Alternatively, just inline the HTTP+parse logic in the action closure if it's small enough.

### 5. Column order (final)
Image | Product | Category | **Store** | Price | Reviews | Problem | Added

## No other files should need changes
Unless extracting scrape logic to a service — in that case, create a minimal service or add a public static method to the command.
