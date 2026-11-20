<?php
/**
 * Content updates: the «update existing content» half of the content calendar.
 *
 * One row per page planned for a professional update in a month, with the
 * AI brief (keyword placement, sections, product attributes, FAQ, internal and
 * external links, schema) and its link to the rewrite / task.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Statuses.
 */
function sap_update_statuses() {
	return array(
		'planned'     => 'برنامه‌ریزی‌شده',
		'in_progress' => 'در حال انجام',
		'done'        => 'انجام شد',
		'cancelled'   => 'لغو',
	);
}

/**
 * Hydrate a row.
 */
function sap_update_hydrate( $row ) {
	if ( ! $row ) {
		return null;
	}
	$row = (array) $row;
	foreach ( array( 'id', 'project_id', 'focus_id', 'task_id' ) as $k ) {
		$row[ $k ] = (int) $row[ $k ];
	}
	$row['secondary'] = json_decode( (string) $row['secondary'], true ) ?: array();
	$row['brief']     = json_decode( (string) $row['brief'], true ) ?: array();
	$row['links']     = json_decode( (string) ( $row['links'] ?? '' ), true ) ?: array();
	$row['url']       = (string) $row['url'];
	$row['reason']    = (string) $row['reason'];
	$row['my_view']   = (string) ( $row['my_view'] ?? '' );
	$row['source_note'] = (string) ( $row['source_note'] ?? '' );
	foreach ( array( 'rank_before', 'kw_pos_before' ) as $k ) {
		$row[ $k ] = isset( $row[ $k ] ) && null !== $row[ $k ] ? (float) $row[ $k ] : null;
	}
	foreach ( array( 'clicks_before', 'impr_before' ) as $k ) {
		$row[ $k ] = isset( $row[ $k ] ) && null !== $row[ $k ] ? (int) $row[ $k ] : null;
	}
	return $row;
}

/**
 * Updates of a project.
 *
 * @param array $args period, status.
 */
function sap_updates( $project_id, $args = array() ) {
	global $wpdb;
	$args  = wp_parse_args( $args, array( 'period' => null, 'status' => '' ) );
	$where = $wpdb->prepare( 'project_id = %d', $project_id );
	if ( null !== $args['period'] ) {
		$where .= $wpdb->prepare( ' AND period = %s', $args['period'] );
	}
	if ( $args['status'] ) {
		$where .= " AND status IN ('" . implode( "','", array_map( 'sanitize_key', (array) $args['status'] ) ) . "')";
	}
	return array_map( 'sap_update_hydrate', (array) $wpdb->get_results( 'SELECT * FROM ' . sap_table( 'updates' ) . " WHERE $where ORDER BY period DESC, planned_date ASC, id ASC", ARRAY_A ) ); // phpcs:ignore
}

/**
 * One update.
 */
function sap_update( $id ) {
	global $wpdb;
	return sap_update_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'updates' ) . ' WHERE id = %d', $id ), ARRAY_A ) ); // phpcs:ignore
}

/**
 * Insert / update.
 */
