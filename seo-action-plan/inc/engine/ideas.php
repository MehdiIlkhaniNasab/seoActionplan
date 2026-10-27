<?php
/**
 * AI content ideas: new articles worth writing, found in the project's data.
 *
 * Signals: informational Search Console queries that no page owns or that rank
 * beyond page one, broader keywords without an owner, cluster keywords without a
 * page, competitor gap keywords, real user questions (SEO Signal) and the focus
 * goals. The model gets the existing articles (to avoid duplicates), the money
 * pages (categories / products) the blog should support, and the rejected ideas
 * with your notes (so it does not suggest them again).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Words that mark an informational query.
 */
function sap_info_markers() {
	return array( 'چیست', 'چیه', 'چگونه', 'چطور', 'چطوری', 'روش', 'علت', 'دلیل', 'نحوه', 'طرز', 'آموزش', 'راهنما', 'راهنمای', 'بهترین', 'مقایسه', 'تفاوت', 'فرق', 'انواع', 'معرفی', 'نکات', 'مزایا', 'معایب', 'خواص', 'مضرات', 'نگهداری', 'تعمیر', 'درمان', 'کدام', 'چرا', 'آیا', 'چند', 'چقدر', 'how', 'what', 'why', 'best' );
}

/**
 * Is a query informational (question / guide intent)?
 */
