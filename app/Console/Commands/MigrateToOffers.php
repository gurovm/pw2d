<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiMatchingDecision;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Console\Command;

class MigrateToOffers extends Command
{
    protected $signature = 'pw2d:migrate-to-offers';
    protected $description = 'Migrate existing product data into product_offers and ai_matching_decisions tables';

    public function handle(): int
    {
        $query = Product::withoutGlobalScopes()->whereNotNull('external_id');
        $total = $query->count();

        if ($total === 0) {
            $this->warn('No products with external_id found.');
            return self::SUCCESS;
        }

        $this->info("Migrating {$total} products to offers...");
        $bar = $this->output->createProgressBar($total);

        $offersCreated = 0;
        $decisionsCreated = 0;

        $query->chunk(200, function ($products) use ($bar, &$offersCreated, &$decisionsCreated) {
            foreach ($products as $product) {
                $store = Store::firstOrCreate(
                    ['slug' => 'amazon', 'tenant_id' => $product->tenant_id],
                    ['name' => 'Amazon']
                );

                $offer = ProductOffer::firstOrCreate(
                    [
                        'product_id'  => $product->id,
                        'store_id'    => $store->id,
                    ],
                    [
                        'tenant_id'     => $product->tenant_id,
                        'url'           => "https://www.amazon.com/dp/{$product->external_id}",
                        'scraped_price' => $product->scraped_price,
                        'raw_title'     => $product->name,
                    ]
                );

                if ($offer->wasRecentlyCreated) {
                    $offersCreated++;
                }

                $decision = AiMatchingDecision::firstOrCreate(
                    [
                        'tenant_id'        => $product->tenant_id,
                        'scraped_raw_name' => $product->name,
                    ],
                    [
                        'existing_product_id' => $product->id,
                        'is_match'            => true,
                    ]
                );

                if ($decision->wasRecentlyCreated) {
                    $decisionsCreated++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done! Created {$offersCreated} offers and {$decisionsCreated} AI matching decisions.");

        return self::SUCCESS;
    }
}
