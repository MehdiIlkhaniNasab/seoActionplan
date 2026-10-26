<?php
/**
 * Focus KPIs measured from the latest Search Console snapshot.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Current value of every KPI metric for a focus.
 *
 * A Search Console row belongs to the focus when its page matches the focus URL
 * patterns or its query is one of the focus keywords.
 *
 * @return array|null metric => value, plus 'keywords' (size of the keyword set).
 */
function sap_focus_kpis( $focus, $project_id ) {
	$qp = sap_dataset_payload( $project_id, 'gsc_qp' );
	if ( empty( $qp['rows'] ) ) {
		return null;
	}
	if ( ! isset( $focus['kw_set'] ) ) {
		$clusters        = sap_dataset_payload( $project_id, 'sheet_clusters' );
		$focus['kw_set'] = sap_focus_keyword_set( $focus, $clusters['rows'] ?? array() );
	}
	$clicks = 0;
	$impr   = 0;
	$best   = array();
	foreach ( $qp['rows'] as $r ) {
		$k     = sap_kw_key( $r['query'] );
		$is_kw = isset( $focus['kw_set'][ $k ] );
		if ( $is_kw || sap_url_matches( $r['page'], $focus['url_patterns'] ) ) {
			$clicks += $r['clicks'];
			$impr   += $r['impressions'];
		}
		if ( $is_kw && ( ! isset( $best[ $k ] ) || $r['position'] < $best[ $k ] ) ) {
			$best[ $k ] = $r['position'];
		}
	}
	$top10 = count( array_filter( $best, function ( $p ) {
		return $p <= 10;
	} ) );
	$top3  = count( array_filter( $best, function ( $p ) {
		return $p <= 3;
	} ) );
	return array(
		'clicks'      => $clicks,
		'impressions' => $impr,
		'top10'       => $top10,
		'top3'        => $top3,
		'keywords'    => count( $focus['kw_set'] ),
		'ranking'     => count( $best ),
	);
}

/**
 * Goal progress (0–1) or null when no target is set.
 */
function sap_focus_progress( $focus, $current ) {
	$t = (float) $focus['kpi_target'];
	$b = (float) $focus['kpi_baseline'];
	if ( $t <= 0 || null === $current || abs( $t - $b ) < 1e-9 ) {
		return null;
	}
	return max( 0, min( 1, ( $current - $b ) / ( $t - $b ) ) );
}
