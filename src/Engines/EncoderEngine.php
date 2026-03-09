<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Engines;

use Google\Client as GoogleClient;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use Illuminate\Support\Facades\Storage;
use Shamimstack\YouTubeCloudStorage\DTOs\PacketMetadata;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageReference;
use Shamimstack\YouTubeCloudStorage\Exceptions\FileTooLargeException;
use Shamimstack\YouTubeCloudStorage\Exceptions\UploadFailedException;

/**
 * Encoder Engine — Full Pipeline Coordinator.
 *
 * Orchestrates the complete encode-upload and download-decode pipelines,
 * wiring together the FountainEncoder (redundancy) and VideoProcessor (DCT embedding).
 *
 * PHP 8.4 Features Used:
 *   - Asymmetric Visibility: Pipeline state is publicly readable but privately mutable.
 *     External observers (progress bars, logging) can read currentPhase, progressPercent,
 *     and lastError, but only the engine itself can set them.
 */
class EncoderEngine
{
    /*
    |----------------------------------------------------------------------
    | Pipeline State — Asymmetric Visibility (PHP 8.4)
    |----------------------------------------------------------------------
    | These properties are readable by any external consumer (Artisan commands,
    | progress bar callbacks, etc.) but can only be mutated by the engine.
    */

    /** Current pipeline phase description. */
    public private(set) string $currentPhase = 'idle';

    /** Progress percentage within the current phase (0.0–100.0). */
    public private(set) float $progressPercent = 0.0;

    /** Last error message, if any. */
    public private(set) string $lastError = '';

    /** Whether the pipeline is currently running. */
    public private(set) bool $isRunning = false;

    /** Maximum upload retry count with exponential backoff. */
    private const int MAX_UPLOAD_RETRIES = 3;

    /** Base delay for exponential backoff in seconds. */
    private const int RETRY_BASE_DELAY_SECONDS = 2;

    public function __construct(
        private readonly StorageConfig $config,
        private readonly FountainEncoder $fountainEncoder,
        private readonly VideoProcessor $videoProcessor,
    ) {}

    /*
    |----------------------------------------------------------------------
    | Upload Pipeline: File → YouTube
    |----------------------------------------------------------------------
    */

