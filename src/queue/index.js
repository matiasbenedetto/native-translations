/**
 * WP Native Translations — Translation Queue page (#96).
 *
 * A dedicated admin sub-section (slug `native-translations-queue`) that lists the
 * plugin's background translation/detection jobs with their state
 * (pending / running / failed), the target entity (title + type), target
 * language and action type. Failed jobs surface their error message and keep the
 * Re-run (retry) affordance; pending/running jobs can be cancelled individually
 * or all at once. The list polls so state changes appear without a full reload,
 * and a manual Refresh is always available.
 *
 * Reads/writes the existing REST surface plus the new cancel endpoints:
 *   GET  /queue-jobs        — pending + running jobs
 *   GET  /failed-jobs       — failed jobs (with error message)
 *   POST /retry-job         — re-run a failed job by action_id
 *   POST /queue/cancel      — cancel one pending/running job by action_id
 *   POST /queue/cancel-all  — cancel every pending/running job
 */

import { createRoot, useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner, Flex, FlexItem, __experimentalText as Text } from '@wordpress/components';

const cfg = window.wpntQueue || {
	namespace: 'native-translations/v1',
	languages: [],
	overviewUrl: '',
};

const POLL_MS = 5000;

/**
 * Human label for a job's target entity type.
 *
 * @param {string} type 'post' | 'term'.
 * @return {string} Localized label.
 */
function typeLabel( type ) {
	if ( 'term' === type ) {
		return __( 'Term', 'native-translations' );
	}
	if ( 'post' === type ) {
		return __( 'Post', 'native-translations' );
	}
	return type || __( 'Item', 'native-translations' );
}

/**
 * Human label for a job's action type.
 *
 * @param {string} kind 'translate' | 'detect'.
 * @return {string} Localized label.
 */
function kindLabel( kind ) {
	return 'detect' === kind
		? __( 'Detect language', 'native-translations' )
		: __( 'Translate', 'native-translations' );
}

/**
 * Resolves a target language code to its configured display name, falling back
 * to the raw code.
 *
 * @param {string} code Language code.
 * @return {string} Display name.
 */
function languageName( code ) {
	if ( ! code ) {
		return '';
	}
	const found = ( cfg.languages || [] ).find( ( l ) => l.code === code );
	return found ? found.name : code;
}

/**
 * Small colored state badge.
 *
 * @param {Object} props       Props.
 * @param {string} props.state 'pending' | 'running' | 'failed'.
 * @return {JSX.Element} Badge.
 */
function StateBadge( { state } ) {
	const map = {
		running: { bg: '#cce5ff', fg: '#004085', label: __( 'Running', 'native-translations' ) },
		pending: { bg: '#f0f0f1', fg: '#3c434a', label: __( 'Pending', 'native-translations' ) },
		failed: { bg: '#f8d7da', fg: '#842029', label: __( 'Failed', 'native-translations' ) },
		// 'completed' is unreachable today (Action Scheduler prunes completed actions,
		// so they never appear in the queue list) — kept as a forward-looking mapping
		// in case completed jobs are ever surfaced.
		completed: { bg: '#d4edda', fg: '#155724', label: __( 'Completed', 'native-translations' ) },
	};
	const s = map[ state ] || map.pending;
	return (
		<span
			style={ {
				display: 'inline-block',
				padding: '1px 8px',
				borderRadius: '10px',
				background: s.bg,
				color: s.fg,
				fontSize: '12px',
				lineHeight: '1.8',
				whiteSpace: 'nowrap',
			} }
		>
			{ s.label }
		</span>
	);
}

/**
 * The Translation Queue app.
 *
 * @return {JSX.Element} App.
 */
