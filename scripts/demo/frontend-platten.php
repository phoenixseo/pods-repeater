<?php
/**
 * Plugin Name: Demo – Plattensammlung (Frontend)
 * Description: Zeigt den Pod "platte" im Frontend: Einzelansicht mit Trackliste und Pressungen, Übersicht per Shortcode [platten]. Gehört NICHT zum Plugin pods-repeater, sondern nur zur Demo-Instanz.
 *
 * @package PodsRepeater\Demo
 */

/**
 * Ermittelt die Attachment-ID aus einem Datei-Feldwert.
 *
 * Pods liefert bei "single" den kompletten Attachment-Datensatz, bei
 * Mehrfachauswahl eine Liste davon — je nach Speichervariante auch nur IDs.
 *
 * @param mixed $value Feldwert.
 * @return int Attachment-ID oder 0.
 */
function pods_repeater_demo_image_id( $value ) {
	if ( is_array( $value ) ) {
		if ( isset( $value['ID'] ) ) {
			return (int) $value['ID'];
		}

		if ( isset( $value['pod_item_id'] ) ) {
			return (int) $value['pod_item_id'];
		}

		$first = reset( $value );

		return is_scalar( $first ) ? (int) $first : pods_repeater_demo_image_id( $first );
	}

	return is_scalar( $value ) ? (int) $value : 0;
}

/**
 * Normalisiert einen Feldwert auf eine Liste von Strings.
 *
 * Pick-Felder liefern je nach Einstellung ein Array oder einen
 * kommaseparierten String, Zahlen kommen als String aus dem Meta.
 *
 * @param mixed $value Rohwert.
 * @return string[]
 */
function pods_repeater_demo_list( $value ) {
	if ( is_array( $value ) ) {
		$value = array_filter(
			array_map(
				static function ( $item ) {
					return is_scalar( $item ) ? trim( (string) $item ) : '';
				},
				$value
			),
			'strlen'
		);

		return array_values( $value );
	}

	if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
		return array_map( 'trim', explode( ',', (string) $value ) );
	}

	return array();
}

/**
 * Liest die Zeilen eines Repeater-Feldes als Array.
 *
 * @param int    $post_id Beitrags-ID.
 * @param string $field   Feldname.
 * @return array<int,array<string,mixed>>
 */
function pods_repeater_demo_rows( $post_id, $field ) {
	$rows = pods_field( 'platte', $post_id, $field, true );

	if ( is_string( $rows ) ) {
		$rows = json_decode( $rows, true );
	}

	if ( ! is_array( $rows ) ) {
		return array();
	}

	return array_values(
		array_filter(
			$rows,
			static function ( $row ) {
				if ( ! is_array( $row ) ) {
					return false;
				}

				// Multi-Pick-Zellen sind Arrays, Zahlen sind Skalare.
				foreach ( $row as $value ) {
					if ( is_array( $value ) && array_filter( $value, 'strlen' ) ) {
						return true;
					}

					if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
						return true;
					}
				}

				return false;
			}
		)
	);
}

/**
 * Gibt eine Zeile aus Sub-Feld-Werten als Zellen aus.
 *
 * @param array $row    Zeilenwerte.
 * @param array $order  Reihenfolge der Sub-Felder (name => format).
 * @return string HTML.
 */
function pods_repeater_demo_cells( $row, $order ) {
	$html = '';

	foreach ( $order as $name => $format ) {
		$value = isset( $row[ $name ] ) ? $row[ $name ] : '';
		$items = pods_repeater_demo_list( $value );

		if ( 'multi' === $format ) {
			$text = implode( ', ', $items );
		} else {
			$text = isset( $items[0] ) ? $items[0] : '';
		}

		$html .= '<td style="padding:6px 10px;border-bottom:1px solid #e5e2dc;vertical-align:top;">' . esc_html( $text ) . '</td>';
	}

	return $html;
}

/**
 * Tabelle aus Repeater-Zeilen.
 *
 * @param array  $rows      Zeilen.
 * @param array  $columns   Spalten (name => array(label, format)).
 * @param string $empty_msg Meldung bei leerem Feld.
 * @return string HTML.
 */
function pods_repeater_demo_table( $rows, $columns, $empty_msg ) {
	if ( empty( $rows ) ) {
		return '<p style="color:#6b6b6b;">' . esc_html( $empty_msg ) . '</p>';
	}

	$order = array();

	$html = '<table style="width:100%;border-collapse:collapse;font-size:0.95rem;margin:0.5rem 0 1.5rem;"><thead><tr>';

	foreach ( $columns as $name => $column ) {
		$order[ $name ] = $column[1];

		$html .= '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #1f1f1f;font-weight:600;">' . esc_html( $column[0] ) . '</th>';
	}

	$html .= '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$html .= '<tr>' . pods_repeater_demo_cells( $row, $order ) . '</tr>';
	}

	$html .= '</tbody></table>';

	return $html;
}

/**
 * Einzelansicht einer Platte als HTML.
 *
 * @param int $post_id Beitrags-ID.
 * @return string HTML.
 */
