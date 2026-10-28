<?php
/**
 * Findings: issues and opportunities discovered by the detectors.
 *
 * Each finding has a stable fingerprint, so re-running the analysis updates it
 * instead of duplicating it. Findings no longer detected are marked resolved.
 * In GTD terms the open findings are the project's inbox.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finding status labels.
 */
function sap_finding_statuses() {
	return array(
		'open'      => 'باز',
		'planned'   => 'در برنامه',
		'dismissed' => 'رد شده',
		'resolved'  => 'برطرف شده',
	);
}

/**
 * Priority score (0–100).
 *
 * Impact is an estimate of extra organic clicks per month; when a detector cannot
 * estimate clicks it gives a severity (0–100) instead. Confidence (0–1) and the
 * focus weight raise or lower it, and effort (hours) discounts it.
 */
function sap_finding_score( $f ) {
	$impact   = (float) ( $f['impact'] ?? 0 );
	$base     = $impact > 0 ? min( 100, 25 * log10( 1 + $impact ) ) : (float) ( $f['severity'] ?? 30 );
	$conf     = (float) ( $f['confidence'] ?? 0.7 );
	$weight   = (float) ( $f['weight'] ?? 1 );
	$effort   = max( 0.5, (float) ( $f['effort'] ?? 1 ) );
	$score    = $base * $conf * $weight / sqrt( $effort );
	return round( min( 100, max( 0, $score ) ), 1 );
}

/**
 * Hydrate a row.
 */
function sap_finding_hydrate( $row ) {
	if ( ! $row ) {
		return null;
	}
	$row             = (array) $row;
	$row['id']       = (int) $row['id'];
	$row['focus_id'] = (int) $row['focus_id'];
	$row['task_id']  = (int) $row['task_id'];
	$row['score']    = (float) $row['score'];
	$row['effort']   = (float) $row['effort'];
	$row['impact']   = (float) $row['impact'];
	$row['evidence']   = json_decode( (string) $row['evidence'], true ) ?: array();
	$row['project_id'] = (int) $row['project_id'];
	$row['url']        = (string) $row['url'];
	$row['detail']     = (string) $row['detail'];
	return $row;
}

/**
 * Save the findings of one analysis run.
 *
 * @param int   $project_id Project.
 * @param array $findings   Detector output.
 * @param array $detectors  Detectors that ran (only their old findings may be auto-resolved).
 * @return array{new:int, updated:int, resolved:int}
 */
function sap_findings_sync( $project_id, $findings, $detectors ) {
	global $wpdb;
	$table = sap_table( 'findings' );
	$now   = sap_now();
	$seen  = array();
	$stats = array( 'new' => 0, 'updated' => 0, 'resolved' => 0 );
	$user_done = function_exists( 'sap_user_resolved' ) ? sap_user_resolved( $project_id ) : array();
	foreach ( $findings as $f ) {
		$f  = apply_filters( 'sap_finding_pre_sync', $f, $project_id );
		$fp = substr( hash( 'sha256', $f['detector'] . '|' . $f['key'] ), 0, 40 );
		if ( isset( $seen[ $fp ] ) ) {
			continue;
		}
		$seen[ $fp ] = true;
		$data        = array(
			'focus_id'   => (int) ( $f['focus_id'] ?? 0 ),
			'context'    => $f['context'],
			'title'      => mb_substr( $f['title'], 0, 250 ),
			'detail'     => $f['detail'] ?? '',
			'url'        => $f['url'] ?? '',
			'keyword'    => mb_substr( (string) ( $f['keyword'] ?? '' ), 0, 250 ),
			'evidence'   => wp_json_encode( $f['evidence'] ?? array() ),
			'impact'     => round( (float) ( $f['impact'] ?? 0 ), 2 ),
			'confidence' => (float) ( $f['confidence'] ?? 0.7 ),
			'effort'     => (float) ( $f['effort'] ?? 1 ),
			'score'      => sap_finding_score( $f ),
			'last_seen'  => $now,
		);
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE project_id = %d AND fingerprint = %s", $project_id, $fp ), ARRAY_A ); // phpcs:ignore
		if ( $old ) {
			// A resolved finding that shows up again is reopened; dismissed stays dismissed.
			// …unless you marked it resolved in the last 30 days (the data behind it may not be refreshed yet).
			if ( 'resolved' === $old['status'] && ( $user_done[ $fp ] ?? 0 ) < time() - 30 * DAY_IN_SECONDS ) {
				$data['status'] = 'open';
			}
			$wpdb->update( $table, $data, array( 'id' => (int) $old['id'] ) );
			++$stats['updated'];
		} else {
			$data['project_id']  = (int) $project_id;
			$data['detector']    = $f['detector'];
			$data['fingerprint'] = $fp;
			$data['status']      = 'open';
			$data['first_seen']  = $now;
			$wpdb->insert( $table, $data );
			++$stats['new'];
		}
	}
	if ( $detectors ) {
		$in    = implode( ',', array_fill( 0, count( $detectors ), '%s' ) );
		$stale = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE project_id = %d AND status = 'open' AND last_seen < %s AND detector IN ($in)", array_merge( array( $project_id, $now ), $detectors ) ) ); // phpcs:ignore
		foreach ( $stale as $id ) {
			$wpdb->update( $table, array( 'status' => 'resolved' ), array( 'id' => (int) $id ) );
			++$stats['resolved'];
		}
	}
	return $stats;
}

