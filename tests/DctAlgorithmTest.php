<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Tests;

use PHPUnit\Framework\TestCase;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\Engines\VideoProcessor;

/**
 * Functional Tests for DCT Algorithm.
 *
 * Validates the mathematical correctness of DCT operations.
 */
class DctAlgorithmTest extends TestCase
{
    private ?StorageConfig $config = null;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->config = new StorageConfig([
            'ffmpeg_path' => '',
            'youtube_api_key' => 'key',
            'youtube_oauth_credentials' => [],
            'coefficient_threshold' => 10,
        ]);
    }

    /**
     * Test forward and inverse DCT roundtrip preserves pixel values.
     */
    public function testDctRoundtripPreservesData(): void
    {
        $processor = new VideoProcessor($this->config);
        
        // Create a simple 8x8 test block
        $block = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $block[$y][$x] = ($x + $y) * 10; // Gradient pattern
            }
        }

        // Apply forward DCT
        $reflection = new \ReflectionClass($processor);
        $forwardMethod = $reflection->getMethod('applyForwardDct');
        $forwardMethod->setAccessible(true);
        $coefficients = $forwardMethod->invoke($processor, $block);

        // Apply inverse DCT
        $inverseMethod = $reflection->getMethod('applyInverseDct');
        $inverseMethod->setAccessible(true);
        $reconstructed = $inverseMethod->invoke($processor, $coefficients);

        // Verify reconstruction is accurate (within floating point precision)
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $this->assertEqualsWithDelta(
                    $block[$y][$x],
                    $reconstructed[$y][$x],
                    0.0001,
                    "Pixel mismatch at ({$x}, {$y})"
                );
            }
        }
    }

    /**
     * Test DCT basis matrix properties.
     */
    public function testDctBasisMatrixProperties(): void
    {
        $processor = new VideoProcessor($this->config);
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('getDctBasis');
        $method->setAccessible(true);

        $basis = $method->invoke($processor);

        // Property 1: First row should be constant (DC component)
        $firstRowValue = $basis[0][0];
        for ($i = 0; $i < 8; $i++) {
            $this->assertEqualsWithDelta(
                $firstRowValue,
                $basis[0][$i],
                0.0001,
                "First row not constant at index {$i}"
            );
        }

        // Property 2: Rows should be orthogonal
        for ($i = 0; $i < 8; $i++) {
            for ($j = $i + 1; $j < 8; $j++) {
                $dotProduct = 0.0;
                for ($k = 0; $k < 8; $k++) {
                    $dotProduct += $basis[$i][$k] * $basis[$j][$k];
                }
                $this->assertEqualsWithDelta(
                    0.0,
                    $dotProduct,
                    0.0001,
                    "Rows {$i} and {$j} not orthogonal"
                );
            }
        }
    }

    /**
     * Test sign-bit embedding changes coefficient signs correctly.
     */
    public function testSignBitEmbedding(): void
    {
        $processor = new VideoProcessor($this->config);
        $reflection = new \ReflectionClass($processor);
        
        $embedMethod = $reflection->getMethod('embedBitInCoefficients');
        $embedMethod->setAccessible(true);

        // Create test coefficients (all positive)
        $coefficients = [
            [50.0, 10.0, 5.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            [10.0, 5.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            [5.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
        ];

        $positions = [[0, 1]]; // Embed at position (0, 1)
        $threshold = 10;

        // Test embedding bit 0 (should make positive)
        $modifiedFor0 = $embedMethod->invoke($processor, $coefficients, '0', $positions, $threshold);
        $this->assertGreaterThan(0, $modifiedFor0[0][1], "Bit 0 should result in positive coefficient");

        // Test embedding bit 1 (should make negative)
        $modifiedFor1 = $embedMethod->invoke($processor, $coefficients, '1', $positions, $threshold);
        $this->assertLessThan(0, $modifiedFor1[0][1], "Bit 1 should result in negative coefficient");
    }

    /**
     * Test frame generation produces valid RGB data.
     */
    public function testFrameGenerationProducesValidRgb(): void
    {
        $processor = new VideoProcessor($this->config);
        $reflection = new \ReflectionClass($processor);
        
        $generateMethod = $reflection->getMethod('generateFrame');
        $generateMethod->setAccessible(true);

        $bitStream = str_repeat('10110011', 100); // 800 bits
        $width = 32;
        $height = 32;
        $positions = [[1, 2], [2, 1]];
        $threshold = 10;

        $frameData = $generateMethod->invoke(
            $processor,
            $bitStream,
            0, // bitOffset
            $width,
            $height,
            $positions,
            $threshold
        );

        // Verify frame size: width * height * 3 bytes (RGB)
        $expectedSize = $width * $height * 3;
        $this->assertEquals($expectedSize, strlen($frameData));

        // Verify it's binary data (not all zeros or ones)
        $this->assertNotEquals(str_repeat("\x00", $expectedSize), $frameData);
        $this->assertNotEquals(str_repeat("\xFF", $expectedSize), $frameData);
    }

    /**
     * Test bit extraction from modified coefficients.
     */
    public function testBitExtractionFromCoefficients(): void
    {
        $processor = new VideoProcessor($this->config);
        $reflection = new \ReflectionClass($processor);
        
        $extractMethod = $reflection->getMethod('extractBitFromCoefficients');
        $extractMethod->setAccessible(true);

        // Test with positive coefficient (should extract '0')
        $coeffsPositive = [[15.0, 5.0], [5.0, 0.0]];
        $positions = [[0, 0]];
        $threshold = 10;
        
        $extractedBit = $extractMethod->invoke($processor, $coeffsPositive, $positions, $threshold);
        $this->assertEquals('0', $extractedBit, "Positive coefficient should yield bit 0");

        // Test with negative coefficient (should extract '1')
        $coeffsNegative = [[-15.0, 5.0], [5.0, 0.0]];
        $extractedBit = $extractMethod->invoke($processor, $coeffsNegative, $positions, $threshold);
        $this->assertEquals('1', $extractedBit, "Negative coefficient should yield bit 1");
    }
}
