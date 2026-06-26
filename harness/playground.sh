#!/usr/bin/env bash
# playground.sh — disposable WordPress Playground lifecycle for the native-translations
# dev harness. Spins up a real, isolated WordPress 7.0 site (PHP-WASM + SQLite) that
# coding agents can tear down and rebuild in seconds. Adapted from Automattic/wp-skill.
#
# Verbs:
#   playground.sh bootstrap                  one-time: create a site, record its dir
#   playground.sh ensure [host:vfs ...]      idempotent: converge to a running server
#                                            (the plugin is always mounted; pass extra
#                                            mounts, e.g. a test theme, as args)
#   playground.sh wp -- <wp-cli args>        run wp-cli against the same site + mounts
#   playground.sh test [php|js|e2e]          run test suites: php/js unit (default
#                                            both), or `e2e` for the live translation
#                                            flow against the configured AI provider
#   playground.sh provision-ai               wire OpenRouter into the site from
#                                            harness/.env.local (no-op without a key)
#   playground.sh seed [--force]            seed example posts/cats/tags (en_US + es_AR)
#   playground.sh shot -- <shot.mjs args>    take a screenshot (see shot.mjs)
#   playground.sh cast -- <cast.mjs args>    record a screencast (see cast.mjs)
#   playground.sh status                     print server state + live URL
#   playground.sh url [path]                 print the live URL (optionally + path)
#   playground.sh stop                       stop the server, assert clean teardown
#   playground.sh reset                      WIPE the site + state (disposable!): next
#                                            `bootstrap`/`up` starts a brand-new site
#
# State lives in harness/.state/ (gitignored). The WordPress files themselves live in
# ~/.wordpress-playground/sites/<hash>/ — `reset` deletes that dir too so nothing
# survives. The plugin is mounted read/write from the repo, so edits are live without a
# copy/build step (build artifacts are committed under native-translations/build/).
#
# Mount consistency is enforced by construction (same idea as wp-skill): `ensure`
# records its extra mounts and `wp`/`shot`/`cast` replay them. Calling `ensure` with a
# different mount set than the live server restarts the server with the new set.
#
# Pinned versions (bump only after re-running the full up→wp→shot→cast→stop chain):
PLAYGROUND_VERSION="${PLAYGROUND_VERSION:-3.1.38}"   # @wp-playground/cli, verified WP 7.0
WPCLI_PHAR_URL="https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar"
set -euo pipefail

# Resolve repo + harness dirs regardless of cwd.
HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$HARNESS_DIR/.." && pwd)"
PLUGIN_DIR="$REPO_DIR/native-translations"
PLUGIN_VFS="/wordpress/wp-content/plugins/native-translations"

STATE="$HARNESS_DIR/.state"
PHAR="$STATE/wp-cli.phar"

die() { echo "playground.sh: $*" >&2; exit 1; }

command -v setsid >/dev/null 2>&1 || \
  die "setsid not found (Linux/WSL have it; on macOS: brew install util-linux)"
[ -d "$PLUGIN_DIR" ] || die "plugin not found at $PLUGIN_DIR"

CLI=(npx -y "@wp-playground/cli@${PLAYGROUND_VERSION}")

free_port() {
  local p
  for p in $(seq 9400 9499); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; then echo "$p"; return 0; fi
    exec 3>&- 3<&- || true
  done
  die "no free port in 9400-9499"
}

site_dir() {
  test -s "$STATE/site-dir" || die "run 'bootstrap' first (no $STATE/site-dir)"
  local d; d=$(cat "$STATE/site-dir")
  [ -d "$d" ] || die "recorded site dir is gone ($d) — run 'reset' then 'bootstrap'"
  echo "$d"
}

# Always-on plugin mount, plus any extra mounts recorded in $STATE/mounts.
mount_args() {
  MOUNTS=( "--mount=$PLUGIN_DIR:$PLUGIN_VFS" )
  [ -f "$STATE/mounts" ] || return 0
  while IFS= read -r m; do
    [ -n "$m" ] && MOUNTS+=( "--mount=$m" )
  done < "$STATE/mounts"
}

pgid_is_ours() {
  local g="$1"
  [ -n "$g" ] || return 1
  ps -eo pgid=,args= | awk -v g="$g" '$1 == g' | grep -q 'wp-playground\|@wp-playground/cli'
}

server_alive() {
  [ -f "$STATE/server.port" ] && [ -f "$STATE/server.pid" ] && [ -f "$STATE/server.pgid" ] || return 1
  kill -0 "$(cat "$STATE/server.pid")" 2>/dev/null || return 1
  pgid_is_ours "$(cat "$STATE/server.pgid")" || return 1
  curl -fs -o /dev/null --max-time 2 "http://127.0.0.1:$(cat "$STATE/server.port")/" 2>/dev/null
}

