<?php
/**
 * Alerts: rules that watch the site day and night — downtime, SSL, traffic
 * drops, pages leaving the index, error spikes, robots.txt and sitemap
 * problems, risky changes made on the site outside Sokan, ranking drops of the
 * focus keywords, Core Web Vitals, stale data and the AI budget.
 *
 * Each condition is one alert row (fingerprint): raised once, notified on the
 * channels of its severity, re-notified while critical, and resolved on its own
 * when the condition clears.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Severity labels and tones.
 */
function sap_alert_severities() {
	return array(
		'critical' => array( 'بحرانی', 'bad', '🔴' ),
		'warning'  => array( 'هشدار', 'warn', '🟠' ),
		'info'     => array( 'اطلاع', 'info', '🔵' ),
	);
}

/**
 * Rules: key => [label, description, schedule hourly|daily, default settings].
 */
function sap_alert_rules() {
	return array(
		'uptime'    => array( 'در دسترس بودن سایت', 'هر ساعت صفحه اصلی بررسی می‌شود؛ دو خطای پشت‌سرهم (خطای سرور یا عدم پاسخ) = بحرانی. پاسخ کندتر از آستانه = هشدار.', 'hourly', array( 'on' => 1, 'slow' => 8 ) ),
		'ssl'       => array( 'گواهی SSL', 'روزهای باقی‌مانده تا انقضای گواهی HTTPS.', 'daily', array( 'on' => 1, 'days' => 14 ) ),
		'traffic'   => array( 'افت ترافیک', '۷ روز اخیر سرچ کنسول در برابر ۷ روز قبل، برای کل سایت و صفحات پربازدید.', 'daily', array( 'on' => 1, 'drop' => 25, 'page_drop' => 40, 'min_clicks' => 50 ) ),
		'index'     => array( 'خروج صفحه مهم از ایندکس', 'تغییر وضعیت URL Inspection صفحات مهم از «ایندکس‌شده» به حالت دیگر.', 'daily', array( 'on' => 1 ) ),
		'errors'    => array( 'جهش خطاهای 4xx/5xx', 'مقایسه خزش Screaming Frog با خزش قبلی و خطای صفحات دارای نمایش.', 'daily', array( 'on' => 1, 'min' => 10 ) ),
		'robots'    => array( 'تغییر robots.txt', 'هر تغییر در robots.txt؛ مسدود شدن کل سایت = بحرانی.', 'daily', array( 'on' => 1 ) ),
		'sitemap'   => array( 'خطای سایت‌مپ', 'خطاهای سایت‌مپ در سرچ کنسول، پیدا نشدن سایت‌مپ و خوانده نشدن طولانی.', 'daily', array( 'on' => 1 ) ),
		'changes'   => array( 'تغییرات پرریسک خارج از سکان', 'noindex، تغییر نامک یا کنونیکال، خارج شدن صفحه از انتشار و تغییر تایتل صفحات مهم که در سایت (نه از سکان) انجام شده.', 'sync', array( 'on' => 1 ) ),
		'ranks'     => array( 'افت رتبه کلمات هدف', 'افت چند پله‌ای یا خروج از صفحه اول/سه نتیجه اول برای کلمات ردیابی‌شده.', 'daily', array( 'on' => 1, 'drop' => 5 ) ),
		'cwv'       => array( 'افت سرعت (Core Web Vitals)', 'افت امتیاز موبایل یا رد شدن LCP از ۴ ثانیه در صفحات کلیدی.', 'daily', array( 'on' => 1, 'drop' => 15 ) ),
		'stale'     => array( 'داده کهنه یا قطع اتصال', 'خزش قدیمی، همگام‌سازی انجام‌نشده، قطع افزونه رابط یا خطای سرچ کنسول.', 'daily', array( 'on' => 1, 'crawl_days' => 35 ) ),
		'radar'     => array( 'رادار رقبا', 'جلو زدن رقیب روی کلمه هدف و صفحات تازه رقبا (تب رقبا).', 'daily', array( 'on' => 1 ) ),
	);
}

/**
 * Effective settings of a rule in a project.
 */
function sap_alert_rule_cfg( $project, $rule ) {
	$def = sap_alert_rules()[ $rule ][3] ?? array( 'on' => 1 );
	return wp_parse_args( (array) ( $project['options']['alert_rules'][ $rule ] ?? array() ), $def );
}

/**
 * Hydrate an alert row.
 */
function sap_alert_hydrate( $r ) {
	if ( ! $r ) {
		return null;
	}
	$r                 = (array) $r;
	$r['id']           = (int) $r['id'];
	$r['project_id']   = (int) $r['project_id'];
	$r['task_id']      = (int) $r['task_id'];
	$r['notify_count'] = (int) $r['notify_count'];
	$r['data']         = (array) json_decode( (string) $r['data'], true );
	$r['url']          = (string) $r['url'];
	$r['detail']       = (string) $r['detail'];
	return $r;
}

/**
 * Alerts of a project (0 = site-wide ones).
 *
 * @param array $args status (string|array), severity, limit.
 */
function sap_alerts( $project_id, $args = array() ) {
	global $wpdb;
	$args  = wp_parse_args( $args, array( 'status' => array( 'open', 'ack' ), 'severity' => '', 'limit' => 300 ) );
	$where = $wpdb->prepare( 'project_id = %d', $project_id );
	if ( $args['status'] ) {
		$st     = (array) $args['status'];
		$where .= ' AND status IN (' . implode( ',', array_map( function ( $s ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $s );
		}, $st ) ) . ')';
	}
	if ( $args['severity'] ) {
		$where .= $wpdb->prepare( ' AND severity = %s', $args['severity'] );
	}
	$rows = $wpdb->get_results( 'SELECT * FROM ' . sap_table( 'alerts' ) . " WHERE $where ORDER BY FIELD(severity,'critical','warning','info'), last_seen DESC LIMIT " . (int) $args['limit'] ); // phpcs:ignore
	return array_map( 'sap_alert_hydrate', (array) $rows );
}

