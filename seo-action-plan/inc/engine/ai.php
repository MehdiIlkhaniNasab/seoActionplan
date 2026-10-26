<?php
/**
 * AI layer: turns the project's data and rule-based findings into a diagnosis and
 * a concrete monthly action plan, and writes ready-to-use deliverables for tasks.
 *
 * The rules (detectors) stay the source of facts; the model reads a compact brief
 * of those facts and decides what matters, in what order, and exactly what to do.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persian system prompt shared by all AI calls.
 */
function sap_ai_system_prompt() {
	return implode( "\n", array(
		'تو یک مدیر سئوی ارشد و عمل‌گرا برای سایت‌های فارسی (اغلب وردپرس/ووکامرس با افزونه Rank Math) هستی و به یک متخصص سئو کمک می‌کنی کارش را سریع‌تر و دقیق‌تر انجام دهد.',
		'فقط بر اساس داده‌های ارائه‌شده نظر بده و عدد و آدرس و کلمه دقیق را از داده نقل کن؛ چیزی از خودت نساز. اگر داده‌ای نیست، صریح بگو.',
		'کارهای کلی و تکراری مثل «بررسی سایت‌مپ» یا «بهبود سئو» ممنوع است؛ هر کار باید مشخص کند کدام صفحه، کدام کلمه، چرا (با استناد به داده) و دقیقاً چه کاری.',
		'اولویت با Focus پروژه و اثر واقعی روی کلیک و فروش است؛ ایرادهای حیاتی کل سایت (خطای سرور، 404 صفحات مهم، noindex اشتباه) را هم نادیده نگیر.',
		'ریچ‌ریزالت FAQ از ۲۰۲۳ برای اغلب سایت‌ها نمایش داده نمی‌شود؛ FAQ را برای پوشش کوئری‌ها پیشنهاد بده، نه برای ستاره و ریچ‌ریزالت.',
		'همه متن‌ها را به فارسی روان و حرفه‌ای و کوتاه بنویس.',
	) );
}

/**
 * Compact facts about a project for the model.
 */
function sap_ai_brief( $project ) {
	$pid    = $project['id'];
	$ctx    = sap_analysis_context( $project );
	$period = sap_period_current();
	list( $jy, $jm, $jd ) = sap_jnow();
	$brief  = array(
		'project'      => array(
			'name'             => $project['name'],
			'domain'           => sap_project_domain( $project ),
			'brand_terms'      => $project['brand_terms'],
			'competitors'      => $project['competitors'],
			'capacity_hours'   => $project['capacity_hours'],
			'month'            => sap_period_label( $period ),
			'days_left'        => sap_jmonth_days( $jy, $jm ) - $jd,
		),
		'focuses'      => array(),
		'search_console' => null,
		'technical'    => null,
		'content_sheet' => null,
		'clusters'     => null,
		'rank_tracker' => null,
		'findings'     => array(),
		'this_month'   => array(),
	);
	foreach ( sap_focuses( $pid, true ) as $f ) {
		$k                  = sap_focus_kpis( $f, $pid );
		$brief['focuses'][] = array(
			'id'        => $f['id'],
			'name'      => $f['name'],
			'goal'      => $f['goal'],
			'metric'    => $f['kpi_metric'],
			'baseline'  => $f['kpi_baseline'],
			'target'    => $f['kpi_target'],
			'current'   => $k ? $k[ $f['kpi_metric'] ] : null,
			'keywords'  => $k ? $k['keywords'] : 0,
			'top10'     => $k ? $k['top10'] : 0,
			'url_patterns' => $f['url_patterns'],
		);
	}
	if ( ! empty( $ctx['gsc_qp']['rows'] ) ) {
		$rows    = sap_sort( $ctx['gsc_qp']['rows'], 'clicks' );
		$queries = array();
		foreach ( array_slice( $rows, 0, 30 ) as $r ) {
			$queries[] = array( $r['query'], sap_short_url( $r['page'], 70 ), $r['clicks'], $r['impressions'], round( $r['ctr'] * 100, 1 ), round( $r['position'], 1 ) );
		}
		$cur  = (array) ( $ctx['gsc_pages']['cur'] ?? array() );
		$prev = array();
		foreach ( (array) ( $ctx['gsc_pages']['prev'] ?? array() ) as $r ) {
			$prev[ sap_norm_url( $r['page'] ) ] = $r;
		}
		$pages = array();
		foreach ( array_slice( sap_sort( $cur, 'clicks' ), 0, 20 ) as $r ) {
			$p       = $prev[ sap_norm_url( $r['page'] ) ] ?? array( 'clicks' => 0 );
			$pages[] = array( sap_short_url( $r['page'], 70 ), $r['clicks'], $p['clicks'], $r['impressions'] );
		}
		$brief['search_console'] = array(
			'window'           => $ctx['gsc_qp_meta']['range'] ?? array(),
			'clicks_28d'       => array_sum( array_column( $cur, 'clicks' ) ),
			'clicks_prev_28d'  => array_sum( array_column( (array) ( $ctx['gsc_pages']['prev'] ?? array() ), 'clicks' ) ),
			'impressions_28d'  => array_sum( array_column( $cur, 'impressions' ) ),
			'top_queries'      => array( 'columns' => array( 'query', 'page', 'clicks', 'impressions', 'ctr%', 'position' ), 'rows' => $queries ),
			'top_pages'        => array( 'columns' => array( 'page', 'clicks', 'clicks_prev', 'impressions' ), 'rows' => $pages ),
		);
	}
	if ( ! empty( $ctx['crawl']['rows'] ) ) {
		$m                  = $ctx['crawl_meta'] ?? array();
		$brief['technical'] = array(
			'crawled_urls' => $m['urls'] ?? count( $ctx['crawl']['rows'] ),
			'status_codes' => $m['codes'] ?? array(),
			'crawl_date'   => substr( (string) ( sap_dataset( $pid, 'crawl' )['fetched_at'] ?? '' ), 0, 10 ),
		);
	}
	if ( ! empty( $ctx['sheet_calendar']['rows'] ) ) {
		$by = array();
		foreach ( $ctx['sheet_calendar']['rows'] as $r ) {
			$by[ $r['status_key'] ?: 'none' ] = ( $by[ $r['status_key'] ?: 'none' ] ?? 0 ) + 1;
		}
		$brief['content_sheet'] = array( 'rows' => count( $ctx['sheet_calendar']['rows'] ), 'by_status' => $by );
	}
	if ( ! empty( $ctx['sheet_clusters']['rows'] ) ) {
		$cov = array();
		foreach ( sap_cluster_coverage( $ctx ) as $c ) {
			$cl                              = $c['row']['cluster'];
			$cov[ $cl ][ $c['status'] ]      = ( $cov[ $cl ][ $c['status'] ] ?? 0 ) + 1;
		}
		$brief['clusters'] = $cov;
	}
	if ( ! empty( $ctx['ss_rank']['keywords'] ) ) {
		$kw = array();
		foreach ( array_slice( sap_sort( $ctx['ss_rank']['keywords'], 'volume' ), 0, 25 ) as $k ) {
			$kw[] = array( $k['keyword'], $k['start_rank'], $k['end_rank'], $k['volume'] );
		}
		$brief['rank_tracker'] = array( 'columns' => array( 'keyword', 'rank_30d_ago', 'rank_now', 'volume' ), 'rows' => $kw );
	}
	$fnames = wp_list_pluck( sap_focuses( $pid ), 'name', 'id' );
	foreach ( sap_findings_active( $pid, 45 ) as $f ) {
		$items = array();
		foreach ( array_slice( (array) ( $f['evidence']['items'] ?? array() ), 0, 4 ) as $it ) {
			$items[] = implode( ' | ', array_map( 'strval', array_filter( (array) $it, 'is_scalar' ) ) );
		}
		$brief['findings'][] = array(
			'id'       => $f['id'],
			'rule'     => $f['detector'],
			'area'     => $f['context'],
			'title'    => $f['title'],
			'score'    => $f['score'],
			'focus'    => $fnames[ $f['focus_id'] ] ?? '',
			'url'      => $f['url'] ? sap_short_url( $f['url'], 80 ) : '',
			'keyword'  => $f['keyword'],
			'evidence' => $items,
		);
	}
	foreach ( sap_tasks( array( 'project_id' => $pid, 'period' => $period ) ) as $t ) {
		$brief['this_month'][] = array( 'title' => $t['title'], 'status' => $t['status'] );
	}
	return $brief;
}

