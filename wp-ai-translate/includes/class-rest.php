<?php
/**
 * REST API: namespace `wp-ai-translate/v1`.
 *
 * Each route resolves the concrete target object and checks the correct
 * capability for that object type against that object — never a blanket
 * `edit_posts` (S2). The `type` parameter is validated against an allowlist and
 * language codes against the configured, enabled languages.
 *
 * @package WpAiTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the editor/term REST endpoints used by the sidebar panel and the
 * term meta box.
 */
class Wpait_Rest {

	const NS = 'wp-ai-translate/v1';

	/**
	 * Allowed object types.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'post', 'term' );

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
	 * AI translator.
	 *
	 * @var Wpait_Translator
	 */
	private Wpait_Translator $translator;

	/**
	 * Constructor.
	 *
	 * @param Wpait_Translation_Store $store      Translation store.
	 * @param Wpait_Languages         $languages  Languages handler.
	 * @param Wpait_Translator        $translator AI translator.
	 */
	public function __construct( Wpait_Translation_Store $store, Wpait_Languages $languages, Wpait_Translator $translator ) {
		$this->store      = $store;
		$this->languages  = $languages;
		$this->translator = $translator;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers all routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$types    = self::TYPES;
		$type_arg = array(
			'type'              => 'string',
			'required'          => true,
			'enum'              => $types,
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => static function ( $value ) use ( $types ) {
				return in_array( $value, $types, true );
			},
		);
		$id_arg   = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => static function ( $value ) {
				return absint( $value ) > 0;
			},
		);
		$code_arg = array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_title',
		);

		register_rest_route(
			self::NS,
			'/translate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_translate' ),
				'permission_callback' => array( $this, 'permission_translate' ),
				'args'                => array(
					'source_id'   => $id_arg,
					'target_code' => $code_arg,
					'type'        => $type_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/recreate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_recreate' ),
				'permission_callback' => array( $this, 'permission_edit_target' ),
				'args'                => array(
					'object_id' => $id_arg,
					'type'      => $type_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/translations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_translations' ),
				'permission_callback' => array( $this, 'permission_edit_target' ),
				'args'                => array(
					'object_id' => $id_arg,
					'type'      => $type_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/set-language',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_set_language' ),
				'permission_callback' => array( $this, 'permission_edit_target' ),
				'args'                => array(
					'object_id' => $id_arg,
					'code'      => $code_arg,
					'type'      => $type_arg,
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Permission callbacks (S2 — resolved per target object type)
	 * ------------------------------------------------------------------- */

	/**
	 * Permission for `/translate`: the user must be able to edit the source AND
	 * to create the new translation object (post: `edit_post` on source +
	 * `create_posts` for its type; term: `manage_terms` for the taxonomy).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission_translate( WP_REST_Request $request ) {
		$type = (string) $request->get_param( 'type' );
		$id   = (int) $request->get_param( 'source_id' );

		if ( 'term' === $type ) {
			$term = get_term( $id );
			if ( ! $term instanceof WP_Term ) {
				return $this->not_found();
			}
			$tax = get_taxonomy( $term->taxonomy );
			if ( ! $tax || ! current_user_can( $tax->cap->manage_terms ) ) {
				return $this->forbidden();
			}
			return true;
		}

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		$pto = get_post_type_object( $post->post_type );
		if ( ! $pto || ! current_user_can( 'edit_post', $id ) || ! current_user_can( $pto->cap->create_posts ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * Permission for routes keyed on an existing target object (`/recreate`,
	 * `/set-language`, and the edit-context read `/translations`): the user must be
	 * able to edit that target (post: `edit_post`; term: `edit_term`). Reading the
	 * edit-context siblings is gated on the same edit access.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission_edit_target( WP_REST_Request $request ) {
		return $this->can_edit_target(
			(string) $request->get_param( 'type' ),
			(int) $request->get_param( 'object_id' )
		);
	}

	/**
	 * Resolves edit capability for a concrete target object (S2).
	 *
	 * @param string $type      'post' | 'term'.
	 * @param int    $object_id Target object id.
	 * @return true|WP_Error
	 */
	private function can_edit_target( string $type, int $object_id ) {
		if ( 'term' === $type ) {
			$term = get_term( $object_id );
			if ( ! $term instanceof WP_Term ) {
				return $this->not_found();
			}
			return current_user_can( 'edit_term', $object_id ) ? true : $this->forbidden();
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		return current_user_can( 'edit_post', $object_id ) ? true : $this->forbidden();
	}

	/* ---------------------------------------------------------------------
	 * Route handlers
	 * ------------------------------------------------------------------- */

	/**
	 * `POST /translate` — creates a new draft translation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_translate( WP_REST_Request $request ) {
		$type      = (string) $request->get_param( 'type' );
		$source_id = (int) $request->get_param( 'source_id' );
		$code      = (string) $request->get_param( 'target_code' );

		$lang_error = $this->validate_enabled_language( $code );
		if ( is_wp_error( $lang_error ) ) {
			return $lang_error;
		}

		$result = 'term' === $type
			? $this->translator->translate_term( $source_id, $code )
			: $this->translator->translate_post( $source_id, $code );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The source's group now contains the new translation — return its view.
		return rest_ensure_response( $this->payload( $type, $source_id ) );
	}

	/**
	 * `POST /recreate` — regenerates an existing translation in place.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_recreate( WP_REST_Request $request ) {
		$type      = (string) $request->get_param( 'type' );
		$object_id = (int) $request->get_param( 'object_id' );

		$result = 'term' === $type
			? $this->translator->recreate_term( $object_id )
			: $this->translator->recreate_post( $object_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->payload( $type, $object_id ) );
	}

	/**
	 * `GET /translations` — returns the object's language + its translations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_translations( WP_REST_Request $request ) {
		return rest_ensure_response(
			$this->payload(
				(string) $request->get_param( 'type' ),
				(int) $request->get_param( 'object_id' )
			)
		);
	}

	/**
	 * `POST /set-language` — assigns the object's own language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_set_language( WP_REST_Request $request ) {
		$type      = (string) $request->get_param( 'type' );
		$object_id = (int) $request->get_param( 'object_id' );
		$code      = (string) $request->get_param( 'code' );

		$lang_error = $this->validate_enabled_language( $code );
		if ( is_wp_error( $lang_error ) ) {
			return $lang_error;
		}

		$result = $this->store->set_language( $type, $object_id, $code );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->payload( $type, $object_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Validates a language code against the configured, enabled languages.
	 *
	 * @param string $code Language code.
	 * @return true|WP_Error
	 */
	private function validate_enabled_language( string $code ) {
		// Delegates to the shared enabled-language helper (M4) instead of re-looping
		// the raw settings.
		$codes = wp_list_pluck( $this->languages->enabled(), 'code' );
		if ( in_array( $code, $codes, true ) ) {
			return true;
		}
		return new WP_Error(
			'wpait_invalid_language',
			__( 'The language is not a configured, enabled language.', 'wp-ai-translate' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Builds the response describing an object's language and translations.
	 * Editor context: all statuses are included (admin caller).
	 *
	 * @param string $type      'post' | 'term'.
	 * @param int    $object_id Object id.
	 * @return array<string,mixed>
	 */
	private function payload( string $type, int $object_id ): array {
		$language     = $this->store->get_language( $type, $object_id );
		$members      = $this->store->get_translations( $type, $object_id, array( 'include_self' => false ) );
		$translations = array();

		foreach ( $members as $code => $member_id ) {
			$translations[ $code ] = $this->describe( $type, (int) $member_id );
		}

		return array(
			'object_id'    => $object_id,
			'type'         => $type,
			'language'     => $language,
			'translations' => $translations,
		);
	}

	/**
	 * Describes a single translation member for the panel.
	 *
	 * @param string $type      'post' | 'term'.
	 * @param int    $object_id Member id.
	 * @return array<string,mixed>
	 */
	private function describe( string $type, int $object_id ): array {
		if ( 'term' === $type ) {
			$term = get_term( $object_id );
			return array(
				'id'        => $object_id,
				'label'     => $term instanceof WP_Term ? $term->name : '',
				'status'    => '', // Terms have no status.
				'edit_link' => get_edit_term_link( $object_id ),
			);
		}

		$post = get_post( $object_id );
		return array(
			'id'        => $object_id,
			'label'     => $post instanceof WP_Post ? get_the_title( $post ) : '',
			'status'    => $post instanceof WP_Post ? $post->post_status : '',
			'edit_link' => get_edit_post_link( $object_id, 'raw' ),
		);
	}

	/**
	 * 403 helper.
	 *
	 * @return WP_Error
	 */
	private function forbidden(): WP_Error {
		return new WP_Error(
			'wpait_forbidden',
			__( 'You are not allowed to translate this item.', 'wp-ai-translate' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * 404 helper.
	 *
	 * @return WP_Error
	 */
	private function not_found(): WP_Error {
		return new WP_Error(
			'wpait_no_source',
			__( 'The requested item was not found.', 'wp-ai-translate' ),
			array( 'status' => 404 )
		);
	}
}
