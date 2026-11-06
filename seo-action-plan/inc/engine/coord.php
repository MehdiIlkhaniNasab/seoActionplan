<?php
/**
 * Coordinator («هماهنگ‌کننده و پیگیری»): many tasks land on this person, most of
 * them to hand over to the right member and follow up. Their desk turns that into
 * a quick routine: a triage queue (delegate or keep), a plan for their own tasks
 * with a start date chosen against their workload, and a follow-up board of
 * everything they delegated — with reminders, follow-up notes and an optional
 * automatic daily reminder.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Open statuses a coordinator works with.
 */
function sap_coord_open_statuses() {
	return array( 'inbox', 'next', 'doing', 'waiting' );
}

/**
 * Tasks waiting for a decision: assigned to the coordinator, not delegated and not planned yet.
 */
function sap_coord_triage( $uid ) {
	$out = array_values( array_filter( sap_tasks( array( 'assignee' => $uid, 'status' => sap_coord_open_statuses() ) ), function ( $t ) {
		return ! $t['coord_id'] && '' === $t['start_date'];
	} ) );
	usort( $out, function ( $a, $b ) {
		return array( (string) ( $a['due_date'] ?: '9999' ), $a['id'] ) <=> array( (string) ( $b['due_date'] ?: '9999' ), $b['id'] );
	} );
	return $out;
}

/**
 * The coordinator's own planned tasks (start date chosen), by start date.
 */
function sap_coord_mine( $uid ) {
	$out = array_values( array_filter( sap_tasks( array( 'assignee' => $uid, 'status' => array_merge( sap_coord_open_statuses(), array( 'review' ) ) ) ), function ( $t ) {
		return $t['coord_id'] || '' !== $t['start_date'];
	} ) );
	usort( $out, function ( $a, $b ) {
		return array( (string) ( $a['start_date'] ?: $a['due_date'] ?: '9999' ), (string) ( $a['due_date'] ?: '9999' ) ) <=> array( (string) ( $b['start_date'] ?: $b['due_date'] ?: '9999' ), (string) ( $b['due_date'] ?: '9999' ) );
	} );
	return $out;
}

/**
 * Tasks the coordinator delegated: open ones and those finished in the last 7 days.
 */
function sap_coord_delegated( $uid ) {
	global $wpdb;
	$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ); // phpcs:ignore
	$rows  = array_map( 'sap_task_hydrate', (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'tasks' ) . " WHERE coord_id = %d AND assignee <> %d AND status NOT IN ('dropped','proposed') AND ( status <> 'done' OR done_at >= %s )", $uid, $uid, $since ) ) ); // phpcs:ignore
	$rank  = array( 'stuck' => 0, 'late' => 1, 'open' => 2, 'review' => 3, 'done' => 4 );
	usort( $rows, function ( $a, $b ) use ( $rank ) {
		return array( $rank[ sap_task_bucket( $a ) ] ?? 5, (string) ( $a['due_date'] ?: '9999' ) ) <=> array( $rank[ sap_task_bucket( $b ) ] ?? 5, (string) ( $b['due_date'] ?: '9999' ) );
	} );
	return $rows;
}

/**
 * Follow-up state of a delegated task: what the coordinator should do about it.
 *
 * @return array [label, class, key] key: stuck|late|silent|nudged|review|ok|done
 */
function sap_coord_follow_state( $t ) {
	$b  = sap_task_bucket( $t );
	$ps = sap_progress_summary( $t );
	if ( 'done' === $b ) {
		return array( 'انجام و تأیید شد', 'is-good', 'done' );
	}
	if ( 'review' === $b ) {
		return array( 'گزارش نهایی ثبت شد · منتظر تأیید', 'is-info', 'review' );
	}
	if ( 'stuck' === $b ) {
		return array( '⚠️ گیر کرده: ' . mb_strimwidth( $t['waiting_for'], 0, 40, '…' ), 'is-bad', 'stuck' );
	}
	if ( 'late' === $b ) {
		return array( sap_num( sap_days_late( $t['due_date'] ) ) . ' روز تأخیر', 'is-bad', 'late' );
	}
	if ( sap_nudge_open( $t ) ) {
		return array( '📣 یادآوری شد · منتظر پاسخ', 'is-warn', 'nudged' );
	}
	$since = $ps['last'] ? $ps['quiet'] : ( '' !== $t['delegated_at'] ? sap_days_late( substr( $t['delegated_at'], 0, 10 ) ) : 0 );
	if ( $since >= 2 ) {
		return array( ( $ps['last'] ? sap_num( $since ) . ' روز بی‌گزارش' : 'هنوز گزارشی نداده' ), 'is-warn', 'silent' );
	}
	return array( $ps['last'] ? 'گزارش داده · در حال انجام' : 'تازه واگذار شده', 'is-info', 'ok' );
}

