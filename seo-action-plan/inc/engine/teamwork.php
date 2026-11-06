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
		// «متن مورد | تاریخ»: the item's own deadline (Jalali or Gregorian).
		$due = '';
		if ( preg_match( '/^(.*?)\s*\|\s*([0-9۰-۹\/\-.]{8,10})\s*$/u', $line, $mm ) ) {
			$line = trim( $mm[1] );
			$due  = sap_no_friday( sap_parse_date( $mm[2] ) );
		}
		if ( '' === $line ) {
			continue;
		}
		$line  = mb_substr( sanitize_text_field( $line ), 0, 240 );
		$prev  = $done[ sap_norm_text( $line ) ] ?? null;
		$out[] = $prev ? array( 't' => $line, 'd' => 1, 'by' => (int) ( $prev['by'] ?? 0 ), 'at' => (string) ( $prev['at'] ?? '' ), 'due' => $due ) : array( 't' => $line, 'd' => 0, 'by' => 0, 'at' => '', 'due' => $due );
		if ( count( $out ) >= 100 ) {
			break;
		}
	}
	return $out;
}

/**
 * Items as editor text: «متن | 2026-11-20» per line.
 */
function sap_checklist_to_text( $items ) {
	return implode( "\n", array_map( function ( $it ) {
		return $it['t'] . ( ! empty( $it['due'] ) ? ' | ' . $it['due'] : '' );
	}, (array) $items ) );
}

/**
 * A small grey date for an item (red when it is past and not done).
 */
