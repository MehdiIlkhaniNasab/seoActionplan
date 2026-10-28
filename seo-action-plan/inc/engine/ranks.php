<?php
/**
 * Rank tracking: a daily history of every tracked keyword, kept for good in
 * Sokan (not limited to the 30–90 days of the source):
 *  - SEO Signal rank tracker: the exact Google position of our domain and of the
 *    competitors, one snapshot a day;
 *  - Search Console: average position, clicks and impressions per keyword per
 *    day (backfilled 90 days on the first run).
 * On top: visibility index (CTR-weighted), position distribution, winners and
 * losers, ranking-URL switches (cannibalisation) and share of voice.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Expected CTR of an organic position (blended desktop / mobile curve).
 */
function sap_ctr_at( $pos ) {
	if ( null === $pos || $pos <= 0 ) {
		return 0.0;
	}
	$curve = array( 1 => 0.28, 2 => 0.155, 3 => 0.10, 4 => 0.072, 5 => 0.053, 6 => 0.04, 7 => 0.032, 8 => 0.026, 9 => 0.021, 10 => 0.018 );
	$p     = (int) round( $pos );
	if ( isset( $curve[ $p ] ) ) {
		return $curve[ $p ];
	}
	return $p <= 20 ? 0.01 : ( $p <= 30 ? 0.004 : 0.0 );
}

/**
 * Tracked keywords: SEO Signal tracker + Focus keywords + main keywords of mapped
 * pages that have impressions + the ones you add. keyword key => info.
 */
function sap_rank_keywords( $project ) {
	static $cache = array();
	$pid = $project['id'];
	if ( isset( $cache[ $pid ] ) ) {
		return $cache[ $pid ];
	}
	$vols  = (array) ( sap_dataset_payload( $pid, 'ss_volumes' )['volumes'] ?? array() );
	$out   = array();
	$add   = function ( $kw, $src, $focus = '' ) use ( &$out, $vols ) {
		$kw = trim( (string) $kw );
		$k  = sap_kw_key( $kw );
		if ( '' === $k || mb_strlen( $kw ) > 120 ) {
			return;
		}
		if ( ! isset( $out[ $k ] ) ) {
			$out[ $k ] = array( 'keyword' => $kw, 'sources' => array(), 'focus' => '', 'volume' => (int) ( $vols[ $k ]['volume'] ?? 0 ) );
		}
		$out[ $k ]['sources'][ $src ] = true;
		if ( '' !== $focus && '' === $out[ $k ]['focus'] ) {
			$out[ $k ]['focus'] = $focus;
		}
	};
	$ss = sap_dataset_payload( $pid, 'ss_rank' );
	foreach ( (array) ( $ss['keywords'] ?? array() ) as $r ) {
		$add( $r['keyword'], 'ss' );
		if ( ! empty( $r['volume'] ) ) {
			$out[ sap_kw_key( $r['keyword'] ) ]['volume'] = max( $out[ sap_kw_key( $r['keyword'] ) ]['volume'], (int) $r['volume'] );
		}
	}
	foreach ( sap_focuses( $pid ) as $f ) {
		foreach ( (array) $f['keywords'] as $kw ) {
			$add( $kw, 'focus', $f['name'] );
		}
	}
	$impr = sap_url_ctx( $pid )['impr'];
	$map  = array_values( array_filter( sap_keymap( $pid ), function ( $m ) {
		return '' !== $m['primary_kw'] && sap_keymap_has_kw( $m['status'] );
	} ) );
	usort( $map, function ( $a, $b ) use ( $impr ) {
		return ( $impr[ sap_norm_url( $b['url'] ) ] ?? 0 ) <=> ( $impr[ sap_norm_url( $a['url'] ) ] ?? 0 );
	} );
	foreach ( array_slice( $map, 0, 150 ) as $m ) {
		if ( ( $impr[ sap_norm_url( $m['url'] ) ] ?? 0 ) > 0 ) {
			$add( $m['primary_kw'], 'map' );
		}
	}
	foreach ( sap_list( (string) ( $project['options']['rank_keywords'] ?? '' ) ) as $kw ) {
		$add( $kw, 'manual' );
	}
	return $cache[ $pid ] = array_slice( $out, 0, 500, true );
}

