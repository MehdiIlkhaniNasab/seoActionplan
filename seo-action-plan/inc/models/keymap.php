<?php
/**
 * Keyword map: the primary keyword (and secondary keywords) each page of the
 * site is meant to rank for. It is the basis of every judgement that needs to
 * know what a page is *for*: real cannibalization vs a double result, titles,
 * internal-link anchors and keywords that have no page.
 *
 * Sources, strongest first: manual (typed or confirmed by the user) → sheet
 * (clustering / content calendar) → ai (suggested by the model) → auto (best
 * Search Console query that matches the page title / H1).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Labels.
 */
function sap_keymap_sources() {
	return array(
		'manual' => 'تأیید شما',
		'sheet'  => 'گوگل شیت',
		'ai'     => 'پیشنهاد هوش مصنوعی',
		'auto'   => 'تشخیص خودکار',
	);
}

function sap_keymap_intents() {
	return array(
		'transactional' => 'خرید',
		'commercial'    => 'مقایسه/بررسی',
		'informational' => 'اطلاعاتی',
		'navigational'  => 'ناوبری/برند',
	);
}

/**
 * What the page is in the keyword map: a target with its own keyword, a
 * product whose title is its keyword, no keyword at all, or pruned.
 */
function sap_keymap_statuses() {
	return array(
		'target'    => 'هدف: کلمه کلیدی دارد',
		'title'     => 'محصول: عنوان = کلمه اصلی',
		'none'      => 'بدون کلمه کلیدی',
		'noindex'   => 'noindex شود',
		'prune_410' => 'هرس: حذف (410)',
		'prune_301' => 'هرس: ادغام و ریدایرکت 301',
	);
}

/**
 * Does a status keep a keyword?
 */
function sap_keymap_has_kw( $status ) {
	return in_array( $status, array( 'target', 'title', '' ), true );
}

function sap_keymap_page_types() {
	return array(
		'home'     => 'صفحه اصلی',
		'category' => 'دسته‌بندی',
		'product'  => 'محصول',
		'article'  => 'مقاله',
		'page'     => 'برگه',
		'other'    => 'سایر',
	);
}

/**
 * Words that change the wording but not the topic of a query
 * («خرید کفی ورزشی» and «قیمت کفی ورزشی» are the same topic as «کفی ورزشی»).
 */
function sap_kw_modifiers() {
	// Only words that keep the intent: «بهترین X» or «راهنمای X» is a different (commercial /
	// informational) intent than the category «X», so they stay part of the topic.
	return array( 'خرید', 'قیمت', 'فروش', 'ارزان', 'آنلاین', 'اینترنتی', 'عمده', 'تخفیف', 'با', 'و', 'برای', 'در', 'از', 'buy', 'price', 'shop' );
}

/**
 * Topic key of a keyword: sorted core words without modifiers.
 */
function sap_kw_core( $keyword ) {
	$mods  = array_flip( array_map( 'sap_norm_text', sap_kw_modifiers() ) );
	$words = array_values( array_filter( sap_tokens( $keyword ), function ( $w ) use ( $mods ) {
		return ! isset( $mods[ $w ] );
	} ) );
	if ( ! $words ) {
		$words = sap_tokens( $keyword );
	}
	sort( $words );
	return implode( ' ', array_unique( $words ) );
}

/**
 * Hydrate a row.
 */
function sap_keymap_hydrate( $row ) {
	$row              = (array) $row;
	$row['id']        = (int) $row['id'];
	$row['focus_id']  = (int) $row['focus_id'];
	$row['confirmed'] = (int) $row['confirmed'];
	$row['secondary'] = sap_list( (string) $row['secondary'] );
	$row['reason']    = (string) $row['reason'];
	$row['url']       = (string) $row['url'];
	$row['status']    = (string) ( $row['status'] ?? '' ) ?: 'target';
	$row['redirect_to'] = (string) ( $row['redirect_to'] ?? '' );
	// Pages without a keyword (contact, policies, pruned…) own nothing.
	$row['core']      = '' !== $row['primary_kw'] && sap_keymap_has_kw( $row['status'] ) ? sap_kw_core( $row['primary_kw'] ) : '';
	return $row;
}

/**
 * The keyword map of a project, keyed by normalized URL.
 */
