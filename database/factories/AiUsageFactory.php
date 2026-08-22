<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiUsage>
 */
class AiUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'          => null,
            'purpose'            => 'evaluate_product',
            'model'              => 'gemini-2.5-pro',
            'input_tokens'       => $this->faker->numberBetween(500, 3000),
            'output_tokens'      => $this->faker->numberBetween(200, 1500),
            'thinking_tokens'    => $this->faker->numberBetween(0, 200),
            'estimated_cost_usd' => $this->faker->randomFloat(6, 0.0001, 0.05),
        ];
    }
}
