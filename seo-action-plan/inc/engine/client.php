<?php
/**
 * Client portal (Pilot) bridge.
 *
 *  - «نیاز به تأیید کارفرما»: a tick on a task. Ticked tasks are sent to the
 *    client in Pilot before they are done; unticked tasks need no approval.
 *    While the client decides the task waits («در انتظار: تأیید کارفرما»);
 *    approval sends it back to «اقدام بعدی», a revision request is noted on
 *    the task for the team.
 *  - Client requests from Pilot arrive as proposed tasks (source «pilot») in
 *    their own section of the manager's desk («درخواست‌های کارفرما»); approving
 *    and assigning them there is what Pilot shows the client as «در حال انجام».
 *  - Same WordPress: Pilot listens to the actions below and calls
 *    sap_task_client_status(). Another WordPress: the REST API at the end,
 *    authorised with the project's API key (the same key as the report link).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client approval states shown on a task.
 */
function sap_client_statuses() {
	return array(
		''          => array( 'در صف ارسال برای کارفرما', 'is-soft', '🛡' ),
		'pending'   => array( 'منتظر تأیید کارفرما', 'is-warn', '🛡' ),
		'approved'  => array( 'کارفرما تأیید کرد', 'is-good', '✅' ),
		'changes'   => array( 'کارفرما اصلاح خواست', 'is-bad', '✍️' ),
		'withdrawn' => array( 'درخواست تأیید پس گرفته شد', 'is-soft', '↩️' ),
	);
}

/**
 * Chip of a task's client approval ('' when the task needs none).
 */
function sap_client_chip( $t ) {
	if ( empty( $t['needs_client'] ) && '' === (string) $t['client_status'] ) {
		return '';
	}
	$s = sap_client_statuses()[ (string) $t['client_status'] ] ?? sap_client_statuses()[''];
	return '<span class="sap-chip ' . esc_attr( $s[1] ) . '" title="پورتال کارفرما (پایلوت)">' . esc_html( $s[2] . ' ' . $s[0] ) . '</span>';
}

/**
 * Is a task a client request from Pilot?
 */
function sap_is_client_request( $t ) {
	return 'pilot' === (string) ( $t['source'] ?? '' );
}

/**
 * Ticking / unticking «نیاز به تأیید کارفرما» tells the client portal.
 */
add_action( 'sap_task_saved', function ( $before, $after ) {
	if ( ! $after ) {
		return;
	}
	$was = $before ? (int) $before['needs_client'] : 0;
	$now = (int) $after['needs_client'];
	if ( ! $was && $now ) {
		do_action( 'sap_client_approval_requested', $after );
	} elseif ( $was && ! $now && in_array( $after['client_status'], array( '', 'pending', 'changes' ), true ) ) {
		do_action( 'sap_client_approval_withdrawn', $after );
		if ( '' !== $after['client_status'] ) {
			sap_task_client_status( $after['id'], 'withdrawn' ); // Stop waiting for the client.
		}
	}
}, 30, 2 );

/**
 * Set the client's decision (or the portal's state) on a task.
 *
 * @param string $status pending | approved | changes | withdrawn.
 * @return array|WP_Error The task.
 */
