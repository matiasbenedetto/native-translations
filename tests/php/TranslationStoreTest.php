<?php
/**
 * Unit tests for the REAL Wpnt_Translation_Store — the single writer for language
 * + group relationships and the one-member-per-language invariant (C2).
 *
 * The store is driven against the bootstrap's in-memory WP object model
 * (Wpnt_Test_State::$posts/$terms/$post_meta/$term_meta/$object_terms/$cache), so
 * groups and languages are modelled declaratively without a database. These tests
 * exercise the store's real logic — the invariants, the re-assignment guard, group
 * linking + sibling lookup, ensure_group idempotency, and cache busting.
 *
 * @package WpNativeTranslations\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class TranslationStoreTest extends TestCase {

	private Wpnt_Translation_Store $store;

	protected function setUp(): void {
		Wpnt_Test_State::reset();
		$this->store = new Wpnt_Translation_Store();
	}

	/* ------------------------------------------------------------------ *
	 * Helpers: seed the in-memory model.
	 * ------------------------------------------------------------------ */

	/** Seeds a post with a language (object term) and optional group meta. */
	private function seed_post( int $id, string $code, string $group = '', string $status = 'publish' ): void {
		Wpnt_Test_State::$posts[ $id ] = array( 'ID' => $id, 'post_type' => 'post', 'post_status' => $status );
		if ( '' !== $code ) {
			Wpnt_Test_State::$object_terms[ $id ][ Wpnt_Languages::TAXONOMY ] = array( $code );
		}
		if ( '' !== $group ) {
			Wpnt_Test_State::$post_meta[ $id ][ Wpnt_Translation_Store::META_GROUP ] = $group;
		}
	}

	/** Seeds a term with a language (term meta) and optional group meta. */
	private function seed_term( int $id, string $code, string $group = '' ): void {
		Wpnt_Test_State::$terms[ $id ] = array( 'term_id' => $id, 'taxonomy' => 'category', 'name' => "Term $id" );
		if ( '' !== $code ) {
			Wpnt_Test_State::$term_meta[ $id ][ Wpnt_Translation_Store::META_LANGUAGE ] = $code;
		}
		if ( '' !== $group ) {
			Wpnt_Test_State::$term_meta[ $id ][ Wpnt_Translation_Store::META_GROUP ] = $group;
		}
	}

	/* ------------------------------------------------------------------ *
	 * get_language / set_language basics
	 * ------------------------------------------------------------------ */

	public function test_get_language_reads_post_object_term_and_term_meta(): void {
		$this->seed_post( 10, 'en' );
		$this->seed_term( 20, 'es' );
		$this->assertSame( 'en', $this->store->get_language( 'post', 10 ) );
		$this->assertSame( 'es', $this->store->get_language( 'term', 20 ) );
	}

	public function test_get_language_empty_when_unassigned(): void {
		$this->seed_post( 10, '' );
		$this->assertSame( '', $this->store->get_language( 'post', 10 ) );
		$this->assertSame( '', $this->store->get_language( 'term', 999 ) );
	}

	public function test_set_language_rejects_blank_code(): void {
		$this->seed_post( 10, '' );
		$result = $this->store->set_language( 'post', 10, '' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_invalid_language', $result->get_error_code() );
	}

	public function test_set_language_assigns_post_and_term(): void {
		$this->seed_post( 10, '' );
		$this->seed_term( 20, '' );
		$this->assertTrue( $this->store->set_language( 'post', 10, 'fr' ) );
		$this->assertTrue( $this->store->set_language( 'term', 20, 'fr' ) );
		$this->assertSame( 'fr', $this->store->get_language( 'post', 10 ) );
		$this->assertSame( 'fr', $this->store->get_language( 'term', 20 ) );
	}

	public function test_set_language_sanitizes_code(): void {
		$this->seed_post( 10, '' );
		$this->store->set_language( 'post', 10, 'PT BR' );
		$this->assertSame( 'pt-br', $this->store->get_language( 'post', 10 ) );
	}

	/* ------------------------------------------------------------------ *
	 * Original flag (#92)
	 * ------------------------------------------------------------------ */

	public function test_is_original_false_by_default(): void {
		$this->seed_post( 10, 'en' );
		$this->seed_term( 20, 'es' );
		$this->assertFalse( $this->store->is_original( 'post', 10 ) );
		$this->assertFalse( $this->store->is_original( 'term', 20 ) );
	}

	public function test_set_original_marks_and_unmarks_post_and_term(): void {
		$this->seed_post( 10, 'en' );
		$this->seed_term( 20, 'es' );

		$this->assertTrue( $this->store->set_original( 'post', 10, true ) );
		$this->assertTrue( $this->store->set_original( 'term', 20, true ) );
		$this->assertTrue( $this->store->is_original( 'post', 10 ) );
		$this->assertTrue( $this->store->is_original( 'term', 20 ) );
		// Persisted as the '1' marker meta.
		$this->assertSame( '1', Wpnt_Test_State::$post_meta[10][ Wpnt_Translation_Store::META_IS_ORIGINAL ] );
		$this->assertSame( '1', Wpnt_Test_State::$term_meta[20][ Wpnt_Translation_Store::META_IS_ORIGINAL ] );

		$this->assertTrue( $this->store->set_original( 'post', 10, false ) );
		$this->assertTrue( $this->store->set_original( 'term', 20, false ) );
		$this->assertFalse( $this->store->is_original( 'post', 10 ) );
		$this->assertFalse( $this->store->is_original( 'term', 20 ) );
		$this->assertArrayNotHasKey( Wpnt_Translation_Store::META_IS_ORIGINAL, Wpnt_Test_State::$post_meta[10] ?? array() );
		$this->assertArrayNotHasKey( Wpnt_Translation_Store::META_IS_ORIGINAL, Wpnt_Test_State::$term_meta[20] ?? array() );
	}

	public function test_set_original_rejects_marking_an_item_with_no_language(): void {
		// An item without a language cannot be a translation original (#92).
		$this->seed_post( 10, '' );
		$result = $this->store->set_original( 'post', 10, true );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_no_language', $result->get_error_code() );
		$this->assertFalse( $this->store->is_original( 'post', 10 ) );
	}

	public function test_set_original_unmark_allowed_even_without_language(): void {
		// Unmarking is always safe, language or not.
		$this->seed_post( 10, '' );
		$this->assertTrue( $this->store->set_original( 'post', 10, false ) );
	}

	/* ------------------------------------------------------------------ *
	 * clear_language (#103)
	 * ------------------------------------------------------------------ */

	public function test_clear_language_removes_post_language_and_original_flag(): void {
		$this->seed_post( 10, 'en' );
		$this->store->set_original( 'post', 10, true );
		$this->assertSame( 'en', $this->store->get_language( 'post', 10 ) );

		$this->assertTrue( $this->store->clear_language( 'post', 10 ) );
		$this->assertSame( '', $this->store->get_language( 'post', 10 ) );
		$this->assertFalse( $this->store->is_original( 'post', 10 ), 'Clearing the language must clear the original flag.' );
	}

	public function test_clear_language_removes_term_language_and_original_flag(): void {
		$this->seed_term( 20, 'es' );
		$this->store->set_original( 'term', 20, true );

		$this->assertTrue( $this->store->clear_language( 'term', 20 ) );
		$this->assertSame( '', $this->store->get_language( 'term', 20 ) );
		$this->assertFalse( $this->store->is_original( 'term', 20 ) );
	}

	public function test_clear_language_unlinks_from_group_leaving_siblings_intact(): void {
		// A group of two: clearing one member must remove it from the group while the
		// sibling keeps its language slot.
		$this->seed_post( 10, 'en', 'grp-1' );
		$this->seed_post( 11, 'es', 'grp-1' );

		$this->assertTrue( $this->store->clear_language( 'post', 10 ) );

		$this->assertSame( '', $this->store->get_group( 'post', 10 ), 'Cleared member must leave its group.' );
		// The sibling is untouched: still grouped, still es.
		$this->assertSame( 'grp-1', $this->store->get_group( 'post', 11 ) );
		$this->assertSame( 'es', $this->store->get_language( 'post', 11 ) );
		// And it is reachable as the sole remaining member of the group.
		$translations = $this->store->get_translations( 'post', 11, array( 'include_self' => true ) );
		$this->assertSame( array( 'es' => 11 ), $translations );
	}

	/* ------------------------------------------------------------------ *
	 * One-member-per-language invariant (C2)
	 * ------------------------------------------------------------------ */

	public function test_set_language_rejects_duplicate_language_in_group(): void {
		// Group g1 already has an English post (10); reassigning post 11 (Spanish, same
		// group) to English would collapse two members onto one language key.
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );

		$result = $this->store->set_language( 'post', 11, 'en' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_language_exists', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		// The Spanish assignment is untouched.
		$this->assertSame( 'es', $this->store->get_language( 'post', 11 ) );
	}

	public function test_set_language_to_same_code_is_idempotent_even_when_grouped(): void {
		// Re-asserting the member's own existing language is allowed (it owns that slot).
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );
		$this->assertTrue( $this->store->set_language( 'post', 11, 'es' ) );
	}

	public function test_link_translation_rejects_duplicate_language(): void {
		$this->seed_post( 10, 'en', 'g1' ); // source, already grouped.
		$this->seed_post( 11, 'es', 'g1' ); // sibling holds Spanish.
		$this->seed_post( 12, 'es' );       // candidate, also Spanish, ungrouped.

		$result = $this->store->link_translation( 'post', 10, 12 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_language_exists', $result->get_error_code() );
		// Candidate was NOT pulled into the group.
		$this->assertSame( '', $this->store->get_group( 'post', 12 ) );
	}

	public function test_link_translation_rejects_translation_with_no_language(): void {
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 12, '' ); // no language assigned.
		$result = $this->store->link_translation( 'post', 10, 12 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_no_language', $result->get_error_code() );
	}

	/* ------------------------------------------------------------------ *
	 * Re-assignment guard (relabel only a lone / ungrouped member)
	 * ------------------------------------------------------------------ */

	public function test_set_language_refuses_relabel_of_grouped_member_with_siblings(): void {
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' ); // sibling exists, so relabelling 10 would orphan English.

		$result = $this->store->set_language( 'post', 10, 'de' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpnt_language_reassign', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'en', $this->store->get_language( 'post', 10 ) );
	}

	public function test_set_language_allows_relabel_of_lone_group_member(): void {
		// A group of one has no slot to orphan, so relabelling is allowed.
		$this->seed_post( 10, 'en', 'g1' );
		$this->assertTrue( $this->store->set_language( 'post', 10, 'de' ) );
		$this->assertSame( 'de', $this->store->get_language( 'post', 10 ) );
	}

	public function test_set_language_allows_relabel_of_ungrouped_object(): void {
		$this->seed_post( 10, 'en' ); // no group at all.
		$this->assertTrue( $this->store->set_language( 'post', 10, 'de' ) );
		$this->assertSame( 'de', $this->store->get_language( 'post', 10 ) );
	}

	/* ------------------------------------------------------------------ *
	 * ensure_group / get_group
	 * ------------------------------------------------------------------ */

	public function test_ensure_group_creates_a_group_for_an_ungrouped_object(): void {
		$this->seed_post( 10, 'en' );
		$this->assertSame( '', $this->store->get_group( 'post', 10 ) );
		$group = $this->store->ensure_group( 'post', 10 );
		$this->assertNotSame( '', $group );
		$this->assertSame( $group, $this->store->get_group( 'post', 10 ) );
	}

	public function test_ensure_group_is_idempotent(): void {
		$this->seed_post( 10, 'en' );
		$first  = $this->store->ensure_group( 'post', 10 );
		$second = $this->store->ensure_group( 'post', 10 );
		$this->assertSame( $first, $second, 'ensure_group must not mint a new id for an already-grouped object.' );
	}

	/* ------------------------------------------------------------------ *
	 * Group linking + sibling lookup (get_translations filters)
	 * ------------------------------------------------------------------ */

	public function test_link_translation_groups_the_objects(): void {
		$this->seed_post( 10, 'en' ); // source, ungrouped.
		$this->seed_post( 11, 'es' ); // candidate.

		$this->assertTrue( $this->store->link_translation( 'post', 10, 11 ) );

		$group = $this->store->get_group( 'post', 10 );
		$this->assertNotSame( '', $group );
		$this->assertSame( $group, $this->store->get_group( 'post', 11 ) );

		$siblings = $this->store->get_translations( 'post', 10 );
		$this->assertSame( array( 'es' => 11 ), $siblings );
	}

	public function test_get_translations_returns_empty_for_ungrouped_object(): void {
		$this->seed_post( 10, 'en' );
		$this->assertSame( array(), $this->store->get_translations( 'post', 10 ) );
	}

	public function test_get_translations_excludes_self_by_default_and_includes_with_flag(): void {
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );

		$this->assertSame( array( 'es' => 11 ), $this->store->get_translations( 'post', 10 ) );
		$this->assertSame(
			array( 'en' => 10, 'es' => 11 ),
			$this->store->get_translations( 'post', 10, array( 'include_self' => true ) )
		);
	}

	public function test_get_translations_viewable_filters_non_public_siblings(): void {
		$this->seed_post( 10, 'en', 'g1', 'publish' );
		$this->seed_post( 11, 'es', 'g1', 'draft' );
		Wpnt_Test_State::$not_viewable[11] = true; // draft sibling not publicly viewable.

		$this->assertArrayHasKey( 'es', $this->store->get_translations( 'post', 10 ) );
		$this->assertArrayNotHasKey(
			'es',
			$this->store->get_translations( 'post', 10, array( 'viewable' => true ) )
		);
	}

	public function test_get_translations_status_filter(): void {
		$this->seed_post( 10, 'en', 'g1', 'publish' );
		$this->seed_post( 11, 'es', 'g1', 'draft' );

		$published = $this->store->get_translations( 'post', 10, array( 'include_self' => true, 'status' => 'publish' ) );
		$this->assertSame( array( 'en' => 10 ), $published );
	}

	public function test_get_translations_skips_a_missing_post_member(): void {
		// Group meta references a post id that no longer exists (get_post → null).
		$this->seed_post( 10, 'en', 'g1' );
		Wpnt_Test_State::$post_meta[99][ Wpnt_Translation_Store::META_GROUP ] = 'g1';
		Wpnt_Test_State::$object_terms[99][ Wpnt_Languages::TAXONOMY ]        = array( 'es' );
		// Post 99 is intentionally absent from $posts.

		$siblings = $this->store->get_translations( 'post', 10 );
		$this->assertArrayNotHasKey( 'es', $siblings );
	}

	public function test_term_group_membership_via_term_meta(): void {
		$this->seed_term( 20, 'en', 'tg1' );
		$this->seed_term( 21, 'es', 'tg1' );
		$this->assertSame( array( 'es' => 21 ), $this->store->get_translations( 'term', 20 ) );
	}

	/* ------------------------------------------------------------------ *
	 * unlink_translation
	 * ------------------------------------------------------------------ */

	public function test_unlink_translation_removes_group_meta(): void {
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );

		$this->store->unlink_translation( 'post', 11 );
		$this->assertSame( '', $this->store->get_group( 'post', 11 ) );
		// The source no longer sees the unlinked member.
		$this->assertSame( array(), $this->store->get_translations( 'post', 10 ) );
	}

	/* ------------------------------------------------------------------ *
	 * Caching (C1) + cache busting
	 * ------------------------------------------------------------------ */

	public function test_group_members_are_cached_and_busted_on_write(): void {
		$this->seed_post( 10, 'en', 'g1' );
		$this->seed_post( 11, 'es', 'g1' );

		// Prime the cache.
		$this->assertSame( array( 'es' => 11 ), $this->store->get_translations( 'post', 10 ) );

		// Mutate the underlying data WITHOUT going through the store: a stale cache hit
		// would still report only Spanish.
		$this->seed_post( 12, 'fr', 'g1' );
		$this->assertSame(
			array( 'es' => 11 ),
			$this->store->get_translations( 'post', 10 ),
			'A primed group membership map must be served from cache.'
		);

		// A store write busts the cache, so the next read reflects the new member.
		$this->store->bust_group_cache( 'post', 'g1' );
		$this->assertSame(
			array( 'es' => 11, 'fr' => 12 ),
			$this->store->get_translations( 'post', 10 )
		);
	}

	public function test_link_translation_busts_cache_so_new_sibling_is_visible(): void {
		$this->seed_post( 10, 'en', 'g1' );
		$this->store->get_translations( 'post', 10 ); // prime (empty).
		$this->seed_post( 11, 'es' );
		$this->store->link_translation( 'post', 10, 11 );
		$this->assertSame( array( 'es' => 11 ), $this->store->get_translations( 'post', 10 ) );
	}

	public function test_bust_group_cache_noop_for_empty_group(): void {
		// Must not throw or touch cache when there is no group.
		$this->store->bust_group_cache( 'post', '' );
		$this->assertSame( array(), Wpnt_Test_State::$cache );
	}

	/* ------------------------------------------------------------------ *
	 * Object-type guard (S5)
	 * ------------------------------------------------------------------ */

	public function test_invalid_object_type_triggers_doing_it_wrong(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid object type/' );
		$this->store->get_language( 'widget', 1 );
	}
}
