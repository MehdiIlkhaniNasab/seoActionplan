<?php
/**
 * Page plan: every proposal of one page in one card — a checklist of actions,
 * a brief that fits the kind of page, your own notes (given to the AI too) —
 * and one approval that makes ONE task with the whole brief. Articles and other
 * pages whose content changes also go to the content calendar as an update.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page notes are comments of type «page» keyed by the page address.
 */
add_filter( 'sap_comment_types', function ( $types ) {
	$types[] = 'page';
	return $types;
} );

/**
 * Comment object id of a page (stable number from its normalised address).
 */
function sap_page_comment_id( $url ) {
	return (int) sprintf( '%u', crc32( sap_norm_url( $url ) ) ) & 0x7fffffff;
}

/**
 * Your notes on a page (newest last), as plain lines.
 */
function sap_page_notes( $url ) {
	return array_values( array_filter( array_map( function ( $c ) {
		return trim( (string) $c['body'] );
	}, sap_comments( 'page', sap_page_comment_id( $url ) ) ) ) );
}

/**
 * Content audit rows by page address: url key => [group, row].
 */
function sap_content_index( $project ) {
	static $cache = array();
	if ( isset( $cache[ $project['id'] ] ) ) {
		return $cache[ $project['id'] ];
	}
	$out = array();
	foreach ( sap_content_inventory( $project ) as $g => $rows ) {
		foreach ( $rows as $r ) {
			$out[ sap_norm_url( $r['url'] ) ] = array( $g, $r );
		}
	}
	return $cache[ $project['id'] ] = $out;
}

/**
 * Labels of the kinds of work a page proposal can be.
 */
function sap_page_action_kinds() {
	return array(
		'title_meta'  => array( 'تایتل و متا', 'onpage' ),
		'content'     => array( 'محتوا', 'content_update' ),
		'attributes'  => array( 'ویژگی‌های محصول', 'content_update' ),
		'faq'         => array( 'سوالات متداول', 'content_update' ),
		'schema'      => array( 'اسکیما', 'onpage' ),
		'links'       => array( 'لینک داخلی', 'internal_links' ),
		'cta'         => array( 'نرخ تبدیل', 'cro' ),
		'images'      => array( 'تصاویر', 'onpage' ),
		'merge'       => array( 'ادغام', 'content_update' ),
		'noindex'     => array( 'ایندکس', 'technical' ),
		'keyword_fit' => array( 'کلمه کلیدی', 'research' ),
	);
}

/**
 * The standard checks of a kind of page (what a complete page of that kind has).
 */
function sap_page_type_standard( $group ) {
	$s = array(
		'products'    => array( 'کلمه اصلی (نام دقیق محصول/مدل) در تایتل، H1 و پاراگراف اول', 'توضیحات کامل: کاربرد، مزایا، نحوه استفاده و نگهداری', 'ویژگی‌های ووکامرس کامل (جنس، ابعاد، وزن، رنگ، سازگاری، کشور سازنده)', 'گالری تصاویر با Alt توصیفی', 'اسکیمای Product با Offer و در صورت وجود AggregateRating', 'سوالات متداول (۳ تا ۵ سوال واقعی خریداران)', 'اعتمادسازی: ارسال، گارانتی، مرجوعی', 'لینک به دسته اصلی و محصولات مرتبط' ),
		'product_tax' => array( 'کلمه عمومی خرید (خرید/قیمت X) در تایتل و H1', 'متن معرفی کوتاه بالای محصولات', 'راهنمای خرید جامع پایین صفحه (انواع، نحوه انتخاب، بازه قیمت)', 'سوالات متداول آکاردئونی با اسکیمای FAQ', 'لینک به زیردسته‌ها و مقالات راهنمای خرید', 'اسکیمای BreadcrumbList' ),
		'posts'       => array( 'پوشش کامل نیت جستجوی کلمه اصلی و فرعی', 'ساختار H2/H3 منطقی و فهرست مطالب', 'اطلاعات به‌روز (سال، قیمت، مدل‌ها)', 'لینک داخلی به دسته/محصول پولساز با انکر مناسب و CTA', 'منبع معتبر خارجی', 'سوالات متداول آکاردئونی + اسکیمای FAQ و Article', 'تصاویر با Alt و کپشن' ),
		'post_cat'    => array( 'متن معرفی دسته', 'لینک به مقاله‌های پیلار و صفحات پولساز', 'جلوگیری از ایندکس صفحه‌بندی کم‌ارزش' ),
		'pages'       => array( 'پیام اصلی روشن و CTA بالای صفحه', 'اعتمادسازی و E-E-A-T (نمونه کار، نظرات، مجوزها)', 'کلمه اصلی مناسب در تایتل و H1', 'لینک به دسته‌ها و صفحات مهم', 'راه تماس یا فرم در دسترس' ),
	);
	return $s[ $group ] ?? $s['pages'];
}

