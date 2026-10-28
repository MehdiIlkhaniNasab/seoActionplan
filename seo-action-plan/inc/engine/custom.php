<?php
/**
 * Work the SEO manager describes in their own words:
 *
 *  - Custom tasks: a brief («complete the WooCommerce attributes and content
 *    of every product in the shoe-cloth category») becomes executable tasks
 *    with steps, a per-page checklist and the role to hand it to. The pages
 *    the brief is about come from the WordPress inventory.
 *  - Keyword idea: a keyword (and optional secondary keywords / notes) becomes
 *    a full calendar article in the agency structure, links included.
 *  - Rewrites: a content rewrite is part of the month's «content update»
 *    plan, so the calendar the content manager gets lists it too.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pages of the inventory a brief is about: terms named in it and, for a
 * product category / brand, its products.
 *
 * @return array list of {title, url, type, words, attrs, impr}
 */
function sap_custom_pages_for( $project, $brief, $limit = 80 ) {
	$pid   = $project['id'];
	$inv   = sap_site_inventory( $pid );
	$btok  = array_flip( array_filter( sap_tokens( $brief ), function ( $w ) {
		return mb_strlen( $w ) > 2;
	} ) );
	$impr  = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$impr[ sap_norm_url( $r['page'] ) ] = (int) $r['impressions'];
	}
	$terms = array();
	$out   = array();
	foreach ( $inv as $k => $it ) {
		$tt = array_filter( sap_tokens( $it['title'] ), function ( $w ) {
			return mb_strlen( $w ) > 2;
		} );
		if ( ! $tt || array_diff( $tt, array_keys( $btok ) ) ) {
			continue; // Every word of the page name must be in the brief.
		}
		if ( 'term' === $it['kind'] ) {
			$terms[] = $it['title'];
		}
		$out[ $k ] = $it;
	}
	// Products of the named categories / brands.
	if ( $terms ) {
		foreach ( $inv as $k => $it ) {
			if ( 'product' === $it['ctype'] && array_intersect( $terms, (array) ( $it['product']['cats'] ?? array() ) ) ) {
				$out[ $k ] = $it;
			}
		}
	}
	uksort( $out, function ( $a, $b ) use ( $impr ) {
		return ( $impr[ $b ] ?? 0 ) <=> ( $impr[ $a ] ?? 0 );
	} );
	$types = sap_content_types();
	$rows  = array();
	foreach ( array_slice( $out, 0, $limit, true ) as $k => $it ) {
		$rows[] = array(
			'title' => $it['title'],
			'url'   => $it['url'],
			'type'  => $types[ $it['ctype'] ] ?? $it['ctype'],
			'words' => (int) $it['words'],
			'attrs' => isset( $it['product'] ) ? (int) $it['product']['attrs'] : null,
			'impr'  => $impr[ $k ] ?? 0,
		);
	}
	return $rows;
}

/**
 * Turn a brief into task proposals (not saved: shown for approval).
 *
 * @return array|WP_Error {summary, tasks[], pages}
 */
