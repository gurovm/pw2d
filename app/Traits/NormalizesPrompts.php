<?php

namespace App\Traits;

trait NormalizesPrompts
{
    /**
     * Normalise a sample_prompts value regardless of whether the DB cast
     * was applied (PHP array) or not (raw JSON string from MySQL).
     */
    protected static function normalizePrompts(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            return json_decode($value, true) ?? [];
        }
        return [];
    }
}
