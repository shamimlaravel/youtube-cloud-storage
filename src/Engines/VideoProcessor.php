<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Engines;

use Shamimstack\YouTubeCloudStorage\DTOs\PacketMetadata;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\Exceptions\EncodingParameterException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Video Processor — FFmpeg/DCT Sign-Bit Nudging Engine.
 *
 * Implements the core steganographic encoding/decoding logic:
 *
 * ENCODING (file → video):
 *   1. Accepts fountain-coded symbol packets from FountainEncoder
 *   2. Generates raw video frames by embedding packet bits into DCT coefficient signs
 *   3. Exports frames as a lossless intermediate video via FFmpeg (FFV1 or libx264rgb CRF 0)
 *
 * DECODING (video → packets):
 *   1. Extracts raw frames from a downloaded (re-encoded) video via FFmpeg
 *   2. Applies 8×8 block DCT to each frame
 *   3. Reads sign bits from configured coefficient positions
 *   4. Reassembles bits into fountain symbol packets
 *
 * Sign-Bit Nudging Mathematical Basis:
 * ─────────────────────────────────────
 * The 2D DCT of an 8×8 pixel block B is:
 *
 *   F(u,v) = C(u)·C(v)/4 · Σ_{x=0}^{7} Σ_{y=0}^{7}
 *            B(x,y) · cos((2x+1)uπ/16) · cos((2y+1)vπ/16)
 *
 * where C(0) = 1/√2, C(k) = 1 for k > 0.
 *
 * YouTube's H.264/VP9/AV1 encoder quantizes these coefficients:
 *   F_q(u,v) = round(F(u,v) / Q(u,v))
 *
 * The SIGN of F(u,v) is preserved as long as |F(u,v)| > Q(u,v)/2.
 * By ensuring |F(u,v)| ≥ threshold (default 30), we guarantee sign survival.
 *
 * We embed 1 bit per selected coefficient position:
 *   - sign(F) > 0 → bit 0
 *   - sign(F) < 0 → bit 1
 *   - |F| < threshold → erasure (skip, handled by fountain code)
 *
 * PHP 8.4 Features Used:
 *   - Typed Constants: codec identifiers, DCT parameters
 */
class VideoProcessor
{
    /*
    |----------------------------------------------------------------------
    | Typed Constants (PHP 8.4)
    |----------------------------------------------------------------------
    */

    /** FFV1 codec identifier for lossless MKV output. */
    public const string CODEC_FFV1 = 'ffv1';

    /** libx264rgb codec identifier for lossless MP4 output (CRF 0). */
    public const string CODEC_LIBX264RGB = 'libx264rgb';

    /** Number of pixels in a DCT block edge (8×8 standard block). */
    public const int DCT_BLOCK_SIZE = 8;

    /**
     * Pre-computed 8×8 DCT basis matrix.
     * T[u][x] = C(u) * cos((2x+1)*u*π/16) / 2
     *
     * The full 2D DCT is: F = T · B · T^T
     * The inverse is:      B = T^T · F · T
     *
     * @var array<int, array<int, float>>|null Lazily computed.
     */
    private ?array $dctBasis = null;

    public function __construct(
        private readonly StorageConfig $config,
    ) {}

    /*
    |----------------------------------------------------------------------
    | Encoding: Packets → Lossless Video
    |----------------------------------------------------------------------
    */

