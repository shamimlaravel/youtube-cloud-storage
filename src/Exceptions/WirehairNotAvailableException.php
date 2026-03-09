<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Exceptions;

use RuntimeException;

/**
 * Thrown when the Wirehair shared library cannot be loaded via FFI.
 *
 * This typically means libwirehair.so / wirehair.dll is not installed
 * or the configured path is incorrect.
 */
class WirehairNotAvailableException extends RuntimeException
{
    public function __construct(string $path = '', ?\Throwable $previous = null)
    {
        $message = 'Wirehair fountain code library could not be loaded via FFI.';

        if ($path !== '') {
            $message .= " Configured path: {$path}";
        }

        $message .= ' Please compile libwirehair and set the YTSTORAGE_WIREHAIR_LIB environment variable.';

        parent::__construct($message, 0, $previous);
    }
}
