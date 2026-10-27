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
function sap_ss_request( $endpoint, $body = array(), $metered = true ) {
	$key = sap_setting( 'seosignal_key' );
	if ( '' === $key ) {
		return new WP_Error( 'sap_ss_key', 'کلید API سئو سیگنال وارد نشده است (تنظیمات › سئو سیگنال).' );
	}
	if ( $metered && sap_ss_remaining() < 1 ) {
		return new WP_Error( 'sap_ss_cap', 'سقف روزانه درخواست‌های سئو سیگنال که برای این افزونه تعیین کرده‌اید پر شده است؛ فردا (ساعت ۰۰:۰۰ تهران) ادامه می‌دهد.' );
	}
	$res = wp_remote_post( SAP_SS_BASE . $endpoint, array(
		'timeout' => 60,
		'headers' => array(
			'X-Api-Key'    => $key,
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		),
		'body'    => wp_json_encode( (object) $body ),
	) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'sap_ss_http', 'اتصال به سئو سیگنال ناموفق بود: ' . sap_net_error( $res ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
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
		return $data;
	}
	$err  = is_array( $data ) ? ( $data['error'] ?? array() ) : array();
	$ecode = (string) ( $err['code'] ?? 'HTTP_' . $code );
	$msgs = array(
		'INVALID_API_KEY'     => 'کلید API سئو سیگنال نامعتبر است.',
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
	return new WP_Error( 'sap_ss_' . strtolower( $ecode ), $msg, array( 'status' => $code, 'code' => $ecode ) );
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