function sap_checklist_due_tag( $it ) {
	if ( empty( $it['due'] ) ) {
		return '';
	}
	$late = empty( $it['d'] ) && $it['due'] < current_time( 'Y-m-d' );
	return '<time class="sap-ci-due' . ( $late ? ' is-late' : '' ) . '">' . esc_html( ( $late ? 'گذشته · ' : '' ) . sap_jdate( $it['due'], 'day' ) ) . '</time>';
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
		$due   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $it['due'] ?? '' ) ) ? (string) $it['due'] : '';
		$out[] = array( 't' => mb_substr( sanitize_text_field( (string) $it['t'] ), 0, 240 ), 'd' => empty( $it['d'] ) ? 0 : 1, 'by' => (int) ( $it['by'] ?? 0 ), 'at' => (string) ( $it['at'] ?? '' ), 'due' => $due );
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
	$pm = $t['created_by'] && 'pm' === sap_user_role( $t['created_by'] ) && $t['created_by'] !== get_current_user_id() ? $t['created_by'] : 0;
	// The coordinator who delegated it follows every report.
	$co = $t['coord_id'] && $t['coord_id'] !== get_current_user_id() && $t['coord_id'] !== $t['assignee'] ? $t['coord_id'] : 0;
	if ( $co ) {
		sap_notice_add( $co, array( 'icon' => 'blocked' === $state ? '⚠️' : '🗒️', 'title' => sap_user_name( get_current_user_id() ) . ( 'blocked' === $state ? ' در «' . $t['title'] . '» گیر کرده' : ' روی «' . $t['title'] . '» گزارش ثبت کرد' ), 'text' => 'blocked' === $state ? 'علت: ' . $why : $text, 'url' => sap_url( 'coord' ) . '#f-' . $t['id'] ) );
	}
	if ( 'blocked' !== $state && $pm ) {
		sap_notice_add( $pm, array( 'icon' => '🗒️', 'title' => sap_user_name( get_current_user_id() ) . ' روی «' . $t['title'] . '» گزارش روزانه ثبت کرد', 'text' => $text, 'url' => sap_url( 'pmp' ) . '?p=' . $t['project_id'] . '#t-' . $t['id'] ) );
	}
	if ( 'blocked' === $state ) {
		$who  = sap_user_name( get_current_user_id() );
		$days = sap_task_open_days( $t );
		$n    = array( 'icon' => '⚠️', 'title' => $who . ' در کار «' . $t['title'] . '» گیر کرده', 'text' => 'علت: ' . $why . ( $days ? ' · ' . sap_num( $days ) . ' روز از شروع' : '' ), 'url' => sap_url( 'desk' ) . '#long' );
		foreach ( sap_full_users() as $u ) {
			if ( (int) $u->ID !== get_current_user_id() ) {
				sap_notice_add( $u->ID, $n );
			}
		}
		if ( $pm ) {
			sap_notice_add( $pm, array_merge( $n, array( 'url' => sap_url( 'pmp' ) . '?p=' . $t['project_id'] . '#t-' . $t['id'] ) ) );
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
 * Progress entries written as reports (without delegation / follow-up notes).
 */
function sap_progress_own( $t ) {
	return array_values( array_filter( (array) $t['progress'], function ( $p ) {
		return ! in_array( $p['kind'] ?? '', array( 'delegate', 'follow' ), true );
	} ) );
}

/**
 * Summary of a task's reports: count, last report, days since the last one, stuck now?
 */
function sap_progress_summary( $t ) {
	// The person's own reports; a coordinator's «واگذار شد» / «پیگیری» notes are not reports.
	$list = sap_progress_own( $t );
	$last = $list ? end( $list ) : null;
	return array(
		'count'   => count( $list ),
		'notes'   => count( $t['progress'] ) - count( $list ),
		'last'    => $last,
		'quiet'   => $last ? sap_days_late( substr( $last['at'], 0, 10 ) ) : null,
		'blocked' => 'waiting' === $t['status'] && $last && 'blocked' === $last['state'],
		'stuck_n' => count( array_filter( $list, function ( $p ) {
			return 'blocked' === ( $p['state'] ?? '' );
		} ) ),
	);
}

/* ---------------------------------------------------------------------------
 * Weekend, repeating tasks, who reviews a report
 * ------------------------------------------------------------------------- */

/**
 * Friday is the weekend: a date that falls on it moves to Saturday.
 */
function sap_no_friday( $ymd ) {
	$ymd = (string) $ymd;
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
		return $ymd;
	}
	return 5 === (int) gmdate( 'w', strtotime( $ymd ) ) ? gmdate( 'Y-m-d', strtotime( $ymd . ' +1 day' ) ) : $ymd;
}

/**
 * Repeat choices.
 */
function sap_repeat_options() {
	return array( 'daily' => 'روزانه', 'weekly' => 'هفتگی', 'monthly' => 'ماهانه' );
}

/**
 * The next deadline of a repeating task (never in the past, never a Friday).
 */
function sap_repeat_next_due( $due, $every ) {
	$today = current_time( 'Y-m-d' );
	$base  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $due ) ? (string) $due : $today;
	if ( 'monthly' === $every ) {
		$ts               = strtotime( $base );
		list( $jy, $jm, $jd ) = sap_g2j( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
		do {
			$jm = 12 === $jm ? 1 : $jm + 1;
			$jy = 1 === $jm ? $jy + 1 : $jy;
			list( $gy, $gm, $gd ) = sap_j2g( $jy, $jm, min( $jd, sap_jmonth_days( $jy, $jm ) ) );
			$next = sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
		} while ( $next <= $today );
		return sap_no_friday( $next );
	}
	$step = 'weekly' === $every ? 7 : 1;
	$next = $base;
	do {
		$next = gmdate( 'Y-m-d', strtotime( $next . ' +' . $step . ' day' ) );
		if ( 1 === $step && 5 === (int) gmdate( 'w', strtotime( $next ) ) ) {
			$next = gmdate( 'Y-m-d', strtotime( $next . ' +1 day' ) );
		}
	} while ( $next <= $today );
	return sap_no_friday( $next );
}

/**
 * A repeating task was approved (done): its next copy is created at once —
 * same person, description and checklist (unticked, dates moved along).
 *
 * @return int new task id (0 = none)
 */
function sap_repeat_spawn( $t ) {
	if ( '' === $t['repeat_every'] || $t['repeat_next'] ) {
		return 0;
	}
	$due   = sap_repeat_next_due( $t['due_date'], $t['repeat_every'] );
	$shift = $t['due_date'] ? (int) round( ( strtotime( $due ) - strtotime( $t['due_date'] ) ) / DAY_IN_SECONDS ) : 0;
	$items = array_map( function ( $it ) use ( $shift ) {
		$d = '' !== $it['due'] ? sap_no_friday( gmdate( 'Y-m-d', strtotime( $it['due'] . ' ' . ( $shift >= 0 ? '+' : '' ) . $shift . ' day' ) ) ) : '';
		return array( 't' => $it['t'], 'd' => 0, 'by' => 0, 'at' => '', 'due' => $d );
	}, $t['checklist'] );
	$id = sap_task_save( array(
		'project_id'   => $t['project_id'],
		'focus_id'     => $t['focus_id'],
		'title'        => $t['title'],
		'notes'        => $t['notes'],
		'outcome'      => $t['outcome'],
		'context'      => $t['context'],
		'assignee'     => $t['assignee'],
		'effort'       => $t['effort'],
		'url'          => $t['url'],
		'keyword'      => $t['keyword'],
		'due_date'     => $due,
		'period'       => sap_period_of( $due ),
		'status'       => 'next',
		'source'       => 'repeat',
		'repeat_every' => $t['repeat_every'],
		'checklist'    => $items,
		'created_by'   => $t['created_by'],
	) );
	if ( $id ) {
		sap_task_save( array( 'repeat_next' => $id ), $t['id'] );
	}
	return $id;
}
add_action( 'sap_task_saved', function ( $before, $after ) {
	if ( $after && 'done' === $after['status'] && ( ! $before || 'done' !== $before['status'] ) && '' !== $after['repeat_every'] && ! $after['repeat_next'] ) {
		sap_repeat_spawn( $after );
	}
}, 30, 2 );

/**
 * Who may approve a submitted report: full-access managers, and the project
 * manager who created the task.
 */
function sap_can_review( $t ) {
	return sap_can() || ( $t && sap_is_pm() && (int) $t['created_by'] === get_current_user_id() );
}

/**
 * Reports waiting for the current project manager (tasks they created).
 */
function sap_pm_reviews( $uid = 0 ) {
	global $wpdb;
	$uid = $uid ?: get_current_user_id();
	return array_map( 'sap_task_hydrate', (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'tasks' ) . " WHERE created_by = %d AND status = 'review' ORDER BY submitted_at ASC", $uid ) ) ); // phpcs:ignore
}

