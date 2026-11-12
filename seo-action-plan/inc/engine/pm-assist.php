<?php
/**
 * Project assistant («دستیار پروژه»): a project manager asks in plain Persian
 * about one project — is X done, who is on what, what is late or stuck — and
 * gets an answer built only from the tasks they assigned there (a full manager:
 * every task of the project), with links to the tasks. When something asked
 * about has no task, the assistant proposes one, which can be created on the spot.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tasks the current user may ask about in a project.
 */
function sap_pm_assist_tasks( $project_id ) {
	$all = sap_tasks( array( 'project_id' => $project_id ) );
	if ( ! sap_can() ) {
		$me  = get_current_user_id();
		$all = array_filter( $all, function ( $t ) use ( $me ) {
			return $t['created_by'] === $me;
		} );
	}
	return array_values( array_filter( $all, function ( $t ) {
		return 'dropped' !== $t['status'];
	} ) );
}

/**
 * Compact facts of the tasks for the model (no internal ids beyond the task id).
 */
function sap_pm_assist_context( $project, $tasks ) {
	$today = current_time( 'Y-m-d' );
	$rows  = array();
	usort( $tasks, function ( $a, $b ) {
		return strcmp( (string) $b['updated_at'], (string) $a['updated_at'] );
	} );
	foreach ( array_slice( $tasks, 0, 140 ) as $t ) {
		$ps   = sap_progress_summary( $t );
		$prog = $t['checklist'] ? sap_checklist_progress( $t ) : null;
		$own  = array_slice( sap_progress_own( $t ), -3 );
		$row  = array(
			'id'       => $t['id'],
			'title'    => $t['title'],
			'state'    => sap_task_state_chip( $t )[0],
			'person'   => $t['assignee'] ? sap_user_name( $t['assignee'] ) : 'بدون مسئول',
			'due'      => $t['due_date'] ? sap_jdate( $t['due_date'] ) . ( $t['due_date'] < $today && 'done' !== $t['status'] ? ' (گذشته)' : '' ) : '',
			'created'  => sap_jdate( substr( (string) $t['created_at'], 0, 10 ) ),
		);
		if ( 'done' === $t['status'] && $t['done_at'] ) {
			$row['done_on'] = sap_jdate( substr( $t['done_at'], 0, 10 ) );
		}
		if ( $prog ) {
			$row['checklist'] = sprintf( '%d از %d', $prog['done'], $prog['total'] );
			$left             = array_values( array_filter( $t['checklist'], function ( $i ) {
				return empty( $i['d'] );
			} ) );
			if ( $left ) {
				$row['checklist_left'] = array_slice( array_column( $left, 't' ), 0, 5 );
			}
		}
		if ( $ps['blocked'] ) {
			$row['stuck_because'] = $t['waiting_for'];
		}
		if ( $own ) {
			$row['reports'] = array_map( function ( $p ) {
				return sap_jdate( substr( $p['at'], 0, 10 ), 'day' ) . ': ' . mb_substr( preg_replace( '/\s+/u', ' ', $p['text'] ), 0, 220 ) . ( 'blocked' === ( $p['state'] ?? '' ) ? ' [گیر: ' . $p['why'] . ']' : '' );
			}, $own );
		}
		if ( '' !== trim( $t['result'] ) ) {
			$row['final_report'] = mb_substr( preg_replace( '/\s+/u', ' ', $t['result'] ), 0, 300 );
		}
		if ( '' !== $t['review_note'] ) {
			$row['feedback'] = mb_substr( $t['review_note'], 0, 200 );
		}
		if ( sap_nudge_open( $t ) ) {
			$row['reminder'] = 'یادآوری فرستاده شده و هنوز پاسخ نداده';
		}
		if ( '' !== trim( $t['notes'] ) ) {
			$row['about'] = mb_substr( preg_replace( '/\s+/u', ' ', $t['notes'] ), 0, 160 );
		}
		$rows[] = $row;
	}
	$people = array();
	foreach ( sap_team_users() as $u ) {
		$people[] = array( 'name' => $u->display_name, 'open_tasks' => count( sap_member_open_tasks( $u->ID ) ) );
	}
	return array(
		'project' => $project['name'],
		'today'   => sap_jdate( $today ) . ' (' . sap_weekday_fa( $today ) . ')',
		'tasks'   => $rows,
		'team'    => $people,
	);
}

/**
 * Chat history of a user in a project (last 16 messages).
 */
