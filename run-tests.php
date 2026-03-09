#!/usr/bin/env php
<?php

/**
 * Test Runner for YouTube Cloud Storage Package.
 *
 * Runs all tests with proper error reporting and coverage.
 *
 * Usage:
 *   php run-tests.php                    # Run all tests
 *   php run-tests.php --unit             # Run unit tests only
 *   php run-tests.php --integration      # Run integration tests only
 *   php run-tests.php --dct              # Run DCT algorithm tests only
 *   php run-tests.php --coverage         # Generate code coverage report
 */

declare(strict_types=1);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Colors for terminal output
class Colors
{
    const RED = "\033[31m";
    const GREEN = "\033[32m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const MAGENTA = "\033[35m";
    const CYAN = "\033[36m";
    const RESET = "\033[0m";
    const BOLD = "\033[1m";
}

// Check if PHPUnit is available
function checkPHPUnit(): bool
{
    if (!file_exists('vendor/autoload.php')) {
        echo Colors::RED . "❌ vendor/autoload.php not found.\n" . Colors::RESET;
        echo "Run: composer install\n\n";
        return false;
    }
    
    if (!file_exists('vendor/bin/phpunit')) {
        echo Colors::RED . "❌ PHPUnit not found.\n" . Colors::RESET;
        echo "Run: composer require --dev phpunit/phpunit\n\n";
        return false;
    }
    
    return true;
}

// Run tests
function runTests(array $options): int
{
    $testFiles = [];
    
    // Determine which tests to run
    if (isset($options['--dct'])) {
        $testFiles[] = 'tests/DctAlgorithmTest.php';
    } elseif (isset($options['--unit'])) {
        $testFiles[] = 'tests/UnitTest.php';
    } elseif (isset($options['--integration'])) {
        $testFiles[] = 'tests/IntegrationTest.php';
    } else {
        // Run all tests
        $testFiles = [
            'tests/UnitTest.php',
            'tests/IntegrationTest.php',
            'tests/DctAlgorithmTest.php',
        ];
    }
    
    // Build PHPUnit command
    $command = './vendor/bin/phpunit ';
    
    if (isset($options['--coverage']) && extension_loaded('xdebug')) {
        $command .= '--coverage-html coverage/ ';
        echo Colors::BLUE . "✓ Code coverage enabled. Report will be in coverage/\n\n" . Colors::RESET;
    }
    
    $command .= implode(' ', $testFiles);
    
    // Add verbose flag
    if (isset($options['--verbose']) || isset($options['-v'])) {
        $command .= ' -v';
    }
    
    // Execute
    echo Colors::CYAN . "Running tests...\n" . Colors::RESET;
    echo Colors::YELLOW . "Command: {$command}\n\n" . Colors::RESET;
    
    exec($command, $output, $returnCode);
    
    // Display output
    foreach ($output as $line) {
        echo $line . "\n";
    }
    
    echo "\n";
    
    // Summary
    if ($returnCode === 0) {
        echo Colors::GREEN . str_repeat('=', 60) . "\n";
        echo "✅ ALL TESTS PASSED!\n";
        echo str_repeat('=', 60) . Colors::RESET . "\n\n";
    } else {
        echo Colors::RED . str_repeat('=', 60) . "\n";
        echo "❌ TESTS FAILED (Exit code: {$returnCode})\n";
        echo str_repeat('=', 60) . Colors::RESET . "\n\n";
    }
    
    return $returnCode;
}

// Display help
function displayHelp(): void
{
    echo Colors::BOLD . "YouTube Cloud Storage - Test Runner\n" . Colors::RESET;
    echo Colors::CYAN . "=====================================\n\n" . Colors::RESET;
    
    echo "Usage:\n";
    echo "  php run-tests.php [options]\n\n";
    
    echo "Options:\n";
    echo "  --unit          Run unit tests only\n";
    echo "  --integration   Run integration tests only\n";
    echo "  --dct           Run DCT algorithm tests only\n";
    echo "  --coverage      Generate code coverage report (requires Xdebug)\n";
    echo "  --verbose, -v   Verbose output\n";
    echo "  --help, -h      Show this help message\n\n";
    
    echo "Examples:\n";
    echo "  php run-tests.php                 # Run all tests\n";
    echo "  php run-tests.php --unit          # Run unit tests\n";
    echo "  php run-tests.php --coverage      # With code coverage\n\n";
}

// Parse command line arguments
$options = [];
$args = array_slice($argv, 1);

foreach ($args as $arg) {
    if (in_array($arg, ['--help', '-h'])) {
        displayHelp();
        exit(0);
    }
    $options[$arg] = true;
}

// Main execution
echo Colors::MAGENTA . "╔════════════════════════════════════════════╗\n";
echo "║  YouTube Cloud Storage - Test Suite       ║\n";
echo "╚════════════════════════════════════════════╝\n" . Colors::RESET . "\n";

if (!checkPHPUnit()) {
    exit(1);
}

$exitCode = runTests($options);
exit($exitCode);
