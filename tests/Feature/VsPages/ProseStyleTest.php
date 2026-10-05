<?php

declare(strict_types=1);

namespace Tests\Feature\VsPages;

use App\Models\Category;
use App\Services\AiService;
use App\Support\ProseStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProseStyleTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_finds_banned_phrases_case_insensitively(): void
    {
        $hits = ProseStyle::violations('<p>A SEAMLESS setup. It <strong>Boasts</strong> a lot.</p>');

        $this->assertEqualsCanonicalizing(['seamless', 'boasts'], $hits);
    }

    /** @test */
    public function it_matches_whole_words_only(): void
    {
        $this->assertSame([], ProseStyle::violations('<p>The seamlessly named machine and the robustness test.</p>'));
    }

    /** @test */
    public function it_ignores_html_markup_but_sees_text_split_by_tags(): void
    {
        $this->assertSame(['look no further'], ProseStyle::violations('<p>look <em>no</em> further</p>'));
        $this->assertSame([], ProseStyle::violations('<a href="/robust">plain link</a>'));
    }

    /** @test */
    public function it_catches_the_shape_bans_and_openers(): void
    {
        $hits = ProseStyle::violations("<p>Whether you're a pro or not, it's not just fast, but quiet. In today's market it takes brewing to the next level.</p>");

        $this->assertContains("whether you're", $hits);
        $this->assertContains('not just X, but Y', $hits);
        $this->assertContains("in today's market", $hits);
        $this->assertContains('takes X to the next level', $hits);
    }

    /** @test */
    public function clean_prose_has_no_violations(): void
    {
        $this->assertSame([], ProseStyle::violations('<p>The Bianca heats up in 20 minutes. The Drive takes 8.</p>'));
    }

    /** @test */
    public function the_landing_page_prompt_still_contains_every_banned_phrase(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => json_encode([
                        'intro' => '<p>x</p>', 'picks' => [], 'faqs' => [], 'methodology_note' => 'x',
                    ])]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ]),
        ]);

        $category = Category::factory()->create(['name' => 'Espresso Machines', 'slug' => 'espresso-prompt']);

        try {
            app(AiService::class)->generateLandingPageContent($category, [], [], 1);
        } catch (\Throwable) {
            // Response validation may reject the stub; only the outgoing prompt matters here.
        }

        $prompt = '';
        Http::assertSent(function ($request) use (&$prompt) {
            $prompt = (string) data_get($request->data(), 'contents.0.parts.0.text', json_encode($request->data()));

            return true;
        });

        $this->assertNotSame('', $prompt);

        foreach (ProseStyle::CLICHES as $phrase) {
            $this->assertStringContainsString('"' . $phrase . '"', $prompt, "Prompt lost banned phrase: {$phrase}");
        }

        $this->assertStringContainsString('"Finding the right Espresso Machines can transform..."', $prompt);
        $this->assertStringContainsString('"In today\'s market..."', $prompt);
        $this->assertStringContainsString('Whether you\'re', $prompt);
    }
}
