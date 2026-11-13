<?php
/**
 * Reporting cycle and monthly commitments of a project.
 *
 * Each SEO project has a reporting day (e.g. the 1st or the 18th) and a set of
 * monthly commitments: new articles, content updates, a full technical analysis in
 * the first / last week, off-page (yes / no, budget, keywords) and the monthly
 * report. Every week (Saturday 08:00 by default) Sokan builds that week's tasks:
 * its share of the commitments, then side tasks from the analysis (findings) up to
 * a weekly hour cap — so nobody starts the week wondering what to do.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Commitments of a project with defaults.
 */
function sap_commit( $project ) {
	$c = (array) ( $project['options']['commit'] ?? array() );
	return array(
		'on'          => ! empty( $c['on'] ),
		'content_new' => max( 0, (int) ( $c['content_new'] ?? 0 ) ),
		'content_upd' => max( 0, (int) ( $c['content_upd'] ?? 0 ) ),
		'tech_first'  => ! isset( $c['tech_first'] ) || ! empty( $c['tech_first'] ),
		'tech_last'   => ! isset( $c['tech_last'] ) || ! empty( $c['tech_last'] ),
		'offpage'     => ! empty( $c['offpage'] ),
		'off_budget'  => (string) ( $c['off_budget'] ?? '' ),
		'off_kw'      => (string) ( $c['off_kw'] ?? '' ),
		'report'      => ! isset( $c['report'] ) || ! empty( $c['report'] ),
		'side_hours'  => max( 0, (float) ( $c['side_hours'] ?? 10 ) ),
		'week_start'  => isset( $c['week_start'] ) ? max( 0, min( 6, (int) $c['week_start'] ) ) : 6, // 6 = Saturday.
		'gen_hour'    => isset( $c['gen_hour'] ) ? max( 0, min( 23, (int) $c['gen_hour'] ) ) : 8,
		'assign'      => ! isset( $c['assign'] ) || ! empty( $c['assign'] ),
		'people'      => array_map( 'intval', (array) ( $c['people'] ?? array() ) ) + array( 'content' => 0, 'update' => 0, 'tech' => 0, 'offpage' => 0, 'report' => 0, 'side' => 0 ),
	);
}

/**
 * The work weeks of a period: weeks (from the week-start day) with at least four
 * days inside the cycle. Week 1 and the last week are the «first / last week».
 *
 * @return array[] {n, from, to}
 */
function sap_cycle_weeks( $project, $period ) {
	$c              = sap_commit( $project );
	list( $s, $e )  = sap_period_range( $period, $project['id'] );
	$ws             = $c['week_start'];
	$t              = strtotime( $s );
	$t             -= ( ( (int) gmdate( 'w', $t ) - $ws + 7 ) % 7 ) * DAY_IN_SECONDS; // Back to the week start.
	$weeks          = array();
	for ( ; $t <= strtotime( $e ); $t += 7 * DAY_IN_SECONDS ) {
		$from = gmdate( 'Y-m-d', $t );
		$to   = gmdate( 'Y-m-d', $t + 6 * DAY_IN_SECONDS );
		$in   = ( min( strtotime( $to ), strtotime( $e ) ) - max( strtotime( $from ), strtotime( $s ) ) ) / DAY_IN_SECONDS + 1;
		if ( $in >= 4 ) {
			$weeks[] = array( 'n' => count( $weeks ) + 1, 'from' => $from, 'to' => $to );
		}
	}
	return $weeks;
}

/**
 * Where a date sits: period, its weeks and the week number.
 *
 * @return array {period, weeks, n, week}
 */
function sap_cycle_locate( $project, $ymd = '' ) {
	$c      = sap_commit( $project );
	$ymd    = $ymd ?: current_time( 'Y-m-d' );
	$t      = strtotime( $ymd );
	$start  = $t - ( ( (int) gmdate( 'w', $t ) - $c['week_start'] + 7 ) % 7 ) * DAY_IN_SECONDS;
	$mid    = gmdate( 'Y-m-d', $start + 3 * DAY_IN_SECONDS ); // The 4th day decides the cycle.
	$period = sap_period_of( $mid, $project['id'] );
	$weeks  = sap_cycle_weeks( $project, $period );
	$n      = 0;
	foreach ( $weeks as $w ) {
		if ( gmdate( 'Y-m-d', $start ) === $w['from'] ) {
			$n = $w['n'];
		}
	}
	return array( 'period' => $period, 'weeks' => $weeks, 'n' => $n, 'week' => array( 'from' => gmdate( 'Y-m-d', $start ), 'to' => gmdate( 'Y-m-d', $start + 6 * DAY_IN_SECONDS ) ) );
}