/**
 * JSON schema of the analysis answer.
 */
function sap_ai_analysis_schema() {
	$str  = array( 'type' => 'string' );
	$obj  = function ( $props ) {
		return array( 'type' => 'object', 'properties' => $props, 'required' => array_keys( $props ), 'additionalProperties' => false );
	};
	return $obj( array(
		'summary'        => array( 'type' => 'string', 'description' => 'تشخیص وضعیت پروژه در ۳ تا ۵ جمله با عدد' ),
		'health'         => array( 'type' => 'array', 'items' => $obj( array(
			'area'  => array( 'type' => 'string', 'enum' => array( 'technical', 'content', 'onpage', 'authority', 'focus' ) ),
			'score' => array( 'type' => 'integer', 'description' => '۰ تا ۱۰۰' ),
			'note'  => $str,
		) ) ),
		'focus_insights' => array( 'type' => 'array', 'items' => $obj( array( 'focus' => $str, 'insight' => $str ) ) ),
		'wins'           => array( 'type' => 'array', 'items' => $str ),
		'risks'          => array( 'type' => 'array', 'items' => $str ),
		'plan'           => array( 'type' => 'array', 'items' => $obj( array(
			'title'        => array( 'type' => 'string', 'description' => 'عنوان کار مشخص با نام صفحه/کلمه' ),
			'why'          => array( 'type' => 'string', 'description' => 'دلیل با استناد به داده' ),
			'steps'        => array( 'type' => 'array', 'items' => $str ),
			'deliverable'  => array( 'type' => 'string', 'description' => 'خروجی آماده در صورت امکان (تایتل/متای پیشنهادی، سوالات FAQ، انکرها) یا رشته خالی' ),
			'area'         => array( 'type' => 'string', 'enum' => array_keys( sap_contexts() ) ),
			'impact'       => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
			'effort_hours' => array( 'type' => 'number' ),
			'week'         => array( 'type' => 'integer', 'description' => 'هفته پیشنهادی ۱ تا ۴' ),
			'focus'        => array( 'type' => 'string', 'description' => 'نام Focus یا رشته خالی' ),
			'url'          => array( 'type' => 'string', 'description' => 'آدرس کامل صفحه یا رشته خالی' ),
			'keyword'      => $str,
			'finding_ids'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
		) ) ),
	) );
}

/**
 * Fill in anything a model left out, so the views never break on a partial answer.
 */
function sap_ai_normalize_analysis( $res ) {
	$res = wp_parse_args( (array) $res, array( 'summary' => '', 'health' => array(), 'focus_insights' => array(), 'wins' => array(), 'risks' => array(), 'plan' => array() ) );
	foreach ( array( 'health', 'focus_insights', 'wins', 'risks', 'plan' ) as $k ) {
		$res[ $k ] = is_array( $res[ $k ] ) ? array_values( $res[ $k ] ) : array();
	}
	$res['summary'] = (string) $res['summary'];
	$res['wins']    = array_values( array_filter( array_map( 'strval', array_filter( $res['wins'], 'is_scalar' ) ) ) );
	$res['risks']   = array_values( array_filter( array_map( 'strval', array_filter( $res['risks'], 'is_scalar' ) ) ) );
	$res['health']  = array_values( array_filter( array_map( function ( $h ) {
		return is_array( $h ) ? wp_parse_args( $h, array( 'area' => '', 'score' => 0, 'note' => '' ) ) : null;
	}, $res['health'] ) ) );
	$res['focus_insights'] = array_values( array_filter( array_map( function ( $f ) {
		return is_array( $f ) ? wp_parse_args( $f, array( 'focus' => '', 'insight' => '' ) ) : null;
	}, $res['focus_insights'] ) ) );
	$areas = sap_contexts();
	$plan  = array();
	foreach ( $res['plan'] as $item ) {
		if ( ! is_array( $item ) || '' === trim( (string) ( $item['title'] ?? '' ) ) ) {
			continue;
		}
		$item           = wp_parse_args( $item, array( 'why' => '', 'steps' => array(), 'deliverable' => '', 'area' => 'content_update', 'impact' => 'medium', 'effort_hours' => 1, 'week' => 1, 'focus' => '', 'url' => '', 'keyword' => '', 'finding_ids' => array() ) );
		$item['steps']  = array_values( array_map( 'strval', array_filter( (array) $item['steps'], 'is_scalar' ) ) );
		$item['area']   = array_key_exists( $item['area'], $areas ) ? $item['area'] : 'content_update';
		$item['impact'] = in_array( $item['impact'], array( 'high', 'medium', 'low' ), true ) ? $item['impact'] : 'medium';
		foreach ( array( 'title', 'why', 'deliverable', 'focus', 'url', 'keyword' ) as $k ) {
			$item[ $k ] = is_scalar( $item[ $k ] ) ? (string) $item[ $k ] : '';
		}
		$plan[] = $item;
	}
	$res['plan'] = $plan;
	return $res;
}

