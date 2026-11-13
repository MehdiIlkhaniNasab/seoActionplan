<?php
/**
 * Focus measurement: the exact pages of a focus and their real Search Console
 * numbers, the same numbers the Search Console report shows with a page filter.
 *
 * Why not the query × page data: Google drops anonymised queries from it (often a
 * third or more of all clicks), so a focus measured that way always looks smaller
 * than it is. Here the clicks / impressions come from a «date» report filtered to
 * the focus pages (regex), with fresh data, and every number carries its dates:
 * the last 28 days, the 28 days before the focus started (baseline) and a weekly
 * series for the chart.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Categories of the site (product categories, brands, post categories) for the picker.
 *
 * @return array[] {url, title, ctype, parent_title, products}
 */
function sap_focus_categories( $project_id ) {
	$inv   = sap_site_inventory( $project_id );
	$terms = array();
	foreach ( $inv as $it ) {
		if ( 'term' === $it['kind'] && in_array( $it['ctype'], array( 'product_cat', 'brand', 'post_cat' ), true ) && '' === (string) $it['redirect'] ) {
			$terms[ $it['id'] . ':' . $it['type'] ] = $it;
		}
	}
	$out = array();
	foreach ( $terms as $t ) {
		$parent = $t['parent'] ? ( $terms[ $t['parent'] . ':' . $t['type'] ]['title'] ?? '' ) : '';
		$out[]  = array( 'url' => $t['url'], 'title' => $t['title'], 'ctype' => $t['ctype'], 'parent' => $parent, 'count' => (int) $t['count'] );
	}
	usort( $out, function ( $a, $b ) {
		return array( $a['ctype'], $a['parent'] ?: $a['title'], $a['parent'] ? 1 : 0, $a['title'] ) <=> array( $b['ctype'], $b['parent'] ?: $b['title'], $b['parent'] ? 1 : 0, $b['title'] );
	} );
	return $out;
}

/**
 * Titles of a category and all its sub-categories (products list category names).
 */
function sap_focus_cat_titles( $inv, $cat ) {
	$terms = array();
	foreach ( $inv as $it ) {
		if ( 'term' === $it['kind'] && $it['type'] === $cat['type'] ) {
			$terms[ $it['id'] ] = $it;
		}
	}
	$titles = array( sap_norm_text( $cat['title'] ) => true );
	$ids    = array( $cat['id'] => true );
	for ( $round = 0; $round < 6; $round++ ) {
		$grew = false;
		foreach ( $terms as $id => $t ) {
			if ( ! isset( $ids[ $id ] ) && isset( $ids[ $t['parent'] ] ) ) {
				$ids[ $id ]                          = true;
				$titles[ sap_norm_text( $t['title'] ) ] = true;
				$grew                                = true;
			}
		}
		if ( ! $grew ) {
			break;
		}
	}
	return $titles;
}

/**
 * Every page of a focus: chosen pages, chosen categories (with their products when
 * asked) and pages matching the URL patterns (WordPress list + Search Console pages).
 *
 * @return array normalized url => full url
 */