/**
 * Store one day row.
 */
function sap_rank_put( $project_id, $keyword, $day, $source, $domain, $position, $url = '', $impr = 0, $clicks = 0 ) {
	global $wpdb;
	$t = sap_table( 'ranks' );
	$wpdb->query( $wpdb->prepare( // phpcs:ignore
		"INSERT INTO $t (project_id, keyword, day, source, domain, position, url, impressions, clicks) VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %d)
		ON DUPLICATE KEY UPDATE position = VALUES(position), url = VALUES(url), impressions = VALUES(impressions), clicks = VALUES(clicks)",
		$project_id, mb_substr( sap_norm_text( $keyword ), 0, 190 ), $day, $source, $domain, null === $position ? null : round( (float) $position, 2 ), (string) $url, (int) $impr, (int) $clicks
	) );
}

/**
 * Today's SEO Signal snapshot: our domain and the competitors.
 *
 * @return int|WP_Error rows stored
 */
function sap_ranks_sync_ss( $project ) {
	if ( ! sap_ss_connected() || ! $project['ss_project_id'] ) {
		return 0;
	}
	$detail = sap_ss_rt_detail( $project['ss_project_id'] );
	if ( is_wp_error( $detail ) ) {
		return $detail;
	}
	$device = (int) ( 'desktop' === $project['ss_device'] ? ( $detail['desktop_id'] ?? 0 ) : ( $detail['mobile_id'] ?? 0 ) );
	if ( ! $device ) {
		return 0;
	}
	$day     = current_time( 'Y-m-d' );
	$n       = 0;
	$domains = array_merge( array( '' ), array_slice( array_values( array_filter( array_map( function ( $d ) {
		return is_array( $d ) ? (string) ( $d['domain'] ?? $d['Domain'] ?? '' ) : (string) $d;
	}, (array) ( $detail['competitor_domains'] ?? array() ) ) ) ), 0, 5 ) );
	foreach ( $domains as $dom ) {
		$st = sap_ss_rt_status( $project['ss_project_id'], $device, $dom );
		if ( is_wp_error( $st ) ) {
			if ( '' === $dom ) {
				return $st;
			}
			continue;
		}
		foreach ( $st['rows'] as $r ) {
			sap_rank_put( $project['id'], $r['keyword'], $day, 'ss', sap_clean_domain( $dom ), $r['rank'] > 0 ? $r['rank'] : null, $r['target_url'] );
			++$n;
		}
	}
	return $n;
}

/**
 * Search Console daily positions of the tracked keywords (last $days days, ending 3 days ago).
 *
 * @return int|WP_Error rows stored
 */
function sap_ranks_sync_gsc( $project, $days = 7 ) {
	if ( '' === $project['gsc_property'] || ! sap_google_connected() ) {
		return 0;
	}
	$track = sap_rank_keywords( $project );
	if ( ! $track ) {
		return 0;
	}
	$end   = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 3 * DAY_IN_SECONDS ); // phpcs:ignore
	$start = gmdate( 'Y-m-d', strtotime( $end ) - ( $days - 1 ) * DAY_IN_SECONDS );
	$rows  = sap_gsc_query( $project['gsc_property'], $start, $end, array( 'query', 'date' ), 25000 );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	// Ranking page of each keyword over the period (most impressions).
	$pages = sap_gsc_query( $project['gsc_property'], $start, $end, array( 'query', 'page' ), 25000 );
	$top   = array();
	foreach ( is_wp_error( $pages ) ? array() : $pages as $r ) {
		$k = sap_kw_key( $r['query'] );
		if ( isset( $track[ $k ] ) && ( ! isset( $top[ $k ] ) || $r['impressions'] > $top[ $k ]['impressions'] ) ) {
			$top[ $k ] = $r;
		}
	}
	$n = 0;
	foreach ( $rows as $r ) {
		$k = sap_kw_key( $r['query'] );
		if ( ! isset( $track[ $k ] ) ) {
			continue;
		}
		sap_rank_put( $project['id'], $track[ $k ]['keyword'], $r['date'], 'gsc', '', $r['position'], $top[ $k ]['page'] ?? '', $r['impressions'], $r['clicks'] );
		++$n;
	}
	return $n;
}

