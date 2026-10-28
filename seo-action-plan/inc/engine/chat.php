<?php
/**
 * Chat with the project's data: the AI first picks which data to read (Search
 * Console, ranks, competitors, pages, alerts, tasks, activity, impact, SERP
 * analyses, inventory), Sokan reads it, then the AI answers from that data
 * only — with a table, a chart and concrete tasks that go into the plan with
 * one click.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * The data tools the model can call.
 */
function sap_chat_tools() {
	return array(
		'search_console' => 'کوئری‌ها یا صفحات ۲۸ روز اخیر سرچ کنسول؛ args: group_by=query|page، text (فیلتر متنی روی کوئری/آدرس)، sort=clicks|impressions|position|opportunity، limit',
		'pages_change'   => 'صفحاتی که کلیکشان نسبت به ۲۸ روز قبل بیشترین رشد یا افت را داشته؛ args: direction=up|down، limit',
		'rank_history'   => 'تاریخچه روزانه رتبه یک کلمه (ما و رقبا)؛ args: keyword',
		'rank_movers'    => 'بیشترین بالا و پایین رفتن رتبه کلمات؛ args: days=7|30',
		'page'           => 'همه چیز درباره یک صفحه: نوع، کلمه هدف، کلیک و نمایش، ایرادهای باز، کارها، تغییرات اخیر؛ args: url',
		'alerts'         => 'هشدارهای باز سایت',
		'tasks'          => 'کارهای اکشن پلن این ماه با وضعیت؛ args: status=open|done|all',
		'activity'       => 'لاگ اقدامات انجام‌شده؛ args: days',
		'impact'         => 'اثر اقدامات انجام‌شده (رشد/افت، بهترین و بدترین)',
		'competitors'    => 'مقایسه رودررو با رقبا روی کلمات هدف و رویدادهای اخیر رادار',
		'serp'           => 'تحلیل ذخیره‌شده ۱۰ نتیجه اول گوگل و شکاف محتوایی یک کلمه؛ args: keyword',
		'keymap'         => 'نقشه کلمات: کدام صفحه برای کدام کلمه است و تداخل‌ها؛ args: text',
		'inventory'      => 'آمار محتوای سایت: تعداد صفحات هر نوع، صفحات کم‌محتوا، تصاویر بدون alt، noindex',
	);
}

/**
 * Run one tool.
 */
