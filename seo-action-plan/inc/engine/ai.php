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
	foreach ( sap_findings( $pid, array( 'limit' => 45 ) ) as $f ) {
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
 * Run the AI analysis and store it as the "ai_analysis" dataset.
 *
 * @return array|WP_Error The analysis.
 */
function sap_ai_analyze( $project ) {
	$brief = sap_ai_brief( $project );
	if ( empty( $brief['search_console'] ) && empty( $brief['findings'] ) ) {
		return new WP_Error( 'sap_ai_nodata', 'هنوز داده‌ای برای تحلیل نیست؛ اول سرچ کنسول را متصل و همگام‌سازی کنید.' );
	}
	$task = implode( "\n", array(
		'داده‌های پروژه سئو در JSON زیر است: نتایج سرچ کنسول (۲۸ روز)، وضعیت تکنیکال خزش، شیت محتوا و کلاسترینگ، ردیاب رتبه و یافته‌های قوانین تحلیل (findings) که با id مشخص شده‌اند.',
		'۱. وضعیت پروژه را تشخیص بده (summary)، سلامت هر حوزه را نمره بده (health) و برای هر Focus یک بینش کلیدی بنویس.',
		'۲. مهم‌ترین دستاوردها (wins) و ریسک‌ها (risks) را کوتاه فهرست کن.',
		sprintf( '۳. اکشن پلن این ماه را بچین: ۸ تا ۱۵ کار مشخص که جمع زمانشان از %s ساعت بیشتر نشود، مرتب‌شده بر اساس اولویت، با هفته پیشنهادی. هر کار که از یک یا چند finding آمده، idهای آن را در finding_ids بگذار. کارهایی که در this_month هستند را تکرار نکن.', sap_num( $project['capacity_hours'] ) ),
		'برای کارهای تایتل/متا، FAQ و لینک داخلی، خروجی آماده را در deliverable بنویس.',
		'',
		wp_json_encode( $brief, JSON_UNESCAPED_UNICODE ),
	) );
	$res = sap_ai_request( sap_ai_system_prompt(), $task, sap_ai_analysis_schema(), array( 'max_tokens' => 16000, 'effort' => 'medium' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$valid = array_flip( array_column( $brief['findings'], 'id' ) );
	foreach ( $res['plan'] as $i => $item ) {
		$res['plan'][ $i ]['finding_ids'] = array_values( array_filter( array_map( 'intval', (array) $item['finding_ids'] ), function ( $id ) use ( $valid ) {
			return isset( $valid[ $id ] );
		} ) );
		$res['plan'][ $i ]['ref'] = 'ai' . substr( md5( $item['title'] . '|' . $item['url'] ), 0, 14 );
	}
	sap_dataset_put( $project['id'], 'ai_analysis', $res, array(
		'model'  => sap_setting( 'ai_model', 'claude-opus-5-5' ),
		'period' => sap_period_current(),
		'count'  => count( $res['plan'] ),
	) );
	return $res;
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
	if ( $task['url'] ) {
		$crawl = sap_dataset_payload( $project['id'], 'crawl' );
		foreach ( (array) ( $crawl['rows'] ?? array() ) as $r ) {
			if ( sap_norm_url( $r['url'] ) === sap_norm_url( $task['url'] ) ) {
				$page = array( 'title' => $r['title'], 'meta' => $r['meta'], 'h1' => $r['h1'], 'words' => $r['words'], 'inlinks' => $r['inlinks'] );
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
		$queries = array_slice( $queries, 0, 25 );
	}
	$data = array(
		'site'     => sap_project_domain( $project ),
		'task'     => array( 'title' => $task['title'], 'area' => sap_context_label( $task['context'] ), 'notes' => $task['notes'], 'url' => $task['url'], 'keyword' => $task['keyword'] ),
		'finding'  => $finding ? array( 'title' => $finding['title'], 'detail' => $finding['detail'], 'evidence' => $finding['evidence']['items'] ?? array() ) : null,
		'page'     => $page,
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
	$res = sap_ai_request( sap_ai_system_prompt(), $prompt, $schema, array( 'max_tokens' => 8000, 'effort' => 'low', 'timeout' => 180 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$text = trim( $res['output'] );
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
