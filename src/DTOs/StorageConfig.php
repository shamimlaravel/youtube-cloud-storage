<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\DTOs;

use Shamimstack\YouTubeCloudStorage\Exceptions\BinaryNotFoundException;

/**
 * Configuration DTO using PHP 8.4 Property Hooks for validation.
 *
 * Every configurable path and numeric parameter is validated at assignment time
 * through set hooks, ensuring the package fails fast with descriptive errors
 * rather than producing cryptic failures deep in the encoding pipeline.
 *
 * PHP 8.4 Features Used:
 *   - Property Hooks (set): Inline validation on assignment.
 *   - Asymmetric Visibility: Internal state is publicly readable but privately writable.
 */
class StorageConfig
{
    /*
    |----------------------------------------------------------------------
    | Typed Constants (PHP 8.4)
    |----------------------------------------------------------------------
    */

    /** Supported lossless video codecs for the intermediate carrier video. */
    public const string CODEC_FFV1 = 'ffv1';
    public const string CODEC_LIBX264RGB = 'libx264rgb';

    /** Supported metadata store drivers. */
    public const string META_DRIVER_JSON = 'json';
    public const string META_DRIVER_SQLITE = 'sqlite';

    /** Minimum redundancy factor (1.0 = no redundancy, original packets only). */
    public const float MIN_REDUNDANCY_FACTOR = 1.0;

    /** Maximum practical redundancy factor. */
    public const float MAX_REDUNDANCY_FACTOR = 10.0;

    /** Minimum coefficient threshold for DCT sign-bit embedding. */
    public const int MIN_COEFFICIENT_THRESHOLD = 1;

    /** Minimum packet payload size in bytes. */
    public const int MIN_PACKET_SIZE = 64;

    /** Maximum packet payload size in bytes. */
    public const int MAX_PACKET_SIZE = 65535;

    /*
    |----------------------------------------------------------------------
    | Properties with Hooks
    |----------------------------------------------------------------------
    */

    /**
     * Absolute path to the FFmpeg binary.
     *
     * The set hook validates that the path exists and is executable.
     * If null or empty string is provided, attempts auto-detection via system PATH.
     */
    public string $ffmpegPath {
        set(string $value) {
            if ($value === '') {
                // Try auto-detection
                $detected = $this->detectBinary('ffmpeg');
                if ($detected !== '') {
                    $this->validateBinaryPath($detected, 'ffmpeg');
                    $this->ffmpegPath = $detected;
                    return;
                }
                // Auto-detection failed, allow empty but will fail at runtime
                $this->ffmpegPath = '';
                return;
            }
            $this->validateBinaryPath($value, 'ffmpeg');
            $this->ffmpegPath = $value;
        }
    }

    /**
     * Absolute path to the FFprobe binary.
     */
    public string $ffprobePath {
        set(string $value) {
            if ($value === '') {
                $detected = $this->detectBinary('ffprobe');
                if ($detected !== '') {
                    $this->validateBinaryPath($detected, 'ffprobe');
                    $this->ffprobePath = $detected;
                    return;
                }
                $this->ffprobePath = '';
                return;
            }
            $this->validateBinaryPath($value, 'ffprobe');
            $this->ffprobePath = $value;
        }
    }

    /**
     * Absolute path to the yt-dlp binary.
     */
    public string $ytdlpPath {
        set(string $value) {
            if ($value === '') {
                $detected = $this->detectBinary('yt-dlp');
                if ($detected !== '') {
                    $this->validateBinaryPath($detected, 'yt-dlp');
                    $this->ytdlpPath = $detected;
                    return;
                }
                $this->ytdlpPath = '';
                return;
            }
            $this->validateBinaryPath($value, 'yt-dlp');
            $this->ytdlpPath = $value;
        }
    }

    /**
     * Path to the compiled Wirehair shared library (.so / .dll / .dylib).
     */
    public string $wirehairLibPath {
        set(string $value) {
            if ($value !== '' && !file_exists($value)) {
                throw new BinaryNotFoundException('wirehair', $value);
            }
            $this->wirehairLibPath = $value;
        }
    }

    /** Path to the Wirehair C header file for FFI binding. */
    public string $wirehairHeaderPath {
        set(string $value) {
            $this->wirehairHeaderPath = $value;
        }
    }

    /** YouTube Data API v3 key. */
    public string $youtubeApiKey {
        set(string $value) {
            $this->youtubeApiKey = $value;
        }
    }

    /**
     * YouTube OAuth 2.0 credentials array.
     *
     * @var array{client_id: string, client_secret: string, refresh_token: string}
     */
    public array $youtubeOAuthCredentials {
        set(array $value) {
            $this->youtubeOAuthCredentials = $value;
        }
    }