function sap_custom_tasks_draft( $project, $brief, $focus_id = 0 ) {
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید هوش مصنوعی در تنظیمات وارد نشده است.' );
	}
	$brief = trim( (string) $brief );
	if ( mb_strlen( $brief ) < 15 ) {
		return new WP_Error( 'sap_brief', 'توضیح کار را کامل‌تر بنویسید (چه چیزی، روی کدام صفحات، با چه هدفی).' );
	}
	$focus = null;
	foreach ( sap_focuses( $project['id'] ) as $f ) {
		if ( (int) $f['id'] === (int) $focus_id ) {
			$focus = $f;
		}
	}
	$pages = sap_custom_pages_for( $project, $brief );
	$str   = array( 'type' => 'string' );
	$schema = sap_ai_obj( array(
		'summary' => array( 'type' => 'string', 'description' => 'برداشت تو از درخواست در ۱ تا ۲ جمله' ),
		'tasks'   => array( 'type' => 'array', 'items' => sap_ai_obj( array(
			'title'     => array( 'type' => 'string', 'description' => 'عنوان کار مشخص و قابل واگذاری' ),
			'why'       => $str,
			'steps'     => array( 'type' => 'array', 'items' => $str, 'description' => 'مراحل اجرایی دقیق (وردپرس/ووکامرس/Rank Math)' ),
			'checklist' => array( 'type' => 'array', 'items' => $str, 'description' => 'اگر کار روی چند صفحه است: یک خط برای هر صفحه (نام صفحه: چه کاری)؛ وگرنه خالی' ),
			'role'      => array( 'type' => 'string', 'description' => 'به چه کسی واگذار شود: مدیر سئو، نویسنده، مدیر محتوا، کارفرما، طراح، برنامه‌نویس' ),
			'area'      => array( 'type' => 'string', 'enum' => array_keys( sap_contexts() ) ),
			'hours'     => array( 'type' => 'number' ),
			'week'      => array( 'type' => 'integer', 'description' => 'هفته ۱ تا ۴' ),
			'url'       => array( 'type' => 'string', 'description' => 'آدرس صفحه اصلی کار یا خالی' ),
			'keyword'   => $str,
			'depends'   => array( 'type' => 'string', 'description' => 'پیش‌نیاز (مثلاً «دریافت اطلاعات از کارفرما») یا خالی' ),
		) ) ),
	) );
	$task = implode( "\n", array(
		'تو مدیر پروژه سئو هستی. متخصص سئو کاری را با زبان خودش توضیح داده؛ آن را به کارهای اجرایی، مشخص و قابل واگذاری تبدیل کن.',
		'- کارها را به ترتیب اجرا بچین و وابستگی‌ها را بنویس (مثلاً اول گرفتن اطلاعات از کارفرما، بعد ثبت ویژگی‌ها در ووکامرس، بعد نوشتن محتوا).',
		'- اگر کار روی چند صفحه است و pages آمده، checklist را با نام همان صفحه‌ها پر کن (حداکثر ۴۰ خط)؛ صفحه‌ای از خودت نساز.',
		'- ساعت هر کار را واقع‌بینانه تخمین بزن (کل صفحات را حساب کن). کارهای خیلی ریز را ادغام کن؛ حداکثر ۸ کار.',
		'- حوزه (area) و نقش (role) درست بگذار. خروجی را کوتاه و عملی بنویس.',
	) );
	$input = array(
		'brief'   => $brief,
		'focus'   => $focus ? array( 'name' => $focus['name'], 'goal' => $focus['goal'] ) : null,
		'site'    => sap_project_domain( $project ),
		'pages'   => $pages,
		'month_capacity_hours' => (float) $project['capacity_hours'],
	);
	$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'analysis', 'max_tokens' => 5000, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$tasks = array();
	foreach ( array_values( array_filter( (array) ( $res['tasks'] ?? array() ), 'is_array' ) ) as $t ) {
		$t = wp_parse_args( $t, array( 'title' => '', 'why' => '', 'steps' => array(), 'checklist' => array(), 'role' => '', 'area' => 'content_update', 'hours' => 1, 'week' => 1, 'url' => '', 'keyword' => '', 'depends' => '' ) );
		if ( '' === trim( (string) $t['title'] ) ) {
			continue;
		}
		$t['steps']     = array_values( array_map( 'strval', array_filter( (array) $t['steps'], 'is_scalar' ) ) );
		$t['checklist'] = array_values( array_map( 'strval', array_filter( (array) $t['checklist'], 'is_scalar' ) ) );
		$t['url']       = sap_full_url( $project, (string) $t['url'] );
		$t['area']      = array_key_exists( $t['area'], sap_contexts() ) ? $t['area'] : 'content_update';
		$tasks[]        = $t;
	}
	$draft = array( 'brief' => $brief, 'focus_id' => (int) $focus_id, 'summary' => (string) ( $res['summary'] ?? '' ), 'tasks' => $tasks, 'pages' => count( $pages ), 'at' => sap_now() );
	set_transient( 'sap_custom_draft_' . $project['id'] . '_' . get_current_user_id(), $draft, DAY_IN_SECONDS );
	return $draft;
}

