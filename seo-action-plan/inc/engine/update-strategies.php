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

/* ---------------------------------------------------------------------------
 * Page universe, keyword ownership and query fit
 * ------------------------------------------------------------------------- */

/**
 * Page types the update tab works with.
 */
function sap_upd_types() {
	return array( 'cat' => 'دسته‌بندی‌ها', 'product' => 'محصولات', 'post' => 'مقالات' );
}

/**
 * Pages that can be updated: categories / brands, products and articles (no
 * home, contact, about, cart … pages), with their keyword-map keyword and focus.
 *
 * @return array normalized url => {url, title, type, ctype, kw, secondary, focus}
 */
function sap_upd_universe( $project ) {
	$pid   = $project['id'];
	$inv   = sap_site_inventory( $pid );
	$map   = sap_keymap( $pid );
	$fpage = array();
	foreach ( sap_focuses( $pid, true ) as $f ) {
		foreach ( sap_focus_page_set( $pid, $f ) as $k => $u ) {
			$fpage[ $k ] = $f['name'];
		}
	}
	$type_of = array( 'product_cat' => 'cat', 'brand' => 'cat', 'product_tag' => 'cat', 'product' => 'product', 'post' => 'post' );
	$out     = array();
	foreach ( $inv as $k => $it ) {
		$t = $type_of[ $it['ctype'] ] ?? '';
		if ( ! $t || '' !== (string) $it['redirect'] || $it['noindex'] || sap_upd_is_junk( $it['url'] ) ) {
			continue;
		}
		$m         = $map[ $k ] ?? null;
		$out[ $k ] = array( 'url' => $it['url'], 'title' => $it['title'], 'type' => $t, 'ctype' => $it['ctype'], 'kw' => $m ? (string) $m['primary_kw'] : (string) ( $it['seo']['focus_keyword'] ?? '' ), 'secondary' => $m ? (array) $m['secondary'] : array(), 'focus' => $fpage[ $k ] ?? '', 'words' => (int) $it['words'] );
	}
	if ( ! $inv ) {
		// No WordPress list: the keyword map's pages.
		$mt = array( 'category' => 'cat', 'product' => 'product', 'article' => 'post' );
		foreach ( $map as $k => $m ) {
			$t = $mt[ $m['page_type'] ] ?? '';
			if ( $t && ! sap_upd_is_junk( $m['url'] ) && ! in_array( $m['status'], array( 'prune_410', 'prune_301', 'noindex' ), true ) ) {
				$out[ $k ] = array( 'url' => $m['url'], 'title' => $m['title'] ?: sap_short_url( $m['url'], 60 ), 'type' => $t, 'ctype' => $m['page_type'], 'kw' => (string) $m['primary_kw'], 'secondary' => (array) $m['secondary'], 'focus' => $fpage[ $k ] ?? '', 'words' => 0 );
			}
		}
	}
	return $out;
}

/**
 * Which page owns a keyword in the keyword map (primary first, then secondary).
 *
 * @return array kw key => normalized url
 */
function sap_upd_owners( $project_id ) {
	$own = array();
	foreach ( sap_keymap( $project_id ) as $k => $m ) {
		foreach ( (array) $m['secondary'] as $s ) {
			$own[ sap_kw_key( $s ) ] = $own[ sap_kw_key( $s ) ] ?? $k;
		}
	}
	foreach ( sap_keymap( $project_id ) as $k => $m ) {
		if ( '' !== (string) $m['primary_kw'] ) {
			$own[ sap_kw_key( $m['primary_kw'] ) ] = $k; // Primary wins.
		}
	}
	unset( $own[''] );
	return $own;
}

/**
 * Brand query? The project's brand terms plus its name and domain.
 */
function sap_upd_is_brand( $q, $project ) {
	static $terms = array();
	$pid = $project['id'];
	if ( ! isset( $terms[ $pid ] ) ) {
		$t   = (array) $project['brand_terms'];
		$t[] = $project['name'];
		$t[] = preg_replace( '/\.[a-z.]+$/', '', sap_project_domain( $project ) );
		$terms[ $pid ] = array_values( array_filter( array_unique( array_map( 'sap_kw_key', $t ) ), function ( $x ) {
			return mb_strlen( $x ) >= 4;
		} ) );
	}
	$qk = sap_kw_key( $q );
	foreach ( $terms[ $pid ] as $b ) {
		if ( false !== mb_strpos( $qk, $b ) || false !== mb_strpos( $b, $qk ) && mb_strlen( $qk ) >= 4 ) {
			return true;
		}
	}
	return false;
}

/**
 * How a query relates to a page: «own» (its keyword-map keyword), «fit» (free and
 * matches the page's intent), «other» (another page owns it), «brand», «info»
 * (informational on a shop page → an article) or «buy» (a buying search on an
 * article → the category).
 */
function sap_upd_fit( $q, $k, $page, $owners, $project ) {
	if ( sap_upd_is_brand( $q, $project ) ) {
		return array( 'brand', '' );
	}
	$owner = $owners[ sap_kw_key( $q ) ] ?? '';
	if ( $owner ) {
		return $owner === $k ? array( 'own', '' ) : array( 'other', $owner );
	}
	$intent = sap_kw_intent( $q );
	if ( in_array( $page['type'], array( 'cat', 'product' ), true ) && 'informational' === $intent ) {
		return array( 'info', '' );
	}
	if ( 'post' === $page['type'] && 'transactional' === $intent ) {
		return array( 'buy', '' );
	}
	return array( 'fit', '' );
}

