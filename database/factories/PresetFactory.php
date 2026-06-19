<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Preset>
 */
class PresetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => \App\Models\Category::factory(),
            'name' => $this->faker->words(2, true),
            'sort_order' => $this->faker->numberBetween(0, 10),
            'seo_description' => $this->faker->optional()->sentence(),
            'seo_content' => null,
        ];
    }

    /**
     * State: preset with fully-populated seo_content (intro + faqs).
     * Use this in tests that need a preset with AI-generated content already saved.
     */
    public function seoContent(
        string $intro = '<p>Use-case specific intro paragraph for this preset.</p>',
        array $faqs = [
            ['question' => 'Which product is best for this use-case?', 'answer' => 'It depends on your budget and priorities.'],
            ['question' => 'What feature matters most?', 'answer' => 'The top-weighted feature for this use-case defines the ranking.'],
        ],
    ): static {
        return $this->state(fn (array $attributes) => [
            'seo_content' => [
                'intro' => $intro,
                'faqs'  => $faqs,
            ],
        ]);
    }
}
