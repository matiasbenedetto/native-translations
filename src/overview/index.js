/**
 * AI Translate — Overview page, rebuilt on @wordpress/dataviews (#54).
 *
 * A controlled DataViews table backed by the REST endpoint
 * `GET wp-ai-translate/v1/overview`. A top-level view switch (Missing vs each
 * enabled language) drives the `view` query param; pagination, sorting and search
 * are server-side. Per-row + bulk actions (Translate to <lang>, Hide/Unhide,
 * Edit, View) are the foundation for the bulk-translate work in #55/#56.
 */

import { createRoot } from '@wordpress/element';
import { useState, useEffect, useMemo, useCallback, useRef } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Button, Flex, FlexItem, __experimentalText as Text } from '@wordpress/components';

// The DataViews stylesheet is copied to build/overview/style-index.css by the
// build:overview-css npm step and enqueued from PHP (wp-scripts' CSS extraction
// drops a bundled-package .css import, so we copy the prebuilt sheet instead).

const cfg = window.wpaitOverview || {
	namespace: 'wp-ai-translate/v1',
	languages: [],
	aiOk: false,
	perPage: 20,
	settingsUrl: '',
};

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: cfg.perPage || 20,
	sort: { field: 'title', direction: 'asc' },
	search: '',
	filters: [],
	titleField: 'title',
	fields: [ 'type_label', 'missing' ],
	layout: {},
};

/**
 * Renders the missing-language names as small chips.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Row.
 * @return {JSX.Element} Chips.
 */
function MissingChips( { item } ) {
	const missing = item.missing || [];
	if ( ! missing.length ) {
		return <span aria-hidden="true">—</span>;
	}
	return (
		<span className="wpait-ov-chips" style={ { display: 'flex', flexWrap: 'wrap', gap: '4px' } }>
			{ missing.map( ( m ) => (
				<span
					key={ m.code }
					className="wpait-ov-chip"
					style={ {
						display: 'inline-block',
						padding: '1px 8px',
						borderRadius: '10px',
						background: '#f0f0f1',
						fontSize: '12px',
						lineHeight: '1.8',
					} }
				>
					{ m.name }
				</span>
			) ) }
		</span>
	);
}

/**
 * The Overview app.
 *
 * @return {JSX.Element} App.
 */
