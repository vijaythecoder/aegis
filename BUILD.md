# Building Aegis Installers

This document explains how to build platform-specific installers for Aegis.

## Prerequisites

- PHP 8.4+
- Composer
- Node.js & NPM
- NativePHP installed (`composer require nativephp/electron`)

## Quick Start

### Local Build (Current Platform Only)

```bash
# Run the build script
./build-installer.sh
```

This will:
1. Run tests to ensure quality
2. Build an installer for your current platform
3. Output the installer to `dist/`

### Manual Build

```bash
# macOS
php artisan native:build mac

# Windows
php artisan native:build win

# Linux
php artisan native:build linux

# All platforms (requires appropriate build environment)
php artisan native:build all
```

## Multi-Platform Builds (CI/CD)

For production releases, use GitHub Actions to build for all platforms:

1. **Push a version tag**:
   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```

2. **GitHub Actions will automatically**:
   - Build macOS .dmg on macOS runner
   - Build Windows .exe on Windows runner
   - Build Linux .AppImage on Linux runner
   - Create a GitHub Release with all installers

3. **Manual trigger** (optional):
   - Go to Actions tab in GitHub
   - Select "Build Installers" workflow
   - Click "Run workflow"

## Build Artifacts

After building, installers are located in `dist/`:

- **macOS**: `Aegis-{version}.dmg`
- **Windows**: `Aegis Setup {version}.exe`
- **Linux**: `Aegis-{version}.AppImage`

## Configuration

Build settings are in `config/nativephp.php`:

```php
'version' => env('NATIVEPHP_APP_VERSION', '1.0.0'),
'app_id' => env('NATIVEPHP_APP_ID', 'com.aegis.app'),
'author' => env('NATIVEPHP_APP_AUTHOR', 'Aegis Team'),
```

Update `.env` to customize:

```env
NATIVEPHP_APP_VERSION=1.0.0
NATIVEPHP_APP_ID=com.aegis.app
NATIVEPHP_APP_AUTHOR=Your Name
```

## Code Signing (Production)

For production distribution, you need code signing certificates:

### macOS

1. Get an Apple Developer account
2. Create a Developer ID Application certificate
3. Set environment variables:
   ```env
   NATIVEPHP_APPLE_ID=your@email.com
   NATIVEPHP_APPLE_ID_PASS=app-specific-password
   NATIVEPHP_APPLE_TEAM_ID=TEAM123456
   ```

### Windows

1. Get a code signing certificate
2. Use Azure Trusted Signing (recommended):
   ```env
   NATIVEPHP_AZURE_PUBLISHER_NAME=Your Name
   NATIVEPHP_AZURE_ENDPOINT=https://...
   NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME=profile
   NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME=account
   ```

### Linux

Linux .AppImage files don't require code signing but can be GPG-signed for verification.

## Troubleshooting

### Build fails with "npm timeout"

Increase timeout in `package.json`:
```json
{
  "config": {
    "timeout": 600000
  }
}
```

### Missing dependencies

```bash
composer install
npm ci
```

### Tests fail

Fix failing tests before building:
```bash
php artisan test
```

## Publishing

After building, publish to:

1. **GitHub Releases** (automated via Actions)
2. **Direct download** from your website
3. **Package managers**:
   - macOS: Homebrew Cask
   - Windows: Chocolatey, Scoop
   - Linux: Snap, Flatpak

## Auto-Updates

Aegis includes auto-update functionality. Configure update server in `.env`:

```env
NATIVEPHP_UPDATER_PATH=https://your-domain.com/updates
```

The updater checks for new versions and prompts users to install updates.

## Further Reading

- [NativePHP Documentation](https://nativephp.com/docs/1/building/overview)
- [Electron Builder](https://www.electron.build/)
- [Code Signing Guide](https://nativephp.com/docs/1/building/code-signing)
