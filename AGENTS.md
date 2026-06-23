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
- Put the images in the PR description. If a change is purely backend/non-visual, note
  that explicitly instead.