/* ---------------------------------------------------------------------------
 * Analysis
 * ------------------------------------------------------------------------- */

/**
 * Pull the data the strategies need and build one card per page.
 *
 * @return array|WP_Error {counts, excluded, notes}
 */
function sap_upd_refresh( $project ) {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore
	}
	$pid   = $project['id'];
	$live  = '' !== (string) $project['gsc_property'] && sap_google_connected();
	$notes = array();
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
	$uni    = sap_upd_universe( $project );
	$owners = sap_upd_owners( $pid );
	$qp     = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
		$qp[ sap_norm_url( $r['page'] ) ][] = $r;
	}
	$g28 = sap_dataset( $pid, 'gsc_pages' );
	$g90 = sap_dataset( $pid, 'gsc_pages_90' );
	$pg  = array();
	foreach ( array( 'cur', 'prev' ) as $w ) {
		foreach ( (array) ( $g28['payload'][ $w ] ?? array() ) as $r ) {
			$pg[ sap_norm_url( $r['page'] ) ][ $w ] = $r;
		}
		foreach ( (array) ( $g90['payload'][ $w ] ?? array() ) as $r ) {
			$pg[ sap_norm_url( $r['page'] ) ][ $w . '90' ] = $r;
		}
	}
	$excluded = array( 'brand' => 0, 'other' => 0, 'pages' => 0 );
	foreach ( $pg as $k => $x ) {
		if ( ! isset( $uni[ $k ] ) && (int) ( $x['cur']['impressions'] ?? 0 ) >= 50 ) {
			++$excluded['pages']; // Home, contact, about, cart …: not update candidates.
		}
	}
	// Read the text of the most visible pages (for «in the text?» checks).
	$budget = 20;
	uksort( $uni, function ( $a, $b ) use ( $pg ) {
		return ( (int) ( $pg[ $b ]['cur']['impressions'] ?? 0 ) ) <=> ( (int) ( $pg[ $a ]['cur']['impressions'] ?? 0 ) );
	} );
	$weight = array( 'cat' => 1.3, 'product' => 1.15, 'post' => 1.0 );
	$cards  = array();
	foreach ( $uni as $k => $page ) {
		$rows = (array) ( $qp[ $k ] ?? array() );
		$x    = $pg[ $k ] ?? array();
		$card = array( 'url' => $page['url'], 'title' => $page['title'], 'type' => $page['type'], 'kw' => $page['kw'], 'focus' => $page['focus'], 's' => array(), 'queries' => array(), 'todo' => array(), 'goal' => '', 'why' => '', 'potential' => 0 );
		if ( $rows ) {
			$text = $budget > 0 || isset( $pg[ $k ] ) ? sap_upd_page( $pid, $page['url'], $budget ) : null;
			$w_t  = sap_signals_words( $text['title'] ?? $page['title'] );
			$w_hd = $text ? sap_signals_words( implode( ' ', array_merge( (array) $text['h1'], (array) $text['h2'], (array) ( $text['h3'] ?? array() ) ) ) ) : array();
			$w_tx = $text ? sap_signals_words( $text['text'] . ' ' . implode( ' ', array_merge( (array) $text['h1'], (array) $text['h2'] ) ) ) : array();
			foreach ( sap_sort( $rows, 'impressions' ) as $r ) {
				list( $fit, $owner ) = sap_upd_fit( $r['query'], $k, $page, $owners, $project );
				if ( 'brand' === $fit ) {
					++$excluded['brand'];
				} elseif ( 'other' === $fit ) {
					++$excluded['other'];
				}
				$impr = (int) $r['impressions'];
				$ctr  = $impr ? $r['clicks'] / $impr : 0;
				$card['queries'][] = array(
					'q'        => $r['query'],
					'impr'     => $impr,
					'clicks'   => (int) $r['clicks'],
					'ctr'      => round( 100 * $ctr, 1 ),
					'pos'      => round( (float) $r['position'], 1 ),
					'fit'      => $fit,
					'owner'    => $owner,
					'in_title' => (bool) sap_signals_covers( $w_t, $r['query'] ),
					'in_head'  => $w_hd ? (bool) sap_signals_covers( $w_hd, $r['query'] ) : null,
					'in_text'  => $w_tx ? (bool) sap_signals_covers( $w_tx, $r['query'] ) : null,
				);
			}
			$card['queries'] = array_slice( $card['queries'], 0, 20 );
			$card['read']    = (bool) $text;
		}
		$fitq = array_values( array_filter( $card['queries'], function ( $q ) {
			return in_array( $q['fit'], array( 'own', 'fit' ), true );
		} ) );
		$fi   = array_sum( array_column( $fitq, 'impr' ) );
		$fc   = array_sum( array_column( $fitq, 'clicks' ) );
		// s1: the page's own searches are seen but not clicked.
		$low = array();
		foreach ( $fitq as $q ) {
			$exp = sap_ctr_expected( $q['pos'] );
			$tgt = $q['pos'] <= 5 ? max( 0.05, $exp ) : $exp;
			if ( $q['pos'] <= 10 && $q['impr'] >= 30 && $q['ctr'] / 100 < 0.7 * $tgt ) {
				$low[] = $q + array( 'gain' => (int) round( $q['impr'] * ( $tgt - $q['ctr'] / 100 ) ), 'tgt' => round( 100 * $tgt ) );
			}
		}
		if ( $fi >= 100 && $low ) {
			usort( $low, function ( $a, $b ) {
				return $b['gain'] <=> $a['gain'];
			} );
			$gain = array_sum( array_column( $low, 'gain' ) );
			if ( $gain >= 5 ) {
				$top = $low[0];
				$card['s']['s1'] = array( 'pot' => $gain, 'why' => sprintf( 'جستجوهای مرتبط با این صفحه %s نمایش در ماه دارند ولی کلیک کمی می‌گیرند؛ «%s» در جایگاه %s با CTR %s٪ (معمول این جایگاه حدود %s٪).', sap_num( $fi ), $top['q'], sap_num( $top['pos'], 1 ), sap_num( $top['ctr'], 1 ), sap_num( $top['tgt'] ) ), 'items' => array_slice( $low, 0, 4 ) );
			}
		}
		// s2: own searches on page two or three.
		$edge = array();
		foreach ( $fitq as $q ) {
			if ( $q['pos'] > 10 && $q['pos'] <= 30 && $q['impr'] >= 30 ) {
				$edge[] = $q + array( 'gain' => (int) max( 0, round( $q['impr'] * sap_ctr_expected( 7 ) - $q['clicks'] ) ) );
			}
		}
		if ( $edge ) {
			usort( $edge, function ( $a, $b ) {
				return $b['gain'] <=> $a['gain'];
			} );
			$gain = array_sum( array_column( $edge, 'gain' ) );
			if ( $gain >= 5 ) {
				$card['s']['s2'] = array( 'pot' => $gain, 'why' => sprintf( '%s جستجوی مرتبط با این صفحه در صفحه دوم و سوم گوگل است؛ «%s» در جایگاه %s با %s نمایش.', sap_num( count( $edge ) ), $edge[0]['q'], sap_num( $edge[0]['pos'], 1 ), sap_num( $edge[0]['impr'] ) ), 'items' => array_slice( $edge, 0, 4 ) );
			}
		}
		// s3: own / fitting searches the page does not answer; others' keywords → links; wrong intent → article.
		$gaps  = array_values( array_filter( $fitq, function ( $q ) {
			return $q['impr'] >= 20 && ( false === $q['in_text'] || ( $q['pos'] > 3 && false === $q['in_head'] ) );
		} ) );
		$links = array_values( array_filter( $card['queries'], function ( $q ) {
			return 'other' === $q['fit'] && $q['impr'] >= 30;
		} ) );
		$info  = array_values( array_filter( $card['queries'], function ( $q ) {
			return in_array( $q['fit'], array( 'info', 'buy' ), true ) && $q['impr'] >= 50;
		} ) );
		if ( $gaps || $links || $info ) {
			$card['s']['s3'] = array( 'pot' => (int) round( array_sum( array_column( $gaps, 'impr' ) ) * 0.05 + array_sum( array_column( $links, 'impr' ) ) * 0.01 ), 'why' => trim( ( $gaps ? sprintf( '%s جستجوی مرتبط با این صفحه (%s نمایش) جوابی در صفحه ندارد. ', sap_num( count( $gaps ) ), sap_num( array_sum( array_column( $gaps, 'impr' ) ) ) ) : '' ) . ( $links ? sprintf( '%s جستجو مال صفحه دیگری است و باید با لینک داخلی به آن برود.', sap_num( count( $links ) ) ) : '' ) ), 'items' => array_slice( $gaps, 0, 5 ), 'links' => array_slice( $links, 0, 4 ), 'info' => array_slice( $info, 0, 3 ) );
		}
		// s4: decay (90 days when available, else 28).
		$pc   = $x['prev90'] ?? null;
		$cc   = $x['cur90'] ?? null;
		$span = '۹۰';
		if ( ! $pc || ! $cc ) {
			$pc   = $x['prev'] ?? null;
			$cc   = $x['cur'] ?? null;
			$span = '۲۸';
		}
		if ( $pc && $cc ) {
			$lost_c = (int) $pc['clicks'] - (int) $cc['clicks'];
			$lost_i = (int) $pc['impressions'] - (int) $cc['impressions'];
			$drop_c = $pc['clicks'] ? $lost_c / $pc['clicks'] : 0;
			$drop_i = $pc['impressions'] ? $lost_i / $pc['impressions'] : 0;
			if ( ( $drop_c >= 0.2 && $lost_c >= 10 ) || ( $drop_i >= 0.3 && $lost_i >= 300 ) ) {
				$card['s']['s4'] = array( 'pot' => (int) round( max( 0, $lost_c ) + max( 0, $lost_i ) * 0.02 ), 'why' => sprintf( 'در %s روز اخیر نسبت به %s روز قبل: کلیک %s → %s، نمایش %s → %s.', $span, $span, sap_num( $pc['clicks'] ), sap_num( $cc['clicks'] ), sap_num( $pc['impressions'] ), sap_num( $cc['impressions'] ) ), 'was' => (int) $pc['clicks'], 'is' => (int) $cc['clicks'], 'items' => array(), 'span' => $span );
			}
		}
		$card['fit_impr']   = $fi;
		$card['fit_clicks'] = $fc;
		if ( $card['s'] ) {
			$cards[ $k ] = $card;
		}
	}
	// s4: the queries that dropped (only the page's own / fitting ones count).
	$dec = array_filter( $cards, function ( $c ) {
		return isset( $c['s']['s4'] );
	} );
	uasort( $dec, function ( $a, $b ) {
		return $b['s']['s4']['pot'] <=> $a['s']['s4']['pot'];
	} );
	if ( $live ) {
		$win = $g90 ? $g90['meta'] : $g28['meta'];
		foreach ( array_slice( array_keys( $dec ), 0, 10 ) as $k ) {
			$flt  = array( 'dimensionFilterGroups' => array( array( 'filters' => array( array( 'dimension' => 'page', 'operator' => 'equals', 'expression' => $cards[ $k ]['url'] ) ) ) ) );
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
				list( $fit ) = sap_upd_fit( $r['query'], $k, $uni[ $k ], $owners, $project );
				$was         = (int) $r['impressions'];
				$is          = (int) ( $n[ sap_kw_key( $r['query'] ) ]['impressions'] ?? 0 );
				if ( in_array( $fit, array( 'own', 'fit' ), true ) && $was - $is >= 20 ) {
					$drops[] = array( 'q' => $r['query'], 'was' => $was, 'is' => $is, 'pos_was' => round( $r['position'], 1 ), 'pos_is' => isset( $n[ sap_kw_key( $r['query'] ) ] ) ? round( $n[ sap_kw_key( $r['query'] ) ]['position'], 1 ) : null );
				}
			}
			usort( $drops, function ( $a, $b ) {
				return ( $b['was'] - $b['is'] ) <=> ( $a['was'] - $a['is'] );
			} );
			if ( ! $drops ) {
				unset( $cards[ $k ]['s']['s4'] ); // Only brand / other pages' searches fell: not this page's update.
				if ( ! $cards[ $k ]['s'] ) {
					unset( $cards[ $k ] );
				}
				continue;
			}
			$cards[ $k ]['s']['s4']['items'] = array_slice( $drops, 0, 5 );
		}
	}
	// s5: important pages Google does not show.
	$seen = array();
	foreach ( $pg as $k => $x ) {
		if ( (int) ( $x['cur']['impressions'] ?? 0 ) + (int) ( $x['cur90']['impressions'] ?? 0 ) > 0 ) {
			$seen[ $k ] = true;
		}
	}
	$insp = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_inspect' )['rows'] ?? array() ) as $r ) {
		$insp[ sap_norm_url( $r['url'] ) ] = $r;
	}
	$cached = get_option( 'sap_upd_inspect_' . $pid, array() );
	$cached = is_array( $cached ) ? $cached : array();
	$insp  += $cached;
	$cand5  = array();
	foreach ( $uni as $k => $page ) {
		if ( isset( $seen[ $k ] ) && ! isset( $insp[ $k ] ) ) {
			continue;
		}
		$cov    = (string) ( $insp[ $k ]['coverage'] ?? '' );
		$not_ix = '' !== $cov && ( false !== stripos( $cov, 'not indexed' ) || false !== stripos( $cov, 'نشده' ) || in_array( $insp[ $k ]['verdict'] ?? '', array( 'NEUTRAL', 'FAIL' ), true ) );
		if ( ( '' !== $cov && ! $not_ix ) || ( isset( $seen[ $k ] ) && ! $not_ix ) ) {
			continue;
		}
		$cand5[ $k ] = array( 'cov' => $cov, 'pot' => array( 'cat' => 50, 'product' => 35, 'post' => 20 )[ $page['type'] ] + ( $page['words'] && $page['words'] < 300 ? 10 : 0 ) + ( $not_ix ? 25 : 0 ) + ( $page['focus'] ? 15 : 0 ) );
	}
	uasort( $cand5, function ( $a, $b ) {
		return $b['pot'] <=> $a['pot'];
	} );
	$checked = 0;
	foreach ( $cand5 as $k => $c5 ) {
		if ( $live && '' === $c5['cov'] && $checked < 12 ) {
			++$checked;
			$res = sap_google_request( 'POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', array( 'inspectionUrl' => $uni[ $k ]['url'], 'siteUrl' => $project['gsc_property'], 'languageCode' => 'fa' ), 40 );
			if ( is_wp_error( $res ) ) {
				$notes[] = 'بررسی ایندکس: ' . $res->get_error_message();
				$live    = false;
			} else {
				$i            = (array) ( $res['inspectionResult']['indexStatusResult'] ?? array() );
				$c5['cov']    = (string) ( $i['coverageState'] ?? '' );
				$cached[ $k ] = array( 'coverage' => $c5['cov'], 'verdict' => (string) ( $i['verdict'] ?? '' ), 'at' => sap_now() );
				if ( 'PASS' === ( $i['verdict'] ?? '' ) ) {
					continue; // Indexed after all.
				}
				$c5['pot'] += 25;
			}
		}
		$page        = $uni[ $k ];
		$cards[ $k ] = ( $cards[ $k ] ?? array( 'url' => $page['url'], 'title' => $page['title'], 'type' => $page['type'], 'kw' => $page['kw'], 'focus' => $page['focus'], 's' => array(), 'queries' => array(), 'fit_impr' => 0, 'fit_clicks' => 0 ) );
		$cards[ $k ]['s']['s5'] = array( 'pot' => $c5['pot'], 'why' => '' !== $c5['cov'] ? 'وضعیت در سرچ کنسول: ' . $c5['cov'] . '.' : 'در ۹۰ روز گذشته هیچ نمایشی در گوگل نداشته؛ احتمالاً ایندکس نشده.', 'items' => array(), 'cov' => $c5['cov'] );
	}
	update_option( 'sap_upd_inspect_' . $pid, $cached, false );
	// Value: clicks the work can bring × kind of page (sales pages first) × focus.
	foreach ( $cards as $k => &$c ) {
		$pots          = array_column( $c['s'], 'pot' );
		$c['potential'] = (int) ( max( $pots ) + 0.3 * ( array_sum( $pots ) - max( $pots ) ) );
		$c['value']    = $c['potential'] * $weight[ $c['type'] ] * ( $c['focus'] ? 1.3 : 1 );
		$c['main']     = array_key_first( $c['s'] );
		$best          = 0;
		foreach ( $c['s'] as $sk => $sv ) {
			if ( $sv['pot'] >= $best ) {
				$best      = $sv['pot'];
				$c['main'] = $sk;
			}
		}
		$plan          = sap_upd_plan_text( $c );
		$c['todo']     = $plan['todo'];
		$c['goal']     = $plan['goal'];
		$c['why']      = $c['s'][ $c['main'] ]['why'];
		$c['secondary'] = $plan['secondary'];
	}
	unset( $c );
	uasort( $cards, function ( $a, $b ) {
		return $b['value'] <=> $a['value'];
	} );
	$max = max( array_merge( array( 1 ), array_column( $cards, 'value' ) ) );
	foreach ( $cards as &$c ) {
		$c['score'] = (int) round( 100 * sqrt( $c['value'] / $max ) );
	}
	unset( $c );
	$counts = array( 'cat' => 0, 'product' => 0, 'post' => 0 );
	foreach ( $cards as $c ) {
		++$counts[ $c['type'] ];
	}
	sap_dataset_put( $pid, 'upd_pages', array( 'cards' => $cards ), array( 'notes' => $notes, 'excluded' => $excluded, 'w90' => (bool) $g90, 'counts' => $counts ) );
	return array( 'counts' => $counts, 'excluded' => $excluded, 'notes' => $notes );
}

