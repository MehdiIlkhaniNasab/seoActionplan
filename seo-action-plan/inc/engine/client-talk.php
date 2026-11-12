<?php
/**
 * The conversation with the client lives on the task, here in Sokan.
 *
 * The client writes in Pilot (or its bot), the message is stored on the task
 * (comment type «client») and the person on the task, the coordinator and the
 * SEO managers are told. The team answers on the task card or on the desk;
 * Pilot shows the same thread to the client and tells them. Nothing of the
 * conversation is kept in Pilot, so nothing is missed on the team's side.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'sap_comment_types', function ( $types ) {
	$types[] = 'client';
	return $types;
} );

/**
 * The thread of a task, oldest first.
 *
 * @return array[] {id, at, side: client|team, who, role, avatar, text}
 */
function sap_client_messages( $task_id ) {
	$out = array();
	foreach ( sap_comments( 'client', (int) $task_id ) as $c ) {
		$client = '' !== (string) ( $c['author'] ?? '' );
		$m      = $client ? null : sap_member( $c['user_id'] );
		$out[]  = array(
			'id'     => (int) $c['id'],
			'at'     => (string) $c['created_at'],
			'side'   => $client ? 'client' : 'team',
			'who'    => $client ? (string) $c['author'] : ( $m ? $m['name'] : sap_user_name( $c['user_id'] ) ),
			'role'   => $client ? 'کارفرما' : ( $m ? ( $m['title'] ?: ( 'coord' === $m['role'] ? 'هماهنگ‌کننده' : ( $m['manager'] ? 'مدیر سئو' : 'تیم' ) ) ) : 'تیم' ),
			'avatar' => $client ? '' : get_avatar_url( $c['user_id'], array( 'size' => 64 ) ),
			'text'   => (string) $c['body'],
		);
	}
	return $out;
}

/**
 * Message counts of many tasks.
 */
function sap_client_message_counts( $ids ) {
	return function_exists( 'sap_comment_counts' ) ? sap_comment_counts( 'client', $ids ) : array();
}

/**
 * The people who must read a client's message on a task.
 */
