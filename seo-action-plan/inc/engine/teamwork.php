<?php
/**
 * Teamwork (0.21): checklist tasks, question / request threads, in-app
 * notifications, editable work areas and the monthly calendar of tasks.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * Work areas («حوزه‌ها») — editable list
 * ------------------------------------------------------------------------- */

/**
 * Built-in work areas (the AI planner uses these keys).
 */
function sap_contexts_default() {
	return array(
		'research'       => 'کیورد ریسرچ و تحقیق',
		'content_new'    => 'تولید محتوا',
		'content_update' => 'بهینه‌سازی و آپدیت محتوا',
		'onpage'         => 'سئو داخلی (تایتل، متا، اسکیما)',
		'internal_links' => 'لینک‌سازی داخلی',
		'technical'      => 'سئو تکنیکال',
		'offpage'        => 'آف‌پیج و لینک‌سازی',
		'cro'            => 'CRO و نرخ تبدیل',
		'management'     => 'مدیریت و گزارش',
	);
}

/**
 * Every work area ever defined: key => {label, on}. Removed ones stay here
 * (switched off) so old tasks keep their label.
 */
function sap_contexts_all() {
	$saved = get_option( 'sap_contexts' );
	if ( is_array( $saved ) && $saved ) {
		$all = array(); // Saved order first, then built-ins never edited.
		foreach ( $saved as $k => $row ) {
			$all[ sanitize_key( $k ) ] = array( 'label' => (string) ( $row['label'] ?? $k ), 'on' => ! empty( $row['on'] ) );
		}
		foreach ( sap_contexts_default() as $k => $l ) {
			if ( ! isset( $all[ $k ] ) ) {
				$all[ $k ] = array( 'label' => $l, 'on' => true );
			}
		}
		return $all;
	}
	$all = array();
	foreach ( sap_contexts_default() as $k => $l ) {
		$all[ $k ] = array( 'label' => $l, 'on' => true );
	}
	return $all;
}

/**
 * Save the list from the editor (order kept, removed built-ins switched off).
 *
 * @param array $rows [{key, label, del}]
 */
function sap_contexts_save( $rows ) {
	$out  = array();
	$seen = array();
	foreach ( (array) $rows as $r ) {
		$label = sanitize_text_field( (string) ( $r['label'] ?? '' ) );
		$key   = sanitize_key( (string) ( $r['key'] ?? '' ) );
		if ( '' === $label && '' === $key ) {
			continue;
		}
		if ( '' === $key ) {
			$key = 'c' . substr( md5( $label . microtime() ), 0, 6 );
		}
		$seen[ $key ] = true;
		$out[ $key ]  = array( 'label' => '' !== $label ? $label : ( sap_contexts_all()[ $key ]['label'] ?? $key ), 'on' => empty( $r['del'] ) );
	}
	// Anything not in the form (an old area) keeps its label, switched off.
	foreach ( sap_contexts_all() as $k => $row ) {
		if ( ! isset( $seen[ $k ] ) ) {
			$out[ $k ] = array( 'label' => $row['label'], 'on' => false );
		}
	}
	if ( ! array_filter( wp_list_pluck( $out, 'on' ) ) ) {
		return new WP_Error( 'sap_ctx', 'حداقل یک حوزه لازم است.' );
	}
	update_option( 'sap_contexts', $out, false );
	return true;
}

/* ---------------------------------------------------------------------------
 * Checklist tasks
 * ------------------------------------------------------------------------- */

/**
 * Items from text (one per line), keeping the ticks of items that stay.
 *
 * @return array[] {t, d, by, at}
 */
function sap_checklist_from_text( $text, $old = array() ) {
	$done = array();
	foreach ( (array) $old as $it ) {
		if ( ! empty( $it['d'] ) ) {
			$done[ sap_norm_text( (string) $it['t'] ) ] = $it;
		}
	}
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( preg_replace( '/^\s*(?:[-•*]|\[[ xX✓]?\]|\d+[.)\-])\s*/u', '', $line ) );
		if ( '' === $line ) {
			continue;
		}
		$line  = mb_substr( sanitize_text_field( $line ), 0, 240 );
		$prev  = $done[ sap_norm_text( $line ) ] ?? null;
		$out[] = $prev ? array( 't' => $line, 'd' => 1, 'by' => (int) ( $prev['by'] ?? 0 ), 'at' => (string) ( $prev['at'] ?? '' ) ) : array( 't' => $line, 'd' => 0, 'by' => 0, 'at' => '' );
		if ( count( $out ) >= 100 ) {
			break;
		}
	}
	return $out;
}

