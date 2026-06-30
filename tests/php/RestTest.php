<?php
/**
 * Unit tests for Wpnt_Rest — the permission callbacks (per-object capability
 * checks, the source-vs-target distinction S2) and arg validation (language-code
 * validation against enabled languages, the /test-connection admin gate).
 *
 * Scope: the security-relevant decision logic, not full WP REST plumbing. The
 * handlers' translator/store side effects are covered by TranslatorTest /
 * TranslationStoreTest and the E2E suite.
 *
 * @package WpNativeTranslations\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class RestTest extends TestCase {

	private Wpnt_Rest $rest;

	protected function setUp(): void {
		Wpnt_Test_State::reset();
		Wpnt_Languages::flush_index();
		Wpnt_Test_State::$options['wpnt_settings'] = array(
			'languages' => array(
				array( 'code' => 'en', 'name' => 'English', 'enabled' => true ),
				array( 'code' => 'es', 'name' => 'Spanish', 'enabled' => true ),
				array( 'code' => 'fr', 'name' => 'French', 'enabled' => false ),
			),
		);
		$languages = new Wpnt_Languages();
		$store      = new Wpnt_Translation_Store();
		$translator = new Wpnt_Translator( $store, $languages );
		$queue      = new Wpnt_Queue( $store, $languages, $translator );
		$this->rest = new Wpnt_Rest( $store, $languages, $translator, $queue );
	}

	private function request( array $params ): WP_REST_Request {
		return new WP_REST_Request( $params );
	}

	/** Calls a private method (validate_enabled_language) for focused arg-check tests. */
	private function invoke_private( string $method, ...$args ) {
		// PHP 8.1+ makes private methods reflection-invocable without setAccessible().
		$ref = new ReflectionMethod( Wpnt_Rest::class, $method );
		return $ref->invoke( $this->rest, ...$args );
	}

	/* ------------------------------------------------------------------ *
	 * permission_translate (S2: edit source + create target)
	 * ------------------------------------------------------------------ */

	public function test_translate_post_requires_edit_source_and_create(): void {
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:5']  = true;
		Wpnt_Test_State::$caps['create_posts'] = true;

		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'post', 'source_id' => 5 ) ) );
		$this->assertTrue( $result );
	}

	public function test_translate_post_forbidden_without_create_capability(): void {
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:5']  = true;
		Wpnt_Test_State::$caps['create_posts'] = false;

		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'post', 'source_id' => 5 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_forbidden', $result->get_error_code() );
	}

	public function test_translate_post_forbidden_without_edit_source(): void {
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:5']  = false;
		Wpnt_Test_State::$caps['create_posts'] = true;

		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'post', 'source_id' => 5 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_forbidden', $result->get_error_code() );
	}

	public function test_translate_not_found_when_source_post_missing(): void {
		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'post', 'source_id' => 999 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_no_source', $result->get_error_code() );
	}

	public function test_translate_term_requires_manage_terms(): void {
		Wpnt_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpnt_Test_State::$caps['manage_categories'] = true;
		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'term', 'source_id' => 7 ) ) );
		$this->assertTrue( $result );
	}

	public function test_translate_term_forbidden_without_manage_terms(): void {
		Wpnt_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpnt_Test_State::$caps['manage_categories'] = false;
		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'term', 'source_id' => 7 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_forbidden', $result->get_error_code() );
	}

	public function test_translate_term_not_found_when_missing(): void {
		$result = $this->rest->permission_translate( $this->request( array( 'type' => 'term', 'source_id' => 404 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_no_source', $result->get_error_code() );
	}

	/* ------------------------------------------------------------------ *
	 * permission_edit_target (S2: edit the concrete target object)
	 * ------------------------------------------------------------------ */

	public function test_edit_target_post_allowed_with_edit_post(): void {
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:5'] = true;
		$result = $this->rest->permission_edit_target( $this->request( array( 'type' => 'post', 'object_id' => 5 ) ) );
		$this->assertTrue( $result );
	}

	public function test_edit_target_post_forbidden_without_edit_post(): void {
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:5'] = false;
		$result = $this->rest->permission_edit_target( $this->request( array( 'type' => 'post', 'object_id' => 5 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_forbidden', $result->get_error_code() );
	}

	public function test_edit_target_term_uses_edit_term_cap(): void {
		Wpnt_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpnt_Test_State::$caps['edit_term:7'] = true;
		$result = $this->rest->permission_edit_target( $this->request( array( 'type' => 'term', 'object_id' => 7 ) ) );
		$this->assertTrue( $result );
	}

	public function test_edit_target_not_found_for_missing_object(): void {
		$result = $this->rest->permission_edit_target( $this->request( array( 'type' => 'post', 'object_id' => 12345 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_no_source', $result->get_error_code() );
	}

	/* ------------------------------------------------------------------ *
	 * permission_delete_target (S2: delete cap, distinct from edit)
	 * ------------------------------------------------------------------ */

	public function test_delete_target_post_uses_delete_post_cap(): void {
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		// Has edit but NOT delete: delete must still be refused.
		Wpnt_Test_State::$caps['edit_post:5']   = true;
		Wpnt_Test_State::$caps['delete_post:5'] = false;
		$result = $this->rest->permission_delete_target( $this->request( array( 'type' => 'post', 'object_id' => 5 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );

		Wpnt_Test_State::$caps['delete_post:5'] = true;
		$result = $this->rest->permission_delete_target( $this->request( array( 'type' => 'post', 'object_id' => 5 ) ) );
		$this->assertTrue( $result );
	}

	public function test_delete_target_term_uses_delete_terms_cap(): void {
		Wpnt_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpnt_Test_State::$caps['manage_categories'] = true;
		$result = $this->rest->permission_delete_target( $this->request( array( 'type' => 'term', 'object_id' => 7 ) ) );
		$this->assertTrue( $result );
	}

	/* ------------------------------------------------------------------ *
	 * permission_manage (the /test-connection + overview admin gate)
	 * ------------------------------------------------------------------ */

	public function test_manage_gate_requires_manage_options(): void {
		Wpnt_Test_State::$caps['manage_options'] = false;
		$this->assertFalse( $this->rest->permission_manage() );
		Wpnt_Test_State::$caps['manage_options'] = true;
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
		$this->assertSame( 'wpnt_invalid_language', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_unknown_language_rejected(): void {
		$result = $this->invoke_private( 'validate_enabled_language', 'de' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_invalid_language', $result->get_error_code() );
	}

	/* ------------------------------------------------------------------ *
	 * type allowlist (route arg validate_callback shape)
	 * ------------------------------------------------------------------ */

	public function test_type_allowlist_constant(): void {
		// The route's `type` arg validates against this allowlist; the store/REST units
		// both reject anything outside { post, term }.
		$this->assertSame( array( 'post', 'term' ), Wpnt_Rest::TYPES );
	}

	/* ------------------------------------------------------------------ *
	 * payload() — editor context exposes is_original (#105)
	 * ------------------------------------------------------------------ */

	public function test_payload_exposes_is_original_false_by_default(): void {
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		$data = $this->invoke_private( 'payload', 'post', 5 );
		$this->assertArrayHasKey( 'is_original', $data );
		$this->assertFalse( $data['is_original'] );
	}

	public function test_payload_reflects_marked_original(): void {
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpnt_Test_State::$post_meta[5]['_wpnt_is_original'] = '1';
		$data = $this->invoke_private( 'payload', 'post', 5 );
		$this->assertTrue( $data['is_original'] );
	}

	public function test_payload_exposes_is_original_for_terms(): void {
		Wpnt_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpnt_Test_State::$term_meta[7]['_wpnt_is_original'] = '1';
		$data = $this->invoke_private( 'payload', 'term', 7 );
		$this->assertTrue( $data['is_original'] );
	}

	/* ------------------------------------------------------------------ *
	 * /link-existing (#116) — manually link existing content into a group
	 * ------------------------------------------------------------------ */

	public function test_link_existing_post_to_original_group(): void {
		Wpnt_Test_State::$posts[10] = array( 'ID' => 10, 'post_type' => 'post', 'post_title' => 'English original' );
		Wpnt_Test_State::$posts[11] = array( 'ID' => 11, 'post_type' => 'post', 'post_title' => 'Spanish translation' );
		Wpnt_Test_State::$caps['edit_post:10'] = true;
		Wpnt_Test_State::$caps['edit_post:11'] = true;
		$store = new Wpnt_Translation_Store();
		$store->set_language( 'post', 10, 'en' );
		$store->set_original( 'post', 10, true );
		$store->set_language( 'post', 11, 'es' );

		$response = $this->rest->handle_link_existing(
			$this->request( array( 'type' => 'post', 'object_id' => 11, 'original_id' => 10 ) )
		);

		$data = $response->get_data();
		$this->assertSame( 11, $data['object_id'] );
		$this->assertSame( 10, $data['translations']['en']['id'] );
		$this->assertSame( $store->get_group( 'post', 10 ), $store->get_group( 'post', 11 ) );
	}

	public function test_link_candidates_skip_uneditable_targets(): void {
		Wpnt_Test_State::$posts[10] = array( 'ID' => 10, 'post_type' => 'post', 'post_title' => 'Spanish translation' );
		Wpnt_Test_State::$posts[11] = array( 'ID' => 11, 'post_type' => 'post', 'post_title' => 'Hidden original' );
		Wpnt_Test_State::$posts[12] = array( 'ID' => 12, 'post_type' => 'post', 'post_title' => 'Visible original' );
		Wpnt_Test_State::$caps['edit_post:10'] = true;
		Wpnt_Test_State::$caps['edit_post:11'] = false;
		Wpnt_Test_State::$caps['edit_post:12'] = true;
		$store = new Wpnt_Translation_Store();
		$store->set_language( 'post', 10, 'es' );
		$store->set_language( 'post', 11, 'en' );
		$store->set_original( 'post', 11, true );
		$store->set_language( 'post', 12, 'en' );
		$store->set_original( 'post', 12, true );

		$response = $this->rest->handle_link_candidates(
			$this->request( array( 'type' => 'post', 'object_id' => 10 ) )
		);

		$data = $response->get_data();
		$this->assertSame( array( 12 ), array_column( $data['candidates'], 'id' ) );
	}

	public function test_link_existing_term_to_original_group(): void {
		Wpnt_Test_State::$terms[20] = array( 'term_id' => 20, 'taxonomy' => 'category', 'name' => 'English category' );
		Wpnt_Test_State::$terms[21] = array( 'term_id' => 21, 'taxonomy' => 'category', 'name' => 'Spanish category' );
		Wpnt_Test_State::$caps['edit_term:20'] = true;
		Wpnt_Test_State::$caps['edit_term:21'] = true;
		$store = new Wpnt_Translation_Store();
		$store->set_language( 'term', 20, 'en' );
		$store->set_original( 'term', 20, true );
		$store->set_language( 'term', 21, 'es' );

		$response = $this->rest->handle_link_existing(
			$this->request( array( 'type' => 'term', 'object_id' => 21, 'original_id' => 20 ) )
		);

		$data = $response->get_data();
		$this->assertSame( 20, $data['translations']['en']['id'] );
		$this->assertSame( $store->get_group( 'term', 20 ), $store->get_group( 'term', 21 ) );
	}

	public function test_link_existing_rejects_target_that_is_not_original(): void {
		Wpnt_Test_State::$posts[10] = array( 'ID' => 10, 'post_type' => 'post' );
		Wpnt_Test_State::$posts[11] = array( 'ID' => 11, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:10'] = true;
		$store = new Wpnt_Translation_Store();
		$store->set_language( 'post', 10, 'en' );
		$store->set_language( 'post', 11, 'es' );

		$result = $this->rest->handle_link_existing(
			$this->request( array( 'type' => 'post', 'object_id' => 11, 'original_id' => 10 ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_original_required', $result->get_error_code() );
	}

	public function test_link_existing_rejects_current_item_without_language(): void {
		Wpnt_Test_State::$posts[10] = array( 'ID' => 10, 'post_type' => 'post' );
		Wpnt_Test_State::$posts[11] = array( 'ID' => 11, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:10'] = true;
		$store = new Wpnt_Translation_Store();
		$store->set_language( 'post', 10, 'en' );
		$store->set_original( 'post', 10, true );

		$result = $this->rest->handle_link_existing(
			$this->request( array( 'type' => 'post', 'object_id' => 11, 'original_id' => 10 ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_current_language_required', $result->get_error_code() );
	}

	public function test_link_existing_rejects_incompatible_post_types(): void {
		Wpnt_Test_State::$posts[10] = array( 'ID' => 10, 'post_type' => 'post' );
		Wpnt_Test_State::$posts[11] = array( 'ID' => 11, 'post_type' => 'page' );
		Wpnt_Test_State::$caps['edit_post:10'] = true;
		$store = new Wpnt_Translation_Store();
		$store->set_language( 'post', 10, 'en' );
		$store->set_original( 'post', 10, true );
		$store->set_language( 'post', 11, 'es' );

		$result = $this->rest->handle_link_existing(
			$this->request( array( 'type' => 'post', 'object_id' => 11, 'original_id' => 10 ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_incompatible_link', $result->get_error_code() );
	}

	public function test_link_existing_rejects_duplicate_language_in_group(): void {
		Wpnt_Test_State::$posts[10] = array( 'ID' => 10, 'post_type' => 'post' );
		Wpnt_Test_State::$posts[11] = array( 'ID' => 11, 'post_type' => 'post' );
		Wpnt_Test_State::$posts[12] = array( 'ID' => 12, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:10'] = true;
		$store = new Wpnt_Translation_Store();
		$store->set_language( 'post', 10, 'en' );
		$store->set_original( 'post', 10, true );
		$store->set_language( 'post', 12, 'es' );
		$store->link_translation( 'post', 10, 12 );
		$store->set_language( 'post', 11, 'es' );

		$result = $this->rest->handle_link_existing(
			$this->request( array( 'type' => 'post', 'object_id' => 11, 'original_id' => 10 ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_language_exists', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
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
		$this->assertSame( 'wpnt_invalid_language', $result->get_error_code() );
		$this->assertSame( array(), Wpnt_Test_State::$as_enqueued );
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
		$this->assertSame( 'wpnt_invalid_language', $result->get_error_code() );
	}

	public function test_enqueue_skips_unauthorized_items_and_queues_allowed(): void {
		// Post 5: caller can edit + create → authorized → queued.
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:5']  = true;
		Wpnt_Test_State::$caps['create_posts'] = true;
		// Post 6: caller cannot edit → skipped (forbidden).
		Wpnt_Test_State::$posts[6] = array( 'ID' => 6, 'post_type' => 'post' );

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
		$this->assertCount( 1, Wpnt_Test_State::$as_enqueued );
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
		$this->assertSame( array(), Wpnt_Test_State::$as_enqueued );
	}

	/* ------------------------------------------------------------------ *
	 * /set-languages (#56) — synchronous manual bulk assignment
	 * ------------------------------------------------------------------ */

	public function test_set_languages_rejects_invalid_code(): void {
		$result = $this->rest->handle_set_languages(
			$this->request( array( 'items' => array( array( 'id' => 5, 'type' => 'post' ) ), 'code' => 'de' ) )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_invalid_language', $result->get_error_code() );
	}

	public function test_set_languages_sets_authorized_and_skips_others(): void {
		// Post 5: editable, no language yet → set.
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:5'] = true;
		// Post 6: not editable → forbidden.
		Wpnt_Test_State::$posts[6] = array( 'ID' => 6, 'post_type' => 'post' );

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
		$store = new Wpnt_Translation_Store();
		$this->assertSame( 'en', $store->get_language( 'post', 5 ) );
	}

	/* ------------------------------------------------------------------ *
	 * /enqueue-detection (#56) — queued AI detection
	 * ------------------------------------------------------------------ */

	public function test_enqueue_detection_queues_authorized_unmarked_item(): void {
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:5'] = true;

		$response = $this->rest->handle_enqueue_detection(
			$this->request( array( 'items' => array( array( 'id' => 5, 'type' => 'post' ) ) ) )
		);

		$data = $response->get_data();
		$this->assertSame( 1, $data['queued'] );
		$this->assertSame( 0, $data['skipped'] );
		$this->assertSame( 'queued', $data['results'][0]['status'] );
		$this->assertCount( 1, Wpnt_Test_State::$as_enqueued );
		$this->assertSame( Wpnt_Queue::DETECT_HOOK, Wpnt_Test_State::$as_enqueued[0][0] );
	}

	public function test_enqueue_detection_skips_forbidden_and_invalid(): void {
		Wpnt_Test_State::$posts[6] = array( 'ID' => 6, 'post_type' => 'post' ); // not editable.

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
		$this->assertSame( array(), Wpnt_Test_State::$as_enqueued );
	}

	public function test_enqueue_detection_rejects_when_no_enabled_languages(): void {
		Wpnt_Test_State::$options['wpnt_settings'] = array(
			'languages' => array(
				array( 'code' => 'en', 'name' => 'English', 'enabled' => false ),
			),
		);
		Wpnt_Languages::flush_index();
		Wpnt_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpnt_Test_State::$caps['edit_post:5'] = true;

		$result = $this->rest->handle_enqueue_detection(
			$this->request( array( 'items' => array( array( 'id' => 5, 'type' => 'post' ) ) ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_no_detection_languages', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), Wpnt_Test_State::$as_enqueued );
	}

	public function test_queue_status_handler_returns_counts(): void {
		Wpnt_Test_State::$as_counts = array(
			ActionScheduler_Store::STATUS_PENDING => 3,
			ActionScheduler_Store::STATUS_RUNNING => 0,
			ActionScheduler_Store::STATUS_FAILED  => 1,
		);
		$response = $this->rest->handle_queue_status();
		$this->assertSame( array( 'pending' => 3, 'running' => 0, 'failed' => 1 ), $response->get_data() );
	}

	/* ------------------------------------------------------------------ *
	 * /queue/cancel + /queue/cancel-all (#96) — manage_options gate + behavior
	 * ------------------------------------------------------------------ */

	public function test_cancel_routes_require_manage_options(): void {
		// Both cancel routes share the manage_options permission callback.
		Wpnt_Test_State::$caps['manage_options'] = false;
		$this->assertFalse( $this->rest->permission_manage() );
		Wpnt_Test_State::$caps['manage_options'] = true;
		$this->assertTrue( $this->rest->permission_manage() );
	}

	public function test_cancel_job_handler_cancels_pending_action(): void {
		Wpnt_Test_State::$as_actions = array(
			301 => array(
				'hook'   => Wpnt_Queue::HOOK,
				'args'   => array( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
		);

		$response = $this->rest->handle_cancel_job( $this->request( array( 'action_id' => 301 ) ) );
		$this->assertSame( array( 'cancelled' => true ), $response->get_data() );
		$this->assertSame( array( 301 ), Wpnt_Test_State::$as_cancelled );
	}

	public function test_cancel_job_handler_surfaces_unknown_action(): void {
		$result = $this->rest->handle_cancel_job( $this->request( array( 'action_id' => 999 ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_unknown_action', $result->get_error_code() );
	}

	public function test_cancel_all_handler_clears_pending_group(): void {
		Wpnt_Test_State::$as_actions = array(
			301 => array(
				'hook'   => Wpnt_Queue::HOOK,
				'args'   => array( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			302 => array(
				'hook'   => Wpnt_Queue::HOOK,
				'args'   => array( array( 'type' => 'post', 'id' => 8, 'target' => 'es' ) ),
				'status' => ActionScheduler_Store::STATUS_RUNNING,
			),
		);

		$response = $this->rest->handle_cancel_all();
		$this->assertSame( array( 'cancelled' => 2 ), $response->get_data() );
		$this->assertEqualsCanonicalizing( array( 301, 302 ), Wpnt_Test_State::$as_cancelled );
		$this->assertSame( array(), Wpnt_Test_State::$as_actions );
	}

	/* ------------------------------------------------------------------ *
	 * /install-language-pack (#62) — manage_options gate + configured-locale bound
	 * ------------------------------------------------------------------ */

	/** Replaces the settings with configured locales for the pack tests. */
	private function seed_locales(): void {
		Wpnt_Languages::flush_index();
		Wpnt_Test_State::$options['wpnt_settings'] = array(
			'languages' => array(
				array( 'code' => 'en', 'name' => 'English', 'locale' => 'en_US', 'enabled' => true ),
				array( 'code' => 'es', 'name' => 'Spanish', 'locale' => 'es_ES', 'enabled' => true ),
			),
		);
	}

	public function test_install_pack_admin_gate(): void {
		Wpnt_Test_State::$caps['manage_options'] = false;
		$this->assertFalse( $this->rest->permission_manage() );
		Wpnt_Test_State::$caps['manage_options'] = true;
		$this->assertTrue( $this->rest->permission_manage() );
	}

	public function test_install_pack_rejects_unconfigured_locale(): void {
		$this->seed_locales();
		$result = $this->rest->handle_install_language_pack( $this->request( array( 'locale' => 'fr_FR' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_invalid_locale', $result->get_error_code() );
		$this->assertSame( array(), Wpnt_Test_State::$downloaded_locales );
	}

	public function test_install_pack_downloads_configured_locale(): void {
		$this->seed_locales();
		Wpnt_Test_State::$available_languages = array(); // es_ES not yet installed.

		$response = $this->rest->handle_install_language_pack( $this->request( array( 'locale' => 'es_ES' ) ) );
		$data     = $response->get_data();

		$this->assertTrue( $data['installed'] );
		$this->assertSame( 'es_ES', $data['locale'] );
		$this->assertSame( array( 'es_ES' ), Wpnt_Test_State::$downloaded_locales );
	}

	public function test_install_pack_builtin_locale_needs_no_download(): void {
		$this->seed_locales();
		$response = $this->rest->handle_install_language_pack( $this->request( array( 'locale' => 'en_US' ) ) );
		$this->assertTrue( $response->get_data()['installed'] );
		$this->assertSame( array(), Wpnt_Test_State::$downloaded_locales );
	}

	public function test_install_pack_surfaces_download_failure(): void {
		$this->seed_locales();
		Wpnt_Test_State::$download_result = false; // Simulate a failed download.
		$result = $this->rest->handle_install_language_pack( $this->request( array( 'locale' => 'es_ES' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_install_failed', $result->get_error_code() );
	}
}