/**
 * Daily update of a project's rank history (backfills Search Console the first time).
 */
function sap_ranks_daily( $project ) {
	$ss  = sap_ranks_sync_ss( $project );
	$new = ! get_option( 'sap_ranks_backfill_' . $project['id'] );
	$gsc = sap_ranks_sync_gsc( $project, $new ? 90 : 7 );
	if ( $new && ! is_wp_error( $gsc ) && $gsc ) {
		update_option( 'sap_ranks_backfill_' . $project['id'], 1, false );
	}
	return array( 'ss' => $ss, 'gsc' => $gsc );
}

/**
 * Sync step.
 */
function sap_step_ranks( $project ) {
	$r = sap_ranks_daily( $project );
	foreach ( $r as $v ) {
		if ( is_wp_error( $v ) ) {
			return $v;
		}
	}
	if ( ! $r['ss'] && ! $r['gsc'] ) {
		return sap_skip( 'منبع رتبه (ردیاب سئو سیگنال یا سرچ کنسول) وصل نیست یا کلمه‌ای برای ردیابی نیست.' );
	}
	return array( 'msg' => sprintf( 'تاریخچه رتبه بروز شد: %s ردیف سئو سیگنال (ما و رقبا)، %s ردیف روزانه سرچ کنسول؛ %s کلمه ردیابی می‌شود.', sap_num( (int) $r['ss'] ), sap_num( (int) $r['gsc'] ), sap_num( count( sap_rank_keywords( $project ) ) ) ) );
}

/**
 * The best source we have for a project's own positions: 'ss' when the tracker
 * has rows, else 'gsc'.
 */
function sap_rank_source( $project_id ) {
	global $wpdb;
	$has = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . sap_table( 'ranks' ) . " WHERE project_id = %d AND source = 'ss' AND domain = ''", $project_id ) ); // phpcs:ignore
	return $has ? 'ss' : 'gsc';
}

/**
 * Daily rows of a project: keyword => day => row (own domain, one source).
 */
function sap_rank_history( $project_id, $days = 90, $source = '', $domain = '' ) {
	global $wpdb;
	$source = $source ?: sap_rank_source( $project_id );
	$since  = gmdate( 'Y-m-d', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore
	$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT keyword, day, position, url, impressions, clicks FROM ' . sap_table( 'ranks' ) . ' WHERE project_id = %d AND source = %s AND domain = %s AND day >= %s ORDER BY day ASC', $project_id, $source, $domain, $since ), ARRAY_A ); // phpcs:ignore
	$out    = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r['keyword'] ][ $r['day'] ] = array( 'pos' => null === $r['position'] ? null : (float) $r['position'], 'url' => (string) $r['url'], 'impr' => (int) $r['impressions'], 'clicks' => (int) $r['clicks'] );
	}
	return $out;
}

/**
 * Position on or before a day (nearest earlier snapshot within 3 days).
 */
function sap_rank_at( $series, $day ) {
	for ( $i = 0; $i <= 3; $i++ ) {
		$d = gmdate( 'Y-m-d', strtotime( $day ) - $i * DAY_IN_SECONDS );
		if ( isset( $series[ $d ] ) ) {
			return $series[ $d ];
		}
	}
	return null;
}

/**
 * Keyword table: now, change 1 / 7 / 30 days, best, url (and switch), sparkline.
 */
