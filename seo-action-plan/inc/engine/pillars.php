<?php
/**
 * The three pillars of SEO (technical, content, off-page) and «proposals».
 *
 * A proposal is an actionable suggestion — from a rule (finding) or from an AI
 * analysis — that waits for the specialist's approval. Approving it turns it
 * into a task of the chosen month (GTD «clarify»); dismissing it keeps it out
 * of future suggestions. AI suggestions are stored as findings with detector
 * "ai_<source>", so they share the inbox, scoring, focus weight and the
 * existing approve / dismiss flow, and are replaced when the analysis re-runs.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pillars: label, icon, work areas (contexts).
 */
function sap_pillars() {
	return array(
		'technical' => array( 'سئو تکنیکال', 'settings', array( 'technical' ) ),
		'content'   => array( 'سئو محتوا', 'log', array( 'content_new', 'content_update', 'onpage', 'internal_links', 'research', 'cro' ) ),
		'offpage'   => array( 'سئو آف‌پیج', 'link', array( 'offpage' ) ),
	);
}

/**
 * Pillar of a work area.
 */
function sap_pillar_of_context( $context ) {
	foreach ( sap_pillars() as $k => $p ) {
		if ( in_array( $context, $p[2], true ) ) {
			return $k;
		}
	}
	return 'content';
}

/**
 * Store an AI analysis' suggestions as proposals (replacing that source's old
 * open ones; approved / dismissed ones keep their status).
 *
 * @param string $source  e.g. tech, audit_product, links, offpage.
 * @param array  $items   [key, title, detail, context, url, keyword, priority (high|medium|low),
 *                         effort (hours), section, deliverable, steps[], kind].
 * @return int Proposals stored.
 */
function sap_proposals_put( $project, $source, $items ) {
	$fc       = sap_focus_context( $project['id'] );
	$sev      = array( 'high' => 80, 'medium' => 55, 'low' => 30 );
	$findings = array();
	foreach ( $items as $i ) {
		$i = wp_parse_args( $i, array( 'key' => '', 'title' => '', 'detail' => '', 'context' => 'content_update', 'url' => '', 'keyword' => '', 'priority' => 'medium', 'effort' => 1, 'section' => '', 'deliverable' => '', 'steps' => array(), 'kind' => '', 'meta' => array() ) );
		if ( '' === trim( (string) $i['title'] ) ) {
			continue;
		}
		// Not doable or not worth it: vague (no page, no detail, no ready output) or low impact for long work.
		if ( ( '' === (string) $i['url'] && mb_strlen( trim( (string) $i['detail'] ) ) < 30 && '' === trim( (string) $i['deliverable'] ) ) || ( 'low' === $i['priority'] && (float) $i['effort'] > 3 ) ) {
			continue;
		}
		$i['effort'] = min( 8, (float) $i['effort'] );
		// The same kind of work on this page was done / planned / changed on the site recently: don't suggest it again.
		if ( '' !== (string) $i['url'] && ( '' !== $i['kind'] || 'technical' !== $i['context'] ) && sap_recently_handled( $project['id'], (string) $i['url'], (string) $i['kind'], (string) $i['context'] ) ) {
			continue;
		}
		list( $fid, $w ) = sap_focus_match( $fc, (string) $i['url'], (string) $i['keyword'] );
		$findings[]      = array(
			'detector'   => 'ai_' . $source,
			'key'        => $i['key'] ?: md5( $i['title'] . '|' . $i['url'] ),
			'context'    => array_key_exists( $i['context'], sap_contexts() ) ? $i['context'] : 'content_update',
			'title'      => (string) $i['title'],
			'detail'     => trim( (string) $i['detail'] . ( $i['steps'] ? "\n\nمراحل:\n" . implode( "\n", array_map( function ( $s, $n ) {
				return sap_fa( $n + 1 ) . '. ' . $s;
			}, (array) $i['steps'], array_keys( (array) $i['steps'] ) ) ) : '' ) ),
			'url'        => (string) $i['url'],
			'keyword'    => (string) $i['keyword'],
			'severity'   => $sev[ $i['priority'] ] ?? 55,
			'confidence' => 0.75,
			'effort'     => max( 0.25, (float) $i['effort'] ),
			'focus_id'   => $fid,
			'weight'     => $w,
			'evidence'   => array_filter( array(
				'section'     => (string) $i['section'],
				'kind'        => (string) $i['kind'],
				'priority'    => (string) $i['priority'],
				'deliverable' => (string) $i['deliverable'],
				'source'      => $source,
				'meta'        => $i['meta'],
			) ),
		);
	}
	sap_findings_sync( $project['id'], $findings, array( 'ai_' . $source ) );
	return count( $findings );
}

