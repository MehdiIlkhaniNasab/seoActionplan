<?php
/**
 * Cross-project learning: which kinds of work moved the needle, learned from
 * the measured actions of every project. Only the type of work, the page type
 * and the measured change are pooled — never URLs, keywords, names or numbers
 * of a client — so projects never see each other's data.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page type of a URL in a project (keyword map, else the URL pattern), coarse.
 */
function sap_learning_page_type( $project_id, $url ) {
	// The keyword map knows the page type (lighter than loading a 10,000-item inventory per project).
	static $types = array();
	if ( ! isset( $types[ $project_id ] ) ) {
		$types[ $project_id ] = array();
		foreach ( sap_keymap( $project_id ) as $m ) {
			$types[ $project_id ][ sap_norm_url( $m['url'] ) ] = (string) ( $m['page_type'] ?? '' );
		}
	}
	$t = $types[ $project_id ][ sap_norm_url( $url ) ] ?? '';
	if ( in_array( $t, array( 'product', 'category', 'article', 'page', 'home' ), true ) ) {
		return $t;
	}
	$g = sap_guess_page_type( $url );
	return in_array( $g, array( 'product', 'category', 'article', 'page', 'home' ), true ) ? $g : 'page';
}

function sap_learning_page_types() {
	return array( 'product' => 'صفحه محصول', 'category' => 'صفحه دسته', 'article' => 'مقاله', 'page' => 'برگه', 'home' => 'صفحه اصلی' );
}

/**
 * The pooled playbook (rebuilt at most every 12 hours).
 *
 * @return array {at, projects, actions, groups: [{context, page, n, projects, wins, losses, avg, median, pos}]}
 */
function sap_learning( $fresh = false ) {
	$cache = get_option( 'sap_learning' );
	if ( ! $fresh && is_array( $cache ) && time() - strtotime( $cache['at'] ) < 12 * HOUR_IN_SECONDS ) {
		return $cache;
	}
	$groups = array();
	$pset   = array();
	$total  = 0;
	foreach ( sap_projects() as $p ) {
		foreach ( sap_impact_items( $p['id'] ) as $i ) {
			if ( ! in_array( $i['verdict'] ?? '', array( 'growth', 'decline', 'neutral' ), true ) ) {
				continue;
			}
			$k = $i['context'] . '|' . sap_learning_page_type( $p['id'], $i['url'] );
			if ( ! isset( $groups[ $k ] ) ) {
				list( $c, $pt ) = explode( '|', $k );
				$groups[ $k ]   = array( 'context' => $c, 'page' => $pt, 'n' => 0, 'projects' => array(), 'wins' => 0, 'losses' => 0, 'net' => array(), 'pos' => array() );
			}
			$g = &$groups[ $k ];
			++$g['n'];
			$g['projects'][ $p['id'] ] = true;
			$g['wins']   += 'growth' === $i['verdict'] ? 1 : 0;
			$g['losses'] += 'decline' === $i['verdict'] ? 1 : 0;
			$g['net'][]   = max( -100, min( 300, (int) $i['net_change'] ) ); // Clip outliers of tiny pages.
			if ( null !== ( $i['pos_gain'] ?? null ) ) {
				$g['pos'][] = (float) $i['pos_gain'];
			}
			unset( $g );
			$pset[ $p['id'] ] = true;
			++$total;
		}
	}
	$out = array();
	foreach ( $groups as $g ) {
		sort( $g['net'] );
		$out[] = array(
			'context'  => $g['context'],
			'page'     => $g['page'],
			'n'        => $g['n'],
			'projects' => count( $g['projects'] ),
			'pids'     => array_keys( $g['projects'] ),
			'wins'     => $g['wins'],
			'losses'   => $g['losses'],
			'avg'      => (int) round( array_sum( $g['net'] ) / count( $g['net'] ) ),
			'median'   => (int) $g['net'][ (int) floor( count( $g['net'] ) / 2 ) ],
			'pos'      => $g['pos'] ? round( array_sum( $g['pos'] ) / count( $g['pos'] ), 1 ) : null,
		);
	}
	usort( $out, function ( $a, $b ) {
		return $b['median'] <=> $a['median'] ?: $b['n'] <=> $a['n'];
	} );
	$data = array( 'at' => sap_now(), 'projects' => count( $pset ), 'actions' => $total, 'groups' => $out );
	update_option( 'sap_learning', $data, false );
	return $data;
}

/**
 * Playbook lines for the AI of one project: patterns seen in at least 3
 * actions of OTHER projects (its own results reach the AI separately).
 */
function sap_learning_for_ai( $project ) {
	$types = sap_learning_page_types();
	$lines = array();
	foreach ( sap_learning()['groups'] as $g ) {
		$others = array_diff( $g['pids'], array( $project['id'] ) );
		if ( $g['n'] < 3 || ! $others ) {
			continue;
		}
		$lines[] = sprintf( '- %s روی %s: %s اقدام در %s پروژه، میانه تغییر خالص کلیک %s٪ (%s رشد، %s افت)%s', sap_context_label( $g['context'] ), $types[ $g['page'] ] ?? $g['page'], sap_num( $g['n'] ), sap_num( count( $others ) + ( in_array( $project['id'], $g['pids'], true ) ? 1 : 0 ) ), sap_num( $g['median'] ), sap_num( $g['wins'] ), sap_num( $g['losses'] ), null !== $g['pos'] ? '، میانگین بهبود جایگاه ' . sap_num( $g['pos'], 1 ) : '' );
		if ( count( $lines ) >= 8 ) {
			break;
		}
	}
	return $lines;
}

/**
 * Pooled expectation of a kind of work on a page type (for the ROI forecast).
 *
 * @return array|null {median, pos, n}
 */
function sap_learning_expect( $context, $page ) {
	foreach ( sap_learning()['groups'] as $g ) {
		if ( $g['context'] === $context && $g['page'] === $page && $g['n'] >= 3 ) {
			return array( 'median' => $g['median'], 'pos' => $g['pos'], 'n' => $g['n'] );
		}
	}
	return null;
}

add_action( 'sap_daily', function () {
	sap_learning( true );
}, 30 );