/**
 * Clean stored items.
 */
function sap_checklist_clean( $items ) {
	$out = array();
	foreach ( (array) $items as $it ) {
		if ( ! is_array( $it ) || '' === trim( (string) ( $it['t'] ?? '' ) ) ) {
			continue;
		}
		$out[] = array( 't' => mb_substr( sanitize_text_field( (string) $it['t'] ), 0, 240 ), 'd' => empty( $it['d'] ) ? 0 : 1, 'by' => (int) ( $it['by'] ?? 0 ), 'at' => (string) ( $it['at'] ?? '' ) );
	}
	return array_slice( $out, 0, 100 );
}

/**
 * Progress of a checklist task.
 *
 * @return array {done, total, pct}
 */
function sap_checklist_progress( $task ) {
	$items = (array) ( $task['checklist'] ?? array() );
	$total = count( $items );
	$done  = count( array_filter( $items, function ( $it ) {
		return ! empty( $it['d'] );
	} ) );
	return array( 'done' => $done, 'total' => $total, 'pct' => $total ? (int) round( 100 * $done / $total ) : 0 );
}

/**
 * Text of the done items, for the report.
 */
function sap_checklist_report( $task ) {
	$lines = array();
	foreach ( (array) $task['checklist'] as $it ) {
		$lines[] = ( $it['d'] ? '✔ ' : '✖ ' ) . $it['t'];
	}
	return implode( "\n", $lines );
}

/**
 * Tick / untick one item. The first tick starts the task.
 *
 * @return array|WP_Error
 */
function sap_checklist_tick( $task, $index, $done ) {
	$items = $task['checklist'];
	if ( ! isset( $items[ $index ] ) ) {
		return new WP_Error( 'sap_check', 'این مورد پیدا نشد؛ صفحه را تازه کنید.' );
	}
	if ( in_array( $task['status'], array( 'done', 'review', 'dropped' ), true ) ) {
		return new WP_Error( 'sap_check', 'این کار بسته شده یا منتظر تأیید است.' );
	}
	$items[ $index ]['d']  = $done ? 1 : 0;
	$items[ $index ]['by'] = $done ? get_current_user_id() : 0;
	$items[ $index ]['at'] = $done ? sap_now() : '';
	$data                  = array( 'checklist' => $items );
	if ( $done && in_array( $task['status'], array( 'inbox', 'next', 'waiting' ), true ) ) {
		$data['status'] = 'doing';
	}
	sap_task_save( $data, $task['id'] );
	$t = sap_task( $task['id'] );
	$p = sap_checklist_progress( $t );
	return array_merge( $p, array( 'all' => $p['total'] && $p['done'] === $p['total'], 'status' => $t['status'], 'report' => sap_checklist_report( $t ) ) );
}

/**
 * AI: is this a list of repeated units (products, pages, articles…)? Then the items.
 *
 * @return array|WP_Error items (strings), empty when the task is not a list
 */
