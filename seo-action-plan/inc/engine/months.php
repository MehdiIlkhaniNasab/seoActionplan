<?php
/**
 * Monthly record («کارنامه ماه»): what was planned, done and published in a
 * Jalali month, and the plan of the next one.
 *
 * The live data (tasks, activity, revisions, ideas) can be deleted — a reset to
 * rebuild the plan from scratch, a deleted task, a reinstall — so every month
 * also keeps a snapshot in {prefix}sap_months. A snapshot is only ever merged
 * into (done work is added, never removed), so rebuilding the plan three or
 * four times in a month never loses what was already done, and the monthly SEO
 * report always gets the full month.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stored snapshot of a month (or empty array).
 */
function sap_month_get( $project_id, $period ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'months' ) . ' WHERE project_id = %d AND period = %s', $project_id, $period ), ARRAY_A ); // phpcs:ignore
	if ( ! $row ) {
		return array();
	}
	$data               = json_decode( (string) $row['data'], true ) ?: array();
	$data['updated_at'] = $row['updated_at'];
	return $data;
}

/**
 * Archived months of a project (newest first): period => summary.
 */
function sap_months( $project_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT period, data, updated_at FROM ' . sap_table( 'months' ) . ' WHERE project_id = %d ORDER BY period DESC', $project_id ), ARRAY_A ); // phpcs:ignore
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$d                    = json_decode( (string) $r['data'], true ) ?: array();
		$d['updated_at']      = $r['updated_at'];
		$out[ $r['period'] ] = $d;
	}
	return $out;
}

/**
 * Key that identifies "the same" piece of work across rebuilds.
 */
function sap_month_item_key( $title, $url = '' ) {
	return md5( sap_norm_text( (string) $title ) . '|' . sap_norm_url( (string) $url ) );
}

/**
 * Live data of a month, computed from the current tables.
 */
function sap_month_live( $project, $period ) {
	list( $from, $to ) = sap_period_range( $period );
	$pid     = $project['id'];
	$fnames  = wp_list_pluck( sap_focuses( $pid ), 'name', 'id' );
	$tasks   = sap_tasks( array( 'project_id' => $pid, 'period' => $period ) );
	$planned = array();
	$done    = array();
	foreach ( $tasks as $t ) {
		if ( in_array( $t['status'], array( 'dropped', 'someday' ), true ) ) {
			continue;
		}
		$item = array(
			'title'          => $t['title'],
			'category'       => $t['context'],
			'category_label' => sap_context_label( $t['context'] ),
			'focus'          => $fnames[ $t['focus_id'] ] ?? '',
			'url'            => $t['url'],
			'effort'         => (float) $t['effort'],
			'result'         => (string) $t['result'],
			'done_at'        => (string) $t['done_at'],
		);
		$planned[ sap_month_item_key( $t['title'], $t['url'] ) ] = $item;
		if ( 'done' === $t['status'] ) {
			$done[ sap_month_item_key( $t['title'], $t['url'] ) ] = $item;
		}
	}

	// Content: AI rewrites published to the site, calendar articles published.
	$content = array();
	foreach ( sap_revisions( $pid, '', 300 ) as $r ) {
		if ( in_array( $r['status'], array( 'published', 'applied' ), true ) && substr( $r['updated_at'], 0, 10 ) >= $from && substr( $r['updated_at'], 0, 10 ) <= $to ) {
			$content[ sap_month_item_key( 'rev', $r['url'] ) ] = array( 'kind' => 'update', 'title' => $r['title'] ?: sap_page_title( $pid, $r['url'] ), 'url' => $r['url'], 'date' => substr( $r['updated_at'], 0, 10 ) );
		}
	}
	foreach ( sap_updates( $pid, array( 'status' => 'done' ) ) as $u ) {
		$d = substr( (string) $u['done_at'], 0, 10 );
		if ( $d >= $from && $d <= $to ) {
			$content[ sap_month_item_key( 'rev', $u['url'] ) ] = array( 'kind' => 'update', 'title' => $u['title'], 'url' => $u['url'], 'keyword' => $u['keyword'], 'date' => $d );
		}
	}
	foreach ( sap_ideas( $pid, array( 'status' => array( 'published' ) ) ) as $i ) {
		$d = (string) ( $i['planned_date'] ?: substr( $i['updated_at'], 0, 10 ) );
		if ( $i['period'] === $period || ( $d >= $from && $d <= $to ) ) {
			$content[ sap_month_item_key( 'new', $i['published_url'] ?: $i['keyword'] ) ] = array( 'kind' => 'new', 'title' => $i['title'] ?: $i['keyword'], 'keyword' => $i['keyword'], 'url' => $i['published_url'], 'date' => $d );
		}
	}

	$focuses = array();
	foreach ( sap_focuses( $pid ) as $f ) {
		$k         = sap_focus_kpis( $f, $pid );
		$metric    = $f['kpi_metric'];
		$focuses[] = array(
			'name'         => $f['name'],
			'goal'         => $f['goal'],
			'status'       => $f['status'],
			'metric'       => $metric,
			'metric_label' => sap_kpi_metrics()[ $metric ] ?? $metric,
			'baseline'     => $f['kpi_baseline'],
			'target'       => $f['kpi_target'],
			'current'      => $k ? $k[ $metric ] : null,
			'progress'     => $k ? sap_focus_progress( $f, $k[ $metric ] ) : null,
			'deadline'     => $f['deadline'],
		);
	}

	$next      = sap_period_shift( $period, 1 );
	$next_plan = array();
	foreach ( sap_tasks( array( 'project_id' => $pid, 'period' => $next, 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) ) as $t ) {
		$next_plan[] = array(
			'title'          => $t['title'],
			'category'       => $t['context'],
			'category_label' => sap_context_label( $t['context'] ),
			'focus'          => $fnames[ $t['focus_id'] ] ?? '',
			'url'            => $t['url'],
			'effort'         => (float) $t['effort'],
		);
	}

	return array(
		'period'    => $period,
		'from'      => $from,
		'to'        => $to,
		'planned'   => $planned,
		'done'      => $done,
		'content'   => $content,
		'activity'  => sap_activity_export( $project, $from, $to ),
		'focuses'   => $focuses,
		'next_plan' => $next_plan,
	);
}