function sap_update_save( $data, $id = 0 ) {
	global $wpdb;
	$f = array( 'updated_at' => sap_now() );
	foreach ( array( 'project_id', 'focus_id', 'task_id' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$f[ $k ] = (int) $data[ $k ];
		}
	}
	foreach ( array( 'title' => 250, 'content_type' => 20, 'keyword' => 190, 'priority' => 10, 'period' => 7, 'source' => 20 ) as $k => $max ) {
		if ( isset( $data[ $k ] ) ) {
			$f[ $k ] = mb_substr( sanitize_text_field( (string) $data[ $k ] ), 0, $max );
		}
	}
	if ( isset( $data['status'] ) && array_key_exists( $data['status'], sap_update_statuses() ) ) {
		$f['status']  = $data['status'];
		$f['done_at'] = 'done' === $data['status'] ? ( ! empty( $data['done_at'] ) ? $data['done_at'] : sap_now() ) : null;
	}
	if ( isset( $data['url'] ) ) {
		$f['url'] = esc_url_raw( (string) $data['url'] );
	}
	foreach ( array( 'reason', 'my_view', 'source_note' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$f[ $k ] = sanitize_textarea_field( (string) $data[ $k ] );
		}
	}
	foreach ( array( 'rank_before', 'kw_pos_before' ) as $k ) {
		if ( array_key_exists( $k, $data ) ) {
			$f[ $k ] = null === $data[ $k ] || '' === $data[ $k ] ? null : round( (float) $data[ $k ], 1 );
		}
	}
	foreach ( array( 'clicks_before', 'impr_before' ) as $k ) {
		if ( array_key_exists( $k, $data ) ) {
			$f[ $k ] = null === $data[ $k ] || '' === $data[ $k ] ? null : (int) $data[ $k ];
		}
	}
	if ( array_key_exists( 'baseline_at', $data ) ) {
		$f['baseline_at'] = $data['baseline_at'] ?: null;
	}
	if ( isset( $data['links'] ) ) {
		$f['links'] = wp_json_encode( array_values( array_slice( (array) $data['links'], 0, 10 ) ), JSON_UNESCAPED_UNICODE );
	}
	if ( array_key_exists( 'planned_date', $data ) ) {
		$f['planned_date'] = $data['planned_date'] ?: null;
	}
	foreach ( array( 'secondary', 'brief' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$f[ $k ] = wp_json_encode( $data[ $k ], JSON_UNESCAPED_UNICODE );
		}
	}
	if ( $id ) {
		$wpdb->update( sap_table( 'updates' ), $f, array( 'id' => (int) $id ) );
		return (int) $id;
	}
	$f['created_at'] = sap_now();
	$wpdb->insert( sap_table( 'updates' ), $f );
	return (int) $wpdb->insert_id;
}

/**
 * Delete one.
 */
function sap_update_delete( $id ) {
	global $wpdb;
	$wpdb->delete( sap_table( 'updates' ), array( 'id' => (int) $id ) );
}

/**
 * The open update of a page in any month (planned or in progress), if there is one.
 */
function sap_update_open_for( $project_id, $url ) {
	$k = sap_norm_url( $url );
	foreach ( sap_updates( $project_id, array( 'status' => array( 'planned', 'in_progress' ) ) ) as $u ) {
		if ( sap_norm_url( $u['url'] ) === $k ) {
			return $u;
		}
	}
	return null;
}

/**
 * The last finished update of a page within N days, if any.
 */
function sap_update_recent_done( $project_id, $url, $days = 60 ) {
	$k     = sap_norm_url( $url );
	$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	foreach ( sap_updates( $project_id, array( 'status' => array( 'done' ) ) ) as $u ) {
		if ( sap_norm_url( $u['url'] ) === $k && (string) $u['done_at'] >= $since ) {
			return $u;
		}
	}
	return null;
}

/**
 * Where a page stands before its update: average position, clicks and impressions
 * of the last 28 days (and the position of its main keyword), from the stored
 * Search Console data — or live when that data does not have the page.
 *
 * @return array {rank_before, kw_pos_before, clicks_before, impr_before, baseline_at}
 */
function sap_update_baseline( $project, $url, $keyword = '' ) {
	$k   = sap_norm_url( $url );
	$out = array( 'rank_before' => null, 'kw_pos_before' => null, 'clicks_before' => null, 'impr_before' => null, 'baseline_at' => current_time( 'Y-m-d' ) );
	$hit = false;
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		if ( sap_norm_url( (string) ( $r['page'] ?? '' ) ) === $k ) {
			$out['clicks_before'] = (int) $r['clicks'];
			$out['impr_before']   = (int) $r['impressions'];
			$out['rank_before']   = ! empty( $r['impressions'] ) ? round( (float) $r['position'], 1 ) : null;
			$hit                  = true;
			break;
		}
	}
	if ( '' !== trim( (string) $keyword ) ) {
		$kk = sap_kw_key( $keyword );
		foreach ( (array) ( sap_dataset_payload( $project['id'], 'gsc_qp' )['rows'] ?? array() ) as $r ) {
			if ( sap_norm_url( (string) ( $r['page'] ?? '' ) ) === $k && sap_kw_key( (string) ( $r['query'] ?? '' ) ) === $kk ) {
				$out['kw_pos_before'] = round( (float) $r['position'], 1 );
				break;
			}
		}
	}
	if ( ! $hit && '' !== (string) $project['gsc_property'] && function_exists( 'sap_google_connected' ) && sap_google_connected() && function_exists( 'sap_impact_window' ) ) {
		$end = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -3 days' ) );
		$w   = sap_impact_window( $project, $url, '', gmdate( 'Y-m-d', strtotime( $end . ' -27 days' ) ), $end );
		if ( ! is_wp_error( $w ) ) {
			$out['clicks_before'] = $w['clicks'];
			$out['impr_before']   = $w['impressions'];
			$out['rank_before']   = $w['position'];
		}
	}
	return $out;
}

