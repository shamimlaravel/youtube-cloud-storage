<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Shamimstack\YouTubeCloudStorage\Commands\RestoreCommand;
use Shamimstack\YouTubeCloudStorage\Commands\StoreCommand;
use Shamimstack\YouTubeCloudStorage\Commands\SetupCommand;
use Shamimstack\YouTubeCloudStorage\Drivers\YouTubeStorageDriver;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\Engines\EncoderEngine;
use Shamimstack\YouTubeCloudStorage\Engines\FountainEncoder;
use Shamimstack\YouTubeCloudStorage\Engines\VideoProcessor;

/**
 * YouTube Cloud Storage Service Provider.
 *
 * Boots the shamimstack/youtube-cloud-storage package into a Laravel 12 application.
 *
 * Registration phase (register()):
 *   - Merges the package config with the application config
 *   - Binds StorageConfig as a validated singleton (with PHP 8.4 property hooks)
 *   - Binds FountainEncoder as a singleton (Wirehair FFI bridge)
 *   - Binds VideoProcessor as a singleton (FFmpeg/DCT engine)
 *   - Binds EncoderEngine as a singleton (pipeline coordinator)
 *
 * Boot phase (boot()):
 *   - Loads the Wirehair FFI header and initializes the shared library
 *   - Extends Laravel's Storage facade with the 'youtube' driver
 *   - Registers the yt:store and yt:restore Artisan commands
 *   - Publishes the config file for user customization
 */
class YTStorageServiceProvider extends ServiceProvider
{
    /**
     * Register package services into the container.
     *
     * All bindings are singletons because:
     *   - StorageConfig holds validated configuration (compute once, reuse)
     *   - FountainEncoder maintains the FFI handle (one-time library load)
     *   - VideoProcessor is stateless but benefits from DCT basis caching
     *   - EncoderEngine coordinates the pipeline and tracks state
     */
    public function register(): void
    {
        // ── Merge Package Config ───────────────────────────────────────
        $this->mergeConfigFrom(
            __DIR__ . '/../config/youtube-storage.php',
            'youtube-storage',
        );

        // ── Bind StorageConfig (Validated DTO with PHP 8.4 Property Hooks) ──
        $this->app->singleton(StorageConfig::class, function (Application $app): StorageConfig {
            $config = $app['config']->get('youtube-storage', []);
            return StorageConfig::fromArray($config);
        });

        // ── Bind FountainEncoder (Wirehair FFI Bridge) ─────────────────
        $this->app->singleton(FountainEncoder::class, function (Application $app): FountainEncoder {
            return new FountainEncoder(
                config: $app->make(StorageConfig::class),
            );
        });

        // ── Bind VideoProcessor (FFmpeg/DCT Engine) ────────────────────
        $this->app->singleton(VideoProcessor::class, function (Application $app): VideoProcessor {
            return new VideoProcessor(
                config: $app->make(StorageConfig::class),
            );
        });

        // ── Bind EncoderEngine (Pipeline Coordinator) ──────────────────
        $this->app->singleton(EncoderEngine::class, function (Application $app): EncoderEngine {
            return new EncoderEngine(
                config: $app->make(StorageConfig::class),
                fountainEncoder: $app->make(FountainEncoder::class),
                videoProcessor: $app->make(VideoProcessor::class),
            );
        });
    }

    /**
     * Boot package services after all providers have been registered.
     *
     * This phase performs operations that depend on other services being available:
     *   - FFI library loading (requires config to be resolved)
     *   - Storage driver extension (requires Filesystem manager)
     *   - Command registration (requires console kernel)
     *   - Config publishing (requires Artisan)
     */
    public function boot(): void
    {
        // ── Publish Config File ────────────────────────────────────────
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/youtube-storage.php' => config_path('youtube-storage.php'),
            ], 'youtube-storage-config');
        }

        // ── Boot Wirehair FFI Bridge ───────────────────────────────────
        // Initialize the Wirehair fountain code library via FFI.
        // This loads the .so/.dll shared library and calls wirehair_init_().
        // Wrapped in a try-catch so the app boots even without Wirehair,
        // allowing config publishing and other non-encoding operations.
        try {
            $fountainEncoder = $this->app->make(FountainEncoder::class);
            $fountainEncoder->boot();
        } catch (\Throwable $e) {
            // Log warning but don't prevent app boot
            if ($this->app->bound('log')) {
                $this->app->make('log')->warning(
                    '[YouTubeCloudStorage] Wirehair FFI not available: ' . $e->getMessage()
                    . '. Encode/decode operations will fail until the library is installed.',
                );
            }
        }

        // ── Extend Storage with 'youtube' Driver ───────────────────────
        // This allows: Storage::disk('youtube')->put('file.txt', $data)
        // Users configure a disk in config/filesystems.php with:
        //   'youtube' => ['driver' => 'youtube']
        Storage::extend('youtube', function (Application $app, array $config): FilesystemAdapter {
            $storageConfig = $app->make(StorageConfig::class);
            $engine        = $app->make(EncoderEngine::class);

            $adapter = new YouTubeStorageDriver($storageConfig, $engine);

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config,
            );
        });

        // ── Register Artisan Commands ──────────────────────────────────
        if ($this->app->runningInConsole()) {
            $this->commands([
                StoreCommand::class,
                RestoreCommand::class,
                SetupCommand::class,
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            StorageConfig::class,
            FountainEncoder::class,
            VideoProcessor::class,
            EncoderEngine::class,
        ];
    }
}
