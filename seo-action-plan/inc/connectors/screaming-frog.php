<?php
/**
 * Screaming Frog SEO Spider crawl intake.
 *
 * Screaming Frog has no cloud API; its headless CLI exports the "Internal:All" tab.
 * The SeoReport agent (connectors/screaming-frog/sf-agent.*) can push the same file
 * here: point --endpoint at /wp-json/seoactionplan/v1/crawl/{project} and use this
 * project's key (the X-SR-Key header the agent sends is accepted). A CSV can also be
 * uploaded by hand on the project page.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Map SF column headers (several SF versions) to internal keys.
 */
function sap_sf_column_map() {
	return array(
		'address'        => array( 'Address' ),
		'content_type'   => array( 'Content Type', 'Content' ),
		'status_code'    => array( 'Status Code' ),
		'status'         => array( 'Status' ),
		'indexability'   => array( 'Indexability' ),
		'index_status'   => array( 'Indexability Status' ),
		'title'          => array( 'Title 1' ),
		'title_len'      => array( 'Title 1 Length' ),
		'title_px'       => array( 'Title 1 Pixel Width' ),
		'meta'           => array( 'Meta Description 1' ),
		'meta_len'       => array( 'Meta Description 1 Length' ),
		'h1'             => array( 'H1-1' ),
		'h1_2'           => array( 'H1-2' ),
		'meta_robots'    => array( 'Meta Robots 1' ),
		'canonical'      => array( 'Canonical Link Element 1' ),
		'size'           => array( 'Size (bytes)', 'Size' ),
		'words'          => array( 'Word Count' ),
		'depth'          => array( 'Crawl Depth', 'Level' ),
		'inlinks'        => array( 'Unique Inlinks', 'Inlinks' ),
		'outlinks'       => array( 'Unique Outlinks', 'Outlinks' ),
		'response_time'  => array( 'Response Time' ),
		'redirect_url'   => array( 'Redirect URL' ),
		'near_dupes'     => array( 'No. Near Duplicates' ),
		'link_score'     => array( 'Link Score' ),
		'hash'           => array( 'Hash' ),
	);
}

/**
 * Parse an Internal:All CSV file into compact rows.
 *
 * @return array|WP_Error
 */
function sap_sf_parse_csv( $file ) {
	$fh = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return new WP_Error( 'sap_sf_file', 'فایل خزش قابل خواندن نیست.' );
	}
	$header = fgetcsv( $fh, 0, ',', '"', '\\' );
	// Older SF versions put a title row ("Internal - All") above the header.
	if ( is_array( $header ) && count( $header ) <= 2 ) {
		$header = fgetcsv( $fh, 0, ',', '"', '\\' );
	}
	if ( ! is_array( $header ) ) {
		fclose( $fh ); // phpcs:ignore
		return new WP_Error( 'sap_sf_header', 'سربرگ فایل خزش یافت نشد.' );
	}
	$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
	$header    = array_map( 'trim', $header );
	$idx       = array();
	foreach ( sap_sf_column_map() as $key => $names ) {
		foreach ( $names as $n ) {
			$pos = array_search( $n, $header, true );
			if ( false !== $pos ) {
				$idx[ $key ] = $pos;
				break;
			}
		}
	}
	if ( ! isset( $idx['address'] ) ) {
		fclose( $fh ); // phpcs:ignore
		return new WP_Error( 'sap_sf_cols', 'ستون Address در فایل وجود ندارد؛ باید تب Internal:All خروجی گرفته شود.' );
	}
	$rows = array();
	while ( ( $line = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
		if ( count( $line ) < 2 ) {
			continue;
		}
		$r = array();
		foreach ( $idx as $key => $pos ) {
			$r[ $key ] = isset( $line[ $pos ] ) ? trim( $line[ $pos ] ) : '';
		}
		$rows[] = sap_sf_normalize_row( $r );
		if ( count( $rows ) >= 100000 ) {
			break;
		}
	}
	fclose( $fh ); // phpcs:ignore
	return $rows;
}

