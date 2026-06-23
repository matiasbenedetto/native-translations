<?php
/**
 * PHPUnit bootstrap for the wp-ai-translate unit suite.
 *
 * These are true unit tests: no database, no Docker, no WordPress install. The
 * handful of WordPress functions the units touch are stubbed here, and the WP 7.0
 * AI connector is replaced by a recording fake ({@see Wpait_Fake_Prompt_Builder})
 * so tests can assert exactly which model/temperature a request would use — the
 * core of the bug #32 regression guard.
 *
 * @package WpAiTranslate\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPAIT_TESTING', true );

/**
 * Mutable test state shared with the WordPress stubs below. Reset in each test's
 * setUp() via Wpait_Test_State::reset().
 */
final class Wpait_Test_State {
	/** @var bool Return value of wp_supports_ai(). */
	public static bool $supports_ai = true;

	/** @var bool Return value of the connector's is_supported() probe. */
	public static bool $is_supported = true;

	/** @var array<string,mixed> Transient store. */
	public static array $transients = array();

	/** @var array<string,mixed> Filter overrides keyed by tag (value returned verbatim). */
	public static array $filters = array();

	/** @var mixed Result the fake connector returns from generate_text(). */
	public static $connector_result = 'Hola';

	/**
	 * @var array<int,mixed> Queue of results returned by successive generate_text()
	 * calls (shifted in order). When empty, generate_text() falls back to
	 * $connector_result. Lets a test simulate "first call 404s, retry succeeds".
	 */
	public static array $connector_results = array();

	/** @var array<string,mixed>|null The recorded builder calls from the last generate_text(). */
	public static ?array $last_request = null;

	/**
	 * @var array<int,array<string,mixed>> The recorded builder calls from every
	 * generate_text() call, in order — lets the #32 retry tests assert both the
	 * first (auto-pick) and the second (fallback) request.
	 */
	public static array $requests = array();

	/** @var array<string,mixed> WP options store (get_option). */
	public static array $options = array();

	/* ---------------------------------------------------------------------
	 * In-memory object model for the REAL Wpait_Translation_Store (and the
	 * admin-list / frontend units that read through it). There is no DB: the
	 * WP_Query / get_terms / *_meta / object-term stubs below all read and write
	 * these arrays, so a test can model groups + languages declaratively.
	 * ------------------------------------------------------------------- */

	/**
	 * Posts keyed by id: each row is `[ 'ID' => int, 'post_type' => string,
	 * 'post_status' => string ]`. get_post() returns a WP_Post wrapping the row.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $posts = array();

	/**
	 * Terms keyed by id: each row is `[ 'term_id' => int, 'taxonomy' => string,
	 * 'name' => string, 'slug' => string, 'count' => int ]`.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $terms = array();

	/** @var array<int,array<string,string>> Post meta: `[ post_id => [ key => value ] ]`. */
	public static array $post_meta = array();

	/** @var array<int,array<string,string>> Term meta: `[ term_id => [ key => value ] ]`. */
	public static array $term_meta = array();

	/**
	 * Object terms (post → taxonomy → slugs), used for the `wpait_language`
	 * taxonomy a post carries its language through.
	 *
	 * @var array<int,array<string,string[]>>
	 */
	public static array $object_terms = array();

	/** @var array<string,array<string,mixed>> Object cache: `[ group => [ key => value ] ]`. */
	public static array $cache = array();

	/**
	 * Posts whose ids `is_post_publicly_viewable()` should reject (front-end
	 * viewability filtering). Any post id NOT in this set is treated as viewable.
	 *
	 * @var array<int,bool>
	 */
	public static array $not_viewable = array();

	/** @var array<string,bool> current_user_can() answers keyed by "cap" or "cap:id". */
	public static array $caps = array();

	/** @var int Counter feeding the deterministic wp_generate_uuid4() stub. */
	public static int $uuid_seq = 0;

	/**
	 * Current view's queried object for the frontend resolver (a WP_Post for a
	 * singular view, a WP_Term for a term archive, or null). null => not singular.
	 *
	 * @var WP_Post|WP_Term|null
	 */
	public static $queried_object = null;

	/** @var string Archive type for is_category()/is_tag(): 'category'|'post_tag'|''. */
	public static string $archive_type = '';

	/** @var bool Return value of is_admin(). */
	public static bool $is_admin = false;

	/* --- Locale switching (#62) ----------------------------------------- */

	/** @var bool Return value of wp_doing_ajax(). */
	public static bool $doing_ajax = false;

	/** @var bool Return value of is_feed(). */
	public static bool $is_feed = false;

	/** @var bool Return value of is_robots(). */
	public static bool $is_robots = false;

	/** @var bool Return value of is_main_query(). */
	public static bool $is_main_query = true;