/**
 * Share of a monthly count that falls in week n of W (spread evenly).
 */
function sap_cycle_quota( $total, $n, $w ) {
	if ( $total <= 0 || $w <= 0 || $n <= 0 ) {
		return 0;
	}
	// Front-loaded: the early weeks get the extra items, so the month never ends behind.
	return (int) ( ceil( $total * $n / $w ) - ceil( $total * ( $n - 1 ) / $w ) );
}

/**
 * Tasks the cycle already made for a period, by key.
 */
function sap_cycle_tasks( $project_id, $period ) {
	$out = array();
	foreach ( sap_tasks( array( 'project_id' => $project_id, 'period' => $period ) ) as $t ) {
		if ( 0 === strpos( (string) $t['recurring'], 'cyc_' ) ) {
			$out[ $t['recurring'] ] = $t;
		}
	}
	return $out;
}

/**
 * Last working day of a week (Thursday when the week starts on Saturday).
 */
function sap_cycle_due( $week, $cycle_end = '' ) {
	$due = gmdate( 'Y-m-d', strtotime( $week['from'] . ' +5 days' ) );
	if ( $cycle_end && $due > $cycle_end ) {
		$due = $cycle_end; // Never after the report date.
	}
	return max( $due, current_time( 'Y-m-d' ) ); // Built late in the week: not born overdue.
}

/**
 * Kind of content work a task is: «upd», «new» or ''. The title decides first
 * («آپدیت 3 محتوا» filed under content is still an update).
 */
function sap_cycle_task_kind( $t ) {
	$rec   = (string) $t['recurring'];
	$title = (string) $t['title'];
	if ( 0 === strpos( $rec, 'cyc_upd_' ) ) {
		return 'upd';
	}
	if ( 0 === strpos( $rec, 'cyc_new_' ) ) {
		return 'new';
	}
	if ( preg_match( '/تولید محتوا|محتوای جدید|مقاله جدید|نوشتن مقاله|نوشتن محتوا/u', $title ) ) {
		return 'new';
	}
	if ( preg_match( '/آپدیت|به‌روزرسانی محتوا|بروزرسانی محتوا|بازنویسی/u', $title ) ) {
		return 'upd';
	}
	return '';
}

/**
 * What a period already covers of the content commitments: planned (made) and
 * done, for new content and for updates. Any content task counts — made by the
 * cycle or by hand — a checklist counts one per item; published calendar items
 * and done updates without a task count too.
 *
 * @return array {new:{planned,done}, upd:{planned,done}}
 */
function sap_cycle_coverage( $project_id, $period ) {
	$out  = array( 'new' => array( 'planned' => 0, 'done' => 0 ), 'upd' => array( 'planned' => 0, 'done' => 0 ) );
	$live = array();
	foreach ( sap_tasks( array( 'project_id' => $project_id, 'period' => $period ) ) as $t ) {
		if ( 'dropped' === $t['status'] || 0 === strpos( (string) $t['recurring'], 'cyc_side_' ) ) {
			continue;
		}
		$kind = sap_cycle_task_kind( $t );
		if ( ! $kind ) {
			continue;
		}
		$live[ $t['id'] ]           = true;
		$items                      = (array) $t['checklist'];
		$w                          = max( 1, count( $items ) );
		$d                          = 'done' === $t['status'] ? $w : count( array_filter( $items, function ( $i ) {
			return ! empty( $i['d'] );
		} ) );
		$out[ $kind ]['planned'] += $w;
		$out[ $kind ]['done']    += $d;
	}
	foreach ( sap_calendar_items( $project_id, $period ) as $it ) {
		if ( 'published' === $it['status'] && ! isset( $live[ $it['task_id'] ] ) ) {
			++$out['new']['planned'];
			++$out['new']['done'];
		}
	}
	foreach ( sap_updates( $project_id, array( 'period' => $period, 'status' => array( 'done' ) ) ) as $u ) {
		if ( ! isset( $live[ $u['task_id'] ] ) ) {
			++$out['upd']['planned'];
			++$out['upd']['done'];
		}
	}
	return $out;
}