/**
 * The plain plan of a card: numbered steps, the goal, secondary keywords.
 *
 * @return array {todo[], goal, secondary[]}
 */
function sap_upd_plan_text( $c ) {
	$todo = array();
	$goal = '';
	$s    = $c['s'];
	$h    = function ( $q ) {
		return preg_match( '/قیمت|هزینه|چند\s/u', $q ) ? 'بخش «عوامل مؤثر بر ' . preg_replace( '/^(قیمت|هزینه)\s*/u', 'قیمت ', $q ) . '» (بدون عدد قیمت؛ قیمت از خود فروشگاه نمایش داده می‌شود)' : 'بخش «' . $q . '» با پاسخ مستقیم';
	};
	if ( isset( $s['s1'] ) ) {
		$q      = $s['s1']['items'][0];
		$todo[] = $q['in_title']
			? sprintf( 'تایتل و متادیسکریپشن را برای «%s» جذاب‌تر کنید (مزیت، دلیل خرید، دعوت به کلیک): در جایگاه %s است ولی CTR آن %s٪ است.', $q['q'], sap_num( $q['pos'], 1 ), sap_num( $q['ctr'], 1 ) )
			: sprintf( 'تایتل و متادیسکریپشن را با «%s» بنویسید؛ الان در تایتل نیست (%s نمایش، CTR %s٪ در جایگاه %s).', $q['q'], sap_num( $q['impr'] ), sap_num( $q['ctr'], 1 ), sap_num( $q['pos'], 1 ) );
		if ( false === $q['in_text'] ) {
			$todo[] = sprintf( '«%s» را در H1 یا مقدمه بیاورید؛ الان در متن صفحه نیست.', $q['q'] );
		}
		foreach ( array_slice( $s['s1']['items'], 1, 2 ) as $q2 ) {
			$todo[] = sprintf( '«%s» (%s نمایش، CTR %s٪) را هم در متا یا یک تیتر پوشش دهید.', $q2['q'], sap_num( $q2['impr'] ), sap_num( $q2['ctr'], 1 ) );
		}
		$ci   = array_sum( array_column( $s['s1']['items'], 'impr' ) );
		$cc   = array_sum( array_column( $s['s1']['items'], 'clicks' ) );
		$goal = sprintf( 'CTR این کلمات از %s٪ به حدود %s٪ برسد (+%s کلیک در ماه).', sap_num( $ci ? round( 100 * $cc / $ci, 1 ) : 0, 1 ), sap_num( $s['s1']['items'][0]['tgt'] ), sap_num( $s['s1']['pot'] ) );
	}
	if ( isset( $s['s2'] ) ) {
		foreach ( array_slice( $s['s2']['items'], 0, 3 ) as $q ) {
			$todo[] = false === $q['in_text']
				? sprintf( '%s اضافه کنید (H2/H3) — «%s» در جایگاه %s با %s نمایش است و در متن نیامده.', $h( $q['q'] ), $q['q'], sap_num( $q['pos'], 1 ), sap_num( $q['impr'] ) )
				: ( false === $q['in_head']
					? sprintf( 'برای «%s» یک تیتر H3 بگذارید و آن بخش را کامل‌تر کنید (جایگاه %s).', $q['q'], sap_num( $q['pos'], 1 ) )
					: sprintf( 'بخش «%s» را عمیق‌تر کنید: نمونه، جدول مقایسه، تصویر و ۲ تا ۳ لینک داخلی به این صفحه (جایگاه %s).', $q['q'], sap_num( $q['pos'], 1 ) ) );
		}
		$goal = $goal ?: sprintf( '«%s» از جایگاه %s به صفحه اول برسد (+%s کلیک در ماه).', $s['s2']['items'][0]['q'], sap_num( $s['s2']['items'][0]['pos'], 1 ), sap_num( $s['s2']['pot'] ) );
	}
	if ( isset( $s['s3'] ) ) {
		foreach ( array_slice( $s['s3']['items'], 0, 3 ) as $q ) {
			if ( isset( $s['s2'] ) && in_array( $q['q'], array_column( $s['s2']['items'], 'q' ), true ) ) {
				continue;
			}
			$todo[] = sprintf( '%s اضافه کنید — کاربران «%s» را جستجو می‌کنند (%s نمایش) و صفحه جوابی برایش ندارد.', $h( $q['q'] ), $q['q'], sap_num( $q['impr'] ) );
		}
		foreach ( array_slice( (array) $s['s3']['links'], 0, 2 ) as $q ) {
			$todo[] = sprintf( 'از این صفحه با انکر «%s» به صفحه «%s» لینک بدهید؛ این کلمه در نقشه کلمات مال آن صفحه است (روی آن هدف نگیرید).', $q['q'], sap_upd_title_by_key( $q['owner'] ) );
		}
		foreach ( array_slice( (array) $s['s3']['info'], 0, 1 ) as $q ) {
			$todo[] = 'info' === $q['fit']
				? sprintf( '«%s» سوال اطلاعاتی است؛ جایش مقاله جداست — در ایده‌های محتوا ثبت کنید و از مقاله به همین صفحه لینک دهید.', $q['q'] )
				: sprintf( '«%s» جستجوی خرید است؛ از این مقاله با همین انکر به دسته مربوط لینک و CTA بگذارید.', $q['q'] );
		}
		$goal = $goal ?: ( $s['s3']['items']
			? 'صفحه به جستجوهای مرتبطی که الان جوابی ندارند پاسخ بدهد و جایگاه آن‌ها بهتر شود.'
			: 'جستجوهایی که مال صفحات دیگرند با لینک داخلی به صاحبشان برسند (رتبه آن صفحات تقویت شود و کنیبال پیش نیاید).' );
	}
	if ( isset( $s['s4'] ) ) {
		foreach ( array_slice( $s['s4']['items'], 0, 2 ) as $d ) {
			$todo[] = sprintf( '«%s» از %s به %s نمایش افت کرده؛ این کلمه را در تیترها و متن تقویت و اطلاعات قدیمی را به‌روز کنید.', $d['q'], sap_num( $d['was'] ), sap_num( $d['is'] ) );
		}
		if ( empty( $s['s4']['items'] ) ) {
			$todo[] = 'کوئری‌های افت‌کرده را در سرچ کنسول (مقایسه دو دوره) ببینید و همان‌ها را در تیترها و متن تقویت کنید.';
		}
		$todo[] = 'تصویر یا ویدیو و بخش سوالات متداول اضافه کنید؛ اگر محتوا قدیمی است، بازنویسی کامل.';
		$goal   = $goal ?: sprintf( 'کلیک به سطح قبل از افت برگردد (%s در ماه؛ الان %s).', sap_num( $s['s4']['was'] ), sap_num( $s['s4']['is'] ) );
	}
	if ( isset( $s['s5'] ) ) {
		$todo   = array_merge( $todo, array( 'تایتل، متا و مقدمه را با کلمه اصلی صفحه بازنویسی و متن را کامل‌تر کنید (FAQ و جمع‌بندی).', 'از ۳ صفحه مرتبط پربازدید به این صفحه لینک داخلی بدهید.', 'در سرچ کنسول برای این صفحه درخواست ایندکس ثبت کنید.' ) );
		$goal   = $goal ?: 'صفحه ایندکس شود و اولین نمایش‌ها را بگیرد.';
	}
	$sec = array();
	foreach ( $c['queries'] as $q ) {
		if ( in_array( $q['fit'], array( 'own', 'fit' ), true ) && sap_kw_key( $q['q'] ) !== sap_kw_key( $c['kw'] ) && count( $sec ) < 6 ) {
			$sec[] = $q['q'];
		}
	}
	return array( 'todo' => array_values( array_unique( $todo ) ), 'goal' => $goal, 'secondary' => $sec );
}

