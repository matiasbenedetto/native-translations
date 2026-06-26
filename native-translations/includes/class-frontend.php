<?php
/**
 * Front-end blocks + the shared resolver both of them call (plan §8 / S6 / S7).
 *
 * One resolver keeps the two dynamic blocks behaviourally identical: it is
 * context-safe (only trusts an explicit block `postId` context or a genuine
 * singular queried object — never guesses a post inside a query loop, template
 * part, archive, or editor preview) and viewability-filtered (front-end siblings
 * pass through `is_post_publicly_viewable()` via the store's `viewable` arg).
 *
 * @package WpNativeTranslations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the `post-links` and `language-switcher` blocks.
 */
class Wpnt_Frontend {

	/**
	 * Translation store.
	 *
	 * @var Wpnt_Translation_Store
	 */
	private Wpnt_Translation_Store $store;

	/**
	 * Languages handler.
	 *
	 * @var Wpnt_Languages
	 */
	private Wpnt_Languages $languages;

	/**
	 * Block definitions: handle + source dir, keyed by block name suffix.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $blocks = array(
		'post-links'        => array( 'handle' => 'wpnt-post-links-editor' ),
		'language-switcher' => array( 'handle' => 'wpnt-language-switcher-editor' ),
	);

	/**
	 * Constructor.
	 *
	 * @param Wpnt_Translation_Store $store     Translation store.
	 * @param Wpnt_Languages         $languages Languages handler.
	 */
	public function __construct( Wpnt_Translation_Store $store, Wpnt_Languages $languages ) {
		$this->store     = $store;
		$this->languages = $languages;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/* ---------------------------------------------------------------------
	 * Block registration
	 * ------------------------------------------------------------------- */

	/**
	 * Registers both dynamic blocks from their committed `block.json` metadata,
	 * wiring a PHP render callback to each. Editor scripts are registered first so
	 * the `editorScript` handle in block.json resolves.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		$callbacks = array(
			'post-links'        => array( $this, 'render_post_links' ),
			'language-switcher' => array( $this, 'render_language_switcher' ),
		);

		foreach ( $this->blocks as $name => $meta ) {
			$this->register_editor_script( $name, $meta['handle'] );

			$dir = WPNT_PLUGIN_DIR . 'blocks/' . $name;
			if ( ! file_exists( $dir . '/block.json' ) ) {
				continue;
			}
			register_block_type(
				$dir,
				array( 'render_callback' => $callbacks[ $name ] )
			);

			// Shared front-end stylesheet, enqueued only on pages that use the block
			// (both blocks render the same .wpnt-language-list markup). Without this
			// the list rendered as theme-default bullets at the entry-content size (#25).
			wp_enqueue_block_style(
				'native-translations/' . $name,
				array(
					'handle' => 'wpnt-frontend',
					'src'    => WPNT_PLUGIN_URL . 'assets/css/frontend.css',
					'path'   => WPNT_PLUGIN_DIR . 'assets/css/frontend.css',
					'ver'    => WPNT_VERSION,
				)
			);
		}
	}

	/**
	 * Registers a block's built editor script under its block.json handle, reading
	 * the wp-scripts-generated dependency/version manifest when present.
	 *
	 * @param string $name   Block name suffix (build dir).
	 * @param string $handle Script handle referenced by block.json `editorScript`.
	 * @return void
	 */
	private function register_editor_script( string $name, string $handle ): void {
		$asset_file = WPNT_PLUGIN_DIR . 'build/' . $name . '/index.asset.php';
		$script     = WPNT_PLUGIN_URL . 'build/' . $name . '/index.js';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_register_script(
			$handle,
			$script,
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( $handle, 'native-translations' );
	}

	/* ---------------------------------------------------------------------
	 * Shared resolver (S6 / S7)
	 * ------------------------------------------------------------------- */

	/**
	 * Resolves the category/tag whose translations the switcher should offer on a
	 * term archive, context-safely: only a genuine category/tag archive's queried
	 * term counts (never a term guessed from some other context). Returns 0
	 * elsewhere (home, search, post-type/date/author archives, singular).
	 *
	 * @return int Term id, or 0 when the current view is not a category/tag archive.
	 */
	public function resolve_term_id(): int {
		if ( ! is_category() && ! is_tag() ) {
			return 0;
		}
		$obj = get_queried_object();
		if ( $obj instanceof WP_Term && in_array( $obj->taxonomy, array( 'category', 'post_tag' ), true ) ) {
			return (int) $obj->term_id;
		}
		return 0;
	}

	/**
	 * Resolves the post whose translations a block should show, context-safely.
	 *
	 * Prefers an explicit block `postId` context (set inside query loops, template
	 * parts, and editor/server-side-render previews); falls back to the genuine
	 * singular queried object on the front end; otherwise 0. Never derives a post
	 * from a non-singular main query (S6).
	 *
	 * @param WP_Block|null $block Block instance, if available.
	 * @return int Post id, or 0 when there is no safe current post.
	 */
	public function resolve_post_id( $block = null ): int {
		if ( $block instanceof WP_Block && ! empty( $block->context['postId'] ) ) {
			return (int) $block->context['postId'];
		}
		if ( is_singular() ) {
			$obj = get_queried_object();
			if ( $obj instanceof WP_Post ) {
				return (int) $obj->ID;
			}
		}
		// Editor preview only: ServerSideRender passes the edited post id as a query
		// arg. Scoped to REST so it can never influence a front-end render (S6).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only editor preview.
			$pid = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
			if ( $pid > 0 ) {
				return $pid;
			}
		}
		return 0;
	}

	/**
	 * Language links for a singular post: one row per enabled language that
	 * resolves to a publicly-viewable target (the post itself for its own
	 * language; a viewable sibling otherwise). Languages with no viewable
	 * translation are omitted (locked decision §1 / S7).
	 *
	 * @param int  $post_id        Current post id.
	 * @param bool $include_current Include the post's own language row.
	 * @return array<int,array<string,mixed>> Rows of { code, name, native, flag, url, is_current }.
	 */
	public function links_for_post( int $post_id, bool $include_current ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$own      = $this->store->get_language( 'post', $post_id );
		$siblings = $this->store->get_translations( 'post', $post_id, array( 'viewable' => true ) );

		$rows = array();
		foreach ( $this->languages->enabled() as $lang ) {
			$code = (string) $lang['code'];

			if ( $code === $own ) {
				if ( ! $include_current ) {
					continue;
				}
				$url        = get_permalink( $post_id );
				$is_current = true;
			} elseif ( isset( $siblings[ $code ] ) ) {
				$url        = get_permalink( (int) $siblings[ $code ] );
				$is_current = false;
			} else {
				continue;
			}

			if ( ! $url ) {
				continue;
			}

			$rows[] = $this->row( $lang, $url, $is_current );
		}

		return $rows;
	}

	/**
	 * Language links for a category/tag archive: one row per enabled language that
	 * resolves to a real term archive (the term itself for its own language; a
	 * translated sibling term otherwise). Languages whose term has no translation
	 * are omitted, mirroring the singular behaviour (§1 / S7) — so the switcher only
	 * offers links that actually switch language in context, never dead home links.
	 *
	 * @param int  $term_id         Current term id.
	 * @param bool $include_current Include the term's own language row.
	 * @return array<int,array<string,mixed>> Rows of { code, name, native, flag, url, is_current }.
	 */
	public function links_for_term( int $term_id, bool $include_current ): array {
		if ( $term_id <= 0 ) {
			return array();
		}

		$own      = $this->store->get_language( 'term', $term_id );
		$siblings = $this->store->get_translations( 'term', $term_id );

		$rows = array();
		foreach ( $this->languages->enabled() as $lang ) {
			$code = (string) $lang['code'];

			if ( $code === $own ) {
				if ( ! $include_current ) {
					continue;
				}
				$url        = get_term_link( $term_id );
				$is_current = true;
			} elseif ( isset( $siblings[ $code ] ) ) {
				$url        = get_term_link( (int) $siblings[ $code ] );
				$is_current = false;
			} else {
				continue;
			}

			if ( is_wp_error( $url ) || ! $url ) {
				continue;
			}

			$rows[] = $this->row( $lang, (string) $url, $is_current );
		}

		return $rows;
	}

	/**
	 * Normalizes one configured-language row into a render row.
	 *
	 * @param array<string,mixed> $lang       Configured language row.
	 * @param string              $url        Target URL.
	 * @param bool                $is_current Whether this is the current language.
	 * @return array<string,mixed>
	 */
	private function row( array $lang, string $url, bool $is_current ): array {
		return array(
			'code'       => (string) $lang['code'],
			'name'       => (string) $lang['name'],
			'native'     => (string) ( $lang['native'] ?? '' ),
			'flag'       => (string) ( $lang['flag'] ?? '' ),
			'url'        => $url,
			'is_current' => $is_current,
		);
	}

	/* ---------------------------------------------------------------------
	 * Render callbacks
	 * ------------------------------------------------------------------- */

	/**
	 * Renders the `post-links` block: links to the current post's viewable
	 * translation siblings, one per language (plan §8.1).
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner content (unused, dynamic).
	 * @param WP_Block|null       $block      Block instance.
	 * @return string
	 */
	public function render_post_links( $attributes, $content = '', $block = null ): string {
		$post_id = $this->resolve_post_id( $block );
		if ( $post_id <= 0 ) {
			return '';
		}

		$show_current = ! empty( $attributes['showCurrent'] );
		$rows         = $this->links_for_post( $post_id, $show_current );

		// Nothing to link to (no viewable siblings) — render nothing rather than an
		// empty shell.
		$other = array_filter(
			$rows,
			static function ( $r ) {
				return empty( $r['is_current'] );
			}
		);
		if ( empty( $other ) && ! $show_current ) {
			return '';
		}

		$style = $this->display_style( $attributes );
		$items = array();
		foreach ( $rows as $r ) {
			$items[] = $this->render_item( $r, $style );
		}

		$wrapper = get_block_wrapper_attributes( array( 'class' => 'wpnt-post-links' ) );

		return sprintf(
			'<nav %1$s aria-label="%2$s"><ul class="wpnt-language-list">%3$s</ul></nav>',
			$wrapper,
			esc_attr__( 'Translations of this content', 'native-translations' ),
			implode( '', $items )
		);
	}

	/**
	 * Renders the `language-switcher` block: a list of enabled languages that
	 * switches to the current view's translation when one genuinely exists — the
	 * sibling post on a singular view, the translated term archive on a category/tag
	 * archive. On views with no per-content translation target (home, search,
	 * post-type/date/author archives) it renders nothing rather than a row of
	 * identical, non-switching home links (§8.2 revised — see #16).
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner content (unused, dynamic).
	 * @param WP_Block|null       $block      Block instance.
	 * @return string
	 */
	public function render_language_switcher( $attributes, $content = '', $block = null ): string {
		$show_current = ! isset( $attributes['showCurrent'] ) || ! empty( $attributes['showCurrent'] );

		$post_id = $this->resolve_post_id( $block );
		if ( $post_id > 0 ) {
			$rows = $this->links_for_post( $post_id, $show_current );
		} else {
			$term_id = $this->resolve_term_id();
			$rows    = $term_id > 0 ? $this->links_for_term( $term_id, $show_current ) : array();
		}

		// Nothing real to switch to (or only the current language with showCurrent
		// off) — render nothing instead of a switcher that cannot switch.
		$other = array_filter(
			$rows,
			static function ( $r ) {
				return empty( $r['is_current'] );
			}
		);
		if ( empty( $other ) ) {
			return '';
		}

		$style = $this->display_style( $attributes );
		$items = array();
		foreach ( $rows as $r ) {
			$items[] = $this->render_item( $r, $style );
		}

		$wrapper = get_block_wrapper_attributes( array( 'class' => 'wpnt-language-switcher' ) );

		return sprintf(
			'<nav %1$s aria-label="%2$s"><ul class="wpnt-language-list">%3$s</ul></nav>',
			$wrapper,
			esc_attr__( 'Language switcher', 'native-translations' ),
			implode( '', $items )
		);
	}

	/* ---------------------------------------------------------------------
	 * Render helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Normalizes the display-style attribute to one of flags|names|both.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function display_style( array $attributes ): string {
		$style = isset( $attributes['displayStyle'] ) ? (string) $attributes['displayStyle'] : 'both';
		return in_array( $style, array( 'flags', 'names', 'both' ), true ) ? $style : 'both';
	}

	/**
	 * Renders a single language list item.
	 *
	 * @param array<string,mixed> $row   Render row.
	 * @param string              $style flags|names|both.
	 * @return string
	 */
	private function render_item( array $row, string $style ): string {
		$flag = (string) $row['flag'];
		$name = (string) $row['name'];

		$show_flag = ( 'names' !== $style ) && '' !== $flag;
		// Show a visible name unless we are in flags-only mode and actually have a
		// flag to show in its place.
		$show_name = ( 'flags' !== $style ) || '' === $flag;

		$label_parts = array();
		if ( $show_flag ) {
			$label_parts[] = '<span class="wpnt-flag" aria-hidden="true">' . Wpnt_Languages::flag_html( $flag ) . '</span>';
		}
		if ( $show_name ) {
			$label_parts[] = '<span class="wpnt-name">' . esc_html( $name ) . '</span>';
		} else {
			// Flags-only: the flag is aria-hidden, so carry the language name as
			// screen-reader text — otherwise the link has an empty accessible name.
			$label_parts[] = '<span class="screen-reader-text">' . esc_html( $name ) . '</span>';
		}
		$label = implode( ' ', $label_parts );

		$li_class = 'wpnt-language-item' . ( ! empty( $row['is_current'] ) ? ' is-current' : '' );

		if ( ! empty( $row['is_current'] ) ) {
			return sprintf(
				'<li class="%1$s"><span aria-current="true">%2$s</span></li>',
				esc_attr( $li_class ),
				$label
			);
		}

		return sprintf(
			'<li class="%1$s"><a href="%2$s" lang="%3$s" hreflang="%3$s">%4$s</a></li>',
			esc_attr( $li_class ),
			esc_url( (string) $row['url'] ),
			esc_attr( (string) $row['code'] ),
			$label
		);
	}
}
