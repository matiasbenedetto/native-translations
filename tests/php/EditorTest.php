<?php
/**
 * Unit tests for Wpait_Editor's save-time persistence of the editor's deferred
 * language/original changes (#106).
 *
 * The block-editor sidebar stages its language choice in `_wpait_editor_language`
 * post meta and edits `_wpait_is_original` natively; the term form stages both in
 * hidden $_POST fields. These tests drive the two server handlers that consume
 * those staged values on save and route them through the real store.
 *
 * @package WpAiTranslate\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class EditorTest extends TestCase {

	private Wpait_Editor $editor;
	private Wpait_Translation_Store $store;

	protected function setUp(): void {
		Wpait_Test_State::reset();
		$_POST        = array();
		$this->store  = new Wpait_Translation_Store();
		$this->editor = new Wpait_Editor( new Wpait_Languages(), $this->store );
	}

	/* ------------------------------------------------------------------ *
	 * persist_post_editor_changes (block editor → rest_after_insert)
	 * ------------------------------------------------------------------ */

	public function test_post_save_applies_staged_language_and_consumes_it(): void {
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$object_terms[5][ Wpait_Languages::TAXONOMY ] = array( 'en' );
		Wpait_Test_State::$post_meta[5]['_wpait_editor_language'] = 'es';

		$this->editor->persist_post_editor_changes( get_post( 5 ) );

		$this->assertSame( 'es', $this->store->get_language( 'post', 5 ), 'Staged language is applied through the store.' );
		$this->assertSame( '', (string) get_post_meta( 5, '_wpait_editor_language', true ), 'Staging meta is consumed (deleted) after applying.' );
	}

	public function test_post_save_no_staged_language_leaves_language_untouched(): void {
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$object_terms[5][ Wpait_Languages::TAXONOMY ] = array( 'en' );

		$this->editor->persist_post_editor_changes( get_post( 5 ) );

		$this->assertSame( 'en', $this->store->get_language( 'post', 5 ) );
	}

	public function test_post_save_clears_original_flag_when_no_language(): void {
		// Core just persisted the original flag, but the post has no language: the
		// store rule (an original must have a language, #92) is reconciled at save.
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$post_meta[5]['_wpait_is_original'] = '1';

		$this->editor->persist_post_editor_changes( get_post( 5 ) );

		$this->assertFalse( $this->store->is_original( 'post', 5 ) );
	}

	public function test_post_save_keeps_original_flag_when_language_present(): void {
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$object_terms[5][ Wpait_Languages::TAXONOMY ] = array( 'en' );
		Wpait_Test_State::$post_meta[5]['_wpait_is_original'] = '1';

		$this->editor->persist_post_editor_changes( get_post( 5 ) );

		$this->assertTrue( $this->store->is_original( 'post', 5 ) );
	}

	public function test_post_save_staged_language_then_original_survives_reconcile(): void {
		// A post with no saved language stages a language AND is marked original in the
		// same save: language is applied first, so the original flag is kept.
		Wpait_Test_State::$posts[5] = array( 'ID' => 5, 'post_type' => 'post' );
		Wpait_Test_State::$post_meta[5]['_wpait_editor_language'] = 'es';
		Wpait_Test_State::$post_meta[5]['_wpait_is_original']     = '1';

		$this->editor->persist_post_editor_changes( get_post( 5 ) );

		$this->assertSame( 'es', $this->store->get_language( 'post', 5 ) );
		$this->assertTrue( $this->store->is_original( 'post', 5 ) );
	}

	/* ------------------------------------------------------------------ *
	 * persist_term_editor_changes (term form → edited_{taxonomy})
	 * ------------------------------------------------------------------ */

	public function test_term_save_applies_language_and_original_from_post_fields(): void {
		Wpait_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpait_Test_State::$caps['edit_term:7'] = true;
		$_POST['wpait_editor_language']    = 'es';
		$_POST['wpait_editor_is_original'] = '1';

		$this->editor->persist_term_editor_changes( 7 );

		$this->assertSame( 'es', $this->store->get_language( 'term', 7 ) );
		$this->assertTrue( $this->store->is_original( 'term', 7 ) );
	}

	public function test_term_save_unmarks_original_when_field_is_zero(): void {
		Wpait_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpait_Test_State::$term_meta[7]['_wpait_language']    = 'es';
		Wpait_Test_State::$term_meta[7]['_wpait_is_original'] = '1';
		Wpait_Test_State::$caps['edit_term:7'] = true;
		$_POST['wpait_editor_is_original'] = '0';

		$this->editor->persist_term_editor_changes( 7 );

		$this->assertFalse( $this->store->is_original( 'term', 7 ) );
	}

	public function test_term_save_does_nothing_without_edit_capability(): void {
		Wpait_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpait_Test_State::$caps['edit_term:7'] = false;
		$_POST['wpait_editor_language'] = 'es';

		$this->editor->persist_term_editor_changes( 7 );

		$this->assertSame( '', $this->store->get_language( 'term', 7 ), 'A user without edit_term must not mutate the term.' );
	}

	public function test_term_save_ignores_absent_fields(): void {
		Wpait_Test_State::$terms[7] = array( 'term_id' => 7, 'taxonomy' => 'category' );
		Wpait_Test_State::$term_meta[7]['_wpait_language'] = 'en';
		Wpait_Test_State::$caps['edit_term:7'] = true;
		// No $_POST fields set (e.g. a save from a screen without the panel).

		$this->editor->persist_term_editor_changes( 7 );

		$this->assertSame( 'en', $this->store->get_language( 'term', 7 ) );
	}
}
