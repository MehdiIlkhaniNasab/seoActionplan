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

	// Comments (your own notes) on tasks, findings, ideas, revisions.
	register_rest_route( $ns, '/comment', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$type = sanitize_key( (string) $req->get_param( 'type' ) );
			$id   = sap_comment_add( absint( $req->get_param( 'project' ) ), $type, absint( $req->get_param( 'id' ) ), (string) $req->get_param( 'body' ) );
			if ( ! $id ) {
				return new WP_Error( 'sap_comment', 'متن یادداشت خالی است.', array( 'status' => 400 ) );
			}
			$c = sap_comment( $id );
			return array( 'ok' => true, 'id' => $id, 'meta' => sap_user_name( (int) $c['user_id'] ) . ' · ' . sap_jdate( $c['created_at'] ) );
		},
	) );
	register_rest_route( $ns, '/comment/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$c = sap_comment( (int) $req['id'] );
			if ( ! $c ) {
				return new WP_Error( 'sap_comment', 'یادداشت پیدا نشد.', array( 'status' => 404 ) );
			}
			if ( ! sap_can() && (int) $c['user_id'] !== get_current_user_id() ) {
				return new WP_Error( 'sap_forbidden', 'فقط یادداشت خودتان را می‌توانید حذف کنید.', array( 'status' => 403 ) );
			}
			sap_comment_delete( (int) $c['id'] );
			return array( 'ok' => true );
		},
	) );

	// Delete one task / bulk actions on many.
	register_rest_route( $ns, '/task/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! sap_task( (int) $req['id'] ) ) {
				return new WP_Error( 'sap_task', 'کار یافت نشد.', array( 'status' => 404 ) );
			}
			sap_task_delete( (int) $req['id'] );
			return array( 'ok' => true, 'msg' => 'کار حذف شد' );
		},
	) );
	register_rest_route( $ns, '/tasks/bulk', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$ids = array_filter( array_map( 'absint', (array) $req->get_param( 'ids' ) ) );
			$do  = sanitize_key( (string) $req->get_param( 'do' ) );
			$val = sanitize_text_field( (string) $req->get_param( 'value' ) );
			$n   = 0;
			foreach ( $ids as $id ) {
				if ( ! sap_task( $id ) ) {
					continue;
				}
				if ( 'delete' === $do ) {
					sap_task_delete( $id );
				} elseif ( 'status' === $do && array_key_exists( $val, sap_task_statuses() ) ) {
					sap_task_save( array( 'status' => $val ), $id );
				} elseif ( 'period' === $do ) {
					sap_task_save( array( 'period' => sap_period_sanitize( $val ) ), $id );
				} else {
					continue;
				}
				++$n;
			}
			return array( 'ok' => true, 'msg' => sprintf( '%s کار %s', sap_num( $n ), 'delete' === $do ? 'حذف شد' : 'بروز شد' ) );
		},
	) );

	// Content ideas and the content calendar.
	register_rest_route( $ns, '/ideas/(?P<project>\d+)/generate', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$project = sap_project( (int) $req['project'] );
			if ( ! $project ) {
				return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
			}
			$period = (string) $req->get_param( 'period' );
			$res    = $period ? sap_calendar_fill_month( $project, sap_period_sanitize( $period ), max( 1, absint( $req->get_param( 'target' ) ?: 8 ) ) ) : sap_ideas_generate( $project, absint( $req->get_param( 'count' ) ?: 10 ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$u = sap_ai_usage();
			return array( 'ok' => true, 'msg' => ( $res['msg'] ?? sprintf( '%s ایده جدید آماده بررسی است.', sap_num( $res['added'] ) ) ) . sprintf( ' (هزینه حدود %s دلار)', sap_num( $u['last_cost'], 3 ) ) );
		},
	) );
	register_rest_route( $ns, '/idea/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$idea = sap_idea( (int) $req['id'] );
			if ( ! $idea ) {
				return new WP_Error( 'sap_idea', 'ایده پیدا نشد.', array( 'status' => 404 ) );
			}
			$do = sanitize_key( (string) $req->get_param( 'do' ) );
			switch ( $do ) {
				case 'schedule':
					sap_calendar_schedule( $idea['project_id'], array( $idea['id'] ), (string) $req->get_param( 'period' ) ?: sap_period_current() );
					return array( 'ok' => true, 'msg' => 'به تقویم ' . sap_period_label( sap_period_sanitize( (string) $req->get_param( 'period' ) ?: sap_period_current() ) ) . ' اضافه شد' );
				case 'approve':
					sap_idea_save( array( 'status' => 'approved' ), $idea['id'] );
					return array( 'ok' => true, 'msg' => 'تأیید شد' );
				case 'reject':
					sap_idea_save( array( 'status' => 'rejected' ), $idea['id'] );
					return array( 'ok' => true, 'msg' => 'رد شد؛ هوش مصنوعی دیگر آن را پیشنهاد نمی‌دهد' );
				case 'unschedule':
					sap_idea_save( array( 'status' => 'approved', 'period' => '', 'slot' => 0, 'planned_date' => null ), $idea['id'] );
					sap_calendar_spread_dates( $idea['project_id'], $idea['period'] );
					return array( 'ok' => true, 'msg' => 'از تقویم خارج شد' );
				case 'published':
					sap_idea_save( array( 'status' => 'published', 'published_url' => (string) $req->get_param( 'url' ) ), $idea['id'] );
					return array( 'ok' => true, 'msg' => 'منتشرشده ثبت شد' );
				case 'delete':
					sap_idea_delete( $idea['id'] );
					return array( 'ok' => true, 'msg' => 'حذف شد' );
				case 'edit':
					$data = array();
					foreach ( array( 'keyword', 'title', 'brief', 'goal', 'source_note' ) as $k ) {
						if ( null !== $req->get_param( $k ) ) {
							$data[ $k ] = (string) $req->get_param( $k );
						}
					}
					if ( null !== $req->get_param( 'secondary' ) ) {
						$old = array();
						foreach ( sap_idea_secondary( $idea ) as $s ) {
							$old[ sap_kw_key( $s[0] ) ] = $s[1];
						}
						$data['secondary'] = array_map( function ( $k ) use ( $old ) {
							return array( $k, (int) ( $old[ sap_kw_key( $k ) ] ?? 0 ) );
						}, sap_list( (string) $req->get_param( 'secondary' ) ) );
					}
					sap_idea_save( $data, $idea['id'] );
					return array( 'ok' => true, 'msg' => 'ذخیره شد' );
				case 'move':
					$dir   = 'up' === $req->get_param( 'dir' ) ? -1 : 1;
					$items = sap_calendar_items( $idea['project_id'], $idea['period'] );
					$pos   = array_search( $idea['id'], array_column( $items, 'id' ), true );
					$swap  = $items[ $pos + $dir ] ?? null;
					if ( false !== $pos && $swap ) {
						sap_idea_save( array( 'slot' => $swap['slot'] ), $idea['id'] );
						sap_idea_save( array( 'slot' => $idea['slot'] ), $swap['id'] );
						sap_calendar_spread_dates( $idea['project_id'], $idea['period'] );
					}
					return array( 'ok' => true, 'msg' => 'جابه‌جا شد' );
				case 'task':
					$tid = sap_task_save( array(
						'project_id' => $idea['project_id'],
						'focus_id'   => $idea['focus_id'],
						'title'      => 'تولید محتوا: ' . ( $idea['title'] ?: $idea['keyword'] ),
						'notes'      => trim( "کلمه اصلی: {$idea['keyword']}\nکلمات فرعی: " . implode( '، ', array_column( sap_idea_secondary( $idea ), 0 ) ) . "\nهدف: {$idea['goal']}\n\n{$idea['brief']}\n\nساختار:\n• " . implode( "\n• ", $idea['outline'] ) ),
						'context'    => 'content_new',
						'status'     => 'next',
						'period'     => $idea['period'] ?: sap_period_current(),
						'due_date'   => $idea['planned_date'],
						'effort'     => 3,
						'keyword'    => $idea['keyword'],
						'source'     => 'ai',
					) );
					sap_idea_save( array( 'task_id' => $tid ), $idea['id'] );
					return array( 'ok' => true, 'msg' => 'به اکشن پلن اضافه شد' );
			}
			return new WP_Error( 'sap_do', 'عملیات نامعتبر.', array( 'status' => 400 ) );
		},
	) );
	register_rest_route( $ns, '/calendar/(?P<project>\d+)/sheet', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$project = sap_project( (int) $req['project'] );
			if ( ! $project ) {
				return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
			}
			if ( $req->get_param( 'create' ) ) {
				$res = sap_project_sheet_create( $project, (string) $req->get_param( 'existing' ) );
				return is_wp_error( $res ) ? $res : array( 'ok' => true ) + $res;
			}
			$res = sap_calendar_write_sheet( $project );
			return is_wp_error( $res ) ? $res : array( 'ok' => true, 'msg' => $res );
		},
	) );

	// SEO Signal: cost of a step, tools of the SEO Signal tab, AI advice, using results.
	register_rest_route( $ns, '/estimate/(?P<project>\d+)/(?P<step>[a-z_]+)', array(
		'methods'             => 'GET',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$project = sap_project( (int) $req['project'] );
			if ( ! $project ) {
				return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
			}
			$u = sap_ss_usage();
			return sap_ss_estimate( $project, sanitize_key( $req['step'] ) ) + array( 'left' => sap_ss_remaining(), 'cap' => $u['cap'], 'connected' => sap_ss_connected() );
		},
	) );
	register_rest_route( $ns, '/ss/(?P<project>\d+)/(?P<do>tool|advise|use|link|test)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$project = sap_project( (int) $req['project'] );
			if ( ! $project ) {
				return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
			}
			if ( 'test' === $req['do'] ) {
				return sap_ss_diagnose();
			}
			if ( 'link' === $req['do'] ) {
				return sap_ss_link_tracker( $project, (int) $req->get_param( 'id' ) );
			}
			if ( 'advise' === $req['do'] ) {
				$res = sap_ss_advise( $project );
				return is_wp_error( $res ) ? $res : array( 'ok' => true, 'actions' => $res );
			}
			if ( 'tool' === $req['do'] ) {
				$res = sap_ss_tool( $project, sanitize_key( (string) $req->get_param( 'tool' ) ), (array) $req->get_param( 'params' ), (bool) $req->get_param( 'force' ) );
				return is_wp_error( $res ) ? $res : array( 'ok' => true, 'left' => sap_ss_remaining() ) + $res;
			}
			return sap_ss_use( $project, (string) $req->get_param( 'action' ), (array) $req->get_param( 'items' ), (string) $req->get_param( 'url' ) );
		},
	) );

	// AI gateway test: key, model list, a tiny request with each configured model.
	register_rest_route( $ns, '/ai/test', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function () {
			$t = sap_ai_test();
			if ( $t['ok'] ) {
				delete_transient( 'sap_ai_denied' );
			}
			return $t;
		},
	) );

	// Network reachability test (settings page).
	register_rest_route( $ns, '/net/test', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$url = esc_url_raw( (string) $req->get_param( 'url' ) );
			if ( ! preg_match( '#^https://(sheets\.googleapis\.com|api\.gapgpt\.app|panel\.seosignal\.net|api\.anthropic\.com)/#', $url ) && $url !== untrailingslashit( esc_url_raw( sap_setting( 'ai_base_url' ) ) ) . '/models' ) {
				return new WP_Error( 'sap_url', 'آدرس مجاز نیست.', array( 'status' => 400 ) );
			}
			if ( 0 === strpos( $url, 'https://panel.seosignal.net/' ) && sap_ss_connected() ) {
				// A 200 is not enough: run a real request and read its JSON.
				$d    = sap_ss_diagnose();
				$fail = array_values( array_filter( $d['steps'], function ( $s ) {
					return false === $s['ok'];
				} ) );
				return array( 'ok' => $d['ok'], 'msg' => $d['ok'] ? end( $d['steps'] )['msg'] : $fail[0]['name'] . ': ' . $fail[0]['msg'] );
			}
			$r = sap_net_test( $url );
			return array( 'ok' => $r['ok'], 'msg' => $r['msg'] . ' · ' . $r['via'] );
		},
	) );

	// Client site connector: test, apply a revision as draft, publish, roll back.
	register_rest_route( $ns, '/site/(?P<project>\d+)/test', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$project = sap_project( (int) $req['project'] );
			$ping    = $project ? sap_site_request( $project, 'GET', 'ping' ) : new WP_Error( 'sap_project', 'پروژه یافت نشد.' );
			if ( is_wp_error( $ping ) ) {
				return $ping;
			}
			$seo = array( 'rank_math' => 'Rank Math', 'yoast' => 'Yoast' )[ $ping['seo_plugin'] ?? '' ] ?? 'بدون افزونه سئو';
			return array( 'ok' => true, 'msg' => sprintf( 'متصل: %s · افزونه رابط %s · %s · %s · لاگ از %s', $ping['site'], $ping['version'], $seo, ! empty( $ping['write'] ) ? 'اعمال تغییرات فعال' : 'فقط خواندن', $ping['since'] ) );
		},
	) );
	register_rest_route( $ns, '/revision/(?P<id>\d+)/site', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$rev = sap_revision( (int) $req['id'] );
			if ( ! $rev ) {
				return new WP_Error( 'sap_rev', 'نسخه پیدا نشد.', array( 'status' => 404 ) );
			}
			$res = sap_content_site_action( $rev, sanitize_key( (string) $req->get_param( 'do' ) ), (array) $req->get_param( 'extra' ) );
			return is_wp_error( $res ) ? $res : array( 'ok' => true ) + $res;
		},
	) );

	// Content audit & rewrite: start, run the next step, approve.
	register_rest_route( $ns, '/content/(?P<project>\d+)/start', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$project = sap_project( (int) $req['project'] );
			$url     = esc_url_raw( (string) $req->get_param( 'url' ) );
			if ( ! $project || '' === $url ) {
				return new WP_Error( 'sap_content', 'پروژه یا آدرس صفحه نامعتبر است.', array( 'status' => 400 ) );
			}
			$id = sap_content_start( $project, $url, absint( $req->get_param( 'task_id' ) ) );
			return is_wp_error( $id ) ? $id : array( 'ok' => true, 'id' => $id, 'url' => sap_project_url( $project['id'], 'content', array( 'rev' => $id ) ) );
		},
	) );
	register_rest_route( $ns, '/revision/(?P<id>\d+)/(?P<do>step|approve|reject)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$rev = sap_revision( (int) $req['id'] );
			if ( ! $rev ) {
				return new WP_Error( 'sap_rev', 'نسخه پیدا نشد.', array( 'status' => 404 ) );
			}
			if ( 'step' === $req['do'] ) {
				$res = sap_content_step( $rev );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				return $res + array( 'ok' => true );
			}
			if ( 'reject' === $req['do'] ) {
				sap_revision_save( array( 'status' => 'rejected' ), $rev['id'] );
				return array( 'ok' => true, 'msg' => 'رد شد' );
			}
			$n = sap_content_approve( $rev, (array) $req->get_param( 'accepted' ) );
			return array( 'ok' => true, 'msg' => sprintf( '%s تغییر تأیید شد و در لاگ اقدامات ثبت شد.', sap_num( $n ) ) );
		},
	) );

	// Keyword map: save / confirm / delete one page's keywords.
	register_rest_route( $ns, '/keymap/(?P<project>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$pid = (int) $req['project'];
			if ( ! sap_project( $pid ) ) {
				return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
			}
			if ( $req->get_param( 'delete' ) ) {
				sap_keymap_delete( $pid, (int) $req->get_param( 'id' ) );
				return array( 'ok' => true, 'msg' => 'حذف شد' );
			}
			if ( $req->get_param( 'confirm_all' ) ) {
				global $wpdb;
				$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . sap_table( 'keymap' ) . " SET confirmed = 1, source = 'manual' WHERE project_id = %d AND confirmed = 0 AND primary_kw <> ''", $pid ) ); // phpcs:ignore
				sap_keymap( $pid, true );
				return array( 'ok' => true, 'msg' => sprintf( '%s صفحه تأیید شد', sap_num( (int) $n ) ) );
			}
			$url = esc_url_raw( (string) $req->get_param( 'url' ) );
			if ( '' === $url ) {
				return new WP_Error( 'sap_url', 'آدرس صفحه لازم است.', array( 'status' => 400 ) );
			}
			$id = sap_keymap_save( $pid, array(
				'url'        => $url,
				'title'      => (string) $req->get_param( 'title' ),
				'primary_kw' => (string) $req->get_param( 'primary_kw' ),
				'secondary'  => (string) $req->get_param( 'secondary' ),
				'intent'     => sanitize_key( (string) $req->get_param( 'intent' ) ),
				'page_type'  => sanitize_key( (string) $req->get_param( 'page_type' ) ),
				'source'     => 'manual',
				'confirmed'  => 1,
			), true );
			return array( 'ok' => true, 'id' => $id, 'msg' => 'ذخیره و تأیید شد؛ در تحلیل بعدی اعمال می‌شود' );
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
		'message' => sprintf( 'Crawl received by Sokan: %d URLs', count( $rows ) ),
	);
}

/**
 * Report export: everything SeoReport needs for «اقدامات انجام‌شده» and
 * «برنامه ماه آینده» of a period.
 *
 * Query: ?period=1405-08 (Jalali month) or ?from=Y-m-d&to=Y-m-d.
 */
function sap_rest_report( WP_REST_Request $req ) {
	return sap_month_report(
		sap_project( (int) $req['project'] ),
		(string) $req->get_param( 'period' ),
		sap_parse_date( (string) $req->get_param( 'from' ) ),
		sap_parse_date( (string) $req->get_param( 'to' ) )
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