/**
 * Number of the person's tasks per day (by start date, else deadline), Fridays included as 0.
 *
 * @return array Y-m-d => count
 */
function sap_user_day_load( $uid, $from, $to ) {
	$out = array();
	for ( $d = $from; $d <= $to; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
		$out[ $d ] = 0;
	}
	foreach ( sap_tasks( array( 'assignee' => $uid, 'status' => array_merge( sap_coord_open_statuses(), array( 'review' ) ) ) ) as $t ) {
		$day = '' !== $t['start_date'] ? $t['start_date'] : (string) $t['due_date'];
		if ( isset( $out[ $day ] ) ) {
			++$out[ $day ];
		}
	}
	return $out;
}

/**
 * May the current user delegate / plan this task?
 */
function sap_can_delegate( $t ) {
	return $t && ( sap_can() || (int) $t['assignee'] === get_current_user_id() ) && in_array( $t['status'], array_merge( sap_coord_open_statuses(), array( 'someday' ) ), true );
}

/**
 * Hand a task to a member: deadline, description and checklist as edited; it moves to
 * «در حال انجام», gets a «واگذار شد» note in its daily reports and the person is told.
 *
 * @param array $d {assignee, due_date, notes?, checklist?}
 * @return array|WP_Error
 */
function sap_task_delegate( $t, $d ) {
	if ( ! sap_can_delegate( $t ) ) {
		return new WP_Error( 'sap_coord', 'این کار به شما سپرده نشده یا بسته شده است.', array( 'status' => 403 ) );
	}
	$to = absint( $d['assignee'] ?? 0 );
	$me = get_current_user_id();
	if ( ! $to || $to === $me || '' === sap_user_role( $to ) ) {
		return new WP_Error( 'sap_coord', 'مسئول کار را انتخاب کنید (کسی غیر از خودتان).' );
	}
	$due = sap_no_friday( sap_parse_date( (string) ( $d['due_date'] ?? '' ) ) );
	if ( '' === $due ) {
		return new WP_Error( 'sap_coord', 'ددلاین را تعیین کنید.' );
	}
	$progress   = $t['progress'];
	$progress[] = array( 'u' => $me, 'at' => sap_now(), 'text' => '📤 واگذار شد به ' . sap_user_name( $to ) . ' · ددلاین ' . sap_jdate( $due ), 'state' => 'progress', 'why' => '', 'kind' => 'delegate' );
	$save = array(
		'assignee'     => $to,
		'due_date'     => $due,
		'coord_id'     => $me,
		'delegated_at' => sap_now(),
		'start_date'   => '',
		'status'       => 'doing',
		'progress'     => $progress,
		'period'       => sap_period_of( $due ),
	);
	if ( isset( $d['notes'] ) && null !== $d['notes'] ) {
		$save['notes'] = (string) $d['notes'];
	}
	if ( isset( $d['checklist'] ) && null !== $d['checklist'] ) {
		$save['checklist'] = (string) $d['checklist'];
	}
	sap_task_save( $save, $t['id'] );
	return array( 'msg' => 'به ' . sap_user_name( $to ) . ' واگذار شد و برایش اعلان و ایمیل رفت.' );
}

/**
 * Keep a task: plan when to start it (and its deadline).
 *
 * @return array|WP_Error
 */