function sap_rank_table( $project, $source = '' ) {
	$pid    = $project['id'];
	$source = $source ?: sap_rank_source( $pid );
	$hist   = sap_rank_history( $pid, 90, $source );
	$track  = sap_rank_keywords( $project );
	$gsc    = 'gsc' === $source ? $hist : sap_rank_history( $pid, 35, 'gsc' );
	$last   = '';
	foreach ( $hist as $series ) {
		$last = max( $last, (string) array_key_last( $series ) );
	}
	$out = array();
	foreach ( $track as $k => $info ) {
		$key    = sap_norm_text( $info['keyword'] );
		$series = $hist[ $key ] ?? array();
		$now    = $last ? sap_rank_at( $series, $last ) : null;
		$d1     = $last ? sap_rank_at( $series, gmdate( 'Y-m-d', strtotime( $last ) - DAY_IN_SECONDS ) ) : null;
		$d7     = $last ? sap_rank_at( $series, gmdate( 'Y-m-d', strtotime( $last ) - 7 * DAY_IN_SECONDS ) ) : null;
		$d30    = $last ? sap_rank_at( $series, gmdate( 'Y-m-d', strtotime( $last ) - 30 * DAY_IN_SECONDS ) ) : null;
		$best   = null;
		$spark  = array();
		foreach ( $series as $day => $r ) {
			if ( null !== $r['pos'] && ( null === $best || $r['pos'] < $best ) ) {
				$best = $r['pos'];
			}
		}
		foreach ( array_slice( $series, -30, null, true ) as $r ) {
			$spark[] = $r['pos'];
		}
		$g      = $gsc[ $key ] ?? array();
		$g28    = array_slice( $g, -28, null, true );
		$urls   = array_values( array_filter( array_unique( array_column( array_slice( $series, -14, null, true ), 'url' ) ) ) );
		$out[]  = array(
			'keyword'   => $info['keyword'],
			'focus'     => $info['focus'],
			'volume'    => $info['volume'],
			'sources'   => array_keys( $info['sources'] ),
			'now'       => $now['pos'] ?? null,
			'd1'        => $d1['pos'] ?? null,
			'd7'        => $d7['pos'] ?? null,
			'd30'       => $d30['pos'] ?? null,
			'best'      => $best,
			'url'       => $now['url'] ?? ( end( $urls ) ?: '' ),
			'url_count' => count( $urls ),
			'spark'     => $spark,
			'clicks'    => array_sum( array_column( $g28, 'clicks' ) ),
			'impr'      => array_sum( array_column( $g28, 'impr' ) ),
		);
	}
	return array( 'rows' => $out, 'last' => $last, 'source' => $source );
}

/**
 * Visibility index per day (0–100): CTR-weighted share of the clicks we would get
 * at position 1 for every tracked keyword (volume-weighted when volumes are known).
 */
function sap_rank_visibility( $project, $days = 90, $source = '', $domain = '' ) {
	$pid    = $project['id'];
	$source = $source ?: sap_rank_source( $pid );
	$hist   = sap_rank_history( $pid, $days, $source, $domain );
	$track  = sap_rank_keywords( $project );
	$w      = array();
	foreach ( $track as $info ) {
		$w[ sap_norm_text( $info['keyword'] ) ] = max( 10, (int) $info['volume'] );
	}
	$max = array_sum( $w ) * sap_ctr_at( 1 );
	if ( ! $max ) {
		return array();
	}
	$by_day = array();
	foreach ( $hist as $kw => $series ) {
		if ( ! isset( $w[ $kw ] ) ) {
			continue;
		}
		foreach ( $series as $day => $r ) {
			$by_day[ $day ] = ( $by_day[ $day ] ?? 0 ) + $w[ $kw ] * sap_ctr_at( $r['pos'] );
		}
	}
	ksort( $by_day );
	return array_map( function ( $v ) use ( $max ) {
		return round( $v / $max * 100, 2 );
	}, $by_day );
}

/**
 * Position buckets of the latest snapshot.
 */
function sap_rank_distribution( $rows ) {
	$d = array( 'top3' => 0, 'top10' => 0, 'top20' => 0, 'top50' => 0, 'out' => 0 );
	foreach ( $rows as $r ) {
		$p = $r['now'];
		if ( null === $p ) {
			++$d['out'];
		} elseif ( $p <= 3 ) {
			++$d['top3'];
		} elseif ( $p <= 10 ) {
			++$d['top10'];
		} elseif ( $p <= 20 ) {
			++$d['top20'];
		} elseif ( $p <= 50 ) {
			++$d['top50'];
		} else {
			++$d['out'];
		}
	}
	return $d;
}

/**
 * Keywords that moved over $days (for alerts and the "movers" lists).
 *
 * @return array|null {rows: [keyword, now, then, url, url_then, url_changed]}
 */
