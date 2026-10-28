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
	) ) . ( function_exists( 'sap_ai_project_context' ) ? sap_ai_project_context() : '' );
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
		$notes = sap_comments_for_ai( 'finding', $f['id'] );
		if ( $notes ) {
			$brief['findings'][ count( $brief['findings'] ) - 1 ]['expert_note'] = implode( ' / ', $notes );
		}
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
		'expert_notes'    => array_merge( sap_comments_for_ai( 'task', $task['id'] ), $finding ? sap_comments_for_ai( 'finding', $finding['id'] ) : array() ),
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
		'expert_notes نظر و اصلاحات خود متخصص سئو درباره همین کار است؛ آن را دقیقاً رعایت کن و بر پیشنهادهای عمومی مقدم بدان.',
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
 * Persian name of a site section: the H1 / title of its own page, else its
 * keyword map entry, else its top Search Console query, else the slug.
 */
function sap_focus_section_name( $project, $folder ) {
	$pid  = $project['id'];
	$url  = sap_full_url( $project, $folder );
	$name = sap_page_title( $pid, $url );
	if ( '' !== $name && ! preg_match( '#^(/|صفحه اصلی$)#u', $name ) ) {
		return $name;
	}
	$km = sap_keymap( $pid )[ sap_norm_url( $url ) ] ?? null;
	if ( $km && '' !== $km['primary_kw'] ) {
		return $km['primary_kw'];
	}
	$best = null;
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
		if ( sap_url_matches( $r['page'], array( $folder ) ) && ! sap_is_brand( $r['query'], $project ) && ( ! $best || $r['impressions'] > $best['impressions'] ) ) {
			$best = $r;
		}
	}
	return $best ? $best['query'] : str_replace( array( '-', '_' ), ' ', rawurldecode( basename( $folder ) ) );
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
		$label = sap_focus_section_name( $project, $folder );
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
	return apply_filters( 'sap_ai_stages', array(
		'keymap'    => array(
			'label'  => 'کلمه اصلی صفحات',
			'model'  => 'light',
			'tokens' => 3000,
			'batch'  => array( 'in' => 'pages', 'out' => 'pages', 'size' => 8 ),
			'schema' => sap_ai_obj( array( 'pages' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'id'        => array( 'type' => 'integer' ),
				'primary'   => array( 'type' => 'string', 'description' => 'کلمه کلیدی اصلی صفحه' ),
				'secondary' => array( 'type' => 'array', 'items' => $str, 'description' => 'تا ۸ کلمه فرعی/هم‌کانسپت' ),
				'intent'    => array( 'type' => 'string', 'enum' => array_keys( sap_keymap_intents() ) ),
				'page_type' => array( 'type' => 'string', 'enum' => array_keys( sap_keymap_page_types() ) ),
				'reason'    => array( 'type' => 'string', 'description' => 'یک جمله کوتاه' ),
			) ) ) ) ),
			'task'   => implode( "\n", array(
				'برای هر صفحه سایت (با تایتل، H1، تیترها و کوئری‌های واقعی سرچ کنسول: کوئری، نمایش، جایگاه) مشخص کن این صفحه برای چه «کلمه کلیدی اصلی» ساخته شده است.',
				'- کلمه اصلی باید با موضوع و نیت خود صفحه (تایتل و H1) بخواند، نه صرفاً پرنمایش‌ترین کوئری. مثال: صفحه دسته «کفی ورزشی» کلمه اصلی‌اش «کفی ورزشی» است حتی اگر برای «کفی» نمایش بیشتری بگیرد؛ «کفی» کلمه کلی‌تر است و نباید کلمه اصلی این صفحه باشد.',
				'- برای صفحه محصول، نام محصول/مدل؛ برای مقاله، موضوع اصلی مقاله؛ برای دسته، نام دسته به شکلی که کاربر جستجو می‌کند.',
				'- secondary: کلمات فرعی و هم‌کانسپتی که همین صفحه باید پوشش دهد (هم‌معنی، نسخه‌های خرید/قیمت، زیرموضوع‌ها) — نه کلمات کلی‌تر یا موضوعات صفحات دیگر. حداکثر ۶ کلمه دقیق؛ فله‌ای کلمه نده.',
				'- نیت هر کلمه باید با نوع صفحه بخواند: صفحه محصول فقط کلمات مخصوص همان محصول/مدل را می‌گیرد (مثلاً برای «چسب کفش ۱۰۵»: «چسب ۱۰۵»، «قیمت چسب کفش ۱۰۵»)؛ کلمات عمومی خرید مثل «خرید چسب کفش» یا «چسب کتونی» نیاز کاربر به مقایسه چند محصول است و مال صفحه دسته‌بندی است، نه یک محصول. کلمات اطلاعاتی (چیست، چگونه، روش، آموزش) مال مقاله‌اند و نباید به صفحه دسته یا محصول داده شوند.',
				'- قانون یک صاحب: هر کلمه فقط کلمه اصلی یک صفحه است. کلمات taken_keywords صاحب دارند؛ آن‌ها را به صفحه دیگری نده. دو صفحه از همین فهرست هم کلمه اصلی یکسان نگیرند.',
				'- اولویت نوع صفحه: کلمه عمومی مال دسته‌بندی/برند/برچسب است، کلمه اطلاعاتی مال مقاله، و محصول فقط نام خودش را می‌گیرد.',
				'- اگر صفحه واقعاً کلمه هدفی ندارد (صفحه کاربردی یا موضوع نامشخص)، primary را خالی بگذار؛ کلمه زورکی نساز.',
				'- id هر صفحه را عیناً برگردان.',
			) ),
		),
		'focus'     => array(
			'label'  => 'Focus و هدف‌گذاری هوشمند',
			'tokens' => 4000,
			'schema' => sap_ai_obj( array( 'focuses' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'ref'           => array( 'type' => 'string', 'description' => 'ref ورودی را عیناً برگردان' ),
				'name'          => array( 'type' => 'string', 'description' => 'نام فارسی کوتاه و گویا، مثل «کفی طبی ورزشی»' ),
				'goal'          => array( 'type' => 'string', 'description' => 'هدف مشخص و قابل اندازه‌گیری با عدد و مهلت' ),
				'metric'        => array( 'type' => 'string', 'enum' => array_keys( sap_kpi_metrics() ) ),
				'target'        => array( 'type' => 'number' ),
				'months'        => array( 'type' => 'integer', 'description' => '۱ تا ۶ ماه' ),
				'potential'     => array( 'type' => 'integer', 'description' => '۰ تا ۱۰: ظرفیت رشد (نمایش، حجم جستجو، کلمات نزدیک صفحه اول)' ),
				'value'         => array( 'type' => 'integer', 'description' => '۰ تا ۱۰: ارزش کسب‌وکاری (نیت خرید، کلیک فعلی)' ),
				'urgency'       => array( 'type' => 'integer', 'description' => '۰ تا ۱۰: بحرانی بودن (افت، ایرادها، رقبا)' ),
				'weight'        => array( 'type' => 'number', 'description' => '۱ تا ۳' ),
				'metric_reason' => array( 'type' => 'string', 'description' => 'چرا این شاخص برای شروع بهترین است' ),
				'rationale'     => array( 'type' => 'string', 'description' => 'دلیل اولویت با استناد به عدد' ),
				'first_steps'   => array( 'type' => 'array', 'items' => $str, 'description' => '۲ تا ۴ اقدام اول' ),
			) ) ) ) ),
			'task'   => implode( "\n", array(
				'این‌ها بخش‌های سایت هستند که می‌توانند Focus (تمرکز) پروژه سئو باشند، با داده واقعی هر بخش: کلیک و نمایش ۲۸ روز و ۲۸ روز قبل، تعداد کلمات رتبه‌دار و صفحه اول و رتبه ۱ تا ۳، کوئری‌های اصلی، حجم جستجوی کلمات کلاستر و تعداد ایرادها. existing=true یعنی این Focus از قبل تعریف شده است.',
				'برای هر بخش:',
				'- name: نام فارسی کوتاه و گویا بر اساس عنوان صفحه و کوئری‌ها (هرگز نامک انگلیسی).',
				'- metric: بهترین شاخص شروع. قاعده: اگر کلمات هدف هنوز صفحه اول نیستند (top10 کم ولی ranking زیاد)، top10؛ اگر صفحه اول هستند ولی کلیک کم است، clicks؛ اگر بخش تازه است و رتبه ندارد، impressions؛ اگر صفحه اول تثبیت شده، top3. دلیل را در metric_reason بنویس.',
				'- target و months: هدف واقع‌بینانه نسبت به مقدار فعلی همان شاخص (current) و ظرفیت (کلمات نزدیک صفحه اول، حجم جستجو). goal را یک جمله با عدد فعلی، عدد هدف و مهلت بنویس.',
				'- potential و value و urgency را صادقانه نمره بده و weight را بین ۱ (کم‌اهمیت) تا ۳ (حیاتی) از آن‌ها نتیجه بگیر. rationale را با عدد بنویس.',
				'- first_steps: اولین اقدام‌های مشخص (با نام صفحه/کلمه).',
				'بخش‌ها را به ترتیب اولویت (بیشترین weight اول) برگردان.',
			) ),
		),
		'cannibal'  => array(
			'label'  => 'کنیبالیزیشن هوشمند',
			'model'  => 'light',
			'tokens' => 3000,
			'batch'  => array( 'in' => 'candidates', 'out' => 'items', 'size' => 10 ),
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
				'- primary_keyword هر صفحه کلمه‌ای است که آن صفحه برایش ساخته شده (نقشه کلمات). اگر کوئری کلمه اصلی هیچ‌کدام نیست یا کلی‌تر از کلمه اصلی صفحه است (مثل «کفی» برای صفحه «کفی ورزشی»)، این نمایش جانبی/دابل ریزالت است، نه کنیبال (not_cannibal) — مگر اینکه صفحه اشتباه بالاتر از صاحب کلمه باشد.',
				'- اگر مطمئن نیستی check بگذار و دلیل را بنویس.',
			) ),
		),
		'content'   => array(
			'label'  => 'صفحات و محتوا',
			'tokens' => 5000,
			'batch'  => array( 'in' => 'pages', 'out' => 'pages', 'size' => 3 ),
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
			'model'  => 'light',
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
				'قواعد سخت: مبدأ و مقصد هرگز یک صفحه نباشند. اگر در existing_links صفحه مبدأ لینکی به همان مقصد هست (حتی با انکر دیگر) پیشنهاد نده. انکر باید کلمه اصلی صفحه مقصد (keyword) یا شکل طبیعی آن باشد، هرگز own_keyword صفحه مبدأ یا کلمه‌ای که مال صفحه دیگری است. از یک انکر برای دو مقصد مختلف استفاده نکن. فقط لینک‌هایی که از نظر موضوعی برای کاربر واقعاً مفیدند؛ کمتر و دقیق بهتر از زیاد و کلی.',
			) ),
		),
		'technical' => array(
			'label'  => 'تکنیکال، سایت‌مپ، اسکیما و سرعت',
			'tokens' => 4000,
			'schema' => sap_ai_obj( array( 'issues' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'area'     => array( 'type' => 'string', 'enum' => array( 'crawl', 'index', 'sitemap', 'robots', 'schema', 'cwv', 'pages', 'onpage' ) ),
				'priority' => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
				'title'    => $str,
				'detail'   => $str,
				'fix'      => array( 'type' => 'string', 'description' => 'راه‌حل گام‌به‌گام دقیق (Rank Math / وردپرس / ووکامرس)' ),
				'urls'     => $arr,
			) ) ) ) ),
			'task'   => implode( "\n", array(
				'وضعیت تکنیکال سایت آمده: کدهای وضعیت خزش، ایرادهای شناسایی‌شده با تعداد، سایت‌مپ‌های سرچ کنسول و نتیجه بررسی فایل‌های سایت‌مپ (صفحات مهم جامانده، آدرس‌های خراب)، robots.txt (قوانین و مسیرهای مسدود)، وضعیت ایندکس صفحات مهم از URL Inspection، اسکیمای هر صفحه کلیدی (نوع‌ها و خطاهای JSON-LD) و Core Web Vitals موبایل (داده واقعی کاربران CrUX و امتیاز آزمایشگاهی).',
					'مثل یک متخصص سئو تکنیکال حرفه‌ای بخش به بخش (sitemap، robots، cwv، schema، index، pages، crawl) بررسی کن؛ هر مشکل را در area درست بگذار و اگر بخشی سالم است چیزی برایش ننویس.',
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
	) );
}

