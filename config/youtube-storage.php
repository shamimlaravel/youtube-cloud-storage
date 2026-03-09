<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | FFmpeg Binary Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the FFmpeg binary used for video encoding/decoding
    | and DCT coefficient manipulation. Auto-detected if null.
    |
    */
    'ffmpeg_path' => env('YTSTORAGE_FFMPEG_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | FFprobe Binary Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the FFprobe binary used for video inspection.
    | Auto-detected if null.
    |
    */
    'ffprobe_path' => env('YTSTORAGE_FFPROBE_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | yt-dlp Binary Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the yt-dlp binary used for downloading re-encoded
    | videos from YouTube. Auto-detected if null.
    |
    */
    'ytdlp_path' => env('YTSTORAGE_YTDLP_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | Wirehair Shared Library Path
    |--------------------------------------------------------------------------
    |
    | Path to the compiled Wirehair fountain code shared library.
    | Linux: libwirehair.so | Windows: wirehair.dll | macOS: libwirehair.dylib
    |
    */
    'wirehair_lib_path' => env('YTSTORAGE_WIREHAIR_LIB', null),

    /*
    |--------------------------------------------------------------------------
    | Wirehair C Header Path
    |--------------------------------------------------------------------------
    |
    | Path to the Wirehair C header file used by PHP FFI to define
    | the foreign function interface bindings.
    |
    */
    'wirehair_header_path' => env('YTSTORAGE_WIREHAIR_HEADER', null),

    /*
    |--------------------------------------------------------------------------
    | YouTube Data API v3 Key
    |--------------------------------------------------------------------------
    |
    | API key for read-only YouTube operations (metadata queries).
    |
    */
    'youtube_api_key' => env('YOUTUBE_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | YouTube OAuth 2.0 Credentials
    |--------------------------------------------------------------------------
    |
    | OAuth 2.0 client credentials for authenticated operations
    | (video upload, deletion). Obtain from Google Cloud Console.
    |
    */
    'youtube_oauth_credentials' => [
        'client_id'     => env('YOUTUBE_CLIENT_ID', ''),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET', ''),
        'refresh_token' => env('YOUTUBE_REFRESH_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Video Codec
    |--------------------------------------------------------------------------
    |
    | The lossless codec used for the intermediate video before YouTube upload.
    | Options: 'ffv1' (MKV container) or 'libx264rgb' (MP4, CRF 0).
    |
    */
    'default_codec' => env('YTSTORAGE_CODEC', 'libx264rgb'),

    /*
    |--------------------------------------------------------------------------
    | Fountain Code Packet Size
    |--------------------------------------------------------------------------
    |
    | Size in bytes of each Wirehair fountain code packet payload.
    | Smaller packets = more overhead but finer-grained redundancy.
    |
    */
    'packet_size' => (int) env('YTSTORAGE_PACKET_SIZE', 1400),

    /*
    |--------------------------------------------------------------------------
    | Redundancy Factor
    |--------------------------------------------------------------------------
    |
    | Ratio of total fountain-coded symbols to original data packets.
    | 1.5 means 50% overhead — 50% of packets can be lost and still recover.
    | Minimum: 1.0 (no redundancy). Recommended: 1.3–2.0.
    |
    */
    'redundancy_factor' => (float) env('YTSTORAGE_REDUNDANCY', 1.5),

    /*
    |--------------------------------------------------------------------------
    | DCT Coefficient Magnitude Threshold
    |--------------------------------------------------------------------------
    |
    | Minimum absolute value a DCT coefficient must have to be used as
    | a data carrier. Coefficients below this threshold are skipped
    | (treated as erasures). Higher = more resilient but lower capacity.
    |
    | Mathematical basis: YouTube's H.264 quantization at CRF ~18-23
    | divides coefficients by quantization matrix values (typically 10-40).
    | A magnitude of 30 ensures the sign survives division and rounding.
    |
    */
    'coefficient_threshold' => (int) env('YTSTORAGE_DCT_THRESHOLD', 30),

    /*
    |--------------------------------------------------------------------------
    | DCT Embedding Positions
    |--------------------------------------------------------------------------
    |
    | Which positions within each 8×8 DCT block to use for sign-bit embedding.
    | Each entry is [row, col]. Low-to-mid frequency positions survive
    | lossy re-encoding best. Position (0,0) is the DC coefficient (skip it).
    |
    | Zigzag order reference for an 8×8 block:
    |   (0,1) = index 1, (1,0) = index 2, (2,0) = index 3, (1,1) = index 4
    |
    */
    'dct_positions' => [
        [0, 1],
        [1, 0],
        [1, 1],
        [2, 0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Carrier Video Frame Resolution
    |--------------------------------------------------------------------------
    |
    | Width and height of the generated carrier video frames.
    | Must be divisible by 8 for DCT block alignment.
    | Higher resolution = more data capacity per frame.
    |
    */
    'frame_resolution' => [
        'width'  => (int) env('YTSTORAGE_FRAME_WIDTH', 1920),
        'height' => (int) env('YTSTORAGE_FRAME_HEIGHT', 1080),
    ],

    /*
    |--------------------------------------------------------------------------
    | Carrier Video Frame Rate
    |--------------------------------------------------------------------------
    |
    | Frames per second for the generated carrier video.
    | Higher FPS = more data throughput per second of video.
    |
    */
    'frame_rate' => (int) env('YTSTORAGE_FPS', 30),

    /*
    |--------------------------------------------------------------------------
    | Temporary Disk
    |--------------------------------------------------------------------------
    |
    | The Laravel filesystem disk used for intermediate temporary files
    | (raw frames, lossless video before upload, downloaded video).
    |
    */
    'temp_disk' => env('YTSTORAGE_TEMP_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Metadata Store Driver
    |--------------------------------------------------------------------------
    |
    | Driver for the local index that maps logical file paths to YouTube
    | video IDs and their encoding parameters.
    | Options: 'json' (simple file) or 'sqlite' (database).
    |
    */
    'metadata_store' => env('YTSTORAGE_METADATA_STORE', 'json'),

];
