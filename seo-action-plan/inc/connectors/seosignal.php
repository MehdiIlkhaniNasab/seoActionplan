<?php
/**
 * SEO Signal OpenAPI (panel.seosignal.net/openapi/v1).
 *
 * All endpoints are POST + JSON with the "X-Api-Key: email:token" header and answer
 * {ok, data} or {error: {code, message}}. Keyword research, site analyzer and site
 * compare share a daily quota (50 by default, reset at 00:00 Tehran time); the rank
 * tracker endpoints are free. The plugin keeps its own counter and stops at the
 * configured cap so it never exhausts the quota the panel / SeoReport also need.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

const SAP_SS_BASE = 'https://panel.seosignal.net/openapi/v1/';

/**
 * Today in Tehran (the quota day).
 */
function sap_ss_today() {
	$d = new DateTime( 'now', new DateTimeZone( 'Asia/Tehran' ) );
	return $d->format( 'Y-m-d' );
}

/**
 * Quota usage counted by this plugin today.
 *
 * @return array{date:string, used:int, cap:int, server_limit:int|null, last_error:string}
 */
function sap_ss_usage() {
	$u = get_option( 'sap_ss_usage', array() );
	if ( ! is_array( $u ) || ( $u['date'] ?? '' ) !== sap_ss_today() ) {
		$u = array( 'date' => sap_ss_today(), 'used' => 0, 'server_limit' => null, 'last_error' => '' );
	}
	$u['cap'] = max( 1, (int) sap_setting( 'seosignal_daily_cap', 40 ) );
	return $u;
}

/**
 * Update the usage counter.
 */
function sap_ss_usage_bump( $changes ) {
	$u = sap_ss_usage();
	unset( $u['cap'] );
	update_option( 'sap_ss_usage', array_merge( $u, $changes ), false );
}

/**
 * Remaining requests this plugin may still spend today.
 */
function sap_ss_remaining() {
	$u = sap_ss_usage();
	return max( 0, $u['cap'] - $u['used'] );
}

/**
 * Is an API key configured?
 */
function sap_ss_connected() {
	return '' !== sap_setting( 'seosignal_key' );
}

/**
 * Call an endpoint.
 *
 * @param string $endpoint e.g. "keyword-research-bulk".
 * @param array  $body     JSON body.
 * @param bool|int $metered Counts against the daily quota (true = 1 unit, or the number of units).
 * @return array|WP_Error Full decoded response (with ok/data).
 */
function sap_ss_key() {
	// People paste the key with the header name, quotes or spaces around it.
	$key = trim( (string) sap_setting( 'seosignal_key' ) );
	$key = preg_replace( '/^\s*x-api-key\s*:\s*/i', '', $key );
	return trim( $key, " \t\n\r\0\x0B\"'" );
}

/**
 * Remember the last requests (endpoint, status, error) for the diagnostics panel.
 */
function sap_ss_log( $endpoint, $code, $ok, $msg, $ms ) {
	$log   = (array) get_option( 'sap_ss_log', array() );
	$log[] = array(
		'at'       => current_time( 'mysql' ),
		'endpoint' => $endpoint,
		'code'     => (int) $code,
		'ok'       => (bool) $ok,
		'msg'      => mb_substr( (string) $msg, 0, 300 ),
		'ms'       => (int) $ms,
	);
	update_option( 'sap_ss_log', array_slice( $log, -15 ), false );
}

