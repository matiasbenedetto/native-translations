=== AI Translate ===
Contributors: matiasbenedetto
Tags: translation, multilingual, ai, language, localization
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage multilingual content and generate translations on demand using the WordPress core AI connectors.

== Description ==

AI Translate is a deliberately small multilingual plugin. It manages translations of
posts, pages, and the category/tag taxonomies, and generates them on demand using the
WordPress 7.0 core AI connectors. There are no custom tables, no rewrite rules, and no
locale switching: each translation is an ordinary post (or term) with its own permalink,
linked to its siblings through a shared translation group.

= What it does =

* **Per-item language** assigned from a sidebar panel (posts/pages) or a meta box
  (categories/tags).
* **On-demand translation.** A "Translate" action creates a draft in the target
  language; "Recreate" regenerates an existing translation in place (saving a revision
  first). Translation is always user-initiated — nothing runs automatically on publish.
* **Markup-safe output.** Block markup, HTML, shortcodes, URLs, and code are preserved;
  only human-readable text is translated. Output is validated (balanced block markup +
  author-scoped `wp_kses`) before anything is written to the database.
* **Customizable instructions.** Global, per-content-type, and per-language instruction
  fields shape tone and style; the non-overridable markup-safety rules are always applied.
* **Admin tooling.** A language column and language/untranslated filters on the post,
  page, category, and tag list tables, plus an Overview page listing missing translations
  and content by language.
* **Front-end blocks.** A *Translation Links* block (links to the current post's
  translations) and a site-wide *Language Switcher* block, both server-rendered and
  filtered to publicly-viewable targets.

= AI provider =

Translation requires a configured WordPress AI provider (the core AI connectors). The
model is selected through the connector's own preferences; if the connector's default
model is not available to your provider account, set a preferred model with the
`wpait_model_preference` filter. Temperature is omitted by default (some models reject
it) and can be supplied with the `wpait_temperature` filter.

== Installation ==

1. Upload the `wp-ai-translate` folder to `/wp-content/plugins/`, or install through the
   Plugins screen.
2. Activate the plugin.
3. Go to **Settings → AI Translate** and configure your languages, the default language,
   and (optionally) custom translation instructions.
4. Ensure an AI provider is configured for your site (required to generate translations).

== Frequently Asked Questions ==

= Does it change my URLs? =

No. There are no rewrite rules. Every translation is a normal post or term with its own
standard permalink, linked to its siblings only through a shared translation group.

= Are translations automatic? =

No. Translation is always user-initiated, per item, so AI usage and cost stay predictable.

= Can I delete a language? =

A language code is permanent once content uses it (the code is the taxonomy slug, so
renaming would orphan relationships). You can rename the display name, and you can
*disable* a language to hide it from new-translation targets and the switcher. A language
with content cannot be deleted until that content is reassigned or removed.

= Does it work on multisite? =

Version 1 is single-site only. Configuration is per-site; network admin is out of scope.

== Caveats ==

* **Page caching.** The front-end blocks are dynamic (rendered by a PHP `render_callback`),
  so they are not served by full-page caches the way static block output is. The
  group→members lookup is served from the object cache to keep the per-request cost low,
  but if you run a full-page cache, be aware these blocks re-render per request rather than
  being baked into the cached HTML.
* **Synchronous translation.** The AI call runs inside the request that triggers it, with a
  bounded timeout. Very long content is split into chunks on top-level block boundaries to
  stay within model limits.
* **Single-site only** in version 1 (see the FAQ).

== Changelog ==

= 0.1.0 =
* Initial release: language taxonomy + translation store; AI translation of posts and
  terms with markup-safe, validated output; editor sidebar panel and term meta box; REST
  endpoints with per-target capability checks; admin language column, filters, and Overview
  page for posts, pages, categories, and tags; front-end Translation Links and Language
  Switcher blocks; long-content chunking.
