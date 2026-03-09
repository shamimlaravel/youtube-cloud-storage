<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Tests;

use PHPUnit\Framework\TestCase;
use Shamimstack\YouTubeCloudStorage\Support\AutoConfigurator;
use Shamimstack\YouTubeCloudStorage\Support\HealthCheck;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\DTOs\PacketMetadata;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageReference;
use Shamimstack\YouTubeCloudStorage\Engines\FountainEncoder;
use Shamimstack\YouTubeCloudStorage\Engines\VideoProcessor;
use Shamimstack\YouTubeCloudStorage\Engines\EncoderEngine;
use Shamimstack\YouTubeCloudStorage\Drivers\YouTubeStorageDriver;

/**
 * Unit Tests for YouTube Cloud Storage Package.
 *
 * Tests core functionality, algorithms, and data integrity.
 */
class UnitTest extends TestCase
{
    private ?StorageConfig $config = null;
    private ?AutoConfigurator $autoConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->config = new StorageConfig([
            'ffmpeg_path' => '',
            'ffprobe_path' => '',
            'ytdlp_path' => '',
            'wirehair_lib_path' => '',
            'youtube_api_key' => 'test_key',
            'youtube_oauth_credentials' => [
                'client_id' => 'test',
                'client_secret' => 'test',
                'refresh_token' => 'test',
            ],
            'coefficient_threshold' => 10,
            'redundancy_factor' => 1.5,
            'dct_positions' => [[1, 2], [2, 1]],
        ]);

