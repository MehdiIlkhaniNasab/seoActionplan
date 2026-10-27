<?php
/**
 * Content audit & rewrite.
 *
 * 1. The main content of a page is split into blocks (paragraphs, headings,
 *    lists, tables). Header, menus, sidebar and footer are left out.
 * 2. Audit (one AI call): reads the whole text with the page's primary and
 *    secondary keywords, its real Search Console queries and user questions,
 *    and diagnoses what needs updating — tone, wording, grammar, keyword
 *    coverage, missing sections, outdated facts — and scores the page.
 * 3. Rewrite (one AI call per chunk of blocks): returns only the blocks that
 *    change (replace / insert after / delete) with the reason of each edit.
 *    Small calls: no answer reaches the token limit, and long articles work.
 * 4. Review: every edit is shown highlighted against the original; accepted
 *    edits make the new content, which is approved (and in a later phase
 *    published as a draft on the client site).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit types and labels.
 */
function sap_content_edit_types() {
	return array(
		'tone'      => 'لحن',
		'wording'   => 'واژه‌گزینی',
		'grammar'   => 'نگارش و ویرایش',
		'keyword'   => 'کلمه کلیدی',
		'missing'   => 'بخش جدید',
		'outdated'  => 'بروزرسانی اطلاعات',
		'structure' => 'ساختار',
		'eeat'      => 'اعتماد و تخصص',
		'readability' => 'خوانایی',
		'link'      => 'لینک داخلی',
	);
}

function sap_content_score_labels() {
	return array(
		'intent'      => 'تطابق با نیت جستجو',
		'coverage'    => 'پوشش موضوع',
		'keywords'    => 'کلمات کلیدی',
		'readability' => 'خوانایی',
		'tone'        => 'لحن',
		'grammar'     => 'نگارش',
		'eeat'        => 'اعتماد و تخصص',
		'freshness'   => 'بروز بودن',
	);
}

function sap_content_verdicts() {
	return array(
		'keep'    => 'خوب است؛ تغییر جدی لازم نیست',
		'light'   => 'ویرایش سبک',
		'update'  => 'بروزرسانی و تکمیل',
		'rewrite' => 'بازنویسی اساسی',
	);
}

/**
 * Start an audit for a page: read the content and create the revision.
 *
 * @return int|WP_Error revision id
 */
function sap_content_start( $project, $url, $task_id = 0 ) {
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید هوش مصنوعی در تنظیمات وارد نشده است.' );
	}
	$page = sap_page_content_blocks( $url, $project );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	if ( count( $page['blocks'] ) < 2 ) {
		return new WP_Error( 'sap_content_empty', 'محتوای اصلی این صفحه پیدا نشد (ممکن است با جاوااسکریپت ساخته شود). در فاز بعد، محتوا مستقیم از افزونه رابط سایت خوانده می‌شود.' );
	}
	return sap_revision_save( array(
		'project_id' => $project['id'],
		'task_id'    => (int) $task_id,
		'url'        => $url,
		'title'      => sap_clean_title( $page['title'], $page['h1'] ),
		'kind'       => 'content',
		'status'     => 'analyzing',
		'original'   => $page,
		'edits'      => array(),
		'audit'      => array(),
		'progress'   => array( 'step' => 'audit', 'chunk' => 0 ),
		'user_id'    => get_current_user_id(),
	) );
}

/**
 * Facts about the page for the model: keywords, queries, questions, focus goal.
 */