/**
 * Input of a stage (null = nothing to analyse).
 */
function sap_ai_stage_input( $project, $stage, $ctx ) {
	$pid = $project['id'];
	switch ( $stage ) {
		case 'keymap':
			$pages = array();
			$taken = array();
			foreach ( sap_keymap( $pid ) as $k => $m ) {
				if ( '' !== $m['core'] && ( $m['confirmed'] || in_array( $m['source'], array( 'manual', 'sheet' ), true ) ) ) {
					$taken[] = $m['primary_kw'];
				}
			}
			foreach ( sap_keymap( $pid ) as $k => $m ) {
				// Products keep their name; pages without a keyword need no AI.
				if ( $m['confirmed'] || in_array( $m['source'], array( 'manual', 'sheet' ), true ) || ( $ctx['page_impr'][ $k ] ?? 0 ) < 1 || in_array( $m['status'], array( 'title', 'none', 'noindex', 'prune_410', 'prune_301' ), true ) || 'product' === sap_content_type( $m ) ) {
					continue;
				}
				$p = $ctx['pages'][ $k ] ?? null;
				$q = array();
				foreach ( array_slice( sap_sort( (array) ( $ctx['by_page'][ $k ] ?? array() ), 'impressions' ), 0, 10 ) as $r ) {
					$q[] = array( $r['query'], $r['impressions'], round( $r['position'], 1 ) );
				}
				$pages[ $k ] = array(
					'id'        => $m['id'],
					'url'       => sap_short_url( $m['url'], 90 ),
					'title'     => $p['title'] ?? $m['title'],
					'h1'        => $p ? implode( ' | ', $p['h1'] ) : '',
					'h2'        => $p ? array_slice( $p['h2'], 0, 5 ) : array(),
					'type'      => sap_content_types()[ sap_content_type( $m ) ] ?? $m['page_type'],
					'queries'   => $q,
				);
			}
			uksort( $pages, function ( $a, $b ) use ( $ctx ) {
				return ( $ctx['page_impr'][ $b ] ?? 0 ) <=> ( $ctx['page_impr'][ $a ] ?? 0 );
			} );
			$pages = array_slice( array_values( $pages ), 0, 40 );
			return $pages ? array( 'site' => sap_project_domain( $project ), 'brand_terms' => $project['brand_terms'], 'taken_keywords' => array_slice( $taken, 0, 80 ), 'pages' => $pages ) : null;

		case 'focus':
			$cands = array();
			foreach ( sap_focuses( $pid, true ) as $f ) {
				$cands[ 'f' . $f['id'] ] = $f + array( 'existing' => true );
			}
			foreach ( sap_focus_suggestions( $project ) as $i => $sg ) {
				$cands[ 's' . $i ] = array(
					'name'         => $sg['name'],
					'url_patterns' => sap_rows( $sg['url_patterns'] ),
					'clusters'     => sap_list( $sg['clusters'] ),
					'keywords'     => sap_list( $sg['keywords'] ),
					'existing'     => false,
				);
			}
			if ( ! $cands || empty( $ctx['gsc_qp']['rows'] ) ) {
				return null;
			}
			$prev = array();
			foreach ( (array) ( $ctx['gsc_pages']['prev'] ?? array() ) as $r ) {
				$prev[] = $r;
			}
			$fcount = array();
			foreach ( sap_findings_active( $pid ) as $f ) {
				$fcount[ $f['focus_id'] ] = ( $fcount[ $f['focus_id'] ] ?? 0 ) + 1;
			}
			$out = array();
			foreach ( $cands as $ref => $c ) {
				$c['kw_set'] = sap_focus_keyword_set( $c, $ctx['sheet_clusters']['rows'] ?? array() );
				$k           = sap_focus_kpis( $c, $pid );
				$q           = array();
				$near        = 0;
				foreach ( $ctx['gsc_qp']['rows'] as $r ) {
					if ( sap_url_matches( $r['page'], $c['url_patterns'] ) || isset( $c['kw_set'][ sap_kw_key( $r['query'] ) ] ) ) {
						$q[] = $r;
						if ( $r['position'] > 10 && $r['position'] <= 20 && $r['impressions'] >= 20 ) {
							++$near;
						}
					}
				}
				$top = array();
				foreach ( array_slice( sap_sort( $q, 'impressions' ), 0, 8 ) as $r ) {
					$top[] = array( $r['query'], $r['impressions'], $r['clicks'], round( $r['position'], 1 ) );
				}
				$vol = 0;
				foreach ( $c['kw_set'] as $kw ) {
					$vol += sap_ctx_volume( $ctx, $kw );
				}
				$prev_clicks = 0;
				foreach ( $prev as $r ) {
					if ( $c['url_patterns'] && sap_url_matches( $r['page'], $c['url_patterns'] ) ) {
						$prev_clicks += $r['clicks'];
					}
				}
				$out[] = array(
					'ref'            => $ref,
					'existing'       => $c['existing'],
					'name_now'       => $c['name'],
					'section'        => implode( ' ', $c['url_patterns'] ),
					'clusters'       => implode( '، ', $c['clusters'] ),
					'keywords'       => implode( '، ', array_slice( $c['keywords'], 0, 5 ) ),
					'current'        => $k ? array_intersect_key( $k, sap_kpi_metrics() ) : null,
					'ranking_kws'    => $k['ranking'] ?? 0,
					'near_page1'     => $near,
					'clicks_prev28'  => $c['url_patterns'] ? $prev_clicks : null,
					'cluster_volume' => $vol ?: null,
					'issues'         => $c['existing'] ? ( $fcount[ $c['id'] ] ?? 0 ) : null,
					'top_queries'    => $top,
					'goal_now'       => $c['existing'] ? $c['goal'] : '',
				);
			}
			return array( 'site' => sap_project_domain( $project ), 'columns_top_queries' => array( 'query', 'impressions', 'clicks', 'position' ), 'sections' => $out );

		case 'cannibal':
			$items = array();
			foreach ( sap_findings_active( $pid, 500 ) as $f ) {
				if ( 'cannibal' !== $f['detector'] || count( $items ) >= 25 ) {
					continue;
				}
				$pages = array();
				foreach ( (array) ( $f['evidence']['items'] ?? array() ) as $it ) {
					$facts   = sap_ai_page_facts( $ctx, $it['url'] ?? '' );
					$km      = sap_keymap( $pid )[ sap_norm_url( $it['url'] ?? '' ) ] ?? null;
					$pages[] = array( 'url' => $facts['url'], 'title' => $facts['title'], 'primary_keyword' => $km['primary_kw'] ?? '', 'intent' => $km['intent'] ?? '', 'position' => $it['pos'] ?? null, 'impressions' => $it['impr'] ?? null, 'clicks' => $it['clicks'] ?? null );
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
			$pick = array_slice( array_keys( $urls + $all ), 0, 12 );
			$out  = array();
			foreach ( $pick as $k ) {
				$out[] = sap_ai_page_facts( $ctx, $ctx['page_url'][ $k ] ?? $ctx['pages'][ $k ]['url'], 2500 );
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
				$tm      = sap_keymap( $pid )[ sap_norm_url( $u ) ] ?? null;
				$t_out[] = array( 'url' => $facts['url'], 'title' => $facts['title'], 'keyword' => $tm ? (string) $tm['primary_kw'] : '', 'main_queries' => array_slice( array_column( $facts['queries'], 0 ), 0, 3 ), 'inlinks' => $facts['inlinks'] );
			}
			$s_out = array();
			foreach ( array_slice( $ctx['pages'], 0, 25, true ) as $k => $p ) {
				$sm      = sap_keymap( $pid )[ $k ] ?? null;
				$s_out[] = array(
					'url'            => sap_short_url( $p['url'], 90 ),
					'title'          => $p['title'],
					'own_keyword'    => $sm ? (string) $sm['primary_kw'] : '',
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
					$issues[] = array( $f['title'], array_slice( array_column( array_filter( (array) ( $f['evidence']['items'] ?? array() ), 'is_array' ), 'page' ), 0, 5 ) );
				}
			}
			$schema = array();
			foreach ( $ctx['pages'] as $p ) {
				$schema[] = array( sap_short_url( (string) ( $p['url'] ?? '' ), 80 ), (array) ( $p['schema'] ?? array() ), (int) ( $p['schema_errors'] ?? 0 ), (int) ( $p['no_alt'] ?? 0 ) );
			}
			$cwv = array();
			foreach ( (array) ( $ctx['cwv']['pages'] ?? array() ) as $c ) {
				$cwv[] = array( 'url' => sap_short_url( (string) ( $c['url'] ?? '' ), 80 ), 'lab_score' => $c['score'] ?? null, 'field' => $c['field'] ?? null, 'lab' => $c['lab'] ?? null );
			}
			return array(
				'site'         => sap_project_domain( $project ),
				'crawl'        => $ctx['crawl_meta'] ?? null,
				'sitemaps'     => $ctx['gsc_sitemaps']['sitemaps'] ?? null,
				'issues'       => array_slice( $issues, 0, 30 ),
				'schema_pages' => array( 'columns' => array( 'url', 'types', 'jsonld_errors', 'images_without_alt' ), 'rows' => $schema ),
				'cwv_mobile'   => $cwv,
				'robots'       => isset( $ctx['robots'] ) ? array( 'code' => $ctx['robots']['code'] ?? 0, 'rules' => array_slice( (array) ( $ctx['robots']['rules'] ?? array() ), 0, 30 ), 'sitemaps' => $ctx['robots']['sitemaps'] ?? array(), 'blocked_paths' => array_values( array_map( function ( $t ) {
					return $t['path'] ?? '';
				}, array_filter( (array) ( $ctx['robots']['tests'] ?? array() ), function ( $t ) {
					return is_array( $t ) && empty( $t['allowed'] );
				} ) ) ) ) : null,
				'sitemap_check' => isset( $ctx['sitemap_check'] ) ? array( 'root' => $ctx['sitemap_check']['root'] ?? '', 'files' => count( (array) ( $ctx['sitemap_check']['maps'] ?? array() ) ), 'urls' => $ctx['sitemap_check']['total'] ?? 0, 'important_missing' => array_slice( array_column( (array) ( $ctx['sitemap_check']['missing'] ?? array() ), 'url' ), 0, 10 ), 'bad_urls' => array_slice( (array) ( $ctx['sitemap_check']['bad'] ?? array() ), 0, 10 ) ) : null,
				'index_status'  => isset( $ctx['gsc_inspect'] ) ? array_map( function ( $r ) {
					return array( sap_short_url( (string) ( $r['url'] ?? '' ), 70 ), $r['verdict'] ?? '', $r['coverage'] ?? '', ! empty( $r['g_canon'] ) && ! empty( $r['u_canon'] ) && $r['g_canon'] !== $r['u_canon'] ? 'canonical_mismatch' : '' );
				}, array_slice( (array) ( $ctx['gsc_inspect']['rows'] ?? array() ), 0, 15 ) ) : null,
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
	return apply_filters( 'sap_ai_stage_input', null, $project, $stage, $ctx );
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
	$hash = md5( wp_json_encode( $facts ) . '|' . sap_ai_model_for( $def['model'] ?? 'analysis' ) );
	$old  = sap_dataset( $project['id'], $key );
	if ( ! $force && $old && ( $old['meta']['hash'] ?? '' ) === $hash ) {
		return array( 'skipped' => 'داده‌های این بخش از تحلیل قبلی تغییری نکرده؛ نتیجه قبلی نگه داشته شد (بدون هزینه).' );
	}
	if ( 'plan' === $stage ) {
		return sap_ai_plan_run_parts( $project, $input, $hash, $key, $def );
	}
	$task = apply_filters( 'sap_ai_stage_task', $def['task'], $stage );

	// Big inputs go in small batches (one batch per call): no answer comes near the
	// token limit, and each item gets the model's full attention.
	$batch = $def['batch'] ?? null;
	$opt   = 'sap_ai_prog_' . $project['id'] . '_' . $stage;
	if ( $batch && count( (array) ( $input[ $batch['in'] ] ?? array() ) ) > $batch['size'] ) {
		$prog = get_option( $opt );
		if ( ! is_array( $prog ) || ( $prog['hash'] ?? '' ) !== $hash ) {
			$prog = array( 'hash' => $hash, 'offset' => 0, 'out' => array(), 'partial' => 0 );
		}
		$all  = array_values( (array) $input[ $batch['in'] ] );
		$part = $input;
		$part[ $batch['in'] ] = array_slice( $all, $prog['offset'], $batch['size'] );
		$res  = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $part, JSON_UNESCAPED_UNICODE ), $def['schema'], array( 'purpose' => $def['model'] ?? 'analysis', 'max_tokens' => $def['tokens'], 'effort' => 'low' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$prog['out']      = array_merge( $prog['out'], array_values( array_filter( (array) ( $res[ $batch['out'] ] ?? array() ), 'is_array' ) ) );
		$prog['partial'] += empty( $res['_partial'] ) ? 0 : 1;
		$prog['cost']     = (float) ( $prog['cost'] ?? 0 ) + (float) ( sap_ai_usage()['last_cost'] ?? 0 );
		$prog['offset']  += $batch['size'];
		if ( $prog['offset'] < count( $all ) ) {
			update_option( $opt, $prog, false );
			return array( 'more' => true, 'summary_line' => sprintf( '%s از %s مورد تحلیل شد…', sap_num( $prog['offset'] ), sap_num( count( $all ) ) ) );
		}
		delete_option( $opt );
		$res = array( $batch['out'] => $prog['out'], '_partial' => $prog['partial'] > 0 );
	} else {
		$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE ), $def['schema'], array( 'purpose' => $def['model'] ?? 'analysis', 'max_tokens' => $def['tokens'], 'effort' => 'low' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
	}
	$partial = ! empty( $res['_partial'] );
	unset( $res['_partial'] );
	$res = sap_ai_stage_postprocess( $project, $stage, $res, $input );
	if ( $partial ) {
		$res['summary_line'] .= ' (بخشی از پاسخ به سقف طول رسید؛ موارد کامل نگه داشته شد.)';
	}
	sap_dataset_put( $project['id'], $key, $res, array(
		'model' => sap_ai_model_for( $def['model'] ?? 'analysis' ),
		'hash'  => $hash,
		'cost'  => sap_ai_usage()['last_cost'] ?? 0,
		'count' => count( (array) reset( $res ) ),
	) );
	return $res;
}

/* ---------------------------------------------------------------------------
 * Action plan in parts: one long answer (diagnosis + 15 tasks with steps and
 * ready deliverables) reaches the output limit and is cut. The plan is built
 * in four short calls instead — diagnosis, technical, content, growth — each
 * with its own share of the month's hours, then merged.
 * ------------------------------------------------------------------------- */

/**
 * Parts of the action plan: label, work areas, specialist stages, share of hours, max tasks.
 */
function sap_ai_plan_parts() {
	return array(
		'diagnosis' => array( 'label' => 'تشخیص وضعیت پروژه', 'areas' => array(), 'stages' => array(), 'share' => 0, 'max' => 0 ),
		'technical' => array( 'label' => 'کارهای تکنیکال', 'areas' => array( 'technical' ), 'stages' => array( 'technical' ), 'share' => 0.25, 'max' => 5 ),
		'content'   => array( 'label' => 'بهینه‌سازی و آپدیت محتوا', 'areas' => array( 'content_update', 'onpage', 'cro', 'research' ), 'stages' => array( 'content', 'cannibal' ), 'share' => 0.4, 'max' => 6 ),
		'growth'    => array( 'label' => 'لینک داخلی، محتوای جدید و اعتبار', 'areas' => array( 'internal_links', 'content_new', 'offpage', 'management' ), 'stages' => array( 'links' ), 'share' => 0.35, 'max' => 5 ),
	);
}

/**
 * Run the next part of the plan; the step runner calls again while 'more' is set.
 */
function sap_ai_plan_run_parts( $project, $input, $hash, $key, $def ) {
	$parts = sap_ai_plan_parts();
	$names = array_keys( $parts );
	$opt   = 'sap_ai_prog_' . $project['id'] . '_plan';
	$prog  = get_option( $opt );
	if ( ! is_array( $prog ) || ( $prog['hash'] ?? '' ) !== $hash ) {
		$prog = array( 'hash' => $hash, 'i' => 0, 'diag' => array(), 'plan' => array(), 'partial' => 0, 'cost' => 0 );
	}
	$name = $names[ $prog['i'] ];
	$part = $parts[ $name ];
	$cap  = (float) $project['capacity_hours'];
	$base = array_intersect_key( $input, array_flip( array( 'project', 'focuses', 'search_console', 'rank_tracker', 'technical', 'content_sheet' ) ) );
	$learn = sap_impact_feedback( $project['id'] );
	if ( $learn ) {
		$base['impact_feedback'] = $learn; // What worked / did not on this site (measured before / after).
	}
	$done = array_map( function ( $t ) {
		return $t['title'];
	}, $prog['plan'] );
	$this_month = array_column( (array) ( $input['this_month'] ?? array() ), 'title' );
	$item_schema = sap_ai_analysis_schema()['properties']['plan'];
	if ( 'diagnosis' === $name ) {
		$schema = sap_ai_analysis_schema();
		unset( $schema['properties']['plan'] );
		$schema['required'] = array_values( array_diff( $schema['required'], array( 'plan' ) ) );
		$data   = $base + array( 'findings' => array_map( function ( $f ) {
			return array( $f['area'], $f['title'], $f['focus'] );
		}, (array) ( $input['findings'] ?? array() ) ) );
		foreach ( array( 'cannibal', 'content', 'links', 'technical' ) as $st ) {
			if ( ! empty( $input[ 'stage_' . $st ]['summary'] ) ) {
				$data[ 'stage_' . $st ] = $input[ 'stage_' . $st ]['summary'];
			}
		}
		$task   = implode( "\n", array(
			'داده‌های پروژه سئو در JSON زیر است: خلاصه سرچ کنسول، Focusها و یافته‌ها (حوزه، عنوان، Focus).',
			'وضعیت پروژه را تشخیص بده (summary با عدد)، سلامت هر حوزه را نمره ۰ تا ۱۰۰ بده (health)، برای هر Focus یک بینش کلیدی بنویس و نقاط قوت (wins) و ریسک‌ها (risks) را کوتاه فهرست کن. کار و تسک ننویس.',
		) );
		$tokens = 2500;
	} else {
		$schema = sap_ai_obj( array( 'plan' => $item_schema ) );
		$data   = $base;
		$data['findings'] = array_values( array_filter( (array) ( $input['findings'] ?? array() ), function ( $f ) use ( $part ) {
			return in_array( $f['area'], $part['areas'], true );
		} ) );
		foreach ( $part['stages'] as $st ) {
			if ( isset( $input[ 'stage_' . $st ] ) ) {
				$data[ 'stage_' . $st ] = $input[ 'stage_' . $st ];
			}
		}
		if ( 'growth' === $name ) {
			$pool = sap_dataset_payload( $project['id'], 'ideas_pool' );
			if ( $pool ) {
				$data['new_content_candidates'] = array_slice( array_column( (array) ( $pool['blog'] ?? array() ), 'keyword' ), 0, 15 );
			}
		}
		if ( ! $data['findings'] && count( $data ) === count( $base ) ) {
			$prog['i']++; // Nothing for this part.
			return sap_ai_plan_next( $project, $prog, $opt, $key, $hash, $def, $input );
		}
		$hours = max( 2, round( $cap * $part['share'] ) );
		$task  = implode( "\n", array(
			'داده‌های پروژه سئو در JSON زیر است: خلاصه سرچ کنسول، Focusها، یافته‌های قوانین (findings با id) و نتیجه تحلیل تخصصی همین حوزه.',
			'هر جا expert_note آمده، نظر خود متخصص سئو است؛ آن را رعایت کن. impact_feedback نتیجه سنجیده‌شده اقدامات قبلی همین سایت است: نوع کارهایی که رشد آورده‌اند اولویت بگیرند و کارهایی که نتیجه نداده‌اند با روش بهتر یا اصلاح‌شده پیشنهاد شوند.',
			sprintf( 'فقط کارهای حوزه «%s» را برای اکشن پلن این ماه بنویس: حداکثر %s کار مشخص در سقف حدود %s ساعت، مرتب‌شده بر اساس اثر روی Focus و هدف، با هفته پیشنهادی ۱ تا ۴.', $part['label'], sap_num( $part['max'] ), sap_num( $hours ) ),
			'فقط کارهای عملی، مشخص و پراثر: کاری که مبهم است، بیش از ۸ ساعت طول می‌کشد، به منابع بیرون از دسترس تیم سئو نیاز دارد یا اثرش کم است ننویس. هر کار باید به یک صفحه یا گروه مشخص و هدف (Focus) وصل باشد. چند اقدام روی یک صفحه یک کار است با چک‌لیست، نه چند کار.',
			'هر کار: عنوان با نام صفحه/کلمه، دلیل با عدد، مراحل کوتاه، و خروجی آماده در deliverable (تایتل/متا، FAQ، لینک‌ها «مبدأ ← مقصد: انکر»، راه‌حل تکنیکال) — کوتاه و کاربردی. کارهای هم‌نوع کوچک را ادغام کن. idهای finding مرتبط را در finding_ids بگذار.',
			( $this_month || $done ) ? 'این کارها از قبل در پلن هستند؛ تکرار نکن: ' . implode( ' | ', array_slice( array_merge( $this_month, $done ), 0, 30 ) ) : '',
		) );
		$tokens = 4000;
	}
	$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'analysis', 'max_tokens' => $tokens, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$prog['partial'] += empty( $res['_partial'] ) ? 0 : 1;
	$prog['cost']    += (float) ( sap_ai_usage()['last_cost'] ?? 0 );
	unset( $res['_partial'] );
	if ( 'diagnosis' === $name ) {
		$prog['diag'] = $res;
	} else {
		foreach ( (array) ( $res['plan'] ?? array() ) as $it ) {
			if ( is_array( $it ) ) {
				$it['_part']    = $name;
				$prog['plan'][] = $it;
			}
		}
	}
	$prog['i']++;
	return sap_ai_plan_next( $project, $prog, $opt, $key, $hash, $def, $input );
}

/**
 * Save progress and ask for the next part, or merge and store the plan.
 */
function sap_ai_plan_next( $project, $prog, $opt, $key, $hash, $def, $input ) {
	$parts = sap_ai_plan_parts();
	$names = array_keys( $parts );
	if ( $prog['i'] < count( $names ) ) {
		update_option( $opt, $prog, false );
		return array( 'more' => true, 'summary_line' => sprintf( 'اکشن پلن، بخش %s از %s: %s…', sap_num( $prog['i'] + 1 ), sap_num( count( $names ) ), $parts[ $names[ $prog['i'] ] ]['label'] ) );
	}
	delete_option( $opt );
	// Merge: by impact, then week; drop near-duplicate titles.
	$rank = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
	$plan = $prog['plan'];
	usort( $plan, function ( $a, $b ) use ( $rank ) {
		return array( $rank[ $a['impact'] ?? 'medium' ] ?? 1, (int) ( $a['week'] ?? 2 ) ) <=> array( $rank[ $b['impact'] ?? 'medium' ] ?? 1, (int) ( $b['week'] ?? 2 ) );
	} );
	$seen = array();
	$out  = array();
	foreach ( $plan as $it ) {
		$k = sap_norm_text( (string) ( $it['title'] ?? '' ) );
		if ( '' === $k || isset( $seen[ $k ] ) ) {
			continue;
		}
		$seen[ $k ] = true;
		unset( $it['_part'] );
		$out[] = $it;
	}
	$res = sap_ai_stage_postprocess( $project, 'plan', array_merge( (array) $prog['diag'], array( 'plan' => $out ) ), $input );
	if ( $prog['partial'] ) {
		$res['summary_line'] .= ' (پاسخ بخشی به سقف طول رسید؛ موارد کامل نگه داشته شد.)';
	}
	sap_dataset_put( $project['id'], $key, $res, array(
		'model' => sap_ai_model_for( 'analysis' ),
		'hash'  => $hash,
		'cost'  => $prog['cost'],
		'count' => count( $res['plan'] ),
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
		case 'keymap':
			$by_id = array();
			foreach ( sap_keymap( $project['id'] ) as $m ) {
				$by_id[ $m['id'] ] = $m;
			}
			$n = 0;
			foreach ( $list( $res['pages'] ?? array() ) as $p ) {
				$m = $by_id[ (int) ( $p['id'] ?? 0 ) ] ?? null;
				if ( ! $m || '' === trim( (string) ( $p['primary'] ?? '' ) ) ) {
					continue;
				}
				// Drop secondaries whose intent belongs to another page type (rules agree with the prompt).
				$ptype = sap_content_type( array( 'url' => $m['url'], 'page_type' => array_key_exists( $p['page_type'] ?? '', sap_keymap_page_types() ) ? $p['page_type'] : $m['page_type'] ) );
				$moved = array();
				$secs  = array_values( array_filter( $strs( $p['secondary'] ?? array() ), function ( $kw ) use ( $ptype, $m, &$moved ) {
					if ( sap_kw_misfit( $kw, $ptype, $m['title'] ) ) {
						$moved[] = $kw;
						return false;
					}
					return true;
				} ) );
				if ( $moved ) {
					$p['reason'] = trim( (string) ( $p['reason'] ?? '' ) . ' (نیت این کلمات با نوع صفحه نمی‌خواند و حذف شد: ' . implode( '، ', $moved ) . ')' );
				}
				sap_keymap_save( $project['id'], array(
					'url'        => $m['url'],
					'primary_kw' => (string) $p['primary'],
					'secondary'  => array_slice( $secs, 0, 8 ),
					'intent'     => array_key_exists( $p['intent'] ?? '', sap_keymap_intents() ) ? $p['intent'] : $m['intent'],
					'page_type'  => array_key_exists( $p['page_type'] ?? '', sap_keymap_page_types() ) ? $p['page_type'] : $m['page_type'],
					'reason'     => (string) ( $p['reason'] ?? '' ),
					'source'     => 'ai',
				) );
				++$n;
			}
			// Strict ownership / page-type rules, then re-run the detectors with the new map.
			sap_keymap_enforce( $project );
			$run = sap_run_detectors( $project );
			sap_findings_sync( $project['id'], $run['findings'], $run['ran'] );
			return array( 'pages' => array(), 'count' => $n, 'summary_line' => sprintf( 'کلمه اصلی %s صفحه تشخیص داده شد؛ در تب «نقشه کلمات» بررسی و تأیید کنید.', sap_num( $n ) ) );

		case 'focus':
			$by_ref = array();
			foreach ( (array) ( $input['sections'] ?? array() ) as $sec ) {
				$by_ref[ $sec['ref'] ] = $sec;
			}
			$out = array();
			foreach ( $list( $res['focuses'] ?? array() ) as $f ) {
				$f = wp_parse_args( $f, array( 'ref' => '', 'name' => '', 'goal' => '', 'metric' => 'clicks', 'target' => 0, 'months' => 3, 'potential' => 0, 'value' => 0, 'urgency' => 0, 'weight' => 1.5, 'metric_reason' => '', 'rationale' => '', 'first_steps' => array() ) );
				if ( ! isset( $by_ref[ $f['ref'] ] ) || '' === trim( (string) $f['name'] ) ) {
					continue;
				}
				$f['metric']      = array_key_exists( $f['metric'], sap_kpi_metrics() ) ? $f['metric'] : 'clicks';
				$f['weight']      = min( 3, max( 1, (float) $f['weight'] ) );
				$f['months']      = min( 12, max( 1, (int) $f['months'] ) );
				$f['first_steps'] = $strs( $f['first_steps'] );
				$f['existing']    = (bool) $by_ref[ $f['ref'] ]['existing'];
				$f['current']     = $by_ref[ $f['ref'] ]['current'][ $f['metric'] ] ?? null;
				$f['def']         = array(
					'url_patterns' => str_replace( ' ', "\n", (string) $by_ref[ $f['ref'] ]['section'] ),
					'clusters'     => str_replace( '، ', "\n", (string) $by_ref[ $f['ref'] ]['clusters'] ),
					'keywords'     => str_replace( '، ', "\n", (string) $by_ref[ $f['ref'] ]['keywords'] ),
				);
				$out[]            = $f;
			}
			return array( 'focuses' => $out, 'summary_line' => sprintf( 'هدف‌گذاری و اولویت %s بخش سایت پیشنهاد شد.', sap_num( count( $out ) ) ) );

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
			sap_content_ai_to_proposals( $project, $pages );
			return array( 'pages' => $pages, 'summary_line' => sprintf( 'تحلیل محتوای %s صفحه کلیدی (تایتل/متای پیشنهادی، موضوعات جاافتاده، FAQ) — در تب «سئو محتوا» برای تأیید.', sap_num( count( $pages ) ) ) );

		case 'links':
			$links = array();
			foreach ( $list( $res['links'] ?? array() ) as $l ) {
				$links[] = wp_parse_args( $l, array( 'source_url' => '', 'target_url' => '', 'anchor' => '', 'placement' => '', 'reason' => '' ) );
			}
			sap_links_ai_to_proposals( $project, $links );
			return array( 'links' => $links, 'summary_line' => sprintf( '%s پیشنهاد لینک داخلی با انکر و محل قرارگیری (در تب «سئو محتوا» برای تأیید).', sap_num( count( $links ) ) ) );

		case 'technical':
			$issues = array();
			foreach ( $list( $res['issues'] ?? array() ) as $i ) {
				$i         = wp_parse_args( $i, array( 'area' => 'crawl', 'priority' => 'medium', 'title' => '', 'detail' => '', 'fix' => '', 'urls' => array() ) );
				// Models sometimes answer a text field with a list: keep everything as text.
				foreach ( array( 'area', 'priority', 'title', 'detail', 'fix' ) as $fld ) {
					$i[ $fld ] = is_array( $i[ $fld ] ) ? implode( "\n", array_filter( array_map( function ( $x ) {
						return is_scalar( $x ) ? (string) $x : '';
					}, $i[ $fld ] ) ) ) : (string) $i[ $fld ];
				}
				$i['urls'] = $strs( $i['urls'] );
				$issues[]  = $i;
			}
			sap_tech_ai_to_proposals( $project, $issues );
			return array( 'issues' => $issues, 'summary_line' => sprintf( '%s مشکل تکنیکال اولویت‌بندی شد و در تب «سئو تکنیکال» برای تأیید شما آماده است.', sap_num( count( $issues ) ) ) );

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
	return apply_filters( 'sap_ai_stage_postprocess', $res, $project, $stage, $input );
}