/**
 * Full AI analysis (kept for callers of the single-step API): runs the final stage.
 */
function sap_ai_analyze( $project, $force = false ) {
	return sap_ai_stage_run( $project, 'plan', $force );
}

/**
 * Plain-text task notes from an AI plan item.
 */
function sap_ai_item_notes( $item ) {
	$lines = array( 'چرا: ' . $item['why'] );
	if ( $item['steps'] ) {
		$lines[] = '';
		$lines[] = 'مراحل:';
		foreach ( $item['steps'] as $i => $s ) {
			$lines[] = sap_fa( $i + 1 ) . '. ' . $s;
		}
	}
	return implode( "\n", $lines );
}

/**
 * Add the AI plan items to a period as tasks (skipping ones already added).
 *
 * @return array{added:int, hours:float}
 */
function sap_ai_plan_to_tasks( $project, $period, $capacity = null ) {
	$ai = sap_dataset_payload( $project['id'], 'ai_analysis' );
	if ( empty( $ai['plan'] ) ) {
		return array( 'added' => 0, 'hours' => 0 );
	}
	$have = array();
	foreach ( sap_tasks( array( 'project_id' => $project['id'], 'period' => $period ) ) as $t ) {
		if ( $t['ai_ref'] ) {
			$have[ $t['ai_ref'] ] = true;
		}
	}
	$focus_ids = array();
	foreach ( sap_focuses( $project['id'] ) as $f ) {
		$focus_ids[ sap_kw_key( $f['name'] ) ] = $f['id'];
	}
	list( $start ) = sap_period_range( $period );
	$capacity      = null === $capacity ? $project['capacity_hours'] : (float) $capacity;
	$used          = sap_plan_used_hours( $project['id'], $period );
	$added         = 0;
	$weight        = array( 'high' => 80, 'medium' => 55, 'low' => 30 );
	foreach ( $ai['plan'] as $i => $item ) {
		if ( isset( $have[ $item['ref'] ] ) ) {
			continue;
		}
		$hours = max( 0.25, (float) $item['effort_hours'] );
		if ( $used + $hours > $capacity * 1.05 ) {
			continue;
		}
		$week = min( 4, max( 1, (int) $item['week'] ) );
		$tid  = sap_task_save( array(
			'project_id' => $project['id'],
			'focus_id'   => $focus_ids[ sap_kw_key( $item['focus'] ) ] ?? 0,
			'finding_id' => $item['finding_ids'][0] ?? 0,
			'title'      => $item['title'],
			'notes'      => sap_ai_item_notes( $item ),
			'ai_output'  => $item['deliverable'],
			'context'    => $item['area'],
			'status'     => 'next',
			'period'     => $period,
			'effort'     => $hours,
			'score'      => ( $weight[ $item['impact'] ] ?? 50 ) - $i * 0.5,
			'url'        => $item['url'],
			'keyword'    => $item['keyword'],
			'due_date'   => gmdate( 'Y-m-d', strtotime( $start ) + ( 7 * $week - 1 ) * DAY_IN_SECONDS ),
			'source'     => 'ai',
			'ai_ref'     => $item['ref'],
		) );
		foreach ( $item['finding_ids'] as $fid ) {
			sap_finding_set_status( $fid, 'planned', $tid );
		}
		$used += $hours;
		++$added;
	}
	return array( 'added' => $added, 'hours' => $used );
}

/**
 * Write a ready-to-use deliverable for one task (titles/metas, FAQ, brief, anchors…).
 *
 * @return string|WP_Error The text (also saved on the task).
 */
