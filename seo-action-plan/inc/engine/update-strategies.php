<?php
/**
 * Content update strategies («آپدیت محتوا»): the five ways the agency picks pages
 * to update, each turned into a scored list of candidate pages from real data.
 *
 *  s1  High impressions, low CTR      → title / meta / H1 / intro on the queries that show but are not clicked.
 *  s2  On the edge of page one        → average position above 10 with impressions; push to page one.
 *  s3  Query gaps                     → queries the page shows for but does not answer (new H2/H3, FAQ, links).
 *  s4  Content decay                  → clicks / impressions fell against the previous period; which queries dropped.
 *  s5  Important pages not indexed    → crawled / discovered but not indexed (or no impressions at all).
 *
 * A refresh pulls what is missing (90-day page comparison, the dropped queries of
 * the decaying pages, page texts, URL inspection for important pages) and stores
 * the lists; an AI pass can analyse one page for one strategy in depth. Picked
 * pages become content updates of the cycle (and rows of the update sheet).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * The five strategies: name, goal, steps.
 */
function sap_upd_strategies() {
	return array(
		's1' => array(
			'name'  => 'ایمپرشن بالا، CTR پایین',
			'icon'  => '🔎',
			'goal'  => 'صفحاتی که زیاد در نتایج دیده می‌شوند ولی کلیک نمی‌گیرند (CTR حدود ۱ تا ۳٪)؛ هدف: رساندن CTR به ۵، ۷ و بعد ۱۰٪ به بالا.',
			'steps' => array( 'کوئری‌های پرایمپرشن و کم‌کلیک صفحه را پیدا کنید', 'تایتل و متادیسکریپشن را با همین کلمه بهینه کنید', 'H1 را بهینه کنید', 'مقدمه را کوتاه کنید و کلمه دقیق را در آن بیاورید', 'کلمات تکرارنشده را اضافه و کم‌تکرارها را تا ۳ یا ۴ بار برسانید', 'تصویر، ویدیو و کامنت اضافه کنید', 'از صفحات دیگر به این صفحه لینک داخلی بدهید', 'کلمات با مفهوم مستقل را برای محتوای جدید جدا کنید' ),
		),
		's2' => array(
			'name'  => 'در آستانه صفحه اول',
			'icon'  => '🚀',
			'goal'  => 'صفحاتی با میانگین جایگاه بالاتر از ۱۰ (صفحه دوم و سوم گوگل) و نمایش زیاد؛ با یک «هل» به صفحه اول و بعد رتبه ۱ تا ۳ برسند.',
			'steps' => array( 'کوئری‌های صفحه را بر اساس نمایش مرتب کنید', 'موردی که اصلاً در صفحه نیامده را به‌صورت H3 جدید اضافه کنید', 'برای موارد موجود محتوای بیشتر بنویسید', 'تایتل، متا، H1 و مقدمه را مثل استراتژی اول بهینه کنید' ),
		),
		's3' => array(
			'name'  => 'رفع گپ محتوایی کوئری‌ها',
			'icon'  => '🧩',
			'goal'  => 'کوئری‌هایی که گوگل صفحه را برایشان نشان می‌دهد ولی صفحه مستقیم و کامل جوابشان را نداده؛ صفحه باید همه ابعاد موضوع را پوشش دهد.',
			'steps' => array( 'برای موضوعات ناقص یا غایب H2/H3 جدید با پاسخ مستقیم اضافه کنید (هزینه، آدرس، ویژگی‌ها، تصاویر، ویدیو، FAQ)', 'کلمات تکرارنشده را در متن بیاورید', 'کوئری با پتانسیل صفحه مستقل: یک H3 کوتاه + مقاله جدا و لینک به آن', 'کوئری مربوط به صفحه دیگر سایت: از همین صفحه با همان کلمه به آن لینک دهید' ),
		),
		's4' => array(
			'name'  => 'احیای صفحات افت‌کرده',
			'icon'  => '📉',
			'goal'  => 'صفحاتی که قبلاً خوب بودند و کلیک یا نمایششان نسبت به دوره قبل افت کرده (Content Decay)؛ جلوی افت را بگیرید و دوباره بالا بیاورید.',
			'steps' => array( 'کوئری‌هایی که بیشترین افت نمایش را داشته‌اند پیدا کنید (نامرتبط‌ها را کنار بگذارید)', 'کلمات افت‌کرده را در متن تکرار کنید', 'از کلمه افت‌کرده در تیترها و یک H3 جدید استفاده کنید', 'تصویر، ویدیو و بخش سوالات متداول اضافه کنید', 'اگر کل محتوا قدیمی است، بازنویسی صفر تا صد' ),
		),
		's5' => array(
			'name'  => 'صفحات مهم ایندکس‌نشده',
			'icon'  => '🗂️',
			'goal'  => 'صفحات مهمی که «کرال‌شده/دیسکاورشده ولی ایندکس‌نشده» مانده‌اند یا اصلاً در گوگل دیده نمی‌شوند؛ غنی‌سازی، درخواست ایندکس و لینک داخلی.',
			'steps' => array( 'صفحات مهم را از کم‌اهمیت‌ها جدا کنید', 'تایتل و متا را به‌روز کنید', 'مقدمه را به‌روز کنید', 'سوالات متداول و جمع‌بندی اضافه کنید', '۳ تا ۴ کامنت اضافه کنید', 'درخواست ایندکس در سرچ کنسول ثبت کنید', 'از صفحات مرتبط به این صفحه لینک داخلی بدهید' ),
		),
	);
}

