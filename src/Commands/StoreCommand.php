<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Commands;

use Illuminate\Console\Command;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\Engines\EncoderEngine;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\table;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Artisan Command: yt:store
 *
 * Encodes a local file into a lossless carrier video with fountain-coded
 * redundancy and uploads it to YouTube as an unlisted video.
 *
 * Uses Laravel Prompts for a rich terminal UI with:
 *   - Animated spinner during fountain encoding and DCT embedding
 *   - Progress bar for YouTube upload
 *   - Summary table with video URL, file size, redundancy ratio, elapsed time
 *
 * Usage:
 *   php artisan yt:store /path/to/file.pdf
 *   php artisan yt:store /path/to/file.pdf --redundancy=2.0 --codec=ffv1
 */
class StoreCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'yt:store
        {path : Local file path to encode and upload to YouTube}
        {--redundancy= : Redundancy factor override (default: config value)}
        {--codec= : Video codec override (ffv1 or libx264rgb)}
        {--encrypt : Enable encryption (reserved for future implementation)}
        {--password= : Encryption password (reserved for future implementation)}';

    /**
     * The console command description.
     */
    protected $description = '⚡ Encode a file into a YouTube video using DCT sign-bit nudging and fountain codes';

    public function __construct(
        private readonly EncoderEngine $engine,
        private readonly StorageConfig $config,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $filePath = $this->argument('path');

        // ── Validate input file ────────────────────────────────────────
        if (!file_exists($filePath)) {
            error("  ✖ File not found: {$filePath}");
            return self::FAILURE;
        }

        $fileSize = filesize($filePath);
        $fileName = basename($filePath);

        // ── Display header ─────────────────────────────────────────────
        $this->newLine();
        info('  ╔══════════════════════════════════════════════════╗');
        info('  ║   ⚡ YouTube Cloud Storage — Encoder Pipeline   ║');
        info('  ╚══════════════════════════════════════════════════╝');
        $this->newLine();

        note("  📁 File: {$fileName}");
        note('  📏 Size: ' . $this->formatBytes($fileSize));
        note('  🎬 Codec: ' . ($this->option('codec') ?? $this->config->defaultCodec));
        note('  🔄 Redundancy: ' . ($this->option('redundancy') ?? $this->config->redundancyFactor) . 'x');
        $this->newLine();

        // ── Apply overrides ────────────────────────────────────────────
        if ($this->option('redundancy') !== null) {
            $this->config->redundancyFactor = (float) $this->option('redundancy');
        }
        if ($this->option('codec') !== null) {
            $this->config->defaultCodec = $this->option('codec');
        }

        // ── Check capacity ─────────────────────────────────────────────
        $maxSize = $this->config->maxFileSize();
        if ($fileSize > $maxSize) {
            error("  ✖ File too large ({$this->formatBytes($fileSize)}). Max: {$this->formatBytes($maxSize)}");
            return self::FAILURE;
        }

        // ── Run the pipeline ───────────────────────────────────────────
        $startTime = microtime(true);

        try {
            $reference = spin(
                callback: fn () => $this->engine->upload($filePath, $fileName),
                message: '  ⚙️  Running encode-upload pipeline...',
            );
        } catch (\Throwable $e) {
            error("  ✖ Pipeline failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        // ── Display results ────────────────────────────────────────────
        $this->newLine();
        info('  ✔ Upload complete!');
        $this->newLine();

        table(
            headers: ['Property', 'Value'],
            rows: [
                ['YouTube Video ID', $reference->videoId],
                ['YouTube URL', $reference->videoUrl],
                ['Original File', $reference->originalFileName],
                ['Original Size', $this->formatBytes($reference->originalFileSize)],
                ['SHA-256 Hash', substr($reference->originalFileHash, 0, 16) . '...'],
                ['Data Packets (N)', (string) $reference->originalPacketCount],
                ['Total Symbols', (string) $reference->totalSymbolCount],
                ['Redundancy Factor', $reference->redundancyFactor . 'x'],
                ['Codec', $reference->codec],
                ['Resolution', $reference->frameResolution['width'] . 'x' . $reference->frameResolution['height']],
                ['Frame Rate', $reference->frameRate . ' FPS'],
                ['Elapsed Time', "{$elapsed}s"],
            ],
        );

        $this->newLine();
        info("  🔗 Restore with: php artisan yt:restore {$reference->videoUrl}");
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Format bytes into a human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $factor = min($factor, count($units) - 1);

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}
