<?php
/**
 * Unit tests for Wpait_Translator — the only caller of the WP 7.0 AI connector.
 *
 * The headline test is the bug #32 regression guard: when a site pins a model via
 * the `wpait_model_preference` filter (the documented fix for a provider that
 * rejects the connector's default model, e.g. "Claude Fable 5 is not available…
 * use Opus 4.8"), the request MUST carry that model and MUST NOT silently fall back
 * to the rejected default.
 *
 * @package WpAiTranslate\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase {

	private Wpait_Translator $translator;

	protected function setUp(): void {
		Wpait_Test_State::reset();
		Wpait_Languages::flush_index();
		$this->translator = new Wpait_Translator( new Wpait_Translation_Store(), new Wpait_Languages() );
	}

	/** Helper: translate with an explicit system prompt (skips prompt-building). */
	private function translate( string $text = 'Hello' ) {
		return $this->translator->translate_text( $text, 'English', 'Spanish', array( 'system_prompt' => 'SYS' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Bug #32: model pinning
	 * ------------------------------------------------------------------ */

	public function test_pinned_model_is_sent_to_the_connector(): void {
		Wpait_Test_State::$filters['wpait_model_preference'] = array( 'claude-opus-4-8' );

		$out = $this->translate();

		$this->assertSame( 'Hola', $out );
		$this->assertSame(
			array( 'claude-opus-4-8' ),
			Wpait_Test_State::$last_request['models'] ?? null,
			'The pinned model must be passed via using_model_preference().'
		);
	}

	public function test_pinned_model_preserves_priority_order(): void {
		Wpait_Test_State::$filters['wpait_model_preference'] = array( 'claude-opus-4-8', 'claude-sonnet-4-6' );

		$this->translate();

		$this->assertSame(
			array( 'claude-opus-4-8', 'claude-sonnet-4-6' ),
			Wpait_Test_State::$last_request['models']
		);
	}

	public function test_empty_preference_means_no_model_preference_call(): void {
		// Default (no filter): the connector picks its own model; we must NOT force one.
		$this->translate();
		$this->assertArrayNotHasKey(
			'models',
			Wpait_Test_State::$last_request,
			'With no pinned model, using_model_preference() must not be called.'
		);
	}

	public function test_blank_entries_in_preference_are_filtered_out(): void {
		Wpait_Test_State::$filters['wpait_model_preference'] = array( '', 'claude-opus-4-8', null );
		$this->translate();
		$this->assertSame( array( 'claude-opus-4-8' ), Wpait_Test_State::$last_request['models'] );
	}

	public function test_configured_settings_model_is_used_as_the_default(): void {
		// #32: the admin-selected model is sent so the connector doesn't fall back to a
		// model the provider rejects (e.g. "Claude Fable 5 is not available").
		Wpait_Test_State::$options['wpait_settings'] = array( 'model' => 'claude-opus-4-8' );
		$this->translate();
		$this->assertSame( array( 'claude-opus-4-8' ), Wpait_Test_State::$last_request['models'] );
	}

	public function test_filter_overrides_the_configured_model(): void {
		Wpait_Test_State::$options['wpait_settings']         = array( 'model' => 'claude-opus-4-8' );
		Wpait_Test_State::$filters['wpait_model_preference'] = array( 'claude-sonnet-4-6' );
		$this->translate();
		$this->assertSame( array( 'claude-sonnet-4-6' ), Wpait_Test_State::$last_request['models'] );
	}

	/* ------------------------------------------------------------------ *
	 * Bug #32, take two: auto-recover from the "model not available" 404
	 * The previous fix (#47) only let admins avoid this by hand-picking a model;
	 * the default path still surfaced the raw 404. The plugin must self-heal.
	 * ------------------------------------------------------------------ */

	public function test_recovers_from_model_not_available_404_by_retrying_with_an_advertised_model(): void {
		// The connector's auto-picked model 404s ("Claude Fable 5 is not available,
		// use Opus 4.8") on the first call; the plugin must retry once, pinning an
		// advertised text-generation model, instead of surfacing the raw error.
		Wpait_Test_State::$connector_results = array(
			new WP_Error( 'prompt_client_error', 'Not Found (404) - Claude Fable 5 is not available. Please use Opus 4.8.', array( 'status' => 404 ) ),
			'Hola',
		);
		set_transient( 'wpait_available_models', array(
			array( 'id' => 'claude-opus-4-8', 'label' => 'Opus 4.8', 'provider' => 'Anthropic' ),
		) );

		$out = $this->translate();

		$this->assertSame( 'Hola', $out );
		$this->assertCount( 2, Wpait_Test_State::$requests, 'The connector must be called twice — initial 404 then one retry.' );
		// First call used the connector's auto-pick (no model pinned).
		$this->assertArrayNotHasKey( 'models', Wpait_Test_State::$requests[0] );
		// Retry pinned the advertised fallback model.
		$this->assertSame( array( 'claude-opus-4-8' ), Wpait_Test_State::$requests[1]['models'] );
	}

	public function test_does_not_loop_forever_on_repeated_not_available_errors(): void {
		// If the fallback also 404s, surface the friendly, actionable notice — never
		// a third call, never the raw connector error.
		Wpait_Test_State::$connector_results = array(
			new WP_Error( 'prompt_client_error', 'Not Found (404) - Claude Fable 5 is not available. Please use Opus 4.8.' ),
			new WP_Error( 'prompt_client_error', 'Not Found (404) - Opus 4.8 is not available. Please use Sonnet.' ),
		);
		set_transient( 'wpait_available_models', array(
			array( 'id' => 'claude-opus-4-8', 'label' => 'Opus 4.8', 'provider' => 'Anthropic' ),
		) );

		$out = $this->translate();

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'wpait_model_not_available', $out->get_error_code() );
		$this->assertSame( 404, $out->get_error_data()['status'] );
		$this->assertCount( 2, Wpait_Test_State::$requests, 'At most one retry — no infinite loop.' );
	}

	public function test_friendly_error_when_not_available_and_no_advertised_fallback(): void {
		// #32's worst case: the provider 404s on its default and advertises no
		// accessible model to retry with. The raw connector 404 must NOT surface.
		Wpait_Test_State::$connector_results = array(
			new WP_Error( 'prompt_client_error', 'Not Found (404) - Claude Fable 5 is not available. Please use Opus 4.8.' ),
		);
		set_transient( 'wpait_available_models', array() );

		$out = $this->translate();

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'wpait_model_not_available', $out->get_error_code() );
		$this->assertSame( 404, $out->get_error_data()['status'] );
		$this->assertCount( 1, Wpait_Test_State::$requests, 'No retry when there is no advertised fallback.' );
	}

	public function test_pinned_model_404_falls_back_to_an_other_advertised_model(): void {
		// An admin explicitly pinned Opus 4.8 but the account can't access it; the
		// plugin retries once with a *different* advertised model rather than failing.
		Wpait_Test_State::$options['wpait_settings'] = array( 'model' => 'claude-opus-4-8' );
		Wpait_Test_State::$connector_results        = array(
			new WP_Error( 'prompt_client_error', 'Not Found (404) - claude-opus-4-8 is not available.' ),
			'Hola',
		);
		set_transient( 'wpait_available_models', array(
			array( 'id' => 'claude-opus-4-8', 'label' => 'Opus 4.8', 'provider' => 'Anthropic' ),
			array( 'id' => 'claude-sonnet-4-6', 'label' => 'Sonnet 4.6', 'provider' => 'Anthropic' ),
		) );

		$out = $this->translate();

		$this->assertSame( 'Hola', $out );
		$this->assertSame( array( 'claude-opus-4-8' ), Wpait_Test_State::$requests[0]['models'] );
		$this->assertSame( array( 'claude-sonnet-4-6' ), Wpait_Test_State::$requests[1]['models'], 'Retry must skip the model already tried.' );
	}

	/* ------------------------------------------------------------------ *
	 * Temperature (omitted by default — newer models reject it)
	 * ------------------------------------------------------------------ */

	public function test_temperature_is_omitted_by_default(): void {
		$this->translate();
		$this->assertArrayNotHasKey( 'temperature', Wpait_Test_State::$last_request );
	}

	public function test_temperature_is_sent_when_opted_in(): void {
		Wpait_Test_State::$filters['wpait_temperature'] = 0.3;
		$this->translate();
		$this->assertSame( 0.3, Wpait_Test_State::$last_request['temperature'] );
	}

	/* ------------------------------------------------------------------ *
	 * Error handling
	 * ------------------------------------------------------------------ */

	public function test_connector_wp_error_is_propagated(): void {
		Wpait_Test_State::$connector_result = new WP_Error( 'prompt_client_error', 'Bad Request (400)', array( 'status' => 400 ) );
		$out = $this->translate();
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'prompt_client_error', $out->get_error_code() );
	}

	public function test_no_model_error_is_mapped_to_friendly_unavailable(): void {
		// The raw "No models found that support text_generation" connector error
		// (the #14 silent-failure shape) is mapped to the friendly wpait_ai_unavailable.
		Wpait_Test_State::$connector_result = new WP_Error(
			'prompt_invalid_argument',
			'No models found that support text_generation for this prompt.',
			array( 'status' => 400 )
		);
		$out = $this->translate();
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'wpait_ai_unavailable', $out->get_error_code() );
		$this->assertSame( 503, $out->get_error_data()['status'] );
	}

	public function test_unavailable_when_no_usable_model(): void {
		Wpait_Test_State::$is_supported = false; // capability probe fails.
		$out = $this->translate();
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'wpait_ai_unavailable', $out->get_error_code() );
	}

	public function test_unavailable_when_provider_unsupported(): void {
		Wpait_Test_State::$supports_ai = false;
		$out = $this->translate();
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'wpait_ai_unavailable', $out->get_error_code() );
	}

	/* ------------------------------------------------------------------ *
	 * Capability probe (#14) + connection test (#18)
	 * ------------------------------------------------------------------ */

	public function test_can_generate_text_true_when_supported(): void {
		$this->assertTrue( Wpait_Translator::can_generate_text() );
	}

	public function test_can_generate_text_false_when_no_model(): void {
		Wpait_Test_State::$is_supported = false;
		$this->assertFalse( Wpait_Translator::can_generate_text() );
	}

	public function test_can_generate_text_result_is_cached(): void {
		Wpait_Translator::can_generate_text();
		$this->assertSame( '1', Wpait_Test_State::$transients['wpait_text_generation_supported'] );
	}

	public function test_test_connection_reports_success(): void {
		Wpait_Test_State::$connector_result = 'Hola';
		$res = $this->translator->test_connection();
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'Hola', $res['sample'] );
	}

	public function test_test_connection_reports_failure(): void {
		Wpait_Test_State::$is_supported = false;
		$res = $this->translator->test_connection();
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'wpait_ai_unavailable', $res['code'] );
	}

	/* ------------------------------------------------------------------ *
	 * Output handling
	 * ------------------------------------------------------------------ */

	public function test_empty_input_skips_the_connector(): void {
		$out = $this->translator->translate_text( '   ', 'English', 'Spanish', array( 'system_prompt' => 'SYS' ) );
		$this->assertSame( '   ', $out );
		$this->assertNull( Wpait_Test_State::$last_request, 'Blank text must not call the connector.' );
	}

	public function test_surrounding_code_fence_is_stripped(): void {
		Wpait_Test_State::$connector_result = "```html\n<p>Hola</p>\n```";
		$this->assertSame( '<p>Hola</p>', $this->translate() );
	}

	public function test_system_instruction_is_forwarded(): void {
		$this->translate();
		$this->assertSame( 'SYS', Wpait_Test_State::$last_request['system'] );
	}
}
