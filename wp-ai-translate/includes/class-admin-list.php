<?php
/**
 * Admin list-table integration: language column, language/untranslated filter,
 * and an optional Overview page (plan §6).
 *
 * All reads go through the translation store (cached group→members map, C1) and
 * the configured-language list. The "untranslated" computation is a cross-join
 * per post, so it is admin-only, capped, and paginated (N6).
 *
 * @package WpAiTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the language column + filters to edit.php for posts/pages, and the
 * Overview page under the AI Translate top-level menu.
 */
class Wpait_Admin_List {

	/**
	 * Request var carrying the language filter value: a language code, or the
	 * special token `untranslated`.
	 */
	const QUERY_VAR = 'wpait_lang';

	/**
	 * Token used by the "Untranslated" filter option.
	 */
	const UNTRANSLATED = 'untranslated';

	/**
	 * Custom column id.
	 */
	const COLUMN = 'wpait_language';

	/**
	 * Taxonomies (terms) that carry a language via term meta.
	 *
	 * @var string[]
	 */
	const TERM_TAXONOMIES = array( 'category', 'post_tag' );

	/**
	 * Overview page slug.
	 */
	const OVERVIEW_SLUG = 'wp-ai-translate-overview';

	/**
	 * Hook suffix returned by add_submenu_page for the Overview page, used to
	 * gate asset enqueuing without hardcoding the (fragile) hook string.
	 *
	 * @var string
	 */
	private string $overview_hook = '';

	/**
	 * Meta flag marking an item as hidden from the Overview's curated lists.
	 */
	const HIDE_META = '_wpait_exclude_from_overview';

	/**
	 * Defensive scan bound for the untranslated cross-join (N6). Larger sites
	 * should lean on the by-language filter; this keeps the admin query bounded.
	 */
	const MAX_SCAN = 2000;

	/**
	 * Items per page on the Overview page.
	 */
	const PER_PAGE = 20;

	/**
	 * Whether the last untranslated scan hit MAX_SCAN (results may be incomplete).
	 *
	 * @var bool
	 */
	private bool $scan_truncated = false;

	/**
	 * Re-entrancy guard so the term-list `get_terms_args` filter never rewrites the
	 * internal `get_terms()` calls it makes while computing the untranslated set.
	 *
	 * @var bool
	 */
	private bool $in_term_filter = false;

	/**
	 * Translation store.
	 *
	 * @var Wpait_Translation_Store
	 */
	private Wpait_Translation_Store $store;

	/**
	 * Languages handler.
	 *
	 * @var Wpait_Languages
	 */
	private Wpait_Languages $languages;

	/**
	 * Constructor.
	 *
	 * @param Wpait_Translation_Store $store     Translation store.
	 * @param Wpait_Languages         $languages Languages handler.
	 */
	public function __construct( Wpait_Translation_Store $store, Wpait_Languages $languages ) {
		$this->store     = $store;
		$this->languages = $languages;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		foreach ( Wpait_Languages::OBJECT_TYPES as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		}

		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_action( 'admin_menu', array( $this, 'add_overview_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_list_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_overview_assets' ) );
		// Note: the /overview REST route is registered from Wpait_Plugin (unconditionally,
		// so it works in non-admin REST context), not here.

		// Terms (categories/tags): language column on edit-tags.php, plus a
		// language/untranslated filter applied through the term query (§6 for terms).
		foreach ( self::TERM_TAXONOMIES as $taxonomy ) {
			add_filter( "manage_edit-{$taxonomy}_columns", array( $this, 'add_term_column' ) );
			add_filter( "manage_{$taxonomy}_custom_column", array( $this, 'render_term_column' ), 10, 3 );
		}
		add_filter( 'get_terms_args', array( $this, 'filter_terms_query' ), 10, 2 );
		add_action( 'admin_footer-edit-tags.php', array( $this, 'render_term_filter' ) );
	}

	/* ---------------------------------------------------------------------
	 * Language column (§6.1)
	 * ------------------------------------------------------------------- */

	/**
	 * Inserts the Language column before the Date column.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out[ self::COLUMN ] = __( 'Language', 'wp-ai-translate' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Language', 'wp-ai-translate' );
		}
		return $out;
	}

	/**
	 * Renders the language cell: the post's own language plus small links to its
	 * sibling translations.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$post_id = (int) $post_id;

		$code = $this->store->get_language( 'post', $post_id );
		if ( '' === $code ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">'
				. esc_html__( 'No language', 'wp-ai-translate' ) . '</span>';
			return;
		}

		echo $this->language_column_html( 'post', $post_id, $code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- language_column_html() returns escaped HTML.
	}

	/* ---------------------------------------------------------------------
	 * Language / untranslated filter (§6.1)
	 * ------------------------------------------------------------------- */

	/**
	 * Renders the language filter dropdown above the post list.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public function render_filter( $post_type ): void {
		if ( ! in_array( $post_type, Wpait_Languages::OBJECT_TYPES, true ) ) {
			return;
		}

		$languages = $this->enabled_languages();
		if ( empty( $languages ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter navigation.
		$current = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		?>
		<label class="screen-reader-text" for="wpait-lang-filter"><?php esc_html_e( 'Filter by language', 'wp-ai-translate' ); ?></label>
		<select name="<?php echo esc_attr( self::QUERY_VAR ); ?>" id="wpait-lang-filter">
			<option value=""><?php esc_html_e( 'All languages', 'wp-ai-translate' ); ?></option>
			<option value="<?php echo esc_attr( self::UNTRANSLATED ); ?>" <?php selected( $current, self::UNTRANSLATED ); ?>>
				<?php esc_html_e( 'Untranslated', 'wp-ai-translate' ); ?>
			</option>
			<?php foreach ( $languages as $lang ) : ?>
				<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $current, $lang['code'] ); ?>>
					<?php echo esc_html( $lang['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Applies the language / untranslated filter to the main edit.php query.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public function filter_query( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base || ! in_array( $screen->post_type, Wpait_Languages::OBJECT_TYPES, true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter navigation.
		$value = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $value ) {
			return;
		}

		if ( self::UNTRANSLATED === $value ) {
			$ids = $this->untranslated_ids( $screen->post_type );
			// Force an empty result set when nothing is untranslated.
			$query->set( 'post__in', ! empty( $ids ) ? $ids : array( 0 ) );
			// Surface the same N6 truncation warning the Overview shows, so a
			// silently-capped filtered list on a large site is not mistaken for
			// "everything is translated".
			if ( $this->scan_truncated ) {
				add_action( 'admin_notices', array( $this, 'render_truncation_notice' ) );
			}
			return;
		}

		$tax_query   = (array) $query->get( 'tax_query' );
		$tax_query[] = array(
			'taxonomy' => Wpait_Languages::TAXONOMY,
			'field'    => 'slug',
			'terms'    => $value,
		);
		$query->set( 'tax_query', $tax_query );
	}

	/* ---------------------------------------------------------------------
	 * Term language column + filter (§6 for categories/tags)
	 * ------------------------------------------------------------------- */

