<?php
/**
 * Comments: your own notes on a task, finding, content idea or revision.
 * They are shown on the item and passed to the AI as the expert's view, so the
 * next deliverable or analysis takes them into account.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Object types that take comments.
 */
function sap_comment_types() {
	return apply_filters( 'sap_comment_types', array( 'task', 'finding', 'idea', 'revision' ) );
}

/**
 * Comments of an object, oldest first.
 */
function sap_comments( $type, $object_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'comments' ) . ' WHERE object_type = %s AND object_id = %d ORDER BY id ASC', $type, $object_id ), ARRAY_A ); // phpcs:ignore
	foreach ( (array) $rows as &$r ) {
		$r['id']      = (int) $r['id'];
		$r['user_id'] = (int) $r['user_id'];
	}
	return (array) $rows;
}

/**
 * Comment counts for many objects of one type.
 *
 * @return array object id => count
 */
function sap_comment_counts( $type, $ids ) {
	global $wpdb;
	$ids = array_filter( array_map( 'intval', (array) $ids ) );
	if ( ! $ids ) {
		return array();
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, COUNT(*) n FROM ' . sap_table( 'comments' ) . ' WHERE object_type = %s AND object_id IN (' . implode( ',', $ids ) . ') GROUP BY object_id', $type ), ARRAY_A ); // phpcs:ignore
	return wp_list_pluck( (array) $rows, 'n', 'object_id' );
}

/**
 * Add a comment.
 */
function sap_comment_add( $project_id, $type, $object_id, $body ) {
	global $wpdb;
	$body = trim( sanitize_textarea_field( $body ) );
	if ( '' === $body || ! in_array( $type, sap_comment_types(), true ) ) {
		return 0;
	}
	$wpdb->insert( sap_table( 'comments' ), array(
		'project_id'  => (int) $project_id,
		'object_type' => $type,
		'object_id'   => (int) $object_id,
		'user_id'     => get_current_user_id(),
		'body'        => mb_substr( $body, 0, 4000 ),
		'created_at'  => sap_now(),
	) );
	return (int) $wpdb->insert_id;
}

/**
 * One comment.
 */
function sap_comment( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'comments' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
}

/**
 * Delete a comment.
 */
function sap_comment_delete( $id ) {
	global $wpdb;
	$wpdb->delete( sap_table( 'comments' ), array( 'id' => (int) $id ) );
}

/**
 * Delete every comment of an object (when the object is deleted).
 */
function sap_comments_purge( $type, $object_id ) {
	global $wpdb;
	$wpdb->delete( sap_table( 'comments' ), array( 'object_type' => $type, 'object_id' => (int) $object_id ) );
}

/**
 * Comments as plain lines for an AI prompt ("the expert's view").
 *
 * @return string[]
 */
function sap_comments_for_ai( $type, $object_id ) {
	return array_values( array_map( function ( $c ) {
		return $c['body'];
	}, sap_comments( $type, $object_id ) ) );
}
