<?php
/**
 * SERP analysis: before a brief, read Google's top 10 for the keyword (Serper or
 * SerpApi), open every ranking page (headings, length, schema, FAQ, tables,
 * media), compare them with our page and let the AI write the content gap:
 * search intent, topics every winner covers (with coverage %), what our page
 * misses, the angle to beat them, a full outline, FAQ from "People also ask",
 * terms and schema. The result feeds content briefs and update briefs.
 *
 * Also the competitor radar: overtakes on tracked keywords, new competitor pages
 * on our topics, changes of the competitor pages that rank for our focus keywords.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is a SERP data source configured?
 */
function sap_serp_ready() {
	return '' !== (string) sap_setting( 'serp_key' );
}

/**
 * Top 10 of Google for a keyword, normalised. Cached 7 days (shared by projects).
 *
 * @return array|WP_Error {keyword, organic: [position, title, url, domain, snippet], paa: [], related: [], features: [], at}
 */
function sap_serp_fetch( $keyword, $fresh = false ) {
	$keyword = trim( (string) $keyword );
	$ck      = 'sap_serp_' . md5( sap_kw_key( $keyword ) . '|' . sap_setting( 'serp_gl', 'ir' ) );
	$cached  = get_transient( $ck );
	if ( ! $fresh && is_array( $cached ) ) {
		return $cached;
	}
	if ( ! sap_serp_ready() ) {
		return new WP_Error( 'sap_serp_key', 'کلید سرویس نتایج گوگل (Serper.dev یا SerpApi) در تنظیمات وارد نشده.' );
	}
	$gl  = (string) sap_setting( 'serp_gl', 'ir' );
	$hl  = (string) sap_setting( 'serp_hl', 'fa' );
	$key = (string) sap_setting( 'serp_key' );
	if ( 'serpapi' === sap_setting( 'serp_provider', 'serper' ) ) {
		$res = sap_http( add_query_arg( array( 'engine' => 'google', 'q' => rawurlencode( $keyword ), 'gl' => $gl, 'hl' => $hl, 'num' => 10, 'api_key' => $key ), 'https://serpapi.com/search.json' ), array( 'timeout' => 40 ) );
	} else {
		$res = sap_http( 'https://google.serper.dev/search', array( 'method' => 'POST', 'timeout' => 30, 'headers' => array( 'X-API-KEY' => $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'q' => $keyword, 'gl' => $gl, 'hl' => $hl, 'num' => 10 ) ) ) );
	}
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'sap_serp_http', 'دریافت نتایج گوگل ناموفق بود: ' . sap_net_error( $res ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$d    = (array) json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( $code >= 400 ) {
		return new WP_Error( 'sap_serp', 'سرویس نتایج گوگل خطا داد (HTTP ' . $code . '): ' . mb_substr( (string) ( $d['message'] ?? $d['error'] ?? '' ), 0, 200 ) );
	}
	$org = array();
	foreach ( (array) ( $d['organic'] ?? $d['organic_results'] ?? array() ) as $i => $r ) {
		$url = (string) ( $r['link'] ?? '' );
		if ( '' === $url ) {
			continue;
		}
		$org[] = array(
			'position' => (int) ( $r['position'] ?? $i + 1 ),
			'title'    => (string) ( $r['title'] ?? '' ),
			'url'      => $url,
			'domain'   => sap_clean_domain( (string) wp_parse_url( $url, PHP_URL_HOST ) ),
			'snippet'  => (string) ( $r['snippet'] ?? '' ),
		);
		if ( count( $org ) >= 10 ) {
			break;
		}
	}
	$paa = array();
	foreach ( (array) ( $d['peopleAlsoAsk'] ?? $d['related_questions'] ?? array() ) as $q ) {
		$paa[] = (string) ( $q['question'] ?? '' );
	}
	$rel = array();
	foreach ( (array) ( $d['relatedSearches'] ?? $d['related_searches'] ?? array() ) as $q ) {
		$rel[] = (string) ( $q['query'] ?? '' );
	}
	$feat = array();
	foreach ( array( 'answerBox' => 'باکس پاسخ', 'answer_box' => 'باکس پاسخ', 'knowledgeGraph' => 'نالج پنل', 'knowledge_graph' => 'نالج پنل', 'shopping' => 'نتایج خرید', 'shopping_results' => 'نتایج خرید', 'videos' => 'ویدیو', 'inline_videos' => 'ویدیو', 'images' => 'تصاویر', 'inline_images' => 'تصاویر', 'places' => 'نقشه و کسب‌وکار محلی', 'local_results' => 'نقشه و کسب‌وکار محلی', 'topStories' => 'اخبار', 'top_stories' => 'اخبار', 'ai_overview' => 'AI Overview', 'aiOverview' => 'AI Overview' ) as $k => $label ) {
		if ( ! empty( $d[ $k ] ) ) {
			$feat[ $label ] = true;
		}
	}
	if ( $paa ) {
		$feat['People Also Ask'] = true;
	}
	$out = array( 'keyword' => $keyword, 'organic' => $org, 'paa' => array_values( array_filter( $paa ) ), 'related' => array_values( array_filter( $rel ) ), 'features' => array_keys( $feat ), 'at' => sap_now() );
	set_transient( $ck, $out, 7 * DAY_IN_SECONDS );
	return $out;
}

