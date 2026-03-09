<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Support;

use Shamimstack\YouTubeCloudStorage\Exceptions\BinaryNotFoundException;

/**
 * Auto-Configuration System — Binary Discovery and Wirehair Compilation.
 *
 * Automatically detects required system binaries (FFmpeg, FFprobe, yt-dlp)
 * and manages the Wirehair fountain code library.
 *
 * Features:
 *   - Probes system PATH using `which` (Linux/macOS) or `where` (Windows)
 *   - Checks common installation directories
 *   - Respects environment variable overrides
 *   - Can compile Wirehair from bundled source if no pre-built binary exists
 *
 * Usage:
 *   $autoConfig = new AutoConfigurator();
 *   $ffmpegPath = $autoConfig->detectBinary('ffmpeg');
 *   $autoConfig->compileWirehair('/path/to/output');
 */
class AutoConfigurator
{
    /**
     * Common paths where FFmpeg/FFprobe might be installed.
     */
    private const array FFMPEG_COMMON_PATHS = [
        '/usr/bin',
        '/usr/local/bin',
        '/opt/homebrew/bin',
        '/opt/local/bin',
        'C:\\ffmpeg\\bin',
        'C:\\Program Files\\ffmpeg\\bin',
        'C:\\Users\\%USERNAME%\\scoop\\apps\\ffmpeg\\current',
    ];

    /**
     * Common paths where yt-dlp might be installed.
     */
    private const array YTDL_COMMON_PATHS = [
        '/usr/bin',
        '/usr/local/bin',
        '/opt/homebrew/bin',
        'C:\\Program Files\\yt-dlp',
        'C:\\Users\\%USERNAME%\\scoop\\apps\\yt-dlp\\current',
    ];

    /**
     * Standard library directories for Wirehair shared library.
     */
    private const array WIREHAIR_LIB_PATHS = [
        '/usr/lib',
        '/usr/local/lib',
        '/opt/homebrew/lib',
        'C:\\Windows\\System32',
        'C:\\Program Files\\wirehair',
    ];

    /**
     * Bundled Wirehair source directory relative to package root.
     */
    private const string WIREHAIR_SOURCE_DIR = __DIR__ . '/../../resources/wirehair';

    /**
     * Cache directory for compiled Wirehair binary.
     */
    private const string WIREHAIR_CACHE_DIR = __DIR__ . '/../../storage/youtube-storage/lib';