/**
 * Normalize types for a parsed row (also used for JSON pushes).
 */
function sap_sf_normalize_row( $r ) {
	$num = function ( $v ) {
		return is_numeric( $v ) ? $v + 0 : 0;
	};
	return array(
		'url'      => (string) ( $r['address'] ?? $r['url'] ?? '' ),
		'type'     => strtolower( (string) ( $r['content_type'] ?? $r['type'] ?? '' ) ),
		'code'     => (int) ( $r['status_code'] ?? $r['code'] ?? 0 ),
		'idx'      => 0 === stripos( (string) ( $r['indexability'] ?? $r['idx'] ?? '' ), 'indexable' ) ? 1 : 0,
		'idx_why'  => (string) ( $r['index_status'] ?? $r['idx_why'] ?? '' ),
		'title'    => (string) ( $r['title'] ?? '' ),
		'title_len'=> (int) $num( $r['title_len'] ?? mb_strlen( (string) ( $r['title'] ?? '' ) ) ),
		'title_px' => (int) $num( $r['title_px'] ?? 0 ),
		'meta'     => (string) ( $r['meta'] ?? '' ),
		'meta_len' => (int) $num( $r['meta_len'] ?? mb_strlen( (string) ( $r['meta'] ?? '' ) ) ),
		'h1'       => (string) ( $r['h1'] ?? '' ),
		'h1_2'     => (string) ( $r['h1_2'] ?? '' ),
		'robots'   => strtolower( (string) ( $r['meta_robots'] ?? $r['robots'] ?? '' ) ),
		'canon'    => (string) ( $r['canonical'] ?? $r['canon'] ?? '' ),
		'size'     => (int) $num( $r['size'] ?? 0 ),
		'words'    => (int) $num( $r['words'] ?? 0 ),
		'depth'    => (int) $num( $r['depth'] ?? 0 ),
		'inlinks'  => (int) $num( $r['inlinks'] ?? 0 ),
		'outlinks' => (int) $num( $r['outlinks'] ?? 0 ),
		'rt'       => (float) $num( $r['response_time'] ?? $r['rt'] ?? 0 ),
		'redirect' => (string) ( $r['redirect_url'] ?? $r['redirect'] ?? '' ),
		'dupes'    => (int) $num( $r['near_dupes'] ?? $r['dupes'] ?? 0 ),
		'hash'     => (string) ( $r['hash'] ?? '' ),
	);
}

/**
 * Store a crawl as the project's "crawl" dataset.
 *
 * Keeps HTML pages and problem resources only, to bound the snapshot size.
 */
function sap_sf_store_crawl( $project_id, $rows, $source = 'agent', $meta = array() ) {
	$keep  = array();
	$codes = array( '2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0 );
	foreach ( $rows as $r ) {
		$bucket = $r['code'] >= 500 ? '5xx' : ( $r['code'] >= 400 ? '4xx' : ( $r['code'] >= 300 ? '3xx' : '2xx' ) );
		++$codes[ $bucket ];
		if ( false !== strpos( $r['type'], 'html' ) || $r['code'] >= 300 ) {
			$keep[] = $r;
		}
		if ( count( $keep ) >= 20000 ) {
			break;
		}
	}
	$prev = sap_dataset( $project_id, 'crawl' );
	sap_dataset_put( $project_id, 'crawl', array( 'rows' => $keep ), array_merge( $meta, array(
		'source'     => $source,
		'urls'       => count( $rows ),
		'codes'      => $codes,
		'prev_crawl' => $prev ? $prev['fetched_at'] : '',
	) ) );
	// One log entry per day, however many times the agent pushes.
	sap_activity_upsert_ref( $project_id, 'crawl:' . current_time( 'Y-m-d' ), array(
		'category' => 'technical',
		'action'   => 'crawl',
		'title'    => sprintf( 'خزش سایت با Screaming Frog (%s آدرس)', sap_num( count( $rows ) ) ),
		'source'   => 'system',
		'user_id'  => 0,
	) );
	return count( $keep );
}
