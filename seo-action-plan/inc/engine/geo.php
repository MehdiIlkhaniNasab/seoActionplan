<?php
/**
 * GEO — visibility in AI answers (ChatGPT, Gemini, Perplexity-style
 * assistants): a set of real customer questions is asked of an AI model on a
 * schedule, and every answer is checked for our brand/site and the
 * competitors'. Plus a GEO audit (AI crawlers in robots.txt, llms.txt,
 * Organization/WebSite schema, freshness, FAQ coverage) and an llms.txt
 * generator published through the connector.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * AI crawlers worth allowing.
 */
function sap_geo_bots() {
	return array(
		'GPTBot'          => 'OpenAI (آموزش مدل)',
		'OAI-SearchBot'   => 'جستجوی ChatGPT',
		'ChatGPT-User'    => 'ChatGPT هنگام پاسخ',
		'ClaudeBot'       => 'Anthropic Claude',
		'PerplexityBot'   => 'Perplexity',
		'Google-Extended' => 'Gemini و AI گوگل',
		'CCBot'           => 'Common Crawl (منبع داده مدل‌ها)',
	);
}

/**
 * Questions tracked for a project: {q, kw}.
 */
function sap_geo_prompts( $project ) {
	return array_values( (array) ( $project['options']['geo_prompts'] ?? array() ) );
}

/**
 * Brand terms of the project (brand names + the domain without TLD).
 */
function sap_geo_brand_terms( $project ) {
	$dom   = sap_project_domain( $project );
	$terms = array_filter( array_map( 'trim', preg_split( '/[\n،,]+/u', (string) $project['brand_terms'] ) ) );
	$terms[] = $dom;
	$terms[] = preg_replace( '/\.[a-z.]+$/i', '', $dom );
	return array_values( array_unique( array_filter( $terms, function ( $t ) {
		return mb_strlen( $t ) >= 3;
	} ) ) );
}

/**
 * Suggest questions from the Focus keywords and the questions people search.
 */
function sap_geo_suggest( $project ) {
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید هوش مصنوعی در تنظیمات وارد نشده است.' );
	}
	$kws = array();
	foreach ( sap_rank_keywords( $project ) as $i ) {
		if ( '' !== $i['focus'] || $i['volume'] >= 100 ) {
			$kws[] = $i['keyword'];
		}
	}
	$qs  = array_slice( array_column( (array) ( sap_dataset_payload( $project['id'], 'ss_questions' )['rows'] ?? array() ), 'keyword' ), 0, 15 );
	$str = array( 'type' => 'string' );
	$res = sap_ai_request(
		sap_ai_system_prompt(),
		"برای سنجش دیده شدن این کسب‌وکار در پاسخ‌های هوش مصنوعی (ChatGPT، Gemini، Perplexity)، ۱۰ سوال واقعی که مشتری‌ها از دستیار هوش مصنوعی می‌پرسند بنویس: ترکیبی از «بهترین … کدام است»، «از کجا بخرم»، «مقایسه …» و سوال‌های راهنمای خرید. سوال‌ها طبیعی و محاوره‌ای باشند و نام برند خود سایت را نداشته باشند.\n\n" . wp_json_encode( array( 'site' => sap_project_domain( $project ), 'keywords' => array_slice( $kws, 0, 25 ), 'search_questions' => $qs ), JSON_UNESCAPED_UNICODE ),
		sap_ai_obj( array( 'prompts' => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'q' => $str, 'kw' => $str ) ) ) ) ),
		array( 'purpose' => 'assist', 'max_tokens' => 1200, 'effort' => 'low' )
	);
	return is_wp_error( $res ) ? $res : array_slice( (array) ( $res['prompts'] ?? array() ), 0, 12 );
}

/**
 * Ask the tracked questions (a few per call; resumable) and store the run.
 *
 * @return array|WP_Error {more, msg}
 */