/**
 * Brief of one page: what it is, its goal and keywords, the expert diagnosis,
 * ready texts and the standard of its kind.
 */
function sap_page_brief( $project, $url, $findings = array() ) {
	$pid   = $project['id'];
	$k     = sap_norm_url( $url );
	$found = sap_content_index( $project )[ $k ] ?? null;
	$g     = $found ? $found[0] : 'pages';
	$row   = $found ? $found[1] : null;
	$a     = sap_dataset_payload( $pid, 'ai_audit' )['groups'][ $g ][ $k ] ?? null;
	$m     = sap_keymap( $pid )[ $k ] ?? null;
	$focus = $m && $m['focus_id'] ? ( wp_list_pluck( sap_focuses( $pid ), 'name', 'id' )[ $m['focus_id'] ] ?? '' ) : '';
	return array(
		'group'     => $g,
		'type'      => $row ? ( sap_content_types()[ $row['type'] ] ?? $row['type'] ) : '',
		'title'     => $row['title'] ?? ( sap_page_title( $pid, $url ) ?: sap_short_url( $url, 60 ) ),
		'url'       => $row['url'] ?? $url,
		'focus'     => $focus,
		'keyword'   => $row['kw'] ?? ( $m['primary_kw'] ?? '' ),
		'secondary' => array_slice( (array) ( $row['secondary'] ?? ( $m['secondary'] ?? array() ) ), 0, 8 ),
		'queries'   => array_slice( (array) ( $row['queries'] ?? array() ), 0, 5 ),
		'clicks'    => (int) ( $row['clicks'] ?? 0 ),
		'impr'      => (int) ( $row['impr'] ?? 0 ),
		'score'     => $row['score'] ?? null,
		'checks'    => array_column( (array) ( $row['checks'] ?? array() ), 0 ),
		'diagnosis' => (string) ( $a['diagnosis'] ?? '' ),
		'new_title' => (string) ( $a['title'] ?? '' ),
		'new_meta'  => (string) ( $a['meta'] ?? '' ),
		'update'    => (string) ( $a['update_brief'] ?? '' ),
		'kw_fit'    => (array) ( $a['keyword_fit'] ?? array() ),
		'standard'  => sap_page_type_standard( $g ),
		'notes'     => sap_page_notes( $url ),
		'recent'    => array_slice( array_values( array_unique( array_column( sap_recent_work( $pid )[ $k ] ?? array(), 'what' ) ) ), 0, 4 ),
	);
}

/**
 * The brief as plain text (task notes, calendar reason).
 */
