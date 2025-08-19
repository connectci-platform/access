#!/bin/bash

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Navigate to the ACCESS module root (one level up from scripts/)
cd "$SCRIPT_DIR/.."

# Get the absolute path to the parent Drupal installation  
DRUPAL_ROOT="$(cd ../../../.. && pwd)"

# Create a temporary phpstan config with absolute paths
cat > phpstan-tmp.neon << EOF
includes:
  - phpstan-baseline.neon
parameters:
  level: 0
  paths:
    - .
  excludePaths:
    - tests/*
    - */tests/*
    - vendor/*
  bootstrapFiles:
    - $DRUPAL_ROOT/vendor/autoload.php
EOF

# Run PHPStan with absolute paths
"$DRUPAL_ROOT/vendor/bin/phpstan" analyse \
  --configuration=phpstan-tmp.neon \
  --memory-limit=512M \
  --no-progress

# Clean up temp config
rm -f phpstan-tmp.neon