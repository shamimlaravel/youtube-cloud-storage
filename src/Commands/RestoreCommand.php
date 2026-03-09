<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Commands;

use Illuminate\Console\Command;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageReference;
use Shamimstack\YouTubeCloudStorage\Engines\EncoderEngine;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

/**
 * Artisan Command: yt:restore
 *
 * Downloads a re-encoded YouTube video, extracts DCT sign bits to recover
 * fountain-coded symbol packets, and reconstructs the original file.
 *
 * Uses Laravel Prompts for a rich terminal UI with:
 *   - Animated spinner during yt-dlp download
 *   - Progress indicator during frame extraction and DCT decoding
 *   - Integrity verification report
 *
 * Usage:
 *   php artisan yt:restore "https://www.youtube.com/watch?v=dQw4w9WgXcQ"
 *   php artisan yt:restore "https://www.youtube.com/watch?v=dQw4w9WgXcQ" --output=/path/to/output.pdf
 */
class RestoreCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'yt:restore
        {url : YouTube video URL to download and decode}
        {--output= : Output file path for the reconstructed file}
        {--password= : Decryption password (reserved for future implementation)}';

    /**
     * The console command description.
     */
    protected $description = '⚡ Restore a file from a YouTube video using DCT sign-bit extraction and fountain decoding';

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
        $youtubeUrl = $this->argument('url');

        // ── Display header ─────────────────────────────────────────────
        $this->newLine();
        info('  ╔══════════════════════════════════════════════════╗');
        info('  ║   ⚡ YouTube Cloud Storage — Decoder Pipeline   ║');
        info('  ╚══════════════════════════════════════════════════╝');
        $this->newLine();

        note("  🔗 URL: {$youtubeUrl}");
        $this->newLine();

        // ── Load storage reference from metadata index ─────────────────
        $reference = $this->findReference($youtubeUrl);

        if ($reference === null) {
            error('  ✖ No storage reference found for this URL in the metadata index.');
            error('  ℹ The file must have been originally uploaded via yt:store or Storage::disk(\'youtube\').');
            return self::FAILURE;
        }

        note("  📁 Original file: {$reference->originalFileName}");
        note('  📏 Expected size: ' . $this->formatBytes($reference->originalFileSize));
        note("  🔢 Expected packets: {$reference->totalSymbolCount}");
        $this->newLine();

        // ── Determine output path ──────────────────────────────────────
        $outputPath = $this->option('output');
        if ($outputPath === null) {
            $outputPath = getcwd() . DIRECTORY_SEPARATOR . $reference->originalFileName;
        }

        // ── Run the decode pipeline ────────────────────────────────────
        $startTime = microtime(true);

        try {
            $fileData = spin(
                callback: fn () => $this->engine->download($youtubeUrl, $reference),
                message: '  ⚙️  Running download-decode pipeline...',
            );
        } catch (\Throwable $e) {
            error("  ✖ Pipeline failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        // ── Write output file ──────────────────────────────────────────
        file_put_contents($outputPath, $fileData);

        // ── Verify integrity ───────────────────────────────────────────
        $hash = hash('sha256', $fileData);
        $integrityPassed = ($hash === $reference->originalFileHash);

        // ── Display results ────────────────────────────────────────────
        $this->newLine();

        if ($integrityPassed) {
            info('  ✔ File restored and integrity verified!');
        } else {
            error('  ⚠ File restored but SHA-256 hash mismatch!');
        }

        $this->newLine();

        table(
            headers: ['Property', 'Value'],
            rows: [
                ['Output File', $outputPath],
                ['Restored Size', $this->formatBytes(strlen($fileData))],
                ['Expected Size', $this->formatBytes($reference->originalFileSize)],
                ['SHA-256 (Expected)', substr($reference->originalFileHash, 0, 16) . '...'],
                ['SHA-256 (Actual)', substr($hash, 0, 16) . '...'],
                ['Integrity', $integrityPassed ? '✔ PASSED' : '✖ FAILED'],
                ['Elapsed Time', "{$elapsed}s"],
            ],
        );

        $this->newLine();

        return $integrityPassed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Find the StorageReference for a given YouTube URL from the metadata index.
     *
     * Searches the JSON metadata index file for a matching video URL or ID.
     */
    private function findReference(string $youtubeUrl): ?StorageReference
    {
        $indexPath = storage_path('app/youtube-storage-index.json');

        if (!file_exists($indexPath)) {
            return null;
        }

        $index = json_decode(file_get_contents($indexPath), true) ?? [];

        // Extract video ID from URL for flexible matching
        $videoId = $this->extractVideoId($youtubeUrl);

        foreach ($index as $data) {
            if (($data['video_url'] ?? '') === $youtubeUrl
                || ($data['video_id'] ?? '') === $videoId) {
                return StorageReference::fromArray($data);
            }
        }

        return null;
    }

    /**
     * Extract a YouTube video ID from various URL formats.
     *
     * Supports:
     *   - https://www.youtube.com/watch?v=VIDEO_ID
     *   - https://youtu.be/VIDEO_ID
     *   - https://youtube.com/embed/VIDEO_ID
     *   - Plain VIDEO_ID string
     */
    private function extractVideoId(string $url): string
    {
        // Try standard watch URL
        if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        // Try short URL
        if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        // Try embed URL
        if (preg_match('/embed\/([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        // Assume it's a raw video ID
        return $url;
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
