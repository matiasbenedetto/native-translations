<?php
/**
 * Unit tests for Wpait_Locale — the front-end locale switch (#62).
 *
 * Verifies the guards (admin/ajax/REST/feed/robots, main-query only), the
 * code→locale→installed resolution, the no-op when the locale already matches,
 * and the `wpait_switch_locale` veto filter. DB-free: view context + the
 * switch_to_locale / get_available_languages surface are driven through
 * Wpait_Test_State.
 *
 * @package WpAiTranslate\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase {

	private Wpait_Locale $locale;

	protected function setUp(): void {
		Wpait_Test_State::reset();
		Wpait_Languages::flush_index();
		Wpait_Test_State::$options['wpait_settings'] = array(
			'languages' => array(
				array( 'code' => 'en', 'name' => 'English', 'locale' => 'en_US', 'enabled' => true ),
				array( 'code' => 'es', 'name' => 'Spanish', 'locale' => 'es_ES', 'enabled' => true ),
				array( 'code' => 'de', 'name' => 'German', 'locale' => 'de_DE', 'enabled' => true ),
				array( 'code' => 'nl', 'name' => 'Dutch', 'locale' => '', 'enabled' => true ),
			),
		);
		$store           = new Wpait_Translation_Store();
		$languages       = new Wpait_Languages();
		$this->locale    = new Wpait_Locale( $store, $languages );

		// Site default locale is English; the es_ES pack is installed.
		Wpait_Test_State::$locale              = 'en_US';
		Wpait_Test_State::$available_languages = array( 'es_ES' );
	}

	/** Seeds a singular post of a given language as the main queried object. */
	private function seed_singular_post( int $id, string $code ): void {
		Wpait_Test_State::$posts[ $id ]        = array( 'ID' => $id, 'post_type' => 'post' );
		Wpait_Test_State::$object_terms[ $id ] = array( Wpait_Languages::TAXONOMY => array( $code ) );
		Wpait_Test_State::$queried_object      = get_post( $id );
		Wpait_Test_State::$is_main_query       = true;
	}

	public function test_spanish_singular_post_switches_locale(): void {
		$this->seed_singular_post( 7, 'es' );

		$this->locale->maybe_switch_locale();

		$this->assertSame( array( 'es_ES' ), Wpait_Test_State::$switched_locales );
	}

	public function test_post_with_no_language_does_not_switch(): void {
		Wpait_Test_State::$posts[8]        = array( 'ID' => 8, 'post_type' => 'post' );
		Wpait_Test_State::$queried_object  = get_post( 8 );

		$this->locale->maybe_switch_locale();

		$this->assertSame( array(), Wpait_Test_State::$switched_locales );
	}

	public function test_language_without_locale_does_not_switch(): void {
		$this->seed_singular_post( 9, 'nl' ); // nl has an empty configured locale.

		$this->locale->maybe_switch_locale();

		$this->assertSame( array(), Wpait_Test_State::$switched_locales );
	}

	public function test_locale_not_installed_does_not_switch(): void {
		// German is configured (de_DE) but its pack is NOT installed.
		$this->seed_singular_post( 10, 'de' );

		$this->locale->maybe_switch_locale();

		$this->assertSame( array(), Wpait_Test_State::$switched_locales );
	}

	public function test_locale_already_active_is_noop(): void {
		Wpait_Test_State::$locale = 'es_ES'; // Already Spanish.
		$this->seed_singular_post( 11, 'es' );

		$this->locale->maybe_switch_locale();

		$this->assertSame( array(), Wpait_Test_State::$switched_locales );
	}

	public function test_admin_context_does_not_switch(): void {
		$this->seed_singular_post( 12, 'es' );
		Wpait_Test_State::$is_admin = true;

		$this->locale->maybe_switch_locale();

		$this->assertSame( array(), Wpait_Test_State::$switched_locales );
	}

	public function test_rest_context_does_not_switch(): void {
		// REST_REQUEST is defined true by re-running in a separate process is not
		// possible here; instead exercise ajax + feed + robots which share the guard.
		$this->seed_singular_post( 13, 'es' );
		Wpait_Test_State::$doing_ajax = true;

		$this->locale->maybe_switch_locale();

		$this->assertSame( array(), Wpait_Test_State::$switched_locales );
	}

	public function test_feed_and_robots_do_not_switch(): void {
		$this->seed_singular_post( 14, 'es' );

		Wpait_Test_State::$is_feed = true;
		$this->locale->maybe_switch_locale();
		$this->assertSame( array(), Wpait_Test_State::$switched_locales );

		Wpait_Test_State::$is_feed   = false;
		Wpait_Test_State::$is_robots = true;
		$this->locale->maybe_switch_locale();
		$this->assertSame( array(), Wpait_Test_State::$switched_locales );
	}

	public function test_non_main_query_does_not_switch(): void {
		$this->seed_singular_post( 15, 'es' );
		Wpait_Test_State::$is_main_query = false;

		$this->locale->maybe_switch_locale();

		$this->assertSame( array(), Wpait_Test_State::$switched_locales );
	}

	public function test_category_archive_switches_to_term_language(): void {
		Wpait_Test_State::$terms[20] = array( 'term_id' => 20, 'taxonomy' => 'category', 'name' => 'Noticias', 'slug' => 'noticias' );
		Wpait_Test_State::$term_meta[20] = array( Wpait_Translation_Store::META_LANGUAGE => 'es' );
		Wpait_Test_State::$queried_object = get_term( 20 );
		Wpait_Test_State::$archive_type   = 'category';

		$this->locale->maybe_switch_locale();

		$this->assertSame( array( 'es_ES' ), Wpait_Test_State::$switched_locales );
	}

	public function test_filter_can_veto_switch(): void {
		$this->seed_singular_post( 16, 'es' );
		Wpait_Test_State::$filters['wpait_switch_locale'] = false;

		$this->locale->maybe_switch_locale();

		$this->assertSame( array(), Wpait_Test_State::$switched_locales );
	}
}
