<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\DTOs;

/**
 * Immutable reference returned after a successful file-to-YouTube upload.
 *
 * Contains all metadata required to later retrieve and decode the file:
 * the YouTube video ID, original file properties, and the encoding parameters
 * that were active at upload time (needed for correct decoding).
 */
final readonly class StorageReference
{
    public function __construct(
        /** The YouTube video ID (e.g., 'dQw4w9WgXcQ'). Primary key for retrieval. */
        public string $videoId,

        /** Full YouTube URL for the uploaded video. */
        public string $videoUrl,

        /** Original file name before encoding. */
        public string $originalFileName,

        /** Original file size in bytes before encoding. */
        public int $originalFileSize,

        /** SHA-256 hash of the original file for integrity verification after decoding. */
        public string $originalFileHash,

        /** Number of original data packets (N) before fountain coding. */
        public int $originalPacketCount,

        /** Total number of fountain-coded symbol packets embedded in the video. */
        public int $totalSymbolCount,

        /** Packet payload size in bytes used during encoding. */
        public int $packetSize,

        /** Redundancy factor used during encoding (e.g., 1.5). */
        public float $redundancyFactor,

        /** DCT coefficient magnitude threshold used during encoding. */
        public int $coefficientThreshold,

        /** DCT positions used during encoding, as array of [row, col] pairs. */
        public array $dctPositions,

        /** Frame resolution used: ['width' => int, 'height' => int]. */
        public array $frameResolution,

        /** Frame rate (FPS) of the carrier video. */
        public int $frameRate,

        /** Codec used for lossless intermediate ('ffv1' or 'libx264rgb'). */
        public string $codec,

        /** ISO 8601 timestamp of when the upload was completed. */
        public string $uploadedAt,
    ) {}

    /**
     * Serialize to an associative array for JSON storage in the metadata index.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'video_id'              => $this->videoId,
            'video_url'             => $this->videoUrl,
            'original_file_name'    => $this->originalFileName,
            'original_file_size'    => $this->originalFileSize,
            'original_file_hash'    => $this->originalFileHash,
            'original_packet_count' => $this->originalPacketCount,
            'total_symbol_count'    => $this->totalSymbolCount,
            'packet_size'           => $this->packetSize,
            'redundancy_factor'     => $this->redundancyFactor,
            'coefficient_threshold' => $this->coefficientThreshold,
            'dct_positions'         => $this->dctPositions,
            'frame_resolution'      => $this->frameResolution,
            'frame_rate'            => $this->frameRate,
            'codec'                 => $this->codec,
            'uploaded_at'           => $this->uploadedAt,
        ];
    }

    /**
     * Reconstruct a StorageReference from a previously serialized array.
     *
     * @param  array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            videoId:              $data['video_id'],
            videoUrl:             $data['video_url'],
            originalFileName:     $data['original_file_name'],
            originalFileSize:     $data['original_file_size'],
            originalFileHash:     $data['original_file_hash'],
            originalPacketCount:  $data['original_packet_count'],
            totalSymbolCount:     $data['total_symbol_count'],
            packetSize:           $data['packet_size'],
            redundancyFactor:     (float) $data['redundancy_factor'],
            coefficientThreshold: $data['coefficient_threshold'],
            dctPositions:         $data['dct_positions'],
            frameResolution:      $data['frame_resolution'],
            frameRate:            $data['frame_rate'],
            codec:                $data['codec'],
            uploadedAt:           $data['uploaded_at'],
        );
    }
}
