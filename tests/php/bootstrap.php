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

	/** @var array<string,mixed>|null The recorded builder calls from the last generate_text(). */
	public static ?array $last_request = null;

	/** @var array<string,mixed> WP options store (get_option). */
	public static array $options = array();

	public static function reset(): void {
		self::$supports_ai      = true;
		self::$is_supported     = true;
		self::$transients       = array();
		self::$filters          = array();
		self::$connector_result = 'Hola';
		self::$last_request     = null;
		self::$options          = array();
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
		Wpait_Test_State::$last_request = $this->calls;
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

function wp_list_pluck( $list, $field ) {
	return array_map( static fn( $row ) => is_array( $row ) ? ( $row[ $field ] ?? null ) : ( $row->$field ?? null ), (array) $list );
}

function add_action( ...$args ) {
	return true;
}

/* Term/taxonomy no-ops sufficient for Wpait_Admin_Settings::sanitize() ->
 * Wpait_Languages::reconcile() in the add-languages path (no removals). */
function taxonomy_exists( $taxonomy ) {
	return true;
}

function get_term_by( $field, $value, $taxonomy = '' ) {
	return false;
}

function wp_insert_term( $term, $taxonomy, $args = array() ) {
	static $next = 1000;
	return array( 'term_id' => ++$next, 'term_taxonomy_id' => $next );
}

function update_term_meta( $term_id, $key, $value ) {
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
	}
}

// The Translation Store is a heavy WP_Query/term-meta integration; the units tested
// here don't drive it, so an empty stand-in satisfies the Translator's constructor
// type-hint. (Store behaviour is covered end-to-end by the E2E suite.)
if ( ! class_exists( 'Wpait_Translation_Store' ) ) {
	class Wpait_Translation_Store {
		const META_LANGUAGE = '_wpait_language';
		const META_GROUP    = '_wpait_group';
	}
}

/* -------------------------------------------------------------------------
 * Load the real units under test (these only touch the WP functions stubbed above).
 * ---------------------------------------------------------------------- */

require_once __DIR__ . '/../../wp-ai-translate/includes/class-admin-settings.php';
require_once __DIR__ . '/../../wp-ai-translate/includes/class-languages.php';
require_once __DIR__ . '/../../wp-ai-translate/includes/class-translator.php';