/**
 * Pages that are not content (cart, account, feeds, paging, parameters).
 */
function sap_upd_is_junk( $url ) {
	$u = strtolower( rawurldecode( (string) $url ) );
	return (bool) preg_match( '#/(cart|checkout|my-account|wp-json|wp-admin|feed|tag/[^/]+/feed|page/\d+)(/|$)|\?|/(سبد-خرید|تسویه-حساب|حساب-کاربری)/#u', $u );
}

/**
 * Page title for a URL (inventory → keyword map → crawl → path).
 */
function sap_upd_title( $project_id, $url ) {
	$it = sap_site_item( $project_id, $url );
	return $it ? $it['title'] : ( sap_page_title( $project_id, $url ) ?: sap_short_url( $url, 60 ) );
}

/**
 * Text of a page for coverage checks: page reader dataset, then this module's cache,
 * then a live read (at most $budget new reads per run).
 */
function sap_upd_page( $project_id, $url, &$budget ) {
	$k = sap_norm_url( $url );
	$p = sap_dataset_payload( $project_id, 'pages' )['pages'][ $k ] ?? null;
	if ( $p ) {
		return $p;
	}
	$cache = get_option( 'sap_upd_pages_' . $project_id, array() );
	if ( isset( $cache[ $k ] ) ) {
		return $cache[ $k ];
	}
	if ( $budget <= 0 ) {
		return null;
	}
	--$budget;
	$p = sap_page_fetch( $url );
	if ( is_wp_error( $p ) ) {
		return null;
	}
	$keep        = array( 'title' => $p['title'], 'meta' => $p['meta'], 'h1' => $p['h1'], 'h2' => array_slice( (array) $p['h2'], 0, 30 ), 'h3' => array_slice( (array) ( $p['h3'] ?? array() ), 0, 40 ), 'text' => mb_substr( (string) $p['text'], 0, 12000 ), 'words' => $p['words'] );
	$cache[ $k ] = $keep;
	update_option( 'sap_upd_pages_' . $project_id, array_slice( $cache, -60, null, true ), false );
	return $keep;
}

/**
 * Pull the data the strategies need and build the five lists.
 *
 * @return array|WP_Error Summary counts.
 */
