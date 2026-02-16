#!/bin/bash
# Aegis Installer Build Script
# Builds platform-specific installers for Aegis

set -e

echo "=== Aegis Installer Build ==="
echo ""

# Check current platform
PLATFORM=$(uname -s)
echo "Current platform: $PLATFORM"
echo ""

# Determine what we can build
case "$PLATFORM" in
    Darwin)
        echo "✓ Can build: macOS .dmg"
        echo "✗ Cannot build: Windows .exe (requires Windows or cross-compilation)"
        echo "✗ Cannot build: Linux .AppImage (requires Linux)"
        BUILD_TARGET="mac"
        ;;
    Linux)
        echo "✗ Cannot build: macOS .dmg (requires macOS)"
        echo "✗ Cannot build: Windows .exe (requires Windows or cross-compilation)"
        echo "✓ Can build: Linux .AppImage"
        BUILD_TARGET="linux"
        ;;
    MINGW*|MSYS*|CYGWIN*)
        echo "✗ Cannot build: macOS .dmg (requires macOS)"
        echo "✓ Can build: Windows .exe"
        echo "✗ Cannot build: Linux .AppImage (requires Linux)"
        BUILD_TARGET="win"
        ;;
    *)
        echo "✗ Unknown platform: $PLATFORM"
        exit 1
        ;;
esac

echo ""
echo "Building for: $BUILD_TARGET"
echo ""

# Pre-build checks
echo "Running pre-build checks..."
php artisan test --compact || { echo "✗ Tests failed. Fix tests before building."; exit 1; }
echo "✓ Tests passed"
echo ""

# Build
echo "Starting build process..."
php artisan native:build "$BUILD_TARGET" --no-interaction

echo ""
echo "=== Build Complete ==="
echo ""
echo "Installer location: dist/"
ls -lh dist/ 2>/dev/null || echo "No dist/ directory created"
