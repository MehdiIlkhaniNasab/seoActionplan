<?php
/**
 * Vetted keyword candidates for new content.
 *
 * Before the AI writes a single idea, every candidate keyword goes through
 * hard rules so the ideas are strategic and never duplicate what the site
 * already targets:
 *
 *  1. Sources: real Search Console queries, user questions (SEO Signal) for
 *     the focuses, and competitor gap keywords (SEO Signal site compare).
 *  2. Already ranking 1–5 anywhere on the site → excluded (no new content).
 *  3. Already targeted (primary / secondary keyword of a page in the keyword
 *     map, a calendar row or an idea) → excluded.
 *  4. A page already ranks 6–20 for it with the same topic → «optimize that
 *     page» proposal instead of new content.
 *  5. Intent decides the page type: informational / comparison → blog idea;
 *     buying intent → category / product page opportunity (not a blog post);
 *     brand / navigational → dropped.
 *  6. Near-duplicates merge into one topic (main keyword + secondaries).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is a keyword already targeted? Compares topic cores and word overlap with
 * every primary / secondary keyword of the map, the calendar and the ideas.
 *
 * @param array $targets core => [label, url].
 * @return array|null The target it collides with.
 */
function sap_pool_covered( $kw, $targets ) {
	$core = sap_kw_core( $kw );
	if ( isset( $targets[ $core ] ) ) {
		return $targets[ $core ];
	}
	$words = array_flip( explode( ' ', $core ) );
	if ( count( $words ) < 2 ) {
		return null;
	}
	foreach ( $targets as $tc => $t ) {
		$tw    = array_flip( explode( ' ', $tc ) );
		$inter = count( array_intersect_key( $words, $tw ) );
		$union = count( $words + $tw );
		if ( $union && $inter / $union >= 0.75 ) {
			return $t;
		}
	}
	return null;
}

/**
 * Build the candidate pool.
 *
 * @return array{blog: array, landing: array, optimize: array, excluded: array, sources: array}
 */
