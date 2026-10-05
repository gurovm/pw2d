<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Feature;
use App\Models\Product;

/**
 * Spec 043 — the feature table and preset winners for a pair, shared by the VS page
 * and the save command.
 *
 * Feature winner: the higher score when the gap is at least WIN_MARGIN points, else null.
 * Preset winner: score = sum(raw_value x weight) over the preset's weighted features
 * (the exact SelectLandingPagePicks formula); null when the two are within 1%.
 * Features missing a value on either side are left out (nothing to compare); presets
 * with no weighted features are skipped.
 */
final class BuildVsComparison
{
    public const WIN_MARGIN = 3;
    private const PRESET_TIE_RATIO = 0.01;

    /**
     * @return array{features: list<array{feature: string, a: int, b: int, winner: 'a'|'b'|null}>,
     *               presets: list<array{preset: string, winner: 'a'|'b'|null}>, a_wins: int, b_wins: int}
     */
    public function handle(Product $a, Product $b): array
    {
        $a->loadMissing('featureValues:id,product_id,feature_id,raw_value');
        $b->loadMissing('featureValues:id,product_id,feature_id,raw_value');

        $valuesA = $a->featureValues->pluck('raw_value', 'feature_id');
        $valuesB = $b->featureValues->pluck('raw_value', 'feature_id');

        $features = Feature::where('category_id', $a->category_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $rows   = [];
        $aWins  = 0;
        $bWins  = 0;

        foreach ($features as $feature) {
            if (!$valuesA->has($feature->id) || !$valuesB->has($feature->id)) {
                continue;
            }

            $scoreA = (int) round((float) $valuesA->get($feature->id));
            $scoreB = (int) round((float) $valuesB->get($feature->id));

            $winner = match (true) {
                $scoreA - $scoreB >= self::WIN_MARGIN => 'a',
                $scoreB - $scoreA >= self::WIN_MARGIN => 'b',
                default                               => null,
            };

            $winner === 'a' && $aWins++;
            $winner === 'b' && $bWins++;

            $rows[] = ['feature' => $feature->name, 'a' => $scoreA, 'b' => $scoreB, 'winner' => $winner];
        }

        return [
            'features' => $rows,
            'presets'  => $this->presetWinners($a, $b),
            'a_wins'   => $aWins,
            'b_wins'   => $bWins,
        ];
    }

    /**
     * @return list<array{preset: string, winner: 'a'|'b'|null}>
     */
    private function presetWinners(Product $a, Product $b): array
    {
        $presets = $a->category()->first()?->presets()->with('presetFeatures')->orderBy('sort_order')->get() ?? collect();

        $out = [];

        foreach ($presets as $preset) {
            $weights = $preset->presetFeatures->pluck('weight', 'feature_id')->toArray();

            if ($weights === []) {
                continue;
            }

            $scoreA = $this->presetScore($a, $weights);
            $scoreB = $this->presetScore($b, $weights);

            $top    = max($scoreA, $scoreB);
            $winner = match (true) {
                $top <= 0.0 || abs($scoreA - $scoreB) <= $top * self::PRESET_TIE_RATIO => null,
                $scoreA > $scoreB => 'a',
                default           => 'b',
            };

            $out[] = ['preset' => $preset->name, 'winner' => $winner];
        }

        return $out;
    }

    /** @param array<int, int|float> $weights feature_id => weight */
    private function presetScore(Product $product, array $weights): float
    {
        return (float) $product->featureValues
            ->filter(fn ($fv) => isset($weights[$fv->feature_id]))
            ->sum(fn ($fv) => (float) $fv->raw_value * (float) $weights[$fv->feature_id]);
    }
}