    /**
     * Detect a binary by name on the system PATH.
     *
     * @param  string $name Binary name (e.g., 'ffmpeg', 'ffprobe', 'yt-dlp').
     * @return string|null Absolute path to the binary, or null if not found.
     */
    public function detectBinary(string $name): ?string
    {
        // Check environment variable override first
        $envVar = match (strtolower($name)) {
            'ffmpeg' => 'FFMPEG_PATH',
            'ffprobe' => 'FFPROBE_PATH',
            'yt-dlp' => 'YTDLP_PATH',
            default => null,
        };

        if ($envVar !== null && ($envValue = getenv($envVar)) !== false) {
            if (file_exists($envValue) && is_executable($envValue)) {
                return $envValue;
            }
        }

        // Try system PATH using which/where command
        $command = PHP_OS_FAMILY === 'Windows'
            ? "where {$name} 2>NUL"
            : "which {$name} 2>/dev/null";

        $result = @exec($command, $output, $returnCode);

        if ($returnCode === 0 && isset($output[0]) && $output[0] !== '') {
            $path = trim($output[0]);
            if (PHP_OS_FAMILY === 'Windows') {
                // Normalize Windows paths
                $path = str_replace('/', '\\', $path);
            }
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Check common installation paths
        $commonPaths = match (strtolower($name)) {
            'ffmpeg', 'ffprobe' => self::FFMPEG_COMMON_PATHS,
            'yt-dlp' => self::YTDL_COMMON_PATHS,
            default => [],
        };

        foreach ($commonPaths as $baseDir) {
            // Expand environment variables in paths
            $baseDir = $this->expandEnvVars($baseDir);

            if (!is_dir($baseDir)) {
                continue;
            }

            $binaryPath = $baseDir . DIRECTORY_SEPARATOR . $name;

            // On Windows, also try with .exe extension
            if (PHP_OS_FAMILY === 'Windows') {
                if (!file_exists($binaryPath) && !file_exists($binaryPath . '.exe')) {
                    continue;
                }
            } else {
                if (!file_exists($binaryPath)) {
                    continue;
                }
            }

            $actualPath = file_exists($binaryPath) ? $binaryPath : $binaryPath . '.exe';

            if (file_exists($actualPath) && is_executable($actualPath)) {
                return $actualPath;
            }
        }

        return null;
    }

    /**
     * Validate that a binary is functional by running --version.
     *
     * @param  string $path Path to the binary.
     * @return bool True if the binary responds to --version.
     */
    public function validateBinary(string $path): bool
    {
        if (!file_exists($path) || !is_executable($path)) {
            return false;
        }

        $command = escapeshellcmd($path) . ' --version';
        $result = @exec($command, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Detect the Wirehair shared library.
     *
     * @return string|null Path to the Wirehair library, or null if not found.
     */
    public function detectWirehairLibrary(): ?string
    {
        $libName = $this->getWirehairLibName();

        // Check config first
        $configPath = getenv('YTSTORAGE_WIREHAIR_LIB');
        if ($configPath !== false && file_exists($configPath)) {
            return $configPath;
        }

        // Check standard library paths
        foreach (self::WIREHAIR_LIB_PATHS as $baseDir) {
            $baseDir = $this->expandEnvVars($baseDir);
            $libPath = $baseDir . DIRECTORY_SEPARATOR . $libName;

            if (file_exists($libPath)) {
                return $libPath;
            }
        }

        // Check cache directory
        $cachePath = $this->expandEnvVars(self::WIREHAIR_CACHE_DIR) . DIRECTORY_SEPARATOR . $libName;
        if (file_exists($cachePath)) {
            return $cachePath;
        }

        return null;
    }

    /**
     * Compile Wirehair from bundled source.
     *
     * @param  string|null $outputDir Optional output directory. Defaults to cache dir.
     * @return string Path to the compiled library.
     *
     * @throws \RuntimeException If compilation fails.
     * @throws BinaryNotFoundException If no C compiler is found.
     */
    public function compileWirehair(?string $outputDir = null): string
    {
        $outputDir ??= $this->expandEnvVars(self::WIREHAIR_CACHE_DIR);

        // Create output directory if it doesn't exist
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $libName = $this->getWirehairLibName();
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $libName;

        // Check if source directory exists
        if (!is_dir(self::WIREHAIR_SOURCE_DIR)) {
            throw new \RuntimeException(
                "Wirehair source directory not found at " . self::WIREHAIR_SOURCE_DIR . ". " .
                "Please install the wirehair-c package or provide a pre-built binary."
            );
        }

        // Detect C compiler
        $compiler = $this->detectCompiler();
        if ($compiler === null) {
            throw new BinaryNotFoundException(
                'gcc or clang',
                'No C compiler found. Please install GCC or Clang to compile Wirehair.'
            );
        }

        // Build compilation command based on OS
        $compileCmd = match (PHP_OS_FAMILY) {
            'Windows' => $this->buildWindowsCompileCommand($compiler, $outputPath),
            'Darwin', 'Linux' => $this->buildUnixCompileCommand($compiler, $outputPath),
            default => throw new \RuntimeException("Unsupported OS: " . PHP_OS_FAMILY),
        };

        // Execute compilation
        $result = @exec($compileCmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException(
                "Wirehair compilation failed with exit code {$returnCode}. Output:\n" .
                implode("\n", $output)
            );
        }

        // Verify the compiled library
        if (!file_exists($outputPath)) {
            throw new \RuntimeException(
                "Compilation succeeded but library not found at {$outputPath}."
            );
        }

        return $outputPath;
    }

    /**
     * Get the platform-specific Wirehair library filename.
     */
    public function getWirehairLibName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'wirehair.dll',
            'Darwin' => 'libwirehair.dylib',
            'Linux' => 'libwirehair.so',
            default => throw new \RuntimeException("Unsupported OS: " . PHP_OS_FAMILY),
        };
    }

    /**
     * Run a complete auto-configuration and return results.
     *
     * @return array{
     *     ffmpeg: string|null,
     *     ffprobe: string|null,
     *     ytdlp: string|null,
     *     wirehair: string|null,
     *     allFound: bool
     * }
     */
    public function runAutoConfig(): array
    {
        $ffmpeg = $this->detectBinary('ffmpeg');
        $ffprobe = $this->detectBinary('ffprobe');
        $ytdlp = $this->detectBinary('yt-dlp');
        $wirehair = $this->detectWirehairLibrary();

        // Try to compile Wirehair if not found
        if ($wirehair === null) {
            try {
                $wirehair = $this->compileWirehair();
            } catch (\Throwable $e) {
                // Compilation failed, wirehair remains null
            }
        }

        return [
            'ffmpeg' => $ffmpeg,
            'ffprobe' => $ffprobe,
            'ytdlp' => $ytdlp,
            'wirehair' => $wirehair,
            'allFound' => ($ffmpeg !== null && $ffprobe !== null && $ytdlp !== null && $wirehair !== null),
        ];
    }

    /**
     * Expand environment variables in a path string.
     */
    private function expandEnvVars(string $path): string
    {
        // Expand %VAR% syntax on Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $path = preg_replace_callback(
                '/%([^%]+)%/',
                fn ($matches) => getenv($matches[1]) ?: $matches[0],
                $path
            );
        }

