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
	 * Background translation queue (#55).
	 *
	 * @var Wpait_Queue
	 */
	private Wpait_Queue $queue;

	/**
	 * Constructor.
	 *
	 * @param Wpait_Translation_Store $store      Translation store.
	 * @param Wpait_Languages         $languages  Languages handler.
	 * @param Wpait_Translator        $translator AI translator.
	 * @param Wpait_Queue             $queue      Background translation queue.
	 */
	public function __construct( Wpait_Translation_Store $store, Wpait_Languages $languages, Wpait_Translator $translator, Wpait_Queue $queue ) {
		$this->store      = $store;
		$this->languages  = $languages;
		$this->translator = $translator;
		$this->queue      = $queue;
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
			'/enqueue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_enqueue' ),
				'permission_callback' => array( $this, 'permission_bulk_enqueue' ),
				'args'                => array(
					'items'       => array(
						'type'     => 'array',
						'required' => true,
					),
					'target_code' => $code_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/set-languages',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_set_languages' ),
				'permission_callback' => array( $this, 'permission_bulk_enqueue' ),
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
					),
					'code'  => $code_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/enqueue-detection',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_enqueue_detection' ),
				'permission_callback' => array( $this, 'permission_bulk_enqueue' ),
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/queue-status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_queue_status' ),
				'permission_callback' => array( $this, 'permission_bulk_enqueue' ),
			)
		);

		register_rest_route(
			self::NS,
			'/failed-jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_failed_jobs' ),
				'permission_callback' => array( $this, 'permission_bulk_enqueue' ),
			)
		);

		register_rest_route(
			self::NS,
			'/queue-jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_queue_jobs' ),
				'permission_callback' => array( $this, 'permission_bulk_enqueue' ),
			)
		);

		register_rest_route(
			self::NS,
			'/retry-job',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_retry_job' ),
				'permission_callback' => array( $this, 'permission_bulk_enqueue' ),
				'args'                => array(
					'action_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/queue/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_cancel_job' ),
				'permission_callback' => array( $this, 'permission_manage' ),
				'args'                => array(
					'action_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return absint( $value ) > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/queue/cancel-all',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_cancel_all' ),
				'permission_callback' => array( $this, 'permission_manage' ),
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

		register_rest_route(
			self::NS,
			'/test-connection',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_test_connection' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/unlink',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_unlink' ),
				'permission_callback' => array( $this, 'permission_edit_target' ),
				'args'                => array(
					'object_id' => $id_arg,
					'type'      => $type_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_delete' ),
				'permission_callback' => array( $this, 'permission_delete_target' ),
				'args'                => array(
					'object_id' => $id_arg,
					'type'      => $type_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/install-language-pack',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_install_language_pack' ),
				'permission_callback' => array( $this, 'permission_manage' ),
				'args'                => array(
					'locale' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/overview-visibility',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_overview_visibility' ),
				'permission_callback' => array( $this, 'permission_manage' ),
				'args'                => array(
					'object_id' => $id_arg,
					'type'      => $type_arg,
					'hidden'    => array(
						'type'    => 'boolean',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/overview-original',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_overview_original' ),
				'permission_callback' => array( $this, 'permission_edit_target' ),
				'args'                => array(
					'object_id'   => $id_arg,
					'type'        => $type_arg,
					'is_original' => array(
						'type'     => 'boolean',
						'required' => true,
					),
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
		return $this->can_translate_source(
			(string) $request->get_param( 'type' ),
			(int) $request->get_param( 'source_id' )
		);
	}

	/**
	 * Resolves "may translate this source object" capability for a concrete object
	 * (S2). Shared by the single `/translate` permission callback and the per-item
	 * authorization inside `/enqueue`, so the rule lives in exactly one place.
	 *
	 * @param string $type      'post' | 'term'.
	 * @param int    $source_id Source object id.
	 * @return true|WP_Error
	 */
	private function can_translate_source( string $type, int $source_id ) {
		if ( 'term' === $type ) {
			$term = get_term( $source_id );
			if ( ! $term instanceof WP_Term ) {
				return $this->not_found();
			}
			$tax = get_taxonomy( $term->taxonomy );
			if ( ! $tax || ! current_user_can( $tax->cap->manage_terms ) ) {
				return $this->forbidden();
			}
			return true;
		}

		$post = get_post( $source_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		$pto = get_post_type_object( $post->post_type );
		if ( ! $pto || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( $pto->cap->create_posts ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * Coarse gate for the bulk queue endpoints (`/enqueue`, `/queue-status`): the
	 * caller must be a content editor. The fine-grained per-target capability check
	 * runs per item inside the handler (mirroring how WordPress bulk endpoints work).
	 *
	 * @return bool
	 */
	public function permission_bulk_enqueue(): bool {
		return current_user_can( 'edit_posts' );
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
	 * `POST /enqueue` — schedules background translations for many items (#55).
	 *
	 * Body: `{ items: [{ id:int, type:'post'|'term' }], target_code: string }`.
	 * Each item is authorized with the same per-target capability rule as
	 * `/translate`; items the caller cannot translate, or that are invalid, are
	 * skipped. Returns `{ queued, skipped, results:[{ id, type, status }] }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_enqueue( WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'target_code' );

		$lang_error = $this->validate_enabled_language( $code );
		if ( is_wp_error( $lang_error ) ) {
			return $lang_error;
		}

		$items   = (array) $request->get_param( 'items' );
		$queued  = 0;
		$skipped = 0;
		$results = array();

		foreach ( $items as $item ) {
			$item = (array) $item;
			$id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$type = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';

			if ( $id <= 0 || ! in_array( $type, self::TYPES, true ) ) {
				++$skipped;
				$results[] = array( 'id' => $id, 'type' => $type, 'status' => 'invalid' );
				continue;
			}

			// Per-item capability check: identical rule to `/translate`.
			$allowed = $this->can_translate_source( $type, $id );
			if ( is_wp_error( $allowed ) ) {
				++$skipped;
				$results[] = array( 'id' => $id, 'type' => $type, 'status' => 'forbidden' );
				continue;
			}

			$status    = $this->queue->enqueue( $type, $id, $code );
			$results[] = array( 'id' => $id, 'type' => $type, 'status' => $status );

			if ( 'queued' === $status ) {
				++$queued;
			} else {
				++$skipped;
			}
		}

		return rest_ensure_response(
			array(
				'queued'  => $queued,
				'skipped' => $skipped,
				'results' => $results,
			)
		);
	}

	/**
	 * `POST /set-languages` — synchronously assigns one language to many items (#56).
	 *
	 * Manual, no AI: body `{ items:[{ id, type }], code }`. Each item is authorized
	 * with the same per-target edit rule as `/set-language`; the store's invariants
	 * still apply (e.g. it rejects reassigning a grouped member with siblings), which
	 * surfaces as a per-item `error`. Returns `{ set, skipped, results:[{ id, type,
	 * status }] }` with status ∈ `set`|`error`|`forbidden`|`invalid`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_set_languages( WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'code' );

		$lang_error = $this->validate_enabled_language( $code );
		if ( is_wp_error( $lang_error ) ) {
			return $lang_error;
		}

		$items   = (array) $request->get_param( 'items' );
		$set     = 0;
		$skipped = 0;
		$results = array();

		foreach ( $items as $item ) {
			$item = (array) $item;
			$id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$type = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';

			if ( $id <= 0 || ! in_array( $type, self::TYPES, true ) ) {
				++$skipped;
				$results[] = array( 'id' => $id, 'type' => $type, 'status' => 'invalid' );
				continue;
			}

			if ( is_wp_error( $this->can_edit_target( $type, $id ) ) ) {
				++$skipped;
				$results[] = array( 'id' => $id, 'type' => $type, 'status' => 'forbidden' );
				continue;
			}

			$result = $this->store->set_language( $type, $id, $code );
			if ( is_wp_error( $result ) ) {
				++$skipped;
				$results[] = array( 'id' => $id, 'type' => $type, 'status' => 'error', 'message' => $result->get_error_message() );
				continue;
			}

			++$set;
			$results[] = array( 'id' => $id, 'type' => $type, 'status' => 'set' );
		}

		return rest_ensure_response(
			array(
				'set'     => $set,
				'skipped' => $skipped,
				'results' => $results,
			)
		);
	}

	/**
	 * `POST /enqueue-detection` — queues AI language-detection jobs for many items (#56).
	 *
	 * Body: `{ items:[{ id, type }] }`. Each item is authorized with the same
	 * per-target edit rule as `/set-languages`; the queue then skips items that
	 * already carry a language (`has_language`). Returns `{ queued, skipped,
	 * results:[{ id, type, status }] }` with status from {@see Wpait_Queue::enqueue_detection()}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_enqueue_detection( WP_REST_Request $request ) {
		$items   = (array) $request->get_param( 'items' );
		$queued  = 0;
		$skipped = 0;
		$results = array();

		foreach ( $items as $item ) {
			$item = (array) $item;
			$id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$type = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';

			if ( $id <= 0 || ! in_array( $type, self::TYPES, true ) ) {
				++$skipped;
				$results[] = array( 'id' => $id, 'type' => $type, 'status' => 'invalid' );
				continue;
			}

			if ( is_wp_error( $this->can_edit_target( $type, $id ) ) ) {
				++$skipped;
				$results[] = array( 'id' => $id, 'type' => $type, 'status' => 'forbidden' );
				continue;
			}

			$status    = $this->queue->enqueue_detection( $type, $id );
			$results[] = array( 'id' => $id, 'type' => $type, 'status' => $status );

			if ( 'queued' === $status ) {
				++$queued;
			} else {
				++$skipped;
			}
		}

		return rest_ensure_response(
			array(
				'queued'  => $queued,
				'skipped' => $skipped,
				'results' => $results,
			)
		);
	}

	/**
	 * `GET /queue-status` — pending/running/failed counts for the Overview banner.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_queue_status() {
		return rest_ensure_response( $this->queue->get_status() );
	}

	/**
	 * `GET /failed-jobs` — lists this plugin's failed background jobs (#69) so the
	 * Overview can surface which items failed and why, without leaving the plugin.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_failed_jobs() {
		return rest_ensure_response( array( 'jobs' => $this->queue->get_failed_jobs() ) );
	}

	/**
	 * `GET /queue-jobs` — lists this plugin's queued + processing background jobs
	 * (#81) so the Overview can show which translations are waiting/running, not
	 * just a count.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_queue_jobs() {
		return rest_ensure_response( array( 'jobs' => $this->queue->get_pending_jobs() ) );
	}

	/**
	 * `POST /retry-job` — re-runs a single failed job by action id (#69),
	 * re-enqueueing it and clearing the failed record.
	 *
	 * @param WP_REST_Request $request Request with `action_id`.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_retry_job( WP_REST_Request $request ) {
		$action_id = (int) $request->get_param( 'action_id' );

		// Per-item authorization: the Overview is admin-gated, but re-running a job
		// re-touches its target, so enforce the same edit capability the enqueue
		// endpoints do. Block only an explicit "forbidden" (the user exists-but-can't
		// edit this item); a not-found target (e.g. the post was deleted) is harmless
		// to retry and stays clearable from the failed list.
		$desc = $this->queue->describe_action( $action_id );
		if ( $desc && '' !== $desc['type'] && $desc['id'] > 0 ) {
			$cap = $this->can_edit_target( $desc['type'], $desc['id'] );
			if ( is_wp_error( $cap ) && 'wpait_forbidden' === $cap->get_error_code() ) {
				return $cap;
			}
		}

		$result = $this->queue->retry_job( $action_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'retried' => true ) );
	}

	/**
	 * `POST /queue/cancel` — cancels a single scheduled job by `action_id` (#96),
	 * unscheduling a pending action via Action Scheduler (best-effort for a running
	 * one). Admin-only (`manage_options`) and nonce-protected like the other write
	 * routes.
	 *
	 * @param WP_REST_Request $request Request with `action_id`.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_cancel_job( WP_REST_Request $request ) {
		$action_id = (int) $request->get_param( 'action_id' );

		$result = $this->queue->cancel_job( $action_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'cancelled' => true ) );
	}

	/**
	 * `POST /queue/cancel-all` — cancels every pending (and best-effort running) job
	 * in this plugin's queue group (#96). Admin-only (`manage_options`). Returns the
	 * number of actions cancelled.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_cancel_all() {
		return rest_ensure_response( array( 'cancelled' => $this->queue->cancel_all() ) );
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

	/**
	 * `POST /test-connection` — runs a trivial live translation so an admin can
	 * verify, from the settings page, that the AI provider actually works (a real
	 * round trip, so it catches credential/model/network failures the metadata-only
	 * availability probe cannot). Admin-only.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_test_connection() {
		$result = $this->translator->test_connection();
		return rest_ensure_response( $result );
	}

	/**
	 * `POST /install-language-pack` — installs a configured locale's core language
	 * pack (#62) so dates and core/theme UI strings can render in that language.
	 *
	 * Body `{ locale }`. The locale is validated against the site's configured
	 * locales inside the languages handler (never an arbitrary download). Returns
	 * `{ installed, locale, available }`. Admin-only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_install_language_pack( WP_REST_Request $request ) {
		$locale = (string) $request->get_param( 'locale' );

		$result = $this->languages->install_language_pack( $locale );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'installed' => true,
				'locale'    => $locale,
				'available' => get_available_languages(),
			)
		);
	}

	/**
	 * `POST /overview-visibility` — hides or unhides an item from the Overview's
	 * missing-translations list (#21 per-item curation). Admin-only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_overview_visibility( WP_REST_Request $request ) {
		Wpait_Plugin::instance()->admin_list()->set_overview_hidden(
			(string) $request->get_param( 'type' ),
			(int) $request->get_param( 'object_id' ),
			(bool) $request->get_param( 'hidden' )
		);
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * `POST /overview-original` — marks or unmarks an item as a translation original
	 * from the Overview (#92). Marking is rejected by the store when the item has no
	 * language assigned. Gated on edit capability for the concrete target object.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_overview_original( WP_REST_Request $request ) {
		$result = $this->store->set_original(
			(string) $request->get_param( 'type' ),
			(int) $request->get_param( 'object_id' ),
			(bool) $request->get_param( 'is_original' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * `POST /unlink` — removes a translation from its group (keeps the content).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_unlink( WP_REST_Request $request ) {
		$this->store->unlink_translation(
			(string) $request->get_param( 'type' ),
			(int) $request->get_param( 'object_id' )
		);
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * `POST /delete` — removes a translation: unlinks it from its group, then trashes
	 * the post (recoverable) or deletes the term. Gated on delete capability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_delete( WP_REST_Request $request ) {
		$type      = (string) $request->get_param( 'type' );
		$object_id = (int) $request->get_param( 'object_id' );

		// Leave the group first so it never references a trashed/removed member.
		$this->store->unlink_translation( $type, $object_id );

		if ( 'term' === $type ) {
			$term = get_term( $object_id );
			if ( $term instanceof WP_Term ) {
				wp_delete_term( $object_id, $term->taxonomy );
			}
			return rest_ensure_response( array( 'ok' => true, 'deleted' => 'term' ) );
		}

		$trashed = wp_trash_post( $object_id );
		if ( ! $trashed ) {
			return new WP_Error( 'wpait_delete_failed', __( 'The translation could not be trashed.', 'wp-ai-translate' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'deleted' => 'post' ) );
	}

	/**
	 * Permission for `/delete`: the user must be able to delete that target object
	 * (post: `delete_post`; term: the taxonomy's `delete_terms`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission_delete_target( WP_REST_Request $request ) {
		$type      = (string) $request->get_param( 'type' );
		$object_id = (int) $request->get_param( 'object_id' );

		if ( 'term' === $type ) {
			$term = get_term( $object_id );
			if ( ! $term instanceof WP_Term ) {
				return $this->not_found();
			}
			$tax = get_taxonomy( $term->taxonomy );
			return ( $tax && current_user_can( $tax->cap->delete_terms ) ) ? true : $this->forbidden();
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		return current_user_can( 'delete_post', $object_id ) ? true : $this->forbidden();
	}

	/**
	 * Permission for site-level diagnostics (`/test-connection`): the same
	 * capability that gates the settings page itself.
	 *
	 * @return bool
	 */
	public function permission_manage(): bool {
		return current_user_can( 'manage_options' );
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
			'is_original'  => $this->store->is_original( $type, $object_id ),
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
			$term      = get_term( $object_id );
			$view_link = get_term_link( $object_id );
			return array(
				'id'        => $object_id,
				'label'     => $term instanceof WP_Term ? $term->name : '',
				'status'    => '', // Terms have no status.
				'edit_link' => get_edit_term_link( $object_id ),
				'view_link' => is_wp_error( $view_link ) ? '' : (string) $view_link,
			);
		}

		$post   = get_post( $object_id );
		$status = $post instanceof WP_Post ? $post->post_status : '';
		// Published content links to its permalink; unpublished to a preview URL.
		$view_link = '';
		if ( $post instanceof WP_Post ) {
			$view_link = 'publish' === $status ? (string) get_permalink( $object_id ) : (string) get_preview_post_link( $object_id );
		}
		return array(
			'id'        => $object_id,
			'label'     => $post instanceof WP_Post ? get_the_title( $post ) : '',
			'status'    => $status,
			'edit_link' => get_edit_post_link( $object_id, 'raw' ),
			'view_link' => $view_link,
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
