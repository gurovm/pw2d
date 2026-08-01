<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LandingPage>
 */
class LandingPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id'      => \App\Models\Category::factory(),
            'slug'             => $this->faker->unique()->slug(3),
            'title_template'   => null,
            'intro'            => '<p>We scored a bunch of products to find the best picks.</p>',
            // picks is NOT NULL at the DB level — default to an empty list; tests
            // that need real picks should override this explicitly.
            'picks'            => [],
            'faqs'             => null,
            'methodology_note' => null,
            'status'           => 'draft',
            'generated_at'     => now(),
        ];
    }

    /**
     * State: a published landing page (visible on the public /best/{slug} route).
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
        ]);
    }
}