function sap_geo_run_step( $project, $restart = false ) {
	$prompts = sap_geo_prompts( $project );
	if ( ! $prompts ) {
		return new WP_Error( 'sap_geo', 'هنوز سوالی برای پیگیری تعریف نشده.' );
	}
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید هوش مصنوعی در تنظیمات وارد نشده است.' );
	}
	$opt = 'sap_geo_job_' . $project['id'];
	$job = $restart ? null : get_option( $opt );
	if ( ! is_array( $job ) ) {
		$job = array( 'i' => 0, 'results' => array(), 'at' => sap_now() );
	}
	$brand = sap_geo_brand_terms( $project );
	$comps = (array) $project['competitors'];
	$str   = array( 'type' => 'string' );
	$t0    = microtime( true );
	while ( $job['i'] < count( $prompts ) && microtime( true ) - $t0 < 20 ) {
		$p   = $prompts[ $job['i'] ];
		$res = sap_ai_request(
			'تو یک دستیار هوش مصنوعی عمومی مثل ChatGPT هستی و به کاربر ایرانی جواب می‌دهی. طبیعی و مفید جواب بده و اگر مناسب است برندها، فروشگاه‌ها یا سایت‌های مشخص را پیشنهاد کن (همان‌طور که معمولاً جواب می‌دهی). سپس فهرست برندها/فروشگاه‌ها/سایت‌هایی که در جوابت نام بردی را به ترتیب ذکر بنویس.',
			(string) $p['q'],
			sap_ai_obj( array( 'answer' => $str, 'mentions' => array( 'type' => 'array', 'items' => $str, 'description' => 'برندها/سایت‌ها به ترتیب ذکر' ) ) ),
			array( 'purpose' => 'geo', 'max_tokens' => 900, 'effort' => 'low', 'timeout' => 90 )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$answer = (string) ( $res['answer'] ?? '' );
		$hay    = sap_norm_text( $answer . ' ' . implode( ' ', (array) ( $res['mentions'] ?? array() ) ) );
		$us     = false;
		foreach ( $brand as $b ) {
			$us = $us || false !== mb_strpos( $hay, sap_norm_text( $b ) );
		}
		$rank = null;
		foreach ( array_values( (array) ( $res['mentions'] ?? array() ) ) as $n => $m ) {
			foreach ( $brand as $b ) {
				if ( null === $rank && false !== mb_strpos( sap_norm_text( $m ), sap_norm_text( $b ) ) ) {
					$rank = $n + 1;
				}
			}
		}
		$cm = array();
		foreach ( $comps as $c ) {
			$base = preg_replace( '/\.[a-z.]+$/i', '', $c );
			if ( false !== mb_strpos( $hay, sap_norm_text( $c ) ) || ( mb_strlen( $base ) >= 3 && false !== mb_strpos( $hay, sap_norm_text( $base ) ) ) ) {
				$cm[] = $c;
			}
		}
		$job['results'][] = array( 'q' => $p['q'], 'kw' => (string) ( $p['kw'] ?? '' ), 'us' => $us, 'rank' => $rank, 'competitors' => $cm, 'mentions' => array_slice( (array) ( $res['mentions'] ?? array() ), 0, 10 ), 'answer' => mb_substr( $answer, 0, 1200 ) );
		++$job['i'];
	}
	if ( $job['i'] < count( $prompts ) ) {
		update_option( $opt, $job, false );
		return array( 'more' => true, 'pct' => (int) ( 100 * $job['i'] / count( $prompts ) ), 'msg' => sprintf( 'پرسیدن سوال‌ها از هوش مصنوعی (%s از %s)…', sap_num( $job['i'] ), sap_num( count( $prompts ) ) ) );
	}
	delete_option( $opt );
	$data   = (array) sap_dataset_payload( $project['id'], 'geo' );
	$runs   = (array) ( $data['runs'] ?? array() );
	$hits   = count( array_filter( $job['results'], function ( $r ) {
		return $r['us'];
	} ) );
	$runs[] = array( 'at' => sap_now(), 'model' => sap_ai_model_for( 'geo' ), 'visibility' => round( 100 * $hits / max( 1, count( $job['results'] ) ) ), 'results' => $job['results'] );
	$data['runs'] = array_slice( $runs, -26 );
	sap_dataset_put( $project['id'], 'geo', $data, array( 'count' => count( $data['runs'] ) ) );
	return array( 'more' => false, 'pct' => 100, 'msg' => sprintf( 'دیده شدن در پاسخ‌های هوش مصنوعی: در %s از %s سوال نام سایت آمد.', sap_num( $hits ), sap_num( count( $job['results'] ) ) ) );
}

