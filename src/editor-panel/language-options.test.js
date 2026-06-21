/**
 * Unit tests for the Translations panel's pure helpers.
 */
import { buildLanguageOptions, hasSiblings } from './language-options';

const LANGS = [
	{ code: 'en', name: 'English', native: 'English' },
	{ code: 'es', name: 'Spanish', native: 'Español' },
	{ code: 'xx', name: 'NoNative', native: '' },
];

describe( 'buildLanguageOptions', () => {
	it( 'omits the placeholder when a language is already set', () => {
		const opts = buildLanguageOptions( LANGS, 'en', '— Not set —' );
		expect( opts ).toHaveLength( 3 );
		expect( opts[ 0 ] ).toEqual( { label: 'English (English)', value: 'en' } );
	} );

	it( 'prepends the placeholder when no language is set', () => {
		const opts = buildLanguageOptions( LANGS, '', '— Not set —' );
		expect( opts ).toHaveLength( 4 );
		expect( opts[ 0 ] ).toEqual( { label: '— Not set —', value: '' } );
	} );

	it( 'falls back to the name when there is no native name', () => {
		const opts = buildLanguageOptions( LANGS, 'en', '—' );
		expect( opts.find( ( o ) => o.value === 'xx' ).label ).toBe( 'NoNative' );
	} );

	it( 'is safe with no languages', () => {
		expect( buildLanguageOptions( undefined, 'en', '—' ) ).toEqual( [] );
	} );
} );

describe( 'hasSiblings', () => {
	it( 'is true when the translations map is non-empty', () => {
		expect( hasSiblings( { es: { id: 5 } } ) ).toBe( true );
	} );

	it( 'is false for an empty or missing map', () => {
		expect( hasSiblings( {} ) ).toBe( false );
		expect( hasSiblings( undefined ) ).toBe( false );
	} );
} );
