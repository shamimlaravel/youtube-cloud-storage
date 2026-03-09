<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Drivers;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageReference;
use Shamimstack\YouTubeCloudStorage\Engines\EncoderEngine;

/**
 * YouTube Storage Driver — Flysystem v3 Adapter.
 *
 * Implements the League\Flysystem\FilesystemAdapter interface to provide
 * a native Laravel Storage disk backed by YouTube.
 *
 * Architecture:
 *   Storage::disk('youtube')->put('file.txt', $data)
 *     → YouTubeStorageDriver::write()
 *       → EncoderEngine::upload()
 *         → FountainEncoder::encode() → VideoProcessor::encodeToVideo() → YouTube API
 *
 * A local metadata index maps logical file paths to YouTube video IDs
 * and their encoding parameters (needed for decoding).
 */
class YouTubeStorageDriver implements FilesystemAdapter
{
    /**
     * In-memory metadata index.
     * Maps logical paths to their StorageReference arrays.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $index = [];

    /** Path to the persistent metadata index file. */
    private string $indexPath;

    public function __construct(
        private readonly StorageConfig $config,
        private readonly EncoderEngine $engine,
    ) {
        $this->indexPath = storage_path('app/youtube-storage-index.json');
        $this->loadIndex();
    }

    /*
    |----------------------------------------------------------------------
    | Core Flysystem Operations
    |----------------------------------------------------------------------
    */

    /**
     * Check if a file exists in the YouTube storage index.
     */
    public function fileExists(string $path): bool
    {
        return isset($this->index[$this->normalizePath($path)]);
    }