function sap_ss_request( $endpoint, $body = array(), $metered = true ) {
	$key = sap_ss_key();
	if ( '' === $key ) {
		return new WP_Error( 'sap_ss_key', 'کلید API سئو سیگنال وارد نشده است (تنظیمات › سئو سیگنال).', array( 'code' => 'NO_KEY' ) );
	}
	if ( $metered && sap_ss_remaining() < 1 ) {
		return new WP_Error( 'sap_ss_cap', 'سقف روزانه درخواست‌های سئو سیگنال که برای این افزونه تعیین کرده‌اید پر شده است (تنظیمات › سئو سیگنال › سقف درخواست روزانه)؛ یا عدد را بیشتر کنید یا فردا ساعت ۰۰:۰۰ تهران ادامه می‌دهد.', array( 'code' => 'LOCAL_CAP' ) );
	}
	$t0  = microtime( true );
	$res = sap_http( SAP_SS_BASE . $endpoint, array(
		'method'  => 'POST',
		'timeout' => 60,
		'headers' => array(
			'X-Api-Key'    => $key,
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		),
		'body'    => wp_json_encode( (object) $body ),
	) );
	$ms = (int) round( 1000 * ( microtime( true ) - $t0 ) );
	if ( is_wp_error( $res ) ) {
		$msg = 'اتصال به سئو سیگنال (panel.seosignal.net) برقرار نشد: ' . sap_net_error( $res );
		sap_ss_log( $endpoint, 0, false, $res->get_error_message(), $ms );
		sap_ss_usage_bump( array( 'last_error' => $msg ) );
		return new WP_Error( 'sap_ss_http', $msg, array( 'code' => 'NETWORK' ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	// SEO Signal prefixes its JSON with UTF-8 BOMs (often two), which json_decode rejects.
	$raw  = preg_replace( '/^(?:\xEF\xBB\xBF|\s)+/', '', (string) wp_remote_retrieve_body( $res ) );
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		$msg = sprintf( 'پاسخ سئو سیگنال JSON نبود (HTTP %d). معمولاً یعنی فایروال یا VPN/پروکسی سرور شما درخواست را به صفحه دیگری برده یا سئو سیگنال IP خارج از ایران را رد کرده است. «تست اتصال» را در تب سئو سیگنال بزنید.', $code );
		sap_ss_log( $endpoint, $code, false, wp_strip_all_tags( mb_substr( $raw, 0, 200 ) ), $ms );
		sap_ss_usage_bump( array( 'last_error' => $msg ) );
		return new WP_Error( 'sap_ss_not_json', $msg, array( 'status' => $code, 'code' => 'NOT_JSON' ) );
	}
	if ( $metered && in_array( $code, array( 200, 422 ), true ) ) {
		sap_ss_usage_bump( array( 'used' => sap_ss_usage()['used'] + ( true === $metered ? 1 : (int) $metered ) ) );
	}
	// What SEO Signal itself reports (usage / limit of the account), shown in the SEO Signal tab.
	if ( is_array( $data ) ) {
		$su = $data['usage'] ?? ( $data['data']['Usage'] ?? null );
		$sl = $data['limit'] ?? ( $data['data']['Limit'] ?? null );
		if ( null !== $su ) {
			$rep              = (array) get_option( 'sap_ss_reported', array() );
			$rep[ $endpoint ] = array( 'usage' => (int) $su, 'limit' => null === $sl ? null : (int) $sl, 'at' => current_time( 'mysql' ) );
			update_option( 'sap_ss_reported', $rep, false );
		}
	}
	if ( 200 === $code && ! empty( $data['ok'] ) ) {
		sap_ss_log( $endpoint, $code, true, '', $ms );
		return $data;
	}
	$err  = is_array( $data ) ? ( $data['error'] ?? array() ) : array();
	$ecode = (string) ( $err['code'] ?? 'HTTP_' . $code );
	if ( 'HTTP_401' === $ecode || 'HTTP_403' === $ecode ) {
		$ecode = 'INVALID_API_KEY';
	}
	$msgs = array(
		'INVALID_API_KEY'     => 'کلید API سئو سیگنال نامعتبر است. کلید را دقیقاً همان‌طور که در پنل سئو سیگنال › مدیریت API نمایش داده می‌شود (بدون فاصله و بدون «X-Api-Key:») دوباره در تنظیمات وارد کنید.',
		'PLAN_NOT_ALLOWED'    => 'پلن حساب سئو سیگنال اجازه استفاده از این بخش API را ندارد.',
		'ACCOUNT_DISABLED'    => 'حساب سئو سیگنال غیرفعال است.',
		'RATE_LIMIT_EXCEEDED' => 'سقف روزانه API سئو سیگنال پر شده است.',
		'REQUEST_FAILED'      => 'سئو سیگنال برای این درخواست داده‌ای نداشت.',
		'INVALID_PARAMS'      => 'پارامترهای درخواست سئو سیگنال نامعتبر است.',
	);
	$msg = $msgs[ $ecode ] ?? ( 'خطای سئو سیگنال: ' . ( $err['message'] ?? 'HTTP ' . $code ) );
	if ( 'RATE_LIMIT_EXCEEDED' === $ecode ) {
		sap_ss_usage_bump( array( 'used' => sap_ss_usage()['cap'], 'server_limit' => (int) ( $data['limit'] ?? 0 ) ) );
	}
	sap_ss_usage_bump( array( 'last_error' => $msg ) );
	sap_ss_log( $endpoint, $code, false, $ecode . ( isset( $err['message'] ) ? ' — ' . $err['message'] : '' ), $ms );
	return new WP_Error( 'sap_ss_' . strtolower( $ecode ), $msg, array( 'status' => $code, 'code' => $ecode ) );
}

/**
 * Connection test (free endpoint): DNS, network route, key, plan.
 *
 * @return array{ok: bool, steps: array}
 */
function sap_ss_diagnose() {
	$steps = array();
	$key   = sap_ss_key();
	$steps[] = array(
		'ok'   => '' !== $key,
		'name' => 'کلید API',
		'msg'  => '' === $key ? 'کلید وارد نشده.' : sprintf( 'وارد شده (%s کاراکتر، شروع با %s…)%s', sap_num( strlen( $key ) ), substr( $key, 0, 4 ), false !== strpos( $key, ' ' ) ? ' — داخل کلید فاصله هست؛ احتمالاً اشتباه کپی شده.' : '' ),
	);
	$ip = gethostbyname( 'panel.seosignal.net' );
	$steps[] = array(
		'ok'   => 'panel.seosignal.net' !== $ip,
		'name' => 'پیدا کردن سرور سئو سیگنال (DNS)',
		'msg'  => 'panel.seosignal.net' !== $ip ? $ip : 'آدرس panel.seosignal.net روی این سرور پیدا نشد (DNS یا اینترنت سرور).',
	);
	$proxied = apply_filters( 'pre_http_send_through_proxy', null, SAP_SS_BASE, wp_parse_url( SAP_SS_BASE ), array() );
	$steps[] = array(
		'ok'   => null,
		'name' => 'مسیر شبکه',
		'msg'  => ( sap_proxy_defined() && false !== $proxied ? 'از پروکسی ' . sap_proxy_label() . ' (اگر سئو سیگنال IP خارجی را رد کند، در تنظیمات › شبکه حالت «هوشمند» را بگذارید)' : 'مستقیم (بدون پروکسی)' ),
	);
	if ( '' !== $key ) {
		$res = sap_ss_request( 'ranktracker-projects', array(), false );
		if ( ! is_wp_error( $res ) || 'NETWORK' !== ( $res->get_error_data()['code'] ?? '' ) ) {
			$steps[1]['ok'] = 'panel.seosignal.net' !== $ip ? true : null; // The server was reached (maybe via a proxy that resolves it).
		}
		if ( is_wp_error( $res ) ) {
			$steps[] = array( 'ok' => false, 'name' => 'درخواست آزمایشی (رایگان)', 'msg' => $res->get_error_message() );
		} else {
			$n       = count( (array) ( $res['data']['List'] ?? array() ) );
			$steps[] = array( 'ok' => true, 'name' => 'درخواست آزمایشی (رایگان)', 'msg' => sprintf( 'موفق؛ کلید معتبر است و %s پروژه ردیاب رتبه در حساب هست.', sap_num( $n ) ) );
		}
	}
	return array(
		'ok'    => ! in_array( false, array_column( $steps, 'ok' ), true ),
		'steps' => $steps,
	);
}

/**
 * Search volume for many keywords: one quota unit per 1000 keywords.
 *
 * @return array|WP_Error kw_key => [word, volume, competition]
 */
function sap_ss_volumes( $keywords ) {
	$keywords = array_values( array_unique( array_filter( array_map( 'trim', (array) $keywords ), 'strlen' ) ) );
	$out      = array();
	foreach ( array_chunk( $keywords, 1000 ) as $chunk ) {
		$res = sap_ss_request( 'keyword-research-bulk', array( 'keywords' => $chunk ) );
		if ( is_wp_error( $res ) ) {
			if ( $out ) {
				break; // Keep what we already have.
			}
			return $res;
		}
		foreach ( (array) ( $res['data']['keywordList'] ?? array() ) as $k ) {
			$w                       = (string) ( $k['Word'] ?? '' );
			$out[ sap_kw_key( $w ) ] = array(
				'word'        => $w,
				'volume'      => (int) ( $k['SearchVolume'] ?? 0 ),
				'competition' => (string) ( $k['Competition'] ?? '' ),
			);
		}
	}
	return $out;
}

/**
 * Search types of keyword research.
 */
function sap_ss_research_types() {
	return array(
		'similar'       => 'کلمات مشابه و هم‌معنی',
		'topical'       => 'کلمات هم‌موضوع',
		'starting_with' => 'شروع‌شونده با این کلمه',
		'ending_with'   => 'تمام‌شونده با این کلمه',
		'questions'     => 'سوالات کاربران',
	);
}

/**
 * Keyword research for one keyword, with the keyword's own volume, trend and
 * the domains that take its clicks.
 *
 * @param string $type similar|topical|starting_with|ending_with|questions.
 * @return array|WP_Error {rows: [word, volume, competition], meta}
 */
function sap_ss_research_full( $keyword, $type = 'similar', $count = 200 ) {
	$res = sap_ss_request( 'keyword-research', array(
		'keyword'     => $keyword,
		'search_type' => array_key_exists( $type, sap_ss_research_types() ) ? $type : 'similar',
		'count'       => min( 1000, max( 1, (int) $count ) ),
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return array( 'rows' => sap_ss_word_rows( $res['data']['keywordList'] ?? array() ), 'meta' => sap_ss_trend_meta( (array) ( $res['data'] ?? array() ) ) );
}

/**
 * Keyword research rows only (used by the analysis steps).
 */
function sap_ss_research( $keyword, $type = 'similar', $count = 200 ) {
	$r = sap_ss_research_full( $keyword, $type, $count );
	return is_wp_error( $r ) ? $r : $r['rows'];
}

/**
 * [{Word, SearchVolume, Competition, Page}] → normalized rows.
 */
function sap_ss_word_rows( $list ) {
	$out = array();
	foreach ( (array) $list as $k ) {
		$row = array(
			'word'        => (string) ( $k['Word'] ?? '' ),
			'volume'      => (int) ( $k['SearchVolume'] ?? 0 ),
			'competition' => (string) ( $k['Competition'] ?? '' ),
		);
		if ( isset( $k['Page'] ) ) {
			$row['page'] = (string) $k['Page'];
		}
		$out[] = $row;
	}
	return $out;
}

/**
 * Volume, change, monthly trend and click share from a research response.
 */
function sap_ss_trend_meta( $d ) {
	$ctr = array();
	foreach ( (array) ( $d['domainCTR']['Domain'] ?? array() ) as $i => $dom ) {
		$ctr[] = array( (string) $dom, (float) ( $d['domainCTR']['CTR'][ $i ] ?? 0 ) );
	}
	return array(
		'volume' => isset( $d['wordSearchVolume'] ) ? (int) $d['wordSearchVolume'] : null,
		'all'    => isset( $d['allSearchVolume'] ) ? (int) $d['allSearchVolume'] : null,
		'change' => isset( $d['changeSearchVolume'] ) ? (float) $d['changeSearchVolume'] : null,
		'chart'  => array( 'labels' => array_values( (array) ( $d['chart']['lable'] ?? $d['chart']['label'] ?? array() ) ), 'data' => array_map( 'intval', array_values( (array) ( $d['chart']['data'] ?? array() ) ) ) ),
		'ctr'    => array_slice( $ctr, 0, 10 ),
	);
}

/**
 * Keywords and top pages of a domain or URL (site analyzer).
 *
 * @param string $type domain|page|starting_with|contains.
 * @return array|WP_Error {rows, meta}
 */
function sap_ss_site_analyzer( $url, $type = 'domain', $contains = '', $count = 500 ) {
	$type = in_array( $type, array( 'domain', 'page', 'starting_with', 'contains' ), true ) ? $type : 'domain';
	$body = array( 'url' => $url, 'search_type' => $type, 'count' => min( 2000, max( 1, (int) $count ) ) );
	if ( 'contains' === $type ) {
		$body['contains'] = $contains;
	}
	$res = sap_ss_request( 'site-analyzer', $body );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$d     = (array) ( $res['data'] ?? array() );
	$pages = array();
	foreach ( (array) ( $d['topPages'] ?? array() ) as $p ) {
		$pages[] = array( 'page' => (string) ( $p['Page'] ?? '' ), 'avg' => (float) ( $p['Avg'] ?? 0 ), 'count' => (int) ( $p['Count'] ?? 0 ) );
	}
	return array(
		'rows' => sap_ss_word_rows( $d['Keywords'] ?? array() ),
		'meta' => array( 'domain' => (string) ( $d['domain'] ?? $url ), 'pages' => array_slice( $pages, 0, 50 ), 'related' => array_values( array_map( 'strval', (array) ( $d['RelDomains'] ?? array() ) ) ), 'ctr' => sap_ss_trend_meta( $d )['ctr'] ),
	);
}

/**
 * Reportage assistant endpoints (plans with the reportage service only).
 *
 * @param string $endpoint reportage-suggest|reportage-suggest-check|reportage-seller|reportage-buyer.
 * @return array|WP_Error {rows, meta}
 */
function sap_ss_reportage( $endpoint, $body ) {
	$res = sap_ss_request( $endpoint, $body );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$d = $res['data'] ?? array();
	// The list is either the data itself or its first array field (Reportages, PRList, …).
	$rows = array();
	if ( is_array( $d ) && array_is_list( $d ) ) {
		$rows = $d;
	} elseif ( is_array( $d ) ) {
		foreach ( $d as $v ) {
			if ( is_array( $v ) && array_is_list( $v ) ) {
				$rows = $v;
				break;
			}
		}
	}
	$meta = array();
	if ( is_array( $d ) && ! array_is_list( $d ) ) {
		foreach ( $d as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$meta[ $k ] = $v;
			}
		}
	}
	return array( 'rows' => array_slice( array_values( array_filter( $rows, 'is_array' ) ), 0, 1000 ), 'meta' => $meta );
}

/**
 * Compare two sites.
 *
 * @param string $type common|better_than|worse_than|gap.
 * @return array|WP_Error list of [word, rank1, rank2, volume]
 */
function sap_ss_compare( $site1, $site2, $type = 'gap' ) {
	// Both domains are charged as soon as they are valid (2 units).
	$res = sap_ss_request( 'site-compare', array(
		'site1'        => $site1,
		'site2'        => $site2,
		'compare_type' => $type,
	), 2 );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$out = array();
	foreach ( (array) ( $res['data'] ?? array() ) as $r ) {
		$out[] = array(
			'word'   => (string) ( $r['Word'] ?? '' ),
			'rank1'  => (int) ( $r['Rank1'] ?? 0 ),
			'rank2'  => (int) ( $r['Rank2'] ?? 0 ),
			'volume' => (int) ( $r['SearchVolume'] ?? 0 ),
			'diff'   => (int) ( $r['Diff'] ?? 0 ),
		);
	}
	return $out;
}

/**
 * Current rank status of a project's domain (free): rank, change, best rank,
 * volume, and the rank / change distributions.
 */
function sap_ss_rt_status( $project_id, $device_id, $domain = '', $start = '', $end = '' ) {
	$body = array( 'project_id' => (int) $project_id, 'device_id' => (int) $device_id );
	foreach ( array( 'domain' => $domain, 'start_date' => $start, 'end_date' => $end ) as $k => $v ) {
		if ( '' !== $v ) {
			$body[ $k ] = $v;
		}
	}
	$res = sap_ss_request( 'ranktracker-rank-status', $body, false );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$d    = (array) ( $res['data'] ?? array() );
	$rows = array();
	foreach ( (array) ( $d['keywords'] ?? array() ) as $k ) {
		$rows[] = array(
			'keyword'     => (string) ( $k['keyword'] ?? '' ),
			'rank'        => (int) ( $k['rank'] ?? 0 ),
			'rank_change' => (int) ( $k['rank_change'] ?? 0 ),
			'best_rank'   => (int) ( $k['best_rank'] ?? 0 ),
			'avg_rank'    => (float) ( $k['avg_rank'] ?? 0 ),
			'volume'      => (int) ( $k['search_volume'] ?? 0 ),
			'target_url'  => (string) ( $k['target_url'] ?? '' ),
		);
	}
	return array(
		'rows' => $rows,
		'meta' => array(
			'domain'       => (string) ( $d['domain'] ?? '' ),
			'range'        => (array) ( $d['date_range'] ?? array() ),
			'distribution' => (array) ( $d['rank_distribution'] ?? array() ),
			'changes'      => (array) ( $d['rank_change_distribution'] ?? array() ),
			'visibility'   => (array) ( $d['visibility_series'] ?? array() ),
		),
	);
}

/**
 * Rank tracker projects (free).
 */
function sap_ss_rt_projects() {
	$res = sap_ss_request( 'ranktracker-projects', array(), false );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$out = array();
	foreach ( (array) ( $res['data']['List'] ?? array() ) as $p ) {
		$out[] = array(
			'id'       => (int) ( $p['Id'] ?? 0 ),
			'name'     => (string) ( $p['Name'] ?? '' ),
			'domain'   => (string) ( $p['MainDomain'] ?? '' ),
			'keywords' => (int) ( $p['KeywordCount'] ?? 0 ),
			'active'   => ! empty( $p['IsActive'] ),
		);
	}
	return $out;
}

/**
 * Rank tracker project detail (free): keywords, competitors, device ids.
 */
function sap_ss_rt_detail( $project_id ) {
	$res = sap_ss_request( 'ranktracker-project-detail', array( 'project_id' => (int) $project_id ), false );
	return is_wp_error( $res ) ? $res : (array) ( $res['data'] ?? array() );
}

/**
 * Day-by-day rank history (free), max 90 days.
 */
function sap_ss_rt_history( $project_id, $device_id, $domain = '', $start = '', $end = '' ) {
	$body = array( 'project_id' => (int) $project_id, 'device_id' => (int) $device_id );
	if ( $domain ) {
		$body['domain'] = $domain;
	}
	if ( $start ) {
		$body['start_date'] = $start;
	}
	if ( $end ) {
		$body['end_date'] = $end;
	}
	$res = sap_ss_request( 'ranktracker-rank-history', $body, false );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$out = array();
	foreach ( (array) ( $res['data']['keywords'] ?? array() ) as $k ) {
		$out[] = array(
			'keyword'    => (string) ( $k['keyword'] ?? '' ),
			'volume'     => (int) ( $k['search_volume'] ?? 0 ),
			'target_url' => (string) ( $k['target_url'] ?? '' ),
			'start_rank' => (int) ( $k['start_rank'] ?? 0 ),
			'end_rank'   => (int) ( $k['end_rank'] ?? 0 ),
			'best_rank'  => (int) ( $k['best_rank'] ?? 0 ),
			'avg_rank'   => (float) ( $k['avg_rank'] ?? 0 ),
		);
	}
	return array(
		'domain'     => (string) ( $res['data']['domain'] ?? '' ),
		'date_range' => (array) ( $res['data']['date_range'] ?? array() ),
		'visibility' => (array) ( $res['data']['visibility_series'] ?? array() ),
		'avg_rank'   => (array) ( $res['data']['avg_rank_series'] ?? array() ),
		'keywords'   => $out,
	);
}