/**
 * Build the tasks of the week a date falls in. Safe to run again: every task has
 * a key, so nothing is made twice.
 *
 * @return array {added, side, period, n, of, msg}
 */
function sap_cycle_build_week( $project, $ymd = '' ) {
	$pid   = $project['id'];
	$c     = sap_commit( $project );
	$loc   = sap_cycle_locate( $project, $ymd );
	$P     = $loc['period'];
	$W     = count( $loc['weeks'] );
	$n     = $loc['n'];
	$week  = $loc['week'];
	$due   = sap_cycle_due( $week, sap_period_range( $P, $pid )[1] );
	$have  = sap_cycle_tasks( $pid, $P );
	$wk    = $week['from'];
	$added = 0;
	$make  = function ( $key, $data, $who ) use ( &$have, &$added, $pid, $P, $c, $due ) {
		if ( isset( $have[ $key ] ) ) {
			return 0;
		}
		$id = sap_task_save( array_merge( array(
			'project_id' => $pid,
			'period'     => $P,
			'status'     => 'next',
			'due_date'   => $due,
			'recurring'  => $key,
			'source'     => 'cycle',
			'assignee'   => $c['assign'] ? (int) ( $c['people'][ $who ] ?? 0 ) : 0,
		), $data ) );
		$have[ $key ] = sap_task( $id );
		++$added;
		return $id;
	};
	if ( ! $n ) {
		return array( 'added' => 0, 'side' => 0, 'period' => $P, 'n' => 0, 'of' => $W, 'msg' => 'این هفته جزو هفته‌های کاری دوره نیست.' );
	}
	$period_label = sap_period_label( $P ) . ' (' . sap_period_dates( $P, $pid ) . ')';

	// 1–2. Content: the week's cumulative share of the month minus what the period
	// already covers (cycle tasks, manual content tasks — a checklist counts per
	// item — and published / done items). Only real calendar items become tasks;
	// a shortfall is reported on the overview instead of a placeholder task.
	// Placeholder tasks of older versions («تولید محتوای جدید (۱ از تعهد …)»): dropped
	// when nobody started them; real calendar items replace them.
	foreach ( $have as $key => $t ) {
		if ( preg_match( '/^cyc_(new|upd)_.+_w\d+_\d+$/', $key ) && in_array( $t['status'], array( 'inbox', 'next' ), true ) && ! sap_progress_own( $t ) ) {
			sap_task_save( array( 'status' => 'dropped' ), $t['id'] );
			unset( $have[ $key ] );
		}
	}
	$cov   = sap_cycle_coverage( $pid, $P );
	$short = array( 'new' => 0, 'upd' => 0 );
	$need  = (int) ceil( $c['content_new'] * $n / max( 1, $W ) ) - $cov['new']['planned'];
	if ( $need > 0 ) {
		$free = array_values( array_filter( sap_calendar_items( $pid, $P ), function ( $it ) {
			return 'published' !== $it['status'] && ( ! $it['task_id'] || ! sap_task( $it['task_id'] ) );
		} ) );
		foreach ( array_slice( $free, 0, $need ) as $it ) {
			$tid = $make( 'cyc_new_' . $P . '_i' . $it['id'], array(
				'title'   => 'نوشتن محتوای جدید: «' . ( $it['title'] ?: $it['keyword'] ) . '»',
				'notes'   => trim( 'کلمه اصلی: ' . $it['keyword'] . "\n" . ( $it['brief'] ?? '' ) . ( ! empty( $it['outline'] ) ? "\n\nسرفصل‌ها:\n- " . implode( "\n- ", (array) $it['outline'] ) : '' ) ),
				'keyword' => $it['keyword'],
				'context' => 'content',
				'effort'  => 3,
			), 'content' );
			if ( $tid ) {
				sap_idea_save( array( 'task_id' => $tid ), $it['id'] );
			}
		}
		$short['new'] = max( 0, $need - count( $free ) );
	}
	$need = (int) ceil( $c['content_upd'] * $n / max( 1, $W ) ) - $cov['upd']['planned'];
	if ( $need > 0 ) {
		$ups = array_values( array_filter( sap_updates( $pid, array( 'period' => $P, 'status' => array( 'planned' ) ) ), function ( $u ) {
			return ! $u['task_id'] || ! sap_task( $u['task_id'] );
		} ) );
		foreach ( array_slice( $ups, 0, $need ) as $u ) {
			$tid = $make( 'cyc_upd_' . $P . '_u' . $u['id'], array(
				'title'   => 'آپدیت محتوا: «' . $u['title'] . '»',
				'notes'   => trim( ( $u['keyword'] ? 'کلمه اصلی: ' . $u['keyword'] . "\n" : '' ) . $u['reason'] ),
				'url'     => $u['url'],
				'keyword' => $u['keyword'],
				'context' => 'content_update',
				'effort'  => 2,
			), 'update' );
			if ( $tid ) {
				sap_update_save( array( 'task_id' => $tid ), $u['id'] );
			}
		}
		$short['upd'] = max( 0, $need - count( $ups ) );
	}
	sap_project_set_option( $pid, 'cycle_short_' . $P, $short );

	// 3. Full technical analysis in the first and the last week.
	$first = 1 === $n && $c['tech_first'];
	$last  = $W === $n && $c['tech_last'];
	if ( $first || $last ) {
		$make( 'cyc_tech_' . $P . '_' . ( $first ? 'first' : 'last' ), array(
			'title'     => 'آنالیز کامل تکنیکال — ' . ( $first ? 'هفته اول' : 'هفته آخر' ) . ' دوره ' . sap_period_label( $P ),
			'notes'     => 'بررسی کامل تکنیکال سایت و ثبت ایرادهای تازه در سکان. ' . ( $first ? 'خروجی: فهرست کارهای تکنیکال این دوره.' : 'خروجی: مقایسه با هفته اول دوره برای گزارش ماهانه.' ),
			'context'   => 'technical',
			'effort'    => 3,
			'checklist' => implode( "\n", array( 'کراول کامل با اسکریمینگ فراگ و بررسی خطاها', 'گزارش ایندکس سرچ کنسول (Crawled / Discovered – not indexed)', 'سایت‌مپ و robots.txt', 'ریدایرکت‌ها، زنجیره ریدایرکت، ۴۰۴ و ۴۱۰', 'Core Web Vitals و سرعت صفحات مهم', 'اسکیما و داده ساختاریافته', 'اجرای «تحلیل کامل» سکان و تأیید ایرادهای جدید' ) ),
		), 'tech' );
	}

	// 4. Off-page.
	if ( $c['offpage'] && 1 === $n ) {
		$make( 'cyc_off_' . $P . '_plan', array(
			'title'   => 'برنامه آف‌پیج دوره ' . sap_period_label( $P ),
			'notes'   => trim( ( '' !== $c['off_budget'] ? 'بودجه: ' . $c['off_budget'] . "\n" : '' ) . ( '' !== $c['off_kw'] ? 'کلمات هدف: ' . $c['off_kw'] . "\n" : '' ) . 'رسانه‌ها، انکرها و زمان انتشار را مشخص و هماهنگ کنید.' ),
			'context' => 'offpage',
			'effort'  => 2,
		), 'offpage' );
	}
	if ( $c['offpage'] && $W === $n && $W > 1 ) {
		$make( 'cyc_off_' . $P . '_check', array(
			'title'   => 'بررسی لینک‌های منتشرشده آف‌پیج دوره ' . sap_period_label( $P ),
			'notes'   => 'انتشار، ایندکس شدن و انکر هر لینک را چک و در گزارش ماه ثبت کنید.',
			'context' => 'offpage',
			'effort'  => 1,
		), 'offpage' );
	}

	// 5. Monthly report, due at the end of the cycle.
	if ( $c['report'] && $W === $n ) {
		list( , $end ) = sap_period_range( $P, $pid );
		$make( 'cyc_report_' . $P, array(
			'title'    => 'تهیه و ارسال گزارش سئو ' . $period_label,
			'notes'    => 'گزارش دوره: کارهای انجام‌شده، اثر اقدامات، وضعیت Focusها و برنامه دوره بعد.',
			'context'  => 'management',
			'effort'   => 2,
			'due_date' => $end,
		), 'report' );
	}

	// 6. Side tasks from the analysis, up to the weekly hour cap.
	$side = 0;
	if ( $c['side_hours'] > 0 ) {
		$used = 0.0;
		foreach ( $have as $key => $t ) {
			if ( 0 === strpos( $key, 'cyc_side_' . $wk ) ) {
				$used += $t['effort'];
			}
		}
		$people = array( 'technical' => 'tech', 'content_update' => 'update', 'onpage' => 'update', 'internal_links' => 'update', 'content' => 'content', 'offpage' => 'offpage' );
		foreach ( sap_findings( $pid, array( 'status' => 'open', 'limit' => 200 ) ) as $f ) {
			if ( $used >= $c['side_hours'] || $side >= 6 ) {
				break; // Hour cap, and at most six side tasks a week.
			}
			if ( $f['score'] < 40 || $used + $f['effort'] > $c['side_hours'] || ( $f['task_id'] && sap_task( $f['task_id'] ) ) ) {
				continue;
			}
			$tid = sap_finding_to_task( $f, $P );
			if ( $tid ) {
				$who = $people[ $f['context'] ] ?? 'side';
				sap_task_save( array( 'due_date' => $due, 'recurring' => 'cyc_side_' . $wk . '_f' . $f['id'], 'source' => 'cycle', 'assignee' => $c['assign'] ? (int) ( $c['people'][ $who ] ?: $c['people']['side'] ) : 0 ), $tid );
				$used += $f['effort'];
				++$side;
			}
		}
	}
	sap_project_set_option( $pid, 'cycle_built_' . $wk, sap_now() );
	return array(
		'added'  => $added + $side,
		'side'   => $side,
		'period' => $P,
		'n'      => $n,
		'of'     => $W,
		'msg'    => sprintf( 'هفته %s از %s دوره %s: %s کار اصلی و %s کار فرعی ساخته شد.', sap_num( $n ), sap_num( $W ), sap_period_label( $P ), sap_num( $added ), sap_num( $side ) ),
	);
}