/**
 * Proposals (open findings + AI suggestions) of a pillar, optionally one
 * section / page type / source.
 *
 * @param array $args section, sources (detector list), url, status, limit.
 */
function sap_proposals( $project_id, $pillar, $args = array() ) {
	$args  = wp_parse_args( $args, array( 'section' => '', 'detectors' => array(), 'status' => 'open', 'filter' => null, 'limit' => 300 ) );
	$ctxs  = sap_pillars()[ $pillar ][2] ?? array();
	$out   = array();
	foreach ( sap_findings( $project_id, array( 'status' => $args['status'], 'limit' => 2000 ) ) as $f ) {
		if ( ! in_array( $f['context'], $ctxs, true ) && ! ( 'technical' === $pillar && sap_tech_section_of( $f ) ) ) {
			continue;
		}
		if ( $args['detectors'] && ! in_array( $f['detector'], $args['detectors'], true ) ) {
			continue;
		}
		if ( $args['section'] && ( $f['evidence']['section'] ?? ( 'technical' === $pillar ? sap_tech_section_of( $f ) : '' ) ) !== $args['section'] ) {
			continue;
		}
		if ( $args['filter'] && ! call_user_func( $args['filter'], $f ) ) {
			continue;
		}
		$out[] = $f;
		if ( count( $out ) >= $args['limit'] ) {
			break;
		}
	}
	return $out;
}

/**
 * Proposals of a pillar per status: open / planned (approved) / dismissed.
 */
function sap_proposal_counts( $project_id, $pillar ) {
	$c = array( 'open' => 0, 'planned' => 0, 'dismissed' => 0 );
	foreach ( array_keys( $c ) as $st ) {
		$c[ $st ] = count( sap_proposals( $project_id, $pillar, array( 'status' => $st, 'limit' => 5000 ) ) );
	}
	return $c;
}

/**
 * Convert the AI technical stage answer into proposals.
 */
function sap_tech_ai_to_proposals( $project, $issues ) {
	$area_to_section = array( 'crawl' => 'crawl', 'index' => 'index', 'sitemap' => 'sitemap', 'schema' => 'schema', 'cwv' => 'speed', 'onpage' => 'crawl', 'robots' => 'robots', 'pages' => 'pages' );
	$effort          = array( 'high' => 2, 'medium' => 1, 'low' => 0.5 );
	$items           = array();
	foreach ( $issues as $i ) {
		$items[] = array(
			'key'         => md5( $i['area'] . '|' . $i['title'] ),
			'title'       => $i['title'],
			'detail'      => $i['detail'] . ( $i['urls'] ? "\n\nنمونه آدرس‌ها:\n" . implode( "\n", array_slice( $i['urls'], 0, 8 ) ) : '' ),
			'deliverable' => $i['fix'],
			'context'     => 'technical',
			'url'         => $i['urls'][0] ?? '',
			'priority'    => $i['priority'],
			'effort'      => $effort[ $i['priority'] ] ?? 1,
			'section'     => $area_to_section[ $i['area'] ] ?? 'crawl',
		);
	}
	return sap_proposals_put( $project, 'tech', $items );
}

/**
 * Internal links a page already has (target url key => anchor), from the pages
 * read for the analysis, or read now.
 */
function sap_page_outlinks( $project, $url ) {
	static $cache = array();
	$k = sap_norm_url( $url );
	if ( isset( $cache[ $k ] ) ) {
		return $cache[ $k ];
	}
	$p = sap_dataset_payload( $project['id'], 'pages' )['pages'][ $k ] ?? null;
	if ( ! $p && count( $cache ) < 12 ) {
		$p = sap_page_fetch( $url );
		$p = is_wp_error( $p ) ? null : $p;
	}
	$out = array();
	foreach ( (array) ( $p['links'] ?? array() ) as $l ) {
		$out[ sap_norm_url( sap_full_url( $project, (string) $l[0] ) ) ] = (string) ( $l[1] ?? '' );
	}
	return $cache[ $k ] = $out;
}

/**
 * Is a link suggestion sound? Returns '' or why it is dropped.
 */
