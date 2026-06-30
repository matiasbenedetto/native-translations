/**
 * Pure helpers for the Translations panel — extracted so they can be unit-tested
 * without rendering the React component.
 */

/**
 * Builds the <SelectControl> options for the "Language of this content" field.
 * The "— Not set —" placeholder is only offered while the content has no language
 * yet (the store cannot unset a language, so it must not be a selectable action).
 *
 * @param {Array<{code:string,name:string,native?:string}>} languages   Configured languages.
 * @param {string}                                          currentLang Current language code ('' if none).
 * @param {string}                                          notSetLabel Placeholder label.
 * @return {Array<{label:string,value:string}>} Select options.
 */
export function buildLanguageOptions( languages, currentLang, notSetLabel ) {
	const opts = ( languages || [] ).map( ( l ) => ( {
		label: l.native ? `${ l.name } (${ l.native })` : l.name,
		value: l.code,
	} ) );
	return currentLang ? opts : [ { label: notSetLabel, value: '' }, ...opts ];
}

/**
 * Whether the object has sibling translations (so its own language is fixed).
 *
 * @param {Object} translations Map of language code → translation info.
 * @return {boolean} True when at least one sibling exists.
 */
export function hasSiblings( translations ) {
	return Object.keys( translations || {} ).length > 0;
}

/**
 * Languages whose rows should be rendered in the Translations panel.
 *
 * Non-original content may still show existing linked translations for management,
 * but it should not render missing-language creation actions.
 *
 * @param {Array<{code:string}>} languages     Configured languages.
 * @param {string}                currentLang   Current language code.
 * @param {Object}                translations  Map of language code → translation info.
 * @param {boolean}               isOriginal    Whether the saved object is original.
 * @return {Array<{code:string}>} Languages to render.
 */
export function visibleTranslationLanguages( languages, currentLang, translations, isOriginal ) {
	return ( languages || [] ).filter( ( language ) => {
		if ( language.code === currentLang ) {
			return false;
		}
		return isOriginal || !! ( translations || {} )[ language.code ];
	} );
}