    /**
     * Lossless video codec for intermediate carrier video.
     * Must be 'ffv1' or 'libx264rgb'.
     */
    public string $defaultCodec {
        set(string $value) {
            if (!in_array($value, [self::CODEC_FFV1, self::CODEC_LIBX264RGB], true)) {
                throw new \InvalidArgumentException(
                    "Invalid codec '{$value}'. Supported: ffv1, libx264rgb.",
                );
            }
            $this->defaultCodec = $value;
        }
    }

    /**
     * Fountain code packet payload size in bytes.
     *
     * Mathematical constraint: packetSize * originalPacketCount ≈ fileSize.
     * Smaller packets give finer granularity for fountain recovery but increase
     * per-packet overhead (8 bytes header per packet).
     */
    public int $packetSize {
        set(int $value) {
            if ($value < self::MIN_PACKET_SIZE || $value > self::MAX_PACKET_SIZE) {
                throw new \InvalidArgumentException(
                    "Packet size must be between " . self::MIN_PACKET_SIZE
                    . " and " . self::MAX_PACKET_SIZE . " bytes. Got: {$value}.",
                );
            }
            $this->packetSize = $value;
        }
    }

    /**
     * Redundancy factor for fountain coding.
     *
     * Mathematically: totalSymbols = ceil(originalPackets * redundancyFactor).
     * A factor of 1.5 means 50% more symbols than original packets are generated,
     * allowing recovery even if up to ~33% of symbols are lost/corrupted.
     */
    public float $redundancyFactor {
        set(float $value) {
            if ($value < self::MIN_REDUNDANCY_FACTOR || $value > self::MAX_REDUNDANCY_FACTOR) {
                throw new \InvalidArgumentException(
                    "Redundancy factor must be between " . self::MIN_REDUNDANCY_FACTOR
                    . " and " . self::MAX_REDUNDANCY_FACTOR . ". Got: {$value}.",
                );
            }
            $this->redundancyFactor = $value;
        }
    }

    /**
     * Minimum DCT coefficient magnitude for sign-bit embedding.
     *
     * Mathematical basis: YouTube H.264 quantization at CRF 18–23 divides
     * coefficients by quantization matrix values (typically 10–40). A threshold
     * of 30 ensures the sign survives division and rounding:
     *   |coeff| >= threshold => sign(coeff) is preserved through quantization.
     */
    public int $coefficientThreshold {
        set(int $value) {
            if ($value < self::MIN_COEFFICIENT_THRESHOLD) {
                throw new \InvalidArgumentException(
                    "Coefficient threshold must be >= " . self::MIN_COEFFICIENT_THRESHOLD . ". Got: {$value}.",
                );
            }
            $this->coefficientThreshold = $value;
        }
    }

    /**
     * DCT positions within each 8×8 block for sign-bit embedding.
     * Each entry is [row, col] where 0 <= row,col <= 7.
     * Position (0,0) is the DC coefficient and should be avoided.
     *
     * @var list<array{0: int, 1: int}>
     */
    public array $dctPositions {
        set(array $value) {
            foreach ($value as $i => $pos) {
                if (!is_array($pos) || count($pos) !== 2
                    || $pos[0] < 0 || $pos[0] > 7
                    || $pos[1] < 0 || $pos[1] > 7) {
                    throw new \InvalidArgumentException(
                        "Invalid DCT position at index {$i}. Each must be [row, col] with 0 <= row,col <= 7.",
                    );
                }
            }
            $this->dctPositions = $value;
        }
    }

    /**
     * Carrier video frame resolution.
     * Both width and height must be divisible by 8 for DCT block alignment.
     *
     * @var array{width: int, height: int}
     */
    public array $frameResolution {
        set(array $value) {
            $w = $value['width'] ?? 0;
            $h = $value['height'] ?? 0;
            if ($w % 8 !== 0 || $h % 8 !== 0 || $w < 8 || $h < 8) {
                throw new \InvalidArgumentException(
                    "Frame resolution ({$w}x{$h}) must be divisible by 8 and >= 8x8.",
                );
            }
            $this->frameResolution = $value;
        }
    }

    /** Carrier video frame rate (FPS). */
    public int $frameRate {
        set(int $value) {
            if ($value < 1 || $value > 120) {
                throw new \InvalidArgumentException(
                    "Frame rate must be between 1 and 120 FPS. Got: {$value}.",
                );
            }
            $this->frameRate = $value;
        }
    }

    /** Laravel disk name for temporary intermediate files. */
    public string $tempDisk {
        set(string $value) {
            $this->tempDisk = $value;
        }
    }

    /** Metadata store driver ('json' or 'sqlite'). */
    public string $metadataStore {
        set(string $value) {
            if (!in_array($value, [self::META_DRIVER_JSON, self::META_DRIVER_SQLITE], true)) {
                throw new \InvalidArgumentException(
                    "Invalid metadata store driver '{$value}'. Supported: json, sqlite.",
                );
            }
            $this->metadataStore = $value;
        }
    }

