# wp-ai-translate dev harness

A disposable, real-environment test surface for the `wp-ai-translate` plugin — the
same idea as [Automattic/wp-skill](https://github.com/Automattic/wp-skill): spin up a
real WordPress site on [WordPress Playground](https://developer.wordpress.org/playground/)
(PHP-WASM + SQLite, no Docker, no MySQL), mount the plugin live, and tear the whole
thing down in one command. Built so any coding agent (or human) can stand up the exact
site it needs to reproduce or verify a change, take screenshots and screencasts, and
throw the environment away when it is done.

## What it gives you

- A real **WordPress 7.0** site (satisfies the plugin's `Requires at least: 7.0`
  guard) on PHP 8.3 with SQLite.
- The **default bundled theme** (`twentytwentyfive`) — no custom theme, by design.
- The plugin **mounted live** from `wp-ai-translate/`, so file edits take effect with
  no copy/build step (block build artifacts are committed under `wp-ai-translate/build/`).
- **Example content** seeded in two languages: US English (`en_US`) and Argentinean
  Spanish (`es_AR`) — posts, categories, and tags, with a couple of en/es pairs
  pre-linked as translation groups so the front-end blocks and admin lists render
  realistic data.
- **Screenshot** and **screencast** capture (Playwright + system Chrome).
- Everything in `harness/.state/` (and the site files in
  `~/.wordpress-playground/sites/<hash>/`) is **disposable**: `playground.sh reset`
  wipes it all and the next `up` builds a brand-new site.

## One-time setup

Requires Node 18+, a POSIX shell with `setsid` (Linux/WSL; macOS:
`brew install util-linux`), and a system Chrome/Chromium. The harness uses the
**system Chrome** (`/usr/bin/google-chrome-stable`) for screenshots/screencasts
because the repo's pinned Playwright build may not match a preinstalled browser
bundle. Screencasts additionally need the Playwright ffmpeg host:

```sh
npm install            # repo deps, incl. playwright (already pinned in package.json)
npx playwright install ffmpeg   # one-time, for cast.mjs video recording
```

## Bring up a test site

```sh
./harness/up.sh            # bootstrap + serve + activate plugin + seed content
```

When it finishes it prints the live URL, the admin URL, and the settings/overview
URLs. The server stays running so you can drive it. Stop it with
`harness/playground.sh stop`; start a completely fresh site with
`harness/playground.sh reset` then `./harness/up.sh` again.

Flags: `./harness/up.sh --no-seed` (plugin active on a clean WP, no example
content), `./harness/up.sh --reset` (wipe first).

## The verbs (`harness/playground.sh`)

| Verb | What it does |
|---|---|
| `bootstrap` | One-time: create a Playground site, record its dir in `.state/site-dir`. |
| `ensure [host:vfs …]` | Converge to a running server. The plugin is always mounted; pass extra mounts (e.g. a test theme) as `./my-theme:/wordpress/wp-content/themes/my-theme`. Safe to re-run blindly; restarts only if the mount set changed. |
| `wp -- <wp-cli args>` | Run wp-cli against the same site + mounts. `--allow-root --path=/wordpress` is injected for you. |
| `shot -- <args>` | Take a screenshot (see `shot.mjs`). |
| `cast -- <args>` | Record a screencast (see `cast.mjs`). |
| `status` | Print harness state + live URL. |
| `url [path]` | Print the live URL (optionally with a path appended). |
| `stop` | Stop the server and assert nothing survives. |
| `reset` | **Wipe** the site + state (disposable env). Next `bootstrap`/`up` is fresh. |

The live URL always comes from `harness/.state/server.port` — never read `siteurl`
from the Playground DB (it stores a junk ephemeral port). Frontend `curl` checks need
a cookie jar (Playground's one-time 302 sets a session cookie); the harness manages
that jar at `harness/.state/cookies` and feeds it to the browser tools.

## Screenshots

```sh
# Full-page home shot
./harness/playground.sh shot -- "/" /tmp/home.png

# An element only (CSS selector)
./harness/playground.sh shot -- "/wp-admin/edit.php" /tmp/list.png ".wp-list-table"

# A specific post
./harness/playground.sh shot -- "/?p=3" /tmp/post.png

# Admin page (Playground --login auto-authenticates the first visit)
./harness/playground.sh shot -- "/wp-admin/options-general.php?page=wp-ai-translate" /tmp/settings.png
```

`shot.mjs` loads the harness cookie jar into the browser so the admin session is
shared with the CLI. With a selector, it screenshots just that element (scrolled into
view); without one, a full-page shot.

## Screencasts

```sh
# Quick recording of a page (slow scroll sweep)
./harness/playground.sh cast -- "/" /tmp/home.webm

# Scripted interaction: a JSON array of steps
./harness/playground.sh cast -- "/wp-admin/edit.php" /tmp/translate.webm harness/steps-example.json
```

A steps file is a JSON array. Each step is one of `goto`, `click`, `fill`, `press`,
`select`, `wait`, `wait_for`, `scroll`, `screenshot`, `note` (see `cast.mjs` for the
shape). A 400ms settle follows each visible step so the recording reads naturally.
Videos are webm. (Requires the one-time `npx playwright install ffmpeg`.)

## Seeded content (cheat sheet)

Languages: `en` (🇺🇸 English, en_US, default) and `es` (🇦🇷 Español (Argentina),
es_AR). Per-language instruction on `es`: "Use Argentinean Spanish (rioplatense)…
prefer vos forms".

Posts (all published, block markup):
- **The Blue Garden** (en) ↔ **El jardín azul** (es) — linked translation group, both
  carry the `wp-ai-translate/post-links` block so the single view shows sibling links.
- **Pour-Over Coffee Basics** (en) — unlinked (only in English; good "untranslated"
  case for the admin lists/overview).
- **Cómo preparar mate** (es) — unlinked (only in Spanish).
- **A Weekend in Brooklyn** (en) ↔ **Fin de semana en Buenos Aires** (es) — linked group.

Categories (en ↔ es linked): Gardening ↔ Jardinería, Travel ↔ Viajes, Guides ↔ Guías.
Tags: coffee ↔ café (linked), brewing (en), mate (es), argentina (es), travel (en),
viajes (es).

> The es_AR posts are **authored directly**, not AI-generated, and linked by hand.
> Playground has no AI provider configured (`wp_supports_ai()` is false), so the
> translation *generation* flow cannot be exercised here without wiring a provider.
> Everything else — settings, admin lists, overview, editor sidebar, front-end blocks,
> REST endpoints, term UI — is fully testable. To test AI generation, point the plugin
> at a configured-AI host (e.g. your local Apache site per `AGENTS.local.md`) instead.

## Typical agent workflow

```sh
./harness/up.sh                                   # live site with seed data
./harness/playground.sh wp -- plugin list          # inspect state
./harness/playground.sh shot -- "/" /tmp/a.png     # capture before / evidence
# … make a change in wp-ai-translate/ (edits are live) …
./harness/playground.sh wp -- cache flush          # if needed
./harness/playground.sh shot -- "/" /tmp/b.png     # capture after
./harness/playground.sh cast -- "/wp-admin/options-general.php?page=wp-ai-translate" /tmp/settings.webm
./harness/playground.sh reset                      # throw it all away when done
```

## Playground vs a real host (be honest about these)

Playground runs WordPress on PHP-WASM with SQLite. Differences that matter here:
no real MySQL, no background cron daemon (cron fires on requests), no outbound mail,
a reduced PHP extension set, **no AI provider**, and slower execution. When something
fails locally, consider whether it is a Playground limitation before debugging the
plugin — and say so in any issue you file.
