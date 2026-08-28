<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidProductEvaluation;

/**
 * Spec 039 T1 — single validated evaluation schema shared by both producers:
 * the Gemini Bouncer ({@see \App\Jobs\ProcessPendingProduct}) and, from Spec
 * 039 T3/T4 onward, the operator-session overflow path
 * (`pw2d:products:apply-evaluations`). One schema feeds the one finalize
 * path, {@see \App\Actions\FinalizeProductEvaluation}.
 *
 * Immutable — built exclusively via {@see self::fromArray()}, which validates
 * and throws {@see InvalidProductEvaluation} (naming the offending field) on
 * any violation, INCLUDING a non-array payload (Gemini's `parsed` is `?array`
 * — a non-JSON/prose reply decodes to `null`; review HIGH-1, 2026-08-28).
 *
 * `status` compatibility note: Gemini's actual "scored" JSON payload has never
 * included a `status` key (only the "ignored" shape does — see
 * `AiService::evaluateProduct()`'s prompt). `fromArray()` preserves that:
 * status is `ignored` only when the raw `status` value is literally
 * `'ignored'`; anything else (including the key being entirely absent) is
 * `scored`. This mirrors `ProcessPendingProduct`'s pre-existing
 * `($parsed['status'] ?? null) === 'ignored'` check exactly, so this
 * formalisation changes no existing behaviour.
 *
 * `wrong_category` is a `reason` value under `status = ignored` (Spec 039
 * §2 T1), not a third status — {@see FinalizeProductEvaluation} branches on
 * `reason() === 'wrong_category'` to route it to category-rejection instead
 * of the ordinary ignore flag.
 *
 * Strictness rule (review M2/M3, 2026-08-28): this object accepts everything
 * the *old* (pre-extraction) `ProcessPendingProduct` job accepted from
 * Gemini. An `ignored` `reason` is any non-empty string (trimmed); a missing
 * reason defaults to `''`, exactly like the old job's `$parsed['reason'] ??
 * ''` — neither throws. Free text (`ai_summary`, feature `reason`) is
 * truncated at its cap with `mb_substr()`, never rejected for length; feature
 * `reason` is nullable. `self::VALID_REASONS` is kept only as a documented
 * list for callers that DO want to enforce the four-value enum — the
 * operator-file apply path ({@see \App\Actions\ApplyProductEvaluations}, T4),
 * where the producer is under our control and strictness costs nothing. This
 * object itself never checks a reason against it.
 *
 * Unknown feature names are NOT validated against a category here — that is
 * an apply-time concern (T4 step 3 / the finalize action), because this
 * object has no category context.
 */
final class ProductEvaluation
{
    /**
     * The four reasons Gemini's prompt (and the T4 apply path) recognise.
     * NOT enforced by this class — see the strictness-rule docblock above.
     */
    public const VALID_REASONS = [
        'accessory_or_bundle',
        'generic_white_label',
        'renewed_or_refurbished',
        'wrong_category',
    ];

    /**
     * @param array<string, array{score: float, reason: ?string}> $features
     */
    private function __construct(
        private readonly string $status,
        private readonly ?string $reason,
        private readonly ?string $name,
        private readonly ?string $brand,
        private readonly ?string $aiSummary,
        private readonly ?int $priceTier,
        private readonly ?float $amazonRating,
        private readonly ?int $amazonReviewsCount,
        private readonly array $features,
    ) {}

    /**
     * @param mixed $raw Decoded JSON payload — `?array` from
     *   `GeminiService::generate()['parsed']` (`json_decode()` yields `null`
     *   for a non-JSON/prose reply), or arbitrary decoded JSON from a T4
     *   evaluations file row.
     */
    public static function fromArray(mixed $raw): self
    {
        if (!is_array($raw)) {
            throw new InvalidProductEvaluation('payload', 'expected a JSON object');
        }

        $isIgnored = ($raw['status'] ?? null) === 'ignored';

        return $isIgnored ? self::buildIgnored($raw) : self::buildScored($raw);
    }

    /**
     * `reason` is any non-empty string here (trimmed) — the four-value enum
     * is enforced only at T4 apply time (see class docblock). A missing
     * reason defaults to `''`, matching the old job's `$parsed['reason'] ??
     * ''`; neither case throws.
     */
    private static function buildIgnored(array $raw): self
    {
        $rawReason = $raw['reason'] ?? '';
        $reason    = is_string($rawReason) ? trim($rawReason) : '';

        return new self(
            status: 'ignored',
            reason: $reason,
            name: null,
            brand: null,
            aiSummary: null,
            priceTier: null,
            amazonRating: null,
            amazonReviewsCount: null,
            features: [],
        );
    }

