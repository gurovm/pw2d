<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Services\ImageOptimizer;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class RegenerateWebpImages extends Command
{
    protected $signature = 'images:regenerate
                            {--dry-run : List files that would be processed without actually converting}
                            {--reoptimize : Re-encode existing .webp files at 800px max width}';

    protected $description = 'Convert PNG/JPG to WebP and optionally re-optimize existing WebP files.';

    public function handle(): int
    {
        $reoptimize = (bool) $this->option('reoptimize');

        $directories = array_filter([
            public_path('images'),
            storage_path('app/public'),
        ], 'is_dir');

        if (empty($directories)) {
            $this->error('No image directories found.');
            return self::FAILURE;
        }

        $this->info('Scanning: ' . implode(', ', array_map(fn($d) => str_replace(base_path() . '/', '', $d), $directories)));

        $extensions = $reoptimize
            ? ['*.png', '*.jpg', '*.jpeg', '*.webp']
            : ['*.png', '*.jpg', '*.jpeg'];

        $finder = (new Finder())
            ->files()
            ->in($directories)
            ->name($extensions);

        $files = iterator_to_array($finder);

        if (empty($files)) {
            $this->info('No matching files found. Nothing to do.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d file(s) to process.', count($files)));
        $this->newLine();

        if ($this->option('dry-run')) {
            foreach ($files as $file) {
                $relative = str_replace(base_path() . '/', '', $file->getRealPath());
                $this->line("  Would process: {$relative} (" . $this->formatBytes($file->getSize()) . ')');
            }
            return self::SUCCESS;
        }

        $converted = 0;
        $failed = 0;
        $totalSaved = 0;

        foreach ($files as $file) {
            $sourcePath = $file->getRealPath();
            $originalSize = filesize($sourcePath);
            $relative = str_replace(base_path() . '/', '', $sourcePath);
            $isWebp = strtolower($file->getExtension()) === 'webp';

            $this->info(($isWebp ? 'Re-optimizing: ' : 'Converting: ') . "{$relative} (" . $this->formatBytes($originalSize) . ')');

            try {
                if ($isWebp) {
                    ImageOptimizer::reoptimizeWebp($sourcePath);
                    $resultPath = $sourcePath;
                } else {
                    $resultPath = ImageOptimizer::toWebp($sourcePath);
                }

                $newSize = filesize($resultPath);
                $saved = $originalSize - $newSize;
                $totalSaved += max(0, $saved);
                $reduction = $originalSize > 0
                    ? round((1 - $newSize / $originalSize) * 100)
                    : 0;

                $this->line(sprintf(
                    '  -> %s (%s, %d%% smaller)',
                    basename($resultPath),
                    $this->formatBytes($newSize),
                    $reduction,
                ));

                $converted++;
            } catch (\RuntimeException $e) {
                $this->error("  Failed: {$e->getMessage()}");
                $failed++;
            }
        }

        // Update DB references for any PNG/JPG → WebP conversions
        if (!$reoptimize) {
            $this->newLine();
            $this->info('Updating database references...');

            $catUpdated = Category::where(fn($q) => $q->where('image', 'like', '%.png')->orWhere('image', 'like', '%.jpg')->orWhere('image', 'like', '%.jpeg'))
                ->get()
                ->each(fn(Category $c) => $c->update([
                    'image' => preg_replace('/\.(png|jpe?g)$/i', '.webp', $c->image),
                ]))
                ->count();

            $prodUpdated = Product::where(fn($q) => $q->where('image_path', 'like', '%.png')->orWhere('image_path', 'like', '%.jpg')->orWhere('image_path', 'like', '%.jpeg'))
                ->get()
                ->each(fn(Product $p) => $p->update([
                    'image_path' => preg_replace('/\.(png|jpe?g)$/i', '.webp', $p->image_path),
                ]))
                ->count();

            $this->line("  Categories updated: {$catUpdated}");
            $this->line("  Products updated: {$prodUpdated}");
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. Processed: %d, Failed: %d, Space saved: %s',
            $converted,
            $failed,
            $this->formatBytes($totalSaved),
        ));

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