function sap_pm_assist_history( $project_id, $uid = 0 ) {
	$h = get_user_meta( $uid ?: get_current_user_id(), 'sap_pm_assist_' . (int) $project_id, true );
	return is_array( $h ) ? $h : array();
}

/**
 * Ask the assistant.
 *
 * @return array|WP_Error {answer, refs[], suggest[], followups[]}
 */
function sap_pm_assist_ask( $project, $question ) {
	$question = trim( sanitize_textarea_field( (string) $question ) );
	if ( '' === $question ) {
		return new WP_Error( 'sap_assist', 'سوال را بنویسید.' );
	}
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_assist', 'هوش مصنوعی در تنظیمات وصل نشده است.' );
	}
	$tasks   = sap_pm_assist_tasks( $project['id'] );
	$history = sap_pm_assist_history( $project['id'] );
	$conv    = '';
	foreach ( array_slice( $history, -6 ) as $m ) {
		$conv .= ( 'user' === $m['role'] ? 'مدیر: ' : 'دستیار: ' ) . mb_substr( (string) $m['text'], 0, 600 ) . "\n";
	}
	$schema = sap_ai_obj( array(
		'answer'    => array( 'type' => 'string', 'description' => 'پاسخ فارسی، کوتاه و دقیق؛ برای فهرست از خط‌های «- » استفاده کن' ),
		'refs'      => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'id کارهایی که در پاسخ به آن‌ها اشاره شد' ),
		'suggest'   => array( 'type' => 'array', 'items' => sap_ai_obj( array(
			'title'    => array( 'type' => 'string' ),
			'notes'    => array( 'type' => 'string' ),
			'person'   => array( 'type' => 'string', 'description' => 'نام یکی از اعضای team یا خالی' ),
			'due_days' => array( 'type' => 'integer', 'description' => 'چند روز دیگر ددلاین باشد' ),
			'why'      => array( 'type' => 'string' ),
		) ) ),
		'followups' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
	) );
	$system = implode( "\n", array(
		'تو دستیار مدیر پروژه در سکان هستی. فقط بر اساس داده کارهای همین پروژه (tasks) جواب بده؛ هر چیزی که در داده نیست را حدس نزن.',
		'- برای هر ادعا به کار مربوط اشاره کن و id آن را در refs بگذار. وضعیت، مسئول، ددلاین، پیشرفت چک‌لیست، آخرین گزارش روزانه و علت گیر کردن را دقیق بگو.',
		'- اگر سوال درباره کاری است که در tasks نیست، صریح بگو «کاری برای این ثبت نشده» و در suggest یک کار پیشنهاد بده (عنوان روشن، شرح کوتاه و قابل اجرا، مسئول مناسب از team با توجه به تعداد کارهای بازش، و ددلاین منطقی). جمعه تعطیل است.',
		'- اگر در داده ایراد مدیریتی می‌بینی (کار بدون مسئول، بدون ددلاین، عقب‌افتاده بدون گزارش)، کوتاه گوشزد کن.',
		'- پاسخ کوتاه و کاربردی؛ بدون مقدمه. followups: ۲ تا ۳ سوال بعدی مفید و کوتاه.',
	) );
	$user = "داده پروژه:\n" . wp_json_encode( sap_pm_assist_context( $project, $tasks ), JSON_UNESCAPED_UNICODE ) . ( '' !== $conv ? "\n\nگفتگوی قبلی:\n" . $conv : '' ) . "\n\nسوال تازه مدیر:\n" . mb_substr( $question, 0, 1500 );
	$res  = sap_ai_request( $system, $user, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 2500, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$ids     = wp_list_pluck( $tasks, 'id' );
	$refs    = array_values( array_intersect( array_map( 'intval', (array) ( $res['refs'] ?? array() ) ), $ids ) );
	$team    = sap_team_users();
	$match   = function ( $name ) use ( $team ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		foreach ( $team as $u ) {
			if ( $u->display_name === $name ) {
				return (int) $u->ID;
			}
		}
		foreach ( $team as $u ) {
			if ( false !== mb_strpos( $u->display_name, $name ) || false !== mb_strpos( $name, $u->display_name ) ) {
				return (int) $u->ID;
			}
		}
		return 0;
	};
	$suggest = array();
	foreach ( array_slice( (array) ( $res['suggest'] ?? array() ), 0, 3 ) as $s ) {
		$title = trim( sanitize_text_field( (string) ( $s['title'] ?? '' ) ) );
		if ( '' === $title ) {
			continue;
		}
		$days      = max( 1, min( 60, (int) ( $s['due_days'] ?? 3 ) ) );
		$suggest[] = array(
			'title'    => $title,
			'notes'    => sanitize_textarea_field( (string) ( $s['notes'] ?? '' ) ),
			'assignee' => $match( $s['person'] ?? '' ),
			'due'      => sap_no_friday( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . $days . ' days' ) ) ),
			'why'      => sanitize_text_field( (string) ( $s['why'] ?? '' ) ),
		);
	}
	$out = array(
		'answer'    => trim( (string) ( $res['answer'] ?? '' ) ),
		'refs'      => $refs,
		'suggest'   => $suggest,
		'followups' => array_slice( array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $res['followups'] ?? array() ) ) ) ) ), 0, 3 ),
	);
	$history[] = array( 'role' => 'user', 'text' => $question, 'at' => sap_now() );
	$history[] = array( 'role' => 'bot', 'text' => $out['answer'], 'refs' => $refs, 'suggest' => $suggest, 'at' => sap_now() );
	update_user_meta( get_current_user_id(), 'sap_pm_assist_' . (int) $project['id'], array_slice( $history, -16 ) );
	return $out;
}