function sap_keymap( $project_id, $refresh = false ) {
	static $cache = array();
	if ( ! $refresh && isset( $cache[ $project_id ] ) ) {
		return $cache[ $project_id ];
	}
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'keymap' ) . ' WHERE project_id = %d ORDER BY id ASC', $project_id ) ); // phpcs:ignore
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$r                    = sap_keymap_hydrate( $r );
		$out[ $r['url_key'] ] = $r;
	}
	$cache[ $project_id ] = $out;
	return $out;
}

/**
 * Insert or update the entry of a page. A weaker source never overwrites a
 * stronger one (an AI suggestion does not replace a keyword you confirmed).
 *
 * @param array $data url, title, primary_kw, secondary (array|string), intent, page_type, focus_id, source, confirmed, reason.
 * @param bool  $force Overwrite whatever source is stored.
 */
function sap_keymap_save( $project_id, $data, $force = false ) {
	global $wpdb;
	$url = (string) ( $data['url'] ?? '' );
	$key = sap_norm_url( $url );
	if ( '' === $key ) {
		return 0;
	}
	$rank   = array( 'auto' => 1, 'ai' => 2, 'sheet' => 3, 'manual' => 4 );
	$old    = sap_keymap( $project_id )[ $key ] ?? null;
	$source = isset( $rank[ $data['source'] ?? '' ] ) ? $data['source'] : 'auto';
	$fields = array( 'updated_at' => sap_now() );
	if ( isset( $data['title'] ) && '' !== $data['title'] ) {
		$fields['title'] = mb_substr( sanitize_text_field( $data['title'] ), 0, 250 );
	}
	$may_write = $force || ! $old || $rank[ $source ] >= ( $rank[ $old['source'] ] ?? 0 ) || ( ! $old['confirmed'] && '' === $old['primary_kw'] );
	if ( $old && $old['confirmed'] && ! $force ) {
		$may_write = false;
	}
	if ( $may_write ) {
		foreach ( array( 'primary_kw', 'intent', 'page_type', 'reason' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$fields[ $k ] = mb_substr( sanitize_text_field( (string) $data[ $k ] ), 0, 'reason' === $k ? 1000 : 190 );
			}
		}
		if ( array_key_exists( 'secondary', $data ) ) {
			$sec                 = is_array( $data['secondary'] ) ? $data['secondary'] : sap_list( (string) $data['secondary'] );
			$fields['secondary'] = implode( "\n", array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $sec ) ) ) ), 0, 30 ) );
		}
		if ( isset( $data['focus_id'] ) ) {
			$fields['focus_id'] = (int) $data['focus_id'];
		}
		if ( isset( $data['status'] ) && isset( sap_keymap_statuses()[ $data['status'] ] ) ) {
			$fields['status'] = $data['status'];
		}
		if ( isset( $data['redirect_to'] ) ) {
			$fields['redirect_to'] = esc_url_raw( (string) $data['redirect_to'] );
		}
		$fields['source'] = $source;
		if ( isset( $data['confirmed'] ) ) {
			$fields['confirmed'] = (int) (bool) $data['confirmed'];
		}
	}
	if ( $old ) {
		$wpdb->update( sap_table( 'keymap' ), $fields, array( 'id' => $old['id'] ) );
		$id = $old['id'];
	} else {
		$fields += array( 'project_id' => (int) $project_id, 'url_key' => $key, 'url' => esc_url_raw( $url ), 'source' => $source );
		$wpdb->insert( sap_table( 'keymap' ), $fields );
		$id = (int) $wpdb->insert_id;
	}
	sap_keymap( $project_id, true );
	return $id;
}

/**
 * Remove one entry.
 */
function sap_keymap_delete( $project_id, $id ) {
	global $wpdb;
	$wpdb->delete( sap_table( 'keymap' ), array( 'id' => (int) $id, 'project_id' => (int) $project_id ) );
	sap_keymap( $project_id, true );
}

/**
 * Page type from the URL (WordPress / WooCommerce conventions).
 */
function sap_guess_page_type( $url ) {
	$path = trim( (string) wp_parse_url( rawurldecode( $url ), PHP_URL_PATH ), '/' );
	if ( '' === $path ) {
		return 'home';
	}
	$first = strtolower( explode( '/', $path )[0] );
	if ( in_array( $first, array( 'product-category', 'category', 'product-tag', 'tag', 'brand', 'brands', 'collection', 'collections', 'shop' ), true ) ) {
		return 'category';
	}
	if ( in_array( $first, array( 'product', 'products', 'p' ), true ) ) {
		return 'product';
	}
	if ( in_array( $first, array( 'blog', 'mag', 'magazine', 'news', 'article', 'articles' ), true ) || preg_match( '#^\d{4}/\d{2}/#', $path ) ) {
		return 'article';
	}
	return 'other';
}

