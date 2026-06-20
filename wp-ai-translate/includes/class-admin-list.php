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
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Translate Overview', 'wp-ai-translate' ); ?></h1>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo $is_lang ? '' : 'current'; ?>">
						<?php esc_html_e( 'Missing translations', 'wp-ai-translate' ); ?>
					</a><?php echo $languages ? ' |' : ''; ?>
				</li>
				<?php foreach ( $languages as $i => $lang ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'view', $lang['code'], $base_url ) ); ?>"
							class="<?php echo ( $view === $lang['code'] ) ? 'current' : ''; ?>">
							<?php echo esc_html( $lang['name'] ); ?>
						</a><?php echo ( $i < count( $languages ) - 1 ) ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<br class="clear" />

			<?php
			if ( $is_lang ) {
				$this->render_by_language( $view, $paged, $base_url );
			} else {
				$this->render_missing( $codes, $paged, $base_url );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders the paginated "missing translations" table across posts and pages.
	 *
	 * @param string[] $codes    Enabled language codes.
	 * @param int      $paged    Current page (1-based).
	 * @param string   $base_url Page base URL.
	 * @return void
	 */
	private function render_missing( array $codes, int $paged, string $base_url ): void {
		$count = count( $codes );
		if ( $count <= 1 ) {
			echo '<p>' . esc_html__( 'Configure at least two languages to track missing translations.', 'wp-ai-translate' ) . '</p>';
			return;
		}

		$rows = array();
		foreach ( Wpait_Languages::OBJECT_TYPES as $post_type ) {
			foreach ( $this->untranslated_map( $post_type ) as $post_id => $present ) {
				$missing = array_diff( $codes, $present );
				if ( ! empty( $missing ) ) {
					$rows[ $post_id ] = $missing;
				}
			}
		}

		$total = count( $rows );
		// Clamp the requested page so a stale ?paged beyond the end does not show
		// an empty table while items remain on earlier pages.
		$pages     = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged     = min( $paged, $pages );
		$page_rows = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE, true );

		if ( $this->scan_truncated ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html(
					sprintf(
						/* translators: %d: scan limit. */
						__( 'Only the most recent %d items per type were scanned; the list may be incomplete. Use the by-language filter for older content.', 'wp-ai-translate' ),
						self::MAX_SCAN
					)
				) . '</p></div>';
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'wp-ai-translate' ); ?></th>
					<th><?php esc_html_e( 'Language', 'wp-ai-translate' ); ?></th>
					<th><?php esc_html_e( 'Missing', 'wp-ai-translate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $page_rows ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Nothing is missing translations.', 'wp-ai-translate' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $page_rows as $post_id => $missing ) : ?>
					<?php
					$edit = get_edit_post_link( $post_id );
					$own  = $this->store->get_language( 'post', $post_id );
					$miss = array_map( array( $this, 'label' ), $missing );
					?>
					<tr>
						<td>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( get_the_title( $post_id ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( '' !== $own ? $this->label( $own ) : '—' ); ?></td>
						<td><?php echo esc_html( implode( ', ', $miss ) ); ?></td>
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
		$query = new WP_Query(
			array(
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
			)
		);
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
		foreach ( $this->languages->configured() as $lang ) {
			if ( $lang['code'] === $code && '' !== $lang['flag'] ) {
				return (string) $lang['flag'];
			}
		}
		return strtoupper( $code );
	}
}