/**
 * Facts of one ranking page for the comparison (compact).
 */
function sap_serp_page_facts( $url ) {
	$p = sap_page_fetch( $url );
	if ( is_wp_error( $p ) ) {
		return array( 'url' => $url, 'error' => $p->get_error_message() );
	}
	$html_has = function ( $needle ) use ( $p ) {
		return false !== mb_stripos( (string) $p['text'], $needle );
	};
	return array(
		'url'    => $url,
		'title'  => $p['title'],
		'h1'     => array_slice( (array) $p['h1'], 0, 2 ),
		'h2'     => array_slice( (array) $p['h2'], 0, 18 ),
		'h3'     => array_slice( (array) $p['h3'], 0, 20 ),
		'words'  => (int) $p['words'],
		'schema' => array_values( array_unique( array_map( 'strval', (array) $p['schema'] ) ) ),
		'faq'    => in_array( 'FAQPage', (array) $p['schema'], true ) || $html_has( 'سوالات متداول' ) || $html_has( 'پرسش‌های متداول' ),
		'images' => (int) $p['images'],
		'text'   => mb_substr( (string) $p['text'], 0, 700 ),
	);
}

/**
 * Start or continue a SERP analysis (resumable: one SERP call, then 3 pages per
 * call, then the AI). Returns more => true until done.
 *
 * @return array|WP_Error {more, msg, pct, result?}
 */
function sap_serp_analyze_step( $project, $keyword, $our_url = '', $restart = false ) {
	$pid  = $project['id'];
	$kk   = sap_kw_key( $keyword );
	$opt  = 'sap_serp_job_' . $pid . '_' . md5( $kk );
	$job  = $restart ? null : get_option( $opt );
	if ( ! is_array( $job ) ) {
		$serp = sap_serp_fetch( $keyword, $restart );
		if ( is_wp_error( $serp ) ) {
			return $serp;
		}
		// Our page: given, or the best page of ours for the keyword in Search Console / keyword map.
		if ( '' === $our_url ) {
			foreach ( sap_keymap( $pid ) as $m ) {
				if ( sap_kw_key( $m['primary_kw'] ) === $kk ) {
					$our_url = $m['url'];
					break;
				}
			}
		}
		$job = array( 'keyword' => $keyword, 'serp' => $serp, 'pages' => array(), 'i' => 0, 'our_url' => $our_url, 'ours' => null );
		update_option( $opt, $job, false );
		return array( 'more' => true, 'pct' => 10, 'msg' => sprintf( '۱۰ نتیجه اول گوگل گرفته شد؛ خواندن صفحات رقبا (۰ از %s)…', sap_num( count( $serp['organic'] ) ) ) );
	}
	$org = $job['serp']['organic'];
	$t0  = microtime( true );
	while ( $job['i'] < count( $org ) && microtime( true ) - $t0 < 18 ) {
		$job['pages'][ $job['i'] ] = sap_serp_page_facts( $org[ $job['i'] ]['url'] );
		++$job['i'];
	}
	if ( $job['i'] < count( $org ) ) {
		update_option( $opt, $job, false );
		return array( 'more' => true, 'pct' => 10 + (int) ( 70 * $job['i'] / max( 1, count( $org ) ) ), 'msg' => sprintf( 'خواندن صفحات رقبا (%s از %s)…', sap_num( $job['i'] ), sap_num( count( $org ) ) ) );
	}
	if ( null === $job['ours'] && '' !== $job['our_url'] ) {
		$job['ours'] = sap_serp_page_facts( $job['our_url'] );
		update_option( $opt, $job, false );
	}
	$res = sap_serp_gap( $project, $job );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	delete_option( $opt );
	$all        = (array) sap_dataset_payload( $pid, 'serp_analysis' );
	$all[ $kk ] = $res;
	if ( count( $all ) > 60 ) {
		uasort( $all, function ( $a, $b ) {
			return strcmp( $b['at'], $a['at'] );
		} );
		$all = array_slice( $all, 0, 60, true );
	}
	sap_dataset_put( $pid, 'serp_analysis', $all, array( 'count' => count( $all ) ) );
	return array( 'more' => false, 'pct' => 100, 'msg' => 'تحلیل نتایج گوگل و شکاف محتوایی «' . $keyword . '» آماده شد.', 'key' => $kk );
}

