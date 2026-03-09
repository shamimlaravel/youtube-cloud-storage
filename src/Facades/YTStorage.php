<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Facades;

use Illuminate\Support\Facades\Facade;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageReference;
use Shamimstack\YouTubeCloudStorage\Engines\EncoderEngine;

/**
 * YTStorage Facade.
 *
 * Provides a clean static interface to the YouTube cloud storage engine.
 *
 * Usage:
 *   YTStorage::upload($file)         → StorageReference
 *   YTStorage::download($youtubeUrl) → File contents
 *
 * @method static StorageReference upload(string $filePath, ?string $fileName = null)
 * @method static string download(string $youtubeUrl, StorageReference $reference)
 * @method static string currentPhase()
 * @method static float progressPercent()
 * @method static bool isRunning()
 *
 * @see \Shamimstack\YouTubeCloudStorage\Engines\EncoderEngine
 */
class YTStorage extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return EncoderEngine::class;
    }
}
