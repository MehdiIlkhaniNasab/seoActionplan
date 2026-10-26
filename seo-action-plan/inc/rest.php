<?php
/**
 * REST API (namespace seoactionplan/v1).
 *
 *   GET  /oauth/google                 Google OAuth callback.
 *   POST /crawl/{project}              Screaming Frog crawl intake (project key).
 *   GET  /report/{project}             Report export for SeoReport (project key).
 *   POST /activity/{project}           Add an activity entry from outside (project key).
 *   POST /sync/{project}/{step}        Run one sync step (logged-in manager).
 *   GET  /gsc/sites                    Search Console properties (logged-in manager).
 *   GET  /seosignal/projects           Rank tracker projects (logged-in manager).
 *
 * The project key is sent in the X-SAP-Key header. X-SR-Key is accepted too, so
 * the SeoReport Screaming Frog agent can push the same crawl here unchanged.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	$ns = 'seoactionplan/v1';

	register_rest_route( $ns, '/oauth/google', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'sap_rest_oauth_google',
	) );

	register_rest_route( $ns, '/crawl/(?P<project>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_rest_key_permission',
		'callback'            => 'sap_rest_crawl_ingest',
	) );

	register_rest_route( $ns, '/report/(?P<project>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => 'sap_rest_key_permission',
		'callback'            => 'sap_rest_report',
		'args'                => array(
			'period' => array( 'type' => 'string' ),
			'from'   => array( 'type' => 'string' ),
			'to'     => array( 'type' => 'string' ),
		),
	) );

	register_rest_route( $ns, '/activity/(?P<project>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_rest_key_permission',
		'callback'            => 'sap_rest_activity_add',
	) );

	register_rest_route( $ns, '/sync/(?P<project>\d+)/(?P<step>[a-z_]+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$project = sap_project( (int) $req['project'] );
			if ( ! $project ) {
				return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
			}
			return rest_ensure_response( sap_sync_step( $project, sanitize_key( $req['step'] ), rest_sanitize_boolean( $req->get_param( 'force' ) ) ) );
		},
	) );

	register_rest_route( $ns, '/gsc/sites', array(
		'methods'             => 'GET',
		'permission_callback' => 'sap_can',
		'callback'            => function () {
			delete_transient( 'sap_gsc_sites' );
			$sites = sap_gsc_sites();
			return is_wp_error( $sites ) ? $sites : array( 'sites' => $sites );
		},
	) );

	register_rest_route( $ns, '/seosignal/projects', array(
		'methods'             => 'GET',
		'permission_callback' => 'sap_can',
		'callback'            => function () {
			$list = sap_ss_rt_projects();
			return is_wp_error( $list ) ? $list : array( 'projects' => $list, 'usage' => sap_ss_usage() );
		},
	) );

	// Quick task status change from the board / dashboard (no page reload).
	register_rest_route( $ns, '/task/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$task = sap_task( (int) $req['id'] );
			if ( ! $task ) {
				return new WP_Error( 'sap_task', 'کار یافت نشد.', array( 'status' => 404 ) );
			}
			$status = sanitize_key( $req->get_param( 'status' ) );
			if ( ! array_key_exists( $status, sap_task_statuses() ) ) {
				return new WP_Error( 'sap_status', 'وضعیت نامعتبر.', array( 'status' => 400 ) );
			}
			if ( ! sap_can() && ( $task['assignee'] !== get_current_user_id() || ! in_array( $status, array( 'doing', 'waiting', 'done' ), true ) ) ) {
				return new WP_Error( 'sap_forbidden', 'اجازه این تغییر را ندارید.', array( 'status' => 403 ) );
			}
			sap_task_save( array( 'status' => $status ), $task['id'] );
			return array( 'ok' => true, 'status' => $status, 'label' => sap_task_statuses()[ $status ] );
		},
	) );

	// AI assistant: write the deliverable of a task.
	register_rest_route( $ns, '/ai/task/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$task = sap_task( (int) $req['id'] );
			if ( ! $task ) {
				return new WP_Error( 'sap_task', 'کار یافت نشد.', array( 'status' => 404 ) );
			}
			if ( ! sap_can() && $task['assignee'] !== get_current_user_id() ) {
				return new WP_Error( 'sap_forbidden', 'اجازه این کار را ندارید.', array( 'status' => 403 ) );
			}
			$text = sap_ai_task_assist( $task );
			return is_wp_error( $text ) ? $text : array( 'ok' => true, 'output' => $text );
		},
	) );

	// Quick finding action (plan / someday / dismiss / reopen) without page reload.
	register_rest_route( $ns, '/finding/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$f  = sap_finding( (int) $req['id'] );
			$do = sanitize_key( $req->get_param( 'do' ) );
			if ( ! $f ) {
				return new WP_Error( 'sap_finding', 'یافته پیدا نشد.', array( 'status' => 404 ) );
			}
			$period = sap_period_sanitize( (string) ( $req->get_param( 'period' ) ?: sap_period_current() ) );
			if ( 'plan' === $do ) {
				sap_finding_to_task( $f, $period );
				$msg = 'به اکشن پلن ' . sap_period_label( $period ) . ' اضافه شد';
			} elseif ( 'someday' === $do ) {
				sap_finding_to_task( $f, '', 'someday' );
				$msg = 'به «شاید بعداً» رفت';
			} elseif ( 'dismiss' === $do ) {
				sap_finding_set_status( $f['id'], 'dismissed' );
				$msg = 'رد شد';
			} elseif ( 'reopen' === $do ) {
				sap_finding_set_status( $f['id'], 'open' );
				$msg = 'به صندوق ورودی برگشت';
			} else {
				return new WP_Error( 'sap_do', 'عملیات نامعتبر.', array( 'status' => 400 ) );
			}
			return array( 'ok' => true, 'msg' => $msg );
		},
	) );
} );

/**
 * OAuth callback → save tokens → back to settings.
 */