/**
 * Statistics of the ranking pages + the AI content gap.
 */
function sap_serp_gap( $project, $job ) {
	// The winners are the other ranking pages: our own result is compared, never counted.
	$dom   = sap_project_domain( $project );
	$pages = array();
	foreach ( $job['pages'] as $i => $p ) {
		if ( empty( $p['error'] ) && $p['words'] > 50 && ( $job['serp']['organic'][ $i ]['domain'] ?? '' ) !== $dom ) {
			$pages[] = $p;
		}
	}
	$words  = array_column( $pages, 'words' );
	sort( $words );
	$median = $words ? $words[ (int) floor( count( $words ) / 2 ) ] : 0;
	$schema = array();
	foreach ( $pages as $p ) {
		foreach ( $p['schema'] as $s ) {
			$schema[ $s ] = ( $schema[ $s ] ?? 0 ) + 1;
		}
	}
	arsort( $schema );
	$stats = array(
		'read'    => count( $pages ),
		'median'  => $median,
		'min'     => $words ? min( $words ) : 0,
		'max'     => $words ? max( $words ) : 0,
		'faq'     => count( array_filter( $pages, function ( $p ) {
			return $p['faq'];
		} ) ),
		'h2_avg'  => $pages ? round( array_sum( array_map( function ( $p ) {
			return count( $p['h2'] );
		}, $pages ) ) / count( $pages ) ) : 0,
		'schema'  => $schema,
	);
	$ours   = $job['ours'] && empty( $job['ours']['error'] ) ? $job['ours'] : null;
	$result = array(
		'keyword'  => $job['keyword'],
		'at'       => sap_now(),
		'serp'     => $job['serp'],
		'pages'    => $job['pages'],
		'stats'    => $stats,
		'our_url'  => $job['our_url'],
		'ours'     => $ours ? array( 'url' => $ours['url'], 'title' => $ours['title'], 'words' => $ours['words'], 'h2' => $ours['h2'], 'schema' => $ours['schema'], 'faq' => $ours['faq'] ) : null,
		'our_rank' => null,
		'gap'      => null,
	);
	foreach ( $job['serp']['organic'] as $o ) {
		if ( $o['domain'] === $dom ) {
			$result['our_rank'] = $o['position'];
			break;
		}
	}
	if ( ! sap_ai_connected() ) {
		return $result;
	}
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array(
		'intent'        => array( 'type' => 'string', 'enum' => array( 'informational', 'commercial', 'transactional', 'navigational', 'mixed' ) ),
		'page_type'     => array( 'type' => 'string', 'description' => 'نوع صفحه‌ای که گوگل برای این کلمه ترجیح می‌دهد: راهنمای خرید، مقاله آموزشی، دسته محصول، صفحه محصول، مقایسه، لیست…' ),
		'summary'       => array( 'type' => 'string', 'description' => '۲ تا ۳ جمله: گوگل برای این کلمه چه چیزی را رتبه می‌دهد و چرا' ),
		'words_target'  => array( 'type' => 'integer', 'description' => 'طول پیشنهادی متن (کلمه) برای رقابت' ),
		'must_cover'    => array( 'type' => 'array', 'description' => 'موضوعاتی که بیشتر صفحات برتر پوشش داده‌اند', 'items' => sap_ai_obj( array( 'topic' => $str, 'coverage' => array( 'type' => 'integer', 'description' => 'چند صفحه از صفحات خوانده‌شده این را دارند' ), 'ours' => array( 'type' => 'boolean', 'description' => 'صفحه ما این را پوشش داده؟ (اگر صفحه ما نیست false)' ) ) ) ),
		'gaps'          => array( 'type' => 'array', 'items' => $str, 'description' => 'دقیقاً چه چیزی صفحه ما ندارد که برندگان دارند' ),
		'angle'         => array( 'type' => 'string', 'description' => 'زاویه یا ارزش افزوده‌ای که هیچ‌کدام از برندگان کامل ندارند و ما را متمایز می‌کند' ),
		'outline'       => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'h2' => $str, 'h3' => array( 'type' => 'array', 'items' => $str ), 'note' => $str ) ) ),
		'faq'           => array( 'type' => 'array', 'items' => $str, 'description' => 'سوالات متداول از People Also Ask و نیاز کاربر' ),
		'terms'         => array( 'type' => 'array', 'items' => $str, 'description' => 'اصطلاحات و موجودیت‌هایی که باید در متن بیایند' ),
		'title'         => $str,
		'meta'          => $str,
		'schema'        => array( 'type' => 'array', 'items' => $str ),
		'formats'       => array( 'type' => 'array', 'items' => $str, 'description' => 'قالب‌های لازم: جدول مقایسه، فهرست مراحل، ویدیو، تصویر، ماشین‌حساب…' ),
	) );
	$input = array(
		'keyword' => $job['keyword'],
		'site'    => $dom,
		'serp'    => array_map( function ( $o ) {
			return array( $o['position'], $o['domain'], $o['title'], mb_substr( $o['snippet'], 0, 160 ) );
		}, $job['serp']['organic'] ),
		'features'          => $job['serp']['features'],
		'people_also_ask'   => array_slice( $job['serp']['paa'], 0, 8 ),
		'related_searches'  => array_slice( $job['serp']['related'], 0, 8 ),
		'ranking_pages'     => array_map( function ( $p ) {
			return array( 'url' => sap_short_url( $p['url'], 60 ), 'words' => $p['words'], 'h2' => $p['h2'], 'h3' => array_slice( $p['h3'], 0, 10 ), 'schema' => $p['schema'], 'faq' => $p['faq'] );
		}, $pages ),
		'our_page'          => $ours ? array( 'url' => sap_short_url( $ours['url'], 60 ), 'words' => $ours['words'], 'h2' => $ours['h2'], 'h3' => array_slice( $ours['h3'], 0, 10 ), 'schema' => $ours['schema'], 'faq' => $ours['faq'], 'rank' => $result['our_rank'] ) : null,
		'stats'             => $stats,
	);
	$task = implode( "\n", array(
		'تو استراتژیست ارشد سئو محتوا هستی. ۱۰ نتیجه اول گوگل این کلمه و ساختار صفحات برتر (تیترها، طول، اسکیما، FAQ) و صفحه ما آمده.',
		'نیت جستجو و نوع صفحه‌ای که گوگل ترجیح می‌دهد را مشخص کن. موضوعاتی که بیشتر برندگان پوشش داده‌اند را با تعداد (coverage) بیاور و بگو صفحه ما دارد یا نه. شکاف‌های دقیق صفحه ما، زاویه متمایزکننده، سرفصل کامل (H2/H3) برای شکست دادن برندگان، FAQ از People Also Ask، اصطلاحات لازم، تایتل و متا، اسکیما و قالب‌های لازم را بنویس.',
		'به داده استناد کن، کلی‌گویی نکن، و اگر صفحه ما نیست ours را false بگذار و gaps را برای ساخت صفحه جدید بنویس.',
	) );
	$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'analysis', 'max_tokens' => 4000, 'effort' => 'low' ) );
	if ( ! is_wp_error( $res ) ) {
		unset( $res['_partial'] );
		$result['gap'] = $res;
	} else {
		$result['gap_error'] = $res->get_error_message();
	}
	return $result;
}