    /**
     * Embed fountain-coded packets into video frames and export as lossless video.
     *
     * Process:
     *   1. Serialize all packets to a contiguous bit stream
     *   2. Divide bit stream into frame-sized chunks (bitsPerFrame from config)
     *   3. For each frame:
     *      a. Generate a neutral gray base frame (128,128,128 per pixel)
     *      b. Apply 8×8 block DCT
     *      c. For each configured position in each block, set the coefficient
     *         sign to encode the next data bit, ensuring |coeff| ≥ threshold
     *      d. Apply inverse DCT to produce the pixel-domain frame
     *   4. Feed all frames to FFmpeg via rawvideo pipe to produce lossless output
     *
     * @param  list<PacketMetadata> $packets Fountain-coded symbol packets to embed.
     * @param  string               $outputPath Path for the lossless video file.
     * @return string The output video file path.
     *
     * @throws EncodingParameterException If zero usable coefficients result.
     */
    public function encodeToVideo(array $packets, string $outputPath): string
    {
        $width         = $this->config->frameResolution['width'];
        $height        = $this->config->frameResolution['height'];
        $bitsPerFrame  = $this->config->bitsPerFrame();
        $dctPositions  = $this->config->dctPositions;
        $threshold     = $this->config->coefficientThreshold;

        // Serialize all packets into a contiguous binary string
        $bitStream = '';
        foreach ($packets as $packet) {
            $bitStream .= $packet->serialize();
        }

        // Convert byte string to bit string for embedding
        $totalBits = strlen($bitStream) * 8;
        $frameCount = (int) ceil($totalBits / max($bitsPerFrame, 1));

        if ($bitsPerFrame < 1) {
            throw new EncodingParameterException($threshold, 0);
        }

        // Build FFmpeg command for lossless output
        $codec = $this->config->defaultCodec;
        $fps   = $this->config->frameRate;

        $ffmpegArgs = $this->buildEncoderCommand($width, $height, $fps, $codec, $outputPath);

        // Start FFmpeg process with rawvideo pipe input using Symfony Process
        $ffmpegCmd = implode(' ', $ffmpegArgs);
        $inputStream = new InputStream();
        
        $process = Process::fromShellCommandline(
            $ffmpegCmd,
            sys_get_temp_dir(),
            null,
            null,
            0 // No timeout
        );
        $process->setInput($inputStream);
        $process->start();

        $bitOffset = 0;

        for ($f = 0; $f < $frameCount; $f++) {
            // Generate frame with embedded data bits
            $frameData = $this->generateFrame(
                $bitStream,
                $bitOffset,
                $width,
                $height,
                $dctPositions,
                $threshold,
            );

            // Write raw frame bytes to FFmpeg stdin (RGB24 format)
            $inputStream->write($frameData);

            $bitOffset += $bitsPerFrame;
        }

        // Close input to signal end of frames
        fclose($process->input());
        $process->wait();

        return $outputPath;
    }

    /*
    |----------------------------------------------------------------------
    | Decoding: Re-encoded Video → Packets
    |----------------------------------------------------------------------
    */

    /**
     * Extract fountain-coded packets from a downloaded (re-encoded) YouTube video.
     *
     * Process:
     *   1. Use FFmpeg to decode the video to raw RGB24 frames
     *   2. For each frame, apply 8×8 block DCT
     *   3. Read the sign of each coefficient at the configured positions
     *   4. Coefficients with |value| < threshold are marked as erasures
     *   5. Reassemble bit stream into PacketMetadata objects
     *   6. CRC32 verification filters out corrupted packets
     *
     * @param  string $videoPath Path to the downloaded re-encoded video.
     * @param  int    $expectedPacketCount Number of packets that were originally embedded.
     * @param  int    $packetSize Payload size of each fountain packet.
     * @return list<PacketMetadata> Recovered packets (CRC-verified).
     */
    public function decodeFromVideo(
        string $videoPath,
        int $expectedPacketCount,
        int $packetSize,
    ): array {
        $width        = $this->config->frameResolution['width'];
        $height       = $this->config->frameResolution['height'];
        $dctPositions = $this->config->dctPositions;
        $threshold    = $this->config->coefficientThreshold;

        // Extract raw frames from video using FFmpeg
        $rawFrames = $this->extractFrames($videoPath, $width, $height);

        // Collect all bits from all frames
        $allBits = '';
        foreach ($rawFrames as $frameData) {
            $frameBits = $this->extractBitsFromFrame(
                $frameData,
                $width,
                $height,
                $dctPositions,
                $threshold,
            );
            $allBits .= $frameBits;
        }

        // Reassemble bits into packets
        $totalPacketBits = ($packetSize + PacketMetadata::HEADER_OVERHEAD_BYTES) * 8;
        $packets         = [];

        for ($i = 0; $i < $expectedPacketCount; $i++) {
            $packetBitOffset = $i * $totalPacketBits;

            if ($packetBitOffset + $totalPacketBits > strlen($allBits)) {
                break;
            }

            $packetBits = substr($allBits, $packetBitOffset, $totalPacketBits);

            // Convert bit string back to byte string
            $packetBytes = $this->bitsToBytes($packetBits);

            try {
                $packet = PacketMetadata::deserialize($packetBytes);

                // Only keep packets that pass CRC integrity check
                if ($packet->verifyIntegrity()) {
                    $packets[] = $packet;
                }
            } catch (\InvalidArgumentException) {
                // Corrupted packet structure — skip (handled by fountain redundancy)
                continue;
            }
        }

        return $packets;
    }

    /*
    |----------------------------------------------------------------------
    | DCT Operations
    |----------------------------------------------------------------------
    */