	/** @var bool Return value of is_tax() (custom-tax archive). */
	public static bool $is_tax = false;

	/** @var string Return value of get_locale(). */
	public static string $locale = 'en_US';

	/** @var string[] Locales get_available_languages() reports as installed. */
	public static array $available_languages = array();

	/** @var string[] Locales passed to switch_to_locale(), in order. */
	public static array $switched_locales = array();

	/** @var string[] Locales passed to wp_download_language_pack(), in order. */
	public static array $downloaded_locales = array();

	/** @var string|false Result wp_download_language_pack() returns ('' => echo back locale). */
	public static $download_result = '';

	/**
	 * Recorded calls to as_enqueue_async_action(): each is `[ hook, args, group ]`.
	 *
	 * @var array<int,array{0:string,1:array,2:string}>
	 */
	public static array $as_enqueued = array();

	/**
	 * Hook+args+group tuples as_has_scheduled_action() should report as already
	 * scheduled. Keyed by a serialized signature.
	 *
	 * @var array<string,bool>
	 */
	public static array $as_scheduled = array();

	/**
	 * Action Scheduler status counts the get_status() store query returns, keyed by
	 * status constant string.
	 *
	 * @var array<string,int>
	 */
	public static array $as_counts = array();

	/**
	 * Recorded calls to as_schedule_single_action(): each is `[ timestamp, hook, args, group ]`.
	 *
	 * @var array<int,array{0:int,1:string,2:array,3:string}>
	 */
	public static array $as_scheduled_single = array();

	/**
	 * Failed Action Scheduler actions keyed by action id, each
	 * `[ hook, args, message ]`, used by the fetch_action/query/logger stubs (#69).
	 *
	 * @var array<int,array{hook:string,args:array,message:string}>
	 */
	public static array $as_failed_actions = array();

	/** @var array<int,int> Action ids passed to delete_action(). */
	public static array $as_deleted = array();

	/** @var bool When true, as_schedule_single_action() returns 0 (scheduling failed). */
	public static bool $as_schedule_fails = false;

	/** @var object|null Return value of get_current_screen() (e.g. ->base, ->taxonomy). */
	public static $current_screen = null;

	public static function reset(): void {
		self::$supports_ai      = true;
		self::$is_supported     = true;
		self::$transients       = array();
		self::$filters          = array();
		self::$connector_result = 'Hola';
		self::$connector_results = array();
		self::$last_request     = null;
		self::$requests          = array();
		self::$options          = array();
		self::$posts            = array();
		self::$terms            = array();
		self::$post_meta        = array();
		self::$term_meta        = array();
		self::$object_terms     = array();
		self::$cache            = array();
		self::$not_viewable     = array();
		self::$caps             = array();
		self::$uuid_seq         = 0;
		self::$queried_object   = null;
		self::$archive_type     = '';
		self::$is_admin         = false;
		self::$doing_ajax        = false;
		self::$is_feed           = false;
		self::$is_robots         = false;
		self::$is_main_query     = true;
		self::$is_tax            = false;
		self::$locale            = 'en_US';
		self::$available_languages = array();
		self::$switched_locales  = array();
		self::$downloaded_locales = array();
		self::$download_result   = '';
		self::$current_screen   = null;
		self::$as_enqueued      = array();
		self::$as_scheduled     = array();
		self::$as_counts        = array();
		self::$as_scheduled_single = array();
		self::$as_failed_actions = array();
		self::$as_deleted       = array();
		self::$as_schedule_fails = false;
		$_GET                   = array();
	}
}

/**
 * Recording fake of WP_AI_Client_Prompt_Builder. Captures the fluent calls so a
 * test can assert the model/temperature/system instruction a request would carry.
 */
final class Wpait_Fake_Prompt_Builder {
	/** @var array<string,mixed> */
	public array $calls = array();

	public function __construct( $prompt ) {
		$this->calls['prompt'] = $prompt;
	}

	public function using_system_instruction( $s ): self {
		$this->calls['system'] = $s;
		return $this;
	}

	public function using_temperature( $t ): self {
		$this->calls['temperature'] = $t;
		return $this;
	}

	public function using_model_preference( ...$models ): self {
		$this->calls['models'] = $models;
		return $this;
	}

	public function is_supported(): bool {
		return Wpait_Test_State::$is_supported;
	}

	public function generate_text() {
		Wpait_Test_State::$requests[]    = $this->calls;
		Wpait_Test_State::$last_request = $this->calls;
		if ( ! empty( Wpait_Test_State::$connector_results ) ) {
			return array_shift( Wpait_Test_State::$connector_results );
		}
		return Wpait_Test_State::$connector_result;
	}
}