/**
 * A stored analysis of a keyword, or null.
 */
function sap_serp_analysis( $project_id, $keyword ) {
	return sap_dataset_payload( $project_id, 'serp_analysis' )[ sap_kw_key( $keyword ) ] ?? null;
}

/**
 * The gap of a keyword as compact facts for a brief prompt.
 */
function sap_serp_brief_facts( $project_id, $keyword ) {
	$a = sap_serp_analysis( $project_id, $keyword );
	if ( ! $a || empty( $a['gap'] ) ) {
		return null;
	}
	$g    = $a['gap'];
	$read = max( 1, (int) $a['stats']['read'] );
	return array(
		'intent'       => $g['intent'] ?? '',
		'page_type'    => $g['page_type'] ?? '',
		'words_target' => $g['words_target'] ?? $a['stats']['median'],
		'must_cover'   => array_slice( array_map( function ( $m ) use ( $read ) {
			return ( $m['topic'] ?? '' ) . ' (' . ( $m['coverage'] ?? 0 ) . ' از ' . $read . ' صفحه برتر)';
		}, (array) ( $g['must_cover'] ?? array() ) ), 0, 14 ),
		'gaps'         => array_slice( (array) ( $g['gaps'] ?? array() ), 0, 10 ),
		'angle'        => $g['angle'] ?? '',
		'outline'      => array_slice( (array) ( $g['outline'] ?? array() ), 0, 12 ),
		'faq'          => array_slice( (array) ( $g['faq'] ?? array() ), 0, 8 ),
		'terms'        => array_slice( (array) ( $g['terms'] ?? array() ), 0, 20 ),
		'top_domains'  => array_slice( array_column( $a['serp']['organic'], 'domain' ), 0, 5 ),
	);
}

