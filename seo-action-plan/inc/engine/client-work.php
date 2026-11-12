<?php
/**
 * The client's window on a project (Pilot): every task with its stage, the
 * person on it and the reports they wrote; tasks the client gives (to a
 * person they know, or to the coordinator / SEO manager by default); a
 * delivery estimate from the person's queue; and the client's feedback on a
 * finished task (confirm with a rating, or reopen with what is still wrong).
 *
 * Internal notes never leave Sokan: follow-up notes of the coordinator, the
 * review note of the manager and tasks of internal areas.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stage of a task as the client sees it.
 *
 * @return string assign | assigned | doing | review | done | dropped
 */
function sap_client_stage( $t ) {
	if ( 'done' === $t['status'] ) {
		return 'done';
	}
	if ( 'dropped' === $t['status'] ) {
		return 'dropped';
	}
	if ( 'review' === $t['status'] ) {
		return 'review';
	}
	if ( in_array( $t['status'], array( 'doing', 'waiting' ), true ) ) {
		return 'doing';
	}
	// Waiting on the SEO manager's desk, or nobody holds it yet.
	if ( 'proposed' === $t['status'] || ! $t['assignee'] ) {
		return 'assign';
	}
	return 'assigned';
}

/**
 * Client-facing stage labels.
 */
function sap_client_stage_labels() {
	return array(
		'assign'   => 'بررسی و واگذاری',
		'assigned' => 'واگذار شد',
		'doing'    => 'در حال انجام',
		'review'   => 'در حال بازبینی نهایی',
		'done'     => 'انجام شد',
		'dropped'  => 'بسته شد',
	);
}

/**
 * Is this person the one who receives and hands out work (coordinator or SEO manager)?
 */
function sap_client_is_dispatcher( $uid, $project_id = 0 ) {
	$uid = (int) $uid;
	return $uid && $uid === (int) sap_client_dispatcher( $project_id );
}

/**
 * Who receives a client's task when the client does not name anyone: the
 * project's coordinator, else any coordinator, else the project's SEO
 * manager, else the first full manager.
 */
function sap_client_dispatcher( $project_id ) {
	static $cache = array();
	$project_id = (int) $project_id;
	if ( isset( $cache[ $project_id ] ) ) {
		return $cache[ $project_id ];
	}
	$members = $project_id ? sap_project_members( $project_id ) : array();
	$pick    = 0;
	foreach ( array_keys( $members ) as $uid ) {
		if ( 'coord' === sap_user_role( $uid ) ) {
			$pick = (int) $uid;
			break;
		}
	}
	if ( ! $pick ) {
		foreach ( sap_team_users() as $u ) {
			if ( 'coord' === sap_user_role( $u->ID ) ) {
				$pick = (int) $u->ID;
				break;
			}
		}
	}
	if ( ! $pick ) {
		foreach ( $members as $uid => $role ) {
			if ( 'manager' === $role ) {
				$pick = (int) $uid;
				break;
			}
		}
	}
	if ( ! $pick ) {
		$full = sap_full_users();
		$pick = $full ? (int) $full[0]->ID : 0;
	}
	$cache[ $project_id ] = $pick;
	return $pick;
}

/**
 * People a client may hand a task to: the project's members, with their
 * role and how many open tasks they have now.
 */
function sap_client_people( $project ) {
	$roles = sap_member_roles();
	$disp  = sap_client_dispatcher( $project['id'] );
	$out   = array();
	$ids   = array_keys( sap_project_members( $project['id'] ) );
	if ( $disp && ! in_array( $disp, $ids, true ) ) {
		array_unshift( $ids, $disp );
	}
	foreach ( $ids as $uid ) {
		$m = sap_member( $uid );
		if ( ! $m ) {
			continue;
		}
		$role  = sap_project_members( $project['id'] )[ $uid ] ?? '';
		$out[] = array(
			'id'         => (int) $uid,
			'name'       => $m['name'],
			'role_label' => $roles[ $role ] ?? ( 'coord' === $m['role'] ? 'هماهنگ‌کننده و پیگیری' : ( $m['manager'] ? 'مدیر سئو' : ( $m['title'] ?: 'عضو تیم' ) ) ),
			'avatar'     => get_avatar_url( $uid, array( 'size' => 96 ) ),
			'open'       => count( sap_tasks( array( 'assignee' => $uid, 'status' => array( 'inbox', 'next', 'doing', 'waiting', 'review' ) ) ) ),
			'dispatcher' => (int) $uid === (int) $disp,
		);
	}
	return $out;
}

/**
 * One task as the client sees it.
 *
 * @param bool $full With the whole report timeline (else the last report only).
 */