/**
 * Save the chosen draft tasks to a month.
 *
 * @return int Tasks added.
 */
function sap_custom_tasks_commit( $project, $picked, $period ) {
	$key   = 'sap_custom_draft_' . $project['id'] . '_' . get_current_user_id();
	$draft = get_transient( $key );
	if ( ! is_array( $draft ) ) {
		return 0;
	}
	list( $start ) = sap_period_range( $period );
	$n = 0;
	foreach ( $draft['tasks'] as $i => $t ) {
		if ( ! in_array( $i, array_map( 'intval', (array) $picked ), true ) ) {
			continue;
		}
		$notes = array( 'درخواست: ' . $draft['brief'], '', 'چرا: ' . $t['why'] );
		if ( '' !== $t['role'] ) {
			$notes[] = 'مسئول پیشنهادی: ' . $t['role'];
		}
		if ( '' !== $t['depends'] ) {
			$notes[] = 'پیش‌نیاز: ' . $t['depends'];
		}
		if ( $t['steps'] ) {
			$notes[] = '';
			$notes[] = 'مراحل:';
			foreach ( $t['steps'] as $j => $s ) {
				$notes[] = sap_fa( $j + 1 ) . '. ' . $s;
			}
		}
		if ( $t['checklist'] ) {
			$notes[] = '';
			$notes[] = 'چک‌لیست صفحات:';
			foreach ( $t['checklist'] as $c ) {
				$notes[] = '☐ ' . $c;
			}
		}
		$week = min( 4, max( 1, (int) $t['week'] ) );
		sap_task_save( array(
			'project_id' => $project['id'],
			'focus_id'   => $draft['focus_id'],
			'title'      => $t['title'],
			'notes'      => implode( "\n", $notes ),
			'context'    => $t['area'],
			'status'     => 'next',
			'period'     => $period,
			'effort'     => max( 0.25, (float) $t['hours'] ),
			'url'        => $t['url'],
			'keyword'    => $t['keyword'],
			'due_date'   => gmdate( 'Y-m-d', strtotime( $start ) + ( 7 * $week - 1 ) * DAY_IN_SECONDS ),
			'source'     => 'custom',
		) );
		++$n;
	}
	delete_transient( $key );
	return $n;
}

/**
 * A full calendar article from a keyword you give (plus optional secondary
 * keywords and notes), in the same structure as the AI ideas.
 *
 * @return int|WP_Error Idea id.
 */
