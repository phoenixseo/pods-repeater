#!/usr/bin/env node
/**
 * E2E-Abnahme der Repeater-Admin-UI in einem echten Browser.
 *
 * Der PHPUnit-Lauf deckt nur die Serverseite ab: input() rendert HTML, die
 * Tests zählen Row-Container. Ob PHP und JS denselben CSS-Vertrag sprechen —
 * Zeile hinzufügen, Limit, Verschieben, Entfernen, Speichern, Wiederladen —
 * sieht erst ein Browser. Genau das prüft dieses Skript.
 *
 * Ablauf (jeder Schritt gibt PASS/FAIL aus, Exit ≠ 0 bei Abweichung):
 *   1  Login über wp-login.php
 *   2  Pod vorbereiten: e2e-ui (post_type) + Feld items, Typ repeater, Limit 3
 *      mit zwei Sub-Feldern (title/text, body/paragraph) — per `wp eval-file`,
 *      die Pod-Konfiguration ist nicht Gegenstand des Tests
 *   3  Post-Editor öffnen, Feld rendern lassen (CSS-Vertrag Feld ↔ Container)
 *   4  Drei Zeilen anlegen und füllen
 *   5  Vierte Zeile versuchen: Limit blockt, Zeilenzahl bleibt 3
 *   6  Zweite Zeile nach oben verschieben: Reihenfolge im DOM wechselt
 *   7  Eine Zeile löschen: zwei bleiben übrig
 *   8  Speichern, neu laden: zwei Zeilen mit den richtigen Werten sind wieder da
 *   9  Screenshot nach dist/e2e/
 *
 * Aufruf:
 *   node scripts/e2e-admin-ui.mjs
 *   BASE_URL=http://… WP_CLI_CONTAINER=… node scripts/e2e-admin-ui.mjs
 *
 * Voraussetzungen: laufende wp-env-Instanz (Browser unter BASE_URL erreichbar),
 * laufender CLI-Container für `wp eval-file`, Playwright samt Chromium.
 * Das Skript legt den Test-Pod selbst an und räumt ihn im finally wieder ab.
 */

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { chromium } = require( 'playwright' );

const HERE = dirname( fileURLToPath( import.meta.url ) );
const ROOT = resolve( HERE, '..' );

const BASE_URL = ( process.env.BASE_URL || 'http://100.67.225.184:8899' ).replace( /\/+$/, '' );
const WP_USER = process.env.WP_USER || 'admin';
const WP_PASS = process.env.WP_PASS || 'password';
const CLI_CONTAINER = process.env.WP_CLI_CONTAINER || 'wp-env-pods-repeater-54d58dae-cli-1';
const SHOT_DIR = resolve( ROOT, 'dist/e2e' );
const POD_NAME = 'e2e-ui';
const FIELD_NAME = 'items';
const ROW_LIMIT = 3;

// Selektoren aus dem gerenderten HTML abgeleitet (S14-Befund: nicht raten).
const SEL = {
	repeater: '.pods-repeater',
	rows: '.pods-repeater .pods-repeater-rows .pods-repeater-row',
	add: '.pods-repeater .pods-repeater-add',
	rowRemove: '.pods-repeater-remove',
	rowUp: '.pods-repeater-move-up',
	rowDown: '.pods-repeater-move-down',
	titleInput: 'input[data-sub="title"]',
	bodyInput: 'textarea[data-sub="body"]',
};

/* ------------------------------------------------------------------ Hilfen */

const results = [];
let browser = null;
let page = null;

const log = ( line ) => process.stdout.write( line + '\n' );

/**
 * Protokolliert einen Schritt. Wirft bei Fehlschlag, damit try/finally greift
 * und der Pod auch im Fehlerfall abgeräumt wird.
 *
 * @param {string} step     Schrittbezeichnung.
 * @param {boolean} ok      Ergebnis.
 * @param {string} observed Beobachtung bei Fehlschlag.
 * @param {string} expected Erwartung bei Fehlschlag.
 */