        $this->autoConfig = new AutoConfigurator();
    }

    /**
     * Test StorageConfig validation with valid data.
     */
    public function testStorageConfigValidData(): void
    {
        $config = new StorageConfig([
            'ffmpeg_path' => '',
            'youtube_api_key' => 'key',
            'youtube_oauth_credentials' => [],
            'coefficient_threshold' => 10,
            'redundancy_factor' => 1.5,
        ]);

        $this->assertInstanceOf(StorageConfig::class, $config);
        $this->assertEquals(10, $config->coefficientThreshold);
        $this->assertEquals(1.5, $config->redundancyFactor);
    }

    /**
     * Test StorageConfig rejects invalid coefficient threshold.
     */
    public function testStorageConfigRejectsInvalidThreshold(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageConfig([
            'ffmpeg_path' => '',
            'youtube_api_key' => 'key',
            'youtube_oauth_credentials' => [],
            'coefficient_threshold' => -1, // Invalid: negative
        ]);
    }

    /**
     * Test StorageConfig rejects invalid redundancy factor.
     */
    public function testStorageConfigRejectsInvalidRedundancy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageConfig([
            'ffmpeg_path' => '',
            'youtube_api_key' => 'key',
            'youtube_oauth_credentials' => [],
            'redundancy_factor' => 0.5, // Invalid: < 1.0
        ]);
    }

    /**
     * Test binary path auto-detection when empty.
     */
    public function testStorageConfigAutoDetectsBinaries(): void
    {
        $config = new StorageConfig([
            'ffmpeg_path' => '',
            'ffprobe_path' => '',
            'ytdlp_path' => '',
            'youtube_api_key' => 'key',
            'youtube_oauth_credentials' => [],
        ]);

        // Should not throw, allows empty strings for auto-detection
        $this->assertIsString($config->ffmpegPath);
    }

    /**
     * Test PacketMetadata creation and serialization.
     */
    public function testPacketMetadataSerialization(): void
    {
        $metadata = new PacketMetadata(
            packetIndex: 0,
            totalPackets: 10,
            isSystematic: true,
            originalSize: 1024,
            packetSize: 128,
            crc32: 0x12345678,
            timestamp: time(),
        );

        $serialized = $metadata->serialize();
        $this->assertIsString($serialized);
        $this->assertNotEmpty($serialized);

        // Test JSON serialization
        $json = json_encode($metadata);
        $this->assertIsString($json);
        
        $decoded = json_decode($json, true);
        $this->assertEquals(0, $decoded['packetIndex']);
        $this->assertEquals(10, $decoded['totalPackets']);
    }

    /**
     * Test StorageReference creation from video URL.
     */
    public function testStorageReferenceFromUrl(): void
    {
        $ref = StorageReference::fromVideoUrl(
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
        );

        $this->assertEquals('dQw4w9WgXcQ', $ref->videoId);
        $this->assertEquals('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $ref->videoUrl);
    }

    /**
     * Test StorageReference from video ID.
     */
    public function testStorageReferenceFromVideoId(): void
    {
        $ref = StorageReference::fromVideoId('abc123XYZ');

        $this->assertEquals('abc123XYZ', $ref->videoId);
        $this->assertEquals('https://www.youtube.com/watch?v=abc123XYZ', $ref->videoUrl);
    }

    /**
     * Test Wirehair library name detection per platform.
     */
    public function testWirehairLibraryNameDetection(): void
    {
        $libName = $this->autoConfig->getWirehairLibName();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertEquals('wirehair.dll', $libName);
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $this->assertEquals('libwirehair.dylib', $libName);
        } else {
            $this->assertEquals('libwirehair.so', $libName);
        }
    }

    /**
     * Test binary validation returns false for non-existent path.
     */
    public function testBinaryValidationFailsForNonExistent(): void
    {
        $validated = $this->autoConfig->validateBinary('/nonexistent/binary');
        $this->assertFalse($validated);
    }

    /**
     * Test HealthCheck report structure.
     */
    public function testHealthCheckReportStructure(): void
    {
        $healthCheck = new HealthCheck($this->config, $this->autoConfig);
        $report = $healthCheck->run();

        $this->assertIsArray($report);
        $this->assertArrayHasKey('passed', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertIsBool($report['passed']);
        $this->assertIsArray($report['checks']);

        foreach ($report['checks'] as $check) {
            $this->assertArrayHasKey('name', $check);
            $this->assertArrayHasKey('passed', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertArrayHasKey('severity', $check);
            $this->assertContains($check['severity'], ['critical', 'warning', 'info']);
        }
    }

    /**
     * Test HealthCheck table formatting produces valid string.
     */
    public function testHealthCheckTableFormatting(): void
    {
        $healthCheck = new HealthCheck($this->config, $this->autoConfig);
        $report = $healthCheck->run();
        $table = HealthCheck::formatAsTable($report);

        $this->assertIsString($table);
        $this->assertStringContainsString('│', $table);
        $this->assertStringContainsString('Check', $table);
        $this->assertStringContainsString('Status', $table);
    }

    /**
     * Test EncoderEngine instantiation and initial state.
     */
    public function testEncoderEngineInitialState(): void
    {
        $engine = new EncoderEngine(
            config: $this->config,
            fountainEncoder: new FountainEncoder($this->config),
            videoProcessor: new VideoProcessor($this->config),
        );

        $this->assertInstanceOf(EncoderEngine::class, $engine);
        $this->assertEquals('idle', $engine->currentPhase);
        $this->assertEquals(0.0, $engine->progressPercent);
        $this->assertFalse($engine->isRunning);
    }

    /**
     * Test FountainEncoder instantiation.
     */
    public function testFountainEncoderInstantiation(): void
    {
        $encoder = new FountainEncoder($this->config);
        $this->assertInstanceOf(FountainEncoder::class, $encoder);
    }

    /**
     * Test VideoProcessor instantiation.
     */
    public function testVideoProcessorInstantiation(): void
    {
        $processor = new VideoProcessor($this->config);
        $this->assertInstanceOf(VideoProcessor::class, $processor);
    }

    /**
     * Test YouTubeStorageDriver instantiation.
     */
    public function testYouTubeStorageDriverInstantiation(): void
    {
        $driver = new YouTubeStorageDriver($this->config);
        $this->assertInstanceOf(YouTubeStorageDriver::class, $driver);
    }

    /**
     * Test DCT basis matrix is orthonormal.
     */
    public function testDctBasisOrthonormality(): void
    {
        $processor = new VideoProcessor($this->config);
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('getDctBasis');
        $method->setAccessible(true);

        $basis = $method->invoke($processor);

        $this->assertIsArray($basis);
        $this->assertCount(8, $basis);
        $this->assertCount(8, $basis[0]);

        // Verify orthonormality: T · T^T = I
        for ($i = 0; $i < 8; $i++) {
            for ($j = 0; $j < 8; $j++) {
                $dotProduct = 0.0;
                for ($k = 0; $k < 8; $k++) {
                    $dotProduct += $basis[$i][$k] * $basis[$j][$k];
                }

                $expected = ($i === $j) ? 1.0 : 0.0;
                $this->assertEqualsWithDelta($expected, $dotProduct, 0.0001,
                    "Orthonormality failed at row {$i}, col {$j}");
            }
        }
    }

    /**
     * Test bit stream chunking works correctly.
     */
    public function testBitStreamChunking(): void
    {
        $processor = new VideoProcessor($this->config);
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('chunkBitStream');
        $method->setAccessible(true);

        // Test even chunks
        $chunks = $method->invoke($processor, '10110011', 4);
        $this->assertEquals(['1011', '0011'], $chunks);

        // Test odd chunks (padding)
        $chunks = $method->invoke($processor, '101', 2);
        $this->assertEquals(['10', '10'], $chunks); // Last bit padded
    }

    /**
     * Test CRC32 calculation.
     */
    public function testCrc32Calculation(): void
    {
        $data = 'test data';
        $crc = hash('crc32b', $data, true);
        
        $this->assertIsString($crc);
        $this->assertEquals(4, strlen($crc));
    }

    /**
     * Test AutoConfigurator environment variable expansion.
     */
    public function testEnvVarExpansion(): void
    {
        $reflection = new \ReflectionClass($this->autoConfig);
        $method = $reflection->getMethod('expandEnvVars');
        $method->setAccessible(true);

        if (PHP_OS_FAMILY === 'Windows') {
            // Test Windows %VAR% syntax
            $path = $method->invoke($this->autoConfig, '%USERNAME%');
            $expected = getenv('USERNAME');
            $this->assertEquals($expected, $path);
        } else {
            // Unix doesn't expand in this implementation
            $path = $method->invoke($this->autoConfig, '$USER');
            $this->assertEquals('$USER', $path);
        }
    }

    /**
     * Test AutoConfigurator runAutoConfig returns correct structure.
     */
    public function testAutoConfigRunStructure(): void
    {
        $result = $this->autoConfig->runAutoConfig();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ffmpeg', $result);
        $this->assertArrayHasKey('ffprobe', $result);
        $this->assertArrayHasKey('ytdlp', $result);
        $this->assertArrayHasKey('wirehair', $result);
        $this->assertArrayHasKey('allFound', $result);
        $this->assertIsBool($result['allFound']);
    }
}
