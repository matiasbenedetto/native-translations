/**
 * AI Translate — block editor sidebar panel.
 *
 * A thin client over the wp-ai-translate/v1 REST endpoints: shows the current
 * post's language, lets the author set it, and manages its translations —
 * Translate / Recreate (with confirmation) / View / Edit / Unlink / Delete — with
 * success feedback.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { PanelRow, SelectControl, ToggleControl, Button, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, sprintf } from '@wordpress/i18n';
import { buildLanguageOptions, hasSiblings } from './language-options';

const cfg = window.wpaitEditor || {
	namespace: 'wp-ai-translate/v1',
	languages: [],
	defaultLanguage: '',
	aiAvailable: false,
};

const STATUS_LABELS = {
	publish: __( 'Published', 'wp-ai-translate' ),
	draft: __( 'Draft', 'wp-ai-translate' ),
	pending: __( 'Pending', 'wp-ai-translate' ),
	future: __( 'Scheduled', 'wp-ai-translate' ),
	private: __( 'Private', 'wp-ai-translate' ),
	trash: __( 'Trash', 'wp-ai-translate' ),
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
	const [ notice, setNotice ] = useState( '' );
	// Pending confirmation: { action: 'recreate'|'delete', id, code }.
	const [ confirm, setConfirm ] = useState( null );

	const load = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		setLoading( true );
		setError( '' );
		apiFetch( {
			path: addQueryArgs( `/${ cfg.namespace }/translations`, { object_id: postId, type: 'post' } ),
		} )
			.then( ( res ) => setData( res ) )
			.catch( ( e ) => setError( e.message || __( 'Could not load translations.', 'wp-ai-translate' ) ) )
			.finally( () => setLoading( false ) );
	}, [ postId ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const request = ( path, body, key, opts = {} ) => {
		setBusy( key );
		setError( '' );
		setNotice( '' );
		setConfirm( null );
		return apiFetch( { path: `/${ cfg.namespace }/${ path }`, method: 'POST', data: body } )
			.then( ( res ) => {
				if ( opts.reload ) {
					load();
				} else {
					setData( res );
				}
				if ( opts.successMsg ) {
					setNotice( opts.successMsg );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'Request failed.', 'wp-ai-translate' ) ) )
			.finally( () => setBusy( '' ) );
	};

	const setLanguage = ( code ) => {
		if ( ! code ) {
			return;
		}
		return request( 'set-language', { object_id: postId, code, type: 'post' }, 'lang' );
	};

	const setOriginal = ( isOriginal ) =>
		request( 'overview-original', { object_id: postId, type: 'post', is_original: isOriginal }, 'original', {
			reload: true,
			successMsg: isOriginal
				? __( 'Marked as the translation original.', 'wp-ai-translate' )
				: __( 'No longer marked as an original.', 'wp-ai-translate' ),
		} );

	const translate = ( name, code ) =>
		request( 'translate', { source_id: postId, target_code: code, type: 'post' }, code, {
			/* translators: %s: language name. */
			successMsg: sprintf( __( '%s translation created as a draft.', 'wp-ai-translate' ), name ),
		} );

	const recreate = ( name, id, code ) =>
		request( 'recreate', { object_id: id, type: 'post' }, code, {
			/* translators: %s: language name. */
			successMsg: sprintf( __( '%s translation regenerated.', 'wp-ai-translate' ), name ),
		} );

	const unlink = ( name, id, code ) =>
		request( 'unlink', { object_id: id, type: 'post' }, 'unlink-' + code, {
			reload: true,
			/* translators: %s: language name. */
			successMsg: sprintf( __( '%s translation unlinked from this group.', 'wp-ai-translate' ), name ),
		} );

	const del = ( name, id, code ) =>
		request( 'delete', { object_id: id, type: 'post' }, 'del-' + code, {
			reload: true,
			/* translators: %s: language name. */
			successMsg: sprintf( __( '%s translation moved to Trash.', 'wp-ai-translate' ), name ),
		} );

	// Only post/page screens enqueue this script, but guard defensively.
	if ( ! postId || ( postType !== 'post' && postType !== 'page' ) ) {
		return null;
	}

	const currentLang = data ? data.language : '';
	const translations = data ? data.translations : {};
	const isOriginal = data ? !! data.is_original : false;
	const siblingsExist = hasSiblings( translations );

	const languageOptions = buildLanguageOptions(
		cfg.languages,
		currentLang,
		__( '— Not set —', 'wp-ai-translate' )
	);

	const isConfirming = ( action, code ) => confirm && confirm.action === action && confirm.code === code;

	return (
		<PluginDocumentSettingPanel name="wpait-translations" title={ __( 'Translations', 'wp-ai-translate' ) } icon="translation">
			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ notice && (
				<Notice status="success" isDismissible onRemove={ () => setNotice( '' ) }>
					{ notice }
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
				<div style={ { width: '100%' } }>
					<SelectControl
						label={ __( 'Language of this content', 'wp-ai-translate' ) }
						value={ currentLang || '' }
						options={ languageOptions }
						disabled={ loading || busy === 'lang' || siblingsExist }
						onChange={ setLanguage }
						__nextHasNoMarginBottom
					/>
					{ siblingsExist && (
						<p className="description" style={ { margin: '4px 0 0' } }>
							{ __( 'This content belongs to a translation group, so its language is fixed. Unlink its other translations to change it.', 'wp-ai-translate' ) }
						</p>
					) }
				</div>
			</PanelRow>

			<PanelRow>
				<div style={ { width: '100%' } }>
					<ToggleControl
						label={ __( 'Mark as original', 'wp-ai-translate' ) }
						help={
							currentLang
								? __( 'Only originals can be translated into other languages.', 'wp-ai-translate' )
								: __( 'Set a language above before marking this content as an original.', 'wp-ai-translate' )
						}
						checked={ !! ( data && data.is_original ) }
						disabled={ loading || busy === 'original' || ! currentLang }
						onChange={ setOriginal }
						__nextHasNoMarginBottom
					/>
				</div>
			</PanelRow>

			{ loading && ! data ? (
				<PanelRow><Spinner /></PanelRow>
			) : ! currentLang ? (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Set the language of this content above to generate translations.', 'wp-ai-translate' ) }
				</Notice>
			) : (
				<>
				{ ! isOriginal && (
					<Notice status="info" isDismissible={ false }>
						{ __( 'Only originals can be translated. Turn on “Mark as original” above to create translations from this content.', 'wp-ai-translate' ) }
					</Notice>
				) }
				{ cfg.languages
					.filter( ( l ) => l.code !== currentLang )
					.map( ( l ) => {
						const existing = translations[ l.code ];
						const working = busy === l.code || busy === 'unlink-' + l.code || busy === 'del-' + l.code;
						return (
							<PanelRow key={ l.code }>
								<div style={ { width: '100%' } }>
									<strong>{ l.name }</strong>
									{ existing && existing.status && (
										<span className="wpait-status-badge" style={ { marginLeft: '6px', fontSize: '11px', color: '#50575e' } }>
											{ STATUS_LABELS[ existing.status ] || existing.status }
										</span>
									) }
									{ existing ? (
										isConfirming( 'recreate', l.code ) ? (
											<div style={ { marginTop: '4px' } }>
												<p className="description" style={ { margin: '0 0 4px' } }>
													{ sprintf(
														/* translators: %s: language name. */
														__( 'This overwrites the current %s translation. A revision is saved first so you can restore it. Continue?', 'wp-ai-translate' ),
														l.name
													) }
												</p>
												<Button variant="primary" isSmall isBusy={ working } onClick={ () => recreate( l.name, existing.id, l.code ) }>
													{ __( 'Yes, regenerate', 'wp-ai-translate' ) }
												</Button>{ ' ' }
												<Button variant="tertiary" isSmall onClick={ () => setConfirm( null ) }>
													{ __( 'Cancel', 'wp-ai-translate' ) }
												</Button>
											</div>
										) : isConfirming( 'delete', l.code ) ? (
											<div style={ { marginTop: '4px' } }>
												<p className="description" style={ { margin: '0 0 4px' } }>
													{ sprintf(
														/* translators: %s: language name. */
														__( 'Move the %s translation to Trash and unlink it from this group?', 'wp-ai-translate' ),
														l.name
													) }
												</p>
												<Button variant="primary" isDestructive isSmall isBusy={ working } onClick={ () => del( l.name, existing.id, l.code ) }>
													{ __( 'Yes, trash it', 'wp-ai-translate' ) }
												</Button>{ ' ' }
												<Button variant="tertiary" isSmall onClick={ () => setConfirm( null ) }>
													{ __( 'Cancel', 'wp-ai-translate' ) }
												</Button>
											</div>
										) : (
											<div style={ { display: 'flex', gap: '8px', alignItems: 'center', marginTop: '4px', flexWrap: 'wrap' } }>
												{ existing.edit_link && (
													<Button variant="link" href={ existing.edit_link }>{ __( 'Edit', 'wp-ai-translate' ) }</Button>
												) }
												{ existing.view_link && (
													<Button variant="link" href={ existing.view_link }>{ __( 'View', 'wp-ai-translate' ) }</Button>
												) }
												<Button variant="secondary" isSmall isBusy={ working } disabled={ working || ! cfg.aiAvailable } onClick={ () => setConfirm( { action: 'recreate', id: existing.id, code: l.code } ) }>
													{ __( 'Recreate', 'wp-ai-translate' ) }
												</Button>
												<Button variant="link" isBusy={ busy === 'unlink-' + l.code } onClick={ () => unlink( l.name, existing.id, l.code ) }>
													{ __( 'Unlink', 'wp-ai-translate' ) }
												</Button>
												<Button variant="link" isDestructive onClick={ () => setConfirm( { action: 'delete', id: existing.id, code: l.code } ) }>
													{ __( 'Delete', 'wp-ai-translate' ) }
												</Button>
											</div>
										)
									) : (
										<div style={ { marginTop: '4px' } }>
											<Button variant="primary" isSmall isBusy={ working } disabled={ working || ! cfg.aiAvailable || isNew || ! isOriginal } onClick={ () => translate( l.name, l.code ) }>
												{ __( 'Translate', 'wp-ai-translate' ) }
											</Button>
											{ isNew && (
												<p className="description" style={ { margin: '4px 0 0' } }>
													{ __( 'Save this content before translating.', 'wp-ai-translate' ) }
												</p>
											) }
										</div>
									) }
								</div>
							</PanelRow>
						);
					} ) }
				</>
			) }
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'wpait-translations-panel', {
	render: TranslationsPanel,
	icon: 'translation',
} );