function sap_page_brief_text( $b, $findings ) {
	$kinds = sap_page_action_kinds();
	$out   = array();
	$out[] = sprintf( 'صفحه: %s (%s)', $b['title'], rawurldecode( $b['url'] ) );
	if ( '' !== $b['type'] ) {
		$out[] = 'نوع صفحه: ' . $b['type'];
	}
	if ( '' !== $b['focus'] ) {
		$out[] = 'هدف (Focus): ' . $b['focus'];
	}
	if ( '' !== $b['keyword'] ) {
		$out[] = 'کلمه اصلی: ' . $b['keyword'] . ( $b['secondary'] ? ' · فرعی: ' . implode( '، ', $b['secondary'] ) : '' );
	}
	if ( $b['queries'] ) {
		$out[] = 'کوئری‌های واقعی: ' . implode( '، ', array_map( function ( $q ) {
			return $q[0] . ' (جایگاه ' . sap_num( $q[1], 1 ) . ')';
		}, $b['queries'] ) );
	}
	if ( '' !== $b['diagnosis'] ) {
		$out[] = "\nتشخیص:\n" . $b['diagnosis'];
	}
	$out[] = "\nچک‌لیست اقدامات:";
	foreach ( $findings as $n => $f ) {
		$kind  = $f['evidence']['kind'] ?? '';
		$out[] = sprintf( '%s. [%s] %s', sap_num( $n + 1 ), $kinds[ $kind ][0] ?? sap_context_label( $f['context'] ), $f['title'] );
		$det   = trim( (string) ( $f['evidence']['deliverable'] ?? '' ) ) ?: trim( preg_replace( '/\n+تشخیص:.*$/su', '', (string) $f['detail'] ) );
		if ( '' !== $det ) {
			$out[] = '   ' . str_replace( "\n", "\n   ", $det );
		}
	}
	if ( '' !== $b['new_title'] || '' !== $b['new_meta'] ) {
		$out[] = "\nمتن آماده:" . ( '' !== $b['new_title'] ? "\nتایتل: " . $b['new_title'] : '' ) . ( '' !== $b['new_meta'] ? "\nمتا: " . $b['new_meta'] : '' );
	}
	if ( '' !== $b['update'] ) {
		$out[] = "\nبریف آپدیت محتوا:\n" . $b['update'];
	}
	$out[] = "\nاستاندارد این نوع صفحه (بعد از انجام، همه را چک کنید):\n- " . implode( "\n- ", $b['standard'] );
	if ( $b['notes'] ) {
		$out[] = "\nدیدگاه من:\n- " . implode( "\n- ", $b['notes'] );
	}
	return implode( "\n", $out );
}

/**
 * Open content proposals grouped by page: url key => [url, findings].
 * Proposals without a page are returned under the key ''.
 */
function sap_proposals_by_page( $items ) {
	$out = array();
	foreach ( $items as $f ) {
		$k = '' === (string) $f['url'] ? '' : sap_norm_url( $f['url'] );
		if ( ! isset( $out[ $k ] ) ) {
			$out[ $k ] = array( 'url' => (string) $f['url'], 'items' => array(), 'score' => 0 );
		}
		$out[ $k ]['items'][] = $f;
		$out[ $k ]['score']   = max( $out[ $k ]['score'], (float) $f['score'] );
	}
	uasort( $out, function ( $a, $b ) {
		return $b['score'] <=> $a['score'];
	} );
	return $out;
}

/**
 * Approve the chosen actions of one page: one task with the whole brief, the
 * proposals marked planned, and an update in the content calendar when the
 * page's content changes (articles always).
 *
 * @return array|WP_Error {task_id, calendar}
 */