function sap_link_check( $project, $source, $target, $anchor ) {
	$sk = sap_norm_url( $source );
	$tk = sap_norm_url( $target );
	if ( '' === $sk || '' === $tk || $sk === $tk ) {
		return 'مبدأ و مقصد یکی است';
	}
	$have = sap_page_outlinks( $project, $source );
	if ( isset( $have[ $tk ] ) ) {
		return 'صفحه مبدأ همین الان به مقصد لینک دارد (انکر «' . $have[ $tk ] . '»)';
	}
	$map  = sap_keymap( $project['id'] );
	$tm   = $map[ $tk ] ?? null;
	$sm   = $map[ $sk ] ?? null;
	$a    = sap_kw_core( $anchor );
	if ( '' === $a ) {
		return 'انکر خالی است';
	}
	if ( $sm && '' !== $sm['primary_kw'] && sap_kw_core( $sm['primary_kw'] ) === $a && ( ! $tm || sap_kw_core( $tm['primary_kw'] ) !== $a ) ) {
		return 'انکر کلمه اصلی خود صفحه مبدأ است';
	}
	// The anchor names the target: its keyword, a secondary keyword, its title or a real query.
	$words = array();
	foreach ( array_filter( array_merge( $tm ? array( $tm['primary_kw'], $tm['title'] ) : array(), $tm ? (array) $tm['secondary'] : array(), array( sap_page_title( $project['id'], $target ) ) ) ) as $t ) {
		$words = array_merge( $words, sap_tokens( sap_kw_core( (string) $t ) ) );
	}
	if ( $words && ! array_intersect( sap_tokens( $a ), $words ) ) {
		return 'انکر به موضوع صفحه مقصد ربطی ندارد';
	}
	foreach ( $map as $k => $m ) {
		if ( $k !== $tk && '' !== $m['primary_kw'] && sap_kw_core( $m['primary_kw'] ) === $a ) {
			return 'انکر کلمه اصلی صفحه دیگری است (' . ( $m['title'] ?: sap_short_url( $m['url'], 40 ) ) . ')';
		}
	}
	return '';
}

/**
 * Convert the AI internal-links stage answer into proposals: one per target
 * page, each link with its exact source address, target address and anchor.
 * Self-links, links that already exist and anchors that don't name the target
 * are dropped.
 */
function sap_links_ai_to_proposals( $project, $links ) {
	$by_target = array();
	$dropped   = 0;
	foreach ( $links as $l ) {
		if ( empty( $l['target_url'] ) || empty( $l['source_url'] ) ) {
			continue;
		}
		$target = sap_full_url( $project, $l['target_url'] );
		$source = sap_full_url( $project, $l['source_url'] );
		if ( '' !== sap_link_check( $project, $source, $target, (string) $l['anchor'] ) ) {
			++$dropped;
			continue;
		}
		$l['target_full'] = $target;
		$l['source_full'] = $source;
		$by_target[ sap_norm_url( $target ) ][ sap_norm_url( $source ) ] = $l; // One link per source → target.
	}
	update_option( 'sap_links_dropped_' . $project['id'], $dropped, false );
	$items = array();
	foreach ( $by_target as $k => $ls ) {
		$ls     = array_values( $ls );
		$target = $ls[0]['target_full'];
		$tname  = sap_page_title( $project['id'], $target ) ?: sap_short_url( $target, 50 );
		$lines  = array();
		$rows   = array();
		foreach ( $ls as $n => $l ) {
			$sname   = sap_page_title( $project['id'], $l['source_full'] ) ?: sap_short_url( $l['source_full'], 60 );
			$lines[] = sprintf( "%s. مبدأ: %s\n   %s\n   مقصد: %s\n   انکر: «%s»%s", sap_num( $n + 1 ), $sname, rawurldecode( $l['source_full'] ), rawurldecode( $target ), $l['anchor'], '' !== (string) $l['placement'] ? "\n   محل: " . $l['placement'] : '' );
			$rows[]  = array( 'page' => $sname, 'url' => $l['source_full'], 'anchor' => $l['anchor'], 'placement' => (string) $l['placement'] );
		}
		$tm      = sap_keymap( $project['id'] )[ $k ] ?? null;
		$items[] = array(
			'key'         => 'links:' . $k,
			'title'       => sprintf( '%s لینک داخلی به «%s»%s', sap_num( count( $ls ) ), $tname, $tm && '' !== $tm['primary_kw'] ? ' با انکر «' . $tm['primary_kw'] . '»' : '' ),
			'detail'      => (string) $ls[0]['reason'],
			'deliverable' => 'مقصد: ' . $tname . "\n" . rawurldecode( $target ) . "\n\n" . implode( "\n\n", $lines ),
			'context'     => 'internal_links',
			'url'         => $target,
			'keyword'     => $tm ? (string) $tm['primary_kw'] : '',
			'priority'    => count( $ls ) >= 3 ? 'high' : 'medium',
			'effort'      => max( 0.25, 0.15 * count( $ls ) ),
			'section'     => 'links',
			'kind'        => 'links',
			'meta'        => array( 'links' => $rows ),
		);
	}
	return sap_proposals_put( $project, 'links', $items );
}