function sap_upd_refresh( $project ) {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore
	}
	$pid    = $project['id'];
	$live   = '' !== (string) $project['gsc_property'] && sap_google_connected();
	$notes  = array();
	// 90-day page comparison (decay over a season, not just a month).
	if ( $live ) {
		$w    = sap_gsc_windows();
		$end  = $w['cur'][1];
		$c0   = gmdate( 'Y-m-d', strtotime( $end . ' -89 days' ) );
		$p1   = gmdate( 'Y-m-d', strtotime( $c0 . ' -1 day' ) );
		$p0   = gmdate( 'Y-m-d', strtotime( $p1 . ' -89 days' ) );
		$cur  = sap_gsc_query( $project['gsc_property'], $c0, $end, array( 'page' ), 10000 );
		$prev = is_wp_error( $cur ) ? $cur : sap_gsc_query( $project['gsc_property'], $p0, $p1, array( 'page' ), 10000 );
		if ( ! is_wp_error( $prev ) ) {
			sap_dataset_put( $pid, 'gsc_pages_90', array( 'cur' => $cur, 'prev' => $prev ), array( 'cur' => array( $c0, $end ), 'prev' => array( $p0, $p1 ) ) );
		} else {
			$notes[] = 'مقایسه ۹۰ روزه: ' . $prev->get_error_message();
		}
	}
	$g28   = sap_dataset( $pid, 'gsc_pages' );
	$g90   = sap_dataset( $pid, 'gsc_pages_90' );
	$pages = array();
	foreach ( array( 'cur', 'prev' ) as $w ) {
		foreach ( (array) ( $g28['payload'][ $w ] ?? array() ) as $r ) {
			$pages[ sap_norm_url( $r['page'] ) ][ $w ] = $r;
		}
		foreach ( (array) ( $g90['payload'][ $w ] ?? array() ) as $r ) {
			$pages[ sap_norm_url( $r['page'] ) ][ $w . '90' ] = $r;
		}
	}
	$index  = sap_content_index( $project );
	$inv    = sap_site_inventory( $pid );
	$budget = 20;
	$lists  = array( 's1' => array(), 's2' => array(), 's3' => array(), 's4' => array(), 's5' => array() );
	$row_of = function ( $k, $url ) use ( $index ) {
		return $index[ $k ][1] ?? array( 'url' => $url, 'type' => 'page', 'title' => '', 'kw' => '', 'secondary' => array(), 'impr' => 0, 'clicks' => 0, 'focus_id' => 0, 'words' => null, 'wp' => null );
	};
	foreach ( $pages as $k => $x ) {
		$c = $x['cur'] ?? null;
		if ( ! $c || sap_upd_is_junk( $c['page'] ) || ( isset( $inv[ $k ] ) && ( '' !== (string) $inv[ $k ]['redirect'] || $inv[ $k ]['noindex'] ) ) ) {
			continue;
		}
		$impr = (int) $c['impressions'];
		$clk  = (int) $c['clicks'];
		$ctr  = $impr ? $clk / $impr : 0;
		$pos  = (float) $c['position'];
		$base = array( 'url' => $c['page'], 'impr' => $impr, 'clicks' => $clk, 'ctr' => round( 100 * $ctr, 2 ), 'pos' => round( $pos, 1 ) );
		// s1: seen a lot, clicked little.
		if ( $impr >= 150 && $pos <= 20 && $ctr < 0.03 ) {
			$target = max( 0.05, sap_ctr_expected( $pos ) );
			$pot    = (int) round( $impr * ( $target - $ctr ) );
			if ( $pot >= 5 ) {
				$lists['s1'][ $k ] = $base + array( 'potential' => $pot, 'why' => sprintf( '%s نمایش با CTR %s٪ در جایگاه %s؛ با CTR %s٪ حدود +%s کلیک.', sap_num( $impr ), sap_num( round( 100 * $ctr, 1 ), 1 ), sap_num( $pos, 1 ), sap_num( round( 100 * $target ) ), sap_num( $pot ) ) );
			}
		}
		// s2: page two and three with impressions.
		if ( $pos > 10 && $pos <= 40 && $impr >= 80 ) {
			$pot = (int) max( 0, round( $impr * sap_ctr_expected( 8 ) - $clk ) );
			$lists['s2'][ $k ] = $base + array( 'potential' => $pot, 'why' => sprintf( 'میانگین جایگاه %s (صفحه %s گوگل) با %s نمایش؛ رسیدن به صفحه اول حدود +%s کلیک.', sap_num( $pos, 1 ), sap_num( (int) ceil( $pos / 10 ) ), sap_num( $impr ), sap_num( $pot ) ) );
		}
		// s4: decay against the previous period (90 days when available, else 28).
		$pc = $x['prev90'] ?? null;
		$cc = $x['cur90'] ?? null;
		$span = '۹۰';
		if ( ! $pc || ! $cc ) {
			$pc   = $x['prev'] ?? null;
			$cc   = $c;
			$span = '۲۸';
		}
		if ( $pc ) {
			$lost_c = (int) $pc['clicks'] - (int) $cc['clicks'];
			$lost_i = (int) $pc['impressions'] - (int) $cc['impressions'];
			$drop_c = $pc['clicks'] ? $lost_c / $pc['clicks'] : 0;
			$drop_i = $pc['impressions'] ? $lost_i / $pc['impressions'] : 0;
			if ( ( $drop_c >= 0.2 && $lost_c >= 10 ) || ( $drop_i >= 0.3 && $lost_i >= 300 ) ) {
				$lists['s4'][ $k ] = $base + array(
					'potential' => (int) round( max( 0, $lost_c ) + max( 0, $lost_i ) * 0.02 ),
					'prev'      => array( 'clicks' => (int) $pc['clicks'], 'impr' => (int) $pc['impressions'], 'pos' => round( (float) $pc['position'], 1 ) ),
					'why'       => sprintf( 'در %s روز اخیر نسبت به %s روز قبل: کلیک %s → %s (%s٪)، نمایش %s → %s.', $span, $span, sap_num( $pc['clicks'] ), sap_num( $cc['clicks'] ), sap_num( -round( 100 * $drop_c ) ), sap_num( $pc['impressions'] ), sap_num( $cc['impressions'] ) ),
				);
			}
		}
	}
	// s3: query gaps of the most visible pages (needs the page text).
	$cands = array_keys( array_slice( $lists['s1'], 0, 12, true ) + array_slice( $lists['s2'], 0, 12, true ) );
	uasort( $pages, function ( $a, $b ) {
		return ( (int) ( $b['cur']['impressions'] ?? 0 ) ) <=> ( (int) ( $a['cur']['impressions'] ?? 0 ) );
	} );
	foreach ( array_slice( array_keys( $pages ), 0, 20 ) as $k ) {
		$cands[] = $k;
	}
	$keymap = sap_keymap( $pid );
	$kw_owner = array();
	foreach ( $keymap as $mk => $m ) {
		if ( '' !== (string) $m['primary_kw'] ) {
			$kw_owner[ sap_kw_key( $m['primary_kw'] ) ] = $m['url'];
		}
	}
	foreach ( array_unique( $cands ) as $k ) {
		$c = $pages[ $k ]['cur'] ?? null;
		if ( ! $c || sap_upd_is_junk( $c['page'] ) ) {
			continue;
		}
		$pg  = sap_upd_page( $pid, $c['page'], $budget );
		$sig = sap_page_signals( $project, $row_of( $k, $c['page'] ), $pg );
		$gap = array();
		$lnk = array();
		foreach ( $sig['queries'] as $q ) {
			$owner = $kw_owner[ sap_kw_key( $q['q'] ) ] ?? '';
			if ( $owner && sap_norm_url( $owner ) !== $k && $q['impr'] >= 20 ) {
				$lnk[] = array( 'q' => $q['q'], 'impr' => $q['impr'], 'to' => $owner );
				continue;
			}
			if ( $q['impr'] >= 20 && ( false === $q['in_text'] || ( $q['striking'] && false === $q['in_head'] ) ) ) {
				$gap[] = array( 'q' => $q['q'], 'impr' => $q['impr'], 'pos' => $q['pos'], 'missing' => false === $q['in_text'] ? 'text' : 'heading' );
			}
		}
		if ( $gap || $lnk ) {
			$imp = array_sum( array_column( $gap, 'impr' ) ) + array_sum( array_column( $lnk, 'impr' ) ) / 2;
			$lists['s3'][ $k ] = array( 'url' => $c['page'], 'impr' => (int) $c['impressions'], 'clicks' => (int) $c['clicks'], 'ctr' => round( 100 * $c['ctr'], 2 ), 'pos' => round( $c['position'], 1 ), 'potential' => (int) round( $imp * 0.05 ), 'gap' => array_slice( $gap, 0, 8 ), 'links' => array_slice( $lnk, 0, 5 ), 'read' => (bool) $pg, 'why' => sprintf( '%s کوئری با %s نمایش که صفحه جوابشان را نداده%s.', sap_num( count( $gap ) ), sap_num( array_sum( array_column( $gap, 'impr' ) ) ), $lnk ? sprintf( '؛ %s کوئری مال صفحه دیگری است (لینک داخلی)', sap_num( count( $lnk ) ) ) : '' ) );
		}
		// Query flags for the other lists too.
		foreach ( array( 's1', 's2', 's4' ) as $s ) {
			if ( isset( $lists[ $s ][ $k ] ) ) {
				$lists[ $s ][ $k ]['queries'] = array_slice( array_map( function ( $q ) {
					return array( 'q' => $q['q'], 'impr' => $q['impr'], 'clicks' => $q['clicks'], 'pos' => $q['pos'], 'in_title' => $q['in_title'], 'in_text' => $q['in_text'] );
				}, $sig['queries'] ), 0, 8 );
				$lists[ $s ][ $k ]['read'] = (bool) $pg;
			}
		}
	}
	// s4: the queries that dropped most, for the biggest decays.
	uasort( $lists['s4'], function ( $a, $b ) {
		return $b['potential'] <=> $a['potential'];
	} );
	if ( $live ) {
		$win = $g90 ? $g90['meta'] : $g28['meta'];
		foreach ( array_slice( array_keys( $lists['s4'] ), 0, 8 ) as $k ) {
			$u    = $lists['s4'][ $k ]['url'];
			$flt  = array( 'dimensionFilterGroups' => array( array( 'filters' => array( array( 'dimension' => 'page', 'operator' => 'equals', 'expression' => $u ) ) ) ) );
			$now  = sap_gsc_query( $project['gsc_property'], $win['cur'][0], $win['cur'][1], array( 'query' ), 300, $flt );
			$then = is_wp_error( $now ) ? $now : sap_gsc_query( $project['gsc_property'], $win['prev'][0], $win['prev'][1], array( 'query' ), 300, $flt );
			if ( is_wp_error( $then ) ) {
				$notes[] = 'کوئری‌های افت‌کرده: ' . $then->get_error_message();
				break;
			}
			$n = array();
			foreach ( $now as $r ) {
				$n[ sap_kw_key( $r['query'] ) ] = $r;
			}
			$drops = array();
			foreach ( $then as $r ) {
				$was = (int) $r['impressions'];
				$is  = (int) ( $n[ sap_kw_key( $r['query'] ) ]['impressions'] ?? 0 );
				if ( $was - $is >= 20 ) {
					$drops[] = array( 'q' => $r['query'], 'was' => $was, 'is' => $is, 'pos_was' => round( $r['position'], 1 ), 'pos_is' => isset( $n[ sap_kw_key( $r['query'] ) ] ) ? round( $n[ sap_kw_key( $r['query'] ) ]['position'], 1 ) : null );
				}
			}
			usort( $drops, function ( $a, $b ) {
				return ( $b['was'] - $b['is'] ) <=> ( $a['was'] - $a['is'] );
			} );
			$lists['s4'][ $k ]['drops'] = array_slice( $drops, 0, 8 );
		}
	}
	// s5: important pages Google does not show: inspection says not indexed, or no impressions at all.
	$seen = array();
	foreach ( $pages as $k => $x ) {
		if ( (int) ( $x['cur']['impressions'] ?? 0 ) + (int) ( $x['cur90']['impressions'] ?? 0 ) > 0 ) {
			$seen[ $k ] = true;
		}
	}
	$insp = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_inspect' )['rows'] ?? array() ) as $r ) {
		$insp[ sap_norm_url( $r['url'] ) ] = $r;
	}
	$cached = get_option( 'sap_upd_inspect_' . $pid, array() );
	$insp  += is_array( $cached ) ? $cached : array();
	$weight = array( 'product_cat' => 5, 'brand' => 4, 'page' => 4, 'product' => 3, 'post' => 2, 'post_cat' => 1 );
	$s5     = array();
	foreach ( $inv as $k => $it ) {
		if ( '' !== (string) $it['redirect'] || $it['noindex'] || sap_upd_is_junk( $it['url'] ) || ! isset( $weight[ $it['ctype'] ] ) ) {
			continue;
		}
		$cov    = (string) ( $insp[ $k ]['coverage'] ?? '' );
		$not_ix = '' !== $cov && ( false !== stripos( $cov, 'not indexed' ) || false !== stripos( $cov, 'نشده' ) || 'NEUTRAL' === ( $insp[ $k ]['verdict'] ?? '' ) || 'FAIL' === ( $insp[ $k ]['verdict'] ?? '' ) );
		if ( ! $not_ix && isset( $seen[ $k ] ) ) {
			continue;
		}
		if ( '' !== $cov && ! $not_ix ) {
			continue; // Inspected and indexed: just not seen yet.
		}
		$score = $weight[ $it['ctype'] ] * 10 + ( $it['words'] < 300 ? 10 : 0 ) + ( $not_ix ? 25 : 0 );
		$s5[ $k ] = array( 'url' => $it['url'], 'impr' => 0, 'clicks' => 0, 'ctr' => 0, 'pos' => null, 'potential' => $score, 'coverage' => $cov, 'words' => (int) $it['words'], 'type' => $it['ctype'], 'why' => $cov ? 'وضعیت سرچ کنسول: ' . $cov . '.' : 'در ۹۰ روز گذشته هیچ نمایشی در گوگل نداشته؛ احتمالاً ایندکس نشده.' );
	}
	uasort( $s5, function ( $a, $b ) {
		return $b['potential'] <=> $a['potential'];
	} );
	// Confirm the top candidates with URL inspection (cached a week).
	if ( $live ) {
		$checked = 0;
		foreach ( $s5 as $k => &$x ) {
			if ( $checked >= 12 || '' !== $x['coverage'] ) {
				continue;
			}
			++$checked;
			$res = sap_google_request( 'POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', array( 'inspectionUrl' => $x['url'], 'siteUrl' => $project['gsc_property'], 'languageCode' => 'fa' ), 40 );
			if ( is_wp_error( $res ) ) {
				$notes[] = 'بررسی ایندکس: ' . $res->get_error_message();
				break;
			}
			$i             = (array) ( $res['inspectionResult']['indexStatusResult'] ?? array() );
			$x['coverage'] = (string) ( $i['coverageState'] ?? '' );
			$cached[ $k ]  = array( 'coverage' => $x['coverage'], 'verdict' => (string) ( $i['verdict'] ?? '' ), 'at' => sap_now() );
			if ( 'PASS' === ( $i['verdict'] ?? '' ) ) {
				$x = null; // Indexed after all.
				continue;
			}
			$x['potential'] += 25;
			$x['why']        = 'وضعیت سرچ کنسول: ' . $x['coverage'] . '.';
		}
		unset( $x );
		update_option( 'sap_upd_inspect_' . $pid, $cached, false );
		$s5 = array_filter( $s5 );
	}
	$lists['s5'] = array_slice( $s5, 0, 40, true );
	// Order and score 0–100 within each list.
	foreach ( $lists as $s => &$list ) {
		uasort( $list, function ( $a, $b ) {
			return $b['potential'] <=> $a['potential'];
		} );
		$list = array_slice( $list, 0, 40, true );
		$max  = max( 1, max( array_merge( array( 1 ), array_column( $list, 'potential' ) ) ) );
		foreach ( $list as &$x ) {
			$x['score'] = (int) round( 100 * sqrt( $x['potential'] / $max ) );
			$x['title'] = sap_upd_title( $pid, $x['url'] );
		}
		unset( $x );
	}
	unset( $list );
	sap_dataset_put( $pid, 'upd_strategies', $lists, array( 'notes' => $notes, 'live' => $live, 'w90' => (bool) $g90 ) );
	return array( 'counts' => array_map( 'count', $lists ), 'notes' => $notes );
}

