# Spec 005: Extract GeminiService

**Priority:** HIGH
**Audit refs:** Reviewer #12 (Gemini pattern duplicated 5x), Security #10 (API key in URL), Security #19 (prompt injection)

---

## Problem

The Gemini API call pattern (URL construction, config loading, HTTP call, response parsing, markdown stripping) is copy-pasted across 5 files:

1. `app/Http/Controllers/Api/ProductImportController.php:113-130`
2. `app/Jobs/ProcessPendingProduct.php:112-129`
3. `app/Jobs/RescanProductFeatures.php:89-106`
4. `app/Livewire/GlobalSearch.php:139-151`
5. (Potentially Filament actions)

Each copy independently constructs the URL with `?key={$apiKey}` in the query string, which leaks the key into server logs.

## Solution

Extract a `GeminiService` that encapsulates all Gemini API interaction.

### `app/Services/GeminiService.php` (new)

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GeminiService
{
    /**
     * Send a prompt to Gemini and return the parsed JSON response.
     *
     * @param string $prompt     The full prompt text
     * @param array  $config     Override generation config (temperature, maxOutputTokens, etc.)
     * @param string|null $model Override the model (defaults to config value)
     * @return array{content: string, parsed: ?array, finish_reason: string}
     * @throws \Exception on API error or truncation
     */
    public function generate(string $prompt, array $config = [], ?string $model = null): array
    {
        $apiKey   = config('services.gemini.api_key');
        $model    = $model ?? config('services.gemini.site_model');

        $response = Http::timeout(30)
            ->withHeaders(['x-goog-api-key' => $apiKey])  // Header auth, not query string
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                [
                    'contents'         => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => array_merge([
                        'temperature'    => 0.3,
                        'maxOutputTokens' => 3000,
                    ], $config),
                ]
            );

        if (!$response->successful()) {
            $status = $response->status();
            throw new \Exception(
                $status === 429
                    ? 'Gemini rate limit hit. Please retry.'
                    : "Gemini API error: {$status}"
            );
        }

        $result = $response->json();
        $finishReason = $result['candidates'][0]['finishReason'] ?? 'UNKNOWN';

        if ($finishReason === 'MAX_TOKENS') {
            throw new \Exception('Gemini response truncated (MAX_TOKENS).');
        }

        $raw = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Strip markdown code fences
        $content = trim(preg_replace('/^```json\s*|\s*```$/m', '', trim($raw)));

        return [
            'content'       => $content,
            'parsed'        => json_decode($content, true),
            'finish_reason' => $finishReason,
        ];
    }
}
```

### Key design decisions:

1. **API key via header** (`x-goog-api-key`) instead of query string — Google's Gemini API supports both. This prevents key leakage in logs.
2. **Returns both raw `content` and `parsed`** — callers can check `parsed` for null (JSON parse failure) and fall back to raw for error logging.
3. **Throws on errors** — callers wrap in try/catch as they already do.
4. **No prompt building** — prompts are domain-specific; the service only handles transport + parsing.

### Refactor each caller

Each file replaces ~15-20 lines of HTTP + parsing with:

```php
$gemini = app(GeminiService::class);
$result = $gemini->generate($prompt, ['maxOutputTokens' => 4000]);
$parsed = $result['parsed'];
```

### SSRF host allowlist extraction

The duplicated `$allowedImageHosts` array (in `ProductImportController` and `ProcessPendingProduct`) should also be extracted — but into a simple config value, not the Gemini service:

**File:** `config/services.php`
```php
'amazon' => [
    'allowed_image_hosts' => [
        'm.media-amazon.com',
        'images-na.ssl-images-amazon.com',
        'images-eu.ssl-images-amazon.com',
        'images-fe.ssl-images-amazon.com',
    ],
],
```

## Files Modified/Created

| File | Action |
|------|--------|
| `app/Services/GeminiService.php` | **Create** |
| `config/services.php` | Add `amazon.allowed_image_hosts` |
| `app/Http/Controllers/Api/ProductImportController.php` | Replace inline Gemini call |
| `app/Jobs/ProcessPendingProduct.php` | Replace inline Gemini call |
| `app/Jobs/RescanProductFeatures.php` | Replace inline Gemini call |
| `app/Livewire/GlobalSearch.php` | Replace inline Gemini call |

## Testing

- **Unit:** `GeminiService::generate()` sends API key in header, not query string.
- **Unit:** `GeminiService::generate()` throws on 429/500 status.
- **Unit:** `GeminiService::generate()` throws on `MAX_TOKENS` finish reason.
- **Unit:** `GeminiService::generate()` strips markdown fences from response.
- **Feature:** Existing import + search flows work end-to-end after refactor (mock HTTP).