/**
 * Plan a page for update in a month. A page has one open update at a time: an
 * update still planned or in progress (in any month) is returned instead of a
 * second row. Where the page stood before the update is recorded on creation.
 *
 * @return int Update id.
 */
function sap_update_plan( $project, $url, $period, $args = array() ) {
	$open = sap_update_open_for( $project['id'], $url );
	if ( $open ) {
		return $open['id'];
	}
	$k  = sap_norm_url( $url );
	$m  = sap_keymap( $project['id'] )[ $k ] ?? null;
	$it = function_exists( 'sap_site_item' ) ? sap_site_item( $project['id'], $url ) : null;
	$kw = (string) ( $args['keyword'] ?? '' ) ?: ( $m ? $m['primary_kw'] : ( $it ? (string) ( $it['seo']['focus_keyword'] ?? '' ) : '' ) );
	$id = sap_update_save( array(
		'project_id'   => $project['id'],
		'focus_id'     => $m ? $m['focus_id'] : 0,
		'url'          => $it ? $it['url'] : $url,
		'title'        => $it ? $it['title'] : ( $m ? ( $m['title'] ?: sap_short_url( $url, 60 ) ) : ( sap_page_title( $project['id'], $url ) ?: sap_short_url( $url, 60 ) ) ),
		'content_type' => $it ? $it['ctype'] : ( $m ? sap_content_type( $m ) : 'page' ),
		'keyword'      => $kw,
		'secondary'    => isset( $args['secondary'] ) ? (array) $args['secondary'] : ( $m ? $m['secondary'] : array() ),
		'reason'       => (string) ( $args['reason'] ?? '' ),
		'my_view'      => (string) ( $args['my_view'] ?? '' ),
		'brief'        => (array) ( $args['brief'] ?? array() ),
		'priority'     => (string) ( $args['priority'] ?? 'medium' ),
		'status'       => (string) ( $args['status'] ?? 'planned' ),
		'period'       => $period,
		'source'       => (string) ( $args['source'] ?? 'manual' ),
	) );
	if ( $id ) {
		sap_update_save( sap_update_baseline( $project, $it ? $it['url'] : $url, $kw ), $id );
	}
	return $id;
}

/**
 * Kind of page of an update: cat (category / brand / tag), product, post or other.
 */
function sap_update_kind( $u ) {
	$t = (string) $u['content_type'];
	if ( in_array( $t, array( 'product_cat', 'product_tag', 'brand', 'category', 'cat' ), true ) ) {
		return 'cat';
	}
	if ( 'product' === $t ) {
		return 'product';
	}
	return in_array( $t, array( 'post', 'article', 'post_cat' ), true ) ? 'post' : 'other';
}

/**
 * Labels of the kinds of page.
 */
function sap_update_kinds() {
	return array( 'cat' => 'دسته‌بندی', 'product' => 'محصول', 'post' => 'مقاله', 'other' => 'صفحه' );
}

/**
 * Goal of an update in one short line: the stored goal, the «هدف:» line of the
 * plan, or its first line.
 */
function sap_update_goal( $u ) {
	$b = (array) $u['brief'];
	if ( '' !== trim( (string) ( $b['goal'] ?? '' ) ) ) {
		return trim( (string) $b['goal'] );
	}
	if ( preg_match( '/^هدف:\s*(.+)$/mu', (string) $u['reason'], $m ) ) {
		return trim( $m[1] );
	}
	$first = trim( (string) strtok( (string) $u['reason'], "\n" ) );
	return '' !== $first ? mb_strimwidth( $first, 0, 140, '…' ) : '';
}