function sap_is_info_query( $q ) {
	$t = ' ' . sap_norm_text( $q ) . ' ';
	foreach ( sap_info_markers() as $m ) {
		if ( false !== mb_strpos( $t, ' ' . $m . ' ' ) || false !== mb_strpos( $t, ' ' . $m ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Facts for the ideas prompt.
 */
function sap_ideas_input( $project, $count, $source = 'all' ) {
	$pid  = $project['id'];
	$ctx  = sap_analysis_context( $project );
	$map  = $ctx['keymap'];
	$have = array(); // Topic keys already covered by an article, calendar row or idea.

	$articles = array();
	$money    = array();
	foreach ( $map as $k => $m ) {
		if ( '' !== $m['core'] ) {
			$have[ $m['core'] ] = true;
		}
		$row = array( 'url' => $m['url'], 'title' => $m['title'], 'keyword' => $m['primary_kw'] );
		if ( 'article' === $m['page_type'] ) {
			$articles[] = $row['title'] . ( $m['primary_kw'] ? ' — ' . $m['primary_kw'] : '' );
		} elseif ( in_array( $m['page_type'], array( 'category', 'product', 'other', 'page' ), true ) && '' !== $m['primary_kw'] ) {
			$row['impr'] = (int) ( $ctx['page_impr'][ $k ] ?? 0 );
			$money[]     = $row;
		}
	}
	foreach ( (array) ( $ctx['sheet_calendar']['rows'] ?? array() ) as $r ) {
		$have[ sap_kw_core( $r['keyword'] ) ] = true;
		$articles[]                           = $r['keyword'];
	}
	$rejected = array();
	foreach ( sap_ideas( $pid ) as $i ) {
		$have[ sap_kw_core( $i['keyword'] ) ] = true;
		if ( 'rejected' === $i['status'] ) {
			$notes      = sap_comments_for_ai( 'idea', $i['id'] );
			$rejected[] = $i['keyword'] . ( $notes ? ' (نظر متخصص: ' . implode( ' / ', $notes ) . ')' : '' );
		} else {
			$articles[] = $i['keyword'];
		}
	}

	// Informational queries without an owner or beyond page one.
	$queries = array();
	foreach ( $ctx['by_query'] as $rows ) {
		$q    = $rows[0]['query'];
		$impr = array_sum( array_column( $rows, 'impressions' ) );
		$best = min( array_column( $rows, 'position' ) );
		if ( $impr < 20 || sap_is_brand( $q, $project ) || isset( $have[ sap_kw_core( $q ) ] ) ) {
			continue;
		}
		if ( sap_is_info_query( $q ) || ( $best > 10 && ! isset( $ctx['kw_index']['secondary'][ sap_kw_core( $q ) ] ) ) ) {
			$page      = sap_sort( $rows, 'impressions' )[0]['page'];
			$queries[] = array( $q, $impr, round( $best, 1 ), sap_ctx_title( $ctx, $page ) );
		}
	}
	usort( $queries, function ( $a, $b ) {
		return $b[1] <=> $a[1];
	} );

	// Keywords from the rules: unowned, cluster keywords without a page, competitor gaps.
	$signals = array();
	foreach ( sap_findings_active( $pid, 300 ) as $f ) {
		if ( in_array( $f['detector'], array( 'kw_unowned', 'cl_no_page', 'gap', 'cal_todo' ), true ) ) {
			$kws = array( $f['keyword'] );
			foreach ( array_slice( (array) ( $f['evidence']['items'] ?? array() ), 0, 6 ) as $it ) {
				if ( is_array( $it ) && ! empty( $it['kw'] ) ) {
					$kws[] = $it['kw'];
				}
			}
			$signals[] = array( 'rule' => sap_detectors()[ $f['detector'] ][0] ?? $f['detector'], 'keywords' => array_values( array_unique( array_filter( $kws ) ) ) );
		}
	}
	// Vetted candidates (strict rules, see ideas-pool.php): the only keywords ideas may use.
	$pool  = sap_ideas_pool( $project );
	$cands = array();
	foreach ( $pool['blog'] as $t ) {
		if ( 'competitors' === $source && ! in_array( 'competitor', $t['sources'], true ) ) {
			continue;
		}
		$cands[] = array(
			'keyword'   => $t['keyword'],
			'secondary' => array_map( function ( $s ) {
				return $s[0];
			}, $t['secondary'] ),
			'volume'    => $t['volume'] ?: null,
			'position'  => $t['pos'],
			'intent'    => $t['intent'],
			'evidence'  => implode( ' · ', array_slice( $t['evidence'], 0, 2 ) ),
		);
	}
	$questions = array();
	foreach ( (array) ( $ctx['ss_questions']['focuses'] ?? array() ) as $q ) {
		foreach ( array_slice( sap_sort( (array) ( $q['rows'] ?? array() ), 'volume' ), 0, 15 ) as $r ) {
			if ( ! isset( $have[ sap_kw_core( $r['word'] ) ] ) ) {
				$questions[] = array( $r['word'], $r['volume'] );
			}
		}
	}
	usort( $money, function ( $a, $b ) {
		return $b['impr'] <=> $a['impr'];
	} );
	$focuses = array();
	foreach ( $ctx['focuses'] as $f ) {
		$focuses[] = array( 'name' => $f['name'], 'goal' => $f['goal'] );
	}
	return array(
		'site'              => sap_project_domain( $project ),
		'brand_terms'       => $project['brand_terms'],
		'count'             => $count,
		'focuses'           => $focuses,
		'money_pages'       => array_slice( array_map( function ( $m ) {
			unset( $m['impr'] );
			return $m;
		}, $money ), 0, 45 ),
		'existing_articles' => array_slice( array_values( array_unique( array_filter( $articles ) ) ), 0, 120 ),
		'rejected_ideas'    => array_slice( $rejected, 0, 40 ),
		'candidates'        => array_slice( $cands, 0, 50 ),
		'_pool'             => $pool,
	);
}

/**
 * Ask the model for new content ideas and store them as «new».
 *
 * @param string $period When set, the ideas go straight into that calendar month.
 * @return array|WP_Error {added: int, ids: int[]}
 */
function sap_ideas_generate( $project, $count = 10, $period = '', $source = 'all' ) {
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید هوش مصنوعی در تنظیمات وارد نشده است.' );
	}
	$count  = min( 16, max( 3, (int) $count ) );
	$input  = sap_ideas_input( $project, $count, $source );
	$pool   = $input['_pool'];
	unset( $input['_pool'] );
	if ( ! $input['candidates'] && empty( $pool['sources']['gsc'] ) && empty( $pool['sources']['questions'] ) && empty( $pool['sources']['competitor'] ) ) {
		return new WP_Error( 'sap_ideas_pool', 'هنوز داده‌ای برای پیدا کردن کلمات تازه نیست: اول «تحلیل کامل» را بزنید تا کوئری‌های سرچ کنسول دریافت شود (و در صورت تمایل سوالات کاربران یا گپ رقبا از سئو سیگنال).' );
	}
	if ( ! $input['candidates'] ) {
		return new WP_Error( 'sap_ideas_pool', 'competitors' === $source
			? 'از گپ رقبا کلمه‌ای پیدا نشد که هنوز هدف‌گیری نشده باشد و سایت در آن رتبه ۱ تا ۵ نداشته باشد. اول «مقایسه با رقبا» را اجرا کنید یا رقیب دیگری اضافه کنید.'
			: sprintf( 'کلمه تازه‌ای برای مقاله جدید پیدا نشد: %s کلمه را سایت از قبل در جایگاه ۱ تا ۵ دارد، %s کلمه در نقشه کلمات/تقویم هدف‌گیری شده و %s کلمه نیت خرید دارد (فرصت صفحه دسته/محصول در پیشنهادها).', sap_num( $pool['excluded']['ranking'] ), sap_num( $pool['excluded']['covered'] ), sap_num( count( $pool['landing'] ) ) ) );
	}
	$allowed = array();
	foreach ( $input['candidates'] as $c ) {
		$allowed[ sap_kw_core( $c['keyword'] ) ] = true;
		foreach ( $c['secondary'] as $s ) {
			$allowed[ sap_kw_core( $s ) ] = true;
		}
	}
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array( 'ideas' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
		'keyword'     => array( 'type' => 'string', 'description' => 'کلمه کلیدی اصلی مقاله (Main Idea)، همان‌طور که کاربر جستجو می‌کند' ),
		'secondary'   => array( 'type' => 'array', 'items' => $str, 'description' => '۵ تا ۱۰ کلمه فرعی و هم‌کانسپت (نسخه‌های جستجوی همین موضوع)' ),
		'title'       => array( 'type' => 'string', 'description' => 'عنوان جذاب مقاله با کلمه اصلی در ابتدا؛ برای عدد از x استفاده کن، مثل «x دلیل اصلی + راه درمان»' ),
		'intent'      => array( 'type' => 'string', 'enum' => array( 'informational', 'commercial', 'transactional' ) ),
		'goal'        => array( 'type' => 'string', 'description' => 'هدف مقاله: کدام صفحه پولساز یا Focus را تقویت می‌کند، در کدام مرحله قیف و چرا الان' ),
		'evidence'    => array( 'type' => 'string', 'description' => 'استناد به داده: کدام کوئری/سوال/گپ با چه عددی' ),
		'brief'       => array( 'type' => 'string', 'description' => '۲ تا ۳ جمله: چه چیزی و با چه زاویه‌ای پوشش داده شود' ),
		'outline'     => array( 'type' => 'array', 'items' => $str, 'description' => '۴ تا ۷ تیتر H2 پیشنهادی' ),
		'links'       => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'url' => $str, 'anchor' => $str ) ), 'description' => 'دقیقاً ۵ لینک خروجی به money_pages یا مقالات مرتبط با انکر طبیعی (کلمه اصلی صفحه مقصد یا نسخه‌ای از آن)' ),
		'source_note' => array( 'type' => 'string', 'description' => 'منبع یا مرجع پیشنهادی برای نویسنده (عبارت جستجو در یوتیوب/منابع معتبر)' ),
		'cluster'     => array( 'type' => 'string', 'description' => 'نام کلاستر/موضوع کلی' ),
		'priority'    => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
	) ) ) ) );
	$task = implode( "\n", array(
		sprintf( 'تو استراتژیست محتوای سئو برای یک سایت فارسی هستی. %s ایده مقاله جدید برای بلاگ پیشنهاد بده که واقعاً ترافیک و فروش بیاورد.', sap_num( $count ) ),
		'داده‌ها: candidates کلمات کاندیدای بررسی‌شده (از سرچ کنسول، سوالات کاربران و گپ رقبا) با حجم، جایگاه فعلی، نیت و دلیل؛ money_pages صفحات دسته و محصول که بلاگ باید به آن‌ها لینک بدهد و آن‌ها را تقویت کند؛ focuses اهداف فعلی پروژه.',
		'قواعد:',
		'- هر ایده باید به یک داده واقعی استناد کند (evidence) و هدفش (goal) را روشن بگوید: کدام صفحه پولساز یا Focus را تقویت می‌کند.',
		'- موضوعات existing_articles و rejected_ideas را تکرار نکن و ایده‌هایی شبیه به هم (کنیبال) نده؛ هر ایده یک نیت جستجوی مستقل داشته باشد. نظر متخصص در rejected_ideas را رعایت کن.',
		'- کلمه اصلی را به شکلی که کاربر جستجو می‌کند بنویس (نه عنوان)، و کلمات فرعی را از نسخه‌های جستجوی همان موضوع انتخاب کن.',
		'- لینک‌ها فقط از آدرس‌های money_pages (یا مقالات موجود اگر آدرسشان آمده) باشند؛ ترجیحاً صفحاتی که با موضوع مقاله مرتبط‌اند و انکرها متنوع و طبیعی.',
		'- ایده‌های مرتبط با Focusها و صفحات پولساز اولویت بالاتر دارند. ایده عمومی و بی‌ربط به کسب‌وکار نده.',
		'- مهم‌ترین قاعده: کلمه اصلی هر ایده باید دقیقاً از candidates باشد (کاندیداهایی که سیستم بررسی کرده: در نقشه کلمات/تقویم هدف‌گیری نشده‌اند، سایت در آن‌ها رتبه ۱ تا ۵ ندارد و نیتشان اطلاعاتی/مقایسه‌ای است). کلمه اصلی خارج از candidates نساز. کلمات فرعی را از secondary همان کاندیدا و نسخه‌های جستجوی همان موضوع انتخاب کن.',
		'- نیت هر کلمه را جدی بگیر: کلمه با نیت خرید جای مقاله نیست. evidence هر ایده را از evidence کاندیدا بنویس.',
	) );
	$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'analysis', 'max_tokens' => 1000 + 650 * $count, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$ideas = array_values( array_filter( (array) ( $res['ideas'] ?? array() ), function ( $i ) {
		return is_array( $i ) && '' !== trim( (string) ( $i['keyword'] ?? '' ) );
	} ) );

	// Search volume of every keyword (one metered SEO Signal request, if connected).
	$volumes = array();
	if ( sap_ss_connected() && $ideas ) {
		$kws = array();
		foreach ( $ideas as $i ) {
			$kws[] = $i['keyword'];
			foreach ( (array) ( $i['secondary'] ?? array() ) as $s ) {
				$kws[] = (string) $s;
			}
		}
		$v = sap_ss_volumes( $kws );
		if ( ! is_wp_error( $v ) ) {
			$volumes = $v;
		}
	}
	$known = array();
	foreach ( sap_ideas( $project['id'] ) as $i ) {
		$known[ sap_kw_core( $i['keyword'] ) ] = true;
	}
	$valid = array();
	foreach ( sap_keymap( $project['id'] ) as $m ) {
		$valid[ sap_norm_url( $m['url'] ) ] = $m['url'];
	}
	$focus_ctx = sap_focus_context( $project['id'] );
	$ids       = array();
	$slot      = count( sap_ideas( $project['id'], array( 'period' => $period, 'status' => 'scheduled' ) ) );
	$dropped = 0;
	foreach ( $ideas as $i ) {
		$core = sap_kw_core( $i['keyword'] );
		if ( isset( $known[ $core ] ) ) {
			continue;
		}
		if ( ! isset( $allowed[ $core ] ) && ! sap_pool_covered( $i['keyword'], array_fill_keys( array_keys( $allowed ), array() ) ) ) {
			++$dropped; // The model invented a keyword outside the vetted candidates.
			continue;
		}
		$known[ $core ] = true;
		$sec            = array();
		foreach ( (array) ( $i['secondary'] ?? array() ) as $s ) {
			$s = trim( (string) $s );
			if ( '' !== $s && sap_kw_key( $s ) !== sap_kw_key( $i['keyword'] ) ) {
				$sec[] = array( $s, (int) ( $volumes[ sap_kw_key( $s ) ]['volume'] ?? 0 ) );
			}
		}
		$links = array();
		foreach ( (array) ( $i['links'] ?? array() ) as $l ) {
			$u = sap_full_url( $project, (string) ( $l['url'] ?? '' ) );
			if ( '' !== $u && '' !== trim( (string) ( $l['anchor'] ?? '' ) ) && count( $links ) < 5 ) {
				$links[] = array( 'url' => $valid[ sap_norm_url( $u ) ] ?? $u, 'anchor' => trim( (string) $l['anchor'] ) );
			}
		}
		$links        = sap_idea_fill_links( $project, $i['keyword'], $links );
		$vol          = (int) ( $volumes[ sap_kw_key( $i['keyword'] ) ]['volume'] ?? 0 );
		list( $fid )  = sap_focus_match( $focus_ctx, '', $i['keyword'] );
		$ids[]        = sap_idea_save( array(
			'project_id'  => $project['id'],
			'focus_id'    => $fid,
			'keyword'     => $i['keyword'],
			'secondary'   => $sec,
			'title'       => (string) ( $i['title'] ?? '' ),
			'intent'      => (string) ( $i['intent'] ?? 'informational' ),
			'goal'        => (string) ( $i['goal'] ?? '' ),
			'brief'       => (string) ( $i['brief'] ?? '' ),
			'outline'     => array_values( array_map( 'strval', array_filter( (array) ( $i['outline'] ?? array() ), 'is_scalar' ) ) ),
			'links'       => $links,
			'source_note' => (string) ( $i['source_note'] ?? '' ),
			'cluster'     => (string) ( $i['cluster'] ?? '' ),
			'priority'    => in_array( $i['priority'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $i['priority'] : 'medium',
			'evidence'    => array( (string) ( $i['evidence'] ?? '' ) ),
			'volume'      => $vol,
			'kp'          => $vol,
			'origin'      => 'ai',
			'status'      => $period ? 'scheduled' : 'new',
			'period'      => $period,
			'slot'        => $period ? ++$slot : 0,
		) );
	}
	if ( $period ) {
		sap_calendar_spread_dates( $project['id'], $period );
	}
	return array( 'added' => count( $ids ), 'ids' => $ids, 'partial' => ! empty( $res['_partial'] ), 'dropped' => $dropped, 'pool' => array( 'blog' => count( $pool['blog'] ), 'landing' => count( $pool['landing'] ), 'optimize' => count( $pool['optimize'] ), 'excluded' => $pool['excluded'] ) );
}

/**
 * Complete the five out links of an idea from the keyword map: pages whose
 * keyword shares words with the idea, money pages first.
 */
function sap_idea_fill_links( $project, $keyword, $links ) {
	if ( count( $links ) >= 5 ) {
		return array_slice( $links, 0, 5 );
	}
	$have  = array();
	foreach ( $links as $l ) {
		$have[ sap_norm_url( $l['url'] ) ] = true;
	}
	$words = array_flip( sap_tokens( $keyword ) );
	$cands = array();
	foreach ( sap_keymap( $project['id'] ) as $k => $m ) {
		if ( isset( $have[ $k ] ) || '' === $m['primary_kw'] || 'home' === $m['page_type'] ) {
			continue;
		}
		$hit = count( array_intersect_key( array_flip( sap_tokens( $m['primary_kw'] . ' ' . $m['title'] ) ), $words ) );
		if ( $hit ) {
			$cands[] = array( $hit + ( in_array( $m['page_type'], array( 'category', 'product' ), true ) ? 0.5 : 0 ), $m );
		}
	}
	usort( $cands, function ( $a, $b ) {
		return $b[0] <=> $a[0];
	} );
	foreach ( $cands as $c ) {
		if ( count( $links ) >= 5 ) {
			break;
		}
		$m       = $c[1];
		$anchors = array_merge( array( $m['primary_kw'] ), $m['secondary'] );
		$links[] = array( 'url' => $m['url'], 'anchor' => $anchors[ count( $links ) % count( $anchors ) ] );
	}
	return $links;
}
