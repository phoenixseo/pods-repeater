/**
 * E2E-Beleg für den Feldgruppen-Pfad (S18).
 *
 * Beweist den kompletten Weg einer registrierten Feldgruppe durch die echte
 * Instanz:
 *
 *   Feldgruppe in der Admin-UI anlegen  →  Feld mit repeater_group anlegen
 *   →  Editor rendert die Sub-Felder aus der Gruppe  →  Zeilen füllen
 *   →  Limit blockt  →  Speichern  →  Neuladen  →  Move-up  →  Lese-API
 *
 * Abgrenzung zu scripts/e2e-admin-ui.mjs (S14): dort kommt die Sub-Feld-
 * Definition aus Inline-JSON (repeater_sub_fields). Hier steht im Feld
 * AUSSCHLIESSLICH repeater_group; die Sub-Felder existieren nur in der
 * Registry. Das ist der Produktkern.
 *
 * Aufruf:
 *   node scripts/e2e-group-path.mjs
 *   BASE_URL=http://… WP_CLI_CONTAINER=… node scripts/e2e-group-path.mjs
 *
 * Voraussetzungen: laufende wp-env-Instanz (Browser unter BASE_URL), Pods
 * im Mount (scripts/watch-pods-install.sh), Playwright auflösbar.
 *
 * Ergebnis: dist/e2e/ergebnis-gruppe.json, Exit 0 nur wenn jeder Schritt
 * PASS ist.
 */

import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { chromium } = require( 'playwright' );

const HERE = dirname( fileURLToPath( import.meta.url ) );
const ROOT = resolve( HERE, '..' );

const BASE_URL = ( process.env.BASE_URL || 'http://localhost:8899' ).replace( /\/+$/, '' );
const WP_USER = process.env.WP_USER || 'admin';
const WP_PASS = process.env.WP_PASS || 'password';
const CLI_CONTAINER = process.env.WP_CLI_CONTAINER || 'wp-env-pods-repeater-54d58dae-cli-1';
const SHOT_DIR = resolve( ROOT, 'dist/e2e' );

const POD_NAME = 'e2e-group-path';
const FIELD_NAME = 'items';
const GROUP_SLUG = 'e2e-group';
const ROW_LIMIT = 3;
const HEADLINES = [ 'Zeile-1', 'Zeile-2', 'Zeile-3' ];
const NOTES = [ 'Notiz-1', 'Notiz-2', 'Notiz-3' ];

/**
 * Selektoren stammen aus dem gerenderten HTML (S14-Befund: nicht raten):
 * classes/fields/repeater.php:526-557 (Feld), classes/admin/field-groups-page.php:381-403 (Formular).
 */
const SEL = {
	repeater: '.pods-repeater',
	rows: '.pods-repeater .pods-repeater-rows .pods-repeater-row',
	add: '.pods-repeater .pods-repeater-add',
	rowRemove: '.pods-repeater-remove',
	rowUp: '.pods-repeater-move-up',
	label: '.pods-repeater-subfield-label',
	headlineInput: 'input[data-sub="headline"]',
	noteInput: 'textarea[data-sub="note"]',
	groupNotice: '.pods-repeater-group-notice',
	// Feldgruppen-Admin-Seite
	groupForm: 'form.pods-repeater-group-form',
	groupLabel: '#pods-repeater-group-label',
	groupSlug: '#pods-repeater-group-slug',
	groupAddField: '.pods-repeater-group-add-field',
	groupRow: '.pods-repeater-group-fields tbody tr.pods-repeater-group-field-row',
	groupName: '.pods-repeater-group-field-name',
	groupFieldLabel: '.pods-repeater-group-field-label',
	groupType: '.pods-repeater-group-field-type',
	groupRequired: '.pods-repeater-group-field-required',
	groupDelete: '.pods-repeater-group-delete',
	// submit_button() vergibt keine ID, sondern .button-primary (page.php:359).
	groupSubmit: 'form.pods-repeater-group-form .button-primary',
};

/* ------------------------------------------------------------------ Hilfen */

const results = [];
const notes = [];
let browser = null;
let page = null;
let apiRead = null;

const log = ( line ) => process.stdout.write( line + '\n' );

function check( step, ok, observed = '', expected = '' ) {
	results.push( { step, ok, observed: ok ? '' : observed, expected: ok ? '' : expected } );
	if ( ok ) {
		log( `PASS ${ step }` );
		return;
	}
	log( `FAIL ${ step }: ${ observed } / erwartet: ${ expected }` );
	throw new StepFailure( step, observed, expected );
}

class StepFailure extends Error {
	constructor( step, observed, expected ) {
		super( `${ step }: ${ observed }` );
		this.step = step;
		this.observed = observed;
		this.expected = expected;
	}
}

async function shot( name ) {
	mkdirSync( SHOT_DIR, { recursive: true } );
	const file = resolve( SHOT_DIR, `${ name }.png` );
	if ( page ) {
		await page.screenshot( { path: file, fullPage: true } ).catch( () => {} );
	}
	return file;
}

function wpCli( file ) {
	const r = spawnSync( 'docker', [ 'exec', CLI_CONTAINER, 'wp', 'eval-file', file ], {
		encoding: 'utf8',
		maxBuffer: 16 * 1024 * 1024,
	} );
	if ( r.status !== 0 ) {
		const e = new Error( `wp eval-file ${ file } Exit ${ r.status } (signal ${ r.signal || '-' })` );
		e.stdout = r.stdout || '';
		e.stderr = r.stderr || '';
		e.status = r.status;
		throw e;
	}
	return r.stdout || '';
}

