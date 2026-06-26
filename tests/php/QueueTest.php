<?php
/**
 * Unit tests for Wpait_Queue — the background translation queue (#55).
 *
 * DB-free: Action Scheduler is replaced by the recording stubs in bootstrap.php
 * (as_enqueue_async_action / as_has_scheduled_action / a status-count store),
 * and the translator is a recording double so a test can assert which method the
 * job dispatches to and that a WP_Error re-throws (so AS records the failure).
 *
 * @package WpAiTranslate\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * Recording translator double: captures dispatch + returns a configurable result.
 */
final class Wpait_Recording_Translator extends Wpait_Translator {
	/** @var array<int,array{0:string,1:int,2:string}> */
	public array $calls = array();

	/** @var int|WP_Error */
	public $post_result = 99;

	/** @var int|WP_Error */
	public $term_result = 99;

	/** @var string|WP_Error Result detect_language returns. */
	public $detect_result = 'es';

	/** @var array<int,array{0:string,1:int}> Recorded detect_language calls. */
	public array $detect_calls = array();

	public function translate_post( int $source_id, string $to_code ) {
		$this->calls[] = array( 'post', $source_id, $to_code );
		return $this->post_result;
	}

	public function translate_term( int $term_id, string $to_code ) {
		$this->calls[] = array( 'term', $term_id, $to_code );
		return $this->term_result;
	}

	public function detect_language( string $type, int $id ) {
		$this->detect_calls[] = array( $type, $id );
		return $this->detect_result;
	}
}

final class QueueTest extends TestCase {

	private Wpait_Translation_Store $store;
	private Wpait_Languages $languages;
	private Wpait_Recording_Translator $translator;
	private Wpait_Queue $queue;

	protected function setUp(): void {
		Wpait_Test_State::reset();
		Wpait_Languages::flush_index();
		Wpait_Test_State::$options['wpait_settings'] = array(
			'languages' => array(
				array( 'code' => 'en', 'name' => 'English', 'enabled' => true ),
				array( 'code' => 'es', 'name' => 'Spanish', 'enabled' => true ),
				array( 'code' => 'fr', 'name' => 'French', 'enabled' => false ),
			),
		);
		$this->store      = new Wpait_Translation_Store();
		$this->languages  = new Wpait_Languages();
		$this->translator = new Wpait_Recording_Translator( $this->store, $this->languages );
		$this->queue      = new Wpait_Queue( $this->store, $this->languages, $this->translator );
	}

	/* ------------------------------------------------------------------ *
	 * enqueue() — dedup / validation paths
	 * ------------------------------------------------------------------ */

	public function test_enqueue_queued_records_single_payload_arg(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );

		$status = $this->queue->enqueue( 'post', 7, 'es' );

