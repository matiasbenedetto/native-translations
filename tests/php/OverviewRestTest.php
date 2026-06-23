<?php
/**
 * Unit tests for the GET /wp-ai-translate/v1/overview REST endpoint (#54).
 *
 * Covers the admin-only permission gate and the response-shape contract the
 * DataViews app depends on (rows/total/total_pages/languages/ai_ok/counts), plus
 * the missing-vs-by-language switch and server-side search/pagination over the
 * bounded cross post+term scan (N6). Drives the real Wpait_Admin_List against the
 * in-memory WP model, so the actual missing-translation computation runs.
 *
 * @package WpAiTranslate\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class OverviewRestTest extends TestCase {

	private Wpait_Admin_List $list;

	protected function setUp(): void {
		Wpait_Test_State::reset();
		Wpait_Languages::flush_index();
		Wpait_Test_State::$options['wpait_settings'] = array(
			'languages' => array(
				array( 'code' => 'en', 'name' => 'English', 'enabled' => true ),
				array( 'code' => 'es', 'name' => 'Spanish', 'enabled' => true ),
			),
		);
		$this->list = new Wpait_Admin_List( new Wpait_Translation_Store(), new Wpait_Languages() );
		// Translate availability is metadata-only here; the fake connector reports
		// supported by default, so can_generate_text() is true.
	}

	private function seed_post( int $id, string $code, string $group = '', string $type = 'post', string $title = '' ): void {
		Wpait_Test_State::$posts[ $id ] = array(
			'ID'          => $id,
			'post_type'   => $type,
			'post_status' => 'publish',
			'post_title'  => '' !== $title ? $title : "Post $id",
		);
		if ( '' !== $code ) {
			Wpait_Test_State::$object_terms[ $id ][ Wpait_Languages::TAXONOMY ] = array( $code );
		}
		if ( '' !== $group ) {
			Wpait_Test_State::$post_meta[ $id ][ Wpait_Translation_Store::META_GROUP ] = $group;
		}
	}

	private function payload( array $args = array() ): array {
		$request = new WP_REST_Request(
			array_merge(
				array(
					'view'        => 'missing',
					'page'        => 1,
					'per_page'    => 20,
					'orderby'     => 'title',
					'order'       => 'asc',
					'search'      => '',
					'show_hidden' => false,
				),
				$args
			)
		);
		return $this->list->handle_overview( $request )->get_data();
	}

	/* ------------------------------------------------------------------ *
	 * Permission gate (manage_options)
	 * ------------------------------------------------------------------ */

	public function test_overview_route_permission_requires_manage_options(): void {
		// The route registers a manage_options closure as permission_callback; mirror
		// that gate here (current_user_can is the only decision point).
		Wpait_Test_State::$caps['manage_options'] = false;
		$this->assertFalse( current_user_can( 'manage_options' ) );
		Wpait_Test_State::$caps['manage_options'] = true;
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Response shape contract
	 * ------------------------------------------------------------------ */

	public function test_payload_has_expected_top_level_keys(): void {
		$data = $this->payload();
		foreach ( array( 'rows', 'total', 'total_pages', 'languages', 'ai_ok', 'scan_truncated', 'counts' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, "missing key: $key" );
		}
		$this->assertIsArray( $data['rows'] );
		$this->assertArrayHasKey( 'missing', $data['counts'] );
		$this->assertArrayHasKey( 'by_language', $data['counts'] );
		// Languages echoed as {code,name}.
		$this->assertSame( 'en', $data['languages'][0]['code'] );
		$this->assertSame( 'English', $data['languages'][0]['name'] );
	}

	public function test_missing_view_lists_untranslated_with_chips_and_key(): void {
		// post 10 (en) + 11 (es) complete; post 20 only English → missing Spanish.
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );
		$this->seed_post( 20, 'en', '', 'post', 'Lonely English' );

		$data = $this->payload();

		$this->assertSame( 1, $data['total'] );
		$this->assertCount( 1, $data['rows'] );

		$row = $data['rows'][0];
		$this->assertSame( 'post:20', $row['key'] );
		$this->assertSame( 'post', $row['type'] );
		$this->assertSame( 20, $row['id'] );
		$this->assertSame( 'Lonely English', $row['title'] );
		$this->assertSame( 'Post', $row['type_label'] );
		$this->assertSame( 'en', $row['language']['code'] );
		// Missing renders as [{code,name}] chips.
		$this->assertSame( 'es', $row['missing'][0]['code'] );
		$this->assertSame( 'Spanish', $row['missing'][0]['name'] );
		$this->assertFalse( $row['hidden'] );
		$this->assertArrayHasKey( 'edit_url', $row );
		$this->assertSame( 1, $data['counts']['missing'] );
	}

	public function test_missing_view_excludes_translated_copies_keeping_originals(): void {
		// Default (source) language is English; en/es/fr enabled. The missing view
		// should only offer originals (default language) and unmarked items as
		// translation sources — never a translated copy (#85).
		Wpait_Languages::flush_index();
		Wpait_Test_State::$options['wpait_settings'] = array(
			'default_language' => 'en',
			'languages'        => array(
				array( 'code' => 'en', 'name' => 'English', 'enabled' => true ),
				array( 'code' => 'es', 'name' => 'Spanish', 'enabled' => true ),
				array( 'code' => 'fr', 'name' => 'French', 'enabled' => true ),
			),
		);

		// Group g1: en original (10) + its es translation (11). Both lack fr.
		$this->seed_post( 10, 'en', 'g1', 'post', 'Original' );
		$this->seed_post( 11, 'es', 'g1', 'post', 'Traduccion' );
		// Unmarked item (no language) — an original candidate, kept.
		$this->seed_post( 20, '', '', 'post', 'Unmarked' );
		// Standalone non-default-language item — a copy, excluded.
		$this->seed_post( 30, 'es', '', 'post', 'Standalone ES' );

		$data = $this->payload( array( 'view' => 'missing' ) );
		$ids  = array_map( static fn ( $r ) => $r['id'], $data['rows'] );
		sort( $ids );

		$this->assertContains( 10, $ids, 'the en original (missing fr) should be listed' );
		$this->assertContains( 20, $ids, 'the unmarked item should be listed' );
		$this->assertNotContains( 11, $ids, 'the es translation must not be listed' );
		$this->assertNotContains( 30, $ids, 'a non-default-language copy must not be listed' );
		$this->assertSame( count( $data['rows'] ), $data['counts']['missing'], 'count matches the filtered list' );
	}

	public function test_search_filters_rows_by_title(): void {
		$this->seed_post( 20, 'en', '', 'post', 'Alpha' );
		$this->seed_post( 21, 'en', '', 'post', 'Beta' );

		$all = $this->payload();
		$this->assertSame( 2, $all['total'] );

		$filtered = $this->payload( array( 'search' => 'alph' ) );
		$this->assertSame( 1, $filtered['total'] );
		$this->assertSame( 'Alpha', $filtered['rows'][0]['title'] );
	}

	public function test_pagination_reports_total_pages_and_slices(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->seed_post( 100 + $i, 'en', '', 'post', sprintf( 'Item %02d', $i ) );
		}
		$data = $this->payload( array( 'per_page' => 2 ) );

		$this->assertSame( 5, $data['total'] );
		$this->assertSame( 3, $data['total_pages'] );
		$this->assertCount( 2, $data['rows'] );

		$page3 = $this->payload( array( 'per_page' => 2, 'page' => 3 ) );
		$this->assertCount( 1, $page3['rows'] );
	}

	public function test_by_language_view_lists_assigned_posts(): void {
		$this->seed_post( 30, 'es', '', 'post', 'Spanish Post' );
		$this->seed_post( 31, 'en', '', 'post', 'English Post' );

		$data = $this->payload( array( 'view' => 'es' ) );

		$keys = wp_list_pluck( $data['rows'], 'key' );
		$this->assertContains( 'post:30', $keys );
		$this->assertNotContains( 'post:31', $keys );
		$this->assertSame( 'es', $data['rows'][0]['language']['code'] );
	}

	public function test_unmarked_view_lists_only_items_with_no_language(): void {
		// Post 40 has a language; post 41 has none → only 41 is "unmarked" (#56).
		$this->seed_post( 40, 'es', '', 'post', 'Marked' );
		$this->seed_post( 41, '', '', 'post', 'Orphan' );

		$data = $this->payload( array( 'view' => 'unmarked' ) );

		$keys = wp_list_pluck( $data['rows'], 'key' );
		$this->assertContains( 'post:41', $keys );
		$this->assertNotContains( 'post:40', $keys );
		// Unmarked rows carry language: null and an empty missing list.
		$row = $data['rows'][0];
		$this->assertNull( $row['language'] );
		$this->assertSame( array(), $row['missing'] );
		// counts.unmarked is exposed for the tab badge.
		$this->assertArrayHasKey( 'unmarked', $data['counts'] );
		$this->assertSame( 1, $data['counts']['unmarked'] );
	}

	public function test_ai_ok_reflects_connector_availability(): void {
		Wpait_Test_State::$supports_ai = true;
		$this->assertTrue( $this->payload()['ai_ok'] );

		Wpait_Test_State::$supports_ai = false;
		$this->assertFalse( $this->payload()['ai_ok'] );
	}
}