function sap_task_client_status( $id, $status, $note = '', $who = '' ) {
	$t = sap_task( (int) $id );
	if ( ! $t ) {
		return new WP_Error( 'sap_task', 'کار یافت نشد.', array( 'status' => 404 ) );
	}
	$status = sanitize_key( (string) $status );
	if ( ! array_key_exists( $status, sap_client_statuses() ) || '' === $status ) {
		return new WP_Error( 'sap_client', 'وضعیت نامعتبر است.', array( 'status' => 400 ) );
	}
	$note = trim( sanitize_textarea_field( (string) $note ) );
	$who  = trim( sanitize_text_field( (string) $who ) );
	$data = array( 'client_status' => $status, 'client_note' => $note );
	$open = ! in_array( $t['status'], array( 'done', 'dropped' ), true );
	if ( 'pending' === $status ) {
		$data['needs_client'] = 1;
		if ( $open ) {
			$data['status']      = 'waiting';
			$data['waiting_for'] = 'تأیید کارفرما (پایلوت)';
		}
		$msg = '🛡 برای تأیید کارفرما در پایلوت ارسال شد.';
	} elseif ( 'approved' === $status ) {
		if ( $open && 'waiting' === $t['status'] ) {
			$data['status']      = 'next';
			$data['waiting_for'] = '';
		}
		$msg = '✅ کارفرما' . ( $who ? ' (' . $who . ')' : '' ) . ' تأیید کرد' . ( $note ? ': «' . $note . '»' : '.' ) . ' کار را انجام دهید.';
	} elseif ( 'changes' === $status ) {
		$msg = '✍️ کارفرما' . ( $who ? ' (' . $who . ')' : '' ) . ' درخواست اصلاح داد: «' . $note . '»';
	} else {
		$data['needs_client'] = 0;
		if ( $open && 'waiting' === $t['status'] ) {
			$data['status']      = 'next';
			$data['waiting_for'] = '';
		}
		$msg = '↩️ درخواست تأیید کارفرما پس گرفته شد.';
	}
	sap_task_save( $data, $t['id'] );
	sap_comment_add( $t['project_id'], 'task', $t['id'], $msg );
	if ( in_array( $status, array( 'approved', 'changes' ), true ) ) {
		$p     = sap_project( $t['project_id'] );
		$title = 'approved' === $status ? '✅ کارفرما «' . $t['title'] . '» را تأیید کرد' : '✍️ کارفرما برای «' . $t['title'] . '» اصلاح خواست';
		$link  = sap_project_url( $t['project_id'], 'plan', array( 'task' => $t['id'] ) ) . '#task-' . $t['id'];
		$users = array_unique( array_filter( array_merge( array( (int) $t['assignee'] ), wp_list_pluck( sap_full_users(), 'ID' ) ) ) );
		foreach ( $users as $uid ) {
			sap_notice_add( $uid, array( 'icon' => 'approved' === $status ? '✅' : '✍️', 'title' => $title, 'text' => ( $p['name'] ?? '' ) . ( $note ? ' · ' . $note : '' ), 'url' => $link ) );
		}
		if ( 'changes' === $status ) {
			sap_notify_full( $title, array(
				'title'  => 'کارفرما درخواست اصلاح داد',
				'badge'  => '✍️ پایلوت · ' . ( $p['name'] ?? '' ),
				'tone'   => '#D97706',
				'intro'  => 'کارفرما پیش از اجرای این کار، اصلاحی خواسته است. نسخه اصلاح‌شده را از پایلوت دوباره برای تأیید بفرستید.',
				'rows'   => array( 'کار' => $t['title'], 'نظر کارفرما' => $note, 'کارفرما' => $who ),
				'button' => array( 'دیدن کار در سکان', $link ),
			) );
		}
	}
	return sap_task( $t['id'] );
}

/**
 * Create a client request (proposed task, source «pilot») on the desk.
 *
 * @return int|WP_Error Task id.
 */
function sap_client_request_add( $project_id, $data ) {
	$p = sap_project( (int) $project_id );
	if ( ! $p ) {
		return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
	}
	$title = trim( sanitize_text_field( (string) ( $data['title'] ?? '' ) ) );
	if ( '' === $title ) {
		return new WP_Error( 'sap_title', 'عنوان درخواست الزامی است.', array( 'status' => 400 ) );
	}
	$ctx = sanitize_key( (string) ( $data['context'] ?? 'management' ) );
	$id  = sap_task_save( array(
		'project_id'  => $p['id'],
		'title'       => 0 === strpos( $title, 'درخواست کارفرما' ) ? $title : 'درخواست کارفرما: ' . $title,
		'notes'       => (string) ( $data['notes'] ?? '' ),
		'context'     => array_key_exists( $ctx, sap_contexts_all() ) ? $ctx : 'management',
		'status'      => 'proposed',
		'period'      => sap_period_current(),
		'source'      => 'pilot',
		'url'         => (string) ( $data['url'] ?? '' ),
		'client_link' => (string) ( $data['client_link'] ?? '' ),
		'client_note' => trim( (string) ( $data['client_name'] ?? '' ) ),
		'created_by'  => absint( $data['created_by'] ?? 0 ),
	) );
	return $id ? (int) $id : new WP_Error( 'sap_task', 'ثبت درخواست ناموفق بود.', array( 'status' => 500 ) );
}