function sap_content_facts( $project, $url ) {
	$pid = $project['id'];
	$k   = sap_norm_url( $url );
	$km  = sap_keymap( $pid )[ $k ] ?? null;
	$q   = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
		if ( sap_norm_url( $r['page'] ) === $k ) {
			$q[] = $r;
		}
	}
	$queries = array();
	foreach ( array_slice( sap_sort( $q, 'impressions' ), 0, 25 ) as $r ) {
		$queries[] = array( $r['query'], $r['impressions'], $r['clicks'], round( $r['position'], 1 ) );
	}
	list( $fid ) = sap_focus_match( sap_focus_context( $pid ), $url, $km['primary_kw'] ?? '' );
	$focus       = $fid ? sap_focus( $fid ) : null;
	$questions   = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'ss_questions' )['focuses'][ $fid ]['rows'] ?? array() ) as $r ) {
		if ( count( $questions ) < 12 ) {
			$questions[] = $r['word'];
		}
	}
	return array(
		'site'             => sap_project_domain( $project ),
		'primary_keyword'  => $km['primary_kw'] ?? '',
		'secondary'        => $km['secondary'] ?? array(),
		'intent'           => $km['intent'] ?? '',
		'page_type'        => $km['page_type'] ?? sap_guess_page_type( $url ),
		'focus_goal'       => $focus ? $focus['goal'] : '',
		'queries'          => array( 'columns' => array( 'query', 'impressions', 'clicks', 'position' ), 'rows' => $queries ),
		'user_questions'   => $questions,
	);
}

/**
 * Chunks of block ids for the rewrite calls (~4500 characters each).
 */
function sap_content_chunks( $blocks, $chars = 4500 ) {
	$chunks = array();
	$cur    = array();
	$size   = 0;
	foreach ( $blocks as $b ) {
		$l = mb_strlen( $b['html'] );
		if ( $cur && $size + $l > $chars ) {
			$chunks[] = $cur;
			$cur      = array();
			$size     = 0;
		}
		$cur[] = $b['id'];
		$size += $l;
	}
	if ( $cur ) {
		$chunks[] = $cur;
	}
	return $chunks;
}

/**
 * Run the next step of a revision (audit, then one rewrite chunk per call).
 *
 * @return array|WP_Error {more: bool, msg: string, pct: int}
 */
