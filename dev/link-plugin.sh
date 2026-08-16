#!/usr/bin/env bash
#
# Mounts another local plugin repository into an existing bench and requires it.
#
#   ./dev/link-plugin.sh 4 ~/ddev/some-other-craft-plugin
#   ./dev/link-plugin.sh 5 ~/ddev/some-other-craft-plugin
#
# This is what makes the benches shared infrastructure rather than something you
# rebuild per plugin: the path repository is a glob over /var/www/plugins, so a new
# mount is all it takes.

set -euo pipefail

VERSION="${1:-}"
TARGET="${2:-}"
BENCH_ROOT="${CRAFT_BENCH_ROOT:-$HOME/ddev/craft-test-environments}"

if [ -z "$VERSION" ] || [ -z "$TARGET" ]; then
    echo "usage: $0 <4|5> <path-to-plugin-repo>" >&2
    exit 64
fi

BENCH_DIR="$BENCH_ROOT/v$VERSION"
[ -d "$BENCH_DIR" ] || { echo "No bench at $BENCH_DIR. Run scaffold-bench.sh $VERSION first." >&2; exit 1; }

TARGET="$(cd "$TARGET" && pwd)"
[ -f "$TARGET/composer.json" ] || { echo "No composer.json in $TARGET" >&2; exit 1; }

NAME="$(basename "$TARGET")"
PACKAGE="$(php -r 'echo json_decode(file_get_contents("'"$TARGET"'/composer.json"), true)["name"];')"
MOUNTS="$BENCH_DIR/.ddev/docker-compose.plugins.yaml"

if grep -q "$TARGET:" "$MOUNTS" 2>/dev/null; then
    echo "$NAME is already mounted into the Craft $VERSION bench."
else
    echo "==> Mounting $NAME"
    printf '      - "%s:/var/www/plugins/%s"\n' "$TARGET" "$NAME" >> "$MOUNTS"
    (cd "$BENCH_DIR" && ddev restart)
fi

echo "==> Requiring $PACKAGE"
cd "$BENCH_DIR"
ddev composer require "$PACKAGE:@dev" -W

echo "==> Done. Install it with:"
echo "    cd '$BENCH_DIR' && ddev craft plugin/install <handle>"