function sap_ideas_pool( $project ) {
	$pid = $project['id'];
	$ctx = sap_analysis_context( $project );
	$min = max( 10, (int) floor( (int) $ctx['settings']['min_impressions'] / 2 ) );

	// Everything the site already targets.
	$targets = array();
	foreach ( $ctx['keymap'] as $m ) {
		foreach ( array_merge( array( $m['primary_kw'] ), (array) $m['secondary'] ) as $i => $kw ) {
			if ( '' !== trim( (string) $kw ) ) {
				$targets[ sap_kw_core( $kw ) ] = array( 'type' => 0 === $i ? 'primary' : 'secondary', 'label' => $m['title'] ?: $m['primary_kw'], 'url' => $m['url'] );
			}
		}
	}
	foreach ( (array) ( $ctx['sheet_calendar']['rows'] ?? array() ) as $r ) {
		$targets[ sap_kw_core( $r['keyword'] ) ] = array( 'type' => 'calendar', 'label' => $r['keyword'], 'url' => $r['url'] ?? '' );
		foreach ( (array) ( $r['related_list'] ?? array() ) as $k ) {
			$targets[ sap_kw_core( $k ) ] = array( 'type' => 'calendar', 'label' => $r['keyword'], 'url' => '' );
		}
	}
	foreach ( sap_ideas( $pid ) as $i ) {
		$targets[ sap_kw_core( $i['keyword'] ) ] = array( 'type' => 'idea', 'label' => $i['keyword'], 'url' => $i['published_url'] );
	}

	// Raw candidates.
	$cands = array();
	$add   = function ( $kw, $src, $vol, $evidence ) use ( &$cands ) {
		$kw = trim( preg_replace( '/\s+/u', ' ', (string) $kw ) );
		if ( '' === $kw || mb_strlen( $kw ) < 3 ) {
			return;
		}
		$k = sap_kw_key( $kw );
		if ( ! isset( $cands[ $k ] ) ) {
			$cands[ $k ] = array( 'keyword' => $kw, 'sources' => array(), 'volume' => 0, 'impr' => 0, 'evidence' => array() );
		}
		$cands[ $k ]['sources'][ $src ] = true;
		$cands[ $k ]['volume']          = max( $cands[ $k ]['volume'], (int) $vol );
		$cands[ $k ]['evidence'][]      = $evidence;
	};
	foreach ( $ctx['by_query'] as $rows ) {
		$impr = array_sum( array_column( $rows, 'impressions' ) );
		if ( $impr >= $min ) {
			$q = $rows[0]['query'];
			$add( $q, 'gsc', sap_ctx_volume( $ctx, $q ), sprintf( 'سرچ کنسول: %s نمایش', sap_num( $impr ) ) );
		}
	}
	foreach ( (array) ( $ctx['ss_questions']['focuses'] ?? array() ) as $fq ) {
		foreach ( array_slice( sap_sort( (array) ( $fq['rows'] ?? array() ), 'volume' ), 0, 40 ) as $r ) {
			$add( $r['word'], 'questions', $r['volume'], sprintf( 'سوال کاربران (%s): حجم %s', $fq['keyword'] ?? '', sap_num( $r['volume'] ) ) );
		}
	}
	foreach ( (array) ( $ctx['ss_compare']['competitors'] ?? array() ) as $domain => $c ) {
		foreach ( array_slice( sap_sort( (array) ( $c['gap'] ?? array() ), 'volume' ), 0, 150 ) as $r ) {
			$word = $r['word'] ?? ( $r['Word'] ?? '' );
			$add( $word, 'competitor', $r['volume'] ?? ( $r['SearchVolume'] ?? 0 ), sprintf( 'گپ رقیب %s: رتبه %s، حجم %s', $domain, sap_num( (float) ( $r['rank2'] ?? $r['Rank2'] ?? 0 ) ), sap_num( (int) ( $r['volume'] ?? $r['SearchVolume'] ?? 0 ) ) ) );
		}
	}

	// Filters.
	$excluded = array( 'ranking' => 0, 'covered' => 0, 'brand' => 0, 'navigational' => 0 );
	$blog     = array();
	$landing  = array();
	$optimize = array();
	foreach ( $cands as $k => $c ) {
		$kw = $c['keyword'];
		if ( sap_is_brand( $kw, $project ) ) {
			++$excluded['brand'];
			continue;
		}
		$rows = (array) ( $ctx['by_query'][ $k ] ?? array() );
		$best = $rows ? sap_sort( $rows, 'position', false )[0] : null;
		$c['impr'] = array_sum( array_column( $rows, 'impressions' ) );
		$c['pos']  = $best ? round( (float) $best['position'], 1 ) : null;
		if ( $best && $best['position'] < 6 ) {
			++$excluded['ranking']; // Already on top: no new content for it.
			continue;
		}
		$hit = sap_pool_covered( $kw, $targets );
		if ( $hit ) {
			++$excluded['covered'];
			continue;
		}
		// A page already ranks 6–20 with the same topic: optimize it instead.
		if ( $best && $best['position'] <= 20 ) {
			$km = $ctx['keymap'][ sap_norm_url( $best['page'] ) ] ?? null;
			if ( $km && '' !== $km['primary_kw'] && array_intersect( sap_tokens( sap_kw_core( $km['primary_kw'] ) ), sap_tokens( sap_kw_core( $kw ) ) ) ) {
				$c['page']  = $best['page'];
				$c['title'] = $km['title'];
				$optimize[] = $c;
				continue;
			}
		}
		$intent      = sap_kw_intent( $kw );
		$c['intent'] = $intent;
		$c['target'] = sap_kw_target_type( $kw );
		if ( 'navigational' === $intent ) {
			++$excluded['navigational'];
			continue;
		}
		if ( 'post' === $c['target'] ) {
			$blog[] = $c;
		} else {
			$landing[] = $c;
		}
	}

	// Merge near-duplicates into topics; strongest keyword becomes the main one.
	$merge = function ( $list ) use ( $ctx ) {
		$weight = function ( $c ) use ( $ctx ) {
			list( $fid ) = sap_focus_match( $ctx['focuses'], '', $c['keyword'] );
			return ( $c['volume'] ?: $c['impr'] / 3 ) * ( $fid ? 1.5 : 1 ) * ( isset( $c['sources']['competitor'] ) ? 1.2 : 1 );
		};
		usort( $list, function ( $a, $b ) use ( $weight ) {
			return $weight( $b ) <=> $weight( $a );
		} );
		$topics = array();
		foreach ( $list as $c ) {
			$words = array_flip( explode( ' ', sap_kw_core( $c['keyword'] ) ) );
			$into  = null;
			foreach ( $topics as $ti => $t ) {
				$tw    = array_flip( explode( ' ', sap_kw_core( $t['keyword'] ) ) );
				$inter = count( array_intersect_key( $words, $tw ) );
				if ( $inter && $inter / max( 1, min( count( $words ), count( $tw ) ) ) >= 0.67 ) {
					$into = $ti;
					break;
				}
			}
			if ( null === $into ) {
				$c['secondary'] = array();
				$c['score']     = round( $weight( $c ), 1 );
				$topics[]       = $c;
			} elseif ( count( $topics[ $into ]['secondary'] ) < 10 ) {
				$topics[ $into ]['secondary'][] = array( $c['keyword'], $c['volume'] );
				$topics[ $into ]['sources']    += $c['sources'];
				$topics[ $into ]['evidence']    = array_slice( array_merge( $topics[ $into ]['evidence'], $c['evidence'] ), 0, 4 );
			}
		}
		return $topics;
	};
	$pool = array(
		'blog'     => array_slice( $merge( $blog ), 0, 60 ),
		'landing'  => array_slice( $merge( $landing ), 0, 30 ),
		'optimize' => array_slice( sap_sort( $optimize, 'impr' ), 0, 30 ),
		'excluded' => $excluded,
		'sources'  => array(
			'gsc'        => ! empty( $ctx['gsc_qp'] ),
			'questions'  => ! empty( $ctx['ss_questions'] ),
			'competitor' => ! empty( $ctx['ss_compare']['competitors'] ),
		),
		'at'       => sap_now(),
	);
	foreach ( array( 'blog', 'landing' ) as $l ) {
		foreach ( $pool[ $l ] as &$t ) {
			$t['sources'] = array_keys( $t['sources'] );
		}
		unset( $t );
	}
	sap_dataset_put( $pid, 'ideas_pool', $pool, array( 'blog' => count( $pool['blog'] ), 'landing' => count( $pool['landing'] ) ) );
	sap_pool_to_proposals( $project, $pool );
	return $pool;
}