function sap_content_step( $rev ) {
	$project = sap_project( $rev['project_id'] );
	$prog    = $rev['progress'];
	$blocks  = (array) ( $rev['original']['blocks'] ?? array() );
	$facts   = sap_content_facts( $project, $rev['url'] );
	if ( ! empty( $rev['original']['site'] ) ) {
		$site              = $rev['original']['site'];
		$facts['wordpress'] = sap_site_ai_facts( array( 'type' => $site['type'] ?? '', 'seo' => $site['seo'] ?? array(), 'details' => array( 'images' => $site['images'] ?? array(), 'product' => $site['product'] ?? null, 'term' => $site['term'] ?? null ) ) );
	}
	$cost    = $rev['cost'];
	$types   = array_keys( sap_content_edit_types() );

	if ( 'audit' === ( $prog['step'] ?? '' ) ) {
		$text = '';
		foreach ( $blocks as $b ) {
			$line = '[' . $b['id'] . '|' . $b['tag'] . '] ' . $b['text'];
			if ( mb_strlen( $text ) + mb_strlen( $line ) > 16000 ) {
				$text .= "\n[…ادامه متن کوتاه شد]";
				break;
			}
			$text .= $line . "\n";
		}
		$schema = sap_ai_obj( array(
			'verdict'          => array( 'type' => 'string', 'enum' => array_keys( sap_content_verdicts() ) ),
			'summary'          => array( 'type' => 'string', 'description' => '۲ تا ۴ جمله: وضعیت محتوا و مهم‌ترین نیاز' ),
			'scores'           => sap_ai_obj( array_map( function () {
				return array( 'type' => 'integer', 'description' => '۰ تا ۱۰' );
			}, sap_content_score_labels() ) ),
			'tone_guide'       => array( 'type' => 'string', 'description' => 'لحن هدف برای بازنویسی (مخاطب، رسمی/صمیمی، شخص فعل)' ),
			'issues'           => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'type'   => array( 'type' => 'string', 'enum' => $types ),
				'block'  => array( 'type' => 'integer', 'description' => 'شماره بلوک یا 0 برای کل متن' ),
				'detail' => array( 'type' => 'string' ),
			) ) ),
			'missing_sections' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'after_block' => array( 'type' => 'integer', 'description' => 'بعد از کدام بلوک اضافه شود' ),
				'heading'     => array( 'type' => 'string' ),
				'brief'       => array( 'type' => 'string', 'description' => 'چه چیزی و چرا (با استناد به کوئری/سوال)' ),
			) ) ),
			'title'            => array( 'type' => 'string', 'description' => 'تایتل سئو پیشنهادی (حداکثر ۶۰ کاراکتر) یا خالی' ),
			'meta'             => array( 'type' => 'string', 'description' => 'متا دیسکریپشن پیشنهادی (۱۲۰ تا ۱۵۵ کاراکتر) یا خالی' ),
		) );
		$prompt = implode( "\n", array(
			'تو ویراستار ارشد محتوای سئو برای وب‌سایت‌های فارسی هستی. محتوای اصلی این صفحه (فقط بدنه مقاله/توضیحات، بدون هدر و فوتر) بلوک‌به‌بلوک با شماره آمده: [شماره|تگ] متن. تصاویر به شکل [تصویر · Alt: …] سر جای خودشان آمده‌اند؛ Alt خالی یا نامناسب و کمبود تصویر را هم ببین. اگر داده wordpress آمده (ویژگی‌ها، قیمت، توضیح کوتاه، گالری محصول یا محصولات دسته)، به آن استناد کن.',
			'با توجه به کلمه کلیدی اصلی و فرعی صفحه، کوئری‌های واقعی سرچ کنسول (کوئری، نمایش، کلیک، جایگاه) و سوالات کاربران، محتوا را ممیزی کن:',
			'- آیا نیت جستجو را برآورده می‌کند؟ چه موضوع، زیرعنوان یا سوالی که کاربران جستجو می‌کنند جا افتاده است؟ (missing_sections، با شماره بلوکی که باید بعد از آن بیاید)',
			'- لحن (یکدست، مناسب مخاطب و نوع صفحه)، واژه‌گزینی (تکرار، کلمات مبهم یا غیرطبیعی، انباشت کلمه کلیدی)، نگارش (نیم‌فاصله، علائم، جمله‌های طولانی و مجهول، غلط املایی)، ساختار (تیترها، پاراگراف‌های طولانی)، اطلاعات قدیمی، اعتماد و تخصص.',
			'- در issues هر مشکل را با شماره بلوک دقیق و توضیح کوتاه بنویس؛ مشکلات جزئی و سلیقه‌ای را نیاور.',
			'- اگر محتوا خوب است، صادقانه verdict=keep بده؛ تغییر برای تغییر پیشنهاد نکن.',
			'',
			'اطلاعات صفحه: ' . wp_json_encode( array( 'url' => $rev['url'], 'title' => $rev['original']['title'] ?? '', 'h1' => $rev['original']['h1'] ?? '', 'meta' => $rev['original']['meta'] ?? '' ) + $facts, JSON_UNESCAPED_UNICODE ),
			'',
			'محتوا:',
			$text,
		) );
		$res = sap_ai_request( sap_ai_system_prompt(), $prompt, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 4000, 'effort' => 'low' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$cost  += (float) ( sap_ai_usage()['last_cost'] ?? 0 );
		$audit  = sap_content_normalize_audit( $res );
		$chunks = 'keep' === $audit['verdict'] && ! $audit['issues'] && ! $audit['missing_sections'] ? array() : sap_content_chunks( $blocks );
		$prog   = array( 'step' => $chunks ? 'rewrite' : 'done', 'chunk' => 0, 'chunks' => $chunks );
		sap_revision_save( array( 'audit' => $audit, 'progress' => $prog, 'cost' => $cost, 'status' => $chunks ? 'analyzing' : 'review' ), $rev['id'] );
		return array( 'more' => (bool) $chunks, 'msg' => $chunks ? sprintf( 'ممیزی انجام شد (%s). بازنویسی بخش ۱ از %s…', sap_content_verdicts()[ $audit['verdict'] ], sap_num( count( $chunks ) ) ) : 'ممیزی انجام شد؛ تغییری لازم نیست.', 'pct' => $chunks ? 15 : 100 );
	}

	if ( 'rewrite' === ( $prog['step'] ?? '' ) ) {
		$audit  = $rev['audit'];
		$ids    = (array) ( $prog['chunks'][ $prog['chunk'] ] ?? array() );
		$in     = array_flip( $ids );
		$part   = array();
		foreach ( $blocks as $b ) {
			if ( isset( $in[ $b['id'] ] ) && empty( $b['locked'] ) ) {
				$part[] = array( 'id' => $b['id'], 'tag' => $b['tag'], 'html' => $b['html'] );
			}
		}
		foreach ( $blocks as $b ) {
			if ( ! empty( $b['locked'] ) ) {
				unset( $in[ $b['id'] ] ); // Gallery, columns, shortcodes…: not editable from here.
			}
		}
		$issues  = array_values( array_filter( (array) $audit['issues'], function ( $i ) use ( $in ) {
			return 0 === (int) $i['block'] || isset( $in[ (int) $i['block'] ] );
		} ) );
		$missing = array_values( array_filter( (array) $audit['missing_sections'], function ( $m ) use ( $in ) {
			return isset( $in[ (int) $m['after_block'] ] );
		} ) );
		$schema  = sap_ai_obj( array( 'edits' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
			'block'  => array( 'type' => 'integer' ),
			'op'     => array( 'type' => 'string', 'enum' => array( 'replace', 'insert_after', 'delete' ) ),
			'html'   => array( 'type' => 'string', 'description' => 'HTML جدید بلوک (برای delete خالی)' ),
			'type'   => array( 'type' => 'string', 'enum' => $types ),
			'reason' => array( 'type' => 'string', 'description' => 'یک جمله کوتاه' ),
		) ) ) ) );
		$prompt = implode( "\n", array(
			'تو ویراستار ارشد محتوای سئوی فارسی هستی. بخشی از محتوای یک صفحه به صورت بلوک‌های HTML (با id) آمده، همراه با نتیجه ممیزی (لحن هدف، مشکلات همین بلوک‌ها، بخش‌های جاافتاده‌ای که باید در این قسمت اضافه شوند) و کلمات کلیدی صفحه.',
			'فقط بلوک‌هایی را که واقعاً نیاز به تغییر دارند برگردان:',
			'- replace: بلوک بازنویسی‌شده؛ معنی، اعداد، نام برند و محصول، قیمت‌ها و لینک‌های موجود (<a href>) را حفظ کن. تگ اصلی بلوک (p، h2، ul…) را نگه دار.',
			'- insert_after: بخش جدید بعد از همان بلوک (یک یا چند تگ: تیتر h2/h3 و پاراگراف یا فهرست) برای missing_sections؛ اطلاعات عمومی و درست بنویس و عدد و ادعای ساختگی ننویس.',
			'- delete: فقط برای تکرار یا مطلب کاملاً بی‌ربط.',
			'- قواعد نگارش: نیم‌فاصله درست (می‌شود، کتاب‌ها)، علائم فارسی (، ؛ ؟)، جمله‌های کوتاه و فعال، بدون انباشت کلمه کلیدی؛ کلمه اصلی و فرعی را طبیعی و در جای مناسب (تیترها و جمله اول) به کار ببر.',
			'- فقط از تگ‌های p, h2, h3, h4, ul, ol, li, strong, em, a, table, tr, td, th, blockquote استفاده کن.',
			'- اگر این بخش مشکلی ندارد، edits را خالی برگردان.',
			'',
			wp_json_encode( array(
				'tone_guide' => $audit['tone_guide'],
				'keywords'   => array( 'primary' => $facts['primary_keyword'], 'secondary' => $facts['secondary'] ),
				'issues'     => $issues,
				'add'        => $missing,
				'blocks'     => $part,
			), JSON_UNESCAPED_UNICODE ),
		) );
		$res = sap_ai_request( sap_ai_system_prompt(), $prompt, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 6000, 'effort' => 'low' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$cost   += (float) ( sap_ai_usage()['last_cost'] ?? 0 );
		$edits   = $rev['edits'];
		$allowed = sap_content_allowed_html();
		foreach ( (array) ( $res['edits'] ?? array() ) as $e ) {
			if ( ! is_array( $e ) || ! isset( $in[ (int) ( $e['block'] ?? 0 ) ] ) ) {
				continue;
			}
			$op   = in_array( $e['op'] ?? '', array( 'replace', 'insert_after', 'delete' ), true ) ? $e['op'] : 'replace';
			$html = 'delete' === $op ? '' : trim( wp_kses( (string) ( $e['html'] ?? '' ), $allowed ) );
			if ( 'delete' !== $op && '' === $html ) {
				continue;
			}
			$edits[] = array(
				'id'     => count( $edits ) + 1,
				'block'  => (int) $e['block'],
				'op'     => $op,
				'html'   => $html,
				'type'   => in_array( $e['type'] ?? '', $types, true ) ? $e['type'] : 'wording',
				'reason' => (string) ( $e['reason'] ?? '' ),
				'accept' => true,
			);
		}
		++$prog['chunk'];
		$done = $prog['chunk'] >= count( $prog['chunks'] );
		if ( $done ) {
			$prog['step'] = 'done';
		}
		sap_revision_save( array( 'edits' => $edits, 'progress' => $prog, 'cost' => $cost, 'status' => $done ? 'review' : 'analyzing' ), $rev['id'] );
		$n = count( $prog['chunks'] );
		return array(
			'more' => ! $done,
			'msg'  => $done ? sprintf( 'تمام شد: %s پیشنهاد تغییر آماده بررسی است.', sap_num( count( $edits ) ) ) : sprintf( 'بازنویسی بخش %s از %s…', sap_num( $prog['chunk'] + 1 ), sap_num( $n ) ),
			'pct'  => (int) round( 15 + 85 * $prog['chunk'] / max( 1, $n ) ),
		);
	}
	return array( 'more' => false, 'msg' => 'تحلیل این صفحه کامل است.', 'pct' => 100 );
}