/* -------------------------------------------------------------------------
 * Minimal WordPress shims the units under test depend on.
 * ---------------------------------------------------------------------- */

class WP_Error {
	/** @var array<int,array{code:string,message:string}> */
	private array $errors = array();
	/** @var array<string,mixed> */
	private array $data = array();

	public function __construct( string $code = '', string $message = '', $data = '' ) {
		if ( '' !== $code ) {
			$this->errors[] = array( 'code' => $code, 'message' => $message );
			if ( '' !== $data && array() !== $data ) {
				$this->data[ $code ] = $data;
			}
		}
	}

	public function get_error_code(): string {
		return $this->errors[0]['code'] ?? '';
	}

	public function get_error_message(): string {
		return $this->errors[0]['message'] ?? '';
	}

	public function get_error_data() {
		$code = $this->get_error_code();
		return $this->data[ $code ] ?? null;
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function wp_supports_ai(): bool {
	return Wpait_Test_State::$supports_ai;
}

function wp_ai_client_prompt( $prompt = null ): Wpait_Fake_Prompt_Builder {
	return new Wpait_Fake_Prompt_Builder( $prompt );
}

function apply_filters( $tag, $value = null, ...$args ) {
	if ( array_key_exists( $tag, Wpait_Test_State::$filters ) ) {
		return Wpait_Test_State::$filters[ $tag ];
	}
	return $value;
}

function add_filter( ...$args ): bool {
	return true;
}

function remove_filter( ...$args ): bool {
	return true;
}

function get_transient( $key ) {
	return Wpait_Test_State::$transients[ $key ] ?? false;
}

function set_transient( $key, $value, $ttl = 0 ): bool {
	Wpait_Test_State::$transients[ $key ] = $value;
	return true;
}

function wp_generate_password( $length = 12, $special = true, $extra = false ): string {
	return str_repeat( 'a', (int) $length );
}

function get_bloginfo( $show = '' ): string {
	return 'Test Site';
}

function sanitize_title( $title ) {
	$title = strtolower( (string) $title );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
	return trim( (string) $title, '-' );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) );
}

function sanitize_textarea_field( $str ) {
	return trim( (string) $str );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function get_option( $name, $default = false ) {
	return Wpait_Test_State::$options[ $name ] ?? $default;
}

function wp_list_pluck( $list, $field, $index_key = null ) {
	$values = array_map( static fn( $row ) => is_array( $row ) ? ( $row[ $field ] ?? null ) : ( $row->$field ?? null ), (array) $list );
	if ( null === $index_key ) {
		return $values;
	}
	$keys = array_map( static fn( $row ) => is_array( $row ) ? ( $row[ $index_key ] ?? null ) : ( $row->$index_key ?? null ), (array) $list );
	return array_combine( $keys, $values );
}

function add_action( ...$args ) {
	return true;
}

/* Term/taxonomy stubs for Wpait_Languages::reconcile() (S4). These read/write the
 * in-memory $terms model so a reconcile test can seed pre-existing language terms
 * and assert add/update/disable/blocked-delete outcomes. With an empty $terms set
 * (the AdminSettingsTest path) get_term_by() returns false, i.e. "all new". */
function taxonomy_exists( $taxonomy ) {
	return true;
}

function register_taxonomy( ...$args ): bool {
	return true;
}

function register_term_meta( ...$args ): bool {
	return true;
}

function get_term_by( $field, $value, $taxonomy = '' ) {
	foreach ( Wpait_Test_State::$terms as $id => $row ) {
		if ( ( $row['taxonomy'] ?? '' ) !== $taxonomy ) {
			continue;
		}
		$haystack = 'slug' === $field ? ( $row['slug'] ?? '' ) : ( $row['name'] ?? '' );
		if ( (string) $haystack === (string) $value ) {
			return get_term( $id );
		}
	}
	return false;
}

function wp_insert_term( $term, $taxonomy, $args = array() ) {
	static $next = 1000;
	$id          = ++$next;
	$slug        = $args['slug'] ?? sanitize_title( $term );
	Wpait_Test_State::$terms[ $id ] = array(
		'term_id'  => $id,
		'taxonomy' => $taxonomy,
		'name'     => (string) $term,
		'slug'     => (string) $slug,
		'count'    => 0,
	);
	return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}

function wp_update_term( $term_id, $taxonomy, $args = array() ) {
	if ( isset( Wpait_Test_State::$terms[ (int) $term_id ] ) && isset( $args['name'] ) ) {
		Wpait_Test_State::$terms[ (int) $term_id ]['name'] = (string) $args['name'];
	}
	return array( 'term_id' => (int) $term_id, 'term_taxonomy_id' => (int) $term_id );
}

function wp_delete_term( $term_id, $taxonomy = '' ) {
	unset( Wpait_Test_State::$terms[ (int) $term_id ], Wpait_Test_State::$term_meta[ (int) $term_id ] );
	return true;
}

function update_term_meta( $term_id, $key, $value ) {
	Wpait_Test_State::$term_meta[ (int) $term_id ][ $key ] = $value;
	return true;
}

function add_settings_error( ...$args ) {
	return true;
}

// Minimal WP_Term shim (Wpait_Languages::get_term_for_code return type).
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id = 0;
		public $name = '';
		public $slug = '';
		public $taxonomy = '';
		public $description = '';
		public $count = 0;
	}
}

