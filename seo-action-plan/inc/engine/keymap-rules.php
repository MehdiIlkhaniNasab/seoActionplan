<?php
/**
 * Keyword map rules: which page owns which keyword, strictly.
 *
 *  1. One owner per keyword: a keyword (its topic core) is the primary
 *     keyword of one page only, and no other page's secondary.
 *  2. Page-type priority: a generic keyword («کفی ورزشی», «خرید چسب کفش») is
 *     owned by a category / brand / tag page, an informational one by a post;
 *     products come last and only get keywords specific to themselves.
 *  3. Products: the product name is the keyword by default (status «title»);
 *     a generic query a product happens to show for is not its keyword.
 *  4. Utility pages (contact, about, policies, cart…) have no keyword.
 *  5. Pages with no keyword and no traffic are pruning candidates (410, or
 *     301 into the page that owns the topic).
 *  6. Fit score (0–100) of a keyword for a page: intent vs page type,
 *     type priority, title coverage, Search Console evidence, ownership.
 *  7. Ranking conflict: the target page is not the page Google shows best for
 *     its own keyword → possible cannibalization, check now.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keyword-map tabs: content types in priority order.
 */
function sap_keymap_tabs() {
	return array(
		'product_cat' => array( 'دسته‌بندی محصول', array( 'product_cat' ) ),
		'brand'       => array( 'برند', array( 'brand' ) ),
		'product_tag' => array( 'برچسب محصول', array( 'product_tag' ) ),
		'post'        => array( 'مقالات', array( 'post' ) ),
		'post_cat'    => array( 'دسته و برچسب مقالات', array( 'post_cat', 'post_tag' ) ),
		'page'        => array( 'برگه‌ها', array( 'page' ) ),
		'product'     => array( 'محصولات', array( 'product' ) ),
	);
}

/**
 * Priority of a content type as keyword owner (higher wins).
 */
function sap_keymap_type_weight( $ctype ) {
	$w = array( 'product_cat' => 60, 'brand' => 55, 'product_tag' => 45, 'post' => 40, 'page' => 35, 'post_cat' => 25, 'post_tag' => 20, 'product' => 10 );
	return $w[ $ctype ] ?? 30;
}

/**
 * Pages that need no keyword: contact, about, policies, cart, account…
 */
function sap_is_utility_page( $title, $url ) {
	$t = sap_norm_text( (string) $title . ' ' . rawurldecode( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) ) );
	return (bool) preg_match( '/تماس|درباره|قوانین|مقررات|شرایط|حریم|خصوصی|مرجوع|بازگشت کالا|بازگرداندن|ضمانت بازگشت|سبد خرید|تسویه|پرداخت|حساب کاربری|ورود|ثبت ?نام|پیگیری سفارش|علاقه ?مندی|مقایسه محصولات|نقشه سایت|همکاری|استخدام|contact|about|terms|privacy|policy|refund|return|cart|checkout|my-account|account|login|register|wishlist|compare|track|sitemap|careers/u', $t );
}

/**
 * Clean product name to use as its keyword (no brand/site suffix, no SKU noise).
 */
function sap_product_title_kw( $title ) {
	$t = sap_clean_title( (string) $title );
	$t = preg_replace( '/\s*[\(\[][^\)\]]*[\)\]]\s*/u', ' ', $t ); // «(کد 105)», «[اورجینال]».
	return trim( preg_replace( '/\s+/u', ' ', $t ) );
}

/**
 * Indexes of the keyword map and Search Console for the rules.
 */
function sap_keymap_rules_ctx( $project, $refresh = false ) {
	static $cache = array();
	$pid = $project['id'];
	if ( ! $refresh && isset( $cache[ $pid ] ) ) {
		return $cache[ $pid ];
	}
	$ctx = array( 'by_query' => array(), 'by_page' => array(), 'impr' => array(), 'owner' => array(), 'sec' => array() );
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
		$ctx['by_query'][ sap_kw_core( $r['query'] ) ][] = $r;
		$ctx['by_page'][ sap_norm_url( $r['page'] ) ][]  = $r;
	}
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$ctx['impr'][ sap_norm_url( $r['page'] ) ] = $r;
	}
	foreach ( sap_keymap( $pid ) as $k => $m ) {
		if ( '' !== $m['core'] ) {
			$ctx['owner'][ $m['core'] ][] = $k;
		}
		foreach ( $m['secondary'] as $s ) {
			$ctx['sec'][ sap_kw_core( $s ) ][] = $k;
		}
	}
	$cache[ $pid ] = $ctx;
	return $ctx;
}

