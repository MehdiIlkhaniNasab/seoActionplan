<?php
/**
 * Page signals: hard facts about one page computed from real data before any AI
 * is asked — which Search Console queries bring impressions, where each ranks,
 * whether the query is in the title / H1 / headings / text, how many clicks a
 * better position or a better snippet would add, which keywords and SERP topics
 * the page does not cover, and whether the page type fits what people search.
 * The page analysis shows them, gives them to the AI as evidence and uses the
 * potential to decide what is worth a slot in the monthly calendar.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Search Console query rows of a page (normalised url => rows), cached per request.
 */
function sap_signals_qp( $pid ) {
	static $cache = array();
	if ( ! isset( $cache[ $pid ] ) ) {
		$cache[ $pid ] = array();
		foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
			$cache[ $pid ][ sap_norm_url( $r['page'] ) ][] = $r;
		}
	}
	return $cache[ $pid ];
}

/**
 * Does a text cover a phrase? Most of its words (and every word of a 1–2 word
 * phrase) appear in the text. Returns null when there is no text to check.
 *
 * @param array $words Set of the text's words (word => true).
 */
function sap_signals_covers( $words, $phrase ) {
	if ( ! $words ) {
		return null;
	}
	$stop = array_flip( array( 'در', 'به', 'از', 'با', 'و', 'برای', 'که', 'را', 'این', 'آن', 'یا', 'تا', 'چه', 'هر' ) );
	$t    = array_values( array_filter( array_unique( sap_tokens( $phrase ) ), function ( $w ) use ( $stop ) {
		return ! isset( $stop[ $w ] );
	} ) );
	if ( ! $t ) {
		return true;
	}
	$hit = 0;
	foreach ( $t as $w ) {
		// Persian plurals and suffixes: «اسکوترها»، «اسکوتری» still count as «اسکوتر».
		if ( isset( $words[ $w ] ) ) {
			++$hit;
			continue;
		}
		foreach ( array( 'ها', 'های', 'ی', 'ای', 'ان' ) as $suf ) {
			if ( isset( $words[ $w . $suf ] ) || ( mb_strlen( $w ) > 4 && isset( $words[ preg_replace( '/' . $suf . '$/u', '', $w ) ] ) ) ) {
				++$hit;
				continue 2;
			}
		}
	}
	return count( $t ) <= 2 ? $hit === count( $t ) : $hit / count( $t ) >= 0.75;
}

/**
 * Word set of a text.
 */
function sap_signals_words( $text ) {
	return '' === trim( (string) $text ) ? array() : array_fill_keys( sap_tokens( $text ), true );
}

/**
 * All signals of one content-inventory row.
 *
 * @param array      $project
 * @param array      $row  Row of sap_content_inventory().
 * @param array|null $page Page reader data (title, meta, h1[], h2[], h3[], text, words); read from the dataset when null.
 * @return array
 */
