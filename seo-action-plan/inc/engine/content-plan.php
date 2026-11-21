<?php
/**
 * Content plan of the month, filled slot by slot.
 *
 * The monthly commitments say how many updates and new articles a cycle owes.
 * Each one is a slot the SEO manager fills by hand:
 *
 *  - Update slot: pick the page (searchable list of categories, products and
 *    articles), its keyword and «my view» of the update. The AI rewrites the view
 *    into a clear plan (goal, steps, why); approving puts the page in the content
 *    calendar and its task in the action plan, with where the page stood before
 *    (position, 28-day clicks and impressions).
 *  - New content slot: main keyword, secondary keywords and «my view»; the AI
 *    turns it into title, goal, brief and outline; approving schedules it in the
 *    calendar and makes the writing task.
 *
 * Also here: one open update per page (duplicates of older versions are merged),
 * updates finished by real changes on the site (the change log), and the list of
 * pages handled recently that the update suggestions leave out.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * Slots
 * ------------------------------------------------------------------------- */

/**
 * Update slots of a period: the month's updates and how many are still owed.
 *
 * @return array {target, items[], empty}
 */
function sap_cplan_upd_slots( $project, $period ) {
	$c     = sap_commit( $project );
	$items = array_values( array_filter( sap_updates( $project['id'], array( 'period' => $period ) ), function ( $u ) {
		return 'cancelled' !== $u['status'];
	} ) );
	return array( 'target' => $c['content_upd'], 'items' => $items, 'empty' => max( 0, $c['content_upd'] - count( $items ) ) );
}

/**
 * New content slots of a period.
 *
 * @return array {target, items[], empty}
 */
function sap_cplan_new_slots( $project, $period ) {
	$c     = sap_commit( $project );
	$items = sap_calendar_items( $project['id'], $period );
	return array( 'target' => $c['content_new'], 'items' => $items, 'empty' => max( 0, $c['content_new'] - count( $items ) ) );
}

/**
 * Due date of a new slot: spread over the rest of the period, never in the past.
 */
function sap_cplan_due( $project, $period, $index, $count ) {
	list( $s, $e ) = sap_period_range( $period, $project['id'] );
	$from          = max( $s, current_time( 'Y-m-d' ) );
	if ( $from > $e ) {
		return $e;
	}
	$days = max( 1, ( strtotime( $e ) - strtotime( $from ) ) / DAY_IN_SECONDS + 1 );
	$day  = (int) floor( ( $index + 1 ) * $days / max( 1, $count + 1 ) );
	return sap_no_friday( gmdate( 'Y-m-d', strtotime( $from ) + $day * DAY_IN_SECONDS ) );
}

/* ---------------------------------------------------------------------------
 * Page search for the update slot
 * ------------------------------------------------------------------------- */

/**
 * Pages that can be picked for an update, matched by title, address or keyword.
 *
 * @return array[] {url, title, type, type_l, kw, secondary, clicks, impr, pos, busy}
 */
function sap_cplan_pages( $project, $q = '', $type = '', $limit = 30 ) {
	$pid   = $project['id'];
	$uni   = sap_upd_universe( $project );
	$gsc   = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$gsc[ sap_norm_url( (string) $r['page'] ) ] = $r;
	}
	$q     = trim( sap_norm_text( (string) $q ) );
	$types = array( 'cat' => 'دسته‌بندی', 'product' => 'محصول', 'post' => 'مقاله' );
	$busy  = sap_cplan_handled( $pid );
	$out   = array();
	foreach ( $uni as $k => $p ) {
		if ( $type && $p['type'] !== $type ) {
			continue;
		}
		if ( '' !== $q ) {
			$hay = sap_norm_text( $p['title'] . ' ' . $p['kw'] . ' ' . rawurldecode( $p['url'] ) );
			if ( false === mb_stripos( $hay, $q ) ) {
				continue;
			}
		}
		$g     = $gsc[ $k ] ?? null;
		$out[] = array(
			'url'       => $p['url'],
			'title'     => $p['title'],
			'type'      => $p['type'],
			'type_l'    => $types[ $p['type'] ] ?? '',
			'kw'        => $p['kw'],
			'secondary' => implode( '، ', array_slice( (array) $p['secondary'], 0, 6 ) ),
			'clicks'    => $g ? (int) $g['clicks'] : 0,
			'impr'      => $g ? (int) $g['impressions'] : 0,
			'pos'       => $g && $g['impressions'] ? round( (float) $g['position'], 1 ) : null,
			'busy'      => isset( $busy[ $k ] ) ? $busy[ $k ]['label'] : '',
		);
	}
	// Pages with traffic first: those are the ones worth an update.
	usort( $out, function ( $a, $b ) {
		return array( $b['impr'], $b['clicks'] ) <=> array( $a['impr'], $a['clicks'] );
	} );
	return array_slice( $out, 0, $limit );
}

/* ---------------------------------------------------------------------------
 * «My view» → a clear plan (AI)
 * ------------------------------------------------------------------------- */

/**
 * Rewrite the manager's view of an update into a clear plan.
 *
 * @return array|WP_Error {goal, todo[], why, title, meta, plan}
 */
