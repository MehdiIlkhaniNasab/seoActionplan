<?php
/**
 * Google Search Console API: Search Analytics and Sitemaps.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * List verified properties.
 */
function sap_gsc_sites() {
	$cached = get_transient( 'sap_gsc_sites' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$data = sap_google_request( 'GET', 'https://www.googleapis.com/webmasters/v3/sites', null, 20 );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$sites = array();
	foreach ( (array) ( $data['siteEntry'] ?? array() ) as $s ) {
		if ( 'siteUnverifiedUser' === ( $s['permissionLevel'] ?? '' ) ) {
			continue;
		}
		$sites[] = array(
			'url'        => $s['siteUrl'],
			'permission' => $s['permissionLevel'] ?? '',
		);
	}
	usort( $sites, function ( $a, $b ) {
		return strcmp( $a['url'], $b['url'] );
	} );
	set_transient( 'sap_gsc_sites', $sites, 10 * MINUTE_IN_SECONDS );
	return $sites;
}

/**
 * Search Analytics query with automatic pagination.
 *
 * @param string $site       Property (sc-domain:example.com or https://example.com/).
 * @param string $start      Y-m-d.
 * @param string $end        Y-m-d.
 * @param array  $dimensions e.g. ['query'], ['page'], ['query','page'], ['date'].
 * @param int    $max_rows   Max rows to fetch (API page size is 25,000).
 * @param array  $extra      Extra body params (dimensionFilterGroups, type, …).
 * @return array|WP_Error   Rows: keys (by dimension name) + clicks, impressions, ctr, position.
 */
function sap_gsc_query( $site, $start, $end, $dimensions, $max_rows = 25000, $extra = array() ) {
	$url    = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site ) . '/searchAnalytics/query';
	$rows   = array();
	$offset = 0;
	$page   = min( 25000, $max_rows );
	do {
		$body = array_merge( array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => $dimensions,
			'type'       => 'web',
			'rowLimit'   => $page,
			'startRow'   => $offset,
			'dataState'  => 'final',
		), $extra );
		$data = sap_google_request( 'POST', $url, $body, 90 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$batch = (array) ( $data['rows'] ?? array() );
		foreach ( $batch as $r ) {
			$row = array();
			foreach ( $dimensions as $i => $dim ) {
				$row[ $dim ] = $r['keys'][ $i ] ?? '';
			}
			$row['clicks']      = (int) ( $r['clicks'] ?? 0 );
			$row['impressions'] = (int) ( $r['impressions'] ?? 0 );
			$row['ctr']         = (float) ( $r['ctr'] ?? 0 );
			$row['position']    = round( (float) ( $r['position'] ?? 0 ), 2 );
			$rows[]             = $row;
		}
		$offset += count( $batch );
	} while ( count( $batch ) === $page && $offset < $max_rows );
	return $rows;
}

/**
 * Sitemaps report.
 */
function sap_gsc_sitemaps( $site ) {
	$data = sap_google_request( 'GET', 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site ) . '/sitemaps', null, 30 );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$out = array();
	foreach ( (array) ( $data['sitemap'] ?? array() ) as $s ) {
		$submitted = 0;
		foreach ( (array) ( $s['contents'] ?? array() ) as $c ) {
			$submitted += (int) ( $c['submitted'] ?? 0 );
		}
		$out[] = array(
			'path'            => $s['path'] ?? '',
			'last_submitted'  => $s['lastSubmitted'] ?? '',
			'last_downloaded' => $s['lastDownloaded'] ?? '',
			'pending'         => ! empty( $s['isPending'] ),
			'errors'          => (int) ( $s['errors'] ?? 0 ),
			'warnings'        => (int) ( $s['warnings'] ?? 0 ),
			'submitted'       => $submitted,
		);
	}
	return $out;
}

/**
 * Analysis window: the last 28 complete days (GSC data lags ~3 days) and the 28 before.
 *
 * @return array{cur: string[], prev: string[]}
 */
function sap_gsc_windows() {
	$end   = strtotime( '-3 days', current_time( 'timestamp' ) ); // phpcs:ignore
	$start = $end - 27 * DAY_IN_SECONDS;
	return array(
		'cur'  => array( gmdate( 'Y-m-d', $start ), gmdate( 'Y-m-d', $end ) ),
		'prev' => array( gmdate( 'Y-m-d', $start - 28 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $start - DAY_IN_SECONDS ) ),
	);
}
