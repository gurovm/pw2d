<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single source of the AI-prose banned-phrase list (Spec 043).
 *
 * `AiService::generateLandingPageContent()` renders its STYLE CONTRACT from these
 * constants, and the VS-page save command (`pw2d:vs-pages:save`) rejects prose that
 * contains any of them — so the prompt and the guard can never drift apart.
 */
final class ProseStyle
{
    /**
     * Stock openers; `{category}` is replaced by the caller (prompt) or ignored (guard).
     *
     * @var list<string>
     */
    public const STOCK_OPENERS = [
        'Finding the right {category} can transform...',
        "In today's market...",
        'When it comes to {category}...',
    ];

    /**
     * Cliché words and phrases — banned individually, anywhere in the text.
     * The last entry is a pattern description; see PATTERNS for how it is matched.
     *
     * @var list<string>
     */
    public const CLICHES = [
        'cut through the noise',
        'game-changer',
        'elevate your experience',
        'look no further',
        'packs a punch',
        'seamless',
        'boasts',
        'comprehensive',
        'delve',
        'robust',
        'stands out from the crowd',
        'takes X to the next level',
    ];

    /**
     * Banned phrases that are literal in the guard but not listed as quotable
     * phrases in the prompt (the openers are shown there with a category slot).
     *
     * @var list<string>
     */
    private const OPENER_PHRASES = [
        "in today's market",
        'when it comes to',
    ];

    /**
     * Shape bans, matched as regexes against tag-stripped text.
     * Label => pattern (the label is what violations() reports).
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        'takes X to the next level' => '/\btakes\s+(?:\S+\s+){1,4}?to\s+the\s+next\s+level\b/iu',
        "whether you're"            => '/\bwhether\s+you(?:\'|’)?re\b/iu',
        'not just X, but Y'         => '/\bnot\s+just\b[^.!?]{0,80}?\bbut\b/iu',
    ];

    /**
     * Banned phrases found in the text (case-insensitive, whole words, HTML ignored).
     *
     * @return list<string>
     */
    public static function violations(string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace('’', "'", $text);

        $found = [];

        $literals = array_merge(
            array_filter(self::CLICHES, fn (string $p) => !str_contains($p, ' X ')),
            self::OPENER_PHRASES,
        );

        foreach ($literals as $phrase) {
            $regex = '/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/iu';

            if (preg_match($regex, $text) === 1) {
                $found[] = $phrase;
            }
        }

        foreach (self::PATTERNS as $label => $regex) {
            if (preg_match($regex, $text) === 1) {
                $found[] = $label;
            }
        }

        return array_values(array_unique($found));
    }

    /** Stock openers as the prompt quotes them, e.g. `"Finding the right Kettles can transform...", ...`. */
    public static function promptOpeners(string $categoryName): string
    {
        return self::quoteList(array_map(
            fn (string $o) => str_replace('{category}', $categoryName, $o),
            self::STOCK_OPENERS,
        ));
    }

    /** Cliché list as the prompt quotes it. */
    public static function promptCliches(): string
    {
        return self::quoteList(self::CLICHES);
    }

    /** @param list<string> $items */
    private static function quoteList(array $items): string
    {
        return implode(', ', array_map(fn (string $i) => '"' . $i . '"', $items));
    }
}
