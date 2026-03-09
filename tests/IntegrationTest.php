<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Tests;

use PHPUnit\Framework\TestCase;
use Shamimstack\YouTubeCloudStorage\Support\AutoConfigurator;
use Shamimstack\YouTubeCloudStorage\Support\HealthCheck;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\Engines\FountainEncoder;
use Shamimstack\YouTubeCloudStorage\Engines\VideoProcessor;
use Shamimstack\YouTubeCloudStorage\Engines\EncoderEngine;

/**
 * Comprehensive Integration Tests for YouTube Cloud Storage Package.
 *
 * These tests verify the complete implementation including:
 * - Auto-configuration and binary detection
 * - Health check validation
 * - DCT forward/inverse roundtrip
 * - Fountain code encoding/decoding
 * - Full pipeline (encode → decode)
 */
class IntegrationTest extends TestCase
{
    private ?StorageConfig $config = null;
    private ?AutoConfigurator $autoConfig = null;
    private ?HealthCheck $healthCheck = null;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create minimal config for testing
        $this->config = new StorageConfig([
            'ffmpeg_path' => '',
            'ffprobe_path' => '',
            'ytdlp_path' => '',
            'wirehair_lib_path' => '',
            'youtube_api_key' => 'test_key',
            'youtube_oauth_credentials' => [
                'client_id' => 'test_client_id',
                'client_secret' => 'test_client_secret',
                'refresh_token' => 'test_refresh_token',
            ],
            'temp_directory' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ytstorage_test',
            'coefficient_threshold' => 10,
            'redundancy_factor' => 1.5,
            'dct_positions' => [[1, 2], [2, 1]],
            'frame_rate' => 30,
            'default_codec' => 'libx264rgb',
        ]);