/**
 * One alert.
 */
function sap_alert( $id ) {
	global $wpdb;
	return sap_alert_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'alerts' ) . ' WHERE id = %d', $id ) ) ); // phpcs:ignore
}

/**
 * Open alert counts by severity.
 */
function sap_alert_counts( $project_id ) {
	global $wpdb;
	$c = array( 'critical' => 0, 'warning' => 0, 'info' => 0 );
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT severity, COUNT(*) n FROM ' . sap_table( 'alerts' ) . " WHERE project_id = %d AND status = 'open' GROUP BY severity", $project_id ) ) as $r ) { // phpcs:ignore
		$c[ $r->severity ] = (int) $r->n;
	}
	return $c;
}

/**
 * Raise (or refresh) an alert. Returns the row and whether it is new / reopened.
 */
function sap_alert_raise( $project_id, $rule, $key, $severity, $title, $detail = '', $url = '', $data = array() ) {
	global $wpdb;
	$t   = sap_table( 'alerts' );
	$fp  = substr( hash( 'sha256', $rule . '|' . $key ), 0, 40 );
	$now = sap_now();
	$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE project_id = %d AND fingerprint = %s", $project_id, $fp ) ); // phpcs:ignore
	$row = array(
		'severity'  => $severity,
		'title'     => mb_substr( $title, 0, 250 ),
		'detail'    => $detail,
		'url'       => (string) $url,
		'data'      => wp_json_encode( $data ),
		'last_seen' => $now,
	);
	if ( $old ) {
		$fresh = 'resolved' === $old->status;
		if ( $fresh ) {
			$row['status']       = 'open';
			$row['first_seen']   = $now;
			$row['resolved_at']  = null;
			$row['notify_count'] = 0;
			$row['notified_at']  = null;
		}
		// A worse severity reopens an acknowledged alert.
		$order = array( 'info' => 0, 'warning' => 1, 'critical' => 2 );
		if ( 'ack' === $old->status && $order[ $severity ] > $order[ $old->severity ] ) {
			$row['status'] = 'open';
			$fresh         = true;
		}
		$wpdb->update( $t, $row, array( 'id' => (int) $old->id ) );
		return array( sap_alert( (int) $old->id ), $fresh );
	}
	$wpdb->insert( $t, $row + array( 'project_id' => (int) $project_id, 'rule' => $rule, 'fingerprint' => $fp, 'status' => 'open', 'first_seen' => $now ) );
	return array( sap_alert( (int) $wpdb->insert_id ), true );
}

/**
 * Change an alert's state: ack | resolved | open | mute (days).
 */
function sap_alert_set( $id, $status, $mute_days = 0 ) {
	global $wpdb;
	$f = array( 'status' => $status );
	if ( 'resolved' === $status ) {
		$f['resolved_at'] = sap_now();
	}
	if ( 'muted' === $status ) {
		$f['muted_until'] = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + max( 1, (int) $mute_days ) * DAY_IN_SECONDS ); // phpcs:ignore
	}
	$wpdb->update( sap_table( 'alerts' ), $f, array( 'id' => (int) $id ) );
}

/**
 * Per-project state the rules compare against (hashes, verdicts, last values).
 */
function sap_alert_state( $project_id, $key = null, $value = null ) {
	$opt   = 'sap_alert_state_' . $project_id;
	$state = (array) get_option( $opt, array() );
	if ( null === $key ) {
		return $state;
	}
	if ( null === $value ) {
		return $state[ $key ] ?? null;
	}
	$state[ $key ] = $value;
	update_option( $opt, $state, false );
	return $value;
}

/* ---------------------------------------------------------------------------
 * Rules
 * ------------------------------------------------------------------------- */

/**
 * Run the rules of a schedule for a project. Each rule returns the alerts that
 * fire now: [key, severity, title, detail, url, data]; open alerts of a rule
 * that ran without firing them are resolved.
 *
 * @return array{new: array[], fired: int}
 */
function sap_alerts_run( $project, $schedule = 'daily' ) {
	$new   = array();
	$fired = 0;
	foreach ( sap_alert_rules() as $rule => $def ) {
		if ( $def[2] !== $schedule ) {
			continue;
		}
		$cfg = sap_alert_rule_cfg( $project, $rule );
		if ( empty( $cfg['on'] ) ) {
			continue;
		}
		$fn = 'sap_alert_rule_' . $rule;
		if ( ! function_exists( $fn ) ) {
			continue;
		}
		$out = call_user_func( $fn, $project, $cfg );
		if ( null === $out ) {
			continue; // Rule could not run (no data): leave its alerts as they are.
		}
		$keep = array();
		foreach ( (array) $out as $a ) {
			list( $row, $fresh ) = sap_alert_raise( $project['id'], $rule, $a[0], $a[1], $a[2], $a[3] ?? '', $a[4] ?? '', $a[5] ?? array() );
			$keep[] = $row['fingerprint'];
			++$fired;
			if ( $fresh && ! ( $row['muted_until'] && strtotime( $row['muted_until'] ) > current_time( 'timestamp' ) ) ) { // phpcs:ignore
				$new[] = $row;
			}
		}
		sap_alerts_resolve_rest( $project['id'], $rule, $keep );
	}
	if ( $new ) {
		sap_alerts_notify( $project, $new );
	}
	sap_alerts_renotify( $project );
	return array( 'new' => $new, 'fired' => $fired );
}

