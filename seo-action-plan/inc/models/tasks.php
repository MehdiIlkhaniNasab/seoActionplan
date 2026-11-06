<?php
/**
 * Tasks (GTD actions) of the monthly action plan.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * GTD statuses in board order.
 */
function sap_task_statuses() {
	return array(
		'inbox'   => 'صندوق ورودی',
		'next'    => 'اقدام بعدی',
		'doing'   => 'در حال انجام',
		'waiting' => 'در انتظار (Waiting For)',
		'someday' => 'شاید بعداً',
		'review'  => 'در انتظار تأیید مدیر',
		'done'    => 'انجام شد',
		'dropped' => 'حذف شد',
		'proposed' => 'پیشنهاد (منتظر تأیید)',
	);
}

/**
 * Hydrate a row.
 */
function sap_task_hydrate( $row ) {
	if ( ! $row ) {
		return null;
	}
	$row               = (array) $row;
	$row['id']         = (int) $row['id'];
	$row['project_id'] = (int) $row['project_id'];
	$row['focus_id']   = (int) $row['focus_id'];
	$row['finding_id'] = (int) $row['finding_id'];
	$row['assignee']   = (int) $row['assignee'];
	$row['effort']     = (float) $row['effort'];
	$row['score']      = (float) $row['score'];
	$row['spent']      = (float) ( $row['spent'] ?? 0 );
	$row['created_by'] = (int) ( $row['created_by'] ?? 0 );
	$row['review_note'] = (string) ( $row['review_note'] ?? '' );
	$row['submitted_at'] = (string) ( $row['submitted_at'] ?? '' );
	$row['late_alert']   = (string) ( $row['late_alert'] ?? '' );
	$att                 = json_decode( (string) ( $row['attachments'] ?? '' ), true );
	$row['attachments']  = is_array( $att ) ? $att : array();
	$chk                 = json_decode( (string) ( $row['checklist'] ?? '' ), true );
	$row['checklist']    = is_array( $chk ) ? sap_checklist_clean( $chk ) : array();
	$prg                 = json_decode( (string) ( $row['progress'] ?? '' ), true );
	$row['progress']     = is_array( $prg ) ? $prg : array();
	$row['started_at']   = (string) ( $row['started_at'] ?? '' );
	$row['repeat_every'] = (string) ( $row['repeat_every'] ?? '' );
	$row['repeat_next']  = (int) ( $row['repeat_next'] ?? 0 );
	foreach ( array( 'notes', 'result', 'url', 'waiting_for', 'keyword', 'period', 'recurring', 'source', 'ai_ref', 'ai_output', 'outcome' ) as $k ) {
		$row[ $k ] = (string) ( $row[ $k ] ?? '' );
	}
	return $row;
}

/**
 * One task.
 */
function sap_task( $id ) {
	global $wpdb;
	return sap_task_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'tasks' ) . ' WHERE id = %d', $id ) ) ); // phpcs:ignore
}

/**
 * Query tasks.
 *
 * @param array $args project_id, period, status (string|array), assignee, focus_id, context.
 */
function sap_tasks( $args = array() ) {
	global $wpdb;
	$w = array( '1=1' );
	if ( ! empty( $args['project_id'] ) ) {
		$w[] = $wpdb->prepare( 'project_id = %d', $args['project_id'] );
	}
	if ( ! empty( $args['period'] ) ) {
		$w[] = $wpdb->prepare( 'period = %s', $args['period'] );
	}
	if ( ! empty( $args['status'] ) ) {
		$st  = (array) $args['status'];
		$w[] = 'status IN (' . implode( ',', array_map( function ( $s ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $s );
		}, $st ) ) . ')';
	}
	if ( ! empty( $args['assignee'] ) ) {
		$w[] = $wpdb->prepare( 'assignee = %d', $args['assignee'] );
	}
	if ( isset( $args['focus_id'] ) && '' !== $args['focus_id'] && null !== $args['focus_id'] ) {
		$w[] = $wpdb->prepare( 'focus_id = %d', $args['focus_id'] );
	}
	if ( ! empty( $args['context'] ) ) {
		$w[] = $wpdb->prepare( 'context = %s', $args['context'] );
	}
	$rows = $wpdb->get_results( 'SELECT * FROM ' . sap_table( 'tasks' ) . ' WHERE ' . implode( ' AND ', $w ) . ' ORDER BY score DESC, id ASC' ); // phpcs:ignore
	return array_map( 'sap_task_hydrate', (array) $rows );
}