    private static function buildScored(array $raw): self
    {
        // "absent when scored" (spec §2 T1) — a scored payload must not carry
        // a leftover ignore reason.
        if (array_key_exists('reason', $raw) && $raw['reason'] !== null) {
            throw new InvalidProductEvaluation('reason', 'must be absent when status is scored');
        }

        $name      = self::requiredString($raw, 'name', 255);
        $brand     = self::requiredString($raw, 'brand', 100);
        // Required non-empty (it is rendered), but — unlike name/brand — free
        // text is truncated rather than rejected for length (review M3): the
        // Bouncer path is already lossy by design (see capProductName()).
        $aiSummary = self::requiredTruncatedString($raw, 'ai_summary', 600);

        return new self(
            status: 'scored',
            reason: null,
            name: $name,
            brand: $brand,
            aiSummary: $aiSummary,
            priceTier: self::optionalPriceTier($raw),
            amazonRating: self::optionalFloat($raw, 'amazon_rating'),
            amazonReviewsCount: self::optionalInt($raw, 'amazon_reviews_count'),
            features: self::validatedFeatures($raw),
        );
    }

    private static function requiredString(array $raw, string $field, int $maxLength): string
    {
        $value = $raw[$field] ?? null;

        if (!is_string($value) || $value === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidProductEvaluation($field);
        }

        return $value;
    }

    /**
     * Like {@see self::requiredString()} but truncates an over-length value
     * with `mb_substr()` instead of rejecting it (review M3) — the field must
     * still be present and non-empty.
     */
    private static function requiredTruncatedString(array $raw, string $field, int $maxLength): string
    {
        $value = $raw[$field] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidProductEvaluation($field);
        }

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Coerces rather than throws (review LOW-5): an integer-like value in
     * {1, 2, 3} (int, or numeric string/float such as `'2'`/`2.0`) is kept;
     * anything else (0, 4, non-numeric, non-integral) becomes `null`, which
     * falls back to the product's own existing tier at apply time.
     */
    private static function optionalPriceTier(array $raw): ?int
    {
        if (!array_key_exists('price_tier', $raw) || $raw['price_tier'] === null) {
            return null;
        }

        $value = $raw['price_tier'];

        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        if ($float !== floor($float)) {
            return null;
        }

        $int = (int) $float;

        return in_array($int, [1, 2, 3], true) ? $int : null;
    }

    private static function optionalFloat(array $raw, string $field): ?float
    {
        $value = self::optionalNumber($raw, $field);

        return $value === null ? null : (float) $value;
    }

    private static function optionalInt(array $raw, string $field): ?int
    {
        $value = self::optionalNumber($raw, $field);

        return $value === null ? null : (int) $value;
    }

    private static function optionalNumber(array $raw, string $field): float|int|null
    {
        if (!array_key_exists($field, $raw) || $raw[$field] === null) {
            return null;
        }

        if (!is_numeric($raw[$field])) {
            throw new InvalidProductEvaluation($field);
        }

        return $raw[$field];
    }

    /**
     * Mirrors the old (pre-extraction) job's loop shape exactly: `is_array($value)
     * ? extract score/reason : (float) $value` — a bare numeric entry
     * (`"Feature": 72`) is valid with `reason = null`, numeric-string scores
     * are coerced, and `reason` is optional/nullable and truncated at its cap
     * rather than rejected (review M3). `0` is a valid score here — the
     * legacy `score > 0` guard in {@see \App\Actions\FinalizeProductEvaluation::applyFeatureScores()}
     * is what actually skips writing it, on both producers' paths. Only a
     * non-numeric, negative, or >100 score throws.
     *
     * @return array<string, array{score: float, reason: ?string}>
     */
    private static function validatedFeatures(array $raw): array
    {
        if (!array_key_exists('features', $raw) || !is_array($raw['features'])) {
            throw new InvalidProductEvaluation('features');
        }

        $features = [];

        foreach ($raw['features'] as $name => $entry) {
            if (!is_string($name) || $name === '') {
                throw new InvalidProductEvaluation('features');
            }

            // A feature the AI declined to score — dropped, per the accessor
            // contract (never surfaces via features()).
            if ($entry === null) {
                continue;
            }

            $score  = is_array($entry) ? ($entry['score'] ?? null)  : $entry;
            $reason = is_array($entry) ? ($entry['reason'] ?? null) : null;

            if (!is_numeric($score) || (float) $score < 0 || (float) $score > 100) {
                throw new InvalidProductEvaluation("features.{$name}.score");
            }

            if ($reason !== null && !is_string($reason)) {
                $reason = null;
            }

            $features[$name] = [
                'score'  => (float) $score,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 300),
            ];
        }

        return $features;
    }

    public function isIgnored(): bool
    {
        return $this->status === 'ignored';
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function brand(): ?string
    {
        return $this->brand;
    }

    public function aiSummary(): ?string
    {
        return $this->aiSummary;
    }

    public function priceTier(): ?int
    {
        return $this->priceTier;
    }

    public function amazonRating(): ?float
    {
        return $this->amazonRating;
    }

    public function amazonReviewsCount(): ?int
    {
        return $this->amazonReviewsCount;
    }

    /**
     * @return array<string, array{score: float, reason: ?string}>
     */
    public function features(): array
    {
        return $this->features;
    }
}