/** Schreibt ein PHP-Snippet in den Container (base64, damit kein Quoting bricht). */
function putPhp( php, target ) {
	const b64 = Buffer.from( php, 'utf8' ).toString( 'base64' );
	execFileSync( 'docker', [ 'exec', CLI_CONTAINER, 'sh', '-c', `echo ${ b64 } | base64 -d > ${ target }` ], {
		encoding: 'utf8',
	} );
	return target;
}

/** Filtert WP-CLI-Rauschen (Notices aus dem Plugin) aus der Ausgabe. */
function clean( out ) {
	return out
		.split( '\n' )
		.filter(
			( l ) =>
				! /^(PHP )?(Notice|Warning|Deprecated|Strict Standards):/.test( l ) &&
				! /Function _load_textdomain_just_in_time was called/.test( l )
		)
		.join( '\n' )
		.trim();
}

const rowValues = ( selector ) =>
	page.locator( `${ SEL.rows } ${ selector }` ).evaluateAll( ( els ) => els.map( ( e ) => e.value ) );

const rowCount = () => page.locator( SEL.rows ).count();

const subFieldLabels = () =>
	page
		.locator( `${ SEL.rows } .pods-repeater-subfield-label` )
		.evaluateAll( ( els ) => Array.from( new Set( els.map( ( e ) => e.textContent.trim() ) ) ) );

async function clickExpectingRows( locator, expected, timeoutMs = 5000 ) {
	await locator.click();
	try {
		await page.waitForFunction(
			( [ sel, n ] ) => document.querySelectorAll( sel ).length === n,
			[ SEL.rows, expected ],
			{ timeout: timeoutMs }
		);
		return true;
	} catch {
		return false;
	}
}

/* ------------------------------------------------------- PHP-Vorbereitung */

/**
 * Gegenprobe zur Admin-UI: die Gruppe muss in der Registry liegen, mit
 * genau den zwei Feldern, die das Formular abgeschickt hat.
 */
const CHECK_GROUP_PHP = `<?php
$group  = pods_repeater_get_group( '${ GROUP_SLUG }' );
$fields = is_array( $group ) && isset( $group['fields'] ) ? (array) $group['fields'] : array();
$names  = array();
foreach ( $fields as $f ) {
	if ( isset( $f['name'] ) ) { $names[] = (string) $f['name']; }
}
$ok = is_array( $group ) && array( 'headline', 'note' ) === $names;
echo 'GROUP-CHECK ' . wp_json_encode( array( 'present' => is_array( $group ), 'names' => $names, 'labels' => array_map( static function ( $f ) { return isset( $f['label'] ) ? (string) $f['label'] : ''; }, $fields ) ) ) . "\n";
echo $ok ? "GROUP-OK\n" : "GROUP-FAIL\n";
exit( $ok ? 0 : 1 );
`;

/**
 * Schritt 3: Pod + Feld. Bewusst OHNE repeater_sub_fields — wenn das Feld
 * ohne Inline-JSON Sub-Felder rendert, können sie nur aus der Gruppe kommen.
 */
const SETUP_PHP = `<?php
$failures = 0;
function e2e_setup_check( $label, $cond, $detail = '' ) {
	global $failures;
	if ( $cond ) {
		echo "PASS {$label}\n";
	} else {
		$failures++;
		echo "FAIL {$label}: " . ( '' !== $detail ? $detail : 'Zusage nicht erfuellt' ) . "\n";
	}
}

$existing = pods_api()->load_pod( array( 'name' => '${ POD_NAME }' ) );
if ( ! empty( $existing ) ) {
	pods_api()->delete_pod( array( 'id' => (int) $existing['id'], 'delete_all' => true ) );
}

$pod_id = pods_api()->save_pod(
	array(
		'name'                => '${ POD_NAME }',
		'type'                => 'post_type',
		'storage'             => 'meta',
		'label'               => 'E2E Feldgruppen-Pfad',
		'public'              => 1,
		'publicly_queryable'  => 0,
		'exclude_from_search' => 1,
		'show_in_rest'        => 0,
		'show_in_menu'        => 1,
		'menu_position'       => 60,
		'supports_title'      => 1,
		'supports_editor'     => 1,
	)
);
e2e_setup_check( 'Pod ${ POD_NAME } angelegt', ! is_wp_error( $pod_id ) && $pod_id > 0, is_wp_error( $pod_id ) ? $pod_id->get_error_message() : '' );
if ( is_wp_error( $pod_id ) || $pod_id <= 0 ) { echo "SETUP-ABORT\n"; exit( 1 ); }

$field_id = pods_api()->save_field(
	array(
		'pod_id'         => (int) $pod_id,
		'pod'            => '${ POD_NAME }',
		'name'           => '${ FIELD_NAME }',
		'label'          => 'Items',
		'type'           => 'repeater',
		'disable_dfv'    => '1',
		'repeater_limit' => ${ ROW_LIMIT },
		'repeater_group' => '${ GROUP_SLUG }',
	)
);
e2e_setup_check( 'Feld ${ FIELD_NAME } angelegt', ! is_wp_error( $field_id ) && $field_id > 0, is_wp_error( $field_id ) ? $field_id->get_error_message() : '' );
if ( is_wp_error( $field_id ) || $field_id <= 0 ) { echo "SETUP-ABORT\n"; exit( 1 ); }

pods_api()->cache_flush_pods( array( 'name' => '${ POD_NAME }' ) );
$pod  = pods_api()->load_pod( array( 'name' => '${ POD_NAME }' ) );
$args = ( is_object( $pod['fields']['${ FIELD_NAME }'] ) && method_exists( $pod['fields']['${ FIELD_NAME }'], 'get_args' ) )
	? $pod['fields']['${ FIELD_NAME }']->get_args()
	: (array) $pod['fields']['${ FIELD_NAME }'];

e2e_setup_check( 'Feldtyp nachgeladen ist repeater', isset( $args['type'] ) && 'repeater' === $args['type'], 'type=' . wp_json_encode( isset( $args['type'] ) ? $args['type'] : null ) );
e2e_setup_check( 'repeater_group gespeichert', isset( $args['repeater_group'] ) && '${ GROUP_SLUG }' === $args['repeater_group'], 'group=' . wp_json_encode( isset( $args['repeater_group'] ) ? $args['repeater_group'] : null ) );
e2e_setup_check( 'Limit ist ${ ROW_LIMIT }', isset( $args['repeater_limit'] ) && ${ ROW_LIMIT } === (int) $args['repeater_limit'], 'limit=' . wp_json_encode( isset( $args['repeater_limit'] ) ? $args['repeater_limit'] : null ) );
e2e_setup_check(
	'KEIN Inline-JSON am Feld (Sub-Felder muessen aus der Gruppe kommen)',
	! isset( $args['repeater_sub_fields'] ) || '' === (string) $args['repeater_sub_fields'],
	'sub_fields=' . wp_json_encode( isset( $args['repeater_sub_fields'] ) ? $args['repeater_sub_fields'] : null )
);

echo 'SETUP-RESULT ' . wp_json_encode( array( 'pod_id' => (int) $pod_id, 'field_id' => (int) $field_id ) ) . "\n";
exit( 0 === $failures ? 0 : 1 );
`;