/**
 * Where a task stands, for the project manager's lists:
 * proposed | review | done | dropped | stuck | late | open.
 */
function sap_task_bucket( $t ) {
	if ( in_array( $t['status'], array( 'proposed', 'review', 'done', 'dropped' ), true ) ) {
		return $t['status'];
	}
	if ( sap_progress_summary( $t )['blocked'] ) {
		return 'stuck';
	}
	return $t['due_date'] && $t['due_date'] < current_time( 'Y-m-d' ) ? 'late' : 'open';
}

/**
 * Chip [text, class] for a task's state.
 */
function sap_task_state_chip( $t ) {
	$b = sap_task_bucket( $t );
	switch ( $b ) {
		case 'proposed':
			return array( 'منتظر تأیید مدیر سئو', 'is-warn' );
		case 'review':
			return array( 'گزارش ثبت شد · منتظر تأیید', 'is-info' );
		case 'done':
			return array( 'انجام شد', 'is-good' );
		case 'dropped':
			return array( 'رد / حذف شد', 'is-bad' );
		case 'stuck':
			return array( '⚠️ گیر کرده', 'is-bad' );
		case 'late':
			return array( sap_num( sap_days_late( $t['due_date'] ) ) . ' روز تأخیر', 'is-bad' );
	}
	return 'doing' === $t['status'] ? array( 'در حال انجام', 'is-info' ) : array( 'هنوز شروع نشده', 'is-soft' );
}

/**
 * Today's progress reports on the tasks a project manager created.
 *
 * @return array[] {task, entry}
 */