function sap_page_signals( $project, $row, $page = null ) {
	$pid  = $project['id'];
	$k    = sap_norm_url( $row['url'] );
	$page = $page ?: ( sap_dataset_payload( $pid, 'pages' )['pages'][ $k ] ?? null );
	$type = (string) $row['type'];
	$kind = in_array( $type, array( 'product' ), true ) ? 'product' : ( in_array( $type, array( 'product_cat', 'product_tag', 'brand' ), true ) ? 'category' : ( in_array( $type, array( 'post' ), true ) ? 'article' : 'page' ) );

	$title = (string) ( $page['title'] ?? $row['title'] );
	$h1    = $page ? implode( ' ', (array) $page['h1'] ) : (string) $row['title'];
	$heads = $page ? implode( ' ', array_merge( (array) $page['h1'], (array) ( $page['h2'] ?? array() ), (array) ( $page['h3'] ?? array() ) ) ) : '';
	$text  = $page ? (string) $page['text'] : '';
	$w_t   = sap_signals_words( $title );
	$w_h1  = sap_signals_words( $h1 );
	$w_hd  = sap_signals_words( $heads );
	$w_tx  = $page ? sap_signals_words( $text . ' ' . $heads . ' ' . $title ) : array(); // Not read: unknown, not «missing».

	// Queries: the page's real searches with what a better place would bring.
	$rows    = sap_sort( (array) ( sap_signals_qp( $pid )[ $k ] ?? array() ), 'impressions' );
	$queries = array();
	$gain    = 0;
	$intents = array( 'transactional' => 0, 'commercial' => 0, 'informational' => 0 );
	foreach ( array_slice( $rows, 0, 15 ) as $r ) {
		$pos  = round( (float) $r['position'], 1 );
		$impr = (int) $r['impressions'];
		$clk  = (int) $r['clicks'];
		$ctr  = $impr ? $clk / $impr : 0;
		$tgt  = $pos > 3 ? 3 : 1;
		$g    = $pos <= 20 && $pos > 1.5 ? (int) round( $impr * max( 0, sap_ctr_expected( $tgt ) - $ctr ) ) : 0;
		$low  = $pos <= 5 && $impr >= 50 && $ctr < 0.6 * sap_ctr_expected( $pos );
		if ( $low ) {
			$g = max( $g, (int) round( $impr * ( sap_ctr_expected( $pos ) - $ctr ) ) );
		}
		$it              = sap_kw_intent( $r['query'] );
		$intents[ $it ] += $impr;
		$queries[]       = array(
			'q'        => (string) $r['query'],
			'pos'      => $pos,
			'impr'     => $impr,
			'clicks'   => $clk,
			'ctr'      => round( 100 * $ctr, 1 ),
			'intent'   => $it,
			'in_title' => (bool) sap_signals_covers( $w_t, $r['query'] ),
			'in_h1'    => (bool) sap_signals_covers( $w_h1, $r['query'] ),
			'in_head'  => $w_hd ? (bool) sap_signals_covers( $w_hd, $r['query'] ) : null,
			'in_text'  => sap_signals_covers( $w_tx, $r['query'] ),
			'striking' => $pos >= 4 && $pos <= 20 && $impr >= 20,
			'low_ctr'  => $low,
			'gain'     => $g,
		);
		$gain += $g;
	}

	// Opportunities: each one a sentence with its numbers, strongest first.
	$opps = array();
	$add  = function ( $key, $text, $weight, $kindof ) use ( &$opps ) {
		$opps[] = array( 'key' => $key, 'text' => $text, 'weight' => $weight, 'kind' => $kindof );
	};
	foreach ( $queries as $q ) {
		if ( $q['low_ctr'] ) {
			$add( 'ctr:' . $q['q'], sprintf( '«%s» در جایگاه %s است ولی CTR آن %s٪ است (انتظار حدود %s٪)؛ تایتل/متا را برای این جستجو جذاب‌تر کنید: حدود +%s کلیک در ماه.', $q['q'], sap_num( $q['pos'], 1 ), sap_num( $q['ctr'], 1 ), sap_num( 100 * sap_ctr_expected( $q['pos'] ), 1 ), sap_num( $q['gain'] ) ), $q['gain'] + 5, 'snippet' );
		} elseif ( $q['striking'] ) {
			$where = null === $q['in_text'] ? 'هنوز به سه نتیجه اول نرسیده' : ( false === $q['in_text'] ? 'در متن صفحه اصلاً پوشش داده نشده' : ( false === $q['in_head'] ? 'در هیچ تیتری نیامده' : 'در صفحه هست ولی کم‌عمق است' ) );
			$add( 'pos:' . $q['q'], sprintf( '«%s» با %s نمایش در جایگاه %s است و %s؛ رساندنش به ۳ نتیجه اول حدود +%s کلیک در ماه.', $q['q'], sap_num( $q['impr'] ), sap_num( $q['pos'], 1 ), $where, sap_num( $q['gain'] ) ), $q['gain'] + ( false === $q['in_text'] ? 8 : 3 ), 'content' );
		} elseif ( false === $q['in_text'] && $q['impr'] >= 30 ) {
			$add( 'gap:' . $q['q'], sprintf( 'صفحه برای «%s» %s بار دیده شده ولی متنش درباره آن چیزی ندارد.', $q['q'], sap_num( $q['impr'] ) ), 4, 'content' );
		}
	}
	$kw = (string) $row['kw'];
	if ( '' !== $kw ) {
		if ( ! sap_signals_covers( $w_t, $kw ) ) {
			$add( 'kw_title', sprintf( 'کلمه اصلی «%s» در تایتل نیست.', $kw ), 9, 'snippet' );
		}
		if ( $page && ! sap_signals_covers( $w_h1, $kw ) ) {
			$add( 'kw_h1', sprintf( 'کلمه اصلی «%s» در H1 نیست.', $kw ), 7, 'content' );
		}
		$miss = array();
		foreach ( array_slice( (array) $row['secondary'], 0, 10 ) as $s ) {
			if ( false === sap_signals_covers( $w_tx, $s ) ) {
				$miss[] = $s;
			}
		}
		if ( $miss ) {
			$add( 'secondary', 'کلمات فرعی که در صفحه پوشش داده نشده‌اند: «' . implode( '»، «', array_slice( $miss, 0, 6 ) ) . '».', 3 + count( $miss ), 'content' );
		}
	}
	// What the top results of the main keyword cover and this page does not.
	$serp = '' !== $kw ? sap_serp_brief_facts( $pid, $kw ) : null;
	$serp_out = null;
	if ( $serp ) {
		$topics = array();
		foreach ( (array) ( sap_serp_analysis( $pid, $kw )['gap']['must_cover'] ?? array() ) as $m ) {
			$t = (string) ( $m['topic'] ?? '' );
			if ( '' !== $t && false === sap_signals_covers( $w_tx, $t ) ) {
				$topics[] = $t;
			}
		}
		$words    = (int) ( $page['words'] ?? $row['words'] ?? 0 );
		$target   = (int) $serp['words_target'];
		$serp_out = array(
			'intent'      => $serp['intent'],
			'page_type'   => $serp['page_type'],
			'words'       => $target,
			'missing'     => array_slice( $topics, 0, 8 ),
			'faq'         => array_slice( (array) $serp['faq'], 0, 6 ),
			'top_domains' => $serp['top_domains'],
		);
		if ( $topics ) {
			$add( 'serp_topics', sprintf( 'نتایج برتر گوگل برای «%s» این موضوعات را پوشش می‌دهند و صفحه شما نه: «%s».', $kw, implode( '»، «', array_slice( $topics, 0, 5 ) ) ), 6 + count( $topics ), 'content' );
		}
		if ( $target && $words && $words < 0.6 * $target && 'product' !== $kind ) {
			$add( 'serp_words', sprintf( 'صفحه %s کلمه دارد و میانه نتایج برتر حدود %s کلمه است.', sap_num( $words ), sap_num( $target ) ), 5, 'content' );
		}
	}
	// Page type against what people search.
	$total = array_sum( $intents );
	if ( $total >= 100 ) {
		$info = $intents['informational'] / $total;
		$buy  = $intents['transactional'] / $total;
		if ( in_array( $kind, array( 'product', 'category' ), true ) && $info >= 0.6 ) {
			$add( 'intent', sprintf( '%s٪ نمایش‌های این صفحه از جستجوهای اطلاعاتی است؛ این‌ها هدف مقاله‌اند نه صفحه فروش — یا یک بخش راهنما اضافه کنید یا مقاله جدا بسازید و به این صفحه لینک دهید.', sap_num( round( 100 * $info ) ) ), 6, 'structure' );
		} elseif ( 'article' === $kind && $buy >= 0.6 ) {
			$add( 'intent', sprintf( '%s٪ نمایش‌های این مقاله از جستجوهای خرید است؛ این جستجوها مال دسته/محصول است — لینک و CTA قوی به صفحه فروش بگذارید.', sap_num( round( 100 * $buy ) ) ), 6, 'structure' );
		}
	}
	if ( ! empty( $row['wp']['noindex'] ) && $row['impr'] ) {
		$add( 'noindex', 'صفحه noindex است ولی هنوز در گوگل نمایش دارد؛ تصمیم بگیرید ایندکس شود یا نه.', 4, 'structure' );
	}
	usort( $opps, function ( $a, $b ) {
		return $b['weight'] <=> $a['weight'];
	} );

	// Value of working on the page: what it could add, what it already has.
	$value = 'low';
	if ( $gain >= 30 || $row['impr'] >= 1500 || ( $gain >= 15 && $row['clicks'] >= 30 ) ) {
		$value = 'high';
	} elseif ( $gain >= 8 || $row['impr'] >= 200 || ( $row['focus_id'] && $row['impr'] >= 50 ) ) {
		$value = 'medium';
	}
	return array(
		'kind'      => $kind,
		'queries'   => $queries,
		'opps'      => array_slice( $opps, 0, 10 ),
		'potential' => $gain,
		'value'     => $value,
		'intents'   => $total ? array_map( function ( $v ) use ( $total ) {
			return (int) round( 100 * $v / $total );
		}, $intents ) : array(),
		'serp'      => $serp_out,
		'read'      => (bool) $page,
	);
}