/* ---------------------------------------------------------------------------
 * Competitor radar
 * ------------------------------------------------------------------------- */

/**
 * Radar events (newest first).
 */
function sap_radar_events( $project_id, $limit = 100 ) {
	return array_slice( (array) ( sap_dataset_payload( $project_id, 'radar' )['events'] ?? array() ), 0, $limit );
}

/**
 * Add radar events (dedupe by key).
 */
function sap_radar_add( $project_id, $events ) {
	$ds   = (array) sap_dataset_payload( $project_id, 'radar' );
	$list = (array) ( $ds['events'] ?? array() );
	$keys = array_flip( array_column( $list, 'key' ) );
	$new  = array();
	foreach ( $events as $e ) {
		if ( isset( $keys[ $e['key'] ] ) ) {
			continue;
		}
		$e['at'] = $e['at'] ?? sap_now();
		$new[]   = $e;
	}
	if ( $new ) {
		$ds['events'] = array_slice( array_merge( $new, $list ), 0, 400 );
		sap_dataset_put( $project_id, 'radar', $ds, array( 'count' => count( $ds['events'] ) ) );
	}
	return $new;
}

/**
 * Overtakes on tracked keywords from the daily competitor positions.
 */
function sap_radar_overtakes( $project ) {
	global $wpdb;
	$t    = sap_table( 'ranks' );
	$days = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT day FROM $t WHERE project_id = %d AND source = 'ss' ORDER BY day DESC LIMIT 8", $project['id'] ) ); // phpcs:ignore
	if ( count( $days ) < 2 ) {
		return array();
	}
	$now  = $days[0];
	$then = end( $days );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT keyword, domain, day, position FROM $t WHERE project_id = %d AND source = 'ss' AND day IN (%s, %s)", $project['id'], $now, $then ), ARRAY_A ); // phpcs:ignore
	$pos  = array();
	foreach ( (array) $rows as $r ) {
		$pos[ $r['keyword'] ][ $r['domain'] ][ $r['day'] ] = null === $r['position'] ? 999 : (float) $r['position'];
	}
	$focus = array();
	foreach ( sap_rank_keywords( $project ) as $info ) {
		if ( '' !== $info['focus'] ) {
			$focus[ sap_norm_text( $info['keyword'] ) ] = $info['focus'];
		}
	}
	$out = array();
	foreach ( $pos as $kw => $by ) {
		$us_now  = $by[''][ $now ] ?? 999;
		$us_then = $by[''][ $then ] ?? 999;
		foreach ( $by as $dom => $d ) {
			if ( '' === $dom ) {
				continue;
			}
			$c_now  = $d[ $now ] ?? 999;
			$c_then = $d[ $then ] ?? 999;
			if ( $c_now < $us_now && $c_then >= $us_then && $c_now <= 20 ) {
				$out[] = array( 'key' => 'ov:' . $kw . ':' . $dom . ':' . $now, 'type' => 'overtake', 'domain' => $dom, 'keyword' => $kw, 'focus' => $focus[ $kw ] ?? '', 'detail' => sprintf( '%s روی «%s» از ما جلو زد: رقیب %s، ما %s (هفته قبل رقیب %s، ما %s).', $dom, $kw, sap_num( $c_now, 0 ), 999 === $us_now ? 'بدون رتبه' : sap_num( $us_now, 0 ), 999 === $c_then ? '—' : sap_num( $c_then, 0 ), 999 === $us_then ? '—' : sap_num( $us_then, 0 ) ) );
			} elseif ( $c_now > $us_now && $c_then < $us_then && $us_now <= 20 ) {
				$out[] = array( 'key' => 'win:' . $kw . ':' . $dom . ':' . $now, 'type' => 'we_pass', 'domain' => $dom, 'keyword' => $kw, 'focus' => $focus[ $kw ] ?? '', 'detail' => sprintf( 'ما روی «%s» از %s جلو زدیم: ما %s، رقیب %s.', $kw, $dom, sap_num( $us_now, 0 ), 999 === $c_now ? 'بدون رتبه' : sap_num( $c_now, 0 ) ) );
			}
		}
	}
	return $out;
}