function sap_task_plan_own( $t, $start, $due = '' ) {
	if ( ! sap_can_delegate( $t ) ) {
		return new WP_Error( 'sap_coord', 'این کار به شما سپرده نشده یا بسته شده است.', array( 'status' => 403 ) );
	}
	$start = sap_no_friday( sap_parse_date( (string) $start ) );
	if ( '' === $start ) {
		return new WP_Error( 'sap_coord', 'تاریخ شروع را انتخاب کنید.' );
	}
	$due  = '' !== (string) $due ? sap_no_friday( sap_parse_date( (string) $due ) ) : (string) $t['due_date'];
	if ( $due && $due < $start ) {
		return new WP_Error( 'sap_coord', 'ددلاین نمی‌تواند قبل از تاریخ شروع باشد.' );
	}
	$save = array( 'start_date' => $start, 'coord_id' => get_current_user_id() );
	if ( $due ) {
		$save['due_date'] = $due;
		$save['period']   = sap_period_of( $due );
	}
	if ( 'inbox' === $t['status'] ) {
		$save['status'] = 'next';
	}
	sap_task_save( $save, $t['id'] );
	return array( 'msg' => 'برنامه‌ریزی شد: شروع ' . sap_jdate( $start, 'day' ) . ( $due ? ' · ددلاین ' . sap_jdate( $due, 'day' ) : '' ) . '.' );
}

/**
 * A follow-up note by the coordinator (call, chat…) in the task's daily reports.
 *
 * @return array|WP_Error
 */
function sap_task_follow_note( $t, $text ) {
	$text = trim( sanitize_textarea_field( (string) $text ) );
	if ( ! $t || ( ! sap_can() && (int) $t['coord_id'] !== get_current_user_id() ) ) {
		return new WP_Error( 'sap_coord', 'فقط برای کارهایی که واگذار کرده‌اید.', array( 'status' => 403 ) );
	}
	if ( '' === $text ) {
		return new WP_Error( 'sap_coord', 'نتیجه پیگیری را بنویسید.' );
	}
	$progress   = $t['progress'];
	$progress[] = array( 'u' => get_current_user_id(), 'at' => sap_now(), 'text' => '📞 پیگیری: ' . $text, 'state' => 'progress', 'why' => '', 'kind' => 'follow' );
	sap_task_save( array( 'progress' => $progress ), $t['id'] );
	return array( 'msg' => 'پیگیری در گزارش‌های کار ثبت شد.' );
}

/**
 * Report and approval of a delegated task reach the coordinator too.
 */
add_action( 'sap_task_saved', function ( $before, $after ) {
	if ( ! $after || ! $after['coord_id'] || $after['coord_id'] === $after['assignee'] || $after['coord_id'] === get_current_user_id() ) {
		return;
	}
	$was = $before ? $before['status'] : '';
	$url = sap_url( 'coord' ) . '#f-' . $after['id'];
	if ( 'review' === $after['status'] && 'review' !== $was ) {
		sap_notice_add( $after['coord_id'], array( 'icon' => '📝', 'title' => sap_user_name( $after['assignee'] ) . ' کار «' . $after['title'] . '» را تمام کرد', 'text' => mb_substr( $after['result'], 0, 300 ), 'url' => $url ) );
	} elseif ( 'done' === $after['status'] && 'done' !== $was ) {
		sap_notice_add( $after['coord_id'], array( 'icon' => '✅', 'title' => 'کار «' . $after['title'] . '» تأیید و بسته شد', 'text' => sap_user_name( $after['assignee'] ), 'url' => $url ) );
	}
}, 25, 2 );

/**
 * Automatic follow-up (opt-in per coordinator): once a day, delegated tasks with no
 * report for 2+ days get a reminder (not more than once a day per task).
 *
 * @return int reminders sent
 */
