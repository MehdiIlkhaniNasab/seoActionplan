<?php
/**
 * Technical SEO pillar: a professional health checklist of the site.
 *
 * Sections: sitemap, robots.txt, speed (Core Web Vitals), schema, indexing of
 * the important pages (URL Inspection), page errors (status codes) and the
 * Screaming Frog crawl. Each section gets a score, the facts behind it, the
 * rule findings that belong to it and the AI technical analysis; everything
 * actionable is a proposal that enters the monthly plan only when approved.
 *
 * New data steps (free): robots.txt, sitemap XML check, URL Inspection.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base URL of the client site (with trailing slash).
 */
function sap_site_base( $project ) {
	$u = (string) $project['site_url'];
	if ( '' === $u && $project['gsc_property'] ) {
		$u = 0 === strpos( $project['gsc_property'], 'sc-domain:' ) ? 'https://' . substr( $project['gsc_property'], 10 ) : $project['gsc_property'];
	}
	return '' === $u ? '' : trailingslashit( esc_url_raw( $u ) );
}

/**
 * Plain GET of a public URL on the client site.
 *
 * @return array{code:int, body:string, type:string, final:string}|WP_Error
 */
function sap_fetch_public( $url, $timeout = 25 ) {
	$res = sap_http( $url, array(
		'method'      => 'GET',
		'timeout'     => $timeout,
		'redirection' => 3,
		'headers'     => array( 'User-Agent' => 'Mozilla/5.0 (compatible; Sokan-SEO/1.0; +https://ronagh.agency)' ),
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $res ),
		'body' => (string) wp_remote_retrieve_body( $res ),
		'type' => (string) wp_remote_retrieve_header( $res, 'content-type' ),
	);
}

/* ---------------------------------------------------------------------------
 * robots.txt
 * ------------------------------------------------------------------------- */

/**
 * Parse robots.txt into groups: [agents[], rules[[allow|disallow, path]], ...] + sitemaps.
 */
function sap_robots_parse( $txt ) {
	$groups   = array();
	$sitemaps = array();
	$cur      = null;
	$last_was_agent = false;
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $txt ) as $line ) {
		$line = trim( preg_replace( '/#.*$/', '', $line ) );
		if ( '' === $line || false === strpos( $line, ':' ) ) {
			continue;
		}
		list( $k, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
		$k = strtolower( $k );
		if ( 'user-agent' === $k ) {
			if ( ! $last_was_agent || null === $cur ) {
				$groups[] = array( 'agents' => array(), 'rules' => array(), 'delay' => null );
				$cur      = count( $groups ) - 1;
			}
			$groups[ $cur ]['agents'][] = strtolower( $v );
			$last_was_agent             = true;
			continue;
		}
		$last_was_agent = false;
		if ( 'sitemap' === $k ) {
			$sitemaps[] = $v;
		} elseif ( null !== $cur && in_array( $k, array( 'allow', 'disallow' ), true ) ) {
			$groups[ $cur ]['rules'][] = array( $k, $v );
		} elseif ( null !== $cur && 'crawl-delay' === $k ) {
			$groups[ $cur ]['delay'] = (float) $v;
		}
	}
	return array( 'groups' => $groups, 'sitemaps' => $sitemaps );
}

/**
 * Rules that apply to Googlebot (its own group, else *).
 */
function sap_robots_rules_for( $parsed, $agent = 'googlebot' ) {
	$star = null;
	foreach ( $parsed['groups'] as $g ) {
		if ( in_array( $agent, $g['agents'], true ) ) {
			return $g['rules'];
		}
		if ( in_array( '*', $g['agents'], true ) ) {
			$star = $g['rules'];
		}
	}
	return (array) $star;
}

/**
 * Is a path allowed? Longest matching rule wins, allow wins ties (Google's rules).
 */
function sap_robots_allowed( $rules, $path ) {
	$best = array( -1, 'allow' );
	foreach ( $rules as $r ) {
		if ( '' === $r[1] ) {
			continue; // «Disallow:» empty = allow all.
		}
		$re = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $r[1], '#' ) ) . '#';
		if ( preg_match( $re, $path ) ) {
			$len = strlen( $r[1] );
			if ( $len > $best[0] || ( $len === $best[0] && 'allow' === $r[0] ) ) {
				$best = array( $len, $r[0] );
			}
		}
	}
	return 'allow' === $best[1];
}

/**
 * Data step: fetch and check robots.txt.
 */
function sap_step_robots( $project ) {
	$base = sap_site_base( $project );
	if ( '' === $base ) {
		return sap_skip( 'آدرس سایت در تنظیمات پروژه وارد نشده.' );
	}
	$res = sap_fetch_public( $base . 'robots.txt' );
	if ( is_wp_error( $res ) ) {
		return array( 'ok' => false, 'msg' => 'robots.txt خوانده نشد: ' . sap_net_error( $res ) );
	}
	$parsed = 200 === $res['code'] ? sap_robots_parse( $res['body'] ) : array( 'groups' => array(), 'sitemaps' => array() );
	$rules  = sap_robots_rules_for( $parsed );
	$tests  = array();
	$paths  = array( '/' => 'صفحه اصلی' );
	foreach ( array_slice( sap_keymap( $project['id'] ), 0, 60 ) as $m ) {
		$p = (string) wp_parse_url( $m['url'], PHP_URL_PATH );
		if ( '' !== $p ) {
			$paths[ $p ] = $m['title'] ?: $p;
		}
	}
	foreach ( array( '/wp-content/uploads/', '/wp-content/themes/', '/wp-includes/js/', '/wp-admin/admin-ajax.php' ) as $p ) {
		$paths[ $p ] = 'منابع سایت';
	}
	foreach ( $paths as $p => $label ) {
		$tests[] = array( 'path' => $p, 'label' => $label, 'allowed' => sap_robots_allowed( $rules, $p ) );
	}
	$data = array(
		'url'       => $base . 'robots.txt',
		'code'      => $res['code'],
		'size'      => strlen( $res['body'] ),
		'body'      => mb_substr( $res['body'], 0, 6000 ),
		'groups'    => count( $parsed['groups'] ),
		'rules'     => $rules,
		'sitemaps'  => $parsed['sitemaps'],
		'block_all' => ! sap_robots_allowed( $rules, '/' ),
		'tests'     => $tests,
	);
	sap_dataset_put( $project['id'], 'robots', $data );
	$blocked = count( array_filter( $tests, function ( $t ) {
		return ! $t['allowed'];
	} ) );
	return array( 'msg' => 200 === $res['code'] ? sprintf( 'robots.txt خوانده شد: %s قانون برای گوگل، %s سایت‌مپ اعلام‌شده، %s مسیر مسدود از مسیرهای مهم.', sap_num( count( $rules ) ), sap_num( count( $parsed['sitemaps'] ) ), sap_num( $blocked ) ) : sprintf( 'robots.txt وجود ندارد یا خطا می‌دهد (HTTP %d).', $res['code'] ) );
}