/**
 * Title of a page by its normalized URL key.
 */
function sap_upd_title_by_key( $k ) {
	$pid = sap_period_project();
	$it  = $pid ? ( sap_site_inventory( $pid )[ $k ] ?? null ) : null;
	if ( $it ) {
		return $it['title'];
	}
	$m = $pid ? ( sap_keymap( $pid )[ $k ] ?? null ) : null;
	return $m && $m['title'] ? $m['title'] : $k;
}

/**
 * Cards of the last analysis.
 */
function sap_upd_cards( $project_id ) {
	return (array) ( sap_dataset_payload( $project_id, 'upd_pages' )['cards'] ?? array() );
}

/**
 * Approve a page for update in a month: keyword, secondary, goal and the plan
 * (editable in the form) go into the content calendar's updates.
 *
 * @return int Update id.
 */
function sap_upd_approve( $project, $url, $period, $fields = array() ) {
	$k    = sap_norm_url( $url );
	$c    = sap_upd_cards( $project['id'] )[ $k ] ?? null;
	$defs = sap_upd_strategies();
	$kw   = trim( (string) ( $fields['keyword'] ?? ( $c['kw'] ?? '' ) ) );
	$sec  = isset( $fields['secondary'] ) ? array_values( array_filter( array_map( 'trim', preg_split( '/[،,\n]+/u', (string) $fields['secondary'] ) ) ) ) : (array) ( $c['secondary'] ?? array() );
	$goal = trim( (string) ( $fields['goal'] ?? ( $c['goal'] ?? '' ) ) );
	$todo = trim( (string) ( $fields['todo'] ?? implode( "\n", array_map( function ( $t, $i ) {
		return sap_fa( $i + 1 ) . '. ' . $t;
	}, (array) ( $c['todo'] ?? array() ), array_keys( (array) ( $c['todo'] ?? array() ) ) ) ) ) );
	$strs = $c ? implode( '، ', array_map( function ( $s ) use ( $defs ) {
		return $defs[ $s ]['name'];
	}, array_keys( $c['s'] ) ) ) : '';
	$reason = trim( ( '' !== $goal ? 'هدف: ' . $goal . "\n\n" : '' ) . ( '' !== $todo ? "چه کار کنیم:\n" . $todo . "\n\n" : '' ) . ( $c ? 'چرا: ' . $c['why'] . "\n" : '' ) . ( $strs ? 'استراتژی: ' . $strs : '' ) );
	$id     = sap_update_plan( $project, $url, $period, array( 'reason' => $reason, 'source' => 'strategy', 'priority' => $c && $c['score'] >= 60 ? 'high' : 'medium' ) );
	if ( $id ) {
		$data = array( 'reason' => $reason, 'secondary' => $sec, 'source' => 'strategy' );
		if ( '' !== $kw ) {
			$data['keyword'] = $kw;
		}
		sap_update_save( $data, $id );
	}
	return $id;
}

