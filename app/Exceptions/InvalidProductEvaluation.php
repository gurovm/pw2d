<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Spec 039 T1 — thrown by {@see \App\Support\ProductEvaluation::fromArray()}
 * when a raw evaluation payload (from either producer: the Gemini Bouncer or
 * the operator-session overflow path) violates the shared schema.
 *
 * The message always names the offending field so a caller can report exactly
 * what was wrong without re-deriving it — `ProcessPendingProduct`'s catch
 * block logs it and retries/fails the job exactly as it did for the bare
 * `\Exception` this replaces; the (not-yet-built) T4 apply command marks the
 * row `error` and moves on.
 */
class InvalidProductEvaluation extends \RuntimeException
{
    public function __construct(public readonly string $field, string $detail = 'is invalid')
    {
        parent::__construct("Invalid product evaluation: field \"{$field}\" {$detail}.");
    }
}