function sap_chat_run_tool( $project, $tool, $a ) {
	$pid   = $project['id'];
	$limit = max( 3, min( 40, (int) ( $a['limit'] ?? 15 ) ) );
	$text  = sap_norm_text( (string) ( $a['text'] ?? '' ) );
	switch ( $tool ) {
		case 'search_console':
			$rows = (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() );
			if ( ! $rows ) {
				return array( 'error' => 'داده سرچ کنسول هنوز گرفته نشده.' );
			}
			$by  = 'page' === ( $a['group_by'] ?? '' ) ? 'page' : 'query';
			$agg = array();
			foreach ( $rows as $r ) {
				if ( '' !== $text && false === mb_strpos( sap_norm_text( $r['query'] . ' ' . rawurldecode( $r['page'] ) ), $text ) ) {
					continue;
				}
				$k = 'page' === $by ? sap_norm_url( $r['page'] ) : $r['query'];
				if ( ! isset( $agg[ $k ] ) ) {
					$agg[ $k ] = array( 'key' => 'page' === $by ? $r['page'] : $r['query'], 'clicks' => 0, 'impressions' => 0, 'pw' => 0, 'top' => '' );
				}
				$agg[ $k ]['clicks']      += $r['clicks'];
				$agg[ $k ]['impressions'] += $r['impressions'];
				$agg[ $k ]['pw']          += $r['position'] * $r['impressions'];
				$agg[ $k ]['top']          = $agg[ $k ]['top'] ?: ( 'page' === $by ? $r['query'] : sap_short_url( $r['page'], 60 ) );
			}
			foreach ( $agg as &$x ) {
				$x['position']    = $x['impressions'] ? round( $x['pw'] / $x['impressions'], 1 ) : null;
				$x['ctr']         = $x['impressions'] ? round( $x['clicks'] / $x['impressions'] * 100, 1 ) : 0;
				$x['opportunity'] = (int) round( $x['impressions'] * ( sap_ctr_at( max( 1, ( $x['position'] ?: 50 ) - 3 ) ) - sap_ctr_at( $x['position'] ) ) );
				unset( $x['pw'] );
			}
			unset( $x );
			$sort = in_array( $a['sort'] ?? '', array( 'impressions', 'position', 'opportunity' ), true ) ? $a['sort'] : 'clicks';
			usort( $agg, function ( $p, $q ) use ( $sort ) {
				return 'position' === $sort ? ( $p['position'] ?? 999 ) <=> ( $q['position'] ?? 999 ) : $q[ $sort ] <=> $p[ $sort ];
			} );
			return array( 'window' => '۲۸ روز', 'columns' => array( $by, 'clicks', 'impressions', 'ctr%', 'position', 'opportunity_clicks', 'page' === $by ? 'top_query' : 'page' ), 'rows' => array_map( function ( $x ) use ( $by ) {
				return array( 'page' === $by ? sap_short_url( $x['key'], 70 ) : $x['key'], $x['clicks'], $x['impressions'], $x['ctr'], $x['position'], $x['opportunity'], $x['top'] );
			}, array_slice( $agg, 0, $limit ) ) );
		case 'pages_change':
			$d    = (array) sap_dataset_payload( $pid, 'gsc_pages' );
			$prev = array();
			foreach ( (array) ( $d['prev'] ?? array() ) as $r ) {
				$prev[ sap_norm_url( $r['page'] ) ] = $r;
			}
			$rows = array();
			foreach ( (array) ( $d['cur'] ?? array() ) as $r ) {
				$p      = $prev[ sap_norm_url( $r['page'] ) ] ?? array( 'clicks' => 0, 'impressions' => 0 );
				$rows[] = array( sap_short_url( $r['page'], 70 ), (int) $r['clicks'], (int) $p['clicks'], (int) $r['clicks'] - (int) $p['clicks'], (int) $r['impressions'] );
			}
			$up = 'down' !== ( $a['direction'] ?? 'up' );
			usort( $rows, function ( $p, $q ) use ( $up ) {
				return $up ? $q[3] <=> $p[3] : $p[3] <=> $q[3];
			} );
			return array( 'columns' => array( 'page', 'clicks_28d', 'clicks_prev_28d', 'change', 'impressions' ), 'rows' => array_slice( $rows, 0, $limit ) );
		case 'rank_history':
			$kw   = sap_norm_text( (string) ( $a['keyword'] ?? '' ) );
			$us   = sap_rank_history( $pid, 90 )[ $kw ] ?? array();
			$out  = array( 'keyword' => $kw, 'us' => array() );
			foreach ( $us as $day => $r ) {
				$out['us'][ $day ] = $r['pos'];
			}
			$out['us']          = array_slice( $out['us'], -45, null, true );
			$out['competitors'] = sap_rank_competitors( $pid )[ $kw ] ?? array();
			return $out['us'] ? $out : array( 'error' => 'برای این کلمه تاریخچه رتبه ثبت نشده.' );
		case 'rank_movers':
			$m = sap_rank_movers( $pid, 30 === (int) ( $a['days'] ?? 7 ) ? 30 : 7 );
			if ( ! $m ) {
				return array( 'error' => 'تاریخچه رتبه کافی نیست.' );
			}
			$rows = array_values( array_filter( $m['rows'], function ( $r ) {
				return null !== $r['now'] || null !== $r['then'];
			} ) );
			usort( $rows, function ( $p, $q ) {
				return abs( ( $q['then'] ?? 101 ) - ( $q['now'] ?? 101 ) ) <=> abs( ( $p['then'] ?? 101 ) - ( $p['now'] ?? 101 ) );
			} );
			return array( 'columns' => array( 'keyword', 'rank_before', 'rank_now', 'url_changed' ), 'rows' => array_map( function ( $r ) {
				return array( $r['keyword'], $r['then'], $r['now'], $r['url_changed'] ? 'yes' : '' );
			}, array_slice( $rows, 0, $limit ) ) );
		case 'page':
			$url  = sap_full_url( $project, (string) ( $a['url'] ?? '' ) );
			$k    = sap_norm_url( $url );
			$it   = sap_site_item( $pid, $url );
			$map  = null;
			foreach ( sap_keymap( $pid ) as $m ) {
				if ( sap_norm_url( $m['url'] ) === $k ) {
					$map = array( 'primary' => $m['primary_kw'], 'type' => $m['page_type'] ?? '', 'secondary' => array_slice( (array) ( $m['secondary'] ?? array() ), 0, 6 ) );
				}
			}
			$gsc   = sap_roi_gsc( $pid, $url );
			$finds = array();
			foreach ( sap_findings_active( $pid, 300 ) as $f ) {
				if ( sap_norm_url( $f['url'] ) === $k ) {
					$finds[] = $f['title'];
				}
			}
			$tasks = array();
			foreach ( sap_tasks( array( 'project_id' => $pid ) ) as $t ) {
				if ( sap_norm_url( (string) $t['url'] ) === $k ) {
					$tasks[] = array( $t['title'], $t['status'], substr( (string) $t['done_at'], 0, 10 ) );
				}
			}
			return array( 'url' => $url, 'wp' => $it ? array( 'title' => $it['title'], 'type' => $it['ctype'], 'words' => $it['words'], 'images' => $it['images'], 'no_alt' => $it['no_alt'], 'noindex' => $it['noindex'], 'modified' => $it['modified'], 'seo_title' => $it['seo']['seo_title'], 'meta' => $it['seo']['meta_description'] ) : null, 'keymap' => $map, 'search_console_28d' => $gsc, 'open_findings' => array_slice( $finds, 0, 10 ), 'tasks' => array_slice( $tasks, 0, 10 ) );
		case 'alerts':
			return array( 'open' => array_map( function ( $x ) {
				return array( $x['severity'], $x['title'], mb_substr( (string) $x['detail'], 0, 200 ), substr( $x['last_seen'] ?? $x['created_at'] ?? '', 0, 10 ) );
			}, array_slice( sap_alerts( $pid, array( 'status' => 'open' ) ), 0, 20 ) ) );
		case 'tasks':
			$st   = $a['status'] ?? 'open';
			$args = array( 'project_id' => $pid, 'period' => sap_period_current() );
			if ( 'open' === $st ) {
				$args['status'] = array( 'next', 'doing', 'waiting', 'inbox', 'someday' );
			} elseif ( 'done' === $st ) {
				$args['status'] = array( 'done' );
			}
			return array( 'month' => sap_period_label( sap_period_current() ), 'tasks' => array_map( function ( $t ) {
				return array( $t['title'], $t['status'], sap_context_label( $t['context'] ), (float) $t['effort'], $t['assignee'] ? sap_user_name( $t['assignee'] ) : '', sap_short_url( (string) $t['url'], 50 ) );
			}, array_slice( sap_tasks( $args ), 0, 40 ) ) );
		case 'activity':
			$days = max( 1, min( 120, (int) ( $a['days'] ?? 30 ) ) );
			return array( 'days' => $days, 'items' => array_map( function ( $r ) {
				return array( substr( $r['happened_at'], 0, 10 ), $r['title'], sap_short_url( $r['url'], 50 ) );
			}, array_slice( sap_activity_list( $pid, gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ), 0, 40 ) ) );
		case 'impact':
			$s = sap_impact_summary( $pid );
			return array( 'total' => $s['total'], 'growth' => $s['growth'], 'decline' => $s['decline'], 'neutral' => $s['neutral'], 'wait' => $s['wait'], 'by_type' => array_map( function ( $t ) {
				return array( $t['label'], $t['n'], $t['avg'] );
			}, array_values( $s['by_type'] ) ), 'best' => array_map( function ( $i ) {
				return array( $i['title'], (int) $i['net_change'] );
			}, $s['best'] ), 'worst' => array_map( function ( $i ) {
				return array( $i['title'], (int) $i['net_change'] );
			}, $s['worst'] ), 'lessons' => $s['lessons'] );
		case 'competitors':
			$h = sap_radar_h2h( $project );
			return array( 'last_day' => $h['last'], 'our_share_of_voice' => $h['us_sov'] ?? null, 'rivals' => $h['summary'], 'head_to_head' => array_map( function ( $r ) {
				return array( $r['keyword'], $r['us'], $r['comps'] );
			}, array_slice( $h['rows'], 0, 25 ) ), 'radar_events' => array_map( function ( $e ) {
				return array( substr( $e['at'], 0, 10 ), $e['type'], $e['detail'] );
			}, sap_radar_events( $pid, 12 ) ) );
		case 'serp':
			$f = sap_serp_brief_facts( $pid, (string) ( $a['keyword'] ?? '' ) );
			return $f ? $f : array( 'error' => 'برای این کلمه تحلیل نتایج گوگل ذخیره نشده (از تب تحلیل نتایج گوگل بگیرید).' );
		case 'keymap':
			$rows = array();
			foreach ( sap_keymap( $pid ) as $m ) {
				if ( '' === $text || false !== mb_strpos( sap_norm_text( $m['primary_kw'] . ' ' . rawurldecode( $m['url'] ) ), $text ) ) {
					$rows[] = array( $m['primary_kw'], sap_short_url( $m['url'], 60 ), $m['page_type'] ?? '' );
				}
			}
			return array( 'columns' => array( 'keyword', 'page', 'type' ), 'rows' => array_slice( $rows, 0, $limit ) );
		case 'inventory':
			$c = array( 'by_type' => array(), 'thin_under_300_words' => 0, 'images_without_alt' => 0, 'noindex' => 0, 'redirected' => 0 );
			foreach ( sap_site_inventory( $pid ) as $it ) {
				$c['by_type'][ $it['ctype'] ] = ( $c['by_type'][ $it['ctype'] ] ?? 0 ) + 1;
				$c['thin_under_300_words']   += in_array( $it['ctype'], array( 'post', 'page', 'product' ), true ) && $it['words'] < 300 ? 1 : 0;
				$c['images_without_alt']     += $it['no_alt'];
				$c['noindex']                += $it['noindex'] ? 1 : 0;
				$c['redirected']             += $it['redirect'] ? 1 : 0;
			}
			return $c;
	}
	return array( 'error' => 'ابزار نامعتبر' );
}

