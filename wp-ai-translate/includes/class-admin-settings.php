<?php
/**
 * Settings page: the AI Translate top-level admin menu (Settings submenu).
 *
 * @package WpAiTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin settings, and reconciles language terms on
 * save (delegating to Wpait_Languages).
 */
class Wpait_Admin_Settings {

	const OPTION    = 'wpait_settings';
	const PAGE_SLUG = 'wp-ai-translate';

	/**
	 * Hook suffix returned by add_submenu_page for the Settings page, used to
	 * gate asset enqueuing without hardcoding the (fragile) hook string.
	 *
	 * @var string
	 */
	private string $settings_hook = '';

	/**
	 * Languages handler used for reconcile.
	 *
	 * @var Wpait_Languages
	 */
	private Wpait_Languages $languages;

	/**
	 * Whether reconcile admin notices have already been queued this request.
	 * Guards against register_setting sanitizing the option more than once.
	 *
	 * @var bool
	 */
	private static bool $reconcile_notified = false;

	/**
	 * Constructor.
	 *
	 * @param Wpait_Languages $languages Languages handler.
	 */
	public function __construct( Wpait_Languages $languages ) {
		$this->languages = $languages;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Priority 9 so the top-level parent menu is registered before
		// Wpait_Admin_List adds its Overview submenu (default priority 10).
		add_action( 'admin_menu', array( $this, 'add_menu' ), 9 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues wp-api-fetch on the settings page so the "Test connection" control
	 * can call the REST probe (apiFetch wires the REST root + nonce automatically).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( '' === $this->settings_hook || $hook_suffix !== $this->settings_hook ) {
			return;
		}
		wp_enqueue_script( 'wp-api-fetch' );
	}

	/* ---------------------------------------------------------------------
	 * Settings accessors (shared with translator in M2)
	 * ------------------------------------------------------------------- */

	/**
	 * Default settings structure.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'languages'        => array(),
			'default_language' => '',
			'model'            => '',
			'instructions'     => array(
				'global'       => '',
				'post'         => '',
				'term'         => '',
				'per_language' => array(),
			),
		);
	}

	/**
	 * Baseline instruction strings shipped with the plugin. Blank admin fields
	 * fall back to these (used here and by the M2 translator).
	 *
	 * @return array<string,string>
	 */
	public static function instruction_defaults(): array {
		return array(
			'global' => __(
				'You are a professional translator for {site_name}. Translate the provided content from {source_lang} into {target_lang}. Produce a natural, fluent translation that preserves the original meaning, tone, and register. Do not summarise, add, or omit content.',
				'wp-ai-translate'
			),
			'post'   => __(
				'This is web page content. Keep headings, lists, and structure intact.',
				'wp-ai-translate'
			),
			'term'   => __(
				'This is a taxonomy term (a category or tag) used for navigation. Keep the translation short and label-like.',
				'wp-ai-translate'
			),
		);
	}

	/**
	 * A representative set of WordPress locales offered as datalist suggestions for
	 * the Locale field. Suggestions only — any `xx_YY`-format value is still allowed.
	 *
	 * @return string[]
	 */
	public static function common_locales(): array {
		return array(
			'en_US', 'en_GB', 'es_ES', 'es_AR', 'es_MX', 'pt_BR', 'pt_PT', 'fr_FR',
			'fr_CA', 'de_DE', 'it_IT', 'nl_NL', 'pl_PL', 'ru_RU', 'uk', 'sv_SE',
			'da_DK', 'nb_NO', 'fi', 'cs_CZ', 'el', 'tr_TR', 'ar', 'he_IL', 'hi_IN',
			'id_ID', 'ja', 'ko_KR', 'th', 'vi', 'zh_CN', 'zh_TW',
		);
	}

	/**
	 * Whether a locale string matches the WordPress `xx`/`xx_YY` shape. An empty
	 * locale is allowed (the field is optional).
	 *
	 * @param string $locale Locale string.
	 * @return bool
	 */
	public static function is_valid_locale( string $locale ): bool {
		return '' === $locale || 1 === preg_match( '/^[a-z]{2,3}(_[A-Z]{2,3})?$/', $locale );
	}

	/**
	 * Returns the stored settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings                 = array_merge( self::defaults(), $stored );
		$settings['instructions'] = array_merge( self::defaults()['instructions'], $stored['instructions'] ?? array() );

		return $settings;
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------- */

	/**
	 * Adds the top-level "AI Translate" menu plus its Settings submenu.
	 *
	 * The submenu is registered with the same slug as the parent so the first
	 * item reads "Settings" instead of repeating the menu title.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'AI Translate', 'wp-ai-translate' ),
			__( 'AI Translate', 'wp-ai-translate' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-translation',
			58
		);

		$this->settings_hook = (string) add_submenu_page(
			self::PAGE_SLUG,
			__( 'AI Translate', 'wp-ai-translate' ),
			__( 'Settings', 'wp-ai-translate' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the option + sanitize callback.
	 *
	 * @return void
	 */
	public function register_setting(): void {
		register_setting(
			'wpait_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitizes the submitted settings and reconciles language terms (S4).
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$old      = self::get_settings();
		$settings = self::defaults();

		// --- Languages ---
		$languages        = array();
		$invalid_locales  = array();
		$rows             = isset( $input['languages'] ) && is_array( $input['languages'] ) ? $input['languages'] : array();
		foreach ( $rows as $row ) {
			$code = isset( $row['code'] ) ? sanitize_key( $row['code'] ) : '';
			if ( '' === $code ) {
				continue;
			}
			$locale = isset( $row['locale'] ) ? sanitize_text_field( $row['locale'] ) : '';
			if ( ! self::is_valid_locale( $locale ) ) {
				$invalid_locales[] = $code;
			}
			// Flags are emoji; keep the value but cap its length so a stray paste of
			// long text can't land in a field meant for one glyph (subdivision flags
			// can be several code points, so the cap is generous).
			$flag = isset( $row['flag'] ) ? sanitize_text_field( $row['flag'] ) : '';
			if ( function_exists( 'mb_substr' ) ) {
				$flag = mb_substr( $flag, 0, 12 );
			}
			$languages[ $code ] = array(
				'code'    => $code,
				'locale'  => $locale,
				'name'    => isset( $row['name'] ) && '' !== trim( (string) $row['name'] )
					? sanitize_text_field( $row['name'] )
					: $code,
				'native'  => isset( $row['native'] ) ? sanitize_text_field( $row['native'] ) : '',
				'flag'    => $flag,
				'enabled' => ! empty( $row['enabled'] ),
			);
		}
		$languages = array_values( $languages );

		// Warn (don't discard) on malformed locales so the admin can correct them
		// without losing what they typed.
		if ( ! empty( $invalid_locales ) && ! self::$reconcile_notified ) {
			add_settings_error(
				self::OPTION,
				'wpait_invalid_locale',
				sprintf(
					/* translators: %s: comma-separated language codes. */
					__( 'These languages have a locale that is not in WordPress’s expected format (e.g. es_ES): %s. They were saved as entered — please correct them.', 'wp-ai-translate' ),
					implode( ', ', $invalid_locales )
				),
				'warning'
			);
		}

		// --- Reconcile terms (creates/updates terms, blocks unsafe deletes). ---
		// register_setting's sanitize callback can fire more than once per
		// request; reconcile() is idempotent, but guard the admin notices so
		// they are not queued twice.
		$result                = $this->languages->reconcile( $languages, $old['languages'] );
		$settings['languages'] = $result['languages'];
		if ( ! self::$reconcile_notified ) {
			$this->add_reconcile_notices( $result['report'] );
			self::$reconcile_notified = true;
		}

		// --- Default language (must be one of the configured codes). ---
		$codes   = wp_list_pluck( $settings['languages'], 'code' );
		$default = isset( $input['default_language'] ) ? sanitize_key( $input['default_language'] ) : '';
		$settings['default_language'] = in_array( $default, $codes, true ) ? $default : ( $codes[0] ?? '' );

		// --- Preferred AI model (a typed selection; '' = connector default). Kept as
		// entered so a valid pinned id isn't lost if it's absent from a stale list. ---
		$settings['model'] = isset( $input['model'] ) ? sanitize_text_field( wp_unslash( $input['model'] ) ) : '';

		// --- Instructions ---
		$instructions = isset( $input['instructions'] ) && is_array( $input['instructions'] ) ? $input['instructions'] : array();
		$settings['instructions']['global'] = isset( $instructions['global'] ) ? sanitize_textarea_field( $instructions['global'] ) : '';
		$settings['instructions']['post']   = isset( $instructions['post'] ) ? sanitize_textarea_field( $instructions['post'] ) : '';
		$settings['instructions']['term']   = isset( $instructions['term'] ) ? sanitize_textarea_field( $instructions['term'] ) : '';

		$per_language = array();
		if ( isset( $instructions['per_language'] ) && is_array( $instructions['per_language'] ) ) {
			foreach ( $instructions['per_language'] as $code => $text ) {
				$code = sanitize_key( $code );
				if ( in_array( $code, $codes, true ) ) {
					$per_language[ $code ] = sanitize_textarea_field( $text );
				}
			}
		}
		$settings['instructions']['per_language'] = $per_language;

		return $settings;
	}

	/**
	 * Turns the reconcile report into admin notices.
	 *
	 * @param array<string,string[]> $report Reconcile report.
	 * @return void
	 */
	private function add_reconcile_notices( array $report ): void {
		$map = array(
			/* translators: %s: comma-separated list of language names. */
			'added'    => __( 'Added languages: %s', 'wp-ai-translate' ),
			/* translators: %s: comma-separated list of language names. */
			'updated'  => __( 'Updated languages: %s', 'wp-ai-translate' ),
			/* translators: %s: comma-separated list of language names. */
			'disabled' => __( 'Disabled languages: %s', 'wp-ai-translate' ),
			/* translators: %s: comma-separated list of language names. */
			'removed'  => __( 'Removed languages: %s', 'wp-ai-translate' ),
		);
		foreach ( $map as $key => $template ) {
			if ( ! empty( $report[ $key ] ) ) {
				add_settings_error(
					self::OPTION,
					'wpait_' . $key,
					sprintf( $template, implode( ', ', $report[ $key ] ) ),
					'updated'
				);
			}
		}

		if ( ! empty( $report['blocked'] ) ) {
			add_settings_error(
				self::OPTION,
				'wpait_blocked',
				sprintf(
					/* translators: %s: comma-separated language codes. */
					__( 'These languages still have content and were kept (disabled) instead of being deleted: %s. Reassign or delete their content first to remove them.', 'wp-ai-translate' ),
					implode( ', ', $report['blocked'] )
				),
				'warning'
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------- */

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings        = self::get_settings();
		$languages       = $settings['languages'];
		$instr_defaults  = self::instruction_defaults();
		$option          = self::OPTION;

		// Existing languages only; new rows are appended on demand via the
		// "Add language" button (no always-present blank rows — see #17).
		$rows = $languages;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Translate', 'wp-ai-translate' ); ?></h1>
			<?php
			// This page is served by wp-admin/options-general.php, whose
			// options-head.php already calls settings_errors(); calling it again here
			// would render every notice twice, so we intentionally do not.

			$ai_supported = function_exists( 'wp_supports_ai' ) && wp_supports_ai();
			$ai_usable    = Wpait_Translator::can_generate_text();
			if ( $ai_usable ) {
				$badge_class = 'wpait-ai-ok';
				$badge_text  = __( 'Available', 'wp-ai-translate' );
				$status_line = __( 'An AI provider with a text-generation model is available. Translations can be generated.', 'wp-ai-translate' );
			} elseif ( $ai_supported ) {
				$badge_class = 'wpait-ai-warn';
				$badge_text  = __( 'No usable model', 'wp-ai-translate' );
				$status_line = __( 'An AI provider is present, but no text-generation model is available, so translations will fail. Configure a text-generation model for this site’s AI provider.', 'wp-ai-translate' );
			} else {
				$badge_class = 'wpait-ai-bad';
				$badge_text  = __( 'Not configured', 'wp-ai-translate' );
				$status_line = __( 'No AI provider is configured for this site, so translations cannot be generated.', 'wp-ai-translate' );
			}
			?>
			<h2><?php esc_html_e( 'AI provider', 'wp-ai-translate' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'wp-ai-translate' ); ?></th>
					<td>
						<span class="wpait-ai-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span>
						<p class="description" style="margin-top:6px"><?php echo esc_html( $status_line ); ?></p>
						<p>
							<button type="button" class="button" id="wpait-test-connection"><?php esc_html_e( 'Test connection', 'wp-ai-translate' ); ?></button>
							<span id="wpait-test-result" role="status" aria-live="polite" style="margin-left:8px"></span>
						</p>
						<p class="description">
							<?php esc_html_e( 'Choose the model below. AI providers themselves are configured for the site (WordPress 7.0 AI), not in this plugin.', 'wp-ai-translate' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<style>
				.wpait-ai-badge { display:inline-block; padding:2px 10px; border-radius:10px; font-weight:600; color:#fff; }
				.wpait-ai-ok { background:#198754; }
				.wpait-ai-warn { background:#b88600; }
				.wpait-ai-bad { background:#b32d2e; }
				#wpait-test-result.is-ok { color:#198754; }
				#wpait-test-result.is-bad { color:#b32d2e; }
			</style>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpait_settings_group' ); ?>

				<h2><?php esc_html_e( 'Languages', 'wp-ai-translate' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'A language code is permanent once content uses it; you can rename a language but not change its code. Languages with content cannot be deleted — disable them instead.', 'wp-ai-translate' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Code: a short lowercase code (e.g. fr). Locale: the WordPress locale (e.g. fr_FR). Name: the display name (e.g. French). Native name: the language’s own name (e.g. Français). Flag: an optional emoji shown beside the name — leave blank to show the name only.', 'wp-ai-translate' ); ?>
				</p>
				<datalist id="wpait-locales">
					<?php foreach ( self::common_locales() as $loc ) : ?>
						<option value="<?php echo esc_attr( $loc ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<table class="widefat striped" style="max-width:980px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Code', 'wp-ai-translate' ); ?></th>
							<th><?php esc_html_e( 'Locale', 'wp-ai-translate' ); ?></th>
							<th><?php esc_html_e( 'Name', 'wp-ai-translate' ); ?></th>
							<th><?php esc_html_e( 'Native name', 'wp-ai-translate' ); ?></th>
							<th><?php esc_html_e( 'Flag', 'wp-ai-translate' ); ?></th>
							<th><?php esc_html_e( 'Enabled', 'wp-ai-translate' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wp-ai-translate' ); ?></span></th>
						</tr>
					</thead>
					<tbody id="wpait-languages-rows">
						<?php foreach ( $rows as $index => $row ) : ?>
							<?php
							$existing = '' !== $row['code'];
							$in_use   = $existing && $this->languages->language_has_content( $row['code'] );
							$base     = $option . '[languages][' . (int) $index . ']';
							?>
							<tr class="wpait-language-row">
								<td>
									<input type="text" name="<?php echo esc_attr( $base . '[code]' ); ?>"
										value="<?php echo esc_attr( $row['code'] ); ?>"
										readonly
										size="6" />
									<?php if ( $in_use ) : ?>
										<span class="dashicons dashicons-lock" title="<?php esc_attr_e( 'In use — code locked', 'wp-ai-translate' ); ?>"></span>
									<?php endif; ?>
								</td>
								<td><input type="text" name="<?php echo esc_attr( $base . '[locale]' ); ?>" value="<?php echo esc_attr( $row['locale'] ); ?>" placeholder="es_ES" size="8" list="wpait-locales" pattern="[a-z]{2,3}(_[A-Z]{2,3})?" title="<?php esc_attr_e( 'WordPress locale, e.g. es_ES or pt_BR (lowercase language, underscore, uppercase region).', 'wp-ai-translate' ); ?>" /></td>
								<td><input type="text" name="<?php echo esc_attr( $base . '[name]' ); ?>" value="<?php echo esc_attr( $row['name'] ); ?>" placeholder="Spanish" /></td>
								<td><input type="text" name="<?php echo esc_attr( $base . '[native]' ); ?>" value="<?php echo esc_attr( $row['native'] ); ?>" placeholder="Español" /></td>
								<td><input type="text" name="<?php echo esc_attr( $base . '[flag]' ); ?>" value="<?php echo esc_attr( $row['flag'] ); ?>" size="3" placeholder="🇦🇷" title="<?php esc_attr_e( 'Optional emoji flag shown next to the name. Leave blank to show the name only.', 'wp-ai-translate' ); ?>" /></td>
								<td><input type="checkbox" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> /></td>
								<td>
									<?php if ( $in_use ) : ?>
										<span class="description" title="<?php esc_attr_e( 'This language has content and cannot be removed. Disable it instead, or reassign/delete its content first.', 'wp-ai-translate' ); ?>"><?php esc_html_e( 'In use', 'wp-ai-translate' ); ?></span>
									<?php else : ?>
										<button type="button" class="button-link wpait-remove-language" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: language code. */ __( 'Remove language %s', 'wp-ai-translate' ), $row['code'] ) ); ?>"><?php esc_html_e( 'Remove', 'wp-ai-translate' ); ?></button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						<tr class="wpait-no-languages" <?php echo empty( $rows ) ? '' : 'style="display:none"'; ?>>
							<td colspan="7"><?php esc_html_e( 'No languages configured yet. Use “Add language” to create one.', 'wp-ai-translate' ); ?></td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="button" class="button" id="wpait-add-language">
						<span class="dashicons dashicons-plus" style="vertical-align:text-bottom"></span>
						<?php esc_html_e( 'Add language', 'wp-ai-translate' ); ?>
					</button>
				</p>

				<?php // Template for a new, editable language row (cloned by JS on "Add language"). ?>
				<template id="wpait-language-row-template">
					<tr class="wpait-language-row wpait-new-language-row">
						<td><input type="text" name="<?php echo esc_attr( $option ); ?>[languages][__INDEX__][code]" value="" placeholder="<?php esc_attr_e( 'e.g. fr', 'wp-ai-translate' ); ?>" size="6" /></td>
						<td><input type="text" name="<?php echo esc_attr( $option ); ?>[languages][__INDEX__][locale]" value="" placeholder="<?php esc_attr_e( 'e.g. fr_FR', 'wp-ai-translate' ); ?>" size="8" list="wpait-locales" pattern="[a-z]{2,3}(_[A-Z]{2,3})?" title="<?php esc_attr_e( 'WordPress locale, e.g. es_ES or pt_BR (lowercase language, underscore, uppercase region).', 'wp-ai-translate' ); ?>" /></td>
						<td><input type="text" name="<?php echo esc_attr( $option ); ?>[languages][__INDEX__][name]" value="" placeholder="<?php esc_attr_e( 'e.g. French', 'wp-ai-translate' ); ?>" /></td>
						<td><input type="text" name="<?php echo esc_attr( $option ); ?>[languages][__INDEX__][native]" value="" placeholder="<?php esc_attr_e( 'e.g. Français', 'wp-ai-translate' ); ?>" /></td>
						<td><input type="text" name="<?php echo esc_attr( $option ); ?>[languages][__INDEX__][flag]" value="" size="3" placeholder="🇫🇷" title="<?php esc_attr_e( 'Optional emoji flag shown next to the name. Leave blank to show the name only.', 'wp-ai-translate' ); ?>" /></td>
						<td><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[languages][__INDEX__][enabled]" value="1" checked /></td>
						<td><button type="button" class="button-link wpait-remove-language" aria-label="<?php esc_attr_e( 'Remove this new language', 'wp-ai-translate' ); ?>"><?php esc_html_e( 'Remove', 'wp-ai-translate' ); ?></button></td>
					</tr>
				</template>

				<h2><?php esc_html_e( 'Default language', 'wp-ai-translate' ); ?></h2>
				<select name="<?php echo esc_attr( $option . '[default_language]' ); ?>">
					<option value=""><?php esc_html_e( '— Select —', 'wp-ai-translate' ); ?></option>
					<?php foreach ( $languages as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $settings['default_language'], $lang['code'] ); ?>>
							<?php echo esc_html( $lang['name'] . ' (' . $lang['code'] . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<h2><?php esc_html_e( 'AI model', 'wp-ai-translate' ); ?></h2>
				<?php
				$current_model = (string) $settings['model'];
				$models        = $ai_usable ? Wpait_Translator::available_models() : array();
				// Group by provider, and make sure a previously-saved model still shows
				// as the selection even if it's absent from the (possibly cached) list.
				$grouped     = array();
				$model_ids   = array();
				foreach ( $models as $m ) {
					$grouped[ $m['provider'] ][] = $m;
					$model_ids[]                 = $m['id'];
				}
				?>
				<p class="description">
					<?php esc_html_e( 'Which model to use for translations. “Automatic” lets the provider choose — pick a specific model if the provider rejects the default (e.g. Anthropic’s “Claude Fable 5 is not available, use Opus 4.8”).', 'wp-ai-translate' ); ?>
				</p>
				<select name="<?php echo esc_attr( $option . '[model]' ); ?>">
					<option value=""><?php esc_html_e( 'Automatic (provider default)', 'wp-ai-translate' ); ?></option>
					<?php if ( '' !== $current_model && ! in_array( $current_model, $model_ids, true ) ) : ?>
						<option value="<?php echo esc_attr( $current_model ); ?>" selected>
							<?php
							/* translators: %s: model id. */
							echo esc_html( sprintf( __( '%s (currently set)', 'wp-ai-translate' ), $current_model ) );
							?>
						</option>
					<?php endif; ?>
					<?php foreach ( $grouped as $provider => $provider_models ) : ?>
						<optgroup label="<?php echo esc_attr( $provider ); ?>">
							<?php foreach ( $provider_models as $m ) : ?>
								<option value="<?php echo esc_attr( $m['id'] ); ?>" <?php selected( $current_model, $m['id'] ); ?>>
									<?php echo esc_html( $m['label'] . ' — ' . $m['id'] ); ?>
								</option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'If your selected model is unavailable to the account, translation automatically retries once with another advertised model rather than failing — so a translation may be produced by a different model than the one chosen here.', 'wp-ai-translate' ); ?>
				</p>
				<?php if ( $ai_usable && empty( $models ) ) : ?>
					<p class="description"><?php esc_html_e( 'Could not list models from the provider; “Automatic” will be used.', 'wp-ai-translate' ); ?></p>
				<?php elseif ( ! $ai_usable ) : ?>
					<p class="description"><?php esc_html_e( 'Connect an AI provider (see status above) to choose a model.', 'wp-ai-translate' ); ?></p>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Translation instructions', 'wp-ai-translate' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'These instructions shape the AI translation. Markup-safety rules are always applied automatically and cannot be overridden. Available placeholders:', 'wp-ai-translate' ); ?>
					<code>{source_lang}</code> <code>{target_lang}</code> <code>{site_name}</code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Global', 'wp-ai-translate' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" name="<?php echo esc_attr( $option . '[instructions][global]' ); ?>" data-wpait-default="<?php echo esc_attr( $instr_defaults['global'] ); ?>"><?php echo esc_textarea( $settings['instructions']['global'] ); ?></textarea>
							<p><a href="#" class="wpait-reset-instruction"><?php esc_html_e( 'Reset to default', 'wp-ai-translate' ); ?></a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Posts &amp; pages', 'wp-ai-translate' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option . '[instructions][post]' ); ?>" data-wpait-default="<?php echo esc_attr( $instr_defaults['post'] ); ?>"><?php echo esc_textarea( $settings['instructions']['post'] ); ?></textarea>
							<p><a href="#" class="wpait-reset-instruction"><?php esc_html_e( 'Reset to default', 'wp-ai-translate' ); ?></a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Categories &amp; tags', 'wp-ai-translate' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option . '[instructions][term]' ); ?>" data-wpait-default="<?php echo esc_attr( $instr_defaults['term'] ); ?>"><?php echo esc_textarea( $settings['instructions']['term'] ); ?></textarea>
							<p><a href="#" class="wpait-reset-instruction"><?php esc_html_e( 'Reset to default', 'wp-ai-translate' ); ?></a></p>
						</td>
					</tr>
				</table>

				<?php if ( ! empty( $languages ) ) : ?>
					<h3><?php esc_html_e( 'Per-language instructions', 'wp-ai-translate' ); ?></h3>
					<table class="form-table" role="presentation">
						<?php foreach ( $languages as $lang ) : ?>
							<tr>
								<th scope="row"><label><?php echo esc_html( $lang['name'] . ' (' . $lang['code'] . ')' ); ?></label></th>
								<td>
									<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option . '[instructions][per_language][' . $lang['code'] . ']' ); ?>"><?php echo esc_textarea( $settings['instructions']['per_language'][ $lang['code'] ] ?? '' ); ?></textarea>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<script>
		( function () {
			document.querySelectorAll( '.wpait-reset-instruction' ).forEach( function ( link ) {
				link.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var ta = this.closest( 'td' ).querySelector( 'textarea[data-wpait-default]' );
					if ( ta ) { ta.value = ta.getAttribute( 'data-wpait-default' ); }
				} );
			} );

			// Add / remove language rows. New rows get fresh, monotonically increasing
			// indices (never reused) so removing a row can't collide a later add. The
			// server skips rows with a blank code, and reconcile() removes any existing
			// language whose row is no longer submitted (unless it still has content).
			var tbody    = document.getElementById( 'wpait-languages-rows' );
			var template = document.getElementById( 'wpait-language-row-template' );
			var addBtn   = document.getElementById( 'wpait-add-language' );
			var nextIndex = <?php echo (int) count( $languages ); ?>;

			function refreshEmptyState() {
				var empty = tbody.querySelector( '.wpait-no-languages' );
				if ( ! empty ) { return; }
				var hasRows = !! tbody.querySelector( '.wpait-language-row' );
				empty.style.display = hasRows ? 'none' : '';
			}

			if ( addBtn && tbody && template && 'content' in template ) {
				addBtn.addEventListener( 'click', function () {
					var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex++ ) );
					var tmp = document.createElement( 'tbody' );
					tmp.innerHTML = html.trim();
					var row = tmp.firstElementChild;
					var empty = tbody.querySelector( '.wpait-no-languages' );
					if ( empty ) { tbody.insertBefore( row, empty ); } else { tbody.appendChild( row ); }
					var code = row.querySelector( 'input[type="text"]' );
					if ( code ) { code.focus(); }
					refreshEmptyState();
				} );
			}

			if ( tbody ) {
				tbody.addEventListener( 'click', function ( e ) {
					var btn = e.target.closest( '.wpait-remove-language' );
					if ( ! btn ) { return; }
					e.preventDefault();
					var row = btn.closest( '.wpait-language-row' );
					if ( row ) { row.parentNode.removeChild( row ); }
					refreshEmptyState();
				} );
			}

			// "Test connection": live AI round trip via the REST probe.
			var testBtn = document.getElementById( 'wpait-test-connection' );
			var testOut = document.getElementById( 'wpait-test-result' );
			var strings = <?php echo wp_json_encode(
				array(
					'testing' => __( 'Testing…', 'wp-ai-translate' ),
					'failed'  => __( 'Test failed.', 'wp-ai-translate' ),
					'noFetch' => __( 'Could not run the test in this browser.', 'wp-ai-translate' ),
				)
			); ?>;
			if ( testBtn && testOut ) {
				testBtn.addEventListener( 'click', function () {
					// Checked at click time: wp-api-fetch is enqueued in the footer, so
					// it is available by the time an admin can click, even though this
					// inline script runs earlier in the page body.
					if ( ! window.wp || ! wp.apiFetch ) {
						testOut.className = 'is-bad';
						testOut.textContent = strings.noFetch;
						return;
					}
					testBtn.disabled = true;
					testOut.className = '';
					testOut.textContent = strings.testing;
					wp.apiFetch( { path: '/<?php echo esc_js( Wpait_Rest::NS ); ?>/test-connection', method: 'POST' } )
						.then( function ( res ) {
							testOut.className = res && res.ok ? 'is-ok' : 'is-bad';
							testOut.textContent = ( res && res.message ) ? res.message : strings.failed;
						} )
						.catch( function ( e ) {
							testOut.className = 'is-bad';
							testOut.textContent = ( e && e.message ) ? e.message : strings.failed;
						} )
						.finally( function () { testBtn.disabled = false; } );
				} );
			}
		}() );
		</script>
		<?php
	}
}