function Overview() {
	// 'missing' or a language code.
	const [ activeView, setActiveView ] = useState( 'missing' );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ data, setData ] = useState( [] );
	const [ paginationInfo, setPaginationInfo ] = useState( { totalItems: 0, totalPages: 1 } );
	const [ counts, setCounts ] = useState( { missing: 0, unmarked: 0, by_language: {} } );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ aiOk, setAiOk ] = useState( cfg.aiOk );
	const [ notice, setNotice ] = useState( '' );
	// Background-queue status: { pending, running, failed }.
	const [ queue, setQueue ] = useState( { pending: 0, running: 0, failed: 0 } );
	const pollRef = useRef( null );
	const [ selection, setSelection ] = useState( [] );

	const languages = cfg.languages || [];
	const isUnmarked = activeView === 'unmarked';
	const isLang = activeView !== 'missing' && ! isUnmarked;

	const fetchData = useCallback( () => {
		setIsLoading( true );
		setError( '' );
		const args = {
			view: activeView,
			page: view.page || 1,
			per_page: view.perPage || cfg.perPage || 20,
			orderby: view.sort?.field === 'type_label' ? 'type' : 'title',
			order: view.sort?.direction || 'asc',
			search: view.search || '',
		};
		apiFetch( { path: addQueryArgs( `/${ cfg.namespace }/overview`, args ) } )
			.then( ( res ) => {
				setData( res.rows || [] );
				setPaginationInfo( { totalItems: res.total || 0, totalPages: res.total_pages || 1 } );
				setCounts( res.counts || { missing: 0, unmarked: 0, by_language: {} } );
				setAiOk( !! res.ai_ok );
			} )
			.catch( ( e ) => setError( e.message || __( 'Could not load the overview.', 'wp-ai-translate' ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ activeView, view.page, view.perPage, view.sort?.field, view.sort?.direction, view.search ] );

	useEffect( () => {
		fetchData();
	}, [ fetchData ] );

	// Keep the latest fetchData in a ref so the polling loop can refetch the table
	// when the queue drains without re-creating the interval on every view change.
	const fetchDataRef = useRef( fetchData );
	useEffect( () => {
		fetchDataRef.current = fetchData;
	}, [ fetchData ] );

	const stopPolling = useCallback( () => {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
		}
	}, [] );

	const pollOnce = useCallback( () => {
		return apiFetch( { path: `/${ cfg.namespace }/queue-status` } )
			.then( ( res ) => {
				const next = {
					pending: res.pending || 0,
					running: res.running || 0,
					failed: res.failed || 0,
				};
				setQueue( next );
				if ( next.pending + next.running <= 0 ) {
					stopPolling();
					// The queue drained — newly created drafts should appear/disappear.
					fetchDataRef.current();
				}
				return next;
			} )
			.catch( () => ( { pending: 0, running: 0, failed: 0 } ) );
	}, [ stopPolling ] );

	const startPolling = useCallback( () => {
		if ( pollRef.current ) {
			return;
		}
		pollRef.current = setInterval( () => {
			pollOnce();
		}, 5000 );
	}, [ pollOnce ] );

	// On mount: if the queue is already busy, show the banner and start polling.
	useEffect( () => {
		pollOnce().then( ( status ) => {
			if ( status.pending + status.running > 0 ) {
				startPolling();
			}
		} );
		return stopPolling;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const switchView = ( next ) => {
		if ( next === activeView ) {
			return;
		}
		setActiveView( next );
		// Reset paging/search/sort when switching views; show the right field.
		setView( {
			...DEFAULT_VIEW,
			fields: next === 'missing' ? [ 'type_label', 'missing' ] : [ 'type_label', 'language' ],
		} );
		setSelection( [] );
	};

	const fields = useMemo( () => {
		const base = [
			{
				id: 'title',
				label: __( 'Title', 'wp-ai-translate' ),
				type: 'text',
				enableSorting: true,
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.title,
				render: ( { item } ) =>
					item.edit_url ? (
						<a href={ item.edit_url }>{ item.title }</a>
					) : (
						<span>{ item.title }</span>
					),
			},
			{
				id: 'type_label',
				label: __( 'Type', 'wp-ai-translate' ),
				type: 'text',
				enableSorting: true,
				elements: [
					{ value: __( 'Post', 'wp-ai-translate' ), label: __( 'Post', 'wp-ai-translate' ) },
					{ value: __( 'Page', 'wp-ai-translate' ), label: __( 'Page', 'wp-ai-translate' ) },
					{ value: __( 'Category', 'wp-ai-translate' ), label: __( 'Category', 'wp-ai-translate' ) },
					{ value: __( 'Tag', 'wp-ai-translate' ), label: __( 'Tag', 'wp-ai-translate' ) },
				],
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => item.type_label,
			},
		];

		if ( isLang || isUnmarked ) {
			base.push( {
				id: 'language',
				label: __( 'Language', 'wp-ai-translate' ),
				type: 'text',
				enableSorting: false,
				getValue: ( { item } ) => ( item.language ? item.language.name : '' ),
				render: ( { item } ) => <span>{ item.language ? item.language.name : '—' }</span>,
			} );
		} else {
			base.push( {
				id: 'missing',
				label: __( 'Missing', 'wp-ai-translate' ),
				type: 'text',
				enableSorting: false,
				getValue: ( { item } ) => ( item.missing || [] ).map( ( m ) => m.name ).join( ', ' ),
				render: ( { item } ) => <MissingChips item={ item } />,
			} );
		}

		return base;
	}, [ isLang, isUnmarked ] );

	const runBulk = useCallback(
		( items, code, name ) => {
			const eligible = items.filter( ( it ) => ( it.missing || [] ).some( ( m ) => m.code === code ) );
			if ( ! eligible.length ) {
				return Promise.resolve();
			}
			setNotice( '' );
			setError( '' );
			return apiFetch( {
				path: `/${ cfg.namespace }/enqueue`,
				method: 'POST',
				data: {
					items: eligible.map( ( it ) => ( { id: it.id, type: it.type } ) ),
					target_code: code,
				},
			} )
				.then( ( res ) => {
					const queued = res.queued || 0;
					if ( queued ) {
						setNotice(
							sprintf(
								/* translators: 1: count, 2: language name. */
								__( '%1$d translation(s) to %2$s queued.', 'wp-ai-translate' ),
								queued,
								name
							)
						);
						// Start (or refresh) the background-status banner + polling.
						pollOnce().then( () => startPolling() );
					} else {
						setNotice(
							sprintf(
								/* translators: %s: language name. */
								__( 'Nothing new to queue for %s.', 'wp-ai-translate' ),
								name
							)
						);
					}
				} )
				.catch( ( e ) =>
					setError( e.message || __( 'Could not queue the translations.', 'wp-ai-translate' ) )
				);
		},
		[ pollOnce, startPolling ]
	);

	// Manual bulk set: synchronously assign one language to the unmarked items (#56).
	const runSetLanguage = useCallback(
		( items, code, name ) => {
			const eligible = items.filter( ( it ) => ! it.language );
			if ( ! eligible.length ) {
				return Promise.resolve();
			}
			setNotice( '' );
			setError( '' );
			return apiFetch( {
				path: `/${ cfg.namespace }/set-languages`,
				method: 'POST',
				data: {
					items: eligible.map( ( it ) => ( { id: it.id, type: it.type } ) ),
					code,
				},
			} )
				.then( ( res ) => {
					const n = res.set || 0;
					setNotice(
						sprintf(
							/* translators: 1: count, 2: language name. */
							__( '%1$d item(s) set to %2$s.', 'wp-ai-translate' ),
							n,
							name
						)
					);
					fetchData();
				} )
				.catch( ( e ) =>
					setError( e.message || __( 'Could not set the language.', 'wp-ai-translate' ) )
				);
		},
		[ fetchData ]
	);

	// AI detection: queue background detection jobs for the unmarked items (#56).
	const runDetect = useCallback(
		( items ) => {
			const eligible = items.filter( ( it ) => ! it.language );
			if ( ! eligible.length ) {
				return Promise.resolve();
			}
			setNotice( '' );
			setError( '' );
			return apiFetch( {
				path: `/${ cfg.namespace }/enqueue-detection`,
				method: 'POST',
				data: { items: eligible.map( ( it ) => ( { id: it.id, type: it.type } ) ) },
			} )
				.then( ( res ) => {
					const queued = res.queued || 0;
					if ( queued ) {
						setNotice(
							sprintf(
								/* translators: %d: count. */
								__( '%d detection job(s) queued.', 'wp-ai-translate' ),
								queued
							)
						);
						pollOnce().then( () => startPolling() );
					} else {
						setNotice( __( 'Nothing new to detect.', 'wp-ai-translate' ) );
					}
				} )
				.catch( ( e ) =>
					setError( e.message || __( 'Could not queue detection.', 'wp-ai-translate' ) )
				);
		},
		[ pollOnce, startPolling ]
	);

	const actions = useMemo( () => {
		const list = [];

		// Manual "Set language: <name>" for each enabled language (#56). Eligible
		// wherever an item has no language; most useful in the Unmarked view.
		languages.forEach( ( lang ) => {
			list.push( {
				id: `set-language-${ lang.code }`,
				label: sprintf(
					/* translators: %s: language name. */
					__( 'Set language: %s', 'wp-ai-translate' ),
					lang.name
				),
				supportsBulk: true,
				isEligible: ( item ) => ! item.language,
				callback: ( items, { onActionPerformed } ) =>
					runSetLanguage( items, lang.code, lang.name ).then( () => {
						if ( onActionPerformed ) {
							onActionPerformed( items );
						}
					} ),
			} );
		} );

		// AI "Detect language (AI)" for unmarked items (#56).
		list.push( {
			id: 'detect-language',
			label: __( 'Detect language (AI)', 'wp-ai-translate' ),
			supportsBulk: true,
			disabled: ! aiOk,
			isEligible: ( item ) => ! item.language && aiOk,
			callback: ( items, { onActionPerformed } ) =>
				runDetect( items ).then( () => {
					if ( onActionPerformed ) {
						onActionPerformed( items );
					}
				} ),
		} );

		// Per-language Translate actions (foundation for #55/#56 bulk translate).
		languages.forEach( ( lang ) => {
			list.push( {
				id: `translate-${ lang.code }`,
				label: sprintf(
					/* translators: %s: language name. */
					__( 'Translate to %s', 'wp-ai-translate' ),
					lang.name
				),
				supportsBulk: true,
				disabled: ! aiOk,
				isEligible: ( item ) => aiOk && ( item.missing || [] ).some( ( m ) => m.code === lang.code ),
				callback: ( items, { onActionPerformed } ) =>
					runBulk( items, lang.code, lang.name ).then( () => {
						if ( onActionPerformed ) {
							onActionPerformed( items );
						}
					} ),
			} );
		} );

		list.push( {
			id: 'edit',
			label: __( 'Edit', 'wp-ai-translate' ),
			isEligible: ( item ) => !! item.edit_url,
			callback: ( items ) => {
				if ( items[ 0 ]?.edit_url ) {
					window.location.href = items[ 0 ].edit_url;
				}
			},
		} );

		list.push( {
			id: 'view',
			label: __( 'View', 'wp-ai-translate' ),
			isEligible: ( item ) => !! item.view_url,
			callback: ( items ) => {
				if ( items[ 0 ]?.view_url ) {
					window.open( items[ 0 ].view_url, '_blank', 'noopener' );
				}
			},
		} );

		const setHidden = ( items, hidden, onActionPerformed ) =>
			Promise.allSettled(
				items.map( ( it ) =>
					apiFetch( {
						path: `/${ cfg.namespace }/overview-visibility`,
						method: 'POST',
						data: { object_id: it.id, type: it.type, hidden },
					} )
				)
			).then( () => {
				fetchData();
				if ( onActionPerformed ) {
					onActionPerformed( items );
				}
			} );

		list.push( {
			id: 'hide',
			label: __( 'Hide from list', 'wp-ai-translate' ),
			supportsBulk: true,
			isEligible: ( item ) => ! isLang && ! item.hidden,
			callback: ( items, { onActionPerformed } ) => setHidden( items, true, onActionPerformed ),
		} );

		list.push( {
			id: 'unhide',
			label: __( 'Unhide', 'wp-ai-translate' ),
			supportsBulk: true,
			isEligible: ( item ) => ! isLang && item.hidden,
			callback: ( items, { onActionPerformed } ) => setHidden( items, false, onActionPerformed ),
		} );

		return list;
	}, [ languages, aiOk, isLang, runBulk, runSetLanguage, runDetect, fetchData ] );

	const missingCount = counts.missing || 0;
	const unmarkedCount = counts.unmarked || 0;

	return (
		<div className="wpait-overview">
			{ ! aiOk && (
				<Notice status="warning" isDismissible={ false }>
					{ cfg.settingsUrl ? (
						<>
							{ __( 'AI translation is unavailable, so Translate actions are disabled.', 'wp-ai-translate' ) }{ ' ' }
							<a href={ cfg.settingsUrl }>{ __( 'Check the AI provider status.', 'wp-ai-translate' ) }</a>
						</>
					) : (
						__( 'AI translation is unavailable, so Translate actions are disabled.', 'wp-ai-translate' )
					) }
				</Notice>
			) }

			{ queue.pending + queue.running > 0 && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: number of translations being processed. */
						__( '%d translation(s) processing in the background…', 'wp-ai-translate' ),
						queue.pending + queue.running
					) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ notice && (
				<Notice status="success" onRemove={ () => setNotice( '' ) }>
					{ notice }
				</Notice>
			) }

			<Flex justify="flex-start" gap={ 2 } wrap style={ { margin: '12px 0' } } className="wpait-ov-tabs">
				<FlexItem>
					<Button
						variant={ activeView === 'missing' ? 'primary' : 'secondary' }
						onClick={ () => switchView( 'missing' ) }
					>
						{ sprintf(
							/* translators: %d: number of items missing translations. */
							__( 'Missing translations (%d)', 'wp-ai-translate' ),
							missingCount
						) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant={ activeView === 'unmarked' ? 'primary' : 'secondary' }
						onClick={ () => switchView( 'unmarked' ) }
					>
						{ sprintf(
							/* translators: %d: number of items with no language assigned. */
							__( 'Unmarked (%d)', 'wp-ai-translate' ),
							unmarkedCount
						) }
					</Button>
				</FlexItem>
				{ languages.map( ( lang ) => (
					<FlexItem key={ lang.code }>
						<Button
							variant={ activeView === lang.code ? 'primary' : 'secondary' }
							onClick={ () => switchView( lang.code ) }
						>
							{ `${ lang.name } (${ counts.by_language?.[ lang.code ] ?? 0 })` }
						</Button>
					</FlexItem>
				) ) }
			</Flex>

			<DataViews
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				actions={ actions }
				isLoading={ isLoading }
				search
				searchLabel={ __( 'Search by title', 'wp-ai-translate' ) }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => item.key }
				selection={ selection }
				onChangeSelection={ setSelection }
				empty={ <Text>{ __( 'Nothing to show here.', 'wp-ai-translate' ) }</Text> }
			/>
		</div>
	);
}

const mount = document.getElementById( 'wpait-overview-app' );
if ( mount ) {
	createRoot( mount ).render( <Overview /> );
}