function sap_cplan_ai_update( $project, $url, $keyword, $secondary, $view ) {
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_cplan', 'هوش مصنوعی در تنظیمات وصل نشده است؛ برنامه را خودتان بنویسید.' );
	}
	$pid   = $project['id'];
	$k     = sap_norm_url( $url );
	$base  = sap_update_baseline( $project, $url, $keyword );
	$card  = sap_upd_cards( $pid )[ $k ] ?? null;
	$it    = sap_site_item( $pid, $url );
	$facts = array(
		'url'          => rawurldecode( $url ),
		'page_title'   => $it ? $it['title'] : sap_page_title( $pid, $url ),
		'page_type'    => $it ? $it['ctype'] : '',
		'seo_title'    => $it ? (string) ( $it['seo']['seo_title'] ?? '' ) : '',
		'words'        => $it ? (int) $it['words'] : 0,
		'keyword'      => $keyword,
		'secondary'    => sap_list( (string) $secondary ),
		'my_view'      => $view,
		'search_28d'   => array( 'clicks' => $base['clicks_before'], 'impressions' => $base['impr_before'], 'avg_position' => $base['rank_before'], 'keyword_position' => $base['kw_pos_before'] ),
		'analysis'     => $card ? array( 'why' => $card['why'], 'draft_plan' => $card['todo'], 'goal' => $card['goal'] ) : null,
		'top_queries'  => $card ? array_map( function ( $q ) {
			return $q['q'] . ' | ' . $q['impr'] . ' نمایش | جایگاه ' . $q['pos'];
		}, array_slice( (array) $card['queries'], 0, 10 ) ) : array(),
	);
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array(
		'goal'  => array( 'type' => 'string', 'description' => 'هدف آپدیت در یک جمله کوتاه و قابل سنجش (مثلاً جایگاه کلمه اصلی از ۱۴ به زیر ۱۰)' ),
		'why'   => array( 'type' => 'string', 'description' => 'چرا این آپدیت لازم است، ۱ تا ۲ جمله با عدد' ),
		'todo'  => array( 'type' => 'array', 'items' => $str, 'description' => '۳ تا ۷ قدم روشن و اجرایی برای نویسنده، به ترتیب اجرا' ),
		'title' => array( 'type' => 'string', 'description' => 'تایتل پیشنهادی ≤ ۶۰ کاراکتر، یا خالی اگر تایتل نیاز به تغییر ندارد' ),
		'meta'  => array( 'type' => 'string', 'description' => 'متا دیسکریپشن ۱۲۰ تا ۱۵۵ کاراکتر، یا خالی' ),
	) );
	$system = implode( "\n", array(
		'تو سرپرست سئو محتوا هستی. مدیر سئو دیدگاه خودش را برای آپدیت یک صفحه نوشته (my_view). آن را به یک برنامه روشن و اجرایی برای نویسنده تبدیل کن.',
		'- دیدگاه مدیر اصل است: چیزی خلاف آن اضافه نکن و نکته‌ای از آن را جا نینداز؛ فقط مرتب، دقیق و قابل اجرا کن.',
		'- اگر داده سرچ کنسول یا تحلیل هست، از آن برای دقیق کردن هدف و قدم‌ها استفاده کن.',
		'- ماهیت صفحه را رعایت کن: دسته‌بندی = کلمه عمومی خرید و راهنمای انتخاب؛ محصول = نام دقیق، ویژگی‌ها، کاربرد؛ مقاله = پاسخ کامل و لینک به صفحه فروش.',
		'- قیمت، تخفیف، موجودی و سال ننویس. فارسی روان با نیم‌فاصله درست.',
	) );
	$res = sap_ai_request( $system, wp_json_encode( $facts, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'light', 'max_tokens' => 1500, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$todo        = array_values( array_filter( array_map( 'strval', (array) ( $res['todo'] ?? array() ) ), 'strlen' ) );
	$lines       = array_map( function ( $t, $i ) {
		return sap_fa( $i + 1 ) . '. ' . $t;
	}, $todo, array_keys( $todo ) );
	foreach ( array( 'title' => 'تایتل پیشنهادی', 'meta' => 'متا پیشنهادی' ) as $f => $l ) {
		if ( '' !== trim( (string) ( $res[ $f ] ?? '' ) ) ) {
			$lines[] = $l . ': ' . trim( $res[ $f ] );
		}
	}
	return array(
		'goal'  => trim( (string) ( $res['goal'] ?? '' ) ),
		'why'   => trim( (string) ( $res['why'] ?? '' ) ),
		'title' => trim( (string) ( $res['title'] ?? '' ) ),
		'meta'  => trim( (string) ( $res['meta'] ?? '' ) ),
		'plan'  => implode( "\n", $lines ),
	);
}

/**
 * Rewrite the manager's view of a new article into title, goal, brief and outline.
 *
 * @return array|WP_Error {title, goal, brief, outline}
 */
function sap_cplan_ai_new( $project, $keyword, $secondary, $view ) {
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_cplan', 'هوش مصنوعی در تنظیمات وصل نشده است؛ بریف را خودتان بنویسید.' );
	}
	$pid   = $project['id'];
	$owner = sap_upd_owners( $pid )[ sap_kw_key( $keyword ) ] ?? '';
	$facts = array(
		'keyword'   => $keyword,
		'secondary' => sap_list( (string) $secondary ),
		'my_view'   => $view,
		'owner'     => $owner ? rawurldecode( $owner ) : '',
	);
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array(
		'title'   => array( 'type' => 'string', 'description' => 'عنوان مقاله با کلمه اصلی در ابتدا، ≤ ۶۵ کاراکتر' ),
		'goal'    => array( 'type' => 'string', 'description' => 'هدف مقاله در یک جمله' ),
		'brief'   => array( 'type' => 'string', 'description' => '۳ تا ۵ جمله برای نویسنده: زاویه، مخاطب، چه چیزی حتماً پوشش داده شود' ),
		'outline' => array( 'type' => 'array', 'items' => $str, 'description' => '۵ تا ۸ تیتر H2/H3 به ترتیب' ),
		'warning' => array( 'type' => 'string', 'description' => 'اگر owner آمده یا کلمه نیت خرید دارد (جایش صفحه فروش است)، هشدار کوتاه؛ وگرنه خالی' ),
	) );
	$system = implode( "\n", array(
		'تو سرپرست محتوای سئو هستی. مدیر سئو کلمه و دیدگاه خودش را برای یک مقاله جدید نوشته (my_view). آن را به بریف روشن برای نویسنده تبدیل کن.',
		'- دیدگاه مدیر اصل است: چیزی خلاف آن اضافه نکن و نکته‌ای از آن را جا نینداز.',
		'- کلمات فرعی داده‌شده را در تیترها و بریف به کار ببر.',
		'- قیمت و سال ننویس. فارسی روان با نیم‌فاصله درست.',
	) );
	$res = sap_ai_request( $system, wp_json_encode( $facts, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'light', 'max_tokens' => 1500, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return array(
		'title'   => trim( (string) ( $res['title'] ?? '' ) ),
		'goal'    => trim( (string) ( $res['goal'] ?? '' ) ),
		'brief'   => trim( (string) ( $res['brief'] ?? '' ) ),
		'outline' => implode( "\n", array_values( array_filter( array_map( 'strval', (array) ( $res['outline'] ?? array() ) ), 'strlen' ) ) ),
		'warning' => trim( (string) ( $res['warning'] ?? '' ) ),
	);
}

/* ---------------------------------------------------------------------------
 * Commit a slot: calendar + action plan
 * ------------------------------------------------------------------------- */

/**
 * The person who does a kind of content work, from the commitments.
 */
function sap_cplan_person( $project, $who ) {
	$c = sap_commit( $project );
	return $c['assign'] ? (int) ( $c['people'][ $who ] ?? 0 ) : 0;
}

/**
 * Put a page in the month's updates with its plan, and make its task.
 *
 * @return array|WP_Error {id, task_id, msg}
 */
function sap_cplan_commit_update( $project, $f ) {
	$pid    = $project['id'];
	$url    = esc_url_raw( trim( (string) ( $f['url'] ?? '' ) ) );
	$period = sap_period_sanitize( (string) ( $f['period'] ?? '' ) ?: sap_period_current( $pid ) );
	if ( '' === $url ) {
		return new WP_Error( 'sap_cplan', 'صفحه را از فهرست انتخاب کنید (یا آدرسش را وارد کنید).' );
	}
	$open = sap_update_open_for( $pid, $url );
	if ( $open ) {
		return new WP_Error( 'sap_cplan', sprintf( 'این صفحه همین حالا یک آپدیت باز در %s دارد («%s»)؛ همان را در تقویم کامل کنید.', sap_period_label( $open['period'] ), sap_update_statuses()[ $open['status'] ] ?? $open['status'] ) );
	}
	$kw   = trim( sanitize_text_field( (string) ( $f['keyword'] ?? '' ) ) );
	$sec  = sap_list( (string) ( $f['secondary'] ?? '' ) );
	$view = trim( sanitize_textarea_field( (string) ( $f['view'] ?? '' ) ) );
	$goal = trim( sanitize_text_field( (string) ( $f['goal'] ?? '' ) ) );
	$plan = trim( sanitize_textarea_field( (string) ( $f['plan'] ?? '' ) ) );
	if ( '' === $view && '' === $plan ) {
		return new WP_Error( 'sap_cplan', 'دیدگاهتان را برای آپدیت این صفحه بنویسید.' );
	}
	$reason = trim( ( '' !== $goal ? 'هدف: ' . $goal . "\n\n" : '' ) . ( '' !== $plan ? "چه کار کنیم:\n" . $plan : "دیدگاه مدیر سئو:\n" . $view ) );
	$brief  = array_filter( array( 'goal' => $goal, 'title' => trim( (string) ( $f['title'] ?? '' ) ), 'meta' => trim( (string) ( $f['meta'] ?? '' ) ) ), 'strlen' );
	$id     = sap_update_plan( $project, $url, $period, array( 'reason' => $reason, 'my_view' => $view, 'keyword' => $kw, 'secondary' => $sec, 'brief' => $brief, 'source' => 'slot', 'priority' => 'high' ) );
	$u      = sap_update( $id );
	$slots  = sap_cplan_upd_slots( $project, $period );
	$tid    = sap_task_save( array(
		'project_id' => $pid,
		'focus_id'   => $u['focus_id'],
		'title'      => 'آپدیت محتوا: «' . $u['title'] . '»',
		'notes'      => trim( ( '' !== $kw ? 'کلمه اصلی: ' . $kw . "\n" : '' ) . ( $sec ? 'کلمات فرعی: ' . implode( '، ', $sec ) . "\n" : '' ) . "\n" . $reason . ( '' !== $view && '' !== $plan ? "\n\nدیدگاه مدیر سئو:\n" . $view : '' ) ),
		'url'        => $u['url'],
		'keyword'    => $kw,
		'context'    => 'content_update',
		'status'     => 'next',
		'period'     => $period,
		'due_date'   => sap_cplan_due( $project, $period, count( $slots['items'] ) - 1, max( count( $slots['items'] ), $slots['target'] ) ),
		'effort'     => 'product' === sap_update_kind( $u ) ? 1.5 : 3,
		'assignee'   => sap_cplan_person( $project, 'update' ),
		'source'     => 'cycle',
		'recurring'  => 'cyc_upd_' . $period . '_u' . $id,
	) );
	sap_update_save( array( 'task_id' => $tid ), $id );
	return array( 'id' => $id, 'task_id' => $tid, 'msg' => sprintf( '«%s» به آپدیت‌های تقویم %s و اکشن پلن اضافه شد', $u['title'], sap_period_label( $period ) ) . sap_calendar_sync_sheet( sap_project( $pid ) ) );
}

/**
 * Schedule a new article from the manager's keyword and view, and make its task.
 *
 * @return array|WP_Error {id, task_id, msg}
 */
function sap_cplan_commit_new( $project, $f ) {
	$pid    = $project['id'];
	$period = sap_period_sanitize( (string) ( $f['period'] ?? '' ) ?: sap_period_current( $pid ) );
	$kw     = trim( sanitize_text_field( (string) ( $f['keyword'] ?? '' ) ) );
	if ( '' === $kw ) {
		return new WP_Error( 'sap_cplan', 'کلمه کلیدی اصلی مقاله را بنویسید.' );
	}
	foreach ( sap_ideas( $pid, array( 'status' => array( 'scheduled', 'published' ) ) ) as $i ) {
		if ( sap_kw_key( $i['keyword'] ) === sap_kw_key( $kw ) ) {
			return new WP_Error( 'sap_cplan', sprintf( 'این کلمه از قبل در تقویم %s هست («%s»).', sap_period_label( $i['period'] ), $i['title'] ?: $i['keyword'] ) );
		}
	}
	$view    = trim( sanitize_textarea_field( (string) ( $f['view'] ?? '' ) ) );
	$sec     = array_map( function ( $s ) {
		return array( $s, 0 );
	}, sap_list( (string) ( $f['secondary'] ?? '' ) ) );
	$outline = array_values( array_filter( array_map( 'trim', preg_split( '/\n+/u', (string) ( $f['outline'] ?? '' ) ) ), 'strlen' ) );
	$brief   = trim( sanitize_textarea_field( (string) ( $f['brief'] ?? '' ) ) ) ?: $view;
	$slot    = count( sap_calendar_items( $pid, $period ) );
	list( $fid ) = function_exists( 'sap_focus_match' ) ? sap_focus_match( sap_focus_context( $pid ), '', $kw ) : array( 0 );
	$id      = sap_idea_save( array(
		'project_id' => $pid,
		'focus_id'   => (int) $fid,
		'keyword'    => $kw,
		'secondary'  => $sec,
		'title'      => trim( (string) ( $f['title'] ?? '' ) ),
		'goal'       => trim( (string) ( $f['goal'] ?? '' ) ),
		'brief'      => $brief,
		'my_view'    => $view,
		'outline'    => $outline,
		'links'      => sap_idea_fill_links( $project, $kw, array() ),
		'origin'     => 'manual',
		'priority'   => 'high',
		'status'     => 'scheduled',
		'period'     => $period,
		'slot'       => $slot + 1,
	) );
	sap_calendar_spread_dates( $pid, $period );
	$idea = sap_idea( $id );
	$tid  = sap_task_save( array(
		'project_id' => $pid,
		'focus_id'   => $idea['focus_id'],
		'title'      => 'نوشتن محتوای جدید: «' . ( $idea['title'] ?: $kw ) . '»',
		'notes'      => trim( 'کلمه اصلی: ' . $kw . ( $sec ? "\nکلمات فرعی: " . implode( '، ', array_column( $sec, 0 ) ) : '' ) . ( '' !== $idea['goal'] ? "\nهدف: " . $idea['goal'] : '' ) . "\n\n" . $brief . ( $outline ? "\n\nسرفصل‌ها:\n- " . implode( "\n- ", $outline ) : '' ) . ( '' !== $view && $view !== $brief ? "\n\nدیدگاه مدیر سئو:\n" . $view : '' ) ),
		'keyword'    => $kw,
		'context'    => 'content_new',
		'status'     => 'next',
		'period'     => $period,
		'due_date'   => $idea['planned_date'] ?: sap_cplan_due( $project, $period, $slot, max( $slot + 1, sap_commit( $project )['content_new'] ) ),
		'effort'     => 3,
		'assignee'   => sap_cplan_person( $project, 'content' ),
		'source'     => 'cycle',
		'recurring'  => 'cyc_new_' . $period . '_i' . $id,
	) );
	sap_idea_save( array( 'task_id' => $tid ), $id );
	return array( 'id' => $id, 'task_id' => $tid, 'msg' => sprintf( '«%s» به تقویم %s و اکشن پلن اضافه شد', $idea['title'] ?: $kw, sap_period_label( $period ) ) . sap_calendar_sync_sheet( sap_project( $pid ) ) );
}

/* ---------------------------------------------------------------------------
 * One open update per page; updates finished by real changes on the site
 * ------------------------------------------------------------------------- */

/**
 * Pages handled recently: an open update (any month), an update finished in the
 * last 90 days, or a content / title-meta change on the site in the last 60 days.
 *
 * @return array url key => {kind: open|done|site, label, date}
 */
function sap_cplan_handled( $project_id ) {
	static $cache = array();
	if ( isset( $cache[ $project_id ] ) ) {
		return $cache[ $project_id ];
	}
	global $wpdb;
	$out = array();
	$set = function ( $url, $kind, $label, $date ) use ( &$out ) {
		$k = sap_norm_url( $url );
		if ( '' === $k || ( isset( $out[ $k ] ) && 'open' === $out[ $k ]['kind'] ) ) {
			return;
		}
		if ( ! isset( $out[ $k ] ) || 'open' === $kind || (string) $date > (string) $out[ $k ]['date'] ) {
			$out[ $k ] = array( 'kind' => $kind, 'label' => $label, 'date' => substr( (string) $date, 0, 10 ) );
		}
	};
	$since90 = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
	foreach ( sap_updates( $project_id ) as $u ) {
		if ( in_array( $u['status'], array( 'planned', 'in_progress' ), true ) ) {
			$set( $u['url'], 'open', sprintf( 'در آپدیت‌های %s (%s)', sap_period_label( $u['period'] ), sap_update_statuses()[ $u['status'] ] ), $u['created_at'] );
		} elseif ( 'done' === $u['status'] && (string) $u['done_at'] >= $since90 ) {
			$set( $u['url'], 'done', 'آپدیت شد ' . sap_jdate( substr( $u['done_at'], 0, 10 ), 'short' ), $u['done_at'] );
		}
	}
	foreach ( sap_ideas( $project_id, array( 'status' => array( 'published' ) ) ) as $i ) {
		$when = $i['published_at'] ?: substr( (string) $i['updated_at'], 0, 10 );
		if ( '' !== $i['published_url'] && $when >= substr( $since90, 0, 10 ) ) {
			$set( $i['published_url'], 'site', 'محتوای جدید تقویم، منتشر شده ' . sap_jdate( $when, 'short' ), $when );
		}
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT title, url, happened_at FROM ' . sap_table( 'activity' ) . " WHERE project_id = %d AND url <> '' AND category IN ('content_update','onpage','content') AND happened_at >= %s ORDER BY happened_at ASC", $project_id, gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	foreach ( (array) $rows as $r ) {
		$set( $r->url, 'site', 'تغییر روی سایت ' . sap_jdate( substr( $r->happened_at, 0, 10 ), 'short' ) . ': ' . mb_strimwidth( (string) $r->title, 0, 70, '…' ), $r->happened_at );
	}
	$cache[ $project_id ] = $out;
	return $out;
}

/**
 * Changes recorded on a page's site (change log) from a date on.
 */
function sap_cplan_site_changes( $project_id, $url, $since ) {
	global $wpdb;
	$k    = sap_norm_url( $url );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, url, category, happened_at, ref FROM ' . sap_table( 'activity' ) . " WHERE project_id = %d AND url <> '' AND category IN ('content_update','onpage','content') AND happened_at >= %s ORDER BY happened_at ASC", $project_id, $since ) ); // phpcs:ignore
	return array_values( array_filter( (array) $rows, function ( $r ) use ( $k ) {
		return sap_norm_url( $r->url ) === $k && 0 !== strpos( (string) $r->ref, 'upd:' );
	} ) );
}

/**
 * Is this page new content rather than a page to update? Its article in the
 * calendar, a new-content task, or published on the site in the last 45 days.
 *
 * @return string Why ('' = not new content).
 */
function sap_url_new_content( $project_id, $url, $task_id = 0 ) {
	$k = sap_norm_url( $url );
	if ( '' === $k ) {
		return '';
	}
	foreach ( sap_ideas( $project_id, array( 'status' => array( 'scheduled', 'published' ) ) ) as $i ) {
		if ( '' !== $i['published_url'] && sap_norm_url( $i['published_url'] ) === $k ) {
			return 'محتوای جدید تقویم «' . ( $i['title'] ?: $i['keyword'] ) . '»';
		}
	}
	$t = $task_id ? sap_task( $task_id ) : null;
	if ( $t && ( 'content_new' === $t['context'] || 0 === strpos( (string) $t['recurring'], 'cyc_new_' ) ) ) {
		return 'کار تولید محتوای جدید';
	}
	$it = function_exists( 'sap_site_item' ) ? sap_site_item( $project_id, $url ) : null;
	if ( $it && '' !== (string) $it['date'] && strtotime( (string) $it['date'] ) > time() - 45 * DAY_IN_SECONDS ) {
		return 'تازه منتشر شده (' . sap_jdate( substr( (string) $it['date'], 0, 10 ), 'short' ) . ')';
	}
	return '';
}

/**
 * «Done» updates the rewrite tool recorded for what was really a new article of
 * the calendar leave the updates (checked on every reconcile: the article's link
 * may be entered after the rewrite was published).
 */
function sap_cplan_repair2( $project_id ) {
	$n = 0;
	foreach ( sap_updates( $project_id, array( 'status' => array( 'done' ) ) ) as $u ) {
		if ( 'rewrite' === $u['source'] && ! $u['task_id'] && '' !== sap_url_new_content( $project_id, $u['url'] ) ) {
			sap_update_save( array( 'status' => 'cancelled' ), $u['id'] );
			++$n;
		}
	}
	return $n;
}

/**
 * One-time repair of what the earlier sheet sync got wrong:
 *  - ideas the sync made from update rows (the page is in the updates, the
 *    «article» is not real) leave the calendar;
 *  - articles the sync dropped as «deleted from the sheet» while their task is
 *    still open in the action plan come back to their month.
 *
 * @return int Ideas changed.
 */
function sap_cplan_repair( $project_id ) {
	$fixed = sap_cplan_repair2( $project_id );
	$flag  = 'sap_cplan_repair1_' . (int) $project_id;
	if ( get_option( $flag ) ) {
		return $fixed;
	}
	update_option( $flag, sap_now(), false );
	$pages = array();
	foreach ( sap_updates( $project_id ) as $u ) {
		if ( 'cancelled' !== $u['status'] ) {
			$pages[ sap_kw_key( $u['keyword'] ) ] = true;
			$pages[ sap_kw_key( $u['title'] ) ]   = true;
			$pages[ sap_norm_url( $u['url'] ) ]   = true;
		}
	}
	unset( $pages[''] );
	$n = 0;
	foreach ( sap_ideas( $project_id ) as $i ) {
		$t    = $i['task_id'] ? sap_task( $i['task_id'] ) : null;
		$live = $t && ! in_array( $t['status'], array( 'dropped' ), true );
		if ( 'sheet' === $i['origin'] && ! $live && in_array( $i['status'], array( 'scheduled', 'approved', 'new' ), true )
			&& ( isset( $pages[ sap_kw_key( $i['keyword'] ) ] ) || ( '' !== $i['published_url'] && isset( $pages[ sap_norm_url( $i['published_url'] ) ] ) ) ) ) {
			sap_idea_save( array( 'status' => 'rejected', 'source_note' => trim( $i['source_note'] . ' | تکراری: این صفحه در آپدیت‌های محتواست، نه مقاله جدید.', ' |' ) ), $i['id'] );
			++$n;
		} elseif ( 'rejected' === $i['status'] && $live ) {
			sap_idea_save( array( 'status' => 'done' === $t['status'] && '' !== $i['published_url'] ? 'published' : 'scheduled', 'period' => $i['period'] ?: (string) $t['period'] ), $i['id'] );
			++$n;
		}
	}
	return $n;
}

/**
 * Bring the month's updates in line with what really happened:
 *  - duplicates of one page (older versions made one per rewrite) are merged
 *    into the oldest one that has a task, the rest are cancelled;
 *  - an open update whose page changed on the site after it was planned is done
 *    (the change is shown on the row);
 *  - a change earlier in the same month is offered as «همین آپدیت بود؟».
 *
 * @return int Updates changed.
 */
function sap_update_reconcile( $project_id ) {
	$n    = sap_cplan_repair( $project_id );
	$open = sap_updates( $project_id, array( 'status' => array( 'planned', 'in_progress' ) ) );
	$by   = array();
	foreach ( $open as $u ) {
		$by[ sap_norm_url( $u['url'] ) ][] = $u;
	}
	foreach ( $by as $list ) {
		if ( count( $list ) < 2 ) {
			continue;
		}
		usort( $list, function ( $a, $b ) {
			$ta = $a['task_id'] && sap_task( $a['task_id'] ) ? 0 : 1;
			$tb = $b['task_id'] && sap_task( $b['task_id'] ) ? 0 : 1;
			return array( $ta, 'slot' === $a['source'] ? 0 : 1, $a['id'] ) <=> array( $tb, 'slot' === $b['source'] ? 0 : 1, $b['id'] );
		} );
		$keep = array_shift( $list );
		foreach ( $list as $dup ) {
			$t = $dup['task_id'] ? sap_task( $dup['task_id'] ) : null;
			if ( $t && ! in_array( $t['status'], array( 'done', 'dropped' ), true ) ) {
				continue; // Someone works on it: leave it.
			}
			sap_update_save( array( 'status' => 'cancelled' ), $dup['id'] );
			++$n;
		}
		unset( $keep );
	}
	foreach ( sap_updates( $project_id, array( 'status' => array( 'planned', 'in_progress' ) ) ) as $u ) {
		list( $ps ) = sap_period_range( $u['period'], $project_id );
		$changes    = sap_cplan_site_changes( $project_id, $u['url'], min( $ps . ' 00:00:00', (string) $u['created_at'] ) );
		if ( ! $changes ) {
			continue;
		}
		$b    = (array) $u['brief'];
		// Reopened by you after an automatic «done»: only changes after that one count.
		$from = ! empty( $b['site_change']['kept'] ) ? (string) $b['site_change']['at'] : '';
		if ( '' !== $from ) {
			$changes = array_values( array_filter( $changes, function ( $r ) use ( $from ) {
				return (string) $r->happened_at > $from;
			} ) );
			if ( ! $changes ) {
				continue;
			}
		}
		$after = array_values( array_filter( $changes, function ( $r ) use ( $u ) {
			return (string) $r->happened_at >= (string) $u['created_at'];
		} ) );
		if ( $after ) {
			$last            = end( $after );
			$b['site_change'] = array( 'title' => (string) $last->title, 'at' => (string) $last->happened_at, 'auto' => 1 );
			sap_update_save( array( 'status' => 'done', 'done_at' => $last->happened_at, 'brief' => $b ), $u['id'] );
			++$n;
			continue;
		}
		$last = end( $changes );
		if ( empty( $b['site_change'] ) || $b['site_change']['at'] !== (string) $last->happened_at ) {
			$b['site_change'] = array( 'title' => (string) $last->title, 'at' => (string) $last->happened_at, 'auto' => 0 );
			sap_update_save( array( 'brief' => $b ), $u['id'] );
		}
	}
	return $n;
}

/**
 * A change on a page finishes its open update right away.
 */
add_action( 'sap_activity_added', function ( $project_id, $fields ) {
	if ( '' === (string) ( $fields['url'] ?? '' ) || ! in_array( $fields['category'] ?? '', array( 'content_update', 'onpage', 'content' ), true ) || 0 === strpos( (string) ( $fields['ref'] ?? '' ), 'upd:' ) ) {
		return;
	}
	if ( sap_update_open_for( $project_id, $fields['url'] ) ) {
		sap_update_reconcile( $project_id );
	}
}, 10, 2 );

/**
 * The update follows its task: work started → in progress, done → done, the task
 * dropped → back to planned (and free for a new task).
 */
add_action( 'sap_task_saved', function ( $before, $after ) {
	if ( ! $after || ( $before && $before['status'] === $after['status'] ) ) {
		return;
	}
	global $wpdb;
	$uid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . sap_table( 'updates' ) . " WHERE task_id = %d AND status IN ('planned','in_progress')", $after['id'] ) ); // phpcs:ignore
	if ( ! $uid ) {
		return;
	}
	if ( in_array( $after['status'], array( 'doing', 'review', 'waiting' ), true ) ) {
		sap_update_save( array( 'status' => 'in_progress' ), $uid );
	} elseif ( 'dropped' === $after['status'] ) {
		sap_update_save( array( 'status' => 'planned', 'task_id' => 0 ), $uid );
	}
}, 9, 2 );

