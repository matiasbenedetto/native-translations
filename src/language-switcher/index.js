/**
 * WP Native Translations — "Language Switcher" block (editor side).
 *
 * Dynamic block: the front-end markup is produced by the PHP render callback in
 * Wpnt_Frontend. This file only provides the editor representation (a live
 * server-side-rendered preview) and the inspector controls.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

const NAME = 'native-translations/language-switcher';

const STYLE_OPTIONS = [
	{ label: __( 'Flag and name', 'native-translations' ), value: 'both' },
	{ label: __( 'Flag only', 'native-translations' ), value: 'flags' },
	{ label: __( 'Name only', 'native-translations' ), value: 'names' },
];

const Edit = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();
	const postId = useSelect(
		( select ) => select( 'core/editor' )?.getCurrentPostId(),
		[]
	);

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Display', 'native-translations' ) }>
					<SelectControl
						label={ __( 'Show', 'native-translations' ) }
						value={ attributes.displayStyle }
						options={ STYLE_OPTIONS }
						onChange={ ( displayStyle ) => setAttributes( { displayStyle } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Mark the current language', 'native-translations' ) }
						checked={ !! attributes.showCurrent }
						onChange={ ( showCurrent ) => setAttributes( { showCurrent } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block={ NAME }
				attributes={ attributes }
				urlQueryArgs={ postId ? { post_id: postId } : {} }
			/>
		</div>
	);
};

registerBlockType( NAME, {
	edit: Edit,
	save: () => null,
} );