/**
 * Every candidate (all strategies), best first, one entry per page with its strategies.
 */
function sap_upd_ranked( $project_id ) {
	$lists = (array) sap_dataset_payload( $project_id, 'upd_strategies' );
	$out   = array();
	$bonus = array( 's1' => 1.0, 's2' => 0.9, 's3' => 0.8, 's4' => 1.0, 's5' => 0.7 );
	foreach ( $lists as $s => $list ) {
		foreach ( (array) $list as $k => $x ) {
			$v = $x['score'] * ( $bonus[ $s ] ?? 1 );
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array( 'url' => $x['url'], 'title' => $x['title'], 'value' => 0, 'strategies' => array() );
			}
			$out[ $k ]['strategies'][ $s ] = $x;
			$out[ $k ]['best']             = max( $out[ $k ]['best'] ?? 0, $v );
		}
	}
	foreach ( $out as &$o ) {
		$o['value'] = $o['best'] + 5 * ( count( $o['strategies'] ) - 1 ); // A page several strategies agree on comes first.
	}
	unset( $o );
	uasort( $out, function ( $a, $b ) {
		return $b['value'] <=> $a['value'];
	} );
	return $out;
}

/**
 * Text of a candidate for the update reason (strategy, evidence, steps, AI result).
 */
function sap_upd_reason( $project_id, $url, $strategies ) {
	$defs = sap_upd_strategies();
	$ai   = (array) ( get_option( 'sap_upd_ai_' . $project_id, array() ) );
	$out  = array();
	foreach ( $strategies as $s => $x ) {
		$lines   = array( '◆ استراتژی: ' . $defs[ $s ]['name'], 'شاهد: ' . $x['why'] );
		foreach ( (array) ( $x['queries'] ?? array() ) as $q ) {
			if ( ! $q['in_title'] || false === $q['in_text'] ) {
				$lines[] = sprintf( '  · «%s» — %s نمایش، جایگاه %s%s', $q['q'], sap_num( $q['impr'] ), sap_num( $q['pos'], 1 ), false === $q['in_text'] ? '، در متن نیست' : ( ! $q['in_title'] ? '، در تایتل نیست' : '' ) );
			}
		}
		foreach ( (array) ( $x['gap'] ?? array() ) as $g ) {
			$lines[] = sprintf( '  · گپ: «%s» (%s نمایش)%s', $g['q'], sap_num( $g['impr'] ), 'text' === $g['missing'] ? ' — در متن نیامده' : ' — در هیچ تیتری نیست' );
		}
		foreach ( (array) ( $x['links'] ?? array() ) as $l ) {
			$lines[] = sprintf( '  · لینک داخلی با انکر «%s» به %s', $l['q'], rawurldecode( $l['to'] ) );
		}
		foreach ( (array) ( $x['drops'] ?? array() ) as $d ) {
			$lines[] = sprintf( '  · افت «%s»: نمایش %s → %s', $d['q'], sap_num( $d['was'] ), sap_num( $d['is'] ) );
		}
		$a = $ai[ sap_norm_url( $url ) . '|' . $s ] ?? null;
		if ( $a ) {
			$lines[] = 'تحلیل هوشمند: ' . $a['diagnosis'];
			foreach ( array( 'title' => 'تایتل', 'meta' => 'متا', 'h1' => 'H1', 'intro' => 'مقدمه' ) as $f => $l ) {
				if ( '' !== trim( (string) ( $a[ $f ] ?? '' ) ) ) {
					$lines[] = $l . ': ' . $a[ $f ];
				}
			}
			foreach ( (array) ( $a['sections'] ?? array() ) as $sec ) {
				$lines[] = '  + ' . strtoupper( $sec['tag'] ) . ' «' . $sec['heading'] . '»: ' . $sec['what'];
			}
			if ( ! empty( $a['faq'] ) ) {
				$lines[] = 'FAQ: ' . implode( ' | ', $a['faq'] );
			}
		}
		$lines[] = 'گام‌ها: ' . implode( '؛ ', $defs[ $s ]['steps'] );
		$out[]   = implode( "\n", $lines );
	}
	return implode( "\n\n", $out );
}