function sap_client_task_row( $t, $full = false ) {
	$labels  = sap_client_stage_labels();
	$stage   = sap_client_stage( $t );
	$reports = array();
	foreach ( (array) $t['progress'] as $p ) {
		$kind = (string) ( $p['kind'] ?? '' );
		if ( 'follow' === $kind ) {
			continue; // The coordinator's follow-up notes are internal.
		}
		$reports[] = array(
			'at'    => (string) ( $p['at'] ?? '' ),
			'who'   => sap_user_name( (int) ( $p['u'] ?? 0 ) ),
			'text'  => (string) ( $p['text'] ?? '' ),
			'state' => (string) ( $p['state'] ?? 'progress' ),
			'kind'  => $kind,
		);
	}
	$m = $t['assignee'] ? sap_member( $t['assignee'] ) : null;
	$row = array(
		'id'            => (int) $t['id'],
		'title'         => preg_replace( '/^(🚨\s*)?(درخواست کارفرما:\s*)?/u', '', (string) $t['title'] ),
		'urgent'        => false !== strpos( (string) $t['title'], '🚨' ),
		'notes'         => $full ? (string) $t['notes'] : '',
		'url'           => (string) $t['url'],
		'status'        => $t['status'],
		'stage'         => $stage,
		'stage_label'   => $labels[ $stage ] ?? $stage,
		'stuck'         => 'waiting' === $t['status'] && '' !== (string) $t['waiting_for'],
		'waiting_for'   => (string) $t['waiting_for'],
		'category'      => (string) $t['context'],
		'category_label' => sap_context_label( $t['context'] ),
		'assignee'      => (int) $t['assignee'],
		'assignee_name' => $m ? $m['name'] : '',
		'assignee_role' => $m ? ( $m['title'] ?: ( 'coord' === $m['role'] ? 'هماهنگ‌کننده و پیگیری' : ( $m['manager'] ? 'مدیر سئو' : 'عضو تیم' ) ) ) : '',
		'avatar'        => $t['assignee'] ? get_avatar_url( $t['assignee'], array( 'size' => 96 ) ) : '',
		'due_date'      => (string) $t['due_date'],
		'start_date'    => (string) $t['start_date'],
		'created_at'    => (string) $t['created_at'],
		'started_at'    => (string) $t['started_at'],
		'done_at'       => (string) $t['done_at'],
		'effort'        => (float) $t['effort'],
		'outcome'       => (string) $t['outcome'],
		'from_client'   => 'pilot' === $t['source'],
		'needs_client'  => (int) $t['needs_client'],
		'client_status' => (string) $t['client_status'],
		'checklist'     => $full ? array_map( function ( $c ) {
			return array( 'text' => (string) ( $c['text'] ?? '' ), 'done' => ! empty( $c['done'] ) );
		}, (array) $t['checklist'] ) : array(),
		'checklist_done' => count( array_filter( (array) $t['checklist'], function ( $c ) {
			return ! empty( $c['done'] );
		} ) ),
		'checklist_all' => count( (array) $t['checklist'] ),
		'attachments'   => $full ? array_values( array_map( function ( $a ) {
			return array( 'url' => (string) ( $a['url'] ?? '' ), 'name' => (string) ( $a['name'] ?? '' ), 'type' => (string) ( $a['type'] ?? '' ) );
		}, (array) $t['attachments'] ) ) : array(),
		'messages'      => $full && function_exists( 'sap_client_messages' ) ? sap_client_messages( $t['id'] ) : array(),
		'reports_count' => count( $reports ),
		'reports'       => $full ? $reports : array_slice( $reports, -1 ),
		'updated_at'    => (string) $t['updated_at'],
	);
	return $row;
}

/**
 * Every task of a project the client may see, with project facts.
 *
 * @param array $args ids (only these), since (Y-m-d: tasks touched since), limit.
 */
