<?php
/**
 * Language taxonomy registration and settings reconcile.
 *
 * @package WpAiTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the `wpait_language` taxonomy and keeps its terms in sync with the
 * configured language list (S4).
 */
class Wpait_Languages {

	const TAXONOMY = 'wpait_language';

	const META_LOCALE = '_wpait_locale';
	const META_CODE   = '_wpait_code';
	const META_NATIVE = '_wpait_native_name';
	const META_FLAG   = '_wpait_flag';

	/**
	 * Post types that can carry a language.
	 *
	 * @var string[]
	 */
	const OBJECT_TYPES = array( 'post', 'page' );

	/**
	 * Request-scoped memo of the configured-language index, keyed by code.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static ?array $config_index = null;

	/**
	 * Registers runtime hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );

		// Drop the memoized language index when the settings option changes so a
		// save followed by a render in the same request reflects the new list.
		add_action( 'add_option_' . Wpait_Admin_Settings::OPTION, array( __CLASS__, 'flush_index' ) );
		add_action( 'update_option_' . Wpait_Admin_Settings::OPTION, array( __CLASS__, 'flush_index' ) );
	}

	/* ---------------------------------------------------------------------
	 * Configured-language access (shared label/enabled helpers)
	 * ------------------------------------------------------------------- */

	/**
	 * Returns the configured languages indexed by code, memoized per request.
	 * Each row is `[ code, name, native, flag, enabled ]`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function config_index(): array {
		if ( null !== self::$config_index ) {
			return self::$config_index;
		}

		$index    = array();
		$settings = Wpait_Admin_Settings::get_settings();
		foreach ( $settings['languages'] as $lang ) {
			$code = isset( $lang['code'] ) ? (string) $lang['code'] : '';
			if ( '' === $code ) {
				continue;
			}
			$index[ $code ] = array(
				'code'    => $code,
				'locale'  => isset( $lang['locale'] ) ? (string) $lang['locale'] : '',
				'name'    => ! empty( $lang['name'] ) ? (string) $lang['name'] : $code,
				'native'  => isset( $lang['native'] ) ? (string) $lang['native'] : '',
				'flag'    => isset( $lang['flag'] ) ? (string) $lang['flag'] : '',
				'enabled' => ! empty( $lang['enabled'] ),
			);
		}

		self::$config_index = $index;
		return $index;
	}

	/**
	 * Clears the memoized language index.
	 *
	 * @return void
	 */
	public static function flush_index(): void {
		self::$config_index = null;
	}

	/**
	 * All configured languages as a list of `[ code, name, native, flag, enabled ]`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function configured(): array {
		return array_values( self::config_index() );
	}

	/**
	 * The enabled configured languages, same row shape as configured().
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function enabled(): array {
		return array_values(
			array_filter(
				self::config_index(),
				static function ( $lang ) {
					return ! empty( $lang['enabled'] );
				}
			)
		);
	}

	/**
	 * Human-readable label for a code: "🇪🇸 Spanish" (flag prefix when set),
	 * falling back to the code itself for unknown codes.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function label( string $code ): string {
		$index = self::config_index();
		if ( ! isset( $index[ $code ] ) ) {
			return $code;
		}
		// Only a literal emoji reads as a sensible text prefix; an ISO region code
		// (the new flag format, #80) is rendered as an image by flag_html() instead,
		// so it is omitted from the plain-text label.
		$flag   = (string) $index[ $code ]['flag'];
		$prefix = ( '' !== $flag && ! self::is_region_code( $flag ) ) ? $flag . ' ' : '';
		return $prefix . $index[ $code ]['name'];
	}

	/**
	 * Whether a stored flag value is an ISO 3166-1 alpha-2 region code (e.g. "es"),
	 * as opposed to a legacy free-text emoji. Region codes are rendered as flag
	 * images via flagcdn (#80); anything else is treated as literal text.
	 *
	 * @param string $flag Stored flag value.
	 * @return bool
	 */
	public static function is_region_code( string $flag ): bool {
		return 1 === preg_match( '/^[a-z]{2}$/', $flag );
	}

	/**
	 * Renders a stored flag value as safe HTML (#80):
	 *
	 * - an ISO region code → a public-domain flagcdn.com SVG `<img>` (consistent
	 *   cross-platform rendering, unlike pasted emoji);
	 * - any other non-empty value → the literal text in a span (back-compat with
	 *   the previous free-text emoji field);
	 * - empty → ''.
	 *
	 * @param string              $flag Stored flag value (region code or legacy text).
	 * @param array<string,mixed> $args Optional: 'alt' (string, default ''),
	 *                                  'class' (extra css class).
	 * @return string HTML, or '' when there is no flag.
	 */
	public static function flag_html( string $flag, array $args = array() ): string {
		if ( '' === $flag ) {
			return '';
		}
		$extra_class = isset( $args['class'] ) ? ' ' . sanitize_html_class( (string) $args['class'] ) : '';

		if ( self::is_region_code( $flag ) ) {
			$alt = isset( $args['alt'] ) ? (string) $args['alt'] : '';
			$url = sprintf( 'https://flagcdn.com/%s.svg', $flag );
			return sprintf(
				'<img class="wpait-flag-img%s" src="%s" alt="%s" loading="lazy" decoding="async" />',
				esc_attr( $extra_class ),
				esc_url( $url ),
				esc_attr( $alt )
			);
		}

		return '<span class="wpait-flag-emoji' . esc_attr( $extra_class ) . '">' . esc_html( $flag ) . '</span>';
	}