/**
 * Query findings.
 *
 * @param array $args status, focus_id, context, limit, order.
 */
function sap_findings( $project_id, $args = array() ) {
	global $wpdb;
	$args  = wp_parse_args( $args, array( 'status' => 'open', 'focus_id' => null, 'context' => '', 'limit' => 500 ) );
	$where = $wpdb->prepare( 'project_id = %d', $project_id );
	if ( $args['status'] ) {
		$where .= $wpdb->prepare( ' AND status = %s', $args['status'] );
	}
	if ( null !== $args['focus_id'] && '' !== $args['focus_id'] ) {
		$where .= $wpdb->prepare( ' AND focus_id = %d', $args['focus_id'] );
	}
	if ( $args['context'] ) {
		$where .= $wpdb->prepare( ' AND context = %s', $args['context'] );
	}
	$rows = $wpdb->get_results( 'SELECT * FROM ' . sap_table( 'findings' ) . " WHERE $where ORDER BY score DESC, id ASC LIMIT " . (int) $args['limit'] ); // phpcs:ignore
	return array_map( 'sap_finding_hydrate', (array) $rows );
}

/**
 * Findings still in play (open or planned), highest score first. Used by the AI,
 * so planning a finding does not count as a change in the facts.
 */
function sap_findings_active( $project_id, $limit = 300 ) {
	$rows = array_merge( sap_findings( $project_id, array( 'status' => 'open', 'limit' => $limit ) ), sap_findings( $project_id, array( 'status' => 'planned', 'limit' => $limit ) ) );
	usort( $rows, function ( $a, $b ) {
		return $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id'];
	} );
	return array_slice( $rows, 0, $limit );
}

/**
 * One finding.
 */
function sap_finding( $id ) {
	global $wpdb;
	return sap_finding_hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'findings' ) . ' WHERE id = %d', $id ) ) ); // phpcs:ignore
}

/**
 * Count open findings by context.
 */
function sap_findings_counts( $project_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT context, focus_id, COUNT(*) n FROM ' . sap_table( 'findings' ) . " WHERE project_id = %d AND status = 'open' GROUP BY context, focus_id", $project_id ), ARRAY_A ); // phpcs:ignore
	$out  = array( 'total' => 0, 'context' => array(), 'focus' => array() );
	foreach ( (array) $rows as $r ) {
		$out['total']                         += (int) $r['n'];
		$out['context'][ $r['context'] ]       = ( $out['context'][ $r['context'] ] ?? 0 ) + (int) $r['n'];
		$out['focus'][ (int) $r['focus_id'] ]  = ( $out['focus'][ (int) $r['focus_id'] ] ?? 0 ) + (int) $r['n'];
	}
	return $out;
}

/**
 * Change a finding's status.
 */
function sap_finding_set_status( $id, $status, $task_id = null ) {
	global $wpdb;
	$data = array( 'status' => $status );
	if ( null !== $task_id ) {
		$data['task_id'] = (int) $task_id;
	}
	$wpdb->update( sap_table( 'findings' ), $data, array( 'id' => (int) $id ) );
}

/**
 * Turn a finding into a task of a period ("clarify" step in GTD).
 */
function sap_finding_to_task( $finding, $period, $status = 'next' ) {
	if ( ! $finding ) {
		return 0;
	}
	if ( $finding['task_id'] && sap_task( $finding['task_id'] ) ) {
		return $finding['task_id'];
	}
	// Content already ordered / being written goes straight to «Waiting For».
	if ( 'next' === $status && ! empty( $finding['evidence']['task_status'] ) ) {
		$status = $finding['evidence']['task_status'];
	}
	$task_id = sap_task_save( array(
		'project_id'  => $finding['project_id'],
		'focus_id'    => $finding['focus_id'],
		'finding_id'  => $finding['id'],
		'title'       => $finding['title'],
		'notes'       => sap_finding_notes( $finding ),
		'context'     => $finding['context'],
		'status'      => $status,
		'waiting_for' => (string) ( $finding['evidence']['waiting_for'] ?? '' ),
		'period'     => $period,
		'effort'     => $finding['effort'],
		'score'      => $finding['score'],
		'url'        => $finding['url'],
		'keyword'    => $finding['keyword'],
	) );
	sap_finding_set_status( $finding['id'], 'planned', $task_id );
	return $task_id;
}

/**
 * Plain-text task notes from a finding: detail + evidence list.
 */
function sap_finding_notes( $finding ) {
	$lines = array( (string) $finding['detail'] );
	$cols  = (array) ( $finding['evidence']['cols'] ?? array() );
	foreach ( (array) ( $finding['evidence']['items'] ?? array() ) as $it ) {
		if ( ! is_array( $it ) ) {
			$lines[] = '• ' . $it;
			continue;
		}
		$parts = array();
		foreach ( $cols as $k => $label ) {
			if ( isset( $it[ $k ] ) && '' !== (string) $it[ $k ] ) {
				$parts[] = ( $parts ? $label . ': ' : '' ) . $it[ $k ];
			}
		}
		$lines[] = '• ' . implode( ' · ', $parts );
	}
	return trim( implode( "\n", $lines ) );
}
