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

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->store->register_hooks();
		$this->languages->register_hooks();
		$this->settings->register_hooks();
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
}
