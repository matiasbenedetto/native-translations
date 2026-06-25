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
			$this->assertMatchesRegularExpression( '/^[a-z]{2,3}(-[a-z]{2,3})?$/', $code, "code '$code' should be a lowercase code" );
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

	public function test_locale_catalog_contains_regional_variants_with_derived_codes(): void {
		$entry = Wpait_Admin_Settings::catalog_entry_for_locale( 'es_AR' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'es-ar', $entry['code'] );
		$this->assertSame( 'Spanish / Argentina', $entry['name'] );
		$this->assertSame( 'Español (Argentina)', $entry['native'] );
		$this->assertSame( 'ar', $entry['flag'] );

		$this->assertContains( 'zh_HK', Wpait_Admin_Settings::common_locales() );
		$this->assertSame( 'pt-br', Wpait_Admin_Settings::code_for_locale( 'pt_BR' ) );
		$this->assertSame( 'ja', Wpait_Admin_Settings::code_for_locale( 'ja' ) );
	}

	public function test_locale_catalog_covers_the_full_bundled_list(): void {
		$catalog = Wpait_Admin_Settings::locale_catalog();
		// The bundled catalog is the full SimpleLocalize-derived locale set, so it
		// should be broad — far beyond the original ~40 hand-curated entries — and
		// include locales that were not previously offered.
		$this->assertGreaterThan( 300, count( $catalog ), 'expected the expanded locale list' );
		foreach ( array( 'af_ZA', 'sw_KE', 'is_IS', 'vi_VN', 'th_TH' ) as $locale ) {
			$this->assertArrayHasKey( $locale, $catalog, "locale '$locale' should be in the expanded catalog" );
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

	public function test_sanitize_derives_new_language_from_catalog_locale(): void {
		$out = $this->sanitizer()->sanitize(
			array(
				'languages' => array(
					array(
						'locale'  => 'es_AR',
						'code'    => 'malicious',
						'name'    => 'Wrong',
						'native'  => 'Wrong native',
						'flag'    => 'xx',
						'enabled' => '',
					),
				),
			)
		);

		$this->assertCount( 1, $out['languages'] );
		$lang = $out['languages'][0];
		$this->assertSame( 'es-ar', $lang['code'] );
		$this->assertSame( 'es_AR', $lang['locale'] );
		$this->assertSame( 'Spanish / Argentina', $lang['name'] );
		$this->assertSame( 'Español (Argentina)', $lang['native'] );
		$this->assertSame( 'ar', $lang['flag'] );
		$this->assertTrue( $lang['enabled'] );
	}

	public function test_sanitize_preserves_existing_language_metadata_by_code(): void {
		Wpait_Test_State::$options['wpait_settings'] = array(
			'languages' => array(
				array(
					'code'    => 'es',
					'locale'  => 'es_AR',
					'name'    => 'Spanish',
					'native'  => 'Español (Argentina)',
					'flag'    => 'ar',
					'enabled' => true,
				),
			),
		);

		$out = $this->sanitizer()->sanitize(
			array(
				'languages' => array(
					array(
						'code'   => 'es',
						'locale' => 'es_MX',
						'name'   => 'Wrong',
						'native' => 'Wrong native',
						'flag'   => 'mx',
					),
				),
			)
		);

		$lang = $out['languages'][0];
		$this->assertSame( 'es', $lang['code'] );
		$this->assertSame( 'es_AR', $lang['locale'] );
		$this->assertSame( 'Spanish', $lang['name'] );
		$this->assertSame( 'Español (Argentina)', $lang['native'] );
		$this->assertSame( 'ar', $lang['flag'] );
		$this->assertFalse( $lang['enabled'] );
	}

	public function test_sanitize_skips_duplicate_catalog_locales(): void {
		$out = $this->sanitizer()->sanitize(
			array(
				'languages' => array(
					array( 'locale' => 'es_AR' ),
					array( 'locale' => 'es_AR' ),
				),
			)
		);

		$this->assertSame( array( 'es-ar' ), wp_list_pluck( $out['languages'], 'code' ) );
	}

	public function test_sanitize_keeps_legacy_custom_language_rows(): void {
		$out = $this->sanitizer()->sanitize(
			array(
				'languages' => array(
					array(
						'code'    => 'xx',
						'locale'  => 'xx_YY',
						'name'    => 'Custom',
						'native'  => 'Custom native',
						'flag'    => 'zz',
						'enabled' => '1',
					),
				),
			)
		);

		$lang = $out['languages'][0];
		$this->assertSame( 'xx', $lang['code'] );
		$this->assertSame( 'xx_YY', $lang['locale'] );
		$this->assertSame( 'Custom', $lang['name'] );
		$this->assertSame( 'Custom native', $lang['native'] );
		$this->assertSame( 'zz', $lang['flag'] );
		$this->assertTrue( $lang['enabled'] );
	}
}