function sap_ai_task_assist( $task ) {
	$project = sap_project( $task['project_id'] );
	$finding = $task['finding_id'] ? sap_finding( $task['finding_id'] ) : null;
	$page    = null;
	$related = array();
	if ( $task['url'] ) {
		$k     = sap_norm_url( $task['url'] );
		$read  = (array) ( sap_dataset_payload( $project['id'], 'pages' )['pages'] ?? array() );
		if ( isset( $read[ $k ] ) ) {
			$p    = $read[ $k ];
			$page = array( 'title' => $p['title'], 'meta' => $p['meta'], 'h1' => $p['h1'], 'h2' => array_slice( $p['h2'], 0, 12 ), 'schema' => $p['schema'], 'words' => $p['words'], 'text' => mb_substr( $p['text'], 0, 1800 ) );
		} else {
			$crawl = sap_dataset_payload( $project['id'], 'crawl' );
			foreach ( (array) ( $crawl['rows'] ?? array() ) as $r ) {
				if ( sap_norm_url( $r['url'] ) === $k ) {
					$page = array( 'title' => $r['title'], 'meta' => $r['meta'], 'h1' => $r['h1'], 'words' => $r['words'] );
				}
			}
		}
		// What the staged analysis already concluded about this page.
		foreach ( (array) ( sap_dataset_payload( $project['id'], 'ai_content' )['pages'] ?? array() ) as $cp ) {
			if ( sap_norm_url( $cp['url'] ) === sap_norm_url( sap_short_url( $task['url'], 200 ) ) || false !== strpos( $task['url'], trim( $cp['url'], '/' ) ) ) {
				$related['content_analysis'] = $cp;
			}
		}
		foreach ( (array) ( sap_dataset_payload( $project['id'], 'ai_links' )['links'] ?? array() ) as $l ) {
			$path = trim( sap_short_url( $task['url'], 200 ), '/' );
			if ( '' !== $path && ( false !== strpos( $l['target_url'], $path ) || false !== strpos( $l['source_url'], $path ) ) ) {
				$related['internal_links'][] = $l;
			}
		}
	}
	$queries = array();
	if ( $task['url'] ) {
		$qp = sap_dataset_payload( $project['id'], 'gsc_qp' );
		foreach ( (array) ( $qp['rows'] ?? array() ) as $r ) {
			if ( sap_norm_url( $r['page'] ) === sap_norm_url( $task['url'] ) ) {
				$queries[] = array( $r['query'], $r['clicks'], $r['impressions'], round( $r['position'], 1 ) );
			}
		}
		usort( $queries, function ( $a, $b ) {
			return $b[2] <=> $a[2];
		} );
		$queries = array_slice( $queries, 0, 15 );
	}
	$data = array(
		'site'            => sap_project_domain( $project ),
		'task'            => array( 'title' => $task['title'], 'area' => sap_context_label( $task['context'] ), 'notes' => mb_substr( $task['notes'], 0, 1200 ), 'url' => $task['url'], 'keyword' => $task['keyword'] ),
		'finding'         => $finding ? array( 'title' => $finding['title'], 'evidence' => array_slice( (array) ( $finding['evidence']['items'] ?? array() ), 0, 8 ) ) : null,
		'page'            => $page,
		'related'         => $related ?: null,
		'queries_of_page' => array( 'columns' => array( 'query', 'clicks', 'impressions', 'position' ), 'rows' => $queries ),
	);
	$schema = array(
		'type'                 => 'object',
		'properties'           => array(
			'output' => array( 'type' => 'string', 'description' => 'خروجی آماده و قابل کپی' ),
			'tips'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		),
		'required'             => array( 'output', 'tips' ),
		'additionalProperties' => false,
	);
	$prompt = implode( "\n", array(
		'برای این کار سئو، خروجی نهایی و آماده استفاده را بنویس تا متخصص فقط کپی و اجرا کند. نمونه‌ها:',
		'- تایتل/متا: ۳ پیشنهاد تایتل (حداکثر ۶۰ کاراکتر، کلمه اصلی در ابتدا) و ۲ متا دیسکریپشن (۱۲۰ تا ۱۵۵ کاراکتر) بر اساس کوئری‌های واقعی صفحه.',
		'- FAQ: ۵ تا ۸ سوال و جواب کوتاه بر اساس کوئری‌ها، آماده برای بلوک FAQ رنک‌مث.',
		'- محتوای جدید یا آپدیت: بریف کامل (تایتل، H1، ساختار H2/H3، کلمات هم‌کانسپت، سوالات، لینک‌های داخلی، طول پیشنهادی).',
		'- لینک داخلی: صفحات مبدأ پیشنهادی و متن انکر.',
		'- تکنیکال: دستورالعمل گام‌به‌گام دقیق برای همین مورد.',
		'خروجی را با خطوط و تیترهای ساده متنی بنویس (بدون Markdown پیچیده). نکات اجرایی کوتاه را در tips بگذار.',
		'',
		wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
	) );
	$res = sap_ai_request( sap_ai_system_prompt(), $prompt, $schema, array( 'purpose' => 'assist', 'max_tokens' => 5000, 'effort' => 'low', 'timeout' => 200 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$text = trim( is_scalar( $res['output'] ?? '' ) ? (string) ( $res['output'] ?? '' ) : '' );
	$res['tips'] = array_values( array_filter( (array) ( $res['tips'] ?? array() ), 'is_scalar' ) );
	if ( '' === $text ) {
		return new WP_Error( 'sap_ai_empty', 'هوش مصنوعی خروجی‌ای برنگرداند؛ دوباره امتحان کنید.' );
	}
	if ( $res['tips'] ) {
		$text .= "\n\nنکات:\n• " . implode( "\n• ", $res['tips'] );
	}
	sap_task_save( array( 'ai_output' => $text ), $task['id'] );
	return $text;
}

/**
 * One-click focus suggestions from the data (no AI call needed):
 * clusters of the clustering sheet and the site sections with most clicks.
 *
 * @return array list of [name, url_patterns, clusters, keywords, reason]
 */
function sap_focus_suggestions( $project ) {
	$pid      = $project['id'];
	$existing = array_map( 'sap_kw_key', wp_list_pluck( sap_focuses( $pid ), 'name' ) );
	$out      = array();
	$clusters = sap_dataset_payload( $pid, 'sheet_clusters' );
	$count    = array();
	foreach ( (array) ( $clusters['rows'] ?? array() ) as $r ) {
		$count[ $r['cluster'] ] = ( $count[ $r['cluster'] ] ?? 0 ) + 1;
	}
	arsort( $count );
	foreach ( array_slice( $count, 0, 4, true ) as $name => $n ) {
		if ( ! in_array( sap_kw_key( $name ), $existing, true ) ) {
			$out[] = array( 'name' => $name, 'url_patterns' => '', 'clusters' => $name, 'keywords' => $name, 'reason' => sprintf( 'کلاستر شیت · %s کلمه', sap_num( $n ) ) );
		}
	}
	// Sections, not single pages: taxonomy archives (/product-category/x/) or folders with pages under them.
	$pages    = sap_dataset_payload( $pid, 'gsc_pages' );
	$taxonomy = array( 'product-category', 'category', 'product-tag', 'brand', 'brands', 'collection', 'collections', 'shop' );
	$folders  = array();
	$children = array();
	foreach ( (array) ( $pages['cur'] ?? array() ) as $r ) {
		$path = trim( (string) wp_parse_url( rawurldecode( $r['page'] ), PHP_URL_PATH ), '/' );
		$seg  = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		if ( count( $seg ) < 2 ) {
			continue;
		}
		$key             = '/' . $seg[0] . '/' . $seg[1] . '/';
		$folders[ $key ] = ( $folders[ $key ] ?? 0 ) + $r['clicks'];
		if ( count( $seg ) > 2 ) {
			$children[ $key ] = true;
		}
	}
	arsort( $folders );
	foreach ( $folders as $folder => $clicks ) {
		$first = explode( '/', trim( $folder, '/' ) )[0];
		if ( $clicks < 1 || ( ! in_array( strtolower( $first ), $taxonomy, true ) && empty( $children[ $folder ] ) ) ) {
			continue;
		}
		$label = str_replace( array( '-', '_' ), ' ', basename( $folder ) );
		if ( in_array( sap_kw_key( $label ), $existing, true ) ) {
			continue;
		}
		$out[] = array( 'name' => $label, 'url_patterns' => $folder, 'clusters' => '', 'keywords' => '', 'reason' => sprintf( 'بخش پربازدید سایت · %s کلیک در ۲۸ روز', sap_num( $clicks ) ) );
		if ( count( $out ) >= 6 ) {
			break;
		}
	}
	return array_slice( $out, 0, 6 );
}


/* ---------------------------------------------------------------------------
 * Staged analysis: small focused AI calls, then a synthesis.
 * Each stage reads only the facts it needs, so every call is quick and cheap,
 * and a stage whose facts did not change is not re-run.
 * ------------------------------------------------------------------------- */

/**
 * JSON-schema object helper (all properties required, no extras).
 */
function sap_ai_obj( $props ) {
	return array( 'type' => 'object', 'properties' => $props, 'required' => array_keys( $props ), 'additionalProperties' => false );
}

/**
 * Compact page facts from the page reader / crawl for a URL.
 */
function sap_ai_page_facts( $ctx, $url, $text_chars = 0 ) {
	$k    = sap_norm_url( $url );
	$page = $ctx['pages'][ $k ] ?? null;
	$cr   = $ctx['crawl_by_url'][ $k ] ?? null;
	$q    = array();
	foreach ( array_slice( sap_sort( (array) ( $ctx['by_page'][ $k ] ?? array() ), 'impressions' ), 0, 6 ) as $r ) {
		$q[] = array( $r['query'], round( $r['position'], 1 ), $r['impressions'], round( $r['ctr'] * 100, 1 ) );
	}
	$out = array(
		'url'     => sap_short_url( $url, 90 ),
		'title'   => $page['title'] ?? ( $cr['title'] ?? '' ),
		'meta'    => $page['meta'] ?? ( $cr['meta'] ?? '' ),
		'h1'      => $page ? implode( ' | ', $page['h1'] ) : ( $cr['h1'] ?? '' ),
		'words'   => $page['words'] ?? ( $cr['words'] ?? null ),
		'inlinks' => $cr['inlinks'] ?? null,
		'queries' => $q,
	);
	if ( $page ) {
		$out['h2']     = array_slice( $page['h2'], 0, 10 );
		$out['schema'] = $page['schema'];
	}
	if ( $text_chars && $page ) {
		$out['text'] = mb_substr( $page['text'], 0, $text_chars );
	}
	return $out;
}

/**
 * Stage definitions: label, input builder, schema, instruction, output budget.
 */
function sap_ai_stages() {
	$str = array( 'type' => 'string' );
	$arr = array( 'type' => 'array', 'items' => array( 'type' => 'string' ) );
	return array(
		'cannibal'  => array(
			'label'  => 'کنیبالیزیشن هوشمند',
			'tokens' => 3000,
			'schema' => sap_ai_obj( array( 'items' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'query'       => $str,
				'verdict'     => array( 'type' => 'string', 'enum' => array( 'real', 'not_cannibal', 'check' ) ),
				'reason'      => $str,
				'primary_url' => $str,
				'action'      => $str,
			) ) ) ) ),
			'task'   => implode( "\n", array(
				'این‌ها کوئری‌هایی هستند که قانون اولیه به‌عنوان کنیبالیزیشن (رقابت چند صفحه برای یک کوئری) علامت زده است. هر مورد را دقیق قضاوت کن:',
				'- کوئری برند یا ناوبری (نام سایت/برند) که صفحه اصلی و فروشگاه/دسته را نشان می‌دهد، کنیبال نیست (not_cannibal).',
				'- اگر نیت صفحات متفاوت است (مثلاً مقاله راهنما در برابر صفحه دسته فروش) و هر کدام نقش خود را دارند، معمولاً کنیبال نیست مگر جایگاه هر دو را پایین نگه داشته باشد.',
				'- کنیبال واقعی (real): دو صفحه با نیت یکسان که نمایش و جایگاه را بین خود تقسیم کرده‌اند. صفحه اصلی را در primary_url و اقدام دقیق (ادغام، تمایز محتوا، کنونیکال، لینک داخلی با انکر، ریدایرکت) را در action بنویس.',
				'- اگر مطمئن نیستی check بگذار و دلیل را بنویس.',
			) ),
		),
		'content'   => array(
			'label'  => 'صفحات و محتوا',
			'tokens' => 6000,
			'schema' => sap_ai_obj( array( 'pages' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'url'            => $str,
				'diagnosis'      => $str,
				'title'          => array( 'type' => 'string', 'description' => 'تایتل پیشنهادی یا رشته خالی اگر فعلی خوب است' ),
				'meta'           => array( 'type' => 'string', 'description' => 'متای پیشنهادی یا رشته خالی' ),
				'missing_topics' => $arr,
				'faq'            => $arr,
				'actions'        => $arr,
			) ) ) ) ),
			'task'   => implode( "\n", array(
				'این صفحات مهم سایت با کوئری‌های واقعی سرچ کنسول (کوئری، جایگاه، نمایش، CTR%)، تیترها، اسکیما و بخشی از متن اصلی‌شان آمده‌اند.',
				'برای هر صفحه: تشخیص کوتاه (چرا رتبه/CTR بهتر نیست)، تایتل و متای پیشنهادی فقط اگر لازم است (بر اساس کوئری‌های واقعی؛ تایتل حداکثر ۶۰ کاراکتر)، موضوعات و زیرعنوان‌هایی که در متن نیست ولی کوئری‌ها نشان می‌دهند کاربر دنبالش است، تا ۵ سوال FAQ مرتبط، و اقدامات مشخص محتوایی.',
				'کلی‌گویی نکن؛ به کوئری و متن واقعی استناد کن.',
			) ),
		),
		'links'     => array(
			'label'  => 'لینک‌سازی داخلی',
			'tokens' => 4000,
			'schema' => sap_ai_obj( array( 'links' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'source_url' => $str,
				'target_url' => $str,
				'anchor'     => $str,
				'placement'  => array( 'type' => 'string', 'description' => 'کجای صفحه مبدأ: جمله یا بخش مشخص از متن' ),
				'reason'     => $str,
			) ) ) ) ),
			'task'   => implode( "\n", array(
				'targets صفحاتی هستند که باید تقویت شوند (صفحات Focus، صفحات نزدیک صفحه اول، صفحات با لینک داخلی کم). sources صفحاتی هستند که می‌توانند به آن‌ها لینک بدهند، با موضوع، بخشی از متن و لینک‌های داخلی فعلی‌شان.',
				'تا ۲۰ لینک داخلی پیشنهاد بده که از نظر موضوعی واقعاً مرتبط باشد: مبدأ، مقصد، متن انکر طبیعی بر اساس کوئری اصلی صفحه مقصد، محل دقیق قرارگیری در متن مبدأ (با نقل جمله یا بخش)، و دلیل.',
				'لینکی که الان در صفحه مبدأ به همان مقصد وجود دارد را پیشنهاد نکن. از یک انکر برای دو مقصد مختلف استفاده نکن.',
			) ),
		),
		'technical' => array(
			'label'  => 'تکنیکال، سایت‌مپ، اسکیما و سرعت',
			'tokens' => 4000,
			'schema' => sap_ai_obj( array( 'issues' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'area'     => array( 'type' => 'string', 'enum' => array( 'crawl', 'index', 'sitemap', 'schema', 'cwv', 'onpage' ) ),
				'priority' => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
				'title'    => $str,
				'detail'   => $str,
				'fix'      => array( 'type' => 'string', 'description' => 'راه‌حل گام‌به‌گام دقیق (Rank Math / وردپرس / ووکامرس)' ),
				'urls'     => $arr,
			) ) ) ) ),
			'task'   => implode( "\n", array(
				'وضعیت تکنیکال سایت آمده: کدهای وضعیت خزش، ایرادهای شناسایی‌شده با تعداد، سایت‌مپ‌ها، اسکیمای هر صفحه کلیدی (نوع‌ها و خطاهای JSON-LD) و Core Web Vitals موبایل (داده واقعی کاربران CrUX و امتیاز آزمایشگاهی).',
				'مهم‌ترین مشکلات تکنیکال را با اولویت، شرح، راه‌حل گام‌به‌گام و آدرس‌های نمونه بنویس. برای اسکیما مشخص کن کدام نوع‌ها برای هر نوع صفحه لازم است و نیست (Product و Offer برای محصول، BreadcrumbList، Article برای مقاله، Organization برای سایت). برای CWV علت محتمل و راه‌حل عملی بنویس.',
				'مشکلات کم‌اثر را حذف کن؛ حداکثر ۱۲ مورد.',
			) ),
		),
		'plan'      => array(
			'label'  => 'جمع‌بندی و اکشن پلن',
			'tokens' => 7000,
			'schema' => sap_ai_analysis_schema(),
			'task'   => '',
		),
	);
}

