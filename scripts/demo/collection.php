<?php
/**
 * Demo-Datensatz: Schallplattensammlung als Pod mit zwei wiederholbaren
 * Feldgruppen (Trackliste, Pressungen).
 *
 * Aufruf (im wp-env-CLI-Container):
 *   wp eval-file /var/www/html/wp-content/plugins/pods-repeater/scripts/demo/collection.php --allow-root
 *
 * Legt an: Pod `platte` (CPT, meta-based), Feld `tracks` (Feldgruppe
 * `platte-track`), Feld `pressungen` (Feldgruppe `platte-pressung`), vier
 * Beispielplatten inkl. Cover-Bildern (per GD erzeugte Platzhalter).
 *
 * Idempotent: ein vorhandener Pod `platte` wird vorher gelöscht. Das Skript
 * schreibt ausschließlich in die Demo-Instanz, nie in Produktivdaten.
 *
 * Beispieldaten sind illustrativ: Künstler, Alben und Tracklisten sind real,
 * Katalognummern und Erscheinungsjahre der Pressungen sind es nicht.
 *
 * @package PodsRepeater
 */

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$out = array(
	'groups' => array(),
	'pod'    => null,
	'items'  => array(),
	'errors' => array(),
);

/* ---------------------------------------------------------------- Gruppen */

$out['groups']['track'] = pods_repeater_save_group(
	array(
		'slug'   => 'platte-track',
		'label'  => 'Trackliste',
		'fields' => array(
			array(
				'name'  => 'position',
				'label' => 'Nr.',
				'type'  => 'number',
			),
			array(
				'name'     => 'titel',
				'label'    => 'Titel',
				'type'     => 'text',
				'required' => true,
			),
			array(
				'name'              => 'seite',
				'label'             => 'Seite',
				'type'              => 'pick',
				'data'              => array( 'A', 'B', 'C', 'D' ),
				'pick_format_type'  => 'single',
			),
			array(
				'name'     => 'laenge',
				'label'    => 'Länge',
				'type'     => 'text',
				'placeholder' => '3:43',
			),
			array(
				'name'             => 'stil',
				'label'            => 'Stil',
				'type'             => 'pick',
				'data'             => array( 'Rock', 'Jazz', 'Elektronik', 'Soul', 'Blues', 'Pop' ),
				'pick_format_type' => 'multi',
			),
			array(
				'name'  => 'text',
				'label' => 'Notiz / Liedtext',
				'type'  => 'paragraph',
			),
		),
	)
);

$out['groups']['pressung'] = pods_repeater_save_group(
	array(
		'slug'   => 'platte-pressung',
		'label'  => 'Pressungen',
		'fields' => array(
			array(
				'name'  => 'jahr',
				'label' => 'Jahr',
				'type'  => 'number',
			),
			array(
				'name'  => 'label',
				'label' => 'Label',
				'type'  => 'text',
			),
			array(
				'name'  => 'katalognr',
				'label' => 'Katalognr.',
				'type'  => 'text',
			),
			array(
				'name'  => 'land',
				'label' => 'Land',
				'type'  => 'text',
			),
			array(
				'name'  => 'notiz',
				'label' => 'Zustand / Besonderheit',
				'type'  => 'paragraph',
			),
		),
	)
);

foreach ( $out['groups'] as $key => $slug ) {
	if ( is_wp_error( $slug ) ) {
		$out['errors'][] = 'group ' . $key . ': ' . $slug->get_error_message();
		$out['groups'][ $key ] = null;
	}
}

/* -------------------------------------------------------------------- Pod */

$existing = pods_api()->load_pod( array( 'name' => 'platte' ) );
if ( ! empty( $existing ) ) {
	pods_api()->delete_pod(
		array(
			'id'        => (int) $existing['id'],
			'delete_all' => true,
		)
	);
}

$pod_id = pods_api()->save_pod(
	array(
		'name'                => 'platte',
		'type'                => 'post_type',
		'storage'             => 'meta',
		'label'               => 'Platten',
		'singular_label'      => 'Platte',
		'public'              => 1,
		'publicly_queryable'  => 1,
		'exclude_from_search' => 0,
		'show_in_rest'        => 1,
		'has_archive'         => false,
		'show_in_menu'        => 1,
		'menu_position'       => 58,
		'supports_title'      => 1,
		'supports_editor'     => 1,
	)
);

