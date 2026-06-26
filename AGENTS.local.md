# AGENTS.local.md — native-translations local dev & deploy workflow

> Local, machine-specific notes for **matias**'s setup. **Not committed.**
> Adapted from the `contact-sheet` theme's workflow for this **plugin** project.
> Documents how to make a change and test it visually on the local dev site.
> **Prod deploy is intentionally deferred — see §5.**

## The setup at a glance

| Thing | Value |
|-------|-------|
| Plugin repo (this folder) | `/home/matias/dev/native` |
| Plugin code subfolder | `native-translations/` (per plan §9) |
| Default git branch | `trunk` (PRs target `trunk`, not `main`) |
| Local dev site | `http://localhost/wp3/` (Apache) |
| Local WP path | `/var/www/html/wp3` |
| Local plugin install | symlink → this repo's `native-translations/`, so **edits are live instantly** |
| Local sudo | **NOT** passwordless — avoid `sudo` locally |
| Prod | **Not deploying the plugin to prod yet** (§5) |

Set up the symlink once (so saving a file updates the live dev site, no copy/build step):

```bash
ln -s /home/matias/dev/native/native-translations \
      /var/www/html/wp3/wp-content/plugins/native-translations
wp plugin activate native-translations --path=/var/www/html/wp3
```

> Note: the source theme's site uses HTTPS with a self-signed cert; this project's
> local site is plain **HTTP** (`http://localhost/wp3/`), so the cert flags below are
> only needed if your local WP actually serves HTTPS. Keep them if `https://localhost`
> is what resolves; drop them for plain HTTP.

---

## 1. Make the change

Edit the plugin files in `native-translations/`. The block JS source builds with
`@wordpress/scripts`:

- PHP: `native-translations/includes/*.php`
- Block sources: `native-translations/src/*/` → built into `native-translations/build/` by `npm run build`

After changing PHP that registers blocks/taxonomies, or anything cached, **flush WP
caches**:

```bash
wp transient delete --all --path=/var/www/html/wp3
wp cache flush --path=/var/www/html/wp3
```

(`wp` runs locally without sudo against `--path=/var/www/html/wp3`.)

Sanity checks before testing:

```bash
# PHP lints clean
find native-translations -name '*.php' -print0 | xargs -0 -n1 php -l

# block.json files are valid JSON
python3 -c "import json,glob; [json.load(open(f)) for f in glob.glob('native-translations/**/block.json', recursive=True)]; print('OK')"
```

---

## 2. Test it visually (local)

The Claude-in-Chrome extension is usually **not connected**, so drive a headless
browser with Playwright. Playwright is in `node_modules`, but its bundled Chromium is
**not** installed — point it at system Chrome.

Rules that make it work:
- Put the script **inside this repo** (so `import { chromium } from 'playwright'` resolves).
- `executablePath: '/usr/bin/google-chrome-stable'`
- If the local site serves HTTPS with a self-signed cert, add `--ignore-certificate-errors`
  + `ignoreHTTPSErrors: true`. For plain HTTP they're harmless to leave off.
- Element screenshots (`el.screenshot()` / `scrollIntoViewIfNeeded()`) are more reliable
  than `clip`.
- **Delete the temp script afterwards** — don't leave `*.mjs` in the repo.

Template script (`shot.mjs` in repo root):

```js
import { chromium } from 'playwright';
const url = process.argv[2], out = process.argv[3];
const browser = await chromium.launch({
  executablePath: '/usr/bin/google-chrome-stable',
  args: ['--ignore-certificate-errors'],
});
const ctx = await browser.newContext({
  ignoreHTTPSErrors: true,
  viewport: { width: 1100, height: 1000 },
  deviceScaleFactor: 2,
});
const page = await ctx.newPage();
await page.goto(url, { waitUntil: 'networkidle' });

// Measure to verify intent objectively (e.g. the language switcher block rendered):
const info = await page.evaluate(() => {
  const links = [...document.querySelectorAll('.wp-block-native-translations-language-switcher a')]
    .map(a => ({ text: a.textContent.trim(), href: a.getAttribute('href') }));
  return { switcherLinks: links };
});
console.log(JSON.stringify(info, null, 2));

const el = await page.$('.wp-block-native-translations-language-switcher');
if (el) { await el.scrollIntoViewIfNeeded(); await page.screenshot({ path: out }); }
await browser.close();
```

```bash
node ./shot.mjs "http://localhost/wp3/" /tmp/shot.png
rm -f shot.mjs            # clean up
```

Then view `/tmp/shot.png` (Read it). Prefer confirming with **measurements**
(present links, hrefs, computed styles) in addition to the image.

For admin-side features (settings page, editor sidebar, list filters), log in and
screenshot the relevant `wp-admin` URL the same way, e.g.
`http://localhost/wp3/wp-admin/options-general.php?page=native-translations`.

---

## 3. PR in the repo

```bash
git checkout -b my-change
git add -A
git commit -m "Short imperative summary

Optional body explaining the why."
git push -u origin my-change
gh pr create --base trunk --title "…" --body "…"
```

- Always branch off `trunk`; never commit directly to `trunk`.
- Keep one logical change per PR; iterative tweaks can be extra commits on the same
  branch (push updates the open PR).

---

## 4. Merge

```bash
gh pr merge <PR#> --merge --delete-branch
git checkout trunk && git pull --ff-only origin trunk
```

---

## 5. Deploy to prod — DEFERRED

**We are not deploying this plugin to production yet.** Do not install it on any prod
site. When that changes, the install pattern from the `contact-sheet` theme applies
(WP-CLI `wp plugin install <zip> --force` run as `www-data`, with a timestamped backup
and `wp cache flush`), but treat that as out of scope until explicitly requested.

---

## Gotchas learned the hard way (carried over from contact-sheet)

- **Local has no passwordless sudo** — `sudo find /…` etc. silently fail. Read files
  directly.
- Always confirm a package/zip actually contains your change before shipping.
- WordPress block markup must have balanced `<!-- wp:* -->` / `<!-- /wp:* -->` comments
  — relevant to this plugin's AI-translation output validation (plan §4.4).
