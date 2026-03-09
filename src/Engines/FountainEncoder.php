<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Engines;

use FFI;
use Shamimstack\YouTubeCloudStorage\DTOs\PacketMetadata;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\Exceptions\InsufficientRedundancyException;
use Shamimstack\YouTubeCloudStorage\Exceptions\WirehairNotAvailableException;

/**
 * Wirehair Fountain Code FFI Bridge.
 *
 * Wraps the Wirehair C library through PHP 8.4 FFI to provide O(N) erasure
 * coding for data encoded into YouTube videos.
 *
 * Fountain codes produce an unlimited stream of coded symbols from source data.
 * The decoder can reconstruct the original data from any ~N symbols (where N is
 * the number of original data packets), regardless of which specific symbols are received.
 * On average, Wirehair requires N + 0.02 symbols for recovery.
 *
 * This resilience is critical because YouTube's lossy re-encoding will corrupt
 * or destroy some DCT sign bits, effectively erasing the packets they carried.
 *
 * C API Reference (from catid/wirehair):
 *   - wirehair_init()                          → One-time library init
 *   - wirehair_encoder_create(0, data, len, pktSize) → Create encoder
 *   - wirehair_encode(enc, blockId, out, outSz, &wLen) → Generate symbol
 *   - wirehair_decoder_create(0, len, pktSize)  → Create decoder
 *   - wirehair_decode(dec, blockId, data, len)   → Feed symbol
 *   - wirehair_recover(dec, out, outLen)          → Recover original data
 *   - wirehair_free(codec)                        → Release memory
 *
 * PHP 8.4 Features Used:
 *   - Typed Constants: packet sizes, result codes
 *   - Property Hooks: none (uses FFI directly)
 */
class FountainEncoder
{
    /*
    |----------------------------------------------------------------------
    | Typed Constants (PHP 8.4)
    |----------------------------------------------------------------------
    */

    /** Default packet payload size in bytes matching the Wirehair benchmark sweet spot. */
    public const int DEFAULT_PACKET_SIZE = 1400;

    /** Wirehair result code: success. */
    public const int WIREHAIR_SUCCESS = 0;

    /** Wirehair result code: decoder needs more symbols. */
    public const int WIREHAIR_NEED_MORE = 1;

    /**
     * C header declarations for the Wirehair FFI binding.
     * This is the minimal subset of wirehair.h required for encode/decode.
     */
    private const string FFI_HEADER = <<<'CDEF'
        typedef void* WirehairCodec;
        typedef int WirehairResult;

        WirehairResult wirehair_init_(int version);
        WirehairCodec wirehair_encoder_create(void* reuseOpt, const void* message, uint64_t messageBytes, uint32_t packetBytes);
        WirehairResult wirehair_encode(WirehairCodec codec, unsigned int blockId, void* blockDataOut, uint32_t outBytes, uint32_t* dataBytesOut);
        WirehairCodec wirehair_decoder_create(void* reuseOpt, uint64_t messageBytes, uint32_t packetBytes);
        WirehairResult wirehair_decode(WirehairCodec codec, unsigned int blockId, const void* blockData, uint32_t dataBytes);
        WirehairResult wirehair_recover(WirehairCodec codec, void* messageOut, uint64_t messageBytes);
        void wirehair_free(WirehairCodec codec);
    CDEF;

    /** The loaded FFI instance for Wirehair. */
    private ?FFI $ffi = null;

    /** Whether wirehair_init() has been called successfully. */
    private bool $initialized = false;

    public function __construct(
        private readonly StorageConfig $config,
    ) {}

    /*
    |----------------------------------------------------------------------
    | FFI Lifecycle
    |----------------------------------------------------------------------
    */

