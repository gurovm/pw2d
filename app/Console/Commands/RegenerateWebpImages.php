<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ImageOptimizer;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class RegenerateWebpImages extends Command
{
    protected $signature = 'images:regenerate
                            {--dry-run : List files that would be converted without actually converting}';

    protected $description = 'Convert all PNG/JPG files in public/images/ to optimized WebP and delete originals.';

    public function handle(): int
    {
        $directory = public_path('images');

        if (!is_dir($directory)) {
            $this->error("Directory not found: {$directory}");
            return self::FAILURE;
        }

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->name(['*.png', '*.jpg', '*.jpeg'])
            ->depth(0);

        $files = iterator_to_array($finder);

        if (empty($files)) {
            $this->info('No PNG/JPG files found in public/images/. Nothing to do.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d image(s) to convert.', count($files)));

        if ($this->option('dry-run')) {
            foreach ($files as $file) {
                $this->line("  Would convert: {$file->getFilename()}");
            }
            return self::SUCCESS;
        }

        $converted = 0;
        $failed = 0;

        foreach ($files as $file) {
            $sourcePath = $file->getRealPath();
            $originalSize = filesize($sourcePath);

            $this->info("Converting: {$file->getFilename()} (" . $this->formatBytes($originalSize) . ')');

            try {
                $webpPath = ImageOptimizer::toWebp($sourcePath);
                $newSize = filesize($webpPath);

                $reduction = $originalSize > 0
                    ? round((1 - $newSize / $originalSize) * 100)
                    : 0;

                $this->line(sprintf(
                    '  -> %s (%s, %d%% smaller)',
                    basename($webpPath),
                    $this->formatBytes($newSize),
                    $reduction,
                ));

                $converted++;
            } catch (\RuntimeException $e) {
                $this->error("  Failed: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. Converted: {$converted}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function formatBytes(int|false $bytes): string
    {
        if ($bytes === false || $bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }
}