/**
 * Input of a stage (null = nothing to analyse).
 */
function sap_ai_stage_input( $project, $stage, $ctx ) {
	$pid = $project['id'];
	switch ( $stage ) {
		case 'cannibal':
			$items = array();
			foreach ( sap_findings_active( $pid, 500 ) as $f ) {
				if ( 'cannibal' !== $f['detector'] || count( $items ) >= 25 ) {
					continue;
				}
				$pages = array();
				foreach ( (array) ( $f['evidence']['items'] ?? array() ) as $it ) {
					$facts   = sap_ai_page_facts( $ctx, $it['url'] ?? '' );
					$pages[] = array( 'url' => $facts['url'], 'title' => $facts['title'], 'position' => $it['pos'] ?? null, 'impressions' => $it['impr'] ?? null, 'clicks' => $it['clicks'] ?? null );
				}
				$items[] = array( 'query' => $f['keyword'], 'pages' => $pages );
			}
			return $items ? array( 'site' => sap_project_domain( $project ), 'brand_terms' => $project['brand_terms'], 'candidates' => $items ) : null;

		case 'content':
			$urls = array();
			foreach ( $ctx['focuses'] as $f ) {
				foreach ( $ctx['page_impr'] as $k => $impr ) {
					if ( isset( $ctx['pages'][ $k ] ) && sap_url_matches( $ctx['page_url'][ $k ], $f['url_patterns'] ) ) {
						$urls[ $k ] = $impr;
					}
				}
			}
			arsort( $urls );
			$all = array_intersect_key( $ctx['page_impr'], $ctx['pages'] );
			arsort( $all );
			$pick = array_slice( array_keys( $urls + $all ), 0, 8 );
			$out  = array();
			foreach ( $pick as $k ) {
				$out[] = sap_ai_page_facts( $ctx, $ctx['page_url'][ $k ] ?? $ctx['pages'][ $k ]['url'], 900 );
			}
			return $out ? array( 'site' => sap_project_domain( $project ), 'focuses' => wp_list_pluck( $ctx['focuses'], 'name' ), 'pages' => $out ) : null;

		case 'links':
			if ( count( $ctx['pages'] ) < 2 ) {
				return null;
			}
			$targets = array();
			foreach ( $ctx['focuses'] as $f ) {
				$pillar = sap_focus_pillar( $ctx, $f['id'] );
				if ( $pillar ) {
					$targets[ sap_norm_url( $pillar ) ] = $pillar;
				}
			}
			foreach ( sap_findings_active( $pid ) as $f ) {
				if ( in_array( $f['detector'], array( 'striking', 'weak_links', 'cl_wrong_page', 'cl_target_none' ), true ) && $f['url'] && count( $targets ) < 10 ) {
					$targets[ sap_norm_url( $f['url'] ) ] = $f['url'];
				}
			}
			if ( ! $targets ) {
				return null;
			}
			$t_out = array();
			foreach ( $targets as $u ) {
				$facts   = sap_ai_page_facts( $ctx, $u );
				$t_out[] = array( 'url' => $facts['url'], 'title' => $facts['title'], 'main_queries' => array_slice( array_column( $facts['queries'], 0 ), 0, 3 ), 'inlinks' => $facts['inlinks'] );
			}
			$s_out = array();
			foreach ( array_slice( $ctx['pages'], 0, 25, true ) as $k => $p ) {
				$s_out[] = array(
					'url'            => sap_short_url( $p['url'], 90 ),
					'title'          => $p['title'],
					'h2'             => array_slice( $p['h2'], 0, 6 ),
					'text'           => mb_substr( $p['text'], 0, 500 ),
					'existing_links' => array_values( array_unique( array_map( function ( $l ) {
						return sap_short_url( $l[0], 70 );
					}, array_slice( $p['links'], 0, 30 ) ) ) ),
				);
			}
			return array( 'site' => sap_project_domain( $project ), 'targets' => $t_out, 'sources' => $s_out );

		case 'technical':
			$issues = array();
			foreach ( sap_findings_active( $pid ) as $f ) {
				if ( in_array( $f['context'], array( 'technical', 'onpage' ), true ) ) {
					$issues[] = array( $f['title'], array_slice( array_column( (array) ( $f['evidence']['items'] ?? array() ), 'page' ), 0, 5 ) );
				}
			}
			$schema = array();
			foreach ( $ctx['pages'] as $p ) {
				$schema[] = array( sap_short_url( $p['url'], 80 ), $p['schema'], $p['schema_errors'], $p['no_alt'] );
			}
			$cwv = array();
			foreach ( (array) ( $ctx['cwv']['pages'] ?? array() ) as $c ) {
				$cwv[] = array( 'url' => sap_short_url( $c['url'], 80 ), 'lab_score' => $c['score'], 'field' => $c['field'], 'lab' => $c['lab'] );
			}
			return array(
				'site'         => sap_project_domain( $project ),
				'crawl'        => $ctx['crawl_meta'] ?? null,
				'sitemaps'     => $ctx['gsc_sitemaps']['sitemaps'] ?? null,
				'issues'       => array_slice( $issues, 0, 30 ),
				'schema_pages' => array( 'columns' => array( 'url', 'types', 'jsonld_errors', 'images_without_alt' ), 'rows' => $schema ),
				'cwv_mobile'   => $cwv,
			);

		case 'plan':
			$brief = sap_ai_brief( $project );
			unset( $brief['clusters'] );
			foreach ( $brief['findings'] as &$f ) {
				$f['evidence'] = array_slice( $f['evidence'], 0, 2 );
			}
			unset( $f );
			$brief['findings'] = array_slice( $brief['findings'], 0, 30 );
			foreach ( array( 'cannibal', 'content', 'links', 'technical' ) as $st ) {
				$d = sap_dataset_payload( $pid, 'ai_' . $st );
				if ( $d ) {
					unset( $d['summary_line'] );
					$brief[ 'stage_' . $st ] = $d;
				}
			}
			if ( isset( $brief['stage_cannibal']['items'] ) ) {
				$brief['stage_cannibal']['items'] = array_values( array_filter( $brief['stage_cannibal']['items'], function ( $i ) {
					return 'real' === $i['verdict'];
				} ) );
			}
			return $brief;
	}
	return null;
}