function sap_client_listeners( $t ) {
	$ids = array( (int) $t['assignee'], (int) $t['coord_id'], (int) sap_client_dispatcher( $t['project_id'] ) );
	foreach ( sap_full_users() as $u ) {
		$ids[] = (int) $u->ID;
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Add a message to a task's thread.
 *
 * @param string $side client | team.
 * @param string $who  The client's name (client side).
 * @return array|WP_Error The message.
 */
function sap_client_message_add( $task_id, $text, $side = 'team', $who = '' ) {
	global $wpdb;
	$t = sap_task( (int) $task_id );
	if ( ! $t ) {
		return new WP_Error( 'sap_task', 'کار یافت نشد.', array( 'status' => 404 ) );
	}
	$text = trim( sanitize_textarea_field( (string) $text ) );
	if ( '' === $text ) {
		return new WP_Error( 'sap_msg', 'متن پیام خالی است.', array( 'status' => 400 ) );
	}
	$client = 'client' === $side;
	$who    = $client ? ( trim( sanitize_text_field( (string) $who ) ) ?: 'کارفرما' ) : '';
	$wpdb->insert( sap_table( 'comments' ), array(
		'project_id'  => (int) $t['project_id'],
		'object_type' => 'client',
		'object_id'   => (int) $t['id'],
		'user_id'     => $client ? 0 : get_current_user_id(),
		'author'      => mb_substr( $who, 0, 120 ),
		'body'        => mb_substr( $text, 0, 4000 ),
		'created_at'  => sap_now(),
	) );
	$id = (int) $wpdb->insert_id;
	if ( ! $id ) {
		return new WP_Error( 'sap_msg', 'پیام ذخیره نشد.', array( 'status' => 500 ) );
	}
	$msg   = null;
	foreach ( sap_client_messages( $t['id'] ) as $m ) {
		if ( $m['id'] === $id ) {
			$msg = $m;
		}
	}
	$title = preg_replace( '/^(🚨\s*)?(درخواست کارفرما:\s*)?/u', '', (string) $t['title'] );
	if ( $client ) {
		$p    = sap_project( $t['project_id'] );
		$link = 'proposed' === $t['status'] ? sap_url( 'desk' ) . '#client' : sap_project_url( $t['project_id'], 'plan', array( 'task' => $t['id'] ) ) . '#task-' . $t['id'];
		foreach ( sap_client_listeners( $t ) as $uid ) {
			sap_notice_add( $uid, array( 'icon' => '💬', 'title' => $who . ' درباره «' . $title . '» نوشت', 'text' => ( $p['name'] ?? '' ) . ' · ' . mb_substr( $text, 0, 140 ), 'url' => $link ) );
		}
		if ( $t['assignee'] && 'proposed' !== $t['status'] ) {
			$m = sap_member( $t['assignee'] );
			$u = get_userdata( $t['assignee'] );
			if ( $m && $u && ! empty( $m['notify_email'] ) ) {
				sap_notify_email( $u->user_email, 'پیام کارفرما: ' . $title, array(
					'title'  => 'کارفرما پیام داد',
					'badge'  => '💬 ' . ( $p['name'] ?? '' ),
					'tone'   => '#2563EB',
					'intro'  => 'کارفرما روی کاری که دست شماست پیام گذاشته است. جواب را روی همان کار در سکان بنویسید؛ کارفرما در پایلوت می‌بیند.',
					'rows'   => array( 'کار' => $title, 'پیام' => $text, 'کارفرما' => $who ),
					'button' => array( 'جواب در سکان', $link ),
				) );
			}
		}
	}
	/**
	 * A message was added to a task's client thread (Pilot on the same
	 * WordPress tells the client about the team's answers).
	 */
	do_action( 'sap_client_message', $t, $msg );
	return $msg;
}

/**
 * Can the current user talk to the client on this task?
 */
function sap_client_can_talk( $t ) {
	if ( ! $t || ! sap_can_work() ) {
		return false;
	}
	if ( sap_can() ) {
		return true;
	}
	$me = get_current_user_id();
	return in_array( $me, array( (int) $t['assignee'], (int) $t['coord_id'], (int) $t['created_by'] ), true ) || isset( sap_project_members( $t['project_id'] )[ $me ] );
}

/**
 * Approve a client's request on the desk: who does it, by when, how many
 * hours, how urgent; an optional word to the client goes into the thread.
 *
 * @return array|WP_Error
 */
function sap_client_request_approve( $task_id, $data ) {
	$t = sap_task( (int) $task_id );
	if ( ! $t || 'proposed' !== $t['status'] ) {
		return new WP_Error( 'sap_task', 'درخواست پیدا نشد یا قبلاً بررسی شده است.', array( 'status' => 404 ) );
	}
	$assignee = absint( $data['assignee'] ?? 0 );
	if ( ! $assignee ) {
		return new WP_Error( 'sap_assignee', 'مسئول کار را انتخاب کنید.', array( 'status' => 400 ) );
	}
	$title  = trim( sanitize_text_field( (string) ( $data['title'] ?? '' ) ) );
	$urgent = ! empty( $data['urgent'] );
	$clean  = '' !== $title ? preg_replace( '/^(🚨\s*)?(درخواست کارفرما:\s*)?/u', '', $title ) : preg_replace( '/^(🚨\s*)?(درخواست کارفرما:\s*)?/u', '', (string) $t['title'] );
	$save   = array(
		'status'       => 'next',
		'assignee'     => $assignee,
		'title'        => ( $urgent ? '🚨 ' : '' ) . 'درخواست کارفرما: ' . $clean,
		'score'        => $urgent ? 999 : max( 50, (int) $t['score'] < 999 ? (int) $t['score'] : 50 ),
		'delegated_at' => sap_now(),
		'coord_id'     => (int) $t['coord_id'] ?: get_current_user_id(),
	);
	$due = sap_parse_date( (string) ( $data['due_date'] ?? '' ) );
	if ( $due ) {
		$save['due_date'] = $due;
	} elseif ( $urgent ) {
		$save['due_date'] = current_time( 'Y-m-d' );
	}
	if ( isset( $data['effort'] ) && '' !== (string) $data['effort'] ) {
		$save['effort'] = max( 0.25, min( 200, (float) $data['effort'] ) );
	}
	if ( isset( $data['context'] ) && array_key_exists( sanitize_key( (string) $data['context'] ), sap_contexts_all() ) ) {
		$save['context'] = sanitize_key( (string) $data['context'] );
	}
	$list             = (array) $t['progress'];
	$list[]           = array( 'u' => get_current_user_id(), 'at' => sap_now(), 'text' => '✅ ' . sap_user_name( get_current_user_id() ) . ' درخواست را تأیید کرد و به ' . sap_user_name( $assignee ) . ' سپرد' . ( ! empty( $save['due_date'] ) ? ' · موعد ' . sap_jdate( $save['due_date'] ) : '' ), 'state' => 'progress', 'why' => '', 'kind' => 'delegate' );
	$save['progress'] = array_slice( $list, -200 );
	sap_task_save( $save, $t['id'] );
	$note = trim( (string) ( $data['note'] ?? '' ) );
	if ( '' !== $note ) {
		sap_client_message_add( $t['id'], $note, 'team' );
	}
	return array( 'ok' => true, 'msg' => 'تأیید شد و به ' . sap_user_name( $assignee ) . ' سپرده شد. کارفرما خبردار می‌شود.' );
}

/**
 * Decline a client's request: the reason is written to the client.
 *
 * @return array|WP_Error
 */
function sap_client_request_decline( $task_id, $note ) {
	$t = sap_task( (int) $task_id );
	if ( ! $t || 'proposed' !== $t['status'] ) {
		return new WP_Error( 'sap_task', 'درخواست پیدا نشد یا قبلاً بررسی شده است.', array( 'status' => 404 ) );
	}
	$note = trim( (string) $note );
	if ( '' === $note ) {
		return new WP_Error( 'sap_note', 'دلیل را برای کارفرما بنویسید؛ همین متن در پایلوت به او نشان داده می‌شود.', array( 'status' => 400 ) );
	}
	sap_client_message_add( $t['id'], $note, 'team' );
	sap_task_save( array( 'status' => 'dropped', 'outcome' => $note ), $t['id'] );
	return array( 'ok' => true, 'msg' => 'درخواست بسته شد و دلیلش برای کارفرما نوشته شد.' );
}

add_action( 'rest_api_init', function () {
	$ns = 'seoactionplan/v1';

	// Team side (logged-in Sokan users).
	register_rest_route( $ns, '/client-talk/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task( (int) $req['id'] );
			if ( ! sap_client_can_talk( $t ) ) {
				return new WP_Error( 'sap_forbidden', 'فقط اعضای همین پروژه می‌توانند با کارفرما گفتگو کنند.', array( 'status' => 403 ) );
			}
			$m = sap_client_message_add( $t['id'], (string) $req->get_param( 'text' ), 'team' );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			ob_start();
			sap_view( 'client-msg', array( 'm' => $m ) );
			return array( 'ok' => true, 'html' => ob_get_clean(), 'msg' => 'پیام برای کارفرما فرستاده شد.' );
		},
	) );
	register_rest_route( $ns, '/client-request/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			if ( 'decline' === $req->get_param( 'do' ) ) {
				return sap_client_request_decline( (int) $req['id'], (string) $req->get_param( 'note' ) );
			}
			return sap_client_request_approve( (int) $req['id'], $req->get_params() );
		},
	) );

	// Pilot side (project API key).
	register_rest_route( $ns, '/client/(?P<project>\d+)/task/(?P<id>\d+)/message', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_rest_key_permission',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task( (int) $req['id'] );
			if ( ! $t || (int) $t['project_id'] !== (int) $req['project'] ) {
				return new WP_Error( 'sap_task', 'کار یافت نشد.', array( 'status' => 404 ) );
			}
			return sap_client_message_add( $t['id'], (string) $req->get_param( 'text' ), 'client', (string) $req->get_param( 'who' ) );
		},
	) );
} );

/**
 * People the SEO manager can hand a client's request to: the team, every
 * member of the project and the person the client suggested.
 *
 * @return WP_User[]|object[] id => user (ID, display_name)
 */
function sap_client_assignable( $project_id, $extra = 0 ) {
	$by = array();
	foreach ( sap_team_users() as $u ) {
		$by[ (int) $u->ID ] = $u;
	}
	foreach ( array_merge( array_keys( sap_project_members( $project_id ) ), array( (int) $extra ) ) as $uid ) {
		$uid = (int) $uid;
		if ( $uid && ! isset( $by[ $uid ] ) && ( $u = get_userdata( $uid ) ) ) {
			$by[ $uid ] = $u;
		}
	}
	return $by;
}