function sap_focus_page_set( $project_id, $focus ) {
	$inv = sap_site_inventory( $project_id );
	$out = array();
	foreach ( $focus['scope']['pages'] as $u ) {
		$out[ sap_norm_url( $u ) ] = $u;
	}
	foreach ( $focus['scope']['cats'] as $u => $with ) {
		$k         = sap_norm_url( $u );
		$out[ $k ] = $u;
		$cat       = $inv[ $k ] ?? null;
		if ( ! $with || ! $cat ) {
			continue;
		}
		$titles = sap_focus_cat_titles( $inv, $cat );
		foreach ( $inv as $pk => $it ) {
			if ( 'product' !== $it['ctype'] || '' !== (string) $it['redirect'] || empty( $it['product']['cats'] ) ) {
				continue;
			}
			foreach ( $it['product']['cats'] as $c ) {
				if ( isset( $titles[ sap_norm_text( $c ) ] ) ) {
					$out[ $pk ] = $it['url'];
					break;
				}
			}
		}
	}
	if ( $focus['url_patterns'] ) {
		foreach ( $inv as $k => $it ) {
			if ( '' === (string) $it['redirect'] && sap_url_matches( $it['url'], $focus['url_patterns'] ) ) {
				$out[ $k ] = $it['url'];
			}
		}
		foreach ( (array) ( sap_dataset_payload( $project_id, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
			if ( sap_url_matches( $r['page'], $focus['url_patterns'] ) ) {
				$out[ sap_norm_url( $r['page'] ) ] = $r['page'];
			}
		}
	}
	unset( $out[''] );
	return $out;
}

/**
 * Encoded path of a URL as Google stores it (percent-encoded, no trailing slash).
 */
function sap_focus_enc_path( $url ) {
	$path = (string) ( wp_parse_url( rawurldecode( (string) $url ), PHP_URL_PATH ) ?? '/' );
	$segs = array_map( 'rawurlencode', explode( '/', $path ) );
	return rtrim( implode( '/', $segs ), '/' );
}

/**
 * Escape a literal for RE2 (only its metacharacters).
 */
function sap_focus_re_quote( $s ) {
	return addcslashes( (string) $s, '.+*?()|[]{}^$\\' );
}

/**
 * Page-filter regexes (RE2) for the focus pages, split so no expression gets too long.
 *
 * @return string[]
 */
function sap_focus_regexes( $pages, $patterns ) {
	$parts = array();
	foreach ( $patterns as $p ) {
		$p = trim( (string) $p );
		if ( '' === $p ) {
			continue;
		}
		$path = preg_match( '#^https?://#i', $p ) ? (string) wp_parse_url( $p, PHP_URL_PATH ) : $p;
		$star = false !== strpos( $path, '*' );
		$path = '/' . ltrim( rawurldecode( $path ), '/' );
		$enc  = implode( '.*', array_map( function ( $x ) {
			return sap_focus_re_quote( implode( '/', array_map( 'rawurlencode', explode( '/', $x ) ) ) );
		}, explode( '*', $path ) ) );
		if ( $star ) {
			$parts[] = $enc . '.*';
		} else {
			// A prefix like /product-category/insole/ also covers its sub-paths (page/2 …).
			$parts[] = sap_focus_re_quote( implode( '/', array_map( 'rawurlencode', explode( '/', rtrim( $path, '/' ) ) ) ) ) . '(/.*)?';
		}
	}
	foreach ( $pages as $u ) {
		$parts[] = sap_focus_re_quote( sap_focus_enc_path( $u ) ) . '/?';
	}
	$parts = array_values( array_unique( $parts ) );
	$out   = array();
	$buf   = array();
	$len   = 0;
	foreach ( $parts as $p ) {
		if ( $buf && $len + strlen( $p ) > 3000 ) {
			$out[] = $buf;
			$buf   = array();
			$len   = 0;
		}
		$buf[] = $p;
		$len  += strlen( $p ) + 1;
	}
	if ( $buf ) {
		$out[] = $buf;
	}
	return array_map( function ( $b ) {
		return '(?i)^https?://[^/]+(' . implode( '|', $b ) . ')(\?.*)?$';
	}, $out );
}

/**
 * Sum of a daily series between two dates (inclusive).
 */
function sap_focus_sum( $series, $from, $to ) {
	$c = 0;
	$i = 0;
	$n = 0;
	foreach ( $series as $d => $v ) {
		if ( $d >= $from && $d <= $to ) {
			$c += $v[0];
			$i += $v[1];
			++$n;
		}
	}
	return array( 'c' => $c, 'i' => $i, 'days' => $n );
}

/**
 * Measure a focus now and store it on the focus.
 *
 * @return array The measure.
 */
function sap_focus_measure_refresh( $project, $focus ) {
	$pages    = sap_focus_page_set( $project['id'], $focus );
	$exact    = array_values( $pages );
	$patterns = $focus['url_patterns'];
	// Exact pages already covered by a pattern do not need their own regex part.
	if ( $patterns ) {
		$exact = array_values( array_filter( $exact, function ( $u ) use ( $patterns ) {
			return ! sap_url_matches( $u, $patterns );
		} ) );
	}
	$m = array( 'at' => sap_now(), 'pages' => count( $pages ), 'src' => 'local' );
	if ( $pages && '' !== (string) $project['gsc_property'] && sap_google_connected() ) {
		$start  = $focus['started_on'] ?: current_time( 'Y-m-d' );
		$from   = gmdate( 'Y-m-d', max( strtotime( $start . ' -56 days' ), strtotime( current_time( 'Y-m-d' ) . ' -480 days' ) ) );
		$to     = current_time( 'Y-m-d' );
		$series = array();
		$err    = '';
		foreach ( sap_focus_regexes( $exact, $patterns ) as $re ) {
			$rows = sap_gsc_query( $project['gsc_property'], $from, $to, array( 'date' ), 1000, array(
				'dataState'             => 'all',
				'dimensionFilterGroups' => array( array( 'filters' => array( array( 'dimension' => 'page', 'operator' => 'includingRegex', 'expression' => $re ) ) ) ),
			) );
			if ( is_wp_error( $rows ) ) {
				$err = $rows->get_error_message();
				break;
			}
			foreach ( $rows as $r ) {
				$d            = $r['date'];
				$series[ $d ] = array( ( $series[ $d ][0] ?? 0 ) + $r['clicks'], ( $series[ $d ][1] ?? 0 ) + $r['impressions'] );
			}
		}
		if ( '' === $err ) {
			ksort( $series );
			// Today is still filling up: the window ends at the last full day.
			$last = $series ? array_key_last( $series ) : gmdate( 'Y-m-d', strtotime( $to . ' -1 day' ) );
			if ( $last >= current_time( 'Y-m-d' ) ) {
				$last = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) );
			}
			$cur_from = gmdate( 'Y-m-d', strtotime( $last . ' -27 days' ) );
			$b_from   = gmdate( 'Y-m-d', strtotime( $start . ' -28 days' ) );
			$b_to     = gmdate( 'Y-m-d', strtotime( $start . ' -1 day' ) );
			$weeks    = array();
			for ( $e = strtotime( $last ); $e >= strtotime( $from ) + 6 * DAY_IN_SECONDS && count( $weeks ) < 30; $e -= 7 * DAY_IN_SECONDS ) {
				$wf      = gmdate( 'Y-m-d', $e - 6 * DAY_IN_SECONDS );
				$wt      = gmdate( 'Y-m-d', $e );
				$s       = sap_focus_sum( $series, $wf, $wt );
				$weeks[] = array( $wf, $wt, $s['c'], $s['i'] );
			}
			$m = array_merge( $m, array(
				'src'   => 'gsc',
				'last'  => $last,
				'cur'   => array_merge( sap_focus_sum( $series, $cur_from, $last ), array( 'from' => $cur_from, 'to' => $last ) ),
				'base'  => $b_to >= $from ? array_merge( sap_focus_sum( $series, $b_from, $b_to ), array( 'from' => $b_from, 'to' => $b_to ) ) : null,
				'weeks' => array_reverse( $weeks ),
			) );
		} else {
			$m['err'] = $err;
		}
	}
	if ( 'gsc' !== $m['src'] ) {
		// Without a live query: page totals of the last Search Console sync.
		$ds = sap_dataset( $project['id'], 'gsc_pages' );
		$c  = 0;
		$i  = 0;
		foreach ( (array) ( $ds['payload']['cur'] ?? array() ) as $r ) {
			if ( isset( $pages[ sap_norm_url( $r['page'] ) ] ) || sap_url_matches( $r['page'], $patterns ) ) {
				$c += $r['clicks'];
				$i += $r['impressions'];
			}
		}
		$w      = (array) ( $ds['meta']['cur'] ?? array() );
		$m['cur'] = array( 'c' => $c, 'i' => $i, 'from' => (string) ( $w[0] ?? '' ), 'to' => (string) ( $w[1] ?? '' ) );
	}
	global $wpdb;
	$wpdb->update( sap_table( 'focuses' ), array( 'measure' => wp_json_encode( $m, JSON_UNESCAPED_UNICODE ) ), array( 'id' => $focus['id'] ) );
	return $m;
}

