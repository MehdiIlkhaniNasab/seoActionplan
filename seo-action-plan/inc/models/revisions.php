<?php
/**
 * Revisions: a proposed change to a page (content audit & rewrite), kept with
 * the original, the AI audit and every proposed edit, until it is approved,
 * rejected or (later) published on the client site.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status labels.
 */
function sap_revision_statuses() {
	return array(
		'analyzing' => 'در حال تحلیل',
		'review'    => 'آماده بررسی',
		'approved'  => 'تأیید شده',
		'published' => 'منتشر شده',
		'rejected'  => 'رد شده',
		'failed'    => 'ناموفق',
	);
}

/**
 * Hydrate a row.
 */
function sap_revision_hydrate( $row ) {
	if ( ! $row ) {
		return null;
	}
	$row = (array) $row;
	foreach ( array( 'id', 'project_id', 'task_id', 'user_id' ) as $k ) {
		$row[ $k ] = (int) $row[ $k ];
	}
	foreach ( array( 'original', 'audit', 'edits', 'progress' ) as $k ) {
		$row[ $k ] = json_decode( (string) $row[ $k ], true ) ?: array();
	}
	$row['url']  = (string) $row['url'];
	$row['cost'] = (float) $row['cost'];
	return $row;
}

/**
 * One revision.
 */
function sap_revision( $id ) {
	global $wpdb;
	return sap_revision_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'revisions' ) . ' WHERE id = %d', $id ) ) ); // phpcs:ignore
}

/**
 * Revisions of a project (newest first), optionally of one URL.
 */
function sap_revisions( $project_id, $url = '', $limit = 30 ) {
	global $wpdb;
	$sql = $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'revisions' ) . ' WHERE project_id = %d', $project_id ); // phpcs:ignore
	$rows = array_map( 'sap_revision_hydrate', (array) $wpdb->get_results( $sql . ' ORDER BY id DESC LIMIT ' . (int) $limit ) ); // phpcs:ignore
	if ( '' !== $url ) {
		$k    = sap_norm_url( $url );
		$rows = array_values( array_filter( $rows, function ( $r ) use ( $k ) {
			return sap_norm_url( $r['url'] ) === $k;
		} ) );
	}
	return $rows;
}

/**
 * Insert or update.
 */
function sap_revision_save( $data, $id = 0 ) {
	global $wpdb;
	$fields = array( 'updated_at' => sap_now() );
	foreach ( array( 'project_id', 'task_id', 'user_id' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$fields[ $k ] = (int) $data[ $k ];
		}
	}
	foreach ( array( 'url', 'title', 'kind', 'status' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$fields[ $k ] = (string) $data[ $k ];
		}
	}
	foreach ( array( 'original', 'audit', 'edits', 'progress' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$fields[ $k ] = wp_json_encode( $data[ $k ], JSON_UNESCAPED_UNICODE );
		}
	}
	if ( isset( $data['cost'] ) ) {
		$fields['cost'] = (float) $data['cost'];
	}
	if ( $id ) {
		$wpdb->update( sap_table( 'revisions' ), $fields, array( 'id' => (int) $id ) );
		return (int) $id;
	}
	$fields['created_at'] = sap_now();
	$wpdb->insert( sap_table( 'revisions' ), $fields );
	return (int) $wpdb->insert_id;
}
