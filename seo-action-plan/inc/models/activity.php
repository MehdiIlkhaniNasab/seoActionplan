<?php
/**
 * Activity log: every action done on a project — completed tasks, manual entries,
 * content published (from the content calendar sheet), changes pushed by agents.
 * It is the source of the «اقدامات انجام‌شده» section of the monthly SEO report.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activity sources.
 */
function sap_activity_sources() {
	return array(
		'manual' => 'ثبت دستی',
		'task'   => 'تسک اکشن پلن',
		'sheet'  => 'گوگل شیت',
		'system' => 'سیستم',
		'agent'  => 'افزونه سایت',
		'api'    => 'API',
	);
}

/**
 * Insert an activity entry. Returns its id.
 */
function sap_activity_add( $project_id, $data ) {
	global $wpdb;
	$cat    = $data['category'] ?? 'management';
	$fields = array(
		'project_id'   => (int) $project_id,
		'focus_id'     => absint( $data['focus_id'] ?? 0 ),
		'task_id'      => absint( $data['task_id'] ?? 0 ),
		'category'     => array_key_exists( $cat, sap_contexts() ) ? $cat : 'management',
		'action'       => sanitize_key( $data['action'] ?? 'manual' ),
		'title'        => mb_substr( sanitize_text_field( $data['title'] ?? '' ), 0, 250 ),
		'url'          => esc_url_raw( $data['url'] ?? '' ),
		'before_value' => sanitize_textarea_field( $data['before_value'] ?? '' ),
		'after_value'  => sanitize_textarea_field( $data['after_value'] ?? '' ),
		'source'       => array_key_exists( $data['source'] ?? '', sap_activity_sources() ) ? $data['source'] : 'manual',
		'ref'          => substr( (string) ( $data['ref'] ?? '' ), 0, 64 ),
		'user_id'      => absint( $data['user_id'] ?? get_current_user_id() ),
		'happened_at'  => ! empty( $data['happened_at'] ) ? $data['happened_at'] : sap_now(),
		'meta'         => wp_json_encode( $data['meta'] ?? array() ),
	);
	$wpdb->insert( sap_table( 'activity' ), $fields );
	return (int) $wpdb->insert_id;
}

/**
 * Insert or update the entry with a reference key (dedup for automatic entries).
 *
 * @param bool $touch_time When updating, also move happened_at.
 */
function sap_activity_upsert_ref( $project_id, $ref, $data, $touch_time = true ) {
	global $wpdb;
	$ref = substr( (string) $ref, 0, 64 );
	$id  = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . sap_table( 'activity' ) . ' WHERE project_id = %d AND ref = %s', $project_id, $ref ) ); // phpcs:ignore
	if ( ! $id ) {
		$data['ref'] = $ref;
		return sap_activity_add( $project_id, $data );
	}
	$upd = array(
		'focus_id'    => absint( $data['focus_id'] ?? 0 ),
		'category'    => $data['category'] ?? 'management',
		'title'       => mb_substr( sanitize_text_field( $data['title'] ?? '' ), 0, 250 ),
		'url'         => esc_url_raw( $data['url'] ?? '' ),
		'after_value' => sanitize_textarea_field( $data['after_value'] ?? '' ),
	);
	if ( $touch_time && ! empty( $data['happened_at'] ) ) {
		$upd['happened_at'] = $data['happened_at'];
	}
	$wpdb->update( sap_table( 'activity' ), $upd, array( 'id' => (int) $id ) );
	return (int) $id;
}

/**
 * Does an entry with this reference exist?
 */
function sap_activity_has_ref( $project_id, $ref ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . sap_table( 'activity' ) . ' WHERE project_id = %d AND ref = %s', $project_id, substr( (string) $ref, 0, 64 ) ) ); // phpcs:ignore
}

/**
 * Delete the entry with a reference key.
 */
function sap_activity_delete_ref( $project_id, $ref ) {
	global $wpdb;
	$wpdb->delete( sap_table( 'activity' ), array( 'project_id' => (int) $project_id, 'ref' => substr( (string) $ref, 0, 64 ) ) );
}

/**
 * Delete one entry.
 */
function sap_activity_delete( $project_id, $id ) {
	global $wpdb;
	$wpdb->delete( sap_table( 'activity' ), array( 'project_id' => (int) $project_id, 'id' => (int) $id ) );
}

/**
 * Entries of a project in a Gregorian date range (inclusive).
 */
function sap_activity_list( $project_id, $start = '', $end = '', $category = '' ) {
	global $wpdb;
	$w = $wpdb->prepare( 'project_id = %d', $project_id );
	if ( $start ) {
		$w .= $wpdb->prepare( ' AND happened_at >= %s', $start . ' 00:00:00' );
	}
	if ( $end ) {
		$w .= $wpdb->prepare( ' AND happened_at <= %s', $end . ' 23:59:59' );
	}
	if ( $category ) {
		$w .= $wpdb->prepare( ' AND category = %s', $category );
	}
	$rows = $wpdb->get_results( 'SELECT * FROM ' . sap_table( 'activity' ) . " WHERE $w ORDER BY happened_at DESC, id DESC", ARRAY_A ); // phpcs:ignore
	foreach ( $rows as &$r ) {
		$r['id']       = (int) $r['id'];
		$r['focus_id'] = (int) $r['focus_id'];
		$r['meta']     = json_decode( (string) $r['meta'], true ) ?: array();
		foreach ( array( 'url', 'before_value', 'after_value' ) as $k ) {
			$r[ $k ] = (string) $r[ $k ];
		}
	}
	return (array) $rows;
}

/**
 * Report-ready export of a period: what SeoReport reads.
 */
function sap_activity_export( $project, $start, $end ) {
	$entries = sap_activity_list( $project['id'], $start, $end );
	$focuses = array();
	foreach ( sap_focuses( $project['id'] ) as $f ) {
		$focuses[ $f['id'] ] = $f['name'];
	}
	$groups = array();
	$lines  = array();
	$items  = array();
	foreach ( array_reverse( $entries ) as $e ) {
		$label            = sap_context_label( $e['category'] );
		$groups[ $label ] = ( $groups[ $label ] ?? 0 ) + 1;
		$text             = $e['title'] . ( $e['url'] ? ' — ' . rawurldecode( $e['url'] ) : '' );
		$lines[]          = $label . ' | ' . $text;
		$items[]          = array(
			'id'          => $e['id'],
			'date'        => substr( $e['happened_at'], 0, 10 ),
			'date_jalali' => sap_jdate( $e['happened_at'], 'short' ),
			'category'    => $e['category'],
			'category_label' => $label,
			'focus'       => $focuses[ $e['focus_id'] ] ?? '',
			'title'       => $e['title'],
			'url'         => $e['url'],
			'before'      => $e['before_value'],
			'after'       => $e['after_value'],
			'source'      => $e['source'],
			'user'        => sap_user_name( $e['user_id'] ),
		);
	}
	return array(
		'count'           => count( $items ),
		'by_category'     => $groups,
		'items'           => $items,
		'work_done_lines' => implode( "\n", $lines ),
	);
}