function sap_page_plan_commit( $project, $url, $ids, $period ) {
	$pid      = $project['id'];
	$k        = sap_norm_url( $url );
	$findings = array();
	foreach ( array_map( 'absint', (array) $ids ) as $id ) {
		$f = sap_finding( $id );
		if ( $f && $f['project_id'] === $pid && 'open' === $f['status'] && sap_norm_url( $f['url'] ) === $k ) {
			$findings[] = $f;
		}
	}
	if ( ! $findings ) {
		return new WP_Error( 'sap_page_plan', 'هیچ اقدامی از این صفحه انتخاب نشده.' );
	}
	$b       = sap_page_brief( $project, $url, $findings );
	$kinds   = sap_page_action_kinds();
	$content = 'posts' === $b['group'];
	$ctx     = array();
	$effort  = 0;
	$focus   = 0;
	foreach ( $findings as $f ) {
		$kind = $f['evidence']['kind'] ?? '';
		$c    = $kinds[ $kind ][1] ?? $f['context'];
		$ctx[ $c ] = ( $ctx[ $c ] ?? 0 ) + (float) $f['effort'];
		$effort   += (float) $f['effort'];
		$focus     = $focus ?: (int) $f['focus_id'];
		$content   = $content || in_array( $kind, array( 'content', 'faq', 'attributes', 'merge' ), true );
	}
	arsort( $ctx );
	$context = (string) key( $ctx );
	$score   = max( array_map( function ( $f ) {
		return (float) $f['score'];
	}, $findings ) );
	$labels  = array_unique( array_map( function ( $f ) use ( $kinds ) {
		return $kinds[ $f['evidence']['kind'] ?? '' ][0] ?? sap_context_label( $f['context'] );
	}, $findings ) );
	$verb    = 'posts' === $b['group'] ? 'آپدیت مقاله' : 'بهینه‌سازی صفحه';
	$task_id = sap_task_save( array(
		'project_id' => $pid,
		'focus_id'   => $focus,
		'finding_id' => $findings[0]['id'],
		'title'      => sprintf( '%s «%s» (%s)', $verb, $b['title'], implode( '، ', array_slice( $labels, 0, 4 ) ) ),
		'notes'      => sap_page_brief_text( $b, $findings ),
		'context'    => $context ?: 'content_update',
		'status'     => 'next',
		'period'     => $period,
		'effort'     => min( 8, max( 0.25, $effort ) ),
		'score'      => $score,
		'url'        => $b['url'],
		'keyword'    => $b['keyword'],
		'source'     => 'ai',
	) );
	foreach ( $findings as $f ) {
		sap_finding_set_status( $f['id'], 'planned', $task_id );
	}
	$cal = '';
	if ( $content ) {
		$upd = sap_update_plan( $project, $b['url'], $period, array( 'reason' => sap_page_brief_text( $b, $findings ), 'source' => 'audit' ) );
		if ( $upd ) {
			sap_update_save( array( 'task_id' => $task_id ), $upd );
			$cal = sap_calendar_sync_sheet( sap_project( $pid ) );
		}
	}
	return array( 'task_id' => $task_id, 'calendar' => $content, 'msg' => sprintf( '«%s» با %s اقدام به اکشن پلن %s اضافه شد%s', $b['title'], sap_num( count( $findings ) ), sap_period_label( $period ), $content ? ' و در تقویم محتوا (آپدیت) ثبت شد' . $cal : '' ) );
}

/* ---------------------------------------------------------------------------
 * Title / meta straight to the site (connector), with before → after
 * ------------------------------------------------------------------------- */

/**
 * Current and proposed SEO title / meta of a title_meta proposal.
 *
 * @return array|null {url, before:{title,meta}, after:{title,meta}}
 */
function sap_finding_seo_change( $f ) {
	if ( 'title_meta' !== ( $f['evidence']['kind'] ?? '' ) || '' === (string) $f['url'] ) {
		return null;
	}
	$d = (string) ( $f['evidence']['deliverable'] ?? '' );
	preg_match( '/^تایتل:\s*(.+)$/mu', $d, $t );
	preg_match( '/^متا:\s*(.+)$/mu', $d, $m );
	$after = array( 'title' => trim( $t[1] ?? '' ), 'meta' => trim( $m[1] ?? '' ) );
	if ( '' === $after['title'] && '' === $after['meta'] ) {
		return null;
	}
	$pid   = $f['project_id'];
	$it    = sap_site_item( $pid, $f['url'] );
	$page  = sap_dataset_payload( $pid, 'pages' )['pages'][ sap_norm_url( $f['url'] ) ] ?? null;
	$crawl = sap_url_ctx( $pid )['crawl'][ sap_norm_url( $f['url'] ) ] ?? null;
	$seo_t = (string) ( $it['seo']['seo_title'] ?? '' );
	$before = array(
		'title' => '' !== $seo_t && false === strpos( $seo_t, '%' ) ? $seo_t : (string) ( $page['title'] ?? ( $crawl['title'] ?? '' ) ),
		'meta'  => (string) ( $it['seo']['meta_description'] ?? '' ) ?: (string) ( $page['meta'] ?? ( $crawl['meta'] ?? '' ) ),
	);
	return array( 'url' => $it['url'] ?? $f['url'], 'before' => $before, 'after' => $after, 'site' => (bool) $it );
}

