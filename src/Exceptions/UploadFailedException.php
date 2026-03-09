<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Exceptions;

use RuntimeException;

/**
 * Thrown when a YouTube Data API v3 video upload fails after all retry attempts.
 *
 * Contains the original API error response details for debugging.
 */
class UploadFailedException extends RuntimeException
{
    /**
     * @param array<string, mixed> $apiErrors The raw error details from the YouTube API response.
     */
    public function __construct(
        string $message = 'YouTube video upload failed after maximum retry attempts.',
        public readonly array $apiErrors = [],
        ?\Throwable $previous = null,
    ) {
        if ($apiErrors !== []) {
            $message .= ' API errors: ' . json_encode($apiErrors, JSON_PRETTY_PRINT);
        }

        parent::__construct($message, 0, $previous);
    }
}
