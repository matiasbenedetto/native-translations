<?php
/**
 * Unit tests for Wpait_Admin_List — the term-list `get_terms_args` discriminator
 * (#13) and the untranslated cross-join computation (N6).
 *
 * The discriminator must rewrite ONLY the main edit-tags list query on a
 * category/tag screen, and never the parent-category / Quick-Edit dropdowns
 * (which carry `value_field`) or the internal scan (re-entrancy guard). The
 * untranslated computation is exercised through the public filter entry points so
 * the real present-codes logic runs against the in-memory model.
 *
 * @package WpAiTranslate\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class AdminListTest extends TestCase {

	private Wpait_Admin_List $list;

	protected function setUp(): void {
		Wpait_Test_State::reset();
		Wpait_Languages::flush_index();
		Wpait_Test_State::$options['wpait_settings'] = array(
			'default_language' => 'en',
			'languages'        => array(
				array( 'code' => 'en', 'name' => 'English', 'flag' => 'us', 'enabled' => true ),
				array( 'code' => 'es', 'name' => 'Spanish', 'flag' => 'es', 'enabled' => true ),
			),
		);
		$this->list = new Wpait_Admin_List( new Wpait_Translation_Store(), new Wpait_Languages() );
		Wpait_Test_State::$is_admin = true;
	}

	private function term_screen( string $taxonomy = 'category' ): void {
		Wpait_Test_State::$current_screen = (object) array( 'base' => 'edit-tags', 'taxonomy' => $taxonomy );
	}

	private function post_screen( string $post_type = 'post' ): void {
		Wpait_Test_State::$current_screen = (object) array( 'base' => 'edit', 'post_type' => $post_type );
	}

	private function seed_post( int $id, string $code, string $group = '' ): void {
		Wpait_Test_State::$posts[ $id ] = array( 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish' );
		if ( '' !== $code ) {
			Wpait_Test_State::$object_terms[ $id ][ Wpait_Languages::TAXONOMY ] = array( $code );
		}
		if ( '' !== $group ) {
			Wpait_Test_State::$post_meta[ $id ][ Wpait_Translation_Store::META_GROUP ] = $group;
		}
	}

	private function seed_term( int $id, string $code, string $group = '' ): void {
		Wpait_Test_State::$terms[ $id ] = array( 'term_id' => $id, 'taxonomy' => 'category', 'name' => "Term $id" );
		if ( '' !== $code ) {
			Wpait_Test_State::$term_meta[ $id ][ Wpait_Translation_Store::META_LANGUAGE ] = $code;
		}
		if ( '' !== $group ) {
			Wpait_Test_State::$term_meta[ $id ][ Wpait_Translation_Store::META_GROUP ] = $group;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Language column rendering (#89)
	 * ------------------------------------------------------------------ */

	public function test_post_language_column_shows_flagcdn_code_and_linked_original_for_translation(): void {
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );

		ob_start();
		$this->list->render_column( Wpait_Admin_List::COLUMN, 11 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'https://flagcdn.com/es.svg', $html );
		$this->assertStringContainsString( '<strong>es</strong>', $html );
		$this->assertStringContainsString( 'translated from', $html );
		$this->assertStringContainsString( 'href="https://example.test/wp-admin/post.php?post=10&#038;action=edit"', $html );
		$this->assertStringContainsString( 'aria-label="Edit the English original"', $html );
		$this->assertStringContainsString( '>en</a>', $html );
		$this->assertStringNotContainsString( 'Spanish', $html );
	}

	public function test_post_language_column_original_only_shows_flag_and_code(): void {
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );

		ob_start();
		$this->list->render_column( Wpait_Admin_List::COLUMN, 10 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'https://flagcdn.com/us.svg', $html );
		$this->assertStringContainsString( '<strong>en</strong>', $html );
		$this->assertStringNotContainsString( 'translated from', $html );
		$this->assertStringNotContainsString( 'post=11', $html );
	}

	public function test_term_language_column_shows_flagcdn_code_and_linked_original_for_translation(): void {
		$this->seed_term( 20, 'en', 'tg1' );
		$this->seed_term( 21, 'es', 'tg1' );

		$html = $this->list->render_term_column( '', Wpait_Admin_List::COLUMN, 21 );

		$this->assertStringContainsString( 'https://flagcdn.com/es.svg', $html );
		$this->assertStringContainsString( '<strong>es</strong>', $html );
		$this->assertStringContainsString( 'translated from', $html );
		$this->assertStringContainsString( 'href="https://example.test/wp-admin/term.php?tag_ID=20"', $html );
		$this->assertStringContainsString( 'aria-label="Edit the English original"', $html );
		$this->assertStringContainsString( '>en</a>', $html );
		$this->assertStringNotContainsString( 'Spanish', $html );
	}

	/* ------------------------------------------------------------------ *
	 * get_terms_args discriminator (#13)
	 * ------------------------------------------------------------------ */

	public function test_passthrough_when_not_admin(): void {
		Wpait_Test_State::$is_admin = false;
		$this->term_screen();
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = 'es';
		$args = array( 'taxonomy' => array( 'category' ) );
		$this->assertSame( $args, $this->list->filter_terms_query( $args, array( 'category' ) ) );
	}

	public function test_passthrough_on_non_term_screen(): void {
		$this->post_screen();
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = 'es';
		$args = array( 'taxonomy' => array( 'category' ) );
		$this->assertSame( $args, $this->list->filter_terms_query( $args, array( 'category' ) ) );
	}

	public function test_passthrough_when_queried_taxonomies_do_not_match_screen(): void {
		// Screen is the category list, but this query asks for both taxonomies (the
		// internal untranslated scan shape) — must not be rewritten.
		$this->term_screen( 'category' );
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = 'es';
		$args = array( 'taxonomy' => array( 'category', 'post_tag' ) );
		$this->assertSame( $args, $this->list->filter_terms_query( $args, array( 'category', 'post_tag' ) ) );
	}

	public function test_passthrough_for_dropdown_query_with_value_field(): void {
		// wp_dropdown_categories() leaves `value_field` set — these list-every-term
		// dropdowns must never be filtered (#13).
		$this->term_screen();
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = 'es';
		$args = array( 'taxonomy' => array( 'category' ), 'value_field' => 'term_id' );
		$this->assertSame( $args, $this->list->filter_terms_query( $args, array( 'category' ) ) );
	}

	public function test_passthrough_when_no_filter_value(): void {
		$this->term_screen();
		// No QUERY_VAR in $_GET.
		$args = array( 'taxonomy' => array( 'category' ) );
		$this->assertSame( $args, $this->list->filter_terms_query( $args, array( 'category' ) ) );
	}

	public function test_language_filter_adds_meta_query(): void {
		$this->term_screen();
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = 'es';
		$out = $this->list->filter_terms_query( array( 'taxonomy' => array( 'category' ) ), array( 'category' ) );

		$this->assertArrayHasKey( 'meta_query', $out );
		$this->assertSame( Wpait_Translation_Store::META_LANGUAGE, $out['meta_query'][0]['key'] );
		$this->assertSame( 'es', $out['meta_query'][0]['value'] );
	}

	public function test_language_filter_preserves_existing_meta_query(): void {
		$this->term_screen();
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = 'es';
		$existing = array( array( 'key' => '_other', 'value' => 'x' ) );
		$out      = $this->list->filter_terms_query(
			array( 'taxonomy' => array( 'category' ), 'meta_query' => $existing ),
			array( 'category' )
		);
		$this->assertCount( 2, $out['meta_query'] );
		$this->assertSame( '_other', $out['meta_query'][0]['key'] );
	}

	/* ------------------------------------------------------------------ *
	 * Untranslated computation — through the term filter (#13 + N6)
	 * ------------------------------------------------------------------ */

	public function test_untranslated_term_filter_includes_only_missing_terms(): void {
		// term 20 (en) + 21 (es) are a complete group → translated.
		$this->seed_term( 20, 'en', 'tg1' );
		$this->seed_term( 21, 'es', 'tg1' );
		// term 30 has only English → missing Spanish → untranslated.
		$this->seed_term( 30, 'en' );

		$this->term_screen();
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = Wpait_Admin_List::UNTRANSLATED;
		$out = $this->list->filter_terms_query( array( 'taxonomy' => array( 'category' ) ), array( 'category' ) );

		$this->assertArrayHasKey( 'include', $out );
		$this->assertContains( 30, $out['include'] );
		$this->assertNotContains( 20, $out['include'] );
		$this->assertNotContains( 21, $out['include'] );
	}

	public function test_untranslated_term_filter_forces_empty_when_all_translated(): void {
		$this->seed_term( 20, 'en', 'tg1' );
		$this->seed_term( 21, 'es', 'tg1' );

		$this->term_screen();
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = Wpait_Admin_List::UNTRANSLATED;
		$out = $this->list->filter_terms_query( array( 'taxonomy' => array( 'category' ) ), array( 'category' ) );

		// Nothing untranslated → an impossible include so the list shows zero rows.
		$this->assertSame( array( 0 ), $out['include'] );
	}

	/* ------------------------------------------------------------------ *
	 * Untranslated computation — through the post filter_query (pre_get_posts)
	 * ------------------------------------------------------------------ */

	public function test_untranslated_post_filter_sets_post__in_to_missing_posts(): void {
		// post 10 (en) + 11 (es) complete group; post 20 only English (untranslated).
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );
		$this->seed_post( 20, 'en' );

		$this->post_screen( 'post' );
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = Wpait_Admin_List::UNTRANSLATED;

		$query          = new WP_Query();
		$query->is_main = true;
		$this->list->filter_query( $query );

		$post_in = $query->get( 'post__in' );
		$this->assertContains( 20, $post_in );
		$this->assertNotContains( 10, $post_in );
		$this->assertNotContains( 11, $post_in );
	}

	public function test_post_language_filter_sets_tax_query(): void {
		$this->seed_post( 10, 'en' );
		$this->post_screen( 'post' );
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = 'es';

		$query          = new WP_Query();
		$query->is_main = true;
		$this->list->filter_query( $query );

		// filter_query casts the (empty-string) default tax_query to an array before
		// appending, so the new clause lands after the cast placeholder; assert it is
		// present regardless of index.
		$tax_query = $query->get( 'tax_query' );
		$clause    = array_values( array_filter( (array) $tax_query, 'is_array' ) )[0];
		$this->assertSame( Wpait_Languages::TAXONOMY, $clause['taxonomy'] );
		$this->assertSame( 'es', $clause['terms'] );
	}

	public function test_filter_query_ignored_for_non_main_query(): void {
		$this->post_screen( 'post' );
		$_GET[ Wpait_Admin_List::QUERY_VAR ] = 'es';
		$query          = new WP_Query();
		$query->is_main = false; // not the main query.
		$this->list->filter_query( $query );
		$this->assertSame( '', $query->get( 'tax_query' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Overview hidden flag (set_overview_hidden)
	 * ------------------------------------------------------------------ */

	public function test_set_overview_hidden_writes_and_clears_post_meta(): void {
		$this->seed_post( 10, 'en' );
		$this->list->set_overview_hidden( 'post', 10, true );
		$this->assertSame( '1', Wpait_Test_State::$post_meta[10][ Wpait_Admin_List::HIDE_META ] );

		$this->list->set_overview_hidden( 'post', 10, false );
		$this->assertArrayNotHasKey( Wpait_Admin_List::HIDE_META, Wpait_Test_State::$post_meta[10] ?? array() );
	}

	public function test_set_overview_hidden_writes_and_clears_term_meta(): void {
		$this->seed_term( 20, 'en' );
		$this->list->set_overview_hidden( 'term', 20, true );
		$this->assertSame( '1', Wpait_Test_State::$term_meta[20][ Wpait_Admin_List::HIDE_META ] );

		$this->list->set_overview_hidden( 'term', 20, false );
		$this->assertArrayNotHasKey( Wpait_Admin_List::HIDE_META, Wpait_Test_State::$term_meta[20] ?? array() );
	}
}
