<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Spec 039 T5 / Spec 037 T2 — the read-only result of
 * {@see \App\Actions\CompareProductEvaluations::execute()}: diffing a batch
 * of {@see ProductEvaluation} rows against each product's already-stored
 * evaluation.
 *
 * `$diffs` holds one row per input evaluation, in input order, each shaped
 * either as an "unmatched" row (`status: 'unmatched'`, `reason` set, every
 * other key null/empty) or a "compared" row (`status: 'compared'`, `ignore`/
 * `brand`/`features`/`features_skipped`/`condition_word_hit` populated). See
 * {@see \App\Actions\CompareProductEvaluations} for the exact shape.
 */
final readonly class EvaluationComparison
{
    /**
     * @param list<array<string, mixed>> $diffs
     * @param list<string> $gateReasons
     * @param array{product_id: int, feature: string, delta: float}|null $featureMaxDelta
     */
    public function __construct(
        public int $totalRows,
        public int $comparedRows,
        public int $unmatchedRows,
        public int $ignoreAgreementMatches,
        public float $ignoreAgreementRate,
        public int $brandComparisons,
        public int $brandRawExactMatches,
        public float $brandRawExactRate,
        public int $brandNormalizedExactMatches,
        public float $brandNormalizedExactRate,
        public int $featurePairsCompared,
        public int $featurePairsSkipped,
        public float $featureMad,
        public ?array $featureMaxDelta,
        public int $conditionWordHits,
        public string $gate,
        public array $gateReasons,
        public array $diffs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rows' => [
                'total'     => $this->totalRows,
                'compared'  => $this->comparedRows,
                'unmatched' => $this->unmatchedRows,
            ],
            'is_ignored_agreement' => [
                'rate'     => $this->ignoreAgreementRate,
                'matches'  => $this->ignoreAgreementMatches,
                'compared' => $this->comparedRows,
            ],
            'brand' => [
                'raw_exact_rate'           => $this->brandRawExactRate,
                'raw_exact_matches'        => $this->brandRawExactMatches,
                'normalized_exact_rate'    => $this->brandNormalizedExactRate,
                'normalized_exact_matches' => $this->brandNormalizedExactMatches,
                'compared'                 => $this->brandComparisons,
            ],
            'features' => [
                'mad'            => $this->featureMad,
                'max_delta'      => $this->featureMaxDelta,
                'pairs_compared' => $this->featurePairsCompared,
                'pairs_skipped'  => $this->featurePairsSkipped,
            ],
            'ai_summary' => [
                'condition_word_hits' => $this->conditionWordHits,
            ],
            'gate' => [
                'verdict' => $this->gate,
                'reasons' => $this->gateReasons,
            ],
            'diffs' => $this->diffs,
        ];
    }
}