/**
 * HTML of an answer: line breaks, «- » lists, task references as links.
 */
function sap_pm_assist_render( $text, $refs, $project_id ) {
	$html = '';
	$list = false;
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( preg_match( '/^[-•*]\s+(.*)$/u', $line, $m ) ) {
			$html .= ( $list ? '' : '<ul>' ) . '<li>' . esc_html( $m[1] ) . '</li>';
			$list  = true;
			continue;
		}
		if ( $list ) {
			$html .= '</ul>';
			$list  = false;
		}
		$html .= '' !== $line ? '<p>' . esc_html( $line ) . '</p>' : '';
	}
	$html .= $list ? '</ul>' : '';
	if ( $refs ) {
		$html .= '<div class="sap-assist-refs">';
		foreach ( $refs as $id ) {
			$t = sap_task( (int) $id );
			if ( $t ) {
				$st    = sap_task_state_chip( $t );
				$html .= '<a href="' . esc_url( sap_url( 'pmp', array( 'p' => (int) $project_id, 'period' => 'all' ) ) . '#t-' . (int) $id ) . '" data-sap-assist-ref="' . (int) $id . '"><span class="sap-chip ' . esc_attr( $st[1] ) . '">' . esc_html( $st[0] ) . '</span>' . esc_html( $t['title'] ) . '</a>';
			}
		}
		$html .= '</div>';
	}
	return $html;
}

add_action( 'rest_api_init', function () {
	$ns   = 'seoactionplan/v1';
	$perm = function () {
		return sap_can() || sap_is_pm();
	};
	register_rest_route( $ns, '/pm-assist/(?P<project>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) {
			$p = sap_project( (int) $req['project'] );
			if ( ! $p ) {
				return new WP_Error( 'sap_assist', 'پروژه پیدا نشد.', array( 'status' => 404 ) );
			}
			if ( rest_sanitize_boolean( $req->get_param( 'clear' ) ) ) {
				delete_user_meta( get_current_user_id(), 'sap_pm_assist_' . $p['id'] );
				return array( 'ok' => true );
			}
			$r = sap_pm_assist_ask( $p, (string) $req->get_param( 'question' ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			return array_merge( array( 'ok' => true, 'html' => sap_pm_assist_render( $r['answer'], $r['refs'], $p['id'] ) ), $r );
		},
	) );
	register_rest_route( $ns, '/pm-assist/(?P<project>\d+)/create', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) {
			$r = sap_pm_propose( array(
				'project_id' => (int) $req['project'],
				'title'      => (string) $req->get_param( 'title' ),
				'notes'      => (string) $req->get_param( 'notes' ),
				'assignee'   => absint( $req->get_param( 'assignee' ) ),
				'due_date'   => (string) $req->get_param( 'due_date' ),
				'context'    => 'management',
			) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			return array( 'ok' => true, 'id' => $r, 'msg' => sap_can() ? 'کار ساخته و واگذار شد.' : 'کار ثبت شد و بعد از تأیید مدیر سئو به مسئول واگذار می‌شود.' );
		},
	) );
} );
