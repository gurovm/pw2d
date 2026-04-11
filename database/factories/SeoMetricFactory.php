<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SeoMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeoMetric>
 */
class SeoMetricFactory extends Factory
{
    protected $model = SeoMetric::class;

    /**
     * Default state — produces a GSC row. Call ->ga4() for GA4 rows.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = 'https://example.com' . $this->faker->unique()->slug(3);

        return [
            'tenant_id'        => 'test-tenant',
            'source'           => 'gsc',
            'url'              => $url,
            'url_hash'         => hash('sha256', $url),
            'metric_date'      => $this->faker->dateTimeBetween('-30 days', 'yesterday')->format('Y-m-d'),
            'gsc_impressions'  => $this->faker->numberBetween(0, 5000),
            'gsc_clicks'       => $this->faker->numberBetween(0, 500),
            'gsc_ctr'          => round($this->faker->randomFloat(4, 0, 0.5), 4),
            'gsc_position'     => round($this->faker->randomFloat(2, 1, 50), 2),
            'gsc_top_query'    => $this->faker->words(3, true),
            'ga4_sessions'     => null,
            'ga4_users'        => null,
            'ga4_engaged_sess' => null,
            'ga4_conversions'  => null,
            'ga4_bounce_rate'  => null,
        ];
    }

    /**
     * State for GSC rows — all GSC columns populated, GA4 columns null.
     */
    public function gsc(): static
    {
        return $this->state(fn (array $attributes) => [
            'source'           => 'gsc',
            'gsc_impressions'  => $this->faker->numberBetween(10, 5000),
            'gsc_clicks'       => $this->faker->numberBetween(0, 500),
            'gsc_ctr'          => round($this->faker->randomFloat(4, 0, 0.5), 4),
            'gsc_position'     => round($this->faker->randomFloat(2, 1, 50), 2),
            'gsc_top_query'    => $this->faker->words(3, true),
            'ga4_sessions'     => null,
            'ga4_users'        => null,
            'ga4_engaged_sess' => null,
            'ga4_conversions'  => null,
            'ga4_bounce_rate'  => null,
        ]);
    }

    /**
     * State for GA4 rows — all GA4 columns populated, GSC columns null.
     */
    public function ga4(): static
    {
        return $this->state(fn (array $attributes) => [
            'source'           => 'ga4',
            'gsc_impressions'  => null,
            'gsc_clicks'       => null,
            'gsc_ctr'          => null,
            'gsc_position'     => null,
            'gsc_top_query'    => null,
            'ga4_sessions'     => $this->faker->numberBetween(10, 2000),
            'ga4_users'        => $this->faker->numberBetween(8, 1800),
            'ga4_engaged_sess' => $this->faker->numberBetween(5, 1500),
            'ga4_conversions'  => $this->faker->numberBetween(0, 50),
            'ga4_bounce_rate'  => round($this->faker->randomFloat(4, 0, 0.8), 4),
        ]);
    }
}