/**
 * Clean page title: H1, else the <title> without the site-name suffix.
 */
function sap_clean_title( $title, $h1 = '' ) {
	$h1 = trim( (string) $h1 );
	if ( '' !== $h1 && mb_strlen( $h1 ) <= 90 ) {
		return $h1;
	}
	$parts = preg_split( '/\s+[|\-–—»«:]\s+/u', trim( (string) $title ) );
	return trim( (string) ( $parts[0] ?? $title ) );
}

/**
 * Pick the best primary keyword for a page from its Search Console queries:
 * the query with most impressions whose words all appear in the title / H1.
 *
 * @return array{primary:string, secondary:string[]}
 */
function sap_keymap_guess( $project, $rows, $title ) {
	$tt     = array_flip( sap_tokens( $title ) );
	$best   = null;
	$scored = array();
	foreach ( sap_sort( $rows, 'impressions' ) as $r ) {
		if ( sap_is_brand( $r['query'], $project ) ) {
			continue;
		}
		$tok = sap_tokens( $r['query'] );
		if ( ! $tok ) {
			continue;
		}
		$hit   = count( array_filter( $tok, function ( $w ) use ( $tt ) {
			return isset( $tt[ $w ] );
		} ) ) / count( $tok );
		$score = $r['impressions'] * ( 0.15 + $hit ) * ( $hit >= 0.99 ? 2 : 1 ) * ( count( $tok ) >= 2 ? 1.2 : 1 );
		$scored[] = array( $r['query'], $score, $hit );
	}
	usort( $scored, function ( $a, $b ) {
		return $b[1] <=> $a[1];
	} );
	$primary = $scored[0][0] ?? '';
	$sec     = array();
	$core    = '' !== $primary ? sap_kw_core( $primary ) : '';
	foreach ( array_slice( $scored, 1 ) as $s ) {
		if ( $s[2] >= 0.5 && sap_kw_core( $s[0] ) !== $core && count( $sec ) < 8 ) {
			$sec[] = $s[0];
		}
	}
	return array( 'primary' => $primary, 'secondary' => $sec );
}

/**
 * Build / refresh the keyword map from the sheets and Search Console.
 *
 * @return array{pages:int, sheet:int, auto:int}
 */
