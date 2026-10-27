<?php
/**
 * Projects (one per client site).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hydrate a DB row.
 */
function sap_project_hydrate( $row ) {
	if ( ! $row ) {
		return null;
	}
	$row                   = (array) $row;
	$row['id']             = (int) $row['id'];
	$row['ss_project_id']  = (int) $row['ss_project_id'];
	$row['capacity_hours'] = (float) $row['capacity_hours'];
	$row['options']        = json_decode( (string) $row['options'], true ) ?: array();
	$row['competitors']    = sap_rows( (string) $row['competitors'] );
	$row['brand_terms']    = sap_list( (string) $row['brand_terms'] );
	return $row;
}

/**
 * One project.
 */
function sap_project( $id ) {
	global $wpdb;
	$id = (int) $id;
	if ( ! isset( $GLOBALS['sap_project_cache'] ) || ! array_key_exists( $id, $GLOBALS['sap_project_cache'] ) ) {
		$GLOBALS['sap_project_cache'][ $id ] = sap_project_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'projects' ) . ' WHERE id = %d', $id ) ) ); // phpcs:ignore
	}
	return $GLOBALS['sap_project_cache'][ $id ];
}

/**
 * All projects.
 */
function sap_projects( $status = 'active' ) {
	global $wpdb;
	$sql  = 'SELECT * FROM ' . sap_table( 'projects' );
	$sql .= $status ? $wpdb->prepare( ' WHERE status = %s', $status ) : '';
	$rows = $wpdb->get_results( $sql . ' ORDER BY name ASC' ); // phpcs:ignore
	return array_map( 'sap_project_hydrate', (array) $rows );
}

/**
 * Insert or update a project. Returns the id.
 */
function sap_project_save( $data, $id = 0 ) {
	global $wpdb;
	$fields = array(
		'name'               => sanitize_text_field( $data['name'] ?? '' ),
		'site_url'           => esc_url_raw( $data['site_url'] ?? '' ),
		'gsc_property'       => sanitize_text_field( $data['gsc_property'] ?? '' ),
		'sheet_id'           => sap_sheet_id_from_url( $data['sheet_id'] ?? '' ),
		'sheet_calendar_tab' => sanitize_text_field( $data['sheet_calendar_tab'] ?? '' ),
		'sheet_cluster_tab'  => sanitize_text_field( $data['sheet_cluster_tab'] ?? '' ),
		'ss_project_id'      => absint( $data['ss_project_id'] ?? 0 ),
		'ss_device'          => 'desktop' === ( $data['ss_device'] ?? '' ) ? 'desktop' : 'mobile',
		'competitors'        => implode( "\n", array_map( 'sap_clean_domain', sap_rows( $data['competitors'] ?? '' ) ) ),
		'brand_terms'        => sanitize_textarea_field( $data['brand_terms'] ?? '' ),
		'capacity_hours'     => max( 1, (float) ( $data['capacity_hours'] ?? 40 ) ),
		'updated_at'         => sap_now(),
	);
	if ( '' === $fields['sheet_calendar_tab'] ) {
		$fields['sheet_calendar_tab'] = 'تقویم محتوا';
	}
	if ( '' === $fields['sheet_cluster_tab'] ) {
		$fields['sheet_cluster_tab'] = 'کلاسترینگ';
	}
	unset( $GLOBALS['sap_project_cache'] );
	if ( $id ) {
		$wpdb->update( sap_table( 'projects' ), $fields, array( 'id' => (int) $id ) );
		return (int) $id;
	}
	$fields['api_key']    = sap_token( 32 );
	$fields['created_at'] = sap_now();
	$fields['status']     = 'active';
	$wpdb->insert( sap_table( 'projects' ), $fields );
	return (int) $wpdb->insert_id;
}

/**
 * Merge values into the project's options JSON.
 */
function sap_project_set_option( $id, $key, $value ) {
	global $wpdb;
	$p = sap_project( $id );
	if ( ! $p ) {
		return;
	}
	$opts         = $p['options'];
	$opts[ $key ] = $value;
	$wpdb->update( sap_table( 'projects' ), array( 'options' => wp_json_encode( $opts ) ), array( 'id' => (int) $id ) );
	unset( $GLOBALS['sap_project_cache'] );
}

/**
 * Delete a project and all of its data.
 */
function sap_project_delete( $id ) {
	global $wpdb;
	$id = (int) $id;
	foreach ( array( 'focuses', 'datasets', 'findings', 'tasks', 'activity', 'keymap', 'revisions', 'comments', 'ideas', 'months' ) as $t ) {
		$wpdb->delete( sap_table( $t ), array( 'project_id' => $id ) );
	}
	$wpdb->delete( sap_table( 'projects' ), array( 'id' => $id ) );
}

/**
 * Project lookup by API key (crawl intake, report export).
 */
function sap_project_by_key( $id, $key ) {
	$p = sap_project( $id );
	if ( ! $p || '' === (string) $key || ! hash_equals( (string) $p['api_key'], (string) $key ) ) {
		return null;
	}
	return $p;
}

/**
 * Domain only: "https://www.Example.com/x" → "example.com".
 */
function sap_clean_domain( $s ) {
	$s = strtolower( trim( (string) $s ) );
	$s = preg_replace( '#^https?://#', '', $s );
	$s = preg_replace( '#^www\.#', '', $s );
	return sanitize_text_field( rtrim( explode( '/', $s )[0], '.' ) );
}

/**
 * Project domain (for SEO Signal calls).
 */
function sap_project_domain( $project ) {
	$d = sap_clean_domain( $project['site_url'] );
	if ( '' === $d && $project['gsc_property'] ) {
		$d = sap_clean_domain( str_replace( 'sc-domain:', '', $project['gsc_property'] ) );
	}
	return $d;
}

/**
 * Is a query a brand query?
 */
function sap_is_brand( $query, $project ) {
	$q = sap_kw_key( $query );
	foreach ( $project['brand_terms'] as $b ) {
		$b = sap_kw_key( $b );
		if ( '' !== $b && false !== strpos( $q, $b ) ) {
			return true;
		}
	}
	return false;
}
