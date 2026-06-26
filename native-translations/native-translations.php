<?php
/**
 * Plugin Name:       WP Native Translations
 * Plugin URI:        https://github.com/matiasbenedetto/native-translations
 * Description:       Manage multilingual content and generate translations on demand using the WordPress core AI connectors.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Matias Benedetto
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       native-translations
 * Domain Path:       /languages
 *
 * @package WpNativeTranslations
 */

defined( 'ABSPATH' ) || exit;

define( 'WPNT_VERSION', '0.1.0' );
define( 'WPNT_PLUGIN_FILE', __FILE__ );
define( 'WPNT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPNT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Renders an admin notice when the environment does not meet requirements,
 * then prevents the rest of the plugin from loading.
 *
 * @param string $message The requirement that was not met.
 * @return void
 */
function wpnt_requirements_notice( string $message ): void {
	add_action(
		'admin_notices',
		static function () use ( $message ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses_post( $message )
			);
		}
	);
}

// Hard requirement guards. WP 7.0 is needed for the core AI connectors; PHP 8.1 for typed code.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	wpnt_requirements_notice(
		sprintf(
			/* translators: %s: current PHP version. */
			__( 'WP Native Translations requires PHP 8.1 or newer. You are running %s.', 'native-translations' ),
			PHP_VERSION
		)
	);
	return;
}

if ( version_compare( get_bloginfo( 'version' ), '7.0', '<' ) ) {
	wpnt_requirements_notice(
		__( 'WP Native Translations requires WordPress 7.0 or newer (for the core AI connectors).', 'native-translations' )
	);
	return;
}

// Action Scheduler (bundled) powers the background translation queue (#55). Its
// versioned loader self-selects the newest copy if other plugins bundle it too.
if ( file_exists( WPNT_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once WPNT_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

require_once WPNT_PLUGIN_DIR . 'includes/class-translation-store.php';
require_once WPNT_PLUGIN_DIR . 'includes/class-languages.php';
require_once WPNT_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once WPNT_PLUGIN_DIR . 'includes/class-translator.php';
require_once WPNT_PLUGIN_DIR . 'includes/class-queue.php';
require_once WPNT_PLUGIN_DIR . 'includes/class-rest.php';
require_once WPNT_PLUGIN_DIR . 'includes/class-editor.php';
require_once WPNT_PLUGIN_DIR . 'includes/class-admin-list.php';
require_once WPNT_PLUGIN_DIR . 'includes/class-frontend.php';
require_once WPNT_PLUGIN_DIR . 'includes/class-locale.php';
require_once WPNT_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Returns the main plugin instance.
 *
 * @return Wpnt_Plugin
 */
function wpnt(): Wpnt_Plugin {
	return Wpnt_Plugin::instance();
}

// Boot.
wpnt();

// Register the language taxonomy on activation so terms can be reconciled, then flush.
register_activation_hook(
	__FILE__,
	static function () {
		Wpnt_Languages::register_taxonomy();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		flush_rewrite_rules();
	}
);
