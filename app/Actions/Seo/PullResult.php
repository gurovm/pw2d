<?php

declare(strict_types=1);

namespace App\Actions\Seo;

/**
 * Result returned by PullGscMetrics and PullGa4Metrics.
 *
 * Immutable value object — any failure information is carried in $errors
 * rather than thrown, so one broken source never blocks the other.
 */
final readonly class PullResult
{
    /**
     * @param int      $upserted Number of rows inserted or updated.
     * @param string[] $errors   Non-empty when something went wrong (missing config, API error, etc.).
     */
    public function __construct(
        public int $upserted,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * Build a failure result from an exception, redacting the service-account
     * path from the message so CLI output and log aggregators cannot leak it.
     */
    public static function fromThrowable(\Throwable $e, int $upserted = 0): self
    {
        $saPath = (string) config('seo.google.service_account_path');
        $msg    = $e->getMessage();

        if ($saPath !== '' && str_contains($msg, $saPath)) {
            $msg = str_replace($saPath, '[REDACTED_SA_PATH]', $msg);
        }

        return new self(upserted: $upserted, errors: [$msg]);
    }
}