function sap_idea_from_keyword( $project, $keyword, $secondary, $notes, $period ) {
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید هوش مصنوعی در تنظیمات وارد نشده است.' );
	}
	$keyword = trim( (string) $keyword );
	if ( '' === $keyword ) {
		return new WP_Error( 'sap_kw', 'کلمه کلیدی اصلی را وارد کنید.' );
	}
	$pid  = $project['id'];
	$core = sap_kw_core( $keyword );
	foreach ( sap_ideas( $pid ) as $i ) {
		if ( sap_kw_core( $i['keyword'] ) === $core && in_array( $i['status'], array( 'scheduled', 'published', 'approved', 'new' ), true ) ) {
			return new WP_Error( 'sap_dup', sprintf( 'این کلمه قبلاً در ایده‌ها/تقویم هست: «%s».', $i['title'] ?: $i['keyword'] ) );
		}
	}
	$owner = '';
	foreach ( sap_keymap( $pid ) as $m ) {
		if ( $m['core'] === $core ) {
			$owner = $m['title'] ?: $m['url'];
		}
	}
	$base = sap_ideas_input( $project, 3 );
	$gsc  = array();
	foreach ( sap_keymap_rules_ctx( $project )['by_query'][ $core ] ?? array() as $r ) {
		$gsc[] = array( sap_short_url( $r['page'], 70 ), round( (float) $r['position'], 1 ), (int) $r['impressions'] );
	}
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array(
		'secondary'   => array( 'type' => 'array', 'items' => $str, 'description' => 'کلمات فرعی داده‌شده + تکمیل تا ۸ کلمه هم‌کانسپت' ),
		'title'       => array( 'type' => 'string', 'description' => 'عنوان مقاله با کلمه اصلی در ابتدا' ),
		'intent'      => array( 'type' => 'string', 'enum' => array( 'informational', 'commercial', 'transactional' ) ),
		'goal'        => $str,
		'brief'       => array( 'type' => 'string', 'description' => '۲ تا ۴ جمله: زاویه، مخاطب، چه چیزی پوشش داده شود' ),
		'outline'     => array( 'type' => 'array', 'items' => $str, 'description' => '۵ تا ۸ تیتر H2/H3' ),
		'links'       => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'url' => $str, 'anchor' => $str ) ), 'description' => '۵ لینک داخلی به money_pages با انکر طبیعی' ),
		'external'    => array( 'type' => 'array', 'items' => $str, 'description' => '۲ تا ۳ منبع معتبر خارجی برای استناد (نوع منبع یا عبارت جستجو)' ),
		'faq'         => array( 'type' => 'array', 'items' => $str, 'description' => '۳ تا ۵ سوال متداول' ),
		'source_note' => $str,
		'cluster'     => $str,
		'warning'     => array( 'type' => 'string', 'description' => 'اگر این کلمه نیت خرید دارد یا صفحه موجودی آن را پوشش می‌دهد (کنیبال)، هشدار کوتاه؛ وگرنه خالی' ),
	) );
	$task = implode( "\n", array(
		'تو سرپرست محتوای سئو هستی. متخصص سئو کلمه کلیدی این مقاله را خودش انتخاب کرده؛ بریف کامل مقاله را در ساختار تقویم محتوا آماده کن.',
		'- کلمات فرعی داده‌شده را نگه دار و فقط تکمیل کن. یادداشت متخصص (notes) را دقیق رعایت کن.',
		'- لینک‌ها فقط از money_pages باشند؛ موضوعات existing_articles را تکرار نکن.',
		'- اگر owner آمده، یعنی صفحه دیگری صاحب این کلمه است؛ در warning بگو و زاویه مقاله را طوری بچین که کنیبال نشود.',
	) );
	$input = array(
		'keyword'           => $keyword,
		'secondary_given'   => array_values( array_filter( array_map( 'trim', sap_list( (string) $secondary ) ) ) ),
		'notes'             => (string) $notes,
		'owner'             => $owner,
		'search_console'    => $gsc,
		'focuses'           => $base['focuses'],
		'money_pages'       => $base['money_pages'],
		'existing_articles' => array_slice( $base['existing_articles'], 0, 80 ),
	);
	$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'light', 'max_tokens' => 3000, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$sec_words = array_values( array_unique( array_filter( array_merge( $input['secondary_given'], array_map( 'strval', array_filter( (array) ( $res['secondary'] ?? array() ), 'is_scalar' ) ) ) ) ) );
	$volumes   = array();
	if ( sap_ss_connected() ) {
		$v = sap_ss_volumes( array_merge( array( $keyword ), $sec_words ) );
		if ( ! is_wp_error( $v ) ) {
			$volumes = $v;
		}
	}
	$sec = array();
	foreach ( array_slice( $sec_words, 0, 10 ) as $s ) {
		if ( sap_kw_key( $s ) !== sap_kw_key( $keyword ) ) {
			$sec[] = array( $s, (int) ( $volumes[ sap_kw_key( $s ) ]['volume'] ?? 0 ) );
		}
	}
	$links = array();
	foreach ( (array) ( $res['links'] ?? array() ) as $l ) {
		$u = sap_full_url( $project, (string) ( $l['url'] ?? '' ) );
		if ( '' !== $u && '' !== trim( (string) ( $l['anchor'] ?? '' ) ) && count( $links ) < 5 ) {
			$links[] = array( 'url' => $u, 'anchor' => trim( (string) $l['anchor'] ) );
		}
	}
	$links   = sap_idea_fill_links( $project, $keyword, $links );
	$outline = array_values( array_map( 'strval', array_filter( (array) ( $res['outline'] ?? array() ), 'is_scalar' ) ) );
	$faq     = array_values( array_map( 'strval', array_filter( (array) ( $res['faq'] ?? array() ), 'is_scalar' ) ) );
	$ext     = array_values( array_map( 'strval', array_filter( (array) ( $res['external'] ?? array() ), 'is_scalar' ) ) );
	$brief   = trim( (string) ( $res['brief'] ?? '' ) . ( $faq ? "\nFAQ: " . implode( ' | ', $faq ) : '' ) . ( '' !== trim( (string) $notes ) ? "\nیادداشت شما: " . trim( (string) $notes ) : '' ) );
	$vol     = (int) ( $volumes[ sap_kw_key( $keyword ) ]['volume'] ?? 0 );
	list( $fid ) = sap_focus_match( sap_focus_context( $pid ), '', $keyword );
	$slot    = count( sap_ideas( $pid, array( 'period' => $period, 'status' => 'scheduled' ) ) );
	$id      = sap_idea_save( array(
		'project_id'  => $pid,
		'focus_id'    => $fid,
		'keyword'     => $keyword,
		'secondary'   => $sec,
		'title'       => (string) ( $res['title'] ?? '' ),
		'intent'      => in_array( $res['intent'] ?? '', array( 'informational', 'commercial', 'transactional' ), true ) ? $res['intent'] : 'informational',
		'goal'        => (string) ( $res['goal'] ?? '' ),
		'brief'       => $brief,
		'outline'     => $outline,
		'links'       => $links,
		'source_note' => trim( (string) ( $res['source_note'] ?? '' ) . ( $ext ? ' | منابع: ' . implode( '، ', $ext ) : '' ), ' |' ),
		'cluster'     => (string) ( $res['cluster'] ?? '' ),
		'priority'    => 'high',
		'evidence'    => array_values( array_filter( array( 'کلمه انتخابی متخصص سئو', (string) ( $res['warning'] ?? '' ) ) ) ),
		'volume'      => $vol,
		'kp'          => $vol,
		'origin'      => 'manual',
		'status'      => 'scheduled',
		'period'      => $period,
		'slot'        => $slot + 1,
	) );
	sap_calendar_spread_dates( $pid, $period );
	return $id;
}

