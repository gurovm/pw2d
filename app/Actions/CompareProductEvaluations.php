<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\InvalidProductEvaluation;
use App\Models\AiCategoryRejection;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\AiService;
use App\Support\EvaluationComparison;
use App\Support\ProductConditionGuard;
use App\Support\ProductEvaluation;

/**
 * Spec 039 §2 T5 — read-only diff core for the (not-yet-built) Spec 037 T2
 * `pw2d:ai:eval-model` harness. Takes the SAME file shape
 * `pw2d:products:apply-evaluations` reads (`{"evaluations": [...ProductEvaluation
 * rows...]}`) and diffs each row against the product's already-stored
 * evaluation — no product/candidate scoring happens here, only comparison.
 *
 * When Spec 037 T2 adds a live-model runner, it will call `evaluateProduct()`
 * per product, assemble the SAME evaluation-row shape this class already
 * consumes, and hand the result to {@see self::execute()} unchanged — this
 * class does not know or care whether a row came from a file or a live call.
 *
 * Writes nothing. Makes no AI calls.
 */
class CompareProductEvaluations
{
    /**
     * @param array<int, mixed> $evaluations Raw decoded `evaluations` array —
     *   same shape `ApplyProductEvaluations` reads.
     */
    public function execute(Tenant $tenant, array $evaluations): EvaluationComparison
    {
        $diffs = [];

        $ignoreCompared = 0;
        $ignoreMatches  = 0;

        $brandCompared           = 0;
        $brandRawMatches         = 0;
        $brandNormalizedMatches  = 0;

        $featurePairs    = 0;
        $featureSkipped  = 0;
        $featureDeltaSum = 0.0;
        $featureMaxDelta = null;

        $conditionHits = 0;

        foreach ($evaluations as $rawRow) {
            $diff    = $this->diffRow($tenant, $rawRow);
            $diffs[] = $diff;

            if ($diff['status'] !== 'compared') {
                continue;
            }

            $ignoreCompared++;
            if ($diff['ignore']['agree']) {
                $ignoreMatches++;
            }

            if ($diff['brand'] !== null) {
                $brandCompared++;
                if ($diff['brand']['raw_exact']) {
                    $brandRawMatches++;
                }
                if ($diff['brand']['normalized_exact']) {
                    $brandNormalizedMatches++;
                }
            }

            foreach ($diff['features'] as $feature) {
                $featurePairs++;
                $featureDeltaSum += $feature['delta'];

                if ($featureMaxDelta === null || $feature['delta'] > $featureMaxDelta['delta']) {
                    $featureMaxDelta = [
                        'product_id' => $diff['product_id'],
                        'feature'    => $feature['feature'],
                        'delta'      => $feature['delta'],
                    ];
                }
            }
            $featureSkipped += count($diff['features_skipped']);

            if ($diff['condition_word_hit']) {
                $conditionHits++;
            }
        }

        $comparedRows = $ignoreCompared;
        $unmatchedRows = count($diffs) - $comparedRows;

        $ignoreAgreementRate      = $comparedRows > 0 ? $ignoreMatches / $comparedRows : 0.0;
        $brandRawExactRate        = $brandCompared > 0 ? $brandRawMatches / $brandCompared : 0.0;
        $brandNormalizedExactRate = $brandCompared > 0 ? $brandNormalizedMatches / $brandCompared : 0.0;
        $featureMad               = $featurePairs > 0 ? $featureDeltaSum / $featurePairs : 0.0;

        [$gate, $gateReasons] = $this->evaluateGate(
            $comparedRows,
            $ignoreAgreementRate,
            $brandCompared,
            $brandNormalizedExactRate,
            $featurePairs,
            $featureMad,
        );

        return new EvaluationComparison(
            totalRows: count($diffs),
            comparedRows: $comparedRows,
            unmatchedRows: $unmatchedRows,
            ignoreAgreementMatches: $ignoreMatches,
            ignoreAgreementRate: $ignoreAgreementRate,
            brandComparisons: $brandCompared,
            brandRawExactMatches: $brandRawMatches,
            brandRawExactRate: $brandRawExactRate,
            brandNormalizedExactMatches: $brandNormalizedMatches,
            brandNormalizedExactRate: $brandNormalizedExactRate,
            featurePairsCompared: $featurePairs,
            featurePairsSkipped: $featureSkipped,
            featureMad: $featureMad,
            featureMaxDelta: $featureMaxDelta,
            conditionWordHits: $conditionHits,
            gate: $gate,
            gateReasons: $gateReasons,
            diffs: $diffs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function diffRow(Tenant $tenant, mixed $rawRow): array
    {
        if (!is_array($rawRow)) {
            return $this->unmatched(null, 'row is not a JSON object');
        }

        $rawProductId = $rawRow['product_id'] ?? null;

        try {
            $eval = ProductEvaluation::fromArray($rawRow);
        } catch (InvalidProductEvaluation $e) {
            return $this->unmatched($rawProductId, 'invalid evaluation: ' . $e->getMessage());
        }

        $productId = $this->resolveProductId($rawProductId);

        if ($productId === null) {
            return $this->unmatched($rawProductId, 'product_id is missing or not an integer');
        }

        // Explicit tenant_id check (not just the global scope) — this action
        // runs outside per-request tenancy middleware, called from a console
        // command (Spec 039 §2 T5 / CLAUDE.md multi-tenant data access rule).
        $product = Product::withoutGlobalScopes()
            ->with(['brand', 'featureValues.feature'])
            ->where('id', $productId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$product) {
            return $this->unmatched($productId, 'product not found for this tenant');
        }

        if ($product->status !== null) {
            return $this->unmatched($productId, "product status is \"{$product->status}\", not processed");
        }

        return $this->compareRow($product, $eval);
    }

    /**
     * @return array<string, mixed>
     */
    private function compareRow(Product $product, ProductEvaluation $eval): array
    {
        $candidateIgnored = $eval->isIgnored();
        $storedIgnored    = $this->storedIgnored($product);

        $ignore = [
            'candidate' => $candidateIgnored,
            'stored'    => $storedIgnored,
            'agree'     => $candidateIgnored === $storedIgnored,
        ];

        $brand           = null;
        $features        = [];
        $featuresSkipped = [];

        // A candidate `ignored` row (any reason, including `wrong_category`)
        // carries no brand/feature scores at all (ProductEvaluation::buildIgnored())
        // — nothing to compare, so brand/features stay null/empty rather than
        // a false zero-delta "match".
        if (!$candidateIgnored) {
            if ($product->brand !== null) {
                $candidateBrand = (string) $eval->brand();
                $storedBrand    = (string) $product->brand->name;

                $brand = [
                    'candidate'        => $candidateBrand,
                    'stored'           => $storedBrand,
                    'raw_exact'        => $candidateBrand === $storedBrand,
                    'normalized_exact' => AiService::normalizeBrandForComparison($candidateBrand)
                        === AiService::normalizeBrandForComparison($storedBrand),
                ];
            }

            $storedByFeatureName = $product->featureValues->keyBy(
                fn ($featureValue) => $featureValue->feature?->name
            );

            foreach ($eval->features() as $name => $data) {
                $storedValue = $storedByFeatureName->get($name);

                if ($storedValue === null) {
                    $featuresSkipped[] = $name;
                    continue;
                }

                $candidateScore = $data['score'];
                $storedScore    = (float) $storedValue->raw_value;

                $features[] = [
                    'feature'   => $name,
                    'candidate' => $candidateScore,
                    'stored'    => $storedScore,
                    'delta'     => abs($candidateScore - $storedScore),
                ];
            }
        }

        // AI-generated prose (ai_summary), not a raw listing title — the
        // reason this is summaryMarker(), not titleCondition() (which
        // over-matches bare "used" as a plain English verb; see
        // ProductConditionGuard's own class docblock, SUMMARY_MARKERS).
        $conditionWordHit = !$candidateIgnored
            && ProductConditionGuard::summaryMarker($eval->aiSummary()) !== null;

        return [
            'product_id'         => $product->id,
            'status'             => 'compared',
            'reason'             => null,
            'ignore'             => $ignore,
            'brand'              => $brand,
            'features'           => $features,
            'features_skipped'   => $featuresSkipped,
            'condition_word_hit' => $conditionWordHit,
        ];
    }

    /**
     * Spec 039 §2 T5 — a stored product that was detached by a category sweep
     * (`category_id` null, an {@see AiCategoryRejection} row present) counts
     * as "ignored" for is_ignored-agreement purposes, alongside the ordinary
     * `is_ignored = true` flag. Both mean the same thing for this metric:
     * "this product should not appear in the category" — so a candidate
     * saying `ignored` (including the `wrong_category` reason, which is the
     * evaluation-time equivalent of a sweep) against a swept-out product is
     * agreement, not disagreement.
     */
    private function storedIgnored(Product $product): bool
    {
        if ($product->is_ignored) {
            return true;
        }

        if ($product->category_id === null) {
            return AiCategoryRejection::where('product_id', $product->id)->exists();
        }

        return false;
    }

    /**
     * @return array{product_id: mixed, status: string, reason: string, ignore: null, brand: null, features: array, features_skipped: array, condition_word_hit: false}
     */
    private function unmatched(mixed $rawProductId, string $reason): array
    {
        return [
            'product_id'         => $rawProductId,
            'status'             => 'unmatched',
            'reason'             => $reason,
            'ignore'             => null,
            'brand'              => null,
            'features'           => [],
            'features_skipped'   => [],
            'condition_word_hit' => false,
        ];
    }

    private function resolveProductId(mixed $rawProductId): ?int
    {
        if (is_int($rawProductId)) {
            return $rawProductId;
        }

        if (is_string($rawProductId) && ctype_digit($rawProductId)) {
            return (int) $rawProductId;
        }

        return null;
    }

    /**
     * Spec 039 §2 T5 gate: `is_ignored` agreement >= 95%, normalized brand
     * exact-match >= 98%, feature MAD <= 5.0 — and at least one row must have
     * been comparable at all, or the result is `insufficient` rather than a
     * (meaningless) pass on zero data. Brand/feature checks are skipped
     * (neither pass nor fail) when there is nothing of that kind to compare
     * — e.g. every compared row was ignored by the candidate.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function evaluateGate(
        int $comparedRows,
        float $ignoreAgreementRate,
        int $brandCompared,
        float $brandNormalizedExactRate,
        int $featurePairs,
        float $featureMad,
    ): array {
        if ($comparedRows < 1) {
            return ['insufficient', ['no rows were compared (all unmatched)']];
        }

        $reasons = [];

        if ($ignoreAgreementRate < 0.95) {
            $reasons[] = sprintf('is_ignored agreement %.1f%% < 95%%', $ignoreAgreementRate * 100);
        }

        if ($brandCompared > 0 && $brandNormalizedExactRate < 0.98) {
            $reasons[] = sprintf('brand normalized exact-match %.1f%% < 98%%', $brandNormalizedExactRate * 100);
        }

        if ($featurePairs > 0 && $featureMad > 5.0) {
            $reasons[] = sprintf('feature MAD %.2f > 5.0', $featureMad);
        }

        return $reasons === [] ? ['pass', []] : ['fail', $reasons];
    }
}
