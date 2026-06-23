<?php
/**
 * Unit tests for Wpait_Rest — the permission callbacks (per-object capability
 * checks, the source-vs-target distinction S2) and arg validation (language-code
 * validation against enabled languages, the /test-connection admin gate).
 *
 * Scope: the security-relevant decision logic, not full WP REST plumbing. The
 * handlers' translator/store side effects are covered by TranslatorTest /
 * TranslationStoreTest and the E2E suite.
 *
 * @package WpAiTranslate\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class RestTest extends TestCase {

	private Wpait_Rest $rest;

	protected function setUp(): void {
		Wpait_Test_State::reset();
		Wpait_Languages::flush_index();
		Wpait_Test_State::$options['wpait_settings'] = array(
			'languages' => array(
				array( 'code' => 'en', 'name' => 'English', 'enabled' => true ),
				array( 'code' => 'es', 'name' => 'Spanish', 'enabled' => true ),
				array( 'code' => 'fr', 'name' => 'French', 'enabled' => false ),
			),
		);
		$languages = new Wpait_Languages();
		$store      = new Wpait_Translation_Store();
		$translator = new Wpait_Translator( $store, $languages );
		$this->rest = new Wpait_Rest( $store, $languages, $translator );
	}

	private function request( array $params ): WP_REST_Request {
		return new WP_REST_Request( $params );
	}

	/** Calls a private method (validate_enabled_language) for focused arg-check tests. */
	private function invoke_private( string $method, ...$args ) {
		// PHP 8.1+ makes private methods reflection-invocable without setAccessible().
		$ref = new ReflectionMethod( Wpait_Rest::class, $method );
		return $ref->invoke( $this->rest, ...$args );
	}

	/* ------------------------------------------------------------------ *
	 * permission_translate (S2: edit source + create target)
	 * ------------------------------------------------------------------ */

	public function test_translate_post_requires_edit_source_and_create(): void {
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$caps['edit_post:5']  = true;
		Wpait_Test_State::$caps['create_posts'] = true;

		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'post', 'source_id' => 5 ) ) );
		$this->assertTrue( $result );
	}

	public function test_translate_post_forbidden_without_create_capability(): void {
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$caps['edit_post:5']  = true;
		Wpait_Test_State::$caps['create_posts'] = false;

		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'post', 'source_id' => 5 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_forbidden', $result->get_error_code() );
	}

	public function test_translate_post_forbidden_without_edit_source(): void {
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$caps['edit_post:5']  = false;
		Wpait_Test_State::$caps['create_posts'] = true;

		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'post', 'source_id' => 5 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_forbidden', $result->get_error_code() );
	}

	public function test_translate_not_found_when_source_post_missing(): void {
		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'post', 'source_id' => 999 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_no_source', $result->get_error_code() );
	}

	public function test_translate_term_requires_manage_terms(): void {
		Wpait_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpait_Test_State::$caps['manage_categories'] = true;
		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'term', 'source_id' => 7 ) ) );
		$this->assertTrue( $result );
	}

	public function test_translate_term_forbidden_without_manage_terms(): void {
		Wpait_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpait_Test_State::$caps['manage_categories'] = false;
		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'term', 'source_id' => 7 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_forbidden', $result->get_error_code() );
	}

	public function test_translate_term_not_found_when_missing(): void {
		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'term', 'source_id' => 404 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_no_source', $result->get_error_code() );
	}

	/* ------------------------------------------------------------------ *
	 * permission_edit_target (S2: edit the concrete target object)
	 * ------------------------------------------------------------------ */

	public function test_edit_target_post_allowed_with_edit_post(): void {
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$caps['edit_post:5'] = true;
		$result = $this->rest->permission_edit_target( $this->request( array( 'type' => 'post', 'object_id' => 5 ) ) );
		$this->assertTrue( $result );
	}

	public function test_edit_target_post_forbidden_without_edit_post(): void {
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$caps['edit_post:5'] = false;
		$result = $this->rest->permission_edit_target( $this->request( array( 'type' => 'post', 'object_id' => 5 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_forbidden', $result->get_error_code() );
	}

	public function test_edit_target_term_uses_edit_term_cap(): void {
		Wpait_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpait_Test_State::$caps['edit_term:7'] = true;
		$result = $this->rest->permission_edit_target( $this->request( array( 'type' => 'term', 'object_id' => 7 ) ) );
		$this->assertTrue( $result );
	}

	public function test_edit_target_not_found_for_missing_object(): void {
		$result = $this->rest->permission_edit_target( $this->request( array( 'type' => 'post', 'object_id' => 12345 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_no_source', $result->get_error_code() );
	}

	/* ------------------------------------------------------------------ *
	 * permission_delete_target (S2: delete cap, distinct from edit)
	 * ------------------------------------------------------------------ */

	public function test_delete_target_post_uses_delete_post_cap(): void {
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		// Has edit but NOT delete: delete must still be refused.
		Wpait_Test_State::$caps['edit_post:5']   = true;
		Wpait_Test_State::$caps['delete_post:5'] = false;
		$result = $this->rest->permission_delete_target( $this->request( array( 'type' => 'post', 'object_id' => 5 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );

		Wpait_Test_State::$caps['delete_post:5'] = true;
		$result = $this->rest->permission_delete_target( $this->request( array( 'type' => 'post', 'object_id' => 5 ) ) );
		$this->assertTrue( $result );
	}

	public function test_delete_target_term_uses_delete_terms_cap(): void {
		Wpait_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpait_Test_State::$caps['manage_categories'] = true;
		$result = $this->rest->permission_delete_target( $this->request( array( 'type' => 'term', 'object_id' => 7 ) ) );
		$this->assertTrue( $result );
	}

	/* ------------------------------------------------------------------ *
	 * permission_manage (the /test-connection + overview admin gate)
	 * ------------------------------------------------------------------ */

	public function test_manage_gate_requires_manage_options(): void {
		Wpait_Test_State::$caps['manage_options'] = false;
		$this->assertFalse( $this->rest->permission_manage() );
		Wpait_Test_State::$caps['manage_options'] = true;
		$this->assertTrue( $this->rest->permission_manage() );
	}

	/* ------------------------------------------------------------------ *
	 * Language-code validation against enabled languages
	 * ------------------------------------------------------------------ */

	public function test_enabled_language_accepted(): void {
		$this->assertTrue( $this->invoke_private( 'validate_enabled_language', 'es' ) );
	}

	public function test_disabled_language_rejected(): void {
		$result = $this->invoke_private( 'validate_enabled_language', 'fr' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_invalid_language', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_unknown_language_rejected(): void {
		$result = $this->invoke_private( 'validate_enabled_language', 'de' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_invalid_language', $result->get_error_code() );
	}

	/* ------------------------------------------------------------------ *
	 * type allowlist (route arg validate_callback shape)
	 * ------------------------------------------------------------------ */

	public function test_type_allowlist_constant(): void {
		// The route's `type` arg validates against this allowlist; the store/REST units
		// both reject anything outside { post, term }.
		$this->assertSame( array( 'post', 'term' ), Wpait_Rest::TYPES );
	}
}
