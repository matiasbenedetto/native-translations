<?php
/**
 * Unit tests for Wpnt_Admin_Settings — settings shape, locale validation (#19),
 * and the sanitize() pipeline's language + model handling.
 *
 * @package WpNativeTranslations\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase {

	protected function setUp(): void {
		Wpnt_Test_State::reset();
	}

	/* ------------------------------------------------------------------ *
	 * Locale validation (#19)
	 * ------------------------------------------------------------------ */

	public function test_accepts_valid_locales(): void {
		foreach ( array( '', 'en', 'en_US', 'es_AR', 'pt_BR', 'fil' ) as $locale ) {
			$this->assertTrue( Wpnt_Admin_Settings::is_valid_locale( $locale ), "$locale should be valid" );
		}
	}

	public function test_rejects_malformed_locales(): void {
		foreach ( array( 'not-a-locale', 'es-AR', 'EN', 'english', 'e', 'es_ar' ) as $locale ) {
			$this->assertFalse( Wpnt_Admin_Settings::is_valid_locale( $locale ), "$locale should be invalid" );
		}
	}

	public function test_common_locales_are_well_formed_suggestions(): void {
		$locales = Wpnt_Admin_Settings::common_locales();
		$this->assertNotEmpty( $locales );
		foreach ( $locales as $loc ) {
			$this->assertTrue( Wpnt_Admin_Settings::is_valid_locale( $loc ), "$loc should be a valid locale" );
		}
		$this->assertContains( 'es_AR', $locales );
	}

	/* ------------------------------------------------------------------ *
	 * Language defaults catalog (#74)
	 * ------------------------------------------------------------------ */

	public function test_default_catalog_entries_are_well_formed(): void {
		$catalog = Wpnt_Admin_Settings::default_catalog();
		$this->assertNotEmpty( $catalog );
		foreach ( $catalog as $code => $entry ) {
			// Codes are derived from the locale: `es-ar`, and variant locales like
			// `de_DE_formal` -> `de-de-formal`.
			$this->assertMatchesRegularExpression( '/^[a-z]{2,3}(-[a-z0-9]+)*$/', $code, "code '$code' should be a lowercase code" );
			foreach ( array( 'locale', 'name', 'native' ) as $field ) {
				$this->assertArrayHasKey( $field, $entry, "code '$code' missing '$field'" );
				$this->assertNotSame( '', $entry[ $field ], "code '$code' has empty '$field'" );
			}
			// Flags are a two-letter region code, an optional "gb-xxx" subdivision
			// (e.g. gb-sct for Scottish Gaelic), or blank for the few languages with
			// no conventional home region (#88).
			$this->assertMatchesRegularExpression( '/^([a-z]{2}(-[a-z]{3})?)?$/', $entry['flag'], "code '$code' flag should be a region code or blank" );
			$this->assertTrue(
				Wpnt_Admin_Settings::is_valid_locale( $entry['locale'] ),
				"catalog locale '{$entry['locale']}' (code '$code') should be valid"
			);
		}
	}

	public function test_locale_catalog_contains_regional_variants_with_derived_codes(): void {
		$entry = Wpnt_Admin_Settings::catalog_entry_for_locale( 'es_AR' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'es-ar', $entry['code'] );
		$this->assertSame( 'Spanish (Argentina)', $entry['name'] );
		$this->assertSame( 'Español de Argentina', $entry['native'] );
		$this->assertSame( 'ar', $entry['flag'] );

		// Language-only locales carry no region, but most are back-filled with the
		// flag of their conventional home country so the picker still shows one
		// (#88): Japanese -> Japan.
		$ja = Wpnt_Admin_Settings::catalog_entry_for_locale( 'ja' );
		$this->assertNotNull( $ja );
		$this->assertSame( 'ja', $ja['code'] );
		$this->assertSame( 'jp', $ja['flag'] );

		// Languages with no single home region (Arabic, Esperanto, ...) are
		// intentionally left flagless.
		$ar = Wpnt_Admin_Settings::catalog_entry_for_locale( 'ar' );
		$this->assertNotNull( $ar );
		$this->assertSame( 'ar', $ar['code'] );
		$this->assertSame( '', $ar['flag'] );

		$this->assertContains( 'zh_HK', Wpnt_Admin_Settings::common_locales() );
		$this->assertSame( 'pt-br', Wpnt_Admin_Settings::code_for_locale( 'pt_BR' ) );
		$this->assertSame( 'de-de-formal', Wpnt_Admin_Settings::code_for_locale( 'de_DE_formal' ) );
	}

	public function test_locale_catalog_is_the_wordpress_locale_set(): void {
		$catalog = Wpnt_Admin_Settings::locale_catalog();
		// The catalog is the set of locales WordPress core is translated into.
		$this->assertGreaterThan( 100, count( $catalog ), 'expected the full WordPress locale list' );
		// Locales WordPress ships that the previous (country-primary) list lacked.
		foreach ( array( 'ca', 'eu', 'ja', 'pt_BR', 'zh_HK', 'de_DE_formal' ) as $locale ) {
			$this->assertArrayHasKey( $locale, $catalog, "locale '$locale' should be in the catalog" );
		}
	}

	public function test_locale_catalog_filter_lets_extenders_add_locales(): void {
		// An extender returns a locale WordPress doesn't ship, supplying only labels;
		// the catalog normalizes it (derives the code, lower-cases the flag).
		Wpnt_Test_State::$filters['wpnt_locale_catalog'] = array(
			'gl_ES' => array(
				'name'   => 'Galician',
				'native' => 'Galego',
				'flag'   => 'ES', // upper-case on purpose: normalized to 'es'.
			),
		);

		$entry = Wpnt_Admin_Settings::catalog_entry_for_locale( 'gl_ES' );
		$this->assertNotNull( $entry, 'filter-added locale should be in the catalog' );
		$this->assertSame( 'gl_ES', $entry['locale'] );
		$this->assertSame( 'gl-es', $entry['code'] );
		$this->assertSame( 'Galician', $entry['name'] );
		$this->assertSame( 'Galego', $entry['native'] );
		$this->assertSame( 'es', $entry['flag'] );
	}

	public function test_common_locales_are_derived_from_catalog(): void {
		$locales = Wpnt_Admin_Settings::common_locales();
		foreach ( Wpnt_Admin_Settings::default_catalog() as $entry ) {
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
		$settings = Wpnt_Admin_Settings::get_settings();
		$this->assertSame( array(), $settings['languages'] );
		$this->assertSame( '', $settings['default_language'] );
		$this->assertSame( '', $settings['model'] );
		$this->assertArrayHasKey( 'per_language', $settings['instructions'] );
	}

	public function test_get_settings_merges_stored_over_defaults(): void {
		Wpnt_Test_State::$options['wpnt_settings'] = array( 'model' => 'z-ai/glm-5.2' );
		$settings = Wpnt_Admin_Settings::get_settings();
		$this->assertSame( 'z-ai/glm-5.2', $settings['model'] );
		// Instruction defaults still present even though only model was stored.
		$this->assertArrayHasKey( 'global', $settings['instructions'] );
	}

	/* ------------------------------------------------------------------ *
	 * sanitize() — language + model handling
	 * ------------------------------------------------------------------ */

	private function sanitizer(): Wpnt_Admin_Settings {
		Wpnt_Languages::flush_index();
		return new Wpnt_Admin_Settings( new Wpnt_Languages() );
	}

	/** Seeds a complete settings option for partial-save merge tests. */
	private function seed_full_settings(): array {
		$settings = array(
			'languages'        => array(
				array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'flag' => 'us', 'enabled' => true ),
				array( 'code' => 'es', 'locale' => 'es_ES', 'name' => 'Spanish', 'native' => 'Español', 'flag' => 'es', 'enabled' => true ),
			),
			'default_language' => 'es',
			'model'            => 'openai/gpt-5-mini',
			'instructions'     => array(
				'global'       => 'Global guidance',
				'post'         => 'Post guidance',
				'term'         => 'Term guidance',
				'per_language' => array(
					'es' => 'Spanish guidance',
				),
			),
		);
		Wpnt_Test_State::$options['wpnt_settings'] = $settings;
		Wpnt_Languages::flush_index();
		return $settings;
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
		$this->assertSame( 'Spanish (Argentina)', $lang['name'] );
		$this->assertSame( 'Español de Argentina', $lang['native'] );
		$this->assertSame( 'ar', $lang['flag'] );
		$this->assertTrue( $lang['enabled'] );
	}

	public function test_sanitize_preserves_existing_language_metadata_by_code(): void {
		Wpnt_Test_State::$options['wpnt_settings'] = array(
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

	public function test_model_section_save_preserves_other_settings(): void {
		$old = $this->seed_full_settings();

		$out = $this->sanitizer()->sanitize(
			array(
				'_section' => 'model',
				'model'    => 'anthropic/claude-opus-4.8',
			)
		);

		$this->assertSame( 'anthropic/claude-opus-4.8', $out['model'] );
		$this->assertSame( $old['languages'], $out['languages'] );
		$this->assertSame( $old['default_language'], $out['default_language'] );
		$this->assertSame( $old['instructions'], $out['instructions'] );
	}

	public function test_instructions_section_save_preserves_other_settings(): void {
		$old = $this->seed_full_settings();

		$out = $this->sanitizer()->sanitize(
			array(
				'_section'     => 'instructions',
				'instructions' => array(
					'global'       => 'Updated global',
					'post'         => 'Updated posts',
					'term'         => 'Updated terms',
					'per_language' => array(
						'es' => 'Updated Spanish',
					),
				),
			)
		);

		$this->assertSame( $old['languages'], $out['languages'] );
		$this->assertSame( $old['default_language'], $out['default_language'] );
		$this->assertSame( $old['model'], $out['model'] );
		$this->assertSame( 'Updated global', $out['instructions']['global'] );
		$this->assertSame( 'Updated posts', $out['instructions']['post'] );
		$this->assertSame( 'Updated terms', $out['instructions']['term'] );
		$this->assertSame( array( 'es' => 'Updated Spanish' ), $out['instructions']['per_language'] );
	}

	public function test_default_language_section_save_preserves_other_settings(): void {
		$old = $this->seed_full_settings();

		$out = $this->sanitizer()->sanitize(
			array(
				'_section'         => 'default_language',
				'default_language' => 'en',
			)
		);

		$this->assertSame( 'en', $out['default_language'] );
		$this->assertSame( $old['languages'], $out['languages'] );
		$this->assertSame( $old['model'], $out['model'] );
		$this->assertSame( $old['instructions'], $out['instructions'] );
	}

	public function test_languages_section_save_preserves_model_and_instructions(): void {
		$old = $this->seed_full_settings();

		$out = $this->sanitizer()->sanitize(
			array(
				'_section' => 'languages',
				'languages' => array(
					array( 'code' => 'en', 'enabled' => '1' ),
				),
			)
		);

		$this->assertSame( array( 'en' ), wp_list_pluck( $out['languages'], 'code' ) );
		$this->assertSame( 'en', $out['default_language'], 'default falls back when the old default language is removed' );
		$this->assertSame( $old['model'], $out['model'] );
		$this->assertSame( $old['instructions'], $out['instructions'] );
	}
}
