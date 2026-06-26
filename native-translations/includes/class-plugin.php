<?php
/**
 * Main plugin controller.
 *
 * @package WpNativeTranslations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires the plugin's components together.
 */
final class Wpnt_Plugin {

	/**
	 * Single instance.
	 *
	 * @var Wpnt_Plugin|null
	 */
	private static ?Wpnt_Plugin $instance = null;

	/**
	 * Translation store.
	 *
	 * @var Wpnt_Translation_Store
	 */
	private Wpnt_Translation_Store $store;

	/**
	 * Language taxonomy + reconcile handler.
	 *
	 * @var Wpnt_Languages
	 */
	private Wpnt_Languages $languages;

	/**
	 * Admin settings page.
	 *
	 * @var Wpnt_Admin_Settings
	 */
	private Wpnt_Admin_Settings $settings;

	/**
	 * AI translator (the only caller of the core AI connector).
	 *
	 * @var Wpnt_Translator
	 */
	private Wpnt_Translator $translator;

	/**
	 * REST API controller.
	 *
	 * @var Wpnt_Rest
	 */
	private Wpnt_Rest $rest;

	/**
	 * Background translation queue (#55).
	 *
	 * @var Wpnt_Queue
	 */
	private Wpnt_Queue $queue;

	/**
	 * Editor integration (sidebar panel + term meta box).
	 *
	 * @var Wpnt_Editor
	 */
	private Wpnt_Editor $editor;

	/**
	 * Admin list-table integration (column, filters, overview).
	 *
	 * @var Wpnt_Admin_List
	 */
	private Wpnt_Admin_List $admin_list;

	/**
	 * Front-end blocks + shared resolver.
	 *
	 * @var Wpnt_Frontend
	 */
	private Wpnt_Frontend $frontend;

	/**
	 * Front-end locale switching (#62).
	 *
	 * @var Wpnt_Locale
	 */
	private Wpnt_Locale $locale;

	/**
	 * Returns the singleton instance.
	 *
	 * @return Wpnt_Plugin
	 */
	public static function instance(): Wpnt_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wires hooks and instantiates components.
	 */
	private function __construct() {
		$this->store      = new Wpnt_Translation_Store();
		$this->languages  = new Wpnt_Languages();
		$this->settings   = new Wpnt_Admin_Settings( $this->languages );
		$this->translator = new Wpnt_Translator( $this->store, $this->languages );
		$this->queue      = new Wpnt_Queue( $this->store, $this->languages, $this->translator );
		$this->rest       = new Wpnt_Rest( $this->store, $this->languages, $this->translator, $this->queue );
		$this->editor     = new Wpnt_Editor( $this->languages, $this->store );
		$this->admin_list = new Wpnt_Admin_List( $this->store, $this->languages );
		$this->frontend   = new Wpnt_Frontend( $this->store, $this->languages );
		$this->locale     = new Wpnt_Locale( $this->store, $this->languages );

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->store->register_hooks();
		$this->languages->register_hooks();
		$this->settings->register_hooks();
		$this->rest->register_hooks();
		// The queue's AS action hook must register unconditionally: jobs run in
		// cron/async context, not just on admin screens.
		$this->queue->register_hooks();
		$this->editor->register_hooks();
		$this->frontend->register_hooks();

		// Locale switching is front-end only; the handler self-guards, but skip the
		// hook entirely in admin context for clarity.
		if ( ! is_admin() ) {
			$this->locale->register_hooks();
		}

		// The Overview REST route must register in REST (non-admin) context too, so it
		// is wired unconditionally; the admin-screen hooks (columns, filters, menu)
		// stay admin-only.
		add_action( 'rest_api_init', array( $this->admin_list, 'register_rest_routes' ) );

		if ( is_admin() ) {
			$this->admin_list->register_hooks();
		}
	}

	/**
	 * Loads the plugin text domain (N1: i18n wired from M1).
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'native-translations',
			false,
			dirname( plugin_basename( WPNT_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Returns the translation store.
	 *
	 * @return Wpnt_Translation_Store
	 */
	public function store(): Wpnt_Translation_Store {
		return $this->store;
	}

	/**
	 * Returns the languages handler.
	 *
	 * @return Wpnt_Languages
	 */
	public function languages(): Wpnt_Languages {
		return $this->languages;
	}

	/**
	 * Returns the AI translator.
	 *
	 * @return Wpnt_Translator
	 */
	public function translator(): Wpnt_Translator {
		return $this->translator;
	}

	/**
	 * Returns the REST controller.
	 *
	 * @return Wpnt_Rest
	 */
	public function rest(): Wpnt_Rest {
		return $this->rest;
	}

	/**
	 * Returns the background translation queue.
	 *
	 * @return Wpnt_Queue
	 */
	public function queue(): Wpnt_Queue {
		return $this->queue;
	}

	/**
	 * Returns the editor integration.
	 *
	 * @return Wpnt_Editor
	 */
	public function editor(): Wpnt_Editor {
		return $this->editor;
	}

	/**
	 * Returns the admin list-table integration.
	 *
	 * @return Wpnt_Admin_List
	 */
	public function admin_list(): Wpnt_Admin_List {
		return $this->admin_list;
	}

	/**
	 * Returns the front-end blocks/resolver integration.
	 *
	 * @return Wpnt_Frontend
	 */
	public function frontend(): Wpnt_Frontend {
		return $this->frontend;
	}

	/**
	 * Returns the front-end locale-switching integration.
	 *
	 * @return Wpnt_Locale
	 */
	public function locale(): Wpnt_Locale {
		return $this->locale;
	}
}