/**
 * Merge live data into a stored snapshot: work only accumulates.
 */
function sap_month_merge( $snap, $live ) {
	if ( ! $snap ) {
		return $live;
	}
	$out = $live;
	foreach ( array( 'planned', 'done', 'content' ) as $k ) {
		$out[ $k ] = (array) $live[ $k ] + (array) ( $snap[ $k ] ?? array() );
	}
	// Activity: keep entries that were deleted from the live log.
	$items = array();
	foreach ( array_merge( (array) ( $snap['activity']['items'] ?? array() ), (array) $live['activity']['items'] ) as $it ) {
		$items[ $it['id'] . '|' . md5( $it['title'] . $it['date'] ) ] = $it;
	}
	if ( count( $items ) > count( $live['activity']['items'] ) ) {
		usort( $items, function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		} );
		$groups = array();
		$lines  = array();
		foreach ( $items as $it ) {
			$groups[ $it['category_label'] ] = ( $groups[ $it['category_label'] ] ?? 0 ) + 1;
			$lines[]                         = $it['category_label'] . ' | ' . $it['title'] . ( $it['url'] ? ' — ' . rawurldecode( $it['url'] ) : '' );
		}
		$out['activity'] = array(
			'count'           => count( $items ),
			'by_category'     => $groups,
			'items'           => array_values( $items ),
			'work_done_lines' => implode( "\n", $lines ),
		);
	}
	foreach ( array( 'focuses', 'next_plan' ) as $k ) {
		if ( empty( $live[ $k ] ) && ! empty( $snap[ $k ] ) ) {
			$out[ $k ] = $snap[ $k ];
		}
	}
	$out['resets'] = (int) ( $snap['resets'] ?? 0 );
	return $out;
}

/**
 * Refresh (merge) and store the snapshot of a month. Returns the merged month.
 */