/* ---------------------------------------------------------------------------
 * Sitemap XML check
 * ------------------------------------------------------------------------- */

/**
 * Data step: discover the sitemaps, read them (index + up to 10 children) and
 * compare their URLs with the important pages and the crawl.
 */
function sap_step_sitemap_check( $project ) {
	$base = sap_site_base( $project );
	if ( '' === $base ) {
		return sap_skip( 'آدرس سایت در تنظیمات پروژه وارد نشده.' );
	}
	$cands = array();
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'robots' )['sitemaps'] ?? array() ) as $s ) {
		$cands[] = $s;
	}
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'gsc_sitemaps' )['sitemaps'] ?? array() ) as $s ) {
		$cands[] = $s['path'];
	}
	foreach ( array( 'sitemap_index.xml', 'sitemap.xml', 'wp-sitemap.xml' ) as $d ) {
		$cands[] = $base . $d;
	}
	$cands  = array_values( array_unique( array_filter( $cands ) ) );
	$maps   = array();
	$urls   = array();
	$root   = '';
	$queue  = array();
	foreach ( $cands as $c ) {
		$r = sap_fetch_public( $c, 20 );
		if ( is_wp_error( $r ) || 200 !== $r['code'] || false === stripos( $r['body'], '<loc' ) ) {
			continue;
		}
		$root  = $c;
		$queue = array( array( $c, $r ) );
		break;
	}
	if ( '' === $root ) {
		sap_dataset_put( $project['id'], 'sitemap_check', array( 'root' => '', 'maps' => array(), 'tried' => $cands ) );
		return array( 'ok' => true, 'msg' => 'هیچ سایت‌مپ قابل خواندنی پیدا نشد (مسیرهای امتحان‌شده: ' . implode( '، ', array_map( function ( $u ) {
			return sap_short_url( $u, 40 );
		}, $cands ) ) . ').' );
	}
	$fetched = 0;
	while ( $queue && $fetched < 12 ) {
		list( $url, $r ) = array_shift( $queue );
		++$fetched;
		if ( null === $r ) {
			$r = sap_fetch_public( $url, 20 );
		}
		if ( is_wp_error( $r ) ) {
			$maps[] = array( 'url' => $url, 'code' => 0, 'count' => 0, 'lastmod' => 0, 'error' => $r->get_error_message() );
			continue;
		}
		preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</loc>#is', $r['body'], $m );
		$locs = array_map( 'html_entity_decode', $m[1] );
		if ( false !== stripos( $r['body'], '<sitemapindex' ) ) {
			$maps[] = array( 'url' => $url, 'code' => $r['code'], 'index' => true, 'count' => count( $locs ), 'lastmod' => substr_count( $r['body'], '<lastmod' ) );
			foreach ( $locs as $l ) {
				$queue[] = array( $l, null );
			}
			continue;
		}
		$lastmods = array();
		preg_match_all( '#<lastmod>\s*(.*?)\s*</lastmod>#is', $r['body'], $lm );
		foreach ( $lm[1] as $d ) {
			$lastmods[] = strtotime( $d );
		}
		$maps[] = array(
			'url'      => $url,
			'code'     => $r['code'],
			'count'    => count( $locs ),
			'lastmod'  => count( $lastmods ),
			'newest'   => $lastmods ? gmdate( 'Y-m-d', max( $lastmods ) ) : '',
			'xml'      => false !== stripos( $r['type'], 'xml' ) || 0 === strpos( ltrim( $r['body'] ), '<?xml' ),
		);
		foreach ( array_slice( $locs, 0, 5000 ) as $l ) {
			$urls[ sap_norm_url( $l ) ] = $l;
		}
	}
	// Important pages missing from the sitemap; sitemap URLs that are not clean 200 / indexable.
	$impr = array();
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$impr[ sap_norm_url( $r['page'] ) ] = (int) $r['impressions'];
	}
	$missing = array();
	$skipped = 0;
	$crawl_c = array();
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'crawl' )['rows'] ?? array() ) as $r ) {
		$crawl_c[ sap_norm_url( $r['url'] ) ] = $r;
	}
	foreach ( sap_keymap( $project['id'] ) as $k => $m ) {
		if ( isset( $urls[ $k ] ) || 'home' === $m['page_type'] || ( $impr[ $k ] ?? 0 ) <= 0 ) {
			continue;
		}
		// Only real, indexable canonical pages belong in a sitemap: not filter / paginated /
		// cart pages, not pages canonicalised to a URL that is in the sitemap, not noindex.
		$canon = (string) ( $crawl_c[ $k ]['canon'] ?? '' );
		$clean = strtok( $m['url'], '?' );
		if ( sap_url_skip_reason( $project['id'], $m['url'], $m['title'] ) || isset( $urls[ sap_norm_url( $clean ) ] ) || ( '' !== $canon && isset( $urls[ sap_norm_url( $canon ) ] ) ) ) {
			++$skipped;
			continue;
		}
		$missing[] = array( 'url' => $m['url'], 'title' => $m['title'], 'impr' => $impr[ $k ] ?? 0 );
	}
	usort( $missing, function ( $a, $b ) {
		return $b['impr'] <=> $a['impr'];
	} );
	$bad   = array();
	$crawl = array();
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'crawl' )['rows'] ?? array() ) as $r ) {
		$crawl[ sap_norm_url( $r['url'] ) ] = $r;
	}
	foreach ( $urls as $k => $u ) {
		$c = $crawl[ $k ] ?? null;
		if ( $c && ( 200 !== (int) $c['code'] || ! $c['idx'] ) ) {
			$bad[] = array( 'url' => $u, 'code' => (int) $c['code'], 'why' => 200 !== (int) $c['code'] ? 'HTTP ' . $c['code'] : ( $c['idx_why'] ?: 'غیرقابل‌ایندکس' ) );
		}
	}
	$host  = wp_parse_url( $base, PHP_URL_HOST );
	$other = array_values( array_filter( $urls, function ( $u ) use ( $host, $base ) {
		return wp_parse_url( $u, PHP_URL_HOST ) !== $host || ( 0 === strpos( $base, 'https' ) && 0 === strpos( $u, 'http:' ) );
	} ) );
	sap_dataset_put( $project['id'], 'sitemap_check', array(
		'root'        => $root,
		'maps'        => $maps,
		'total'       => count( $urls ),
		'missing'     => array_slice( $missing, 0, 60 ),
		'bad'         => array_slice( $bad, 0, 60 ),
		'other_host'  => array_slice( $other, 0, 20 ),
		'in_robots'   => in_array( $root, (array) ( sap_dataset_payload( $project['id'], 'robots' )['sitemaps'] ?? array() ), true ),
		'crawl_known' => (bool) $crawl,
		'skipped'     => $skipped,
	) );
	return array( 'msg' => sprintf( 'سایت‌مپ %s: %s فایل، %s آدرس؛ %s صفحه مهم در سایت‌مپ نیست، %s آدرس خراب یا غیرقابل‌ایندکس.', sap_short_url( $root, 40 ), sap_num( count( $maps ) ), sap_num( count( $urls ) ), sap_num( count( $missing ) ), sap_num( count( $bad ) ) ) );
}