kill_recorded() {
  local pgid="" pid=""
  [ -f "$STATE/server.pgid" ] && pgid=$(cat "$STATE/server.pgid")
  [ -f "$STATE/server.pid" ]  && pid=$(cat "$STATE/server.pid")
  if [ -n "$pgid" ] && pgid_is_ours "$pgid"; then
    kill -TERM -- "-$pgid" 2>/dev/null || true
  elif [ -n "$pid" ]; then
    kill "$pid" 2>/dev/null || true
  fi
}

print_curl_hint() {
  local p; p=$(cat "$STATE/server.port")
  echo "frontend check: curl -sL -c $STATE/cookies -b $STATE/cookies http://127.0.0.1:$p/"
}

cmd_bootstrap() {
  mkdir -p "$STATE"
  if [ -s "$STATE/site-dir" ] && [ -d "$(cat "$STATE/site-dir")" ]; then
    echo "already bootstrapped: $(cat "$STATE/site-dir")"; return 0
  fi
  rm -f "$STATE/site-dir"
  local port; port=$(free_port)
  echo "bootstrapping a fresh WordPress 7.0 site (mounting plugin $PLUGIN_DIR)…"
  setsid nohup "${CLI[@]}" start --path="$PLUGIN_DIR" --skip-browser --port="$port" \
    > "$STATE/bootstrap.log" 2>&1 &
  local pid=$!
  local pgid; pgid=$(ps -o pgid= -p "$pid" | tr -d ' ')
  local i
  for i in $(seq 1 60); do
    kill -0 "$pid" 2>/dev/null || break
    grep -q "Site files stored at:" "$STATE/bootstrap.log" 2>/dev/null && \
      curl -fs -o /dev/null --max-time 2 "http://127.0.0.1:$port/" 2>/dev/null && break
    sleep 2
  done
  sed -n 's/.*Site files stored at: //p' "$STATE/bootstrap.log" | head -1 > "$STATE/site-dir"
  if ! [ -s "$STATE/site-dir" ] || ! [ -d "$(cat "$STATE/site-dir")" ]; then
    [ -n "$pgid" ] && kill -TERM -- "-$pgid" 2>/dev/null || true
    rm -f "$STATE/site-dir"
    echo "--- last lines of $STATE/bootstrap.log:" >&2; tail -5 "$STATE/bootstrap.log" >&2 || true
    die "bootstrap failed; full log: $STATE/bootstrap.log"
  fi
  kill -TERM -- "-$pgid" 2>/dev/null || kill "$pid" 2>/dev/null || true
  sleep 1
  echo "site-dir: $(cat "$STATE/site-dir")"
}

cmd_ensure() {
  mkdir -p "$STATE"
  local sdir; sdir=$(site_dir)
  local want="" have=""
  [ -f "$STATE/mounts" ] && have=$(cat "$STATE/mounts")
  if [ "$#" -gt 0 ]; then want=$(printf '%s\n' "$@"); else want="$have"; fi
  if server_alive && [ "$want" = "$have" ]; then
    echo "reusing server on port $(cat "$STATE/server.port")"
    print_curl_hint; return 0
  fi
  if server_alive; then
    echo "mounts changed — restarting server with the new mount set"
  fi
  kill_recorded
  rm -f "$STATE"/server.{pid,pgid,port}
  if [ "$#" -gt 0 ]; then printf '%s\n' "$@" > "$STATE/mounts"; else rm -f "$STATE/mounts"; fi
  local MOUNTS; mount_args
  local port; port=$(free_port)
  setsid nohup "${CLI[@]}" server --port="$port" --login \
    --mount-before-install="$sdir:/wordpress" \
    --wordpress-install-mode=install-from-existing-files-if-needed \
    "${MOUNTS[@]}" \
    > "$STATE/server.log" 2>&1 &
  local pid=$!
  echo "$pid" > "$STATE/server.pid"
  ps -o pgid= -p "$pid" | tr -d ' ' > "$STATE/server.pgid"
  echo "$port" > "$STATE/server.port"
  local i
  for i in $(seq 1 60); do
    if ! kill -0 "$pid" 2>/dev/null; then
      kill_recorded
      rm -f "$STATE"/server.{pid,pgid,port}
      echo "--- last lines of $STATE/server.log:" >&2; tail -5 "$STATE/server.log" >&2 || true
      die "server process died during startup; full log: $STATE/server.log"
    fi
    if curl -fs -o /dev/null --max-time 2 "http://127.0.0.1:$port/"; then
      echo "server ready: http://127.0.0.1:$port (log: $STATE/server.log)"
      print_curl_hint; return 0
    fi
    sleep 2
  done
  kill_recorded
  rm -f "$STATE"/server.{pid,pgid,port}
  die "server did not become ready in 120s; see $STATE/server.log"
}