/**
 * Fill in anything the model left out of the audit.
 */
function sap_content_normalize_audit( $res ) {
	$res             = wp_parse_args( (array) $res, array( 'verdict' => 'update', 'summary' => '', 'scores' => array(), 'tone_guide' => '', 'issues' => array(), 'missing_sections' => array(), 'title' => '', 'meta' => '' ) );
	$res['verdict']  = array_key_exists( $res['verdict'], sap_content_verdicts() ) ? $res['verdict'] : 'update';
	$scores          = array();
	foreach ( array_keys( sap_content_score_labels() ) as $k ) {
		$scores[ $k ] = min( 10, max( 0, (int) ( $res['scores'][ $k ] ?? 0 ) ) );
	}
	$res['scores']   = $scores;
	$types           = sap_content_edit_types();
	$res['issues']   = array_values( array_filter( array_map( function ( $i ) use ( $types ) {
		if ( ! is_array( $i ) || '' === trim( (string) ( $i['detail'] ?? '' ) ) ) {
			return null;
		}
		return array( 'type' => isset( $types[ $i['type'] ?? '' ] ) ? $i['type'] : 'wording', 'block' => (int) ( $i['block'] ?? 0 ), 'detail' => (string) $i['detail'] );
	}, (array) $res['issues'] ) ) );
	$res['missing_sections'] = array_values( array_filter( array_map( function ( $m ) {
		return is_array( $m ) && '' !== trim( (string) ( $m['heading'] ?? '' ) ) ? array( 'after_block' => (int) ( $m['after_block'] ?? 0 ), 'heading' => (string) $m['heading'], 'brief' => (string) ( $m['brief'] ?? '' ) ) : null;
	}, (array) $res['missing_sections'] ) ) );
	foreach ( array( 'summary', 'tone_guide', 'title', 'meta' ) as $k ) {
		$res[ $k ] = is_scalar( $res[ $k ] ) ? (string) $res[ $k ] : '';
	}
	unset( $res['_partial'] );
	return $res;
}

