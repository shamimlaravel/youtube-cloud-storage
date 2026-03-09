<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage\Commands;

use Illuminate\Console\Command;
use Shamimstack\YouTubeCloudStorage\DTOs\StorageConfig;
use Shamimstack\YouTubeCloudStorage\Support\AutoConfigurator;
use Shamimstack\YouTubeCloudStorage\Support\HealthCheck;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\error;
use function Laravel\Prompts\text;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

/**
 * Artisan Command: yt:setup
 *
 * Interactive guided setup for YouTube Cloud Storage package.
 *
 * Performs:
 *   1. Auto-detection of system binaries (FFmpeg, FFprobe, yt-dlp)
 *   2. Wirehair library detection/compilation
 *   3. Health check validation
 *   4. OAuth credential collection
 *   5. Environment file writing
 *
 * Usage:
 *   php artisan yt:setup
 *   php artisan yt:setup --force    # Overwrite existing .env values
 *   php artisan yt:setup --no-interaction  # Skip prompts, auto-detect only
 */
class SetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'yt:setup
        {--force : Overwrite existing .env values}
        {--no-interaction : Skip prompts, rely on auto-detection only}';

    /**
     * The console command description.
     */
    protected $description = '⚙️  Interactive setup wizard for YouTube Cloud Storage';

    public function __construct(
        private readonly AutoConfigurator $autoConfigurator,
        private readonly StorageConfig $config,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->newLine();
        info('╔════════════════════════════════════════════════════════╗');
        info('║   ⚙️  YouTube Cloud Storage — Setup Wizard            ║');
        info('╚════════════════════════════════════════════════════════╝');
        $this->newLine();

        $force = $this->option('force');
        $noInteraction = $this->option('no-interaction');

        // ── Step 1: Auto-Configuration ────────────────────────────────
        note('Step 1/5: Detecting system binaries...');
        $this->newLine();

        $detectionResult = spin(
            callback: fn () => $this->autoConfigurator->runAutoConfig(),
            message: 'Scanning system for required binaries...'
        );

        $this->displayDetectionResults($detectionResult);

        // ── Step 2: Health Check ─────────────────────────────────────
        $this->newLine();
        note('Step 2/5: Running health checks...');
        $this->newLine();

        $healthCheck = new HealthCheck($this->config, $this->autoConfigurator);
        $report = $healthCheck->run();

        $this->line(HealthCheck::formatAsTable($report));
        $this->newLine();

        if (!$report['passed']) {
            $criticalFailures = array_filter(
                $report['checks'],
                fn ($check) => !$check['passed'] && $check['severity'] === 'critical'
            );

            if (!empty($criticalFailures)) {
                error('Critical dependencies are missing. Setup cannot continue.');
                note('Install the missing dependencies and run: php artisan yt:setup');
                return self::FAILURE;
            }
        }

        // ── Step 3: OAuth Credentials ────────────────────────────────
        if (!$noInteraction) {
            $this->newLine();
            note('Step 3/5: YouTube OAuth credentials...');
            $this->newLine();

            $credentialsNeeded = $this->checkCredentialsNeeded();

            if ($credentialsNeeded) {
                info('YouTube OAuth credentials are required for video uploads.');
                note('Follow these steps to obtain your credentials:');
                $this->newLine();
                note('1. Go to Google Cloud Console: https://console.cloud.google.com/');
                note('2. Create a new project or select an existing one');
                note('3. Enable "YouTube Data API v3"');
                note('4. Go to "Credentials" → "Create Credentials" → "OAuth client ID"');
                note('5. Application type: "Web application"');
                note('6. Add authorized redirect URIs: http://localhost/callback');
                note('7. Copy your Client ID and Client Secret');
                note('8. Complete OAuth flow to get Refresh Token');
                $this->newLine();

                $clientId = text(
                    label: 'OAuth Client ID',
                    placeholder: 'e.g., 123456789-abc123def456.apps.googleusercontent.com',
                    required: true
                );

                $clientSecret = password(
                    label: 'OAuth Client Secret',
                    placeholder: 'e.g., GOCSPX-abc123def456',
                    required: true
                );

                note('To get the refresh token, run this OAuth flow in your browser:');
                $authUrl = $this->buildAuthUrl($clientId);
                $this->newLine();
                note($authUrl);
                $this->newLine();

                $refreshToken = password(
                    label: 'OAuth Refresh Token (paste from browser callback)',
                    placeholder: 'e.g., 1//0abc123...',
                    required: true
                );

                // Save to environment
                $this->saveToEnv([
                    'YOUTUBE_CLIENT_ID' => $clientId,
                    'YOUTUBE_CLIENT_SECRET' => $clientSecret,
                    'YOUTUBE_REFRESH_TOKEN' => $refreshToken,
                ], $force);

                info('✓ OAuth credentials saved to .env');
            } else {
                info('✓ YouTube credentials already configured');
            }
        }

        // ── Step 4: Write Binary Paths ───────────────────────────────
        $this->newLine();
        note('Step 4/5: Saving configuration...');
        $this->newLine();

        $envVars = [];

        if ($detectionResult['ffmpeg'] !== null) {
            $envVars['FFMPEG_PATH'] = $detectionResult['ffmpeg'];
        }

        if ($detectionResult['ffprobe'] !== null) {
            $envVars['FFPROBE_PATH'] = $detectionResult['ffprobe'];
        }

        if ($detectionResult['ytdlp'] !== null) {
            $envVars['YTDLP_PATH'] = $detectionResult['ytdlp'];
        }

        if ($detectionResult['wirehair'] !== null) {
            $envVars['YTSTORAGE_WIREHAIR_LIB'] = $detectionResult['wirehair'];
        }

        if (!empty($envVars)) {
            $this->saveToEnv($envVars, $force);
            info('✓ Binary paths saved to .env');
        } else {
            note('No binary paths to save (already using system defaults)');
        }

        // ── Step 5: Final Verification ───────────────────────────────
        $this->newLine();
        note('Step 5/5: Final verification...');
        $this->newLine();

        $finalCheck = spin(
            callback: fn () => $this->autoConfigurator->runAutoConfig(),
            message: 'Verifying configuration...'
        );

        $allGood = (
            $finalCheck['ffmpeg'] !== null &&
            $finalCheck['ffprobe'] !== null &&
            $finalCheck['ytdlp'] !== null &&
            $finalCheck['wirehair'] !== null
        );

        if ($allGood) {
            $this->newLine();
            info('╔════════════════════════════════════════════════════════╗');
            info('║   ✓ Setup Complete!                                    ║');
            info('╚════════════════════════════════════════════════════════╝');
            $this->newLine();

            note('Your package is now configured and ready to use!');
            $this->newLine();

            note('Usage examples:');
            $this->newLine();
            note('# Upload a file via Artisan:');
            note('php artisan yt:store /path/to/file.pdf');
            $this->newLine();
            note('# Or use the Storage facade:');
            note('Storage::disk(\'youtube\')->put(\'file.pdf\', $contents);');
            $this->newLine();
            note('# Download a file:');
            note('php artisan yt:restore "https://youtube.com/watch?v=..."');
            $this->newLine();

            return self::SUCCESS;
        } else {
            $this->newLine();
            error('Setup completed with warnings. Some dependencies are still missing.');
            return self::FAILURE;
        }
    }

    /**
     * Display binary detection results as a table.
     */
    private function displayDetectionResults(array $results): void
    {
        $rows = [
            ['FFmpeg', $results['ffmpeg'] ?? '✖ Not found', $results['ffmpeg'] ? '✔' : '✖'],
            ['FFprobe', $results['ffprobe'] ?? '✖ Not found', $results['ffprobe'] ? '✔' : '✖'],
            ['yt-dlp', $results['ytdlp'] ?? '✖ Not found', $results['ytdlp'] ? '✔' : '✖'],
            ['Wirehair', $results['wirehair'] ?? '✖ Not found', $results['wirehair'] ? '✔' : '✖'],
        ];

        table(
            headers: ['Dependency', 'Path', 'Status'],
            rows: $rows
        );

        if (!$results['allFound']) {
            note('Some dependencies were not auto-detected.');
            note('You can install them manually or set environment variables:');
            note('  FFMPEG_PATH=/path/to/ffmpeg');
            note('  YTDLP_PATH=/path/to/yt-dlp');
        }
    }

    /**
     * Check if YouTube credentials are already configured.
     */
    private function checkCredentialsNeeded(): bool
    {
        $current = $this->config->youtubeOAuthCredentials;

        return empty($current['client_id'] ?? '') ||
               empty($current['client_secret'] ?? '') ||
               empty($current['refresh_token'] ?? '');
    }

    /**
     * Build OAuth authorization URL.
     */
    private function buildAuthUrl(string $clientId): string
    {
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => 'http://localhost/callback',
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'scope' => 'https://www.googleapis.com/auth/youtube.upload',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Save environment variables to .env file.
     *
     * @param  array<string, string> $vars Variables to save.
     * @param  bool $force Overwrite existing values.
     */
    private function saveToEnv(array $vars, bool $force = false): void
    {
        $envPath = base_path('.env');

        if (!file_exists($envPath)) {
            error('.env file not found at ' . $envPath);
            return;
        }

        $envContent = file_get_contents($envPath);
        $lines = explode("\n", $envContent);
        $modified = false;

        foreach ($vars as $key => $value) {
            $escapedValue = str_replace('"', '\\"', $value);
            $newValue = "{$key}=\"{$escapedValue}\"";

            $found = false;
            foreach ($lines as &$line) {
                if (preg_match("/^{$key}=/", $line)) {
                    if ($force || empty(trim(explode('=', $line, 2)[1] ?? ''))) {
                        $line = $newValue;
                        $modified = true;
                        $found = true;
                    }
                    break;
                }
            }

            if (!$found) {
                $lines[] = $newValue;
                $modified = true;
            }
        }

        if ($modified) {
            file_put_contents($envPath, implode("\n", $lines));
        }
    }
}