if ( is_wp_error( $pod_id ) || $pod_id <= 0 ) {
	$out['errors'][] = 'pod: ' . ( is_wp_error( $pod_id ) ? $pod_id->get_error_message() : 'unbekannt' );
	echo 'DEMO ' . wp_json_encode( $out ) . "\n";
	exit( 1 );
}

$out['pod'] = (int) $pod_id;

/* ----------------------------------------------------------------- Felder */

$pod_fields = array(
	array(
		'name'  => 'artist',
		'label' => 'Künstler',
		'type'  => 'text',
	),
	array(
		'name'  => 'jahr',
		'label' => 'Erscheinungsjahr',
		'type'  => 'number',
	),
	array(
		'name'  => 'label',
		'label' => 'Label (Original)',
		'type'  => 'text',
	),
	array(
		'name'             => 'format',
		'label'            => 'Format',
		'type'             => 'pick',
		'data'             => array( 'LP', '2LP', '10"', '7"' ),
		'pick_format_type' => 'single',
	),
	array(
		'name'             => 'genre',
		'label'            => 'Genre',
		'type'             => 'pick',
		'data'             => array( 'Rock', 'Jazz', 'Elektronik', 'Soul', 'Blues', 'Pop' ),
		'pick_format_type' => 'multi',
	),
	array(
		'name'             => 'zustand',
		'label'            => 'Zustand',
		'type'             => 'pick',
		'data'             => array( 'M', 'NM', 'VG+', 'VG', 'G' ),
		'pick_format_type' => 'single',
	),
	array(
		'name'           => 'cover',
		'label'          => 'Cover',
		'type'           => 'file',
		'file_format_type' => 'single',
	),
	array(
		'name'           => 'tracks',
		'label'          => 'Trackliste',
		'type'           => 'repeater',
		'disable_dfv'    => '1',
		'repeater_group' => 'platte-track',
	),
	array(
		'name'           => 'pressungen',
		'label'          => 'Pressungen',
		'type'           => 'repeater',
		'disable_dfv'    => '1',
		'repeater_group' => 'platte-pressung',
	),
);

foreach ( $pod_fields as $field ) {
	$field['pod_id'] = (int) $pod_id;
	$field['pod']    = 'platte';

	$field_id = pods_api()->save_field( $field );

	if ( is_wp_error( $field_id ) || $field_id <= 0 ) {
		$out['errors'][] = 'field ' . $field['name'] . ': ' . ( is_wp_error( $field_id ) ? $field_id->get_error_message() : 'unbekannt' );
	}
}

pods_api()->cache_flush_pods( array( 'name' => 'platte', 'type' => 'post_type' ) );

/* ---------------------------------------------------------------- Helfer */

if ( ! function_exists( 'pods_repeater_demo_text' ) ) {
	/**
	 * Text in mehrfacher Größe rendern: imagestring kennt nur kleine Fonts,
	 * deshalb klein zeichnen und hochskalieren.
	 *
	 * @param resource $dst   Zielbild.
	 * @param string   $text  Text.
	 * @param int      $x     Ziel-X.
	 * @param int      $y     Ziel-Y.
	 * @param int      $color Farbe.
	 * @param int      $scale Faktor.
	 * @return void
	 */
	function pods_repeater_demo_text( $dst, $text, $x, $y, $color, $scale = 3 ) {
		$w = imagefontwidth( 5 ) * strlen( $text ) + 4;
		$h = imagefontheight( 5 ) + 4;

		$tmp = imagecreatetruecolor( $w, $h );
		imagefill( $tmp, 0, 0, imagecolorallocate( $tmp, 0, 0, 0 ) );
		imagecolortransparent( $tmp, imagecolorallocate( $tmp, 0, 0, 0 ) );
		imagestring( $tmp, 5, 2, 2, $text, $color );
		imagecopyresized( $dst, $tmp, $x, $y, 0, 0, $w * $scale, $h * $scale, $w, $h );
		imagedestroy( $tmp );
	}
}

/* ----------------------------------------------------------------- Cover */

/**
 * Erzeugt ein quadratisches Platzhalter-Cover (unser eigenes Motiv, keine
 * fremden Bildrechte) und legt es in die Mediathek.
 *
 * @param string $titel      Erste Zeile.
 * @param string $untertitel Zweite Zeile.
 * @param array  $rgb        Hintergrundfarbe.
 * @return int Attachment-ID oder 0.
 */