function sap_client_work( $project, $args = array() ) {
	$args   = wp_parse_args( $args, array( 'ids' => array(), 'limit' => 300 ) );
	$hidden = array_map( 'sanitize_key', (array) ( $project['options']['client_hidden_contexts'] ?? array() ) );
	$tasks  = sap_tasks( array( 'project_id' => $project['id'] ) );
	$rows   = array();
	$counts = array( 'assign' => 0, 'assigned' => 0, 'doing' => 0, 'review' => 0, 'done' => 0, 'late' => 0 );
	$today  = current_time( 'Y-m-d' );
	foreach ( $tasks as $t ) {
		if ( in_array( $t['status'], array( 'someday', 'dropped' ), true ) && 'pilot' !== $t['source'] ) {
			continue;
		}
		if ( 'proposed' === $t['status'] && 'pilot' !== $t['source'] ) {
			continue; // A team member's proposal is not work yet.
		}
		if ( in_array( $t['context'], $hidden, true ) ) {
			continue;
		}
		if ( $args['ids'] && ! in_array( (int) $t['id'], array_map( 'intval', (array) $args['ids'] ), true ) ) {
			continue;
		}
		$r = sap_client_task_row( $t );
		if ( isset( $counts[ $r['stage'] ] ) ) {
			++$counts[ $r['stage'] ];
		}
		if ( 'done' !== $r['stage'] && $r['due_date'] && $r['due_date'] < $today ) {
			++$counts['late'];
		}
		$rows[] = $r;
	}
	$rank = array( 'doing' => 0, 'review' => 1, 'assigned' => 2, 'assign' => 3, 'done' => 4, 'dropped' => 5 );
	usort( $rows, function ( $a, $b ) use ( $rank ) {
		$x = $rank[ $a['stage'] ] ?? 9;
		$y = $rank[ $b['stage'] ] ?? 9;
		if ( $x !== $y ) {
			return $x <=> $y;
		}
		return 'done' === $a['stage'] ? strcmp( $b['done_at'], $a['done_at'] ) : strcmp( $b['updated_at'], $a['updated_at'] );
	} );
	$seo   = function_exists( 'sap_project_is_seo' ) ? sap_project_is_seo( $project ) : true;
	$all   = count( $rows );
	$start = (string) ( $project['options']['ops_start'] ?? '' );
	$end   = (string) ( $project['options']['ops_end'] ?? '' );
	return array(
		'sokan'      => SAP_VERSION,
		'project'    => array(
			'id'        => (int) $project['id'],
			'name'      => $project['name'],
			'kind'      => $seo ? 'seo' : 'ops',
			'type'      => $seo ? '' : ( sap_ops_types()[ $project['options']['ops_type'] ?? 'other' ] ?? '' ),
			'start'     => $start,
			'end'       => $end,
			'progress'  => $all ? round( $counts['done'] / max( 1, $all - 0 ), 3 ) : null,
			'time'      => $start && $end && $end > $start ? max( 0, min( 1, ( strtotime( $today ) - strtotime( $start ) ) / max( 1, strtotime( $end ) - strtotime( $start ) ) ) ) : null,
		),
		'counts'     => $counts + array( 'all' => $all ),
		'people'     => sap_client_people( $project ),
		'dispatcher' => sap_client_dispatcher( $project['id'] ),
		'tasks'      => array_slice( $rows, 0, (int) $args['limit'] ),
	);
}

/**
 * One task with its full report timeline (null when not of this project).
 */
function sap_client_task( $project, $task_id ) {
	$t = sap_task( (int) $task_id );
	if ( ! $t || (int) $t['project_id'] !== (int) $project['id'] ) {
		return null;
	}
	return sap_client_task_row( $t, true );
}

/**
 * When a task would likely be finished: the person's open work queue (hours
 * of their open tasks at about 5 focused hours a day, Fridays off) plus the
 * new task's own hours.
 *
 * @param float $hours Estimated hours of the new task.
 * @return array {assignee, name, open, queue_hours, days, date}
 */
function sap_client_estimate( $project, $assignee, $hours ) {
	$assignee = (int) $assignee ?: sap_client_dispatcher( $project['id'] );
	$open     = sap_tasks( array( 'assignee' => $assignee, 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) );
	$queue    = 0.0;
	foreach ( $open as $t ) {
		$queue += max( 0.25, (float) $t['effort'] - (float) $t['spent'] );
	}
	$per_day = max( 2, (float) apply_filters( 'sap_client_hours_per_day', 5 ) );
	// The new task waits behind a part of the queue (urgent work is not all sequential).
	$days = (int) ceil( ( $queue * 0.6 + max( 0.5, (float) $hours ) ) / $per_day );
	if ( sap_client_is_dispatcher( $assignee, $project['id'] ) ) {
		++$days; // One day to be handed to the right person.
	}
	$days = max( 1, $days );
	$d    = current_time( 'Y-m-d' );
	$left = $days;
	while ( $left > 0 ) {
		$d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) );
		if ( 5 !== (int) gmdate( 'w', strtotime( $d ) ) ) {
			--$left;
		}
	}
	return array(
		'assignee'    => $assignee,
		'name'        => sap_user_name( $assignee ),
		'open'        => count( $open ),
		'queue_hours' => round( $queue, 1 ),
		'days'        => $days,
		'date'        => $d,
	);
}