/**
 * Schritt 8: Lese-API. Bewusst ein frischer Prozess ohne vorherige
 * save_group()-Aufrufe — die Gruppe muss auch im reinen Lesepfad auflösen.
 */
const buildReadPhp = ( expected, expectedNotes ) => `<?php
$out = array( 'checks' => array(), 'api' => array() );
// Erwartete Reihenfolge kommt aus dem Browser (nach Move-up + Save),
// damit das PHP-Skript nicht still eine andere Ordnung behauptet.
$expected_headlines = array( ${ expected.map( ( h ) => `'${ h }'` ).join( ', ' ) } );
$expected_notes     = array( ${ expectedNotes.map( ( n ) => `'${ n }'` ).join( ', ' ) } );
// wp eval-file fuehrt in einem eigenen Scope aus: "global $out" in einer
// Funktion zeigt NICHT auf dieses $out. Deshalb per Referenz arbeiten.
function e2e_read_check( &$out, $label, $cond, $detail = '' ) {
	$out['checks'][] = array( 'label' => $label, 'ok' => (bool) $cond, 'detail' => (string) $detail );
}
function e2e_rows_headline( $rows ) {
	return array_values( array_map( static function ( $row ) { return isset( $row['headline'] ) ? (string) $row['headline'] : null; }, (array) $rows ) );
}
function e2e_rows_note( $rows ) {
	return array_values( array_map( static function ( $row ) { return isset( $row['note'] ) ? (string) $row['note'] : null; }, (array) $rows ) );
}

$posts = get_posts(
	array(
		'post_type'        => '${ POD_NAME }',
		'post_status'      => 'any',
		'numberposts'      => 1,
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => true,
	)
);
e2e_read_check( $out, 'Testbeitrag gefunden', 1 === count( $posts ), 'posts=' . count( $posts ) );
if ( empty( $posts ) ) {
	echo 'READ-RESULT ' . wp_json_encode( $out ) . "\n";
	exit( 1 );
}
$post_id = (int) $posts[0];
$out['post_id'] = $post_id;

// 1) pods_field(): Wert des Feldes als Array.
$field_rows = pods_field( '${ POD_NAME }', $post_id, '${ FIELD_NAME }', true );
$out['api']['pods_field']     = $field_rows;
$out['api']['pods_field_ids'] = array( 1, 2, 3 );
e2e_read_check(
	$out,
	'pods_field() liefert 3 Zeilen als Array',
	is_array( $field_rows ) && 3 === count( $field_rows ),
	'type=' . gettype( $field_rows ) . ' count=' . ( is_array( $field_rows ) ? count( $field_rows ) : -1 )
);
e2e_read_check(
	$out,
	'pods_field() headlines in Reihenfolge',
	$expected_headlines === e2e_rows_headline( $field_rows ),
	wp_json_encode( e2e_rows_headline( (array) $field_rows ) )
);
e2e_read_check(
	$out,
	'pods_field() notes in Reihenfolge',
	$expected_notes === e2e_rows_note( $field_rows ),
	wp_json_encode( e2e_rows_note( (array) $field_rows ) )
);

// 2) Loop-API: have_rows / the_row / get_sub_field.
$loop_headlines = array();
$loop_notes     = array();
$guard          = 0;
while ( pods_repeater_have_rows( '${ FIELD_NAME }', $post_id, '${ POD_NAME }' ) && $guard < 20 ) {
	pods_repeater_the_row();
	$loop_headlines[] = pods_repeater_get_sub_field( 'headline' );
	$loop_notes[]     = pods_repeater_get_sub_field( 'note' );
	$guard++;
}
$out['api']['loop_headlines'] = $loop_headlines;
$out['api']['loop_notes']     = $loop_notes;
e2e_read_check( $out, 'Loop-API liefert 3 Zeilen', 3 === count( $loop_headlines ), 'count=' . count( $loop_headlines ) );
e2e_read_check(
	$out,
	'Loop-API headlines identisch zu pods_field()',
	$loop_headlines === e2e_rows_headline( $field_rows ),
	wp_json_encode( $loop_headlines )
);
e2e_read_check(
	$out,
	'Loop-API notes identisch zu pods_field()',
	$loop_notes === e2e_rows_note( $field_rows ),
	wp_json_encode( $loop_notes )
);

// 3) Die Sub-Feld-Namen kennt nur die Gruppe – als explizite Zeile.
$sub_field_names = array();
foreach ( (array) pods_repeater_get_group_fields( '${ GROUP_SLUG }' ) as $sub ) {
	if ( isset( $sub['name'] ) ) {
		$sub_field_names[] = (string) $sub['name'];
	}
}
$row_keys = array();
foreach ( (array) $field_rows as $row ) {
	if ( is_array( $row ) ) {
		foreach ( array_keys( $row ) as $key ) {
			$row_keys[ $key ] = true;
		}
	}
}
$row_keys = array_keys( $row_keys );
sort( $row_keys );
$out['api']['group_sub_fields'] = $sub_field_names;
$out['api']['stored_row_keys']  = $row_keys;
e2e_read_check(
	$out,
	'Lese-API kennt die Gruppen-Sub-Felder (${ GROUP_SLUG }: headline, note)',
	array( 'headline', 'note' ) === $sub_field_names,
	wp_json_encode( $sub_field_names )
);
e2e_read_check(
	$out,
	'Gespeicherte Zeilen tragen genau die Gruppen-Sub-Felder',
	array( 'headline', 'note' ) === $row_keys,
	wp_json_encode( $row_keys )
);

$all_ok = true;
foreach ( $out['checks'] as $c ) {
	if ( ! $c['ok'] ) { $all_ok = false; }
}
echo 'READ-RESULT ' . wp_json_encode( $out ) . "\n";
exit( $all_ok ? 0 : 1 );
`;

