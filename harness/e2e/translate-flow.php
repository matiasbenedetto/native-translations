<?php
/**
 * E2E translation-flow test (issue #38). Run against a Playground site that has a
 * real AI provider wired in (OpenRouter / z-ai/glm-5.2 via `playground.sh
 * provision-ai`):
 *
 *   ./harness/playground.sh test e2e
 *
 * It exercises the REAL generation path end to end — the connector contract a mocked
 * unit test cannot prove — and asserts the created translation is linked, slug-
 * suffixed, term-mapped, recreatable (with a revision), and surfaced by the
 * front-end resolver. Each generation step is gated on a usable provider: with no
 * key configured the script SKIPS cleanly (exit 0) instead of failing.
 *
 * Run via `wp eval-file`, so WP_CLI is available.
 *
 * @package WpAiTranslate\Harness
 */

if ( ! class_exists( 'Wpait_Plugin' ) ) {
	WP_CLI::error( 'wp-ai-translate is not active.' );
}

// Run as an administrator so capability-dependent values (edit/view links, REST
// permissions) match the real authenticated-admin context the panels run in.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! empty( $admins ) ) {
	wp_set_current_user( (int) $admins[0] );
}

$created_posts = array();
$created_terms = array();
$failures      = 0;

$check = static function ( string $label, bool $ok ) use ( &$failures ) {
	if ( $ok ) {
		WP_CLI::log( '  ✓ ' . $label );
	} else {
		++$failures;
		WP_CLI::warning( '  ✗ ' . $label );
	}
};

// --- Gate: skip cleanly when no usable AI provider is configured. ---
if ( ! Wpait_Translator::can_generate_text() ) {
	WP_CLI::log( 'SKIP: no usable AI provider/model configured (set harness/.env.local + run provision-ai). Non-generation tests are covered by the PHP unit suite.' );
	return;
}

$plugin = Wpait_Plugin::instance();
$store  = $plugin->store();
$trans  = $plugin->translator();
$front  = $plugin->frontend();

// 'es' must be a configured, enabled language (the harness seed configures en + es).
$codes = wp_list_pluck( $plugin->languages()->enabled(), 'code' );
if ( ! in_array( 'es', $codes, true ) || ! in_array( 'en', $codes, true ) ) {
	WP_CLI::log( 'SKIP: en + es languages are not both configured (run the harness seed).' );
	return;
}

try {
	WP_CLI::log( '== Create + translate ==' );

	// A category on the source, with its own es translation, to prove term mapping.
	$cat = wp_insert_term( 'E2E Garden ' . wp_generate_password( 4, false ), 'category' );
	$cat_id = (int) $cat['term_id'];
	$created_terms[] = array( $cat_id, 'category' );
	$store->set_language( 'term', $cat_id, 'en' );
	$cat_es = $trans->translate_term( $cat_id, 'es' );
	if ( ! is_wp_error( $cat_es ) ) {
		$created_terms[] = array( (int) $cat_es, 'category' );
	}

	// Source post (published so the front-end resolver treats it as viewable).
	$src_id = (int) wp_insert_post(
		array(
			'post_title'   => 'The Blue Garden',
			'post_content' => '<!-- wp:paragraph --><p>A short note about a calm blue garden.</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
		),
		true
	);
	$created_posts[] = $src_id;
	$store->set_language( 'post', $src_id, 'en' );
	wp_set_object_terms( $src_id, array( $cat_id ), 'category', false );

	// THE translation call (real GLM-5.2 generation).
	$new_id = $trans->translate_post( $src_id, 'es' );
	$check( 'translate_post returned an id (not WP_Error)', is_int( $new_id ) );
	if ( is_wp_error( $new_id ) ) {
		WP_CLI::error( 'translate_post failed: [' . $new_id->get_error_code() . '] ' . $new_id->get_error_message() );
	}
	$created_posts[] = $new_id;
	$new_post = get_post( $new_id );

	$check( 'translation is a draft', $new_post && 'draft' === $new_post->post_status );
	$check( 'translation language is es', 'es' === $store->get_language( 'post', $new_id ) );
	$check( 'translation has non-empty translated title', '' !== trim( (string) $new_post->post_title ) );
	$check( 'translation title differs from source (was actually translated)', $new_post->post_title !== 'The Blue Garden' );

	$sibs = $store->get_translations( 'post', $src_id );
	$check( 'source is linked to the es translation', isset( $sibs['es'] ) && (int) $sibs['es'] === $new_id );

	$check( 'translation slug is language-suffixed (-es)', (bool) preg_match( '/-es$/', (string) $new_post->post_name ) );

	// Term mapping: the new post should carry the es category if it exists, else the
	// original — either way, exactly one category, and the es one when available.
	$new_cats = wp_get_object_terms( $new_id, 'category', array( 'fields' => 'ids' ) );
	$expected_cat = ! is_wp_error( $cat_es ) ? (int) $cat_es : $cat_id;
	$check( 'translation mapped to the expected category', in_array( $expected_cat, array_map( 'intval', $new_cats ), true ) );

	WP_CLI::log( '== Recreate (in place, with revision) ==' );
	$rev_before = count( wp_get_post_revisions( $new_id ) );
	$re = $trans->recreate_post( $new_id );
	$check( 'recreate_post returned the same id', is_int( $re ) && (int) $re === $new_id );
	$rev_after = count( wp_get_post_revisions( $new_id ) );
	$check( 'a revision was saved before overwriting', $rev_after > $rev_before );

	WP_CLI::log( '== Front-end resolver ==' );
	$links = $front->links_for_post( $new_id, false );
	$has_en = false;
	foreach ( $links as $row ) {
		if ( 'en' === $row['code'] ) {
			$has_en = true;
		}
	}
	$check( 'language switcher resolves the en sibling for the translation', $has_en );

	WP_CLI::log( '== REST payload shape ==' );
	$req = new WP_REST_Request( 'GET', '/' . Wpait_Rest::NS . '/translations' );
	$req->set_param( 'object_id', $src_id );
	$req->set_param( 'type', 'post' );
	$res  = $plugin->rest()->handle_translations( $req );
	$data = $res instanceof WP_REST_Response ? $res->get_data() : array();
	$check( 'REST /translations returns the es translation with edit + view links', isset( $data['translations']['es']['edit_link'], $data['translations']['es']['view_link'] ) );
} finally {
	// Always clean up so the seed/site is left as we found it.
	foreach ( $created_posts as $pid ) {
		wp_delete_post( $pid, true );
	}
	foreach ( $created_terms as $t ) {
		wp_delete_term( $t[0], $t[1] );
	}
}

if ( $failures > 0 ) {
	WP_CLI::error( $failures . ' E2E assertion(s) failed.' );
}
WP_CLI::success( 'E2E translation flow passed (real OpenRouter / z-ai/glm-5.2 generation).' );
