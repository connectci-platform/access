#!/bin/bash

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Navigate to the ACCESS module root (one level up from scripts/)
cd "$SCRIPT_DIR/.."

# Get the absolute path to the parent Drupal installation  
DRUPAL_ROOT="$(cd ../../../.. && pwd)"

# Run PHPStan with explicit autoload file instead of using config bootstrap
"$DRUPAL_ROOT/vendor/bin/phpstan" analyse \
  --configuration=phpstan.neon \
  --autoload-file="$DRUPAL_ROOT/vendor/autoload.php" \
  --memory-limit=512M \
  --no-progress