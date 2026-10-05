<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VsPage>
 */
class VsPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id'  => Category::factory(),
            'product_a_id' => Product::factory(),
            'product_b_id' => Product::factory(),
            'slug'         => $this->faker->unique()->slug(4),
            'title'        => 'A vs B: scores, price and which to buy',
            'intro'        => '<p>Two machines, one choice.</p>',
            'verdict'      => '<p>Buy A if you want speed; buy B if you want value.</p>',
            'sections'     => [
                'a_wins'         => '<p>A is faster.</p>',
                'b_wins'         => '<p>B is cheaper.</p>',
                'who_should_buy' => '<p>Pick by budget.</p>',
            ],
            'faqs'         => [['q' => 'Which is better?', 'a' => '<p>Depends on budget.</p>']],
            'status'       => 'draft',
            'generated_at' => now(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => 'published']);
    }
}
