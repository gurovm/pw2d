<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * The single normaliser for a product's `model` identity (Spec 041 amendment).
 *
 * Used by BOTH the landing-page picker and the apply-models group report, so the
 * report can never disagree with the picker. "+" is identity in this domain
 * (MV7 vs MV7+, GLXD14 vs GLXD14+), so it becomes "plus" before the strip.
 */
final class ModelIdentity
{
    /**
     * Spec 034 §1 — words that precede a model-number token but carry no
     * identity of their own (generic marketing/category language), so they
     * must never be folded into the model key.
     */
    private const MODEL_TOKEN_STOPWORDS = [
        'series', 'gen', 'edition', 'professional', 'espresso',
        'machine', 'coffee', 'super', 'automatic', 'fully',
    ];

    /**
     * Spec 034 §1 — a preceding qualifier token (e.g. "giga") is folded into
     * the model key only when both it AND the bare candidate token are short.
     * Long candidate tokens (e.g. a full SKU like "ecam29043sb") are already
     * self-identifying and must NOT be prefixed — verified against the
     * spec's hand-checked table (Magnifica Evo ECAM29043SB -> "ecam29043sb",
     * not "evoecam29043sb"). See docs/questions.md for this judgment call.
     */
    private const MODEL_TOKEN_MAX_JOIN_LEN = 5;

    public static function normalize(?string $model): string
    {
        $lower = str_replace('+', 'plus', mb_strtolower((string) $model));

        return preg_replace('/[^a-z0-9]+/', '', $lower) ?? '';
    }

    /**
     * True when the two products are the same machine (colour/variant copies).
     * Spec 043: the one pairwise decision shared by SelectLandingPagePicks,
     * SelectRivals and the VS-page save command.
     */
    public static function sameModel(Product $a, Product $b): bool
    {
        return self::match($a, $b) !== null;
    }

    /**
     * The pairwise same-model decision (extracted verbatim from SelectLandingPagePicks'
     * `$isDuplicateOfPicked`, Spec 034 §1 / Spec 041 §3 / Addendum A §2b).
     *
     * Returns null when the products are different models, else the path that decided
     * ('model' | 'heuristic' | 'similarity') and its key, for the caller's logging.
     * Needs `brand` (id,name) loaded to avoid a lazy load in modelKey().
     *
     * @return array{path: string, key: string}|null
     */
    public static function match(Product $candidate, Product $other): ?array
    {
        $candidateModel = self::normalize($candidate->model);
        $otherModel     = self::normalize($other->model);

        if ($candidateModel !== '' && $otherModel !== '' && $candidate->brand_id !== null && $other->brand_id !== null) {
            return ($candidate->brand_id === $other->brand_id && $candidateModel === $otherModel)
                ? ['path' => 'model', 'key' => $candidate->brand_id . ':' . $candidateModel]
                : null;
        }

        $candidateKey = self::modelKey($candidate);
        $otherKey     = self::modelKey($other);

        if ($candidateKey !== null && $otherKey !== null) {
            // Both sides have a confirmed model identity — it alone decides; a
            // difference must veto the similarity fallback (Spec 034).
            return $candidateKey === $otherKey
                ? ['path' => 'heuristic', 'key' => $candidateKey]
                : null;
        }

        $candidateNorm = self::normalizeName($candidate->name);

        if ($candidateNorm === '') {
            return null;
        }

        $otherNorm = self::normalizeName($other->name);

        if ($otherNorm === '') {
            return null;
        }

        if (str_contains($candidateNorm, $otherNorm) || str_contains($otherNorm, $candidateNorm)) {
            return ['path' => 'similarity', 'key' => 'contains'];
        }

        similar_text($candidateNorm, $otherNorm, $percent);

        return $percent >= 85.0
            ? ['path' => 'similarity', 'key' => (string) round($percent, 1) . '%']
            : null;
    }

    /**
     * Lowercase, strip everything but alphanumerics — the normalization Addendum A §2b's
     * duplicate guard compares ("Keychron Q6 Max Black" vs "Keychron Q6 Max - Black" both
     * normalize to "keychronq6maxblack").
     */
    private static function normalizeName(?string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $name)) ?? '';
    }

    /**
     * Spec 034 §1 — the product's identity key: `{brand_id}:{model token}`, or
     * null when the product has no brand or no digit-bearing token at all (e.g.
     * "Gaggia Cadorna Prestige"), in which case $isDuplicateOfPicked falls back
     * to the normalized-name similarity guard instead.
     *
     * Two products are variants of the same machine when this key is non-null
     * and equal on both sides — verified against Spec 034's real-name table:
     *   "JURA Z10 Super-Automatic Espresso Machine - Gen 1"  -> z10
     *   "Jura Z10 Aluminum White"                            -> z10 (same machine)
     *   "JURA GIGA 10 Espresso Machine"                      -> giga10
     *   "Jura GIGA X8 Professional"                          -> gigax8
     *   "JURA X10 Dark Inox" / "JURA J10 Twin"               -> x10 / j10 (distinct)
     *   "Philips 4400 Series Fully Automatic"                -> 4400
     *   "De'Longhi Magnifica Evo ECAM29043SB"                -> ecam29043sb
     */
    private static function modelKey(Product $product): ?string
    {
        if ($product->brand_id === null) {
            return null;
        }

        $tokens = self::tokenize($product->name);

        // Candidate tokens are those containing at least one digit; take the
        // FIRST one in name order — model numbers lead, generation/SKU suffixes
        // trail, so this drops "Gen 1" and trailing catalogue numbers for free.
        $candidateIndex = null;

        foreach ($tokens as $i => $token) {
            if (preg_match('/\d/', $token) === 1) {
                $candidateIndex = $i;
                break;
            }
        }

        if ($candidateIndex === null) {
            return null;
        }

        $modelToken = $tokens[$candidateIndex];

        // Join to the immediately-preceding alpha token (e.g. "giga" + "10" ->
        // "giga10") only when BOTH sides are short (<= MODEL_TOKEN_MAX_JOIN_LEN)
        // and the preceding token is not the brand name or a generic stopword.
        // The candidate-side length check keeps an already-self-identifying long
        // SKU (e.g. "ecam29043sb") from being prefixed with unrelated line-name
        // copy ("evo") it doesn't need — see the class-const doc comment.
        if ($candidateIndex > 0 && strlen($modelToken) <= self::MODEL_TOKEN_MAX_JOIN_LEN) {
            $prevToken = $tokens[$candidateIndex - 1];

            if (
                preg_match('/\d/', $prevToken) !== 1
                && strlen($prevToken) <= self::MODEL_TOKEN_MAX_JOIN_LEN
                && !in_array($prevToken, self::MODEL_TOKEN_STOPWORDS, true)
                && !in_array($prevToken, self::tokenize((string) $product->brand?->name), true)
            ) {
                $modelToken = $prevToken . $modelToken;
            }
        }

        return $product->brand_id . ':' . $modelToken;
    }

    /**
     * Lowercase and split on every run of non-alphanumeric characters, dropping
     * empty tokens (e.g. "De'Longhi Magnifica Evo ECAM29043SB" -> ["de",
     * "longhi", "magnifica", "evo", "ecam29043sb"]).
     *
     * @return list<string>
     */
    private static function tokenize(string $value): array
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($value)) ?? '';

        return array_values(array_filter(explode(' ', trim($normalized)), fn (string $t) => $t !== ''));
    }
}
