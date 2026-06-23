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
		$queue      = new Wpait_Queue( $store, $languages, $translator );
		$this->rest = new Wpait_Rest( $store, $languages, $translator, $queue );
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

	/* ------------------------------------------------------------------ *
	 * /enqueue (#55) — per-item permission skipping, target validation, shape
	 * ------------------------------------------------------------------ */

	public function test_enqueue_rejects_invalid_target_code(): void {
		$result = $this->rest->handle_enqueue(
			$this->request(
				array(
					'items'       => array( array( 'id' => 5, 'type' => 'post' ) ),
					'target_code' => 'de', // unknown.
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_invalid_language', $result->get_error_code() );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	public function test_enqueue_rejects_disabled_target_code(): void {
		$result = $this->rest->handle_enqueue(
			$this->request(
				array(
					'items'       => array( array( 'id' => 5, 'type' => 'post' ) ),
					'target_code' => 'fr', // configured but disabled.
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_invalid_language', $result->get_error_code() );
	}

	public function test_enqueue_skips_unauthorized_items_and_queues_allowed(): void {
		// Post 5: caller can edit + create → authorized → queued.
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$caps['edit_post:5']  = true;
		Wpait_Test_State::$caps['create_posts'] = true;
		// Post 6: caller cannot edit → skipped (forbidden).
		Wpait_Test_State::$posts[6] = array( 'ID' => 6, 'post_type' => 'post' );

		$response = $this->rest->handle_enqueue(
			$this->request(
				array(
					'items'       => array(
						array( 'id' => 5, 'type' => 'post' ),
						array( 'id' => 6, 'type' => 'post' ),
					),
					'target_code' => 'es',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 1, $data['queued'] );
		$this->assertSame( 1, $data['skipped'] );
		$this->assertCount( 2, $data['results'] );
		$this->assertSame( array( 'id' => 5, 'type' => 'post', 'status' => 'queued' ), $data['results'][0] );
		$this->assertSame( 'forbidden', $data['results'][1]['status'] );
		// Exactly one action enqueued (the authorized one).
		$this->assertCount( 1, Wpait_Test_State::$as_enqueued );
	}

	public function test_enqueue_marks_invalid_items(): void {
		$response = $this->rest->handle_enqueue(
			$this->request(
				array(
					'items'       => array(
						array( 'id' => 0, 'type' => 'post' ),    // bad id.
						array( 'id' => 5, 'type' => 'widget' ),  // bad type.
					),
					'target_code' => 'es',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 0, $data['queued'] );
		$this->assertSame( 2, $data['skipped'] );
		$this->assertSame( 'invalid', $data['results'][0]['status'] );
		$this->assertSame( 'invalid', $data['results'][1]['status'] );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	/* ------------------------------------------------------------------ *
	 * /set-languages (#56) — synchronous manual bulk assignment
	 * ------------------------------------------------------------------ */

	public function test_set_languages_rejects_invalid_code(): void {
		$result = $this->rest->handle_set_languages(
			$this->request( array( 'items' => array( array( 'id' => 5, 'type' => 'post' ) ), 'code' => 'de' ) )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_invalid_language', $result->get_error_code() );
	}

	public function test_set_languages_sets_authorized_and_skips_others(): void {
		// Post 5: editable, no language yet → set.
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$caps['edit_post:5'] = true;
		// Post 6: not editable → forbidden.
		Wpait_Test_State::$posts[6] = array( 'ID' => 6, 'post_type' => 'post' );

		$response = $this->rest->handle_set_languages(
			$this->request(
				array(
					'items' => array(
						array( 'id' => 5, 'type' => 'post' ),
						array( 'id' => 6, 'type' => 'post' ),
						array( 'id' => 0, 'type' => 'post' ), // invalid.
					),
					'code'  => 'en',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 1, $data['set'] );
		$this->assertSame( 2, $data['skipped'] );
		$this->assertSame( 'set', $data['results'][0]['status'] );
		$this->assertSame( 'forbidden', $data['results'][1]['status'] );
		$this->assertSame( 'invalid', $data['results'][2]['status'] );
		// The store actually carries the new language.
		$store = new Wpait_Translation_Store();
		$this->assertSame( 'en', $store->get_language( 'post', 5 ) );
	}

	/* ------------------------------------------------------------------ *
	 * /enqueue-detection (#56) — queued AI detection
	 * ------------------------------------------------------------------ */

	public function test_enqueue_detection_queues_authorized_unmarked_item(): void {
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$caps['edit_post:5'] = true;

		$response = $this->rest->handle_enqueue_detection(
			$this->request( array( 'items' => array( array( 'id' => 5, 'type' => 'post' ) ) ) )
		);

		$data = $response->get_data();
		$this->assertSame( 1, $data['queued'] );
		$this->assertSame( 0, $data['skipped'] );
		$this->assertSame( 'queued', $data['results'][0]['status'] );
		$this->assertCount( 1, Wpait_Test_State::$as_enqueued );
		$this->assertSame( Wpait_Queue::DETECT_HOOK, Wpait_Test_State::$as_enqueued[0][0] );
	}

	public function test_enqueue_detection_skips_forbidden_and_invalid(): void {
		Wpait_Test_State::$posts[6] = array( 'ID' => 6, 'post_type' => 'post' ); // not editable.

		$response = $this->rest->handle_enqueue_detection(
			$this->request(
				array(
					'items' => array(
						array( 'id' => 6, 'type' => 'post' ),
						array( 'id' => 0, 'type' => 'post' ),
					),
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 0, $data['queued'] );
		$this->assertSame( 2, $data['skipped'] );
		$this->assertSame( 'forbidden', $data['results'][0]['status'] );
		$this->assertSame( 'invalid', $data['results'][1]['status'] );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	public function test_queue_status_handler_returns_counts(): void {
		Wpait_Test_State::$as_counts = array(
			ActionScheduler_Store::STATUS_PENDING => 3,
			ActionScheduler_Store::STATUS_RUNNING => 0,
			ActionScheduler_Store::STATUS_FAILED  => 1,
		);
		$response = $this->rest->handle_queue_status();
		$this->assertSame( array( 'pending' => 3, 'running' => 0, 'failed' => 1 ), $response->get_data() );
	}
}