    /**
     * Encode a file into a lossless carrier video and upload it to YouTube.
     *
     * Pipeline phases:
     *   1. VALIDATE  — Check file size against capacity model
     *   2. FOUNTAIN  — Encode file data into fountain-coded symbol packets
     *   3. VIDEO     — Embed packets into video frames via DCT sign-bit nudging
     *   4. UPLOAD    — Upload lossless video to YouTube as unlisted
     *   5. COMPLETE  — Return storage reference with all metadata
     *
     * @param  string      $filePath    Absolute path to the source file.
     * @param  string|null $fileName    Optional override for the display name.
     * @return StorageReference Reference containing YouTube video ID and encoding metadata.
     *
     * @throws FileTooLargeException If the file exceeds maximum encodable size.
     * @throws UploadFailedException If YouTube upload fails after retries.
     */
    public function upload(string $filePath, ?string $fileName = null): StorageReference
    {
        $this->isRunning = true;
        $this->lastError = '';

        try {
            // ── Phase 1: Validate ──────────────────────────────────────────
            $this->currentPhase = 'Validating file size against capacity model';
            $this->progressPercent = 0.0;

            $fileData = file_get_contents($filePath);
            $fileSize = strlen($fileData);
            $fileHash = hash('sha256', $fileData);
            $displayName = $fileName ?? basename($filePath);

            $maxSize = $this->config->maxFileSize();
            if ($fileSize > $maxSize) {
                throw new FileTooLargeException($fileSize, $maxSize);
            }

            $this->progressPercent = 100.0;

            // ── Phase 2: Fountain Encoding ─────────────────────────────────
            $this->currentPhase = 'Fountain encoding (Wirehair O(N) erasure code)';
            $this->progressPercent = 0.0;

            $packets = $this->fountainEncoder->encode($fileData);
            $originalPacketCount = (int) ceil($fileSize / $this->config->packetSize);

            $this->progressPercent = 100.0;

            // ── Phase 3: Video Encoding ────────────────────────────────────
            $this->currentPhase = 'Embedding packets into video via DCT sign-bit nudging';
            $this->progressPercent = 0.0;

            $tempDisk  = $this->config->tempDisk;
            $extension = ($this->config->defaultCodec === StorageConfig::CODEC_FFV1) ? 'mkv' : 'mp4';
            $tempFile  = 'yt-storage-' . uniqid() . ".{$extension}";
            $tempPath  = Storage::disk($tempDisk)->path($tempFile);

            $this->videoProcessor->encodeToVideo($packets, $tempPath);

            $this->progressPercent = 100.0;

            // ── Phase 4: YouTube Upload ────────────────────────────────────
            $this->currentPhase = 'Uploading to YouTube';
            $this->progressPercent = 0.0;

            $videoId = $this->uploadToYouTube($tempPath, $displayName);

            // Clean up temp file
            Storage::disk($tempDisk)->delete($tempFile);

            $this->progressPercent = 100.0;

            // ── Phase 5: Build Reference ───────────────────────────────────
            $this->currentPhase = 'Complete';
            $this->progressPercent = 100.0;

            return new StorageReference(
                videoId:              $videoId,
                videoUrl:             "https://www.youtube.com/watch?v={$videoId}",
                originalFileName:     $displayName,
                originalFileSize:     $fileSize,
                originalFileHash:     $fileHash,
                originalPacketCount:  $originalPacketCount,
                totalSymbolCount:     count($packets),
                packetSize:           $this->config->packetSize,
                redundancyFactor:     $this->config->redundancyFactor,
                coefficientThreshold: $this->config->coefficientThreshold,
                dctPositions:         $this->config->dctPositions,
                frameResolution:      $this->config->frameResolution,
                frameRate:            $this->config->frameRate,
                codec:                $this->config->defaultCodec,
                uploadedAt:           now()->toIso8601String(),
            );
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            throw $e;
        } finally {
            $this->isRunning = false;
        }
    }

    /*
    |----------------------------------------------------------------------
    | Download Pipeline: YouTube → File
    |----------------------------------------------------------------------
    */

    /**
     * Download a YouTube video and decode it back to the original file.
     *
     * Pipeline phases:
     *   1. DOWNLOAD  — Fetch the re-encoded video via yt-dlp
     *   2. EXTRACT   — Extract DCT sign bits from video frames
     *   3. FOUNTAIN  — Decode fountain symbols to recover original data
     *   4. VERIFY    — Check SHA-256 hash of recovered data
     *
     * @param  string           $youtubeUrl YouTube video URL.
     * @param  StorageReference $reference  The storage reference from the original upload.
     * @return string The recovered original file data.
     *
     * @throws \RuntimeException If download or decoding fails.
     */
    public function download(string $youtubeUrl, StorageReference $reference): string
    {
        $this->isRunning = true;
        $this->lastError = '';

        try {
            // ── Phase 1: Download ──────────────────────────────────────────
            $this->currentPhase = 'Downloading re-encoded video via yt-dlp';
            $this->progressPercent = 0.0;

            $tempDisk = $this->config->tempDisk;
            $tempFile = 'yt-download-' . uniqid() . '.mp4';
            $tempPath = Storage::disk($tempDisk)->path($tempFile);

            $this->videoProcessor->downloadVideo($youtubeUrl, $tempPath);

            $this->progressPercent = 100.0;

            // ── Phase 2: Extract Packets ───────────────────────────────────
            $this->currentPhase = 'Extracting DCT sign bits from video frames';
            $this->progressPercent = 0.0;

            $recoveredPackets = $this->videoProcessor->decodeFromVideo(
                $tempPath,
                $reference->totalSymbolCount,
                $reference->packetSize,
            );

            // Clean up downloaded video
            Storage::disk($tempDisk)->delete($tempFile);

            $this->progressPercent = 100.0;

            // ── Phase 3: Fountain Decoding ─────────────────────────────────
            $this->currentPhase = 'Fountain decoding (Wirehair recovery)';
            $this->progressPercent = 0.0;

            $recoveredData = $this->fountainEncoder->decode(
                $recoveredPackets,
                $reference->originalFileSize,
            );

            $this->progressPercent = 100.0;

            // ── Phase 4: Integrity Verification ────────────────────────────
            $this->currentPhase = 'Verifying SHA-256 integrity';
            $this->progressPercent = 0.0;

            $hash = hash('sha256', $recoveredData);

            if ($hash !== $reference->originalFileHash) {
                throw new \RuntimeException(
                    "Integrity check failed. Expected SHA-256: {$reference->originalFileHash}, got: {$hash}.",
                );
            }

            $this->progressPercent = 100.0;
            $this->currentPhase = 'Complete';

            return $recoveredData;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            throw $e;
        } finally {
            $this->isRunning = false;
        }
    }

