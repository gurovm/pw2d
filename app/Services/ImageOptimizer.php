<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

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
        int $maxWidth = 1200,
        bool $deleteOriginal = true,
    ): string {
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Source image not found: {$sourcePath}");
        }

        $webpPath = preg_replace('/\.(png|jpe?g)$/i', '.webp', $sourcePath);

        // -q quality, -resize maxWidth 0 (0 = auto height preserving aspect ratio), -m 6 (best compression)
        $process = new Process([
            'cwebp',
            '-q', (string) $quality,
            '-resize', (string) $maxWidth, '0',
            '-m', '6',
            $sourcePath,
            '-o', $webpPath,
        ]);

        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("cwebp failed for {$sourcePath}: " . $process->getErrorOutput());
        }

        if ($deleteOriginal && $webpPath !== $sourcePath && file_exists($webpPath)) {
            unlink($sourcePath);
        }

        return $webpPath;
    }
}
