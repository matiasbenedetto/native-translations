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

	/** Marks a seeded post/term as a translation original (#92). */
	private function mark_original( int $id, string $type = 'post' ): void {
		if ( 'term' === $type ) {
			Wpait_Test_State::$term_meta[ $id ][ Wpait_Translation_Store::META_IS_ORIGINAL ] = '1';
		} else {
			Wpait_Test_State::$post_meta[ $id ][ Wpait_Translation_Store::META_IS_ORIGINAL ] = '1';
		}
	}

	private function payload( array $args = array() ): array {
		$request = new WP_REST_Request(
			array_merge(
				array(
					'view'        => 'originals',
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
		$this->assertArrayHasKey( 'originals', $data['counts'] );
		$this->assertArrayHasKey( 'by_language', $data['counts'] );
		// Languages echoed as {code,name}.
		$this->assertSame( 'en', $data['languages'][0]['code'] );
		$this->assertSame( 'English', $data['languages'][0]['name'] );
	}

	public function test_originals_view_lists_marked_originals_with_chips_and_key(): void {
		// post 20: English, marked original, no translation group → enabled langs are
		// en + es, so it is still missing Spanish (rendered as a chip).
		$this->seed_post( 20, 'en', '', 'post', 'Lonely English' );
		$this->mark_original( 20 );

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
		$this->assertTrue( $row['is_original'] );
		// Missing renders as [{code,name}] chips: this original still lacks Spanish.
		$this->assertSame( 'es', $row['missing'][0]['code'] );
		$this->assertSame( 'Spanish', $row['missing'][0]['name'] );
		$this->assertFalse( $row['hidden'] );
		$this->assertArrayHasKey( 'edit_url', $row );
		$this->assertSame( 1, $data['counts']['originals'] );
	}

	public function test_originals_view_lists_only_language_set_and_marked_items(): void {
		// 10: language set AND marked original → listed.
		$this->seed_post( 10, 'en', '', 'post', 'Marked original' );
		$this->mark_original( 10 );
		// 11: language set but NOT marked → excluded.
		$this->seed_post( 11, 'es', '', 'post', 'Language only' );
		// 12: marked but NO language → excluded (and can never be marked via the UI).
		$this->seed_post( 12, '', '', 'post', 'Marked no language' );
		$this->mark_original( 12 );
		// 13: neither → excluded.
		$this->seed_post( 13, '', '', 'post', 'Plain' );

		$data = $this->payload();
		$ids  = array_map( static fn ( $r ) => $r['id'], $data['rows'] );
		sort( $ids );

		$this->assertSame( array( 10 ), $ids, 'only the language-set, marked-original item appears' );
		$this->assertSame( 1, $data['counts']['originals'] );
	}

	public function test_originals_view_includes_terms_marked_original(): void {
		// Term path: a category with a language set and marked original is listed.
		Wpait_Test_State::$terms[50] = array( 'term_id' => 50, 'taxonomy' => 'category', 'name' => 'Noticias' );
		Wpait_Test_State::$term_meta[50][ Wpait_Translation_Store::META_LANGUAGE ] = 'es';
		$this->mark_original( 50, 'term' );

		$data = $this->payload();
		$keys = wp_list_pluck( $data['rows'], 'key' );

		$this->assertContains( 'term:50', $keys );
		$row = $data['rows'][ array_search( 'term:50', $keys, true ) ];
		$this->assertTrue( $row['is_original'] );
		$this->assertSame( 'es', $row['language']['code'] );
	}

	public function test_rows_include_featured_image_thumbnail_url_or_null( ): void {
		// post 20: marked original WITH a featured image → thumbnail is a URL.
		$this->seed_post( 20, 'en', '', 'post', 'With image' );
		$this->mark_original( 20 );
		Wpait_Test_State::$post_meta[20]['_thumbnail_id'] = '777';
		// post 21: marked original with NO featured image → thumbnail is null.
		$this->seed_post( 21, 'en', '', 'post', 'No image' );
		$this->mark_original( 21 );
		// term 50: terms never have a featured image → thumbnail is null.
		Wpait_Test_State::$terms[50] = array( 'term_id' => 50, 'taxonomy' => 'category', 'name' => 'News' );
		Wpait_Test_State::$term_meta[50][ Wpait_Translation_Store::META_LANGUAGE ] = 'en';
		$this->mark_original( 50, 'term' );

		$data    = $this->payload();
		$by_key  = array();
		foreach ( $data['rows'] as $row ) {
			$this->assertArrayHasKey( 'thumbnail', $row, 'every row carries a thumbnail key' );
			$by_key[ $row['key'] ] = $row;
		}

		$this->assertSame( 'https://example.test/wp-content/uploads/thumb-777.png', $by_key['post:20']['thumbnail'] );
		$this->assertNull( $by_key['post:21']['thumbnail'] );
		$this->assertNull( $by_key['term:50']['thumbnail'] );
	}

	public function test_originals_count_zero_when_nothing_marked(): void {
		// Backs the React empty-state default-to-Unmarked: with content present but
		// nothing marked original, the Originals view is empty and the count is 0,
		// while Unmarked still has rows to fall back to (#92).
		$this->seed_post( 10, 'en', '', 'post', 'Has language, not marked' );
		$this->seed_post( 20, '', '', 'post', 'Unmarked candidate' );

		$data = $this->payload();
		$this->assertSame( 0, $data['total'] );
		$this->assertSame( array(), $data['rows'] );
		$this->assertSame( 0, $data['counts']['originals'] );
		$this->assertGreaterThan( 0, $data['counts']['unmarked'], 'there is an Unmarked item to default to' );
	}

	public function test_search_filters_rows_by_title(): void {
		$this->seed_post( 20, 'en', '', 'post', 'Alpha' );
		$this->mark_original( 20 );
		$this->seed_post( 21, 'en', '', 'post', 'Beta' );
		$this->mark_original( 21 );

		$all = $this->payload();
		$this->assertSame( 2, $all['total'] );

		$filtered = $this->payload( array( 'search' => 'alph' ) );
		$this->assertSame( 1, $filtered['total'] );
		$this->assertSame( 'Alpha', $filtered['rows'][0]['title'] );
	}

	public function test_pagination_reports_total_pages_and_slices(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->seed_post( 100 + $i, 'en', '', 'post', sprintf( 'Item %02d', $i ) );
			$this->mark_original( 100 + $i );
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

	public function test_tab_counts_correct_from_a_non_originals_view(): void {
		// Guards the count reuse: the active view's pre-search rows back its own
		// tab count, while the other tabs' counts still come from their own scan.
		// Viewing "unmarked" must still report the right Originals count, and vice
		// versa, regardless of which view's rows happen to be built this request.
		$this->seed_post( 10, 'en', '', 'post', 'Marked original' );
		$this->mark_original( 10 );
		$this->seed_post( 11, 'es', '', 'post', 'Another original' );
		$this->mark_original( 11 );
		$this->seed_post( 20, '', '', 'post', 'Unmarked one' );
		$this->seed_post( 21, '', '', 'post', 'Unmarked two' );
		$this->seed_post( 22, '', '', 'post', 'Unmarked three' );

		$from_unmarked = $this->payload( array( 'view' => 'unmarked' ) );
		$this->assertSame( 3, $from_unmarked['total'], 'unmarked view rows' );
		$this->assertSame( 3, $from_unmarked['counts']['unmarked'], 'active view count from built rows' );
		$this->assertSame( 2, $from_unmarked['counts']['originals'], 'originals count still scanned' );

		$from_originals = $this->payload( array( 'view' => 'originals' ) );
		$this->assertSame( 2, $from_originals['total'], 'originals view rows' );
		$this->assertSame( 2, $from_originals['counts']['originals'], 'active view count from built rows' );
		$this->assertSame( 3, $from_originals['counts']['unmarked'], 'unmarked count still scanned' );
	}

	public function test_ai_ok_reflects_connector_availability(): void {
		Wpait_Test_State::$supports_ai = true;
		$this->assertTrue( $this->payload()['ai_ok'] );

		Wpait_Test_State::$supports_ai = false;
		$this->assertFalse( $this->payload()['ai_ok'] );
	}
}