/* ---------------------------------------------------------------------------
 * URL Inspection (index status of the important pages)
 * ------------------------------------------------------------------------- */

/**
 * Important pages: most visible mapped pages, focus pillars, new articles.
 */
function sap_important_urls( $project, $limit = 15 ) {
	$pid  = $project['id'];
	$impr = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$impr[ sap_norm_url( $r['page'] ) ] = (int) $r['impressions'];
	}
	$urls = array();
	$base = sap_site_base( $project );
	if ( $base ) {
		$urls[ sap_norm_url( $base ) ] = $base;
	}
	foreach ( sap_ideas( $pid, array( 'status' => array( 'published' ) ) ) as $i ) {
		if ( $i['published_url'] && count( $urls ) < 4 ) {
			$urls[ sap_norm_url( $i['published_url'] ) ] = $i['published_url']; // New content: is it indexed yet?
		}
	}
	$map = sap_keymap( $pid );
	uksort( $map, function ( $a, $b ) use ( $impr ) {
		return ( $impr[ $b ] ?? 0 ) <=> ( $impr[ $a ] ?? 0 );
	} );
	foreach ( $map as $k => $m ) {
		if ( count( $urls ) >= $limit ) {
			break;
		}
		if ( '' === sap_url_skip_reason( $pid, $m['url'], $m['title'] ) ) {
			$urls[ $k ] = $m['url'];
		}
	}
	return array_values( $urls );
}

/**
 * Data step: URL Inspection API for the important pages (5 per call).
 */
function sap_step_gsc_inspect( $project, $force = false ) {
	if ( ! $project['gsc_property'] || ! sap_google_connected() ) {
		return sap_skip( 'سرچ کنسول وصل نیست.' );
	}
	$old = sap_dataset( $project['id'], 'gsc_inspect' );
	if ( ! $force && $old && sap_dataset_age_days( $project['id'], 'gsc_inspect' ) < 3 && empty( $old['meta']['partial'] ) ) {
		return sap_skip( 'وضعیت ایندکس صفحات مهم کمتر از ۳ روز پیش بررسی شده است.' );
	}
	$urls = sap_important_urls( $project );
	$opt  = 'sap_inspect_prog_' . $project['id'];
	$prog = get_option( $opt );
	if ( ! is_array( $prog ) || $force && 0 === (int) ( $prog['offset'] ?? 0 ) ) {
		$prog = array( 'offset' => 0, 'rows' => array() );
	}
	foreach ( array_slice( $urls, $prog['offset'], 5 ) as $u ) {
		$res = sap_google_request( 'POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', array( 'inspectionUrl' => $u, 'siteUrl' => $project['gsc_property'], 'languageCode' => 'fa' ), 40 );
		if ( is_wp_error( $res ) ) {
			$prog['rows'][] = array( 'url' => $u, 'error' => $res->get_error_message() );
			if ( in_array( $res->get_error_code(), array( 'sap_scope', 'sap_google_api_off' ), true ) ) {
				delete_option( $opt );
				return $res;
			}
			continue;
		}
		$i              = (array) ( $res['inspectionResult']['indexStatusResult'] ?? array() );
		$rr             = (array) ( $res['inspectionResult']['richResultsResult'] ?? array() );
		$prog['rows'][] = array(
			'url'        => $u,
			'verdict'    => (string) ( $i['verdict'] ?? '' ),
			'coverage'   => (string) ( $i['coverageState'] ?? '' ),
			'indexing'   => (string) ( $i['indexingState'] ?? '' ),
			'robots'     => (string) ( $i['robotsTxtState'] ?? '' ),
			'fetch'      => (string) ( $i['pageFetchState'] ?? '' ),
			'last_crawl' => (string) ( $i['lastCrawlTime'] ?? '' ),
			'g_canon'    => (string) ( $i['googleCanonical'] ?? '' ),
			'u_canon'    => (string) ( $i['userCanonical'] ?? '' ),
			'rich'       => (string) ( $rr['verdict'] ?? '' ),
			'rich_types' => array_values( array_filter( array_map( function ( $d ) {
				return (string) ( $d['richResultType'] ?? '' );
			}, (array) ( $rr['detectedItems'] ?? array() ) ) ) ),
		);
	}
	$prog['offset'] += 5;
	if ( $prog['offset'] < count( $urls ) ) {
		update_option( $opt, $prog, false );
		return array( 'more' => true, 'msg' => sprintf( '%s از %s صفحه بررسی شد…', sap_num( $prog['offset'] ), sap_num( count( $urls ) ) ) );
	}
	delete_option( $opt );
	sap_dataset_put( $project['id'], 'gsc_inspect', array( 'rows' => $prog['rows'] ), array( 'count' => count( $prog['rows'] ) ) );
	$ok = count( array_filter( $prog['rows'], function ( $r ) {
		return 'PASS' === ( $r['verdict'] ?? '' );
	} ) );
	return array( 'msg' => sprintf( 'وضعیت ایندکس %s صفحه مهم: %s صفحه ایندکس است.', sap_num( count( $prog['rows'] ) ), sap_num( $ok ) ) );
}

