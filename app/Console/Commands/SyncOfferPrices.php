<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProductOffer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Re-scrapes prices and stock status for all active product offers.
 *
 * This is a zero-AI-cost maintenance command. It only visits known URLs
 * and extracts price/stock data from the HTML — no Gemini calls.
 */
class SyncOfferPrices extends Command
{
    protected $signature = 'pw2d:sync-offer-prices
                            {--store= : Limit to a specific store (e.g., Amazon)}
                            {--limit=0 : Max offers to process (0 = all)}
                            {--dry-run : Show what would be updated without saving}';

    protected $description = 'Re-scrape prices and stock status for all active product offers (zero AI cost)';

    public function handle(): int
    {
        $query = ProductOffer::with(['product:id,is_ignored,status,category_id', 'product.category:id,budget_max,midrange_max', 'store:id,slug'])
            ->whereHas('product', fn ($q) => $q->where('is_ignored', false)->whereNull('status'));

        if ($store = $this->option('store')) {
            $query->whereHas('store', fn ($q) => $q->where('slug', $store));
        }

        $limit = (int) $this->option('limit');
        $isDryRun = $this->option('dry-run');

        $total = $limit > 0 ? min($limit, $query->count()) : $query->count();

        if ($total === 0) {
            $this->info('No active offers to sync.');
            return self::SUCCESS;
        }

        $this->info(($isDryRun ? '[DRY RUN] ' : '') . "Syncing prices for {$total} offers...");
        $bar = $this->output->createProgressBar($total);

        $updated   = 0;
        $failed    = 0;
        $unchanged = 0;
        $processed = 0;

        $chunkQuery = $limit > 0 ? $query->limit($limit) : $query;

        $chunkQuery->chunk(50, function ($offers) use ($bar, &$updated, &$failed, &$unchanged, &$processed, $limit, $isDryRun) {
            foreach ($offers as $offer) {
                if ($limit > 0 && $processed >= $limit) {
                    return false; // stop chunking
                }

                try {
                    $result = $this->scrapePrice($offer);

                    if ($result === null) {
                        $failed++;
                    } elseif ($result['price'] === $offer->scraped_price && $result['stock'] === $offer->stock_status) {
                        $unchanged++;
                    } else {
                        if (!$isDryRun) {
                            $offer->update([
                                'scraped_price' => $result['price'],
                                'stock_status'  => $result['stock'],
                            ]);

                            // Recalculate product price tier if price changed
                            if ($result['price'] !== $offer->scraped_price && $offer->product?->category) {
                                $newTier = $offer->product->category->priceTierFor($result['price']);
                                if ($newTier !== null) {
                                    $offer->product->update(['price_tier' => $newTier]);
                                }
                            }
                        }
                        $updated++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('SyncOfferPrices: failed', [
                        'offer_id' => $offer->id,
                        'url'      => $offer->url,
                        'error'    => $e->getMessage(),
                    ]);
                    $failed++;
                }

                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $prefix = $isDryRun ? '[DRY RUN] Would have: ' : '';
        $this->info("{$prefix}{$updated} updated, {$unchanged} unchanged, {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * Scrape price and stock status from a product offer URL.
     * Returns null on failure, or ['price' => float|null, 'stock' => string|null].
     *
     * Public and static so it can be reused from admin pages (e.g. ProblemProducts rescan action).
     * The $offer must have its `store` relation loaded before calling.
     */
    public static function scrapeOfferPrice(ProductOffer $offer): ?array
    {
        $response = Http::timeout(10)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->get($offer->url);

        if (!$response->successful()) {
            return null;
        }

        $html = $response->body();

        return match ($offer->store?->slug) {
            'amazon' => static::parseAmazonPage($html),
            default  => static::parseGenericPage($html),
        };
    }

    /** @deprecated Use scrapeOfferPrice() instead. Kept for internal handle() call. */
    private function scrapePrice(ProductOffer $offer): ?array
    {
        return static::scrapeOfferPrice($offer);
    }

    private static function parseAmazonPage(string $html): array
    {
        $price = null;
        $stock = null;

        // Price: look for the main product price in known containers
        // Pattern: $XX.XX or $X,XXX.XX
        if (preg_match('/<span[^>]*class="[^"]*priceToPay[^"]*"[^>]*>.*?(\$[\d,]+\.\d{2})/s', $html, $m)) {
            $price = (float) str_replace(['$', ','], '', $m[1]);
        } elseif (preg_match('/<span[^>]*id="priceblock_ourprice"[^>]*>\s*(\$[\d,]+\.\d{2})/s', $html, $m)) {
            $price = (float) str_replace(['$', ','], '', $m[1]);
        } elseif (preg_match('/corePrice_feature_div.*?(\$[\d,]+\.\d{2})/s', $html, $m)) {
            $price = (float) str_replace(['$', ','], '', $m[1]);
        }

        // Stock: check for availability signals
        if (str_contains($html, 'Currently unavailable') || str_contains($html, 'not available')) {
            $stock = 'out_of_stock';
        } elseif (str_contains($html, 'In Stock') || str_contains($html, 'in stock') || $price !== null) {
            $stock = 'in_stock';
        }

        return ['price' => $price, 'stock' => $stock];
    }

    private static function parseGenericPage(string $html): array
    {
        $price = null;

        // Generic price extraction: look for common price patterns
        if (preg_match('/\$(\d{1,3}(?:,\d{3})*\.\d{2})/', $html, $m)) {
            $price = (float) str_replace(',', '', $m[1]);
        }

        return ['price' => $price, 'stock' => $price !== null ? 'in_stock' : null];
    }
}