/**
 * New competitor pages on our topics (sitemap diff, weekly).
 */
function sap_radar_new_pages( $project ) {
	$state = (array) sap_alert_state( $project['id'], 'radar_sitemaps' );
	$terms = array();
	$kws   = array();
	foreach ( sap_rank_keywords( $project ) as $info ) {
		$toks = array_values( array_filter( sap_tokens( sap_kw_core( $info['keyword'] ) ), function ( $tk ) {
			return mb_strlen( $tk ) >= 3;
		} ) );
		foreach ( $toks as $tk ) {
			$terms[ $tk ] = true;
		}
		if ( $toks ) {
			$kws[ $info['keyword'] ] = $toks;
		}
	}
	// Label a new page by the tracked keyword it covers best (all its words in the slug).
	$label = function ( $slug, $hit ) use ( $kws ) {
		$best = '';
		foreach ( $kws as $kw => $toks ) {
			$all = true;
			foreach ( $toks as $tk ) {
				$all = $all && false !== mb_strpos( $slug, $tk );
			}
			if ( $all && count( $toks ) > count( $kws[ $best ] ?? array() ) ) {
				$best = $kw;
			}
		}
		return '' !== $best ? $best : implode( '، ', array_slice( $hit, 0, 3 ) );
	};
	$out = array();
	foreach ( array_slice( (array) $project['competitors'], 0, 5 ) as $dom ) {
		$urls = sap_radar_sitemap_urls( $dom );
		if ( ! $urls ) {
			continue;
		}
		$seen = array_flip( (array) ( $state[ $dom ] ?? array() ) );
		if ( $seen ) {
			foreach ( $urls as $u ) {
				if ( isset( $seen[ md5( $u ) ] ) ) {
					continue;
				}
				$slug = sap_norm_text( str_replace( array( '-', '_', '/' ), ' ', rawurldecode( (string) wp_parse_url( $u, PHP_URL_PATH ) ) ) );
				$hit  = array_values( array_filter( array_keys( $terms ), function ( $t ) use ( $slug ) {
					return false !== mb_strpos( $slug, $t );
				} ) );
				if ( $hit && count( $out ) < 40 ) {
					$topic = $label( $slug, $hit );
					$out[] = array( 'key' => 'np:' . md5( $u ), 'type' => 'new_page', 'domain' => $dom, 'url' => $u, 'keyword' => $topic, 'detail' => sprintf( '%s صفحه تازه‌ای در موضوع «%s» منتشر کرد: %s', $dom, $topic, rawurldecode( $u ) ) );
				}
			}
		}
		$state[ $dom ] = array_slice( array_map( 'md5', $urls ), 0, 6000 );
	}
	sap_alert_state( $project['id'], 'radar_sitemaps', $state );
	return $out;
}

/**
 * URLs of a competitor's sitemap (index followed up to 8 files, max 6000).
 */