/**
 * Once a day: reconcile every SEO project's updates.
 */
add_action( 'sap_hourly', function () {
	foreach ( sap_projects() as $p ) {
		if ( ! sap_project_is_seo( $p ) || get_option( 'sap_upd_rec_' . $p['id'] ) === current_time( 'Y-m-d' ) ) {
			continue;
		}
		update_option( 'sap_upd_rec_' . $p['id'], current_time( 'Y-m-d' ), false );
		sap_update_reconcile( $p['id'] );
	}
} );

/* ---------------------------------------------------------------------------
 * Calendar cell edits
 * ------------------------------------------------------------------------- */

/**
 * A number typed in Persian or English: «۱۱٫۵» → 11.5, «۱٬۲۰۰» → 1200.
 */
function sap_cplan_number( $value, $decimal = false ) {
	$v = trim( sap_norm_text( (string) $value ) );
	$v = $decimal ? str_replace( array( '٫', '/', ',' ), '.', str_replace( '٬', '', $v ) ) : str_replace( array( '٬', ',', '٫', ' ' ), '', $v );
	return '' === $v ? null : $v;
}

/**
 * Links typed one per line as «address | anchor».
 *
 * @return array[] {url, anchor}
 */
function sap_cplan_links_parse( $project, $text ) {
	$out = array();
	foreach ( preg_split( '/\n+/u', (string) $text ) as $line ) {
		$parts = array_map( 'trim', preg_split( '/\s*[|｜]\s*/u', trim( $line ), 2 ) );
		if ( '' === ( $parts[0] ?? '' ) ) {
			continue;
		}
		$url = preg_match( '#^https?://#i', $parts[0] ) ? $parts[0] : sap_full_url( $project, $parts[0] );
		if ( '' !== $url ) {
			$out[] = array( 'url' => esc_url_raw( $url ), 'anchor' => sanitize_text_field( (string) ( $parts[1] ?? '' ) ) );
		}
	}
	return array_slice( $out, 0, 10 );
}