    /**
     * Check if a directory exists (virtual — based on path prefixes in the index).
     */
    public function directoryExists(string $path): bool
    {
        $prefix = rtrim($this->normalizePath($path), '/') . '/';

        foreach (array_keys($this->index) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write file contents to YouTube storage.
     *
     * Process:
     *   1. Write contents to a temp file
     *   2. Run the full encode-upload pipeline via EncoderEngine
     *   3. Store the resulting StorageReference in the metadata index
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $normalizedPath = $this->normalizePath($path);

        try {
            // Write to a temporary file for the encoder pipeline
            $tempFile = tempnam(sys_get_temp_dir(), 'ytstorage_');
            file_put_contents($tempFile, $contents);

            // Run the full upload pipeline
            $reference = $this->engine->upload($tempFile, basename($path));

            // Store reference in the metadata index
            $this->index[$normalizedPath] = $reference->toArray();
            $this->persistIndex();

            // Clean up temp file
            @unlink($tempFile);
        } catch (\Throwable $e) {
            @unlink($tempFile ?? '');
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Write a stream to YouTube storage.
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $data = stream_get_contents($contents);
        if ($data === false) {
            throw UnableToWriteFile::atLocation($path, 'Failed to read stream contents.');
        }
        $this->write($path, $data, $config);
    }

    /**
     * Read file contents from YouTube storage.
     *
     * Process:
     *   1. Look up the StorageReference from the metadata index
     *   2. Download the video via yt-dlp
     *   3. Decode the video frames and recover the original data
     */
    public function read(string $path): string
    {
        $normalizedPath = $this->normalizePath($path);

        if (!isset($this->index[$normalizedPath])) {
            throw UnableToReadFile::fromLocation($path, 'File not found in YouTube storage index.');
        }

        try {
            $reference = StorageReference::fromArray($this->index[$normalizedPath]);

            return $this->engine->download($reference->videoUrl, $reference);
        } catch (\Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Read a file as a stream.
     *
     * @return resource
     */
    public function readStream(string $path)
    {
        $contents = $this->read($path);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * Delete a file from YouTube storage.
     *
     * Removes the video from YouTube via the API and deletes the index entry.
     */
    public function delete(string $path): void
    {
        $normalizedPath = $this->normalizePath($path);

        if (!isset($this->index[$normalizedPath])) {
            return; // Already doesn't exist — idempotent
        }

        try {
            $reference = StorageReference::fromArray($this->index[$normalizedPath]);
            $this->deleteFromYouTube($reference->videoId);

            unset($this->index[$normalizedPath]);
            $this->persistIndex();
        } catch (\Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Delete a directory (all files under the given prefix).
     */
    public function deleteDirectory(string $path): void
    {
        $prefix = rtrim($this->normalizePath($path), '/') . '/';

        foreach (array_keys($this->index) as $key) {
            if (str_starts_with($key, $prefix)) {
                $this->delete($key);
            }
        }
    }

    /**
     * Create a directory (no-op for YouTube storage — directories are virtual).
     */
    public function createDirectory(string $path, Config $config): void
    {
        // Directories are virtual in YouTube storage
    }

    /**
     * Set visibility (not supported — YouTube videos are always unlisted).
     */
    public function setVisibility(string $path, string $visibility): void
    {
        // YouTube visibility is always 'unlisted' — no-op
    }

    /**
     * Get file visibility (always 'private' since videos are unlisted).
     */
    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'private');
    }

    /**
     * Get file MIME type (stored in the original file metadata).
     */
    public function mimeType(string $path): FileAttributes
    {
        $normalizedPath = $this->normalizePath($path);

        if (!isset($this->index[$normalizedPath])) {
            throw UnableToReadFile::fromLocation($path, 'File not found.');
        }

        // MIME type detection from the original file name extension
        $fileName = $this->index[$normalizedPath]['original_file_name'] ?? '';
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $mimeMap = [
            'txt' => 'text/plain', 'json' => 'application/json',
            'pdf' => 'application/pdf', 'zip' => 'application/zip',
            'png' => 'image/png', 'jpg' => 'image/jpeg',
        ];

        return new FileAttributes(
            $path,
            null,
            null,
            null,
            $mimeMap[$extension] ?? 'application/octet-stream',
        );
    }

    /**
     * Get last modified time (upload timestamp).
     */
    public function lastModified(string $path): FileAttributes
    {
        $normalizedPath = $this->normalizePath($path);

        if (!isset($this->index[$normalizedPath])) {
            throw UnableToReadFile::fromLocation($path, 'File not found.');
        }

        $uploadedAt = $this->index[$normalizedPath]['uploaded_at'] ?? null;
        $timestamp = $uploadedAt ? strtotime($uploadedAt) : null;

        return new FileAttributes($path, null, null, $timestamp ?: null);
    }

    /**
     * Get file size (original file size before encoding).
     */
    public function fileSize(string $path): FileAttributes
    {
        $normalizedPath = $this->normalizePath($path);

        if (!isset($this->index[$normalizedPath])) {
            throw UnableToReadFile::fromLocation($path, 'File not found.');
        }

        return new FileAttributes(
            $path,
            $this->index[$normalizedPath]['original_file_size'] ?? null,
        );
    }

    /**
     * List contents of a directory (based on path prefixes in the index).
     *
     * @return iterable<StorageAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $path === '' ? '' : rtrim($this->normalizePath($path), '/') . '/';

        foreach ($this->index as $key => $data) {
            if ($prefix !== '' && !str_starts_with($key, $prefix)) {
                continue;
            }

            $relativePath = $prefix !== '' ? substr($key, strlen($prefix)) : $key;

            if (!$deep && str_contains($relativePath, '/')) {
                continue;
            }

            yield new FileAttributes(
                $key,
                $data['original_file_size'] ?? null,
                'private',
                isset($data['uploaded_at']) ? strtotime($data['uploaded_at']) ?: null : null,
            );
        }
    }

    /**
     * Move a file (re-index under new path — no re-upload needed).
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $normalizedSource = $this->normalizePath($source);
        $normalizedDest = $this->normalizePath($destination);

        if (!isset($this->index[$normalizedSource])) {
            throw UnableToReadFile::fromLocation($source, 'Source file not found.');
        }

        $this->index[$normalizedDest] = $this->index[$normalizedSource];
        unset($this->index[$normalizedSource]);
        $this->persistIndex();
    }

    /**
     * Copy a file (duplicate the index entry — same YouTube video).
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $normalizedSource = $this->normalizePath($source);
        $normalizedDest = $this->normalizePath($destination);

        if (!isset($this->index[$normalizedSource])) {
            throw UnableToReadFile::fromLocation($source, 'Source file not found.');
        }

        $this->index[$normalizedDest] = $this->index[$normalizedSource];
        $this->persistIndex();
    }

    /*
    |----------------------------------------------------------------------
    | Metadata Index Management
    |----------------------------------------------------------------------
    */

    /**
     * Load the metadata index from persistent storage.
     */
    private function loadIndex(): void
    {
        if (file_exists($this->indexPath)) {
            $json = file_get_contents($this->indexPath);
            $this->index = json_decode($json, true) ?? [];
        }
    }

    /**
     * Persist the metadata index to disk.
     */
    private function persistIndex(): void
    {
        $dir = dirname($this->indexPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->indexPath,
            json_encode($this->index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Normalize a file path for consistent index lookups.
     */
    private function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    /*
    |----------------------------------------------------------------------
    | YouTube API Helpers
    |----------------------------------------------------------------------
    */

    /**
     * Delete a video from YouTube via the Data API v3.
     *
     * @param string $videoId YouTube video ID to delete.
     */
    private function deleteFromYouTube(string $videoId): void
    {
        $client = new \Google\Client();
        $client->setClientId($this->config->youtubeOAuthCredentials['client_id']);
        $client->setClientSecret($this->config->youtubeOAuthCredentials['client_secret']);
        $client->refreshToken($this->config->youtubeOAuthCredentials['refresh_token']);
        $client->addScope(\Google\Service\YouTube::YOUTUBE);

        $youtube = new \Google\Service\YouTube($client);
        $youtube->videos->delete($videoId);
    }
}