/**
 * Conversation of a user in a project.
 */
function sap_chat_history( $project_id, $user_id = 0 ) {
	$h = get_user_meta( $user_id ?: get_current_user_id(), 'sap_chat_' . (int) $project_id, true );
	return is_array( $h ) ? $h : array();
}

function sap_chat_save( $project_id, $history ) {
	update_user_meta( get_current_user_id(), 'sap_chat_' . (int) $project_id, array_slice( $history, -40 ) );
}

/**
 * Answer a question: plan → read data → answer.
 *
 * @return array|WP_Error assistant message
 */
function sap_chat_ask( $project, $question ) {
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید هوش مصنوعی در تنظیمات وارد نشده است.' );
	}
	$question = trim( mb_substr( wp_strip_all_tags( (string) $question ), 0, 1500 ) );
	if ( '' === $question ) {
		return new WP_Error( 'sap_chat', 'سوال را بنویسید.' );
	}
	$GLOBALS['sap_ai_project'] = $project;
	$history = sap_chat_history( $project['id'] );
	$recent  = array();
	foreach ( array_slice( $history, -6 ) as $m ) {
		$recent[] = ( 'user' === $m['role'] ? 'کاربر: ' : 'سکان: ' ) . mb_substr( (string) $m['text'], 0, 500 );
	}
	$str   = array( 'type' => 'string' );
	$tools = sap_chat_tools();
	// 1) Which data to read.
	$plan = sap_ai_request(
		sap_ai_system_prompt(),
		implode( "\n", array(
			'کاربر درباره داده‌های پروژه «' . $project['name'] . '» (' . sap_project_domain( $project ) . ') سوال دارد. برای جواب دقیق، کدام ابزارهای داده را (حداکثر ۴) با چه ورودی صدا بزنیم؟ اگر سوال بدون داده جواب دارد (مثلاً احوالپرسی یا سوال عمومی سئو)، calls خالی.',
			'ابزارها:',
			implode( "\n", array_map( function ( $k, $v ) {
				return '- ' . $k . ': ' . $v;
			}, array_keys( $tools ), $tools ) ),
			$recent ? "گفتگوی اخیر:\n" . implode( "\n", $recent ) : '',
			'سوال: ' . $question,
		) ),
		sap_ai_obj( array( 'calls' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
			'tool' => array( 'type' => 'string', 'enum' => array_keys( $tools ) ),
			'args' => sap_ai_obj( array( 'group_by' => $str, 'text' => $str, 'sort' => $str, 'limit' => array( 'type' => 'integer' ), 'direction' => $str, 'keyword' => $str, 'url' => $str, 'days' => array( 'type' => 'integer' ), 'status' => $str ) ),
		) ) ) ) ),
		array( 'purpose' => 'assist', 'max_tokens' => 700, 'effort' => 'low' )
	);
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	$data = array();
	$used = array();
	foreach ( array_slice( (array) ( $plan['calls'] ?? array() ), 0, 4 ) as $c ) {
		$tool = (string) ( $c['tool'] ?? '' );
		if ( ! isset( $tools[ $tool ] ) ) {
			continue;
		}
		$res    = sap_chat_run_tool( $project, $tool, (array) ( $c['args'] ?? array() ) );
		$json   = wp_json_encode( $res, JSON_UNESCAPED_UNICODE );
		$data[] = array( 'tool' => $tool, 'args' => array_filter( (array) ( $c['args'] ?? array() ) ), 'result' => mb_strlen( $json ) > 7000 ? array( 'truncated_json' => mb_substr( $json, 0, 7000 ) ) : $res );
		$used[] = $tool;
	}
	// 2) The answer from that data.
	$answer = sap_ai_request(
		sap_ai_system_prompt(),
		implode( "\n", array(
			'به سوال کاربر فقط با تکیه بر داده‌های خوانده‌شده جواب بده؛ عدد و آدرس و کلمه را دقیق از داده نقل کن و اگر داده کافی نیست صریح بگو چه چیزی کم است.',
			'answer: جواب کوتاه، حرفه‌ای و کاربردی (حداکثر ۱۸۰ کلمه؛ می‌توانی خط‌های «- » برای فهرست و **پررنگ** استفاده کنی).',
			'table: اگر جدول کمک می‌کند (حداکثر ۱۲ ردیف)، وگرنه columns خالی. chart: اگر روند یا مقایسه عددی هست (line برای زمان، bar برای مقایسه؛ حداکثر ۳ سری و ۴۵ نقطه)، وگرنه labels خالی.',
			'actions: ۰ تا ۴ کار مشخص و قابل اجرا بر اساس همین داده (کدام صفحه، کدام کلمه، دقیقاً چه کاری). followups: ۲ تا ۳ سوال بعدی مفید.',
			$recent ? "گفتگوی اخیر:\n" . implode( "\n", $recent ) : '',
			'سوال: ' . $question,
			'داده‌های خوانده‌شده: ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
		) ),
		sap_ai_obj( array(
			'answer'    => $str,
			'table'     => sap_ai_obj( array( 'title' => $str, 'columns' => array( 'type' => 'array', 'items' => $str ), 'rows' => array( 'type' => 'array', 'items' => array( 'type' => 'array', 'items' => $str ) ) ) ),
			'chart'     => sap_ai_obj( array( 'title' => $str, 'type' => array( 'type' => 'string', 'enum' => array( 'line', 'bar' ) ), 'labels' => array( 'type' => 'array', 'items' => $str ), 'series' => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'name' => $str, 'values' => array( 'type' => 'array', 'items' => array( 'type' => 'number' ) ) ) ) ), 'reverse' => array( 'type' => 'boolean', 'description' => 'true برای رتبه (عدد کمتر بهتر)' ) ) ),
			'actions'   => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'title' => $str, 'why' => $str, 'url' => $str, 'keyword' => $str, 'context' => array( 'type' => 'string', 'enum' => array_keys( sap_contexts() ) ), 'effort' => array( 'type' => 'number' ) ) ) ),
			'followups' => array( 'type' => 'array', 'items' => $str ),
		) ),
		array( 'purpose' => 'analysis', 'max_tokens' => 2500, 'effort' => 'low' )
	);
	if ( is_wp_error( $answer ) ) {
		return $answer;
	}
	unset( $answer['_partial'] );
	$msg = array(
		'role'      => 'assistant',
		'text'      => (string) ( $answer['answer'] ?? '' ),
		'table'     => ! empty( $answer['table']['columns'] ) ? $answer['table'] : null,
		'chart'     => ! empty( $answer['chart']['labels'] ) && ! empty( $answer['chart']['series'] ) ? $answer['chart'] : null,
		'actions'   => array_slice( (array) ( $answer['actions'] ?? array() ), 0, 4 ),
		'followups' => array_slice( (array) ( $answer['followups'] ?? array() ), 0, 3 ),
		'tools'     => $used,
		'at'        => sap_now(),
	);
	$history[] = array( 'role' => 'user', 'text' => $question, 'at' => sap_now() );
	$history[] = $msg;
	sap_chat_save( $project['id'], $history );
	return $msg;
}

/**
 * Persian labels of the tools (shown under answers: «از کدام داده»).
 */
function sap_chat_tool_labels() {
	return array( 'search_console' => 'سرچ کنسول', 'pages_change' => 'تغییر صفحات', 'rank_history' => 'تاریخچه رتبه', 'rank_movers' => 'جابه‌جایی رتبه‌ها', 'page' => 'اطلاعات صفحه', 'alerts' => 'هشدارها', 'tasks' => 'اکشن پلن', 'activity' => 'لاگ اقدامات', 'impact' => 'ارزیابی اثر', 'competitors' => 'رقبا', 'serp' => 'تحلیل نتایج گوگل', 'keymap' => 'نقشه کلمات', 'inventory' => 'محتوای سایت' );
}