/**
 * Publish a proposal's title / meta on the client site, log it (so its impact is
 * measured), and keep the finding as a done task. Or roll it back.
 *
 * @return array|WP_Error
 */
function sap_finding_seo_apply( $f, $do = 'publish' ) {
	$project = sap_project( $f['project_id'] );
	if ( ! sap_site_connected( $project ) ) {
		return new WP_Error( 'sap_site', 'اتصال به سایت کارفرما (افزونه رابط) تنظیم نشده.' );
	}
	$ch = sap_finding_seo_change( $f );
	if ( ! $ch ) {
		return new WP_Error( 'sap_seo', 'این پیشنهاد متن آماده تایتل یا متا ندارد.' );
	}
	$ref = 'f' . $f['id'];
	if ( 'rollback' === $do ) {
		$res = sap_site_request( $project, 'POST', 'rollback', array( 'url' => $ch['url'], 'ref' => $ref ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		sap_activity_add( $project['id'], array( 'category' => 'onpage', 'action' => 'seo_rollback', 'title' => 'برگرداندن تایتل و متای «' . ( sap_page_title( $project['id'], $ch['url'] ) ?: sap_short_url( $ch['url'], 50 ) ) . '»', 'url' => $ch['url'], 'source' => 'tool', 'ref' => 'rb:' . $ref ) );
		return array( 'msg' => 'تایتل و متا به مقدار قبلی برگشت.' );
	}
	$changes = array_filter( array( 'seo_title' => $ch['after']['title'], 'meta_description' => $ch['after']['meta'] ) );
	$res     = sap_site_request( $project, 'POST', 'apply', array( 'url' => $ch['url'], 'changes' => $changes, 'mode' => 'publish', 'ref' => $ref, 'note' => $f['title'] ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$name    = sap_page_title( $project['id'], $ch['url'] ) ?: sap_short_url( $ch['url'], 50 );
	$task_id = $f['task_id'] && sap_task( $f['task_id'] ) ? $f['task_id'] : sap_task_save( array(
		'project_id' => $project['id'],
		'focus_id'   => $f['focus_id'],
		'finding_id' => $f['id'],
		'title'      => 'اعمال تایتل و متای جدید «' . $name . '» روی سایت',
		'notes'      => sap_finding_notes( $f ),
		'context'    => 'onpage',
		'status'     => 'next',
		'period'     => sap_period_current(),
		'effort'     => 0.25,
		'score'      => $f['score'],
		'url'        => $ch['url'],
		'keyword'    => $f['keyword'],
		'source'     => 'ai',
	) );
	// Done task → the activity log (with before → after) and impact measurement follow.
	sap_task_save( array( 'status' => 'done', 'result' => trim( sprintf( "اعمال خودکار روی سایت با سکان.\nتایتل: %s ← %s\nمتا: %s ← %s", $ch['before']['title'], $ch['after']['title'] ?: $ch['before']['title'], $ch['before']['meta'], $ch['after']['meta'] ?: $ch['before']['meta'] ) ) ), $task_id );
	return array( 'msg' => 'تایتل و متا روی سایت منتشر شد، در لاگ اقدامات و اکشن پلن (انجام‌شده) ثبت شد؛ اثرش از ۱۰ روز بعد سنجیده می‌شود.', 'task_id' => $task_id );
}
