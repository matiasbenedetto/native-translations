<?php
/**
 * Unit tests for Wpait_Languages — the configured-language index used everywhere
 * (enabled list, labels, flags, prompt names). Driven from the settings option.
 *
 * @package WpAiTranslate\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class LanguagesTest extends TestCase {

	private Wpait_Languages $languages;

	protected function setUp(): void {
		Wpait_Test_State::reset();
		Wpait_Languages::flush_index();
		Wpait_Test_State::$options['wpait_settings'] = array(
			'languages' => array(
				array( 'code' => 'en', 'name' => 'English', 'native' => 'English', 'flag' => '🇺🇸', 'enabled' => true ),
				array( 'code' => 'es', 'name' => 'Spanish', 'native' => 'Español', 'flag' => '🇦🇷', 'enabled' => true ),
				array( 'code' => 'fr', 'name' => 'French', 'native' => 'Français', 'flag' => '', 'enabled' => false ),
			),
		);
		$this->languages = new Wpait_Languages();
	}

	public function test_enabled_excludes_disabled_languages(): void {
		$codes = wp_list_pluck( $this->languages->enabled(), 'code' );
		$this->assertSame( array( 'en', 'es' ), array_values( $codes ) );
	}

	public function test_configured_includes_disabled(): void {
		$codes = wp_list_pluck( $this->languages->configured(), 'code' );
		$this->assertContains( 'fr', $codes );
	}

	public function test_label_prefixes_the_flag_when_set(): void {
		$this->assertSame( '🇺🇸 English', $this->languages->label( 'en' ) );
	}

	public function test_label_without_flag_is_just_the_name(): void {
		$this->assertSame( 'French', $this->languages->label( 'fr' ) );
	}

	public function test_label_falls_back_to_code_for_unknown(): void {
		$this->assertSame( 'de', $this->languages->label( 'de' ) );
	}

	public function test_name_is_flag_free(): void {
		$this->assertSame( 'Spanish', $this->languages->name( 'es' ) );
		$this->assertSame( 'de', $this->languages->name( 'de' ) );
	}

	public function test_flag_returns_configured_or_empty(): void {
		$this->assertSame( '🇦🇷', $this->languages->flag( 'es' ) );
		$this->assertSame( '', $this->languages->flag( 'fr' ) );
		$this->assertSame( '', $this->languages->flag( 'de' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Flag rendering: ISO region code -> flagcdn SVG, emoji back-compat (#80).
	 * ------------------------------------------------------------------ */

	public function test_is_region_code(): void {
		$this->assertTrue( Wpait_Languages::is_region_code( 'es' ) );
		$this->assertTrue( Wpait_Languages::is_region_code( 'fr' ) );
		$this->assertFalse( Wpait_Languages::is_region_code( 'ES' ) );    // uppercase
		$this->assertFalse( Wpait_Languages::is_region_code( 'esp' ) );   // too long
		$this->assertFalse( Wpait_Languages::is_region_code( '🇪🇸' ) );    // emoji
		$this->assertFalse( Wpait_Languages::is_region_code( '' ) );
	}

	public function test_flag_html_region_code_renders_flagcdn_svg(): void {
		$html = Wpait_Languages::flag_html( 'es' );
		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'https://flagcdn.com/es.svg', $html );
		$this->assertStringContainsString( 'wpait-flag-img', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
	}

	public function test_flag_html_legacy_emoji_renders_text_span(): void {
		$html = Wpait_Languages::flag_html( '🇦🇷' );
		$this->assertStringNotContainsString( 'flagcdn.com', $html );
		$this->assertStringContainsString( 'wpait-flag-emoji', $html );
		$this->assertStringContainsString( '🇦🇷', $html );
	}

	public function test_flag_html_empty_is_blank(): void {
		$this->assertSame( '', Wpait_Languages::flag_html( '' ) );
	}

	public function test_label_and_label_html_with_region_code_flag(): void {
		Wpait_Languages::flush_index();
		Wpait_Test_State::$options['wpait_settings'] = array(
			'languages' => array(
				array( 'code' => 'es', 'name' => 'Spanish', 'native' => 'Español', 'flag' => 'es', 'enabled' => true ),
			),
		);
		$langs = new Wpait_Languages();
		// Plain-text label omits the region code (it isn't a readable text prefix).
		$this->assertSame( 'Spanish', $langs->label( 'es' ) );
		// HTML label renders the flag image before the name.
		$html = $langs->label_html( 'es' );
		$this->assertStringContainsString( 'https://flagcdn.com/es.svg', $html );
		$this->assertStringContainsString( 'Spanish', $html );
	}

	public function test_label_html_with_legacy_emoji_keeps_emoji(): void {
		// Fixture 'en' has the emoji flag; label_html keeps it (back-compat).
		$html = $this->languages->label_html( 'en' );
		$this->assertStringContainsString( '🇺🇸', $html );
		$this->assertStringContainsString( 'English', $html );
	}

	public function test_name_falls_back_to_code_when_name_blank(): void {
		Wpait_Languages::flush_index();
		Wpait_Test_State::$options['wpait_settings'] = array(
			'languages' => array( array( 'code' => 'pt', 'name' => '', 'enabled' => true ) ),
		);
		$this->assertSame( 'pt', $this->languages->name( 'pt' ) );
	}

	/* ------------------------------------------------------------------ *
	 * reconcile() — taxonomy terms kept in sync with the settings list (S4).
	 * These drive the in-memory term model: get_term_by reads it, wp_insert/
	 * update/delete_term mutate it, and language_has_content() probes it.
	 * ------------------------------------------------------------------ */

	/** Builds a reconcile row with the full sanitized shape. */
	private function lang( string $code, string $name, bool $enabled = true ): array {
		return array(
			'code'    => $code,
			'name'    => $name,
			'locale'  => '',
			'native'  => $name,
			'flag'    => '',
			'enabled' => $enabled,
		);
	}

	public function test_reconcile_adds_a_term_for_a_new_language(): void {
		$result = $this->languages->reconcile( array( $this->lang( 'es', 'Spanish' ) ), array() );

		$this->assertContains( 'es', $result['report']['added'] );
		$term = $this->languages->get_term_for_code( 'es' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'Spanish', $term->name );
	}

	public function test_reconcile_updates_a_renamed_existing_language(): void {
		// Pre-seed an existing 'es' term named "Spanish".
		Wpait_Test_State::$terms[500] = array(
			'term_id' => 500, 'taxonomy' => Wpait_Languages::TAXONOMY, 'name' => 'Spanish', 'slug' => 'es', 'count' => 0,
		);

		$result = $this->languages->reconcile( array( $this->lang( 'es', 'Castellano' ) ), array() );

		$this->assertContains( 'es', $result['report']['updated'] );
		$this->assertNotContains( 'es', $result['report']['added'] );
		$this->assertSame( 'Castellano', Wpait_Test_State::$terms[500]['name'] );
	}

	public function test_reconcile_reports_disabled_languages(): void {
		$result = $this->languages->reconcile( array( $this->lang( 'fr', 'French', false ) ), array() );
		$this->assertContains( 'fr', $result['report']['disabled'] );
	}

	public function test_reconcile_removes_a_language_with_no_content(): void {
		// 'de' existed before, has a term, but no content → deletable.
		Wpait_Test_State::$terms[600] = array(
			'term_id' => 600, 'taxonomy' => Wpait_Languages::TAXONOMY, 'name' => 'German', 'slug' => 'de', 'count' => 0,
		);

		$result = $this->languages->reconcile(
			array( $this->lang( 'en', 'English' ) ), // new list drops 'de'.
			array( $this->lang( 'de', 'German' ) )   // old list had it.
		);

		$this->assertContains( 'de', $result['report']['removed'] );
		// The term is gone and 'de' is NOT forced back into the list.
		$this->assertNull( $this->languages->get_term_for_code( 'de' ) );
		$this->assertNotContains( 'de', wp_list_pluck( $result['languages'], 'code' ) );
	}

	public function test_reconcile_blocks_deletion_of_a_language_with_content(): void {
		// 'de' has content (term count > 0) → cannot be removed; forced back disabled.
		Wpait_Test_State::$terms[700] = array(
			'term_id' => 700, 'taxonomy' => Wpait_Languages::TAXONOMY, 'name' => 'German', 'slug' => 'de', 'count' => 3,
		);

		$result = $this->languages->reconcile(
			array( $this->lang( 'en', 'English' ) ),
			array( $this->lang( 'de', 'German' ) )
		);

		$this->assertContains( 'de', $result['report']['blocked'] );
		$this->assertNotContains( 'de', $result['report']['removed'] );

		// 'de' is retained in the returned list, but forced disabled.
		$de = array_values( array_filter( $result['languages'], static fn( $l ) => 'de' === $l['code'] ) );
		$this->assertNotEmpty( $de );
		$this->assertFalse( $de[0]['enabled'] );
		// The term still exists (assignments preserved).
		$this->assertInstanceOf( WP_Term::class, $this->languages->get_term_for_code( 'de' ) );
	}

	/* ------------------------------------------------------------------ *
	 * language_has_content() — indexed probes (C1)
	 * ------------------------------------------------------------------ */

	public function test_language_has_content_true_when_term_count_positive(): void {
		Wpait_Test_State::$terms[800] = array(
			'term_id' => 800, 'taxonomy' => Wpait_Languages::TAXONOMY, 'name' => 'Spanish', 'slug' => 'es', 'count' => 5,
		);
		$this->assertTrue( $this->languages->language_has_content( 'es' ) );
	}

	public function test_language_has_content_true_when_a_tagged_term_uses_it(): void {
		// The language term itself has no posts (count 0)...
		Wpait_Test_State::$terms[810] = array(
			'term_id' => 810, 'taxonomy' => Wpait_Languages::TAXONOMY, 'name' => 'Spanish', 'slug' => 'es', 'count' => 0,
		);
		// ...but a category carries that language via term meta.
		Wpait_Test_State::$terms[811] = array( 'term_id' => 811, 'taxonomy' => 'category', 'name' => 'News' );
		Wpait_Test_State::$term_meta[811][ Wpait_Translation_Store::META_LANGUAGE ] = 'es';

		$this->assertTrue( $this->languages->language_has_content( 'es' ) );
	}

	public function test_language_has_content_false_when_unused(): void {
		Wpait_Test_State::$terms[820] = array(
			'term_id' => 820, 'taxonomy' => Wpait_Languages::TAXONOMY, 'name' => 'Spanish', 'slug' => 'es', 'count' => 0,
		);
		$this->assertFalse( $this->languages->language_has_content( 'es' ) );
	}

	public function test_language_has_content_false_for_unknown_code(): void {
		$this->assertFalse( $this->languages->language_has_content( 'zz' ) );
	}
}