function sap_ai_task_checklist( $f ) {
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'is_list', 'items' ),
		'properties'           => array(
			'is_list' => array( 'type' => 'boolean' ),
			'items'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		),
	);
	$system = 'تو سرپرست تیم سئو هستی. تشخیص بده آیا این کار از چند واحد جدا و قابل شمارش تشکیل شده (مثلاً ۹ محصول برای آپلود، ۵ مقاله، ۲۰ صفحه برای تایتل، چند لینک) که بشود هر واحد را جدا تیک زد. اگر بله، برای هر واحد یک آیتم کوتاه بنویس (اگر نام‌ها مشخص است همان نام‌ها، وگرنه «محصول ۱»، «محصول ۲» …). اگر کار یک‌تکه است یا چند مرحله پشت‌سرهم است، is_list=false و items خالی. فارسی، هر آیتم حداکثر ۱۲ کلمه، حداکثر ۶۰ آیتم.';
	$user   = wp_json_encode( array(
		'title'       => (string) ( $f['title'] ?? '' ),
		'description' => mb_substr( (string) ( $f['notes'] ?? '' ), 0, 3000 ),
	), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$r = sap_ai_request( $system, $user, $schema, array( 'purpose' => 'help', 'max_tokens' => 1500 ) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	if ( empty( $r['is_list'] ) ) {
		return array();
	}
	return array_slice( array_values( array_filter( array_map( function ( $x ) {
		return trim( sanitize_text_field( (string) $x ) );
	}, (array) $r['items'] ), 'strlen' ) ), 0, 60 );
}

/* ---------------------------------------------------------------------------
 * Progress reports (more than one report per task)
 * ------------------------------------------------------------------------- */

/**
 * Days a task counts as «long running» on the desk.
 */
function sap_long_days() {
	return max( 2, (int) sap_setting( 'long_task_days', 5 ) );
}

/**
 * When work on a task started (first «start», tick or progress report).
 */
function sap_task_started( $t ) {
	if ( '' !== $t['started_at'] ) {
		return $t['started_at'];
	}
	if ( $t['progress'] ) {
		return (string) $t['progress'][0]['at'];
	}
	return in_array( $t['status'], array( 'doing', 'waiting' ), true ) ? (string) $t['created_at'] : '';
}

/**
 * Whole days since work started (0 = today, null = not started).
 */
function sap_task_open_days( $t ) {
	$s = sap_task_started( $t );
	return '' === $s ? null : max( 0, sap_days_late( substr( $s, 0, 10 ) ) );
}

/**
 * Add a progress report: what was done today and, when stuck, why.
 * «Stuck» puts the task in «در انتظار» with the reason; the next normal report brings it back.
 *
 * @param string $state progress | blocked.
 * @return array|WP_Error
 */
function sap_task_progress_add( $task, $text, $state = 'progress', $why = '' ) {
	$text = trim( sanitize_textarea_field( (string) $text ) );
	$why  = trim( sanitize_text_field( (string) $why ) );
	if ( '' === $text ) {
		return new WP_Error( 'sap_prog', 'بنویسید امروز چه کاری روی این کار انجام شد.' );
	}
	if ( in_array( $task['status'], array( 'done', 'dropped', 'review' ), true ) ) {
		return new WP_Error( 'sap_prog', 'این کار بسته شده یا منتظر تأیید است.' );
	}
	$state = 'blocked' === $state ? 'blocked' : 'progress';
	if ( 'blocked' === $state && '' === $why ) {
		return new WP_Error( 'sap_prog', 'علت گیر کردن یا منتظر چه چیزی هستید را بنویسید.' );
	}
	$list   = $task['progress'];
	$list[] = array( 'u' => get_current_user_id(), 'at' => sap_now(), 'text' => mb_substr( $text, 0, 3000 ), 'state' => $state, 'why' => mb_substr( $why, 0, 300 ) );
	$data   = array( 'progress' => array_slice( $list, -200 ) );
	if ( 'blocked' === $state ) {
		$data['status']      = 'waiting';
		$data['waiting_for'] = $why;
	} elseif ( in_array( $task['status'], array( 'inbox', 'next', 'waiting', 'someday' ), true ) ) {
		$data['status']      = 'doing';
		$data['waiting_for'] = '';
	}
	sap_task_save( $data, $task['id'] );
	$t = sap_task( $task['id'] );
	if ( 'blocked' === $state ) {
		$who  = sap_user_name( get_current_user_id() );
		$days = sap_task_open_days( $t );
		$n    = array( 'icon' => '⚠️', 'title' => $who . ' در کار «' . $t['title'] . '» گیر کرده', 'text' => 'علت: ' . $why . ( $days ? ' · ' . sap_num( $days ) . ' روز از شروع' : '' ), 'url' => sap_url( 'desk' ) . '#long' );
		foreach ( sap_full_users() as $u ) {
			if ( (int) $u->ID !== get_current_user_id() ) {
				sap_notice_add( $u->ID, $n );
			}
		}
		if ( $t['created_by'] && 'pm' === sap_user_role( $t['created_by'] ) && $t['created_by'] !== get_current_user_id() ) {
			sap_notice_add( $t['created_by'], array_merge( $n, array( 'url' => sap_url( 'pm' ) . '#t-' . $t['id'] ) ) );
		}
	}
	return array( 'msg' => 'blocked' === $state ? 'گزارش ثبت شد و کار به «در انتظار» رفت؛ مدیر در جریان قرار گرفت.' : 'گزارش امروز ثبت شد. 👏', 'count' => count( $t['progress'] ), 'status' => $t['status'] );
}

/**
 * Open tasks running for N+ days since work started (desk «کارهای طول‌کشیده»).
 */
function sap_long_tasks() {
	$min = sap_long_days();
	$out = array();
	foreach ( sap_tasks( array( 'status' => array( 'doing', 'waiting' ) ) ) as $t ) {
		$d = sap_task_open_days( $t );
		if ( null !== $d && $d >= $min ) {
			$t['open_days'] = $d;
			$out[]          = $t;
		}
	}
	usort( $out, function ( $a, $b ) {
		return $b['open_days'] <=> $a['open_days'];
	} );
	return $out;
}

/**
 * Summary of a task's reports: count, last report, days since the last one, stuck now?
 */
function sap_progress_summary( $t ) {
	$list = $t['progress'];
	$last = $list ? end( $list ) : null;
	return array(
		'count'   => count( $list ),
		'last'    => $last,
		'quiet'   => $last ? sap_days_late( substr( $last['at'], 0, 10 ) ) : null,
		'blocked' => 'waiting' === $t['status'] && $last && 'blocked' === $last['state'],
		'stuck_n' => count( array_filter( $list, function ( $p ) {
			return 'blocked' === ( $p['state'] ?? '' );
		} ) ),
	);
}

/* ---------------------------------------------------------------------------
 * In-app notifications
 * ------------------------------------------------------------------------- */

/**
 * Add a notification for a user (kept: last 80).
 *
 * @param array $n {icon, title, text, url}
 */
function sap_notice_add( $uid, $n ) {
	$uid = (int) $uid;
	if ( ! $uid || ! get_userdata( $uid ) ) {
		return;
	}
	$list   = sap_notices( $uid );
	$list[] = array(
		'id'    => substr( md5( wp_json_encode( $n ) . microtime() ), 0, 10 ),
		'icon'  => (string) ( $n['icon'] ?? '🔔' ),
		'title' => mb_substr( (string) ( $n['title'] ?? '' ), 0, 200 ),
		'text'  => mb_substr( (string) ( $n['text'] ?? '' ), 0, 600 ),
		'url'   => (string) ( $n['url'] ?? '' ),
		'at'    => sap_now(),
		'read'  => 0,
	);
	update_user_meta( $uid, 'sap_notices', array_slice( $list, -80 ) );
}

/**
 * Notifications of a user, oldest first.
 */
function sap_notices( $uid ) {
	$l = get_user_meta( (int) $uid, 'sap_notices', true );
	return is_array( $l ) ? $l : array();
}

/**
 * Unread count.
 */
function sap_notice_unread( $uid ) {
	return count( array_filter( sap_notices( $uid ), function ( $n ) {
		return empty( $n['read'] );
	} ) );
}

/**
 * Mark all as read.
 */
function sap_notices_read( $uid ) {
	$l = sap_notices( $uid );
	foreach ( $l as &$n ) {
		$n['read'] = 1;
	}
	unset( $n );
	update_user_meta( (int) $uid, 'sap_notices', $l );
}

/**
 * Notifications from task changes: a new assignment, approval, a report for the
 * project manager who created the task, a decision on a proposal.
 */
add_action( 'sap_task_saved', function ( $before, $after ) {
	if ( ! $after ) {
		return;
	}
	$me   = get_current_user_id();
	$link = sap_url( 'my' ) . '#task-' . $after['id'];
	$pm   = $after['created_by'] && $after['created_by'] !== $me && 'pm' === sap_user_role( $after['created_by'] ) ? $after['created_by'] : 0;
	$pn   = sap_project( $after['project_id'] )['name'] ?? '';
	$was  = $before ? $before['status'] : '';
	if ( $after['assignee'] && $after['assignee'] !== $me && ( ! $before || $before['assignee'] !== $after['assignee'] || 'proposed' === $was ) && ! in_array( $after['status'], array( 'proposed', 'done', 'dropped' ), true ) ) {
		sap_notice_add( $after['assignee'], array( 'icon' => '📌', 'title' => 'کار جدید: ' . $after['title'], 'text' => $pn . ( $after['due_date'] ? ' · ددلاین ' . sap_jdate( $after['due_date'] ) : '' ), 'url' => $link ) );
	}
	if ( 'review' === $after['status'] && 'review' !== $was && $pm ) {
		sap_notice_add( $pm, array( 'icon' => '📝', 'title' => sap_user_name( $after['assignee'] ) . ' گزارش «' . $after['title'] . '» را ثبت کرد', 'text' => mb_substr( $after['result'], 0, 300 ), 'url' => sap_url( 'pm' ) . '#t-' . $after['id'] ) );
	}
	if ( 'done' === $after['status'] && 'done' !== $was ) {
		if ( 'review' === $was && $after['assignee'] && $after['assignee'] !== $me ) {
			sap_notice_add( $after['assignee'], array( 'icon' => '✅', 'title' => 'گزارش «' . $after['title'] . '» تأیید شد', 'text' => 'دمت گرم! در گزارش ماهانه ثبت شد.', 'url' => sap_url( 'my' ) ) );
		}
		if ( $pm ) {
			sap_notice_add( $pm, array( 'icon' => '✅', 'title' => 'کار «' . $after['title'] . '» انجام و تأیید شد', 'text' => sap_user_name( $after['assignee'] ), 'url' => sap_url( 'pm' ) . '#t-' . $after['id'] ) );
		}
	}
	if ( 'proposed' === $was && $pm ) {
		if ( 'dropped' === $after['status'] ) {
			sap_notice_add( $pm, array( 'icon' => '⛔', 'title' => 'پیشنهاد «' . $after['title'] . '» رد شد', 'url' => sap_url( 'pm' ) . '#t-' . $after['id'] ) );
		} elseif ( 'proposed' !== $after['status'] ) {
			sap_notice_add( $pm, array( 'icon' => '👍', 'title' => 'پیشنهاد «' . $after['title'] . '» تأیید و واگذار شد', 'text' => sap_user_name( $after['assignee'] ), 'url' => sap_url( 'pm' ) . '#t-' . $after['id'] ) );
		}
	}
}, 20, 2 );

/* ---------------------------------------------------------------------------
 * Question / request threads
 * ------------------------------------------------------------------------- */

/**
 * Messages of a question or request: [{u, a (1 = manager), text, at}].
 */
function sap_thread( $q ) {
	$t = json_decode( (string) ( $q['thread'] ?? '' ), true );
	if ( is_array( $t ) && $t ) {
		return $t;
	}
	// Rows from before threads: the question and its answer.
	$t = array( array( 'u' => (int) $q['user_id'], 'a' => 0, 'text' => (string) $q['question'], 'at' => (string) $q['created_at'] ) );
	if ( '' !== (string) ( $q['answer'] ?? '' ) ) {
		$t[] = array( 'u' => (int) $q['answered_by'], 'a' => 1, 'text' => (string) $q['answer'], 'at' => (string) $q['answered_at'] );
	}
	return $t;
}

/**
 * One question / request row.
 */
function sap_question( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'questions' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
}

/**
 * Append a message and set the status (open = waiting for a manager).
 */
function sap_thread_push( $q, $text, $from_manager ) {
	global $wpdb;
	$th   = sap_thread( $q );
	$th[] = array( 'u' => get_current_user_id(), 'a' => $from_manager ? 1 : 0, 'text' => $text, 'at' => sap_now() );
	$data = array( 'thread' => wp_json_encode( $th, JSON_UNESCAPED_UNICODE ), 'updated_at' => sap_now(), 'status' => $from_manager ? 'answered' : 'open' );
	if ( $from_manager ) {
		$data['answer']      = $text;
		$data['answered_by'] = get_current_user_id();
		$data['answered_at'] = sap_now();
	}
	$wpdb->update( sap_table( 'questions' ), $data, array( 'id' => (int) $q['id'] ) );
}

/**
 * A general request from a member (not tied to a task): only full-access managers see it.
 *
 * @return int|WP_Error
 */
function sap_request_add( $title, $text ) {
	global $wpdb;
	$title = trim( sanitize_text_field( (string) $title ) );
	$text  = trim( sanitize_textarea_field( (string) $text ) );
	if ( '' === $text ) {
		return new WP_Error( 'sap_req', 'متن درخواست را بنویسید.' );
	}
	if ( '' === $title ) {
		$title = mb_substr( preg_replace( '/\s+/u', ' ', $text ), 0, 60 );
	}
	$now = sap_now();
	$wpdb->insert( sap_table( 'questions' ), array(
		'project_id' => 0,
		'task_id'    => 0,
		'user_id'    => get_current_user_id(),
		'kind'       => 'request',
		'title'      => $title,
		'question'   => $text,
		'thread'     => wp_json_encode( array( array( 'u' => get_current_user_id(), 'a' => 0, 'text' => $text, 'at' => $now ) ), JSON_UNESCAPED_UNICODE ),
		'status'     => 'open',
		'created_at' => $now,
		'updated_at' => $now,
	) );
	$id = (int) $wpdb->insert_id;
	$me = sap_member( get_current_user_id() );
	sap_notify_full( '📨 درخواست تازه از ' . ( $me['name'] ?? '' ) . ': «' . mb_substr( $title, 0, 60 ) . '»', array(
		'title'     => 'یک درخواست منتظر پاسخ شماست',
		'badge'     => '📨 درخواست از تیم',
		'tone'      => '#0F766E',
		'preheader' => mb_substr( $text, 0, 120 ),
		'intro'     => ( $me['name'] ?? 'یکی از اعضا' ) . ' درخواستی ثبت کرده که فقط مدیران می‌بینند:',
		'rows'      => array( 'موضوع' => $title, 'متن' => $text ),
		'button'    => array( 'پاسخ در میز کار', sap_url( 'desk' ) . '#r-' . $id ),
	) );
	return $id;
}

/**
 * The asker continues the conversation: the thread goes back to «سوالات».
 *
 * @return true|WP_Error
 */
function sap_question_reply( $id, $text ) {
	$q    = sap_question( $id );
	$text = trim( sanitize_textarea_field( (string) $text ) );
	if ( ! $q || (int) $q['user_id'] !== get_current_user_id() ) {
		return new WP_Error( 'sap_q', 'این گفتگو پیدا نشد.', array( 'status' => 404 ) );
	}
	if ( '' === $text ) {
		return new WP_Error( 'sap_q', 'پیام خالی است.' );
	}
	sap_thread_push( $q, $text, false );
	$me    = sap_member( get_current_user_id() );
	$t     = $q['task_id'] ? sap_task( (int) $q['task_id'] ) : null;
	$about = 'request' === $q['kind'] ? $q['title'] : ( $t['title'] ?? '' );
	sap_notify_full( '💬 ' . ( $me['name'] ?? '' ) . ' گفتگو را ادامه داد: «' . mb_substr( $about, 0, 50 ) . '»', array(
		'title'  => 'پیام تازه در یک گفتگو',
		'badge'  => 'request' === $q['kind'] ? '📨 درخواست' : '❓ سوال درباره کار',
		'tone'   => '#7C3AED',
		'intro'  => ( $me['name'] ?? '' ) . ' بعد از پاسخ شما نوشته:',
		'rows'   => array( 'موضوع' => $about, 'پیام' => $text ),
		'button' => array( 'پاسخ در میز کار', sap_url( 'desk' ) . ( 'request' === $q['kind'] ? '#r-' : '#q-' ) . $q['id'] ),
	) );
	return true;
}

/**
 * Requests / questions of a user (member side), newest activity first.
 */
function sap_user_threads( $uid, $kind = 'request' ) {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'questions' ) . ' WHERE user_id = %d AND kind = %s ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 100', $uid, $kind ), ARRAY_A ); // phpcs:ignore
}