/**
 * Commitment progress of a period: target, made, done for each kind.
 */
function sap_cycle_progress( $project, $period ) {
	$c   = sap_commit( $project );
	$out = array(
		'new'    => array( 'label' => 'محتوای جدید', 'target' => $c['content_new'], 'made' => 0, 'done' => 0 ),
		'upd'    => array( 'label' => 'آپدیت محتوا', 'target' => $c['content_upd'], 'made' => 0, 'done' => 0 ),
		'tech'   => array( 'label' => 'آنالیز تکنیکال', 'target' => ( $c['tech_first'] ? 1 : 0 ) + ( $c['tech_last'] ? 1 : 0 ), 'made' => 0, 'done' => 0 ),
		'off'    => array( 'label' => 'آف‌پیج', 'target' => $c['offpage'] ? 2 : 0, 'made' => 0, 'done' => 0 ),
		'report' => array( 'label' => 'گزارش ماهانه', 'target' => $c['report'] ? 1 : 0, 'made' => 0, 'done' => 0 ),
		'side'   => array( 'label' => 'کارهای فرعی (تحلیل‌ها)', 'target' => 0, 'made' => 0, 'done' => 0 ),
	);
	$cov = sap_cycle_coverage( $project['id'], $period );
	foreach ( array( 'new', 'upd' ) as $kk ) {
		$out[ $kk ]['made'] = $cov[ $kk ]['planned'];
		$out[ $kk ]['done'] = $cov[ $kk ]['done'];
	}
	foreach ( sap_cycle_tasks( $project['id'], $period ) as $key => $t ) {
		$kind = explode( '_', $key )[1] ?? '';
		if ( ! isset( $out[ $kind ] ) || in_array( $kind, array( 'new', 'upd' ), true ) || 'dropped' === $t['status'] ) {
			continue;
		}
		++$out[ $kind ]['made'];
		$out[ $kind ]['done'] += 'done' === $t['status'] ? 1 : 0;
	}
	return $out;
}