    /**
     * Load the Wirehair shared library via FFI and initialize it.
     *
     * Called once by the service provider at boot time.
     * Uses the config-provided header path if available, otherwise falls back
     * to the embedded C declarations above.
     *
     * @throws WirehairNotAvailableException If the library cannot be loaded.
     */
    public function boot(): void
    {
        if ($this->initialized) {
            return;
        }

        $libPath = $this->config->wirehairLibPath;

        try {
            $headerContent = self::FFI_HEADER;

            // Use custom header file if configured
            if ($this->config->wirehairHeaderPath !== '' && file_exists($this->config->wirehairHeaderPath)) {
                $headerContent = file_get_contents($this->config->wirehairHeaderPath);
            }

            $this->ffi = FFI::cdef($headerContent, $libPath !== '' ? $libPath : null);

            // wirehair_init_ expects a version parameter (2 for current API)
            $result = $this->ffi->wirehair_init_(2);

            if ($result !== self::WIREHAIR_SUCCESS) {
                throw new WirehairNotAvailableException(
                    $libPath,
                    new \RuntimeException("wirehair_init_() returned error code: {$result}"),
                );
            }

            $this->initialized = true;
        } catch (FFI\Exception $e) {
            throw new WirehairNotAvailableException($libPath, $e);
        }
    }

    /**
     * Check if the FFI bridge is initialized and ready.
     */
    public function isAvailable(): bool
    {
        return $this->initialized && $this->ffi !== null;
    }

    /*
    |----------------------------------------------------------------------
    | Encoding: Source Data → Fountain Symbols
    |----------------------------------------------------------------------
    */

    /**
     * Encode source data into fountain-coded symbol packets.
     *
     * Mathematical process:
     *   1. N = ceil(dataSize / packetSize) — number of original data packets
     *   2. totalSymbols = ceil(N * redundancyFactor) — total symbols to generate
     *   3. For each blockId in [0, totalSymbols):
     *        - First N blocks are systematic (contain original data slices)
     *        - Blocks N+ are repair symbols (linear combinations over GF(2^8))
     *   4. Each symbol is wrapped in a PacketMetadata with CRC32
     *
     * @param  string $data Raw file data to encode.
     * @return list<PacketMetadata> Array of fountain-coded symbol packets.
     *
     * @throws WirehairNotAvailableException If FFI is not initialized.
     * @throws \RuntimeException If encoder creation fails.
     */
    public function encode(string $data): array
    {
        $this->ensureInitialized();

        $dataSize   = strlen($data);
        $packetSize = $this->config->packetSize;

        // N = number of original data packets
        $originalPackets = (int) ceil($dataSize / $packetSize);

        // Total fountain symbols to generate including redundancy
        $totalSymbols = (int) ceil($originalPackets * $this->config->redundancyFactor);

        // Allocate FFI buffers
        $dataBuf = FFI::new("uint8_t[{$dataSize}]");
        FFI::memcpy($dataBuf, $data, $dataSize);

        // Create Wirehair encoder
        $encoder = $this->ffi->wirehair_encoder_create(null, $dataBuf, $dataSize, $packetSize);

        if ($encoder === null || FFI::isNull($encoder)) {
            throw new \RuntimeException(
                "wirehair_encoder_create() failed for data size {$dataSize}, packet size {$packetSize}.",
            );
        }

        $packets = [];

        try {
            $outBuf   = FFI::new("uint8_t[{$packetSize}]");
            $writeLen = FFI::new('uint32_t');

            for ($blockId = 0; $blockId < $totalSymbols; $blockId++) {
                $result = $this->ffi->wirehair_encode(
                    $encoder,
                    $blockId,
                    $outBuf,
                    $packetSize,
                    FFI::addr($writeLen),
                );

                if ($result !== self::WIREHAIR_SUCCESS) {
                    throw new \RuntimeException(
                        "wirehair_encode() failed for block {$blockId}: error code {$result}.",
                    );
                }

                // Extract the encoded bytes
                $payloadLen = $writeLen->cdata;
                $payload    = FFI::string($outBuf, $payloadLen);

                $packets[] = PacketMetadata::fromPayload($blockId, $payload);
            }
        } finally {
            $this->ffi->wirehair_free($encoder);
        }

        return $packets;
    }