ensure_phar() {
  if [ ! -s "$PHAR" ]; then
    echo "downloading wp-cli phar…"
    curl -fsL -o "$PHAR" "$WPCLI_PHAR_URL" || die "wp-cli phar download failed"
  fi
}

cmd_wp() {
  local sdir; sdir=$(site_dir)
  [ "${1:-}" = "--" ] && shift
  [ "$#" -gt 0 ] || die "usage: playground.sh wp -- <wp-cli args>"
  ensure_phar
  local MOUNTS; mount_args
  # --allow-root: the PHP-WASM process runs as root. --path=/wordpress: the site
  # mount. The siteurl in the DB is a junk ephemeral port; always use the recorded port.
  "${CLI[@]}" php \
    --mount-before-install="$sdir:/wordpress" \
    --wordpress-install-mode=install-from-existing-files-if-needed \
    --mount="$STATE:/host" \
    "${MOUNTS[@]}" \
    -- /host/wp-cli.phar --allow-root --path=/wordpress "$@"
}

run_browser_script() {
  local script="$1"; shift
  [ "${1:-}" = "--" ] && shift
  [ "$#" -gt 0 ] || die "usage: playground.sh <verb> -- <script args> (see $script)"
  server_alive || die "server not running — run 'playground.sh ensure' first"
  local port; port=$(cat "$STATE/server.port")
  [ -s "$STATE/cookies" ] || curl -sL -c "$STATE/cookies" -b "$STATE/cookies" -o /dev/null "http://127.0.0.1:$port/" || true
  cd "$REPO_DIR"   # so `import 'playwright'` resolves from repo node_modules
  node "$script" "$port" "$STATE/cookies" "$@"
}

cmd_status() {
  echo "harness:  $HARNESS_DIR"
  echo "repo:     $REPO_DIR"
  echo "plugin:   $PLUGIN_DIR -> $PLUGIN_VFS"
  if [ -s "$STATE/site-dir" ]; then
    echo "site-dir: $(cat "$STATE/site-dir")"
  else
    echo "site-dir: (not bootstrapped)"
  fi
  if [ -f "$STATE/mounts" ]; then
    echo "extra mounts:"; sed 's/^/  /' "$STATE/mounts"
  fi
  if server_alive; then
    echo "server:   LIVE on http://127.0.0.1:$(cat "$STATE/server.port")"
    print_curl_hint
  else
    echo "server:   not running"
  fi
}

cmd_url() {
  server_alive || die "server not running"
  local p; p=$(cat "$STATE/server.port")
  echo "http://127.0.0.1:$p${1:-}"
}

cmd_stop() {
  [ -f "$STATE/server.pgid" ] || { echo "no recorded server"; return 0; }
  local port=""; [ -f "$STATE/server.port" ] && port=$(cat "$STATE/server.port")
  local pgid; pgid=$(cat "$STATE/server.pgid")
  kill_recorded
  local i
  for i in $(seq 1 10); do pgid_is_ours "$pgid" || break; sleep 1; done
  if [ -n "$port" ] && curl -fs -o /dev/null --max-time 2 "http://127.0.0.1:$port/" 2>/dev/null; then
    die "ASSERT FAILED: server still answering on port $port"
  fi
  if [ -n "$port" ] && pgrep -f "wp-playgroun[d].*--port=$port" >/dev/null 2>&1; then
    die "ASSERT FAILED: playground process still owns port $port"
  fi
  if pgid_is_ours "$pgid"; then
    die "ASSERT FAILED: recorded process group $pgid still alive"
  fi
  rm -f "$STATE"/server.{pid,pgid,port}
  echo "stopped clean (curl fails, no surviving process)"
}

cmd_reset() {
  echo "reset: wiping harness state and the Playground site (disposable env)…"
  cmd_stop 2>/dev/null || true
  if [ -s "$STATE/site-dir" ] && [ -d "$(cat "$STATE/site-dir")" ]; then
    rm -rf "$(cat "$STATE/site-dir")"
  fi
  rm -f "$STATE/site-dir" "$STATE/mounts" "$STATE"/server.{pid,pgid,port,log} "$STATE/cookies"
  echo "reset done — run 'playground.sh bootstrap' (or ./harness/up.sh) for a fresh site."
}