	/**
	 * Inserts the Language column into a term list table, before the post-count
	 * "posts" column when present.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function add_term_column( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'posts' === $key ) {
				$out[ self::COLUMN ] = __( 'Language', 'wp-ai-translate' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Language', 'wp-ai-translate' );
		}
		return $out;
	}

	/**
	 * Renders the term language cell: the term's own language plus small links to
	 * its sibling translations. Term custom-column callbacks return their markup
	 * (unlike post columns, which echo).
	 *
	 * @param string $content Existing cell content.
	 * @param string $column  Column id.
	 * @param int    $term_id Term id.
	 * @return string
	 */
	public function render_term_column( $content, $column, $term_id ): string {
		if ( self::COLUMN !== $column ) {
			return $content;
		}
		$term_id = (int) $term_id;

		$code = $this->store->get_language( 'term', $term_id );
		if ( '' === $code ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">'
				. esc_html__( 'No language', 'wp-ai-translate' ) . '</span>';
		}

		return $this->language_column_html( 'term', $term_id, $code );
	}

	/**
	 * Applies the language / untranslated filter to the main term-list query on
	 * edit-tags.php. Scoped tightly: admin term-list screen only, the screen's own
	 * single-taxonomy query only, and guarded against re-entrancy so the internal
	 * untranslated scan (which queries both taxonomies) is never rewritten.
	 *
	 * @param array<string,mixed> $args       Term query args.
	 * @param array<int,string>   $taxonomies Queried taxonomies.
	 * @return array<string,mixed>
	 */
	public function filter_terms_query( $args, $taxonomies ) {
		if ( $this->in_term_filter || ! is_admin() ) {
			return $args;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-tags' !== $screen->base
			|| ! in_array( $screen->taxonomy, self::TERM_TAXONOMIES, true )
			|| array( $screen->taxonomy ) !== (array) $taxonomies ) {
			return $args;
		}

		// Only the main list-table query (and its pagination count) — never the
		// parent-category / Quick-Edit dropdowns on the same screen, which must list
		// every term regardless of the active filter. These cannot be told apart by
		// `name`: wp_dropdown_categories() unsets `name` before calling get_terms(),
		// so every term query on the screen arrives with WP_Term_Query's default
		// `name => ''`. The dropdown calls do, however, carry dropdown-only args that
		// wp_dropdown_categories() leaves in place (e.g. `value_field`), which the
		// list-table query never sets — use that to skip them.
		if ( isset( $args['value_field'] ) ) {
			return $args;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter navigation.
		$value = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $value ) {
			return $args;
		}

		$this->in_term_filter = true;

		if ( self::UNTRANSLATED === $value ) {
			$ids             = $this->untranslated_term_ids( $screen->taxonomy );
			$args['include'] = ! empty( $ids ) ? $ids : array( 0 );
			if ( $this->scan_truncated ) {
				add_action( 'admin_notices', array( $this, 'render_truncation_notice' ) );
			}
		} else {
			$meta_query   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
			$meta_query[] = array(
				'key'   => Wpait_Translation_Store::META_LANGUAGE,
				'value' => $value,
			);
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$this->in_term_filter = false;

		return $args;
	}

	/**
	 * Injects the language filter dropdown into the term-list tablenav via a small
	 * footer script (term list tables expose no server-side filter hook). Navigates
	 * by setting the {@see QUERY_VAR} parameter.
	 *
	 * @return void
	 */
	public function render_term_filter(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-tags' !== $screen->base || ! in_array( $screen->taxonomy, self::TERM_TAXONOMIES, true ) ) {
			return;
		}

		$languages = $this->enabled_languages();
		if ( empty( $languages ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter navigation.
		$current = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		$options = array(
			array( 'value' => '', 'label' => __( 'All languages', 'wp-ai-translate' ) ),
			array( 'value' => self::UNTRANSLATED, 'label' => __( 'Untranslated', 'wp-ai-translate' ) ),
		);
		foreach ( $languages as $lang ) {
			$options[] = array( 'value' => $lang['code'], 'label' => $lang['name'] );
		}

		$data = array(
			'queryVar' => self::QUERY_VAR,
			'current'  => $current,
			'label'    => __( 'Filter by language', 'wp-ai-translate' ),
			'options'  => $options,
		);
		?>
		<script>
		( function () {
			var cfg = <?php echo wp_json_encode( $data ); ?>;
			var nav = document.querySelector( '.tablenav.top .actions' );
			if ( ! nav || document.getElementById( 'wpait-term-lang-filter' ) ) {
				return;
			}
			var select = document.createElement( 'select' );
			select.id = 'wpait-term-lang-filter';
			select.setAttribute( 'aria-label', cfg.label );
			select.style.marginRight = '6px';
			cfg.options.forEach( function ( opt ) {
				var o = document.createElement( 'option' );
				o.value = opt.value;
				o.textContent = opt.label;
				if ( opt.value === cfg.current ) {
					o.selected = true;
				}
				select.appendChild( o );
			} );
			select.addEventListener( 'change', function () {
				var url = new URL( window.location.href );
				if ( select.value ) {
					url.searchParams.set( cfg.queryVar, select.value );
				} else {
					url.searchParams.delete( cfg.queryVar );
				}
				url.searchParams.delete( 'paged' );
				window.location.href = url.toString();
			} );
			nav.insertBefore( select, nav.firstChild );
		}() );
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Untranslated computation (N6 — admin-only, capped, paginated)
	 * ------------------------------------------------------------------- */

	/**
	 * Maps posts of a type that are missing at least one enabled language to the
	 * set of language codes they already cover: `[ post_id => present_codes[] ]`.
	 * Bounded by MAX_SCAN; sets {@see $scan_truncated} when the cap is reached so
	 * callers can warn that results may be incomplete (N6).
	 *
	 * @param string $post_type Post type.
	 * @return array<int,string[]>
	 */
	private function untranslated_map( string $post_type ): array {
		$enabled = wp_list_pluck( $this->enabled_languages(), 'code' );
		$count   = count( $enabled );

		// With one (or zero) enabled language there is nothing to translate into.
		if ( $count <= 1 ) {
			return array();
		}

		$candidates = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => self::MAX_SCAN,
				'no_found_rows'          => true,
				// present_codes() reads each post's language (object terms) and group
				// (post meta), so prime both caches here to avoid an N+1 per candidate.
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				'ignore_sticky_posts'    => true,
			)
		);

		if ( count( $candidates ) >= self::MAX_SCAN ) {
			$this->scan_truncated = true;
		}

		$out = array();
		foreach ( $candidates as $post_id ) {
			$post_id = (int) $post_id;
			$present = $this->present_codes( $post_id );
			if ( count( array_intersect( $present, $enabled ) ) < $count ) {
				$out[ $post_id ] = $present;
			}
		}

		return $out;
	}

	/**
	 * Ids of posts of a type missing at least one enabled language.
	 *
	 * @param string $post_type Post type.
	 * @return int[]
	 */
	private function untranslated_ids( string $post_type ): array {
		return array_map( 'intval', array_keys( $this->untranslated_map( $post_type ) ) );
	}

	/**
	 * The set of language codes a post already covers: its own assigned language
	 * plus every language present in its translation group. A post with a language
	 * but no group (no siblings yet) still counts that own language as present.
	 *
	 * @param int $post_id Post id.
	 * @return string[]
	 */
	private function present_codes( int $post_id ): array {
		$present = array();

		$own = $this->store->get_language( 'post', $post_id );
		if ( '' !== $own ) {
			$present[ $own ] = true;
		}

		if ( '' !== $this->store->get_group( 'post', $post_id ) ) {
			$members = $this->store->get_translations( 'post', $post_id, array( 'include_self' => true ) );
			foreach ( array_keys( $members ) as $code ) {
				$present[ $code ] = true;
			}
		}

		return array_keys( $present );
	}

	/**
	 * Maps terms of a taxonomy missing at least one enabled language to the set of
	 * language codes they already cover: `[ term_id => present_codes[] ]`. Bounded
	 * by MAX_SCAN; sets {@see $scan_truncated} when the cap is reached (N6).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<int,string[]>
	 */
	private function untranslated_term_map( string $taxonomy ): array {
		$enabled = wp_list_pluck( $this->enabled_languages(), 'code' );
		$count   = count( $enabled );
		if ( $count <= 1 ) {
			return array();
		}

		$this->in_term_filter = true;
		$candidates           = get_terms(
			array(
				'taxonomy'               => $taxonomy,
				'hide_empty'             => false,
				'fields'                 => 'ids',
				'number'                 => self::MAX_SCAN,
				'update_term_meta_cache' => true,
			)
		);
		$this->in_term_filter = false;

		if ( is_wp_error( $candidates ) ) {
			return array();
		}
		if ( count( $candidates ) >= self::MAX_SCAN ) {
			$this->scan_truncated = true;
		}

		$out = array();
		foreach ( $candidates as $term_id ) {
			$term_id = (int) $term_id;
			$present = $this->present_term_codes( $term_id );
			if ( count( array_intersect( $present, $enabled ) ) < $count ) {
				$out[ $term_id ] = $present;
			}
		}

		return $out;
	}

	/**
	 * Ids of terms of a taxonomy missing at least one enabled language.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private function untranslated_term_ids( string $taxonomy ): array {
		return array_map( 'intval', array_keys( $this->untranslated_term_map( $taxonomy ) ) );
	}

	/**
	 * The set of language codes a term already covers: its own assigned language
	 * plus every language present in its translation group.
	 *
	 * @param int $term_id Term id.
	 * @return string[]
	 */
	private function present_term_codes( int $term_id ): array {
		$present = array();

		$own = $this->store->get_language( 'term', $term_id );
		if ( '' !== $own ) {
			$present[ $own ] = true;
		}

		if ( '' !== $this->store->get_group( 'term', $term_id ) ) {
			$members = $this->store->get_translations( 'term', $term_id, array( 'include_self' => true ) );
			foreach ( array_keys( $members ) as $code ) {
				$present[ $code ] = true;
			}
		}

		return array_keys( $present );
	}

	/* ---------------------------------------------------------------------
	 * Missing / by-language data (shared by the REST endpoint, #54)
	 * ------------------------------------------------------------------- */

	/**
	 * Whether an item should be left out of the Overview's curated list: it has
	 * been hidden by the admin, or it is default WordPress content the admin almost
	 * certainly doesn't intend to translate (Sample Page / Privacy Policy / the
	 * default Uncategorized category) and has not been edited. Edited defaults are
	 * shown — once you touch it, it's "real" content.
	 *
	 * @param string $type        'post' | 'term'.
	 * @param int    $id          Object id.
	 * @param bool   $show_hidden When true, admin-hidden items are NOT skipped.
	 * @return bool
	 */
	private function skip_in_overview( string $type, int $id, bool $show_hidden ): bool {
		// "Show hidden / default items" reveals everything that is normally filtered.
		if ( $show_hidden ) {
			return false;
		}

		if ( '' !== (string) $this->get_overview_meta( $type, $id ) ) {
			return true;
		}

		$skip = $this->is_default_noise( $type, $id );

		/**
		 * Filters whether an item is omitted from the Overview's missing list.
		 *
		 * @param bool   $skip Whether to skip the item.
		 * @param string $type 'post' | 'term'.
		 * @param int    $id   Object id.
		 */
		return (bool) apply_filters( 'wpait_overview_skip', $skip, $type, $id );
	}

	/**
	 * Detects unedited default WordPress content (Sample Page, Privacy Policy page,
	 * the default category) so it doesn't dominate a fresh install's missing list.
	 *
	 * @param string $type 'post' | 'term'.
	 * @param int    $id   Object id.
	 * @return bool
	 */
	private function is_default_noise( string $type, int $id ): bool {
		if ( 'term' === $type ) {
			return $id === (int) get_option( 'default_category' );
		}

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return false;
		}

		$is_default_page = in_array( $post->post_name, array( 'sample-page', 'privacy-policy' ), true )
			|| $id === (int) get_option( 'wp_page_for_privacy_policy' );
		if ( ! $is_default_page ) {
			return false;
		}

		// "Unedited" = never modified after creation (WP stamps both equal on insert).
		return $post->post_modified_gmt === $post->post_date_gmt;
	}