function sap_rank_movers( $project_id, $days = 7 ) {
	$hist = sap_rank_history( $project_id, $days + 10 );
	if ( ! $hist ) {
		return null;
	}
	$last = '';
	foreach ( $hist as $series ) {
		$last = max( $last, (string) array_key_last( $series ) );
	}
	$then_day = gmdate( 'Y-m-d', strtotime( $last ) - $days * DAY_IN_SECONDS );
	$project  = sap_project( $project_id );
	$tracked  = $project ? array_map( function ( $i ) {
		return sap_norm_text( $i['keyword'] );
	}, sap_rank_keywords( $project ) ) : array();
	$rows     = array();
	foreach ( $hist as $kw => $series ) {
		if ( $tracked && ! in_array( $kw, $tracked, true ) ) {
			continue;
		}
		$now    = sap_rank_at( $series, $last );
		$then   = sap_rank_at( $series, $then_day );
		$rows[] = array(
			'keyword'     => $kw,
			'now'         => $now['pos'] ?? null,
			'then'        => $then['pos'] ?? null,
			'url'         => (string) ( $now['url'] ?? '' ),
			'url_then'    => (string) ( $then['url'] ?? '' ),
			'url_changed' => $now && $then && '' !== $now['url'] && '' !== $then['url'] && sap_norm_url( $now['url'] ) !== sap_norm_url( $then['url'] ),
		);
	}
	return array( 'rows' => $rows, 'last' => $last );
}

/**
 * Share of voice per domain (us + competitors) from the SEO Signal snapshots of the last day.
 */
function sap_rank_share_of_voice( $project ) {
	global $wpdb;
	$t    = sap_table( 'ranks' );
	$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(day) FROM $t WHERE project_id = %d AND source = 'ss'", $project['id'] ) ); // phpcs:ignore
	if ( ! $last ) {
		return array();
	}
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT keyword, domain, position FROM $t WHERE project_id = %d AND source = 'ss' AND day = %s", $project['id'], $last ), ARRAY_A ); // phpcs:ignore
	$track = sap_rank_keywords( $project );
	$w     = array();
	foreach ( $track as $info ) {
		$w[ sap_norm_text( $info['keyword'] ) ] = max( 10, (int) $info['volume'] );
	}
	$sum = array();
	foreach ( (array) $rows as $r ) {
		$d         = '' === $r['domain'] ? sap_project_domain( $project ) : $r['domain'];
		$sum[ $d ] = ( $sum[ $d ] ?? 0 ) + ( $w[ $r['keyword'] ] ?? 10 ) * sap_ctr_at( null === $r['position'] ? null : (float) $r['position'] );
	}
	$total = array_sum( $sum ) ?: 1;
	arsort( $sum );
	return array_map( function ( $v ) use ( $total ) {
		return round( $v / $total * 100, 1 );
	}, $sum );
}

/**
 * Competitor positions of the latest SEO Signal day: keyword => domain => position.
 */
function sap_rank_competitors( $project_id ) {
	global $wpdb;
	$t    = sap_table( 'ranks' );
	$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(day) FROM $t WHERE project_id = %d AND source = 'ss' AND domain <> ''", $project_id ) ); // phpcs:ignore
	if ( ! $last ) {
		return array();
	}
	$out = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT keyword, domain, position FROM $t WHERE project_id = %d AND source = 'ss' AND domain <> '' AND day = %s", $project_id, $last ), ARRAY_A ) as $r ) { // phpcs:ignore
		$out[ $r['keyword'] ][ $r['domain'] ] = null === $r['position'] ? null : (float) $r['position'];
	}
	return $out;
}

add_action( 'sap_hourly', function () {
	$today = current_time( 'Y-m-d' );
	if ( (int) current_time( 'G' ) < 5 || get_option( 'sap_ranks_day' ) === $today ) {
		return;
	}
	update_option( 'sap_ranks_day', $today, false );
	foreach ( sap_projects() as $p ) {
		sap_ranks_daily( $p );
	}
}, 5 ); // Before the daily alert rules (priority 10) so ranking drops are seen the same morning.
