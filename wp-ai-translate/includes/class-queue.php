<?php
/**
 * Background translation queue (#55).
 *
 * Wraps Action Scheduler (bundled) so users can enqueue many translations that
 * run in order in the background instead of blocking the request on a long chain
 * of synchronous AI calls. Each queued item is a single async action carrying a
 * `{type,id,target}` payload; the {@see Wpait_Queue::run_job()} callback resolves
 * the translator method and creates the draft translation. Failures re-throw so
 * Action Scheduler records the action as failed (with the AI error message)
 * rather than silently succeeding.
 *
 * @package WpAiTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues and runs background translation jobs via Action Scheduler.
 */
class Wpait_Queue {

	/**
	 * Action Scheduler hook each queued job fires.
	 *
	 * @var string
	 */
	const HOOK = 'wpait_translate_item';

	/**
	 * Action Scheduler hook each queued AI language-detection job fires (#56).
	 *
	 * @var string
	 */
	const DETECT_HOOK = 'wpait_detect_item';

	/**
	 * Action Scheduler group for all queue actions (lets us count/list them).
	 *
	 * @var string
	 */
	const GROUP = 'wp-ai-translate';

	/**
	 * Default number of attempts (the first run plus retries) before a transient
	 * failure is reported as failed. Filterable via `wpait_max_attempts`.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 2;

	/**
	 * Translation store (dedup detection).
	 *
	 * @var Wpait_Translation_Store
	 */
	private Wpait_Translation_Store $store;

	/**
	 * Languages handler (enabled-language validation).
	 *
	 * @var Wpait_Languages
	 */
	private Wpait_Languages $languages;

	/**
	 * AI translator (the worker each job calls).
	 *
	 * @var Wpait_Translator
	 */
	private Wpait_Translator $translator;

	/**
	 * Constructor.
	 *
	 * @param Wpait_Translation_Store $store      Translation store.
	 * @param Wpait_Languages         $languages  Languages handler.
	 * @param Wpait_Translator        $translator AI translator.
	 */
	public function __construct( Wpait_Translation_Store $store, Wpait_Languages $languages, Wpait_Translator $translator ) {
		$this->store      = $store;
		$this->languages  = $languages;
		$this->translator = $translator;
	}