/**
 * Hourly: build the week's tasks of every project with commitments, once per week,
 * from the week-start day at the chosen hour (or later the same week if missed).
 */
add_action( 'sap_hourly', function () {
	$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	foreach ( sap_projects() as $p ) {
		if ( ! sap_project_is_seo( $p ) || 'active' !== ( $p['status'] ?? 'active' ) ) {
			continue;
		}
		$c = sap_commit( $p );
		if ( ! $c['on'] ) {
			continue;
		}
		$loc   = sap_cycle_locate( $p, gmdate( 'Y-m-d', $now ) );
		$first = strtotime( $loc['week']['from'] . ' ' . sprintf( '%02d:00:00', $c['gen_hour'] ) );
		if ( $now < $first || ! empty( $p['options'][ 'cycle_built_' . $loc['week']['from'] ] ) ) {
			continue;
		}
		$GLOBALS['sap_period_project'] = $p['id'];
		$res = sap_cycle_build_week( $p, gmdate( 'Y-m-d', $now ) );
		sap_period_project( 0 );
		if ( $res['added'] && function_exists( 'sap_notify_full' ) ) {
			sap_notify_full( '🗓️ ' . $p['name'] . ' — ' . $res['msg'], array(
				'title'  => 'کارهای این هفته ساخته شد',
				'badge'  => '🗓️ ' . $p['name'],
				'intro'  => $res['msg'],
				'button' => array( 'دیدن اکشن پلن', sap_project_url( $p['id'], 'plan' ) ),
			) );
		}
	}
} );