/**
 * Links as editable text.
 */
function sap_cplan_links_text( $links ) {
	return implode( "\n", array_map( function ( $l ) {
		return rawurldecode( (string) ( $l['url'] ?? '' ) ) . ( '' !== (string) ( $l['anchor'] ?? '' ) ? ' | ' . $l['anchor'] : '' );
	}, (array) $links ) );
}

/**
 * The action-plan task of a calendar row, made when missing (same task the
 * weekly builder would make, so nothing is duplicated).
 *
 * @param string $kind idea|update.
 * @return array|null Task.
 */
function sap_cplan_row_task( $kind, $row, $create = true ) {
	$t = $row['task_id'] ? sap_task( $row['task_id'] ) : null;
	if ( $t || ! $create ) {
		return $t;
	}
	$project = sap_project( $row['project_id'] );
	if ( 'update' === $kind ) {
		$tid = sap_task_save( array(
			'project_id' => $row['project_id'],
			'focus_id'   => $row['focus_id'],
			'title'      => 'آپدیت محتوا: «' . $row['title'] . '»',
			'notes'      => trim( ( $row['keyword'] ? 'کلمه اصلی: ' . $row['keyword'] . "\n" : '' ) . $row['reason'] . ( '' !== $row['my_view'] ? "\n\nدیدگاه مدیر سئو:\n" . $row['my_view'] : '' ) ),
			'url'        => $row['url'],
			'keyword'    => $row['keyword'],
			'context'    => 'content_update',
			'status'     => 'next',
			'period'     => $row['period'],
			'effort'     => 'product' === sap_update_kind( $row ) ? 1.5 : 3,
			'assignee'   => sap_cplan_person( $project, 'update' ),
			'source'     => 'cycle',
			'recurring'  => 'cyc_upd_' . $row['period'] . '_u' . $row['id'],
		) );
		sap_update_save( array( 'task_id' => $tid ), $row['id'] );
	} else {
		$sec = array_column( sap_idea_secondary( $row ), 0 );
		$tid = sap_task_save( array(
			'project_id' => $row['project_id'],
			'focus_id'   => $row['focus_id'],
			'title'      => 'نوشتن محتوای جدید: «' . ( $row['title'] ?: $row['keyword'] ) . '»',
			'notes'      => trim( 'کلمه اصلی: ' . $row['keyword'] . ( $sec ? "\nکلمات فرعی: " . implode( '، ', $sec ) : '' ) . ( '' !== $row['goal'] ? "\nهدف: " . $row['goal'] : '' ) . "\n\n" . $row['brief'] . ( $row['outline'] ? "\n\nسرفصل‌ها:\n- " . implode( "\n- ", (array) $row['outline'] ) : '' ) ),
			'keyword'    => $row['keyword'],
			'context'    => 'content_new',
			'status'     => 'next',
			'period'     => $row['period'] ?: sap_period_current( $row['project_id'] ),
			'due_date'   => $row['planned_date'] ?: null,
			'effort'     => 3,
			'assignee'   => sap_cplan_person( $project, 'content' ),
			'source'     => 'cycle',
			'recurring'  => 'cyc_new_' . ( $row['period'] ?: sap_period_current( $row['project_id'] ) ) . '_i' . $row['id'],
		) );
		sap_idea_save( array( 'task_id' => $tid ), $row['id'] );
	}
	return sap_task( $tid );
}