function sap_radar_sitemap_urls( $domain ) {
	$queue = array( 'https://' . $domain . '/sitemap_index.xml', 'https://' . $domain . '/sitemap.xml', 'https://' . $domain . '/wp-sitemap.xml' );
	$urls  = array();
	$done  = 0;
	$root  = false;
	while ( $queue && $done < 10 && count( $urls ) < 6000 ) {
		$u = array_shift( $queue );
		$r = sap_fetch_public( $u, 15 );
		++$done;
		if ( is_wp_error( $r ) || 200 !== (int) $r['code'] || false === stripos( $r['body'], '<loc' ) ) {
			continue;
		}
		preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</loc>#is', $r['body'], $m );
		if ( false !== stripos( $r['body'], '<sitemapindex' ) ) {
			if ( ! $root ) {
				$queue = array();
				$root  = true;
			}
			foreach ( array_slice( $m[1], 0, 8 ) as $s ) {
				$queue[] = html_entity_decode( $s );
			}
			continue;
		}
		$root = true;
		foreach ( $m[1] as $l ) {
			$urls[] = html_entity_decode( $l );
		}
		if ( ! $queue ) {
			break;
		}
	}
	return array_values( array_unique( $urls ) );
}

/**
 * Changes of the competitor pages that rank for our focus keywords (title,
 * H1, length), from the stored SERP analyses.
 */
function sap_radar_page_changes( $project ) {
	$state = (array) sap_alert_state( $project['id'], 'radar_pages' );
	$out   = array();
	$dom   = sap_project_domain( $project );
	$urls  = array();
	foreach ( (array) sap_dataset_payload( $project['id'], 'serp_analysis' ) as $a ) {
		foreach ( array_slice( (array) ( $a['serp']['organic'] ?? array() ), 0, 5 ) as $o ) {
			if ( $o['domain'] !== $dom && in_array( $o['domain'], (array) $project['competitors'], true ) ) {
				$urls[ $o['url'] ] = $a['keyword'];
			}
		}
	}
	foreach ( array_slice( $urls, 0, 15, true ) as $u => $kw ) {
		$p = sap_page_fetch( $u );
		if ( is_wp_error( $p ) ) {
			continue;
		}
		$sig = array( 't' => $p['title'], 'h' => implode( '|', array_slice( (array) $p['h1'], 0, 1 ) ), 'w' => (int) $p['words'], 'h2' => count( (array) $p['h2'] ) );
		$old = $state[ md5( $u ) ] ?? null;
		if ( $old ) {
			$parts = array();
			if ( $old['t'] !== $sig['t'] ) {
				$parts[] = 'تایتل: «' . $old['t'] . '» ← «' . $sig['t'] . '»';
			}
			if ( abs( $sig['w'] - $old['w'] ) > max( 150, $old['w'] * 0.2 ) ) {
				$parts[] = sprintf( 'طول متن: %s ← %s کلمه', sap_num( $old['w'] ), sap_num( $sig['w'] ) );
			}
			if ( $sig['h2'] - $old['h2'] >= 2 ) {
				$parts[] = sprintf( '%s سرفصل H2 تازه', sap_num( $sig['h2'] - $old['h2'] ) );
			}
			if ( $parts ) {
				$out[] = array( 'key' => 'pc:' . md5( $u . wp_json_encode( $sig ) ), 'type' => 'page_change', 'domain' => sap_clean_domain( (string) wp_parse_url( $u, PHP_URL_HOST ) ), 'url' => $u, 'keyword' => $kw, 'detail' => 'صفحه رقیب روی «' . $kw . '» تغییر کرد — ' . implode( '؛ ', $parts ) );
			}
		}
		$state[ md5( $u ) ] = $sig;
	}
	sap_alert_state( $project['id'], 'radar_pages', $state );
	return $out;
}

/**
 * Run the radar (daily overtakes; weekly sitemaps and page changes).
 *
 * @return array new events
 */
function sap_radar_run( $project, $weekly = null ) {
	$weekly = null === $weekly ? (int) current_time( 'w' ) === 6 : $weekly; // Saturday.
	$events = sap_radar_overtakes( $project );
	if ( $weekly ) {
		$events = array_merge( $events, sap_radar_new_pages( $project ), sap_radar_page_changes( $project ) );
	}
	return sap_radar_add( $project['id'], $events );
}

/**
 * Alert rule: new radar events of the day (overtakes on focus keywords are warnings).
 */