	/**
	 * Reads the per-item "hidden from Overview" flag.
	 *
	 * @param string $type 'post' | 'term'.
	 * @param int    $id   Object id.
	 * @return string '1' when hidden, '' otherwise.
	 */
	private function get_overview_meta( string $type, int $id ): string {
		$value = 'term' === $type
			? get_term_meta( $id, self::HIDE_META, true )
			: get_post_meta( $id, self::HIDE_META, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Sets or clears the per-item "hidden from Overview" flag. Used by the Overview's
	 * Hide / Unhide actions (REST → {@see Wpait_Rest}).
	 *
	 * @param string $type   'post' | 'term'.
	 * @param int    $id     Object id.
	 * @param bool   $hidden Whether to hide the item.
	 * @return void
	 */
	public function set_overview_hidden( string $type, int $id, bool $hidden ): void {
		if ( 'term' === $type ) {
			if ( $hidden ) {
				update_term_meta( $id, self::HIDE_META, '1' );
			} else {
				delete_term_meta( $id, self::HIDE_META );
			}
			return;
		}
		if ( $hidden ) {
			update_post_meta( $id, self::HIDE_META, '1' );
		} else {
			delete_post_meta( $id, self::HIDE_META );
		}
	}

	/**
	 * Counts published-or-any content (posts/pages + categories/tags) assigned to a
	 * language, for the by-language tab badge.
	 *
	 * @param string $code Language code.
	 * @return int
	 */
	private function count_in_language( string $code ): int {
		$query = new WP_Query(
			array(
				'post_type'      => Wpait_Languages::OBJECT_TYPES,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'ignore_sticky_posts' => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => Wpait_Languages::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $code,
					),
				),
			)
		);
		$posts = (int) $query->found_posts;

		$this->in_term_filter = true;
		$terms                = get_terms(
			array(
				'taxonomy'   => self::TERM_TAXONOMIES,
				'hide_empty' => false,
				'fields'     => 'count',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Wpait_Translation_Store::META_LANGUAGE,
						'value' => $code,
					),
				),
			)
		);
		$this->in_term_filter = false;

		return $posts + ( is_wp_error( $terms ) ? 0 : (int) $terms );
	}

	/* ---------------------------------------------------------------------
	 * Overview page (§6.2 — DataViews React app, #54)
	 * ------------------------------------------------------------------- */

	/**
	 * Registers the Overview REST route. Lives here (rather than in {@see Wpait_Rest})
	 * because this class already owns the cross post+term data computation; the route
	 * is a thin read wrapper over {@see get_overview_payload()}.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			Wpait_Rest::NS,
			'/overview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_overview' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'view'        => array( 'type' => 'string', 'default' => 'originals', 'sanitize_callback' => 'sanitize_key' ),
					'page'        => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page'    => array( 'type' => 'integer', 'default' => self::PER_PAGE, 'sanitize_callback' => 'absint' ),
					'orderby'     => array( 'type' => 'string', 'default' => 'title', 'enum' => array( 'title', 'type' ), 'sanitize_callback' => 'sanitize_key' ),
					'order'       => array( 'type' => 'string', 'default' => 'asc', 'enum' => array( 'asc', 'desc' ), 'sanitize_callback' => 'sanitize_key' ),
					'search'      => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'show_hidden' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
	}

	/**
	 * `GET /overview` — paginated, sortable, searchable Overview data for the
	 * DataViews app. Admin-only (gated in the route's permission callback).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_overview( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'view'        => (string) $request->get_param( 'view' ),
			'page'        => max( 1, (int) $request->get_param( 'page' ) ),
			'per_page'    => max( 1, (int) $request->get_param( 'per_page' ) ),
			'orderby'     => (string) $request->get_param( 'orderby' ),
			'order'       => (string) $request->get_param( 'order' ),
			'search'      => (string) $request->get_param( 'search' ),
			'show_hidden' => (bool) $request->get_param( 'show_hidden' ),
		);

		return rest_ensure_response( $this->get_overview_payload( $args ) );
	}

	/**
	 * Builds the full Overview REST payload: the requested page of rows plus the
	 * metadata the DataViews app needs (languages, counts, AI status, truncation).
	 *
	 * @param array<string,mixed> $args view|page|per_page|orderby|order|search|show_hidden.
	 * @return array<string,mixed>
	 */
	public function get_overview_payload( array $args ): array {
		$languages = $this->enabled_languages();
		$codes     = wp_list_pluck( $languages, 'code' );

		$view        = (string) ( $args['view'] ?? 'originals' );
		$page        = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page    = max( 1, (int) ( $args['per_page'] ?? self::PER_PAGE ) );
		$orderby     = in_array( ( $args['orderby'] ?? 'title' ), array( 'title', 'type' ), true ) ? $args['orderby'] : 'title';
		$order       = 'desc' === ( $args['order'] ?? 'asc' ) ? 'desc' : 'asc';
		$search      = trim( (string) ( $args['search'] ?? '' ) );
		$show_hidden = ! empty( $args['show_hidden'] );
		$is_lang     = in_array( $view, $codes, true );
		$is_unmarked = 'unmarked' === $view;

		$is_originals = ! $is_lang && ! $is_unmarked;

		$this->scan_truncated = false;

		// Build the full row set for the requested view, then sort/search/paginate
		// it in PHP (admin-only + bounded by MAX_SCAN per N6).
		if ( $is_lang ) {
			$rows = $this->language_overview_rows( $view, $languages );
		} elseif ( $is_unmarked ) {
			$rows = $this->unmarked_overview_rows();
		} else {
			// Default view: "Originals" (#92) — items with a language set AND
			// explicitly marked as original.
			$rows = $this->originals_overview_rows( $codes, $languages, $show_hidden );
		}

		// The active view's full (pre-search) row set is exactly the source the
		// matching tab count needs, so capture its size now and reuse it below
		// instead of rescanning the same items a second time.
		$active_view_total = count( $rows );

		// Search filter (title match, case-insensitive).
		if ( '' !== $search ) {
			$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $needle ) {
						$hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $row['title'] ) : strtolower( $row['title'] );
						return false !== strpos( $hay, $needle );
					}
				)
			);
		}

		// Sort.
		usort(
			$rows,
			static function ( $a, $b ) use ( $orderby, $order ) {
				$key = 'type' === $orderby ? 'type_label' : 'title';
				$cmp = strcasecmp( (string) $a[ $key ], (string) $b[ $key ] );
				if ( 0 === $cmp && 'type' === $orderby ) {
					$cmp = strcasecmp( (string) $a['title'], (string) $b['title'] );
				}
				return 'desc' === $order ? -$cmp : $cmp;
			}
		);

		$total       = count( $rows );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( $page, $total_pages );
		$page_rows   = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

		$by_language = array();
		foreach ( $codes as $code ) {
			$by_language[ $code ] = $this->count_in_language( $code );
		}

		return array(
			'rows'          => array_values( $page_rows ),
			'total'         => $total,
			'total_pages'   => $total_pages,
			'languages'     => array_map(
				static function ( $lang ) {
					return array( 'code' => $lang['code'], 'name' => $lang['name'] );
				},
				$languages
			),
			'ai_ok'         => Wpait_Translator::can_generate_text(),
			'scan_truncated' => $this->scan_truncated,
			'counts'        => array(
				'originals'   => $is_originals ? $active_view_total : count( $this->originals_overview_rows( $codes, $languages, $show_hidden ) ),
				'unmarked'    => $is_unmarked ? $active_view_total : count( $this->unmarked_overview_rows() ),
				'by_language' => $by_language,
			),
		);
	}

	/**
	 * Builds the "Originals" rows (#92): posts/pages and categories/tags that have a
	 * language set AND have been explicitly marked as original — the items the site
	 * translates *from*. Unlike the old "missing translations" view, this is driven
	 * by the explicit {@see Wpait_Translation_Store::META_IS_ORIGINAL} marker rather
	 * than inferred from the default language, and a fully-translated original is
	 * still listed (its `missing` chips are simply empty). Bounded by MAX_SCAN (N6);
	 * admin-hidden / default-noise items are filtered unless `$show_hidden`.
	 *
	 * @param string[]                       $codes       Enabled language codes.
	 * @param array<int,array<string,mixed>> $languages   Enabled language rows.
	 * @param bool                           $show_hidden Include admin-hidden items.
	 * @return array<int,array<string,mixed>>
	 */
	private function originals_overview_rows( array $codes, array $languages, bool $show_hidden ): array {
		$name_by_code = wp_list_pluck( $languages, 'name', 'code' );
		$out          = array();

		foreach ( Wpait_Languages::OBJECT_TYPES as $post_type ) {
			$ids = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'any',
					'fields'                 => 'ids',
					'posts_per_page'         => self::MAX_SCAN,
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
					'ignore_sticky_posts'    => true,
				)
			);
			if ( count( $ids ) >= self::MAX_SCAN ) {
				$this->scan_truncated = true;
			}
			foreach ( $ids as $post_id ) {
				$post_id = (int) $post_id;
				$own     = $this->store->get_language( 'post', $post_id );
				if ( '' === $own || ! $this->store->is_original( 'post', $post_id ) ) {
					continue;
				}
				if ( $this->skip_in_overview( 'post', $post_id, $show_hidden ) ) {
					continue;
				}
				$post     = get_post( $post_id );
				$view_url = ( $post instanceof WP_Post && 'publish' === $post->post_status ) ? (string) get_permalink( $post_id ) : (string) get_preview_post_link( $post_id );
				$out[]    = array(
					'key'         => 'post:' . $post_id,
					'type'        => 'post',
					'id'          => $post_id,
					'title'       => get_the_title( $post_id ) ?: __( '(no title)', 'wp-ai-translate' ),
					'type_label'  => $this->type_label( 'post', '', $post_id ),
					'language'    => array( 'code' => $own, 'name' => $this->languages->name( $own ) ),
					'missing'     => $this->missing_chips_for( 'post', $post_id, $codes, $name_by_code ),
					'edit_url'    => (string) get_edit_post_link( $post_id, 'raw' ),
					'view_url'    => $view_url,
					'thumbnail'   => $this->post_thumbnail_url( $post_id ),
					'snippet'     => $this->post_snippet( $post_id ),
					'hidden'      => '' !== $this->get_overview_meta( 'post', $post_id ),
					'is_original' => true,
					'taxonomy'    => '',
				);
			}
		}

		$this->in_term_filter = true;
		$terms                = get_terms(
			array(
				'taxonomy'               => self::TERM_TAXONOMIES,
				'hide_empty'             => false,
				'number'                 => self::MAX_SCAN,
				'update_term_meta_cache' => true,
			)
		);
		$this->in_term_filter = false;
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		if ( count( $terms ) >= self::MAX_SCAN ) {
			$this->scan_truncated = true;
		}
		foreach ( $terms as $term ) {
			$term_id = (int) ( is_object( $term ) ? $term->term_id : $term );
			$own     = $this->store->get_language( 'term', $term_id );
			if ( '' === $own || ! $this->store->is_original( 'term', $term_id ) ) {
				continue;
			}
			if ( $this->skip_in_overview( 'term', $term_id, $show_hidden ) ) {
				continue;
			}
			$term_obj = get_term( $term_id );
			if ( ! $term_obj instanceof WP_Term ) {
				continue;
			}
			$link  = get_term_link( $term_id );
			$out[] = array(
				'key'         => 'term:' . $term_id,
				'type'        => 'term',
				'id'          => $term_id,
				'title'       => $term_obj->name,
				'type_label'  => $this->type_label( 'term', $term_obj->taxonomy, $term_id ),
				'language'    => array( 'code' => $own, 'name' => $this->languages->name( $own ) ),
				'missing'     => $this->missing_chips_for( 'term', $term_id, $codes, $name_by_code ),
				'edit_url'    => (string) get_edit_term_link( $term_id ),
				'view_url'    => is_wp_error( $link ) ? '' : (string) $link,
				'thumbnail'   => null,
				'snippet'     => $this->term_snippet( $term_obj ),
				'hidden'      => '' !== $this->get_overview_meta( 'term', $term_id ),
				'is_original' => true,
				'taxonomy'    => $term_obj->taxonomy,
			);
		}

		return $out;
	}

	/**
	 * Builds the `[{code,name}]` "missing" chips for an item: the enabled languages
	 * the item's translation group does not yet cover.
	 *
	 * @param string                $type         'post' | 'term'.
	 * @param int                   $id           Object id.
	 * @param string[]              $codes        Enabled language codes.
	 * @param array<string,string>  $name_by_code Map of code → display name.
	 * @return array<int,array<string,string>>
	 */
	private function missing_chips_for( string $type, int $id, array $codes, array $name_by_code ): array {
		$present = 'term' === $type ? $this->present_term_codes( $id ) : $this->present_codes( $id );
		$missing = array();
		foreach ( array_diff( $codes, $present ) as $miss_code ) {
			$missing[] = array(
				'code' => $miss_code,
				'name' => isset( $name_by_code[ $miss_code ] ) ? (string) $name_by_code[ $miss_code ] : $miss_code,
			);
		}
		return array_values( $missing );
	}

	/**
	 * Builds the by-language rows (posts + terms assigned to a language) shaped for
	 * the REST/DataViews payload.
	 *
	 * @param string                         $code      Language code.
	 * @param array<int,array<string,mixed>> $languages Enabled language rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function language_overview_rows( string $code, array $languages ): array {
		$out  = array();
		$name = $this->languages->name( $code );

		$query = new WP_Query(
			array(
				'post_type'           => Wpait_Languages::OBJECT_TYPES,
				'post_status'         => 'any',
				'posts_per_page'      => self::MAX_SCAN,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => Wpait_Languages::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $code,
					),
				),
			)
		);
		foreach ( (array) $query->posts as $post_id ) {
			$post_id  = (int) $post_id;
			$post     = get_post( $post_id );
			$view_url = ( $post instanceof WP_Post && 'publish' === $post->post_status ) ? (string) get_permalink( $post_id ) : (string) get_preview_post_link( $post_id );
			$out[]    = array(
				'key'        => 'post:' . $post_id,
				'type'       => 'post',
				'id'         => $post_id,
				'title'      => get_the_title( $post_id ) ?: __( '(no title)', 'wp-ai-translate' ),
				'type_label' => $this->type_label( 'post', '', $post_id ),
				'language'   => array( 'code' => $code, 'name' => $name ),
				'missing'    => array(),
				'edit_url'   => (string) get_edit_post_link( $post_id, 'raw' ),
				'view_url'   => $view_url,
				'thumbnail'  => $this->post_thumbnail_url( $post_id ),
				'snippet'    => $this->post_snippet( $post_id ),
				'hidden'     => false,
				'is_original' => $this->store->is_original( 'post', $post_id ),
				'taxonomy'   => '',
			);
		}
		wp_reset_postdata();

		$this->in_term_filter = true;
		$terms                = get_terms(
			array(
				'taxonomy'   => self::TERM_TAXONOMIES,
				'hide_empty' => false,
				'number'     => self::MAX_SCAN,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Wpait_Translation_Store::META_LANGUAGE,
						'value' => $code,
					),
				),
			)
		);
		$this->in_term_filter = false;
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		foreach ( $terms as $term ) {
			$link  = get_term_link( $term->term_id );
			$out[] = array(
				'key'        => 'term:' . (int) $term->term_id,
				'type'       => 'term',
				'id'         => (int) $term->term_id,
				'title'      => $term->name,
				'type_label' => $this->type_label( 'term', $term->taxonomy, (int) $term->term_id ),
				'language'   => array( 'code' => $code, 'name' => $name ),
				'missing'    => array(),
				'edit_url'   => (string) get_edit_term_link( $term->term_id ),
				'view_url'   => is_wp_error( $link ) ? '' : (string) $link,
				'thumbnail'  => null,
				'snippet'    => $this->term_snippet( $term ),
				'hidden'     => false,
				'is_original' => $this->store->is_original( 'term', (int) $term->term_id ),
				'taxonomy'   => $term->taxonomy,
			);
		}

		return $out;
	}

	/**
	 * Builds the "Unmarked" rows (#56): posts/pages and categories/tags with NO
	 * language assigned at all, shaped for the REST/DataViews payload. These are the
	 * items the manual bulk-set and AI-detection actions target. Bounded by MAX_SCAN
	 * (N6); `language` is always null here by definition.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function unmarked_overview_rows(): array {
		$out = array();

		foreach ( Wpait_Languages::OBJECT_TYPES as $post_type ) {
			$ids = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'any',
					'fields'                 => 'ids',
					'posts_per_page'         => self::MAX_SCAN,
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
					'ignore_sticky_posts'    => true,
				)
			);
			if ( count( $ids ) >= self::MAX_SCAN ) {
				$this->scan_truncated = true;
			}
			foreach ( $ids as $post_id ) {
				$post_id = (int) $post_id;
				if ( '' !== $this->store->get_language( 'post', $post_id ) ) {
					continue;
				}
				$post     = get_post( $post_id );
				$view_url = ( $post instanceof WP_Post && 'publish' === $post->post_status ) ? (string) get_permalink( $post_id ) : (string) get_preview_post_link( $post_id );
				$out[]    = array(
					'key'        => 'post:' . $post_id,
					'type'       => 'post',
					'id'         => $post_id,
					'title'      => get_the_title( $post_id ) ?: __( '(no title)', 'wp-ai-translate' ),
					'type_label' => $this->type_label( 'post', '', $post_id ),
					'language'   => null,
					'missing'    => array(),
					'edit_url'   => (string) get_edit_post_link( $post_id, 'raw' ),
					'view_url'   => $view_url,
					'thumbnail'  => $this->post_thumbnail_url( $post_id ),
					'snippet'    => $this->post_snippet( $post_id ),
					'hidden'     => false,
					'is_original' => false,
					'taxonomy'   => '',
				);
			}
		}

		$this->in_term_filter = true;
		$terms                = get_terms(
			array(
				'taxonomy'               => self::TERM_TAXONOMIES,
				'hide_empty'             => false,
				'number'                 => self::MAX_SCAN,
				'update_term_meta_cache' => true,
			)
		);
		$this->in_term_filter = false;
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		if ( count( $terms ) >= self::MAX_SCAN ) {
			$this->scan_truncated = true;
		}
		foreach ( $terms as $term ) {
			$term_id = (int) ( is_object( $term ) ? $term->term_id : $term );
			if ( '' !== $this->store->get_language( 'term', $term_id ) ) {
				continue;
			}
			$term_obj = get_term( $term_id );
			if ( ! $term_obj instanceof WP_Term ) {
				continue;
			}
			$link  = get_term_link( $term_id );
			$out[] = array(
				'key'        => 'term:' . $term_id,
				'type'       => 'term',
				'id'         => $term_id,
				'title'      => $term_obj->name,
				'type_label' => $this->type_label( 'term', $term_obj->taxonomy, $term_id ),
				'language'   => null,
				'missing'    => array(),
				'edit_url'   => (string) get_edit_term_link( $term_id ),
				'view_url'   => is_wp_error( $link ) ? '' : (string) $link,
				'thumbnail'  => null,
				'snippet'    => $this->term_snippet( $term_obj ),
				'hidden'     => false,
				'is_original' => false,
				'taxonomy'   => $term_obj->taxonomy,
			);
		}

		return $out;
	}

	/**
	 * Words kept in the content-derived snippet fallback. Small enough that the
	 * 1–2 line clamp in the Overview rarely shows a hard mid-word cut.
	 */
	const SNIPPET_WORDS = 30;

	/**
	 * A short plain-text snippet for a post/page row: its excerpt when set,
	 * otherwise a trimmed plain-text snippet derived from the content (blocks /
	 * HTML / shortcodes stripped, truncated to {@see SNIPPET_WORDS} words). Empty
	 * when neither yields text.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private function post_snippet( int $post_id ): string {
		$excerpt = trim( (string) get_the_excerpt( $post_id ) );
		if ( '' !== $excerpt ) {
			return $this->normalize_snippet( $excerpt );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		return $this->normalize_snippet( wp_trim_words( $text, self::SNIPPET_WORDS, '…' ) );
	}

	/**
	 * Normalises a snippet for display as a plain-text node: decodes HTML entities
	 * (so e.g. a texturized excerpt's `&#8217;` renders as a real apostrophe rather
	 * than literal entity text) and collapses runs of whitespace to single spaces.
	 *
	 * @param string $text Raw snippet.
	 * @return string
	 */
	private function normalize_snippet( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * A short plain-text snippet for a term (category/tag) row: its description,
	 * stripped of HTML and trimmed. Empty when the term has no description.
	 *
	 * @param WP_Term $term Term object.
	 * @return string
	 */
	private function term_snippet( WP_Term $term ): string {
		$text = wp_strip_all_tags( (string) $term->description );
		$text = trim( $text );
		return '' === $text ? '' : $this->normalize_snippet( $text );
	}

	/**
	 * Featured-image thumbnail URL for a post, or null when the post has no
	 * featured image. Terms never have a featured image, so callers pass null
	 * directly. Uses the WordPress 'thumbnail' image size, which the Overview
	 * DataView renders as a fixed-size square.
	 *
	 * @param int $post_id Post id.
	 * @return string|null Thumbnail URL, or null when there is no featured image.
	 */
	private function post_thumbnail_url( int $post_id ): ?string {
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumb_id ) {
			return null;
		}
		$src = wp_get_attachment_image_src( (int) $thumb_id, 'thumbnail' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return null;
		}
		return (string) $src[0];
	}

	/**
	 * Human label for an item's type: Post / Page / Category / Tag (or the raw
	 * post-type / taxonomy for anything unexpected).
	 *
	 * @param string $type     'post' | 'term'.
	 * @param string $taxonomy Taxonomy slug for terms.
	 * @param int    $id       Object id (used to resolve a post's exact type).
	 * @return string
	 */
	private function type_label( string $type, string $taxonomy, int $id ): string {
		if ( 'term' === $type ) {
			if ( 'category' === $taxonomy ) {
				return __( 'Category', 'wp-ai-translate' );
			}
			if ( 'post_tag' === $taxonomy ) {
				return __( 'Tag', 'wp-ai-translate' );
			}
			return $taxonomy;
		}

		$post_type = get_post_type( $id );
		if ( 'page' === $post_type ) {
			return __( 'Page', 'wp-ai-translate' );
		}
		if ( 'post' === $post_type ) {
			return __( 'Post', 'wp-ai-translate' );
		}
		return (string) $post_type;
	}

	/**
	 * Registers the Overview submenu under the AI Translate top-level menu.
	 *
	 * @return void
	 */
	public function add_overview_page(): void {
		$this->overview_hook = (string) add_submenu_page(
			Wpait_Admin_Settings::PAGE_SLUG,
			__( 'AI Translate Overview', 'wp-ai-translate' ),
			__( 'Overview', 'wp-ai-translate' ),
			'manage_options',
			self::OVERVIEW_SLUG,
			array( $this, 'render_overview' )
		);
	}

	/**
	 * Enqueues list-table styles for the post/page/category/tag language columns.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_list_assets( $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$is_post_list = 'edit.php' === $hook_suffix
			&& 'edit' === $screen->base
			&& in_array( $screen->post_type, Wpait_Languages::OBJECT_TYPES, true );
		$is_term_list = 'edit-tags.php' === $hook_suffix
			&& 'edit-tags' === $screen->base
			&& in_array( $screen->taxonomy, self::TERM_TAXONOMIES, true );

		if ( ! $is_post_list && ! $is_term_list ) {
			return;
		}

		wp_enqueue_style(
			'wpait-admin-list',
			WPAIT_PLUGIN_URL . 'assets/css/admin-list.css',
			array(),
			WPAIT_VERSION
		);
	}

	/**
	 * Enqueues the DataViews Overview React app (and its styles) on the Overview
	 * page. apiFetch (a build dependency) wires the REST root + nonce.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_overview_assets( $hook_suffix ): void {
		if ( '' === $this->overview_hook || $hook_suffix !== $this->overview_hook ) {
			return;
		}

		$asset_file = WPAIT_PLUGIN_DIR . 'build/overview/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			// Build artifact missing; the page renders its <noscript> fallback only.
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'wpait-overview',
			WPAIT_PLUGIN_URL . 'build/overview/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// wp-scripts emits the combined component/dataviews CSS as style-index.css.
		$style_file = WPAIT_PLUGIN_DIR . 'build/overview/style-index.css';
		if ( file_exists( $style_file ) ) {
			wp_enqueue_style(
				'wpait-overview',
				WPAIT_PLUGIN_URL . 'build/overview/style-index.css',
				array( 'wp-components' ),
				$asset['version']
			);
		} else {
			wp_enqueue_style( 'wp-components' );
		}

		$languages = array_map(
			static function ( $lang ) {
				return array( 'code' => $lang['code'], 'name' => $lang['name'] );
			},
			$this->enabled_languages()
		);

		$config = array(
			'namespace' => Wpait_Rest::NS,
			'languages' => array_values( $languages ),
			'aiOk'      => Wpait_Translator::can_generate_text(),
			'perPage'   => self::PER_PAGE,
			'settingsUrl' => admin_url( 'admin.php?page=' . Wpait_Admin_Settings::PAGE_SLUG ),
		);

		wp_add_inline_script(
			'wpait-overview',
			'window.wpaitOverview = ' . wp_json_encode( $config ) . ';',
			'before'
		);

		wp_set_script_translations( 'wpait-overview', 'wp-ai-translate' );
	}

	/**
	 * Renders the Overview page: the Originals list (default), the Unmarked list, or
	 * a by-language list, all paginated.
	 *
	 * @return void
	 */
	public function render_overview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Translate Overview', 'wp-ai-translate' ); ?></h1>
			<div id="wpait-overview-app">
				<noscript>
					<?php esc_html_e( 'The AI Translate Overview requires JavaScript to display the translations table.', 'wp-ai-translate' ); ?>
				</noscript>
			</div>
		</div>
		<?php
	}


	/**
	 * Emits the N6 scan-truncation warning as a top-level admin notice (used on the
	 * edit.php "Untranslated" filter, where there is no inline notice slot).
	 *
	 * @return void
	 */
	public function render_truncation_notice(): void {
		echo '<div class="notice notice-warning"><p>'
			. esc_html( $this->truncation_message() ) . '</p></div>';
	}

	/**
	 * The shared scan-truncation warning text.
	 *
	 * @return string
	 */
	private function truncation_message(): string {
		return sprintf(
			/* translators: %d: scan limit. */
			__( 'Only the most recent %d items per type were scanned; the list may be incomplete. Use the by-language filter for older content.', 'wp-ai-translate' ),
			self::MAX_SCAN
		);
	}

	/* ---------------------------------------------------------------------
	 * Language label helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Enabled configured languages (delegates to the shared language index).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function enabled_languages(): array {
		return $this->languages->enabled();
	}

	/**
	 * Builds the post/term list-table language cell.
	 *
	 * @param string $type 'post' | 'term'.
	 * @param int    $id   Object id.
	 * @param string $code Current language code.
	 * @return string
	 */
	private function language_column_html( string $type, int $id, string $code ): string {
		$flag = $this->languages->flag_html_for_code( $code );
		$out  = '<span class="wpait-language-cell">';
		if ( '' !== $flag ) {
			$out .= '<span class="wpait-flag" aria-hidden="true">' . $flag . '</span>';
		}
		$out .= '<strong>' . esc_html( $code ) . '</strong>';

		$source = $this->original_source_link( $type, $id, $code );
		if ( '' !== $source ) {
			$out .= ' <span class="description">'
				. sprintf(
					/* translators: %s: linked original language code. */
					esc_html__( 'translated from %s', 'wp-ai-translate' ),
					$source
				)
				. '</span>';
		}

		return $out . '</span>';
	}

	/**
	 * Builds the linked original language code for translated list-table rows.
	 *
	 * @param string $type 'post' | 'term'.
	 * @param int    $id   Object id.
	 * @param string $code Current language code.
	 * @return string
	 */
	private function original_source_link( string $type, int $id, string $code ): string {
		$settings = Wpait_Admin_Settings::get_settings();
		$original = isset( $settings['default_language'] ) ? (string) $settings['default_language'] : '';
		if ( '' === $original || $original === $code ) {
			return '';
		}

		$members = $this->store->get_translations( $type, $id, array( 'include_self' => true ) );
		if ( empty( $members[ $original ] ) || (int) $members[ $original ] === $id ) {
			return '';
		}

		$source_id = (int) $members[ $original ];
		$url       = 'term' === $type ? get_edit_term_link( $source_id ) : get_edit_post_link( $source_id );
		if ( ! $url ) {
			return esc_html( $original );
		}

		$name  = $this->languages->name( $original );
		$label = sprintf(
			/* translators: %s: language name. */
			__( 'Edit the %s original', 'wp-ai-translate' ),
			'' !== $name ? $name : strtoupper( $original )
		);

		return '<a href="' . esc_url( $url ) . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '">'
			. esc_html( $original ) . '</a>';
	}
}