/**
 * Run one stage (skips when its input did not change).
 *
 * @return array|WP_Error result, with 'skipped' => message when not re-run.
 */
function sap_ai_stage_run( $project, $stage, $force = false ) {
	$defs = sap_ai_stages();
	if ( ! isset( $defs[ $stage ] ) ) {
		return new WP_Error( 'sap_ai_stage', 'مرحله نامعتبر.' );
	}
	$def   = $defs[ $stage ];
	$key   = 'plan' === $stage ? 'ai_analysis' : 'ai_' . $stage;
	$ctx   = sap_analysis_context( $project );
	$ctx['pages'] = (array) ( $ctx['pages']['pages'] ?? array() );
	$input = sap_ai_stage_input( $project, $stage, $ctx );
	if ( null === $input ) {
		return array( 'skipped' => 'داده‌ای برای این بخش نیست؛ رد شد.' );
	}
	$facts = $input;
	if ( 'plan' === $stage ) {
		unset( $facts['this_month'], $facts['project']['days_left'] );
		foreach ( $facts['findings'] as &$f ) {
			unset( $f['id'], $f['score'] );
		}
		unset( $f );
	}
	$hash = md5( wp_json_encode( $facts ) . '|' . sap_ai_model_for( 'analysis' ) );
	$old  = sap_dataset( $project['id'], $key );
	if ( ! $force && $old && ( $old['meta']['hash'] ?? '' ) === $hash ) {
		return array( 'skipped' => 'داده‌های این بخش از تحلیل قبلی تغییری نکرده؛ نتیجه قبلی نگه داشته شد (بدون هزینه).' );
	}
	if ( 'plan' === $stage ) {
		$task = implode( "\n", array(
			'داده‌های پروژه سئو در JSON زیر است: خلاصه سرچ کنسول، Focusها، یافته‌های قوانین (findings با id) و نتیجه تحلیل‌های تخصصی قبلی (stage_cannibal، stage_content، stage_links، stage_technical).',
			'۱. وضعیت پروژه را تشخیص بده (summary)، سلامت هر حوزه را نمره ۰ تا ۱۰۰ بده (health) و برای هر Focus یک بینش کلیدی بنویس. نقاط قوت (wins) و ریسک‌ها (risks) را کوتاه فهرست کن.',
			sprintf( '۲. اکشن پلن این ماه: ۸ تا ۱۵ کار مشخص در سقف %s ساعت، مرتب‌شده بر اساس اثر، با هفته پیشنهادی ۱ تا ۴. کارها را از نتایج تحلیل‌های تخصصی بساز و خروجی آماده آن‌ها را در deliverable بگذار: تایتل/متای پیشنهادی، FAQ، فهرست لینک‌های داخلی (مبدأ ← مقصد: انکر)، راه‌حل تکنیکال. کارهای هم‌نوع کوچک را در یک کار ادغام کن (مثلاً «۸ لینک داخلی به دسته X»). idهای finding مرتبط را در finding_ids بگذار. کارهای this_month را تکرار نکن.', sap_num( $project['capacity_hours'] ) ),
			'',
			wp_json_encode( $input, JSON_UNESCAPED_UNICODE ),
		) );
	} else {
		$task = $def['task'] . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE );
	}
	$res = sap_ai_request( sap_ai_system_prompt(), $task, $def['schema'], array( 'purpose' => 'analysis', 'max_tokens' => $def['tokens'], 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$res = sap_ai_stage_postprocess( $project, $stage, $res, $input );
	sap_dataset_put( $project['id'], $key, $res, array(
		'model' => sap_ai_model_for( 'analysis' ),
		'hash'  => $hash,
		'cost'  => sap_ai_usage()['last_cost'] ?? 0,
		'count' => count( (array) reset( $res ) ),
	) );
	return $res;
}

/**
 * Normalize a stage answer and apply its side effects.
 */
function sap_ai_stage_postprocess( $project, $stage, $res, $input ) {
	$list = function ( $v ) {
		return array_values( array_filter( (array) $v, 'is_array' ) );
	};
	$strs = function ( $v ) {
		return array_values( array_map( 'strval', array_filter( (array) $v, 'is_scalar' ) ) );
	};
	switch ( $stage ) {
		case 'cannibal':
			$items = array();
			$by_q  = array();
			foreach ( sap_findings( $project['id'], array( 'status' => '', 'limit' => 2000 ) ) as $f ) {
				if ( 'cannibal' === $f['detector'] ) {
					$by_q[ sap_kw_key( $f['keyword'] ) ] = $f;
				}
			}
			$dismissed = 0;
			$real      = 0;
			foreach ( $list( $res['items'] ?? array() ) as $i ) {
				$i = wp_parse_args( $i, array( 'query' => '', 'verdict' => 'check', 'reason' => '', 'primary_url' => '', 'action' => '' ) );
				$items[] = $i;
				$f       = $by_q[ sap_kw_key( $i['query'] ) ] ?? null;
				if ( ! $f ) {
					continue;
				}
				global $wpdb;
				$ev           = $f['evidence'];
				$ev['ai']     = array( 'verdict' => $i['verdict'], 'reason' => $i['reason'], 'action' => $i['action'] );
				$upd          = array( 'evidence' => wp_json_encode( $ev ) );
				if ( 'not_cannibal' === $i['verdict'] && 'open' === $f['status'] ) {
					$upd['status'] = 'dismissed';
					++$dismissed;
				} elseif ( 'real' === $i['verdict'] ) {
					$upd['detail'] = "تحلیل هوشمند: {$i['reason']}\nاقدام: {$i['action']}" . ( $i['primary_url'] ? "\nصفحه اصلی: {$i['primary_url']}" : '' );
					++$real;
				}
				$wpdb->update( sap_table( 'findings' ), $upd, array( 'id' => $f['id'] ) );
			}
			return array( 'items' => $items, 'summary_line' => sprintf( '%s کنیبال واقعی، %s مورد کنیبال نبود و از صندوق ورودی حذف شد.', sap_num( $real ), sap_num( $dismissed ) ) );

		case 'content':
			$pages = array();
			foreach ( $list( $res['pages'] ?? array() ) as $p ) {
				$p                   = wp_parse_args( $p, array( 'url' => '', 'diagnosis' => '', 'title' => '', 'meta' => '', 'missing_topics' => array(), 'faq' => array(), 'actions' => array() ) );
				$p['missing_topics'] = $strs( $p['missing_topics'] );
				$p['faq']            = $strs( $p['faq'] );
				$p['actions']        = $strs( $p['actions'] );
				$pages[]             = $p;
			}
			return array( 'pages' => $pages, 'summary_line' => sprintf( 'تحلیل محتوای %s صفحه کلیدی (تایتل/متای پیشنهادی، موضوعات جاافتاده، FAQ).', sap_num( count( $pages ) ) ) );

		case 'links':
			$links = array();
			foreach ( $list( $res['links'] ?? array() ) as $l ) {
				$links[] = wp_parse_args( $l, array( 'source_url' => '', 'target_url' => '', 'anchor' => '', 'placement' => '', 'reason' => '' ) );
			}
			return array( 'links' => $links, 'summary_line' => sprintf( '%s پیشنهاد لینک داخلی با انکر و محل قرارگیری.', sap_num( count( $links ) ) ) );

		case 'technical':
			$issues = array();
			foreach ( $list( $res['issues'] ?? array() ) as $i ) {
				$i         = wp_parse_args( $i, array( 'area' => 'crawl', 'priority' => 'medium', 'title' => '', 'detail' => '', 'fix' => '', 'urls' => array() ) );
				$i['urls'] = $strs( $i['urls'] );
				$issues[]  = $i;
			}
			return array( 'issues' => $issues, 'summary_line' => sprintf( '%s مشکل تکنیکال اولویت‌بندی شد (سایت‌مپ، ایندکس، اسکیما، سرعت).', sap_num( count( $issues ) ) ) );

		case 'plan':
			$res   = sap_ai_normalize_analysis( $res );
			$valid = array_flip( array_column( (array) ( $input['findings'] ?? array() ), 'id' ) );
			foreach ( $res['plan'] as $n => $item ) {
				$res['plan'][ $n ]['finding_ids'] = array_values( array_filter( array_map( 'intval', (array) $item['finding_ids'] ), function ( $id ) use ( $valid ) {
					return isset( $valid[ $id ] );
				} ) );
				$res['plan'][ $n ]['ref'] = 'ai' . substr( md5( $item['title'] . '|' . $item['url'] ), 0, 14 );
			}
			$res['summary_line'] = sprintf( 'تشخیص وضعیت و %s کار مشخص برای اکشن پلن آماده شد.', sap_num( count( $res['plan'] ) ) );
			return $res;
	}
	return $res;
}
