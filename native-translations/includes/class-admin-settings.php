<?php
/**
 * Settings page: the WP Native Translations top-level admin menu (Settings submenu).
 *
 * @package WpNativeTranslations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin settings, and reconciles language terms on
 * save (delegating to Wpnt_Languages).
 */
class Wpnt_Admin_Settings {

	const OPTION    = 'wpnt_settings';
	const PAGE_SLUG = 'native-translations';

	/**
	 * Fallback flag region for language-only locales (#88). WordPress ships many
	 * locales as a bare language subtag (e.g. 'el', 'ja') with no region, so the
	 * bundled catalog leaves their flag blank. This map gives each such language
	 * the flag of its conventional home country/region so the picker shows a flag
	 * wherever one can be matched. Keyed by language subtag, value is a flagcdn
	 * region code (two-letter, or a 'gb-xxx' subdivision). Languages with no single
	 * home country (Arabic, Esperanto, Kurdish, Tibetan, ...) are intentionally
	 * omitted and stay flagless. Region-bearing locales (de_DE, fr_FR) already carry
	 * a flag in the catalog and never consult this map.
	 *
	 * @var array<string,string>
	 */
	const LANGUAGE_FLAG_FALLBACK = array(
		'af'  => 'za', // Afrikaans → South Africa.
		'sq'  => 'al', // Albanian → Albania.
		'am'  => 'et', // Amharic → Ethiopia.
		'arg' => 'es', // Aragonese → Spain.
		'hy'  => 'am', // Armenian → Armenia.
		'as'  => 'in', // Assamese → India.
		'az'  => 'az', // Azerbaijani → Azerbaijan.
		'eu'  => 'es', // Basque → Spain.
		'bel' => 'by', // Belarusian → Belarus.
		'ca'  => 'es', // Catalan → Spain.
		'ceb' => 'ph', // Cebuano → Philippines.
		'hr'  => 'hr', // Croatian → Croatia.
		'dzo' => 'bt', // Dzongkha → Bhutan.
		'et'  => 'ee', // Estonian → Estonia.
		'fi'  => 'fi', // Finnish → Finland.
		'fy'  => 'nl', // Frisian → Netherlands.
		'fur' => 'it', // Friulian → Italy.
		'el'  => 'gr', // Greek → Greece.
		'gu'  => 'in', // Gujarati → India.
		'haz' => 'af', // Hazaragi → Afghanistan.
		'ja'  => 'jp', // Japanese → Japan.
		'kab' => 'dz', // Kabyle → Algeria.
		'kn'  => 'in', // Kannada → India.
		'kk'  => 'kz', // Kazakh → Kazakhstan.
		'km'  => 'kh', // Khmer → Cambodia.
		'kir' => 'kg', // Kyrgyz → Kyrgyzstan.
		'lo'  => 'la', // Lao → Laos.
		'lv'  => 'lv', // Latvian → Latvia.
		'dsb' => 'de', // Lower Sorbian → Germany.
		'mr'  => 'in', // Marathi → India.
		'mn'  => 'mn', // Mongolian → Mongolia.
		'ary' => 'ma', // Moroccan Arabic → Morocco.
		'oci' => 'fr', // Occitan → France.
		'ps'  => 'af', // Pashto → Afghanistan.
		'rhg' => 'mm', // Rohingya → Myanmar.
		'sah' => 'ru', // Sakha → Russia.
		'skr' => 'pk', // Saraiki → Pakistan.
		'gd'  => 'gb-sct', // Scottish Gaelic → Scotland.
		'szl' => 'pl', // Silesian → Poland.
		'snd' => 'pk', // Sindhi → Pakistan.
		'azb' => 'ir', // South Azerbaijani → Iran.
		'sw'  => 'tz', // Swahili → Tanzania.
		'tl'  => 'ph', // Tagalog → Philippines.
		'tah' => 'pf', // Tahitian → French Polynesia.
		'te'  => 'in', // Telugu → India.
		'th'  => 'th', // Thai → Thailand.
		'uk'  => 'ua', // Ukrainian → Ukraine.
		'hsb' => 'de', // Upper Sorbian → Germany.
		'ur'  => 'pk', // Urdu → Pakistan.
		'vi'  => 'vn', // Vietnamese → Vietnam.
		'cy'  => 'gb-wls', // Welsh → Wales.
		'yor' => 'ng', // Yoruba → Nigeria.
	);

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
	 * @var Wpnt_Languages
	 */
	private Wpnt_Languages $languages;

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
	 * @param Wpnt_Languages $languages Languages handler.
	 */
	public function __construct( Wpnt_Languages $languages ) {
		$this->languages = $languages;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Priority 9 so the top-level parent menu is registered before
		// Wpnt_Admin_List adds its Overview submenu (default priority 10).
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
			'wpnt-admin-settings',
			WPNT_PLUGIN_URL . 'assets/css/admin-settings.css',
			array(),
			WPNT_VERSION
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
				'native-translations'
			),
			'post'   => __(
				'This is web page content. Keep headings, lists, and structure intact.',
				'native-translations'
			),
			'term'   => __(
				'This is a taxonomy term (a category or tag) used for navigation. Keep the translation short and label-like.',
				'native-translations'
			),
		);
	}

	/**
	 * Normalizes one locale-catalog entry. Accepts loose input (used both for the
	 * bundled data rows and for entries injected via the `wpnt_locale_catalog`
	 * filter) and guarantees the shape the UI relies on: a derived code, a non-empty
	 * display name, a native label (falling back to the name), and a flag that is
	 * either a flagcdn region code (two-letter, or a "gb-xxx" subdivision) or blank.
	 * A blank flag is back-filled from LANGUAGE_FLAG_FALLBACK by language subtag.
	 *
	 * @param string $locale WordPress locale.
	 * @param string $name   Display name (e.g. "Spanish (Argentina)").
	 * @param string $native Native label (e.g. "Español de Argentina").
	 * @param string $flag   Region subtag for the flag icon, or '' for none.
	 * @return array{locale:string,code:string,name:string,native:string,flag:string}
	 */
	private static function locale_catalog_entry( string $locale, string $name, string $native, string $flag ): array {
		$name = '' !== trim( $name ) ? $name : $locale;
		$flag = strtolower( $flag );
		if ( 1 !== preg_match( '/^[a-z]{2}(-[a-z]{3})?$/', $flag ) ) {
			$flag = '';
		}
		if ( '' === $flag ) {
			// Language-only locale with no region flag: fall back to the conventional
			// home country/region for that language so the picker shows a flag (#88).
			$language = strtolower( strtok( $locale, '_-' ) );
			$flag     = self::LANGUAGE_FLAG_FALLBACK[ $language ] ?? '';
		}
		return array(
			'locale' => $locale,
			'code'   => self::code_for_locale( $locale ),
			'name'   => $name,
			'native' => '' !== trim( $native ) ? $native : $name,
			'flag'   => $flag,
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
	 * The data lives in includes/data/locales.php (the locales WordPress core is
	 * translated into; not translated through this text domain), extended by the
	 * `wpnt_locale_catalog` filter.
	 *
	 * @return array<string,array{locale:string,code:string,name:string,native:string,flag:string}>
	 */
	public static function locale_catalog(): array {
		$rows  = require __DIR__ . '/data/locales.php';
		$built = array();
		foreach ( $rows as $row ) {
			// Row shape: [ locale, name, native, flag ].
			$entry                     = self::locale_catalog_entry( $row[0], $row[1], $row[2], $row[3] );
			$built[ $entry['locale'] ] = $entry;
		}

		/**
		 * Filters the catalog of locales offered in the Languages picker.
		 *
		 * Extenders can add locales WordPress doesn't ship (or override a bundled
		 * one) by returning extra entries keyed by WordPress locale. Each entry is
		 * re-normalized afterwards, so a callback only needs to supply the labels:
		 *
		 *     add_filter( 'wpnt_locale_catalog', function ( $catalog ) {
		 *         $catalog['gl_ES'] = array(
		 *             'name'   => 'Galician',
		 *             'native' => 'Galego',
		 *             'flag'   => 'es', // optional two-letter region code
		 *         );
		 *         return $catalog;
		 *     } );
		 *
		 * @param array<string,array{locale:string,code:string,name:string,native:string,flag:string}> $built Locale entries keyed by locale.
		 */
		$filtered = apply_filters( 'wpnt_locale_catalog', $built );
		if ( ! is_array( $filtered ) ) {
			$filtered = $built;
		}

		$catalog = array();
		foreach ( $filtered as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$locale = isset( $entry['locale'] ) && '' !== (string) $entry['locale'] ? (string) $entry['locale'] : (string) $key;
			if ( '' === $locale ) {
				continue;
			}
			$catalog[ $locale ] = self::locale_catalog_entry(
				$locale,
				isset( $entry['name'] ) ? (string) $entry['name'] : '',
				isset( $entry['native'] ) ? (string) $entry['native'] : '',
				isset( $entry['flag'] ) ? (string) $entry['flag'] : ''
			);
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
		// Accepts WordPress locale forms: `xx`, `xx_YY`, and variant locales such as
		// `de_DE_formal` / `pt_PT_ao90` (region required before any variant suffix).
		return '' === $locale || 1 === preg_match( '/^[a-z]{2,3}(_[A-Z]{2,3}(_[a-z0-9]+)?)?$/', $locale );
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
	 * Adds the top-level "WP Native Translations" menu plus its Settings submenu.
	 *
	 * The submenu is registered with the same slug as the parent so the first
	 * item reads "Settings" instead of repeating the menu title.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'WP Native Translations', 'native-translations' ),
			__( 'WP Native Translations', 'native-translations' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-translation',
			58
		);

		$this->settings_hook = (string) add_submenu_page(
			self::PAGE_SLUG,
			__( 'WP Native Translations', 'native-translations' ),
			__( 'Settings', 'native-translations' ),
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
			'wpnt_settings_group',
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
		$old_languages   = isset( $old['languages'] ) && is_array( $old['languages'] ) ? $old['languages'] : array();
		foreach ( $old_languages as $old_row ) {
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
				'wpnt_invalid_locale',
				sprintf(
					/* translators: %s: comma-separated language codes. */
					__( 'These languages have a locale that is not in WordPress’s expected format (e.g. es_ES): %s. They were saved as entered — please correct them.', 'native-translations' ),
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
			'added'    => __( 'Added languages: %s', 'native-translations' ),
			/* translators: %s: comma-separated list of language names. */
			'updated'  => __( 'Updated languages: %s', 'native-translations' ),
			/* translators: %s: comma-separated list of language names. */
			'disabled' => __( 'Disabled languages: %s', 'native-translations' ),
			/* translators: %s: comma-separated list of language names. */
			'removed'  => __( 'Removed languages: %s', 'native-translations' ),
		);
		foreach ( $map as $key => $template ) {
			if ( ! empty( $report[ $key ] ) ) {
				add_settings_error(
					self::OPTION,
					'wpnt_' . $key,
					sprintf( $template, implode( ', ', $report[ $key ] ) ),
					'updated'
				);
			}
		}

		if ( ! empty( $report['blocked'] ) ) {
			add_settings_error(
				self::OPTION,
				'wpnt_blocked',
				sprintf(
					/* translators: %s: comma-separated language codes. */
					__( 'These languages still have content and were kept (disabled) instead of being deleted: %s. Reassign or delete their content first to remove them.', 'native-translations' ),
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
		<div class="wrap wpnt-settings">
			<h1><?php esc_html_e( 'WP Native Translations', 'native-translations' ); ?></h1>
			<?php
			// This page is served by wp-admin/options-general.php, whose
			// options-head.php already calls settings_errors(); calling it again here
			// would render every notice twice, so we intentionally do not.

			$ai_supported = function_exists( 'wp_supports_ai' ) && wp_supports_ai();
			$ai_usable    = Wpnt_Translator::can_generate_text();
			if ( $ai_usable ) {
				$badge_class = 'wpnt-ai-ok';
				$badge_text  = __( 'Available', 'native-translations' );
				$status_line = __( 'An AI provider with a text-generation model is available. Translations can be generated.', 'native-translations' );
			} elseif ( $ai_supported ) {
				$badge_class = 'wpnt-ai-warn';
				$badge_text  = __( 'No usable model', 'native-translations' );
				$status_line = __( 'An AI provider is present, but no text-generation model is available, so translations will fail. Configure a text-generation model for this site’s AI provider.', 'native-translations' );
			} else {
				$badge_class = 'wpnt-ai-bad';
				$badge_text  = __( 'Not configured', 'native-translations' );
				$status_line = __( 'No AI provider is configured for this site, so translations cannot be generated.', 'native-translations' );
			}
			?>
			<div class="wpnt-card">
				<h2><?php esc_html_e( 'AI provider', 'native-translations' ); ?></h2>
				<p>
					<span class="wpnt-ai-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span>
				</p>
				<p class="description"><?php echo esc_html( $status_line ); ?></p>
				<p>
					<button type="button" class="button" id="wpnt-test-connection"><?php esc_html_e( 'Test connection', 'native-translations' ); ?></button>
					<span id="wpnt-test-result" role="status" aria-live="polite" style="margin-left:8px"></span>
				</p>
				<p class="description">
					<?php esc_html_e( 'Choose the model below. AI providers themselves are configured for the site (WordPress 7.0 AI), not in this plugin.', 'native-translations' ); ?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpnt_settings_group' ); ?>

				<div class="wpnt-card">
					<h2><?php esc_html_e( 'Languages', 'native-translations' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Add a language by choosing its locale. The plugin sets the code, display name, native name, and flag automatically. Languages with content cannot be deleted — disable them instead.', 'native-translations' ); ?>
					</p>
					<table class="widefat striped wpnt-languages-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Language', 'native-translations' ); ?></th>
								<th><?php esc_html_e( 'Locale', 'native-translations' ); ?></th>
								<th><?php esc_html_e( 'Enabled', 'native-translations' ); ?></th>
								<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'native-translations' ); ?></span></th>
							</tr>
						</thead>
						<tbody id="wpnt-languages-rows">
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
								<tr class="wpnt-language-row" data-locale="<?php echo esc_attr( $locale ); ?>">
									<td>
										<input type="hidden" name="<?php echo esc_attr( $base . '[code]' ); ?>" value="<?php echo esc_attr( $row['code'] ); ?>" />
										<span class="wpnt-language-summary">
											<span class="wpnt-language-flag" aria-hidden="true"><?php echo Wpnt_Languages::flag_html( $flag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_html() returns escaped HTML. ?></span>
											<span class="wpnt-language-text">
												<strong><?php echo esc_html( $name ); ?></strong>
												<?php if ( '' !== $native && $native !== $name ) : ?>
													<span class="wpnt-language-native"><?php echo esc_html( $native ); ?></span>
												<?php endif; ?>
											</span>
										</span>
									</td>
									<td><code><?php echo '' !== $locale ? esc_html( $locale ) : '—'; ?></code></td>
									<td>
										<label class="wpnt-enabled-toggle">
											<input type="checkbox" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> />
											<?php esc_html_e( 'Enabled', 'native-translations' ); ?>
										</label>
									</td>
									<td>
										<?php if ( $in_use ) : ?>
											<span class="description" title="<?php esc_attr_e( 'This language has content and cannot be removed. Disable it instead, or reassign/delete its content first.', 'native-translations' ); ?>"><?php esc_html_e( 'In use', 'native-translations' ); ?></span>
										<?php else : ?>
											<button type="button" class="button-link wpnt-remove-language" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: language name. */ __( 'Remove %s', 'native-translations' ), $name ) ); ?>"><?php esc_html_e( 'Remove', 'native-translations' ); ?></button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr class="wpnt-no-languages" <?php echo empty( $rows ) ? '' : 'style="display:none"'; ?>>
								<td colspan="4"><?php esc_html_e( 'No languages configured yet. Use “Add language” to choose a locale.', 'native-translations' ); ?></td>
							</tr>
						</tbody>
					</table>
					<p>
						<button type="button" class="button" id="wpnt-add-language" <?php disabled( empty( $available_locales ) ); ?>>
							<span class="dashicons dashicons-plus" style="vertical-align:text-bottom"></span>
							<?php esc_html_e( 'Add language', 'native-translations' ); ?>
						</button>
					</p>
					<?php if ( empty( $available_locales ) ) : ?>
						<p class="description"><?php esc_html_e( 'All bundled locales are already configured.', 'native-translations' ); ?></p>
					<?php else : ?>
						<div class="wpnt-add-language-panel" id="wpnt-add-language-panel" hidden>
							<label class="wpnt-add-language-label"><?php esc_html_e( 'Locale', 'native-translations' ); ?></label>
							<div class="wpnt-locale-picker" data-selected-locale="">
								<button type="button" class="button wpnt-locale-picker-toggle" id="wpnt-locale-picker-toggle" aria-expanded="false" aria-haspopup="listbox">
									<span class="wpnt-locale-picker-selected"><?php esc_html_e( 'Select a locale', 'native-translations' ); ?></span>
								</button>
								<div class="wpnt-locale-picker-menu" id="wpnt-locale-picker-menu" hidden>
									<input type="search" class="wpnt-locale-search" placeholder="<?php esc_attr_e( 'Search locales', 'native-translations' ); ?>" aria-label="<?php esc_attr_e( 'Search locales', 'native-translations' ); ?>" />
									<div class="wpnt-locale-options" role="listbox" aria-label="<?php esc_attr_e( 'Available locales', 'native-translations' ); ?>">
										<?php foreach ( $available_locales as $entry ) : ?>
											<button type="button" class="wpnt-locale-option" role="option"
												data-locale="<?php echo esc_attr( $entry['locale'] ); ?>"
												data-code="<?php echo esc_attr( $entry['code'] ); ?>"
												data-name="<?php echo esc_attr( $entry['name'] ); ?>"
												data-native="<?php echo esc_attr( $entry['native'] ); ?>"
												data-flag="<?php echo esc_attr( $entry['flag'] ); ?>">
												<span class="wpnt-language-flag" aria-hidden="true"><?php echo Wpnt_Languages::flag_html( $entry['flag'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_html() returns escaped HTML. ?></span>
												<span class="wpnt-locale-option-text">
													<strong><?php echo esc_html( $entry['name'] ); ?></strong>
													<code><?php echo esc_html( $entry['locale'] ); ?></code>
												</span>
											</button>
										<?php endforeach; ?>
										<p class="wpnt-no-locale-options" hidden><?php esc_html_e( 'No matching locales.', 'native-translations' ); ?></p>
									</div>
								</div>
							</div>
							<p class="wpnt-add-language-actions">
								<button type="button" class="button button-primary" id="wpnt-confirm-add-language" disabled><?php esc_html_e( 'Add selected locale', 'native-translations' ); ?></button>
								<button type="button" class="button-link" id="wpnt-cancel-add-language"><?php esc_html_e( 'Cancel', 'native-translations' ); ?></button>
							</p>
						</div>
					<?php endif; ?>
					</div>

				<?php $this->render_language_packs_card( $languages ); ?>

				<div class="wpnt-card">
				<h2><?php esc_html_e( 'Default language', 'native-translations' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The site’s primary language — used as the source when no other is given.', 'native-translations' ); ?></p>
				<select name="<?php echo esc_attr( $option . '[default_language]' ); ?>">
					<option value=""><?php esc_html_e( '— Select —', 'native-translations' ); ?></option>
					<?php foreach ( $languages as $lang ) : ?>
						<option value="<?php echo esc_attr( $lang['code'] ); ?>" <?php selected( $settings['default_language'], $lang['code'] ); ?>>
							<?php echo esc_html( $lang['name'] . ' (' . $lang['code'] . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				</div>

				<div class="wpnt-card">
				<h2><?php esc_html_e( 'AI model', 'native-translations' ); ?></h2>
				<?php
				$current_model = (string) $settings['model'];
				$models        = $ai_usable ? Wpnt_Translator::available_models() : array();
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
					<?php esc_html_e( 'Which model to use for translations. “Automatic” lets the provider choose — pick a specific model if the provider rejects the default (e.g. Anthropic’s “Claude Fable 5 is not available, use Opus 4.8”).', 'native-translations' ); ?>
				</p>
				<select name="<?php echo esc_attr( $option . '[model]' ); ?>">
					<option value=""><?php esc_html_e( 'Automatic (provider default)', 'native-translations' ); ?></option>
					<?php if ( '' !== $current_model && ! in_array( $current_model, $model_ids, true ) ) : ?>
						<option value="<?php echo esc_attr( $current_model ); ?>" selected>
							<?php
							/* translators: %s: model id. */
							echo esc_html( sprintf( __( '%s (currently set)', 'native-translations' ), $current_model ) );
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
					<?php esc_html_e( 'If your selected model is unavailable to the account, translation automatically retries once with another advertised model rather than failing — so a translation may be produced by a different model than the one chosen here.', 'native-translations' ); ?>
				</p>
				<?php if ( $ai_usable && empty( $models ) ) : ?>
					<p class="description"><?php esc_html_e( 'Could not list models from the provider; “Automatic” will be used.', 'native-translations' ); ?></p>
				<?php elseif ( ! $ai_usable ) : ?>
					<p class="description"><?php esc_html_e( 'Connect an AI provider (see status above) to choose a model.', 'native-translations' ); ?></p>
				<?php endif; ?>
				</div>

				<div class="wpnt-card">
				<h2><?php esc_html_e( 'Translation instructions', 'native-translations' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'These instructions shape the AI translation. Markup-safety rules are always applied automatically and cannot be overridden. Available placeholders:', 'native-translations' ); ?>
					<code>{source_lang}</code> <code>{target_lang}</code> <code>{site_name}</code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Global', 'native-translations' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" name="<?php echo esc_attr( $option . '[instructions][global]' ); ?>" placeholder="<?php echo esc_attr( $instr_defaults['global'] ); ?>" data-wpnt-default="<?php echo esc_attr( $instr_defaults['global'] ); ?>"><?php echo esc_textarea( $settings['instructions']['global'] ); ?></textarea>
							<p><a href="#" class="wpnt-reset-instruction"><?php esc_html_e( 'Reset to default', 'native-translations' ); ?></a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Posts &amp; pages', 'native-translations' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option . '[instructions][post]' ); ?>" placeholder="<?php echo esc_attr( $instr_defaults['post'] ); ?>" data-wpnt-default="<?php echo esc_attr( $instr_defaults['post'] ); ?>"><?php echo esc_textarea( $settings['instructions']['post'] ); ?></textarea>
							<p><a href="#" class="wpnt-reset-instruction"><?php esc_html_e( 'Reset to default', 'native-translations' ); ?></a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Categories &amp; tags', 'native-translations' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option . '[instructions][term]' ); ?>" placeholder="<?php echo esc_attr( $instr_defaults['term'] ); ?>" data-wpnt-default="<?php echo esc_attr( $instr_defaults['term'] ); ?>"><?php echo esc_textarea( $settings['instructions']['term'] ); ?></textarea>
							<p><a href="#" class="wpnt-reset-instruction"><?php esc_html_e( 'Reset to default', 'native-translations' ); ?></a></p>
						</td>
					</tr>
				</table>

				<?php if ( ! empty( $languages ) ) : ?>
					<h3><?php esc_html_e( 'Per-language instructions', 'native-translations' ); ?></h3>
					<table class="form-table" role="presentation">
						<?php foreach ( $languages as $lang ) : ?>
							<tr>
								<th scope="row"><label><?php echo esc_html( $lang['name'] . ' (' . $lang['code'] . ')' ); ?></label></th>
								<td>
									<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option . '[instructions][per_language][' . $lang['code'] . ']' ); ?>" placeholder="<?php esc_attr_e( 'Optional — adds to the global and content-type instructions for this language.', 'native-translations' ); ?>"><?php echo esc_textarea( $settings['instructions']['per_language'][ $lang['code'] ] ?? '' ); ?></textarea>
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
			document.querySelectorAll( '.wpnt-reset-instruction' ).forEach( function ( link ) {
				link.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var ta = this.closest( 'td' ).querySelector( 'textarea[data-wpnt-default]' );
					if ( ta ) { ta.value = ta.getAttribute( 'data-wpnt-default' ); }
				} );
			} );

				// Add / remove language rows. New rows submit only a catalog locale; the
				// server derives the code, labels, and flag from its own locale catalog.
				var tbody      = document.getElementById( 'wpnt-languages-rows' );
				var addBtn     = document.getElementById( 'wpnt-add-language' );
				var panel      = document.getElementById( 'wpnt-add-language-panel' );
				var picker     = panel ? panel.querySelector( '.wpnt-locale-picker' ) : null;
				var toggle     = document.getElementById( 'wpnt-locale-picker-toggle' );
				var menu       = document.getElementById( 'wpnt-locale-picker-menu' );
				var selectedEl = panel ? panel.querySelector( '.wpnt-locale-picker-selected' ) : null;
				var search     = panel ? panel.querySelector( '.wpnt-locale-search' ) : null;
				var confirmBtn = document.getElementById( 'wpnt-confirm-add-language' );
				var cancelBtn  = document.getElementById( 'wpnt-cancel-add-language' );
				var nextIndex  = <?php echo (int) count( $languages ); ?>;
				var optionName = <?php echo wp_json_encode( $option ); ?>;
				var languageStrings = <?php echo wp_json_encode(
					array(
						'selectLocale' => __( 'Select a locale', 'native-translations' ),
						'enabled'      => __( 'Enabled', 'native-translations' ),
						'remove'       => __( 'Remove', 'native-translations' ),
					)
				); ?>;
				var selectedLocale = null;

				function refreshEmptyState() {
					if ( ! tbody ) { return; }
					var empty = tbody.querySelector( '.wpnt-no-languages' );
					if ( ! empty ) { return; }
					var hasRows = !! tbody.querySelector( '.wpnt-language-row' );
					empty.style.display = hasRows ? 'none' : '';
				}

				function flagNode( flag ) {
					var wrap = document.createElement( 'span' );
					wrap.className = 'wpnt-language-flag';
					wrap.setAttribute( 'aria-hidden', 'true' );
					if ( /^[a-z]{2}$/.test( flag ) ) {
						var img = document.createElement( 'img' );
						img.className = 'wpnt-flag-img';
						img.src = 'https://flagcdn.com/' + flag + '.svg';
						img.alt = '';
						img.loading = 'lazy';
						img.decoding = 'async';
						wrap.appendChild( img );
					} else if ( flag ) {
						var span = document.createElement( 'span' );
						span.className = 'wpnt-flag-emoji';
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
						label.className = 'wpnt-locale-selected-text';
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
					panel.querySelectorAll( '.wpnt-locale-option' ).forEach( function ( option ) {
						var haystack = (
							option.textContent + ' ' +
							( option.getAttribute( 'data-native' ) || '' ) + ' ' +
							( option.getAttribute( 'data-code' ) || '' ) + ' ' +
							( option.getAttribute( 'data-locale' ) || '' )
						).toLowerCase();
						var match = ! q || haystack.indexOf( q ) !== -1;
						option.hidden = option.disabled || ! match;
						if ( ! option.hidden ) {
							shown++;
						}
					} );
					var empty = panel.querySelector( '.wpnt-no-locale-options' );
					if ( empty ) {
						empty.hidden = shown > 0;
					}
				}

				// Disable "Add language" once every catalog option has been consumed,
				// and re-enable it when a pending row frees one up again.
				function updateAddButton() {
					if ( ! addBtn || ! panel ) { return; }
					var available = panel.querySelector( '.wpnt-locale-option:not([disabled])' );
					addBtn.disabled = ! available;
				}

				// Collapse the whole add-language panel (menu + selection), used by
				// Cancel, Escape, and outside-click so they can't leave it half-open.
				function closePanel() {
					if ( panel ) { panel.hidden = true; }
					closeMenu();
					resetPicker();
				}

				function addLanguageRow( data ) {
					var index = String( nextIndex++ );
					var base = optionName + '[languages][' + index + ']';
					var row = document.createElement( 'tr' );
					row.className = 'wpnt-language-row wpnt-new-language-row';
					row.setAttribute( 'data-locale', data.locale );
					row.setAttribute( 'data-pending', '1' );

					var languageCell = document.createElement( 'td' );
					var summary = document.createElement( 'span' );
					summary.className = 'wpnt-language-summary';
					var text = document.createElement( 'span' );
					text.className = 'wpnt-language-text';
					var name = document.createElement( 'strong' );
					name.textContent = data.name;
					var native = document.createElement( 'span' );
					native.className = 'wpnt-language-native';
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
					remove.className = 'button-link wpnt-remove-language';
					remove.textContent = languageStrings.remove;
					remove.setAttribute( 'aria-label', languageStrings.remove + ' ' + data.name );
					actionCell.appendChild( remove );

					row.append( languageCell, localeCell, enabledCell, actionCell );
					var empty = tbody.querySelector( '.wpnt-no-languages' );
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
					cancelBtn.addEventListener( 'click', closePanel );
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
						var option = e.target.closest( '.wpnt-locale-option' );
						if ( ! option || option.disabled ) { return; }
						setSelectedLocale( optionData( option ) );
						closeMenu();
					} );
				}

				if ( confirmBtn ) {
					confirmBtn.addEventListener( 'click', function () {
						if ( ! selectedLocale || ! selectedLocale.locale ) { return; }
						addLanguageRow( selectedLocale );
						var option = panel.querySelector( '.wpnt-locale-option[data-locale="' + selectedLocale.locale + '"]' );
						if ( option ) {
							option.disabled = true;
							option.hidden = true;
						}
						closePanel();
						updateAddButton();
					} );
				}

				if ( tbody ) {
					tbody.addEventListener( 'click', function ( e ) {
						var btn = e.target.closest( '.wpnt-remove-language' );
						if ( ! btn ) { return; }
						e.preventDefault();
						var row = btn.closest( '.wpnt-language-row' );
						if ( ! row ) { return; }
						var locale = row.getAttribute( 'data-locale' );
						var pending = '1' === row.getAttribute( 'data-pending' );
						row.parentNode.removeChild( row );
						if ( pending && panel && locale ) {
							var option = panel.querySelector( '.wpnt-locale-option[data-locale="' + locale + '"]' );
							if ( option ) {
								option.disabled = false;
							}
						}
						filterOptions();
						updateAddButton();
						refreshEmptyState();
					} );
				}

				document.addEventListener( 'click', function ( e ) {
					if ( panel && ! panel.hidden && ! panel.contains( e.target ) && e.target !== addBtn ) {
						closePanel();
					}
				} );

				document.addEventListener( 'keydown', function ( e ) {
					if ( 'Escape' === e.key && panel && ! panel.hidden ) {
						closePanel();
					}
				} );

				// "Test connection": live AI round trip via the REST probe.
				var testBtn = document.getElementById( 'wpnt-test-connection' );
			var testOut = document.getElementById( 'wpnt-test-result' );
			var strings = <?php echo wp_json_encode(
				array(
					'testing' => __( 'Testing…', 'native-translations' ),
					'failed'  => __( 'Test failed.', 'native-translations' ),
					'noFetch' => __( 'Could not run the test in this browser.', 'native-translations' ),
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
					wp.apiFetch( { path: '/<?php echo esc_js( Wpnt_Rest::NS ); ?>/test-connection', method: 'POST' } )
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
		<div class="wpnt-card">
			<h2><?php esc_html_e( 'Language packs', 'native-translations' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'WordPress needs a core language pack installed for a language so that dates and theme/core interface strings (e.g. “Previous”, “Next”, “Leave a Reply”) render in that language when a visitor views content in it.', 'native-translations' ); ?>
			</p>
			<table class="widefat striped wpnt-language-packs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Language', 'native-translations' ); ?></th>
						<th><?php esc_html_e( 'Locale', 'native-translations' ); ?></th>
						<th><?php esc_html_e( 'Status', 'native-translations' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'native-translations' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $languages as $lang ) : ?>
						<?php
						$locale = (string) ( $lang['locale'] ?? '' );
						$status = Wpnt_Languages::locale_pack_status( $locale );
						?>
							<tr class="wpnt-language-pack-row" data-locale="<?php echo esc_attr( $locale ); ?>">
								<td><?php echo $this->languages->label_html( (string) ( $lang['code'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- label_html() returns escaped HTML. ?></td>
							<td><code><?php echo '' !== $locale ? esc_html( $locale ) : '—'; ?></code></td>
							<td class="wpnt-pack-status">
								<?php
								if ( 'builtin' === $status ) {
									echo '<span class="wpnt-pack-badge is-builtin">' . esc_html__( 'Built-in', 'native-translations' ) . '</span>';
								} elseif ( 'installed' === $status ) {
									echo '<span class="wpnt-pack-badge is-installed">' . esc_html__( 'Installed', 'native-translations' ) . '</span>';
								} elseif ( 'not_installed' === $status ) {
									echo '<span class="wpnt-pack-badge is-missing">' . esc_html__( 'Not installed', 'native-translations' ) . '</span>';
								} else {
									echo '<span class="wpnt-pack-badge is-none">' . esc_html__( 'No locale set', 'native-translations' ) . '</span>';
								}
								?>
							</td>
							<td class="wpnt-pack-action">
								<?php if ( 'not_installed' === $status ) : ?>
									<button type="button" class="button wpnt-install-pack" data-locale="<?php echo esc_attr( $locale ); ?>"><?php esc_html_e( 'Install', 'native-translations' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $languages ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No languages configured yet.', 'native-translations' ); ?></td></tr>
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
				'installing' => __( 'Installing…', 'native-translations' ),
				'installed'  => __( 'Installed', 'native-translations' ),
				'failed'     => __( 'Install failed', 'native-translations' ),
				'noFetch'    => __( 'Could not install in this browser.', 'native-translations' ),
			)
		);
		?>
		<script>
		( function () {
			var strings = <?php echo $strings; ?>;
			document.querySelectorAll( '.wpnt-install-pack' ).forEach( function ( btn ) {
				var prev       = btn.textContent;
				var actionCell = btn.parentNode;

				// Surface install errors in a small inline notice next to the
				// (re-enabled) Install button rather than as the button label,
				// which stretched the button and truncated long messages (#71).
				function showError( msg ) {
					var note = actionCell.querySelector( '.wpnt-pack-error' );
					if ( ! note ) {
						note = document.createElement( 'p' );
						note.className = 'wpnt-pack-error';
						note.setAttribute( 'role', 'alert' );
						actionCell.appendChild( note );
					}
					note.textContent = msg;
					btn.textContent = prev;
					btn.disabled = false;
				}

				function clearError() {
					var note = actionCell.querySelector( '.wpnt-pack-error' );
					if ( note ) { note.remove(); }
				}

				btn.addEventListener( 'click', function () {
					var row    = btn.closest( '.wpnt-language-pack-row' );
					var locale = btn.getAttribute( 'data-locale' );
					var statusCell = row ? row.querySelector( '.wpnt-pack-status' ) : null;
					if ( ! window.wp || ! wp.apiFetch ) {
						showError( strings.noFetch );
						return;
					}
					clearError();
					btn.disabled = true;
					btn.textContent = strings.installing;
					wp.apiFetch( { path: '/<?php echo esc_js( Wpnt_Rest::NS ); ?>/install-language-pack', method: 'POST', data: { locale: locale } } )
						.then( function ( res ) {
							if ( res && res.installed ) {
								if ( statusCell ) {
									statusCell.innerHTML = '<span class="wpnt-pack-badge is-installed">' + strings.installed + '</span>';
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