/**
 * Insert or update a task. Handles the done ⇄ activity-log link.
 */
function sap_task_save( $data, $id = 0 ) {
	global $wpdb;
	$table  = sap_table( 'tasks' );
	$before = $id ? sap_task( $id ) : null;
	$fields = array();
	$map    = array(
		'project_id'  => 'absint',
		'focus_id'    => 'absint',
		'finding_id'  => 'absint',
		'assignee'    => 'absint',
		'title'       => 'sanitize_text_field',
		'notes'       => 'sanitize_textarea_field',
		'result'      => 'sanitize_textarea_field',
		'waiting_for' => 'sanitize_text_field',
		'url'         => 'esc_url_raw',
		'keyword'     => 'sanitize_text_field',
		'recurring'   => 'sanitize_key',
		'source'      => 'sanitize_key',
		'ai_ref'      => 'sanitize_key',
		'ai_output'   => 'sanitize_textarea_field',
		'outcome'     => 'sanitize_textarea_field',
		'review_note' => 'sanitize_textarea_field',
		'created_by'  => 'absint',
	);
	foreach ( $map as $k => $fn ) {
		if ( array_key_exists( $k, $data ) ) {
			$fields[ $k ] = call_user_func( $fn, $data[ $k ] );
		}
	}
	if ( array_key_exists( 'attachments', $data ) ) {
		$fields['attachments'] = wp_json_encode( array_values( array_filter( (array) $data['attachments'], 'is_array' ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
	if ( array_key_exists( 'checklist', $data ) ) {
		$items                = is_array( $data['checklist'] ) ? sap_checklist_clean( $data['checklist'] ) : sap_checklist_from_text( (string) $data['checklist'], $before['checklist'] ?? array() );
		$fields['checklist']  = $items ? wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) : null;
	}
	if ( array_key_exists( 'progress', $data ) ) {
		$fields['progress'] = $data['progress'] ? wp_json_encode( array_values( (array) $data['progress'] ), JSON_UNESCAPED_UNICODE ) : null;
	}
	foreach ( array( 'submitted_at', 'late_alert', 'started_at' ) as $dk ) {
		if ( array_key_exists( $dk, $data ) ) {
			$fields[ $dk ] = $data[ $dk ] ?: null;
		}
	}
	if ( array_key_exists( 'spent', $data ) ) {
		$fields['spent'] = max( 0, round( (float) sap_norm_text( (string) $data['spent'] ), 2 ) );
	}
	if ( array_key_exists( 'context', $data ) ) {
		$fields['context'] = array_key_exists( (string) $data['context'], sap_contexts_all() ) ? (string) $data['context'] : 'management';
	}
	if ( array_key_exists( 'status', $data ) ) {
		$fields['status'] = array_key_exists( $data['status'], sap_task_statuses() ) ? $data['status'] : 'inbox';
	}
	if ( array_key_exists( 'period', $data ) ) {
		$fields['period'] = '' === $data['period'] ? '' : sap_period_sanitize( $data['period'] );
	}
	if ( array_key_exists( 'due_date', $data ) ) {
		$fields['due_date'] = sap_no_friday( sap_parse_date( $data['due_date'] ) ) ?: null; // Friday is the weekend: Saturday instead.
	}
	if ( array_key_exists( 'repeat_every', $data ) ) {
		$fields['repeat_every'] = array_key_exists( (string) $data['repeat_every'], sap_repeat_options() ) ? (string) $data['repeat_every'] : '';
	}
	if ( array_key_exists( 'repeat_next', $data ) ) {
		$fields['repeat_next'] = absint( $data['repeat_next'] );
	}
	if ( array_key_exists( 'effort', $data ) ) {
		$fields['effort'] = max( 0.25, (float) sap_norm_text( $data['effort'] ) );
	}
	if ( array_key_exists( 'score', $data ) ) {
		$fields['score'] = (float) $data['score'];
	}
	$fields['updated_at'] = sap_now();
	// Work started: the clock of «how many days has this been open».
	if ( isset( $fields['status'] ) && in_array( $fields['status'], array( 'doing', 'waiting', 'review', 'done' ), true ) && ( ! $before || '' === $before['started_at'] ) && ! isset( $fields['started_at'] ) ) {
		$fields['started_at'] = sap_now();
	}
	if ( isset( $fields['status'] ) ) {
		$was_done = $before && 'done' === $before['status'];
		if ( 'done' === $fields['status'] && ! $was_done ) {
			$fields['done_at'] = sap_now();
		} elseif ( 'done' !== $fields['status'] && $was_done ) {
			$fields['done_at'] = null;
		}
	}
	if ( $id ) {
		$wpdb->update( $table, $fields, array( 'id' => (int) $id ) );
	} else {
		$fields['created_at'] = sap_now();
		if ( ! isset( $fields['created_by'] ) ) {
			$fields['created_by'] = get_current_user_id();
		}
		if ( ! array_key_exists( 'period', $data ) ) {
			$fields['period'] = sap_period_current(); // Visible in this month's plan.
		}
		$wpdb->insert( $table, $fields );
		$id = (int) $wpdb->insert_id;
	}
	$after = sap_task( $id );
	sap_task_sync_activity( $before, $after );
	do_action( 'sap_task_saved', $before, $after );
	return (int) $id;
}

/**
 * Keep the activity log in step with task completion.
 */
function sap_task_sync_activity( $before, $after ) {
	if ( ! $after ) {
		return;
	}
	$was = $before && 'done' === $before['status'];
	$is  = 'done' === $after['status'];
	if ( $is ) {
		sap_activity_upsert_ref( $after['project_id'], 'task:' . $after['id'], array(
			'focus_id'    => $after['focus_id'],
			'task_id'     => $after['id'],
			'category'    => $after['context'],
			'action'      => 'task_done',
			'title'       => $after['title'],
			'url'         => $after['url'],
			'after_value' => $after['result'],
			'source'      => 'task',
			'user_id'     => $after['assignee'] ?: get_current_user_id(),
			'happened_at' => $after['done_at'] ?: sap_now(),
		), ! $was );
		if ( $after['finding_id'] && ! $was ) {
			sap_finding_set_status( $after['finding_id'], 'resolved' );
		}
	} elseif ( $was ) {
		sap_activity_delete_ref( $after['project_id'], 'task:' . $after['id'] );
		if ( $after['finding_id'] ) {
			sap_finding_set_status( $after['finding_id'], 'planned' );
		}
	}
}

/**
 * Delete a task (its finding goes back to the inbox).
 */
function sap_task_delete( $id ) {
	global $wpdb;
	$t = sap_task( $id );
	if ( ! $t ) {
		return;
	}
	if ( $t['finding_id'] ) {
		sap_finding_set_status( $t['finding_id'], 'open', 0 );
	}
	sap_activity_delete_ref( $t['project_id'], 'task:' . $t['id'] );
	sap_comments_purge( 'task', $t['id'] );
	$wpdb->delete( sap_table( 'tasks' ), array( 'id' => (int) $id ) );
}

/**
 * Users who can be assigned tasks.
 */
function sap_team_users() {
	static $users = null;
	if ( null === $users ) {
		$by = array();
		foreach ( get_users( array( 'capability__in' => array( SAP_CAP, SAP_CAP_WORK, 'manage_options' ), 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name' ) ) as $u ) {
			$by[ (int) $u->ID ] = $u;
		}
		// People given a Sokan role while their WordPress role has no Sokan capability.
		foreach ( get_users( array( 'meta_key' => 'sap_role', 'meta_compare' => 'EXISTS', 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
			$by[ (int) $u->ID ] = $u;
		}
		$by = array_filter( $by, function ( $u ) {
			return '' !== sap_user_role( $u->ID );
		} );
		uasort( $by, function ( $a, $b ) {
			return strcmp( $a->display_name, $b->display_name );
		} );
		$users = array_values( $by );
	}
	return $users;
}

/**
 * Display name of a user id.
 */
function sap_user_name( $id ) {
	if ( ! $id ) {
		return '';
	}
	$u = get_userdata( (int) $id );
	return $u ? $u->display_name : '';
}