function sap_coord_auto_followup() {
	$h = (int) current_time( 'G' );
	if ( $h < 10 || get_option( 'sap_coord_auto_day' ) === current_time( 'Y-m-d' ) ) {
		return 0;
	}
	update_option( 'sap_coord_auto_day', current_time( 'Y-m-d' ), false );
	$n    = 0;
	$back = get_current_user_id();
	foreach ( get_users( array( 'meta_key' => 'sap_coord_auto', 'meta_value' => '1', 'fields' => 'ID' ) ) as $uid ) {
		wp_set_current_user( (int) $uid );
		foreach ( sap_coord_delegated( (int) $uid ) as $t ) {
			$st = sap_coord_follow_state( $t );
			if ( 'silent' !== $st[2] ) {
				continue;
			}
			if ( '' !== $t['nudged_at'] && strtotime( $t['nudged_at'] ) > current_time( 'timestamp' ) - DAY_IN_SECONDS ) { // phpcs:ignore
				continue;
			}
			$r  = sap_task_nudge( $t, 'یادآوری خودکار: چند روز است گزارشی از این کار نیامده؛ لطفاً وضعیتش را بنویس.' );
			$n += is_wp_error( $r ) ? 0 : 1;
		}
	}
	wp_set_current_user( $back );
	return $n;
}
add_action( 'sap_hourly', 'sap_coord_auto_followup' );

/* ---------------------------------------------------------------------------
 * Endpoints
 * ------------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	$ns   = 'seoactionplan/v1';
	$task = function ( WP_REST_Request $req ) {
		$t = sap_task( absint( $req->get_param( 'task_id' ) ) );
		return $t ?: new WP_Error( 'sap_task', 'کار پیدا نشد.', array( 'status' => 404 ) );
	};
	register_rest_route( $ns, '/coord/delegate', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) use ( $task ) {
			$t = $task( $req );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$check = null;
			if ( null !== $req->get_param( 'checklist_sent' ) ) {
				$check = rest_sanitize_boolean( $req->get_param( 'has_checklist' ) ) ? sanitize_textarea_field( (string) $req->get_param( 'checklist' ) ) : '';
			}
			$r = sap_task_delegate( $t, array(
				'assignee'  => $req->get_param( 'assignee' ),
				'due_date'  => (string) $req->get_param( 'due_date' ),
				'notes'     => null !== $req->get_param( 'notes' ) ? sanitize_textarea_field( (string) $req->get_param( 'notes' ) ) : null,
				'checklist' => $check,
			) );
			return is_wp_error( $r ) ? $r : array_merge( array( 'ok' => true ), $r );
		},
	) );
	register_rest_route( $ns, '/coord/plan', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) use ( $task ) {
			$t = $task( $req );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$r = sap_task_plan_own( $t, (string) $req->get_param( 'start_date' ), (string) $req->get_param( 'due_date' ) );
			return is_wp_error( $r ) ? $r : array_merge( array( 'ok' => true ), $r );
		},
	) );
	register_rest_route( $ns, '/coord/bulk', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$ok  = 0;
			$err = '';
			foreach ( array_map( 'absint', (array) $req->get_param( 'ids' ) ) as $id ) {
				$r = sap_task_delegate( sap_task( $id ), array( 'assignee' => $req->get_param( 'assignee' ), 'due_date' => (string) $req->get_param( 'due_date' ) ) );
				if ( is_wp_error( $r ) ) {
					$err = $r->get_error_message();
				} else {
					++$ok;
				}
			}
			if ( ! $ok ) {
				return new WP_Error( 'sap_coord', $err ?: 'کاری انتخاب نشده.' );
			}
			return array( 'ok' => true, 'msg' => sap_num( $ok ) . ' کار به ' . sap_user_name( absint( $req->get_param( 'assignee' ) ) ) . ' واگذار شد.' . ( $err ? ' (بعضی موارد: ' . $err . ')' : '' ) );
		},
	) );
	register_rest_route( $ns, '/coord/follow', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) use ( $task ) {
			$t = $task( $req );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$r = sap_task_follow_note( $t, (string) $req->get_param( 'text' ) );
			return is_wp_error( $r ) ? $r : array_merge( array( 'ok' => true ), $r );
		},
	) );
	register_rest_route( $ns, '/coord/auto', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$on = rest_sanitize_boolean( $req->get_param( 'on' ) );
			update_user_meta( get_current_user_id(), 'sap_coord_auto', $on ? '1' : '0' );
			return array( 'ok' => true, 'msg' => $on ? 'پیگیری خودکار روشن شد: هر روز ساعت ۱۰، برای کارهای واگذارشده‌ای که ۲ روز گزارش نداشته‌اند یادآوری می‌رود.' : 'پیگیری خودکار خاموش شد.' );
		},
	) );
} );