/**
 * What a remote Pilot needs: the state of given tasks, open tasks (for the
 * approval tick list) and tasks ticked for the client but not sent yet.
 */
function sap_client_sync_payload( $project, $ids = array() ) {
	$row = function ( $t ) {
		return array(
			'id'            => $t['id'],
			'title'         => $t['title'],
			'url'           => $t['url'],
			'status'        => $t['status'],
			'status_label'  => sap_task_statuses()[ $t['status'] ] ?? $t['status'],
			'assignee'      => (int) $t['assignee'],
			'assignee_name' => sap_user_name( $t['assignee'] ),
			'outcome'       => $t['outcome'],
			'needs_client'  => (int) $t['needs_client'],
			'client_status' => $t['client_status'],
			'done_at'       => (string) $t['done_at'],
		);
	};
	$tasks = array();
	foreach ( array_slice( array_unique( array_map( 'absint', (array) $ids ) ), 0, 200 ) as $id ) {
		$t = $id ? sap_task( $id ) : null;
		if ( $t && (int) $t['project_id'] === (int) $project['id'] ) {
			$tasks[] = $row( $t );
		}
	}
	$open = array_map( $row, sap_tasks( array( 'project_id' => $project['id'], 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) ) );
	return array(
		'sokan'    => SAP_VERSION,
		'desk_url' => sap_url( 'desk' ) . '#client',
		'tasks'    => $tasks,
		'open'     => $open,
		'to_send'  => array_values( array_filter( $open, function ( $t ) {
			return $t['needs_client'] && '' === $t['client_status'];
		} ) ),
	);
}

add_action( 'rest_api_init', function () {
	$ns   = 'seoactionplan/v1';
	$perm = 'sap_rest_key_permission';
	$task = function ( WP_REST_Request $req ) {
		$t = sap_task( (int) $req['id'] );
		return $t && (int) $t['project_id'] === (int) $req['project'] ? $t : null;
	};

	// Pilot → a client request lands on the desk.
	register_rest_route( $ns, '/client/(?P<project>\d+)/request', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) {
			$j  = (array) $req->get_json_params();
			$id = sap_client_request_add( (int) $req['project'], $j );
			return is_wp_error( $id ) ? $id : array( 'ok' => true, 'id' => $id, 'desk_url' => sap_url( 'desk' ) . '#client' );
		},
	) );

	// Pilot ← states of its tasks, the open plan and tasks ticked for approval.
	register_rest_route( $ns, '/client/(?P<project>\d+)/sync', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) $req->get_param( 'ids' ) ) ) );
			return sap_client_sync_payload( sap_project( (int) $req['project'] ), $ids );
		},
	) );

	// Pilot → tick «نیاز به تأیید کارفرما» from the portal console.
	register_rest_route( $ns, '/client/(?P<project>\d+)/task/(?P<id>\d+)/flag', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) use ( $task ) {
			$t = $task( $req );
			if ( ! $t ) {
				return new WP_Error( 'sap_task', 'کار یافت نشد.', array( 'status' => 404 ) );
			}
			sap_task_save( array( 'needs_client' => $req->get_param( 'needs_client' ) ? 1 : 0 ), $t['id'] );
			return array( 'ok' => true );
		},
	) );

	// Pilot → the client's decision (or pending / withdrawn).
	register_rest_route( $ns, '/client/(?P<project>\d+)/task/(?P<id>\d+)/approval', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) use ( $task ) {
			if ( ! $task( $req ) ) {
				return new WP_Error( 'sap_task', 'کار یافت نشد.', array( 'status' => 404 ) );
			}
			$r = sap_task_client_status( (int) $req['id'], (string) $req->get_param( 'status' ), (string) $req->get_param( 'note' ), (string) $req->get_param( 'who' ) );
			return is_wp_error( $r ) ? $r : array( 'ok' => true, 'status' => $r['status'], 'client_status' => $r['client_status'] );
		},
	) );
} );
