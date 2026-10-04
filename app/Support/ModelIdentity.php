<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single normaliser for a product's `model` identity (Spec 041 amendment).
 *
 * Used by BOTH the landing-page picker and the apply-models group report, so the
 * report can never disagree with the picker. "+" is identity in this domain
 * (MV7 vs MV7+, GLXD14 vs GLXD14+), so it becomes "plus" before the strip.
 */
final class ModelIdentity
{
    public static function normalize(?string $model): string
    {
        $lower = str_replace('+', 'plus', mb_strtolower((string) $model));

        return preg_replace('/[^a-z0-9]+/', '', $lower) ?? '';
    }
}
