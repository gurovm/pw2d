<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Spec 039 T2 — the possible results of {@see \App\Actions\FinalizeProductEvaluation::execute()}.
 */
enum FinalizeOutcome: string
{
    case Scored = 'scored';
    case Ignored = 'ignored';
    case Merged = 'merged';
    case RejectedFromCategory = 'rejected_from_category';
}
