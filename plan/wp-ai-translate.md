# wp-ai-translate — Development Plan

> A deliberately small WordPress plugin that manages multilingual content and
> generates translations on demand using the WordPress 7.0 core AI connectors.

- **Slug / folder:** `wp-ai-translate`
- **Main file:** `wp-ai-translate.php`
- **Text domain:** `wp-ai-translate`
- **Display name:** AI Translate
- **Code prefix:** `wpait_` (functions), `Wpait_` (classes), `_wpait_` (meta keys)
- **Requires:** WordPress 7.0+ (for core AI connectors), PHP 8.1+

---

## 1. Design decisions (locked)

| Area | Decision |
|------|----------|
| **Data model** | Custom taxonomy `wpait_language` + shared group id in post meta `_wpait_group`. No custom tables. |
| **Front-end URLs** | No URL/rewrite changes. Each translation is a normal post with its own permalink, linked only via the translation group. |
| **Translation trigger** | Manual / on-demand only. A "Translate" action creates a draft; "Recreate" regenerates an existing one. No auto-translate on publish. |
| **Translatable content** | Posts, Pages, and the `category` + `post_tag` taxonomies. |
| **Initial language assignment** | Manual per-post (and per-term) via the editor sidebar. No bulk/AI detection in v1. |
| **Language switcher behaviour** | Only show languages that actually have a translation of the current item. |

### Why this stays simple
- Reuses core taxonomy + meta (no migrations, no upgrade routines).
- No rewrite rules, no locale switching, no `.mo` generation.
- AI usage is fully user-initiated → predictable cost and no queue/cron needed in v1.

---

## 2. Data model

### 2.1 Language taxonomy: `wpait_language`
- Non-hierarchical, **not public** (used for admin/query only, not pretty URLs).
- Registered against `post`, `page`, `category`, `post_tag` object types.
- Each term represents a configured site language. Term meta:
  - `_wpait_locale` — e.g. `en_US`, `es_ES`
  - `_wpait_code` — short code `en`, `es`
  - `_wpait_native_name` — e.g. `English`, `Español`
  - `_wpait_flag` (optional) — emoji or dashicon hint for the switcher
- Term **slug** = language code (`en`, `es`).

### 2.2 Translation group
- Every translatable object stores `_wpait_group` = a stable group id (a `wp_generate_uuid4()` value).
- All translations of the same source share one `_wpait_group`.
- For taxonomy terms the same pattern uses **term meta** `_wpait_group`.
- An original post is simply the first member of its group; there is no special
  "original" flag — any member can be the source for a new translation.

> **Querying:** "all Spanish posts" = tax_query on `wpait_language=es`.
> "siblings of post X" = posts where `_wpait_group = (X's group)` AND `ID != X`.

### 2.3 Helper layer (`includes/class-translation-store.php`)
Single class wrapping all reads/writes so the rest of the plugin never touches meta directly:
- `get_language( $object_id, $object_type )`
- `set_language( $object_id, $code )`
- `get_group( $object_id )` / `ensure_group( $object_id )`
- `get_translations( $object_id )` → `[ code => object_id ]`
- `link_translation( $source_id, $translation_id )`
- `unlink_translation( $translation_id )`

---

## 3. Settings (wp-admin)

**Location:** Settings → AI Translate (`options-general.php?page=wp-ai-translate`).

Stored as one option `wpait_settings`:
```php
[
  'languages' => [
    [ 'code' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English' ],
    [ 'code' => 'es', 'locale' => 'es_ES', 'name' => 'Spanish', 'native' => 'Español' ],
  ],
  'default_language' => 'en',
  'ai' => [ 'model' => '', 'temperature' => 0.2 ], // connector defaults if blank
  'instructions' => [
    'global' => '',          // base instructions applied to every translation
    'post'   => '',          // optional, appended for posts/pages
    'term'   => '',          // optional, appended for categories/tags
    'per_language' => [      // optional, keyed by target code, appended last
      'es' => '',            // e.g. "Use neutral Latin American Spanish, usted form."
    ],
  ],
]
```
- Add/remove languages (writes/cleans `wpait_language` terms accordingly).
- Pick the site default language.
- Optional AI connector preferences (model/temperature) — left blank uses core defaults.
- **Customizable translation instructions** (see §4.3): a "Translation instructions"
  settings section with a global textarea, optional per content-type textareas, and
  optional per-language overrides. Each field has a "Reset to default" link that
  restores the built-in baseline. Blank fields fall back to the plugin defaults.
