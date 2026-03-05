<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'category_id' => \App\Models\Category::factory(),
            'brand_id' => \App\Models\Brand::factory(),
            'price_tier' => $this->faker->numberBetween(1, 3),
            'amazon_rating' => $this->faker->numberBetween(30, 50) / 10,
            'amazon_reviews_count' => $this->faker->numberBetween(10, 1000),
        ];
    }
}