/**
 * Plan a candidate as a content update of a period.
 */
function sap_upd_add( $project, $url, $period, $only = '' ) {
	$k    = sap_norm_url( $url );
	$cand = sap_upd_ranked( $project['id'] )[ $k ] ?? null;
	$strs = $cand ? $cand['strategies'] : array();
	if ( $only && isset( $strs[ $only ] ) ) {
		$strs = array( $only => $strs[ $only ] );
	}
	$pri    = $cand && $cand['value'] >= 60 ? 'high' : 'medium';
	$reason = sap_upd_reason( $project['id'], $url, $strs );
	$id     = sap_update_plan( $project, $url, $period, array( 'reason' => $reason, 'source' => 'strategy', 'priority' => $pri ) );
	// Already planned this period (e.g. from the page analysis): add the strategy evidence to it.
	$u = $id ? sap_update( $id ) : null;
	if ( $u && '' !== $reason && false === mb_strpos( $u['reason'], $reason ) ) {
		sap_update_save( array( 'reason' => trim( $reason . ( '' !== trim( $u['reason'] ) ? "\n\n" . $u['reason'] : '' ) ), 'priority' => $pri ), $id );
	}
	return $id;
}

/**
 * Plan the best k candidates not planned this period nor updated in the last 60 days
 * (used by the weekly builder when the month's update quota is not planned yet).
 *
 * @return int Planned.
 */