const CLEANUP_PHP = `<?php
$deleted = false;
$pod = pods_api()->load_pod( array( 'name' => '${ POD_NAME }' ) );
if ( ! empty( $pod ) ) {
	pods_api()->delete_pod( array( 'id' => (int) $pod['id'], 'delete_all' => true ) );
	$deleted = true;
}
$leftover = get_posts(
	array(
		'post_type'        => '${ POD_NAME }',
		'post_status'      => 'any',
		'numberposts'      => -1,
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => true,
	)
);
$removed = 0;
foreach ( $leftover as $post_id ) {
	wp_delete_post( (int) $post_id, true );
	$removed++;
}
$group_removed = pods_repeater_delete_group( '${ GROUP_SLUG }' ) || null === pods_repeater_get_group( '${ GROUP_SLUG }' );
$still_pod   = ! empty( pods_api()->load_pod( array( 'name' => '${ POD_NAME }' ) ) );
$still_group = null !== pods_repeater_get_group( '${ GROUP_SLUG }' );
echo 'CLEANUP-RESULT ' . wp_json_encode(
	array(
		'pod_deleted'        => $deleted,
		'pod_still_present'  => $still_pod,
		'group_removed'      => (bool) $group_removed,
		'group_still_present'=> $still_group,
		'removed_posts'      => $removed,
	)
) . "\n";
exit( ( $still_pod || $still_group ) ? 1 : 0 );
`;

/* --------------------------------------------------------------- Ablauf */

/**
 * Liest die von der Admin-Seite zurückgemeldeten Gruppen aus dem DOM.
 * Beobachtung statt Annahme: was die Liste anzeigt, ist der Zustand.
 */
async function readGroupListRow() {
	await page.waitForSelector( '.pods-repeater-field-groups', { timeout: 15000 } );
	return page
		.locator( 'table.wp-list-table tbody tr' )
		.evaluateAll( ( rows ) =>
			rows
				.map( ( r ) => {
					const cells = Array.from( r.querySelectorAll( 'td' ) ).map( ( c ) => c.textContent.trim() );
					return { slug: cells[ 0 ] || '', label: cells[ 1 ] || '', fields: cells[ 2 ] || '' };
				} )
				.filter( ( r ) => r.slug !== '' )
		);
}

/**
 * Liest den Redirect-Zustand nach dem Absenden: URL, Notice und Liste.
 * Beobachtet wird beides, weil ein abgelehnter Save (Nonce, Rechte) sonst
 * wie ein Erfolg aussähe.
 */
async function readSaveOutcome() {
	return page.evaluate( () => {
		const wrap = document.querySelector( '.pods-repeater-field-groups' );
		const notices = Array.from( document.querySelectorAll( '.notice, .updated, .error' ) )
			.map( ( n ) => n.textContent.replace( /\s+/g, ' ' ).trim() )
			.filter( ( t ) => t !== '' );
		const rows = Array.from( document.querySelectorAll( 'table.wp-list-table tbody tr' ) ).map( ( r ) =>
			Array.from( r.querySelectorAll( 'td' ) ).map( ( c ) => c.textContent.trim() )
		);
		return {
			url: location.href,
			hasWrapper: !! wrap,
			notices,
			rows: rows.filter( ( cells ) => ( cells[ 0 ] || '' ) !== '' ),
		};
	} );
}

