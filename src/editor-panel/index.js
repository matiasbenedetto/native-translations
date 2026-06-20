/**
 * AI Translate — block editor sidebar panel.
 *
 * A thin client over the wp-ai-translate/v1 REST endpoints: shows the current
 * post's language, lets the author set it, and offers Translate / Recreate /
 * edit-link actions per configured language.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	PanelRow,
	SelectControl,
	Button,
	Spinner,
	Notice,
	ExternalLink,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, sprintf } from '@wordpress/i18n';

const cfg = window.wpaitEditor || {
	namespace: 'wp-ai-translate/v1',
	languages: [],
	defaultLanguage: '',
	aiAvailable: false,
};

const TranslationsPanel = () => {
	const { postId, postType, isNew } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			isNew: editor.isEditedPostNew(),
		};
	}, [] );

	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const load = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		setLoading( true );
		setError( '' );
		apiFetch( {
			path: addQueryArgs( `/${ cfg.namespace }/translations`, {
				object_id: postId,
				type: 'post',
			} ),
		} )
			.then( ( res ) => setData( res ) )
			.catch( ( e ) => setError( e.message || __( 'Could not load translations.', 'wp-ai-translate' ) ) )
			.finally( () => setLoading( false ) );
	}, [ postId ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const request = ( path, body, key ) => {
		setBusy( key );
		setError( '' );
		return apiFetch( {
			path: `/${ cfg.namespace }/${ path }`,
			method: 'POST',
			data: body,
		} )
			.then( ( res ) => setData( res ) )
			.catch( ( e ) => setError( e.message || __( 'Request failed.', 'wp-ai-translate' ) ) )
			.finally( () => setBusy( '' ) );
	};

	const setLanguage = ( code ) => {
		// The store cannot clear a language; ignore the placeholder option.
		if ( ! code ) {
			return;
		}
		return request( 'set-language', { object_id: postId, code, type: 'post' }, 'lang' );
	};

	const translate = ( code ) =>
		request( 'translate', { source_id: postId, target_code: code, type: 'post' }, code );

	const recreate = ( id, code ) =>
		request( 'recreate', { object_id: id, type: 'post' }, code );

	// Only post/page screens enqueue this script, but guard defensively.
	if ( ! postId || ( postType !== 'post' && postType !== 'page' ) ) {
		return null;
	}

	const currentLang = data ? data.language : '';
	const translations = data ? data.translations : {};

	const languageOptions = [
		// Only offer the placeholder while the content has no language yet — the
		// store cannot unset one, so it must not be a selectable action.
		...( currentLang ? [] : [ { label: __( '— Not set —', 'wp-ai-translate' ), value: '' } ] ),
		...cfg.languages.map( ( l ) => ( {
			label: l.native ? `${ l.name } (${ l.native })` : l.name,
			value: l.code,
		} ) ),
	];

	return (
		<PluginDocumentSettingPanel
			name="wpait-translations"
			title={ __( 'Translations', 'wp-ai-translate' ) }
			icon="translation"
		>
			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ ! cfg.aiAvailable && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'No AI provider is configured, so new translations cannot be generated.', 'wp-ai-translate' ) }
				</Notice>
			) }

			{ isNew && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Save this content before generating translations.', 'wp-ai-translate' ) }
				</Notice>
			) }

			<PanelRow>
				<SelectControl
					label={ __( 'Language of this content', 'wp-ai-translate' ) }
					value={ currentLang || '' }
					options={ languageOptions }
					disabled={ loading || busy === 'lang' }
					onChange={ setLanguage }
					__nextHasNoMarginBottom
				/>
			</PanelRow>

			{ loading && ! data ? (
				<PanelRow><Spinner /></PanelRow>
			) : (
				cfg.languages
					.filter( ( l ) => l.code !== currentLang )
					.map( ( l ) => {
						const existing = translations[ l.code ];
						const working = busy === l.code;
						return (
							<PanelRow key={ l.code }>
								<div style={ { width: '100%' } }>
									<strong>{ l.name }</strong>
									{ existing ? (
										<div style={ { display: 'flex', gap: '8px', alignItems: 'center', marginTop: '4px', flexWrap: 'wrap' } }>
											{ existing.edit_link && (
												<ExternalLink href={ existing.edit_link }>
													{ existing.status
														? sprintf(
															/* translators: %s: post status. */
															__( 'Edit (%s)', 'wp-ai-translate' ),
															existing.status
														)
														: __( 'Edit', 'wp-ai-translate' ) }
												</ExternalLink>
											) }
											<Button
												variant="secondary"
												isSmall
												isBusy={ working }
												disabled={ working || ! cfg.aiAvailable }
												onClick={ () => recreate( existing.id, l.code ) }
											>
												{ __( 'Recreate', 'wp-ai-translate' ) }
											</Button>
										</div>
									) : (
										<div style={ { marginTop: '4px' } }>
											<Button
												variant="primary"
												isSmall
												isBusy={ working }
												disabled={ working || ! cfg.aiAvailable || ! currentLang || isNew }
												onClick={ () => translate( l.code ) }
											>
												{ __( 'Translate', 'wp-ai-translate' ) }
											</Button>
											{ ! currentLang && (
												<p className="description" style={ { margin: '4px 0 0' } }>
													{ __( 'Set this content’s language first.', 'wp-ai-translate' ) }
												</p>
											) }
										</div>
									) }
								</div>
							</PanelRow>
						);
					} )
			) }
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'wpait-translations-panel', {
	render: TranslationsPanel,
	icon: 'translation',
} );
