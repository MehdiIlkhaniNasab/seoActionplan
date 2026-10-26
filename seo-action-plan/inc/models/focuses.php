<?php
/**
 * Focuses («زاویه دید»): the part of a site a project concentrates on, e.g. one
 * category such as «اسکوتر برقی». A focus is defined by URL patterns, clusters
 * from the clustering sheet and extra keywords, plus a measurable goal.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * KPI metrics a focus goal can be measured with.
 */
function sap_kpi_metrics() {
	return array(
		'clicks'      => 'کلیک ارگانیک (۲۸ روز)',
		'impressions' => 'نمایش (۲۸ روز)',
		'top10'       => 'تعداد کلمات هدف در صفحه اول',
		'top3'        => 'تعداد کلمات هدف در رتبه ۱ تا ۳',
	);
}

/**
 * Hydrate a row.
 */
function sap_focus_hydrate( $row ) {
	if ( ! $row ) {
		return null;
	}
	$row                 = (array) $row;
	$row['id']           = (int) $row['id'];
	$row['project_id']   = (int) $row['project_id'];
	$row['url_patterns'] = sap_rows( (string) $row['url_patterns'] );
	$row['clusters']     = sap_list( (string) $row['clusters'] );
	$row['keywords']     = sap_list( (string) $row['keywords'] );
	$row['weight']       = (float) $row['weight'];
	$row['kpi_baseline'] = (float) $row['kpi_baseline'];
	$row['kpi_target']   = (float) $row['kpi_target'];
	return $row;
}

/**
 * Focuses of a project.
 */
function sap_focuses( $project_id, $only_active = false ) {
	global $wpdb;
	$sql = $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'focuses' ) . ' WHERE project_id = %d', $project_id ); // phpcs:ignore
	if ( $only_active ) {
		$sql .= " AND status = 'active'";
	}
	return array_map( 'sap_focus_hydrate', (array) $wpdb->get_results( $sql . ' ORDER BY id ASC' ) ); // phpcs:ignore
}

/**
 * One focus.
 */
function sap_focus( $id ) {
	global $wpdb;
	return sap_focus_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'focuses' ) . ' WHERE id = %d', $id ) ) ); // phpcs:ignore
}

/**
 * Insert or update a focus.
 */
function sap_focus_save( $project_id, $data, $id = 0 ) {
	global $wpdb;
	$metric   = sanitize_key( $data['kpi_metric'] ?? 'clicks' );
	$deadline = sap_parse_date( $data['deadline'] ?? '' );
	$fields   = array(
		'project_id'   => (int) $project_id,
		'name'         => sanitize_text_field( $data['name'] ?? '' ),
		'url_patterns' => sanitize_textarea_field( $data['url_patterns'] ?? '' ),
		'clusters'     => sanitize_textarea_field( $data['clusters'] ?? '' ),
		'keywords'     => sanitize_textarea_field( $data['keywords'] ?? '' ),
		'goal'         => sanitize_textarea_field( $data['goal'] ?? '' ),
		'kpi_metric'   => array_key_exists( $metric, sap_kpi_metrics() ) ? $metric : 'clicks',
		'kpi_baseline' => (float) sap_norm_text( $data['kpi_baseline'] ?? 0 ),
		'kpi_target'   => (float) sap_norm_text( $data['kpi_target'] ?? 0 ),
		'deadline'     => $deadline ?: null,
		'weight'       => min( 3, max( 1, (float) ( $data['weight'] ?? 1.5 ) ) ),
		'status'       => in_array( $data['status'] ?? '', array( 'active', 'paused', 'done' ), true ) ? $data['status'] : 'active',
	);
	if ( $id ) {
		$wpdb->update( sap_table( 'focuses' ), $fields, array( 'id' => (int) $id, 'project_id' => (int) $project_id ) );
		return (int) $id;
	}
	$fields['created_at'] = sap_now();
	$wpdb->insert( sap_table( 'focuses' ), $fields );
	return (int) $wpdb->insert_id;
}

/**
 * Delete a focus (findings/tasks keep their data and lose the focus link).
 */
function sap_focus_delete( $project_id, $id ) {
	global $wpdb;
	$wpdb->delete( sap_table( 'focuses' ), array( 'id' => (int) $id, 'project_id' => (int) $project_id ) );
	foreach ( array( 'findings', 'tasks', 'activity' ) as $t ) {
		$wpdb->update( sap_table( $t ), array( 'focus_id' => 0 ), array( 'focus_id' => (int) $id, 'project_id' => (int) $project_id ) );
	}
}

/**
 * Keyword set of a focus: its own keywords + all keywords of its clusters in the
 * clustering sheet. Keys are sap_kw_key() join keys, values the original text.
 */
function sap_focus_keyword_set( $focus, $cluster_rows ) {
	$set      = array();
	$clusters = array_map( 'sap_kw_key', $focus['clusters'] );
	foreach ( (array) $cluster_rows as $r ) {
		$c  = sap_kw_key( $r['cluster'] ?? '' );
		$sc = sap_kw_key( $r['subcluster'] ?? '' );
		if ( '' !== $c && ( in_array( $c, $clusters, true ) || ( '' !== $sc && in_array( $sc, $clusters, true ) ) ) ) {
			$set[ sap_kw_key( $r['keyword'] ) ] = $r['keyword'];
		}
	}
	foreach ( $focus['keywords'] as $k ) {
		$set[ sap_kw_key( $k ) ] = $k;
	}
	unset( $set[''] );
	return $set;
}

/**
 * Focus context for a project: each active focus with its resolved keyword set.
 * Used by detectors to tag findings.
 */
function sap_focus_context( $project_id ) {
	$clusters = sap_dataset_payload( $project_id, 'sheet_clusters' );
	$out      = array();
	foreach ( sap_focuses( $project_id, true ) as $f ) {
		$f['kw_set'] = sap_focus_keyword_set( $f, $clusters['rows'] ?? array() );
		$out[]       = $f;
	}
	return $out;
}

/**
 * Which focus does a URL / keyword belong to? Returns [focus_id, weight].
 */
function sap_focus_match( $focuses, $url = '', $keyword = '' ) {
	$kk = '' !== $keyword ? sap_kw_key( $keyword ) : '';
	foreach ( $focuses as $f ) {
		if ( ( '' !== $url && sap_url_matches( $url, $f['url_patterns'] ) ) || ( '' !== $kk && isset( $f['kw_set'][ $kk ] ) ) ) {
			return array( $f['id'], $f['weight'] );
		}
	}
	return array( 0, 1.0 );
}