/* -------------------------------------------------------------------------
 * In-memory WP object model: the stubs the REAL Wpait_Translation_Store (and the
 * admin-list / frontend units that read through it) depend on. All of them read
 * and write Wpait_Test_State::$posts/$terms/$post_meta/$term_meta/$object_terms/
 * $cache, so a test models groups + languages without a database. They implement
 * real WordPress semantics (single-meta returns '' when unset, get_post() returns
 * null for an unknown id, etc.) so the tests exercise the unit, not the stub.
 * ---------------------------------------------------------------------- */

const DAY_IN_SECONDS = 86400;

if ( ! defined( 'REST_REQUEST' ) ) {
	// Frontend::resolve_post_id() reads this; default to a non-REST context.
	// (Tests that need the editor-preview branch define it themselves.)
	define( 'REST_REQUEST', false );
}

if ( ! class_exists( 'WP_Post' ) ) {
	/** Minimal WP_Post: only the fields the units read. */
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'post';
		public string $post_status = 'publish';
		public string $post_name = '';
		public string $post_title = '';
		public string $post_content = '';
		public string $post_modified_gmt = '';
		public string $post_date_gmt = '';

		/** @param array<string,mixed> $row */
		public function __construct( array $row ) {
			$this->ID                = (int) ( $row['ID'] ?? 0 );
			$this->post_type         = (string) ( $row['post_type'] ?? 'post' );
			$this->post_status       = (string) ( $row['post_status'] ?? 'publish' );
			$this->post_name         = (string) ( $row['post_name'] ?? '' );
			$this->post_title        = (string) ( $row['post_title'] ?? '' );
			$this->post_content      = (string) ( $row['post_content'] ?? '' );
			$this->post_modified_gmt = (string) ( $row['post_modified_gmt'] ?? '' );
			$this->post_date_gmt     = (string) ( $row['post_date_gmt'] ?? '' );
		}
	}
}

function absint( $n ): int {
	return abs( (int) $n );
}

function wp_generate_uuid4(): string {
	// Deterministic, unique per call — enough for group-id identity in tests.
	return sprintf( 'uuid-%04d', ++Wpait_Test_State::$uuid_seq );
}

/* --- Post / term lookups ------------------------------------------------- */

function get_post( $id = null ) {
	$id  = (int) ( $id instanceof WP_Post ? $id->ID : $id );
	$row = Wpait_Test_State::$posts[ $id ] ?? null;
	return null === $row ? null : new WP_Post( $row );
}

function get_term( $id, $taxonomy = '' ) {
	$id  = (int) $id;
	$row = Wpait_Test_State::$terms[ $id ] ?? null;
	if ( null === $row ) {
		return null;
	}
	$term           = new WP_Term();
	$term->term_id  = $id;
	$term->taxonomy = (string) ( $row['taxonomy'] ?? 'category' );
	$term->name     = (string) ( $row['name'] ?? '' );
	$term->slug     = (string) ( $row['slug'] ?? '' );
	$term->description = (string) ( $row['description'] ?? '' );
	$term->count    = (int) ( $row['count'] ?? 0 );
	return $term;
}

function get_post_stati() {
	// The store keys post-member queries off the registered statuses; the WP_Query
	// stub ignores status, so the exact list only needs to be non-empty.
	return array(
		'publish' => 'publish',
		'draft'   => 'draft',
		'pending' => 'pending',
		'private' => 'private',
		'future'  => 'future',
		'trash'   => 'trash',
	);
}

function is_post_publicly_viewable( $post ): bool {
	$id = (int) ( $post instanceof WP_Post ? $post->ID : $post );
	return empty( Wpait_Test_State::$not_viewable[ $id ] );
}

function wp_is_post_revision( $id ): bool {
	return false;
}

function wp_is_post_autosave( $id ): bool {
	return false;
}

/* --- Meta ---------------------------------------------------------------- */

function get_post_meta( $id, $key = '', $single = false ) {
	$value = Wpait_Test_State::$post_meta[ (int) $id ][ $key ] ?? '';
	return $single ? $value : ( '' === $value ? array() : array( $value ) );
}

