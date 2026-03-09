# Quick Reference Card - YouTube Cloud Storage

## 🚀 Installation & Setup

```bash
# Install package
composer require shamimstack/youtube-cloud-storage

# Run interactive setup (recommended)
php artisan yt:setup

# Or configure manually:
# 1. Publish config
php artisan vendor:publish --provider="Shamimstack\YouTubeCloudStorage\YTStorageServiceProvider" --tag=config

# 2. Add to .env
FFMPEG_PATH=/usr/bin/ffmpeg
YTDLP_PATH=/usr/bin/yt-dlp
YOUTUBE_API_KEY=your_key_here
```

---

## 💾 Basic Operations

### Upload Files
```bash
# Simple upload
php artisan yt:store /path/to/file.pdf

# With custom redundancy
php artisan yt:store file.zip --redundancy=2.0

# With metadata
php artisan yt:store image.png \
  --title="My Image" \
  --description="Uploaded via YTStorage" \
  --privacy=private
```

### Download Files
```bash
# From YouTube URL
php artisan yt:restore "https://youtube.com/watch?v=dQw4w9WgXcQ"

# To specific path
php artisan yt:restore "URL" --output=/tmp/file.pdf
```

### Via Laravel Storage
```php
// Upload
Storage::disk('youtube')->put('file.txt', 'content');

// Download
$content = Storage::disk('youtube')->get('file.txt');

// List files
$files = Storage::disk('youtube')->allFiles();

// Delete
Storage::disk('youtube')->delete('file.txt');
```

---

## 🔧 Configuration Options

### config/youtube-storage.php
```php
return [
    // Binary paths (leave empty for auto-detect)
    'ffmpeg_path' => '',
    'ffprobe_path' => '',
    'ytdlp_path' => '',
    'wirehair_lib_path' => '',
    
    // YouTube credentials
    'youtube_api_key' => env('YOUTUBE_API_KEY'),
    'youtube_oauth_credentials' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'refresh_token' => env('YOUTUBE_REFRESH_TOKEN'),
    ],
    
    // Advanced tuning
    'coefficient_threshold' => 10,   // DCT embedding strength
    'redundancy_factor' => 1.5,      // Fountain code overhead
    'dct_positions' => [[1, 2], [2, 1]], // Embedding locations
    'frame_rate' => 30,              // Video FPS
    'default_codec' => 'libx264rgb', // Video codec
];
```

---

## 🛠 Troubleshooting Commands

```bash
# Check all dependencies
php artisan yt:setup --no-interaction

# Verify health
$healthCheck = app(HealthCheck::class);
$report = $healthCheck->run();

# Auto-detect binaries
$autoConfig = app(AutoConfigurator::class);
$result = $autoConfig->runAutoConfig();
print_r($result);
```

---

## 📊 Key Classes

| Class | Purpose | Location |
|-------|---------|----------|
| **AutoConfigurator** | Binary detection | `src/Support/AutoConfigurator.php` |
| **HealthCheck** | Dependency validation | `src/Support/HealthCheck.php` |
| **EncoderEngine** | Pipeline coordinator | `src/Engines/EncoderEngine.php` |
| **FountainEncoder** | Wirehair FFI bridge | `src/Engines/FountainEncoder.php` |
| **VideoProcessor** | DCT encoding | `src/Engines/VideoProcessor.php` |
| **YouTubeStorageDriver** | Flysystem adapter | `src/Drivers/YouTubeStorageDriver.php` |

---

## 🎯 Common Issues & Solutions

### FFmpeg not found
```bash
# Ubuntu/Debian
sudo apt-get install ffmpeg

# macOS
brew install ffmpeg

# Windows
choco install ffmpeg
```

### Wirehair compilation failed
```bash
# Install build tools
sudo apt-get install gcc cmake  # Linux
brew install cmake             # macOS
```

### YouTube API quota exceeded
- Use private videos (don't count against public quota)
- Wait 24 hours for reset
- Request quota increase from Google

---

## 📈 Performance Tips

1. **Reduce DCT positions** → Faster encode/decode
2. **Lower redundancy** → Smaller videos (less recovery)
3. **Use FFV1 codec** → Lossless but larger files
4. **Higher frame rate** → More packets per second

---

## 🔐 Security Checklist

- [ ] Set videos to **private**
- [ ] Store OAuth tokens securely in `.env`
- [ ] Never commit `.env` to version control
- [ ] Use HTTPS for API calls
- [ ] Validate uploaded file types
- [ ] Implement user quotas

---

## 📝 Testing Commands

```bash
# Run integration tests
vendor/bin/phpunit tests/IntegrationTest.php

# Test specific method
vendor/bin/phpunit --filter testHealthCheckReportStructure

# With coverage
vendor/bin/phpunit --coverage-html coverage/
```

---

## 🌐 External Resources

- **Wirehair Docs**: https://github.com/LancashireCountyCouncil/Wirehair
- **FFmpeg Docs**: https://ffmpeg.org/documentation.html
- **YouTube API**: https://developers.google.com/youtube/v3
- **Laravel Storage**: https://laravel.com/docs/filesystem

---

## 🆘 Getting Help

1. Check README.md first
2. Review IMPLEMENTATION_COMPLETE.md
3. Read FINAL_IMPLEMENTATION_SUMMARY.md
4. Open GitHub issue
5. Join discussions

---

**Quick Start in 3 Steps:**
1. `composer require shamimstack/youtube-cloud-storage`
2. `php artisan yt:setup`
3. `php artisan yt:store file.pdf`

**That's it! 🎉**
