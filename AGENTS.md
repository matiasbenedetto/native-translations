# AGENTS.md — contributor guidelines

Shared conventions for anyone (human or agent) working in this repo. Machine-specific
local dev/deploy notes live in `AGENTS.local.md`.

## Branching & PRs

- Branch off `trunk`; never commit directly to `trunk`. PRs target `trunk`.
- Keep one logical change per PR.

## UI changes require before/after screenshots

**Any PR that changes something in the UI must include screenshots showing the UI
before and after the change.**

- Applies to any visible change: blocks, the settings page, the editor sidebar, list
  filters, language switcher, admin notices, styles/layout — anything a user sees.
- Include at least one "before" image (current state) and one "after" image (your
  change) for each affected view. Annotate or caption them so the difference is clear.
- If a change is purely backend/non-visual, note that explicitly instead.

### Screenshots MUST be uploaded so they render on github.com

**Never** reference local paths (`/tmp/foo.png`, `file:///…`) in a PR/issue/comment —
they are invisible to anyone browsing github.com and are therefore useless. Every
screenshot you mention must be an image that actually renders inline in the PR body,
an issue, or a comment.

This repo is **public**, so the reliable, CLI-friendly way to host images is the
dedicated **`pr-assets`** branch — an orphan branch that holds only images and is
**never merged into `trunk`** (keeps the plugin history clean). Images committed there
render inline via `raw.githubusercontent.com`.

One-time per machine: let git push over HTTPS using your `gh` token:

```bash
gh auth setup-git -h github.com
```

To publish screenshots for a PR (or issue), push them to `pr-assets` from a throwaway
clone so your working tree / current branch is untouched, then embed the raw URLs:

```bash
REPO=matiasbenedetto/wp-ai-translate
PR=123                                   # the PR number (or use issue-<N> for an issue)
TMP=$(mktemp -d)
git clone -q "https://github.com/$REPO.git" "$TMP/a" && cd "$TMP/a"
git fetch -q origin pr-assets && git checkout -q pr-assets   # branch already exists
mkdir -p "pr-$PR"
cp /tmp/before.png /tmp/after.png "pr-$PR"/                  # NEVER copy secrets/temp dumps
git add "pr-$PR"
git -c user.name="Matias Benedetto" -c user.email="matias.benedetto@gmail.com" \
    commit -q -m "screenshots for PR #$PR"
git push -q origin pr-assets
cd - >/dev/null && rm -rf "$TMP"
```

Then in the PR body/comment, embed with raw URLs (these render inline):

```markdown
**Before**
![before](https://raw.githubusercontent.com/matiasbenedetto/wp-ai-translate/pr-assets/pr-123/before.png)
**After**
![after](https://raw.githubusercontent.com/matiasbenedetto/wp-ai-translate/pr-assets/pr-123/after.png)
```

Verify each image is live before relying on it (expect `HTTP 200` + `image/png`):

```bash
curl -sI "https://raw.githubusercontent.com/matiasbenedetto/wp-ai-translate/pr-assets/pr-123/after.png" | head -1
```

Rules:
- The image **must visibly render** in the PR/issue/comment. A local path or a bare
  filename is not acceptable evidence — confirm it renders.
- **Never** upload secrets or temp artifacts (e.g. admin-password/hash dumps, auth
  cookies). Copy only the screenshot files by name; never `cp /tmp/*`.
- Do **not** commit screenshots to `trunk` or to your feature branch — use `pr-assets`.
- If GitHub's browser drag-and-drop `user-attachments` uploader is available to you,
  that works too; the `pr-assets` branch is the method that works headlessly from the
  CLI.
