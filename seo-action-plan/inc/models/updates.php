<?php
/**
 * Content updates: the «update existing content» half of the content calendar.
 *
 * One row per page planned for a professional update in a month, with the
 * AI brief (keyword placement, sections, product attributes, FAQ, internal and
 * external links, schema) and its link to the rewrite / task.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Statuses.
 */
function sap_update_statuses() {
	return array(
		'planned'     => 'برنامه‌ریزی‌شده',
		'in_progress' => 'در حال انجام',
		'done'        => 'انجام شد',
		'cancelled'   => 'لغو',
	);
}

/**
 * Hydrate a row.
 */
function sap_update_hydrate( $row ) {
	if ( ! $row ) {
		return null;
	}
	$row = (array) $row;
	foreach ( array( 'id', 'project_id', 'focus_id', 'task_id' ) as $k ) {
		$row[ $k ] = (int) $row[ $k ];
	}
	$row['secondary'] = json_decode( (string) $row['secondary'], true ) ?: array();
	$row['brief']     = json_decode( (string) $row['brief'], true ) ?: array();
	$row['url']       = (string) $row['url'];
	$row['reason']    = (string) $row['reason'];
	return $row;
}

/**
 * Updates of a project.
 *
 * @param array $args period, status.
 */
function sap_updates( $project_id, $args = array() ) {
	global $wpdb;
	$args  = wp_parse_args( $args, array( 'period' => null, 'status' => '' ) );
	$where = $wpdb->prepare( 'project_id = %d', $project_id );
	if ( null !== $args['period'] ) {
		$where .= $wpdb->prepare( ' AND period = %s', $args['period'] );
	}
	if ( $args['status'] ) {
		$where .= " AND status IN ('" . implode( "','", array_map( 'sanitize_key', (array) $args['status'] ) ) . "')";
	}
	return array_map( 'sap_update_hydrate', (array) $wpdb->get_results( 'SELECT * FROM ' . sap_table( 'updates' ) . " WHERE $where ORDER BY period DESC, planned_date ASC, id ASC", ARRAY_A ) ); // phpcs:ignore
}

/**
 * One update.
 */
function sap_update( $id ) {
	global $wpdb;
	return sap_update_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'updates' ) . ' WHERE id = %d', $id ), ARRAY_A ) ); // phpcs:ignore
}

/**
 * Insert / update.
 */
function sap_update_save( $data, $id = 0 ) {
	global $wpdb;
	$f = array( 'updated_at' => sap_now() );
	foreach ( array( 'project_id', 'focus_id', 'task_id' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$f[ $k ] = (int) $data[ $k ];
		}
	}
	foreach ( array( 'title' => 250, 'content_type' => 20, 'keyword' => 190, 'priority' => 10, 'period' => 7, 'source' => 20 ) as $k => $max ) {
		if ( isset( $data[ $k ] ) ) {
			$f[ $k ] = mb_substr( sanitize_text_field( (string) $data[ $k ] ), 0, $max );
		}
	}
	if ( isset( $data['status'] ) && array_key_exists( $data['status'], sap_update_statuses() ) ) {
		$f['status']  = $data['status'];
		$f['done_at'] = 'done' === $data['status'] ? sap_now() : null;
	}
	if ( isset( $data['url'] ) ) {
		$f['url'] = esc_url_raw( (string) $data['url'] );
	}
	if ( isset( $data['reason'] ) ) {
		$f['reason'] = sanitize_textarea_field( (string) $data['reason'] );
	}
	if ( array_key_exists( 'planned_date', $data ) ) {
		$f['planned_date'] = $data['planned_date'] ?: null;
	}
	foreach ( array( 'secondary', 'brief' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$f[ $k ] = wp_json_encode( $data[ $k ], JSON_UNESCAPED_UNICODE );
		}
	}
	if ( $id ) {
		$wpdb->update( sap_table( 'updates' ), $f, array( 'id' => (int) $id ) );
		return (int) $id;
	}
	$f['created_at'] = sap_now();
	$wpdb->insert( sap_table( 'updates' ), $f );
	return (int) $wpdb->insert_id;
}

/**
 * Delete one.
 */
function sap_update_delete( $id ) {
	global $wpdb;
	$wpdb->delete( sap_table( 'updates' ), array( 'id' => (int) $id ) );
}

/**
 * Plan a page for update in a month (once per page and month).
 *
 * @return int Update id.
 */
function sap_update_plan( $project, $url, $period, $args = array() ) {
	$k = sap_norm_url( $url );
	foreach ( sap_updates( $project['id'], array( 'period' => $period ) ) as $u ) {
		if ( sap_norm_url( $u['url'] ) === $k ) {
			return $u['id'];
		}
	}
	$m = sap_keymap( $project['id'] )[ $k ] ?? null;
	return sap_update_save( array(
		'project_id'   => $project['id'],
		'focus_id'     => $m ? $m['focus_id'] : 0,
		'url'          => $url,
		'title'        => $m ? ( $m['title'] ?: sap_short_url( $url, 60 ) ) : ( sap_page_title( $project['id'], $url ) ?: sap_short_url( $url, 60 ) ),
		'content_type' => $m ? sap_content_type( $m ) : 'page',
		'keyword'      => $m ? $m['primary_kw'] : '',
		'secondary'    => $m ? $m['secondary'] : array(),
		'reason'       => (string) ( $args['reason'] ?? '' ),
		'brief'        => (array) ( $args['brief'] ?? array() ),
		'priority'     => (string) ( $args['priority'] ?? 'medium' ),
		'status'       => 'planned',
		'period'       => $period,
		'source'       => (string) ( $args['source'] ?? 'manual' ),
	) );
}
