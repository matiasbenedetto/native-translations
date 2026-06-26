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
	 * Staging post meta the block-editor sidebar writes the chosen language code
	 * into (#106). It is consumed (read + deleted) on save by
	 * {@see persist_post_editor_changes()}, which applies it through the store —
	 * the post's real language lives in the `wpait_language` taxonomy, not here.
	 */
	const META_EDITOR_LANGUAGE = '_wpait_editor_language';

	/**
	 * Hidden term-form field names the term panel writes the pending language /
	 * original choice into (#106), applied on Update by
	 * {@see persist_term_editor_changes()}.
	 */
	const TERM_FIELD_LANGUAGE    = 'wpait_editor_language';
	const TERM_FIELD_IS_ORIGINAL = 'wpait_editor_is_original';

	/**
	 * Languages handler (shared enabled/label helpers).
	 *
	 * @var Wpait_Languages
	 */
	private Wpait_Languages $languages;

	/**
	 * Translation store (the single writer for language + original — #106 persists
	 * the editor's deferred changes through it on save).
	 *
	 * @var Wpait_Translation_Store
	 */
	private Wpait_Translation_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Wpait_Languages         $languages Languages handler.
	 * @param Wpait_Translation_Store $store     Translation store.
	 */
	public function __construct( Wpait_Languages $languages, Wpait_Translation_Store $store ) {
		$this->languages = $languages;
		$this->store     = $store;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_panel' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_term_assets' ) );
		add_action( 'init', array( $this, 'register_post_meta_fields' ) );

		// #106: apply the sidebar's deferred language/original changes when the post
		// is saved through the REST/block editor.
		foreach ( Wpait_Languages::OBJECT_TYPES as $post_type ) {
			add_action( "rest_after_insert_{$post_type}", array( $this, 'persist_post_editor_changes' ), 10, 1 );
		}

		foreach ( self::TERM_TAXONOMIES as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_term_box' ), 10, 2 );
			// #106: apply the term form's deferred language/original changes on Update.
			add_action( "edited_{$taxonomy}", array( $this, 'persist_term_editor_changes' ), 10, 1 );
		}
	}

	/**
	 * Registers the block-editor post meta used by the sidebar (#106).
	 *
	 * - `_wpait_is_original` (the store's own original flag) is exposed to REST so
	 *   the sidebar's ToggleControl edits it through `core/editor`, participating in
	 *   the native dirty state + Save. {@see persist_post_editor_changes()}
	 *   reconciles the store rule that an original must have a language.
	 * - `_wpait_editor_language` is a staging field the language picker writes into;
	 *   it is consumed on save and never reflects the persisted language directly.
	 *
	 * @return void
	 */
	public function register_post_meta_fields(): void {
		$edit_auth = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', (int) $post_id );
		};

		foreach ( Wpait_Languages::OBJECT_TYPES as $post_type ) {
			register_post_meta(
				$post_type,
				Wpait_Translation_Store::META_IS_ORIGINAL,
				array(
					'type'              => 'boolean',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => $edit_auth,
					'sanitize_callback' => static function ( $value ) {
						return $value ? '1' : '';
					},
				)
			);

			register_post_meta(
				$post_type,
				self::META_EDITOR_LANGUAGE,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => $edit_auth,
					'sanitize_callback' => 'sanitize_title',
				)
			);
		}
	}

	/**
	 * Applies the sidebar's deferred language/original changes when a post is saved
	 * through the block editor (#106).
	 *
	 * The picker stages its choice in `_wpait_editor_language`; here we consume that
	 * staging value and route it through {@see Wpait_Translation_Store::set_language()}
	 * so the group invariants still apply. The original flag is already persisted by
	 * core (it is the store's own meta key), so we only reconcile the rule that an
	 * item with no language cannot remain marked original.
	 *
	 * @param WP_Post $post Saved post.
	 * @return void
	 */
	public function persist_post_editor_changes( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$post_id = (int) $post->ID;

		$choice = (string) get_post_meta( $post_id, self::META_EDITOR_LANGUAGE, true );
		if ( '' !== $choice ) {
			// Staging field is consumed regardless of outcome, so a stale choice never
			// re-applies on a later, unrelated save.
			delete_post_meta( $post_id, self::META_EDITOR_LANGUAGE );

			if ( $choice !== $this->store->get_language( 'post', $post_id ) ) {
				$this->store->set_language( 'post', $post_id, $choice );
			}
		}

		// An item with no language cannot be an original (#92): if core just persisted
		// the flag on a language-less post, clear it back.
		if ( $this->store->is_original( 'post', $post_id ) && '' === $this->store->get_language( 'post', $post_id ) ) {
			$this->store->set_original( 'post', $post_id, false );
		}
	}

	/**
	 * Applies the term edit form's deferred language/original changes on Update (#106).
	 *
	 * The term panel writes the pending choice into hidden form fields instead of
	 * firing REST on change; core has already verified the term-update nonce by the
	 * time `edited_{taxonomy}` fires, but we still re-check the edit capability and
	 * route everything through the store so its invariants apply.
	 *
	 * @param int $term_id Edited term id.
	 * @return void
	 */
	public function persist_term_editor_changes( $term_id ): void {
		$term_id = (int) $term_id;
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core verifies the term-update nonce before edited_{taxonomy}.
		if ( isset( $_POST[ self::TERM_FIELD_LANGUAGE ] ) ) {
			$choice = sanitize_title( wp_unslash( $_POST[ self::TERM_FIELD_LANGUAGE ] ) );
			if ( '' !== $choice && $choice !== $this->store->get_language( 'term', $term_id ) ) {
				$this->store->set_language( 'term', $term_id, $choice );
			}
		}

		if ( isset( $_POST[ self::TERM_FIELD_IS_ORIGINAL ] ) ) {
			$want = '1' === (string) wp_unslash( $_POST[ self::TERM_FIELD_IS_ORIGINAL ] );
			if ( $want !== $this->store->is_original( 'term', $term_id ) ) {
				$this->store->set_original( 'term', $term_id, $want );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
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
		// Delegates to the shared enabled-language helper (M4), projecting to the
		// code/name/native shape the JS clients expect.
		return array_map(
			static function ( $lang ) {
				return array(
					'code'   => (string) $lang['code'],
					'name'   => (string) $lang['name'],
					'native' => (string) $lang['native'],
				);
			},
			$this->languages->enabled()
		);
	}

	/**
	 * Whether the site has a usable AI provider (gates the translate buttons).
	 *
	 * Delegates to the translator's capability probe, which requires not just a
	 * supported provider but at least one text-generation model — so the panel does
	 * not advertise a feature that would fail on every click (see
	 * {@see Wpait_Translator::can_generate_text()}).
	 *
	 * @return bool
	 */
	private function ai_available(): bool {
		return Wpait_Translator::can_generate_text();
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