function sap_pm_today_reports( $uid = 0 ) {
	global $wpdb;
	$uid   = $uid ?: get_current_user_id();
	$today = current_time( 'Y-m-d' );
	$out   = array();
	$rows  = array_map( 'sap_task_hydrate', (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'tasks' ) . ' WHERE created_by = %d AND progress LIKE %s', $uid, '%' . $wpdb->esc_like( $today ) . '%' ) ) ); // phpcs:ignore
	foreach ( $rows as $t ) {
		foreach ( $t['progress'] as $e ) {
			if ( 0 === strpos( (string) $e['at'], $today ) ) {
				$out[] = array( 'task' => $t, 'entry' => $e );
			}
		}
	}
	usort( $out, function ( $a, $b ) {
		return strcmp( $b['entry']['at'], $a['entry']['at'] );
	} );
	return $out;
}

/* ---------------------------------------------------------------------------
 * Reminder: ask the person for a status report
 * ------------------------------------------------------------------------- */

/**
 * Who may send a reminder on a task: full managers, and the project manager who assigned it.
 */
function sap_can_nudge( $t ) {
	return $t && $t['assignee'] && ! in_array( $t['status'], array( 'done', 'dropped', 'proposed' ), true )
		&& ( sap_can() || ( sap_is_pm() && (int) $t['created_by'] === get_current_user_id() ) || (int) $t['coord_id'] === get_current_user_id() )
		&& (int) $t['assignee'] !== get_current_user_id();
}

/**
 * A reminder that is still waiting for an answer (no daily report or final report since).
 */
function sap_nudge_open( $t ) {
	if ( '' === $t['nudged_at'] || in_array( $t['status'], array( 'done', 'dropped', 'review' ), true ) ) {
		return false;
	}
	$own  = sap_progress_own( $t );
	$last = $own ? (string) end( $own )['at'] : '';
	return $last < $t['nudged_at'];
}

/**
 * Send the reminder: an in-app notification and an email asking for a status report.
 *
 * @return array|WP_Error
 */