function pods_repeater_demo_cover( $titel, $untertitel, $rgb ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return 0;
	}

	$size = 700;
	$img  = imagecreatetruecolor( $size, $size );

	imagefill( $img, 0, 0, imagecolorallocate( $img, $rgb[0], $rgb[1], $rgb[2] ) );

	// Aufgehellter Block als Gestaltungselement.
	$accent = imagecolorallocate( $img, min( 255, $rgb[0] + 45 ), min( 255, $rgb[1] + 45 ), min( 255, $rgb[2] + 45 ) );
	imagefilledrectangle( $img, 60, 430, $size - 60, 462, $accent );

	$white = imagecolorallocate( $img, 245, 243, 238 );
	$muted = imagecolorallocate( $img, 190, 186, 178 );

	pods_repeater_demo_text( $img, $titel, 60, 300, $white, 3 );
	pods_repeater_demo_text( $img, $untertitel, 60, 380, $muted, 2 );

	$path = wp_tempnam( 'pods-repeater-cover.png' );
	imagepng( $img, $path );
	imagedestroy( $img );

	$file = array(
		'name'     => sanitize_file_name( sanitize_title( $titel ) . '.png' ),
		'tmp_name' => $path,
	);

	$attachment_id = media_handle_sideload( $file, 0, $titel );

	if ( is_wp_error( $attachment_id ) ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return 0;
	}

	return (int) $attachment_id;
}

/* ------------------------------------------------------------ Beispieldaten */

