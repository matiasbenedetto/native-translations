<?php
/**
 * Settings page: Settings → AI Translate.
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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
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
	 * Adds the settings submenu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'AI Translate', 'wp-ai-translate' ),
			__( 'AI Translate', 'wp-ai-translate' ),
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
		$languages = array();
		$rows      = isset( $input['languages'] ) && is_array( $input['languages'] ) ? $input['languages'] : array();
		foreach ( $rows as $row ) {
			$code = isset( $row['code'] ) ? sanitize_key( $row['code'] ) : '';
			if ( '' === $code ) {
				continue;
			}
			$languages[ $code ] = array(
				'code'    => $code,
				'locale'  => isset( $row['locale'] ) ? sanitize_text_field( $row['locale'] ) : '',
				'name'    => isset( $row['name'] ) && '' !== trim( (string) $row['name'] )
					? sanitize_text_field( $row['name'] )
					: $code,
				'native'  => isset( $row['native'] ) ? sanitize_text_field( $row['native'] ) : '',
				'flag'    => isset( $row['flag'] ) ? sanitize_text_field( $row['flag'] ) : '',
				'enabled' => ! empty( $row['enabled'] ),
			);
		}
		$languages = array_values( $languages );

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
			'added'    => __( 'Added languages: %s', 'wp-ai-translate' ),
			'updated'  => __( 'Updated languages: %s', 'wp-ai-translate' ),
			'disabled' => __( 'Disabled languages: %s', 'wp-ai-translate' ),
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

		// Add blank rows for adding new languages.
		$rows = $languages;
		for ( $i = 0; $i < 2; $i++ ) {
			$rows[] = array(
				'code'    => '',
				'locale'  => '',
				'name'    => '',
				'native'  => '',
				'flag'    => '',
				'enabled' => true,
			);
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Translate', 'wp-ai-translate' ); ?></h1>
			<?php settings_errors( self::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'wpait_settings_group' ); ?>

				<h2><?php esc_html_e( 'Languages', 'wp-ai-translate' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'A language code is permanent once content uses it; you can rename a language but not change its code. Languages with content cannot be deleted — disable them instead.', 'wp-ai-translate' ); ?>
				</p>
				<table class="widefat striped" style="max-width:980px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Code', 'wp-ai-translate' ); ?></th>
							<th><?php esc_html_e( 'Locale', 'wp-ai-translate' ); ?></th>
							<th><?php esc_html_e( 'Name', 'wp-ai-translate' ); ?></th>
							<th><?php esc_html_e( 'Native name', 'wp-ai-translate' ); ?></th>
							<th><?php esc_html_e( 'Flag', 'wp-ai-translate' ); ?></th>
							<th><?php esc_html_e( 'Enabled', 'wp-ai-translate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $index => $row ) : ?>
							<?php
							$existing = '' !== $row['code'];
							$in_use   = $existing && $this->languages->language_has_content( $row['code'] );
							$base     = $option . '[languages][' . (int) $index . ']';
							?>
							<tr>
								<td>
									<input type="text" name="<?php echo esc_attr( $base . '[code]' ); ?>"
										value="<?php echo esc_attr( $row['code'] ); ?>"
										<?php echo $existing ? 'readonly' : ''; ?>
										placeholder="es" size="6" />
									<?php if ( $in_use ) : ?>
										<span class="dashicons dashicons-lock" title="<?php esc_attr_e( 'In use — code locked', 'wp-ai-translate' ); ?>"></span>
									<?php endif; ?>
								</td>
								<td><input type="text" name="<?php echo esc_attr( $base . '[locale]' ); ?>" value="<?php echo esc_attr( $row['locale'] ); ?>" placeholder="es_ES" size="8" /></td>
								<td><input type="text" name="<?php echo esc_attr( $base . '[name]' ); ?>" value="<?php echo esc_attr( $row['name'] ); ?>" placeholder="Spanish" /></td>
								<td><input type="text" name="<?php echo esc_attr( $base . '[native]' ); ?>" value="<?php echo esc_attr( $row['native'] ); ?>" placeholder="Español" /></td>
								<td><input type="text" name="<?php echo esc_attr( $base . '[flag]' ); ?>" value="<?php echo esc_attr( $row['flag'] ); ?>" size="3" /></td>
								<td><input type="checkbox" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Default language', 'wp-ai-translate' ); ?></h2>
				<select name="<?php echo esc_attr( $option . '[default_language]' ); ?>">
					<option value=""><?php esc_html_e( '— Select —', 'wp-ai-translate' ); ?></option>
					<?php foreach ( $languages as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $settings['default_language'], $lang['code'] ); ?>>
							<?php echo esc_html( $lang['name'] . ' (' . $lang['code'] . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>

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
		}() );
		</script>
		<?php
	}
}