/**
 * Landing-page opportunities and «optimize the existing page» become proposals.
 */
function sap_pool_to_proposals( $project, $pool ) {
	$types = sap_content_types();
	$items = array();
	foreach ( array_slice( $pool['landing'], 0, 15 ) as $t ) {
		$sec     = array_map( function ( $s ) {
			return $s[0];
		}, $t['secondary'] );
		$items[] = array(
			'key'      => 'landing:' . sap_kw_core( $t['keyword'] ),
			'title'    => sprintf( 'فرصت صفحه %s برای «%s»', $types[ $t['target'] ] ?? 'فرود', $t['keyword'] ),
			'detail'   => sprintf( "نیت این کلمه خرید است و باید با صفحه %s هدف گرفته شود، نه مقاله بلاگ. هیچ صفحه‌ای در نقشه کلمات آن را هدف نگرفته است.\n%s%s", $types[ $t['target'] ] ?? '', implode( ' · ', $t['evidence'] ), $sec ? "\nکلمات هم‌خانواده: " . implode( '، ', $sec ) : '' ),
			'context'  => 'content_new',
			'keyword'  => $t['keyword'],
			'priority' => $t['volume'] >= 500 || $t['impr'] >= 300 ? 'high' : 'medium',
			'effort'   => 3,
			'section'  => 'landing',
			'kind'     => 'landing',
		);
	}
	foreach ( array_slice( $pool['optimize'], 0, 15 ) as $c ) {
		$items[] = array(
			'key'      => 'opt:' . sap_kw_core( $c['keyword'] ),
			'title'    => sprintf( 'هدف‌گیری «%s» در صفحه «%s» (جایگاه %s)', $c['keyword'], $c['title'] ?: sap_short_url( $c['page'], 40 ), sap_num( $c['pos'], 1 ) ),
			'detail'   => 'این کلمه را صفحه‌ای از سایت در جایگاه ۶ تا ۲۰ دارد و موضوعش با همان صفحه یکی است؛ به‌جای محتوای جدید (که کنیبال می‌سازد) آن را به کلمات فرعی همین صفحه اضافه کنید و بخشی برایش بنویسید. ' . implode( ' · ', $c['evidence'] ),
			'context'  => 'content_update',
			'url'      => $c['page'],
			'keyword'  => $c['keyword'],
			'priority' => $c['impr'] >= 200 ? 'high' : 'medium',
			'effort'   => 1,
			'section'  => 'optimize',
			'kind'     => 'optimize',
		);
	}
	return sap_proposals_put( $project, 'pool', $items );
}
