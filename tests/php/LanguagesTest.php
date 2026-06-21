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

	public function test_name_falls_back_to_code_when_name_blank(): void {
		Wpait_Languages::flush_index();
		Wpait_Test_State::$options['wpait_settings'] = array(
			'languages' => array( array( 'code' => 'pt', 'name' => '', 'enabled' => true ) ),
		);
		$this->assertSame( 'pt', $this->languages->name( 'pt' ) );
	}
}
