<?php
/**
 * Autopilot: a daily assistant that prepares the safe, repetitive work on its
 * own — structured data, image alt text, exact-match internal links and tasks
 * for critical alerts — and, depending on the project's policy, either
 * applies it (low-risk only, within a daily limit, always with rollback) or
 * queues it for one-click approval, then reports to the manager.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Policy of a project.
 */
function sap_autopilot( $project ) {
	return wp_parse_args( (array) ( $project['options']['autopilot'] ?? array() ), array( 'mode' => 'off', 'schema' => 1, 'alts' => 1, 'links' => 1, 'alerts' => 1, 'limit' => 5, 'last' => '', 'last_msg' => '' ) );
}

function sap_autopilot_modes() {
	return array(
		'off'     => array( 'خاموش', 'کاری انجام نمی‌شود.' ),
		'suggest' => array( 'پیشنهاد و تایید', 'هر روز تغییرات آماده می‌شوند و در صف تایید می‌مانند؛ با یک کلیک تایید می‌کنید.' ),
		'auto'    => array( 'خودکار (کم‌ریسک)', 'تغییرات کم‌ریسک بدون خطا خودکار اعمال می‌شوند (با سقف روزانه و امکان برگشت)؛ بقیه به صف تایید می‌روند.' ),
	);
}

/**
 * Pending approvals prepared by the autopilot: [{token, type, label, url, view, at}].
 */
function sap_autopilot_queue( $project_id ) {
	$q = get_option( 'sap_autopilot_q_' . $project_id );
	$q = is_array( $q ) ? $q : array();
	// Drop items whose preview expired or was applied.
	return array_values( array_filter( $q, function ( $i ) use ( $project_id ) {
		return null !== sap_auto_preview_get( $project_id, $i['token'] );
	} ) );
}

function sap_autopilot_queue_save( $project_id, $q ) {
	update_option( 'sap_autopilot_q_' . $project_id, array_slice( array_values( $q ), 0, 60 ), false );
}

/**
 * One run for a project.
 *
 * @return array {applied, queued, tasks, msg}
 */