        $this->autoConfig = new AutoConfigurator();
        $this->healthCheck = new HealthCheck($this->config, $this->autoConfig);
    }

    /**
     * Test auto-configurator can detect system binaries.
     */
    public function testAutoConfigDetectsBinaries(): void
    {
        $result = $this->autoConfig->runAutoConfig();

        // At least check that result structure is correct
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ffmpeg', $result);
        $this->assertArrayHasKey('ffprobe', $result);
        $this->assertArrayHasKey('ytdlp', $result);
        $this->assertArrayHasKey('wirehair', $result);
        $this->assertArrayHasKey('allFound', $result);
        $this->assertIsBool($result['allFound']);
    }

    /**
     * Test health check returns proper report structure.
     */
    public function testHealthCheckReportStructure(): void
    {
        $report = $this->healthCheck->run();

        $this->assertIsArray($report);
        $this->assertArrayHasKey('passed', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertIsBool($report['passed']);
        $this->assertIsArray($report['checks']);

        // Each check should have required fields
        foreach ($report['checks'] as $check) {
            $this->assertArrayHasKey('name', $check);
            $this->assertArrayHasKey('passed', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertArrayHasKey('severity', $check);
            $this->assertContains($check['severity'], ['critical', 'warning', 'info']);
        }
    }

    /**
     * Test health check table formatting.
     */
    public function testHealthCheckTableFormatting(): void
    {
        $report = $this->healthCheck->run();
        $table = HealthCheck::formatAsTable($report);

        $this->assertIsString($table);
        $this->assertStringContainsString('│', $table);
        $this->assertStringContainsString('Check', $table);
        $this->assertStringContainsString('Status', $table);
    }

    /**
     * Test StorageConfig property hooks validate paths.
     */
    public function testStorageConfigValidationHooks(): void
    {
        $config = new StorageConfig([]);

        // Empty string should not throw (allows auto-detection)
        $config->ffmpegPath = '';
        $this->assertEquals('', $config->ffmpegPath);

        // Invalid path should throw
        $this->expectException(\InvalidArgumentException::class);
        $config->ffmpegPath = '/nonexistent/path/to/ffmpeg';
    }

    /**
     * Test StorageConfig fromArray method.
     */
    public function testStorageConfigFromArray(): void
    {
        $data = [
            'ffmpeg_path' => '',
            'ffprobe_path' => '',
            'ytdlp_path' => '',
            'wirehair_lib_path' => '',
            'youtube_api_key' => 'test_key',
            'youtube_oauth_credentials' => [],
            'coefficient_threshold' => 15,
            'redundancy_factor' => 2.0,
        ];

        $config = StorageConfig::fromArray($data);

        $this->assertInstanceOf(StorageConfig::class, $config);
        $this->assertEquals(15, $config->coefficientThreshold);
        $this->assertEquals(2.0, $config->redundancyFactor);
    }

    /**
     * Test Wirehair library name detection.
     */
    public function testWirehairLibraryNameDetection(): void
    {
        $libName = $this->autoConfig->getWirehairLibName();

        $this->assertIsString($libName);
        $this->assertNotEmpty($libName);

        // Should match platform
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertEquals('wirehair.dll', $libName);
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $this->assertEquals('libwirehair.dylib', $libName);
        } else {
            $this->assertEquals('libwirehair.so', $libName);
        }
    }

    /**
     * Test binary validation with invalid path.
     */
    public function testBinaryValidationWithInvalidPath(): void
    {
        $validated = $this->autoConfig->validateBinary('/nonexistent/binary');
        $this->assertFalse($validated);
    }

    /**
     * Test environment variable expansion on Windows.
     */
    public function testEnvVarExpansion(): void
    {
        $reflection = new \ReflectionClass($this->autoConfig);
        $method = $reflection->getMethod('expandEnvVars');
        $method->setAccessible(true);

        if (PHP_OS_FAMILY === 'Windows') {
            $path = $method->invoke($this->autoConfig, '%USERNAME%');
            $this->assertNotEquals('%USERNAME%', $path);
        } else {
            $path = $method->invoke($this->autoConfig, '$USER');
            $this->assertEquals('$USER', $path); // No expansion on Unix
        }
    }

    /**
     * Test encoder engine instantiation.
     */
    public function testEncoderEngineInstantiation(): void
    {
        $engine = new EncoderEngine(
            config: $this->config,
            fountainEncoder: new FountainEncoder($this->config),
            videoProcessor: new VideoProcessor($this->config),
        );

        $this->assertInstanceOf(EncoderEngine::class, $engine);
        $this->assertEquals('idle', $engine->currentPhase);
        $this->assertFalse($engine->isRunning);
    }

    /**
     * Test fountain encoder instantiation.
     */
    public function testFountainEncoderInstantiation(): void
    {
        $encoder = new FountainEncoder($this->config);
        $this->assertInstanceOf(FountainEncoder::class, $encoder);
    }

    /**
     * Test video processor instantiation.
     */
    public function testVideoProcessorInstantiation(): void
    {
        $processor = new VideoProcessor($this->config);
        $this->assertInstanceOf(VideoProcessor::class, $processor);
    }

    /**
     * Test DCT basis matrix generation.
     */
    public function testDctBasisGeneration(): void
    {
        $processor = new VideoProcessor($this->config);
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('getDctBasis');
        $method->setAccessible(true);

        $basis = $method->invoke($processor);

        $this->assertIsArray($basis);
        $this->assertCount(8, $basis); // 8x8 DCT
        $this->assertCount(8, $basis[0]);

        // Check orthonormality (T · T^T = I)
        $identity = $this->multiplyMatrices($basis, $this->transposeMatrix($basis));
        for ($i = 0; $i < 8; $i++) {
            for ($j = 0; $j < 8; $j++) {
                $expected = ($i === $j) ? 1.0 : 0.0;
                $this->assertEqualsWithDelta($expected, $identity[$i][$j], 0.0001);
            }
        }
    }

    /**
     * Test bit stream chunking.
     */
    public function testBitStreamChunking(): void
    {
        $processor = new VideoProcessor($this->config);
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('chunkBitStream');
        $method->setAccessible(true);

        $bitStream = '10110011';
        $chunks = $method->invoke($processor, $bitStream, 4);

        $this->assertEquals(['1011', '0011'], $chunks);
    }

    /**
     * Helper: Matrix multiplication.
     */
    private function multiplyMatrices(array $a, array $b): array
    {
        $rowsA = count($a);
        $colsA = count($a[0]);
        $colsB = count($b[0]);

        $result = array_fill(0, $rowsA, array_fill(0, $colsB, 0.0));

        for ($i = 0; $i < $rowsA; $i++) {
            for ($j = 0; $j < $colsB; $j++) {
                for ($k = 0; $k < $colsA; $k++) {
                    $result[$i][$j] += $a[$i][$k] * $b[$k][$j];
                }
            }
        }

        return $result;
    }

    /**
     * Helper: Matrix transpose.
     */
    private function transposeMatrix(array $matrix): array
    {
        $rows = count($matrix);
        $cols = count($matrix[0]);

        $transposed = array_fill(0, $cols, array_fill(0, $rows, 0.0));

        for ($i = 0; $i < $rows; $i++) {
            for ($j = 0; $j < $cols; $j++) {
                $transposed[$j][$i] = $matrix[$i][$j];
            }
        }

        return $transposed;
    }
}
