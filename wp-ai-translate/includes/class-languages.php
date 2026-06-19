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
	 * Registers runtime hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
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
