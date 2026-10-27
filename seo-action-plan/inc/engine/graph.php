<?php
/**
 * Keyword & page graph: the site as a network.
 *
 * Nodes: pages (size = impressions), their primary / secondary keywords and
 * best Search Console queries (colour = ranking state), and planned articles
 * of the content calendar. Edges: page → keyword, internal links between pages
 * (from the page reader), AI-suggested internal links and the planned links of
 * calendar articles. Nodes of one topic are pulled together, so related pages
 * and intents sit close to each other and gaps are visible at a glance:
 * orphan pages (no internal link in), topics without a hub, keywords that rank
 * on page two.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Words too generic to name a topic.
 */
function sap_graph_generic_words() {
	return array_map( 'sap_norm_text', array( 'خرید', 'قیمت', 'فروش', 'بهترین', 'انواع', 'روش', 'علت', 'دلیل', 'راهنما', 'راهنمای', 'آموزش', 'نحوه', 'طرز', 'مدل', 'نگهداری', 'برای', 'در', 'با', 'و', 'از', 'چیست', 'چگونه', 'معرفی', 'مقایسه', 'تفاوت', 'جدید', 'ارزان', 'گیاه', 'محصول', 'آنلاین' ) );
}

/**
 * Build the graph.
 *
 * @return array{nodes: array, edges: array, topics: array, stats: array}
 */