$platten = array(
	array(
		'artist'    => 'Pink Floyd',
		'titel'     => 'The Dark Side of the Moon',
		'jahr'      => 1973,
		'label'     => 'Harvest',
		'format'    => 'LP',
		'genre'     => array( 'Rock' ),
		'zustand'   => 'VG+',
		'farbe'     => array( 34, 40, 49 ),
		'tracks'    => array(
			array( 'position' => 1, 'titel' => 'Speak to Me', 'seite' => 'A', 'laenge' => '1:30', 'stil' => array( 'Rock' ), 'text' => 'Intro, geht direkt in "Breathe" über.' ),
			array( 'position' => 2, 'titel' => 'Breathe', 'seite' => 'A', 'laenge' => '2:43', 'stil' => array( 'Rock' ), 'text' => 'Demo-Notiz, kein Originaltext (Urheberrecht).' ),
			array( 'position' => 3, 'titel' => 'On the Run', 'seite' => 'A', 'laenge' => '3:36', 'stil' => array( 'Elektronik', 'Rock' ), 'text' => 'Synthesizer-Sequenz, Übergang zur zweiten Seite des Programms.' ),
			array( 'position' => 4, 'titel' => 'Time', 'seite' => 'A', 'laenge' => '6:53', 'stil' => array( 'Rock' ), 'text' => 'Uhrwerk-Intro, dann Band-Einsatz.' ),
			array( 'position' => 5, 'titel' => 'The Great Gig in the Sky', 'seite' => 'A', 'laenge' => '4:44', 'stil' => array( 'Rock', 'Soul' ), 'text' => 'Instrumental mit Gesangsspur ohne Text.' ),
			array( 'position' => 6, 'titel' => 'Money', 'seite' => 'B', 'laenge' => '6:22', 'stil' => array( 'Rock' ), 'text' => 'Kassen-Sample, 7/4-Takt im Hauptteil.' ),
			array( 'position' => 7, 'titel' => 'Us and Them', 'seite' => 'B', 'laenge' => '7:46', 'stil' => array( 'Rock' ), 'text' => 'Saxofon-Passage im Mittelteil.' ),
			array( 'position' => 8, 'titel' => 'Any Colour You Like', 'seite' => 'B', 'laenge' => '3:25', 'stil' => array( 'Rock', 'Elektronik' ), 'text' => 'Instrumental.' ),
			array( 'position' => 9, 'titel' => 'Brain Damage', 'seite' => 'B', 'laenge' => '3:50', 'stil' => array( 'Rock' ), 'text' => 'Demo-Notiz, kein Originaltext (Urheberrecht).' ),
			array( 'position' => 10, 'titel' => 'Eclipse', 'seite' => 'B', 'laenge' => '2:06', 'stil' => array( 'Rock' ), 'text' => 'Schlussstück, geht nahtlos in den Anfang über.' ),
		),
		'pressungen' => array(
			array( 'jahr' => 1973, 'label' => 'Harvest', 'katalognr' => '1C 062-05 249', 'land' => 'DE', 'notiz' => 'Deutsche Erstpressung, Klappcover mit zwei Postern und zwei Stickern.' ),
			array( 'jahr' => 2016, 'label' => 'Pink Floyd Records', 'katalognr' => 'PFRLP8', 'land' => 'EU', 'notiz' => 'Remaster, 180 g, Gatefold.' ),
		),
	),
	array(
		'artist'    => 'Miles Davis',
		'titel'     => 'Kind of Blue',
		'jahr'      => 1959,
		'label'     => 'Columbia',
		'format'    => 'LP',
		'genre'     => array( 'Jazz' ),
		'zustand'   => 'VG',
		'farbe'     => array( 28, 52, 74 ),
		'tracks'    => array(
			array( 'position' => 1, 'titel' => 'So What', 'seite' => 'A', 'laenge' => '9:22', 'stil' => array( 'Jazz' ), 'text' => 'Bass-Intro, dann Bläser-Thema.' ),
			array( 'position' => 2, 'titel' => 'Freddie Freeloader', 'seite' => 'A', 'laenge' => '9:46', 'stil' => array( 'Jazz', 'Blues' ), 'text' => 'Zwölftakt-Blues in B-Dur.' ),
			array( 'position' => 3, 'titel' => 'Blue in Green', 'seite' => 'A', 'laenge' => '5:37', 'stil' => array( 'Jazz' ), 'text' => 'Ruhiges Stück, nur Klavier, Bass, Schlagzeug und Dämpfer.' ),
			array( 'position' => 4, 'titel' => 'All Blues', 'seite' => 'B', 'laenge' => '11:33', 'stil' => array( 'Jazz', 'Blues' ), 'text' => 'Sechs-Achtel-Feeling.' ),
			array( 'position' => 5, 'titel' => 'Flamenco Sketches', 'seite' => 'B', 'laenge' => '9:26', 'stil' => array( 'Jazz' ), 'text' => 'Fünf tonale Zentren statt fester Akkordfolge.' ),
		),
		'pressungen' => array(
			array( 'jahr' => 1959, 'label' => 'Columbia', 'katalognr' => 'CL 1355', 'land' => 'US', 'notiz' => 'Mono-Erstpressung, sechs Augen auf dem Label.' ),
			array( 'jahr' => 2015, 'label' => 'Columbia', 'katalognr' => '88875174441', 'land' => 'EU', 'notiz' => 'Stereo-Reissue, 180 g.' ),
		),
	),
	array(
		'artist'    => 'Kraftwerk',
		'titel'     => 'Trans-Europa Express',
		'jahr'      => 1977,
		'label'     => 'Kling Klang',
		'format'    => 'LP',
		'genre'     => array( 'Elektronik' ),
		'zustand'   => 'NM',
		'farbe'     => array( 92, 30, 34 ),
		'tracks'    => array(
			array( 'position' => 1, 'titel' => 'Trans-Europa Express', 'seite' => 'A', 'laenge' => '13:32', 'stil' => array( 'Elektronik' ), 'text' => 'Seite A ist ein durchgehendes Stück.' ),
			array( 'position' => 2, 'titel' => 'Metall auf Metall', 'seite' => 'B', 'laenge' => '2:10', 'stil' => array( 'Elektronik' ), 'text' => 'Kurze Klangstudie.' ),
			array( 'position' => 3, 'titel' => 'Abzug', 'seite' => 'B', 'laenge' => '4:45', 'stil' => array( 'Elektronik' ), 'text' => 'Rhythmusspur im Vordergrund.' ),
			array( 'position' => 4, 'titel' => 'Franz Schubert', 'seite' => 'B', 'laenge' => '4:25', 'stil' => array( 'Elektronik' ), 'text' => 'Melodie-Zitat, stark verfremdet.' ),
			array( 'position' => 5, 'titel' => 'Endlos endlos', 'seite' => 'B', 'laenge' => '5:48', 'stil' => array( 'Elektronik' ), 'text' => 'Ausblendendes Schlussstück.' ),
		),
		'pressungen' => array(
			array( 'jahr' => 1977, 'label' => 'Kling Klang', 'katalognr' => '1C 064-82 471', 'land' => 'DE', 'notiz' => 'Deutsche Originalausgabe, blau-transparentes Vinyl.' ),
			array( 'jahr' => 2009, 'label' => 'Kling Klang / Mute', 'katalognr' => 'STUMM 305', 'land' => 'UK', 'notiz' => 'Remaster, 180 g, mit Downloadcode.' ),
		),
	),
	array(
		'artist'    => 'Nina Simone',
		'titel'     => 'Pastel Blues',
		'jahr'      => 1965,
		'label'     => 'Philips',
		'format'    => 'LP',
		'genre'     => array( 'Soul', 'Blues', 'Jazz' ),
		'zustand'   => 'VG+',
		'farbe'     => array( 58, 46, 74 ),
		'tracks'    => array(
			array( 'position' => 1, 'titel' => 'Be My Husband', 'seite' => 'A', 'laenge' => '3:19', 'stil' => array( 'Blues', 'Soul' ), 'text' => 'Nur Handtrommel und Gesang.' ),
			array( 'position' => 2, 'titel' => 'Nobody Knows You When You’re Down and Out', 'seite' => 'A', 'laenge' => '2:38', 'stil' => array( 'Blues' ), 'text' => 'Blues-Standard.' ),
			array( 'position' => 3, 'titel' => 'End of the Line', 'seite' => 'A', 'laenge' => '2:52', 'stil' => array( 'Blues' ), 'text' => 'Kurzes Stück mit Bläsersatz.' ),
			array( 'position' => 4, 'titel' => 'Trouble in Mind', 'seite' => 'A', 'laenge' => '2:55', 'stil' => array( 'Blues' ), 'text' => 'Demo-Notiz, kein Originaltext (Urheberrecht).' ),
			array( 'position' => 5, 'titel' => 'Tell Me More and More and Then Some', 'seite' => 'A', 'laenge' => '3:06', 'stil' => array( 'Jazz', 'Blues' ), 'text' => 'Langsamer Blues.' ),
			array( 'position' => 6, 'titel' => 'Chilly Winds Don’t Blow', 'seite' => 'B', 'laenge' => '4:00', 'stil' => array( 'Soul' ), 'text' => 'Seite B beginnt mit einem Soul-Stück.' ),
			array( 'position' => 7, 'titel' => 'Ain’t No Use', 'seite' => 'B', 'laenge' => '2:47', 'stil' => array( 'Soul', 'Jazz' ), 'text' => 'Mit Bläsern und Orgel.' ),
			array( 'position' => 8, 'titel' => 'Strange Fruit', 'seite' => 'B', 'laenge' => '3:26', 'stil' => array( 'Jazz' ), 'text' => 'Nur Gesang und Klavier.' ),
			array( 'position' => 9, 'titel' => 'Sinnerman', 'seite' => 'B', 'laenge' => '10:19', 'stil' => array( 'Soul', 'Blues', 'Jazz' ), 'text' => 'Langes Stück mit Klavier-Ostinato und Steigerung.' ),
		),
		'pressungen' => array(
			array( 'jahr' => 1965, 'label' => 'Philips', 'katalognr' => 'PHS 600-187', 'land' => 'US', 'notiz' => 'US-Stereo-Erstpressung.' ),
			array( 'jahr' => 2019, 'label' => 'Verve', 'katalognr' => '7746 421', 'land' => 'EU', 'notiz' => 'Reissue, 180 g, Acoustic Sounds Series.' ),
		),
	),
);