function pods_repeater_demo_single( $post_id ) {
	$post_id = (int) $post_id;
	$pods    = pods( 'platte', $post_id );

	if ( ! $pods->valid() ) {
		return '';
	}

	$cover_id = pods_repeater_demo_image_id( $pods->field( 'cover' ) );
	$artist   = (string) $pods->field( 'artist' );
	$jahr     = (string) $pods->field( 'jahr' );
	$label    = (string) $pods->field( 'label' );
	$format   = pods_repeater_demo_list( $pods->field( 'format' ) );
	$genre    = pods_repeater_demo_list( $pods->field( 'genre' ) );
	$zustand  = pods_repeater_demo_list( $pods->field( 'zustand' ) );
	$tracks   = pods_repeater_demo_rows( $post_id, 'tracks' );
	$pressen  = pods_repeater_demo_rows( $post_id, 'pressungen' );

	$html  = '<div style="margin:1.5rem 0 2rem;">';
	$html .= '<div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;">';

	if ( $cover_id > 0 ) {
		$html .= '<div style="flex:0 0 260px;">' . wp_get_attachment_image(
			$cover_id,
			'medium',
			false,
			array(
				'style' => 'width:100%;height:auto;border-radius:4px;display:block;',
				'alt'   => $artist,
			)
		) . '</div>';
	}

	$html .= '<div style="flex:1 1 320px;">';
	$html .= '<h2 style="margin:0 0 0.25rem;font-size:1.5rem;">' . esc_html( $artist ) . '</h2>';

	$meta = array_filter(
		array(
			$jahr,
			$label,
			implode( ' / ', $format ),
			implode( ', ', $genre ),
			$zustand ? 'Zustand ' . implode( ', ', $zustand ) : '',
		),
		'strlen'
	);

	$html .= '<p style="margin:0 0 1rem;color:#4a4a4a;">' . esc_html( implode( ' · ', $meta ) ) . '</p>';

	$html .= '<p style="margin:0 0 1.5rem;">'
		. esc_html( sprintf( '%d Tracks · %d Pressungen', count( $tracks ), count( $pressen ) ) )
		. '</p>';

	$html .= '</div></div>';

	$html .= '<h3 style="margin:2rem 0 0.25rem;font-size:1.15rem;">Trackliste</h3>';
	$html .= pods_repeater_demo_table(
		$tracks,
		array(
			'position' => array( 'Nr.', 'single' ),
			'titel'    => array( 'Titel', 'single' ),
			'seite'    => array( 'Seite', 'single' ),
			'laenge'   => array( 'Länge', 'single' ),
			'stil'     => array( 'Stil', 'multi' ),
			'text'     => array( 'Notiz', 'single' ),
		),
		'Keine Tracks erfasst.'
	);

	$html .= '<h3 style="margin:2rem 0 0.25rem;font-size:1.15rem;">Pressungen</h3>';
	$html .= pods_repeater_demo_table(
		$pressen,
		array(
			'jahr'      => array( 'Jahr', 'single' ),
			'label'     => array( 'Label', 'single' ),
			'katalognr' => array( 'Katalognr.', 'single' ),
			'land'      => array( 'Land', 'single' ),
			'notiz'     => array( 'Zustand / Besonderheit', 'single' ),
		),
		'Keine Pressungen erfasst.'
	);

	$html .= '</div>';

	return $html;
}

/**
 * Hängt die Detailansicht an den Inhalt einer Platte.
 *
 * @param string $content Beitragsinhalt.
 * @return string Inhalt.
 */
function pods_repeater_demo_content( $content ) {
	if ( ! is_singular( 'platte' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	return $content . pods_repeater_demo_single( get_the_ID() );
}
add_filter( 'the_content', 'pods_repeater_demo_content', 20 );

/**
 * Shortcode [platten]: Raster aller Platten mit Cover, Titel und Trackzahl.
 *
 * @return string HTML.
 */
function pods_repeater_demo_shortcode() {
	$posts = get_posts(
		array(
			'post_type'     => 'platte',
			'numberposts'   => -1,
			'post_status'   => 'publish',
			'orderby'       => 'title',
			'order'         => 'ASC',
			'no_found_rows' => true,
		)
	);

	if ( empty( $posts ) ) {
		return '<p>Noch keine Platten erfasst.</p>';
	}

	$html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1.5rem;margin:1.5rem 0;">';

	foreach ( $posts as $post ) {
		$pods     = pods( 'platte', $post->ID );
		$cover_id = pods_repeater_demo_image_id( $pods->field( 'cover' ) );
		$tracks   = pods_repeater_demo_rows( $post->ID, 'tracks' );
		$artist   = (string) $pods->field( 'artist' );
		$jahr     = (string) $pods->field( 'jahr' );
		$genre    = implode( ', ', pods_repeater_demo_list( $pods->field( 'genre' ) ) );

		$html .= '<a href="' . esc_url( get_permalink( $post ) ) . '" style="text-decoration:none;color:inherit;display:block;">';

		if ( $cover_id > 0 ) {
			$html .= wp_get_attachment_image(
				$cover_id,
				'medium',
				false,
				array(
					'style' => 'width:100%;height:auto;border-radius:4px;display:block;margin-bottom:0.6rem;',
					'alt'   => $artist,
				)
			);
		}

		$html .= '<strong style="display:block;font-size:1rem;line-height:1.3;">' . esc_html( get_the_title( $post ) ) . '</strong>';
		$html .= '<span style="display:block;color:#5a5a5a;font-size:0.85rem;">'
			. esc_html( trim( $jahr . ' · ' . $genre ) )
			. '</span>';
		$html .= '<span style="display:block;color:#5a5a5a;font-size:0.85rem;">'
			. esc_html( sprintf( '%d Tracks', count( $tracks ) ) )
			. '</span>';
		$html .= '</a>';
	}

	$html .= '</div>';

	return $html;
}
add_shortcode( 'platten', 'pods_repeater_demo_shortcode' );
