<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Exceptions;

use RuntimeException;

/**
 * Thrown when a required external binary (FFmpeg, FFprobe, yt-dlp) is not found
 * at the configured path or cannot be located on the system PATH.
 */
class BinaryNotFoundException extends RuntimeException
{
    public function __construct(string $binaryName, string $configuredPath = '', ?\Throwable $previous = null)
    {
        $message = "Required binary '{$binaryName}' was not found.";

        if ($configuredPath !== '') {
            $message .= " Configured path: {$configuredPath}";
        }

        $message .= " Ensure '{$binaryName}' is installed and the path is correctly set in config/youtube-storage.php.";

        parent::__construct($message, 0, $previous);
    }
}
