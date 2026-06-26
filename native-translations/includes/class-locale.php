<?php
/**
 * Front-end locale switching (#62).
 *
 * On the front end, when the main query resolves to a singular post/page or a
 * category/tag archive whose content carries a configured language, switch the
 * WordPress locale to that language's locale for the rest of the request. This
 * makes core/theme UI strings ("previous"/"next", "Leave a Reply", …) and dates
 * render in the content's language rather than the site default.
 *
 * Why `switch_to_locale()` on `wp` (not a `locale` filter): the queried object is
 * not known early enough to answer a `locale` filter, and `switch_to_locale()`
 * additionally reloads the loaded textdomains so already-late strings localize too.
 * The `wp` action fires after the main query is set up and before template output.
 *
 * NOTE (v1 → v2): the original plan deferred locale switching ("no locale
 * switching" was a v1 simplification). This issue deliberately reverses that.
 *
 * @package WpNativeTranslations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Switches the request locale to match the queried content's language.
 */
class Wpnt_Locale {

	/**
	 * Translation store.
	 *
	 * @var Wpnt_Translation_Store
	 */
	private Wpnt_Translation_Store $store;

	/**
	 * Languages handler.
	 *
	 * @var Wpnt_Languages
	 */
	private Wpnt_Languages $languages;

	/**
	 * Constructor.
	 *
	 * @param Wpnt_Translation_Store $store     Translation store.
	 * @param Wpnt_Languages         $languages Languages handler.
	 */
	public function __construct( Wpnt_Translation_Store $store, Wpnt_Languages $languages ) {
		$this->store     = $store;
		$this->languages = $languages;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp', array( $this, 'maybe_switch_locale' ) );
	}

	/**
	 * Switches the locale to the queried content's language when appropriate.
	 *
	 * Self-guards so it can be registered unconditionally: it only acts on a
	 * front-end main-query singular post/page or category/tag archive, resolves the
	 * content's configured language → locale, and switches only when that locale is
	 * non-empty, installed, and differs from the current one.
	 *
	 * @return void
	 */
	public function maybe_switch_locale(): void {
		// Non-front-end contexts never switch.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_robots() ) {
			return;
		}

		$resolved = $this->resolve_content_language();
		if ( null === $resolved ) {
			return;
		}
		list( $code, $type, $id ) = $resolved;
		if ( '' === $code ) {
			return;
		}

		$locale = $this->languages->locale( $code );
		if ( '' === $locale ) {
			return;
		}

		// Already the active locale (e.g. the site default) — nothing to do.
		if ( $locale === get_locale() ) {
			return;
		}

		// Only switch to a locale whose language pack is actually installed; en_US is
		// the built-in default and always counts.
		if ( 'en_US' !== $locale && ! in_array( $locale, get_available_languages(), true ) ) {
			return;
		}

		/**
		 * Filters whether to switch the request locale for this content.
		 *
		 * Return false to opt out per request.
		 *
		 * @param bool   $switch Whether to switch (default true).
		 * @param string $locale Target WordPress locale.
		 * @param string $type   'post' | 'term'.
		 * @param int    $id     Queried object id.
		 */
		if ( ! apply_filters( 'wpnt_switch_locale', true, $locale, $type, $id ) ) {
			return;
		}

		switch_to_locale( $locale );
	}

	/**
	 * Resolves the queried content's language code from the main query.
	 *
	 * Context-safe: only a genuine main-query singular post/page or category/tag
	 * archive's queried object is trusted (never a query-loop/preview guess).
	 *
	 * @return array{0:string,1:string,2:int}|null [ code, type, id ] or null when the
	 *                                             current view is not eligible.
	 */
	private function resolve_content_language(): ?array {
		if ( ! is_main_query() ) {
			return null;
		}

		if ( is_singular() ) {
			$obj = get_queried_object();
			if ( $obj instanceof WP_Post ) {
				return array( $this->store->get_language( 'post', (int) $obj->ID ), 'post', (int) $obj->ID );
			}
			return null;
		}

		if ( is_category() || is_tag() || is_tax( array( 'category', 'post_tag' ) ) ) {
			$obj = get_queried_object();
			if ( $obj instanceof WP_Term && in_array( $obj->taxonomy, array( 'category', 'post_tag' ), true ) ) {
				return array( $this->store->get_language( 'term', (int) $obj->term_id ), 'term', (int) $obj->term_id );
			}
		}

		return null;
	}
}
