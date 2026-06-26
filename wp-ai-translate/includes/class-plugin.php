<?php
/**
 * Main plugin controller.
 *
 * @package WpAiTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires the plugin's components together.
 */
final class Wpait_Plugin {

	/**
	 * Single instance.
	 *
	 * @var Wpait_Plugin|null
	 */
	private static ?Wpait_Plugin $instance = null;

	/**
	 * Translation store.
	 *
	 * @var Wpait_Translation_Store
	 */
	private Wpait_Translation_Store $store;

	/**
	 * Language taxonomy + reconcile handler.
	 *
	 * @var Wpait_Languages
	 */
	private Wpait_Languages $languages;

	/**
	 * Admin settings page.
	 *
	 * @var Wpait_Admin_Settings
	 */
	private Wpait_Admin_Settings $settings;

	/**
	 * AI translator (the only caller of the core AI connector).
	 *
	 * @var Wpait_Translator
	 */
	private Wpait_Translator $translator;

	/**
	 * REST API controller.
	 *
	 * @var Wpait_Rest
	 */
	private Wpait_Rest $rest;

	/**
	 * Background translation queue (#55).
	 *
	 * @var Wpait_Queue
	 */
	private Wpait_Queue $queue;

	/**
	 * Editor integration (sidebar panel + term meta box).
	 *
	 * @var Wpait_Editor
	 */
	private Wpait_Editor $editor;

	/**
	 * Admin list-table integration (column, filters, overview).
	 *
	 * @var Wpait_Admin_List
	 */
	private Wpait_Admin_List $admin_list;

	/**
	 * Front-end blocks + shared resolver.
	 *
	 * @var Wpait_Frontend
	 */
	private Wpait_Frontend $frontend;

	/**
	 * Front-end locale switching (#62).
	 *
	 * @var Wpait_Locale
	 */
	private Wpait_Locale $locale;

	/**
	 * Returns the singleton instance.
	 *
	 * @return Wpait_Plugin
	 */
	public static function instance(): Wpait_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wires hooks and instantiates components.
	 */
	private function __construct() {
		$this->store      = new Wpait_Translation_Store();
		$this->languages  = new Wpait_Languages();
		$this->settings   = new Wpait_Admin_Settings( $this->languages );
		$this->translator = new Wpait_Translator( $this->store, $this->languages );
		$this->queue      = new Wpait_Queue( $this->store, $this->languages, $this->translator );
		$this->rest       = new Wpait_Rest( $this->store, $this->languages, $this->translator, $this->queue );
		$this->editor     = new Wpait_Editor( $this->languages, $this->store );
		$this->admin_list = new Wpait_Admin_List( $this->store, $this->languages );
		$this->frontend   = new Wpait_Frontend( $this->store, $this->languages );
		$this->locale     = new Wpait_Locale( $this->store, $this->languages );

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
			'wp-ai-translate',
			false,
			dirname( plugin_basename( WPAIT_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Returns the translation store.
	 *
	 * @return Wpait_Translation_Store
	 */
	public function store(): Wpait_Translation_Store {
		return $this->store;
	}

	/**
	 * Returns the languages handler.
	 *
	 * @return Wpait_Languages
	 */
	public function languages(): Wpait_Languages {
		return $this->languages;
	}

	/**
	 * Returns the AI translator.
	 *
	 * @return Wpait_Translator
	 */
	public function translator(): Wpait_Translator {
		return $this->translator;
	}

	/**
	 * Returns the REST controller.
	 *
	 * @return Wpait_Rest
	 */
	public function rest(): Wpait_Rest {
		return $this->rest;
	}

	/**
	 * Returns the background translation queue.
	 *
	 * @return Wpait_Queue
	 */
	public function queue(): Wpait_Queue {
		return $this->queue;
	}

	/**
	 * Returns the editor integration.
	 *
	 * @return Wpait_Editor
	 */
	public function editor(): Wpait_Editor {
		return $this->editor;
	}

	/**
	 * Returns the admin list-table integration.
	 *
	 * @return Wpait_Admin_List
	 */
	public function admin_list(): Wpait_Admin_List {
		return $this->admin_list;
	}

	/**
	 * Returns the front-end blocks/resolver integration.
	 *
	 * @return Wpait_Frontend
	 */
	public function frontend(): Wpait_Frontend {
		return $this->frontend;
	}

	/**
	 * Returns the front-end locale-switching integration.
	 *
	 * @return Wpait_Locale
	 */
	public function locale(): Wpait_Locale {
		return $this->locale;
	}
}