function sap_keymap_build( $project ) {
	$pid   = $project['id'];
	$stats = array( 'pages' => 0, 'sheet' => 0, 'auto' => 0 );
	$read  = (array) ( sap_dataset_payload( $pid, 'pages' )['pages'] ?? array() );
	$crawl = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'crawl' )['rows'] ?? array() ) as $r ) {
		$crawl[ sap_norm_url( $r['url'] ) ] = $r;
	}
	$title_of = function ( $url ) use ( $read, $crawl ) {
		$k = sap_norm_url( $url );
		if ( isset( $read[ $k ] ) ) {
			return sap_clean_title( $read[ $k ]['title'], $read[ $k ]['h1'][0] ?? '' );
		}
		if ( isset( $crawl[ $k ] ) ) {
			return sap_clean_title( $crawl[ $k ]['title'] ?? '', $crawl[ $k ]['h1'] ?? '' );
		}
		return '';
	};
	$focuses = sap_focus_context( $pid );

	// 1. Clustering sheet: the «اصلی» row of each target page is its primary keyword.
	$sheet = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'sheet_clusters' )['rows'] ?? array() ) as $r ) {
		if ( '' === ( $r['target'] ?? '' ) || '' === ( $r['keyword'] ?? '' ) ) {
			continue;
		}
		$k = sap_norm_url( $r['target'] );
		$sheet[ $k ]['url'] = $r['target'];
		// «پیلار» is the page's own keyword; «اصلی» the main keyword of a sub-cluster on that page.
		$role = sap_norm_text( $r['role'] ?? '' );
		$rank = ( false !== mb_strpos( $role, 'پیلار' ) || false !== strpos( $role, 'pillar' ) ) ? 3 : ( ( false !== mb_strpos( $role, 'اصلی' ) || false !== strpos( $role, 'primary' ) || false !== strpos( $role, 'main' ) ) ? 2 : 1 );
		if ( $rank > ( $sheet[ $k ]['rank'] ?? 0 ) ) {
			if ( ! empty( $sheet[ $k ]['primary'] ) ) {
				$sheet[ $k ]['secondary'][] = $sheet[ $k ]['primary'];
			}
			$sheet[ $k ]['primary'] = $r['keyword'];
			$sheet[ $k ]['rank']    = $rank;
			$sheet[ $k ]['intent']  = sap_intent_key( $r['intent'] ?? '' );
		} else {
			$sheet[ $k ]['secondary'][] = $r['keyword'];
		}
	}
	// 2. Content calendar: the article's main keyword + its co-concept keywords.
	foreach ( (array) ( sap_dataset_payload( $pid, 'sheet_calendar' )['rows'] ?? array() ) as $r ) {
		if ( '' === ( $r['url'] ?? '' ) || '' === ( $r['keyword'] ?? '' ) ) {
			continue;
		}
		$k = sap_norm_url( $r['url'] );
		if ( empty( $sheet[ $k ]['primary'] ) ) {
			$sheet[ $k ] = array( 'url' => $r['url'], 'primary' => $r['keyword'], 'secondary' => sap_list( (string) ( $r['related'] ?? '' ) ), 'intent' => sap_intent_key( $r['intent'] ?? '' ) ?: 'informational', 'type' => 'article' );
		}
	}
	foreach ( $sheet as $k => $s ) {
		list( $fid ) = sap_focus_match( $focuses, $s['url'], $s['primary'] );
		sap_keymap_save( $pid, array(
			'url'        => $s['url'],
			'title'      => $title_of( $s['url'] ),
			'primary_kw' => $s['primary'],
			'secondary'  => $s['secondary'] ?? array(),
			'intent'     => $s['intent'] ?? '',
			'page_type'  => $s['type'] ?? sap_guess_page_type( $s['url'] ),
			'focus_id'   => $fid,
			'source'     => 'sheet',
		) );
		++$stats['sheet'];
	}

	// 3. Every other important page: best matching Search Console query.
	$by_page = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
		$by_page[ sap_norm_url( $r['page'] ) ][] = $r;
	}
	$urls = sap_pages_to_read( $project, 80 );
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		if ( $r['impressions'] >= (int) sap_setting( 'min_impressions', 50 ) && count( $urls ) < 150 ) {
			$urls[] = $r['page'];
		}
	}
	$map = sap_keymap( $pid );
	foreach ( array_unique( $urls ) as $url ) {
		$k = sap_norm_url( $url );
		if ( isset( $sheet[ $k ] ) ) {
			continue;
		}
		$title = $title_of( $url );
		$type  = sap_guess_page_type( $url );
		$old   = $map[ $k ] ?? null;
		if ( $old && 'auto' !== $old['source'] ) {
			if ( '' !== $title && $title !== $old['title'] ) {
				sap_keymap_save( $pid, array( 'url' => $url, 'title' => $title, 'source' => $old['source'] ) );
			}
			continue;
		}
		$g = sap_keymap_guess( $project, (array) ( $by_page[ $k ] ?? array() ), '' !== $title ? $title : rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		if ( 'home' === $type ) {
			$g = array( 'primary' => (string) ( $project['brand_terms'][0] ?? $project['name'] ), 'secondary' => array() );
		}
		if ( '' === $g['primary'] && '' === $title ) {
			continue;
		}
		list( $fid ) = sap_focus_match( $focuses, $url, $g['primary'] );
		sap_keymap_save( $pid, array(
			'url'        => $url,
			'title'      => $title,
			'primary_kw' => $g['primary'],
			'secondary'  => $g['secondary'],
			'intent'     => 'home' === $type ? 'navigational' : ( in_array( $type, array( 'category', 'product' ), true ) ? 'transactional' : ( 'article' === $type ? 'informational' : '' ) ),
			'page_type'  => $type,
			'focus_id'   => $fid,
			'source'     => 'auto',
		) );
		++$stats['auto'];
	}
	$stats['pages'] = count( sap_keymap( $pid, true ) );
	return $stats;
}

/**
 * Intent key from what is typed in a sheet.
 */
function sap_intent_key( $text ) {
	$t = sap_norm_text( $text );
	if ( '' === $t ) {
		return '';
	}
	foreach ( array(
		'transactional' => array( 'تراکنش', 'خرید', 'transaction' ),
		'commercial'    => array( 'تجاری', 'مقایس', 'بررسی', 'commercial' ),
		'informational' => array( 'اطلاع', 'آموزش', 'info' ),
		'navigational'  => array( 'ناوبر', 'برند', 'nav' ),
	) as $k => $needles ) {
		foreach ( $needles as $n ) {
			if ( false !== mb_strpos( $t, $n ) ) {
				return $k;
			}
		}
	}
	return '';
}