/**
 * Kept for older callers: the weekly builder no longer picks pages by itself
 * (pages enter the calendar only after approval).
 */
function sap_update_pick_best( $project, $period, $k ) {
	return 0;
}

/**
 * Deep AI analysis of one page: ready texts for the plan.
 *
 * @return array|WP_Error
 */
function sap_upd_ai( $project, $url, $s = '' ) {
	$defs = sap_upd_strategies();
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_upd', 'هوش مصنوعی در تنظیمات وصل نشده است.' );
	}
	$pid    = $project['id'];
	$k      = sap_norm_url( $url );
	$c      = sap_upd_cards( $pid )[ $k ] ?? null;
	if ( ! $c ) {
		return new WP_Error( 'sap_upd', 'این صفحه در تحلیل آخر نیست؛ تحلیل را دوباره اجرا کنید.' );
	}
	$budget = 1;
	$pg     = sap_upd_page( $pid, $url, $budget );
	$types  = sap_upd_types();
	$fit_l  = array( 'own' => 'کلمه خود صفحه', 'fit' => 'مرتبط و آزاد', 'other' => 'مال صفحه دیگر — هدف نگیر، فقط لینک بده', 'brand' => 'برند — کاری لازم نیست', 'info' => 'اطلاعاتی — جایش مقاله است', 'buy' => 'خرید — جایش دسته است' );
	$facts  = array(
		'url'        => rawurldecode( $url ),
		'page_type'  => $types[ $c['type'] ],
		'keyword'    => $c['kw'],
		'focus'      => $c['focus'],
		'strategies' => array_map( function ( $sk ) use ( $defs, $c ) {
			return $defs[ $sk ]['name'] . ': ' . $c['s'][ $sk ]['why'];
		}, array_keys( $c['s'] ) ),
		'plan_draft' => $c['todo'],
		'queries'    => array_map( function ( $q ) use ( $fit_l ) {
			return sprintf( '%s | %s نمایش | CTR %s٪ | جایگاه %s | %s%s', $q['q'], $q['impr'], $q['ctr'], $q['pos'], $fit_l[ $q['fit'] ] ?? $q['fit'], false === $q['in_text'] ? ' | در متن نیست' : '' );
		}, array_slice( $c['queries'], 0, 15 ) ),
		'title'      => $pg['title'] ?? '',
		'meta'       => $pg['meta'] ?? '',
		'h1'         => implode( ' | ', (array) ( $pg['h1'] ?? array() ) ),
		'headings'   => array_slice( array_merge( (array) ( $pg['h2'] ?? array() ), (array) ( $pg['h3'] ?? array() ) ), 0, 30 ),
		'intro'      => mb_substr( (string) ( $pg['text'] ?? '' ), 0, 900 ),
		'text'       => mb_substr( (string) ( $pg['text'] ?? '' ), 900, 3000 ),
	);
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array(
		'diagnosis' => array( 'type' => 'string', 'description' => '۲ تا ۳ جمله ساده: مشکل اصلی صفحه با عدد و اینکه آپدیت چه اثری دارد' ),
		'title'     => array( 'type' => 'string', 'description' => 'تایتل پیشنهادی ≤ ۶۰ کاراکتر، یا خالی' ),
		'meta'      => array( 'type' => 'string', 'description' => 'متا ۱۲۰ تا ۱۵۵ کاراکتر، یا خالی' ),
		'h1'        => array( 'type' => 'string', 'description' => 'H1 پیشنهادی یا خالی' ),
		'intro'     => array( 'type' => 'string', 'description' => 'مقدمه جدید کوتاه (۲ تا ۳ جمله) یا خالی' ),
		'sections'  => array( 'type' => 'array', 'items' => sap_ai_obj( array(
			'tag'     => array( 'type' => 'string', 'enum' => array( 'h2', 'h3' ) ),
			'heading' => $str,
			'what'    => array( 'type' => 'string', 'description' => 'چه چیزی در این بخش نوشته شود و کدام جستجو را جواب می‌دهد' ),
		) ) ),
		'repeat'    => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'keyword' => $str, 'target' => array( 'type' => 'integer' ) ) ) ),
		'faq'       => array( 'type' => 'array', 'items' => $str ),
		'links_in'  => array( 'type' => 'array', 'description' => 'انکرهایی که از صفحات دیگر به این صفحه لینک شوند', 'items' => $str ),
		'media'     => array( 'type' => 'array', 'items' => $str ),
		'new_pages' => array( 'type' => 'array', 'description' => 'جستجوهایی که جایشان محتوای جداست', 'items' => $str ),
	) );
	$system = implode( "\n", array(
		'تو متخصص ارشد سئو محتوا برای سایت فروشگاهی فارسی هستی. این صفحه از نوع «' . $types[ $c['type'] ] . '» است و برای آپدیت انتخاب شده. پیش‌نویس برنامه (plan_draft) را دقیق و آماده اجرا کن.',
		'- فقط روی کلمات «کلمه خود صفحه» و «مرتبط و آزاد» هدف بگیر. کلمه‌ای که مال صفحه دیگر است را هدف نگیر (فقط لینک)، برند را نادیده بگیر، و جستجوی اطلاعاتی روی صفحه فروش را به new_pages بفرست.',
		'- ماهیت صفحه را رعایت کن: دسته‌بندی = کلمه عمومی خرید، معرفی انواع و راهنمای انتخاب؛ محصول = نام دقیق محصول، ویژگی‌ها، کاربرد؛ مقاله = پاسخ کامل به سوال کاربر و لینک به صفحه فروش.',
		'- خروجی آماده اجرا بده؛ قیمت، تخفیف و موجودی ننویس؛ سال نیاور.',
		'- فارسی روان با نیم‌فاصله درست.',
	) );
	$res = sap_ai_request( $system, wp_json_encode( $facts, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'analysis', 'max_tokens' => 3000 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$res['at'] = sap_now();
	$all       = get_option( 'sap_upd_ai_' . $pid, array() );
	$all       = is_array( $all ) ? $all : array();
	unset( $all[ $k ] );
	$all[ $k ] = $res;
	update_option( 'sap_upd_ai_' . $pid, array_slice( $all, -80, null, true ), false );
	return $res;
}

/**
 * The AI result as plan lines (added to the plan when approving).
 */
function sap_upd_ai_lines( $a ) {
	$out = array();
	foreach ( array( 'title' => 'تایتل', 'meta' => 'متا', 'h1' => 'H1', 'intro' => 'مقدمه' ) as $f => $l ) {
		if ( '' !== trim( (string) ( $a[ $f ] ?? '' ) ) ) {
			$out[] = $l . ' پیشنهادی: ' . $a[ $f ];
		}
	}
	foreach ( (array) ( $a['sections'] ?? array() ) as $sec ) {
		$out[] = 'افزودن ' . strtoupper( $sec['tag'] ) . ' «' . $sec['heading'] . '»: ' . $sec['what'];
	}
	if ( ! empty( $a['faq'] ) ) {
		$out[] = 'FAQ: ' . implode( ' | ', $a['faq'] );
	}
	if ( ! empty( $a['links_in'] ) ) {
		$out[] = 'لینک داخلی به این صفحه با انکر: ' . implode( '، ', $a['links_in'] );
	}
	return $out;
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
			return array( 'ok' => true, 'msg' => sprintf( 'تحلیل انجام شد: %s دسته‌بندی، %s محصول، %s مقاله برای آپدیت.', sap_num( $r['counts']['cat'] ), sap_num( $r['counts']['product'] ), sap_num( $r['counts']['post'] ) ) . ( $r['notes'] ? ' (' . implode( '؛ ', $r['notes'] ) . ')' : '' ) );
		} ),
	) );
	register_rest_route( $ns, '/upd/(?P<project>\d+)/add', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => $route( function ( $p, $req ) {
			$url    = esc_url_raw( (string) $req->get_param( 'url' ) );
			$period = sap_period_sanitize( (string) ( $req->get_param( 'period' ) ?: sap_period_current( $p['id'] ) ) );
			if ( '' === $url ) {
				return new WP_Error( 'sap_upd', 'آدرس صفحه را وارد کنید.', array( 'status' => 400 ) );
			}
			$fields = array();
			foreach ( array( 'keyword', 'secondary', 'goal', 'todo' ) as $f ) {
				if ( null !== $req->get_param( $f ) ) {
					$fields[ $f ] = sanitize_textarea_field( (string) $req->get_param( $f ) );
				}
			}
			sap_upd_approve( $p, $url, $period, $fields );
			return array( 'ok' => true, 'msg' => 'به آپدیت‌های تقویم ' . sap_period_label( $period ) . ' اضافه شد' . sap_calendar_sync_sheet( sap_project( $p['id'] ) ) );
		} ),
	) );
	register_rest_route( $ns, '/upd/(?P<project>\d+)/ai', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => $route( function ( $p, $req ) {
			$r = sap_upd_ai( $p, esc_url_raw( (string) $req->get_param( 'url' ) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			ob_start();
			sap_view( 'upd-ai', array( 'a' => $r ) );
			return array( 'ok' => true, 'html' => (string) ob_get_clean(), 'lines' => sap_upd_ai_lines( $r ) );
		} ),
	) );
} );