function check( step, ok, observed = '', expected = '' ) {
	results.push( { step, ok } );
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

/**
 * Führt ein PHP-Snippet im CLI-Container aus (wp eval-file oder wp eval).
 *
 * @param {string} file Pfad im Container.
 * @return {string} stdout.
 */
function wpCli( file ) {
	return execFileSync(
		'docker',
		[ 'exec', CLI_CONTAINER, 'wp', 'eval-file', file ],
		{ encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }
	);
}

function wpEval( php ) {
	return execFileSync(
		'docker',
		[ 'exec', CLI_CONTAINER, 'wp', 'eval', php ],
		{ encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }
	);
}

/** Schreibt ein PHP-Snippet in den Container (base64, damit kein Quoting bricht). */
function putPhp( php, target ) {
	const b64 = Buffer.from( php, 'utf8' ).toString( 'base64' );
	execFileSync(
		'docker',
		[ 'exec', CLI_CONTAINER, 'sh', '-c', `echo ${ b64 } | base64 -d > ${ target }` ],
		{ encoding: 'utf8' }
	);
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

const rowValues = ( sub ) =>
	page.locator( `${ SEL.rows } ${ sub }` ).evaluateAll( ( els ) => els.map( ( e ) => e.value ) );

const rowCount = () => page.locator( SEL.rows ).count();

/**
 * Klickt ein Element und wartet auf die erwartete Zeilenzahl.
 *
 * Kein Timeout als Fehlersignal: bleibt die Zahl aus, ist das ein Befund
 * (JS-Vertrag gebrochen) und wird als FAIL gemeldet, nicht als Absturz.
 *
 * @param {import('playwright').Locator} locator Ziel-Element.
 * @param {number}                        expected Erwartete Zeilenzahl danach.
 * @param {number}                        timeoutMs Wartezeit in ms.
 * @return {Promise<boolean>} true, wenn die Zahl erreicht wurde.
 */
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

const SETUP_PHP = `<?php
/**
 * Test-Pod e2e-ui + Repeater-Feld items (vom E2E-Skript erzeugt).
 */
$failures = 0;
function e2e_setup_check( $label, $cond, $detail = '' ) {
	global $failures;
	if ( $cond ) {
		echo "PASS {$label}\\n";
	} else {
		$failures++;
		echo "FAIL {$label}: " . ( '' !== $detail ? $detail : 'Zusage nicht erfuellt' ) . "\\n";
	}
}

$existing = pods_api()->load_pod( array( 'name' => '${ POD_NAME }' ) );
if ( ! empty( $existing ) ) {
	pods_api()->delete_pod( array( 'id' => (int) $existing['id'], 'delete_all' => true ) );
}

$sub_fields = array(
	array( 'name' => 'title', 'label' => 'Titel', 'type' => 'text' ),
	array( 'name' => 'body', 'label' => 'Text', 'type' => 'paragraph' ),
);

$pod_id = pods_api()->save_pod(
	array(
		'name'                => '${ POD_NAME }',
		'type'                => 'post_type',
		'storage'             => 'meta',
		'label'               => 'E2E UI Test',
		'public'              => 1,
		'publicly_queryable'  => 0,
		'exclude_from_search' => 1,
		'show_in_rest'        => 0,
		'show_in_menu'        => 1,
		'menu_position'       => 60,
		// Pods 3.3.9.1 liest keine supports-Liste, sondern supports_*-Flags
		// (PodsInit.php:1181-1192). Ohne supports_title fehlt dem CPT das
		// Titel-Feld komplett — der Editor rendert dann ohne #title.
		'supports_title'      => 1,
		'supports_editor'     => 1,
	)
);
e2e_setup_check( 'Pod ${ POD_NAME } angelegt', ! is_wp_error( $pod_id ) && $pod_id > 0, is_wp_error( $pod_id ) ? $pod_id->get_error_message() : '' );
if ( is_wp_error( $pod_id ) || $pod_id <= 0 ) { echo "SETUP-ABORT\\n"; exit( 1 ); }

$field_id = pods_api()->save_field(
	array(
		'pod_id'              => (int) $pod_id,
		'pod'                 => '${ POD_NAME }',
		'name'                => '${ FIELD_NAME }',
		'label'               => 'Items',
		'type'                => 'repeater',
		'disable_dfv'         => '1',
		'repeater_limit'      => ${ ROW_LIMIT },
		'repeater_sub_fields' => wp_json_encode( $sub_fields ),
	)
);
e2e_setup_check( 'Feld ${ FIELD_NAME } angelegt', ! is_wp_error( $field_id ) && $field_id > 0, is_wp_error( $field_id ) ? $field_id->get_error_message() : '' );
if ( is_wp_error( $field_id ) || $field_id <= 0 ) { echo "SETUP-ABORT\\n"; exit( 1 ); }

pods_api()->cache_flush_pods( array( 'name' => '${ POD_NAME }' ) );
$pod  = pods_api()->load_pod( array( 'name' => '${ POD_NAME }' ) );
$args = ( is_object( $pod['fields']['${ FIELD_NAME }'] ) && method_exists( $pod['fields']['${ FIELD_NAME }'], 'get_args' ) )
	? $pod['fields']['${ FIELD_NAME }']->get_args()
	: (array) $pod['fields']['${ FIELD_NAME }'];

e2e_setup_check( 'Feldtyp nachgeladen ist repeater', isset( $args['type'] ) && 'repeater' === $args['type'], 'type=' . wp_json_encode( isset( $args['type'] ) ? $args['type'] : null ) );
e2e_setup_check( 'Limit ist ${ ROW_LIMIT }', isset( $args['repeater_limit'] ) && ${ ROW_LIMIT } === (int) $args['repeater_limit'], 'limit=' . wp_json_encode( isset( $args['repeater_limit'] ) ? $args['repeater_limit'] : null ) );
e2e_setup_check(
	'Zwei Sub-Felder gespeichert',
	isset( $args['repeater_sub_fields'] ) && is_string( $args['repeater_sub_fields'] ) && 2 === count( (array) json_decode( $args['repeater_sub_fields'], true ) ),
	'sub_fields=' . wp_json_encode( isset( $args['repeater_sub_fields'] ) ? $args['repeater_sub_fields'] : null )
);

echo 'SETUP-RESULT ' . wp_json_encode( array( 'pod_id' => (int) $pod_id, 'field_id' => (int) $field_id ) ) . "\\n";
exit( 0 === $failures ? 0 : 1 );
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
$still = ! empty( pods_api()->load_pod( array( 'name' => '${ POD_NAME }' ) ) );
echo 'CLEANUP-RESULT ' . wp_json_encode( array( 'pod_deleted' => $deleted, 'pod_still_present' => $still, 'removed_posts' => $removed ) ) . "\\n";
exit( $still ? 1 : 0 );
`;

/* --------------------------------------------------------------- Ablauf */

async function run() {
	log( `E2E Repeater-Admin-UI gegen ${ BASE_URL }` );

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
	const loggedIn = await page.locator( '#wpadminbar, #adminmenu' ).count() > 0;
	check(
		'1 Login und Weiterleitung nach /wp-admin/',
		loggedIn && page.url().includes( '/wp-admin/' ),
		`url=${ page.url() }, adminbar=${ loggedIn }`,
		'URL enthält /wp-admin/ und Admin-Bar vorhanden'
	);

	// --- Schritt 2: Pod vorbereiten --------------------------------------
	const setupFile = putPhp( SETUP_PHP, '/tmp/e2e-admin-ui-setup.php' );
	let setupOut;
	try {
		setupOut = clean( wpCli( setupFile ) );
	} catch ( e ) {
		const text = clean( `${ e.stdout || '' }${ e.stderr || '' }` ) || e.message;
		check( '2 Pod und Repeater-Feld vorbereitet', false, text.slice( 0, 400 ), 'wp eval-file endet mit Exit 0' );
	}
	const setupFails = setupOut.split( '\n' ).filter( ( l ) => l.startsWith( 'FAIL' ) );
	check(
		'2 Pod und Repeater-Feld vorbereitet (Limit ${ ROW_LIMIT }, zwei Sub-Felder)'.replace( '${ ROW_LIMIT }', String( ROW_LIMIT ) ),
		0 === setupFails.length && setupOut.includes( 'SETUP-RESULT' ),
		setupFails.join( ' | ' ) || setupOut.slice( 0, 400 ),
		'alle Vorbereitungs-Zusagen erfüllt'
	);

	// --- Schritt 3: Editor und Feld-Rendering ----------------------------
	await page.goto( `${ BASE_URL }/wp-admin/post-new.php?post_type=${ POD_NAME }`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( SEL.repeater, { timeout: 20000 } );

	const fieldCount = await page.locator( SEL.repeater ).count();
	const limitAttr = await page.locator( SEL.repeater ).first().getAttribute( 'data-limit' );
	const subFieldLabels = await page
		.locator( `${ SEL.rows } .pods-repeater-subfield-label` )
		.evaluateAll( ( els ) => Array.from( new Set( els.map( ( e ) => e.textContent.trim() ) ) ) );
	check(
		'3 Feld rendert als Repeater-Container mit data-limit',
		1 === fieldCount && Number( limitAttr ) === ROW_LIMIT && subFieldLabels.length === 2,
		`Container=${ fieldCount }, data-limit=${ limitAttr }, Sub-Feld-Labels=${ JSON.stringify( subFieldLabels ) }`,
		`genau 1 Container, data-limit=${ ROW_LIMIT }, 2 Sub-Feld-Labels (PHP-Vertrag ↔ JS)`
	);

	const addButton = page.locator( SEL.add ).first();
	const hasAdd = ( await page.locator( SEL.add ).count() ) > 0;
	check(
		'3b Add-Button im gerenderten Feld vorhanden (JS-Selektor greift)',
		hasAdd,
		'kein Element für ' + SEL.add,
		'Add-Button vom JS selektierbar'
	);

	// Post-Titel setzen, damit der Editor einen vollständigen Post speichert.
	await page.locator( '#title' ).fill( `E2E Abnahme ${ Date.now() }` );

	// --- Schritt 4: Drei Zeilen anlegen ----------------------------------
	const startRows = await rowCount();
	const wanted = [ 'Zeile-1', 'Zeile-2', 'Zeile-3' ];
	await page.locator( `${ SEL.rows } ${ SEL.titleInput }` ).first().fill( wanted[ 0 ] );
	let addWorked = true;
	for ( let i = 1; i < wanted.length; i++ ) {
		if ( ! ( await clickExpectingRows( addButton, startRows + i ) ) ) {
			addWorked = false;
			break;
		}
		await page.locator( `${ SEL.rows } ${ SEL.titleInput }` ).nth( i ).fill( wanted[ i ] );
	}
	check(
		'4a Add-Button legt eine Zeile an (JS-Handler greift)',
		addWorked,
		`nach ${ startRows + 1 } Klicks Zeilen=${ await rowCount() } (Start ${ startRows })`,
		`Add-Button erhöht die Zeilenzahl`
	);

	// body der ersten Zeile füllen: prüft den textarea-Pfad mit.
	await page.locator( `${ SEL.rows } ${ SEL.bodyInput }` ).first().fill( 'Notiz zur ersten Zeile' );

	const afterAdd = await rowValues( SEL.titleInput );
	check(
		'4 Drei Zeilen angelegt und gefüllt',
		afterAdd.length === ROW_LIMIT && JSON.stringify( afterAdd ) === JSON.stringify( wanted ),
		`Zeilen vorher=${ startRows }, Titel=${ JSON.stringify( afterAdd ) }`,
		`${ ROW_LIMIT } Zeilen mit Titeln ${ JSON.stringify( wanted ) }`
	);

	// --- Schritt 5: Limit blockt die vierte Zeile ------------------------
	const beforeBlocked = await rowCount();
	await addButton.click();
	await page.waitForTimeout( 400 ); // bewusst warten: ein blockendes Limit hat kein Ereignis
	const afterBlocked = await rowCount();
	check(
		'5 Vierte Zeile wird durch Limit blockiert',
		afterBlocked === beforeBlocked && afterBlocked === ROW_LIMIT,
		`Zeilen vorher=${ beforeBlocked }, nachher=${ afterBlocked }`,
		`Zeilenzahl bleibt ${ ROW_LIMIT }`
	);

	// --- Schritt 6: Zweite Zeile nach oben -------------------------------
	const beforeMove = await rowValues( SEL.titleInput );
	const upButton = page.locator( SEL.rows ).nth( 1 ).locator( SEL.rowUp );
	check(
		'6a Move-up-Button an der zweiten Zeile vorhanden',
		( await upButton.count() ) > 0,
		'kein ' + SEL.rowUp + ' in Zeile 2',
		'Verschiebe-Button in jeder Zeile verfügbar'
	);
	await upButton.click();
	await page.waitForTimeout( 300 ); // Move ist synchron; kurze Ruhe reicht
	const afterMove = await rowValues( SEL.titleInput );
	const expectedOrder = [ beforeMove[ 1 ], beforeMove[ 0 ], ...beforeMove.slice( 2 ) ];
	check(
		'6 Reihenfolge nach Move-up geändert',
		JSON.stringify( afterMove ) === JSON.stringify( expectedOrder ),
		`vorher=${ JSON.stringify( beforeMove ) }, nachher=${ JSON.stringify( afterMove ) }`,
		JSON.stringify( expectedOrder )
	);

	// --- Schritt 7: Eine Zeile löschen -----------------------------------
	const beforeRemove = await rowValues( SEL.titleInput );
	const victimIndex = 1;
	const removedTitle = beforeRemove[ victimIndex ];
	const removeWorked = await clickExpectingRows(
		page.locator( SEL.rows ).nth( victimIndex ).locator( SEL.rowRemove ),
		beforeRemove.length - 1
	);
	const afterRemove = await rowValues( SEL.titleInput );
	const expectedRemaining = beforeRemove.filter( ( _, i ) => i !== victimIndex );
	check(
		'7 Zeile entfernt, Reihenfolge der restlichen bleibt',
		removeWorked &&
			afterRemove.length === 2 &&
			JSON.stringify( afterRemove ) === JSON.stringify( expectedRemaining ) &&
			! afterRemove.includes( removedTitle ),
		`vorher=${ JSON.stringify( beforeRemove ) }, nachher=${ JSON.stringify( afterRemove ) }`,
		`2 Zeilen ${ JSON.stringify( expectedRemaining ) }`
	);

	// --- Schritt 8: Speichern und neu laden ------------------------------
	const titlesBeforeSave = await rowValues( SEL.titleInput );
	const bodiesBeforeSave = await rowValues( SEL.bodyInput );

	await page.locator( '#publish, #save-post' ).first().click();
	await page.waitForFunction( () => /[?&]post=\d+/.test( location.href ), null, { timeout: 30000 } );
	await page.waitForLoadState( 'domcontentloaded' );
	const postId = await page.locator( '#post_ID' ).inputValue().catch( () => '' );
	check(
		'8a Speichern liefert Post-ID und Erfolgsmeldung',
		/^\d+$/.test( postId ) && page.url().includes( `post=${ postId }` ),
		`url=${ page.url() }, post_ID=${ postId }`,
		'Weiterleitung auf post.php?post=<id>&action=edit'
	);

	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( SEL.repeater, { timeout: 20000 } );
	const titlesAfterReload = await rowValues( SEL.titleInput );
	const bodiesAfterReload = await rowValues( SEL.bodyInput );
	check(
		'8 Nach dem Neuladen stehen die zwei Zeilen mit den richtigen Werten',
		JSON.stringify( titlesAfterReload ) === JSON.stringify( titlesBeforeSave ) &&
			JSON.stringify( bodiesAfterReload ) === JSON.stringify( bodiesBeforeSave ),
		`Titel=${ JSON.stringify( titlesAfterReload ) }, Texte=${ JSON.stringify( bodiesAfterReload ) }`,
		`Titel=${ JSON.stringify( titlesBeforeSave ) }, Texte=${ JSON.stringify( bodiesBeforeSave ) }`
	);

	// --- Schritt 9: Screenshot -------------------------------------------
	const shotPath = await shot( 'nach-neuladen' );
	results.push( { step: '9 Screenshot nach dem Neuladen', ok: true } );
	log( `PASS 9 Screenshot nach dem Neuladen (${ shotPath })` );

	if ( consoleErrors.length ) {
		log( `HINWEIS: ${ consoleErrors.length } Browser-Fehlermeldung(en) waehrend des Laufs:` );
		consoleErrors.slice( 0, 5 ).forEach( ( e ) => log( `  - ${ e }` ) );
	}

	return { postId, shotPath, titlesAfterReload };
}

/* ------------------------------------------------------------------ Main */

let exitCode = 0;
let failure = null;
try {
	await run();
} catch ( e ) {
	failure = e;
	exitCode = 1;
	const at = await shot( 'fehlschlag' ).catch( () => null );
	if ( e instanceof StepFailure ) {
		log( `\nAbbruch bei: ${ e.step }` );
	} else {
		log( `\nUNERWARTETER FEHLER: ${ e && e.message ? e.message : e }` );
		results.push( { step: 'unerwarteter Fehler', ok: false } );
	}
	if ( at ) {
		log( `Screenshot: ${ at }` );
	}
} finally {
	// Pod immer abräumen, auch nach einem roten Schritt.
	try {
		putPhp( CLEANUP_PHP, '/tmp/e2e-admin-ui-cleanup.php' );
		const cleanupOut = clean( wpCli( '/tmp/e2e-admin-ui-cleanup.php' ) );
		const line = cleanupOut.split( '\n' ).find( ( l ) => l.startsWith( 'CLEANUP-RESULT' ) );
		if ( failure ) {
			log( `Aufgeraeumt: ${ line || cleanupOut.slice( 0, 200 ) }` );
		} else if ( ! line || ! line.includes( '"pod_still_present":false' ) ) {
			log( `FAIL Aufraeumen: ${ line || cleanupOut.slice( 0, 200 ) } / erwartet: pod_still_present=false` );
			results.push( { step: 'Aufraeumen des Test-Pods', ok: false } );
			exitCode = 1;
		} else {
			log( `PASS Aufraeumen: Test-Pod ${ POD_NAME } entfernt` );
		}
	} catch ( e ) {
		log( `FAIL Aufraeumen: ${ e.message } / erwartet: Test-Pod entfernt` );
		results.push( { step: 'Aufraeumen des Test-Pods', ok: false } );
		exitCode = 1;
	}

	if ( browser ) {
		await browser.close().catch( () => {} );
	}

	const passed = results.filter( ( r ) => r.ok ).length;
	log( `\n${ passed }/${ results.length } Schritte PASS` );
	writeFileSync(
		resolve( SHOT_DIR, 'ergebnis.json' ),
		JSON.stringify( { baseUrl: BASE_URL, results, failure: failure ? String( failure.message ) : null }, null, 2 )
	);

	if ( exitCode !== 0 ) {
		process.exitCode = 1;
	}
}