function sap_task_nudge( $t, $note = '' ) {
	if ( ! sap_can_nudge( $t ) ) {
		return new WP_Error( 'sap_nudge', 'برای این کار نمی‌توانید یادآوری بفرستید (کار بسته شده، مسئول ندارد یا شما آن را واگذار نکرده‌اید).', array( 'status' => 403 ) );
	}
	if ( '' !== $t['nudged_at'] && strtotime( $t['nudged_at'] ) > strtotime( sap_now() ) - 10 * MINUTE_IN_SECONDS ) {
		return new WP_Error( 'sap_nudge', 'همین چند دقیقه پیش یادآوری فرستاده شد؛ کمی بعد دوباره امتحان کنید.' );
	}
	$note = trim( sanitize_textarea_field( (string) $note ) );
	$me   = get_current_user_id();
	sap_task_save( array( 'nudged_at' => sap_now(), 'nudged_by' => $me, 'nudge_note' => $note ), $t['id'] );
	$m     = sap_member( $t['assignee'] );
	$p     = sap_project( $t['project_id'] );
	$who   = sap_user_name( $me );
	$link  = sap_url( 'my' ) . '#task-' . $t['id'];
	$days  = sap_task_open_days( $t );
	sap_notice_add( $t['assignee'], array(
		'icon'  => '📣',
		'title' => $who . ' وضعیت کار «' . $t['title'] . '» را می‌خواهد',
		'text'  => '' !== $note ? $note : 'لطفاً در «گزارش‌های روزانه» همین کار بنویس به کجا رسیده و اگر گیر کرده، علتش چیست.',
		'url'   => $link,
	) );
	$mail = false;
	if ( $m && $m['notify_email'] ) {
		$mail = (bool) sap_notify_email( $m['email'], '📣 ' . sap_first_name( $m['name'] ) . ' عزیز، گزارش وضعیت «' . mb_substr( $t['title'], 0, 60 ) . '» را بفرست', array(
			'title'     => 'یادآوری: این کار به کجا رسید؟',
			'badge'     => '📣 درخواست گزارش وضعیت · ' . ( $p['name'] ?? '' ),
			'tone'      => '#D97706',
			'preheader' => '' !== $note ? mb_substr( $note, 0, 120 ) : $who . ' وضعیت این کار را می‌خواهد.',
			'intro'     => $m['name'] . ' عزیز، ' . $who . ' وضعیت کار زیر را خواسته. در پنل، روی همین کار «ثبت گزارش امروز» را بزن و بنویس چه کرده‌ای و اگر گیر کرده، علتش چیست. اگر تمام شده، گزارش نهایی را ثبت کن.',
			'rows'      => array_filter( array(
				'کار'            => $t['title'],
				'پروژه'          => $p['name'] ?? '',
				'ددلاین'         => $t['due_date'] ? sap_jdate( $t['due_date'] ) : '—',
				'وضعیت فعلی'     => sap_task_statuses()[ $t['status'] ] ?? $t['status'],
				'از شروع'        => null !== $days ? sap_num( $days ) . ' روز' : '',
				'پیام ' . $who   => $note,
			) ),
			'button'    => array( 'ثبت گزارش وضعیت', $link ),
		) );
	}
	$name = $m['name'] ?? 'مسئول';
	return array( 'msg' => 'یادآوری برای ' . $name . ' ارسال شد (اعلان در پنل' . ( $mail ? ' + ایمیل' : '' ) . ').', 'at' => sap_jdate( substr( sap_now(), 0, 10 ), 'day' ) . ' ' . sap_fa( substr( sap_now(), 11, 5 ) ) );
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
		sap_notice_add( $after['assignee'], array( 'icon' => 'repeat' === $after['source'] && ! $before ? '🔁' : '📌', 'title' => ( 'repeat' === $after['source'] && ! $before ? 'نوبت بعدی کار تکراری: ' : 'کار جدید: ' ) . $after['title'], 'text' => $pn . ( $after['due_date'] ? ' · ددلاین ' . sap_jdate( $after['due_date'] ) : '' ), 'url' => $link ) );
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
 * A task belongs in a calendar: its person is among $people (empty = all) and,
 * with $owner, it is that user's own task or one they assigned.
 */
function sap_task_in_scope( $t, $people = array(), $owner = 0 ) {
	if ( $owner && $t['assignee'] !== (int) $owner && $t['created_by'] !== (int) $owner && $t['coord_id'] !== (int) $owner ) {
		return false;
	}
	return ! $people || in_array( $t['assignee'], $people, true );
}

/**
 * Tasks with a deadline in a Jalali month, grouped by date.
 *
 * @param string $period  «1405-08».
 * @param int    $project 0 = all.
 * @param int[]  $people  assignees to show (empty = all; 0 = no assignee).
 * @return array date => task[]
 */
function sap_calendar_tasks( $period, $project = 0, $people = array(), $owner = 0 ) {
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
		if ( ! sap_task_in_scope( $t, $people, $owner ) ) {
			continue;
		}
		$by[ $t['due_date'] ][] = $t;
	}
	return $by;
}

/**
 * Checklist items with their own date in a month: date => [ {task, i, item} ].
 */
function sap_calendar_check_items( $period, $project = 0, $people = array(), $owner = 0 ) {
	global $wpdb;
	list( $from, $to ) = sap_period_range( $period );
	$sql = 'SELECT * FROM ' . sap_table( 'tasks' ) . " WHERE checklist LIKE %s AND status NOT IN ('dropped','proposed')";
	$arg = array( '%"due":"2%' );
	if ( $project ) {
		$sql  .= ' AND project_id = %d';
		$arg[] = (int) $project;
	}
	$by = array();
	foreach ( array_map( 'sap_task_hydrate', (array) $wpdb->get_results( $wpdb->prepare( $sql, $arg ) ) ) as $t ) { // phpcs:ignore
		if ( ! sap_task_in_scope( $t, $people, $owner ) ) {
			continue;
		}
		foreach ( $t['checklist'] as $i => $it ) {
			if ( '' !== $it['due'] && $it['due'] >= $from && $it['due'] <= $to ) {
				$by[ $it['due'] ][] = array( 'task' => $t, 'i' => $i, 'item' => $it );
			}
		}
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
	register_rest_route( $ns, '/task/(?P<id>\d+)/nudge', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task( (int) $req['id'] );
			if ( ! $t ) {
				return new WP_Error( 'sap_task', 'کار پیدا نشد.', array( 'status' => 404 ) );
			}
			$r = sap_task_nudge( $t, (string) $req->get_param( 'note' ) );
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
