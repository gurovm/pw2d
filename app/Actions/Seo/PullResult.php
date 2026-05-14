<?php

declare(strict_types=1);

namespace App\Actions\Seo;

/**
 * Result returned by PullGscMetrics and PullGa4Metrics.
 *
 * Immutable value object — any failure information is carried in $errors
 * rather than thrown, so one broken source never blocks the other.
 *
 * The optional $dailyCounts property carries per-date upsert counts for
 * callers that need date-level granularity (e.g. PullGscMetrics after F23).
 * GA4 callers leave it at the default empty array.
 */
final readonly class PullResult
{
    /**
     * @param int                $upserted    Number of rows inserted or updated.
     * @param string[]           $errors      Non-empty when something went wrong (missing config, API error, etc.).
     * @param array<string, int> $dailyCounts Per 'Y-m-d' upsert counts. Empty for GA4 and single-date GSC calls.
     */
    public function __construct(
        public int $upserted,
        public array $errors,
        public array $dailyCounts = [],
    ) {}

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * Build a failure result from an exception, redacting the service-account
     * path from the message so CLI output and log aggregators cannot leak it.
     *
     * @param array<string, int> $dailyCounts Partial per-date counts accumulated before the failure.
     */
    public static function fromThrowable(\Throwable $e, int $upserted = 0, array $dailyCounts = []): self
    {
        $saPath = (string) config('seo.google.service_account_path');
        $msg    = $e->getMessage();

        if ($saPath !== '' && str_contains($msg, $saPath)) {
            $msg = str_replace($saPath, '[REDACTED_SA_PATH]', $msg);
        }

        return new self(upserted: $upserted, errors: [$msg], dailyCounts: $dailyCounts);
    }
}
