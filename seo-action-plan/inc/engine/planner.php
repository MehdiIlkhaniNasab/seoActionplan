<?php
/**
 * Monthly action plan generator.
 *
 * For a Jalali month: add the recurring tasks, then fill the remaining capacity
 * (hours) with the highest-scoring open findings. Focus findings already carry a
 * higher weight in their score, so the plan leans towards the project's focus while
 * critical site-wide problems still get in.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hours already planned in a period (not dropped).
 */
function sap_plan_used_hours( $project_id, $period ) {
	$used = 0;
	foreach ( sap_tasks( array( 'project_id' => $project_id, 'period' => $period ) ) as $t ) {
		if ( 'dropped' !== $t['status'] ) {
			$used += $t['effort'];
		}
	}
	return $used;
}

/**
 * Add this month's recurring tasks (once per period).
 *
 * @return int Tasks added.
 */
function sap_plan_add_recurring( $project_id, $period ) {
	$have = array();
	foreach ( sap_tasks( array( 'project_id' => $project_id, 'period' => $period ) ) as $t ) {
		if ( $t['recurring'] ) {
			$have[ $t['recurring'] ] = true;
		}
	}
	$n = 0;
	foreach ( sap_recurring_tasks() as $r ) {
		$key = 'r' . substr( md5( $r['title'] ), 0, 12 );
		if ( isset( $have[ $key ] ) ) {
			continue;
		}
		sap_task_save( array(
			'project_id' => $project_id,
			'title'      => $r['title'],
			'context'    => $r['context'],
			'status'     => 'next',
			'period'     => $period,
			'effort'     => $r['hours'],
			'recurring'  => $key,
		) );
		++$n;
	}
	return $n;
}

/**
 * Generate (or top up) the plan of a period.
 *
 * @param array $args capacity (hours, default project capacity), max_tasks, focus_share.
 * @return array{recurring:int, added:int, hours:float, capacity:float}
 */
function sap_plan_generate( $project_id, $period, $args = array() ) {
	$project = sap_project( $project_id );
	$args    = wp_parse_args( $args, array(
		'capacity'  => $project ? $project['capacity_hours'] : 40,
		'max_tasks' => 40,
	) );
	$rec      = sap_plan_add_recurring( $project_id, $period );
	$used     = sap_plan_used_hours( $project_id, $period );
	$capacity = (float) $args['capacity'];
	$added    = 0;
	$count    = count( sap_tasks( array( 'project_id' => $project_id, 'period' => $period ) ) );
	foreach ( sap_findings( $project_id, array( 'status' => 'open', 'limit' => 300 ) ) as $f ) {
		if ( $count >= $args['max_tasks'] || $used >= $capacity ) {
			break;
		}
		if ( $used + $f['effort'] > $capacity ) {
			continue; // Try smaller items that still fit.
		}
		sap_finding_to_task( $f, $period );
		$used += $f['effort'];
		++$added;
		++$count;
	}
	sap_project_set_option( $project_id, 'plan_generated_' . $period, sap_now() );
	return array(
		'recurring' => $rec,
		'added'     => $added,
		'hours'     => $used,
		'capacity'  => $capacity,
	);
}

/**
 * Carry unfinished tasks of a period over to another one.
 *
 * @return int Tasks moved.
 */
function sap_plan_carry_over( $project_id, $from, $to ) {
	$n = 0;
	foreach ( sap_tasks( array( 'project_id' => $project_id, 'period' => $from, 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) ) as $t ) {
		if ( $t['recurring'] ) {
			continue; // Recurring tasks are re-created for each month.
		}
		sap_task_save( array( 'period' => $to ), $t['id'] );
		++$n;
	}
	return $n;
}

/**
 * Weekly review data (GTD «Reflect»).
 */
function sap_weekly_review( $project_id ) {
	$period   = sap_period_current();
	$today    = current_time( 'Y-m-d' );
	$last     = (string) ( sap_project( $project_id )['options']['last_review'] ?? '' );
	$overdue  = array();
	$waiting  = array();
	$inbox    = 0;
	foreach ( sap_tasks( array( 'project_id' => $project_id, 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) ) as $t ) {
		if ( 'inbox' === $t['status'] ) {
			++$inbox;
		}
		if ( $t['due_date'] && $t['due_date'] < $today ) {
			$overdue[] = $t;
		}
		if ( 'waiting' === $t['status'] && strtotime( $t['updated_at'] ) < strtotime( '-7 days', current_time( 'timestamp' ) ) ) { // phpcs:ignore
			$waiting[] = $t;
		}
	}
	global $wpdb;
	$new = $last ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . sap_table( 'findings' ) . " WHERE project_id = %d AND status = 'open' AND first_seen > %s", $project_id, $last ) ) : null; // phpcs:ignore
	$open_period = count( sap_tasks( array( 'project_id' => $project_id, 'period' => $period, 'status' => array( 'next', 'doing', 'waiting' ) ) ) );
	return array(
		'last'        => $last,
		'due'         => ! $last || strtotime( $last ) < strtotime( '-7 days', current_time( 'timestamp' ) ), // phpcs:ignore
		'inbox_tasks' => $inbox,
		'overdue'     => $overdue,
		'waiting'     => $waiting,
		'new_found'   => $new,
		'open_period' => $open_period,
	);
}