async function run() {
	log( `E2E Feldgruppen-Pfad gegen ${ BASE_URL }` );

	// --- Schritt 1: Login ------------------------------------------------
	browser = await chromium.launch( { headless: true } );
	const context = await browser.newContext( { viewport: { width: 1440, height: 1100 } } );
	page = await context.newPage();

	const consoleErrors = [];
	page.on( 'pageerror', ( e ) => consoleErrors.push( String( e ).slice( 0, 300 ) ) );
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' ) {
			consoleErrors.push( m.text().slice( 0, 300 ) );
		}
	} );

	await page.goto( `${ BASE_URL }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', WP_USER );
	await page.fill( '#user_pass', WP_PASS );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
		page.click( '#wp-submit' ),
	] );
	const loggedIn = ( await page.locator( '#wpadminbar, #adminmenu' ).count() ) > 0;
	check(
		'1 Login und Weiterleitung nach /wp-admin/',
		loggedIn && page.url().includes( '/wp-admin/' ),
		`url=${ page.url() }, adminbar=${ loggedIn }`,
		'URL enthält /wp-admin/ und Admin-Bar vorhanden'
	);

	// --- Schritt 2: Feldgruppe über die Admin-UI anlegen ------------------
	// Erst aufräumen: ein Rest aus einem abgebrochenen Lauf würde den
	// Slug-Save in eine Bearbeitung verwandeln und den Schritt entwerten.
	putPhp( CLEANUP_PHP, '/tmp/e2e-group-cleanup.php' );
	clean( wpCli( '/tmp/e2e-group-cleanup.php' ) );

	await page.goto( `${ BASE_URL }/wp-admin/admin.php?page=pods-repeater-groups&view=new`, {
		waitUntil: 'domcontentloaded',
	} );
	const hasForm = ( await page.locator( SEL.groupForm ).count() ) === 1;
	check(
		'2a Feldgruppen-Seite erreichbar, Formular gerendert',
		hasForm,
		`url=${ page.url() }, form=${ hasForm }`,
		'genau ein form.pods-repeater-group-form'
	);

	await page.locator( SEL.groupLabel ).fill( 'E2E Gruppe' );
	await page.locator( SEL.groupSlug ).fill( GROUP_SLUG );

	// Erste Feldzeile ist serverseitig vorhanden (page.php:316-319).
	const rowsAfterLoad = await page.locator( SEL.groupRow ).count();
	await page.locator( `${ SEL.groupRow } ${ SEL.groupName }` ).first().fill( 'headline' );
	await page.locator( `${ SEL.groupRow } ${ SEL.groupFieldLabel }` ).first().fill( 'Headline' );
	await page.locator( `${ SEL.groupRow } ${ SEL.groupType }` ).first().selectOption( 'text' );
	await page.locator( `${ SEL.groupRow } ${ SEL.groupRequired }` ).first().check();

	// Zweite Zeile über "Add field" (JS-Pfad der Admin-Seite).
	await page.locator( SEL.groupAddField ).click();
	await page.waitForFunction(
		( [ sel, n ] ) => document.querySelectorAll( sel ).length === n,
		[ SEL.groupRow, 2 ],
		{ timeout: 5000 }
	);
	await page.locator( `${ SEL.groupRow } ${ SEL.groupName }` ).nth( 1 ).fill( 'note' );
	await page.locator( `${ SEL.groupRow } ${ SEL.groupFieldLabel }` ).nth( 1 ).fill( 'Note' );
	await page.locator( `${ SEL.groupRow } ${ SEL.groupType }` ).nth( 1 ).selectOption( 'paragraph' );

	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
		page.locator( SEL.groupSubmit ).click(),
	] );

	const outcome = await readSaveOutcome();
	// Nach dem Save bleibt die Seite auf der Formular-Ansicht (slug=…),
	// nicht auf der Liste — der Redirect trägt den neuen Slug. Beobachtet
	// wird deshalb der Formularzustand nach dem Redirect plus die Notice.
	const formRowsAfterSave = await page.locator( SEL.groupRow ).count();
	const formNamesAfterSave = await page
		.locator( `${ SEL.groupRow } ${ SEL.groupName }` )
		.evaluateAll( ( els ) => els.map( ( e ) => e.value ) );
	const savedInRegistry = ( () => {
		try {
			putPhp( CHECK_GROUP_PHP, '/tmp/e2e-group-check-group.php' );
			return clean( wpCli( '/tmp/e2e-group-check-group.php' ) );
		} catch ( e ) {
			return clean( `${ e.stdout || '' }${ e.stderr || '' }` ) || e.message;
		}
	} )();
	// Notice-Kennung steht als data-Attribut am Hinweis-Div (page.php:492).
	const noticeCodes = await page
		.locator( '[data-pods-repeater-notice]' )
		.evaluateAll( ( els ) => els.map( ( e ) => e.getAttribute( 'data-pods-repeater-notice' ) ) );
	const registryOk = /GROUP-OK/.test( savedInRegistry );
	check(
		'2 Feldgruppe über die Admin-UI gespeichert (Slug, 2 Felder, Registry)',
		rowsAfterLoad === 1 &&
			outcome.url.includes( `slug=${ GROUP_SLUG }` ) &&
			noticeCodes.includes( 'saved' ) &&
			JSON.stringify( formNamesAfterSave ) === JSON.stringify( [ 'headline', 'note' ] ) &&
			registryOk,
		`url=${ outcome.url }, Zeilen beim Laden=${ rowsAfterLoad }, Zeilen nach Save=${ formRowsAfterSave }, names=${ JSON.stringify( formNamesAfterSave ) }, Notices=${ JSON.stringify( noticeCodes ) }, Registry=${ savedInRegistry.slice( 0, 300 ) }`,
		`Redirect auf slug=${ GROUP_SLUG }, Notice saved, Formular zeigt headline+note, Registry kennt die Gruppe`
	);

	// Erneut öffnen: der gespeicherte Zustand muss aus der Registry kommen.
	await page.goto( `${ BASE_URL }/wp-admin/admin.php?page=pods-repeater-groups&slug=${ GROUP_SLUG }`, {
		waitUntil: 'domcontentloaded',
	} );
	const reopenedNames = await page
		.locator( `${ SEL.groupRow } ${ SEL.groupName }` )
		.evaluateAll( ( els ) => els.map( ( e ) => e.value ) );
	const reopenedTypes = await page
		.locator( `${ SEL.groupRow } ${ SEL.groupType }` )
		.evaluateAll( ( els ) => els.map( ( e ) => e.value ) );
	check(
		'2b Erneutes Öffnen zeigt die gespeicherte Gruppe',
		JSON.stringify( reopenedNames ) === JSON.stringify( [ 'headline', 'note' ] ) &&
			JSON.stringify( reopenedTypes ) === JSON.stringify( [ 'text', 'paragraph' ] ),
		`names=${ JSON.stringify( reopenedNames ) }, types=${ JSON.stringify( reopenedTypes ) }`,
		'names=["headline","note"], types=["text","paragraph"]'
	);

	// --- Schritt 3: Pod + Feld mit repeater_group (ohne Inline-JSON) ------
	const setupFile = putPhp( SETUP_PHP, '/tmp/e2e-group-setup.php' );
	let setupOut;
	try {
		setupOut = clean( wpCli( setupFile ) );
	} catch ( e ) {
		const text = clean( `${ e.stdout || '' }${ e.stderr || '' }` ) || e.message;
		check( '3 Pod und Repeater-Feld (repeater_group) vorbereitet', false, text.slice( 0, 500 ), 'wp eval-file endet mit Exit 0' );
	}
	const setupFails = setupOut.split( '\n' ).filter( ( l ) => l.startsWith( 'FAIL' ) );
	check(
		'3 Pod und Feld anlegen: repeater_group gesetzt, kein Inline-JSON',
		0 === setupFails.length && setupOut.includes( 'SETUP-RESULT' ),
		setupFails.join( ' | ' ) || setupOut.slice( 0, 500 ),
		'alle Vorbereitungs-Zusagen erfüllt'
	);

	// --- Schritt 4: Sub-Felder kommen aus der Gruppe ----------------------
	await page.goto( `${ BASE_URL }/wp-admin/post-new.php?post_type=${ POD_NAME }`, {
		waitUntil: 'domcontentloaded',
	} );
	await page.waitForSelector( SEL.repeater, { timeout: 20000 } );

	const fieldCount = await page.locator( SEL.repeater ).count();
	const limitAttr = await page.locator( SEL.repeater ).first().getAttribute( 'data-limit' );
	const labels = await subFieldLabels();
	const noticeCount = await page.locator( SEL.groupNotice ).count();
	const headlineInputs = await page.locator( `${ SEL.rows } ${ SEL.headlineInput }` ).count();
	const noteInputs = await page.locator( `${ SEL.rows } ${ SEL.noteInput}` ).count();

	check(
		'4a Editor rendert den Repeater mit data-limit',
		1 === fieldCount && Number( limitAttr ) === ROW_LIMIT,
		`Container=${ fieldCount }, data-limit=${ limitAttr }`,
		`genau 1 Container, data-limit=${ ROW_LIMIT }`
	);
	check(
		'4b Sub-Feld-Labels kommen aus der Feldgruppe (headline, note)',
		JSON.stringify( labels ) === JSON.stringify( [ 'Headline', 'Note' ] ),
		`Labels=${ JSON.stringify( labels ) }, Seitenfehler=${ JSON.stringify( consoleErrors.slice( 0, 3 ) ) }`,
		'Labels=["Headline","Note"] aus der Registry'
	);
	check(
		'4c Kein Gruppen-Hinweis (Slug löst auf) und beide Eingaben vorhanden',
		0 === noticeCount && headlineInputs >= 1 && noteInputs >= 1,
		`notice=${ noticeCount }, headline-Inputs=${ headlineInputs }, note-Textareas=${ noteInputs }`,
		'kein .pods-repeater-group-notice, je mindestens ein Input für headline und note'
	);

	// 4d: die Auflösung selbst, nicht nur ihre Wirkung im DOM. Wenn
	// pods_repeater_get_group_fields() leer zurückkommt, muss dieser Schritt
	// rot werden (Rot-Beleg S18), auch wenn das Rendering noch Reste zeigt.
	const groupResolve = ( () => {
		try {
			putPhp( CHECK_GROUP_PHP, '/tmp/e2e-group-check-group.php' );
			return clean( wpCli( '/tmp/e2e-group-check-group.php' ) );
		} catch ( e ) {
			return clean( `${ e.stdout || '' }${ e.stderr || '' }` ) || e.message;
		}
	} )();
	check(
		'4d Feldgruppe löst auf: pods_repeater_get_group_fields() liefert headline und note',
		/ GROUP-OK|^GROUP-OK$/m.test( groupResolve ) && groupResolve.includes( '"headline","note"' ),
		`Registry-Antwort=${ groupResolve.replace( /\s+/g, ' ' ).slice( 0, 400 ) }, Verzeichnis=${ JSON.stringify( labels ) }`,
		'GROUP-OK mit names=["headline","note"]'
	);

	await page.locator( '#title' ).fill( `E2E Feldgruppe ${ Date.now() }` );

	// --- Schritt 5: Drei Zeilen anlegen, vierte blockt --------------------
	const startRows = await rowCount();
	const addButton = page.locator( SEL.add ).first();
	await page.locator( `${ SEL.rows } ${ SEL.headlineInput }` ).first().fill( HEADLINES[ 0 ] );
	await page.locator( `${ SEL.rows } ${ SEL.noteInput }` ).first().fill( NOTES[ 0 ] );

	let addWorked = true;
	for ( let i = 1; i < HEADLINES.length; i++ ) {
		if ( ! ( await clickExpectingRows( addButton, startRows + i ) ) ) {
			addWorked = false;
			break;
		}
		await page.locator( `${ SEL.rows } ${ SEL.headlineInput }` ).nth( i ).fill( HEADLINES[ i ] );
		await page.locator( `${ SEL.rows } ${ SEL.noteInput }` ).nth( i ).fill( NOTES[ i ] );
	}

	const filledHeadlines = await rowValues( SEL.headlineInput );
	const filledNotes = await rowValues( SEL.noteInput );
	check(
		'5a Drei Zeilen angelegt und mit Gruppen-Sub-Feldern gefüllt',
		addWorked &&
			JSON.stringify( filledHeadlines ) === JSON.stringify( HEADLINES ) &&
			JSON.stringify( filledNotes ) === JSON.stringify( NOTES ),
		`Zeilen=${ await rowCount() }, headline=${ JSON.stringify( filledHeadlines ) }, note=${ JSON.stringify( filledNotes ) }`,
		`headline=${ JSON.stringify( HEADLINES ) }, note=${ JSON.stringify( NOTES ) }`
	);

	const beforeBlocked = await rowCount();
	await addButton.click();
	await page.waitForTimeout( 400 ); // bewusst warten: ein blockendes Limit hat kein Ereignis
	const afterBlocked = await rowCount();
	check(
		'5b Vierte Zeile wird durch das Limit blockiert',
		afterBlocked === beforeBlocked && afterBlocked === ROW_LIMIT,
		`Zeilen vorher=${ beforeBlocked }, nachher=${ afterBlocked }`,
		`Zeilenzahl bleibt ${ ROW_LIMIT }`
	);

	// --- Schritt 6: Speichern und neu laden ------------------------------
	await page.locator( '#publish, #save-post' ).first().click();
	await page.waitForFunction( () => /[?&]post=\d+/.test( location.href ), null, { timeout: 30000 } );
	await page.waitForLoadState( 'domcontentloaded' );
	const postId = await page.locator( '#post_ID' ).inputValue().catch( () => '' );
	check(
		'6a Speichern liefert Post-ID',
		/^\d+$/.test( postId ) && page.url().includes( `post=${ postId }` ),
		`url=${ page.url() }, post_ID=${ postId }`,
		'Weiterleitung auf post.php?post=<id>&action=edit'
	);

	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( SEL.repeater, { timeout: 20000 } );
	const reloadHeadlines = await rowValues( SEL.headlineInput );
	const reloadNotes = await rowValues( SEL.noteInput );
	check(
		'6b Nach dem Neuladen stehen die drei Zeilen mit den gespeicherten Werten (keine Duplikate)',
		JSON.stringify( reloadHeadlines ) === JSON.stringify( HEADLINES ) &&
			JSON.stringify( reloadNotes ) === JSON.stringify( NOTES ),
		`headlines=${ JSON.stringify( reloadHeadlines ) }, notes=${ JSON.stringify( reloadNotes ) }`,
		`headlines=${ JSON.stringify( HEADLINES ) }, notes=${ JSON.stringify( NOTES ) }`
	);

	// --- Schritt 7: Move-up, speichern, neu laden ------------------------
	const orderBeforeMove = await rowValues( SEL.headlineInput );
	const expectedOrder = [ orderBeforeMove[ 1 ], orderBeforeMove[ 0 ], ...orderBeforeMove.slice( 2 ) ];
	const upButton = page.locator( SEL.rows ).nth( 1 ).locator( SEL.rowUp );
	check(
		'7a Move-up-Button an der zweiten Zeile vorhanden',
		( await upButton.count() ) > 0,
		`kein ${ SEL.rowUp } in Zeile 2`,
		'Verschiebe-Button in jeder Zeile'
	);
	await upButton.click();
	await page.waitForTimeout( 300 );
	const orderAfterMove = await rowValues( SEL.headlineInput );
	check(
		'7b Reihenfolge im DOM nach Move-up geändert',
		JSON.stringify( orderAfterMove ) === JSON.stringify( expectedOrder ),
		`vorher=${ JSON.stringify( orderBeforeMove ) }, nachher=${ JSON.stringify( orderAfterMove ) }`,
		JSON.stringify( expectedOrder )
	);

	const notesAfterMove = await rowValues( SEL.noteInput );
	await page.locator( '#publish, #save-post' ).first().click();
	await page.waitForFunction( ( expected ) => /[?&]post=\d+/.test( location.href ) && document.readyState !== 'loading', null, {
		timeout: 30000,
	} );
	await page.waitForLoadState( 'domcontentloaded' );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( SEL.repeater, { timeout: 20000 } );
	const persistedOrder = await rowValues( SEL.headlineInput );
	const persistedNotes = await rowValues( SEL.noteInput );
	check(
		'7c Reihenfolge bleibt nach dem Speichern erhalten',
		JSON.stringify( persistedOrder ) === JSON.stringify( expectedOrder ) &&
			JSON.stringify( persistedNotes ) === JSON.stringify( notesAfterMove ),
		`headlines=${ JSON.stringify( persistedOrder ) }, notes=${ JSON.stringify( persistedNotes ) }`,
		`headlines=${ JSON.stringify( expectedOrder ) }, notes=${ JSON.stringify( notesAfterMove ) }`
	);

	// --- Schritt 8: Lese-API ---------------------------------------------
	// Erwartete Reihenfolge = was der Browser nach Move-up + Save anzeigt.
	const expectedNotes = notesAfterMove;
	const readFile = putPhp( buildReadPhp( expectedOrder, expectedNotes ), '/tmp/e2e-group-read.php' );
	let readOut;
	let readExitedOk = true;
	try {
		readOut = clean( wpCli( readFile ) );
	} catch ( e ) {
		readExitedOk = false;
		readOut = clean( `${ e.stdout || '' }${ e.stderr || '' }` ) || e.message;
		log(
			`     Lese-API Exit != 0: stdout=${ JSON.stringify( ( e.stdout || '' ).slice( 0, 600 ) ) } stderr=${ JSON.stringify(
				clean( e.stderr || '' ).slice( 0, 400 )
			) }`
		);
	}
	const readLine = readOut.split( '\n' ).find( ( l ) => l.startsWith( 'READ-RESULT' ) );
	let readData = null;
	if ( readLine ) {
		try {
			readData = JSON.parse( readLine.slice( 'READ-RESULT'.length ).trim() );
		} catch ( e ) {
			readData = null;
		}
	}
	apiRead = readData ? readData.api : null;
	const readFails = ( readData && readData.checks ? readData.checks : [] ).filter( ( c ) => ! c.ok );
	const apiOk =
		!! readData &&
		0 === readFails.length &&
		JSON.stringify( readData.api ? readData.api.loop_headlines : null ) === JSON.stringify( expectedOrder );
	check(
		'8 Lese-API liefert die Gruppen-Sub-Felder in gespeicherter Reihenfolge',
		readExitedOk && apiOk,
		`exitOk=${ readExitedOk }, fehlgeschlagene Checks=${ JSON.stringify( readFails ) }, loop=${ JSON.stringify(
			readData && readData.api ? readData.api.loop_headlines : null
		) }, erwartet=${ JSON.stringify( expectedOrder ) }`,
		`pods_field() und Loop-API liefern ${ JSON.stringify( expectedOrder ) } in gleicher Reihenfolge`
	);

	log(
		`     pods_field() = ${ JSON.stringify( readData && readData.api ? readData.api.pods_field : null ) }`
	);
	log(
		`     loop        = ${ JSON.stringify( readData && readData.api ? readData.api.loop_headlines : null ) } / ${ JSON.stringify(
			readData && readData.api ? readData.api.loop_notes : null
		) }`
	);
	log(
		`     group_sub_fields = ${ JSON.stringify( readData && readData.api ? readData.api.group_sub_fields : null ) }`
	);

	// --- Schritt 9: Screenshot -------------------------------------------
	const shotPath = await shot( 'gruppe-nach-neuladen' );
	results.push( { step: '9 Screenshot nach dem Neuladen', ok: true } );
	log( `PASS 9 Screenshot nach dem Neuladen (${ shotPath })` );

	if ( consoleErrors.length ) {
		notes.push( `${ consoleErrors.length } Browser-Fehlermeldung(en): ${ consoleErrors.slice( 0, 3 ).join( ' | ' ) }` );
	}

	return { postId, shotPath, order: expectedOrder };
}

/* ------------------------------------------------------------------ Main */

let exitCode = 0;
let failure = null;
try {
	await run();
} catch ( e ) {
	failure = e;
	exitCode = 1;
	const at = await shot( 'gruppe-fehlschlag' ).catch( () => null );
	if ( e instanceof StepFailure ) {
		log( `\nAbbruch bei: ${ e.step }` );
	} else {
		log( `\nUNERWARTETER FEHLER: ${ e && e.message ? e.message : e }` );
		results.push( { step: 'unerwarteter Fehler', ok: false, observed: String( e && e.message ? e.message : e ) } );
	}
	if ( at ) {
		log( `Screenshot: ${ at }` );
	}
} finally {
	// Pod, Beitrag und Gruppe immer abräumen, auch nach einem roten Schritt.
	try {
		putPhp( CLEANUP_PHP, '/tmp/e2e-group-cleanup.php' );
		const cleanupOut = clean( wpCli( '/tmp/e2e-group-cleanup.php' ) );
		const line = cleanupOut.split( '\n' ).find( ( l ) => l.startsWith( 'CLEANUP-RESULT' ) );
		let cleanupData = null;
		if ( line ) {
			try {
				cleanupData = JSON.parse( line.slice( 'CLEANUP-RESULT'.length ).trim() );
			} catch ( e ) {
				cleanupData = null;
			}
		}
		const cleanOk =
			cleanupData && ! cleanupData.pod_still_present && ! cleanupData.group_still_present;
		if ( failure ) {
			log( `Aufgeraeumt: ${ line || cleanupOut.slice( 0, 200 ) }` );
		} else if ( ! cleanOk ) {
			log( `FAIL Aufraeumen: ${ line || cleanupOut.slice( 0, 200 ) } / erwartet: Pod und Gruppe entfernt` );
			results.push( { step: 'Aufraeumen von Pod und Feldgruppe', ok: false } );
			exitCode = 1;
		} else {
			log( `PASS Aufraeumen: Pod ${ POD_NAME }, Beitrag und Feldgruppe ${ GROUP_SLUG } entfernt` );
		}
	} catch ( e ) {
		log( `FAIL Aufraeumen: ${ e.message } / erwartet: Pod und Gruppe entfernt` );
		results.push( { step: 'Aufraeumen von Pod und Feldgruppe', ok: false } );
		exitCode = 1;
	}

	if ( browser ) {
		await browser.close().catch( () => {} );
	}

	const passed = results.filter( ( r ) => r.ok ).length;
	log( `\n${ passed }/${ results.length } Schritte PASS` );

	mkdirSync( SHOT_DIR, { recursive: true } );
	writeFileSync(
		resolve( SHOT_DIR, 'ergebnis-gruppe.json' ),
		JSON.stringify(
			{
				baseUrl: BASE_URL,
				group: GROUP_SLUG,
				pod: POD_NAME,
				results,
				failure: failure ? String( failure.message ) : null,
				api_read: apiRead,
				notes,
			},
			null,
			2
		)
	);

	if ( exitCode !== 0 ) {
		process.exitCode = 1;
	}
}