function update_post_meta( $id, $key, $value ) {
	Wpait_Test_State::$post_meta[ (int) $id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $id, $key ) {
	unset( Wpait_Test_State::$post_meta[ (int) $id ][ $key ] );
	return true;
}

function get_term_meta( $id, $key = '', $single = false ) {
	$value = Wpait_Test_State::$term_meta[ (int) $id ][ $key ] ?? '';
	return $single ? $value : ( '' === $value ? array() : array( $value ) );
}

function delete_term_meta( $id, $key ) {
	unset( Wpait_Test_State::$term_meta[ (int) $id ][ $key ] );
	return true;
}

/* --- Object terms (a post's wpait_language taxonomy slug) ---------------- */

function wp_get_object_terms( $object_id, $taxonomy, $args = array() ) {
	$slugs = Wpait_Test_State::$object_terms[ (int) $object_id ][ $taxonomy ] ?? array();
	return array_values( $slugs );
}

function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	$slugs = array_map( 'strval', (array) $terms );
	Wpait_Test_State::$object_terms[ (int) $object_id ][ $taxonomy ] = $slugs;
	return $slugs;
}

/* --- Object cache (single 'wpait' group is all the store uses) ----------- */

function wp_cache_get( $key, $group = '' ) {
	return Wpait_Test_State::$cache[ $group ][ $key ] ?? false;
}

function wp_cache_set( $key, $value, $group = '', $ttl = 0 ): bool {
	Wpait_Test_State::$cache[ $group ][ $key ] = $value;
	return true;
}

function wp_cache_delete( $key, $group = '' ): bool {
	unset( Wpait_Test_State::$cache[ $group ][ $key ] );
	return true;
}

function delete_transient( $key ): bool {
	unset( Wpait_Test_State::$transients[ $key ] );
	return true;
}

/* --- Capabilities + misc ------------------------------------------------- */

function current_user_can( $cap, ...$args ): bool {
	if ( ! empty( $args ) ) {
		$keyed = $cap . ':' . (int) $args[0];
		if ( array_key_exists( $keyed, Wpait_Test_State::$caps ) ) {
			return (bool) Wpait_Test_State::$caps[ $keyed ];
		}
	}
	return (bool) ( Wpait_Test_State::$caps[ $cap ] ?? false );
}

function _doing_it_wrong( $function, $message, $version ): void {
	// Surface the store's S5 type guard as a catchable error so a test can assert it.
	throw new RuntimeException( 'doing_it_wrong: ' . $function . ' — ' . $message );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	return trim( $text );
}

function strip_shortcodes( $content ) {
	return (string) $content;
}

/**
 * In-memory WP_Query: only the read shape the store's query_post_members() needs —
 * `meta_key`/`meta_value` against post meta, returning ids. Scans
 * Wpait_Test_State::$posts; status/post_type are not filtered (the store includes
 * all statuses and `post_type => 'any'`).
 */
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		/** @var int[] */
		public array $posts = array();

		/** @var array<string,mixed> Query vars (admin-list filter_query reads/writes these). */
		public array $query_vars = array();

		/** @var bool Whether this is the main query (admin-list filter gate). */
		public bool $is_main = false;

		/** @var int Total matches (admin-list count_in_language / by-language read this). */
		public int $found_posts = 0;

		/** @param array<string,mixed> $args */
		public function __construct( array $args = array() ) {
			$this->query_vars = $args;

			// The store's group lookup keys off meta_key/meta_value; the admin-list
			// by-language / count queries key off a wpait_language tax_query. Both scan
			// the in-memory post model. (admin-list filter_query constructs an empty
			// WP_Query and drives it via set()/get(), so neither path runs there.)
			$meta_key   = $args['meta_key'] ?? null;
			$meta_value = $args['meta_value'] ?? null;
			$tax_query  = $args['tax_query'] ?? null;

			if ( null !== $meta_key ) {
				foreach ( Wpait_Test_State::$posts as $id => $row ) {
					$have = Wpait_Test_State::$post_meta[ (int) $id ][ $meta_key ] ?? null;
					if ( $have === $meta_value ) {
						$this->posts[] = (int) $id;
					}
				}
			} elseif ( is_array( $tax_query ) && isset( $tax_query[0]['taxonomy'] ) ) {
				$tax  = (string) $tax_query[0]['taxonomy'];
				$want = (array) ( $tax_query[0]['terms'] ?? array() );
				foreach ( Wpait_Test_State::$posts as $id => $row ) {
					$slugs = Wpait_Test_State::$object_terms[ (int) $id ][ $tax ] ?? array();
					if ( array_intersect( $slugs, $want ) ) {
						$this->posts[] = (int) $id;
					}
				}
			}

			$this->found_posts = count( $this->posts );
		}

		public function is_main_query(): bool {
			return $this->is_main;
		}

		public function get( $key, $default = '' ) {
			return $this->query_vars[ $key ] ?? $default;
		}

		public function set( $key, $value ): void {
			$this->query_vars[ $key ] = $value;
		}
	}
}