/**
 * A content rewrite is a content update of the month: create / advance the
 * update row so the calendar lists it.
 *
 * @param string $state started|approved|published
 */
function sap_update_from_revision( $rev, $state ) {
	$project = sap_project( $rev['project_id'] );
	if ( ! $project || empty( $rev['url'] ) ) {
		return;
	}
	$k  = sap_norm_url( $rev['url'] );
	$id = 0;
	foreach ( sap_updates( $project['id'] ) as $u ) {
		if ( sap_norm_url( $u['url'] ) === $k && 'done' !== $u['status'] && 'cancelled' !== $u['status'] ) {
			$id = $u['id'];
		}
	}
	if ( ! $id ) {
		if ( 'started' !== $state && 'approved' !== $state ) {
			return;
		}
		$id = sap_update_plan( $project, $rev['url'], sap_period_current(), array( 'source' => 'rewrite', 'reason' => 'بازنویسی و آپدیت محتوا با سکان (پیشنهاد تغییرات با هایلایت).' ) );
	}
	$set = array( 'status' => 'published' === $state ? 'done' : 'in_progress' );
	if ( 'approved' === $state && ! empty( $rev['audit']['summary'] ) ) {
		$u            = sap_update( $id );
		$set['reason'] = trim( ( $u['reason'] ?? '' ) . "\nنتیجه ممیزی: " . $rev['audit']['summary'] );
	}
	sap_update_save( $set, $id );
	sap_calendar_sync_sheet( $project );
}
