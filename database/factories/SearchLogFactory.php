<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SearchLog>
 */
class SearchLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(['global_search', 'homepage_ai', 'category_ai']),
            'query' => $this->faker->sentence(),
            'category_name' => $this->faker->optional()->word(),
            'user_id' => null,
            'results_count' => $this->faker->optional()->numberBetween(0, 50),
            'response_summary' => $this->faker->optional()->sentence(),
        ];
    }
}