/**
 * In-memory get_terms(): supports the store's group lookup (a `meta_query` of one
 * key/value over the category/post_tag taxonomies, `fields => ids`) and the
 * language reconcile's has-content probe. Returns ids.
 *
 * @param array<string,mixed> $args
 * @return int[]
 */
function get_terms( $args = array() ) {
	$taxonomies = (array) ( $args['taxonomy'] ?? array() );
	$meta_query = $args['meta_query'] ?? array();
	$number     = (int) ( $args['number'] ?? 0 );

	$want_key   = null;
	$want_value = null;
	if ( ! empty( $meta_query[0]['key'] ) ) {
		$want_key   = $meta_query[0]['key'];
		$want_value = $meta_query[0]['value'] ?? null;
	}

	$ids = array();
	foreach ( Wpait_Test_State::$terms as $id => $row ) {
		if ( ! empty( $taxonomies ) && ! in_array( $row['taxonomy'] ?? '', $taxonomies, true ) ) {
			continue;
		}
		if ( null !== $want_key ) {
			$have = Wpait_Test_State::$term_meta[ (int) $id ][ $want_key ] ?? null;
			if ( $have !== $want_value ) {
				continue;
			}
		}
		$ids[] = (int) $id;
		if ( $number > 0 && count( $ids ) >= $number ) {
			break;
		}
	}
	return $ids;
}

/* -------------------------------------------------------------------------
 * REST plumbing: only the surface Wpait_Rest's permission + validation logic
 * touches. The handlers themselves drive the translator/store (covered by their
 * own tests + E2E); these tests target the permission callbacks and arg checks.
 * ---------------------------------------------------------------------- */

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/** Minimal request: a param bag. */
	class WP_REST_Request {
		/** @var array<string,mixed> */
		private array $params;

		/** @param array<string,mixed> $params */
		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( $key, $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/** Minimal response wrapper recording its payload. */
	class WP_REST_Response {
		/** @var mixed */
		public $data;

		public function __construct( $data = null ) {
			$this->data = $data;
		}

		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
	}
}

function rest_ensure_response( $response ) {
	return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( $response );
}

function register_rest_route( ...$args ): bool {
	return true;
}

function rest_authorization_required_code(): int {
	return 401;
}

function get_taxonomy( $taxonomy ) {
	// The REST permission checks read $tax->cap->{manage,delete}_terms; map them to
	// distinct cap strings so current_user_can() can answer per-capability.
	if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
		return false;
	}
	return (object) array(
		'cap' => (object) array(
			'manage_terms' => 'manage_categories',
			'delete_terms' => 'manage_categories',
			'edit_terms'   => 'manage_categories',
		),
	);
}

function get_post_type_object( $post_type ) {
	return (object) array(
		'cap' => (object) array(
			'create_posts' => 'create_' . $post_type . 's',
		),
	);
}

/* -------------------------------------------------------------------------
 * Front-end link + view-context stubs (Wpait_Frontend). Permalinks are simple
 * derived strings so a test can assert "a URL was produced" and the chosen id.
 * View context (is_singular / is_category / is_tag / queried object) is driven
 * from Wpait_Test_State so resolve_post_id()/resolve_term_id() can be exercised.
 * ---------------------------------------------------------------------- */

function get_permalink( $id = 0 ) {
	$id = (int) ( $id instanceof WP_Post ? $id->ID : $id );
	return isset( Wpait_Test_State::$posts[ $id ] ) ? 'https://example.test/?p=' . $id : false;
}

function get_term_link( $id, $taxonomy = '' ) {
	$id = (int) ( $id instanceof WP_Term ? $id->term_id : $id );
	return isset( Wpait_Test_State::$terms[ $id ] )
		? 'https://example.test/?term=' . $id
		: new WP_Error( 'invalid_term', 'Invalid term.' );
}

function is_singular( $types = '' ): bool {
	return null !== Wpait_Test_State::$queried_object
		&& Wpait_Test_State::$queried_object instanceof WP_Post;
}

function is_category( $cat = '' ): bool {
	return 'category' === Wpait_Test_State::$archive_type;
}

function is_tag( $tag = '' ): bool {
	return 'post_tag' === Wpait_Test_State::$archive_type;
}

function get_queried_object() {
	return Wpait_Test_State::$queried_object;
}

/* --- Locale switching context (#62) -------------------------------------- */

function wp_doing_ajax(): bool {
	return Wpait_Test_State::$doing_ajax;
}