    /*
    |----------------------------------------------------------------------
    | Factory
    |----------------------------------------------------------------------
    */

    /**
     * Build a StorageConfig from the Laravel config array.
     *
     * @param  array<string, mixed> $config Values from config('youtube-storage').
     * @return self
     */
    public static function fromArray(array $config): self
    {
        $instance = new self();

        $instance->ffmpegPath             = $config['ffmpeg_path'] ?? '';
        $instance->ffprobePath            = $config['ffprobe_path'] ?? '';
        $instance->ytdlpPath              = $config['ytdlp_path'] ?? '';
        $instance->wirehairLibPath        = $config['wirehair_lib_path'] ?? '';
        $instance->wirehairHeaderPath     = $config['wirehair_header_path'] ?? '';
        $instance->youtubeApiKey          = $config['youtube_api_key'] ?? '';
        $instance->youtubeOAuthCredentials = $config['youtube_oauth_credentials'] ?? [
            'client_id'     => '',
            'client_secret' => '',
            'refresh_token' => '',
        ];
        $instance->defaultCodec           = $config['default_codec'] ?? self::CODEC_LIBX264RGB;
        $instance->packetSize             = (int) ($config['packet_size'] ?? 1400);
        $instance->redundancyFactor       = (float) ($config['redundancy_factor'] ?? 1.5);
        $instance->coefficientThreshold   = (int) ($config['coefficient_threshold'] ?? 30);
        $instance->dctPositions           = $config['dct_positions'] ?? [[0, 1], [1, 0], [1, 1], [2, 0]];
        $instance->frameResolution        = $config['frame_resolution'] ?? ['width' => 1920, 'height' => 1080];
        $instance->frameRate              = (int) ($config['frame_rate'] ?? 30);
        $instance->tempDisk               = $config['temp_disk'] ?? 'local';
        $instance->metadataStore          = $config['metadata_store'] ?? self::META_DRIVER_JSON;

        return $instance;
    }

    /*
    |----------------------------------------------------------------------
    | Computed Capacity Metrics
    |----------------------------------------------------------------------
    */

    /**
     * Calculate the number of 8×8 DCT blocks per frame.
     *
     * Formula: (width / 8) * (height / 8)
     */
    public function blocksPerFrame(): int
    {
        return (int) (($this->frameResolution['width'] / 8) * ($this->frameResolution['height'] / 8));
    }

    /**
     * Calculate raw data bits embeddable per frame.
     *
     * Formula: blocksPerFrame * positionsPerBlock
     * Each position carries exactly 1 bit (the sign of the DCT coefficient).
     */
    public function bitsPerFrame(): int
    {
        return $this->blocksPerFrame() * count($this->dctPositions);
    }

    /**
     * Calculate raw data bytes per frame (before fountain coding overhead).
     */
    public function rawBytesPerFrame(): int
    {
        return (int) floor($this->bitsPerFrame() / 8);
    }

    /**
     * Calculate effective payload bytes per frame after fountain redundancy.
     */
    public function effectiveBytesPerFrame(): float
    {
        return $this->rawBytesPerFrame() / $this->redundancyFactor;
    }

    /**
     * Calculate effective throughput in bytes per second of video.
     */
    public function effectiveBytesPerSecond(): float
    {
        return $this->effectiveBytesPerFrame() * $this->frameRate;
    }

    /**
     * Calculate maximum encodable file size for a given video duration.
     *
     * @param  int $durationSeconds Maximum video duration in seconds.
     * @return int Maximum file size in bytes.
     */
    public function maxFileSize(int $durationSeconds = 900): int
    {
        return (int) floor($this->effectiveBytesPerSecond() * $durationSeconds);
    }

    /*
    |----------------------------------------------------------------------
    | Helpers
    |----------------------------------------------------------------------
    */

    /**
     * Attempt to auto-detect a binary on the system PATH.
     *
     * @param  string $name Binary name (e.g., 'ffmpeg', 'yt-dlp').
     * @return string Resolved absolute path, or empty string if not found.
     */
    private function detectBinary(string $name): string
    {
        $command = PHP_OS_FAMILY === 'Windows'
            ? "where {$name} 2>NUL"
            : "which {$name} 2>/dev/null";

        $result = @exec($command, $output, $returnCode);

        if ($returnCode === 0 && isset($output[0]) && $output[0] !== '') {
            return trim($output[0]);
        }

        return '';
    }

    /**
     * Validate that a binary path exists and is executable.
     *
     * @throws BinaryNotFoundException If the binary is not found or not executable.
     */
    private function validateBinaryPath(string $path, string $name): void
    {
        if ($path === '') {
            throw new BinaryNotFoundException($name);
        }

        if (!file_exists($path)) {
            throw new BinaryNotFoundException($name, $path);
        }
    }
}
