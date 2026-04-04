<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductFeatureValue>
 */
class ProductFeatureValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => \App\Models\Product::factory(),
            'feature_id' => \App\Models\Feature::factory(),
            'raw_value' => $this->faker->randomFloat(2, 0, 100),
            'explanation' => $this->faker->optional()->sentence(),
        ];
    }
}
