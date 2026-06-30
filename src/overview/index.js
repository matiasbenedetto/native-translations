/**
 * WP Native Translations — Overview page, rebuilt on @wordpress/dataviews (#54).
 *
 * A controlled DataViews table backed by the REST endpoint
 * `GET native-translations/v1/overview`. A top-level view switch (Originals, Unmarked,
 * or each enabled language) drives the `view` query param; pagination, sorting and
 * search are server-side. Per-row + bulk actions (Translate to <lang>, Hide/Unhide,
 * Mark/Unmark as original, Edit, View) are the foundation for the bulk-translate
 * work in #55/#56.
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

const cfg = window.wpntOverview || {
	namespace: 'native-translations/v1',
	languages: [],
	aiOk: false,
	perPage: 20,
	settingsUrl: '',
	queueUrl: '',
};

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: cfg.perPage || 20,
	sort: { field: 'title', direction: 'asc' },
	search: '',
	filters: [],
	titleField: 'title',
	mediaField: 'thumbnail',
	fields: [ 'type_label', 'language', 'missing' ],
	layout: {},
};

// Side length (px) of the square featured-image thumbnail / placeholder rendered
// in the Overview. A single constant keeps the real <img> and the gray
// placeholder identical so table rows stay aligned.
const THUMB_SIZE = 40;

/**
 * Renders a fixed-size square for a row's featured image: the image when the
 * entity has one, otherwise a gray placeholder of identical dimensions so rows
 * stay aligned. Terms (categories/tags) never have a featured image.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Row.
 * @return {JSX.Element} Square thumbnail or placeholder.
 */
function Thumbnail( { item } ) {
	const box = {
		width: `${ THUMB_SIZE }px`,
		height: `${ THUMB_SIZE }px`,
		borderRadius: '2px',
		flexShrink: 0,
	};
	if ( item.thumbnail ) {
		return (
			<img
				src={ item.thumbnail }
				alt={ sprintf(
					/* translators: %s: entity title. */
					__( 'Featured image for %s', 'native-translations' ),
					item.title
				) }
				width={ THUMB_SIZE }
				height={ THUMB_SIZE }
				loading="lazy"
				style={ { ...box, objectFit: 'cover', display: 'block' } }
			/>
		);
	}
	return (
		<div
			aria-hidden="true"
			style={ { ...box, backgroundColor: '#e0e0e0' } }
		/>
	);
}

/**
 * Renders a row's text snippet below its title: smaller and muted (lower visual
 * hierarchy than the title) and clamped to two lines so rows stay compact. The
 * Overview CSS pipeline only vendors the DataViews stylesheet, so the clamp is
 * applied inline. Renders nothing when the row has no snippet, so the title
 * block stays clean (no stray empty line/separator).
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Row.
 * @return {JSX.Element|null} Snippet, or null when empty.
 */