- On save, reconcile taxonomy terms with the configured list (never hard-delete terms that still have content; warn instead).

---

## 4. AI connector integration (`includes/class-translator.php`)

> ⚠️ **Verify exact API at build time** against the shipped WP 7.0 AI client.
> Keep all calls behind this one class so the integration point is swappable.

Responsibilities:
- `translate_text( string $text, string $from, string $to, array $opts ) : string`
- `translate_post( int $source_id, string $to_code ) : int|WP_Error`
  1. Load source post (title, content, excerpt).
  2. Build a prompt by composing the **admin-customizable instructions** (§4.3)
     with the non-overridable markup-safety rules, so it **preserves block markup /
     HTML** and only translates human-readable text (keep `<!-- wp:* -->` comments,
     attributes, shortcodes, and URLs intact).
  3. Call the AI connector.
  4. Create a new draft post in `$to_code`, copy non-text fields (featured image,
     post_type, menu_order), assign `wpait_language` term, join `_wpait_group`.
  5. Translate & map taxonomy terms (see §4.1).
- `translate_term( int $term_id, string $to_code ) : int|WP_Error`
  - Translates name + description, creates sibling term, links via group.

### 4.1 Term mapping during post translation
- For each category/tag on the source post, look up its translation in `$to_code`.
- If a translated term exists → assign it.
- If not → either create it on the fly via `translate_term()` or leave the
  original term assigned (configurable; default = create on the fly).

### 4.2 Prompt safety
- Chunk very long content to stay within model limits (split on top-level blocks).
- System instruction emphasises: translate only, do not summarise, keep markup,
  keep proper nouns/code/URLs unchanged.

### 4.3 Customizable instructions
The system prompt is assembled in a fixed order so admins can shape tone/style
without being able to break the mechanics:

1. **Markup-safety rules** (hardcoded, always last in priority / non-overridable) —
   the "keep blocks, attributes, shortcodes, URLs, code intact; translate only" rules.
2. **Global instructions** — `wpait_settings['instructions']['global']`
   (defaults to a sensible baseline string shipped with the plugin).
3. **Content-type instructions** — `['post']` or `['term']` appended for the
   matching object type.
4. **Per-language instructions** — `['per_language'][$to_code]` appended last so a
   language-specific note (e.g. dialect, formality) wins.

- Available **placeholders** resolved before sending: `{source_lang}`,
  `{target_lang}`, `{site_name}`. Documented under the settings fields.
- All instruction fields are stored as plain text and sanitized; only users with
  `manage_options` can edit them.
- A single `Wpait_Translator::build_system_prompt( $type, $from, $to )` method owns
  this composition so both posts and terms use identical logic.
- Markup-safety rules are concatenated such that admin text cannot remove them
  (they are appended by code regardless of the custom fields).

---

## 5. Editor integration (Block Editor sidebar)

A small PluginDocumentSettingPanel ("Translations") added via `@wordpress/plugins`:
- **Language selector** for the current post (sets `wpait_language`).
- **Translations list**: each configured language shows status:
  - has translation → link to edit it + "Recreate" button
  - no translation → "Translate" button
- Buttons call REST endpoints (§7); results refresh the panel.
- Same panel pattern for the term edit screens (categories/tags) via a lighter
  meta box, since terms don't use the block editor.

---

## 6. Admin list views

### 6.1 Reuse core list tables (simplest)
- Add a **language filter dropdown** to `edit.php` (posts) and `edit.php?post_type=page`.
- Add a **language column** showing the post's language + small icons linking to siblings.
- Add a **"Untranslated" filter**: posts missing one or more configured languages
  (group has fewer members than configured language count, or no group at all).

### 6.2 Dedicated overview page (optional, still simple)
- Submenu under Settings → AI Translate → "Overview".
- Tabs / filters:
  - **Missing translations** — list items lacking ≥1 language, with quick "Translate" links.
  - **By language** — pick a language, list all its items.