/**
 * Answered questions and requests (desk), last 45 days.
 */
function sap_threads_answered() {
	global $wpdb;
	$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 45 * DAY_IN_SECONDS ); // phpcs:ignore
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'questions' ) . " WHERE status = 'answered' AND COALESCE(updated_at, answered_at, created_at) >= %s ORDER BY COALESCE(updated_at, answered_at) DESC LIMIT 60", $since ), ARRAY_A ); // phpcs:ignore
}

/* ---------------------------------------------------------------------------
 * Monthly calendar of tasks (full-access managers)
 * ------------------------------------------------------------------------- */

/**
 * Tasks with a deadline in a Jalali month, grouped by date.
 *
 * @param string $period  «1405-08».
 * @param int    $project 0 = all.
 * @param int[]  $people  assignees to show (empty = all; 0 = no assignee).
 * @return array date => task[]
 */
function sap_calendar_tasks( $period, $project = 0, $people = array() ) {
	global $wpdb;
	list( $from, $to ) = sap_period_range( $period );
	$sql = 'SELECT * FROM ' . sap_table( 'tasks' ) . " WHERE due_date IS NOT NULL AND due_date BETWEEN %s AND %s AND status NOT IN ('dropped','proposed')";
	$arg = array( $from, $to );
	if ( $project ) {
		$sql  .= ' AND project_id = %d';
		$arg[] = (int) $project;
	}
	$rows = array_map( 'sap_task_hydrate', (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY due_date ASC, id ASC', $arg ) ) ); // phpcs:ignore
	$by   = array();
	foreach ( $rows as $t ) {
		if ( $people && ! in_array( $t['assignee'], $people, true ) ) {
			continue;
		}
		$by[ $t['due_date'] ][] = $t;
	}
	return $by;
}

/**
 * Weekday name (Saturday first) of a Gregorian date.
 */
function sap_weekday_fa( $ymd ) {
	$names = array( 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه' );
	return $names[ (int) gmdate( 'w', strtotime( $ymd ) ) ];
}

/* ---------------------------------------------------------------------------
 * Endpoints
 * ------------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	$ns = 'seoactionplan/v1';
	register_rest_route( $ns, '/task/(?P<id>\d+)/check', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task_for_user( $req['id'] );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$r = sap_checklist_tick( $t, (int) $req->get_param( 'i' ), rest_sanitize_boolean( $req->get_param( 'done' ) ) );
			return is_wp_error( $r ) ? $r : array_merge( array( 'ok' => true ), $r );
		},
	) );
	register_rest_route( $ns, '/task/(?P<id>\d+)/progress', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task_for_user( $req['id'] );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$r = sap_task_progress_add( $t, (string) $req->get_param( 'text' ), sanitize_key( (string) $req->get_param( 'state' ) ), (string) $req->get_param( 'why' ) );
			return is_wp_error( $r ) ? $r : array_merge( array( 'ok' => true ), $r );
		},
	) );
	register_rest_route( $ns, '/task-checklist', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return sap_can() || sap_is_pm();
		},
		'callback'            => function ( WP_REST_Request $req ) {
			$items = sap_ai_task_checklist( array( 'title' => (string) $req->get_param( 'title' ), 'notes' => (string) $req->get_param( 'notes' ) ) );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			return array( 'ok' => true, 'items' => $items, 'msg' => $items ? sprintf( '%s مورد ساخته شد؛ بررسی و ویرایش کنید.', sap_num( count( $items ) ) ) : 'این کار از واحدهای جدا تشکیل نشده؛ آیتم‌ها را خودتان بنویسید.' );
		},
	) );
	register_rest_route( $ns, '/thread/(?P<id>\d+)/reply', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$r = sap_question_reply( (int) $req['id'], (string) $req->get_param( 'text' ) );
			return is_wp_error( $r ) ? $r : array( 'ok' => true, 'msg' => 'پیام برای مدیر ارسال شد.' );
		},
	) );
	register_rest_route( $ns, '/request', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$r = sap_request_add( (string) $req->get_param( 'title' ), (string) $req->get_param( 'text' ) );
			return is_wp_error( $r ) ? $r : array( 'ok' => true, 'id' => $r, 'msg' => 'درخواست ثبت شد؛ پاسخ مدیر در «اعلان‌ها» و همین‌جا می‌آید.' );
		},
	) );
} );

/**
 * Work-area editor (admin-post).
 */
add_action( 'admin_post_sap_contexts_save', function () {
	sap_guard( 'sap_contexts_save' );
	$keys   = (array) sap_post( 'ctx_key', array() );
	$labels = (array) sap_post( 'ctx_label', array() );
	$del    = array_map( 'sanitize_key', (array) sap_post( 'ctx_del', array() ) );
	$rows   = array();
	foreach ( $labels as $i => $label ) {
		$key    = sanitize_key( (string) ( $keys[ $i ] ?? '' ) );
		$rows[] = array( 'key' => $key, 'label' => $label, 'del' => '' !== $key && in_array( $key, $del, true ) );
	}
	$r = sap_contexts_save( $rows );
	sap_back( sap_url( 'team' ) . '#contexts', is_wp_error( $r ) ? $r->get_error_message() : 'حوزه‌های کار ذخیره شد.', ! is_wp_error( $r ) );
} );
