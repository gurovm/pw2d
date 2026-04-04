<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Encapsulates all Gemini API interaction: URL construction, auth,
 * generation config, response parsing, and error handling.
 *
 * Callers build their own prompts (domain-specific) and pass them here.
 * The service handles transport + parsing only.
 */
class GeminiService
{
    /**
     * Send a prompt to Gemini and return the parsed response.
     *
     * @param string      $prompt  The full prompt text
     * @param array       $config  Override generation config (temperature, maxOutputTokens, timeout, thinkingConfig, etc.)
     * @param string|null $model   Override the model (defaults to config value)
     * @return array{content: string, parsed: ?array, finish_reason: string}
     *
     * @throws \Exception on API error or truncation
     */
    public function generate(string $prompt, array $config = [], ?string $model = null): array
    {
        $apiKey  = config('services.gemini.api_key');
        $model   = $model ?? config('services.gemini.site_model');
        $timeout = (int) ($config['timeout'] ?? 30);

        // Separate top-level keys from generationConfig
        $topLevelKeys = ['timeout', 'thinkingConfig'];
        $generationConfig = array_merge(
            ['temperature' => 0.3, 'maxOutputTokens' => 3000],
            collect($config)->except($topLevelKeys)->toArray()
        );

        $payload = [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => $generationConfig,
        ];

        // thinkingConfig lives inside generationConfig for Gemini v1beta
        if (!empty($config['thinkingConfig'])) {
            $payload['generationConfig']['thinkingConfig'] = $config['thinkingConfig'];
        }

        try {
            $response = Http::timeout($timeout)
                ->retry(
                    3,
                    fn (int $attempt) => $attempt * 2000,
                    when: fn (\Throwable $e) => $e instanceof \Illuminate\Http\Client\RequestException
                        && $e->response?->status() === 429,
                    throw: false,
                )
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                    $payload
                );
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $status = $e->response?->status();
            throw new \Exception(
                $status === 429
                    ? 'Gemini rate limit hit. Please retry.'
                    : "Gemini API error: {$status}"
            );
        }

        if (! $response->successful()) {
            $status = $response->status();
            throw new \Exception(
                $status === 429
                    ? 'Gemini rate limit hit. Please retry.'
                    : "Gemini API error: {$status}"
            );
        }

        $result       = $response->json();
        $finishReason = $result['candidates'][0]['finishReason'] ?? 'UNKNOWN';

        if ($finishReason === 'MAX_TOKENS') {
            throw new \Exception('Gemini response truncated (MAX_TOKENS).');
        }

        // When thinking is enabled, parts[0] is the internal reasoning (thought: true).
        // Iterate all parts and keep the last one where `thought` is not set — that is the actual output.
        $parts = $result['candidates'][0]['content']['parts'] ?? [];
        $raw = '';
        foreach ($parts as $part) {
            if (empty($part['thought'])) {
                $raw = $part['text'] ?? '';
            }
        }

        // Strip markdown code fences
        $content = trim(preg_replace('/^```json\s*|\s*```$/m', '', trim($raw)));

        return [
            'content'       => $content,
            'parsed'        => json_decode($content, true),
            'finish_reason' => $finishReason,
        ];
    }
}