/**
 * Index of the keyword map for the detectors.
 *
 * @return array{primary: array<string,string[]>, secondary: array<string,string[]>}
 *         topic key => URL keys whose primary / secondary keyword it is.
 */
function sap_keymap_index( $map ) {
	$idx = array( 'primary' => array(), 'secondary' => array() );
	foreach ( $map as $k => $m ) {
		if ( '' !== $m['core'] ) {
			$idx['primary'][ $m['core'] ][] = $k;
		}
		foreach ( $m['secondary'] as $s ) {
			$idx['secondary'][ sap_kw_core( $s ) ][] = $k;
		}
	}
	return $idx;
}

/**
 * Keyword cloud of a project: page keywords, Search Console queries and the
 * calendar / idea keywords, each with its weight and ranking state.
 *
 * @return array list of {kw, weight, impr, volume, pos, page, kind, state}
 */
function sap_keyword_cloud( $project ) {
	$pid   = $project['id'];
	$best  = array(); // kw_key => best GSC row.
	$sum   = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
		$k         = sap_kw_key( $r['query'] );
		$sum[ $k ] = ( $sum[ $k ] ?? 0 ) + $r['impressions'];
		if ( ! isset( $best[ $k ] ) || $r['position'] < $best[ $k ]['position'] ) {
			$best[ $k ] = $r;
		}
	}
	$volumes = (array) ( sap_dataset_payload( $pid, 'ss_volumes' )['volumes'] ?? array() );
	$words   = array();
	$add     = function ( $kw, $kind, $page = '', $volume = 0 ) use ( &$words, $best, $sum, $volumes ) {
		$k = sap_kw_key( $kw );
		if ( '' === $k ) {
			return;
		}
		$order = array( 'primary' => 4, 'planned' => 3, 'secondary' => 2, 'query' => 1 );
		if ( isset( $words[ $k ] ) && $order[ $words[ $k ]['kind'] ] >= $order[ $kind ] ) {
			return;
		}
		$pos        = isset( $best[ $k ] ) ? (float) $best[ $k ]['position'] : 0;
		$vol        = $volume ?: (int) ( $volumes[ $k ]['volume'] ?? 0 );
		$words[ $k ] = array(
			'kw'     => $kw,
			'impr'   => (int) ( $sum[ $k ] ?? 0 ),
			'volume' => $vol,
			'pos'    => $pos,
			'page'   => $page ?: ( $best[ $k ]['page'] ?? '' ),
			'kind'   => $kind,
			'state'  => 'planned' === $kind && ! $pos ? 'planned' : ( ! $pos ? 'none' : ( $pos <= 3 ? 'top3' : ( $pos <= 10 ? 'top10' : ( $pos <= 20 ? 'near' : 'far' ) ) ) ),
		);
	};
	foreach ( sap_keymap( $pid ) as $m ) {
		if ( '' !== $m['primary_kw'] && 'home' !== $m['page_type'] ) {
			$add( $m['primary_kw'], 'primary', $m['url'] );
		}
		foreach ( $m['secondary'] as $s ) {
			$add( $s, 'secondary', $m['url'] );
		}
	}
	foreach ( sap_ideas( $pid, array( 'status' => array( 'scheduled', 'approved', 'published' ) ) ) as $i ) {
		$add( $i['keyword'], 'planned', $i['published_url'], $i['kp'] );
	}
	arsort( $sum );
	$n = 0;
	foreach ( $sum as $k => $impr ) {
		if ( $n++ >= 120 || $impr < 10 ) {
			break;
		}
		if ( ! sap_is_brand( $best[ $k ]['query'], $project ) ) {
			$add( $best[ $k ]['query'], 'query' );
		}
	}
	$max = 1;
	foreach ( $words as $w ) {
		$max = max( $max, $w['impr'], $w['volume'] );
	}
	foreach ( $words as &$w ) {
		$w['weight'] = round( log( 1 + max( $w['impr'], $w['volume'] ) ) / log( 1 + $max ), 3 );
	}
	unset( $w );
	$words = array_values( $words );
	usort( $words, function ( $a, $b ) {
		return strcmp( $a['kw'], $b['kw'] );
	} );
	return $words;
}