/**
 * The page after the accepted edits: list of blocks {html, edit|null, op}.
 *
 * @param array $accepted Edit ids to apply (null = the stored accept flags).
 */
function sap_content_merge( $rev, $accepted = null ) {
	$by_block = array();
	foreach ( $rev['edits'] as $e ) {
		$on = null === $accepted ? ! empty( $e['accept'] ) : in_array( (int) $e['id'], array_map( 'intval', (array) $accepted ), true );
		if ( $on ) {
			$by_block[ $e['block'] ][] = $e;
		}
	}
	$out = array();
	foreach ( (array) ( $rev['original']['blocks'] ?? array() ) as $b ) {
		$html = $b['html'];
		$add  = array();
		foreach ( $by_block[ $b['id'] ] ?? array() as $e ) {
			if ( 'replace' === $e['op'] ) {
				$html = $e['html'];
			} elseif ( 'delete' === $e['op'] ) {
				$html = '';
			} else {
				$add[] = $e['html'];
			}
		}
		if ( '' !== $html ) {
			$out[] = $html;
		}
		foreach ( $add as $a ) {
			$out[] = $a;
		}
	}
	return implode( "\n\n", $out );
}

/**
 * Word-level diff of two texts as HTML (<del> removed, <ins> added).
 */
function sap_word_diff( $old, $new ) {
	$a = preg_split( '/(\s+)/u', trim( (string) $old ), -1, PREG_SPLIT_NO_EMPTY );
	$b = preg_split( '/(\s+)/u', trim( (string) $new ), -1, PREG_SPLIT_NO_EMPTY );
	$n = count( $a );
	$m = count( $b );
	if ( $n * $m > 250000 ) {
		return '<del>' . esc_html( $old ) . '</del> <ins>' . esc_html( $new ) . '</ins>';
	}
	// LCS table, bottom-up.
	$l = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
	for ( $i = $n - 1; $i >= 0; $i-- ) {
		for ( $j = $m - 1; $j >= 0; $j-- ) {
			$l[ $i ][ $j ] = $a[ $i ] === $b[ $j ] ? $l[ $i + 1 ][ $j + 1 ] + 1 : max( $l[ $i + 1 ][ $j ], $l[ $i ][ $j + 1 ] );
		}
	}
	$out  = array();
	$i    = 0;
	$j    = 0;
	$push = function ( $kind, $word ) use ( &$out ) {
		$last = count( $out ) - 1;
		if ( $last >= 0 && $out[ $last ][0] === $kind ) {
			$out[ $last ][1] .= ' ' . $word;
		} else {
			$out[] = array( $kind, $word );
		}
	};
	while ( $i < $n && $j < $m ) {
		if ( $a[ $i ] === $b[ $j ] ) {
			$push( '=', $a[ $i ] );
			++$i;
			++$j;
		} elseif ( $l[ $i + 1 ][ $j ] >= $l[ $i ][ $j + 1 ] ) {
			$push( '-', $a[ $i++ ] );
		} else {
			$push( '+', $b[ $j++ ] );
		}
	}
	while ( $i < $n ) {
		$push( '-', $a[ $i++ ] );
	}
	while ( $j < $m ) {
		$push( '+', $b[ $j++ ] );
	}
	$html = '';
	foreach ( $out as $o ) {
		$t     = esc_html( $o[1] );
		$html .= ( '=' === $o[0] ? $t : ( '-' === $o[0] ? '<del>' . $t . '</del>' : '<ins>' . $t . '</ins>' ) ) . ' ';
	}
	return trim( $html );
}

