<?php
/**
 * Editor integration: the block-editor sidebar panel (posts/pages) and the
 * term edit-screen meta box (categories/tags).
 *
 * Both UIs are thin clients over the REST endpoints in {@see Wpait_Rest}; this
 * class only enqueues the assets and provides the configured-language context.
 *
 * @package WpAiTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the editor sidebar panel and the term meta box.
 */
class Wpait_Editor {

	/**
	 * Taxonomies whose edit screens get the translation meta box.
	 *
	 * @var string[]
	 */
	const TERM_TAXONOMIES = array( 'category', 'post_tag' );

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_panel' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_term_assets' ) );

		foreach ( self::TERM_TAXONOMIES as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_term_box' ), 10, 2 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Shared context
	 * ------------------------------------------------------------------- */

	/**
	 * Returns the enabled, configured languages as a JS-friendly list.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function enabled_languages(): array {
		$settings = Wpait_Admin_Settings::get_settings();
		$out      = array();
		foreach ( $settings['languages'] as $lang ) {
			if ( empty( $lang['enabled'] ) || empty( $lang['code'] ) ) {
				continue;
			}
			$out[] = array(
				'code'   => (string) $lang['code'],
				'name'   => (string) ( $lang['name'] ?? $lang['code'] ),
				'native' => (string) ( $lang['native'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Whether the site has a usable AI provider (gates the translate buttons).
	 *
	 * @return bool
	 */
	private function ai_available(): bool {
		return function_exists( 'wp_supports_ai' ) && wp_supports_ai();
	}

	/**
	 * Common context shared with both clients.
	 *
	 * @return array<string,mixed>
	 */
	private function base_context(): array {
		$settings = Wpait_Admin_Settings::get_settings();
		return array(
			'namespace'       => Wpait_Rest::NS,
			'languages'       => $this->enabled_languages(),
			'defaultLanguage' => (string) $settings['default_language'],
			'aiAvailable'     => $this->ai_available(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Block editor sidebar panel (posts/pages)
	 * ------------------------------------------------------------------- */

	/**
	 * Enqueues the sidebar panel on the block editor for supported post types.
	 *
	 * @return void
	 */
	public function enqueue_panel(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, Wpait_Languages::OBJECT_TYPES, true ) ) {
			return;
		}

		$asset_file = WPAIT_PLUGIN_DIR . 'build/editor-panel/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'wpait-editor-panel',
			WPAIT_PLUGIN_URL . 'build/editor-panel/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_add_inline_script(
			'wpait-editor-panel',
			'window.wpaitEditor = ' . wp_json_encode( $this->base_context() ) . ';',
			'before'
		);

		wp_set_script_translations( 'wpait-editor-panel', 'wp-ai-translate' );
	}

	/* ---------------------------------------------------------------------
	 * Term meta box (categories/tags)
	 * ------------------------------------------------------------------- */

	/**
	 * Enqueues the term-screen client on category/tag edit screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_term_assets( $hook_suffix ): void {
		if ( 'term.php' !== $hook_suffix ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->taxonomy, self::TERM_TAXONOMIES, true ) ) {
			return;
		}

		$term_id = isset( $_GET['tag_ID'] ) ? absint( wp_unslash( $_GET['tag_ID'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_enqueue_script(
			'wpait-term-panel',
			WPAIT_PLUGIN_URL . 'assets/js/term-panel.js',
			array( 'wp-api-fetch', 'wp-dom-ready', 'wp-i18n' ),
			WPAIT_VERSION,
			true
		);

		$context              = $this->base_context();
		$context['termId']    = $term_id;
		$context['taxonomy']  = $screen->taxonomy;

		wp_add_inline_script(
			'wpait-term-panel',
			'window.wpaitTerm = ' . wp_json_encode( $context ) . ';',
			'before'
		);

		wp_set_script_translations( 'wpait-term-panel', 'wp-ai-translate' );
	}

	/**
	 * Renders the meta-box mount point inside the term edit form. The actual UI is
	 * rendered by term-panel.js, which talks to the REST endpoints.
	 *
	 * @param WP_Term $term     Term being edited.
	 * @param string  $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function render_term_box( $term, $taxonomy ): void {
		if ( ! in_array( $taxonomy, self::TERM_TAXONOMIES, true ) ) {
			return;
		}
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Translations', 'wp-ai-translate' ); ?></th>
			<td>
				<div id="wpait-term-panel" data-term-id="<?php echo esc_attr( (string) $term->term_id ); ?>">
					<p class="description"><?php esc_html_e( 'Loading translations…', 'wp-ai-translate' ); ?></p>
				</div>
			</td>
		</tr>
		<?php
	}
}