function sap_update_pick_best( $project, $period, $k ) {
	$skip = array();
	foreach ( sap_updates( $project['id'] ) as $u ) {
		if ( $u['period'] === $period || ( 'done' === ( $u['status'] ?? '' ) && strtotime( (string) ( $u['updated_at'] ?? $u['created_at'] ?? '' ) ) > strtotime( '-60 days' ) ) ) {
			$skip[ sap_norm_url( $u['url'] ) ] = true;
		}
	}
	$n = 0;
	foreach ( sap_upd_ranked( $project['id'] ) as $key => $c ) {
		if ( $n >= $k ) {
			break;
		}
		if ( isset( $skip[ $key ] ) ) {
			continue;
		}
		sap_upd_add( $project, $c['url'], $period );
		++$n;
	}
	return $n;
}

/**
 * Deep AI analysis of one page for one strategy: ready texts and sections.
 *
 * @return array|WP_Error
 */
function sap_upd_ai( $project, $url, $s ) {
	$defs = sap_upd_strategies();
	if ( ! isset( $defs[ $s ] ) ) {
		return new WP_Error( 'sap_upd', 'استراتژی نامعتبر است.' );
	}
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_upd', 'هوش مصنوعی در تنظیمات وصل نشده است.' );
	}
	$pid    = $project['id'];
	$k      = sap_norm_url( $url );
	$x      = ( (array) sap_dataset_payload( $pid, 'upd_strategies' ) )[ $s ][ $k ] ?? array( 'url' => $url, 'why' => '' );
	$budget = 1;
	$pg     = sap_upd_page( $pid, $url, $budget );
	$row    = sap_content_index( $project )[ $k ][1] ?? array( 'url' => $url, 'type' => 'page', 'title' => '', 'kw' => '', 'secondary' => array(), 'impr' => 0, 'clicks' => 0, 'focus_id' => 0, 'words' => null, 'wp' => null );
	$sig    = sap_page_signals( $project, $row, $pg );
	$facts  = array(
		'url'      => rawurldecode( $url ),
		'type'     => sap_content_types()[ $row['type'] ] ?? $row['type'],
		'keyword'  => $row['kw'],
		'title'    => $pg['title'] ?? '',
		'meta'     => $pg['meta'] ?? '',
		'h1'       => implode( ' | ', (array) ( $pg['h1'] ?? array() ) ),
		'headings' => array_slice( array_merge( (array) ( $pg['h2'] ?? array() ), (array) ( $pg['h3'] ?? array() ) ), 0, 30 ),
		'intro'    => mb_substr( (string) ( $pg['text'] ?? '' ), 0, 900 ),
		'text'     => mb_substr( (string) ( $pg['text'] ?? '' ), 900, 3500 ),
		'evidence' => $x['why'],
		'signals'  => sap_page_signals_ai( $sig ),
		'gaps'     => $x['gap'] ?? array(),
		'links'    => $x['links'] ?? array(),
		'drops'    => $x['drops'] ?? array(),
		'coverage' => $x['coverage'] ?? '',
	);
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array(
		'diagnosis' => array( 'type' => 'string', 'description' => '۲ تا ۳ جمله: مشکل اصلی صفحه در این استراتژی با عدد' ),
		'title'     => array( 'type' => 'string', 'description' => 'تایتل پیشنهادی ≤ ۶۰ کاراکتر با کوئری پرایمپرشن کم‌کلیک، یا خالی' ),
		'meta'      => array( 'type' => 'string', 'description' => 'متا ۱۲۰ تا ۱۵۵ کاراکتر، یا خالی' ),
		'h1'        => array( 'type' => 'string', 'description' => 'H1 پیشنهادی یا خالی' ),
		'intro'     => array( 'type' => 'string', 'description' => 'مقدمه کوتاه و جدید (۲ تا ۳ جمله) با کلمه دقیق، یا خالی' ),
		'sections'  => array( 'type' => 'array', 'items' => sap_ai_obj( array(
			'tag'     => array( 'type' => 'string', 'enum' => array( 'h2', 'h3' ) ),
			'heading' => $str,
			'what'    => array( 'type' => 'string', 'description' => 'چه چیزی در این بخش نوشته شود و کدام کوئری را جواب می‌دهد' ),
		) ) ),
		'repeat'    => array( 'type' => 'array', 'description' => 'کلماتی که باید بیشتر در متن تکرار شوند', 'items' => sap_ai_obj( array( 'keyword' => $str, 'target' => array( 'type' => 'integer' ) ) ) ),
		'faq'       => array( 'type' => 'array', 'items' => $str ),
		'links_in'  => array( 'type' => 'array', 'description' => 'انکرهایی که باید از صفحات دیگر به این صفحه لینک شوند', 'items' => $str ),
		'media'     => array( 'type' => 'array', 'description' => 'تصویر، ویدیو یا کامنت‌هایی که اضافه شود', 'items' => $str ),
		'new_pages' => array( 'type' => 'array', 'description' => 'کوئری‌های مستقلی که ارزش محتوای جدا دارند', 'items' => $str ),
	) );
	$system = implode( "\n", array(
		'تو متخصص ارشد سئو محتوا هستی و فقط با این استراتژی آپدیت کار می‌کنی: «' . $defs[ $s ]['name'] . '». هدف: ' . $defs[ $s ]['goal'],
		'گام‌های این استراتژی: ' . implode( '؛ ', $defs[ $s ]['steps'] ),
		'- فقط بر اساس داده‌های داده‌شده (signals، gaps، drops، متن و تیترهای فعلی) کار کن. هر پیشنهاد باید یک کوئری یا شاهد مشخص را جواب بدهد.',
		'- خروجی آماده اجرا بده: متن دقیق تایتل، متا، H1، مقدمه، تیترهای جدید با توضیح محتوای هر بخش. اگر چیزی لازم نیست خالی بگذار.',
		'- هرگز قیمت، تخفیف یا موجودی را در متن پیشنهاد نکن؛ سال نیاور مگر صفحه ذاتاً سالانه باشد.',
		'- فارسی روان، با نیم‌فاصله درست.',
	) );
	$res    = sap_ai_request( $system, wp_json_encode( $facts, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'analysis', 'max_tokens' => 3000 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$res['at'] = sap_now();
	$all       = get_option( 'sap_upd_ai_' . $pid, array() );
	$all       = is_array( $all ) ? $all : array();
	unset( $all[ $k . '|' . $s ] );
	$all[ $k . '|' . $s ] = $res;
	update_option( 'sap_upd_ai_' . $pid, array_slice( $all, -80, null, true ), false );
	return $res;
}

add_action( 'rest_api_init', function () {
	$ns    = 'seoactionplan/v1';
	$route = function ( $cb ) {
		return function ( WP_REST_Request $req ) use ( $cb ) {
			$p = sap_project( (int) $req['project'] );
			if ( ! $p ) {
				return new WP_Error( 'sap_upd', 'پروژه پیدا نشد.', array( 'status' => 404 ) );
			}
			sap_period_project( $p['id'] );
			return $cb( $p, $req );
		};
	};
	register_rest_route( $ns, '/upd/(?P<project>\d+)/refresh', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => $route( function ( $p ) {
			$r = sap_upd_refresh( $p );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$n = array_sum( $r['counts'] );
			return array( 'ok' => true, 'msg' => sprintf( 'تحلیل انجام شد: %s صفحه در ۵ استراتژی.', sap_num( $n ) ) . ( $r['notes'] ? ' (' . implode( '؛ ', $r['notes'] ) . ')' : '' ) );
		} ),
	) );
	register_rest_route( $ns, '/upd/(?P<project>\d+)/add', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => $route( function ( $p, $req ) {
			$url    = esc_url_raw( (string) $req->get_param( 'url' ) );
			$period = sap_period_sanitize( (string) ( $req->get_param( 'period' ) ?: sap_period_current( $p['id'] ) ) );
			if ( '' === $url ) {
				return new WP_Error( 'sap_upd', 'صفحه مشخص نیست.', array( 'status' => 400 ) );
			}
			sap_upd_add( $p, $url, $period, sanitize_key( (string) $req->get_param( 's' ) ) );
			return array( 'ok' => true, 'msg' => 'به آپدیت‌های ' . sap_period_label( $period ) . ' اضافه شد' . sap_calendar_sync_sheet( sap_project( $p['id'] ) ) );
		} ),
	) );
	register_rest_route( $ns, '/upd/(?P<project>\d+)/ai', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => $route( function ( $p, $req ) {
			$url = esc_url_raw( (string) $req->get_param( 'url' ) );
			$s   = sanitize_key( (string) $req->get_param( 's' ) );
			$r   = sap_upd_ai( $p, $url, $s );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			ob_start();
			sap_view( 'upd-ai', array( 'a' => $r ) );
			return array( 'ok' => true, 'html' => (string) ob_get_clean() );
		} ),
	) );
} );