function sap_month_snapshot( $project, $period, $extra = array() ) {
	global $wpdb;
	$snap = sap_month_get( $project['id'], $period );
	$data = sap_month_merge( $snap, sap_month_live( $project, $period ) );
	foreach ( $extra as $k => $v ) {
		$data[ $k ] = $v;
	}
	unset( $data['updated_at'] );
	$json = wp_json_encode( $data );
	if ( $snap ) {
		$wpdb->update( sap_table( 'months' ), array( 'data' => $json, 'updated_at' => sap_now() ), array( 'project_id' => (int) $project['id'], 'period' => $period ) );
	} else {
		$wpdb->insert( sap_table( 'months' ), array( 'project_id' => (int) $project['id'], 'period' => $period, 'data' => $json, 'updated_at' => sap_now() ) );
	}
	return $data;
}

/**
 * Report-ready month: what SeoReport reads (REST /report and direct calls).
 *
 * @param string $from Optional Gregorian range (the report's own dates); the
 *                     month is the one that contains $from.
 */
function sap_month_report( $project, $period = '', $from = '', $to = '' ) {
	if ( $from && $to ) {
		$period = sap_period_of( $from );
	}
	$period = sap_period_sanitize( $period ?: sap_period_current() );
	$m      = sap_month_snapshot( $project, $period );
	if ( $from && $to && ( $from !== $m['from'] || $to !== $m['to'] ) ) {
		$m['activity'] = sap_activity_export( $project, $from, $to ); // A custom range: the log of exactly that range.
	} else {
		list( $from, $to ) = array( $m['from'], $m['to'] );
	}
	$planned = array_values( $m['planned'] );
	$done    = array_values( $m['done'] );
	$content = array_values( $m['content'] );
	$next    = sap_period_shift( $period, 1 );
	return array(
		'project'        => array( 'id' => $project['id'], 'name' => $project['name'], 'site' => $project['site_url'] ),
		'period'         => array( 'key' => $period, 'label' => sap_period_label( $period ), 'from' => $from, 'to' => $to ),
		'activity'       => $m['activity'],
		'plan'           => array(
			'tasks_planned' => count( $planned ),
			'tasks_done'    => count( $done ),
			'hours_planned' => array_sum( array_column( $planned, 'effort' ) ),
			'hours_done'    => array_sum( array_column( $done, 'effort' ) ),
			'done'          => $done,
		),
		'content'        => array(
			'new'     => array_values( array_filter( $content, function ( $c ) {
				return 'new' === $c['kind'];
			} ) ),
			'updated' => array_values( array_filter( $content, function ( $c ) {
				return 'update' === $c['kind'];
			} ) ),
		),
		'next_plan'      => array(
			'period' => array( 'key' => $next, 'label' => sap_period_label( $next ) ),
			'items'  => $m['next_plan'],
			'lines'  => implode( "\n", array_map( function ( $i ) {
				return $i['category_label'] . ' | ' . $i['title'];
			}, $m['next_plan'] ) ),
		),
		'focuses'        => $m['focuses'],
		'resets'         => (int) ( $m['resets'] ?? 0 ),
		'generated_at'   => sap_now(),
		'plugin_version' => SAP_VERSION,
	);
}

/**
 * Find the Sokan project of a site (SeoReport on the same WordPress).
 */
function sap_project_for_domain( $domain ) {
	$domain = sap_clean_domain( $domain );
	foreach ( sap_projects() as $p ) {
		if ( '' !== $domain && sap_project_domain( $p ) === $domain ) {
			return $p;
		}
	}
	return null;
}

/**
 * Parts a reset can clear, with what each one removes.
 */
function sap_reset_parts() {
	return array(
		'plan'      => array( 'اکشن پلن و یافته‌ها', 'همه کارهای همه ماه‌ها، یافته‌ها و دیدگاه‌های روی آن‌ها' ),
		'analysis'  => array( 'داده‌های دریافتی و تحلیل هوش مصنوعی', 'داده سرچ کنسول، شیت، سئو سیگنال، خوانده‌شده از صفحات و همه خروجی‌های هوش مصنوعی؛ «تحلیل کامل» از صفر انجام می‌شود' ),
		'keymap'    => array( 'نقشه کلمات', 'کلمه اصلی و فرعی صفحات، حتی موارد تأییدشده دستی' ),
		'ideas'     => array( 'ایده‌ها و تقویم محتوا', 'ایده‌ها و مقالات زمان‌بندی‌شده (خود گوگل شیت دست نمی‌خورد)' ),
		'revisions' => array( 'بازنویسی‌های محتوا', 'پیشنهادهای بازنویسی و تاریخچه آن‌ها (چیزی از سایت حذف نمی‌شود)' ),
		'focuses'   => array( 'Focusها (اهداف)', 'اهداف و KPIهای پروژه' ),
		'log'       => array( 'لاگ اقدامات', 'تاریخچه کارهای انجام‌شده و تغییرات سایت؛ کارنامه ماه‌ها جدا نگه داشته می‌شود' ),
		'archive'   => array( 'کارنامه ماه‌ها', 'خلاصه ذخیره‌شده هر ماه که گزارش سئو از آن استفاده می‌کند' ),
	);
}

