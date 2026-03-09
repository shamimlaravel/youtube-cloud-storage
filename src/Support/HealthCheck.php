<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Support;

use FFI;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\Exceptions\BinaryNotFoundException;

/**
 * Health Check Diagnostic — Runtime Prerequisite Validator.
 *
 * Validates all dependencies and configuration required for the package to function:
 *   - PHP version and extensions
 *   - System binaries (FFmpeg, FFprobe, yt-dlp)
 *   - Wirehair FFI library
 *   - YouTube API credentials
 *   - Filesystem permissions
 *
 * Returns a structured diagnostic report suitable for display in Artisan commands
 * or programmatic analysis.
 *
 * Usage:
 *   $healthCheck = new HealthCheck($config, $autoConfigurator);
 *   $report = $healthCheck->run();
 *
 *   if (!$report['passed']) {
 *       foreach ($report['checks'] as $check) {
 *           if (!$check['passed']) {
 *               echo "Failed: {$check['name']} - {$check['message']}\n";
 *           }
 *       }
 *   }
 */
class HealthCheck
{
    /**
     * Minimum required PHP version.
     */
    private const string MIN_PHP_VERSION = '8.4.0';

    public function __construct(
        private readonly StorageConfig $config,
        private readonly AutoConfigurator $autoConfigurator,
    ) {}

    /**
     * Run all health checks and return a structured report.
     *
     * @return array{
     *     passed: bool,
     *     checks: list<array{
     *         name: string,
     *         passed: bool,
     *         message: string,
     *         severity: 'critical'|'warning'|'info'
     *     }>
     * }
     */
    public function run(): array
    {
        $checks = [];

        // Run all checks
        $checks[] = $this->checkPhpVersion();
        $checks[] = $this->checkFfiExtension();
        $checks[] = $this->checkFfmpegBinary();
        $checks[] = $this->checkFfprobeBinary();
        $checks[] = $this->checkYtdlpBinary();
        $checks[] = $this->checkWirehairLibrary();
        $checks[] = $this->checkYoutubeCredentials();
        $checks[] = $this->checkTempDiskWritable();

        // Determine overall status
        $passed = !in_array(false, array_column($checks, 'passed'), true);
        $hasCriticalFailures = in_array('critical', array_filter(
            array_map(fn ($check) => $check['passed'] ? null : $check['severity'], $checks),
            fn ($sev) => $sev !== null
        ), true);

        return [
            'passed' => $passed && !$hasCriticalFailures,
            'checks' => $checks,
        ];
    }

    /**
     * Check PHP version meets minimum requirement.
     */
    private function checkPhpVersion(): array
    {
        $currentVersion = PHP_VERSION;
        $passed = version_compare($currentVersion, self::MIN_PHP_VERSION, '>=');

        return [
            'name' => 'PHP Version',
            'passed' => $passed,
            'message' => $passed
                ? "PHP {$currentVersion} (required: >= " . self::MIN_PHP_VERSION . ")"
                : "PHP {$currentVersion} is below minimum requirement (" . self::MIN_PHP_VERSION . ")",
            'severity' => 'critical',
        ];
    }

    /**
     * Check FFI extension is loaded.
     */
    private function checkFfiExtension(): array
    {
        $passed = extension_loaded('ffi');

        return [
            'name' => 'FFI Extension',
            'passed' => $passed,
            'message' => $passed
                ? 'FFI extension is loaded'
                : 'FFI extension is not loaded. Enable it in php.ini or install PHP-FFI',
            'severity' => 'critical',
        ];
    }

    /**
     * Check FFmpeg binary exists and is functional.
     */
    private function checkFfmpegBinary(): array
    {
        try {
            $path = $this->config->ffmpegPath;

            if ($path === '') {
                $path = $this->autoConfigurator->detectBinary('ffmpeg');
            }

            if ($path === null || $path === '') {
                return [
                    'name' => 'FFmpeg Binary',
                    'passed' => false,
                    'message' => 'FFmpeg binary not found. Install FFmpeg or set FFMPEG_PATH environment variable',
                    'severity' => 'critical',
                ];
            }

            $validated = $this->autoConfigurator->validateBinary($path);

            return [
                'name' => 'FFmpeg Binary',
                'passed' => $validated,
                'message' => $validated
                    ? "FFmpeg found at {$path}"
                    : "FFmpeg at {$path} is not executable or does not respond to --version",
                'severity' => 'critical',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'FFmpeg Binary',
                'passed' => false,
                'message' => 'Error checking FFmpeg: ' . $e->getMessage(),
                'severity' => 'critical',
            ];
        }
    }

    /**
     * Check FFprobe binary exists and is functional.
     */
    private function checkFfprobeBinary(): array
    {
        try {
            $path = $this->config->ffprobePath;

            if ($path === '') {
                $path = $this->autoConfigurator->detectBinary('ffprobe');
            }

            if ($path === null || $path === '') {
                return [
                    'name' => 'FFprobe Binary',
                    'passed' => false,
                    'message' => 'FFprobe binary not found. Install FFmpeg (includes FFprobe)',
                    'severity' => 'critical',
                ];
            }

            $validated = $this->autoConfigurator->validateBinary($path);

            return [
                'name' => 'FFprobe Binary',
                'passed' => $validated,
                'message' => $validated
                    ? "FFprobe found at {$path}"
                    : "FFprobe at {$path} is not executable",
                'severity' => 'critical',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'FFprobe Binary',
                'passed' => false,
                'message' => 'Error checking FFprobe: ' . $e->getMessage(),
                'severity' => 'critical',
            ];
        }
    }

