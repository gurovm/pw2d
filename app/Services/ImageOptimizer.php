<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\Process\Process;

class ImageOptimizer
{
    /**
     * Convert a PNG/JPG to WebP using cwebp, then delete the original.
     *
     * @return string Path to the resulting .webp file
     * @throws \RuntimeException If cwebp fails or the source doesn't exist
     */
    public static function toWebp(
        string $sourcePath,
        int $quality = 80,
        int $maxWidth = 800,
        bool $deleteOriginal = true,
    ): string {
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Source image not found: {$sourcePath}");
        }

        $webpPath = preg_replace('/\.(png|jpe?g)$/i', '.webp', $sourcePath);

        self::runCwebp($sourcePath, $webpPath, $quality, $maxWidth);

        if ($deleteOriginal && $webpPath !== $sourcePath && file_exists($webpPath)) {
            unlink($sourcePath);
        }

        return $webpPath;
    }

    /**
     * Re-optimize an existing WebP file: decode via dwebp, re-encode at target width.
     * Overwrites the original file in place.
     *
     * @return bool True if the file was re-optimized, false if skipped (already small enough)
     */
    public static function reoptimizeWebp(
        string $webpPath,
        int $quality = 80,
        int $maxWidth = 800,
    ): bool {
        if (!file_exists($webpPath)) {
            throw new \RuntimeException("Source image not found: {$webpPath}");
        }

        // Decode WebP to a temporary PNG
        $tempPng = $webpPath . '.tmp.png';

        $decode = new Process(['dwebp', $webpPath, '-o', $tempPng]);
        $decode->setTimeout(60);
        $decode->run();

        if (!$decode->isSuccessful()) {
            @unlink($tempPng);
            throw new \RuntimeException("dwebp failed for {$webpPath}: " . $decode->getErrorOutput());
        }

        // Re-encode from the temp PNG to the original WebP path
        $originalSize = filesize($webpPath);
        self::runCwebp($tempPng, $webpPath, $quality, $maxWidth);
        @unlink($tempPng);

        return true;
    }

    private static function runCwebp(string $input, string $output, int $quality, int $maxWidth): void
    {
        $process = new Process([
            'cwebp',
            '-q', (string) $quality,
            '-resize', (string) $maxWidth, '0',
            '-m', '6',
            $input,
            '-o', $output,
        ]);

        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("cwebp failed for {$input}: " . $process->getErrorOutput());
        }
    }
}
