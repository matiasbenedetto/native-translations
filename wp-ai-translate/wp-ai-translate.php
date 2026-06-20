<?php
/**
 * Plugin Name:       AI Translate
 * Plugin URI:        https://github.com/matiasbenedetto/wp-ai-translate
 * Description:       Manage multilingual content and generate translations on demand using the WordPress core AI connectors.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Matias Benedetto
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-translate
 * Domain Path:       /languages
 *
 * @package WpAiTranslate
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAIT_VERSION', '0.1.0' );
define( 'WPAIT_PLUGIN_FILE', __FILE__ );
define( 'WPAIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Renders an admin notice when the environment does not meet requirements,
 * then prevents the rest of the plugin from loading.
 *
 * @param string $message The requirement that was not met.
 * @return void
 */
function wpait_requirements_notice( string $message ): void {
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
	wpait_requirements_notice(
		sprintf(
			/* translators: %s: current PHP version. */
			__( 'AI Translate requires PHP 8.1 or newer. You are running %s.', 'wp-ai-translate' ),
			PHP_VERSION
		)
	);
	return;
}

if ( version_compare( get_bloginfo( 'version' ), '7.0', '<' ) ) {
	wpait_requirements_notice(
		__( 'AI Translate requires WordPress 7.0 or newer (for the core AI connectors).', 'wp-ai-translate' )
	);
	return;
}

require_once WPAIT_PLUGIN_DIR . 'includes/class-translation-store.php';
require_once WPAIT_PLUGIN_DIR . 'includes/class-languages.php';
require_once WPAIT_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once WPAIT_PLUGIN_DIR . 'includes/class-translator.php';
require_once WPAIT_PLUGIN_DIR . 'includes/class-rest.php';
require_once WPAIT_PLUGIN_DIR . 'includes/class-editor.php';
require_once WPAIT_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Returns the main plugin instance.
 *
 * @return Wpait_Plugin
 */
function wpait(): Wpait_Plugin {
	return Wpait_Plugin::instance();
}

// Boot.
wpait();

// Register the language taxonomy on activation so terms can be reconciled, then flush.
register_activation_hook(
	__FILE__,
	static function () {
		Wpait_Languages::register_taxonomy();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		flush_rewrite_rules();
	}
);