/* ---- Settings and manual run ------------------------------------------- */

add_action( 'admin_post_sap_commit_save', function () {
	sap_guard( 'sap_commit_save' );
	$pid = absint( sap_post( 'project_id' ) );
	$p   = sap_project( $pid );
	if ( ! $p ) {
		sap_back( sap_url(), 'پروژه پیدا نشد.', false );
	}
	$people = array();
	foreach ( array( 'content', 'update', 'tech', 'offpage', 'report', 'side' ) as $k ) {
		$people[ $k ] = absint( sap_post( 'who_' . $k ) );
	}
	sap_project_set_option( $pid, 'cycle_day', max( 1, min( 28, absint( sap_post( 'cycle_day', 1 ) ) ) ) );
	sap_project_set_option( $pid, 'commit', array(
		'on'          => sap_post( 'on' ) ? 1 : 0,
		'content_new' => absint( sap_post( 'content_new' ) ),
		'content_upd' => absint( sap_post( 'content_upd' ) ),
		'tech_first'  => sap_post( 'tech_first' ) ? 1 : 0,
		'tech_last'   => sap_post( 'tech_last' ) ? 1 : 0,
		'offpage'     => sap_post( 'offpage' ) ? 1 : 0,
		'off_budget'  => sanitize_text_field( (string) sap_post( 'off_budget' ) ),
		'off_kw'      => sanitize_text_field( (string) sap_post( 'off_kw' ) ),
		'report'      => sap_post( 'report' ) ? 1 : 0,
		'side_hours'  => max( 0, min( 60, (float) sap_post( 'side_hours', 10 ) ) ),
		'week_start'  => max( 0, min( 6, absint( sap_post( 'week_start', 6 ) ) ) ),
		'gen_hour'    => max( 0, min( 23, absint( sap_post( 'gen_hour', 8 ) ) ) ),
		'assign'      => sap_post( 'assign' ) ? 1 : 0,
		'people'      => $people,
	) );
	sap_back( sap_project_url( $pid ) . '#cycle', 'تعهدات ماهانه و دوره گزارش ذخیره شد.' );
} );

add_action( 'admin_post_sap_cycle_build', function () {
	sap_guard( 'sap_cycle_build' );
	$pid = absint( sap_post( 'project_id' ) );
	$p   = sap_project( $pid );
	if ( ! $p ) {
		sap_back( sap_url(), 'پروژه پیدا نشد.', false );
	}
	sap_period_project( $pid );
	$r = sap_cycle_build_week( $p );
	sap_back( sap_project_url( $pid ) . '#cycle', $r['msg'], (bool) $r['n'] );
} );
