<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Exceptions;

use RuntimeException;

/**
 * Thrown when the input file exceeds the maximum encodable size for a
 * single YouTube video given the current encoding parameters.
 *
 * The maximum size is determined by:
 *   max_bytes = (bits_per_frame / 8) * frame_count_limit / redundancy_factor
 *
 * YouTube limits videos to 12 hours / 256 GB, but practical limits
 * are much smaller due to DCT capacity constraints.
 */
class FileTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly int $fileSizeBytes,
        public readonly int $maxSizeBytes,
        ?\Throwable $previous = null,
    ) {
        $message = sprintf(
            'File size (%s) exceeds the maximum encodable size (%s) for current parameters. '
            . 'Increase frame_resolution, add more dct_positions, or split the file.',
            self::formatBytes($fileSizeBytes),
            self::formatBytes($maxSizeBytes),
        );

        parent::__construct($message, 0, $previous);
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = (int) floor(log(max($bytes, 1), 1024));
        $factor = min($factor, count($units) - 1);

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}