function sap_alert_rule_radar( $project, $cfg ) {
	$new = sap_radar_run( $project );
	$out = array();
	foreach ( sap_radar_events( $project['id'], 60 ) as $e ) {
		if ( strtotime( $e['at'] ) < current_time( 'timestamp' ) - 3 * DAY_IN_SECONDS ) { // phpcs:ignore
			continue;
		}
		if ( 'overtake' === $e['type'] ) {
			$out[] = array( $e['key'], '' !== $e['focus'] ? 'warning' : 'info', sprintf( '%s روی «%s» از ما جلو زد', $e['domain'], $e['keyword'] ), $e['detail'] );
		} elseif ( 'new_page' === $e['type'] ) {
			$out[] = array( $e['key'], 'info', sprintf( '%s صفحه جدید در موضوع «%s» منتشر کرد', $e['domain'], $e['keyword'] ), $e['detail'], $e['url'] );
		} elseif ( 'page_change' === $e['type'] ) {
			$out[] = array( $e['key'], 'info', sprintf( '%s صفحه رتبه‌دار خود روی «%s» را تغییر داد', $e['domain'], $e['keyword'] ), $e['detail'], $e['url'] );
		}
	}
	return array_slice( $out, 0, 15 );
}

/**
 * Sync step.
 */
function sap_step_radar( $project ) {
	if ( ! $project['competitors'] ) {
		return sap_skip( 'رقیبی برای پروژه ثبت نشده (تب رادار رقبا).' );
	}
	$new = sap_radar_run( $project, true );
	return array( 'msg' => sprintf( 'رادار رقبا: %s رویداد تازه (جلو زدن، صفحه جدید، تغییر صفحه).', sap_num( count( $new ) ) ) );
}

/**
 * Head to head with the competitors on the tracked keywords (latest SEO Signal
 * day): rows per keyword and a summary per competitor.
 *
 * @return array {last, rows: [keyword, focus, volume, us, comps{domain: pos}], summary{domain: {ahead, behind, top10, avg, sov}}}
 */
function sap_radar_h2h( $project ) {
	global $wpdb;
	$t    = sap_table( 'ranks' );
	$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(day) FROM $t WHERE project_id = %d AND source = 'ss' AND domain <> ''", $project['id'] ) ); // phpcs:ignore
	if ( ! $last ) {
		return array( 'last' => '', 'rows' => array(), 'summary' => array() );
	}
	$pos = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT keyword, domain, position FROM $t WHERE project_id = %d AND source = 'ss' AND day = %s", $project['id'], $last ), ARRAY_A ) as $r ) { // phpcs:ignore
		$pos[ $r['keyword'] ][ $r['domain'] ] = null === $r['position'] ? null : (float) $r['position'];
	}
	$comps = array_values( (array) $project['competitors'] );
	$sov   = sap_rank_share_of_voice( $project );
	$sum   = array();
	foreach ( $comps as $c ) {
		$sum[ $c ] = array( 'ahead' => 0, 'behind' => 0, 'top10' => 0, 'avg' => array(), 'sov' => (float) ( $sov[ $c ] ?? 0 ) );
	}
	$rows = array();
	foreach ( sap_rank_keywords( $project ) as $info ) {
		$k = sap_norm_text( $info['keyword'] );
		if ( ! isset( $pos[ $k ] ) ) {
			continue;
		}
		$us  = $pos[ $k ][''] ?? null;
		$row = array( 'keyword' => $info['keyword'], 'focus' => $info['focus'], 'volume' => $info['volume'], 'us' => $us, 'comps' => array() );
		foreach ( $comps as $c ) {
			$p                    = $pos[ $k ][ $c ] ?? null;
			$row['comps'][ $c ] = $p;
			if ( null !== $p ) {
				$sum[ $c ]['avg'][] = $p;
				$sum[ $c ]['top10'] += $p <= 10 ? 1 : 0;
			}
			if ( null !== $p && ( null === $us || $p < $us ) ) {
				++$sum[ $c ]['ahead'];
			} elseif ( null !== $us && ( null === $p || $us < $p ) ) {
				++$sum[ $c ]['behind'];
			}
		}
		$rows[] = $row;
	}
	foreach ( $sum as $c => $s ) {
		$sum[ $c ]['avg'] = $s['avg'] ? round( array_sum( $s['avg'] ) / count( $s['avg'] ), 1 ) : null;
	}
	// Focus keywords first, then where a competitor beats us, then by volume.
	usort( $rows, function ( $a, $b ) {
		$fa = '' !== $a['focus'] ? 0 : 1;
		$fb = '' !== $b['focus'] ? 0 : 1;
		return $fa <=> $fb ?: $b['volume'] <=> $a['volume'];
	} );
	return array( 'last' => $last, 'rows' => $rows, 'summary' => $sum, 'us_sov' => (float) ( $sov[ sap_project_domain( $project ) ] ?? 0 ) );
}