    /**
     * Generate a single video frame with data bits embedded via DCT sign-bit nudging.
     *
     * Each frame is a grayscale image (luma channel) where:
     *   - Base pixel value is 128 (mid-gray, ensures non-zero DCT coefficients)
     *   - 8×8 blocks are DCT-transformed
     *   - Selected coefficient signs are set to encode data bits
     *   - Coefficient magnitudes are forced above the threshold
     *   - Inverse DCT produces final pixel values (clamped to 0–255)
     *
     * @param  string $bitStream Full bit stream (binary string of bytes).
     * @param  int    $bitOffset Bit offset into the stream for this frame.
     * @param  int    $width     Frame width in pixels.
     * @param  int    $height    Frame height in pixels.
     * @param  list<array{0:int,1:int}> $positions DCT coefficient positions.
     * @param  int    $threshold Minimum coefficient magnitude.
     * @return string Raw RGB24 frame data.
     */
    private function generateFrame(
        string $bitStream,
        int $bitOffset,
        int $width,
        int $height,
        array $positions,
        int $threshold,
    ): string {
        $basis      = $this->getDctBasis();
        $blocksX    = intdiv($width, self::DCT_BLOCK_SIZE);
        $blocksY    = intdiv($height, self::DCT_BLOCK_SIZE);
        $bitIdx     = $bitOffset;
        $totalBits  = strlen($bitStream) * 8;

        // Initialize frame with mid-gray (128)
        $pixels = array_fill(0, $height, array_fill(0, $width, 128.0));

        for ($by = 0; $by < $blocksY; $by++) {
            for ($bx = 0; $bx < $blocksX; $bx++) {
                // Extract 8×8 pixel block
                $block = [];
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $block[$y][$x] = $pixels[$by * 8 + $y][$bx * 8 + $x];
                    }
                }

                // Forward DCT: F = T · B · T^T
                $dctBlock = $this->forwardDct($block, $basis);

                // Embed data bits into selected coefficient positions
                foreach ($positions as [$u, $v]) {
                    if ($bitIdx >= $totalBits) {
                        break 2; // No more data to embed
                    }

                    // Read the next bit from the stream
                    $byteIndex = intdiv($bitIdx, 8);
                    $bitInByte = 7 - ($bitIdx % 8); // MSB first
                    $bit = ($byteIndex < strlen($bitStream))
                        ? (ord($bitStream[$byteIndex]) >> $bitInByte) & 1
                        : 0;

                    // Set the coefficient sign: positive = 0, negative = 1
                    // Ensure magnitude exceeds threshold for survival through quantization
                    $magnitude = max(abs($dctBlock[$u][$v]), (float) $threshold);
                    $dctBlock[$u][$v] = ($bit === 1) ? -$magnitude : $magnitude;

                    $bitIdx++;
                }

                // Inverse DCT: B = T^T · F · T
                $reconstructed = $this->inverseDct($dctBlock, $basis);

                // Write reconstructed pixels back, clamped to [0, 255]
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $pixels[$by * 8 + $y][$bx * 8 + $x] = max(0.0, min(255.0, round($reconstructed[$y][$x])));
                    }
                }
            }
        }

        // Convert pixel array to RGB24 raw bytes (grayscale → R=G=B=value)
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $v = (int) $pixels[$y][$x];
                $raw .= chr($v) . chr($v) . chr($v);
            }
        }

        return $raw;
    }

    /**
     * Extract data bits from a single re-encoded video frame.
     *
     * @param  string $frameData Raw RGB24 frame data.
     * @param  int    $width     Frame width in pixels.
     * @param  int    $height    Frame height in pixels.
     * @param  list<array{0:int,1:int}> $positions DCT coefficient positions.
     * @param  int    $threshold Minimum coefficient magnitude.
     * @return string Bit string ('0' and '1' characters).
     */
    private function extractBitsFromFrame(
        string $frameData,
        int $width,
        int $height,
        array $positions,
        int $threshold,
    ): string {
        $basis  = $this->getDctBasis();
        $blocksX = intdiv($width, self::DCT_BLOCK_SIZE);
        $blocksY = intdiv($height, self::DCT_BLOCK_SIZE);

        // Parse RGB24 data into grayscale pixel array (use R channel, since R=G=B)
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $offset = ($y * $width + $x) * 3;
                $pixels[$y][$x] = ord($frameData[$offset]); // R channel
            }
        }

        $bits = '';

        for ($by = 0; $by < $blocksY; $by++) {
            for ($bx = 0; $bx < $blocksX; $bx++) {
                // Extract 8×8 pixel block
                $block = [];
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $block[$y][$x] = (float) $pixels[$by * 8 + $y][$bx * 8 + $x];
                    }
                }

                // Forward DCT
                $dctBlock = $this->forwardDct($block, $basis);

                // Read sign bits from selected positions
                foreach ($positions as [$u, $v]) {
                    $coeff = $dctBlock[$u][$v];

                    if (abs($coeff) < $threshold) {
                        // Below threshold — treat as erasure, emit '0' as placeholder
                        // The fountain code will handle the resulting packet errors
                        $bits .= '0';
                    } else {
                        // Sign bit: negative = 1, positive/zero = 0
                        $bits .= ($coeff < 0) ? '1' : '0';
                    }
                }
            }
        }

        return $bits;
    }

    /**
     * Compute the forward 2D DCT of an 8×8 block.
     *
     * F = T · B · T^T
     *
     * Where T is the DCT basis matrix and B is the input block.
     *
     * @param  array<int, array<int, float>> $block 8×8 pixel block.
     * @param  array<int, array<int, float>> $basis DCT basis matrix.
     * @return array<int, array<int, float>> 8×8 DCT coefficient block.
     */
    private function forwardDct(array $block, array $basis): array
    {
        // Compute temp = T · B
        $temp = array_fill(0, 8, array_fill(0, 8, 0.0));
        for ($i = 0; $i < 8; $i++) {
            for ($j = 0; $j < 8; $j++) {
                $sum = 0.0;
                for ($k = 0; $k < 8; $k++) {
                    $sum += $basis[$i][$k] * $block[$k][$j];
                }
                $temp[$i][$j] = $sum;
            }
        }

        // Compute F = temp · T^T
        $result = array_fill(0, 8, array_fill(0, 8, 0.0));
        for ($i = 0; $i < 8; $i++) {
            for ($j = 0; $j < 8; $j++) {
                $sum = 0.0;
                for ($k = 0; $k < 8; $k++) {
                    $sum += $temp[$i][$k] * $basis[$j][$k]; // T^T[k][j] = T[j][k]
                }
                $result[$i][$j] = $sum;
            }
        }

        return $result;
    }

    /**
     * Compute the inverse 2D DCT of an 8×8 coefficient block.
     *
     * B = T^T · F · T
     *
     * @param  array<int, array<int, float>> $dctBlock 8×8 DCT coefficient block.
     * @param  array<int, array<int, float>> $basis    DCT basis matrix.
     * @return array<int, array<int, float>> 8×8 reconstructed pixel block.
     */
    private function inverseDct(array $dctBlock, array $basis): array
    {
        // Compute temp = T^T · F
        $temp = array_fill(0, 8, array_fill(0, 8, 0.0));
        for ($i = 0; $i < 8; $i++) {
            for ($j = 0; $j < 8; $j++) {
                $sum = 0.0;
                for ($k = 0; $k < 8; $k++) {
                    $sum += $basis[$k][$i] * $dctBlock[$k][$j]; // T^T[i][k] = T[k][i]
                }
                $temp[$i][$j] = $sum;
            }
        }

        // Compute B = temp · T
        $result = array_fill(0, 8, array_fill(0, 8, 0.0));
        for ($i = 0; $i < 8; $i++) {
            for ($j = 0; $j < 8; $j++) {
                $sum = 0.0;
                for ($k = 0; $k < 8; $k++) {
                    $sum += $temp[$i][$k] * $basis[$j][$k]; // T[j][k] is already correct
                }
                $result[$i][$j] = $sum;
            }
        }

        return $result;
    }

    /**
     * Lazily compute the 8×8 DCT basis matrix T.
     *
     * T[u][x] = C(u) * cos((2x+1)*u*π/16) / 2
     * where C(0) = 1/√2, C(u) = 1 for u > 0.
     *
     * @return array<int, array<int, float>>
     */
    private function getDctBasis(): array
    {
        if ($this->dctBasis !== null) {
            return $this->dctBasis;
        }

        $basis = [];
        for ($u = 0; $u < 8; $u++) {
            $cu = ($u === 0) ? (1.0 / sqrt(2.0)) : 1.0;
            for ($x = 0; $x < 8; $x++) {
                $basis[$u][$x] = 0.5 * $cu * cos(((2.0 * $x + 1.0) * $u * M_PI) / 16.0);
            }
        }

        $this->dctBasis = $basis;
        return $basis;
    }

    /*
    |----------------------------------------------------------------------
    | FFmpeg Process Helpers
    |----------------------------------------------------------------------
    */

    /**
     * Build the FFmpeg command for encoding raw frames to lossless video.
     *
     * @return list<string> Command arguments.
     */
    private function buildEncoderCommand(
        int $width,
        int $height,
        int $fps,
        string $codec,
        string $outputPath,
    ): array {
        $ffmpeg = $this->config->ffmpegPath;
        $args   = [
            $ffmpeg,
            '-y',                              // Overwrite output
            '-f', 'rawvideo',                  // Input format: raw frames
            '-pixel_format', 'rgb24',          // Pixel format
            '-video_size', "{$width}x{$height}", // Frame dimensions
            '-framerate', (string) $fps,       // Frame rate
            '-i', 'pipe:0',                    // Read from stdin
        ];

        if ($codec === self::CODEC_LIBX264RGB) {
            $args = array_merge($args, [
                '-c:v', 'libx264rgb',
                '-crf', '0',                   // Mathematically lossless
                '-preset', 'ultrafast',         // Speed over compression
                '-color_range', 'pc',           // Full range 0-255
            ]);
            $args[] = $outputPath;
        } else {
            // FFV1 in MKV container
            $args = array_merge($args, [
                '-c:v', 'ffv1',
                '-level', '3',                 // FFV1 version 3 (multithreaded)
                '-slicecrc', '1',              // Per-slice CRC for error detection
            ]);
            $args[] = $outputPath;
        }

        return $args;
    }

    /**
     * Extract raw RGB24 frames from a video file using FFmpeg.
     *
     * @param  string $videoPath Path to the input video.
     * @param  int    $width     Expected frame width.
     * @param  int    $height    Expected frame height.
     * @return list<string> Array of raw RGB24 frame data strings.
     */
    private function extractFrames(string $videoPath, int $width, int $height): array
    {
        $ffmpeg     = $this->config->ffmpegPath;
        $frameSize  = $width * $height * 3; // RGB24 = 3 bytes per pixel

        // Get frame count via FFprobe
        $probeResult = Process::run(implode(' ', [
            $this->config->ffprobePath,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-count_packets',
            '-show_entries', 'stream=nb_read_packets',
            '-of', 'csv=p=0',
            $videoPath,
        ]));

        $frameCount = (int) trim($probeResult->output());

        // Extract all frames as raw RGB24
        $result = Process::timeout(0)->run(implode(' ', [
            $ffmpeg,
            '-i', $videoPath,
            '-f', 'rawvideo',
            '-pix_fmt', 'rgb24',
            '-v', 'error',
            'pipe:1',
        ]));

        $rawData = $result->output();
        $frames  = [];

        for ($i = 0; $i < $frameCount; $i++) {
            $offset = $i * $frameSize;
            if ($offset + $frameSize > strlen($rawData)) {
                break;
            }
            $frames[] = substr($rawData, $offset, $frameSize);
        }

        return $frames;
    }

    /**
     * Download a YouTube video using yt-dlp at the highest available quality.
     *
     * @param  string $youtubeUrl The YouTube video URL.
     * @param  string $outputPath Local path to save the downloaded video.
     * @return string The output file path.
     */
    public function downloadVideo(string $youtubeUrl, string $outputPath): string
    {
        $ytdlp = $this->config->ytdlpPath;

        $result = Process::timeout(0)->run(implode(' ', [
            $ytdlp,
            '--format', 'bestvideo',           // Highest quality video only
            '--output', $outputPath,
            '--no-playlist',                    // Single video only
            '--quiet',
            $youtubeUrl,
        ]));

        if (!$result->successful()) {
            throw new \RuntimeException(
                "yt-dlp download failed: {$result->errorOutput()}",
            );
        }

        return $outputPath;
    }

    /*
    |----------------------------------------------------------------------
    | Bit Manipulation Helpers
    |----------------------------------------------------------------------
    */

    /**
     * Convert a string of '0' and '1' characters back to a byte string.
     *
     * @param  string $bits String of '0'/'1' characters.
     * @return string Binary byte string.
     */
    private function bitsToBytes(string $bits): string
    {
        $bytes = '';
        $len   = strlen($bits);

        for ($i = 0; $i < $len; $i += 8) {
            $byte = substr($bits, $i, 8);
            // Pad last byte with zeros if needed
            $byte = str_pad($byte, 8, '0');
            $bytes .= chr((int) bindec($byte));
        }

        return $bytes;
    }
}