function is_feed(): bool {
	return Wpait_Test_State::$is_feed;
}

function is_robots(): bool {
	return Wpait_Test_State::$is_robots;
}

function is_main_query(): bool {
	return Wpait_Test_State::$is_main_query;
}

function is_tax( $taxonomy = '', $term = '' ): bool {
	return Wpait_Test_State::$is_tax;
}

function get_locale(): string {
	return Wpait_Test_State::$locale;
}

function get_available_languages( $dir = null ): array {
	return Wpait_Test_State::$available_languages;
}

function switch_to_locale( $locale ): bool {
	Wpait_Test_State::$switched_locales[] = (string) $locale;
	Wpait_Test_State::$locale             = (string) $locale;
	return true;
}

function wp_download_language_pack( $locale ) {
	Wpait_Test_State::$downloaded_locales[] = (string) $locale;
	$result = Wpait_Test_State::$download_result;
	return '' === $result ? (string) $locale : $result;
}

if ( ! class_exists( 'WP_Block' ) ) {
	/** Minimal block instance: only the `context` bag resolve_post_id() reads. */
	class WP_Block {
		/** @var array<string,mixed> */
		public array $context;

		/** @param array<string,mixed> $context */
		public function __construct( array $context = array() ) {
			$this->context = $context;
		}
	}
}

/* -------------------------------------------------------------------------
 * Admin-list stubs: the discriminator (get_terms_args, #13) and the untranslated
 * cross-join. Admin context, current screen, and the candidate post query all read
 * from Wpait_Test_State so a test can model the screen + content set declaratively.
 * ---------------------------------------------------------------------- */

function is_admin(): bool {
	return Wpait_Test_State::$is_admin;
}

function get_current_screen() {
	return Wpait_Test_State::$current_screen;
}

/**
 * In-memory get_posts(): the untranslated scan asks for `post_type` + ids; return
 * every seeded post of that type. Status is 'any' there, so it is not filtered.
 *
 * @param array<string,mixed> $args
 * @return int[]
 */
function get_posts( $args = array() ) {
	$type = $args['post_type'] ?? 'post';
	$ids  = array();
	foreach ( Wpait_Test_State::$posts as $id => $row ) {
		if ( ( $row['post_type'] ?? 'post' ) === $type ) {
			$ids[] = (int) $id;
		}
	}
	return $ids;
}

/* --- Title / type / link stubs the Overview REST payload (#54) reads --------- */

function get_the_title( $id = 0 ) {
	$id = (int) ( $id instanceof WP_Post ? $id->ID : $id );
	return (string) ( Wpait_Test_State::$posts[ $id ]['post_title'] ?? ( 'Post ' . $id ) );
}

function get_post_type( $id = 0 ) {
	$id = (int) ( $id instanceof WP_Post ? $id->ID : $id );
	return (string) ( Wpait_Test_State::$posts[ $id ]['post_type'] ?? 'post' );
}

function get_edit_post_link( $id = 0, $context = 'display' ) {
	$id = (int) ( $id instanceof WP_Post ? $id->ID : $id );
	return isset( Wpait_Test_State::$posts[ $id ] ) ? 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit' : '';
}

function get_edit_term_link( $id, $taxonomy = '' ) {
	$id = (int) ( $id instanceof WP_Term ? $id->term_id : $id );
	return isset( Wpait_Test_State::$terms[ $id ] ) ? 'https://example.test/wp-admin/term.php?tag_ID=' . $id : '';
}

function get_preview_post_link( $id = 0 ) {
	$id = (int) ( $id instanceof WP_Post ? $id->ID : $id );
	return isset( Wpait_Test_State::$posts[ $id ] ) ? 'https://example.test/?p=' . $id . '&preview=true' : '';
}

function wp_reset_postdata(): void {}

/* -------------------------------------------------------------------------
 * Action Scheduler stubs (#55 queue). The queue unit only touches three AS
 * surfaces: enqueue, the "already scheduled?" dedup probe, and a status-count
 * store query. All record into / read from Wpait_Test_State so QueueTest can
 * assert the enqueued payload and drive the dedup branches declaratively.
 * ---------------------------------------------------------------------- */

/** Signature for an (hook, args, group) tuple used by the dedup stub. */
function wpait_test_as_signature( $hook, $args, $group ): string {
	return md5( wp_json_encode( array( $hook, $args, $group ) ) );
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	Wpait_Test_State::$as_enqueued[] = array( $hook, $args, $group );
	return count( Wpait_Test_State::$as_enqueued ); // A fake action id.
}

function as_has_scheduled_action( $hook, $args = null, $group = '' ): bool {
	$sig = wpait_test_as_signature( $hook, $args, $group );
	return ! empty( Wpait_Test_State::$as_scheduled[ $sig ] );
}