/**
 * Resolve the open alerts of a rule that did not fire this time.
 */
function sap_alerts_resolve_rest( $project_id, $rule, $keep ) {
	global $wpdb;
	$t    = sap_table( 'alerts' );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, fingerprint FROM $t WHERE project_id = %d AND rule = %s AND status IN ('open','ack','muted')", $project_id, $rule ) ); // phpcs:ignore
	foreach ( (array) $rows as $r ) {
		if ( ! in_array( $r->fingerprint, $keep, true ) ) {
			sap_alert_set( (int) $r->id, 'resolved' );
		}
	}
}

/**
 * Site availability (hourly).
 */
function sap_alert_rule_uptime( $project, $cfg ) {
	$base = sap_site_base( $project );
	if ( '' === $base ) {
		return null;
	}
	$t0   = microtime( true );
	$res  = sap_http( $base, array( 'timeout' => 25, 'redirection' => 5, 'user-agent' => 'Mozilla/5.0 (compatible; SokanMonitor/1.0)' ) );
	$ms   = (int) round( ( microtime( true ) - $t0 ) * 1000 );
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	$fail = 0 === $code || $code >= 500;
	$cnt  = $fail ? (int) sap_alert_state( $project['id'], 'uptime_fails' ) + 1 : 0;
	sap_alert_state( $project['id'], 'uptime_fails', $cnt );
	$log   = (array) sap_alert_state( $project['id'], 'uptime_log' );
	$log[] = array( current_time( 'mysql' ), $code, $ms );
	sap_alert_state( $project['id'], 'uptime_log', array_slice( $log, -168 ) ); // A week of hourly checks.
	$out = array();
	if ( $cnt >= 2 ) {
		$why   = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code;
		$out[] = array( 'down', 'critical', 'سایت از دسترس خارج است', sprintf( 'صفحه اصلی در %s بررسی پشت‌سرهم پاسخ نداد (%s). گوگل در صورت ادامه، صفحات را کم‌کم از نتایج کنار می‌گذارد؛ هاست، DNS و فایروال را بررسی کنید.', sap_num( $cnt ), $why ), $base );
	} elseif ( ! $fail && $ms > (int) $cfg['slow'] * 1000 ) {
		$out[] = array( 'slow', 'warning', 'سایت خیلی کند پاسخ می‌دهد', sprintf( 'صفحه اصلی در %s ثانیه پاسخ داد (آستانه %s ثانیه). کندی سرور روی خزش گوگل و تجربه کاربر اثر دارد.', sap_num( $ms / 1000, 1 ), sap_num( $cfg['slow'] ) ), $base );
	}
	return $out;
}

/**
 * HTTPS certificate expiry (daily).
 */
