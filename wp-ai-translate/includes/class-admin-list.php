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
 * Adds the language column + filters to edit.php for posts/pages, and a
 * Settings → AI Translate Overview page.
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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_overview_assets' ) );

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

		echo '<strong>' . esc_html( $this->label( $code ) ) . '</strong>';

		$siblings = $this->store->get_translations( 'post', $post_id );
		if ( empty( $siblings ) ) {
			return;
		}

		$links = array();
		foreach ( $siblings as $sib_code => $sib_id ) {
			$edit = get_edit_post_link( (int) $sib_id );
			$text = $this->short_label( $sib_code );
			$links[] = $edit
				? '<a href="' . esc_url( $edit ) . '">' . esc_html( $text ) . '</a>'
				: esc_html( $text );
		}

		echo '<br /><span class="description">'
			. wp_kses_post( implode( ', ', $links ) ) . '</span>';
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

		$out      = '<strong>' . esc_html( $this->label( $code ) ) . '</strong>';
		$siblings = $this->store->get_translations( 'term', $term_id );
		if ( empty( $siblings ) ) {
			return $out;
		}

		$links = array();
		foreach ( $siblings as $sib_code => $sib_id ) {
			$edit    = get_edit_term_link( (int) $sib_id );
			$text    = $this->short_label( $sib_code );
			$links[] = $edit
				? '<a href="' . esc_url( $edit ) . '">' . esc_html( $text ) . '</a>'
				: esc_html( $text );
		}

		return $out . '<br /><span class="description">' . wp_kses_post( implode( ', ', $links ) ) . '</span>';
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
	 * Overview page (§6.2 — optional, simple)
	 * ------------------------------------------------------------------- */

	/**
	 * Registers the Overview submenu under Settings.
	 *
	 * @return void
	 */
	public function add_overview_page(): void {
		add_submenu_page(
			'options-general.php',
			__( 'AI Translate Overview', 'wp-ai-translate' ),
			__( 'AI Translate Overview', 'wp-ai-translate' ),
			'manage_options',
			self::OVERVIEW_SLUG,
			array( $this, 'render_overview' )
		);
	}

	/**
	 * Enqueues wp-api-fetch on the Overview page so the per-row quick-Translate
	 * actions can call the REST endpoint (apiFetch wires the REST root + nonce).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_overview_assets( $hook_suffix ): void {
		if ( 'settings_page_' . self::OVERVIEW_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( 'wp-api-fetch' );
	}

	/**
	 * Renders the Overview page: missing-translations list (default) or a
	 * by-language list, both paginated.
	 *
	 * @return void
	 */
	public function render_overview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$languages = $this->enabled_languages();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin view navigation.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'missing';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin view navigation.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

		$codes      = wp_list_pluck( $languages, 'code' );
		$is_lang    = in_array( $view, $codes, true );
		$base_url   = admin_url( 'options-general.php?page=' . self::OVERVIEW_SLUG );

		// Computed once so the "Missing" tab can show a live count on every view and
		// the table (when shown) reuses it rather than re-scanning.
		$missing_rows  = $this->missing_rows( $codes );
		$missing_count = count( $missing_rows );

		// Quick-Translate actions need a usable AI model; mirror the editor's gating.
		$ai_ok = Wpait_Translator::can_generate_text();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Translate Overview', 'wp-ai-translate' ); ?></h1>

			<?php if ( ! $ai_ok ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: settings page URL. */
						wp_kses_post( __( 'AI translation is unavailable, so quick-Translate actions are disabled. Check the <a href="%s">AI provider status</a>.', 'wp-ai-translate' ) ),
						esc_url( admin_url( 'options-general.php?page=' . Wpait_Admin_Settings::PAGE_SLUG ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo $is_lang ? '' : 'current'; ?>">
						<?php esc_html_e( 'Missing translations', 'wp-ai-translate' ); ?>
						<span class="count">(<?php echo (int) $missing_count; ?>)</span>
					</a><?php echo $languages ? ' |' : ''; ?>
				</li>
				<?php foreach ( $languages as $i => $lang ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'view', $lang['code'], $base_url ) ); ?>"
							class="<?php echo ( $view === $lang['code'] ) ? 'current' : ''; ?>">
							<?php echo esc_html( $lang['name'] ); ?>
							<span class="count">(<?php echo (int) $this->count_in_language( $lang['code'] ); ?>)</span>
						</a><?php echo ( $i < count( $languages ) - 1 ) ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<br class="clear" />

			<?php
			if ( $is_lang ) {
				$this->render_by_language( $view, $paged, $base_url );
			} else {
				$this->render_missing( $codes, $missing_rows, $paged, $base_url, $ai_ok );
			}
			?>
		</div>
		<?php
		$this->render_overview_script();
	}

	/**
	 * Builds the type-tagged "missing translations" rows across posts and terms:
	 * each `[ type, id, missing[], taxonomy? ]`. Posts and terms share an id space
	 * (S5), so rows are never keyed by bare id. Sets {@see $scan_truncated} via the
	 * underlying bounded scans (N6).
	 *
	 * @param string[] $codes Enabled language codes.
	 * @return array<int,array<string,mixed>>
	 */
	private function missing_rows( array $codes ): array {
		if ( count( $codes ) <= 1 ) {
			return array();
		}

		$rows = array();
		foreach ( Wpait_Languages::OBJECT_TYPES as $post_type ) {
			foreach ( $this->untranslated_map( $post_type ) as $post_id => $present ) {
				$missing = array_diff( $codes, $present );
				if ( ! empty( $missing ) ) {
					$rows[] = array( 'type' => 'post', 'id' => (int) $post_id, 'missing' => $missing );
				}
			}
		}
		foreach ( self::TERM_TAXONOMIES as $taxonomy ) {
			foreach ( $this->untranslated_term_map( $taxonomy ) as $term_id => $present ) {
				$missing = array_diff( $codes, $present );
				if ( ! empty( $missing ) ) {
					$rows[] = array( 'type' => 'term', 'id' => (int) $term_id, 'missing' => $missing, 'taxonomy' => $taxonomy );
				}
			}
		}

		return $rows;
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

	/**
	 * Inline script powering the Overview's per-row quick-Translate buttons via the
	 * REST endpoint (apiFetch supplies the nonce). On success the button is marked
	 * done; on failure the connector's friendly message is shown inline.
	 *
	 * @return void
	 */
	private function render_overview_script(): void {
		$strings = array(
			'working' => __( 'Translating…', 'wp-ai-translate' ),
			'done'    => __( '✓ Translated', 'wp-ai-translate' ),
			'failed'  => __( 'Translation failed.', 'wp-ai-translate' ),
			'noFetch' => __( 'Could not run the request in this browser.', 'wp-ai-translate' ),
		);
		?>
		<script>
		( function () {
			var ns = <?php echo wp_json_encode( Wpait_Rest::NS ); ?>;
			var strings = <?php echo wp_json_encode( $strings ); ?>;
			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.wpait-ov-translate' );
				if ( ! btn ) { return; }
				e.preventDefault();
				var result = btn.parentNode.querySelector( '.wpait-ov-result' );
				if ( ! window.wp || ! wp.apiFetch ) {
					if ( result ) { result.textContent = strings.noFetch; }
					return;
				}
				btn.disabled = true;
				if ( result ) { result.textContent = strings.working; }
				wp.apiFetch( {
					path: '/' + ns + '/translate',
					method: 'POST',
					data: {
						source_id: parseInt( btn.getAttribute( 'data-id' ), 10 ),
						target_code: btn.getAttribute( 'data-code' ),
						type: btn.getAttribute( 'data-type' )
					}
				} ).then( function () {
					btn.replaceWith( document.createTextNode( strings.done + ' ' ) );
					if ( result ) { result.textContent = ''; }
				} ).catch( function ( err ) {
					btn.disabled = false;
					if ( result ) { result.textContent = ( err && err.message ) ? err.message : strings.failed; }
				} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Renders the paginated "missing translations" table across posts and pages.
	 *
	 * @param string[]                       $codes    Enabled language codes.
	 * @param array<int,array<string,mixed>> $rows     Precomputed missing rows.
	 * @param int                            $paged    Current page (1-based).
	 * @param string                         $base_url Page base URL.
	 * @param bool                           $ai_ok    Whether quick-Translate is available.
	 * @return void
	 */
	private function render_missing( array $codes, array $rows, int $paged, string $base_url, bool $ai_ok ): void {
		if ( count( $codes ) <= 1 ) {
			echo '<p>' . esc_html__( 'Configure at least two languages to track missing translations.', 'wp-ai-translate' ) . '</p>';
			return;
		}

		$total = count( $rows );
		// Clamp the requested page so a stale ?paged beyond the end does not show
		// an empty table while items remain on earlier pages.
		$pages     = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged     = min( $paged, $pages );
		$page_rows = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		if ( $this->scan_truncated ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html( $this->truncation_message() ) . '</p></div>';
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'wp-ai-translate' ); ?></th>
					<th><?php esc_html_e( 'Type', 'wp-ai-translate' ); ?></th>
					<th><?php esc_html_e( 'Language', 'wp-ai-translate' ); ?></th>
					<th><?php esc_html_e( 'Missing', 'wp-ai-translate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $page_rows ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Nothing is missing translations.', 'wp-ai-translate' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $page_rows as $row ) :
					if ( 'term' === $row['type'] ) {
						$edit  = get_edit_term_link( $row['id'] );
						$term  = get_term( $row['id'] );
						$title = $term instanceof WP_Term ? $term->name : '';
						$type  = $term instanceof WP_Term ? $term->taxonomy : 'term';
						$own   = $this->store->get_language( 'term', $row['id'] );
					} else {
						$edit  = get_edit_post_link( $row['id'] );
						$title = get_the_title( $row['id'] );
						$type  = get_post_type( $row['id'] );
						$own   = $this->store->get_language( 'post', $row['id'] );
					}
					?>
					<tr>
						<td>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $title ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $title ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $type ); ?></td>
						<td><?php echo esc_html( '' !== $own ? $this->label( $own ) : '—' ); ?></td>
						<td>
							<?php foreach ( $row['missing'] as $miss_code ) : ?>
								<?php if ( $ai_ok ) : ?>
									<button type="button" class="button button-small wpait-ov-translate"
										data-id="<?php echo esc_attr( (string) $row['id'] ); ?>"
										data-type="<?php echo esc_attr( (string) $row['type'] ); ?>"
										data-code="<?php echo esc_attr( (string) $miss_code ); ?>">
										<?php
										printf(
											/* translators: %s: target language label. */
											esc_html__( 'Translate to %s', 'wp-ai-translate' ),
											esc_html( $this->label( $miss_code ) )
										);
										?>
									</button>
								<?php else : ?>
									<span class="wpait-missing-lang"><?php echo esc_html( $this->label( $miss_code ) ); ?></span>
								<?php endif; ?>
							<?php endforeach; ?>
							<span class="wpait-ov-result" aria-live="polite"></span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->render_pagination( $total, $paged, $base_url );
	}

	/**
	 * Renders the paginated by-language list for one language.
	 *
	 * @param string $code     Language code.
	 * @param int    $paged    Current page (1-based).
	 * @param string $base_url Page base URL.
	 * @return void
	 */
	private function render_by_language( string $code, int $paged, string $base_url ): void {
		$args = array(
			'post_type'      => Wpait_Languages::OBJECT_TYPES,
			'post_status'    => 'any',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => Wpait_Languages::TAXONOMY,
					'field'    => 'slug',
					'terms'    => $code,
				),
			),
		);
		$query = new WP_Query( $args );

		// Clamp a stale out-of-range ?paged to the last page so it shows real items
		// instead of an empty table while content exists on earlier pages (mirrors
		// the clamp in render_missing()).
		if ( $paged > 1 && $query->max_num_pages > 0 && $paged > $query->max_num_pages ) {
			$paged         = (int) $query->max_num_pages;
			$args['paged'] = $paged;
			$query         = new WP_Query( $args );
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'wp-ai-translate' ); ?></th>
					<th><?php esc_html_e( 'Type', 'wp-ai-translate' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-ai-translate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $query->have_posts() ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No content in this language yet.', 'wp-ai-translate' ); ?></td></tr>
				<?php endif; ?>
				<?php
				while ( $query->have_posts() ) :
					$query->the_post();
					$edit = get_edit_post_link( get_the_ID() );
					?>
					<tr>
						<td>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( get_the_title() ); ?></a>
							<?php else : ?>
								<?php echo esc_html( get_the_title() ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( get_post_type() ); ?></td>
						<td><?php echo esc_html( get_post_status() ); ?></td>
					</tr>
				<?php endwhile; ?>
			</tbody>
		</table>
		<?php
		$this->render_pagination( (int) $query->found_posts, $paged, add_query_arg( 'view', $code, $base_url ) );
		wp_reset_postdata();

		$this->render_terms_by_language( $code );
	}

	/**
	 * Renders the categories/tags assigned to a language, below the posts table on
	 * the by-language Overview view. Bounded; terms are typically few.
	 *
	 * @param string $code Language code.
	 * @return void
	 */
	private function render_terms_by_language( string $code ): void {
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
		?>
		<h2><?php esc_html_e( 'Categories &amp; tags', 'wp-ai-translate' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'wp-ai-translate' ); ?></th>
					<th><?php esc_html_e( 'Taxonomy', 'wp-ai-translate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $terms ) ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No categories or tags in this language yet.', 'wp-ai-translate' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $terms as $term ) :
					$edit = get_edit_term_link( $term->term_id );
					?>
					<tr>
						<td>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $term->name ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $term->name ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $term->taxonomy ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders simple prev/next pagination for the Overview tables.
	 *
	 * @param int    $total    Total item count.
	 * @param int    $paged    Current page.
	 * @param string $base_url Base URL (already carrying any view arg).
	 * @return void
	 */
	private function render_pagination( int $total, int $paged, string $base_url ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html(
			sprintf(
				/* translators: %d: number of items. */
				_n( '%d item', '%d items', $total, 'wp-ai-translate' ),
				$total
			)
		) . '</span> ';
		if ( $paged > 1 ) {
			printf(
				'<a class="button" href="%s">%s</a> ',
				esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ),
				esc_html__( '‹ Previous', 'wp-ai-translate' )
			);
		}
		if ( $paged < $pages ) {
			printf(
				'<a class="button" href="%s">%s</a>',
				esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ),
				esc_html__( 'Next ›', 'wp-ai-translate' )
			);
		}
		echo '</div></div>';
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
	 * Human-readable label for a code, e.g. "🇪🇸 Spanish".
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	private function label( string $code ): string {
		return $this->languages->label( $code );
	}

	/**
	 * Compact label for sibling links: the flag if set, else the uppercased code.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	private function short_label( string $code ): string {
		$flag = $this->languages->flag( $code );
		return '' !== $flag ? $flag : strtoupper( $code );
	}
}