    /**
     * Check yt-dlp binary exists and is functional.
     */
    private function checkYtdlpBinary(): array
    {
        try {
            $path = $this->config->ytdlpPath;

            if ($path === '') {
                $path = $this->autoConfigurator->detectBinary('yt-dlp');
            }

            if ($path === null || $path === '') {
                return [
                    'name' => 'yt-dlp Binary',
                    'passed' => false,
                    'message' => 'yt-dlp binary not found. Install yt-dlp or set YTDLP_PATH',
                    'severity' => 'critical',
                ];
            }

            $validated = $this->autoConfigurator->validateBinary($path);

            return [
                'name' => 'yt-dlp Binary',
                'passed' => $validated,
                'message' => $validated
                    ? "yt-dlp found at {$path}"
                    : "yt-dlp at {$path} is not executable",
                'severity' => 'critical',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'yt-dlp Binary',
                'passed' => false,
                'message' => 'Error checking yt-dlp: ' . $e->getMessage(),
                'severity' => 'critical',
            ];
        }
    }

    /**
     * Check Wirehair library can be loaded via FFI.
     */
    private function checkWirehairLibrary(): array
    {
        try {
            $path = $this->config->wirehairLibPath;

            if ($path === '') {
                $path = $this->autoConfigurator->detectWirehairLibrary();
            }

            if ($path === null || $path === '') {
                return [
                    'name' => 'Wirehair Library',
                    'passed' => false,
                    'message' => 'Wirehair library not found. Provide pre-built binary or compile from source',
                    'severity' => 'critical',
                ];
            }

            // Try to load via FFI
            $ffi = @FFI::cdef(
                'int wirehair_init_(int version);',
                $path
            );

            if ($ffi === null) {
                return [
                    'name' => 'Wirehair Library',
                    'passed' => false,
                    'message' => "Failed to load Wirehair library from {$path}",
                    'severity' => 'critical',
                ];
            }

            // Try to initialize
            $result = $ffi->wirehair_init_(2);
            $passed = ($result === 0);

            return [
                'name' => 'Wirehair Library',
                'passed' => $passed,
                'message' => $passed
                    ? "Wirehair library loaded successfully from {$path}"
                    : "Wirehair library at {$path} failed initialization (error code: {$result})",
                'severity' => 'critical',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'Wirehair Library',
                'passed' => false,
                'message' => 'Error loading Wirehair: ' . $e->getMessage(),
                'severity' => 'critical',
            ];
        }
    }

    /**
     * Check YouTube API credentials are present.
     */
    private function checkYoutubeCredentials(): array
    {
        $credentials = $this->config->youtubeOAuthCredentials;

        $hasApiKey = !empty($this->config->youtubeApiKey);
        $hasClientId = !empty($credentials['client_id'] ?? '');
        $hasClientSecret = !empty($credentials['client_secret'] ?? '');
        $hasRefreshToken = !empty($credentials['refresh_token'] ?? '');

        $allPresent = $hasApiKey && $hasClientId && $hasClientSecret && $hasRefreshToken;

        $missing = [];
        if (!$hasApiKey) $missing[] = 'API key';
        if (!$hasClientId) $missing[] = 'Client ID';
        if (!$hasClientSecret) $missing[] = 'Client Secret';
        if (!$hasRefreshToken) $missing[] = 'Refresh Token';

        return [
            'name' => 'YouTube Credentials',
            'passed' => $allPresent,
            'message' => $allPresent
                ? 'All YouTube OAuth credentials are present'
                : 'Missing YouTube credentials: ' . implode(', ', $missing),
            'severity' => 'critical',
        ];
    }

    /**
     * Check temporary disk is writable.
     */
    private function checkTempDiskWritable(): array
    {
        try {
            $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ytstorage_test_' . uniqid();

            if (!@mkdir($tempDir, 0755)) {
                return [
                    'name' => 'Temp Disk Writable',
                    'passed' => false,
                    'message' => 'Failed to create test directory in temp path',
                    'severity' => 'warning',
                ];
            }

            $testFile = $tempDir . DIRECTORY_SEPARATOR . 'test.txt';
            if (@file_put_contents($testFile, 'test') === false) {
                @rmdir($tempDir);
                return [
                    'name' => 'Temp Disk Writable',
                    'passed' => false,
                    'message' => 'Failed to write test file to temp directory',
                    'severity' => 'warning',
                ];
            }

            @unlink($testFile);
            @rmdir($tempDir);

            return [
                'name' => 'Temp Disk Writable',
                'passed' => true,
                'message' => 'Temporary directory is writable',
                'severity' => 'info',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'Temp Disk Writable',
                'passed' => false,
                'message' => 'Error testing temp disk: ' . $e->getMessage(),
                'severity' => 'warning',
            ];
        }
    }

    /**
     * Format the health check report as a console table.
     *
     * @param  array $report The report from run().
     * @return string Formatted table string.
     */
    public static function formatAsTable(array $report): string
    {
        $lines = [];
        $lines[] = '┌──────────────────────┬─────────┬─────────────────────────────────────────────┐';
        $lines[] = '│ Check                │ Status  │ Details                                   │';
        $lines[] = '├──────────────────────┼─────────┼─────────────────────────────────────────────┤';

        foreach ($report['checks'] as $check) {
            $status = $check['passed'] ? '✔ PASS' : '✖ FAIL';
            $statusColor = $check['passed'] ? 'green' : 'red';

            // Truncate message if too long
            $message = substr($check['message'], 0, 43);
            if (strlen($check['message']) > 43) {
                $message .= '...';
            }

            $lines[] = sprintf(
                '│ %-20s │ %-7s │ %-43s │',
                substr($check['name'], 0, 20),
                $status,
                $message
            );
        }

        $lines[] = '└──────────────────────┴─────────┴─────────────────────────────────────────────┘';

        return implode("\n", $lines);
    }
}
