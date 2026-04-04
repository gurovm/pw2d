<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;

class SamplePrompts
{
    /**
     * Normalize a raw sample_prompts value (PHP array, JSON string, or null)
     * into a flat string array.
     *
     * Mirrors the logic from App\Traits\NormalizesPrompts without requiring
     * a trait to be mixed into a class.
     */
    public static function normalize(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            return json_decode($value, true) ?? [];
        }
        return [];
    }

    /**
     * Resolve sample prompts for a single category using a 3-priority fallback:
     *
     * 1. The category's own `sample_prompts` field.
     * 2. Aggregate and shuffle from the provided child/subcategory collection.
     * 3. Four category-name-aware fallback strings.
     *
     * @param  object|null     $category  Any object with `name` and `sample_prompts` properties.
     * @param  Collection|null $children  Optional collection of child category objects.
     * @param  int             $take      Maximum number of prompts to take from child aggregation.
     * @return array<string>
     */
    public static function forCategory(?object $category, ?Collection $children = null, int $take = 6): array
    {
        if ($category === null) {
            return [];
        }

        // Priority 1: the category's own prompts
        $prompts = self::normalize($category->sample_prompts);

        // Priority 2: aggregate from children
        if (empty($prompts) && $children?->isNotEmpty()) {
            $prompts = $children
                ->pluck('sample_prompts')
                ->map(fn($v) => self::normalize($v))
                ->flatten()
                ->filter()
                ->shuffle()
                ->take($take)
                ->values()
                ->toArray();
        }

        // Priority 3: category-aware fallback
        if (empty($prompts)) {
            $name = strtolower($category->name ?? 'product');
            $prompts = [
                "best {$name} for beginners",
                "top budget {$name}",
                "professional {$name} under \$200",
                "{$name} for everyday use",
            ];
        }

        return $prompts;
    }
}