/**
 * Fit of a keyword for a page.
 *
 * @param string $kw    Keyword.
 * @param string $url   Page URL.
 * @param string $ctype Content type of the page.
 * @param string $title Page title.
 * @return array{score:int, label:string, tone:string, reasons:string[]}
 */
function sap_kw_fit( $project, $kw, $url, $ctype, $title ) {
	$kw = trim( (string) $kw );
	if ( '' === $kw ) {
		return array( 'score' => 0, 'label' => '—', 'tone' => '', 'reasons' => array() );
	}
	$ctx     = sap_keymap_rules_ctx( $project );
	$k       = sap_norm_url( $url );
	$core    = sap_kw_core( $kw );
	$score   = 0;
	$reasons = array();
	$types   = sap_content_types();
	// 1. Intent vs page type (30).
	$mis = sap_kw_misfit( $kw, $ctype, $title );
	if ( 'product' === $ctype && 'product_cat' === $mis && sap_kw_core( sap_product_title_kw( $title ) ) === $core ) {
		$mis = ''; // The product's own name.
	}
	if ( $mis ) {
		$reasons[] = 'نیت کلمه مال ' . ( $types[ $mis ] ?? $mis ) . ' است، نه ' . ( $types[ $ctype ] ?? $ctype );
	} else {
		$score += 30;
	}
	// 2. Type priority (15): generic → category / brand / tag, informational → post, specific → product.
	$target = sap_kw_target_type( $kw, $title );
	if ( $target === $ctype || ( 'product_cat' === $target && in_array( $ctype, array( 'brand', 'product_tag' ), true ) ) || ( 'post' === $target && 'post_cat' === $ctype ) ) {
		$score += 15;
	} elseif ( ! $mis ) {
		$score    += 7;
		$reasons[] = 'نوع صفحه اولویت اول این کلمه نیست';
	}
	// 3. Title coverage (20).
	$words = array_values( array_filter( explode( ' ', $core ) ) );
	$tt    = array_flip( sap_tokens( $title ) );
	$hit   = $words ? count( array_filter( $words, function ( $w ) use ( $tt ) {
		return isset( $tt[ $w ] );
	} ) ) / count( $words ) : 0;
	$score += (int) round( 20 * $hit );
	if ( $hit < 0.6 ) {
		$reasons[] = 'کلمه در عنوان صفحه نیست';
	}
	// 4. Search Console (25): this page's share of the keyword's impressions and its position.
	$rows = (array) ( $ctx['by_query'][ $core ] ?? array() );
	if ( $rows ) {
		$total = array_sum( array_column( $rows, 'impressions' ) );
		$mine  = 0;
		$best  = null;
		foreach ( $rows as $r ) {
			if ( sap_norm_url( $r['page'] ) === $k ) {
				$mine += $r['impressions'];
			}
			if ( ! $best || $r['position'] < $best['position'] ) {
				$best = $r;
			}
		}
		$share  = $total ? $mine / $total : 0;
		$score += (int) round( 25 * $share );
		if ( $share < 0.4 && $best && sap_norm_url( $best['page'] ) !== $k ) {
			$reasons[] = sprintf( 'گوگل برای این کلمه بیشتر صفحه دیگری را نشان می‌دهد (%s٪ نمایش‌ها مال این صفحه است)', sap_num( round( $share * 100 ) ) );
		}
	} else {
		$score += 10; // No data yet: neutral.
	}
	// 5. Ownership (10).
	$owners = array_diff( (array) ( $ctx['owner'][ $core ] ?? array() ), array( $k ) );
	$secs   = array_diff( (array) ( $ctx['sec'][ $core ] ?? array() ), array( $k ) );
	if ( $owners ) {
		$reasons[] = 'کلمه اصلی صفحه دیگری هم هست';
	} elseif ( $secs ) {
		$score    += 5;
		$reasons[] = 'کلمه فرعی صفحه دیگری هم هست';
	} else {
		$score += 10;
	}
	$score = max( 0, min( 100, $score ) );
	return array(
		'score'   => $score,
		'label'   => $score >= 75 ? 'مناسب' : ( $score >= 50 ? 'قابل قبول' : 'نامناسب' ),
		'tone'    => $score >= 75 ? 'good' : ( $score >= 50 ? 'warn' : 'bad' ),
		'reasons' => $reasons,
	);
}