/**
 * Who does a calendar row and where its task stands — set from the calendar,
 * written to the action-plan task (the same task, nothing to set twice).
 *
 * @return array|WP_Error {task view, row status}
 */
function sap_cplan_row_task_set( $kind, $row, $field, $value ) {
	$t = sap_cplan_row_task( $kind, $row );
	if ( ! $t ) {
		return new WP_Error( 'sap_cell', 'کار ساخته نشد.' );
	}
	if ( 'task_assignee' === $field ) {
		sap_task_save( array( 'assignee' => absint( $value ) ), $t['id'] );
	} else {
		$st = sanitize_key( (string) $value );
		if ( ! in_array( $st, array( 'next', 'doing', 'waiting', 'done' ), true ) ) {
			return new WP_Error( 'sap_cell', 'وضعیت نامعتبر است.' );
		}
		sap_task_save( array( 'status' => $st ), $t['id'] );
	}
	return sap_task( $t['id'] );
}

/**
 * Edit one field of a calendar row.
 *
 * @param string $kind idea|update.
 * @return array|WP_Error {ok, value}
 */
function sap_cplan_cell( $kind, $id, $field, $value ) {
	$value = is_string( $value ) ? trim( $value ) : $value;
	if ( in_array( $field, array( 'task_assignee', 'task_status' ), true ) ) {
		$row = 'idea' === $kind ? sap_idea( $id ) : sap_update( $id );
		if ( ! $row ) {
			return new WP_Error( 'sap_cell', 'ردیف پیدا نشد.', array( 'status' => 404 ) );
		}
		$t = sap_cplan_row_task_set( $kind, $row, $field, $value );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$row = 'idea' === $kind ? sap_idea( $id ) : sap_update( $id );
		return array( 'ok' => true, 'row' => $row, 'task' => sap_task_quick_view( $t ) );
	}
	if ( 'idea' === $kind ) {
		$it = sap_idea( $id );
		if ( ! $it ) {
			return new WP_Error( 'sap_cell', 'ردیف پیدا نشد.', array( 'status' => 404 ) );
		}
		$project = sap_project( $it['project_id'] );
		$data    = array();
		switch ( $field ) {
			case 'keyword':
			case 'title':
			case 'brief':
			case 'source_note':
			case 'goal':
			case 'my_view':
				if ( 'keyword' === $field && '' === $value ) {
					return new WP_Error( 'sap_cell', 'کلمه کلیدی خالی نمی‌ماند.' );
				}
				$data[ $field ] = (string) $value;
				break;
			case 'secondary':
				$data['secondary'] = array_map( function ( $s ) {
					return array( $s, 0 );
				}, sap_list( (string) $value ) );
				break;
			case 'kp':
				$data['kp'] = (int) sap_norm_text( (string) $value );
				break;
			case 'links':
				$data['links'] = sap_cplan_links_parse( $project, (string) $value );
				break;
			case 'published_url':
				$data['published_url'] = (string) $value;
				if ( '' !== (string) $value ) {
					$data['status'] = 'published';
				}
				break;
			case 'published_at':
				$d = sap_parse_date( (string) $value );
				if ( '' !== (string) $value && ! $d ) {
					return new WP_Error( 'sap_cell', 'تاریخ را مثل ۱۴۰۵/۰۸/۱۲ بنویسید.' );
				}
				$data['published_at'] = $d ?: null;
				if ( $d ) {
					$data['status'] = 'published';
				}
				break;
			case 'status':
				$data['status'] = 'published' === $value ? 'published' : 'scheduled';
				if ( 'scheduled' === $data['status'] ) {
					$data['published_at'] = null;
				}
				break;
			default:
				return new WP_Error( 'sap_cell', 'این ستون قابل ویرایش نیست.' );
		}
		sap_idea_save( $data, $id );
		if ( isset( $data['status'] ) && 'published' === $data['status'] && 'published' !== $it['status'] ) {
			$fresh = sap_idea( $id );
			sap_track_published( $it['project_id'], $id, $fresh['published_at'] ?: '' );
		}
		return array( 'ok' => true, 'row' => sap_idea( $id ) );
	}
	$u = sap_update( $id );
	if ( ! $u ) {
		return new WP_Error( 'sap_cell', 'ردیف پیدا نشد.', array( 'status' => 404 ) );
	}
	$project = sap_project( $u['project_id'] );
	$data    = array();
	$b       = (array) $u['brief'];
	switch ( $field ) {
		case 'keyword':
		case 'reason':
		case 'source_note':
		case 'my_view':
			$data[ $field ] = (string) $value;
			break;
		case 'title':
		case 'goal':
		case 'meta':
			$b[ $field ]   = (string) $value;
			$data['brief'] = $b;
			break;
		case 'secondary':
			$data['secondary'] = sap_list( (string) $value );
			break;
		case 'links':
			$data['links'] = sap_cplan_links_parse( $project, (string) $value );
			break;
		case 'rank_before':
		case 'kw_pos_before':
		case 'clicks_before':
		case 'impr_before':
			$data[ $field ] = '' === (string) $value ? null : sap_cplan_number( $value, in_array( $field, array( 'rank_before', 'kw_pos_before' ), true ) );
			if ( null !== $data[ $field ] && ! is_numeric( $data[ $field ] ) ) {
				return new WP_Error( 'sap_cell', 'یک عدد بنویسید.' );
			}
			break;
		case 'status':
			if ( ! array_key_exists( (string) $value, sap_update_statuses() ) ) {
				return new WP_Error( 'sap_cell', 'وضعیت نامعتبر است.' );
			}
			$data['status'] = (string) $value;
			if ( 'done' !== $value && ! empty( $b['site_change']['auto'] ) ) {
				$b['site_change']['auto'] = 0; // You reopened it: the change was not the whole update.
				$b['site_change']['kept'] = 1;
				$data['brief']            = $b;
			}
			break;
		case 'done_at':
			$d = sap_parse_date( (string) $value );
			if ( ! $d ) {
				return new WP_Error( 'sap_cell', 'تاریخ را مثل ۱۴۰۵/۰۸/۱۲ بنویسید.' );
			}
			$data['status']  = 'done';
			$data['done_at'] = $d . ' 12:00:00';
			break;
		case 'period':
			$data['period'] = sap_period_sanitize( (string) $value );
			break;
		default:
			return new WP_Error( 'sap_cell', 'این ستون قابل ویرایش نیست.' );
	}
	sap_update_save( $data, $id );
	if ( isset( $data['status'] ) && 'done' === $data['status'] && 'done' !== $u['status'] ) {
		sap_activity_add( $u['project_id'], array( 'category' => 'content_update', 'action' => 'content_updated', 'title' => 'آپدیت محتوای «' . $u['title'] . '»', 'url' => $u['url'], 'source' => 'tool', 'ref' => 'upd:' . $u['id'] ) );
	}
	return array( 'ok' => true, 'row' => sap_update( $id ) );
}