- Built on `WP_List_Table` or a simple paginated query; no DataViews dependency required.

> Requirements covered: *list posts without translations* (6.1/6.2 missing filter),
> *list posts of a particular language* (6.1 dropdown / 6.2 by-language).

---

## 7. REST API (`includes/class-rest.php`)

Namespace `wp-ai-translate/v1`, all `permission_callback` = `current_user_can('edit_posts')` (and `edit_post` for the specific object):

| Method | Route | Purpose |
|--------|-------|---------|
| `POST` | `/translate` | `{ source_id, target_code, type:'post'\|'term' }` → create translation |
| `POST` | `/recreate` | `{ object_id }` → regenerate an existing translation in place |
| `GET`  | `/translations` | `{ object_id }` → sibling map for the editor panel |
| `POST` | `/set-language` | `{ object_id, code }` |

- `/recreate` overwrites title/content/excerpt of the existing translation
  (keeps its ID, status, slug, comments) rather than creating a new post —
  this is the "recreate manually" requirement.

---

## 8. Blocks (`src/` → built with `@wordpress/scripts`)

### 8.1 Translation links block (`wp-ai-translate/post-links`)
- Dynamic (server-rendered) block.
- On a singular view, resolves the current post's group and renders links to its
  siblings (one per language that has a translation). Hides languages with none.
- Attributes: display style (flags / names / both), show-current toggle.
- Use in single templates (theme) or inside post content.

### 8.2 Language switcher block (`wp-ai-translate/language-switcher`)
- Dynamic block, site-wide.
- Lists configured languages; switching navigates to the **translation of the
  current page** when one exists, otherwise to that language's latest content /
  home (configurable). On non-singular pages, links to home.
- Only renders languages that resolve to a target (hide missing per decision).
- Shared front-end resolver (`includes/class-frontend.php`) feeds both blocks.

> Both blocks register via `block.json` + `register_block_type()` with a PHP
> `render_callback`. No client-side state needed.

---

## 9. File structure

```
wp-ai-translate/
├── wp-ai-translate.php          # bootstrap, constants, autoload, hooks
├── readme.txt
├── includes/
│   ├── class-plugin.php         # singleton, wires everything
│   ├── class-languages.php      # taxonomy registration + settings reconcile
│   ├── class-translation-store.php
│   ├── class-translator.php     # AI connector calls
│   ├── class-rest.php
│   ├── class-admin-settings.php
│   ├── class-admin-list.php     # columns, filters, overview page
│   ├── class-editor.php         # enqueue sidebar, term meta box
│   └── class-frontend.php       # block render resolvers
├── src/
│   ├── editor-panel/            # JS sidebar panel
│   ├── post-links/              # block
│   └── language-switcher/       # block
├── build/                       # compiled assets (gitignored or shipped)
└── languages/                   # plugin's own i18n .pot
```

---

## 10. Build & tooling
- `@wordpress/scripts` for block/JS build (`npm run build`, `npm start`).
- `wp-env` for local testing against WP 7.0.
- PHP coding standards: WordPress-Extra (phpcs).
- No Composer deps required for v1.

---

## 11. Milestones

1. **Foundation** — plugin bootstrap, `wpait_language` taxonomy, settings page, translation-store helper.
2. **AI layer** — `class-translator`, customizable-instruction composition (§4.3) + markup-preserving prompt, post translation incl. term mapping; manual test against the AI connector.
3. **Editor UX** — sidebar panel, language selector, translate/recreate buttons, REST endpoints.
4. **Admin lists** — language column + filter, "untranslated" filter, optional overview page.
5. **Front-end blocks** — translation-links block, language-switcher block, frontend resolver.
6. **Terms** — category/tag translation UI + recreate.
7. **Polish** — i18n, capabilities, error handling, readme, chunking for long posts.

---

## 12. Open items to confirm during build
- Exact WP 7.0 AI connector API surface (method names, response shape, error handling).
- Long-content chunking strategy & token limits of the configured model.
- Whether to add a lightweight bulk "AI-detect language" tool later (explicitly deferred from v1).
- Slug uniqueness when an auto-created translation collides with an existing slug.
```
