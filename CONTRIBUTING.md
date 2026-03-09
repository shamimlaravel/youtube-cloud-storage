# Contributing to YouTube Cloud Storage

Thank you for your interest in contributing! This document provides guidelines and instructions.

## 📋 Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Testing Guidelines](#testing-guidelines)
- [Code Style](#code-style)
- [Pull Request Process](#pull-request-process)
- [Architecture Overview](#architecture-overview)

---

## Code of Conduct

- Be respectful and inclusive
- Welcome newcomers and help them learn
- Focus on constructive feedback
- Keep discussions professional and on-topic

---

## Getting Started

### 1. Fork the Repository

```bash
# Clone your fork
git clone https://github.com/YOUR_USERNAME/youtube-cloud-storage.git

# Navigate to directory
cd youtube-cloud-storage

# Add upstream remote
git remote add upstream https://github.com/shamimstack/youtube-cloud-storage.git
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Set Up Environment

```bash
# Copy example environment
cp .env.example .env

# Configure paths (adjust for your system)
FFMPEG_PATH=/usr/bin/ffmpeg
YTDLP_PATH=/usr/bin/yt-dlp
```

---

## Development Setup

### Required Tools

- PHP 8.4+
- Composer
- FFmpeg
- yt-dlp
- Git

### Recommended IDE Extensions

- PHP Intelephense
- PHPUnit Test Explorer
- Laravel Blade Highlight
- Tailwind CSS IntelliSense

---

## Testing Guidelines

### Running Tests

```bash
# Run all tests
php run-tests.php

# Run specific test suite
php run-tests.php --unit          # Unit tests
php run-tests.php --integration   # Integration tests
php run-tests.php --dct           # DCT algorithm tests

# With code coverage (requires Xdebug)
php run-tests.php --coverage
```

### Writing Tests

#### Unit Test Example

```php
<?php

namespace Shamimstack\YouTubeCloudStorage\Tests;

use PHPUnit\Framework\TestCase;

class MyTest extends TestCase
{
    public function testExample(): void
    {
        // Arrange
        $expected = 42;
        
        // Act
        $actual = 6 * 7;
        
        // Assert
        $this->assertEquals($expected, $actual);
    }
}
```

#### Test Naming Conventions

- Use descriptive names: `testMethodName_ExpectedBehavior_State`
- Start with `test` prefix
- Use camelCase

#### Coverage Requirements

Aim for:
- ✅ **80%+** code coverage
- ✅ All critical paths tested
- ✅ Edge cases covered
- ✅ Error conditions validated

---

## Code Style

### PHP Standards

Follow PSR-12 coding standards:

```php
<?php

declare(strict_types=1);

namespace Shamimstack\YouTubeCloudStorage;

use SomeClass;

/**
 * Class documentation with description.
 */
class MyClass
{
    public function methodName(Type $param): ReturnType
    {
        // Implementation
    }
}
```

### Key Rules

1. **Indentation**: 4 spaces (no tabs)
2. **Line length**: Max 120 characters
3. **Naming**: 
   - Classes: PascalCase (`VideoProcessor`)
   - Methods: camelCase (`encodeAndUpload`)
   - Variables: camelCase (`bitStream`)
   - Constants: UPPER_SNAKE_CASE (`DEFAULT_CODEC`)

4. **Type hints**: Always use strict types
5. **DocBlocks**: Required for public methods

### PHPStan Level

We maintain PHPStan level 8 compliance:

```bash
vendor/bin/phpstan analyse --level=8 src/
```

---

## Pull Request Process

### Before Submitting

1. **Fork and branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make changes**
   - Follow existing code style
   - Add/update tests
   - Update documentation

3. **Run tests**
   ```bash
   php run-tests.php
   ```

4. **Check code quality**
   ```bash
   vendor/bin/phpstan analyse
   vendor/bin/phpcs
   ```

5. **Commit messages**
   ```bash
   # Good
   git commit -m "feat: add encryption support for uploads"
   
   # Bad
   git commit -m "fixed stuff"
   ```

### PR Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
- [ ] Tests added/updated
- [ ] Manual testing performed

## Checklist
- [ ] Code follows project guidelines
- [ ] Self-review completed
- [ ] Comments added where necessary
- [ ] Documentation updated
```

---

## Architecture Overview

### Core Components

```
src/
├── Commands/           # Artisan CLI commands
├── DTOs/              # Data Transfer Objects
├── Drivers/           # Flysystem adapter
├── Engines/           # Core algorithms
├── Exceptions/        # Custom exceptions
├── Facades/          # Laravel facades
└── Support/          # Helper classes
```

### Key Concepts

1. **DCT Steganography**: Embed data in video frequency domain
2. **Fountain Codes**: Add redundancy for error recovery
3. **Flysystem Adapter**: Integrate with Laravel Storage

### Data Flow

```
File → Wirehair Encode → DCT Embed → FFmpeg → YouTube
                                              ↓
YouTube → FFprobe → DCT Extract → Wirehair Decode → File
```

---

## Areas Needing Contribution

### High Priority

- [ ] AES-256 encryption before encoding
- [ ] Batch upload support
- [ ] Progress callbacks
- [ ] Resume interrupted uploads

### Medium Priority

- [ ] GPU acceleration for DCT
- [ ] Multi-threaded processing
- [ ] Automatic chunking for large files
- [ ] Performance optimization

### Nice to Have

- [ ] Web UI for uploads
- [ ] Mobile app integration
- [ ] Cloud storage sync
- [ ] Compression optimization

---

## Documentation

### Updating Docs

1. **README.md**: User-facing documentation
2. **docs/index.html**: Interactive documentation site
3. **Code comments**: Inline explanations

### Documentation Standards

- Clear and concise language
- Include examples for all features
- Explain why, not just what
- Link to related resources

---

## Getting Help

- 💬 **Discussions**: GitHub Discussions tab
- 🐛 **Issues**: GitHub Issues for bugs
- 📧 **Email**: shamim@example.com
- 💻 **Slack**: Laravel Discord server

---

## Recognition

Contributors will be recognized in:

- README.md contributors section
- Release notes
- Project website

---

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

---

**Thank you for contributing to YouTube Cloud Storage! 🎉**
