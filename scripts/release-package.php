<?php
/**
 * Release-Paket bauen, lesen, pruefen — ohne Docker und ohne unzip.
 *
 * Warum als eigenes PHP-Skript: der Paketbau braucht nur ZipArchive. Frueher
 * lief er ueber `docker exec <wp-env-container>`, was den Bau an einen
 * laufenden Webserver koppelte — lokal bequem, in CI unmoeglich. Jetzt
 * entscheidet build-release-package.sh nur noch, WER php aufruft (Host oder
 * Container), und dieses Skript macht die Arbeit.
 *
 * Alle Pfade sind relativ zum Arbeitsverzeichnis. Damit sind Host- und
 * Container-Lauf identisch, ohne Pfaduebersetzung: der Aufrufer setzt cwd auf
 * die Repo-Wurzel (`docker exec -w` bzw. `cd`).
 *
 * Aufrufe:
 *   php release-package.php build --zip <rel> --manifest <rel> \
 *       --dirs "classes/fields:0;includes:0" --files "pods-repeater.php;readme.txt"
 *   php release-package.php list --zip <rel>
 *   php release-package.php get  --zip <rel> --entry pods-repeater/readme.txt
 *
 * Exit 1 mit Meldung auf STDERR bei jedem Fehler — das Bash-Skript bricht
 * darauf ab, statt ein halbes Paket weiterzureichen.
 *
 * @package Pods_Repeater
 */

/**
 * Fehler melden und beenden.
 *
 * @param string $message Meldung.
 * @return void
 */
function pr_die( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Argumente der Form --name wert einlesen.
 *
 * @param array<int,string> $argv Roh-Argumente.
 * @return array<string,string> Name => Wert.
 */
function pr_args( array $argv ) {
	$out  = array();
	$last = count( $argv );
	for ( $i = 2; $i < $last; $i++ ) {
		$name = $argv[ $i ];
		if ( 0 !== strpos( $name, '--' ) ) {
			continue;
		}
		$key          = substr( $name, 2 );
		$out[ $key ] = isset( $argv[ $i + 1 ] ) ? $argv[ $i + 1 ] : '';
		$i++;
	}
	return $out;
}

/**
 * Zip oeffnen oder abbrechen.
 *
 * @param string $path Pfad relativ zur Repo-Wurzel.
 * @param int    $flags ZipArchive-Modus.
 * @return ZipArchive
 */
function pr_open( $path, $flags ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		pr_die( 'ZipArchive fehlt — php-zip installieren' );
	}
	// Beim Bauen existiert die Datei noch nicht; beim Lesen muss sie da sein.
	if ( ZipArchive::CREATE !== ( $flags & ZipArchive::CREATE ) && ! is_file( $path ) ) {
		pr_die( "Zip fehlt: {$path}" );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, $flags ) ) {
		pr_die( "ZipArchive konnte {$path} nicht oeffnen" );
	}
	return $zip;
}

/**
 * Eintragsnamen in Zip-Reihenfolge.
 *
 * @param ZipArchive $zip Offenes Archiv.
 * @return array<int,string>
 */
function pr_entries( ZipArchive $zip ) {
	$out = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$out[] = $zip->getNameIndex( $i );
	}
	return $out;
}

$command = isset( $argv[1] ) ? $argv[1] : '';
$args    = pr_args( $argv );

if ( 'build' === $command ) {
	$zip_path = isset( $args['zip'] ) ? $args['zip'] : '';
	$manifest = isset( $args['manifest'] ) ? $args['manifest'] : '';
	$dirs     = array_filter( explode( ';', isset( $args['dirs'] ) ? $args['dirs'] : '' ) );
	$files    = array_filter( explode( ';', isset( $args['files'] ) ? $args['files'] : '' ) );

	if ( '' === $zip_path || '' === $manifest ) {
		pr_die( 'build braucht --zip und --manifest' );
	}

	$root = '.';
	$zip  = pr_open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

	$added = 0;
	$add   = function ( $abs, $rel ) use ( $zip, &$added ) {
		if ( ! is_file( $abs ) ) {
			pr_die( "fehlt: {$abs}" );
		}
		$name = 'pods-repeater/' . $rel;
		if ( ! $zip->addFile( $abs, $name ) ) {
			pr_die( "addFile fehlgeschlagen: {$abs}" );
		}
		// Reproduzierbar: jeder Eintrag traegt die mtime der Datei, nicht die
		// Build-Zeit. setMtimeName gibt es erst ab PHP 8.3 — unter aelteren
		// Versionen bleibt der Eintrag bei der Build-Zeit, das Paket ist dann
		// nicht byte-identisch, aber vollstaendig.
		if ( method_exists( $zip, 'setMtimeName' ) ) {
			$zip->setMtimeName( $name, filemtime( $abs ) );
		}
		$added++;
	};

	foreach ( $files as $file ) {
		$add( $root . '/' . $file, $file );
	}

	foreach ( $dirs as $spec ) {
		$parts     = explode( ':', $spec );
		$dir       = $parts[0];
		$recursive = isset( $parts[1] ) ? '1' === $parts[1] : false;
		$base      = $root . '/' . $dir;
		if ( ! is_dir( $base ) ) {
			pr_die( "Verzeichnis fehlt: {$dir}" );
		}
		if ( $recursive ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $info ) {
				if ( ! $info->isFile() ) {
					continue;
				}
				$rel = $dir . '/' . substr( $info->getPathname(), strlen( $base ) + 1 );
				$add( $info->getPathname(), $rel );
			}
		} else {
			foreach ( scandir( $base ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ! is_file( $base . '/' . $entry ) ) {
					continue;
				}
				$add( $base . '/' . $entry, $dir . '/' . $entry );
			}
		}
	}

	if ( 0 === $added ) {
		pr_die( 'keine Datei hinzugefuegt' );
	}

	$zip->close();

	// Manifest aus dem fertigen Zip, nicht aus der Absicht: die Liste ist
	// das, was der Tester spaeter entpackt.
	$check = pr_open( $zip_path, ZipArchive::RDONLY );
	$names = pr_entries( $check );
	$check->close();

	if ( ! $names ) {
		pr_die( "Zip {$zip_path} ist leer" );
	}
	if ( false === file_put_contents( $manifest, implode( "\n", $names ) . "\n" ) ) {
		pr_die( "Manifest nicht schreibbar: {$manifest}" );
	}

	echo 'Dateien im Zip: ' . count( $names ) . "\n";
	exit( 0 );
}

if ( 'list' === $command ) {
	$zip = pr_open( isset( $args['zip'] ) ? $args['zip'] : '', ZipArchive::RDONLY );
	$all = pr_entries( $zip );
	$zip->close();
	echo implode( "\n", $all ) . "\n";
	exit( 0 );
}

if ( 'get' === $command ) {
	$entry  = isset( $args['entry'] ) ? $args['entry'] : '';
	$zip    = pr_open( isset( $args['zip'] ) ? $args['zip'] : '', ZipArchive::RDONLY );
	$handle = $entry ? $zip->getFromName( $entry ) : false;
	$zip->close();
	if ( false === $handle ) {
		pr_die( "Eintrag fehlt im Zip: {$entry}" );
	}
	echo $handle;
	exit( 0 );
}

pr_die( 'Unbekannter Aufruf: ' . $command . ' (erwartet: build, list, get)' );