        return $path;
    }

    /**
     * Detect an available C compiler.
     *
     * @return string|null Compiler command (gcc, clang, or cl.exe), or null.
     */
    private function detectCompiler(): ?string
    {
        $compilers = ['gcc', 'clang'];

        if (PHP_OS_FAMILY === 'Windows') {
            $compilers[] = 'cl';
        }

        foreach ($compilers as $compiler) {
            $command = PHP_OS_FAMILY === 'Windows'
                ? "where {$compiler} 2>NUL"
                : "which {$compiler} 2>/dev/null";

            $result = @exec($command, $output, $returnCode);

            if ($returnCode === 0 && isset($output[0])) {
                return trim($output[0]);
            }
        }

        return null;
    }

    /**
     * Build Unix (Linux/macOS) compilation command.
     */
    private function buildUnixCompileCommand(string $compiler, string $outputPath): string
    {
        $sourceDir = self::WIREHAIR_SOURCE_DIR;
        $sources = glob("{$sourceDir}/*.c");

        if (empty($sources)) {
            throw new \RuntimeException("No C source files found in {$sourceDir}");
        }

        $flags = match (PHP_OS_FAMILY) {
            'Darwin' => '-dynamiclib -o',
            'Linux' => '-shared -fPIC -o',
            default => '-shared -fPIC -o',
        };

        $sourcesStr = implode(' ', $sources);

        return "{$compiler} {$flags} " . escapeshellarg($outputPath) . " {$sourcesStr}";
    }

    /**
     * Build Windows compilation command.
     */
    private function buildWindowsCompileCommand(string $compiler, string $outputPath): string
    {
        $sourceDir = self::WIREHAIR_SOURCE_DIR;
        $sources = glob("{$sourceDir}/*.c");

        if (empty($sources)) {
            throw new \RuntimeException("No C source files found in {$sourceDir}");
        }

        if (stripos($compiler, 'cl') !== false) {
            // MSVC compiler
            $sourcesStr = implode(' ', $sources);
            return "cl /LD {$sourcesStr} /Fe:" . escapeshellarg($outputPath);
        } else {
            // GCC/MinGW
            $sourcesStr = implode(' ', $sources);
            return "{$compiler} -shared -o " . escapeshellarg($outputPath) . " {$sourcesStr}";
        }
    }
}
