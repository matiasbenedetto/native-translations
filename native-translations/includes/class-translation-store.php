<?php
/**
 * Translation store: the single writer for language + group relationships.
 *
 * Every public method takes an explicit `$object_type` (`post` | `term`) because
 * post and term IDs overlap across tables (S5). Nothing else in the plugin touches
 * the `_wpnt_group` meta directly.
 *
 * @package WpNativeTranslations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps all reads/writes of language and translation-group relationships,
 * with object caching (C1) and the one-member-per-language invariant (C2).
 */
class Wpnt_Translation_Store {

	/**
	 * Group id meta key (post meta and term meta).
	 */
	const META_GROUP = '_wpnt_group';

	/**
	 * Language code meta key — used for terms (categories/tags). Posts/pages
	 * carry their language via the `wpnt_language` taxonomy instead.
	 */
	const META_LANGUAGE = '_wpnt_language';

	/**
	 * "Marked as original" flag meta key (post meta and term meta). An item is an
	 * explicit translation source only when this flag is set (#92). Stored as the
	 * string '1' when set, absent otherwise. Replaces the old "infer the original
	 * from the default language" heuristic. May only be set on an item that already
	 * has a language assigned.
	 */
	const META_IS_ORIGINAL = '_wpnt_is_original';

	/**
	 * Object cache group.
	 */
	const CACHE_GROUP = 'wpnt';

	/**
	 * Valid object types.
	 *
	 * @var string[]
	 */
	const OBJECT_TYPES = array( 'post', 'term' );

	/**
	 * Registers lifecycle hooks that bust the group cache (C1).
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_post_lifecycle' ), 10, 1 );
		add_action( 'untrash_post', array( $this, 'on_post_lifecycle' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_post_lifecycle' ), 10, 1 );
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 10, 6 );

		// Term lifecycle (categories/tags) — busts on language/group meta change.
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'on_pre_delete_term' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Language
	 * ------------------------------------------------------------------- */

