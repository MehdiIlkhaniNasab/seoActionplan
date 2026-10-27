<?php
/**
 * Content ideas and the content calendar.
 *
 * An idea is a proposed article: main keyword, secondary (co-concept) keywords,
 * title, goal, brief, outline and five internal links. Approved ideas are placed
 * in a month of the content calendar; the calendar is written to the project's
 * Google Sheet in the agency's "Blog" layout (monthly blocks M1, M2, …).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status labels.
 */
function sap_idea_statuses() {
	return array(
		'new'       => 'پیشنهاد جدید',
		'approved'  => 'تأیید شده (بدون زمان)',
		'scheduled' => 'در تقویم',
		'published' => 'منتشر شده',
		'rejected'  => 'رد شده',
	);
}

/**
 * Hydrate a row.
 */
function sap_idea_hydrate( $row ) {
	if ( ! $row ) {
		return null;
	}
	$row = (array) $row;
	foreach ( array( 'id', 'project_id', 'focus_id', 'volume', 'kp', 'slot', 'task_id' ) as $k ) {
		$row[ $k ] = (int) $row[ $k ];
	}
	foreach ( array( 'secondary', 'links', 'outline', 'evidence' ) as $k ) {
		$v         = json_decode( (string) $row[ $k ], true );
		$row[ $k ] = is_array( $v ) ? $v : array();
	}
	foreach ( array( 'brief', 'goal', 'source_note', 'published_url' ) as $k ) {
		$row[ $k ] = (string) $row[ $k ];
	}
	return $row;
}

/**
 * Ideas of a project.
 *
 * @param array $args status (string|array), period, limit.
 */
function sap_ideas( $project_id, $args = array() ) {
	global $wpdb;
	$args  = wp_parse_args( $args, array( 'status' => '', 'period' => null, 'limit' => 1000 ) );
	$where = $wpdb->prepare( 'project_id = %d', $project_id );
	if ( $args['status'] ) {
		$st     = array_map( 'sanitize_key', (array) $args['status'] );
		$where .= " AND status IN ('" . implode( "','", $st ) . "')";
	}
	if ( null !== $args['period'] ) {
		$where .= $wpdb->prepare( ' AND period = %s', $args['period'] );
	}
	$rows = $wpdb->get_results( 'SELECT * FROM ' . sap_table( 'ideas' ) . " WHERE $where ORDER BY period DESC, slot ASC, id DESC LIMIT " . (int) $args['limit'] ); // phpcs:ignore
	return array_map( 'sap_idea_hydrate', (array) $rows );
}

/**
 * One idea.
 */
function sap_idea( $id ) {
	global $wpdb;
	return sap_idea_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'ideas' ) . ' WHERE id = %d', $id ) ) ); // phpcs:ignore
}

/**
 * Insert or update.
 */
function sap_idea_save( $data, $id = 0 ) {
	global $wpdb;
	$fields = array( 'updated_at' => sap_now() );
	foreach ( array( 'project_id', 'focus_id', 'volume', 'kp', 'slot', 'task_id' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$fields[ $k ] = (int) $data[ $k ];
		}
	}
	foreach ( array( 'keyword' => 190, 'title' => 250, 'intent' => 20, 'cluster' => 190, 'priority' => 10, 'origin' => 10, 'status' => 20, 'period' => 7 ) as $k => $max ) {
		if ( isset( $data[ $k ] ) ) {
			$fields[ $k ] = mb_substr( sanitize_text_field( (string) $data[ $k ] ), 0, $max );
		}
	}
	foreach ( array( 'brief', 'goal', 'source_note' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$fields[ $k ] = sanitize_textarea_field( (string) $data[ $k ] );
		}
	}
	if ( isset( $data['published_url'] ) ) {
		$fields['published_url'] = esc_url_raw( (string) $data['published_url'] );
	}
	if ( array_key_exists( 'planned_date', $data ) ) {
		$fields['planned_date'] = $data['planned_date'] ?: null;
	}
	foreach ( array( 'secondary', 'links', 'outline', 'evidence' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$fields[ $k ] = wp_json_encode( array_values( (array) $data[ $k ] ), JSON_UNESCAPED_UNICODE );
		}
	}
	if ( $id ) {
		$wpdb->update( sap_table( 'ideas' ), $fields, array( 'id' => (int) $id ) );
		return (int) $id;
	}
	$fields['created_at'] = sap_now();
	$wpdb->insert( sap_table( 'ideas' ), $fields );
	return (int) $wpdb->insert_id;
}

/**
 * Delete an idea.
 */
function sap_idea_delete( $id ) {
	global $wpdb;
	sap_comments_purge( 'idea', $id );
	$wpdb->delete( sap_table( 'ideas' ), array( 'id' => (int) $id ) );
}

/**
 * Secondary keywords as [[keyword, volume], …] whatever the stored shape.
 */
function sap_idea_secondary( $idea ) {
	$out = array();
	foreach ( (array) $idea['secondary'] as $s ) {
		if ( is_array( $s ) ) {
			$out[] = array( (string) ( $s[0] ?? $s['keyword'] ?? '' ), (int) ( $s[1] ?? $s['volume'] ?? 0 ) );
		} elseif ( '' !== trim( (string) $s ) ) {
			$out[] = array( (string) $s, 0 );
		}
	}
	return array_values( array_filter( $out, function ( $s ) {
		return '' !== $s[0];
	} ) );
}