function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	Wpait_Test_State::$as_scheduled_single[] = array( (int) $timestamp, $hook, $args, $group );
	if ( Wpait_Test_State::$as_schedule_fails ) {
		return 0; // Scheduling failed to persist.
	}
	return count( Wpait_Test_State::$as_scheduled_single ); // A fake action id.
}

if ( ! class_exists( 'Wpait_Fake_AS_Action' ) ) {
	/** Minimal stand-in for ActionScheduler_Action used by fetch_action(). */
	class Wpait_Fake_AS_Action {
		private string $hook;
		private array $args;
		public function __construct( string $hook, array $args ) {
			$this->hook = $hook;
			$this->args = $args;
		}
		public function get_hook(): string {
			return $this->hook;
		}
		public function get_args(): array {
			return $this->args;
		}
	}
}

if ( ! class_exists( 'Wpait_Fake_AS_LogEntry' ) ) {
	/** Minimal stand-in for ActionScheduler_LogEntry. */
	class Wpait_Fake_AS_LogEntry {
		private string $message;
		public function __construct( string $message ) {
			$this->message = $message;
		}
		public function get_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'ActionScheduler_Store' ) ) {
	class ActionScheduler_Store {
		const STATUS_PENDING  = 'pending';
		const STATUS_RUNNING  = 'in-progress';
		const STATUS_FAILED   = 'failed';
		const STATUS_COMPLETE = 'complete';

		/** @param array<string,mixed> $args @return int|array<int,int> */
		public function query_actions( $args = array(), $return_format = 'select' ) {
			$status = $args['status'] ?? '';
			if ( 'count' !== $return_format ) {
				// 'select' returns an array of action ids (mirrors the real store).
				return self::STATUS_FAILED === $status
					? array_map( 'intval', array_keys( Wpait_Test_State::$as_failed_actions ) )
					: array();
			}
			return (int) ( Wpait_Test_State::$as_counts[ $status ] ?? 0 );
		}

		/** @return Wpait_Fake_AS_Action|null */
		public function fetch_action( $action_id ) {
			$rec = Wpait_Test_State::$as_failed_actions[ (int) $action_id ] ?? null;
			return $rec ? new Wpait_Fake_AS_Action( $rec['hook'], $rec['args'] ) : null;
		}

		public function delete_action( $action_id ): void {
			Wpait_Test_State::$as_deleted[] = (int) $action_id;
			unset( Wpait_Test_State::$as_failed_actions[ (int) $action_id ] );
		}
	}
}

if ( ! class_exists( 'Wpait_Fake_AS_Logger' ) ) {
	class Wpait_Fake_AS_Logger {
		/** @return array<int,Wpait_Fake_AS_LogEntry> */
		public function get_logs( $action_id ): array {
			$rec = Wpait_Test_State::$as_failed_actions[ (int) $action_id ] ?? null;
			return $rec ? array( new Wpait_Fake_AS_LogEntry( 'action failed: ' . $rec['message'] ) ) : array();
		}
	}
}

if ( ! class_exists( 'ActionScheduler' ) ) {
	class ActionScheduler {
		private static ?ActionScheduler_Store $store = null;
		private static ?Wpait_Fake_AS_Logger $logger = null;

		public static function store(): ActionScheduler_Store {
			if ( null === self::$store ) {
				self::$store = new ActionScheduler_Store();
			}
			return self::$store;
		}

		public static function logger(): Wpait_Fake_AS_Logger {
			if ( null === self::$logger ) {
				self::$logger = new Wpait_Fake_AS_Logger();
			}
			return self::$logger;
		}
	}
}

/* -------------------------------------------------------------------------
 * Load the real units under test. The Translation Store is now the REAL class
 * (the stubs above give it a DB-free WP environment); it must load before the
 * Translator, whose constructor type-hints it.
 * ---------------------------------------------------------------------- */

require_once __DIR__ . '/../../wp-ai-translate/includes/class-translation-store.php';
require_once __DIR__ . '/../../wp-ai-translate/includes/class-admin-settings.php';
require_once __DIR__ . '/../../wp-ai-translate/includes/class-languages.php';
require_once __DIR__ . '/../../wp-ai-translate/includes/class-translator.php';
require_once __DIR__ . '/../../wp-ai-translate/includes/class-queue.php';
require_once __DIR__ . '/../../wp-ai-translate/includes/class-rest.php';
require_once __DIR__ . '/../../wp-ai-translate/includes/class-frontend.php';
require_once __DIR__ . '/../../wp-ai-translate/includes/class-locale.php';
require_once __DIR__ . '/../../wp-ai-translate/includes/class-admin-list.php';