/**
 * Plain text of an HTML block (for the diff).
 */
function sap_block_text( $html ) {
	return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( str_replace( '<', ' <', (string) $html ) ), ENT_QUOTES, 'UTF-8' ) ) );
}

/**
 * Approve a revision with the chosen edits; log it for the monthly report.
 */
function sap_content_approve( $rev, $accepted ) {
	$accepted = array_map( 'intval', (array) $accepted );
	$edits    = $rev['edits'];
	foreach ( $edits as &$e ) {
		$e['accept'] = in_array( (int) $e['id'], $accepted, true );
	}
	unset( $e );
	sap_revision_save( array( 'edits' => $edits, 'status' => 'approved' ), $rev['id'] );
	$on     = array_filter( $edits, function ( $e ) {
		return $e['accept'];
	} );
	$counts = array();
	foreach ( $on as $e ) {
		$counts[ $e['type'] ] = ( $counts[ $e['type'] ] ?? 0 ) + 1;
	}
	$types = sap_content_edit_types();
	$parts = array();
	foreach ( $counts as $t => $n ) {
		$parts[] = sap_num( $n ) . ' ' . ( $types[ $t ] ?? $t );
	}
	$task = $rev['task_id'] ? sap_task( $rev['task_id'] ) : null;
	sap_activity_upsert_ref( $rev['project_id'], 'rev:' . $rev['id'], array(
		'focus_id'     => $task['focus_id'] ?? 0,
		'task_id'      => $rev['task_id'],
		'category'     => 'content_update',
		'action'       => 'content_update',
		'title'        => sprintf( 'بروزرسانی محتوای «%s»: %s', $rev['title'], implode( '، ', $parts ) ?: 'بدون تغییر' ),
		'url'          => $rev['url'],
		'before_value' => sprintf( '%s بلوک محتوا', sap_num( count( (array) ( $rev['original']['blocks'] ?? array() ) ) ) ),
		'after_value'  => sprintf( '%s تغییر تأیید شد (%s)', sap_num( count( $on ) ), implode( '، ', $parts ) ),
		'source'       => 'tool',
		'meta'         => array( 'revision' => $rev['id'], 'verdict' => $rev['audit']['verdict'] ?? '' ),
	) );
	if ( $task && '' === trim( $task['result'] ) ) {
		sap_task_save( array( 'result' => sprintf( 'محتوای صفحه ممیزی و بازنویسی شد: %s تغییر تأیید شد (%s).', sap_num( count( $on ) ), implode( '، ', $parts ) ) ), $task['id'] );
	}
	return count( $on );
}

