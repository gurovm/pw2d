<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Spec 041 Part 2 — deterministic clean-up applied to the Bouncer's product
 * name AFTER the AI has answered (replaces the old `capProductName()`). The
 * raw Amazon title is never a name source; this only trims what the AI returned
 * and guarantees the name starts with the brand, because the product page
 * title is `{name} {category} — AI Review & Match Score`.
 */
final class ProductNameShaper
{
    private const MAX_WORDS = 8;

    /** Words that read as dangling once a cut leaves them at the end. */
    private const TRAILING_STOPWORDS = ['with', 'for', 'and', 'the', 'of', 'in'];

    /** Characters that join two things; meaningless at the end of a name. */
    private const TRAILING_CONNECTORS = '/[-–|\/&]+$/u';

    private function __construct() {}

    public static function shape(string $name, string $brand): string
    {
        $name = trim($name);

        // 1. Leading bracketed junk: 【Iron Gray】Brand ..., [New] Brand ...
        $name = preg_replace('/^(?:\s*[\[【（(][^\]】）)]*[\]】）)])+\s*/u', '', $name) ?? $name;

        // 2. Cut at the first character that starts a spec/bundle list.
        if (preg_match('/[,(|【]/u', $name, $m, PREG_OFFSET_CAPTURE) === 1 && $m[0][1] > 0) {
            $name = substr($name, 0, $m[0][1]);
        }

        // 3. Trailing connectors and stopwords.
        $name = self::trimTrailing($name);

        // 4. Brand first.
        $brand = trim($brand);
        if ($brand !== '' && $name !== '') {
            $name = self::withBrandPrefix($name, $brand);
        }

        // 5. Cap, then re-trim: the cut may have left a dangling word.
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $name  = implode(' ', array_slice($words, 0, self::MAX_WORDS));

        return self::trimTrailing($name);
    }

    private static function withBrandPrefix(string $name, string $brand): string
    {
        if (mb_stripos($name, $brand) === 0) {
            $rest = mb_substr($name, mb_strlen($brand));

            // Must end on a word boundary: brand "Rode" must not claim "Rodecaster".
            if ($rest === '' || preg_match('/^[\p{L}\p{N}]/u', $rest) !== 1) {
                return $brand . $rest;
            }
        }

        // Brand appears later in the name as a whole word: leave it where it is.
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($brand, '/') . '(?![\p{L}\p{N}])/iu', $name) === 1) {
            return $name;
        }

        return $brand . ' ' . $name;
    }

    /**
     * A `+` is NOT a trailing connector when attached to a token ("Shure MV7+"
     * is a real model name); a standalone ` +` is.
     */
    private static function trimTrailing(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        while ($words !== []) {
            $last = end($words);
            $stripped = preg_replace(self::TRAILING_CONNECTORS, '', $last) ?? $last;

            if ($stripped !== $last) {
                $words[array_key_last($words)] = $stripped;
                $last = $stripped;
            }

            if ($last === '' || $last === '+' || in_array(mb_strtolower($last), self::TRAILING_STOPWORDS, true)) {
                array_pop($words);

                continue;
            }

            break;
        }

        return implode(' ', $words);
    }
}
