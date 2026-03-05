<?php

namespace Tests\Feature\Livewire;

use App\Livewire\ComparisonHeader;
use App\Models\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ComparisonHeaderTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function renders_successfully()
    {
        $features = Feature::factory()->count(3)->make();
        
        Livewire::test(ComparisonHeader::class, [
            'features' => $features,
            'weights' => [],
            'priceWeight' => 50,
            'amazonRatingWeight' => 50,
            'categoryId' => 1,
        ])
        ->assertStatus(200);
    }

    /** @test */
    public function can_toggle_ai_chat()
    {
        $features = Feature::factory()->count(3)->make();

        Livewire::test(ComparisonHeader::class, [
            'features' => $features,
            'weights' => [],
            'priceWeight' => 50,
            'amazonRatingWeight' => 50,
            'categoryId' => 1,
        ])
        ->call('toggleAiChat')
        ->assertDispatched('toggle-ai-chat');
    }

    /** @test */
    public function emits_weights_updated_event_when_properties_change()
    {
        $features = Feature::factory()->count(1)->create(); // Create to have ID
        $weights = [$features[0]->id => 50];

        Livewire::test(ComparisonHeader::class, [
            'features' => $features,
            'weights' => $weights,
            'priceWeight' => 50,
            'amazonRatingWeight' => 50,
            'categoryId' => 1,
        ])
        ->set('priceWeight', 80)
        ->assertDispatched('weights-updated', function ($event, $data) {
             return $data['priceWeight'] === 80;
        })
        ->set('amazonRatingWeight', 90)
        ->assertDispatched('weights-updated', function ($event, $data) {
             return $data['amazonRatingWeight'] === 90;
        });
    }

    /** @test */
    public function submitting_ai_prompt_dispatches_event()
    {
        $features = Feature::factory()->count(1)->create();

        Livewire::test(ComparisonHeader::class, [
            'features' => $features,
            'weights' => [],
            'priceWeight' => 50,
            'amazonRatingWeight' => 50,
            'categoryId' => 1,
        ])
        ->set('aiPrompt', 'I need a cheap laptop')
        ->call('submitAiPrompt')
        ->assertDispatched('trigger-ai-concierge', function ($event, $data) {
             return $data['prompt'] === 'I need a cheap laptop';
        })
        ->assertSet('aiPrompt', '');
    }
}