/**
 * Share of voice in AI answers of the latest run: domain => mentions.
 */
function sap_geo_share( $run, $project ) {
	$out = array( sap_project_domain( $project ) => 0 );
	foreach ( (array) $project['competitors'] as $c ) {
		$out[ $c ] = 0;
	}
	foreach ( (array) ( $run['results'] ?? array() ) as $r ) {
		if ( $r['us'] ) {
			++$out[ sap_project_domain( $project ) ];
		}
		foreach ( $r['competitors'] as $c ) {
			$out[ $c ] = ( $out[ $c ] ?? 0 ) + 1;
		}
	}
	arsort( $out );
	return $out;
}

/**
 * GEO audit of the site (no AI): crawlers, llms.txt, schema, freshness, FAQ.
 *
 * @return array {checks: [{key, label, status: good|warn|bad, detail, fix}], score}
 */
function sap_geo_audit( $project, $fresh = false ) {
	$key = 'sap_geo_audit_' . $project['id'];
	$hit = $fresh ? false : get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$base   = 'https://' . sap_project_domain( $project ) . '/';
	$checks = array();
	// 1) AI crawlers in robots.txt.
	$robots = sap_fetch_public( $base . 'robots.txt', 15 );
	$txt    = is_wp_error( $robots ) || 200 !== (int) $robots['code'] ? '' : (string) $robots['body'];
	$groups = array();
	$cur    = array();
	foreach ( preg_split( '/\r?\n/', $txt ) as $line ) {
		$line = trim( preg_replace( '/#.*/', '', $line ) );
		if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
			if ( ! empty( $cur['rules'] ) ) {
				$cur = array();
			}
			$cur['agents'][]              = strtolower( trim( $m[1] ) );
			$cur['rules']                 = $cur['rules'] ?? array();
			$groups[ spl_object_id( (object) array() ) . count( $groups ) ] = &$cur;
		} elseif ( preg_match( '/^(dis)?allow:\s*(.*)$/i', $line, $m ) && isset( $cur['agents'] ) ) {
			$cur['rules'][] = array( '' !== $m[1] ? 'disallow' : 'allow', trim( $m[2] ) );
		}
	}
	unset( $cur );
	$blocked = array();
	foreach ( sap_geo_bots() as $bot => $label ) {
		$rules = null;
		foreach ( $groups as $g ) {
			if ( in_array( strtolower( $bot ), $g['agents'], true ) ) {
				$rules = $g['rules'];
			}
		}
		if ( null === $rules ) {
			foreach ( $groups as $g ) {
				if ( in_array( '*', $g['agents'], true ) ) {
					$rules = $g['rules'];
				}
			}
		}
		foreach ( (array) $rules as $r ) {
			if ( 'disallow' === $r[0] && '/' === $r[1] ) {
				$blocked[] = $bot . ' (' . $label . ')';
			}
		}
	}
	$checks[] = array( 'key' => 'bots', 'label' => 'دسترسی ربات‌های هوش مصنوعی در robots.txt', 'status' => $blocked ? 'bad' : ( '' === $txt ? 'warn' : 'good' ), 'detail' => $blocked ? 'مسدود: ' . implode( '، ', $blocked ) : ( '' === $txt ? 'robots.txt خوانده نشد.' : 'همه ربات‌های مهم هوش مصنوعی اجازه خزش دارند.' ), 'fix' => $blocked ? 'در robots.txt برای این ربات‌ها Allow بگذارید (اگر عمداً مسدود نکرده‌اید)؛ بدون خزش، پاسخ‌های هوش مصنوعی از سایت شما نقل نمی‌کنند.' : '' );
	// 2) llms.txt.
	$llms     = sap_fetch_public( $base . 'llms.txt', 12 );
	$has      = ! is_wp_error( $llms ) && 200 === (int) $llms['code'] && mb_strlen( trim( (string) $llms['body'] ) ) > 30 && false === stripos( (string) $llms['body'], '<html' );
	$checks[] = array( 'key' => 'llms', 'label' => 'فایل llms.txt', 'status' => $has ? 'good' : 'warn', 'detail' => $has ? 'وجود دارد.' : 'وجود ندارد.', 'fix' => $has ? '' : 'با دکمه «ساخت llms.txt» یک نسخه از صفحات کلیدی سایت بسازید و منتشر کنید؛ راهنمای خلاصه سایت برای مدل‌های زبانی است.' );
	// 3) Entity schema on the home page.
	$home     = sap_page_fetch( $base );
	$types    = is_wp_error( $home ) ? array() : array_map( 'strval', (array) $home['schema'] );
	$miss     = array_values( array_diff( array( 'Organization', 'WebSite' ), $types ) );
	if ( in_array( 'LocalBusiness', $types, true ) || in_array( 'Store', $types, true ) || in_array( 'OnlineStore', $types, true ) ) {
		$miss = array_values( array_diff( $miss, array( 'Organization' ) ) );
	}
	$checks[] = array( 'key' => 'entity', 'label' => 'هویت برند (Organization و WebSite) در صفحه اصلی', 'status' => $miss ? 'warn' : 'good', 'detail' => $miss ? 'ندارد: ' . implode( '، ', $miss ) : 'دارد.', 'fix' => $miss ? 'از «اتوماسیون سایت › اسکیما» برای صفحه اصلی اسکیما بسازید؛ مدل‌ها برند را با این داده‌ها به‌عنوان «موجودیت» می‌شناسند.' : '' );
	// 4) Freshness and depth of guides.
	$inv    = sap_site_inventory( $project['id'] );
	$arts   = array_filter( $inv, function ( $it ) {
		return 'post' === $it['ctype'] && ! $it['redirect'];
	} );
	if ( $arts ) {
		$old   = count( array_filter( $arts, function ( $it ) {
			return strtotime( $it['modified'] ) < time() - 365 * DAY_IN_SECONDS;
		} ) );
		$thin  = count( array_filter( $arts, function ( $it ) {
			return $it['words'] < 600;
		} ) );
		$share = round( 100 * $old / count( $arts ) );
		$checks[] = array( 'key' => 'fresh', 'label' => 'تازگی مقالات', 'status' => $share > 50 ? 'warn' : 'good', 'detail' => sprintf( '%s٪ مقالات (%s از %s) بیش از یک سال به‌روز نشده‌اند؛ %s مقاله کمتر از ۶۰۰ کلمه.', sap_num( $share ), sap_num( $old ), sap_num( count( $arts ) ), sap_num( $thin ) ), 'fix' => $share > 50 ? 'مقالات پربازدید را با آمار، تاریخ و پاسخ مستقیم به سوال‌ها به‌روز کنید؛ هوش مصنوعی منابع تازه و دقیق را ترجیح می‌دهد.' : '' );
	}
	// 5) Answer blocks: FAQ coverage from our stored automation/schema and the content index.
	$faq = 0;
	foreach ( sap_auto_log( $project['id'], 'schema', 600 ) as $e ) {
		$faq += 'live' === $e['status'] && false !== strpos( $e['label'], 'FAQPage' ) ? 1 : 0;
	}
	$checks[] = array( 'key' => 'faq', 'label' => 'بلوک‌های پرسش و پاسخ (FAQ) روی صفحات مهم', 'status' => $faq >= 3 ? 'good' : 'warn', 'detail' => sprintf( '%s صفحه با اسکیمای FAQ از طریق سکان.', sap_num( $faq ) ), 'fix' => $faq >= 3 ? '' : 'روی صفحات کلیدی بخش «سوالات متداول» با جواب‌های کوتاه و دقیق بگذارید (ساختار سوال › جواب مستقیم دقیقاً همان چیزی است که موتورهای پاسخ نقل می‌کنند).' );
	$score = (int) round( 100 * array_sum( array_map( function ( $c ) {
		return 'good' === $c['status'] ? 1 : ( 'warn' === $c['status'] ? 0.5 : 0 );
	}, $checks ) ) / max( 1, count( $checks ) ) );
	$out = array( 'checks' => $checks, 'score' => $score, 'at' => sap_now() );
	set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
	return $out;
}