function sap_alert_rule_ssl( $project, $cfg ) {
	$base = sap_site_base( $project );
	if ( 0 !== strpos( $base, 'https://' ) || ! function_exists( 'openssl_x509_parse' ) ) {
		return null;
	}
	$host = (string) wp_parse_url( $base, PHP_URL_HOST );
	$ctx  = stream_context_create( array( 'ssl' => array( 'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host ) ) );
	$fp   = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore
	if ( ! $fp ) {
		return null;
	}
	$params = stream_context_get_params( $fp );
	fclose( $fp ); // phpcs:ignore
	$cert = ! empty( $params['options']['ssl']['peer_certificate'] ) ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ) : null;
	if ( ! $cert || empty( $cert['validTo_time_t'] ) ) {
		return null;
	}
	$days = (int) floor( ( $cert['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
	sap_alert_state( $project['id'], 'ssl_days', $days );
	if ( $days > (int) $cfg['days'] ) {
		return array();
	}
	return array( array( 'expiry', $days <= 5 ? 'critical' : 'warning', $days < 0 ? 'گواهی SSL منقضی شده است' : sprintf( 'گواهی SSL تا %s روز دیگر منقضی می‌شود', sap_num( $days ) ), 'بعد از انقضا مرورگرها صفحه هشدار امنیتی نشان می‌دهند و ترافیک و فروش متوقف می‌شود. تمدید خودکار گواهی (Let\'s Encrypt / هاست) را بررسی کنید.', $base, array( 'days' => $days ) ) );
}

/**
 * Search Console traffic drops, site-wide and on the top pages (daily).
 */
function sap_alert_rule_traffic( $project, $cfg ) {
	if ( '' === $project['gsc_property'] || ! sap_google_connected() ) {
		return null;
	}
	$end   = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 3 * DAY_IN_SECONDS ); // phpcs:ignore
	$mid   = gmdate( 'Y-m-d', strtotime( $end ) - 6 * DAY_IN_SECONDS );
	$p_end = gmdate( 'Y-m-d', strtotime( $mid ) - DAY_IN_SECONDS );
	$p_beg = gmdate( 'Y-m-d', strtotime( $p_end ) - 6 * DAY_IN_SECONDS );
	$days  = sap_gsc_query( $project['gsc_property'], $p_beg, $end, array( 'date' ), 30 );
	if ( is_wp_error( $days ) || ! $days ) {
		return null;
	}
	$cur  = array( 'clicks' => 0, 'impressions' => 0 );
	$prev = array( 'clicks' => 0, 'impressions' => 0 );
	foreach ( $days as $d ) {
		$bucket = $d['date'] >= $mid ? 'cur' : 'prev';
		${$bucket}['clicks']      += (int) $d['clicks'];
		${$bucket}['impressions'] += (int) $d['impressions'];
	}
	sap_alert_state( $project['id'], 'traffic_daily', array_map( function ( $d ) {
		return array( $d['date'], (int) $d['clicks'], (int) $d['impressions'] );
	}, $days ) );
	$out  = array();
	$drop = $prev['clicks'] ? round( ( $prev['clicks'] - $cur['clicks'] ) / $prev['clicks'] * 100 ) : 0;
	if ( $prev['clicks'] >= (int) $cfg['min_clicks'] && $drop >= (int) $cfg['drop'] ) {
		$out[] = array( 'site', $drop >= 45 ? 'critical' : 'warning', sprintf( 'افت %s٪ کلیک ارگانیک کل سایت در ۷ روز اخیر', sap_num( $drop ) ), sprintf( 'کلیک از %s به %s و نمایش از %s به %s رسید (%s تا %s در برابر ۷ روز قبل). لاگ تغییرات این هفته، وضعیت ایندکس، آپدیت‌های گوگل و رقبا را بررسی کنید.', sap_num( $prev['clicks'] ), sap_num( $cur['clicks'] ), sap_num( $prev['impressions'] ), sap_num( $cur['impressions'] ), sap_jdate( $mid, 'short' ), sap_jdate( $end, 'short' ) ), '', array( 'cur' => $cur, 'prev' => $prev ) );
	}
	// Top pages.
	$pc = sap_gsc_query( $project['gsc_property'], $mid, $end, array( 'page' ), 2000 );
	$pp = sap_gsc_query( $project['gsc_property'], $p_beg, $p_end, array( 'page' ), 2000 );
	if ( ! is_wp_error( $pc ) && ! is_wp_error( $pp ) ) {
		$now = array();
		foreach ( $pc as $r ) {
			$now[ sap_norm_url( $r['page'] ) ] = (int) $r['clicks'];
		}
		$cands = array();
		foreach ( sap_sort( $pp, 'clicks' ) as $r ) {
			if ( (int) $r['clicks'] < 20 || count( $cands ) >= 40 ) {
				continue;
			}
			$c = $now[ sap_norm_url( $r['page'] ) ] ?? 0;
			$d = round( ( $r['clicks'] - $c ) / $r['clicks'] * 100 );
			if ( $d >= (int) $cfg['page_drop'] ) {
				$cands[] = array( $r, $c, $d );
			}
		}
		usort( $cands, function ( $a, $b ) {
			return ( $b[0]['clicks'] - $b[1] ) <=> ( $a[0]['clicks'] - $a[1] );
		} );
		foreach ( array_slice( $cands, 0, 5 ) as $x ) {
			$name  = sap_page_title( $project['id'], $x[0]['page'] ) ?: sap_short_url( $x[0]['page'], 50 );
			$out[] = array( 'page:' . sap_norm_url( $x[0]['page'] ), $x[2] >= 70 ? 'critical' : 'warning', sprintf( 'افت %s٪ کلیک صفحه «%s»', sap_num( $x[2] ), $name ), sprintf( 'کلیک ۷ روزه از %s به %s رسید. تغییرات اخیر این صفحه در لاگ، ایندکس، رتبه کلمه اصلی و صفحات رقیب را بررسی کنید.', sap_num( (int) $x[0]['clicks'] ), sap_num( $x[1] ) ), $x[0]['page'] );
		}
	}
	return $out;
}

/**
 * Important pages leaving the index (daily, from URL Inspection).
 */
function sap_alert_rule_index( $project, $cfg ) {
	$ds = sap_dataset( $project['id'], 'gsc_inspect' );
	if ( ! $ds || empty( $ds['payload']['rows'] ) ) {
		return null;
	}
	$prev = (array) sap_alert_state( $project['id'], 'index_verdicts' );
	$cur  = array();
	$out  = array();
	foreach ( (array) $ds['payload']['rows'] as $r ) {
		$k         = sap_norm_url( $r['url'] ?? '' );
		$cur[ $k ] = (string) ( $r['verdict'] ?? '' );
		$was       = $prev[ $k ] ?? 'PASS';
		if ( 'PASS' === $was && 'PASS' !== $cur[ $k ] && '' !== $cur[ $k ] ) {
			$name  = sap_page_title( $project['id'], $r['url'] ) ?: sap_short_url( $r['url'], 50 );
			$out[] = array( 'page:' . $k, 'critical', sprintf( 'صفحه مهم «%s» از ایندکس گوگل خارج شده', $name ), sprintf( 'وضعیت گوگل: %s. noindex، کنونیکال، robots.txt، کد پاسخ و تغییرات اخیر صفحه را بررسی کنید و بعد از رفع، در سرچ کنسول درخواست ایندکس بدهید.', (string) ( $r['coverage'] ?? '' ) ), $r['url'] );
		}
	}
	sap_alert_state( $project['id'], 'index_verdicts', $cur + array_intersect_key( $prev, $cur ) );
	return $out;
}

/**
 * Error spikes in the crawl (daily).
 */
function sap_alert_rule_errors( $project, $cfg ) {
	$ds = sap_dataset( $project['id'], 'crawl' );
	if ( ! $ds ) {
		return null;
	}
	$codes = (array) ( $ds['meta']['codes'] ?? array() );
	$prev  = (array) sap_alert_state( $project['id'], 'crawl_codes' );
	if ( ( $prev['date'] ?? '' ) !== $ds['fetched_at'] ) {
		sap_alert_state( $project['id'], 'crawl_codes', array( 'date' => $ds['fetched_at'], 'codes' => $codes, 'before' => $prev['codes'] ?? null ) );
		$before = $prev['codes'] ?? null;
	} else {
		$before = $prev['before'] ?? null;
	}
	$out = array();
	$n4  = (int) ( $codes['4xx'] ?? 0 );
	$b4  = null === $before ? null : (int) ( $before['4xx'] ?? 0 );
	if ( null !== $b4 && $n4 - $b4 >= (int) $cfg['min'] && $n4 >= $b4 * 1.2 ) {
		$out[] = array( '4xx', 'warning', sprintf( 'جهش خطای 4xx: از %s به %s آدرس', sap_num( $b4 ), sap_num( $n4 ) ), 'در خزش جدید Screaming Frog آدرس‌های خراب بیشتر شده‌اند؛ معمولاً حذف محصول/دسته بدون ریدایرکت یا تغییر نامک. لینک‌های داخلی را اصلاح یا ریدایرکت 301 بگذارید.' );
	}
	if ( (int) ( $codes['5xx'] ?? 0 ) > 0 ) {
		$out[] = array( '5xx', 'critical', sprintf( '%s آدرس با خطای سرور 5xx', sap_num( (int) $codes['5xx'] ) ), 'خطای سرور مانع ایندکس است؛ لاگ خطای هاست و افزونه‌های تازه نصب‌شده را بررسی کنید.' );
	}
	// Pages that still have impressions but answer an error.
	$impr = sap_url_ctx( $project['id'] )['impr'];
	foreach ( (array) ( $ds['payload']['rows'] ?? array() ) as $r ) {
		$k = sap_norm_url( $r['url'] );
		if ( (int) $r['code'] >= 400 && ( $impr[ $k ] ?? 0 ) >= 50 && count( $out ) < 8 ) {
			$out[] = array( 'page:' . $k, 'critical', sprintf( 'صفحه دارای نمایش در گوگل خطای %s می‌دهد', sap_num( (int) $r['code'] ) ), sprintf( 'این صفحه %s نمایش در ۲۸ روز داشته ولی الان پاسخ %s می‌دهد. اگر عمداً حذف شده، به مرتبط‌ترین صفحه ریدایرکت 301 کنید.', sap_num( $impr[ $k ] ), sap_num( (int) $r['code'] ) ), $r['url'] );
		}
	}
	return $out;
}

/**
 * robots.txt changes (daily).
 */
function sap_alert_rule_robots( $project, $cfg ) {
	$base = sap_site_base( $project );
	if ( '' === $base ) {
		return null;
	}
	$r = sap_fetch_public( $base . 'robots.txt', 20 );
	if ( is_wp_error( $r ) ) {
		return null;
	}
	$out = array();
	if ( $r['code'] >= 500 ) {
		return array( array( 'error', 'warning', 'robots.txt خطای سرور می‌دهد', 'وقتی robots.txt خطای 5xx بدهد گوگل ممکن است خزش سایت را متوقف کند.', $base . 'robots.txt' ) );
	}
	$body = 200 === (int) $r['code'] ? str_replace( "\r", '', (string) $r['body'] ) : '';
	$hash = md5( $body );
	$prev = (array) sap_alert_state( $project['id'], 'robots' );
	if ( ! empty( $prev['hash'] ) && $prev['hash'] !== $hash ) {
		$old   = array_filter( array_map( 'trim', explode( "\n", (string) $prev['body'] ) ) );
		$new   = array_filter( array_map( 'trim', explode( "\n", $body ) ) );
		$add   = array_values( array_diff( $new, $old ) );
		$del   = array_values( array_diff( $old, $new ) );
		$block = (bool) preg_grep( '#^disallow:\s*/\s*$#i', $add );
		sap_alert_state( $project['id'], 'robots_change', array( 'at' => sap_now(), 'add' => $add, 'del' => $del, 'block' => $block ) );
	}
	sap_alert_state( $project['id'], 'robots', array( 'hash' => $hash, 'body' => mb_substr( $body, 0, 6000 ) ) );
	// Keep the change alert for a week after it happened.
	$ch = (array) sap_alert_state( $project['id'], 'robots_change' );
	if ( ! empty( $ch['at'] ) && strtotime( $ch['at'] ) > current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ) { // phpcs:ignore
		$lines = array_merge( array_map( function ( $l ) {
			return '+ ' . $l;
		}, (array) $ch['add'] ), array_map( function ( $l ) {
			return '− ' . $l;
		}, (array) $ch['del'] ) );
		$out[] = array( 'change:' . $ch['at'], ! empty( $ch['block'] ) ? 'critical' : 'warning', ! empty( $ch['block'] ) ? 'robots.txt کل سایت را برای گوگل مسدود کرده!' : 'robots.txt تغییر کرده', "تغییرات:\n" . implode( "\n", array_slice( $lines, 0, 12 ) ), $base . 'robots.txt', array( 'lines' => $lines ) );
	}
	return $out;
}

/**
 * Sitemap problems (daily).
 */
function sap_alert_rule_sitemap( $project, $cfg ) {
	$maps = sap_dataset_payload( $project['id'], 'gsc_sitemaps' );
	$chk  = sap_dataset( $project['id'], 'sitemap_check' );
	if ( ! $maps && ! $chk ) {
		return null;
	}
	$out = array();
	foreach ( (array) ( $maps['sitemaps'] ?? array() ) as $s ) {
		if ( (int) ( $s['errors'] ?? 0 ) > 0 ) {
			$out[] = array( 'err:' . $s['path'], 'warning', sprintf( 'سایت‌مپ %s خطا دارد', sap_short_url( $s['path'], 50 ) ), sprintf( 'سرچ کنسول %s خطا برای این سایت‌مپ گزارش کرده؛ در سرچ کنسول › Sitemaps جزئیات را ببینید.', sap_num( (int) $s['errors'] ) ), $s['path'] );
		}
		if ( ! empty( $s['last_downloaded'] ) && strtotime( $s['last_downloaded'] ) < time() - 21 * DAY_IN_SECONDS ) {
			$out[] = array( 'old:' . $s['path'], 'info', sprintf( 'گوگل %s روز است سایت‌مپ %s را نخوانده', sap_num( (int) floor( ( time() - strtotime( $s['last_downloaded'] ) ) / DAY_IN_SECONDS ) ), sap_short_url( $s['path'], 40 ) ), 'سایت‌مپ را دوباره در سرچ کنسول ارسال کنید و مطمئن شوید آدرسش در robots.txt هست.', $s['path'] );
		}
	}
	if ( $chk && '' === (string) ( $chk['payload']['root'] ?? '' ) ) {
		$out[] = array( 'missing', 'warning', 'سایت‌مپ XML پیدا نشد', 'هیچ‌کدام از مسیرهای رایج سایت‌مپ پاسخ معتبر نداد. سایت‌مپ رنک‌مث/یوست را فعال کنید و در سرچ کنسول ثبت کنید.' );
	}
	return $out;
}

/**
 * Risky changes made on the site outside Sokan (from the connector's change
 * feed, right after it is pulled). Raised directly, not by the daily run.
 */
add_action( 'sap_site_change', function ( $project, $c ) {
	$cfg = sap_alert_rule_cfg( $project, 'changes' );
	if ( empty( $cfg['on'] ) ) {
		return;
	}
	$field = (string) ( $c['field'] ?? '' );
	$url   = (string) ( $c['url'] ?? '' );
	$name  = '' !== (string) ( $c['title'] ?? '' ) ? '«' . $c['title'] . '»' : sap_short_url( $url, 50 );
	$imp   = '' !== $url ? sap_url_importance( $project['id'], $url ) : 'low';
	$who   = '' !== (string) ( $c['user'] ?? '' ) ? ' (کاربر: ' . $c['user'] . ')' : '';
	$a     = null;
	if ( ( 'noindex' === $field && in_array( (string) $c['new'], array( '1', 'true', 'yes', 'noindex' ), true ) ) || ( 'robots' === $field && false !== stripos( (string) $c['new'], 'noindex' ) ) ) {
		$a = array( 'critical', 'صفحه ' . $name . ' در سایت noindex شد', 'این صفحه دیگر در گوگل نمی‌آید. اگر عمدی نیست، فوراً در افزونه سئو برگردانید' . $who . '.' );
	} elseif ( 'slug' === $field && 'low' !== $imp ) {
		$a = array( 'critical', 'نامک (آدرس) صفحه ' . $name . ' تغییر کرد', sprintf( 'آدرس از «%s» به «%s» تغییر کرد%s. مطمئن شوید آدرس قبلی به آدرس جدید ریدایرکت 301 شده، وگرنه رتبه و بک‌لینک‌ها از دست می‌روند.', rawurldecode( (string) $c['old'] ), rawurldecode( (string) $c['new'] ), $who ) );
	} elseif ( 'canonical' === $field && 'low' !== $imp ) {
		$a = array( 'warning', 'کنونیکال صفحه ' . $name . ' تغییر کرد', sprintf( 'کنونیکال جدید: %s%s. اگر به صفحه دیگری اشاره کند، این صفحه از نتایج کنار می‌رود.', rawurldecode( (string) $c['new'] ), $who ) );
	} elseif ( 'status' === $field && 'publish' !== (string) $c['new'] && 'low' !== $imp ) {
		$a = array( 'critical', 'صفحه مهم ' . $name . ' از انتشار خارج شد', sprintf( 'وضعیت جدید: %s%s. صفحه‌ای که نمایش در گوگل دارد 404 می‌شود؛ اگر عمدی است ریدایرکت بگذارید.', (string) $c['new'], $who ) );
	} elseif ( in_array( $field, array( 'seo_title', 'title', 'meta_description' ), true ) && 'high' === $imp ) {
		$a = array( 'info', sap_site_field_label( $field ) . ' صفحه پربازدید ' . $name . ' در سایت تغییر کرد', sprintf( 'از «%s» به «%s»%s. اثرش در تب ارزیابی سنجیده می‌شود.', mb_substr( (string) $c['old'], 0, 120 ), mb_substr( (string) $c['new'], 0, 120 ), $who ) );
	} elseif ( 'deleted' === $field ) {
		$a = array( 'warning', 'دسته/برچسب ' . $name . ' حذف شد', 'اگر صفحه آن در گوگل نمایش داشت، به دسته مرتبط ریدایرکت 301 کنید' . $who . '.' );
	}
	if ( ! $a ) {
		return;
	}
	list( $row, $fresh ) = sap_alert_raise( $project['id'], 'changes', 'chg:' . (int) ( $c['id'] ?? 0 ), $a[0], $a[1], $a[2], $url, array( 'field' => $field ) );
	if ( $fresh ) {
		sap_alerts_notify( $project, array( $row ) );
	}
}, 10, 2 );

/**
 * Ranking drops of tracked keywords (daily, from the rank history).
 */
function sap_alert_rule_ranks( $project, $cfg ) {
	if ( ! function_exists( 'sap_rank_movers' ) ) {
		return null;
	}
	$m = sap_rank_movers( $project['id'], 7 );
	if ( null === $m ) {
		return null;
	}
	$out = array();
	foreach ( $m['rows'] as $r ) {
		if ( null === $r['now'] ) {
			if ( null !== $r['then'] && $r['then'] <= 10 ) {
				$out[] = array( 'kw:' . $r['keyword'], 'critical', sprintf( 'کلمه «%s» از نتایج ردیابی خارج شد', $r['keyword'] ), sprintf( 'هفته پیش جایگاه %s داشت و الان در نتایج ردیابی‌شده نیست.', sap_num( $r['then'], 1 ) ), $r['url'] );
			}
			continue;
		}
		if ( null === $r['then'] ) {
			continue;
		}
		$drop = $r['now'] - $r['then'];
		if ( $r['then'] <= 3 && $r['now'] > 3 ) {
			$out[] = array( 'kw:' . $r['keyword'], 'warning', sprintf( 'کلمه «%s» از سه نتیجه اول بیرون رفت', $r['keyword'] ), sprintf( 'جایگاه از %s به %s رسید (۷ روز).', sap_num( $r['then'], 1 ), sap_num( $r['now'], 1 ) ), $r['url'] );
		} elseif ( $r['then'] <= 10 && $r['now'] > 10 ) {
			$out[] = array( 'kw:' . $r['keyword'], 'warning', sprintf( 'کلمه «%s» از صفحه اول گوگل خارج شد', $r['keyword'] ), sprintf( 'جایگاه از %s به %s رسید (۷ روز).', sap_num( $r['then'], 1 ), sap_num( $r['now'], 1 ) ), $r['url'] );
		} elseif ( $drop >= (float) $cfg['drop'] ) {
			$out[] = array( 'kw:' . $r['keyword'], 'warning', sprintf( 'افت %s پله‌ای کلمه «%s»', sap_num( $drop, 0 ), $r['keyword'] ), sprintf( 'جایگاه از %s به %s رسید (۷ روز).', sap_num( $r['then'], 1 ), sap_num( $r['now'], 1 ) ), $r['url'] );
		}
		if ( ! empty( $r['url_changed'] ) ) {
			$out[] = array( 'url:' . $r['keyword'], 'info', sprintf( 'صفحه رتبه‌گیرنده کلمه «%s» عوض شد', $r['keyword'] ), sprintf( 'گوگل به‌جای %s صفحه %s را نشان می‌دهد؛ نشانه احتمالی کنیبالیزیشن.', sap_short_url( $r['url_then'], 50 ), sap_short_url( $r['url'], 50 ) ), $r['url'] );
		}
	}
	return array_slice( $out, 0, 12 );
}

/**
 * Core Web Vitals degradation (daily).
 */
function sap_alert_rule_cwv( $project, $cfg ) {
	$ds = sap_dataset( $project['id'], 'cwv' );
	if ( ! $ds || empty( $ds['payload']['pages'] ) ) {
		return null;
	}
	$prev = (array) sap_alert_state( $project['id'], 'cwv_scores' );
	$cur  = array();
	$out  = array();
	foreach ( (array) $ds['payload']['pages'] as $k => $p ) {
		$cur[ $k ] = $p['score'];
		$lcp       = $p['field']['lcp']['p75'] ?? null;
		$name      = sap_page_title( $project['id'], $p['url'] ) ?: sap_short_url( $p['url'], 50 );
		if ( null !== $p['score'] && isset( $prev[ $k ] ) && null !== $prev[ $k ] && $prev[ $k ] - $p['score'] >= (int) $cfg['drop'] ) {
			$out[] = array( 'score:' . $k, 'warning', sprintf( 'افت امتیاز سرعت «%s» از %s به %s', $name, sap_num( $prev[ $k ] ), sap_num( $p['score'] ) ), 'تصاویر، اسکریپت‌ها و افزونه‌هایی که اخیراً اضافه شده‌اند را بررسی کنید.', $p['url'] );
		}
		if ( null !== $lcp && $lcp > 4000 ) {
			$out[] = array( 'lcp:' . $k, 'warning', sprintf( 'LCP صفحه «%s» %s ثانیه است (ضعیف)', $name, sap_num( $lcp / 1000, 1 ) ), 'داده واقعی کاربران کروم: محتوای اصلی دیرتر از ۴ ثانیه نمایش داده می‌شود. تصویر اصلی را بهینه و preload کنید.', $p['url'] );
		}
	}
	sap_alert_state( $project['id'], 'cwv_scores', $cur + $prev );
	return $out;
}

/**
 * Stale data and broken connections (daily).
 */
function sap_alert_rule_stale( $project, $cfg ) {
	$out   = array();
	$crawl = sap_dataset( $project['id'], 'crawl' );
	if ( $crawl && strtotime( $crawl['fetched_at'] ) < current_time( 'timestamp' ) - (int) $cfg['crawl_days'] * DAY_IN_SECONDS ) { // phpcs:ignore
		$out[] = array( 'crawl', 'info', sprintf( 'آخرین خزش Screaming Frog %s روز پیش است', sap_num( (int) floor( ( current_time( 'timestamp' ) - strtotime( $crawl['fetched_at'] ) ) / DAY_IN_SECONDS ) ) ), 'تحلیل تکنیکال روی داده کهنه انجام می‌شود؛ خزش تازه بگیرید.' ); // phpcs:ignore
	}
	$last = strtotime( (string) ( $project['options']['last_sync'] ?? '' ) );
	if ( $last && $last < current_time( 'timestamp' ) - 10 * DAY_IN_SECONDS ) { // phpcs:ignore
		$out[] = array( 'sync', 'info', 'همگام‌سازی پروژه بیش از ۱۰ روز است انجام نشده', '«تحلیل کامل» را اجرا کنید یا همگام‌سازی خودکار را در تنظیمات فعال کنید.' );
	}
	$log = (array) ( $project['options']['sync_log'] ?? array() );
	foreach ( array( 'gsc_pages' => 'سرچ کنسول', 'site_log' => 'افزونه رابط سایت', 'site_inventory' => 'افزونه رابط سایت' ) as $step => $label ) {
		if ( isset( $log[ $step ] ) && empty( $log[ $step ]['ok'] ) ) {
			$out[] = array( 'link:' . $label, 'warning', 'اتصال ' . $label . ' خطا دارد', 'آخرین تلاش: ' . mb_substr( (string) $log[ $step ]['msg'], 0, 200 ) );
		}
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * Delivery
 * ------------------------------------------------------------------------- */

/**
 * Notify new alerts on the channels of their severity (one message per channel).
 */
function sap_alerts_notify( $project, $alerts ) {
	$sev   = sap_alert_severities();
	$by_ch = array();
	foreach ( $alerts as $a ) {
		foreach ( sap_list( (string) sap_setting( 'alert_' . $a['severity'], '' ) ) as $ch ) {
			if ( 'digest' !== $ch ) {
				$by_ch[ $ch ][] = $a;
			}
		}
	}
	$link = sap_project_url( $project['id'], 'alerts' );
	foreach ( $by_ch as $ch => $list ) {
		$lines = array_map( function ( $a ) use ( $sev ) {
			return $sev[ $a['severity'] ][2] . ' ' . $a['title'];
		}, $list );
		$head  = sprintf( 'سکان | %s: %s هشدار جدید', $project['name'], sap_num( count( $list ) ) );
		if ( 'sms' === $ch ) {
			$text = $head . "\n" . implode( "\n", array_slice( $lines, 0, 3 ) );
		} elseif ( 'email' === $ch ) {
			$text = '';
		} else {
			$text = '<b>' . esc_html( $head ) . '</b>' . "\n\n" . implode( "\n\n", array_map( function ( $a ) use ( $sev ) {
				return $sev[ $a['severity'] ][2] . ' <b>' . esc_html( $a['title'] ) . '</b>' . "\n" . esc_html( mb_substr( $a['detail'], 0, 300 ) ) . ( $a['url'] ? "\n" . esc_html( rawurldecode( $a['url'] ) ) : '' );
			}, array_slice( $list, 0, 6 ) ) ) . "\n\n" . esc_url( $link );
		}
		sap_notify_manager( array( $ch ), array(
			'subject' => $head,
			'text'    => $text ?: $head,
			'sms'     => $text,
			'bot'     => $text,
			'email'   => array(
				'title'  => $head,
				'tone'   => 'critical' === $list[0]['severity'] ? '#DC2626' : '#D97706',
				'items'  => array_map( function ( $a ) use ( $sev ) {
					return '<b>' . esc_html( $sev[ $a['severity'] ][2] . ' ' . $a['title'] ) . '</b><br><small>' . esc_html( mb_substr( $a['detail'], 0, 400 ) ) . '</small>';
				}, $list ),
				'button' => array( 'مشاهده هشدارها', $link ),
			),
		) );
	}
	global $wpdb;
	foreach ( $alerts as $a ) {
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . sap_table( 'alerts' ) . ' SET notified_at = %s, notify_count = notify_count + 1 WHERE id = %d', sap_now(), $a['id'] ) ); // phpcs:ignore
	}
}

/**
 * Critical alerts still open: remind every 24 hours, at most 3 times.
 */
function sap_alerts_renotify( $project ) {
	$due = array_filter( sap_alerts( $project['id'], array( 'status' => 'open', 'severity' => 'critical' ) ), function ( $a ) {
		return $a['notify_count'] > 0 && $a['notify_count'] < 3 && $a['notified_at'] && strtotime( $a['notified_at'] ) < current_time( 'timestamp' ) - DAY_IN_SECONDS; // phpcs:ignore
	} );
	if ( $due ) {
		sap_alerts_notify( $project, array_values( $due ) );
	}
}

/**
 * Daily digest email: open warnings and info of all projects.
 */
function sap_alerts_digest() {
	$items = array();
	$sev   = sap_alert_severities();
	foreach ( sap_projects() as $p ) {
		foreach ( sap_alerts( $p['id'], array( 'status' => 'open' ) ) as $a ) {
			$items[] = '<b>' . esc_html( $sev[ $a['severity'] ][2] . ' ' . $p['name'] . ' — ' . $a['title'] ) . '</b>';
		}
	}
	if ( ! $items ) {
		return;
	}
	sap_notify_email( sap_manager_email(), sprintf( 'سکان | خلاصه هشدارهای امروز (%s مورد باز)', sap_num( count( $items ) ) ), array( 'title' => 'هشدارهای باز پروژه‌ها', 'items' => array_slice( $items, 0, 40 ), 'button' => array( 'باز کردن سکان', sap_url() ) ) );
}

/* ---------------------------------------------------------------------------
 * Schedules
 * ------------------------------------------------------------------------- */

add_action( 'sap_hourly', function () {
	foreach ( sap_projects() as $p ) {
		sap_alerts_run( $p, 'hourly' );
	}
	// Daily rules once a day, at the first run after 06:00 site time.
	$today = current_time( 'Y-m-d' );
	if ( (int) current_time( 'G' ) >= 6 && get_option( 'sap_alerts_daily_on' ) !== $today ) {
		update_option( 'sap_alerts_daily_on', $today, false );
		foreach ( sap_projects() as $p ) {
			sap_alerts_run( $p, 'daily' );
		}
		sap_alerts_digest();
	}
} );

/**
 * Sync step: check the daily rules now.
 */
function sap_step_alerts( $project ) {
	$r = sap_alerts_run( $project, 'daily' );
	$c = sap_alert_counts( $project['id'] );
	return array( 'msg' => sprintf( 'هشدارها بررسی شد: %s مورد جدید؛ باز: %s بحرانی، %s هشدار، %s اطلاع.', sap_num( count( $r['new'] ) ), sap_num( $c['critical'] ), sap_num( $c['warning'] ), sap_num( $c['info'] ) ) );
}
