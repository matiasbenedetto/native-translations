<?php
/**
 * Harness-only must-use plugin: make the disposable Playground site able to run the
 * real translation flow against OpenRouter (issue #38).
 *
 * This file is copied into the live site's wp-content/mu-plugins/ by
 * `playground.sh provision-ai` ONLY when harness/.env.local supplies an
 * OPENROUTER_API_KEY. It is NOT part of the native-translations plugin — the plugin
 * never holds or reads the API key; it only calls the WP 7.0 AI connector.
 *
 * Two jobs:
 *  1. Inject the API key (stored in the `connectors_ai_openrouter_api_key` option by
 *     the harness, the WordPress `ai`-plugin naming convention) into the AI Client
 *     registry for the `openrouter` provider registered by the
 *     ai-provider-for-openrouter plugin.
 *  2. Pin the model the plugin requests, via native-translations's documented
 *     `wpnt_model_preference` filter (the plugin has no model UI by design — N4).
 *
 * @package WpNativeTranslations\Harness
 */

namespace Wpnt\Harness\E2E;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

defined( 'ABSPATH' ) || exit;

// Runs after ai-provider-for-openrouter registers its provider (init priority 5).
add_action(
	'init',
	static function () {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}
		$key = (string) get_option( 'connectors_ai_openrouter_api_key', '' );
		if ( '' === $key ) {
			return;
		}
		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( 'openrouter' ) ) {
			return;
		}
		$registry->setProviderRequestAuthentication( 'openrouter', new ApiKeyRequestAuthentication( $key ) );
	},
	6
);

// Pin the GLM model native-translations should request (the OpenRouter default would
// otherwise be left to the connector). Mirrors the production pattern of selecting
// an accessible model via the plugin's own filter.
add_filter(
	'wpnt_model_preference',
	static function () {
		return array( 'z-ai/glm-5.2' );
	}
);