function Snippet( { item } ) {
	const snippet = ( item.snippet || '' ).trim();
	if ( ! snippet ) {
		return null;
	}
	return (
		<span
			className="wpnt-ov-snippet"
			style={ {
				display: '-webkit-box',
				WebkitLineClamp: 2,
				WebkitBoxOrient: 'vertical',
				overflow: 'hidden',
				marginTop: '2px',
				color: '#757575',
				fontSize: '12px',
				lineHeight: '1.4',
				whiteSpace: 'normal',
				wordBreak: 'break-word',
			} }
		>
			{ snippet }
		</span>
	);
}

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
		<span className="wpnt-ov-chips" style={ { display: 'flex', flexWrap: 'wrap', gap: '4px' } }>
			{ missing.map( ( m ) => (
				<span
					key={ m.code }
					className="wpnt-ov-chip"
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
	// 'originals', 'unmarked', or a language code.
	const [ activeView, setActiveView ] = useState( 'originals' );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ data, setData ] = useState( [] );
	const [ paginationInfo, setPaginationInfo ] = useState( { totalItems: 0, totalPages: 1 } );
	const [ counts, setCounts ] = useState( { originals: 0, unmarked: 0, by_language: {} } );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ aiOk, setAiOk ] = useState( cfg.aiOk );
	const [ notice, setNotice ] = useState( '' );
	// Background-queue status: { pending, running, failed }.
	const [ queue, setQueue ] = useState( { pending: 0, running: 0, failed: 0 } );
	const pollRef = useRef( null );
	const [ selection, setSelection ] = useState( [] );
	// Failed background jobs surfaced in the banner (#69).
	const [ failedJobs, setFailedJobs ] = useState( [] );
	const [ showFailed, setShowFailed ] = useState( false );
	const [ retryingId, setRetryingId ] = useState( 0 );
	// Queued + processing jobs the user can inspect (#81).
	const [ queueJobs, setQueueJobs ] = useState( [] );
	const [ showQueue, setShowQueue ] = useState( false );
	const showQueueRef = useRef( false );

	const languages = cfg.languages || [];
	const hasLanguages = languages.length > 0;
	const isOriginals = activeView === 'originals';
	const isUnmarked = activeView === 'unmarked';
	const isLang = ! isOriginals && ! isUnmarked;
	// One-shot guard so the "default to Unmarked when there are 0 originals" rule
	// only fires on the very first load, never overriding later navigation (#92).
	const didInitialDefault = useRef( false );

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
				const nextCounts = res.counts || { originals: 0, unmarked: 0, by_language: {} };
				setCounts( nextCounts );
				setAiOk( !! res.ai_ok );
				// On first load, if nothing is marked as original, fall back to the
				// Unmarked view so the page doesn't open on an empty list (#92).
				if ( ! didInitialDefault.current ) {
					didInitialDefault.current = true;
					if ( activeView === 'originals' && ( nextCounts.originals || 0 ) === 0 ) {
						setActiveView( 'unmarked' );
						setView( { ...DEFAULT_VIEW, fields: [ 'type_label', 'language' ] } );
						setSelection( [] );
					}
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'Could not load the overview.', 'native-translations' ) ) )
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

	// Load the failed-jobs list (which items failed + why) on demand (#69).
	const loadFailed = useCallback( () => {
		return apiFetch( { path: `/${ cfg.namespace }/failed-jobs` } )
			.then( ( res ) => {
				setFailedJobs( res.jobs || [] );
				return res.jobs || [];
			} )
			.catch( () => {
				setFailedJobs( [] );
				return [];
			} );
	}, [] );

	const toggleFailed = useCallback( () => {
		setShowFailed( ( prev ) => {
			const next = ! prev;
			if ( next ) {
				loadFailed();
			}
			return next;
		} );
	}, [ loadFailed ] );

	const retryJob = useCallback(
		( actionId ) => {
			setRetryingId( actionId );
			apiFetch( {
				path: `/${ cfg.namespace }/retry-job`,
				method: 'POST',
				data: { action_id: actionId },
			} )
				.then( () => {
					setNotice( __( 'Job re-queued.', 'native-translations' ) );
					return Promise.all( [ loadFailed(), pollOnce() ] );
				} )
				.then( () => startPolling() )
				.catch( ( e ) =>
					setError( e.message || __( 'Could not re-run the job.', 'native-translations' ) )
				)
				.finally( () => setRetryingId( 0 ) );
		},
		[ loadFailed, pollOnce, startPolling ]
	);

	// Load the queued + processing jobs list on demand (#81).
	const loadQueue = useCallback( () => {
		return apiFetch( { path: `/${ cfg.namespace }/queue-jobs` } )
			.then( ( res ) => {
				setQueueJobs( res.jobs || [] );
				return res.jobs || [];
			} )
			.catch( () => {
				setQueueJobs( [] );
				return [];
			} );
	}, [] );

	const toggleQueue = useCallback( () => {
		setShowQueue( ( prev ) => {
			const next = ! prev;
			showQueueRef.current = next;
			if ( next ) {
				loadQueue();
			}
			return next;
		} );
	}, [ loadQueue ] );

	// While the queued-jobs list is open, refresh it on each poll tick so items
	// move/clear as they process.
	useEffect( () => {
		if ( ! showQueue ) {
			return undefined;
		}
		const id = setInterval( () => {
			if ( queue.pending + queue.running > 0 ) {
				loadQueue();
			}
		}, 5000 );
		return () => clearInterval( id );
	}, [ showQueue, loadQueue, queue.pending, queue.running ] );

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
		// Reset paging/search/sort when switching views; show the right fields. The
		// Originals view shows both the source language and the still-missing
		// translations; other views show just the language.
		setView( {
			...DEFAULT_VIEW,
			fields: next === 'originals' ? [ 'type_label', 'language', 'missing' ] : [ 'type_label', 'language' ],
		} );
		setSelection( [] );
	};

	const fields = useMemo( () => {
		const base = [
			{
				id: 'thumbnail',
				label: __( 'Image', 'native-translations' ),
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				getValue: ( { item } ) => item.thumbnail || '',
				render: ( { item } ) => <Thumbnail item={ item } />,
			},
			{
				id: 'title',
				label: __( 'Title', 'native-translations' ),
				type: 'text',
				enableSorting: true,
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.title,
				render: ( { item } ) => (
					<span
						className="wpnt-ov-title-block"
						style={ { display: 'block' } }
					>
						<span style={ { display: 'block' } }>
							{ item.edit_url ? (
								<a href={ item.edit_url }>
									{ item.title }
								</a>
							) : (
								item.title
							) }
						</span>
						<Snippet item={ item } />
					</span>
				),
			},
			{
				id: 'type_label',
				label: __( 'Type', 'native-translations' ),
				type: 'text',
				enableSorting: true,
				elements: [
					{ value: __( 'Post', 'native-translations' ), label: __( 'Post', 'native-translations' ) },
					{ value: __( 'Page', 'native-translations' ), label: __( 'Page', 'native-translations' ) },
					{ value: __( 'Category', 'native-translations' ), label: __( 'Category', 'native-translations' ) },
					{ value: __( 'Tag', 'native-translations' ), label: __( 'Tag', 'native-translations' ) },
				],
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) => item.type_label,
			},
		];

		if ( isOriginals || isLang || isUnmarked ) {
			base.push( {
				id: 'language',
				label: __( 'Language', 'native-translations' ),
				type: 'text',
				enableSorting: false,
				getValue: ( { item } ) => ( item.language ? item.language.name : '' ),
				render: ( { item } ) => <span>{ item.language ? item.language.name : '—' }</span>,
			} );
		}

		if ( isOriginals ) {
			base.push( {
				id: 'missing',
				label: __( 'Missing', 'native-translations' ),
				type: 'text',
				enableSorting: false,
				getValue: ( { item } ) => ( item.missing || [] ).map( ( m ) => m.name ).join( ', ' ),
				render: ( { item } ) => <MissingChips item={ item } />,
			} );
		}

		return base;
	}, [ isOriginals, isLang, isUnmarked ] );

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
								__( '%1$d translation(s) to %2$s queued.', 'native-translations' ),
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
								__( 'Nothing new to queue for %s.', 'native-translations' ),
								name
							)
						);
					}
				} )
				.catch( ( e ) =>
					setError( e.message || __( 'Could not queue the translations.', 'native-translations' ) )
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
							__( '%1$d item(s) set to %2$s.', 'native-translations' ),
							n,
							name
						)
					);
					fetchData();
				} )
				.catch( ( e ) =>
					setError( e.message || __( 'Could not set the language.', 'native-translations' ) )
				);
		},
		[ fetchData ]
	);

	// Change an already-assigned language (#103). Reuses /set-languages so the
	// store's group invariants apply; a grouped member with siblings is refused
	// (wpnt_language_reassign) and surfaced per item rather than failing silently.
	const runChangeLanguage = useCallback(
		( items, code, name ) => {
			const eligible = items.filter( ( it ) => it.language && it.language.code !== code );
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
					const errs = ( res.results || [] ).filter( ( r ) => 'error' === r.status );
					if ( errs.length ) {
						setError(
							errs[ 0 ].message ||
								__( 'Some items could not be changed — unlink them from their translation group first.', 'native-translations' )
						);
					}
					if ( n ) {
						setNotice(
							sprintf(
								/* translators: 1: count, 2: language name. */
								__( '%1$d item(s) changed to %2$s.', 'native-translations' ),
								n,
								name
							)
						);
					}
					fetchData();
				} )
				.catch( ( e ) =>
					setError( e.message || __( 'Could not change the language.', 'native-translations' ) )
				);
		},
		[ fetchData ]
	);

	// Unset an entity's language (#103): returns it to the Unmarked state, clearing
	// the original flag and unlinking it from its group (siblings keep their slots).
	const runClearLanguage = useCallback(
		( items ) => {
			const eligible = items.filter( ( it ) => !! it.language );
			if ( ! eligible.length ) {
				return Promise.resolve();
			}
			setNotice( '' );
			setError( '' );
			return Promise.allSettled(
				eligible.map( ( it ) =>
					apiFetch( {
						path: `/${ cfg.namespace }/clear-language`,
						method: 'POST',
						data: { object_id: it.id, type: it.type },
					} )
				)
			).then( ( results ) => {
				const failed = results.filter( ( r ) => 'rejected' === r.status );
				const succeeded = results.length - failed.length;
				if ( failed.length ) {
					setError(
						failed[ 0 ].reason?.message ||
							__( 'Could not unset the language.', 'native-translations' )
					);
				}
				if ( succeeded ) {
					setNotice(
						sprintf(
							/* translators: %d: count. */
							__( '%d item(s) returned to Unmarked.', 'native-translations' ),
							succeeded
						)
					);
				}
				fetchData();
			} );
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
								__( '%d detection job(s) queued.', 'native-translations' ),
								queued
							)
						);
						pollOnce().then( () => startPolling() );
					} else {
						setNotice( __( 'Nothing new to detect.', 'native-translations' ) );
					}
				} )
				.catch( ( e ) =>
					setError( e.message || __( 'Could not queue detection.', 'native-translations' ) )
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
					__( 'Set language: %s', 'native-translations' ),
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

		// Manual "Change language to <name>" for items that already have a language
		// (#103). The store enforces group invariants; a grouped member is refused
		// and surfaced rather than silently relabeled.
		languages.forEach( ( lang ) => {
			list.push( {
				id: `change-language-${ lang.code }`,
				label: sprintf(
					/* translators: %s: language name. */
					__( 'Change language to %s', 'native-translations' ),
					lang.name
				),
				supportsBulk: true,
				isEligible: ( item ) => !! item.language && item.language.code !== lang.code,
				callback: ( items, { onActionPerformed } ) =>
					runChangeLanguage( items, lang.code, lang.name ).then( () => {
						if ( onActionPerformed ) {
							onActionPerformed( items );
						}
					} ),
			} );
		} );

		// "Unset language" — returns an item to Unmarked (#103).
		list.push( {
			id: 'clear-language',
			label: __( 'Unset language', 'native-translations' ),
			supportsBulk: true,
			isEligible: ( item ) => !! item.language,
			callback: ( items, { onActionPerformed } ) =>
				runClearLanguage( items ).then( () => {
					if ( onActionPerformed ) {
						onActionPerformed( items );
					}
				} ),
		} );

		// AI "Detect language (AI)" for unmarked items (#56). Detection needs at
		// least one configured target language; with none, omit the action entirely.
		if ( hasLanguages ) {
			list.push( {
				id: 'detect-language',
				label: __( 'Detect language (AI)', 'native-translations' ),
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
		}

		// Per-language Translate actions (foundation for #55/#56 bulk translate).
		languages.forEach( ( lang ) => {
			list.push( {
				id: `translate-${ lang.code }`,
				label: sprintf(
					/* translators: %s: language name. */
					__( 'Translate to %s', 'native-translations' ),
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
			label: __( 'Edit', 'native-translations' ),
			isEligible: ( item ) => !! item.edit_url,
			callback: ( items ) => {
				if ( items[ 0 ]?.edit_url ) {
					window.location.href = items[ 0 ].edit_url;
				}
			},
		} );

		list.push( {
			id: 'view',
			label: __( 'View', 'native-translations' ),
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
			label: __( 'Hide from list', 'native-translations' ),
			supportsBulk: true,
			isEligible: ( item ) => ! isLang && ! item.hidden,
			callback: ( items, { onActionPerformed } ) => setHidden( items, true, onActionPerformed ),
		} );

		list.push( {
			id: 'unhide',
			label: __( 'Unhide', 'native-translations' ),
			supportsBulk: true,
			isEligible: ( item ) => ! isLang && item.hidden,
			callback: ( items, { onActionPerformed } ) => setHidden( items, false, onActionPerformed ),
		} );

		// Mark / unmark as original (#92). Marking is only offered for items that
		// already have a language set; the server still rejects ineligible items
		// (the wpnt_no_language 409 backstop, or a 403 from a permission/race),
		// so surface those failures the way the other write actions do.
		const setOriginal = ( items, isOriginal, onActionPerformed ) => {
			setNotice( '' );
			setError( '' );
			return Promise.allSettled(
				items.map( ( it ) =>
					apiFetch( {
						path: `/${ cfg.namespace }/overview-original`,
						method: 'POST',
						data: { object_id: it.id, type: it.type, is_original: isOriginal },
					} )
				)
			).then( ( results ) => {
				const failed = results.filter( ( r ) => 'rejected' === r.status );
				const succeeded = results.length - failed.length;
				if ( failed.length ) {
					setError(
						failed[ 0 ].reason?.message ||
							( isOriginal
								? __( 'Could not mark as original.', 'native-translations' )
								: __( 'Could not unmark as original.', 'native-translations' ) )
					);
				} else if ( succeeded ) {
					setNotice(
						isOriginal
							? __( 'Marked as original.', 'native-translations' )
							: __( 'Unmarked as original.', 'native-translations' )
					);
				}
				fetchData();
				if ( onActionPerformed ) {
					onActionPerformed( items );
				}
			} );
		};

		list.push( {
			id: 'mark-original',
			label: __( 'Mark as original', 'native-translations' ),
			supportsBulk: true,
			isEligible: ( item ) => !! item.language && ! item.is_original,
			callback: ( items, { onActionPerformed } ) => setOriginal( items, true, onActionPerformed ),
		} );

		list.push( {
			id: 'unmark-original',
			label: __( 'Unmark as original', 'native-translations' ),
			supportsBulk: true,
			isEligible: ( item ) => !! item.is_original,
			callback: ( items, { onActionPerformed } ) => setOriginal( items, false, onActionPerformed ),
		} );

		return list;
	}, [ languages, hasLanguages, aiOk, isLang, runBulk, runSetLanguage, runChangeLanguage, runClearLanguage, runDetect, fetchData ] );

	const originalsCount = counts.originals || 0;
	const unmarkedCount = counts.unmarked || 0;

	// Short, muted line explaining what the current selection lists. Updates
	// with the active view; each selection has its own translatable copy (#93).
	const selectionDescription = useMemo( () => {
		if ( isOriginals ) {
			return __(
				"Source entities you've marked as original. Translations are created from these.",
				'native-translations'
			);
		}
		if ( isUnmarked ) {
			return __(
				"Entities that don't have a language assigned yet. Mark or detect a language to start translating them.",
				'native-translations'
			);
		}
		const lang = languages.find( ( l ) => l.code === activeView );
		return sprintf(
			/* translators: %s: language name (e.g. "Spanish"). */
			__( 'Entities assigned to %s.', 'native-translations' ),
			lang ? lang.name : activeView
		);
	}, [ isOriginals, isUnmarked, activeView, languages ] );

	return (
		<div className="wpnt-overview">
			{ ! aiOk && (
				<Notice status="warning" isDismissible={ false }>
					{ cfg.settingsUrl ? (
						<>
							{ __( 'AI translation is unavailable, so Translate actions are disabled.', 'native-translations' ) }{ ' ' }
							<a href={ cfg.settingsUrl }>{ __( 'Check the AI provider status.', 'native-translations' ) }</a>
						</>
					) : (
						__( 'AI translation is unavailable, so Translate actions are disabled.', 'native-translations' )
					) }
				</Notice>
			) }

			{ ! hasLanguages && (
				<Notice status="warning" isDismissible={ false }>
					{ cfg.settingsUrl ? (
						<>
							{ __( 'Configure at least one language before detecting content languages.', 'native-translations' ) }{ ' ' }
							<a href={ cfg.settingsUrl }>{ __( 'Open language settings.', 'native-translations' ) }</a>
						</>
					) : (
						__( 'Configure at least one language before detecting content languages.', 'native-translations' )
					) }
				</Notice>
			) }

			{ queue.pending + queue.running > 0 && (
				<Notice status="info" isDismissible={ false }>
					<span>
						{ sprintf(
							/* translators: %d: number of translations being processed. */
							__( '%d translation(s) processing in the background…', 'native-translations' ),
							queue.pending + queue.running
						) }
					</span>{ ' ' }
					<button
						type="button"
						className="button-link"
						onClick={ toggleQueue }
					>
						{ showQueue
							? __( 'Hide queue', 'native-translations' )
							: __( 'Show queue', 'native-translations' ) }
					</button>
					{ cfg.queueUrl && (
						<>
							{ ' ' }
							<a href={ cfg.queueUrl }>
								{ __( 'Manage the Translation Queue', 'native-translations' ) }
							</a>
						</>
					) }
					{ showQueue && (
						<ul className="wpnt-queue-list" style={ { margin: '8px 0 0' } }>
							{ queueJobs.length === 0 && (
								<li>{ __( 'No queued jobs.', 'native-translations' ) }</li>
							) }
							{ queueJobs.map( ( job ) => (
								<li key={ job.action_id }>
									<strong>{ job.title }</strong>
									{ 'translate' === job.kind && job.target
										? ` → ${ job.target }`
										: '' }
									{ 'detect' === job.kind
										? ` — ${ __( 'detect language', 'native-translations' ) }`
										: '' }
									{ ' ' }
									<span className="wpnt-queue-state">
										{ 'running' === job.state
											? __( '(processing…)', 'native-translations' )
											: __( '(queued)', 'native-translations' ) }
									</span>
								</li>
							) ) }
						</ul>
					) }
				</Notice>
			) }

			{ queue.failed > 0 && (
				<Notice status="error" isDismissible={ false }>
					<span>
						{ sprintf(
							/* translators: %d: number of failed background jobs. */
							__( '%d background job(s) failed.', 'native-translations' ),
							queue.failed
						) }
					</span>{ ' ' }
					<button
						type="button"
						className="button-link"
						onClick={ toggleFailed }
					>
						{ showFailed
							? __( 'Hide details', 'native-translations' )
							: __( 'Show details', 'native-translations' ) }
					</button>
					{ cfg.queueUrl && (
						<>
							{ ' ' }
							<a href={ cfg.queueUrl }>
								{ __( 'Manage the Translation Queue', 'native-translations' ) }
							</a>
						</>
					) }
					{ showFailed && (
						<ul className="wpnt-failed-list" style={ { margin: '8px 0 0' } }>
							{ failedJobs.length === 0 && (
								<li>{ __( 'No failed jobs found.', 'native-translations' ) }</li>
							) }
							{ failedJobs.map( ( job ) => (
								<li key={ job.action_id } style={ { marginBottom: '8px' } }>
									<strong>{ job.title }</strong>
									{ 'translate' === job.kind && job.target
										? ` → ${ job.target }`
										: '' }
									{ ' — ' }
									<span className="wpnt-failed-msg">{ job.message }</span>{ ' ' }
									<button
										type="button"
										className="button button-small"
										disabled={ retryingId !== 0 }
										onClick={ () => retryJob( job.action_id ) }
									>
										{ retryingId === job.action_id
											? __( 'Re-running…', 'native-translations' )
											: __( 'Re-run', 'native-translations' ) }
									</button>
								</li>
							) ) }
						</ul>
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

			<Flex justify="flex-start" gap={ 2 } wrap style={ { margin: '12px 0' } } className="wpnt-ov-tabs">
				<FlexItem>
					<Button
						variant={ activeView === 'originals' ? 'primary' : 'secondary' }
						onClick={ () => switchView( 'originals' ) }
					>
						{ sprintf(
							/* translators: %d: number of items marked as originals. */
							__( 'Originals (%d)', 'native-translations' ),
							originalsCount
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
							__( 'Unmarked (%d)', 'native-translations' ),
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

			<Text
				className="wpnt-ov-selection-desc"
				as="p"
				variant="muted"
				size="12"
				style={ { margin: '-4px 0 16px' } }
			>
				{ selectionDescription }
			</Text>

			<DataViews
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				actions={ actions }
				isLoading={ isLoading }
				search
				searchLabel={ __( 'Search by title', 'native-translations' ) }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => item.key }
				selection={ selection }
				onChangeSelection={ setSelection }
				empty={ <Text>{ __( 'Nothing to show here.', 'native-translations' ) }</Text> }
			/>
		</div>
	);
}

const mount = document.getElementById( 'wpnt-overview-app' );
if ( mount ) {
	createRoot( mount ).render( <Overview /> );
}