/**
 * Put an approved revision on the client site through the connector:
 * draft (with preview link) → publish → (optional) roll back.
 *
 * @param string $do    draft|publish|discard|rollback.
 * @param array  $extra seo => also apply the suggested SEO title and meta.
 * @return array|WP_Error {msg, preview_url?}
 */
function sap_content_site_action( $rev, $do, $extra = array() ) {
	$project = sap_project( $rev['project_id'] );
	if ( ! sap_site_connected( $project ) ) {
		return new WP_Error( 'sap_site', 'اتصال به سایت کارفرما (افزونه رابط) در تنظیمات پروژه تنظیم نشده.' );
	}
	if ( 'site' !== ( $rev['original']['source'] ?? '' ) ) {
		return new WP_Error( 'sap_site_source', 'این تحلیل از HTML صفحه انجام شده، نه از متن خود وردپرس. «تحلیل دوباره» را بزنید تا متن دقیق از افزونه رابط خوانده شود، بعد منتشر کنید.' );
	}
	$prog = $rev['progress'];
	$site = (array) ( $prog['site'] ?? array() );
	$ref  = 'rev' . $rev['id'];
	$url  = $rev['url'];
	switch ( $do ) {
		case 'draft':
			if ( ! in_array( $rev['status'], array( 'approved', 'published' ), true ) ) {
				return new WP_Error( 'sap_rev_state', 'اول تغییرات را تأیید کنید.' );
			}
			$changes = array( 'content' => sap_content_final_raw( $rev ) );
			if ( ! empty( $extra['seo'] ) ) {
				if ( '' !== ( $rev['audit']['title'] ?? '' ) ) {
					$changes['seo_title'] = $rev['audit']['title'];
				}
				if ( '' !== ( $rev['audit']['meta'] ?? '' ) ) {
					$changes['meta_description'] = $rev['audit']['meta'];
				}
			}
			$res = sap_site_request( $project, 'POST', 'apply', array( 'url' => $url, 'changes' => $changes, 'mode' => 'draft', 'ref' => $ref, 'note' => $rev['title'] ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$prog['site'] = array( 'state' => 'draft', 'ref' => $ref, 'preview' => (string) ( $res['preview_url'] ?? '' ), 'fields' => array_keys( $changes ), 'at' => sap_now() );
			sap_revision_save( array( 'progress' => $prog ), $rev['id'] );
			return array( 'msg' => 'پیش‌نویس روی سایت ساخته شد؛ پیش‌نمایش را ببینید و بعد منتشر کنید.', 'preview_url' => $prog['site']['preview'] );
		case 'publish':
			if ( 'draft' !== ( $site['state'] ?? '' ) ) {
				return new WP_Error( 'sap_rev_state', 'اول پیش‌نویس روی سایت بسازید.' );
			}
			$res = sap_site_request( $project, 'POST', 'publish', array( 'url' => $url ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$prog['site']['state']        = 'published';
			$prog['site']['published_at'] = sap_now();
			sap_revision_save( array( 'progress' => $prog, 'status' => 'published' ), $rev['id'] );
			$task = $rev['task_id'] ? sap_task( $rev['task_id'] ) : null;
			sap_activity_add( $rev['project_id'], array(
				'focus_id'     => $task['focus_id'] ?? 0,
				'task_id'      => $rev['task_id'],
				'category'     => 'content_update',
				'action'       => 'content_publish',
				'title'        => sprintf( 'انتشار بروزرسانی محتوای «%s» روی سایت', $rev['title'] ),
				'url'          => $url,
				'before_value' => sprintf( '%s بلوک محتوای قبلی', sap_num( count( (array) $rev['original']['blocks'] ) ) ),
				'after_value'  => sprintf( '%s تغییر منتشر شد%s', sap_num( count( array_filter( $rev['edits'], function ( $e ) {
					return ! empty( $e['accept'] );
				} ) ) ), in_array( 'seo_title', (array) ( $site['fields'] ?? array() ), true ) ? ' + تایتل و متای جدید' : '' ),
				'source'       => 'tool',
				'ref'          => 'pub:' . $rev['id'],
				'meta'         => array( 'revision' => $rev['id'] ),
			) );
			return array( 'msg' => 'روی سایت منتشر شد و در لاگ اقدامات ثبت شد. بعد از حدود ۱۴ روز اثرش را بررسی کنید.' );
		case 'discard':
			$res = sap_site_request( $project, 'POST', 'discard', array( 'url' => $url ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			unset( $prog['site'] );
			sap_revision_save( array( 'progress' => $prog ), $rev['id'] );
			return array( 'msg' => 'پیش‌نویس روی سایت حذف شد.' );
		case 'rollback':
			$res = sap_site_request( $project, 'POST', 'rollback', array( 'url' => $url, 'ref' => $site['ref'] ?? $ref ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$prog['site']['state'] = 'rolled_back';
			sap_revision_save( array( 'progress' => $prog, 'status' => 'approved' ), $rev['id'] );
			sap_activity_add( $rev['project_id'], array(
				'task_id'  => $rev['task_id'],
				'category' => 'content_update',
				'action'   => 'content_rollback',
				'title'    => sprintf( 'برگرداندن محتوای «%s» به نسخه قبل', $rev['title'] ),
				'url'      => $url,
				'source'   => 'tool',
				'meta'     => array( 'revision' => $rev['id'] ),
			) );
			return array( 'msg' => 'محتوا به نسخه قبل برگشت.' );
	}
	return new WP_Error( 'sap_do', 'عملیات نامعتبر.' );
}