/**
 * Persian label of a URL Inspection coverage state.
 */
function sap_coverage_label( $state ) {
	$map = array(
		'Submitted and indexed'                  => 'ارسال‌شده و ایندکس',
		'Indexed, not submitted in sitemap'      => 'ایندکس ولی در سایت‌مپ نیست',
		'Crawled - currently not indexed'        => 'خزش شده ولی ایندکس نشده',
		'Discovered - currently not indexed'     => 'کشف شده ولی هنوز خزش/ایندکس نشده',
		'Duplicate, Google chose different canonical than user' => 'تکراری؛ گوگل کنونیکال دیگری انتخاب کرده',
		'Duplicate without user-selected canonical' => 'تکراری بدون کنونیکال',
		'Excluded by ‘noindex’ tag'              => 'noindex',
		'Blocked by robots.txt'                  => 'مسدود در robots.txt',
		'Page with redirect'                     => 'ریدایرکت',
		'Not found (404)'                        => '404',
		'URL is unknown to Google'               => 'برای گوگل ناشناخته',
	);
	return $map[ $state ] ?? $state;
}

/* ---------------------------------------------------------------------------
 * Detectors of the new data (registered in sap_detectors()).
 * ------------------------------------------------------------------------- */

function sap_detect_robots( $ctx ) {
	$r   = $ctx['robots'];
	$out = array();
	if ( 200 !== (int) $r['code'] ) {
		$out[] = array( 'key' => 'missing', 'context' => 'technical', 'title' => 'فایل robots.txt وجود ندارد یا خطا می‌دهد', 'detail' => sprintf( 'آدرس %s کد %d برمی‌گرداند. یک robots.txt ساده بسازید (در رنک‌مث: General Settings › Edit robots.txt) با اجازه خزش کل سایت، بستن /wp-admin/ به‌جز admin-ajax.php و خط Sitemap به سایت‌مپ اصلی.', $r['url'], $r['code'] ), 'severity' => 45, 'effort' => 0.25, 'evidence' => array( 'section' => 'robots' ) );
		return $out;
	}
	if ( ! empty( $r['block_all'] ) ) {
		$out[] = array( 'key' => 'block_all', 'context' => 'technical', 'title' => 'robots.txt کل سایت را برای گوگل مسدود کرده است', 'detail' => 'قانون Disallow: / برای گوگل فعال است؛ فوراً حذف شود (معمولاً از حالت «جلوگیری از ایندکس» وردپرس یا محیط تست جا مانده).', 'severity' => 100, 'effort' => 0.25, 'evidence' => array( 'section' => 'robots' ) );
	}
	$blocked = array();
	$assets  = array();
	foreach ( (array) $r['tests'] as $t ) {
		if ( $t['allowed'] ) {
			continue;
		}
		if ( 'منابع سایت' === $t['label'] ) {
			$assets[] = $t['path'];
		} elseif ( '/' !== $t['path'] ) {
			$blocked[] = array( 'page' => $t['label'], 'path' => $t['path'] );
		}
	}
	if ( $blocked ) {
		$out[] = array( 'key' => 'blocked', 'context' => 'technical', 'title' => sprintf( '%s صفحه مهم در robots.txt مسدود است', sap_num( count( $blocked ) ) ), 'detail' => 'این صفحات در نقشه کلمات هستند ولی گوگل اجازه خزش آن‌ها را ندارد. قانون Disallow مربوط را بردارید یا Allow اختصاصی بگذارید.', 'severity' => 80, 'effort' => 0.5, 'evidence' => array( 'section' => 'robots', 'cols' => array( 'page' => 'صفحه', 'path' => 'مسیر' ), 'items' => $blocked ) );
	}
	if ( $assets ) {
		$out[] = array( 'key' => 'assets', 'context' => 'technical', 'title' => 'فایل‌های CSS/JS یا تصاویر در robots.txt مسدودند', 'detail' => 'مسیرهای ' . implode( '، ', $assets ) . ' بسته شده‌اند؛ گوگل برای رندر صفحه و تشخیص موبایل‌فرندلی به این فایل‌ها نیاز دارد. این Disallowها را حذف کنید.', 'severity' => 55, 'effort' => 0.25, 'evidence' => array( 'section' => 'robots' ) );
	}
	if ( empty( $r['sitemaps'] ) ) {
		$out[] = array( 'key' => 'no_sitemap', 'context' => 'technical', 'title' => 'آدرس سایت‌مپ در robots.txt اعلام نشده', 'detail' => 'خط «Sitemap: https://…/sitemap_index.xml» را به انتهای robots.txt اضافه کنید تا همه موتورهای جستجو سایت‌مپ را پیدا کنند.', 'severity' => 20, 'effort' => 0.1, 'evidence' => array( 'section' => 'robots' ) );
	}
	return $out;
}