    /*
    |----------------------------------------------------------------------
    | YouTube API Interaction
    |----------------------------------------------------------------------
    */

    /**
     * Upload a video file to YouTube as an unlisted video.
     *
     * Uses exponential backoff with up to MAX_UPLOAD_RETRIES attempts.
     *
     * @param  string $videoPath Local path to the video file.
     * @param  string $title     Video title (original file name).
     * @return string YouTube video ID.
     *
     * @throws UploadFailedException After all retries are exhausted.
     */
    private function uploadToYouTube(string $videoPath, string $title): string
    {
        $client = new GoogleClient();
        $client->setClientId($this->config->youtubeOAuthCredentials['client_id']);
        $client->setClientSecret($this->config->youtubeOAuthCredentials['client_secret']);
        $client->refreshToken($this->config->youtubeOAuthCredentials['refresh_token']);
        $client->addScope(YouTube::YOUTUBE_UPLOAD);
        $client->setDefer(true);

        $youtube = new YouTube($client);

        // Build video metadata
        $snippet = new VideoSnippet();
        $snippet->setTitle("[YTStorage] {$title}");
        $snippet->setDescription('Encoded file storage via shamimstack/youtube-cloud-storage');
        $snippet->setCategoryId('22'); // People & Blogs

        $status = new VideoStatus();
        $status->setPrivacyStatus('unlisted');

        $video = new Video();
        $video->setSnippet($snippet);
        $video->setStatus($status);

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_UPLOAD_RETRIES; $attempt++) {
            try {
                $this->progressPercent = ($attempt / self::MAX_UPLOAD_RETRIES) * 50.0;

                /** @var \Psr\Http\Message\RequestInterface $insertRequest */
                $insertRequest = $youtube->videos->insert(
                    'snippet,status',
                    $video,
                );

                // Perform resumable upload
                $media = new \Google\Http\MediaFileUpload(
                    $client,
                    $insertRequest,
                    'video/*',
                    file_get_contents($videoPath),
                    true,
                    1048576, // 1MB chunk size
                );

                $media->setFileSize(filesize($videoPath));

                $uploadResult = false;
                while (!$uploadResult) {
                    $uploadResult = $media->nextChunk();
                }

                $client->setDefer(false);

                if ($uploadResult instanceof Video) {
                    return $uploadResult->getId();
                }

                throw new \RuntimeException('Upload completed but no video ID returned.');
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($attempt < self::MAX_UPLOAD_RETRIES) {
                    // Exponential backoff: 2^attempt seconds
                    $delay = self::RETRY_BASE_DELAY_SECONDS ** $attempt;
                    sleep($delay);
                }
            }
        }

        throw new UploadFailedException(
            'YouTube upload failed after ' . self::MAX_UPLOAD_RETRIES . ' attempts.',
            ['last_error' => $lastException?->getMessage() ?? 'Unknown error'],
            $lastException,
        );
    }
}