/**
 * Signals of a page for display: the ones saved at the last AI analysis (taken
 * with the page text read) unless the live ones are as complete.
 */
function sap_page_signals_view( $project, $row ) {
	$live = sap_page_signals( $project, $row );
	if ( $live['read'] ) {
		return $live;
	}
	$saved = get_option( 'sap_page_sig_' . $project['id'], array() );
	$s     = $saved[ sap_norm_url( $row['url'] ) ] ?? null;
	return is_array( $s ) && ! empty( $s['read'] ) ? $s : $live;
}

/**
 * Keep the signals of analysed pages (newest 400 pages).
 */
function sap_page_signals_save( $project_id, $url, $sig ) {
	$saved = get_option( 'sap_page_sig_' . $project_id, array() );
	$saved = is_array( $saved ) ? $saved : array();
	unset( $saved[ sap_norm_url( $url ) ] );
	$saved[ sap_norm_url( $url ) ] = $sig;
	update_option( 'sap_page_sig_' . $project_id, array_slice( $saved, -400, null, true ), false );
}

/**
 * The signals as compact facts for the AI prompt.
 */
function sap_page_signals_ai( $s ) {
	$q = array();
	foreach ( array_slice( $s['queries'], 0, 12 ) as $r ) {
		$flags = array();
		$flags[] = $r['in_title'] ? 'در تایتل' : 'نه در تایتل';
		if ( null !== $r['in_text'] ) {
			$flags[] = $r['in_text'] ? 'در متن' : 'نه در متن';
		}
		$q[] = sprintf( '%s | جایگاه %s | %s نمایش | CTR %s٪ | %s%s', $r['q'], $r['pos'], $r['impr'], $r['ctr'], implode( '، ', $flags ), $r['gain'] ? ' | پتانسیل +' . $r['gain'] . ' کلیک' : '' );
	}
	$out = array(
		'value'           => array( 'high' => 'بالا', 'medium' => 'متوسط', 'low' => 'پایین' )[ $s['value'] ],
		'potential_clicks' => $s['potential'],
		'queries'         => $q,
		'opportunities'   => array_column( $s['opps'], 'text' ),
	);
	if ( $s['intents'] ) {
		$out['search_intent_share'] = sprintf( 'خرید %s٪، مقایسه %s٪، اطلاعاتی %s٪', $s['intents']['transactional'], $s['intents']['commercial'], $s['intents']['informational'] );
	}
	if ( $s['serp'] ) {
		$out['serp_top10'] = $s['serp'];
	}
	return $out;
}