function sap_keyword_graph( $project ) {
	$pid  = $project['id'];
	$map  = sap_keymap( $pid );
	$impr = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$impr[ sap_norm_url( $r['page'] ) ] = (int) $r['impressions'];
	}
	$by_page = array();
	$best    = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
		$by_page[ sap_norm_url( $r['page'] ) ][] = $r;
		$k                                       = sap_kw_key( $r['query'] );
		if ( ! isset( $best[ $k ] ) || $r['position'] < $best[ $k ]['position'] ) {
			$best[ $k ] = $r;
		}
	}
	$ideas = sap_ideas( $pid, array( 'status' => array( 'scheduled', 'approved' ) ) );

	// Topic of a keyword: its most frequent non-generic word across the site.
	$generic = array_flip( sap_graph_generic_words() );
	$df      = array();
	foreach ( $map as $m ) {
		foreach ( array_unique( sap_tokens( $m['primary_kw'] ) ) as $w ) {
			$df[ $w ] = ( $df[ $w ] ?? 0 ) + 1;
		}
	}
	foreach ( $ideas as $i ) {
		foreach ( array_unique( sap_tokens( $i['keyword'] ) ) as $w ) {
			$df[ $w ] = ( $df[ $w ] ?? 0 ) + 1;
		}
	}
	$fnames = wp_list_pluck( sap_focuses( $pid ), 'name', 'id' );
	$topic  = function ( $kw, $focus_id = 0 ) use ( $df, $generic, $fnames ) {
		if ( $focus_id && isset( $fnames[ $focus_id ] ) ) {
			return $fnames[ $focus_id ];
		}
		$pick = '';
		$max  = 0;
		foreach ( sap_tokens( $kw ) as $w ) {
			if ( isset( $generic[ $w ] ) || mb_strlen( $w ) < 3 ) {
				continue;
			}
			if ( ( $df[ $w ] ?? 0 ) > $max ) {
				$max  = $df[ $w ];
				$pick = $w;
			}
		}
		return $max >= 2 ? $pick : 'سایر';
	};
	$state = function ( $pos ) {
		return ! $pos ? 'none' : ( $pos <= 3 ? 'top3' : ( $pos <= 10 ? 'top10' : ( $pos <= 20 ? 'near' : 'far' ) ) );
	};

	$nodes = array();
	$edges = array();
	$add_e = function ( $s, $t, $type ) use ( &$edges ) {
		$key = $s < $t ? "$s|$t|$type" : "$t|$s|$type";
		if ( $s !== $t && ! isset( $edges[ $key ] ) ) {
			$edges[ $key ] = array( 's' => $s, 't' => $t, 'type' => $type );
		}
	};

	// Pages (most visible first).
	$pages = $map;
	uksort( $pages, function ( $a, $b ) use ( $impr ) {
		return ( $impr[ $b ] ?? 0 ) <=> ( $impr[ $a ] ?? 0 );
	} );
	$pages = array_slice( $pages, 0, 90, true );
	foreach ( $pages as $k => $m ) {
		$pos            = '' !== $m['primary_kw'] && isset( $best[ sap_kw_key( $m['primary_kw'] ) ] ) && sap_norm_url( $best[ sap_kw_key( $m['primary_kw'] ) ]['page'] ) === $k ? (float) $best[ sap_kw_key( $m['primary_kw'] ) ]['position'] : 0;
		$nodes[ 'p:' . $k ] = array(
			'id'    => 'p:' . $k,
			'type'  => 'page',
			'label' => $m['title'] ?: sap_short_url( $m['url'], 40 ),
			'topic' => 'home' === $m['page_type'] ? 'صفحه اصلی' : $topic( $m['primary_kw'] ?: $m['title'], $m['focus_id'] ),
			'w'     => (int) ( $impr[ $k ] ?? 0 ),
			'state' => $state( $pos ),
			'url'   => $m['url'],
			'info'  => array( 'kw' => $m['primary_kw'], 'pt' => sap_keymap_page_types()[ $m['page_type'] ] ?? '', 'impr' => (int) ( $impr[ $k ] ?? 0 ), 'pos' => $pos ? round( $pos, 1 ) : null ),
		);
	}

	// Keywords of each page: primary, top secondaries, best queries.
	$kw_node = function ( $kw, $kind, $page_node ) use ( &$nodes, $best, $state, $add_e, $topic ) {
		$kk = sap_kw_key( $kw );
		if ( '' === $kk ) {
			return;
		}
		$id = 'k:' . $kk;
		if ( ! isset( $nodes[ $id ] ) ) {
			$b            = $best[ $kk ] ?? null;
			$nodes[ $id ] = array(
				'id'    => $id,
				'type'  => 'kw',
				'kind'  => $kind,
				'label' => $kw,
				'topic' => $nodes[ $page_node ]['topic'] ?? $topic( $kw ),
				'w'     => $b ? (int) $b['impressions'] : 0,
				'state' => $state( $b ? (float) $b['position'] : 0 ),
				'url'   => $b ? $b['page'] : '',
				'info'  => array( 'impr' => $b ? (int) $b['impressions'] : 0, 'pos' => $b ? round( (float) $b['position'], 1 ) : null, 'clicks' => $b ? (int) $b['clicks'] : 0 ),
			);
		}
		$add_e( $page_node, $id, $kind );
	};
	foreach ( $pages as $k => $m ) {
		$pn = 'p:' . $k;
		if ( '' !== $m['primary_kw'] && 'home' !== $m['page_type'] ) {
			$kw_node( $m['primary_kw'], 'primary', $pn );
		}
		foreach ( array_slice( $m['secondary'], 0, 3 ) as $s ) {
			$kw_node( $s, 'secondary', $pn );
		}
		$q = array_values( array_filter( sap_sort( (array) ( $by_page[ $k ] ?? array() ), 'impressions' ), function ( $r ) use ( $project ) {
			return ! sap_is_brand( $r['query'], $project );
		} ) );
		foreach ( array_slice( $q, 0, 2 ) as $r ) {
			$kw_node( $r['query'], 'query', $pn );
		}
	}

	// Internal links between mapped pages (from the page reader).
	$inbound = array();
	$read    = (array) ( sap_dataset_payload( $pid, 'pages' )['pages'] ?? array() );
	foreach ( $read as $k => $p ) {
		if ( ! isset( $nodes[ 'p:' . $k ] ) ) {
			continue;
		}
		foreach ( (array) $p['links'] as $l ) {
			$t = sap_norm_url( $l[0] );
			if ( $t !== $k && isset( $nodes[ 'p:' . $t ] ) ) {
				$add_e( 'p:' . $k, 'p:' . $t, 'link' );
				$inbound[ $t ] = true;
			}
		}
	}
	// AI-suggested internal links.
	$suggested = 0;
	foreach ( (array) ( sap_dataset_payload( $pid, 'ai_links' )['links'] ?? array() ) as $l ) {
		$s = sap_norm_url( sap_full_url( $project, $l['source_url'] ) );
		$t = sap_norm_url( sap_full_url( $project, $l['target_url'] ) );
		if ( isset( $nodes[ 'p:' . $s ], $nodes[ 'p:' . $t ] ) ) {
			$add_e( 'p:' . $s, 'p:' . $t, 'suggested' );
			++$suggested;
		}
	}
	// Planned articles and the pages they will link to.
	foreach ( array_slice( $ideas, 0, 40 ) as $i ) {
		$id           = 'i:' . $i['id'];
		$nodes[ $id ] = array(
			'id'    => $id,
			'type'  => 'idea',
			'label' => $i['keyword'],
			'topic' => $topic( $i['keyword'], $i['focus_id'] ),
			'w'     => (int) $i['kp'],
			'state' => 'planned',
			'url'   => '',
			'info'  => array( 'title' => $i['title'], 'period' => $i['period'] ? sap_period_label( $i['period'] ) : '', 'kp' => (int) $i['kp'] ),
		);
		foreach ( $i['links'] as $l ) {
			$t = sap_norm_url( $l['url'] );
			if ( isset( $nodes[ 'p:' . $t ] ) ) {
				$add_e( $id, 'p:' . $t, 'planned' );
			}
		}
	}

	// Orphans: read pages that no other mapped page links to.
	$orphans = 0;
	foreach ( $nodes as &$n ) {
		if ( 'page' === $n['type'] ) {
			$k           = substr( $n['id'], 2 );
			$n['orphan'] = isset( $read[ $k ] ) && empty( $inbound[ $k ] ) && 'صفحه اصلی' !== $n['topic'];
			$orphans    += $n['orphan'] ? 1 : 0;
		}
	}
	unset( $n );
	$topics = array_count_values( array_column( $nodes, 'topic' ) );
	arsort( $topics );
	$near = count( array_filter( $nodes, function ( $n ) {
		return 'kw' === $n['type'] && 'near' === $n['state'];
	} ) );
	return array(
		'nodes'  => array_values( $nodes ),
		'edges'  => array_values( $edges ),
		'topics' => array_keys( $topics ),
		'stats'  => array(
			'pages'     => count( $pages ),
			'keywords'  => count( array_filter( $nodes, function ( $n ) {
				return 'kw' === $n['type'];
			} ) ),
			'links'     => count( array_filter( $edges, function ( $e ) {
				return 'link' === $e['type'];
			} ) ),
			'orphans'   => $orphans,
			'suggested' => $suggested,
			'near'      => $near,
			'planned'   => count( array_slice( $ideas, 0, 40 ) ),
			'read'      => (bool) $read,
		),
	);
}
