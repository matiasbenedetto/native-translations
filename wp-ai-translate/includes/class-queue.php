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
	 * Action Scheduler group for all queue actions (lets us count/list them).
	 *
	 * @var string
	 */
	const GROUP = 'wp-ai-translate';

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

		$type   = isset( $payload['type'] ) ? (string) $payload['type'] : '';
		$id     = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
		$target = isset( $payload['target'] ) ? (string) $payload['target'] : '';

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
			// Re-throw so AS marks the action failed (and stores the message).
			throw new \Exception( $result->get_error_message() );
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

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

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
	 * Counts actions in this plugin's group with a given status.
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
				'hook'   => self::HOOK,
				'group'  => self::GROUP,
				'status' => $status,
			),
			'count'
		);
	}
}
