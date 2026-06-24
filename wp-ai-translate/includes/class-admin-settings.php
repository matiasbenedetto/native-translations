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
		wp_enqueue_style(
			'wpait-admin-settings',
			WPAIT_PLUGIN_URL . 'assets/css/admin-settings.css',
			array(),
			WPAIT_VERSION
		);
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
	 * Builds a locale-catalog entry. The generated `name` is intentionally regional
	 * so admin screens remain clear when a site enables more than one locale for the
	 * same language.
	 *
	 * @param string $locale   WordPress locale.
	 * @param string $language Translated language name.
	 * @param string $native   Native language/region label.
	 * @param string $region   Translated region name.
	 * @param string $flag     ISO 3166-1 alpha-2 region code for flag rendering.
	 * @return array{locale:string,code:string,language:string,region:string,name:string,native:string,flag:string}
	 */
	private static function locale_catalog_entry( string $locale, string $language, string $native, string $region, string $flag ): array {
		return array(
			'locale'   => $locale,
			'code'     => self::code_for_locale( $locale ),
			'language' => $language,
			'region'   => $region,
			'name'     => sprintf(
				/* translators: 1: language name, 2: region name. */
				__( '%1$s / %2$s', 'wp-ai-translate' ),
				$language,
				$region
			),
			'native'   => $native,
			'flag'     => $flag,
		);
	}

	/**
	 * Deterministic language code for newly added catalog locales.
	 *
	 * @param string $locale WordPress locale.
	 * @return string Language code.
	 */
	public static function code_for_locale( string $locale ): string {
		return sanitize_key( strtolower( str_replace( '_', '-', $locale ) ) );
	}

	/**
	 * Bundled catalog of supported locales. This is the source of truth for the
	 * Languages settings UI: admins choose a locale and the plugin derives the code,
	 * label, native name, and flag from this catalog.
	 *
	 * @return array<string,array{locale:string,code:string,language:string,region:string,name:string,native:string,flag:string}>
	 */
	public static function locale_catalog(): array {
		$entries = array(
			self::locale_catalog_entry( 'en_US', __( 'English', 'wp-ai-translate' ), 'English (United States)', __( 'United States', 'wp-ai-translate' ), 'us' ),
			self::locale_catalog_entry( 'en_GB', __( 'English', 'wp-ai-translate' ), 'English (United Kingdom)', __( 'United Kingdom', 'wp-ai-translate' ), 'gb' ),
			self::locale_catalog_entry( 'en_CA', __( 'English', 'wp-ai-translate' ), 'English (Canada)', __( 'Canada', 'wp-ai-translate' ), 'ca' ),
			self::locale_catalog_entry( 'en_AU', __( 'English', 'wp-ai-translate' ), 'English (Australia)', __( 'Australia', 'wp-ai-translate' ), 'au' ),
			self::locale_catalog_entry( 'es_ES', __( 'Spanish', 'wp-ai-translate' ), 'Español (España)', __( 'Spain', 'wp-ai-translate' ), 'es' ),
			self::locale_catalog_entry( 'es_AR', __( 'Spanish', 'wp-ai-translate' ), 'Español (Argentina)', __( 'Argentina', 'wp-ai-translate' ), 'ar' ),
			self::locale_catalog_entry( 'es_MX', __( 'Spanish', 'wp-ai-translate' ), 'Español (México)', __( 'Mexico', 'wp-ai-translate' ), 'mx' ),
			self::locale_catalog_entry( 'es_CO', __( 'Spanish', 'wp-ai-translate' ), 'Español (Colombia)', __( 'Colombia', 'wp-ai-translate' ), 'co' ),
			self::locale_catalog_entry( 'es_CL', __( 'Spanish', 'wp-ai-translate' ), 'Español (Chile)', __( 'Chile', 'wp-ai-translate' ), 'cl' ),
			self::locale_catalog_entry( 'es_US', __( 'Spanish', 'wp-ai-translate' ), 'Español (Estados Unidos)', __( 'United States', 'wp-ai-translate' ), 'us' ),
			self::locale_catalog_entry( 'fr_FR', __( 'French', 'wp-ai-translate' ), 'Français (France)', __( 'France', 'wp-ai-translate' ), 'fr' ),
			self::locale_catalog_entry( 'fr_CA', __( 'French', 'wp-ai-translate' ), 'Français (Canada)', __( 'Canada', 'wp-ai-translate' ), 'ca' ),
			self::locale_catalog_entry( 'fr_BE', __( 'French', 'wp-ai-translate' ), 'Français (Belgique)', __( 'Belgium', 'wp-ai-translate' ), 'be' ),
			self::locale_catalog_entry( 'fr_CH', __( 'French', 'wp-ai-translate' ), 'Français (Suisse)', __( 'Switzerland', 'wp-ai-translate' ), 'ch' ),
			self::locale_catalog_entry( 'de_DE', __( 'German', 'wp-ai-translate' ), 'Deutsch (Deutschland)', __( 'Germany', 'wp-ai-translate' ), 'de' ),
			self::locale_catalog_entry( 'de_AT', __( 'German', 'wp-ai-translate' ), 'Deutsch (Österreich)', __( 'Austria', 'wp-ai-translate' ), 'at' ),
			self::locale_catalog_entry( 'de_CH', __( 'German', 'wp-ai-translate' ), 'Deutsch (Schweiz)', __( 'Switzerland', 'wp-ai-translate' ), 'ch' ),
			self::locale_catalog_entry( 'pt_BR', __( 'Portuguese', 'wp-ai-translate' ), 'Português (Brasil)', __( 'Brazil', 'wp-ai-translate' ), 'br' ),
			self::locale_catalog_entry( 'pt_PT', __( 'Portuguese', 'wp-ai-translate' ), 'Português (Portugal)', __( 'Portugal', 'wp-ai-translate' ), 'pt' ),
			self::locale_catalog_entry( 'it_IT', __( 'Italian', 'wp-ai-translate' ), 'Italiano (Italia)', __( 'Italy', 'wp-ai-translate' ), 'it' ),
			self::locale_catalog_entry( 'nl_NL', __( 'Dutch', 'wp-ai-translate' ), 'Nederlands (Nederland)', __( 'Netherlands', 'wp-ai-translate' ), 'nl' ),
			self::locale_catalog_entry( 'pl_PL', __( 'Polish', 'wp-ai-translate' ), 'Polski (Polska)', __( 'Poland', 'wp-ai-translate' ), 'pl' ),
			self::locale_catalog_entry( 'ru_RU', __( 'Russian', 'wp-ai-translate' ), 'Русский (Россия)', __( 'Russia', 'wp-ai-translate' ), 'ru' ),
			self::locale_catalog_entry( 'uk', __( 'Ukrainian', 'wp-ai-translate' ), 'Українська (Україна)', __( 'Ukraine', 'wp-ai-translate' ), 'ua' ),
			self::locale_catalog_entry( 'sv_SE', __( 'Swedish', 'wp-ai-translate' ), 'Svenska (Sverige)', __( 'Sweden', 'wp-ai-translate' ), 'se' ),
			self::locale_catalog_entry( 'da_DK', __( 'Danish', 'wp-ai-translate' ), 'Dansk (Danmark)', __( 'Denmark', 'wp-ai-translate' ), 'dk' ),
			self::locale_catalog_entry( 'nb_NO', __( 'Norwegian', 'wp-ai-translate' ), 'Norsk bokmål (Norge)', __( 'Norway', 'wp-ai-translate' ), 'no' ),
			self::locale_catalog_entry( 'fi', __( 'Finnish', 'wp-ai-translate' ), 'Suomi (Suomi)', __( 'Finland', 'wp-ai-translate' ), 'fi' ),
			self::locale_catalog_entry( 'cs_CZ', __( 'Czech', 'wp-ai-translate' ), 'Čeština (Česko)', __( 'Czechia', 'wp-ai-translate' ), 'cz' ),
			self::locale_catalog_entry( 'el', __( 'Greek', 'wp-ai-translate' ), 'Ελληνικά (Ελλάδα)', __( 'Greece', 'wp-ai-translate' ), 'gr' ),
			self::locale_catalog_entry( 'tr_TR', __( 'Turkish', 'wp-ai-translate' ), 'Türkçe (Türkiye)', __( 'Turkey', 'wp-ai-translate' ), 'tr' ),
			self::locale_catalog_entry( 'ar', __( 'Arabic', 'wp-ai-translate' ), 'العربية (السعودية)', __( 'Saudi Arabia', 'wp-ai-translate' ), 'sa' ),
			self::locale_catalog_entry( 'he_IL', __( 'Hebrew', 'wp-ai-translate' ), 'עברית (ישראל)', __( 'Israel', 'wp-ai-translate' ), 'il' ),
			self::locale_catalog_entry( 'hi_IN', __( 'Hindi', 'wp-ai-translate' ), 'हिन्दी (भारत)', __( 'India', 'wp-ai-translate' ), 'in' ),
			self::locale_catalog_entry( 'id_ID', __( 'Indonesian', 'wp-ai-translate' ), 'Bahasa Indonesia (Indonesia)', __( 'Indonesia', 'wp-ai-translate' ), 'id' ),
			self::locale_catalog_entry( 'ja', __( 'Japanese', 'wp-ai-translate' ), '日本語 (日本)', __( 'Japan', 'wp-ai-translate' ), 'jp' ),
			self::locale_catalog_entry( 'ko_KR', __( 'Korean', 'wp-ai-translate' ), '한국어 (대한민국)', __( 'South Korea', 'wp-ai-translate' ), 'kr' ),
			self::locale_catalog_entry( 'th', __( 'Thai', 'wp-ai-translate' ), 'ไทย (ประเทศไทย)', __( 'Thailand', 'wp-ai-translate' ), 'th' ),
			self::locale_catalog_entry( 'vi', __( 'Vietnamese', 'wp-ai-translate' ), 'Tiếng Việt (Việt Nam)', __( 'Vietnam', 'wp-ai-translate' ), 'vn' ),
			self::locale_catalog_entry( 'zh_CN', __( 'Chinese', 'wp-ai-translate' ), '中文（中国大陆）', __( 'China', 'wp-ai-translate' ), 'cn' ),
			self::locale_catalog_entry( 'zh_TW', __( 'Chinese', 'wp-ai-translate' ), '中文（台灣）', __( 'Taiwan', 'wp-ai-translate' ), 'tw' ),
			self::locale_catalog_entry( 'zh_HK', __( 'Chinese', 'wp-ai-translate' ), '中文（香港）', __( 'Hong Kong', 'wp-ai-translate' ), 'hk' ),
		);

		$catalog = array();
		foreach ( $entries as $entry ) {
			$catalog[ $entry['locale'] ] = $entry;
		}
		return $catalog;
	}

	/**
	 * Looks up a locale catalog entry.
	 *
	 * @param string $locale WordPress locale.
	 * @return array{locale:string,code:string,language:string,region:string,name:string,native:string,flag:string}|null
	 */
	public static function catalog_entry_for_locale( string $locale ): ?array {
		$catalog = self::locale_catalog();
		return $catalog[ $locale ] ?? null;
	}

	/**
	 * Compatibility view of locale_catalog(), keyed by generated language code.
	 *
	 * @return array<string,array{locale:string,name:string,native:string,flag:string}>
	 */
	public static function default_catalog(): array {
		$catalog = array();
		foreach ( self::locale_catalog() as $entry ) {
			$catalog[ $entry['code'] ] = array(
				'locale' => $entry['locale'],
				'name'   => $entry['name'],
				'native' => $entry['native'],
				'flag'   => $entry['flag'],
			);
		}
		return $catalog;
	}

	/**
	 * WordPress locales offered by the locale picker.
	 *
	 * @return string[]
	 */
	public static function common_locales(): array {
		return array_keys( self::locale_catalog() );
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
	 * Normalizes a stored language row without changing its code or labels. Used for
	 * existing settings rows so saving the new UI does not rename or migrate terms.
	 *
	 * @param array<string,mixed> $row Raw or stored language row.
	 * @return array{code:string,locale:string,name:string,native:string,flag:string,enabled:bool}
	 */
	private static function normalize_language_row( array $row ): array {
		$code = isset( $row['code'] ) ? sanitize_key( $row['code'] ) : '';
		$flag = isset( $row['flag'] ) ? sanitize_text_field( $row['flag'] ) : '';
		if ( 1 === preg_match( '/^[A-Za-z]{2}$/', $flag ) ) {
			$flag = strtolower( $flag );
		}
		if ( function_exists( 'mb_substr' ) ) {
			$flag = mb_substr( $flag, 0, 12 );
		}

		return array(
			'code'    => $code,
			'locale'  => isset( $row['locale'] ) ? sanitize_text_field( $row['locale'] ) : '',
			'name'    => isset( $row['name'] ) && '' !== trim( (string) $row['name'] )
				? sanitize_text_field( $row['name'] )
				: $code,
			'native'  => isset( $row['native'] ) ? sanitize_text_field( $row['native'] ) : '',
			'flag'    => $flag,
			'enabled' => ! empty( $row['enabled'] ),
		);
	}

	/**
	 * Converts a locale catalog entry into the stored settings row shape.
	 *
	 * @param array<string,string> $entry Locale catalog entry.
	 * @return array{code:string,locale:string,name:string,native:string,flag:string,enabled:bool}
	 */
	private static function language_from_catalog_entry( array $entry ): array {
		return array(
			'code'    => $entry['code'],
			'locale'  => $entry['locale'],
			'name'    => $entry['name'],
			'native'  => $entry['native'],
			'flag'    => $entry['flag'],
			'enabled' => true,
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
		$languages       = array();
		$invalid_locales = array();
		$old_by_code     = array();
		foreach ( $old['languages'] as $old_row ) {
			if ( ! is_array( $old_row ) ) {
				continue;
			}
			$normalized = self::normalize_language_row( $old_row );
			if ( '' !== $normalized['code'] ) {
				$old_by_code[ $normalized['code'] ] = $normalized;
			}
		}
		$seen_locales = array();
		$rows         = isset( $input['languages'] ) && is_array( $input['languages'] ) ? $input['languages'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$code   = isset( $row['code'] ) ? sanitize_key( $row['code'] ) : '';
			$locale = isset( $row['locale'] ) ? sanitize_text_field( $row['locale'] ) : '';

			if ( '' !== $code && isset( $old_by_code[ $code ] ) ) {
				$language            = $old_by_code[ $code ];
				$language['enabled'] = ! empty( $row['enabled'] );
			} else {
				$entry = '' !== $locale ? self::catalog_entry_for_locale( $locale ) : null;
				if ( $entry ) {
					$language = self::language_from_catalog_entry( $entry );
				} elseif ( '' !== $code ) {
					$language = self::normalize_language_row( $row );
					if ( ! self::is_valid_locale( $language['locale'] ) ) {
						$invalid_locales[] = $code;
					}
				} else {
					continue;
				}
			}

			if ( '' !== $language['locale'] ) {
				if ( isset( $seen_locales[ $language['locale'] ] ) ) {
					continue;
				}
				$seen_locales[ $language['locale'] ] = true;
			}

			$languages[ $language['code'] ] = $language;
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
			$locale_catalog  = self::locale_catalog();

			// Existing languages only; new rows are appended on demand via the
			// "Add language" button (no always-present blank rows — see #17).
			$rows               = $languages;
			$configured_locales = array();
			foreach ( $languages as $lang ) {
				if ( ! empty( $lang['locale'] ) ) {
					$configured_locales[ (string) $lang['locale'] ] = true;
				}
			}
			$available_locales = array_filter(
				$locale_catalog,
				static function ( $entry ) use ( $configured_locales ) {
					return empty( $configured_locales[ $entry['locale'] ] );
				}
			);
		?>
		<div class="wrap wpait-settings">
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
			<div class="wpait-card">
				<h2><?php esc_html_e( 'AI provider', 'wp-ai-translate' ); ?></h2>
				<p>
					<span class="wpait-ai-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span>
				</p>
				<p class="description"><?php echo esc_html( $status_line ); ?></p>
				<p>
					<button type="button" class="button" id="wpait-test-connection"><?php esc_html_e( 'Test connection', 'wp-ai-translate' ); ?></button>
					<span id="wpait-test-result" role="status" aria-live="polite" style="margin-left:8px"></span>
				</p>
				<p class="description">
					<?php esc_html_e( 'Choose the model below. AI providers themselves are configured for the site (WordPress 7.0 AI), not in this plugin.', 'wp-ai-translate' ); ?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpait_settings_group' ); ?>

				<div class="wpait-card">
					<h2><?php esc_html_e( 'Languages', 'wp-ai-translate' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Add a language by choosing its locale. The plugin sets the code, display name, native name, and flag automatically. Languages with content cannot be deleted — disable them instead.', 'wp-ai-translate' ); ?>
					</p>
					<table class="widefat striped wpait-languages-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Language', 'wp-ai-translate' ); ?></th>
								<th><?php esc_html_e( 'Locale', 'wp-ai-translate' ); ?></th>
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
								$name     = (string) ( $row['name'] ?? $row['code'] );
								$native   = (string) ( $row['native'] ?? '' );
								$locale   = (string) ( $row['locale'] ?? '' );
								$flag     = (string) ( $row['flag'] ?? '' );
								?>
								<tr class="wpait-language-row" data-locale="<?php echo esc_attr( $locale ); ?>">
									<td>
										<input type="hidden" name="<?php echo esc_attr( $base . '[code]' ); ?>" value="<?php echo esc_attr( $row['code'] ); ?>" />
										<span class="wpait-language-summary">
											<span class="wpait-language-flag" aria-hidden="true"><?php echo Wpait_Languages::flag_html( $flag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_html() returns escaped HTML. ?></span>
											<span class="wpait-language-text">
												<strong><?php echo esc_html( $name ); ?></strong>
												<?php if ( '' !== $native && $native !== $name ) : ?>
													<span class="wpait-language-native"><?php echo esc_html( $native ); ?></span>
												<?php endif; ?>
											</span>
										</span>
									</td>
									<td><code><?php echo '' !== $locale ? esc_html( $locale ) : '—'; ?></code></td>
									<td>
										<label class="wpait-enabled-toggle">
											<input type="checkbox" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> />
											<?php esc_html_e( 'Enabled', 'wp-ai-translate' ); ?>
										</label>
									</td>
									<td>
										<?php if ( $in_use ) : ?>
											<span class="description" title="<?php esc_attr_e( 'This language has content and cannot be removed. Disable it instead, or reassign/delete its content first.', 'wp-ai-translate' ); ?>"><?php esc_html_e( 'In use', 'wp-ai-translate' ); ?></span>
										<?php else : ?>
											<button type="button" class="button-link wpait-remove-language" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: language name. */ __( 'Remove %s', 'wp-ai-translate' ), $name ) ); ?>"><?php esc_html_e( 'Remove', 'wp-ai-translate' ); ?></button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr class="wpait-no-languages" <?php echo empty( $rows ) ? '' : 'style="display:none"'; ?>>
								<td colspan="4"><?php esc_html_e( 'No languages configured yet. Use “Add language” to choose a locale.', 'wp-ai-translate' ); ?></td>
							</tr>
						</tbody>
					</table>
					<p>
						<button type="button" class="button" id="wpait-add-language" <?php disabled( empty( $available_locales ) ); ?>>
							<span class="dashicons dashicons-plus" style="vertical-align:text-bottom"></span>
							<?php esc_html_e( 'Add language', 'wp-ai-translate' ); ?>
						</button>
					</p>
					<?php if ( empty( $available_locales ) ) : ?>
						<p class="description"><?php esc_html_e( 'All bundled locales are already configured.', 'wp-ai-translate' ); ?></p>
					<?php else : ?>
						<div class="wpait-add-language-panel" id="wpait-add-language-panel" hidden>
							<label class="wpait-add-language-label"><?php esc_html_e( 'Locale', 'wp-ai-translate' ); ?></label>
							<div class="wpait-locale-picker" data-selected-locale="">
								<button type="button" class="button wpait-locale-picker-toggle" id="wpait-locale-picker-toggle" aria-expanded="false" aria-haspopup="listbox">
									<span class="wpait-locale-picker-selected"><?php esc_html_e( 'Select a locale', 'wp-ai-translate' ); ?></span>
								</button>
								<div class="wpait-locale-picker-menu" id="wpait-locale-picker-menu" hidden>
									<input type="search" class="wpait-locale-search" placeholder="<?php esc_attr_e( 'Search locales', 'wp-ai-translate' ); ?>" aria-label="<?php esc_attr_e( 'Search locales', 'wp-ai-translate' ); ?>" />
									<div class="wpait-locale-options" role="listbox" aria-label="<?php esc_attr_e( 'Available locales', 'wp-ai-translate' ); ?>">
										<?php foreach ( $available_locales as $entry ) : ?>
											<button type="button" class="wpait-locale-option" role="option"
												data-locale="<?php echo esc_attr( $entry['locale'] ); ?>"
												data-code="<?php echo esc_attr( $entry['code'] ); ?>"
												data-name="<?php echo esc_attr( $entry['name'] ); ?>"
												data-native="<?php echo esc_attr( $entry['native'] ); ?>"
												data-flag="<?php echo esc_attr( $entry['flag'] ); ?>">
												<span class="wpait-language-flag" aria-hidden="true"><?php echo Wpait_Languages::flag_html( $entry['flag'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_html() returns escaped HTML. ?></span>
												<span class="wpait-locale-option-text">
													<strong><?php echo esc_html( $entry['name'] ); ?></strong>
													<code><?php echo esc_html( $entry['locale'] ); ?></code>
												</span>
											</button>
										<?php endforeach; ?>
										<p class="wpait-no-locale-options" hidden><?php esc_html_e( 'No matching locales.', 'wp-ai-translate' ); ?></p>
									</div>
								</div>
							</div>
							<p class="wpait-add-language-actions">
								<button type="button" class="button button-primary" id="wpait-confirm-add-language" disabled><?php esc_html_e( 'Add selected locale', 'wp-ai-translate' ); ?></button>
								<button type="button" class="button-link" id="wpait-cancel-add-language"><?php esc_html_e( 'Cancel', 'wp-ai-translate' ); ?></button>
							</p>
						</div>
					<?php endif; ?>
					</div>

				<?php $this->render_language_packs_card( $languages ); ?>

				<div class="wpait-card">
				<h2><?php esc_html_e( 'Default language', 'wp-ai-translate' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The site’s primary language — used as the source when no other is given.', 'wp-ai-translate' ); ?></p>
				<select name="<?php echo esc_attr( $option . '[default_language]' ); ?>">
					<option value=""><?php esc_html_e( '— Select —', 'wp-ai-translate' ); ?></option>
					<?php foreach ( $languages as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $settings['default_language'], $lang['code'] ); ?>>
							<?php echo esc_html( $lang['name'] . ' (' . $lang['code'] . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				</div>

				<div class="wpait-card">
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
				</div>

				<div class="wpait-card">
				<h2><?php esc_html_e( 'Translation instructions', 'wp-ai-translate' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'These instructions shape the AI translation. Markup-safety rules are always applied automatically and cannot be overridden. Available placeholders:', 'wp-ai-translate' ); ?>
					<code>{source_lang}</code> <code>{target_lang}</code> <code>{site_name}</code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Global', 'wp-ai-translate' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" name="<?php echo esc_attr( $option . '[instructions][global]' ); ?>" placeholder="<?php echo esc_attr( $instr_defaults['global'] ); ?>" data-wpait-default="<?php echo esc_attr( $instr_defaults['global'] ); ?>"><?php echo esc_textarea( $settings['instructions']['global'] ); ?></textarea>
							<p><a href="#" class="wpait-reset-instruction"><?php esc_html_e( 'Reset to default', 'wp-ai-translate' ); ?></a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Posts &amp; pages', 'wp-ai-translate' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option . '[instructions][post]' ); ?>" placeholder="<?php echo esc_attr( $instr_defaults['post'] ); ?>" data-wpait-default="<?php echo esc_attr( $instr_defaults['post'] ); ?>"><?php echo esc_textarea( $settings['instructions']['post'] ); ?></textarea>
							<p><a href="#" class="wpait-reset-instruction"><?php esc_html_e( 'Reset to default', 'wp-ai-translate' ); ?></a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Categories &amp; tags', 'wp-ai-translate' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option . '[instructions][term]' ); ?>" placeholder="<?php echo esc_attr( $instr_defaults['term'] ); ?>" data-wpait-default="<?php echo esc_attr( $instr_defaults['term'] ); ?>"><?php echo esc_textarea( $settings['instructions']['term'] ); ?></textarea>
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
									<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option . '[instructions][per_language][' . $lang['code'] . ']' ); ?>" placeholder="<?php esc_attr_e( 'Optional — adds to the global and content-type instructions for this language.', 'wp-ai-translate' ); ?>"><?php echo esc_textarea( $settings['instructions']['per_language'][ $lang['code'] ] ?? '' ); ?></textarea>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>
				</div>

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

				// Add / remove language rows. New rows submit only a catalog locale; the
				// server derives the code, labels, and flag from its own locale catalog.
				var tbody      = document.getElementById( 'wpait-languages-rows' );
				var addBtn     = document.getElementById( 'wpait-add-language' );
				var panel      = document.getElementById( 'wpait-add-language-panel' );
				var picker     = panel ? panel.querySelector( '.wpait-locale-picker' ) : null;
				var toggle     = document.getElementById( 'wpait-locale-picker-toggle' );
				var menu       = document.getElementById( 'wpait-locale-picker-menu' );
				var selectedEl = panel ? panel.querySelector( '.wpait-locale-picker-selected' ) : null;
				var search     = panel ? panel.querySelector( '.wpait-locale-search' ) : null;
				var confirmBtn = document.getElementById( 'wpait-confirm-add-language' );
				var cancelBtn  = document.getElementById( 'wpait-cancel-add-language' );
				var nextIndex  = <?php echo (int) count( $languages ); ?>;
				var optionName = <?php echo wp_json_encode( $option ); ?>;
				var languageStrings = <?php echo wp_json_encode(
					array(
						'selectLocale' => __( 'Select a locale', 'wp-ai-translate' ),
						'enabled'      => __( 'Enabled', 'wp-ai-translate' ),
						'remove'       => __( 'Remove', 'wp-ai-translate' ),
					)
				); ?>;
				var selectedLocale = null;

				function refreshEmptyState() {
					if ( ! tbody ) { return; }
					var empty = tbody.querySelector( '.wpait-no-languages' );
					if ( ! empty ) { return; }
					var hasRows = !! tbody.querySelector( '.wpait-language-row' );
					empty.style.display = hasRows ? 'none' : '';
				}

				function flagNode( flag ) {
					var wrap = document.createElement( 'span' );
					wrap.className = 'wpait-language-flag';
					wrap.setAttribute( 'aria-hidden', 'true' );
					if ( /^[a-z]{2}$/.test( flag ) ) {
						var img = document.createElement( 'img' );
						img.className = 'wpait-flag-img';
						img.src = 'https://flagcdn.com/' + flag + '.svg';
						img.alt = '';
						img.loading = 'lazy';
						img.decoding = 'async';
						wrap.appendChild( img );
					} else if ( flag ) {
						var span = document.createElement( 'span' );
						span.className = 'wpait-flag-emoji';
						span.textContent = flag;
						wrap.appendChild( span );
					}
					return wrap;
				}

				function hiddenInput( name, value ) {
					var input = document.createElement( 'input' );
					input.type = 'hidden';
					input.name = name;
					input.value = value;
					return input;
				}

				function optionData( option ) {
					return {
						locale: option.getAttribute( 'data-locale' ) || '',
						code: option.getAttribute( 'data-code' ) || '',
						name: option.getAttribute( 'data-name' ) || '',
						native: option.getAttribute( 'data-native' ) || '',
						flag: option.getAttribute( 'data-flag' ) || ''
					};
				}

				function setSelectedLocale( data ) {
					selectedLocale = data;
					if ( picker ) {
						picker.setAttribute( 'data-selected-locale', data.locale );
					}
					if ( selectedEl ) {
						var label = document.createElement( 'span' );
						label.className = 'wpait-locale-selected-text';
						label.textContent = data.name + ' (' + data.locale + ')';
						selectedEl.replaceChildren( flagNode( data.flag ), label );
					}
					if ( confirmBtn ) {
						confirmBtn.disabled = ! data.locale;
					}
				}

				function resetPicker() {
					selectedLocale = null;
					if ( picker ) {
						picker.setAttribute( 'data-selected-locale', '' );
					}
					if ( selectedEl ) {
						selectedEl.textContent = languageStrings.selectLocale;
					}
					if ( confirmBtn ) {
						confirmBtn.disabled = true;
					}
					if ( search ) {
						search.value = '';
						filterOptions();
					}
				}

				function openMenu() {
					if ( ! menu || ! toggle ) { return; }
					menu.hidden = false;
					toggle.setAttribute( 'aria-expanded', 'true' );
					if ( search ) {
						search.focus();
					}
				}

				function closeMenu() {
					if ( ! menu || ! toggle ) { return; }
					menu.hidden = true;
					toggle.setAttribute( 'aria-expanded', 'false' );
				}

				function filterOptions() {
					if ( ! panel ) { return; }
					var q = search ? search.value.trim().toLowerCase() : '';
					var shown = 0;
					panel.querySelectorAll( '.wpait-locale-option' ).forEach( function ( option ) {
						var match = ! q || option.textContent.toLowerCase().indexOf( q ) !== -1;
						option.hidden = option.disabled || ! match;
						if ( ! option.hidden ) {
							shown++;
						}
					} );
					var empty = panel.querySelector( '.wpait-no-locale-options' );
					if ( empty ) {
						empty.hidden = shown > 0;
					}
				}

				function addLanguageRow( data ) {
					var index = String( nextIndex++ );
					var base = optionName + '[languages][' + index + ']';
					var row = document.createElement( 'tr' );
					row.className = 'wpait-language-row wpait-new-language-row';
					row.setAttribute( 'data-locale', data.locale );
					row.setAttribute( 'data-pending', '1' );

					var languageCell = document.createElement( 'td' );
					var summary = document.createElement( 'span' );
					summary.className = 'wpait-language-summary';
					var text = document.createElement( 'span' );
					text.className = 'wpait-language-text';
					var name = document.createElement( 'strong' );
					name.textContent = data.name;
					var native = document.createElement( 'span' );
					native.className = 'wpait-language-native';
					native.textContent = data.native;
					text.append( name, native );
					summary.append( flagNode( data.flag ), text );
					languageCell.append(
						hiddenInput( base + '[locale]', data.locale ),
						hiddenInput( base + '[enabled]', '1' ),
						summary
					);

					var localeCell = document.createElement( 'td' );
					var localeCode = document.createElement( 'code' );
					localeCode.textContent = data.locale;
					localeCell.appendChild( localeCode );

					var enabledCell = document.createElement( 'td' );
					enabledCell.textContent = languageStrings.enabled;

					var actionCell = document.createElement( 'td' );
					var remove = document.createElement( 'button' );
					remove.type = 'button';
					remove.className = 'button-link wpait-remove-language';
					remove.textContent = languageStrings.remove;
					remove.setAttribute( 'aria-label', languageStrings.remove + ' ' + data.name );
					actionCell.appendChild( remove );

					row.append( languageCell, localeCell, enabledCell, actionCell );
					var empty = tbody.querySelector( '.wpait-no-languages' );
					if ( empty ) {
						tbody.insertBefore( row, empty );
					} else {
						tbody.appendChild( row );
					}
					refreshEmptyState();
				}

				if ( addBtn && panel ) {
					addBtn.addEventListener( 'click', function () {
						panel.hidden = false;
						openMenu();
					} );
				}

				if ( cancelBtn && panel ) {
					cancelBtn.addEventListener( 'click', function () {
						panel.hidden = true;
						closeMenu();
						resetPicker();
					} );
				}

				if ( toggle ) {
					toggle.addEventListener( 'click', function () {
						if ( menu && menu.hidden ) {
							openMenu();
						} else {
							closeMenu();
						}
					} );
				}

				if ( search ) {
					search.addEventListener( 'input', filterOptions );
				}

				if ( panel ) {
					panel.addEventListener( 'click', function ( e ) {
						var option = e.target.closest( '.wpait-locale-option' );
						if ( ! option || option.disabled ) { return; }
						setSelectedLocale( optionData( option ) );
						closeMenu();
					} );
				}

				if ( confirmBtn ) {
					confirmBtn.addEventListener( 'click', function () {
						if ( ! selectedLocale || ! selectedLocale.locale ) { return; }
						addLanguageRow( selectedLocale );
						var option = panel.querySelector( '.wpait-locale-option[data-locale="' + selectedLocale.locale + '"]' );
						if ( option ) {
							option.disabled = true;
							option.hidden = true;
						}
						panel.hidden = true;
						closeMenu();
						resetPicker();
					} );
				}

				if ( tbody ) {
					tbody.addEventListener( 'click', function ( e ) {
						var btn = e.target.closest( '.wpait-remove-language' );
						if ( ! btn ) { return; }
						e.preventDefault();
						var row = btn.closest( '.wpait-language-row' );
						if ( ! row ) { return; }
						var locale = row.getAttribute( 'data-locale' );
						var pending = '1' === row.getAttribute( 'data-pending' );
						row.parentNode.removeChild( row );
						if ( pending && panel && locale ) {
							var option = panel.querySelector( '.wpait-locale-option[data-locale="' + locale + '"]' );
							if ( option ) {
								option.disabled = false;
							}
						}
						filterOptions();
						refreshEmptyState();
					} );
				}

				document.addEventListener( 'click', function ( e ) {
					if ( panel && ! panel.contains( e.target ) && e.target !== addBtn ) {
						closeMenu();
					}
				} );

				document.addEventListener( 'keydown', function ( e ) {
					if ( 'Escape' === e.key ) {
						closeMenu();
					}
				} );

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
		$this->render_language_packs_script();
	}

	/**
	 * Renders the "Language packs" card: for each configured language, its locale and
	 * core-language-pack install status, with an Install button for missing packs (#62).
	 *
	 * @param array<int,array<string,mixed>> $languages Configured language rows.
	 * @return void
	 */
	private function render_language_packs_card( array $languages ): void {
		?>
		<div class="wpait-card">
			<h2><?php esc_html_e( 'Language packs', 'wp-ai-translate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'WordPress needs a core language pack installed for a language so that dates and theme/core interface strings (e.g. “Previous”, “Next”, “Leave a Reply”) render in that language when a visitor views content in it.', 'wp-ai-translate' ); ?>
			</p>
			<table class="widefat striped wpait-language-packs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Language', 'wp-ai-translate' ); ?></th>
						<th><?php esc_html_e( 'Locale', 'wp-ai-translate' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-ai-translate' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wp-ai-translate' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $languages as $lang ) : ?>
						<?php
						$locale = (string) ( $lang['locale'] ?? '' );
						$status = Wpait_Languages::locale_pack_status( $locale );
						?>
							<tr class="wpait-language-pack-row" data-locale="<?php echo esc_attr( $locale ); ?>">
								<td><?php echo $this->languages->label_html( (string) ( $lang['code'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- label_html() returns escaped HTML. ?></td>
							<td><code><?php echo '' !== $locale ? esc_html( $locale ) : '—'; ?></code></td>
							<td class="wpait-pack-status">
								<?php
								if ( 'builtin' === $status ) {
									echo '<span class="wpait-pack-badge is-builtin">' . esc_html__( 'Built-in', 'wp-ai-translate' ) . '</span>';
								} elseif ( 'installed' === $status ) {
									echo '<span class="wpait-pack-badge is-installed">' . esc_html__( 'Installed', 'wp-ai-translate' ) . '</span>';
								} elseif ( 'not_installed' === $status ) {
									echo '<span class="wpait-pack-badge is-missing">' . esc_html__( 'Not installed', 'wp-ai-translate' ) . '</span>';
								} else {
									echo '<span class="wpait-pack-badge is-none">' . esc_html__( 'No locale set', 'wp-ai-translate' ) . '</span>';
								}
								?>
							</td>
							<td class="wpait-pack-action">
								<?php if ( 'not_installed' === $status ) : ?>
									<button type="button" class="button wpait-install-pack" data-locale="<?php echo esc_attr( $locale ); ?>"><?php esc_html_e( 'Install', 'wp-ai-translate' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $languages ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No languages configured yet.', 'wp-ai-translate' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Inline JS for the Language packs card: installs a missing pack via the REST
	 * endpoint and flips the row to "Installed" on success (#62).
	 *
	 * @return void
	 */
	private function render_language_packs_script(): void {
		$strings = wp_json_encode(
			array(
				'installing' => __( 'Installing…', 'wp-ai-translate' ),
				'installed'  => __( 'Installed', 'wp-ai-translate' ),
				'failed'     => __( 'Install failed', 'wp-ai-translate' ),
				'noFetch'    => __( 'Could not install in this browser.', 'wp-ai-translate' ),
			)
		);
		?>
		<script>
		( function () {
			var strings = <?php echo $strings; ?>;
			document.querySelectorAll( '.wpait-install-pack' ).forEach( function ( btn ) {
				var prev       = btn.textContent;
				var actionCell = btn.parentNode;

				// Surface install errors in a small inline notice next to the
				// (re-enabled) Install button rather than as the button label,
				// which stretched the button and truncated long messages (#71).
				function showError( msg ) {
					var note = actionCell.querySelector( '.wpait-pack-error' );
					if ( ! note ) {
						note = document.createElement( 'p' );
						note.className = 'wpait-pack-error';
						note.setAttribute( 'role', 'alert' );
						actionCell.appendChild( note );
					}
					note.textContent = msg;
					btn.textContent = prev;
					btn.disabled = false;
				}

				function clearError() {
					var note = actionCell.querySelector( '.wpait-pack-error' );
					if ( note ) { note.remove(); }
				}

				btn.addEventListener( 'click', function () {
					var row    = btn.closest( '.wpait-language-pack-row' );
					var locale = btn.getAttribute( 'data-locale' );
					var statusCell = row ? row.querySelector( '.wpait-pack-status' ) : null;
					if ( ! window.wp || ! wp.apiFetch ) {
						showError( strings.noFetch );
						return;
					}
					clearError();
					btn.disabled = true;
					btn.textContent = strings.installing;
					wp.apiFetch( { path: '/<?php echo esc_js( Wpait_Rest::NS ); ?>/install-language-pack', method: 'POST', data: { locale: locale } } )
						.then( function ( res ) {
							if ( res && res.installed ) {
								if ( statusCell ) {
									statusCell.innerHTML = '<span class="wpait-pack-badge is-installed">' + strings.installed + '</span>';
								}
								btn.parentNode.removeChild( btn );
							} else {
								showError( strings.failed );
							}
						} )
						.catch( function ( e ) {
							showError( ( e && e.message ) ? e.message : strings.failed );
						} );
				} );
			} );
		}() );
		</script>
		<?php
	}
}