/**
 * Suggested status of a page that has no decision yet.
 *
 * @return array{status:string, redirect:string, reason:string}
 */
function sap_keymap_suggest( $project, $url, $ctype, $title, $it = null ) {
	$ctx  = sap_keymap_rules_ctx( $project );
	$k    = sap_norm_url( $url );
	$impr = (int) ( $ctx['impr'][ $k ]['impressions'] ?? 0 );
	$clk  = (int) ( $ctx['impr'][ $k ]['clicks'] ?? 0 );
	if ( 'page' === $ctype && sap_is_utility_page( $title, $url ) ) {
		return array( 'status' => 'none', 'redirect' => '', 'reason' => 'صفحه کاربردی (تماس، قوانین، سبد خرید…) کلمه کلیدی لازم ندارد' );
	}
	$has_gsc = ! empty( $ctx['impr'] );
	$old     = $it && ! empty( $it['date'] ) ? strtotime( $it['date'] ) < time() - 180 * DAY_IN_SECONDS : true;
	if ( $has_gsc && in_array( $ctype, array( 'post', 'post_tag', 'product_tag' ), true ) && $impr < 10 && ! $clk && $old && ( ! $it || (int) ( $it['words'] ?? 0 ) < 900 || in_array( $ctype, array( 'post_tag', 'product_tag' ), true ) ) ) {
		// Same topic on a page that owns a keyword: merge there.
		$best = '';
		$tok  = array_flip( sap_tokens( $title ) );
		$top  = 0;
		foreach ( sap_keymap( $project['id'] ) as $mk => $m ) {
			if ( $mk === $k || '' === $m['core'] ) {
				continue;
			}
			$shared = count( array_filter( explode( ' ', $m['core'] ), function ( $w ) use ( $tok ) {
				return mb_strlen( $w ) > 2 && isset( $tok[ $w ] );
			} ) );
			if ( $shared >= 2 && $shared > $top ) {
				$top  = $shared;
				$best = $m['url'];
			}
		}
		$why = sprintf( '%s نمایش و بدون کلیک در ۲۸ روز، قدیمی و بدون کلمه هدف', sap_num( $impr ) );
		return $best
			? array( 'status' => 'prune_301', 'redirect' => $best, 'reason' => $why . '؛ موضوعش را صفحه دیگری پوشش می‌دهد' )
			: array( 'status' => 'prune_410', 'redirect' => '', 'reason' => $why );
	}
	if ( 'product' === $ctype ) {
		return array( 'status' => 'title', 'redirect' => '', 'reason' => 'کلمه اصلی محصول همان نام محصول است' );
	}
	return array( 'status' => 'target', 'redirect' => '', 'reason' => '' );
}

/**
 * Apply the strict rules to the keyword map (rows you confirmed, typed or
 * took from the sheet are never changed, only flagged).
 *
 * @return array{none:int, title:int, owner:int, secondary:int}
 */
