#!/usr/bin/env bash
# up.sh — one command to a ready-to-test native-translations site.
#
#   ./harness/up.sh              bootstrap + serve + activate + seed, then print the URL
#   ./harness/up.sh --no-seed    skip seeding (plugin active on a clean WP 7.0)
#   ./harness/up.sh --reset      wipe any existing site first, then bring up fresh
#
# Leaves the server running so an agent can drive it. Stop with playground.sh stop.
set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PG="$HARNESS_DIR/playground.sh"

SEED=1
RESET=0
for arg in "$@"; do
  case "$arg" in
    --no-seed) SEED=0;;
    --reset) RESET=1;;
    *) echo "up.sh: unknown arg '$arg'" >&2; exit 2;;
  esac
done

[ "$RESET" = 1 ] && "$PG" reset
"$PG" bootstrap
"$PG" ensure

# Activate the plugin on the default bundled theme (twentytwentyfive on WP 7.0).
"$PG" wp -- theme activate twentytwentyfive >/dev/null 2>&1 || true
"$PG" wp -- plugin activate native-translations

if [ "$SEED" = 1 ]; then
  "$PG" seed
fi

# Wire a real AI provider (OpenRouter) when harness/.env.local supplies a key, so the
# disposable site can run real translations for the E2E suite. No-ops without a key.
"$PG" provision-ai

# Flush so the language taxonomy + permalinks are clean.
"$PG" wp -- rewrite flush >/dev/null 2>&1 || true
"$PG" wp -- cache flush >/dev/null 2>&1 || true

echo
echo "✅ Ready. Test site is live at: $("$PG" url)"
echo "   Admin:     $("$PG" url "/wp-admin/")"
echo "   Settings:  $("$PG" url "/wp-admin/options-general.php?page=native-translations")"
echo "   Overview:  $("$PG" url "/wp-admin/options-general.php?page=native-translations-overview")"
echo
echo "   Screenshots:  $PG shot -- \"/\" /tmp/home.png"
echo "   Screencast:   $PG cast -- \"/\" /tmp/home.webm"
echo "   Stop:         $PG stop    (reset a fresh site with: $PG reset)"