function Queue() {
	// Combined job list: pending/running (from /queue-jobs) + failed (/failed-jobs).
	const [ jobs, setJobs ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ busyId, setBusyId ] = useState( 0 );
	const [ cancellingAll, setCancellingAll ] = useState( false );
	const pollRef = useRef( null );

	const load = useCallback( ( { quiet = false } = {} ) => {
		if ( ! quiet ) {
			setIsLoading( true );
		}
		return Promise.all( [
			apiFetch( { path: `/${ cfg.namespace }/queue-jobs` } ).catch( () => ( { jobs: [] } ) ),
			apiFetch( { path: `/${ cfg.namespace }/failed-jobs` } ).catch( () => ( { jobs: [] } ) ),
		] )
			.then( ( [ active, failed ] ) => {
				const activeJobs = ( active.jobs || [] ).map( ( j ) => ( { ...j, state: j.state || 'pending' } ) );
				const failedJobs = ( failed.jobs || [] ).map( ( j ) => ( { ...j, state: 'failed' } ) );
				setJobs( [ ...activeJobs, ...failedJobs ] );
				setError( '' );
			} )
			.catch( ( e ) => setError( e.message || __( 'Could not load the queue.', 'native-translations' ) ) )
			.finally( () => setIsLoading( false ) );
	}, [] );

	// Initial load + steady polling so state changes appear without a reload.
	useEffect( () => {
		load();
		pollRef.current = setInterval( () => load( { quiet: true } ), POLL_MS );
		return () => {
			if ( pollRef.current ) {
				clearInterval( pollRef.current );
			}
		};
	}, [ load ] );

	const retry = useCallback(
		( actionId ) => {
			setBusyId( actionId );
			setNotice( '' );
			setError( '' );
			apiFetch( {
				path: `/${ cfg.namespace }/retry-job`,
				method: 'POST',
				data: { action_id: actionId },
			} )
				.then( () => {
					setNotice( __( 'Job re-queued.', 'native-translations' ) );
					return load( { quiet: true } );
				} )
				.catch( ( e ) => setError( e.message || __( 'Could not re-run the job.', 'native-translations' ) ) )
				.finally( () => setBusyId( 0 ) );
		},
		[ load ]
	);

	const cancel = useCallback(
		( actionId ) => {
			setBusyId( actionId );
			setNotice( '' );
			setError( '' );
			apiFetch( {
				path: `/${ cfg.namespace }/queue/cancel`,
				method: 'POST',
				data: { action_id: actionId },
			} )
				.then( () => {
					setNotice( __( 'Job cancelled.', 'native-translations' ) );
					return load( { quiet: true } );
				} )
				.catch( ( e ) => setError( e.message || __( 'Could not cancel the job.', 'native-translations' ) ) )
				.finally( () => setBusyId( 0 ) );
		},
		[ load ]
	);

	const cancelAll = useCallback( () => {
		// eslint-disable-next-line no-alert
		if ( typeof window !== 'undefined' && window.confirm && ! window.confirm( __( 'Cancel all pending and running jobs?', 'native-translations' ) ) ) {
			return;
		}
		setCancellingAll( true );
		setNotice( '' );
		setError( '' );
		apiFetch( {
			path: `/${ cfg.namespace }/queue/cancel-all`,
			method: 'POST',
			data: {},
		} )
			.then( ( res ) => {
				setNotice(
					sprintf(
						/* translators: %d: number of cancelled jobs. */
						__( '%d job(s) cancelled.', 'native-translations' ),
						res.cancelled || 0
					)
				);
				return load( { quiet: true } );
			} )
			.catch( ( e ) => setError( e.message || __( 'Could not cancel the jobs.', 'native-translations' ) ) )
			.finally( () => setCancellingAll( false ) );
	}, [ load ] );

	const activeCount = jobs.filter( ( j ) => 'failed' !== j.state ).length;
	const cellStyle = { padding: '8px 10px', verticalAlign: 'top' };

	return (
		<div className="wpnt-queue">
			<Flex justify="flex-start" gap={ 3 } style={ { margin: '12px 0' } }>
				<FlexItem>
					<Button variant="secondary" onClick={ () => load() } disabled={ isLoading }>
						{ __( 'Refresh', 'native-translations' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="secondary"
						isDestructive
						onClick={ cancelAll }
						disabled={ cancellingAll || activeCount === 0 }
					>
						{ cancellingAll
							? __( 'Cancelling…', 'native-translations' )
							: __( 'Cancel all', 'native-translations' ) }
					</Button>
				</FlexItem>
				{ isLoading && (
					<FlexItem>
						<Spinner />
					</FlexItem>
				) }
			</Flex>

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

			{ ! isLoading && jobs.length === 0 ? (
				<Text as="p">
					{ __( 'The translation queue is empty. Queue translations from the Overview page.', 'native-translations' ) }
					{ cfg.overviewUrl ? (
						<>
							{ ' ' }
							<a href={ cfg.overviewUrl }>{ __( 'Open the Overview.', 'native-translations' ) }</a>
						</>
					) : null }
				</Text>
			) : (
				<table className="wp-list-table widefat fixed striped" style={ { marginTop: '8px' } }>
					<thead>
						<tr>
							<th scope="col">{ __( 'Entity', 'native-translations' ) }</th>
							<th scope="col">{ __( 'Type', 'native-translations' ) }</th>
							<th scope="col">{ __( 'Action', 'native-translations' ) }</th>
							<th scope="col">{ __( 'Target language', 'native-translations' ) }</th>
							<th scope="col">{ __( 'State', 'native-translations' ) }</th>
							<th scope="col">{ __( 'Actions', 'native-translations' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ jobs.map( ( job ) => {
							const isFailed = 'failed' === job.state;
							const isBusy = busyId === job.action_id;
							return (
								<tr key={ `${ job.state }-${ job.action_id }` }>
									<td style={ cellStyle }>
										<strong>{ job.title }</strong>
										{ isFailed && job.message ? (
											<div
												className="wpnt-queue-error"
												style={ { color: '#842029', fontSize: '12px', marginTop: '4px' } }
											>
												{ job.message }
											</div>
										) : null }
									</td>
									<td style={ cellStyle }>{ typeLabel( job.type ) }</td>
									<td style={ cellStyle }>{ kindLabel( job.kind ) }</td>
									<td style={ cellStyle }>
										{ 'translate' === job.kind ? languageName( job.target ) || '—' : '—' }
									</td>
									<td style={ cellStyle }>
										<StateBadge state={ job.state } />
									</td>
									<td style={ cellStyle }>
										{ isFailed ? (
											<Button
												variant="secondary"
												size="small"
												onClick={ () => retry( job.action_id ) }
												disabled={ isBusy }
											>
												{ isBusy
													? __( 'Re-running…', 'native-translations' )
													: __( 'Re-run', 'native-translations' ) }
											</Button>
										) : (
											<Button
												variant="secondary"
												size="small"
												isDestructive
												onClick={ () => cancel( job.action_id ) }
												disabled={ isBusy }
											>
												{ isBusy
													? __( 'Cancelling…', 'native-translations' )
													: __( 'Cancel', 'native-translations' ) }
											</Button>
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

const mount = document.getElementById( 'wpnt-queue-app' );
if ( mount ) {
	createRoot( mount ).render( <Queue /> );
}