/**
 * Clear parts of a project to start over. Settings and connections stay.
 * The current and previous month are snapshotted first, so the monthly report
 * keeps the work that was already done (unless the archive itself is cleared).
 *
 * @param string[] $parts Keys of sap_reset_parts().
 * @return string[] Labels of the parts cleared.
 */
function sap_project_reset( $project_id, $parts ) {
	global $wpdb;
	$project = sap_project( $project_id );
	if ( ! $project ) {
		return array();
	}
	$parts = array_values( array_intersect( (array) $parts, array_keys( sap_reset_parts() ) ) );
	if ( ! $parts ) {
		return array();
	}
	$cur = sap_period_current();
	if ( ! in_array( 'archive', $parts, true ) ) {
		foreach ( array( sap_period_shift( $cur, -1 ), $cur ) as $per ) {
			$snap = sap_month_get( $project_id, $per );
			sap_month_snapshot( $project, $per, $per === $cur ? array( 'resets' => (int) ( $snap['resets'] ?? 0 ) + 1 ) : array() );
		}
	}
	$id  = (int) $project_id;
	$del = function ( $table, $where = '' ) use ( $wpdb, $id ) {
		$wpdb->query( 'DELETE FROM ' . sap_table( $table ) . $wpdb->prepare( ' WHERE project_id = %d', $id ) . $where ); // phpcs:ignore
	};
	foreach ( $parts as $part ) {
		switch ( $part ) {
			case 'plan':
				$del( 'tasks' );
				$del( 'findings' );
				$del( 'comments', " AND object_type IN ('task','finding')" );
				$opts = $project['options'];
				foreach ( array_keys( $opts ) as $k ) {
					if ( 0 === strpos( $k, 'plan_generated_' ) ) {
						unset( $opts[ $k ] );
					}
				}
				$wpdb->update( sap_table( 'projects' ), array( 'options' => wp_json_encode( $opts ) ), array( 'id' => $id ) );
				$wpdb->update( sap_table( 'ideas' ), array( 'task_id' => 0 ), array( 'project_id' => $id ) );
				break;
			case 'analysis':
				$del( 'datasets' );
				delete_option( 'sap_ss_recent_' . $id );
				break;
			case 'keymap':
				$del( 'keymap' );
				break;
			case 'ideas':
				$del( 'ideas' );
				$del( 'comments', " AND object_type = 'idea'" );
				break;
			case 'revisions':
				$del( 'revisions' );
				$del( 'comments', " AND object_type = 'revision'" );
				break;
			case 'focuses':
				$del( 'focuses' );
				break;
			case 'log':
				$del( 'activity' );
				break;
			case 'archive':
				$del( 'months' );
				break;
		}
	}
	unset( $GLOBALS['sap_project_cache'] );
	sap_activity_add( $project_id, array(
		'category' => 'management',
		'action'   => 'reset',
		'title'    => 'پاکسازی پروژه برای شروع دوباره: ' . implode( '، ', array_map( function ( $p ) {
			return sap_reset_parts()[ $p ][0];
		}, $parts ) ),
		'source'   => 'system',
		'meta'     => array( 'internal' => true ),
	) );
	return array_map( function ( $p ) {
		return sap_reset_parts()[ $p ][0];
	}, $parts );
}

// Keep the snapshots fresh: the running month and the one before it.
add_action( 'sap_daily', function () {
	$cur = sap_period_current();
	foreach ( sap_projects() as $p ) {
		foreach ( array( sap_period_shift( $cur, -1 ), $cur ) as $per ) {
			sap_month_snapshot( $p, $per );
		}
	}
}, 5 );
