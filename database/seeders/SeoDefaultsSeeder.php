<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class SeoDefaultsSeeder extends Seeder
{
    /**
     * Seed sensible SEO defaults for known tenants.
     *
     * Safe to run multiple times — only sets keys that are currently null/missing.
     * Existing manually-configured values are never overwritten.
     *
     * @return void
     */
    public function run(): void
    {
        foreach (['pw2d', 'coffee2decide'] as $id) {
            $tenant = Tenant::find($id);

            if (!$tenant) {
                $this->command->info("Tenant [{$id}] not found — skipping.");
                continue;
            }

            $brand = $tenant->brand_name ?? $id;

            // Only set keys that are not already configured — don't clobber manual edits.
            $tenant->seo_title_suffix        ??= $brand;
            $tenant->seo_default_title       ??= "{$brand} — AI Product Recommendations";
            $tenant->seo_default_description ??= "Discover the best products tailored to your exact needs using {$brand}'s AI-powered recommendation engine.";

            $tenant->save();

            $this->command->info("SEO defaults seeded for tenant [{$id}].");
        }
    }
}
