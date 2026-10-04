<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessPendingProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 041 Part 1 — the category's bouncer_notes reach the Gemini prompt.
 */
class ProcessPendingProductCategoryNotesTest extends TestCase
{
    use RefreshDatabase;

    private function runJob(?string $notes): string
    {
        $category = Category::factory()->create(['bouncer_notes' => $notes]);
        \App\Models\Feature::factory()->create(['category_id' => $category->id]);
        $product  = Product::factory()->create(['category_id' => $category->id, 'status' => 'pending_ai', 'is_ignored' => false]);
        $prompt   = '';

        Http::fake(function ($request) use (&$prompt) {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'];

            return Http::response(['candidates' => [[
                'content' => ['parts' => [['text' => json_encode(['status' => 'ignored', 'reason' => 'wrong_category'])]]],
                'finishReason' => 'STOP',
            ]]]);
        });

        (new ProcessPendingProduct($product->id, $category->id))->handle();

        $this->assertStringContainsString('IGNORE RULE D', $prompt, 'the job must actually have called Gemini');

        return $prompt;
    }

    /** @test */
    public function the_category_notes_are_sent_to_gemini(): void
    {
        $this->assertStringContainsString(
            'Category-specific rules: Exclude mice, numpads, keyboard-and-mouse combos.',
            $this->runJob('Exclude mice, numpads, keyboard-and-mouse combos.')
        );
    }

    /** @test */
    public function no_notes_line_is_sent_when_the_category_has_none(): void
    {
        $this->assertStringNotContainsString('Category-specific rules', $this->runJob(null));
    }
}