	/**
	 * Registers hooks. Must run unconditionally (jobs execute in cron/async
	 * context, not just on admin screens).
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'run_job' ), 10, 1 );
		add_action( self::DETECT_HOOK, array( $this, 'run_detect_job' ), 10, 1 );
	}

	/**
	 * Enqueues a single translation job.
	 *
	 * @param string $type        'post' | 'term'.
	 * @param int    $id          Source object id.
	 * @param string $target_code Target language code.
	 * @return string One of: 'queued', 'exists', 'pending', 'invalid'.
	 */
	public function enqueue( string $type, int $id, string $target_code ): string {
		if ( ! in_array( $type, array( 'post', 'term' ), true ) || $id <= 0 ) {
			return 'invalid';
		}

		// Target must be a configured, enabled language.
		$codes = wp_list_pluck( $this->languages->enabled(), 'code' );
		if ( ! in_array( $target_code, $codes, true ) ) {
			return 'invalid';
		}

		// Dedup: target translation already exists.
		if ( $this->target_exists( $type, $id, $target_code ) ) {
			return 'exists';
		}

		$payload = array(
			'type'   => $type,
			'id'     => $id,
			'target' => $target_code,
		);

		// Dedup: an identical action is already scheduled (same single positional arg).
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::HOOK, array( $payload ), self::GROUP ) ) {
			return 'pending';
		}

		as_enqueue_async_action( self::HOOK, array( $payload ), self::GROUP );

		return 'queued';
	}

	/**
	 * Enqueues a single AI language-detection job for an unmarked item (#56).
	 *
	 * Detection only makes sense for an item with no language yet, so an item that
	 * already carries one is skipped (`has_language`) — re-detecting could only
	 * override a deliberate assignment. Dedups an identical pending action.
	 *
	 * @param string $type 'post' | 'term'.
	 * @param int    $id   Object id.
	 * @return string One of: 'queued', 'has_language', 'pending', 'invalid'.
	 */
	public function enqueue_detection( string $type, int $id ): string {
		if ( ! in_array( $type, array( 'post', 'term' ), true ) || $id <= 0 ) {
			return 'invalid';
		}

		// Detection is only for unmarked items — never override an existing language.
		if ( '' !== $this->store->get_language( $type, $id ) ) {
			return 'has_language';
		}

		$payload = array(
			'type' => $type,
			'id'   => $id,
		);

		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::DETECT_HOOK, array( $payload ), self::GROUP ) ) {
			return 'pending';
		}

		as_enqueue_async_action( self::DETECT_HOOK, array( $payload ), self::GROUP );

		return 'queued';
	}

	/**
	 * Action Scheduler callback: runs one translation job.
	 *
	 * @param array<string,mixed> $payload `{ type, id, target }`.
	 * @return void
	 *
	 * @throws \Exception When validation fails or the translator returns a WP_Error,
	 *                     so Action Scheduler records the action as failed.
	 */
	public function run_job( $payload ): void {
		if ( ! is_array( $payload ) ) {
			throw new \Exception( 'Invalid translation job payload.' );
		}

		$type    = isset( $payload['type'] ) ? (string) $payload['type'] : '';
		$id      = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
		$target  = isset( $payload['target'] ) ? (string) $payload['target'] : '';
		$attempt = isset( $payload['attempt'] ) ? max( 1, (int) $payload['attempt'] ) : 1;

		if ( ! in_array( $type, array( 'post', 'term' ), true ) || $id <= 0 || '' === $target ) {
			throw new \Exception( 'Invalid translation job: missing type, id, or target.' );
		}

		// A sibling may have created this target between enqueue and run — skip quietly.
		if ( $this->target_exists( $type, $id, $target ) ) {
			return;
		}

		$result = 'term' === $type
			? $this->translator->translate_term( $id, $target )
			: $this->translator->translate_post( $id, $target );

		if ( is_wp_error( $result ) ) {
			// Retry a transient failure once (bounded) before reporting it; on the
			// last attempt re-throw so AS marks the action failed (storing the message).
			$this->fail_or_retry(
				self::HOOK,
				array( 'type' => $type, 'id' => $id, 'target' => $target ),
				$attempt,
				$result->get_error_message()
			);
		}
	}

	/**
	 * Action Scheduler callback: runs one AI language-detection job (#56).
	 *
	 * Re-checks the item is still unmarked (a sibling/admin may have set a language
	 * between enqueue and run — skip quietly if so), detects the language via the
	 * translator, and assigns it through the store. Any failure re-throws so Action
	 * Scheduler records the action as failed with the message.
	 *
	 * @param array<string,mixed> $payload `{ type, id }`.
	 * @return void
	 *
	 * @throws \Exception When validation fails, detection fails, or the store rejects
	 *                    the assignment, so Action Scheduler records the failure.
	 */
	public function run_detect_job( $payload ): void {
		if ( ! is_array( $payload ) ) {
			throw new \Exception( 'Invalid detection job payload.' );
		}

		$type    = isset( $payload['type'] ) ? (string) $payload['type'] : '';
		$id      = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
		$attempt = isset( $payload['attempt'] ) ? max( 1, (int) $payload['attempt'] ) : 1;

		if ( ! in_array( $type, array( 'post', 'term' ), true ) || $id <= 0 ) {
			throw new \Exception( 'Invalid detection job: missing type or id.' );
		}

		// Already marked between enqueue and run — nothing to do.
		if ( '' !== $this->store->get_language( $type, $id ) ) {
			return;
		}

		$code = $this->translator->detect_language( $type, $id );
		if ( is_wp_error( $code ) ) {
			$this->fail_or_retry( self::DETECT_HOOK, array( 'type' => $type, 'id' => $id ), $attempt, $code->get_error_message() );
			return;
		}

		$set = $this->store->set_language( $type, $id, (string) $code );
		if ( is_wp_error( $set ) ) {
			// A rejected assignment is deterministic (not transient), so don't retry —
			// re-throw immediately so AS records the failure.
			throw new \Exception( $set->get_error_message() );
		}
	}

	/**
	 * Returns queue counts for the status banner.
	 *
	 * @return array{pending:int,running:int,failed:int}
	 */
	public function get_status(): array {
		return array(
			'pending' => $this->count_actions( ActionScheduler_Store::STATUS_PENDING ),
			'running' => $this->count_actions( ActionScheduler_Store::STATUS_RUNNING ),
			'failed'  => $this->count_actions( ActionScheduler_Store::STATUS_FAILED ),
		);
	}

	/**
	 * Lists this plugin's failed background jobs for the Overview, newest first,
	 * resolving each failed action's payload back to a human label + error message.
	 *
	 * @param int $limit Max failed actions to return.
	 * @return array<int,array{action_id:int,kind:string,type:string,id:int,target:string,title:string,message:string}>
	 */
	public function get_failed_jobs( int $limit = 50 ): array {
		$jobs = $this->list_jobs( array( ActionScheduler_Store::STATUS_FAILED ), $limit );
		foreach ( $jobs as &$job ) {
			$job['message'] = $this->failure_message( $job['action_id'] );
		}
		unset( $job );
		return $jobs;
	}

	/**
	 * Lists this plugin's queued (pending) and currently-running jobs for the
	 * Overview, so the user can see which translations are waiting/processing — not
	 * just a count (#81). Each entry carries a 'state' of 'pending' or 'running'.
	 *
	 * @param int $limit Max jobs to return.
	 * @return array<int,array{action_id:int,kind:string,type:string,id:int,target:string,title:string,state:string}>
	 */
	public function get_pending_jobs( int $limit = 100 ): array {
		// Running first (they're the active work), then pending, oldest-first so the
		// list reads as the processing order.
		$running = $this->list_jobs( array( ActionScheduler_Store::STATUS_RUNNING ), $limit, 'ASC' );
		foreach ( $running as &$job ) {
			$job['state'] = 'running';
		}
		unset( $job );

		$remaining = max( 0, $limit - count( $running ) );
		$pending   = $remaining > 0 ? $this->list_jobs( array( ActionScheduler_Store::STATUS_PENDING ), $remaining, 'ASC' ) : array();
		foreach ( $pending as &$job ) {
			$job['state'] = 'pending';
		}
		unset( $job );

		return array_merge( $running, $pending );
	}

	/**
	 * Shared query: resolves this plugin's actions in the given statuses to a list
	 * of `{action_id,kind,type,id,target,title}` (newest- or oldest-first).
	 *
	 * @param string[] $statuses Action Scheduler status constants.
	 * @param int      $limit    Max actions.
	 * @param string   $order    'ASC' or 'DESC' by modified time.
	 * @return array<int,array{action_id:int,kind:string,type:string,id:int,target:string,title:string}>
	 */
	private function list_jobs( array $statuses, int $limit, string $order = 'DESC' ): array {
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return array();
		}
		$store = ActionScheduler::store();
		$jobs  = array();
		foreach ( $statuses as $status ) {
			// 'select' returns an array of action ids (vs 'count').
			$ids = $store->query_actions(
				array(
					'group'    => self::GROUP,
					'status'   => $status,
					'per_page' => max( 1, $limit ),
					'orderby'  => 'modified',
					'order'    => $order,
				),
				'select'
			);
			foreach ( (array) $ids as $action_id ) {
				$action = $store->fetch_action( (int) $action_id );
				if ( ! $action || ! method_exists( $action, 'get_hook' ) ) {
					continue;
				}
				$hook = $action->get_hook();
				if ( self::HOOK !== $hook && self::DETECT_HOOK !== $hook ) {
					continue;
				}
				$args    = $action->get_args();
				$payload = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : array();
				$type    = isset( $payload['type'] ) ? (string) $payload['type'] : '';
				$id      = isset( $payload['id'] ) ? (int) $payload['id'] : 0;

				$jobs[] = array(
					'action_id' => (int) $action_id,
					'kind'      => self::DETECT_HOOK === $hook ? 'detect' : 'translate',
					'type'      => $type,
					'id'        => $id,
					'target'    => isset( $payload['target'] ) ? (string) $payload['target'] : '',
					'title'     => $this->resolve_title( $type, $id ),
				);
			}
		}
		return $jobs;
	}

	/**
	 * Re-runs a failed job: re-enqueues a fresh action from the failed action's
	 * payload (attempt counter reset) and removes the failed record so it leaves
	 * the Overview's failed list.
	 *
	 * @param int $action_id Failed Action Scheduler action id.
	 * @return true|WP_Error True on success.
	 */
	public function retry_job( int $action_id ) {
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return new WP_Error( 'wpait_no_scheduler', __( 'The background scheduler is unavailable.', 'wp-ai-translate' ) );
		}
		$store  = ActionScheduler::store();
		$action = $store->fetch_action( $action_id );
		if ( ! $action || ! method_exists( $action, 'get_hook' ) || '' === $action->get_hook() ) {
			return new WP_Error( 'wpait_unknown_action', __( 'That job no longer exists.', 'wp-ai-translate' ), array( 'status' => 404 ) );
		}

		$hook    = $action->get_hook();
		$args    = $action->get_args();
		$payload = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : array();
		unset( $payload['attempt'] ); // Fresh attempt budget on a manual re-run.

		if ( self::HOOK === $hook ) {
			$status = $this->enqueue(
				isset( $payload['type'] ) ? (string) $payload['type'] : '',
				isset( $payload['id'] ) ? (int) $payload['id'] : 0,
				isset( $payload['target'] ) ? (string) $payload['target'] : ''
			);
		} elseif ( self::DETECT_HOOK === $hook ) {
			$status = $this->enqueue_detection(
				isset( $payload['type'] ) ? (string) $payload['type'] : '',
				isset( $payload['id'] ) ? (int) $payload['id'] : 0
			);
		} else {
			return new WP_Error( 'wpait_unknown_action', __( 'That job cannot be retried.', 'wp-ai-translate' ), array( 'status' => 400 ) );
		}

		if ( 'invalid' === $status ) {
			return new WP_Error( 'wpait_retry_invalid', __( 'The job’s item or target is no longer valid.', 'wp-ai-translate' ), array( 'status' => 400 ) );
		}

		// Re-enqueued (or already done/pending) — drop the failed record either way.
		$store->delete_action( $action_id );
		return true;
	}

	/**
	 * Cancels a single scheduled job by action id (#96): unschedules a pending
	 * action so it never runs, or best-effort cancels a running one. Only this
	 * plugin's own actions (the translate/detect hooks) are cancellable here, so a
	 * stray id from another group can't be touched through the queue UI.
	 *
	 * @param int $action_id Action Scheduler action id.
	 * @return true|WP_Error True on success.
	 */
	public function cancel_job( int $action_id ) {
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return new WP_Error( 'wpait_no_scheduler', __( 'The background scheduler is unavailable.', 'wp-ai-translate' ) );
		}
		$store  = ActionScheduler::store();
		$action = $store->fetch_action( $action_id );
		if ( ! $action || ! method_exists( $action, 'get_hook' ) || '' === $action->get_hook() ) {
			return new WP_Error( 'wpait_unknown_action', __( 'That job no longer exists.', 'wp-ai-translate' ), array( 'status' => 404 ) );
		}

		$hook = $action->get_hook();
		if ( self::HOOK !== $hook && self::DETECT_HOOK !== $hook ) {
			return new WP_Error( 'wpait_unknown_action', __( 'That job cannot be cancelled.', 'wp-ai-translate' ), array( 'status' => 400 ) );
		}

		try {
			$store->cancel_action( $action_id );
		} catch ( \Exception $e ) {
			return new WP_Error( 'wpait_cancel_failed', __( 'The job could not be cancelled.', 'wp-ai-translate' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Cancels every pending and (best-effort) running job in this plugin's group
	 * (#96). Completed/failed actions are left alone — failed jobs keep their
	 * Re-run affordance — so only in-flight work is unscheduled. Returns the number
	 * of actions cancelled.
	 *
	 * @return int Count of cancelled actions.
	 */
	public function cancel_all(): int {
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}
		$store     = ActionScheduler::store();
		$cancelled = 0;
		foreach ( array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			$ids = $store->query_actions(
				array(
					'group'    => self::GROUP,
					'status'   => $status,
					'per_page' => 1000,
				),
				'select'
			);
			foreach ( (array) $ids as $action_id ) {
				$action = $store->fetch_action( (int) $action_id );
				if ( ! $action || ! method_exists( $action, 'get_hook' ) ) {
					continue;
				}
				$hook = $action->get_hook();
				if ( self::HOOK !== $hook && self::DETECT_HOOK !== $hook ) {
					continue;
				}
				try {
					$store->cancel_action( (int) $action_id );
					++$cancelled;
				} catch ( \Exception $e ) {
					// Best-effort: skip an action that can't be cancelled (e.g. it just ran).
					continue;
				}
			}
		}
		return $cancelled;
	}

	/**
	 * Resolves a scheduled/failed action to its `{hook,type,id,target}` so a caller
	 * (e.g. the REST layer) can run a per-item capability check before retrying.
	 *
	 * @param int $action_id Action id.
	 * @return array{hook:string,type:string,id:int,target:string}|null Null if unknown.
	 */
	public function describe_action( int $action_id ): ?array {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return null;
		}
		$action = ActionScheduler::store()->fetch_action( $action_id );
		if ( ! $action || ! method_exists( $action, 'get_hook' ) || '' === $action->get_hook() ) {
			return null;
		}
		$args    = $action->get_args();
		$payload = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : array();
		return array(
			'hook'   => (string) $action->get_hook(),
			'type'   => isset( $payload['type'] ) ? (string) $payload['type'] : '',
			'id'     => isset( $payload['id'] ) ? (int) $payload['id'] : 0,
			'target' => isset( $payload['target'] ) ? (string) $payload['target'] : '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Schedules a bounded retry for a failed job, or re-throws on the final attempt.
	 *
	 * A transient failure (AI hiccup, timeout, momentary "model not available")
	 * gets at least one retry with backoff before being reported. Returning without
	 * throwing lets Action Scheduler complete this action; the scheduled retry
	 * carries the work forward. On the last attempt this throws so AS records the
	 * failure and stores the message.
	 *
	 * @param string              $hook    The action hook to re-schedule.
	 * @param array<string,mixed> $payload Clean payload (no attempt key).
	 * @param int                 $attempt 1-based attempt number that just failed.
	 * @param string              $message Error message for the final failure.
	 * @return void
	 * @throws \Exception On the final attempt, so AS records the action as failed.
	 */
	private function fail_or_retry( string $hook, array $payload, int $attempt, string $message ): void {
		$max = (int) apply_filters( 'wpait_max_attempts', self::MAX_ATTEMPTS );

		if ( $attempt < $max && function_exists( 'as_schedule_single_action' ) ) {
			$payload['attempt'] = $attempt + 1;
			/** Backoff (seconds) before the next attempt; receives the failed attempt number. */
			$delay     = (int) apply_filters( 'wpait_retry_delay', 60, $attempt );
			$scheduled = as_schedule_single_action( time() + max( 0, $delay ), $hook, array( $payload ), self::GROUP );
			// Only swallow this failure if the retry was actually scheduled; if
			// scheduling returned falsy, fall through and re-throw so the failure is
			// recorded rather than silently lost (action completed, no retry).
			if ( $scheduled ) {
				return;
			}
		}

		throw new \Exception( $message );
	}

	/**
	 * Resolves a job payload's `{type,id}` to a human-readable title.
	 *
	 * @param string $type 'post' | 'term'.
	 * @param int    $id   Object id.
	 * @return string Title, or a generic "#id" fallback.
	 */
	private function resolve_title( string $type, int $id ): string {
		if ( 'post' === $type && $id > 0 ) {
			$title = get_the_title( $id );
			if ( '' !== (string) $title ) {
				return (string) $title;
			}
		} elseif ( 'term' === $type && $id > 0 ) {
			$term = get_term( $id );
			if ( $term instanceof WP_Term ) {
				return $term->name;
			}
		}
		/* translators: %d: numeric object id of a deleted/unknown item. */
		return sprintf( __( 'Item #%d', 'wp-ai-translate' ), $id );
	}

	/**
	 * Reads the stored failure message for a failed action from its Action
	 * Scheduler log (the most recent log entry, with AS's "action failed:" prefix
	 * stripped). Falls back to a generic message.
	 *
	 * @param int $action_id Action id.
	 * @return string
	 */
	private function failure_message( int $action_id ): string {
		$generic = __( 'The job failed.', 'wp-ai-translate' );
		if ( ! class_exists( 'ActionScheduler' ) || ! method_exists( 'ActionScheduler', 'logger' ) ) {
			return $generic;
		}
		$logs = ActionScheduler::logger()->get_logs( $action_id );
		if ( empty( $logs ) ) {
			return $generic;
		}
		// The failure is the most recent log entry.
		$last    = end( $logs );
		$message = method_exists( $last, 'get_message' ) ? (string) $last->get_message() : '';
		// AS logs failures as "action failed[ via <runner>]: <message>"; strip the
		// prefix up to the first colon so only the underlying error message remains.
		$message = preg_replace( '/^action failed[^:]*:\s*/i', '', $message );
		return '' !== trim( (string) $message ) ? (string) $message : $generic;
	}

	/**
	 * Whether a translation for `$target_code` already exists in the source's group.
	 *
	 * @param string $type        'post' | 'term'.
	 * @param int    $id          Source object id.
	 * @param string $target_code Target language code.
	 * @return bool
	 */
	private function target_exists( string $type, int $id, string $target_code ): bool {
		$members = $this->store->get_translations( $type, $id, array( 'include_self' => true ) );
		return isset( $members[ $target_code ] );
	}

	/**
	 * Counts actions in this plugin's group with a given status. Filtered by group
	 * only (not by hook) so the banner reflects both translation and detection jobs
	 * (#56) — the group is exclusive to this plugin, so the count stays accurate and
	 * cheap.
	 *
	 * @param string $status Action Scheduler status constant.
	 * @return int
	 */
	private function count_actions( string $status ): int {
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}
		return (int) ActionScheduler::store()->query_actions(
			array(
				'group'  => self::GROUP,
				'status' => $status,
			),
			'count'
		);
	}
}