/**
 * The client's feedback on a finished task.
 *
 * @param string $verdict ok | reopen.
 * @return true|WP_Error
 */
function sap_client_feedback( $project, $task_id, $verdict, $note = '', $rating = 0, $who = '' ) {
	$t = sap_task( (int) $task_id );
	if ( ! $t || (int) $t['project_id'] !== (int) $project['id'] ) {
		return new WP_Error( 'sap_task', 'کار یافت نشد.', array( 'status' => 404 ) );
	}
	$note   = trim( sanitize_textarea_field( (string) $note ) );
	$rating = max( 0, min( 5, (int) $rating ) );
	$who    = '' !== $who ? sanitize_text_field( $who ) : 'کارفرما';
	$list   = $t['progress'];
	if ( 'reopen' === $verdict ) {
		if ( '' === $note ) {
			return new WP_Error( 'sap_fb', 'بنویسید هنوز چه چیزی درست نشده است.', array( 'status' => 400 ) );
		}
		$list[] = array( 'u' => 0, 'at' => sap_now(), 'text' => '🔁 ' . $who . ': هنوز درست نشده — ' . $note, 'state' => 'blocked', 'why' => '', 'kind' => 'client' );
		sap_task_save( array( 'status' => 'doing', 'progress' => array_slice( $list, -200 ), 'client_note' => $note ), $t['id'] );
		$n = array( 'icon' => '🔁', 'title' => 'کارفرما «' . $t['title'] . '» را دوباره باز کرد', 'text' => $note, 'url' => sap_url( 'desk' ) );
		foreach ( array_unique( array_filter( array( (int) $t['assignee'], (int) $t['coord_id'], (int) sap_client_dispatcher( $project['id'] ) ) ) ) as $uid ) {
			sap_notice_add( $uid, $n );
		}
		return true;
	}
	$list[] = array( 'u' => 0, 'at' => sap_now(), 'text' => '👍 ' . $who . ' تأیید کرد که کار درست انجام شد' . ( $rating ? ' · امتیاز ' . sap_num( $rating ) . ' از ۵' : '' ) . ( '' !== $note ? ' — ' . $note : '' ), 'state' => 'progress', 'why' => '', 'kind' => 'client' );
	sap_task_save( array( 'progress' => array_slice( $list, -200 ) ), $t['id'] );
	if ( $t['assignee'] ) {
		sap_notice_add( $t['assignee'], array( 'icon' => '🌟', 'title' => 'کارفرما از «' . $t['title'] . '» راضی بود' . ( $rating ? ' (' . sap_num( $rating ) . ' از ۵)' : '' ), 'text' => $note, 'url' => sap_url( 'my' ) ) );
	}
	return true;
}

add_action( 'rest_api_init', function () {
	$ns   = 'seoactionplan/v1';
	$perm = 'sap_rest_key_permission';
	$proj = function ( WP_REST_Request $req ) {
		return sap_project( (int) $req['project'] );
	};
	register_rest_route( $ns, '/client/(?P<project>\d+)/work', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) use ( $proj ) {
			$p = $proj( $req );
			if ( ! $p ) {
				return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
			}
			$ids = array_filter( array_map( 'absint', explode( ',', (string) $req->get_param( 'ids' ) ) ) );
			return sap_client_work( $p, array( 'ids' => $ids ) );
		},
	) );
	register_rest_route( $ns, '/client/(?P<project>\d+)/task/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) use ( $proj ) {
			$p = $proj( $req );
			$t = $p ? sap_client_task( $p, (int) $req['id'] ) : null;
			return $t ?: new WP_Error( 'sap_task', 'کار یافت نشد.', array( 'status' => 404 ) );
		},
	) );
	register_rest_route( $ns, '/client/(?P<project>\d+)/estimate', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) use ( $proj ) {
			$p = $proj( $req );
			return $p ? sap_client_estimate( $p, (int) $req->get_param( 'assignee' ), (float) $req->get_param( 'hours' ) ) : new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
		},
	) );
	register_rest_route( $ns, '/client/(?P<project>\d+)/task/(?P<id>\d+)/feedback', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) use ( $proj ) {
			$p = $proj( $req );
			if ( ! $p ) {
				return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
			}
			$r = sap_client_feedback( $p, (int) $req['id'], (string) $req->get_param( 'verdict' ), (string) $req->get_param( 'note' ), (int) $req->get_param( 'rating' ), (string) $req->get_param( 'who' ) );
			return is_wp_error( $r ) ? $r : array( 'ok' => true );
		},
	) );
} );