function sap_rest_oauth_google( WP_REST_Request $req ) {
	$code  = (string) $req->get_param( 'code' );
	$state = (string) $req->get_param( 'state' );
	$back  = sap_url( 'settings' );
	if ( $req->get_param( 'error' ) || '' === $code ) {
		wp_safe_redirect( add_query_arg( array( 'sap_msg' => rawurlencode( 'اتصال گوگل لغو شد.' ), 'sap_ok' => 0 ), $back ) );
		exit;
	}
	$res = sap_google_handle_callback( $code, $state );
	$msg = is_wp_error( $res ) ? $res->get_error_message() : 'حساب گوگل با موفقیت متصل شد.';
	if ( ! is_wp_error( $res ) && ! sap_google_has_sheets() ) {
		$msg .= ' هشدار: اجازه Google Sheets داده نشد؛ برای خواندن شیت‌ها دوباره متصل شوید و تیک Sheets را بزنید.';
	}
	delete_transient( 'sap_gsc_sites' );
	wp_safe_redirect( add_query_arg( array( 'sap_msg' => rawurlencode( $msg ), 'sap_ok' => is_wp_error( $res ) ? 0 : 1 ), $back ) );
	exit;
}

/**
 * Project-key auth for machine endpoints.
 */
function sap_rest_key_permission( WP_REST_Request $req ) {
	$key = (string) ( $req->get_header( 'x_sap_key' ) ?: $req->get_header( 'x_sr_key' ) );
	if ( ! sap_project_by_key( (int) $req['project'], $key ) ) {
		return new WP_Error( 'sap_forbidden', 'کلید API پروژه نامعتبر است.', array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Parse an uploaded crawl file (CSV or gzip CSV).
 *
 * @return array|WP_Error rows
 */
function sap_crawl_rows_from_file( $tmp, $name ) {
	$name = strtolower( (string) $name );
	if ( '.gz' === substr( $name, -3 ) ) {
		$raw = gzdecode( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			return new WP_Error( 'sap_gz', 'فایل gzip نامعتبر است.' );
		}
		$csv = tempnam( get_temp_dir(), 'sap-crawl' );
		file_put_contents( $csv, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$rows = sap_sf_parse_csv( $csv );
		wp_delete_file( $csv );
		return $rows;
	}
	return sap_sf_parse_csv( $tmp );
}

/**
 * Accept a Screaming Frog crawl: multipart CSV (file), gzip CSV, or JSON rows.
 */
function sap_rest_crawl_ingest( WP_REST_Request $req ) {
	$project_id = (int) $req['project'];
	$files      = $req->get_file_params();
	$rows       = null;
	$meta       = array(
		'agent'     => sanitize_text_field( (string) $req->get_param( 'agent' ) ),
		'start_url' => esc_url_raw( (string) $req->get_param( 'start_url' ) ),
	);
	if ( ! empty( $files['file']['tmp_name'] ) ) {
		$rows = sap_crawl_rows_from_file( $files['file']['tmp_name'], $files['file']['name'] );
	} else {
		$json = $req->get_json_params();
		if ( ! empty( $json['rows'] ) && is_array( $json['rows'] ) ) {
			$rows = array_map( 'sap_sf_normalize_row', $json['rows'] );
		}
	}
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	if ( empty( $rows ) ) {
		return new WP_Error( 'sap_empty', 'داده‌ای ارسال نشد. فایل internal_all.csv را در فیلد file ارسال کنید.', array( 'status' => 400 ) );
	}
	$kept = sap_sf_store_crawl( $project_id, $rows, 'agent', $meta );
	return array(
		'ok'      => true,
		'urls'    => count( $rows ),
		'kept'    => $kept,
		'message' => sprintf( 'Crawl received by SEO Action Plan: %d URLs', count( $rows ) ),
	);
}

/**
 * Report export: everything SeoReport needs for «اقدامات انجام‌شده» and
 * «برنامه ماه آینده» of a period.
 *
 * Query: ?period=1405-08 (Jalali month) or ?from=Y-m-d&to=Y-m-d.
 */
function sap_rest_report( WP_REST_Request $req ) {
	$project = sap_project( (int) $req['project'] );
	$from    = sap_parse_date( (string) $req->get_param( 'from' ) );
	$to      = sap_parse_date( (string) $req->get_param( 'to' ) );
	if ( $from && $to ) {
		$period = sap_period_of( $from );
	} else {
		$period = sap_period_sanitize( (string) ( $req->get_param( 'period' ) ?: sap_period_current() ) );
		list( $from, $to ) = sap_period_range( $period );
	}
	$next     = sap_period_shift( $period, 1 );
	$tasks    = sap_tasks( array( 'project_id' => $project['id'], 'period' => $period ) );
	$planned  = array_filter( $tasks, function ( $t ) {
		return 'dropped' !== $t['status'];
	} );
	$done     = array_filter( $tasks, function ( $t ) {
		return 'done' === $t['status'];
	} );
	$next_plan = array();
	foreach ( sap_tasks( array( 'project_id' => $project['id'], 'period' => $next, 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) ) as $t ) {
		$next_plan[] = array(
			'title'          => $t['title'],
			'category'       => $t['context'],
			'category_label' => sap_context_label( $t['context'] ),
			'url'            => $t['url'],
			'effort'         => $t['effort'],
		);
	}
	$focuses = array();
	foreach ( sap_focuses( $project['id'] ) as $f ) {
		$k         = sap_focus_kpis( $f, $project['id'] );
		$metric    = $f['kpi_metric'];
		$focuses[] = array(
			'name'     => $f['name'],
			'goal'     => $f['goal'],
			'status'   => $f['status'],
			'metric'   => $metric,
			'metric_label' => sap_kpi_metrics()[ $metric ] ?? $metric,
			'baseline' => $f['kpi_baseline'],
			'target'   => $f['kpi_target'],
			'current'  => $k ? $k[ $metric ] : null,
			'progress' => $k ? sap_focus_progress( $f, $k[ $metric ] ) : null,
			'deadline' => $f['deadline'],
		);
	}
	return array(
		'project'        => array( 'id' => $project['id'], 'name' => $project['name'], 'site' => $project['site_url'] ),
		'period'         => array( 'key' => $period, 'label' => sap_period_label( $period ), 'from' => $from, 'to' => $to ),
		'activity'       => sap_activity_export( $project, $from, $to ),
		'plan'           => array(
			'tasks_planned' => count( $planned ),
			'tasks_done'    => count( $done ),
			'hours_planned' => array_sum( array_column( $planned, 'effort' ) ),
			'hours_done'    => array_sum( array_column( $done, 'effort' ) ),
		),
		'next_plan'      => array(
			'period'     => array( 'key' => $next, 'label' => sap_period_label( $next ) ),
			'items'      => $next_plan,
			'lines'      => implode( "\n", array_map( function ( $i ) {
				return $i['category_label'] . ' | ' . $i['title'];
			}, $next_plan ) ),
		),
		'focuses'        => $focuses,
		'generated_at'   => sap_now(),
		'plugin_version' => SAP_VERSION,
	);
}

/**
 * Add an activity entry from an external agent (e.g. a future site connector).
 *
 * JSON: {category, title, url, before, after, happened_at, ref}
 */
function sap_rest_activity_add( WP_REST_Request $req ) {
	$j = (array) $req->get_json_params();
	if ( '' === trim( (string) ( $j['title'] ?? '' ) ) ) {
		return new WP_Error( 'sap_title', 'title الزامی است.', array( 'status' => 400 ) );
	}
	$data = array(
		'category'     => sanitize_key( $j['category'] ?? 'management' ),
		'action'       => sanitize_key( $j['action'] ?? 'api' ),
		'title'        => (string) $j['title'],
		'url'          => (string) ( $j['url'] ?? '' ),
		'before_value' => (string) ( $j['before'] ?? '' ),
		'after_value'  => (string) ( $j['after'] ?? '' ),
		'source'       => 'agent' === ( $j['source'] ?? '' ) ? 'agent' : 'api',
		'user_id'      => 0,
		'happened_at'  => sap_parse_date( (string) ( $j['happened_at'] ?? '' ) ) ? sap_parse_date( (string) $j['happened_at'] ) . ' 12:00:00' : sap_now(),
	);
	$pid = (int) $req['project'];
	$id  = ! empty( $j['ref'] ) ? sap_activity_upsert_ref( $pid, 'ext:' . substr( sanitize_key( $j['ref'] ), 0, 56 ), $data ) : sap_activity_add( $pid, $data );
	return array( 'ok' => true, 'id' => $id );
}