/**
 * Refresh every active focus of a project (after a Search Console sync).
 */
function sap_focus_measure_all( $project ) {
	foreach ( sap_focuses( $project['id'] ) as $f ) {
		if ( 'paused' !== $f['status'] ) {
			sap_focus_measure_refresh( $project, $f );
		}
	}
}

/**
 * The baseline that counts: the number you entered, else the measured 28 days before the start.
 *
 * @return array {value, from, to, manual}
 */
function sap_focus_baseline( $focus ) {
	$metric = $focus['kpi_metric'];
	$m      = $focus['measure'];
	if ( $focus['kpi_baseline'] > 0 ) {
		return array( 'value' => $focus['kpi_baseline'], 'from' => $m['base']['from'] ?? '', 'to' => $m['base']['to'] ?? '', 'manual' => true, 'measured' => isset( $m['base'] ) && in_array( $metric, array( 'clicks', 'impressions' ), true ) ? ( 'clicks' === $metric ? $m['base']['c'] : $m['base']['i'] ) : null );
	}
	if ( ! empty( $m['base'] ) && in_array( $metric, array( 'clicks', 'impressions' ), true ) ) {
		return array( 'value' => 'clicks' === $metric ? $m['base']['c'] : $m['base']['i'], 'from' => $m['base']['from'], 'to' => $m['base']['to'], 'manual' => false, 'measured' => null );
	}
	return array( 'value' => 0.0, 'from' => '', 'to' => '', 'manual' => false, 'measured' => null );
}

