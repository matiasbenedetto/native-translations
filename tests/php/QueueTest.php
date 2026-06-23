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

	public function translate_post( int $source_id, string $to_code ) {
		$this->calls[] = array( 'post', $source_id, $to_code );
		return $this->post_result;
	}

	public function translate_term( int $term_id, string $to_code ) {
		$this->calls[] = array( 'term', $term_id, $to_code );
		return $this->term_result;
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

	public function test_run_job_throws_on_wp_error(): void {
		Wpait_Test_State::$posts[7] = array( 'ID' => 7, 'post_type' => 'post' );
		$this->translator->post_result = new WP_Error( 'wpait_ai', 'AI exploded' );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'AI exploded' );
		$this->queue->run_job( array( 'type' => 'post', 'id' => 7, 'target' => 'es' ) );
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
}
