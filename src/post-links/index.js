/**
 * AI Translate — "Translation Links" block (editor side).
 *
 * Dynamic block: the front-end markup is produced by the PHP render callback in
 * Wpait_Frontend. This file only provides the editor representation (a live
 * server-side-rendered preview) and the inspector controls.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

const NAME = 'wp-ai-translate/post-links';

const STYLE_OPTIONS = [
	{ label: __( 'Flag and name', 'wp-ai-translate' ), value: 'both' },
	{ label: __( 'Flag only', 'wp-ai-translate' ), value: 'flags' },
	{ label: __( 'Name only', 'wp-ai-translate' ), value: 'names' },
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
				<PanelBody title={ __( 'Display', 'wp-ai-translate' ) }>
					<SelectControl
						label={ __( 'Show', 'wp-ai-translate' ) }
						value={ attributes.displayStyle }
						options={ STYLE_OPTIONS }
						onChange={ ( displayStyle ) => setAttributes( { displayStyle } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Include the current language', 'wp-ai-translate' ) }
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