function sap_autopilot_run( $project ) {
	$pol = sap_autopilot( $project );
	if ( 'off' === $pol['mode'] ) {
		return array( 'applied' => 0, 'queued' => 0, 'tasks' => 0, 'msg' => 'دستیار خودکار خاموش است.' );
	}
	$GLOBALS['sap_ai_project']    = $project;
	$GLOBALS['sap_autopilot_run'] = true;
	$pid     = $project['id'];
	$auto    = 'auto' === $pol['mode'];
	$limit   = max( 1, min( 30, (int) $pol['limit'] ) );
	$queue   = sap_autopilot_queue( $pid );
	$queued  = array_flip( array_map( function ( $i ) {
		return $i['type'] . '|' . sap_norm_url( $i['url'] );
	}, $queue ) );
	$applied = array();
	$added   = 0;
	$budget  = $limit;
	$t0      = microtime( true );
	$ready   = sap_auto_ready( $project, 'schema' );
	$handle  = function ( $type, $plan, $safe, $view ) use ( $project, $pid, $auto, &$applied, &$queue, &$added, &$budget, &$queued ) {
		if ( is_wp_error( $plan ) || empty( $plan['token'] ) ) {
			return;
		}
		$pv = sap_auto_preview_get( $pid, $plan['token'] );
		if ( $auto && $safe && $budget > 0 ) {
			$r = sap_auto_apply( $project, $plan['token'], array(), 'autopilot' );
			if ( ! is_wp_error( $r ) ) {
				$applied[] = $pv['label'];
				--$budget;
				return;
			}
		}
		$k = $type . '|' . sap_norm_url( $pv['url'] );
		if ( isset( $queued[ $k ] ) ) {
			return;
		}
		$queue[]      = array( 'token' => $plan['token'], 'type' => $type, 'label' => $pv['label'], 'url' => $pv['url'], 'view' => $view, 'at' => sap_now() );
		$queued[ $k ] = true;
		++$added;
	};
	if ( ! is_wp_error( $ready ) ) {
		$done = array();
		foreach ( sap_auto_log( $pid, '', 600 ) as $e ) {
			if ( 'live' === $e['status'] ) {
				$done[ $e['type'] . '|' . sap_norm_url( $e['url'] ) ] = true;
			}
		}
		// 1) Structured data for the most visited pages without it.
		if ( $pol['schema'] ) {
			$clicks = array();
			foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
				$clicks[ sap_norm_url( $r['page'] ) ] = (int) $r['clicks'];
			}
			$cands = array();
			foreach ( sap_site_inventory( $pid ) as $k => $it ) {
				if ( $it['redirect'] || $it['noindex'] || ! in_array( $it['ctype'], array( 'product', 'post', 'product_cat' ), true ) || isset( $done[ 'schema|' . $k ] ) || isset( $queued[ 'schema|' . $k ] ) ) {
					continue;
				}
				$cands[] = array( 'url' => $it['url'], 'c' => $clicks[ $k ] ?? 0 );
			}
			usort( $cands, function ( $a, $b ) {
				return $b['c'] <=> $a['c'];
			} );
			foreach ( array_slice( $cands, 0, 3 ) as $c ) {
				if ( microtime( true ) - $t0 > 80 ) {
					break;
				}
				$plan = sap_schema_plan( $project, $c['url'] );
				if ( is_wp_error( $plan ) ) {
					continue;
				}
				$safe = ! array_filter( $plan['blocks'], function ( $b ) {
					return 'invalid' === $b['status'];
				} );
				$view = $plan;
				unset( $view['token'] );
				$handle( 'schema', $plan, $safe, $view );
			}
		}
		// 2) Alt text for pages with images without alt.
		if ( $pol['alts'] ) {
			foreach ( array_slice( sap_alt_queue( $project, 6 )['rows'], 0, 3 ) as $r ) {
				if ( microtime( true ) - $t0 > 120 || isset( $queued[ 'alts|' . sap_norm_url( $r['url'] ) ] ) ) {
					continue;
				}
				$plan = sap_alt_plan( $project, $r['url'] );
				if ( is_wp_error( $plan ) ) {
					continue;
				}
				$view = $plan;
				unset( $view['token'] );
				$handle( 'alts', $plan, 'ai' === $plan['source'], $view );
			}
		}
		// 3) Internal links where the anchor is already in the text (no rewriting).
		if ( $pol['links'] ) {
			$n = 0;
			foreach ( sap_link_queue( $project ) as $l ) {
				if ( $l['done'] || $n >= 4 || microtime( true ) - $t0 > 150 || isset( $queued[ 'link|' . sap_norm_url( $l['source'] ) ] ) ) {
					continue;
				}
				$plan = sap_link_insert_plan( $project, $l['source'], $l['target'], $l['anchor'], $l['finding_id'], false );
				if ( is_wp_error( $plan ) ) {
					continue;
				}
				++$n;
				$view = $plan;
				unset( $view['token'] );
				$handle( 'link', $plan, 'exact' === $plan['method'], $view );
			}
		}
	}
	// 4) Critical alerts become tasks (once).
	$tasks = 0;
	if ( $pol['alerts'] ) {
		foreach ( sap_alerts( $pid, array( 'status' => array( 'open' ), 'severity' => 'critical' ) ) as $a ) {
			$ref = 'alert-task:' . $a['id'];
			if ( get_option( 'sap_ap_' . $pid . '_' . md5( $ref ) ) ) {
				continue;
			}
			sap_task_save( array( 'project_id' => $pid, 'title' => 'رفع هشدار بحرانی: ' . $a['title'], 'notes' => (string) $a['detail'], 'context' => 'technical', 'status' => 'next', 'period' => sap_period_current(), 'effort' => 0.5, 'url' => (string) $a['url'], 'source' => 'ai' ) );
			update_option( 'sap_ap_' . $pid . '_' . md5( $ref ), 1, false );
			++$tasks;
		}
	}
	sap_autopilot_queue_save( $pid, $queue );
	unset( $GLOBALS['sap_autopilot_run'] );
	$msg = sprintf( 'دستیار خودکار: %s تغییر اعمال شد، %s تغییر تازه در صف تایید (کل صف: %s)، %s کار از هشدارهای بحرانی.', sap_num( count( $applied ) ), sap_num( $added ), sap_num( count( $queue ) ), sap_num( $tasks ) );
	if ( is_wp_error( $ready ) ) {
		$msg .= ' تغییرات سایت انجام نشد: ' . $ready->get_error_message();
	}
	$pol['last']     = sap_now();
	$pol['last_msg'] = $msg;
	sap_project_set_option( $pid, 'autopilot', $pol );
	if ( $applied || $added || $tasks ) {
		$items = array_merge( array_map( function ( $l ) {
			return '✓ ' . $l;
		}, $applied ), $added ? array( sap_num( $added ) . ' تغییر تازه منتظر تایید شماست.' ) : array() );
		sap_notify_manager( array( 'email', 'tg', 'bale' ), array(
			'subject' => 'دستیار خودکار سکان — ' . $project['name'],
			'text'    => $msg,
			'bot'     => "🤖 سکان · " . $project['name'] . "\n" . $msg,
			'email'   => array( 'title' => 'گزارش دستیار خودکار', 'intro' => $msg, 'items' => $items, 'button' => array( 'بررسی و تایید', sap_project_url( $pid, 'automate', array( 's' => 'autopilot' ) ) ) ),
		) );
	}
	return array( 'applied' => count( $applied ), 'queued' => $added, 'tasks' => $tasks, 'msg' => $msg );
}

add_action( 'sap_hourly', function () {
	// Once a day after 03:00 (quiet hours, after the night's data).
	$today = current_time( 'Y-m-d' );
	if ( (int) current_time( 'G' ) < 3 || get_option( 'sap_autopilot_day' ) === $today ) {
		return;
	}
	update_option( 'sap_autopilot_day', $today, false );
	foreach ( sap_projects() as $p ) {
		if ( 'off' !== sap_autopilot( $p )['mode'] ) {
			sap_autopilot_run( $p );
		}
	}
}, 20 );