foreach ( $platten as $platte ) {
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'platte',
			'post_title'   => $platte['artist'] . ' – ' . $platte['titel'],
			'post_status'  => 'publish',
			'post_content' => 'Beispieldatensatz für den Repeater. Künstler, Alben und Tracklisten sind real, Katalognummern und Pressungsjahre sind illustrativ.',
		)
	);

	if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
		$out['errors'][] = 'post ' . $platte['titel'];
		continue;
	}

	$cover_id = pods_repeater_demo_cover( $platte['artist'], $platte['titel'], $platte['farbe'] );

	$pressungen = array();
	foreach ( $platte['pressungen'] as $index => $pressung ) {
		$pressungen[] = $pressung;
	}

	$save = array(
		'artist'     => $platte['artist'],
		'jahr'       => $platte['jahr'],
		'label'      => $platte['label'],
		'format'     => $platte['format'],
		'genre'      => $platte['genre'],
		'zustand'    => $platte['zustand'],
		'cover'      => $cover_id,
		'tracks'     => $platte['tracks'],
		'pressungen' => $pressungen,
	);

	$result = pods( 'platte', (int) $post_id )->save( $save );

	$out['items'][] = array(
		'post_id'     => (int) $post_id,
		'titel'       => $platte['artist'] . ' – ' . $platte['titel'],
		'cover'       => $cover_id,
		'saved'       => is_wp_error( $result ) ? $result->get_error_message() : true,
		'edit_url'    => admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ),
		'tracks_read' => count( (array) pods_field( 'platte', (int) $post_id, 'tracks', true ) ),
	);
}

echo 'DEMO ' . wp_json_encode( $out ) . "\n";