/**
 * Status of a focus goal: progress, pace against the deadline, a plain sentence.
 *
 * @return array|null {current, baseline, target, pct, state(reached|on|behind|nogoal), text, days_left}
 */
function sap_focus_status( $focus, $kpis ) {
	if ( ! $kpis ) {
		return null;
	}
	$metric = $focus['kpi_metric'];
	$cur    = (float) ( $kpis[ $metric ] ?? 0 );
	$base   = sap_focus_baseline( $focus );
	$b      = (float) $base['value'];
	$t      = (float) $focus['kpi_target'];
	$out    = array( 'current' => $cur, 'baseline' => $base, 'target' => $t, 'pct' => null, 'state' => 'nogoal', 'days_left' => null, 'text' => '' );
	if ( $t <= 0 ) {
		$out['text'] = 'هدف عددی تعیین نشده.';
		return $out;
	}
	$span       = $t - $b;
	$out['pct'] = abs( $span ) < 1e-9 ? ( $cur >= $t ? 1 : 0 ) : max( 0, min( 1, ( $cur - $b ) / $span ) );
	$today      = current_time( 'Y-m-d' );
	if ( $focus['deadline'] ) {
		$out['days_left'] = (int) floor( ( strtotime( $focus['deadline'] ) - strtotime( $today ) ) / DAY_IN_SECONDS );
	}
	if ( $t <= $b && $b > 0 ) {
		$out['pct']   = $cur >= $t ? 1 : 0;
		$out['state'] = $cur >= $t ? 'reached' : 'behind';
		$out['text']  = 'هدف از نقطه شروع کمتر است؛ یعنی رشدی لازم نبوده. هدف را بالاتر از ' . sap_num( $b ) . ' بگذارید.';
		return $out;
	}
	if ( $cur >= $t ) {
		$out['pct']   = 1;
		$out['state'] = 'reached';
		$out['text']  = 'به هدف رسیده است.';
		return $out;
	}
	// Pace: share of the time passed between the start and the deadline.
	$expected = null;
	if ( $focus['deadline'] && $focus['started_on'] ) {
		$total = max( 1, strtotime( $focus['deadline'] ) - strtotime( $focus['started_on'] ) );
		$expected = max( 0, min( 1, ( strtotime( $today ) - strtotime( $focus['started_on'] ) ) / $total ) );
	}
	if ( null !== $out['days_left'] && $out['days_left'] < 0 ) {
		$out['state'] = 'behind';
		$out['text']  = 'مهلت گذشته و به هدف نرسیده.';
	} elseif ( null === $expected || $out['pct'] + 0.1 >= $expected ) {
		$out['state'] = 'on';
		$out['text']  = null === $expected ? 'در حال پیشرفت.' : 'طبق برنامه پیش می‌رود.';
	} else {
		$out['state'] = 'behind';
		$out['text']  = sprintf( 'عقب‌تر از برنامه: باید حدود %s٪ راه رفته بود.', sap_num( round( 100 * $expected ) ) );
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * REST: page search and live preview for the focus form
 * ------------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	$ns = 'seoactionplan/v1';
	register_rest_route( $ns, '/focus-pages/(?P<project>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$q   = sap_norm_text( trim( (string) $req->get_param( 'q' ) ) );
			$out = array();
			if ( mb_strlen( $q ) < 2 ) {
				return array( 'items' => array() );
			}
			$gsc = array();
			foreach ( (array) ( sap_dataset_payload( (int) $req['project'], 'gsc_pages' )['cur'] ?? array() ) as $r ) {
				$gsc[ sap_norm_url( $r['page'] ) ] = (int) $r['clicks'];
			}
			foreach ( sap_site_inventory( (int) $req['project'] ) as $k => $it ) {
				if ( '' !== (string) $it['redirect'] ) {
					continue;
				}
				if ( false !== mb_strpos( sap_norm_text( $it['title'] ), $q ) || false !== mb_strpos( sap_norm_text( rawurldecode( $it['url'] ) ), $q ) ) {
					$out[] = array( 'url' => $it['url'], 'title' => $it['title'], 'type' => sap_content_types()[ $it['ctype'] ] ?? $it['ctype'], 'clicks' => $gsc[ $k ] ?? 0 );
				}
			}
			usort( $out, function ( $a, $b ) {
				return $b['clicks'] <=> $a['clicks'];
			} );
			return array( 'items' => array_slice( $out, 0, 25 ) );
		},
	) );
	register_rest_route( $ns, '/focus-preview/(?P<project>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$pid   = (int) $req['project'];
			$cats  = array();
			foreach ( (array) $req->get_param( 'cats' ) as $u => $w ) {
				$cats[ esc_url_raw( (string) $u ) ] = (bool) $w;
			}
			$focus = array(
				'scope'        => array( 'cats' => $cats, 'pages' => array_map( 'esc_url_raw', (array) $req->get_param( 'pages' ) ) ),
				'url_patterns' => sap_rows( (string) $req->get_param( 'patterns' ) ),
			);
			$set = sap_focus_page_set( $pid, $focus );
			$c   = 0;
			$i   = 0;
			$ds  = sap_dataset( $pid, 'gsc_pages' );
			foreach ( (array) ( $ds['payload']['cur'] ?? array() ) as $r ) {
				if ( isset( $set[ sap_norm_url( $r['page'] ) ] ) || sap_url_matches( $r['page'], $focus['url_patterns'] ) ) {
					$c += $r['clicks'];
					$i += $r['impressions'];
				}
			}
			$w = (array) ( $ds['meta']['cur'] ?? array() );
			return array(
				'pages'  => count( $set ),
				'clicks' => $c,
				'impr'   => $i,
				'range'  => $w ? sap_jdate( $w[0], 'day' ) . ' تا ' . sap_jdate( $w[1], 'day' ) : '',
			);
		},
	) );
	register_rest_route( $ns, '/focus-measure/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$f = sap_focus( (int) $req['id'] );
			$p = $f ? sap_project( $f['project_id'] ) : null;
			if ( ! $p ) {
				return new WP_Error( 'sap_focus', 'Focus پیدا نشد.', array( 'status' => 404 ) );
			}
			$m = sap_focus_measure_refresh( $p, $f );
			return array( 'ok' => true, 'msg' => ! empty( $m['err'] ) ? 'سرچ کنسول جواب نداد: ' . $m['err'] : 'داده Focus از سرچ کنسول به‌روز شد.' );
		},
	) );
} );
