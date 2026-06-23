<?php
/**
 * Unit tests for Wpait_Admin_Settings — settings shape, locale validation (#19),
 * and the sanitize() pipeline's language + model handling.
 *
 * @package WpAiTranslate\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase {

	protected function setUp(): void {
		Wpait_Test_State::reset();
	}

	/* ------------------------------------------------------------------ *
	 * Locale validation (#19)
	 * ------------------------------------------------------------------ */

	public function test_accepts_valid_locales(): void {
		foreach ( array( '', 'en', 'en_US', 'es_AR', 'pt_BR', 'fil' ) as $locale ) {
			$this->assertTrue( Wpait_Admin_Settings::is_valid_locale( $locale ), "$locale should be valid" );
		}
	}

	public function test_rejects_malformed_locales(): void {
		foreach ( array( 'not-a-locale', 'es-AR', 'EN', 'english', 'e', 'es_ar' ) as $locale ) {
			$this->assertFalse( Wpait_Admin_Settings::is_valid_locale( $locale ), "$locale should be invalid" );
		}
	}

	public function test_common_locales_are_well_formed_suggestions(): void {
		$locales = Wpait_Admin_Settings::common_locales();
		$this->assertNotEmpty( $locales );
		foreach ( $locales as $loc ) {
			$this->assertTrue( Wpait_Admin_Settings::is_valid_locale( $loc ), "$loc should be a valid locale" );
		}
		$this->assertContains( 'es_AR', $locales );
	}

	/* ------------------------------------------------------------------ *
	 * Language defaults catalog (#74)
	 * ------------------------------------------------------------------ */

	public function test_default_catalog_entries_are_well_formed(): void {
		$catalog = Wpait_Admin_Settings::default_catalog();
		$this->assertNotEmpty( $catalog );
		foreach ( $catalog as $code => $entry ) {
			$this->assertMatchesRegularExpression( '/^[a-z]{2,3}$/', $code, "code '$code' should be a short lowercase code" );
			foreach ( array( 'locale', 'name', 'native', 'flag' ) as $field ) {
				$this->assertArrayHasKey( $field, $entry, "code '$code' missing '$field'" );
				$this->assertNotSame( '', $entry[ $field ], "code '$code' has empty '$field'" );
			}
			// Flags are ISO 3166-1 alpha-2 region codes (rendered as flag icons, #80).
			$this->assertMatchesRegularExpression( '/^[a-z]{2}$/', $entry['flag'], "code '$code' flag should be a region code" );
			$this->assertTrue(
				Wpait_Admin_Settings::is_valid_locale( $entry['locale'] ),
				"catalog locale '{$entry['locale']}' (code '$code') should be valid"
			);
		}
	}

	public function test_common_locales_are_derived_from_catalog(): void {
		$locales = Wpait_Admin_Settings::common_locales();
		foreach ( Wpait_Admin_Settings::default_catalog() as $entry ) {
			$this->assertContains(
				$entry['locale'],
				$locales,
				"catalog locale '{$entry['locale']}' should be offered as a suggestion"
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 * get_settings() shape
	 * ------------------------------------------------------------------ */

	public function test_get_settings_returns_defaults_when_unset(): void {
		$settings = Wpait_Admin_Settings::get_settings();
		$this->assertSame( array(), $settings['languages'] );
		$this->assertSame( '', $settings['default_language'] );
		$this->assertSame( '', $settings['model'] );
		$this->assertArrayHasKey( 'per_language', $settings['instructions'] );
	}

	public function test_get_settings_merges_stored_over_defaults(): void {
		Wpait_Test_State::$options['wpait_settings'] = array( 'model' => 'z-ai/glm-5.2' );
		$settings = Wpait_Admin_Settings::get_settings();
		$this->assertSame( 'z-ai/glm-5.2', $settings['model'] );
		// Instruction defaults still present even though only model was stored.
		$this->assertArrayHasKey( 'global', $settings['instructions'] );
	}

	/* ------------------------------------------------------------------ *
	 * sanitize() — language + model handling
	 * ------------------------------------------------------------------ */

	private function sanitizer(): Wpait_Admin_Settings {
		Wpait_Languages::flush_index();
		return new Wpait_Admin_Settings( new Wpait_Languages() );
	}

	public function test_sanitize_drops_blank_code_rows_and_defaults_name_to_code(): void {
		$out = $this->sanitizer()->sanitize(
			array(
				'languages' => array(
					array( 'code' => 'en', 'name' => 'English' ),
					array( 'code' => '', 'name' => 'ignored' ),
					array( 'code' => 'es', 'name' => '' ),
				),
			)
		);
		$codes = wp_list_pluck( $out['languages'], 'code' );
		$this->assertSame( array( 'en', 'es' ), array_values( $codes ) );
		$es = array_values( array_filter( $out['languages'], static fn( $l ) => 'es' === $l['code'] ) )[0];
		$this->assertSame( 'es', $es['name'], 'blank name falls back to the code' );
	}

	public function test_sanitize_keeps_model_and_clamps_flag_length(): void {
		$out = $this->sanitizer()->sanitize(
			array(
				'model'     => 'z-ai/glm-5.2',
				'languages' => array( array( 'code' => 'en', 'name' => 'English', 'flag' => str_repeat( 'x', 50 ) ) ),
			)
		);
		$this->assertSame( 'z-ai/glm-5.2', $out['model'] );
		$flag = $out['languages'][0]['flag'];
		$this->assertLessThanOrEqual( 12, mb_strlen( $flag ), 'flag is capped' );
	}

	public function test_sanitize_default_language_must_be_a_configured_code(): void {
		$out = $this->sanitizer()->sanitize(
			array(
				'default_language' => 'de',
				'languages'        => array( array( 'code' => 'en', 'name' => 'English' ) ),
			)
		);
		// 'de' isn't configured, so it falls back to the first configured code.
		$this->assertSame( 'en', $out['default_language'] );
	}
}
