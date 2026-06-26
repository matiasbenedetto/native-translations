<?php
/**
 * Unit tests for Wpnt_Frontend — the shared link builders the two front-end
 * blocks both call (#16) and the context-safety guards of the resolver (S6/S7).
 *
 * Covers links_for_post / links_for_term (own-language row, viewable siblings,
 * omitting languages with no viewable target) and resolve_post_id /
 * resolve_term_id (explicit block postId context, singular queried object, and
 * the refusal to guess a post/term outside a safe context).
 *
 * NOTE: render_* and register_blocks need WordPress block registration +
 * WPNT_PLUGIN_DIR asset files (real block.json / build manifests on disk), so
 * they are out of scope for a DB/asset-free unit suite and are covered by E2E.
 * has_balanced_blocks lives on Wpnt_Translator (private), not the frontend.
 *
 * @package WpNativeTranslations\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class FrontendTest extends TestCase {

	private Wpnt_Frontend $frontend;
	private Wpnt_Translation_Store $store;

	protected function setUp(): void {
		Wpnt_Test_State::reset();
		Wpnt_Languages::flush_index();
		Wpnt_Test_State::$options['wpnt_settings'] = array(
			'languages' => array(
				array( 'code' => 'en', 'name' => 'English', 'native' => 'English', 'flag' => '🇺🇸', 'enabled' => true ),
				array( 'code' => 'es', 'name' => 'Spanish', 'native' => 'Español', 'flag' => '🇦🇷', 'enabled' => true ),
				array( 'code' => 'de', 'name' => 'German', 'native' => 'Deutsch', 'flag' => '', 'enabled' => false ),
			),
		);
		$this->store    = new Wpnt_Translation_Store();
		$this->frontend = new Wpnt_Frontend( $this->store, new Wpnt_Languages() );
	}

	private function seed_post( int $id, string $code, string $group = '', string $status = 'publish' ): void {
		Wpnt_Test_State::$posts[ $id ] = array( 'ID' => $id, 'post_type' => 'post', 'post_status' => $status );
		Wpnt_Test_State::$object_terms[ $id ][ Wpnt_Languages::TAXONOMY ] = array( $code );
		if ( '' !== $group ) {
			Wpnt_Test_State::$post_meta[ $id ][ Wpnt_Translation_Store::META_GROUP ] = $group;
		}
	}

	private function seed_term( int $id, string $code, string $group = '' ): void {
		Wpnt_Test_State::$terms[ $id ] = array( 'term_id' => $id, 'taxonomy' => 'category', 'name' => "Term $id", 'slug' => "term-$id" );
		Wpnt_Test_State::$term_meta[ $id ][ Wpnt_Translation_Store::META_LANGUAGE ] = $code;
		if ( '' !== $group ) {
			Wpnt_Test_State::$term_meta[ $id ][ Wpnt_Translation_Store::META_GROUP ] = $group;
		}
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function codes( array $rows ): array {
		return array_map( static fn( $r ) => $r['code'], $rows );
	}

	/* ------------------------------------------------------------------ *
	 * links_for_post (#16 / S7)
	 * ------------------------------------------------------------------ */

	public function test_links_for_post_empty_for_non_positive_id(): void {
		$this->assertSame( array(), $this->frontend->links_for_post( 0, true ) );
		$this->assertSame( array(), $this->frontend->links_for_post( -3, true ) );
	}

	public function test_links_for_post_includes_current_and_viewable_sibling(): void {
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );

		$rows = $this->frontend->links_for_post( 10, true );
		$this->assertSame( array( 'en', 'es' ), $this->codes( $rows ) );

		$current = $rows[0];
		$this->assertTrue( $current['is_current'] );
		$this->assertSame( 'https://example.test/?p=10', $current['url'] );

		$sibling = $rows[1];
		$this->assertFalse( $sibling['is_current'] );
		$this->assertSame( 'https://example.test/?p=11', $sibling['url'] );
	}

	public function test_links_for_post_excludes_current_when_flag_off(): void {
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );
		$rows = $this->frontend->links_for_post( 10, false );
		$this->assertSame( array( 'es' ), $this->codes( $rows ) );
	}

	public function test_links_for_post_omits_language_with_no_translation(): void {
		// Only English + Spanish exist; the enabled list also has no German row anyway
		// (German is disabled). A language with no viewable target is simply absent.
		$this->seed_post( 10, 'en', 'g1' );
		$rows = $this->frontend->links_for_post( 10, true );
		// Lone member: only its own row, Spanish has no sibling so it is omitted.
		$this->assertSame( array( 'en' ), $this->codes( $rows ) );
	}

	public function test_links_for_post_omits_non_viewable_sibling(): void {
		$this->seed_post( 10, 'en', 'g1', 'publish' );
		$this->seed_post( 11, 'es', 'g1', 'draft' );
		Wpnt_Test_State::$not_viewable[11] = true;

		$rows = $this->frontend->links_for_post( 10, true );
		// The non-viewable Spanish draft is filtered out; only the current row remains.
		$this->assertSame( array( 'en' ), $this->codes( $rows ) );
	}

	public function test_links_for_post_skips_disabled_languages(): void {
		// Seed a German (disabled) sibling — it must never appear in the switcher.
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'de', 'g1' );
		$rows = $this->frontend->links_for_post( 10, true );
		$this->assertNotContains( 'de', $this->codes( $rows ) );
	}

	/* ------------------------------------------------------------------ *
	 * links_for_term (#16 / S7)
	 * ------------------------------------------------------------------ */

	public function test_links_for_term_includes_current_and_sibling(): void {
		$this->seed_term( 20, 'en', 'tg1' );
		$this->seed_term( 21, 'es', 'tg1' );

		$rows = $this->frontend->links_for_term( 20, true );
		$this->assertSame( array( 'en', 'es' ), $this->codes( $rows ) );
		$this->assertSame( 'https://example.test/?term=21', $rows[1]['url'] );
	}

	public function test_links_for_term_omits_sibling_with_broken_term_link(): void {
		// Sibling term meta points at a term id with no term row → get_term_link()
		// returns WP_Error → the row is dropped.
		$this->seed_term( 20, 'en', 'tg1' );
		Wpnt_Test_State::$term_meta[99][ Wpnt_Translation_Store::META_LANGUAGE ] = 'es';
		Wpnt_Test_State::$term_meta[99][ Wpnt_Translation_Store::META_GROUP ]    = 'tg1';
		// Term 99 intentionally absent from $terms.

		$rows = $this->frontend->links_for_term( 20, true );
		$this->assertSame( array( 'en' ), $this->codes( $rows ) );
	}

	public function test_links_for_term_empty_for_non_positive_id(): void {
		$this->assertSame( array(), $this->frontend->links_for_term( 0, true ) );
	}

	/* ------------------------------------------------------------------ *
	 * resolve_post_id (S6 context safety)
	 * ------------------------------------------------------------------ */

	public function test_resolve_post_id_prefers_explicit_block_context(): void {
		$block = new WP_Block( array( 'postId' => 42 ) );
		$this->assertSame( 42, $this->frontend->resolve_post_id( $block ) );
	}

	public function test_resolve_post_id_uses_singular_queried_object(): void {
		$post     = new WP_Post( array( 'ID' => 7, 'post_type' => 'post' ) );
		Wpnt_Test_State::$queried_object = $post;
		$this->assertSame( 7, $this->frontend->resolve_post_id() );
	}

	public function test_resolve_post_id_zero_when_not_singular(): void {
		// No queried object, no block context → never guesses a post.
		$this->assertSame( 0, $this->frontend->resolve_post_id() );
	}

	public function test_resolve_post_id_zero_on_term_archive(): void {
		// A term archive's queried object is a WP_Term, not a WP_Post: resolve_post_id
		// must NOT treat it as a current post (S6).
		Wpnt_Test_State::$queried_object = ( function () {
			$t           = new WP_Term();
			$t->term_id  = 5;
			$t->taxonomy = 'category';
			return $t;
		} )();
		Wpnt_Test_State::$archive_type = 'category';
		$this->assertSame( 0, $this->frontend->resolve_post_id() );
	}

	/* ------------------------------------------------------------------ *
	 * resolve_term_id (S6 context safety)
	 * ------------------------------------------------------------------ */

	public function test_resolve_term_id_on_category_archive(): void {
		$term           = new WP_Term();
		$term->term_id  = 5;
		$term->taxonomy = 'category';
		Wpnt_Test_State::$queried_object = $term;
		Wpnt_Test_State::$archive_type   = 'category';
		$this->assertSame( 5, $this->frontend->resolve_term_id() );
	}

	public function test_resolve_term_id_on_tag_archive(): void {
		$term           = new WP_Term();
		$term->term_id  = 8;
		$term->taxonomy = 'post_tag';
		Wpnt_Test_State::$queried_object = $term;
		Wpnt_Test_State::$archive_type   = 'post_tag';
		$this->assertSame( 8, $this->frontend->resolve_term_id() );
	}

	public function test_resolve_term_id_zero_when_not_a_term_archive(): void {
		// Singular view: not a category/tag archive → 0.
		Wpnt_Test_State::$queried_object = new WP_Post( array( 'ID' => 7 ) );
		$this->assertSame( 0, $this->frontend->resolve_term_id() );
	}

	public function test_resolve_term_id_zero_when_queried_object_wrong_taxonomy(): void {
		// is_category() true but the queried object is a non-category term → 0 (defensive).
		$term           = new WP_Term();
		$term->term_id  = 5;
		$term->taxonomy = 'product_cat';
		Wpnt_Test_State::$queried_object = $term;
		Wpnt_Test_State::$archive_type   = 'category';
		$this->assertSame( 0, $this->frontend->resolve_term_id() );
	}
}
