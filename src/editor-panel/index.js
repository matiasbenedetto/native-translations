/**
 * WP Native Translations — block editor sidebar panel.
 *
 * Shows the current post's language and original flag — edited through the
 * editor's native dirty state and saved on Update (#106) — and manages its
 * translations (Translate / Recreate / View / Edit / Unlink / Delete) over the
 * native-translations/v1 REST endpoints, with success feedback.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { PanelRow, SelectControl, ToggleControl, Button, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, sprintf } from '@wordpress/i18n';
import { buildLanguageOptions, hasSiblings } from './language-options';

// Post meta keys (kept in sync with Wpnt_Editor): the original flag (the store's
// own meta) and the staging field the language picker writes into (#106).
const META_IS_ORIGINAL = '_wpnt_is_original';
const META_EDITOR_LANGUAGE = '_wpnt_editor_language';

const cfg = window.wpntEditor || {
	namespace: 'native-translations/v1',
	languages: [],
	defaultLanguage: '',
	aiAvailable: false,
};

const STATUS_LABELS = {
	publish: __( 'Published', 'native-translations' ),
	draft: __( 'Draft', 'native-translations' ),
	pending: __( 'Pending', 'native-translations' ),
	future: __( 'Scheduled', 'native-translations' ),
	private: __( 'Private', 'native-translations' ),
	trash: __( 'Trash', 'native-translations' ),
};

const TranslationsPanel = () => {
	const { postId, postType, isNew, isSaving } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			isNew: editor.isEditedPostNew(),
			// A real (non-autosave) save in flight; the load-on-completion effect
			// below refetches the saved-state view once it finishes (#106).
			isSaving: editor.isSavingPost() && ! editor.isAutosavingPost(),
		};
	}, [] );

	// Language + original are edited through native post meta so they join the
	// editor's dirty state and persist on Save/Update (#106). The original flag is
	// the store's own meta; the language picker stages its choice in a separate
	// field that the server consumes + clears on save.
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const isOriginalPending = !! ( meta && meta[ META_IS_ORIGINAL ] );
	const stagedLanguage = ( meta && meta[ META_EDITOR_LANGUAGE ] ) || '';

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
			.catch( ( e ) => setError( e.message || __( 'Could not load translations.', 'native-translations' ) ) )
			.finally( () => setLoading( false ) );
	}, [ postId ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// After a save settles, the server has applied (and cleared) the staged
	// language and reconciled the original flag — refetch the saved-state view so
	// the translations list + Translate gating reflect it.
	const wasSaving = useRef( false );
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			load();
		}
		wasSaving.current = isSaving;
	}, [ isSaving, load ] );

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
			.catch( ( e ) => setError( e.message || __( 'Request failed.', 'native-translations' ) ) )
			.finally( () => setBusy( '' ) );
	};

	// Stage the chosen language into post meta (marks the post dirty; applied on
	// Save — #106) rather than writing immediately over REST.
	const setLanguage = ( code ) => {
		if ( ! code ) {
			return;
		}
		setMeta( { ...meta, [ META_EDITOR_LANGUAGE ]: code } );
	};

	// Toggle the original flag through native meta (dirty + save on Update — #106).
	const setOriginal = ( isOriginal ) =>
		setMeta( { ...meta, [ META_IS_ORIGINAL ]: isOriginal } );

	const translate = ( name, code ) =>
		request( 'translate', { source_id: postId, target_code: code, type: 'post' }, code, {
			/* translators: %s: language name. */
			successMsg: sprintf( __( '%s translation created as a draft.', 'native-translations' ), name ),
		} );

	const recreate = ( name, id, code ) =>
		request( 'recreate', { object_id: id, type: 'post' }, code, {
			/* translators: %s: language name. */
			successMsg: sprintf( __( '%s translation regenerated.', 'native-translations' ), name ),
		} );

	const unlink = ( name, id, code ) =>
		request( 'unlink', { object_id: id, type: 'post' }, 'unlink-' + code, {
			reload: true,
			/* translators: %s: language name. */
			successMsg: sprintf( __( '%s translation unlinked from this group.', 'native-translations' ), name ),
		} );

	const del = ( name, id, code ) =>
		request( 'delete', { object_id: id, type: 'post' }, 'del-' + code, {
			reload: true,
			/* translators: %s: language name. */
			successMsg: sprintf( __( '%s translation moved to Trash.', 'native-translations' ), name ),
		} );

	// Only post/page screens enqueue this script, but guard defensively.
	if ( ! postId || ( postType !== 'post' && postType !== 'page' ) ) {
		return null;
	}

	// Saved state (from REST): drives the translations list + the #104 Translate
	// gating, since translating acts on the persisted language/original.
	const savedLang = data ? data.language : '';
	const savedIsOriginal = data ? !! data.is_original : false;
	const translations = data ? data.translations : {};
	const siblingsExist = hasSiblings( translations );

	// Edited (dirty-aware) state shown in the controls: the staged language wins
	// over the saved one until it is saved + consumed server-side (#106).
	const currentLang = stagedLanguage || savedLang;
	const isOriginal = savedIsOriginal;
	const hasPendingChange =
		( !! stagedLanguage && stagedLanguage !== savedLang ) || isOriginalPending !== savedIsOriginal;

	const languageOptions = buildLanguageOptions(
		cfg.languages,
		currentLang,
		__( '— Not set —', 'native-translations' )
	);

	const isConfirming = ( action, code ) => confirm && confirm.action === action && confirm.code === code;

	return (
		<PluginDocumentSettingPanel name="wpnt-translations" title={ __( 'Translations', 'native-translations' ) } icon="translation">
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
					{ __( 'No AI provider is configured, so new translations cannot be generated.', 'native-translations' ) }
				</Notice>
			) }

			{ isNew && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Save this content before generating translations.', 'native-translations' ) }
				</Notice>
			) }

			<PanelRow>
				<div style={ { width: '100%' } }>
					<SelectControl
						label={ __( 'Language of this content', 'native-translations' ) }
						value={ currentLang || '' }
						options={ languageOptions }
						disabled={ loading || siblingsExist }
						onChange={ setLanguage }
						__nextHasNoMarginBottom
					/>
					{ siblingsExist && (
						<p className="description" style={ { margin: '4px 0 0' } }>
							{ __( 'This content belongs to a translation group, so its language is fixed. Unlink its other translations to change it.', 'native-translations' ) }
						</p>
					) }
				</div>
			</PanelRow>

			<PanelRow>
				<div style={ { width: '100%' } }>
					<ToggleControl
						label={ __( 'Mark as original', 'native-translations' ) }
						help={
							currentLang
								? __( 'Only originals can be translated into other languages.', 'native-translations' )
								: __( 'Set a language above before marking this content as an original.', 'native-translations' )
						}
						checked={ isOriginalPending }
						disabled={ loading || ! currentLang }
						onChange={ setOriginal }
						__nextHasNoMarginBottom
					/>
					{ hasPendingChange && (
						<p className="description" style={ { margin: '4px 0 0' } }>
							{ __( 'Unsaved changes — save or update this content to apply.', 'native-translations' ) }
						</p>
					) }
				</div>
			</PanelRow>

			{ loading && ! data ? (
				<PanelRow><Spinner /></PanelRow>
			) : ! currentLang ? (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Set the language of this content above to generate translations.', 'native-translations' ) }
				</Notice>
			) : (
				<>
				{ ! isOriginal && (
					<Notice status="info" isDismissible={ false }>
						{ __( 'Only originals can be translated. Turn on “Mark as original” above to create translations from this content.', 'native-translations' ) }
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
										<span className="wpnt-status-badge" style={ { marginLeft: '6px', fontSize: '11px', color: '#50575e' } }>
											{ STATUS_LABELS[ existing.status ] || existing.status }
										</span>
									) }
									{ existing ? (
										isConfirming( 'recreate', l.code ) ? (
											<div style={ { marginTop: '4px' } }>
												<p className="description" style={ { margin: '0 0 4px' } }>
													{ sprintf(
														/* translators: %s: language name. */
														__( 'This overwrites the current %s translation. A revision is saved first so you can restore it. Continue?', 'native-translations' ),
														l.name
													) }
												</p>
												<Button variant="primary" isSmall isBusy={ working } onClick={ () => recreate( l.name, existing.id, l.code ) }>
													{ __( 'Yes, regenerate', 'native-translations' ) }
												</Button>{ ' ' }
												<Button variant="tertiary" isSmall onClick={ () => setConfirm( null ) }>
													{ __( 'Cancel', 'native-translations' ) }
												</Button>
											</div>
										) : isConfirming( 'delete', l.code ) ? (
											<div style={ { marginTop: '4px' } }>
												<p className="description" style={ { margin: '0 0 4px' } }>
													{ sprintf(
														/* translators: %s: language name. */
														__( 'Move the %s translation to Trash and unlink it from this group?', 'native-translations' ),
														l.name
													) }
												</p>
												<Button variant="primary" isDestructive isSmall isBusy={ working } onClick={ () => del( l.name, existing.id, l.code ) }>
													{ __( 'Yes, trash it', 'native-translations' ) }
												</Button>{ ' ' }
												<Button variant="tertiary" isSmall onClick={ () => setConfirm( null ) }>
													{ __( 'Cancel', 'native-translations' ) }
												</Button>
											</div>
										) : (
											<div style={ { display: 'flex', gap: '8px', alignItems: 'center', marginTop: '4px', flexWrap: 'wrap' } }>
												{ existing.edit_link && (
													<Button variant="link" href={ existing.edit_link }>{ __( 'Edit', 'native-translations' ) }</Button>
												) }
												{ existing.view_link && (
													<Button variant="link" href={ existing.view_link }>{ __( 'View', 'native-translations' ) }</Button>
												) }
												<Button variant="secondary" isSmall isBusy={ working } disabled={ working || ! cfg.aiAvailable } onClick={ () => setConfirm( { action: 'recreate', id: existing.id, code: l.code } ) }>
													{ __( 'Recreate', 'native-translations' ) }
												</Button>
												<Button variant="link" isBusy={ busy === 'unlink-' + l.code } onClick={ () => unlink( l.name, existing.id, l.code ) }>
													{ __( 'Unlink', 'native-translations' ) }
												</Button>
												<Button variant="link" isDestructive onClick={ () => setConfirm( { action: 'delete', id: existing.id, code: l.code } ) }>
													{ __( 'Delete', 'native-translations' ) }
												</Button>
											</div>
										)
									) : (
										<div style={ { marginTop: '4px' } }>
											<Button variant="primary" isSmall isBusy={ working } disabled={ working || ! cfg.aiAvailable || isNew || ! isOriginal } onClick={ () => translate( l.name, l.code ) }>
												{ __( 'Translate', 'native-translations' ) }
											</Button>
											{ isNew && (
												<p className="description" style={ { margin: '4px 0 0' } }>
													{ __( 'Save this content before translating.', 'native-translations' ) }
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

registerPlugin( 'wpnt-translations-panel', {
	render: TranslationsPanel,
	icon: 'translation',
} );