	/**
	 * Returns the language code assigned to an object, or '' if none.
	 *
	 * @param string $object_type 'post' | 'term'.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	public function get_language( string $object_type, int $object_id ): string {
		$this->assert_type( $object_type );

		if ( 'term' === $object_type ) {
			return (string) get_term_meta( $object_id, self::META_LANGUAGE, true );
		}

		$slugs = wp_get_object_terms(
			$object_id,
			Wpnt_Languages::TAXONOMY,
			array( 'fields' => 'slugs' )
		);

		if ( is_wp_error( $slugs ) || empty( $slugs ) ) {
			return '';
		}

		return (string) $slugs[0];
	}

	/**
	 * Assigns a language code to an object.
	 *
	 * @param string $object_type 'post' | 'term'.
	 * @param int    $object_id   Object id.
	 * @param string $code        Language code (taxonomy slug).
	 * @return bool|WP_Error True on success.
	 */
	public function set_language( string $object_type, int $object_id, string $code ) {
		$this->assert_type( $object_type );

		$code = sanitize_title( $code );
		if ( '' === $code ) {
			return new WP_Error( 'wpnt_invalid_language', __( 'A language code is required.', 'native-translations' ) );
		}

		// Group invariants. Only relevant when this object is already in a group.
		$group = $this->get_group( $object_type, $object_id );
		if ( '' !== $group ) {
			$members = $this->get_group_members( $object_type, $group );

			// One-member-per-language (C2): refuse a code that another member already
			// holds, otherwise the group→members map would silently collapse two
			// members onto the same language key.
			if ( isset( $members[ $code ] ) && $members[ $code ] !== $object_id ) {
				return new WP_Error(
					'wpnt_language_exists',
					sprintf(
						/* translators: %s: language code. */
						__( 'This translation group already has a member in language "%s".', 'native-translations' ),
						$code
					),
					array( 'status' => 409 )
				);
			}

			// Data integrity: changing a grouped object's own language would abandon
			// the slot it currently fills, silently dropping that language from a group
			// that other members rely on and mislabeling this object. Refuse while the
			// group has any sibling; the object must be unlinked first. (A lone member
			// has no slot to orphan, so relabeling it is allowed — same as an ungrouped
			// object.)
			$current = $this->get_language( $object_type, $object_id );
			if ( '' !== $current && $current !== $code ) {
				foreach ( $members as $member_id ) {
					if ( (int) $member_id !== $object_id ) {
						return new WP_Error(
							'wpnt_language_reassign',
							__( 'This content belongs to a translation group as its current language. Unlink it from the group before changing its language, so the group does not lose that language.', 'native-translations' ),
							array( 'status' => 409 )
						);
					}
				}
			}
		}

		if ( 'term' === $object_type ) {
			update_term_meta( $object_id, self::META_LANGUAGE, $code );
		} else {
			$result = wp_set_object_terms( $object_id, $code, Wpnt_Languages::TAXONOMY, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$this->bust_group_cache( $object_type, $group );

		return true;
	}

	/**
	 * Removes an object's language assignment, returning it to the "Unmarked"
	 * state (#103).
	 *
	 * Unlike {@see set_language()}, clearing is always safe for a grouped member:
	 * the object leaves its group so the language slot the siblings rely on is
	 * never silently dropped or mislabeled — the siblings keep their slots and
	 * this object becomes a lone, unmarked entity. An item with no language also
	 * cannot be a translation original (#92), so the original flag is cleared too.
	 *
	 * @param string $object_type 'post' | 'term'.
	 * @param int    $object_id   Object id.
	 * @return true
	 */
	public function clear_language( string $object_type, int $object_id ): bool {
		$this->assert_type( $object_type );

		if ( 'term' === $object_type ) {
			delete_term_meta( $object_id, self::META_LANGUAGE );
		} else {
			wp_set_object_terms( $object_id, array(), Wpnt_Languages::TAXONOMY, false );
		}

		// No language ⇒ cannot remain an explicit original (#92).
		$this->set_original( $object_type, $object_id, false );

		// Leave the group so a cleared member never orphans a sibling's slot; this
		// also busts the group cache.
		$this->unlink_translation( $object_type, $object_id );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Original flag (#92)
	 * ------------------------------------------------------------------- */

	/**
	 * Whether an object has been explicitly marked as a translation original.
	 *
	 * @param string $object_type 'post' | 'term'.
	 * @param int    $object_id   Object id.
	 * @return bool
	 */
	public function is_original( string $object_type, int $object_id ): bool {
		$this->assert_type( $object_type );

		$value = 'term' === $object_type
			? get_term_meta( $object_id, self::META_IS_ORIGINAL, true )
			: get_post_meta( $object_id, self::META_IS_ORIGINAL, true );

		return '' !== (string) $value;
	}

	/**
	 * Marks (or unmarks) an object as a translation original.
	 *
	 * Marking is only permitted once the object has a language assigned: an
	 * "original" is the source an item is translated *from*, which is meaningless
	 * without a language (#92). Unmarking is always allowed.
	 *
	 * @param string $object_type 'post' | 'term'.
	 * @param int    $object_id   Object id.
	 * @param bool   $is_original Whether to mark (true) or unmark (false).
	 * @return true|WP_Error True on success.
	 */
	public function set_original( string $object_type, int $object_id, bool $is_original ) {
		$this->assert_type( $object_type );

		if ( $is_original ) {
			if ( '' === $this->get_language( $object_type, $object_id ) ) {
				return new WP_Error(
					'wpnt_no_language',
					__( 'Set a language for this item before marking it as an original.', 'native-translations' ),
					array( 'status' => 409 )
				);
			}

			if ( 'term' === $object_type ) {
				update_term_meta( $object_id, self::META_IS_ORIGINAL, '1' );
			} else {
				update_post_meta( $object_id, self::META_IS_ORIGINAL, '1' );
			}

			return true;
		}

		if ( 'term' === $object_type ) {
			delete_term_meta( $object_id, self::META_IS_ORIGINAL );
		} else {
			delete_post_meta( $object_id, self::META_IS_ORIGINAL );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Group
	 * ------------------------------------------------------------------- */

	/**
	 * Returns the translation-group id for an object, or '' if it has none.
	 *
	 * @param string $object_type 'post' | 'term'.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	public function get_group( string $object_type, int $object_id ): string {
		$this->assert_type( $object_type );

		$value = 'term' === $object_type
			? get_term_meta( $object_id, self::META_GROUP, true )
			: get_post_meta( $object_id, self::META_GROUP, true );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Returns the object's group id, creating one if it has none.
	 *
	 * @param string $object_type 'post' | 'term'.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	public function ensure_group( string $object_type, int $object_id ): string {
		$group = $this->get_group( $object_type, $object_id );
		if ( '' !== $group ) {
			return $group;
		}

		$group = wp_generate_uuid4();

		if ( 'term' === $object_type ) {
			update_term_meta( $object_id, self::META_GROUP, $group );
		} else {
			update_post_meta( $object_id, self::META_GROUP, $group );
		}

		$this->bust_group_cache( $object_type, $group );

		return $group;
	}

	/* ---------------------------------------------------------------------
	 * Translations
	 * ------------------------------------------------------------------- */

	/**
	 * Returns the translations of an object as `[ code => object_id ]`.
	 *
	 * @param string               $object_type 'post' | 'term'.
	 * @param int                  $object_id   Object id.
	 * @param array<string,mixed>  $args        {
	 *     Optional filters.
	 *
	 *     @type bool         $include_self Include the queried object. Default false.
	 *     @type bool         $viewable     Front-end: keep only publicly viewable
	 *                                      posts (`is_post_publicly_viewable`). Default false.
	 *     @type string|array $status       Restrict posts to these statuses.
	 * }
	 * @return array<string,int> Map of language code to object id.
	 */
	public function get_translations( string $object_type, int $object_id, array $args = array() ): array {
		$this->assert_type( $object_type );

		$group = $this->get_group( $object_type, $object_id );
		if ( '' === $group ) {
			return array();
		}

		$members = $this->get_group_members( $object_type, $group );

		$include_self = ! empty( $args['include_self'] );
		$viewable     = ! empty( $args['viewable'] );
		$status       = $args['status'] ?? null;

		$out = array();
		foreach ( $members as $code => $member_id ) {
			if ( ! $include_self && $member_id === $object_id ) {
				continue;
			}

			if ( 'post' === $object_type ) {
				$post = get_post( $member_id );
				if ( ! $post ) {
					continue;
				}
				if ( $viewable && ! is_post_publicly_viewable( $post ) ) {
					continue;
				}
				if ( null !== $status && ! in_array( $post->post_status, (array) $status, true ) ) {
					continue;
				}
			}

			$out[ $code ] = $member_id;
		}

		return $out;
	}

	/**
	 * Links a translation into the source's group, enforcing one member per
	 * language (C2).
	 *
	 * @param string $object_type    'post' | 'term'.
	 * @param int    $source_id      An existing member of the target group.
	 * @param int    $translation_id The object to add to the group.
	 * @return true|WP_Error
	 */
	public function link_translation( string $object_type, int $source_id, int $translation_id ) {
		$this->assert_type( $object_type );

		$code = $this->get_language( $object_type, $translation_id );
		if ( '' === $code ) {
			return new WP_Error(
				'wpnt_no_language',
				__( 'The translation has no language assigned.', 'native-translations' )
			);
		}

		$group   = $this->ensure_group( $object_type, $source_id );
		$members = $this->get_group_members( $object_type, $group );

		if ( isset( $members[ $code ] ) && $members[ $code ] !== $translation_id ) {
			return new WP_Error(
				'wpnt_language_exists',
				sprintf(
					/* translators: %s: language code. */
					__( 'This translation group already has a member in language "%s".', 'native-translations' ),
					$code
				)
			);
		}

		if ( 'term' === $object_type ) {
			update_term_meta( $translation_id, self::META_GROUP, $group );
		} else {
			update_post_meta( $translation_id, self::META_GROUP, $group );
		}

		$this->bust_group_cache( $object_type, $group );

		return true;
	}

	/**
	 * Removes an object from its translation group.
	 *
	 * @param string $object_type    'post' | 'term'.
	 * @param int    $translation_id Object id.
	 * @return void
	 */
	public function unlink_translation( string $object_type, int $translation_id ): void {
		$this->assert_type( $object_type );

		$group = $this->get_group( $object_type, $translation_id );

		if ( 'term' === $object_type ) {
			delete_term_meta( $translation_id, self::META_GROUP );
		} else {
			delete_post_meta( $translation_id, self::META_GROUP );
		}

		$this->bust_group_cache( $object_type, $group );
	}

	/* ---------------------------------------------------------------------
	 * Group membership + caching (C1)
	 * ------------------------------------------------------------------- */

	/**
	 * Resolves all members of a group as `[ code => object_id ]`, cached in the
	 * object cache with a transient fallback. Includes every status; callers
	 * filter for viewability/status at read time.
	 *
	 * @param string $object_type 'post' | 'term'.
	 * @param string $group       Group id.
	 * @return array<string,int>
	 */
	private function get_group_members( string $object_type, string $group ): array {
		if ( '' === $group ) {
			return array();
		}

		$key    = $this->cache_key( $object_type, $group );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$transient = get_transient( $key );
		if ( is_array( $transient ) ) {
			wp_cache_set( $key, $transient, self::CACHE_GROUP );
			return $transient;
		}

		$members = 'term' === $object_type
			? $this->query_term_members( $group )
			: $this->query_post_members( $group );

		wp_cache_set( $key, $members, self::CACHE_GROUP );
		set_transient( $key, $members, DAY_IN_SECONDS );

		return $members;
	}

	/**
	 * Queries post members of a group using the indexed meta_key/meta_value
	 * (never a LIKE scan — C1). Includes all statuses incl. trash.
	 *
	 * @param string $group Group id.
	 * @return array<string,int>
	 */
	private function query_post_members( string $group ): array {
		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => array_keys( get_post_stati() ),
				'meta_key'               => self::META_GROUP, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => $group,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'                 => 'ids',
				// A group holds at most one member per language, so this cap is
				// effectively "number of configured languages" and never bites in
				// practice; it is a defensive bound, not a paging limit.
				'posts_per_page'         => 100,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				// Primed: every returned id is immediately passed to get_language()
				// → wp_get_object_terms(), so caching object terms here avoids an
				// N+1 of uncached term lookups.
				'update_post_term_cache' => true,
				'ignore_sticky_posts'    => true,
			)
		);

		$members = array();
		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			$code    = $this->get_language( 'post', $post_id );
			if ( '' !== $code ) {
				$members[ $code ] = $post_id;
			}
		}

		return $members;
	}

	/**
	 * Queries term members of a group via indexed term meta.
	 *
	 * @param string $group Group id.
	 * @return array<string,int>
	 */
	private function query_term_members( string $group ): array {
		$term_ids = get_terms(
			array(
				'taxonomy'   => array( 'category', 'post_tag' ),
				'hide_empty' => false,
				'fields'     => 'ids',
				// Defensive bound: a group holds at most one member per language,
				// so this is "number of configured languages" in practice.
				'number'     => 100,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_GROUP,
						'value' => $group,
					),
				),
			)
		);

		if ( is_wp_error( $term_ids ) ) {
			return array();
		}

		$members = array();
		foreach ( $term_ids as $term_id ) {
			$term_id = (int) $term_id;
			$code    = $this->get_language( 'term', $term_id );
			if ( '' !== $code ) {
				$members[ $code ] = $term_id;
			}
		}

		return $members;
	}

	/**
	 * Builds the cache key for a group.
	 *
	 * @param string $object_type 'post' | 'term'.
	 * @param string $group       Group id.
	 * @return string
	 */
	private function cache_key( string $object_type, string $group ): string {
		return 'wpnt_group_' . $object_type . '_' . $group;
	}

	/**
	 * Invalidates the cached membership map for a group.
	 *
	 * @param string $object_type 'post' | 'term'.
	 * @param string $group       Group id.
	 * @return void
	 */
	public function bust_group_cache( string $object_type, string $group ): void {
		if ( '' === $group ) {
			return;
		}
		$key = $this->cache_key( $object_type, $group );
		wp_cache_delete( $key, self::CACHE_GROUP );
		delete_transient( $key );
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle hooks (C1/C2)
	 * ------------------------------------------------------------------- */

	/**
	 * Busts the cache for a post's group on save.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function on_save_post( $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->bust_group_cache( 'post', $this->get_group( 'post', (int) $post_id ) );
	}

	/**
	 * Busts the cache on trash/untrash/before-delete of a post.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function on_post_lifecycle( $post_id ): void {
		$this->bust_group_cache( 'post', $this->get_group( 'post', (int) $post_id ) );
	}

	/**
	 * Busts the cache when an object's language terms change.
	 *
	 * @param int    $object_id Object id.
	 * @param array  $terms     Terms.
	 * @param array  $tt_ids    Term taxonomy ids.
	 * @param string $taxonomy  Taxonomy.
	 * @return void
	 */
	public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( Wpnt_Languages::TAXONOMY !== $taxonomy ) {
			return;
		}
		$this->bust_group_cache( 'post', $this->get_group( 'post', (int) $object_id ) );
	}

	/**
	 * Busts the cache when a category/tag is edited.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function on_edited_term( $term_id, $tt_id, $taxonomy ): void {
		if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			return;
		}
		$this->bust_group_cache( 'term', $this->get_group( 'term', (int) $term_id ) );
	}

	/**
	 * Busts the cache before a category/tag is deleted.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function on_pre_delete_term( $term_id, $taxonomy ): void {
		if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			return;
		}
		$this->bust_group_cache( 'term', $this->get_group( 'term', (int) $term_id ) );
	}

	/**
	 * Guards the object type allowlist (S5).
	 *
	 * @param string $object_type Type to validate.
	 * @return void
	 */
	private function assert_type( string $object_type ): void {
		if ( ! in_array( $object_type, self::OBJECT_TYPES, true ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'Invalid object type "%s". Expected "post" or "term".', $object_type ) ),
				'0.1.0'
			);
		}
	}
}
