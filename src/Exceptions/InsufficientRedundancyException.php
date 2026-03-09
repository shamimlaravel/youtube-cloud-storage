<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Exceptions;

use RuntimeException;

/**
 * Thrown when too few fountain-coded symbols are recovered from the
 * downloaded video to reconstruct the original file.
 *
 * The Wirehair decoder requires approximately N symbols (where N is the
 * original packet count) plus a small overhead (~0.02 extra on average).
 * If losses exceed the redundancy factor, recovery is impossible.
 */
class InsufficientRedundancyException extends RuntimeException
{
    public function __construct(
        public readonly int $recoveredSymbols,
        public readonly int $requiredSymbols,
        ?\Throwable $previous = null,
    ) {
        $message = sprintf(
            'Insufficient fountain symbols recovered: %d of %d required. '
            . 'Consider increasing the redundancy_factor in config/youtube-storage.php '
            . 'or raising the coefficient_threshold for more robust embedding.',
            $recoveredSymbols,
            $requiredSymbols,
        );

        parent::__construct($message, 0, $previous);
    }
}