function sap_keymap_enforce( $project ) {
	$pid   = $project['id'];
	$map   = sap_keymap( $pid, true );
	$inv   = sap_site_inventory( $pid );
	$ctx   = sap_keymap_rules_ctx( $project );
	$stats = array( 'none' => 0, 'title' => 0, 'owner' => 0, 'secondary' => 0 );
	$locked = function ( $m ) {
		return $m['confirmed'] || in_array( $m['source'], array( 'manual', 'sheet' ), true );
	};
	$ctype = function ( $m ) use ( $inv ) {
		return isset( $inv[ $m['url_key'] ] ) ? $inv[ $m['url_key'] ]['ctype'] : sap_content_type( $m );
	};
	$title = function ( $m ) use ( $inv ) {
		return isset( $inv[ $m['url_key'] ] ) ? $inv[ $m['url_key'] ]['title'] : $m['title'];
	};
	// 1–2. Utility pages and products.
	foreach ( $map as $k => $m ) {
		if ( $locked( $m ) || 'home' === $m['page_type'] ) {
			continue;
		}
		$t  = $ctype( $m );
		$tt = $title( $m );
		if ( 'page' === $t && sap_is_utility_page( $tt, $m['url'] ) && 'none' !== $m['status'] ) {
			sap_keymap_save( $pid, array( 'url' => $m['url'], 'status' => 'none', 'primary_kw' => '', 'secondary' => array(), 'reason' => 'صفحه کاربردی؛ کلمه کلیدی لازم ندارد.', 'source' => 'auto' ), true );
			++$stats['none'];
			continue;
		}
		if ( 'product' === $t ) {
			$own = sap_product_title_kw( $tt );
			$kw  = $m['primary_kw'];
			if ( sap_keymap_core_taken( $map, sap_kw_core( $own ), $k ) ) {
				$own = ''; // The name is another page's keyword (e.g. the category): no keyword until a precise name (model / code) is given.
			}
			// A generic query the product only shows for is the category's keyword, not the product's.
			if ( '' === $kw || 'product' !== sap_kw_target_type( $kw, $tt ) && sap_kw_core( $kw ) !== sap_kw_core( $own ) ) {
				$secs = array_values( array_filter( $m['secondary'], function ( $s ) use ( $tt ) {
					return 'product' === sap_kw_target_type( $s, $tt );
				} ) );
				$why = '' !== $kw ? 'کلمه «' . $kw . '» عمومی است و مال صفحه دسته است؛ ' : '';
				$why .= '' !== $own ? 'کلمه اصلی محصول نام خودش شد.' : 'نام این محصول همان کلمه صفحه دیگری است؛ نام دقیق‌تر (مدل، کد، ویژگی) بدهید یا «بدون کلمه» بگذارید.';
				sap_keymap_save( $pid, array( 'url' => $m['url'], 'primary_kw' => $own, 'secondary' => $secs, 'status' => 'title', 'reason' => $why, 'source' => 'auto' ), true );
				++$stats['title'];
			}
		}
	}
	// 3. One owner per keyword: the higher-priority page type wins, then the page Google prefers.
	$map    = sap_keymap( $pid, true );
	$owners = array();
	foreach ( $map as $k => $m ) {
		if ( '' !== $m['core'] && 'home' !== $m['page_type'] ) {
			$owners[ $m['core'] ][] = $k;
		}
	}
	foreach ( $owners as $core => $keys ) {
		if ( count( $keys ) < 2 ) {
			continue;
		}
		$impr_of = function ( $k ) use ( $ctx, $core ) {
			$n = 0;
			foreach ( (array) ( $ctx['by_query'][ $core ] ?? array() ) as $r ) {
				if ( sap_norm_url( $r['page'] ) === $k ) {
					$n += $r['impressions'];
				}
			}
			return $n;
		};
		usort( $keys, function ( $a, $b ) use ( $map, $locked, $ctype, $impr_of ) {
			return array( (int) $locked( $map[ $b ] ), sap_keymap_type_weight( $ctype( $map[ $b ] ) ), $impr_of( $b ) ) <=> array( (int) $locked( $map[ $a ] ), sap_keymap_type_weight( $ctype( $map[ $a ] ) ), $impr_of( $a ) );
		} );
		$win = array_shift( $keys );
		foreach ( $keys as $k ) {
			$m = $map[ $k ];
			if ( $locked( $m ) ) {
				continue; // Flagged as a conflict in the map; your decision stays.
			}
			$t    = $ctype( $m );
			$tt   = $title( $m );
			$next = '';
			if ( 'product' === $t ) {
				$next = sap_product_title_kw( $tt );
				if ( isset( $owners[ sap_kw_core( $next ) ] ) ) {
					$next = '';
				}
			} else {
				foreach ( sap_sort( (array) ( $ctx['by_page'][ $k ] ?? array() ), 'impressions' ) as $r ) {
					$c = sap_kw_core( $r['query'] );
					if ( $c !== $core && empty( $owners[ $c ] ) && ! sap_kw_misfit( $r['query'], $t, $tt ) && ! sap_is_brand( $r['query'], $project ) ) {
						$next = $r['query'];
						break;
					}
				}
			}
			$winner = $map[ $win ];
			sap_keymap_save( $pid, array(
				'url'        => $m['url'],
				'primary_kw' => $next,
				'status'     => 'product' === $t ? 'title' : 'target',
				'reason'     => sprintf( '«%s» کلمه اصلی «%s» است (اولویت نوع صفحه یا نمایش بیشتر در گوگل)؛%s', $m['primary_kw'], $winner['title'] ?: sap_short_url( $winner['url'], 50 ), '' !== $next ? ' کلمه این صفحه «' . $next . '» شد.' : ' کلمه دیگری انتخاب کنید یا «بدون کلمه» بگذارید.' ),
				'source'     => 'auto',
			), true );
			if ( '' !== $next ) {
				$owners[ sap_kw_core( $next ) ][] = $k;
			}
			++$stats['owner'];
		}
	}
	// 4. Secondary keywords: not another page's primary, and of the right intent.
	$map  = sap_keymap( $pid, true );
	$prim = array();
	foreach ( $map as $k => $m ) {
		if ( '' !== $m['core'] ) {
			$prim[ $m['core'] ] = $k;
		}
	}
	foreach ( $map as $k => $m ) {
		if ( $locked( $m ) || ! $m['secondary'] ) {
			continue;
		}
		$t    = $ctype( $m );
		$tt   = $title( $m );
		$keep = array_values( array_filter( $m['secondary'], function ( $s ) use ( $prim, $k, $t, $tt, $m ) {
			$c = sap_kw_core( $s );
			return ( ! isset( $prim[ $c ] ) || $prim[ $c ] === $k ) && $c !== $m['core'] && ! sap_kw_misfit( $s, $t, $tt );
		} ) );
		if ( count( $keep ) !== count( $m['secondary'] ) ) {
			sap_keymap_save( $pid, array( 'url' => $m['url'], 'secondary' => $keep, 'source' => $m['source'] ), true );
			++$stats['secondary'];
		}
	}
	sap_keymap( $pid, true );
	sap_keymap_rules_ctx( $project, true );
	return $stats;
}