/* ---------------------------------------------------------------------------
 * REST
 * ------------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	$ns    = 'seoactionplan/v1';
	$route = function ( $cb ) {
		return function ( WP_REST_Request $req ) use ( $cb ) {
			$p = sap_project( (int) $req['project'] );
			if ( ! $p ) {
				return new WP_Error( 'sap_cplan', 'پروژه پیدا نشد.', array( 'status' => 404 ) );
			}
			sap_period_project( $p['id'] );
			return $cb( $p, $req );
		};
	};
	register_rest_route( $ns, '/cplan/(?P<project>\d+)/pages', array(
		'methods'             => 'GET',
		'permission_callback' => 'sap_can',
		'callback'            => $route( function ( $p, $req ) {
			return array( 'ok' => true, 'pages' => sap_cplan_pages( $p, (string) $req->get_param( 'q' ), sanitize_key( (string) $req->get_param( 't' ) ) ) );
		} ),
	) );
	register_rest_route( $ns, '/cplan/(?P<project>\d+)/ai', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => $route( function ( $p, $req ) {
			$view = trim( (string) $req->get_param( 'view' ) );
			if ( '' === $view ) {
				return new WP_Error( 'sap_cplan', 'اول دیدگاهتان را بنویسید؛ هوش مصنوعی همان را مرتب و دقیق می‌کند.', array( 'status' => 400 ) );
			}
			if ( 'new' === $req->get_param( 'kind' ) ) {
				if ( '' === trim( (string) $req->get_param( 'keyword' ) ) ) {
					return new WP_Error( 'sap_cplan', 'کلمه کلیدی اصلی را بنویسید.', array( 'status' => 400 ) );
				}
				$r = sap_cplan_ai_new( $p, (string) $req->get_param( 'keyword' ), (string) $req->get_param( 'secondary' ), $view );
			} else {
				if ( '' === trim( (string) $req->get_param( 'url' ) ) ) {
					return new WP_Error( 'sap_cplan', 'اول صفحه را انتخاب کنید.', array( 'status' => 400 ) );
				}
				$r = sap_cplan_ai_update( $p, esc_url_raw( (string) $req->get_param( 'url' ) ), (string) $req->get_param( 'keyword' ), (string) $req->get_param( 'secondary' ), $view );
			}
			return is_wp_error( $r ) ? $r : array( 'ok' => true, 'fields' => $r );
		} ),
	) );
	register_rest_route( $ns, '/cplan/(?P<project>\d+)/commit', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => $route( function ( $p, $req ) {
			$f = array();
			foreach ( array( 'url', 'keyword', 'secondary', 'view', 'goal', 'plan', 'title', 'meta', 'brief', 'outline', 'period' ) as $k ) {
				$f[ $k ] = (string) $req->get_param( $k );
			}
			$r = 'new' === $req->get_param( 'kind' ) ? sap_cplan_commit_new( $p, $f ) : sap_cplan_commit_update( $p, $f );
			return is_wp_error( $r ) ? $r : array( 'ok' => true, 'msg' => $r['msg'] );
		} ),
	) );
	register_rest_route( $ns, '/cell/(?P<kind>idea|update)/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$r = sap_cplan_cell( $req['kind'], (int) $req['id'], sanitize_key( (string) $req->get_param( 'field' ) ), (string) $req->get_param( 'value' ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$pid = 'idea' === $req['kind'] ? $r['row']['project_id'] : $r['row']['project_id'];
			// The sheet follows within a minute (one write for a burst of edits).
			set_transient( 'sap_cal_dirty_' . $pid, 1, HOUR_IN_SECONDS );
			if ( ! wp_next_scheduled( 'sap_cal_sheet_push', array( (int) $pid ) ) ) {
				wp_schedule_single_event( time() + 60, 'sap_cal_sheet_push', array( (int) $pid ) );
			}
			$row = $r['row'];
			$out = array( 'ok' => true, 'msg' => isset( $r['task'] ) ? 'در اکشن پلن هم ثبت شد' : 'ذخیره شد' );
			if ( isset( $r['task'] ) ) {
				$out['task'] = $r['task'];
			}
			$out['row_status'] = (string) $row['status'];
			$out['row_label']  = 'idea' === $req['kind'] ? ( 'published' === $row['status'] ? 'منتشر شد' : 'در تقویم' ) : ( sap_update_statuses()[ $row['status'] ] ?? $row['status'] );
			return $out;
		},
	) );
} );

/**
 * Deferred sheet write after calendar cell edits.
 */
add_action( 'sap_cal_sheet_push', function ( $project_id ) {
	if ( ! get_transient( 'sap_cal_dirty_' . $project_id ) ) {
		return;
	}
	delete_transient( 'sap_cal_dirty_' . $project_id );
	$p = sap_project( (int) $project_id );
	if ( $p ) {
		sap_calendar_sync_sheet( $p );
	}
} );