    /*
    |----------------------------------------------------------------------
    | Decoding: Fountain Symbols → Original Data
    |----------------------------------------------------------------------
    */

    /**
     * Decode received fountain-coded symbol packets back to the original data.
     *
     * Mathematical process:
     *   1. Create decoder expecting originalDataSize bytes in packetSize chunks
     *   2. Feed each received (and CRC-verified) packet into the decoder
     *   3. Wirehair performs Gaussian elimination over GF(2^8) on the received
     *      symbols' encoding matrix to solve for the original data
     *   4. When rank(receivedMatrix) >= N, recovery succeeds
     *   5. On average, N + 0.02 packets are needed (0.02 overhead)
     *
     * @param  list<PacketMetadata> $packets  Received symbol packets (may be out of order, some corrupted).
     * @param  int                  $originalDataSize Expected size of the original file in bytes.
     * @return string The reconstructed original file data.
     *
     * @throws InsufficientRedundancyException If not enough valid symbols are received.
     * @throws WirehairNotAvailableException If FFI is not initialized.
     */
    public function decode(array $packets, int $originalDataSize): string
    {
        $this->ensureInitialized();

        $packetSize      = $this->config->packetSize;
        $requiredPackets = (int) ceil($originalDataSize / $packetSize);

        // Create Wirehair decoder
        $decoder = $this->ffi->wirehair_decoder_create(null, $originalDataSize, $packetSize);

        if ($decoder === null || FFI::isNull($decoder)) {
            throw new \RuntimeException(
                "wirehair_decoder_create() failed for data size {$originalDataSize}, packet size {$packetSize}.",
            );
        }

        $recovered    = false;
        $validSymbols = 0;

        try {
            foreach ($packets as $packet) {
                // Skip packets that fail CRC integrity check (corrupted by re-encoding)
                if (!$packet->verifyIntegrity()) {
                    continue;
                }

                $payloadLen = strlen($packet->payload);

                // Allocate FFI buffer for this packet's payload
                $pktBuf = FFI::new("uint8_t[{$payloadLen}]");
                FFI::memcpy($pktBuf, $packet->payload, $payloadLen);

                $result = $this->ffi->wirehair_decode(
                    $decoder,
                    $packet->blockId,
                    $pktBuf,
                    $payloadLen,
                );

                $validSymbols++;

                if ($result === self::WIREHAIR_SUCCESS) {
                    // Decoder has enough data to recover
                    $recovered = true;
                    break;
                }

                if ($result !== self::WIREHAIR_NEED_MORE) {
                    throw new \RuntimeException(
                        "wirehair_decode() returned unexpected error: {$result} at block {$packet->blockId}.",
                    );
                }
            }

            if (!$recovered) {
                throw new InsufficientRedundancyException($validSymbols, $requiredPackets);
            }

            // Recover the original data
            $outBuf = FFI::new("uint8_t[{$originalDataSize}]");
            $result = $this->ffi->wirehair_recover($decoder, $outBuf, $originalDataSize);

            if ($result !== self::WIREHAIR_SUCCESS) {
                throw new \RuntimeException(
                    "wirehair_recover() failed with error code: {$result}.",
                );
            }

            return FFI::string($outBuf, $originalDataSize);
        } finally {
            $this->ffi->wirehair_free($decoder);
        }
    }

    /*
    |----------------------------------------------------------------------
    | Internal Helpers
    |----------------------------------------------------------------------
    */

    /**
     * Ensure the FFI bridge is initialized before any encode/decode operation.
     *
     * @throws WirehairNotAvailableException
     */
    private function ensureInitialized(): void
    {
        if (!$this->initialized || $this->ffi === null) {
            throw new WirehairNotAvailableException(
                $this->config->wirehairLibPath,
                new \RuntimeException('FountainEncoder::boot() must be called before encode/decode operations.'),
            );
        }
    }
}