function sap_detect_sitemap_check( $ctx ) {
	$s   = $ctx['sitemap_check'];
	$out = array();
	if ( '' === (string) $s['root'] ) {
		return array( array( 'key' => 'none', 'context' => 'technical', 'title' => 'سایت‌مپ XML قابل خواندن پیدا نشد', 'detail' => 'در رنک‌مث بخش Sitemap Settings را فعال کنید و آدرس /sitemap_index.xml را در مرورگر باز کنید؛ اگر خطا یا صفحه خالی می‌دهد، کش/فایروال را بررسی کنید. سپس آن را در سرچ کنسول ثبت کنید.', 'severity' => 75, 'effort' => 0.5, 'evidence' => array( 'section' => 'sitemap' ) ) );
	}
	if ( $s['missing'] ) {
		$out[] = array( 'key' => 'missing', 'context' => 'technical', 'title' => sprintf( '%s صفحه مهم در سایت‌مپ نیست', sap_num( count( $s['missing'] ) ) ), 'detail' => 'این صفحات قابل‌ایندکس، کنونیکال خودشان و دارای نمایش در گوگل هستند ولی در سایت‌مپ نیامده‌اند؛ معمولاً یعنی نوع محتوا یا طبقه‌بندی آن در تنظیمات سایت‌مپ رنک‌مث غیرفعال است.' . ( ! empty( $s['skipped'] ) ? ' (' . sap_num( $s['skipped'] ) . ' آدرس دیگر — فیلتر، صفحه‌بندی، سبد خرید، کنونیکال‌شده به صفحه‌ای که در سایت‌مپ هست یا noindex — عمداً حساب نشد.)' : '' ), 'url' => $s['missing'][0]['url'], 'severity' => 50, 'effort' => 0.5, 'impact' => array_sum( array_column( $s['missing'], 'impr' ) ) / 10, 'evidence' => array( 'section' => 'sitemap', 'cols' => array( 'title' => 'صفحه', 'url' => 'آدرس', 'impr' => 'نمایش' ), 'items' => array_slice( $s['missing'], 0, 25 ) ) );
	}
	if ( $s['bad'] ) {
		$out[] = array( 'key' => 'bad', 'context' => 'technical', 'title' => sprintf( '%s آدرس خراب، ریدایرکت یا noindex داخل سایت‌مپ', sap_num( count( $s['bad'] ) ) ), 'detail' => 'سایت‌مپ فقط باید آدرس‌های ۲۰۰ و قابل‌ایندکس را داشته باشد. این آدرس‌ها را از سایت‌مپ حذف کنید (یا مشکل خود صفحه را حل کنید).', 'severity' => 45, 'effort' => 1, 'evidence' => array( 'section' => 'sitemap', 'cols' => array( 'url' => 'آدرس', 'why' => 'مشکل' ), 'items' => array_slice( $s['bad'], 0, 25 ) ) );
	}
	if ( $s['other_host'] ) {
		$out[] = array( 'key' => 'host', 'context' => 'technical', 'title' => 'آدرس‌های http یا دامنه دیگر در سایت‌مپ', 'detail' => 'همه آدرس‌های سایت‌مپ باید با نسخه اصلی سایت (https و با/بدون www یکسان) باشند. آدرس سایت در تنظیمات وردپرس را بررسی کنید.', 'severity' => 40, 'effort' => 0.5, 'evidence' => array( 'section' => 'sitemap', 'items' => array_map( function ( $u ) {
			return array( 'url' => $u );
		}, $s['other_host'] ), 'cols' => array( 'url' => 'آدرس' ) ) );
	}
	foreach ( (array) $s['maps'] as $m ) {
		if ( empty( $m['index'] ) && 0 === (int) $m['count'] ) {
			$out[] = array( 'key' => 'empty:' . $m['url'], 'context' => 'technical', 'title' => 'سایت‌مپ خالی: ' . sap_short_url( $m['url'], 50 ), 'detail' => 'این سایت‌مپ هیچ آدرسی ندارد؛ نوع محتوای آن را در تنظیمات سایت‌مپ غیرفعال کنید یا علت خالی بودن را بررسی کنید.', 'url' => $m['url'], 'severity' => 20, 'effort' => 0.25, 'evidence' => array( 'section' => 'sitemap' ) );
		}
	}
	return $out;
}