cmd_test() {
  # Runs the assertion-based unit suites. These are DB-free / browser-free and do
  # not need a running Playground site, so they work in CI and locally alike.
  local which="${1:-all}"
  local rc=0

  if [ "$which" = "all" ] || [ "$which" = "php" ]; then
    echo "== PHP unit tests =="
    if [ ! -f "$REPO_DIR/vendor/bin/phpunit" ]; then
      ( cd "$REPO_DIR" && composer install --no-interaction ) || die "composer install failed"
    fi
    ( cd "$REPO_DIR" && composer test ) || rc=1
  fi

  if [ "$which" = "all" ] || [ "$which" = "js" ]; then
    echo "== JS unit tests =="
    ( cd "$REPO_DIR" && npm run test:js ) || rc=1
  fi

  if [ "$which" = "e2e" ]; then
    echo "== E2E translation flow (live site) =="
    [ -s "$HARNESS_DIR/e2e/translate-flow.php" ] || die "e2e script missing"
    cp "$HARNESS_DIR/e2e/translate-flow.php" "$STATE/e2e-translate-flow.php"
    cmd_wp -- eval-file /host/e2e-translate-flow.php || rc=1
  fi

  [ "$rc" -eq 0 ] || die "tests failed"
  echo "All requested test suites passed."
}

cmd_provision_ai() {
  # Make the disposable site able to run REAL translations against OpenRouter, when
  # harness/.env.local supplies a key (issue #38). No-ops cleanly otherwise so the
  # site stays no-AI and the E2E generation tests skip. The key only ever lands in
  # the disposable site's DB option + a mu-plugin in the (gitignored) site dir — never
  # in the repo.
  local env_file="$HARNESS_DIR/.env.local"
  if [ ! -f "$env_file" ]; then
    echo "provision-ai: no harness/.env.local — leaving the site without an AI provider (E2E generation will skip)."
    return 0
  fi
  # shellcheck disable=SC1090
  set -a; . "$env_file"; set +a
  if [ -z "${OPENROUTER_API_KEY:-}" ]; then
    echo "provision-ai: harness/.env.local has no OPENROUTER_API_KEY — E2E generation will skip."
    return 0
  fi

  # OpenRouter provider plugin (not built in). Idempotent.
  if ! cmd_wp -- plugin is-installed ai-provider-for-openrouter >/dev/null 2>&1; then
    cmd_wp -- plugin install ai-provider-for-openrouter --activate >/dev/null
  else
    cmd_wp -- plugin activate ai-provider-for-openrouter >/dev/null 2>&1 || true
  fi

  # Store the key the WordPress-ai-plugin way; the value is not echoed.
  cmd_wp -- option update connectors_ai_openrouter_api_key "$OPENROUTER_API_KEY" >/dev/null

  # Drop the harness mu-plugin into the live site so the key + model pin apply on
  # every request (front end, REST, editor) — not just one eval.
  local mu_dir; mu_dir="$(site_dir)/wp-content/mu-plugins"
  mkdir -p "$mu_dir"
  cp "$HARNESS_DIR/e2e/mu-openrouter.php" "$mu_dir/wpnt-e2e-openrouter.php"

  # Bust the plugin's cached capability probe so it re-detects the now-usable model.
  cmd_wp -- transient delete wpnt_text_generation_supported >/dev/null 2>&1 || true

  echo "provision-ai: OpenRouter provider configured (model z-ai/glm-5.2)."
}

cmd_seed() {
  [ -s "$HARNESS_DIR/seed-content.php" ] || die "seed script missing: $HARNESS_DIR/seed-content.php"
  # Copy into the mounted state dir so the file is visible inside the VFS at /host/.
  cp "$HARNESS_DIR/seed-content.php" "$STATE/seed-content.php"
  cmd_wp -- eval-file /host/seed-content.php "$@"
}

case "${1:-}" in
  bootstrap) shift; cmd_bootstrap "$@";;
  ensure)    shift; cmd_ensure "$@";;
  wp)          shift; cmd_wp "$@";;
  test)        shift; cmd_test "$@";;
  provision-ai) shift; cmd_provision_ai "$@";;
  seed)        shift; cmd_seed "$@";;
  shot)      shift; run_browser_script "$HARNESS_DIR/shot.mjs" "$@";;
  cast)      shift; run_browser_script "$HARNESS_DIR/cast.mjs" "$@";;
  status)    shift; cmd_status "$@";;
  url)       shift; cmd_url "$@";;
  stop)      shift; cmd_stop "$@";;
  reset)     shift; cmd_reset "$@";;
  *) sed -n '3,21p' "$0"; exit 1;;
esac