/**
 * Is a topic core the primary keyword of another page in the map?
 */
function sap_keymap_core_taken( $map, $core, $except ) {
	if ( '' === $core ) {
		return false;
	}
	foreach ( $map as $k => $m ) {
		if ( $k !== $except && $m['core'] === $core && 'home' !== $m['page_type'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Ranking conflicts: keywords whose target page is not the page Google shows
 * best (or most) for them.
 *
 * @return array list of {kw, url, title, pos, impr, other, other_title, other_pos, other_impr}
 */
function sap_keymap_rank_conflicts( $project, $min_impr = 5 ) {
	$pid = $project['id'];
	$ctx = sap_keymap_rules_ctx( $project );
	$out = array();
	foreach ( sap_keymap( $pid ) as $k => $m ) {
		if ( '' === $m['core'] || 'home' === $m['page_type'] ) {
			continue;
		}
		$rows = (array) ( $ctx['by_query'][ $m['core'] ] ?? array() );
		if ( ! $rows ) {
			continue;
		}
		$mine  = null;
		$other = null;
		foreach ( $rows as $r ) {
			if ( sap_norm_url( $r['page'] ) === $k ) {
				if ( ! $mine || $r['position'] < $mine['position'] ) {
					$mine = $r;
				}
			} elseif ( $r['impressions'] >= $min_impr && ( ! $other || $r['position'] < $other['position'] ) ) {
				$other = $r;
			}
		}
		if ( ! $other || ( $mine && $mine['position'] <= $other['position'] ) ) {
			continue;
		}
		$out[] = array(
			'kw'          => $m['primary_kw'],
			'url'         => $m['url'],
			'title'       => $m['title'] ?: sap_page_title( $pid, $m['url'] ),
			'pos'         => $mine ? round( (float) $mine['position'], 1 ) : null,
			'impr'        => $mine ? (int) $mine['impressions'] : 0,
			'other'       => $other['page'],
			'other_title' => sap_page_title( $pid, $other['page'] ) ?: sap_short_url( $other['page'], 60 ),
			'other_pos'   => round( (float) $other['position'], 1 ),
			'other_impr'  => (int) $other['impressions'],
		);
	}
	usort( $out, function ( $a, $b ) {
		return $b['other_impr'] <=> $a['other_impr'];
	} );
	return $out;
}

/**
 * Rows of one keyword-map tab: WordPress pages of the types (plus mapped
 * pages that are not in WordPress), with the map row, status and numbers.
 */
function sap_keymap_rows( $project, $tab ) {
	$pid   = $project['id'];
	$tabs  = sap_keymap_tabs();
	$types = $tabs[ $tab ][1] ?? array( $tab );
	$map   = sap_keymap( $pid );
	$inv   = sap_site_inventory( $pid );
	$ctx   = sap_keymap_rules_ctx( $project );
	$rows  = array();
	foreach ( $inv as $k => $it ) {
		if ( in_array( $it['ctype'], $types, true ) ) {
			$rows[ $k ] = array( 'url' => $it['url'], 'title' => $it['title'], 'ctype' => $it['ctype'], 'm' => $map[ $k ] ?? null, 'it' => $it );
		}
	}
	foreach ( $map as $k => $m ) {
		if ( ! isset( $rows[ $k ] ) && ! isset( $inv[ $k ] ) && in_array( sap_content_type( $m ), $types, true ) ) {
			$rows[ $k ] = array( 'url' => $m['url'], 'title' => $m['title'] ?: sap_page_title( $pid, $m['url'] ), 'ctype' => sap_content_type( $m ), 'm' => $m, 'it' => null );
		}
	}
	foreach ( $rows as $k => &$r ) {
		$r['k']      = $k;
		$r['impr']   = (int) ( $ctx['impr'][ $k ]['impressions'] ?? 0 );
		$r['clicks'] = (int) ( $ctx['impr'][ $k ]['clicks'] ?? 0 );
		$r['status'] = $r['m'] ? $r['m']['status'] : '';
		$r['kw']     = $r['m'] ? $r['m']['primary_kw'] : '';
	}
	unset( $r );
	return $rows;
}

/**
 * Health of the keyword map: share of important pages with a decision, and the
 * problems left (two owners, ranking conflicts, pruning not done).
 *
 * @return array{score:int|null, important:int, decided:int, with_kw:int, dup:int, rank:int, prune:int}
 */
function sap_keymap_health( $project ) {
	$pid  = $project['id'];
	$map  = sap_keymap( $pid );
	$inv  = sap_site_inventory( $pid );
	$ctx  = sap_keymap_rules_ctx( $project );
	$imp  = array();
	foreach ( $inv as $k => $it ) {
		$impr = (int) ( $ctx['impr'][ $k ]['impressions'] ?? 0 );
		if ( ( in_array( $it['ctype'], array( 'product_cat', 'brand', 'product_tag' ), true ) && empty( $it['noindex'] ) && (int) ( $it['count'] ?? 1 ) > 0 ) || $impr >= 10 ) {
			$imp[ $k ] = true;
		}
	}
	foreach ( $map as $k => $m ) {
		if ( (int) ( $ctx['impr'][ $k ]['impressions'] ?? 0 ) >= 10 ) {
			$imp[ $k ] = true; // Mapped pages with traffic, wherever they live.
		}
	}
	if ( ! $inv ) {
		foreach ( $ctx['impr'] as $k => $r ) {
			if ( (int) $r['impressions'] >= (int) sap_setting( 'min_impressions', 50 ) ) {
				$imp[ $k ] = true;
			}
		}
		foreach ( $map as $k => $m ) {
			$imp[ $k ] = true;
		}
	}
	$h = array( 'score' => null, 'important' => count( $imp ), 'decided' => 0, 'with_kw' => 0, 'dup' => 0, 'rank' => 0, 'prune' => 0 );
	foreach ( array_keys( $imp ) as $k ) {
		$m = $map[ $k ] ?? null;
		if ( $m && ( '' !== $m['core'] || ! sap_keymap_has_kw( $m['status'] ) ) ) {
			++$h['decided'];
		}
		if ( $m && '' !== $m['core'] ) {
			++$h['with_kw'];
		}
	}
	$own = array();
	foreach ( $map as $k => $m ) {
		if ( '' !== $m['core'] && 'home' !== $m['page_type'] ) {
			$own[ $m['core'] ] = ( $own[ $m['core'] ] ?? 0 ) + 1;
		}
		if ( in_array( $m['status'], array( 'prune_410', 'prune_301' ), true ) ) {
			++$h['prune'];
		}
	}
	$h['dup']  = count( array_filter( $own, function ( $n ) {
		return $n > 1;
	} ) );
	$h['rank'] = count( sap_keymap_rank_conflicts( $project ) );
	if ( $h['important'] ) {
		$h['score'] = max( 0, min( 100, (int) round( 100 * $h['decided'] / $h['important'] ) - 4 * $h['dup'] - 3 * $h['rank'] ) );
	}
	return $h;
}