function sap_detect_index_inspect( $ctx ) {
	$out  = array();
	$bad  = array();
	$can  = array();
	foreach ( (array) ( $ctx['gsc_inspect']['rows'] ?? array() ) as $r ) {
		if ( ! empty( $r['error'] ) ) {
			continue;
		}
		if ( 'PASS' !== $r['verdict'] ) {
			$bad[] = array( 'url' => $r['url'], 'page' => sap_ctx_title( $ctx, $r['url'] ), 'state' => sap_coverage_label( $r['coverage'] ), 'crawl' => $r['last_crawl'] ? sap_jdate( substr( $r['last_crawl'], 0, 10 ), 'short' ) : 'هرگز' );
		}
		if ( $r['g_canon'] && $r['u_canon'] && sap_norm_url( $r['g_canon'] ) !== sap_norm_url( $r['u_canon'] ) ) {
			$can[] = array( 'url' => $r['url'], 'page' => sap_ctx_title( $ctx, $r['url'] ), 'google' => $r['g_canon'] );
		}
	}
	if ( $bad ) {
		$out[] = array( 'key' => 'not_indexed', 'context' => 'technical', 'title' => sprintf( '%s صفحه مهم در گوگل ایندکس نیست', sap_num( count( $bad ) ) ), 'detail' => 'طبق URL Inspection سرچ کنسول این صفحات ایندکس نیستند. برای هر کدام علت را برطرف کنید (noindex، کنونیکال، محتوای کم/تکراری، لینک داخلی کم) و سپس در سرچ کنسول «درخواست ایندکس» بزنید.', 'url' => $bad[0]['url'], 'severity' => 85, 'effort' => 1, 'evidence' => array( 'section' => 'index', 'cols' => array( 'page' => 'صفحه', 'state' => 'وضعیت', 'crawl' => 'آخرین خزش' ), 'items' => $bad ) );
	}
	if ( $can ) {
		$out[] = array( 'key' => 'canonical', 'context' => 'technical', 'title' => sprintf( 'گوگل برای %s صفحه کنونیکال دیگری انتخاب کرده', sap_num( count( $can ) ) ), 'detail' => 'کنونیکال انتخابی گوگل با کنونیکال صفحه یکی نیست؛ یعنی گوگل صفحه را تکراری می‌داند. محتوای این صفحات را متمایز کنید یا کنونیکال/ریدایرکت درست بگذارید.', 'url' => $can[0]['url'], 'severity' => 60, 'effort' => 1, 'evidence' => array( 'section' => 'index', 'cols' => array( 'page' => 'صفحه', 'google' => 'کنونیکال گوگل' ), 'items' => $can ) );
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * Checklist
 * ------------------------------------------------------------------------- */

/**
 * Sections: label, icon, data steps it needs, rule detectors, AI technical areas, what is checked.
 */
function sap_tech_sections() {
	return array(
		'sitemap' => array( 'سایت‌مپ XML', 'list', array( 'sitemap_check', 'gsc_sitemaps' ), array( 'sitemaps', 'sitemap_check' ), array( 'sitemap' ), 'وجود و خوانا بودن، ثبت در سرچ کنسول و robots، حضور همه صفحات مهم، نبود آدرس خراب/noindex/ریدایرکت، lastmod.' ),
		'robots'  => array( 'فایل robots.txt', 'settings', array( 'robots' ), array( 'robots' ), array( 'robots', 'crawl' ), 'وجود فایل، بسته نبودن کل سایت یا صفحات مهم، باز بودن CSS/JS/تصاویر، اعلام سایت‌مپ.' ),
		'speed'   => array( 'سرعت و Core Web Vitals', 'clock', array( 'cwv' ), array( 'cwv', 'slow' ), array( 'cwv' ), 'LCP، INP و CLS از داده واقعی کاربران (CrUX)، امتیاز آزمایشگاهی موبایل، زمان پاسخ سرور.' ),
		'schema'  => array( 'اسکیما و داده ساختاریافته', 'check', array( 'pages' ), array( 'schema' ), array( 'schema' ), 'Product/Offer برای محصول، BreadcrumbList، Article برای مقاله، Organization/WebSite، FAQ؛ خطاهای JSON-LD و Rich Results.' ),
		'index'   => array( 'ایندکس صفحات مهم', 'research', array( 'gsc_inspect' ), array( 'index_inspect', 'noindex_impr' ), array( 'index' ), 'وضعیت ایندکس صفحات مهم در URL Inspection، کنونیکال انتخابی گوگل، صفحات noindex با نمایش، ایندکس محتوای تازه.' ),
		'pages'   => array( 'خطاهای صفحات (Pages)', 'alert', array( 'crawl' ), array( 'crawl_4xx', 'crawl_5xx', 'crawl_3xx' ), array( 'pages' ), 'صفحات 404 و 5xx، ریدایرکت‌های داخلی و زنجیره‌ای، لینک‌های داخلی به صفحات خراب.' ),
		'crawl'   => array( 'تحلیل خزش (Screaming Frog)', 'link', array( 'crawl' ), array( 'crawl_meta', 'weak_links' ), array( 'crawl', 'onpage' ), 'تایتل/متا/H1 تکراری یا خالی، عمق و لینک داخلی صفحات، محتوای کم، کنونیکال‌ها.' ),
	);
}

/**
 * Section of a finding / proposal.
 */
function sap_tech_section_of( $f ) {
	if ( ! empty( $f['evidence']['section'] ) && isset( sap_tech_sections()[ $f['evidence']['section'] ] ) ) {
		return $f['evidence']['section'];
	}
	foreach ( sap_tech_sections() as $k => $s ) {
		if ( in_array( $f['detector'], $s[3], true ) ) {
			return $k;
		}
	}
	return '';
}

/**
 * Checklist with status per section.
 *
 * @return array section => [label, icon, checks, status (good|warn|bad|nodata), score, facts[], findings[], proposals[], steps[], updated]
 */
function sap_tech_checklist( $project ) {
	$pid      = $project['id'];
	$open     = array_merge( sap_findings( $pid, array( 'status' => 'open', 'limit' => 1000 ) ), array() );
	$by_sec   = array();
	foreach ( $open as $f ) {
		$sec = sap_tech_section_of( $f );
		if ( $sec ) {
			$by_sec[ $sec ][] = $f;
		}
	}
	$out = array();
	foreach ( sap_tech_sections() as $key => $s ) {
		$have = array_filter( $s[2], function ( $src ) use ( $pid ) {
			return (bool) sap_dataset( $pid, $src );
		} );
		$items   = $by_sec[ $key ] ?? array();
		$penalty = 0;
		foreach ( $items as $f ) {
			// A finding's weight follows its score (importance of the pages it touches) and the
			// expert triage: critical ones weigh most, minor ones barely count.
			$tier     = $f['evidence']['tier'] ?? '';
			$penalty += 'minor' === $tier ? 1 : ( 'critical' === $tier ? min( 30, max( 15, (float) $f['score'] / 3 ) ) : min( 20, max( 2, (float) $f['score'] / 4 ) ) );
		}
		// Diminishing returns: many small issues do not sink a section to zero.
		$score  = $have ? (int) round( 100 * exp( -$penalty / 90 ) ) : null;
		$status = null === $score ? 'nodata' : ( $score >= 85 ? 'good' : ( $score >= 60 ? 'warn' : 'bad' ) );
		$ages   = array();
		foreach ( $have as $src ) {
			$ages[] = sap_dataset( $pid, $src )['fetched_at'];
		}
		$out[ $key ] = array(
			'label'    => $s[0],
			'icon'     => $s[1],
			'checks'   => $s[5],
			'needs'    => $s[2],
			'status'   => $status,
			'score'    => $score,
			'items'    => $items,
			'facts'    => sap_tech_facts( $project, $key ),
			'kpis'     => sap_tech_kpis( $project, $key ),
			'updated'  => $ages ? max( $ages ) : '',
		);
	}
	return $out;
}

/**
 * Key facts of a section (short label => value) for the overview cards.
 */
function sap_tech_facts( $project, $key ) {
	$pid = $project['id'];
	$f   = array();
	switch ( $key ) {
		case 'sitemap':
			$s = sap_dataset_payload( $pid, 'sitemap_check' );
			if ( $s ) {
				$f['آدرس اصلی'] = $s['root'] ? sap_short_url( $s['root'], 40 ) : 'پیدا نشد';
				if ( $s['root'] ) {
					$f['فایل‌ها / آدرس‌ها'] = sap_num( count( $s['maps'] ) ) . ' / ' . sap_num( $s['total'] );
					$f['صفحه مهم جامانده'] = sap_num( count( $s['missing'] ) );
					$f['در robots اعلام شده'] = $s['in_robots'] ? 'بله' : 'خیر';
				}
			}
			$g = (array) ( sap_dataset_payload( $pid, 'gsc_sitemaps' )['sitemaps'] ?? array() );
			if ( $g ) {
				$f['ثبت در سرچ کنسول'] = sap_num( count( $g ) ) . ' سایت‌مپ';
			}
			break;
		case 'robots':
			$r = sap_dataset_payload( $pid, 'robots' );
			if ( $r ) {
				$f['وضعیت'] = 200 === (int) $r['code'] ? 'موجود' : 'HTTP ' . $r['code'];
				$f['قوانین گوگل'] = sap_num( count( (array) $r['rules'] ) );
				$f['سایت‌مپ اعلام‌شده'] = sap_num( count( (array) $r['sitemaps'] ) );
				$f['مسیر مهم مسدود'] = sap_num( count( array_filter( (array) $r['tests'], function ( $t ) {
					return ! $t['allowed'];
				} ) ) );
			}
			break;
		case 'speed':
			foreach ( array_slice( (array) ( sap_dataset_payload( $pid, 'cwv' )['pages'] ?? array() ), 0, 3 ) as $c ) {
				$lcp = $c['field']['lcp']['p75'] ?? ( $c['lab']['lcp'] ?? null );
				$f[ sap_short_url( $c['url'], 30 ) ] = sprintf( 'امتیاز %s · LCP %s', null === $c['score'] ? '—' : sap_num( $c['score'] ), $lcp ? sap_num( $lcp / 1000, 1 ) . 's' : '—' );
			}
			break;
		case 'schema':
			$pages = (array) ( sap_dataset_payload( $pid, 'pages' )['pages'] ?? array() );
			if ( $pages ) {
				$types = array();
				$err   = 0;
				foreach ( $pages as $p ) {
					foreach ( (array) $p['schema'] as $t ) {
						$types[ $t ] = ( $types[ $t ] ?? 0 ) + 1;
					}
					$err += (int) $p['schema_errors'];
				}
				arsort( $types );
				$f['صفحات خوانده‌شده'] = sap_num( count( $pages ) );
				$f['نوع‌های اسکیما'] = $types ? implode( '، ', array_slice( array_keys( $types ), 0, 5 ) ) : 'هیچ';
				$f['خطای JSON-LD'] = sap_num( $err );
			}
			break;
		case 'index':
			$rows = (array) ( sap_dataset_payload( $pid, 'gsc_inspect' )['rows'] ?? array() );
			if ( $rows ) {
				$f['صفحات بررسی‌شده'] = sap_num( count( $rows ) );
				$f['ایندکس'] = sap_num( count( array_filter( $rows, function ( $r ) {
					return 'PASS' === ( $r['verdict'] ?? '' );
				} ) ) );
				$f['Rich Results معتبر'] = sap_num( count( array_filter( $rows, function ( $r ) {
					return 'PASS' === ( $r['rich'] ?? '' );
				} ) ) );
			}
			break;
		case 'pages':
		case 'crawl':
			$c = sap_dataset( $pid, 'crawl' );
			if ( $c ) {
				$rows = (array) ( $c['payload']['rows'] ?? array() );
				$codes = array();
				foreach ( $rows as $r ) {
					$codes[ (int) floor( (int) $r['code'] / 100 ) ] = ( $codes[ (int) floor( (int) $r['code'] / 100 ) ] ?? 0 ) + 1;
				}
				$f['آدرس خزش‌شده'] = sap_num( count( $rows ) );
				if ( 'pages' === $key ) {
					$f['2xx / 3xx'] = sap_num( $codes[2] ?? 0 ) . ' / ' . sap_num( $codes[3] ?? 0 );
					$f['4xx / 5xx'] = sap_num( $codes[4] ?? 0 ) . ' / ' . sap_num( $codes[5] ?? 0 );
				} else {
					$f['غیرقابل‌ایندکس'] = sap_num( count( array_filter( $rows, function ( $r ) {
						return ! $r['idx'];
					} ) ) );
				}
				$f['تاریخ خزش'] = sap_jdate( $c['fetched_at'], 'short' );
			}
			break;
	}
	return $f;
}

/**
 * Up to three short numbers for a section card: [value, label, tone (good|warn|bad|'')].
 */
function sap_tech_kpis( $project, $key ) {
	$pid  = $project['id'];
	$k    = array();
	$tone = function ( $n, $warn = 1, $bad = 5 ) {
		return $n >= $bad ? 'bad' : ( $n >= $warn ? 'warn' : 'good' );
	};
	switch ( $key ) {
		case 'sitemap':
			$s = sap_dataset_payload( $pid, 'sitemap_check' );
			if ( $s && $s['root'] ) {
				$miss = count( (array) $s['missing'] );
				$k[]  = array( sap_num( $miss ), 'صفحه مهم جامانده', $tone( $miss, 1, 10 ) );
				$k[]  = array( sap_num( $s['total'] ), 'آدرس در ' . sap_num( count( $s['maps'] ) ) . ' فایل', '' );
				$k[]  = array( $s['in_robots'] ? '✓' : '✕', 'اعلام در robots', $s['in_robots'] ? 'good' : 'warn' );
			} elseif ( $s ) {
				$k[] = array( '✕', 'سایت‌مپ پیدا نشد', 'bad' );
			}
			break;
		case 'robots':
			$r = sap_dataset_payload( $pid, 'robots' );
			if ( $r ) {
				$blocked = count( array_filter( (array) $r['tests'], function ( $t ) {
					return ! $t['allowed'];
				} ) );
				$k[] = array( sap_num( $blocked ), 'مسیر مهم مسدود', $tone( $blocked, 1, 1 ) );
				$k[] = array( sap_num( count( (array) $r['rules'] ) ), 'قانون', '' );
				$k[] = array( sap_num( count( (array) $r['sitemaps'] ) ), 'سایت‌مپ اعلام‌شده', count( (array) $r['sitemaps'] ) ? 'good' : 'warn' );
			}
			break;
		case 'speed':
			$pages = (array) ( sap_dataset_payload( $pid, 'cwv' )['pages'] ?? array() );
			if ( $pages ) {
				$scores = array_filter( array_column( $pages, 'score' ), 'is_numeric' );
				$lcps   = array();
				foreach ( $pages as $c ) {
					$l = $c['field']['lcp']['p75'] ?? ( $c['lab']['lcp'] ?? null );
					if ( $l ) {
						$lcps[] = $l / 1000;
					}
				}
				$avg = $scores ? (int) round( array_sum( $scores ) / count( $scores ) ) : null;
				$k[] = array( null === $avg ? '—' : sap_num( $avg ), 'میانگین امتیاز موبایل', null === $avg ? '' : ( $avg >= 90 ? 'good' : ( $avg >= 50 ? 'warn' : 'bad' ) ) );
				if ( $lcps ) {
					$w   = max( $lcps );
					$k[] = array( sap_num( $w, 1 ) . 's', 'بدترین LCP', $w <= 2.5 ? 'good' : ( $w <= 4 ? 'warn' : 'bad' ) );
				}
				$k[] = array( sap_num( count( $pages ) ), 'صفحه تست‌شده', '' );
			}
			break;
		case 'schema':
			$pages = (array) ( sap_dataset_payload( $pid, 'pages' )['pages'] ?? array() );
			if ( $pages ) {
				$with  = 0;
				$err   = 0;
				$types = array();
				foreach ( $pages as $p ) {
					$with += $p['schema'] ? 1 : 0;
					$err  += (int) $p['schema_errors'];
					foreach ( (array) $p['schema'] as $t ) {
						$types[ $t ] = true;
					}
				}
				$pc  = (int) round( 100 * $with / count( $pages ) );
				$k[] = array( sap_num( $pc ) . '٪', 'صفحات دارای اسکیما', $pc >= 90 ? 'good' : ( $pc >= 60 ? 'warn' : 'bad' ) );
				$k[] = array( sap_num( count( $types ) ), 'نوع اسکیما', '' );
				$k[] = array( sap_num( $err ), 'خطای JSON-LD', $tone( $err, 1, 3 ) );
			}
			break;
		case 'index':
			$rows = (array) ( sap_dataset_payload( $pid, 'gsc_inspect' )['rows'] ?? array() );
			if ( $rows ) {
				$ok  = count( array_filter( $rows, function ( $r ) {
					return 'PASS' === ( $r['verdict'] ?? '' );
				} ) );
				$no  = count( $rows ) - $ok;
				$k[] = array( sap_num( $ok ) . '/' . sap_num( count( $rows ) ), 'ایندکس‌شده', $no ? 'warn' : 'good' );
				$k[] = array( sap_num( $no ), 'ایندکس‌نشده', $tone( $no, 1, 3 ) );
				$k[] = array( sap_num( count( array_filter( $rows, function ( $r ) {
					return 'PASS' === ( $r['rich'] ?? '' );
				} ) ) ), 'Rich Result معتبر', '' );
			}
			break;
		case 'pages':
		case 'crawl':
			$c = sap_dataset( $pid, 'crawl' );
			if ( $c ) {
				$rows  = (array) ( $c['payload']['rows'] ?? array() );
				$codes = array();
				foreach ( $rows as $r ) {
					$b           = (int) floor( (int) $r['code'] / 100 );
					$codes[ $b ] = ( $codes[ $b ] ?? 0 ) + 1;
				}
				if ( 'pages' === $key ) {
					$k[] = array( sap_num( $codes[4] ?? 0 ), 'خطای 4xx', $tone( $codes[4] ?? 0, 1, 10 ) );
					$k[] = array( sap_num( $codes[5] ?? 0 ), 'خطای 5xx', $tone( $codes[5] ?? 0, 1, 1 ) );
					$k[] = array( sap_num( $codes[3] ?? 0 ), 'ریدایرکت', $tone( $codes[3] ?? 0, 10, 50 ) );
				} else {
					$noidx = count( array_filter( $rows, function ( $r ) {
						return ! $r['idx'];
					} ) );
					$days  = max( 0, (int) floor( ( time() - strtotime( $c['fetched_at'] ) ) / DAY_IN_SECONDS ) );
					$k[]   = array( sap_num( count( $rows ) ), 'آدرس خزش‌شده', '' );
					$k[]   = array( sap_num( $noidx ), 'غیرقابل‌ایندکس', $tone( $noidx, 5, 30 ) );
					$k[]   = array( $days ? sap_num( $days ) . ' روز' : 'امروز', 'از آخرین خزش', $days > 45 ? 'warn' : 'good' );
				}
			}
			break;
	}
	return $k;
}

/**
 * Overall technical score (average of sections with data).
 */
function sap_tech_score( $checklist ) {
	$s = array_filter( array_column( $checklist, 'score' ), 'is_int' );
	return $s ? (int) round( array_sum( $s ) / count( $s ) ) : null;
}