/**
 * Build an llms.txt from the site's key pages (by Search Console clicks).
 */
function sap_geo_llms_build( $project ) {
	$inv   = sap_site_inventory( $project['id'] );
	$click = array();
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$click[ sap_norm_url( $r['page'] ) ] = (int) $r['clicks'];
	}
	$groups = array( 'product_cat' => array(), 'product' => array(), 'post' => array(), 'page' => array() );
	foreach ( $inv as $k => $it ) {
		if ( $it['redirect'] || $it['noindex'] || ! isset( $groups[ $it['ctype'] ] ) ) {
			continue;
		}
		$groups[ $it['ctype'] ][] = $it + array( 'c' => $click[ $k ] ?? 0 );
	}
	$name = $project['name'];
	$desc = '';
	$obj  = sap_site_page_details( $project, 'https://' . sap_project_domain( $project ) . '/' );
	if ( is_array( $obj ) ) {
		$desc = (string) ( $obj['seo']['meta_description'] ?? '' );
		$name = (string) ( $obj['site']['name'] ?? $name );
	}
	$lines   = array( '# ' . $name, '' );
	$lines[] = '> ' . ( $desc ?: 'سایت ' . sap_project_domain( $project ) );
	$lines[] = '';
	$titles  = array( 'product_cat' => 'دسته‌های اصلی', 'product' => 'محصولات کلیدی', 'post' => 'راهنماها و مقالات', 'page' => 'صفحات مهم' );
	$limits  = array( 'product_cat' => 15, 'product' => 20, 'post' => 20, 'page' => 8 );
	foreach ( $groups as $t => $items ) {
		if ( ! $items ) {
			continue;
		}
		usort( $items, function ( $a, $b ) {
			return $b['c'] <=> $a['c'] ?: $b['words'] <=> $a['words'];
		} );
		$lines[] = '## ' . $titles[ $t ];
		foreach ( array_slice( $items, 0, $limits[ $t ] ) as $it ) {
			$d       = trim( (string) $it['seo']['meta_description'] );
			$lines[] = '- [' . $it['title'] . '](' . $it['url'] . ')' . ( '' !== $d ? ': ' . mb_substr( $d, 0, 160 ) : '' );
		}
		$lines[] = '';
	}
	return trim( implode( "\n", $lines ) ) . "\n";
}

add_action( 'sap_daily', function () {
	// Weekly (Saturday) GEO run for projects that track questions.
	if ( 6 !== (int) current_time( 'w' ) ) {
		return;
	}
	foreach ( sap_projects() as $p ) {
		if ( ! sap_geo_prompts( $p ) ) {
			continue;
		}
		$GLOBALS['sap_ai_project'] = $p;
		$n = 0;
		do {
			$r = sap_geo_run_step( $p, 0 === $n );
		} while ( ! is_wp_error( $r ) && ! empty( $r['more'] ) && ++$n < 10 );
	}
}, 40 );
