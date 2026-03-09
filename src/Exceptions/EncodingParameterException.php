<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Exceptions;

use RuntimeException;

/**
 * Thrown when the DCT encoding parameters yield zero usable coefficients.
 *
 * This can happen when the coefficient magnitude threshold is set too high
 * for the selected DCT positions, leaving no coefficients large enough
 * to reliably carry sign-bit data.
 */
class EncodingParameterException extends RuntimeException
{
    public function __construct(
        int $threshold,
        int $usableCoefficients = 0,
        ?\Throwable $previous = null,
    ) {
        $message = sprintf(
            'DCT encoding parameters produced only %d usable coefficients (threshold: %d). '
            . 'Lower the coefficient_threshold or add more dct_positions in config/youtube-storage.php.',
            $usableCoefficients,
            $threshold,
        );

        parent::__construct($message, 0, $previous);
    }
}