		$this->assertSame( 'queued', $status );
		$this->assertCount( 1, Wpait_Test_State::$as_enqueued );
		[ $hook, $args, $group ] = Wpait_Test_State::$as_enqueued[0];
		$this->assertSame( Wpait_Queue::HOOK, $hook );
		$this->assertSame( Wpait_Queue::GROUP, $group );
		// Single positional arg = the payload array.
		$this->assertSame( array( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) ), $args );
	}

	public function test_enqueue_invalid_type(): void {
		$this->assertSame( 'invalid', $this->queue->enqueue( 'widget', 7, 'es' ) );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	public function test_enqueue_invalid_disabled_language(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		// 'fr' is configured but disabled; 'de' is unknown.
		$this->assertSame( 'invalid', $this->queue->enqueue( 'post', 7, 'fr' ) );
		$this->assertSame( 'invalid', $this->queue->enqueue( 'post', 7, 'de' ) );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	/** Seeds a post in a translation group with a given language (matches the store's model). */
	private function seed_post( int $id, string $code, string $group = '' ): void {
		Wpait_Test_State::$posts[ $id ] = array( 'ID' => $id, 'post_type' => 'post' );
		Wpait_Test_State::$object_terms[ $id ][ Wpait_Languages::TAXONOMY ] = array( $code );
		if ( '' !== $group ) {
			Wpait_Test_State::$post_meta[ $id ][ Wpait_Translation_Store::META_GROUP ] = $group;
		}
	}

	public function test_enqueue_exists_when_target_translation_present(): void {
		// Seed a source post in group G with an existing 'es' member.
		$this->seed_post( 7, 'en', 'G' );
		$this->seed_post( 8, 'es', 'G' );

		$this->assertSame( 'exists', $this->queue->enqueue( 'post', 7, 'es' ) );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	public function test_enqueue_pending_when_identical_action_scheduled(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		$payload = array( 'type' => 'post', 'id' => 7, 'target' => 'es' );
		$sig     = wpait_test_as_signature( Wpait_Queue::HOOK, array( $payload ), Wpait_Queue::GROUP );
		Wpait_Test_State::$as_scheduled[ $sig ] = true;

		$this->assertSame( 'pending', $this->queue->enqueue( 'post', 7, 'es' ) );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	/* ------------------------------------------------------------------ *
	 * run_job() — dispatch + failure
	 * ------------------------------------------------------------------ */

	public function test_run_job_dispatches_to_translate_post(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		$this->queue->run_job( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) );
		$this->assertSame( array( array( 'post', 7, 'es' ) ), $this->translator->calls );
	}

	public function test_run_job_dispatches_to_translate_term(): void {
		Wpait_Test_State::$terms[3] = array( 'term_id' => 3, 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news' );
		$this->queue->run_job( array( 'type' => 'term', 'id' => 3, 'target' => 'es' ) );
		$this->assertSame( array( array( 'term', 3, 'es' ) ), $this->translator->calls );
	}

	public function test_run_job_retries_transient_failure_instead_of_throwing(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		$this->translator->post_result = new WP_Error( 'wpait_ai', 'AI exploded' );

		// First attempt (no attempt key): a failure schedules a retry and does NOT throw.
		$this->queue->run_job( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) );

		$this->assertCount( 1, Wpait_Test_State::$as_scheduled_single );
		[ , $hook, $args ] = Wpait_Test_State::$as_scheduled_single[0];
		$this->assertSame( Wpait_Queue::HOOK, $hook );
		// The retry payload carries an incremented attempt counter.
		$this->assertSame(
			array( array( 'type' => 'post', 'id' => 7, 'target' => 'es', 'attempt' => 2 ) ),
			$args
		);
	}

	public function test_run_job_records_failure_when_retry_scheduling_fails(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		$this->translator->post_result = new WP_Error( 'wpait_ai', 'AI exploded' );
		Wpait_Test_State::$as_schedule_fails = true; // as_schedule_single_action returns 0.

		// If the retry can't be scheduled, the failure must surface (re-throw) rather
		// than the action completing with the work silently lost.
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'AI exploded' );
		$this->queue->run_job( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) );
	}

	public function test_run_job_throws_on_final_attempt(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		$this->translator->post_result = new WP_Error( 'wpait_ai', 'AI exploded' );

		// Final attempt (== MAX_ATTEMPTS): re-throw so AS records the failure; no further retry.
		try {
			$this->queue->run_job( array( 'type' => 'post', 'id' => 7, 'target' => 'es', 'attempt' => Wpait_Queue::MAX_ATTEMPTS ) );
			$this->fail( 'Expected an exception on the final attempt.' );
		} catch ( \Exception $e ) {
			$this->assertSame( 'AI exploded', $e->getMessage() );
		}
		$this->assertSame( array(), Wpait_Test_State::$as_scheduled_single );
	}

	public function test_run_job_throws_on_invalid_payload(): void {
		$this->expectException( \Exception::class );
		$this->queue->run_job( 'not-an-array' );
	}

	public function test_run_job_skips_when_target_already_exists(): void {
		// Sibling created the 'es' member between enqueue and run.
		$this->seed_post( 7, 'en', 'G' );
		$this->seed_post( 8, 'es', 'G' );

		$this->queue->run_job( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) );
		$this->assertSame( array(), $this->translator->calls );
	}

	/* ------------------------------------------------------------------ *
	 * enqueue_detection() — dedup / validation (#56)
	 * ------------------------------------------------------------------ */

	public function test_enqueue_detection_queues_unmarked_item(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );

		$status = $this->queue->enqueue_detection( 'post', 7 );

		$this->assertSame( 'queued', $status );
		$this->assertCount( 1, Wpait_Test_State::$as_enqueued );
		[ $hook, $args, $group ] = Wpait_Test_State::$as_enqueued[0];
		$this->assertSame( Wpait_Queue::DETECT_HOOK, $hook );
		$this->assertSame( Wpait_Queue::GROUP, $group );
		$this->assertSame( array( array( 'type' => 'post', 'id' => 7 ) ), $args );
	}

	public function test_enqueue_detection_skips_item_with_language(): void {
		$this->seed_post( 7, 'en' );
		$this->assertSame( 'has_language', $this->queue->enqueue_detection( 'post', 7 ) );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	public function test_enqueue_detection_invalid_type(): void {
		$this->assertSame( 'invalid', $this->queue->enqueue_detection( 'widget', 7 ) );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	public function test_enqueue_detection_pending_when_identical_action_scheduled(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		$payload = array( 'type' => 'post', 'id' => 7 );
		$sig     = wpait_test_as_signature( Wpait_Queue::DETECT_HOOK, array( $payload ), Wpait_Queue::GROUP );
		Wpait_Test_State::$as_scheduled[ $sig ] = true;

		$this->assertSame( 'pending', $this->queue->enqueue_detection( 'post', 7 ) );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	/* ------------------------------------------------------------------ *
	 * run_detect_job() — detect + assign (#56)
	 * ------------------------------------------------------------------ */

	public function test_run_detect_job_sets_detected_language(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		$this->translator->detect_result = 'es';

		$this->queue->run_detect_job( array( 'type' => 'post', 'id' => 7 ) );

		$this->assertSame( array( array( 'post', 7 ) ), $this->translator->detect_calls );
		$this->assertSame( 'es', $this->store->get_language( 'post', 7 ) );
	}

	public function test_run_detect_job_skips_item_that_gained_a_language(): void {
		$this->seed_post( 7, 'en' );
		$this->queue->run_detect_job( array( 'type' => 'post', 'id' => 7 ) );
		$this->assertSame( array(), $this->translator->detect_calls );
		$this->assertSame( 'en', $this->store->get_language( 'post', 7 ) );
	}

	public function test_run_detect_job_retries_transient_failure(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		$this->translator->detect_result = new WP_Error( 'wpait_detect_failed', 'Could not detect' );

		$this->queue->run_detect_job( array( 'type' => 'post', 'id' => 7 ) );

		$this->assertCount( 1, Wpait_Test_State::$as_scheduled_single );
		[ , $hook, $args ] = Wpait_Test_State::$as_scheduled_single[0];
		$this->assertSame( Wpait_Queue::DETECT_HOOK, $hook );
		$this->assertSame( array( array( 'type' => 'post', 'id' => 7, 'attempt' => 2 ) ), $args );
	}

	public function test_run_detect_job_throws_on_final_attempt(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		$this->translator->detect_result = new WP_Error( 'wpait_detect_failed', 'Could not detect' );

		try {
			$this->queue->run_detect_job( array( 'type' => 'post', 'id' => 7, 'attempt' => Wpait_Queue::MAX_ATTEMPTS ) );
			$this->fail( 'Expected an exception on the final attempt.' );
		} catch ( \Exception $e ) {
			$this->assertSame( 'Could not detect', $e->getMessage() );
		}
		$this->assertSame( array(), Wpait_Test_State::$as_scheduled_single );
	}

	public function test_run_detect_job_throws_on_invalid_payload(): void {
		$this->expectException( \Exception::class );
		$this->queue->run_detect_job( 'nope' );
	}

	/* ------------------------------------------------------------------ *
	 * get_status()
	 * ------------------------------------------------------------------ */

	public function test_get_status_counts_by_status(): void {
		Wpait_Test_State::$as_counts = array(
			ActionScheduler_Store::STATUS_PENDING => 4,
			ActionScheduler_Store::STATUS_RUNNING => 1,
			ActionScheduler_Store::STATUS_FAILED  => 2,
		);

		$this->assertSame(
			array( 'pending' => 4, 'running' => 1, 'failed' => 2 ),
			$this->queue->get_status()
		);
	}

	/* ------------------------------------------------------------------ *
	 * get_failed_jobs() / retry_job() (#69)
	 * ------------------------------------------------------------------ */

	public function test_get_failed_jobs_resolves_titles_and_messages(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post', 'post_title' => 'Hello World' );
		Wpait_Test_State::$as_failed_actions = array(
			101 => array(
				'hook'    => Wpait_Queue::HOOK,
				'args'    => array( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) ),
				'message' => 'AI exploded',
			),
			102 => array(
				'hook'    => Wpait_Queue::DETECT_HOOK,
				'args'    => array( array( 'type' => 'term', 'id' => 999 ) ),
				'message' => 'Could not detect',
			),
		);

		$jobs = $this->queue->get_failed_jobs();
		$this->assertCount( 2, $jobs );

		$byId = array();
		foreach ( $jobs as $job ) {
			$byId[ $job['action_id'] ] = $job;
		}

		$this->assertSame( 'translate', $byId[101]['kind'] );
		$this->assertSame( 'Hello World', $byId[101]['title'] );
		$this->assertSame( 'es', $byId[101]['target'] );
		$this->assertSame( 'AI exploded', $byId[101]['message'] ); // "action failed:" prefix stripped.

		$this->assertSame( 'detect', $byId[102]['kind'] );
		$this->assertSame( 'Item #999', $byId[102]['title'] ); // unknown id falls back.
		$this->assertSame( 'Could not detect', $byId[102]['message'] );
	}

	public function test_retry_job_reenqueues_and_deletes_failed_record(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		Wpait_Test_State::$as_failed_actions = array(
			101 => array(
				'hook'    => Wpait_Queue::HOOK,
				'args'    => array( array( 'type' => 'post', 'id' => 7, 'target' => 'es', 'attempt' => 2 ) ),
				'message' => 'AI exploded',
			),
		);

		$this->assertTrue( $this->queue->retry_job( 101 ) );

		// Re-enqueued with a fresh payload (no attempt key).
		$this->assertCount( 1, Wpait_Test_State::$as_enqueued );
		[ $hook, $args ] = Wpait_Test_State::$as_enqueued[0];
		$this->assertSame( Wpait_Queue::HOOK, $hook );
		$this->assertSame( array( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) ), $args );

		// Failed record removed.
		$this->assertSame( array( 101 ), Wpait_Test_State::$as_deleted );
	}

	public function test_retry_job_unknown_action_returns_error(): void {
		$result = $this->queue->retry_job( 4242 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_unknown_action', $result->get_error_code() );
		$this->assertSame( array(), Wpait_Test_State::$as_enqueued );
	}

	public function test_get_pending_jobs_lists_running_then_pending(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post', 'post_title' => 'Running Post' );
		Wpait_Test_State::$posts[8] = array( 'ID' => 8, 'post_type' => 'post', 'post_title' => 'Queued Post' );
		Wpait_Test_State::$as_actions = array(
			201 => array(
				'hook'   => Wpait_Queue::HOOK,
				'args'   => array( array( 'type' => 'post', 'id' => 8, 'target' => 'es' ) ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			202 => array(
				'hook'   => Wpait_Queue::HOOK,
				'args'   => array( array( 'type' => 'post', 'id' => 7, 'target' => 'fr' ) ),
				'status' => ActionScheduler_Store::STATUS_RUNNING,
			),
		);

		$jobs = $this->queue->get_pending_jobs();
		$this->assertCount( 2, $jobs );
		// Running first.
		$this->assertSame( 'running', $jobs[0]['state'] );
		$this->assertSame( 'Running Post', $jobs[0]['title'] );
		$this->assertSame( 'fr', $jobs[0]['target'] );
		$this->assertSame( 'pending', $jobs[1]['state'] );
		$this->assertSame( 'Queued Post', $jobs[1]['title'] );
	}

	public function test_get_pending_jobs_empty_when_idle(): void {
		$this->assertSame( array(), $this->queue->get_pending_jobs() );
	}

	public function test_describe_action_resolves_payload(): void {
		Wpait_Test_State::$as_failed_actions = array(
			101 => array(
				'hook'    => Wpait_Queue::HOOK,
				'args'    => array( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) ),
				'message' => 'x',
			),
		);
		$this->assertSame(
			array( 'hook' => Wpait_Queue::HOOK, 'type' => 'post', 'id' => 7, 'target' => 'es' ),
			$this->queue->describe_action( 101 )
		);
		$this->assertNull( $this->queue->describe_action( 999 ) );
	}

	/* ------------------------------------------------------------------ *
	 * cancel_job() / cancel_all() (#96)
	 * ------------------------------------------------------------------ */

	public function test_cancel_job_unschedules_pending_action(): void {
		Wpait_Test_State::$as_actions = array(
			301 => array(
				'hook'   => Wpait_Queue::HOOK,
				'args'   => array( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
		);

		$this->assertTrue( $this->queue->cancel_job( 301 ) );
		$this->assertSame( array( 301 ), Wpait_Test_State::$as_cancelled );
		// The cancelled action leaves the pending set.
		$this->assertArrayNotHasKey( 301, Wpait_Test_State::$as_actions );
	}

	public function test_cancel_job_unknown_action_returns_error(): void {
		$result = $this->queue->cancel_job( 4242 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpait_unknown_action', $result->get_error_code() );
		$this->assertSame( array(), Wpait_Test_State::$as_cancelled );
	}

	public function test_cancel_job_rejects_foreign_hook(): void {
		// An action belonging to another plugin's group/hook must not be cancellable.
		Wpait_Test_State::$as_actions = array(
			301 => array(
				'hook'   => 'some_other_plugin_hook',
				'args'   => array( array() ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
		);

		$result = $this->queue->cancel_job( 301 );
		$this->assertInstanceOf( WP_Error::class, $result );
		// Distinct code from the not-found (404) case so clients can disambiguate.
		$this->assertSame( 'wpait_foreign_action', $result->get_error_code() );
		$this->assertSame( array(), Wpait_Test_State::$as_cancelled );
	}

	public function test_cancel_all_cancels_pending_and_running_only(): void {
		Wpait_Test_State::$as_actions = array(
			301 => array(
				'hook'   => Wpait_Queue::HOOK,
				'args'   => array( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			302 => array(
				'hook'   => Wpait_Queue::DETECT_HOOK,
				'args'   => array( array( 'type' => 'post', 'id' => 8 ) ),
				'status' => ActionScheduler_Store::STATUS_RUNNING,
			),
			303 => array(
				'hook'   => Wpait_Queue::HOOK,
				'args'   => array( array( 'type' => 'post', 'id' => 9, 'target' => 'es' ) ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
		);

		$cancelled = $this->queue->cancel_all();

		$this->assertSame( 3, $cancelled );
		$this->assertEqualsCanonicalizing( array( 301, 302, 303 ), Wpait_Test_State::$as_cancelled );
		$this->assertSame( array(), Wpait_Test_State::$as_actions );
	}

	public function test_cancel_all_empty_queue_returns_zero(): void {
		$this->assertSame( 0, $this->queue->cancel_all() );
		$this->assertSame( array(), Wpait_Test_State::$as_cancelled );
	}

	/**
	 * A backlog larger than one batch must be fully cleared in a single call: with a
	 * batch size of 2 and 5 pending actions, cancel_all() paginates (cancelled
	 * actions leave the set, so each query returns the next batch) until none remain.
	 */
	public function test_cancel_all_paginates_until_backlog_cleared(): void {
		Wpait_Test_State::$filters['wpait_cancel_batch_size'] = 2;
		$expected = array();
		for ( $i = 401; $i <= 405; $i++ ) {
			Wpait_Test_State::$as_actions[ $i ] = array(
				'hook'   => Wpait_Queue::HOOK,
				'args'   => array( array( 'type' => 'post', 'id' => $i, 'target' => 'es' ) ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			);
			$expected[] = $i;
		}

		$cancelled = $this->queue->cancel_all();

		$this->assertSame( 5, $cancelled );
		$this->assertEqualsCanonicalizing( $expected, Wpait_Test_State::$as_cancelled );
		$this->assertSame( array(), Wpait_Test_State::$as_actions );
	}

	/**
	 * Infinite-loop guard: a batch made entirely of un-cancellable (foreign-hook)
	 * actions must stop the loop rather than re-fetch the same set forever.
	 */
	public function test_cancel_all_stops_when_batch_cancels_nothing(): void {
		Wpait_Test_State::$filters['wpait_cancel_batch_size'] = 2;
		Wpait_Test_State::$as_actions = array(
			501 => array(
				'hook'   => 'some_other_plugin_hook',
				'args'   => array( array() ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			502 => array(
				'hook'   => 'some_other_plugin_hook',
				'args'   => array( array() ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
		);

		$cancelled = $this->queue->cancel_all();

		$this->assertSame( 0, $cancelled );
		$this->assertSame( array(), Wpait_Test_State::$as_cancelled );
		// Foreign actions are left untouched (still present, not cancelled).
		$this->assertArrayHasKey( 501, Wpait_Test_State::$as_actions );
	}
}