	/**
	 * Convenience: renders the configured flag for a language code as HTML.
	 *
	 * @param string              $code Language code.
	 * @param array<string,mixed> $args Passed through to flag_html().
	 * @return string
	 */
	public function flag_html_for_code( string $code, array $args = array() ): string {
		return self::flag_html( $this->flag( $code ), $args );
	}

	/**
	 * HTML label for a code: the flag (image for a region code, or a legacy emoji)
	 * followed by the escaped name. Use this in markup contexts; {@see label()}
	 * stays the plain-text variant. Returns escaped, safe-to-echo HTML.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function label_html( string $code ): string {
		$index = self::config_index();
		if ( ! isset( $index[ $code ] ) ) {
			return esc_html( $code );
		}
		$flag = (string) $index[ $code ]['flag'];
		$name = (string) $index[ $code ]['name'];
		$prefix = '' !== $flag ? self::flag_html( $flag ) . ' ' : '';
		return $prefix . esc_html( $name );
	}

	/**
	 * The configured WordPress locale for a code (e.g. "es_ES"), or '' if none is
	 * set / unknown.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function locale( string $code ): string {
		$index = self::config_index();
		return isset( $index[ $code ] ) ? (string) $index[ $code ]['locale'] : '';
	}

	/**
	 * The configured flag for a code (e.g. "🇪🇸"), or '' if none is set / unknown.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function flag( string $code ): string {
		$index = self::config_index();
		return isset( $index[ $code ] ) ? (string) $index[ $code ]['flag'] : '';
	}

	/**
	 * The plain configured name for a code (no flag prefix), falling back to the
	 * code itself for unknown codes. Used where a flag emoji would be noise — e.g.
	 * the translator's `{source_lang}`/`{target_lang}` prompt labels.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function name( string $code ): string {
		$index = self::config_index();
		return isset( $index[ $code ] ) ? (string) $index[ $code ]['name'] : $code;
	}

	/**
	 * Registers the non-public language taxonomy and its term meta.
	 *
	 * Registered against posts and pages directly. Categories and tags carry
	 * their language via term meta (a taxonomy term cannot itself be assigned a
	 * taxonomy term), handled in the translation store.
	 *
	 * @return void
	 */
	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			self::OBJECT_TYPES,
			array(
				'label'             => __( 'Language', 'wp-ai-translate' ),
				'public'            => false,
				'publicly_queryable' => false,
				'hierarchical'      => false,
				'show_ui'           => false,
				'show_in_menu'      => false,
				'show_in_rest'      => false,
				'show_admin_column' => false,
				'rewrite'           => false,
				'query_var'         => false,
			)
		);

		$string_meta = array(
			self::META_LOCALE,
			self::META_CODE,
			self::META_NATIVE,
			self::META_FLAG,
		);
		foreach ( $string_meta as $meta_key ) {
			register_term_meta(
				self::TAXONOMY,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Core language-pack status + install (#62)
	 * ------------------------------------------------------------------- */

	/**
	 * The set of distinct, non-empty WordPress locales across all configured
	 * languages (e.g. `[ 'es_ES', 'fr_FR' ]`). Used to bound pack installs to the
	 * site's own configured locales — never an arbitrary download target.
	 *
	 * @return string[]
	 */
	public function configured_locales(): array {
		$locales = array();
		foreach ( self::config_index() as $lang ) {
			$loc = (string) $lang['locale'];
			if ( '' !== $loc && ! in_array( $loc, $locales, true ) ) {
				$locales[] = $loc;
			}
		}
		return $locales;
	}

	/**
	 * The install status of a WordPress locale's core language pack.
	 *
	 * @param string $locale WordPress locale (e.g. es_ES). Empty => 'none'.
	 * @return string One of 'builtin' (en_US), 'installed', 'not_installed', or
	 *                'none' when no locale is configured.
	 */
	public static function locale_pack_status( string $locale ): string {
		if ( '' === $locale ) {
			return 'none';
		}
		if ( 'en_US' === $locale ) {
			return 'builtin';
		}
		return in_array( $locale, get_available_languages(), true ) ? 'installed' : 'not_installed';
	}

	/**
	 * Downloads and installs the core language pack for a configured locale.
	 *
	 * Validates that the locale is one of the site's configured locales (never an
	 * arbitrary locale), then delegates to core's downloader. en_US is built in and
	 * needs no download.
	 *
	 * @param string $locale WordPress locale (e.g. es_ES).
	 * @return true|WP_Error True on success (or already built in/installed).
	 */
	public function install_language_pack( string $locale ) {
		if ( '' === $locale || ! in_array( $locale, $this->configured_locales(), true ) ) {
			return new WP_Error(
				'wpait_invalid_locale',
				__( 'That locale is not one of the site’s configured languages.', 'wp-ai-translate' ),
				array( 'status' => 400 )
			);
		}

		if ( 'en_US' === $locale || in_array( $locale, get_available_languages(), true ) ) {
			return true;
		}

		if ( ! function_exists( 'wp_download_language_pack' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}
		$result = wp_download_language_pack( $locale );

		if ( false === $result ) {
			return new WP_Error(
				'wpait_install_failed',
				__( 'The language pack could not be installed.', 'wp-ai-translate' ),
				array( 'status' => 500 )
			);
		}
		return true;
	}

	/**
	 * Returns the language term for a given code, or null.
	 *
	 * @param string $code Language code (term slug).
	 * @return WP_Term|null
	 */
	public function get_term_for_code( string $code ): ?WP_Term {
		$term = get_term_by( 'slug', $code, self::TAXONOMY );
		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * Reconciles taxonomy terms with the configured language list (S4).
	 *
	 * - Creates a term for any new language (slug = code, immutable).
	 * - Updates the display/native name and meta for existing languages.
	 * - Blocks deletion of a language that still has content: it is retained as
	 *   a term and forced back into the (disabled) settings list so existing
	 *   assignments stay intact.
	 *
	 * @param array<int,array<string,mixed>> $new_languages Sanitized language rows.
	 * @param array<int,array<string,mixed>> $old_languages Previously stored rows.
	 * @return array{languages:array<int,array<string,mixed>>,report:array<string,string[]>}
	 */
	public function reconcile( array $new_languages, array $old_languages ): array {
		// Ensure the taxonomy is registered before touching terms (reconcile can
		// run during option save before the init hook on some requests).
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			self::register_taxonomy();
		}

		$report = array(
			'added'    => array(),
			'updated'  => array(),
			'disabled' => array(),
			'blocked'  => array(),
			'removed'  => array(),
		);

		$new_codes = wp_list_pluck( $new_languages, 'code' );

		// Create/update terms for each configured language.
		foreach ( $new_languages as $lang ) {
			$code = $lang['code'];
			$term = $this->get_term_for_code( $code );

			if ( ! $term ) {
				$created = wp_insert_term( $lang['name'], self::TAXONOMY, array( 'slug' => $code ) );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$term_id          = (int) $created['term_id'];
				$report['added'][] = $code;
			} else {
				$term_id = $term->term_id;
				if ( $term->name !== $lang['name'] ) {
					wp_update_term( $term_id, self::TAXONOMY, array( 'name' => $lang['name'] ) );
					$report['updated'][] = $code;
				}
			}

			update_term_meta( $term_id, self::META_CODE, $code );
			update_term_meta( $term_id, self::META_LOCALE, $lang['locale'] );
			update_term_meta( $term_id, self::META_NATIVE, $lang['native'] );
			update_term_meta( $term_id, self::META_FLAG, $lang['flag'] ?? '' );

			if ( empty( $lang['enabled'] ) ) {
				$report['disabled'][] = $code;
			}
		}

		// Handle languages that were removed from the configured list.
		foreach ( $old_languages as $old ) {
			$code = $old['code'] ?? '';
			if ( '' === $code || in_array( $code, $new_codes, true ) ) {
				continue;
			}

			if ( $this->language_has_content( $code ) ) {
				// Cannot delete: retain the term and force the language back in,
				// disabled, so existing assignments are not orphaned.
				$old['enabled']     = false;
				$new_languages[]    = $old;
				$report['blocked'][] = $code;
			} else {
				$term = $this->get_term_for_code( $code );
				if ( $term ) {
					wp_delete_term( $term->term_id, self::TAXONOMY );
				}
				$report['removed'][] = $code;
			}
		}

		return array(
			'languages' => $new_languages,
			'report'    => $report,
		);
	}

	/**
	 * Whether any content (posts/pages or terms) is assigned to a language.
	 *
	 * Uses indexed tax/meta queries — never a LIKE scan (C1).
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public function language_has_content( string $code ): bool {
		$term = $this->get_term_for_code( $code );
		if ( ! $term ) {
			return false;
		}

		if ( (int) $term->count > 0 ) {
			return true;
		}

		// Terms (categories/tags) store their language as term meta.
		$tagged_terms = get_terms(
			array(
				'taxonomy'   => array( 'category', 'post_tag' ),
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => array(
					array(
						'key'   => Wpait_Translation_Store::META_LANGUAGE,
						'value' => $code,
					),
				),
			)
		);

		return ! empty( $tagged_terms ) && ! is_wp_error( $tagged_terms );
	}
}
