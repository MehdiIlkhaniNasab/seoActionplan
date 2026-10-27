<?php
/**
 * Off-page pillar: a link building plan bound to the project's focus.
 *
 * Inputs: the target keywords of each focus with their position and volume,
 * the monthly budget, media suggested by SEO Signal for those keywords, and
 * the reportage profile of competitors (where they buy links, which pages,
 * which anchors, how much). The AI turns that into a concrete plan — free
 * links, brand mentions, guest posts, reportage on named media with target
 * page, anchor and price within the budget — and each action becomes a
 * proposal for the monthly plan.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Action types of the off-page plan.
 */
function sap_offpage_types() {
	return array(
		'reportage'     => 'رپورتاژ آگهی',
		'guest_post'    => 'گست پست / مقاله مهمان',
		'free_link'     => 'لینک‌سازی رایگان',
		'brand_mention' => 'برند منشن',
		'directory'     => 'دایرکتوری و پروفایل کسب‌وکار',
		'social'        => 'شبکه‌های اجتماعی و ویدیو',
		'digital_pr'    => 'روابط عمومی دیجیتال',
		'broken_link'   => 'لینک‌سازی شکسته',
		'forum_qa'      => 'پرسش و پاسخ و انجمن‌ها',
	);
}

/**
 * Settings of the off-page plan (project options).
 */
function sap_offpage_settings( $project ) {
	$o = (array) ( $project['options']['offpage'] ?? array() );
	return wp_parse_args( $o, array( 'budget' => 0, 'brand' => '', 'notes' => '', 'avoid' => '' ) );
}

/**
 * Target keywords of each focus: focus keywords + pillar keyword, with best
 * position (rank tracker, else Search Console) and volume.
 */
function sap_offpage_targets( $project ) {
	$pid   = $project['id'];
	$ctx   = sap_analysis_context( $project );
	$rt    = array();
	foreach ( (array) ( $ctx['ss_rank']['keywords'] ?? array() ) as $k ) {
		$rt[ sap_kw_key( $k['keyword'] ?? '' ) ] = $k;
	}
	$out = array();
	foreach ( $ctx['focuses'] as $f ) {
		$pillar = sap_focus_pillar( $ctx, $f['id'] );
		$kws    = array_slice( array_values( array_unique( array_filter( array_merge( (array) $f['keywords'], array( (string) ( $ctx['keymap'][ sap_norm_url( (string) $pillar ) ]['primary_kw'] ?? '' ) ) ) ) ) ), 0, 8 );
		$rows   = array();
		foreach ( $kws as $kw ) {
			$best   = sap_ctx_best( $ctx, $kw );
			$r      = $rt[ sap_kw_key( $kw ) ] ?? null;
			$rows[] = array(
				'keyword' => $kw,
				'pos'     => $r && ( $r['end_rank'] ?? $r['rank'] ?? 0 ) ? (float) ( $r['end_rank'] ?? $r['rank'] ) : ( $best ? round( (float) $best['position'], 1 ) : null ),
				'page'    => $r && ! empty( $r['target_url'] ) ? $r['target_url'] : ( $best ? $best['page'] : (string) $pillar ),
				'volume'  => sap_ctx_volume( $ctx, $kw ) ?: (int) ( $r['volume'] ?? 0 ),
			);
		}
		$out[] = array( 'focus' => $f['name'], 'goal' => $f['goal'], 'pillar' => (string) $pillar, 'keywords' => $rows );
	}
	return $out;
}

/**
 * Collect SEO Signal data for the plan: media for each focus' keywords and the
 * reportage profile of the competitors (each request is one quota unit).
 *
 * @param string $what media|competitors.
 */
function sap_offpage_collect( $project, $what ) {
	$data = (array) sap_dataset_payload( $project['id'], 'offpage_data' );
	$msgs = array();
	if ( 'media' === $what ) {
		foreach ( sap_offpage_targets( $project ) as $t ) {
			$kws = array_slice( array_column( $t['keywords'], 'keyword' ), 0, 4 );
			if ( ! $kws ) {
				continue;
			}
			$r = sap_ss_tool( $project, 'rp_suggest', array( 'keywords' => implode( "\n", $kws ) ) );
			if ( is_wp_error( $r ) ) {
				$msgs[] = $t['focus'] . ': ' . $r->get_error_message();
				if ( in_array( $r->get_error_data()['code'] ?? '', array( 'PLAN_NOT_ALLOWED', 'INVALID_API_KEY', 'LOCAL_CAP', 'RATE_LIMIT_EXCEEDED' ), true ) ) {
					break;
				}
				continue;
			}
			$data['media'][ $t['focus'] ] = array( 'keywords' => $kws, 'rows' => array_slice( $r['rows'], 0, 25 ), 'at' => sap_now() );
			$msgs[]                       = sprintf( '%s: %s رسانه', $t['focus'], sap_num( count( $r['rows'] ) ) );
		}
	} else {
		foreach ( array_slice( (array) $project['competitors'], 0, 4 ) as $c ) {
			$r = sap_ss_tool( $project, 'rp_buyer', array( 'domain' => $c, 'report_type' => 'reportages', 'date_range' => 'last_90' ) );
			if ( is_wp_error( $r ) ) {
				$msgs[] = $c . ': ' . $r->get_error_message();
				if ( in_array( $r->get_error_data()['code'] ?? '', array( 'PLAN_NOT_ALLOWED', 'INVALID_API_KEY', 'LOCAL_CAP', 'RATE_LIMIT_EXCEEDED' ), true ) ) {
					break;
				}
				continue;
			}
			$data['competitors'][ $c ] = array( 'rows' => array_slice( $r['rows'], 0, 40 ), 'meta' => $r['meta'], 'at' => sap_now() );
			$msgs[]                    = sprintf( '%s: %s رپورتاژ در ۹۰ روز', $c, sap_num( count( $r['rows'] ) ) );
		}
		if ( ! $project['competitors'] ) {
			$msgs[] = 'هیچ رقیبی برای پروژه تعریف نشده (تب کلمات و رقبا › رقبا).';
		}
	}
	sap_dataset_put( $project['id'], 'offpage_data', $data );
	return implode( ' · ', $msgs ) ?: 'داده‌ای دریافت نشد.';
}

/**
 * Competitor reportage summary: media they use most, spend, anchors, pages.
 */
function sap_offpage_competitor_summary( $data ) {
	$out = array();
	foreach ( (array) ( $data['competitors'] ?? array() ) as $domain => $c ) {
		$media = array();
		$spend = 0;
		foreach ( (array) $c['rows'] as $r ) {
			$h           = (string) ( $r['FromHost'] ?? $r['Domain'] ?? '' );
			$media[ $h ] = ( $media[ $h ] ?? 0 ) + 1;
			$spend      += (int) ( $r['Price'] ?? 0 );
		}
		arsort( $media );
		$out[ $domain ] = array( 'count' => count( (array) $c['rows'] ), 'spend' => $spend, 'media' => array_slice( $media, 0, 8, true ), 'at' => $c['at'] );
	}
	return $out;
}

/**
 * Register the off-page AI stage.
 */
add_filter( 'sap_ai_stages', function ( $stages ) {
	$str = array( 'type' => 'string' );
	$stages['offpage'] = array(
		'label'  => 'برنامه آف‌پیج و رپورتاژ',
		'tokens' => 7000,
		'schema' => sap_ai_obj( array(
			'strategy'      => array( 'type' => 'string', 'description' => 'استراتژی آف‌پیج این ماه در ۳ تا ۵ جمله با استناد به جایگاه کلمات هدف و رقبا' ),
			'budget_split'  => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'channel' => $str, 'amount' => array( 'type' => 'number', 'description' => 'تومان' ), 'why' => $str ) ) ),
			'anchor_policy' => array( 'type' => 'string', 'description' => 'توزیع انکرها (برند، دقیق، ترکیبی، URL، عمومی) با درصد و دلیل' ),
			'actions'       => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'type'        => array( 'type' => 'string', 'enum' => array_keys( sap_offpage_types() ) ),
				'title'       => array( 'type' => 'string', 'description' => 'کار مشخص' ),
				'target_url'  => array( 'type' => 'string', 'description' => 'صفحه‌ای که لینک می‌گیرد' ),
				'keyword'     => array( 'type' => 'string', 'description' => 'کلمه هدف' ),
				'anchor'      => array( 'type' => 'string', 'description' => 'متن انکر دقیق' ),
				'anchor_type' => array( 'type' => 'string', 'enum' => array( 'brand', 'exact', 'partial', 'naked', 'generic' ) ),
				'media'       => array( 'type' => 'string', 'description' => 'دامنه رسانه/سایت یا نوع سایت؛ برای رپورتاژ از media_suggestions' ),
				'price'       => array( 'type' => 'number', 'description' => 'هزینه تقریبی به تومان؛ ۰ برای رایگان' ),
				'week'        => array( 'type' => 'integer', 'description' => '۱ تا ۴' ),
				'priority'    => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
				'why'         => $str,
				'steps'       => array( 'type' => 'array', 'items' => $str ),
			) ) ),
			'kpis'          => array( 'type' => 'array', 'items' => $str, 'description' => 'شاخص‌های سنجش نتیجه تا آخر ماه' ),
		) ),
		'task'   => '',
	);
	return $stages;
} );

add_filter( 'sap_ai_stage_task', function ( $task, $stage ) {
	if ( 'offpage' !== $stage ) {
		return $task;
	}
	return implode( "\n", array(
		'تو استراتژیست ارشد لینک‌سازی و آف‌پیج برای سایت‌های فارسی هستی. برای این ماه یک برنامه آف‌پیج دقیق و قابل اجرا بنویس که کلمات هدف Focusها را به هدفشان برساند.',
		'داده‌ها: targets کلمات هدف هر Focus با جایگاه فعلی، صفحه رتبه‌گرفته و حجم جستجو؛ budget بودجه ماهانه (تومان)؛ media_suggestions رسانه‌های پیشنهادی سئو سیگنال برای همان کلمات (امتیاز، تعداد لینک، میانگین رتبه، قیمت)؛ competitors پروفایل رپورتاژ رقبا (رسانه‌ها، تعداد، هزینه)؛ brand نام برند.',
		'قواعد:',
		'- اولویت با کلماتی است که در جایگاه ۴ تا ۲۰ هستند (نزدیک صفحه اول و پولساز)؛ کلماتی که در ۱ تا ۳ هستند فقط تثبیت لازم دارند.',
		'- مجموع price کارهای پولی از budget بیشتر نشود؛ اگر بودجه صفر است فقط روش‌های رایگان (برند منشن، پروفایل‌ها، پرسش و پاسخ، گست پست رایگان، لینک شکسته، شبکه‌های اجتماعی) بده.',
		'- رپورتاژها را روی رسانه‌های مشخص از media_suggestions (یا رسانه‌هایی که رقبا استفاده کرده‌اند) با صفحه مقصد، کلمه هدف، متن انکر و قیمت بنویس. صفحه مقصد را صفحه رتبه‌گرفته یا پیلار Focus بگذار، نه همیشه صفحه اصلی.',
		'- انکرها را طبیعی و متنوع نگه دار (بیشتر برند و ترکیبی، انکر دقیق محدود)؛ از یک انکر دقیق تکراری برای یک صفحه پرهیز کن.',
		'- کارها را بین ۴ هفته پخش کن و برای هر کدام دلیل و مراحل کوتاه بنویس. ۸ تا ۱۵ کار.',
	) );
}, 10, 2 );

add_filter( 'sap_ai_stage_input', function ( $input, $project, $stage, $ctx ) {
	if ( 'offpage' !== $stage ) {
		return $input;
	}
	$targets = sap_offpage_targets( $project );
	if ( ! $targets ) {
		return null;
	}
	$set  = sap_offpage_settings( $project );
	$data = (array) sap_dataset_payload( $project['id'], 'offpage_data' );
	$media = array();
	foreach ( (array) ( $data['media'] ?? array() ) as $focus => $m ) {
		$media[ $focus ] = array_map( function ( $r ) {
			return array( $r['Domain'] ?? '', $r['Score'] ?? null, $r['Count'] ?? null, $r['RankAvg'] ?? null, $r['Price'] ?? null );
		}, array_slice( (array) $m['rows'], 0, 15 ) );
	}
	return array(
		'site'              => sap_project_domain( $project ),
		'brand'             => $set['brand'] ?: implode( '، ', (array) $project['brand_terms'] ),
		'budget'            => (int) $set['budget'],
		'notes'             => $set['notes'],
		'avoid'             => $set['avoid'],
		'targets'           => $targets,
		'media_suggestions' => array( 'columns' => array( 'domain', 'score', 'links', 'rank_avg', 'price' ), 'by_focus' => $media ),
		'competitors'       => sap_offpage_competitor_summary( $data ),
	);
}, 10, 4 );

add_filter( 'sap_ai_stage_postprocess', function ( $res, $project, $stage, $input ) {
	if ( 'offpage' !== $stage ) {
		return $res;
	}
	$res     = wp_parse_args( (array) $res, array( 'strategy' => '', 'budget_split' => array(), 'anchor_policy' => '', 'actions' => array(), 'kpis' => array() ) );
	$actions = array();
	$items   = array();
	foreach ( array_values( array_filter( (array) $res['actions'], 'is_array' ) ) as $a ) {
		$a = wp_parse_args( $a, array( 'type' => 'free_link', 'title' => '', 'target_url' => '', 'keyword' => '', 'anchor' => '', 'anchor_type' => 'partial', 'media' => '', 'price' => 0, 'week' => 1, 'priority' => 'medium', 'why' => '', 'steps' => array() ) );
		$a['target_url'] = sap_full_url( $project, (string) $a['target_url'] );
		$a['steps']      = array_values( array_map( 'strval', array_filter( (array) $a['steps'], 'is_scalar' ) ) );
		$actions[]       = $a;
		$items[]         = array(
			'key'         => md5( $a['type'] . '|' . $a['title'] . '|' . $a['media'] ),
			'title'       => $a['title'],
			'detail'      => $a['why'],
			'steps'       => $a['steps'],
			'deliverable' => implode( "\n", array_filter( array(
				'نوع: ' . ( sap_offpage_types()[ $a['type'] ] ?? $a['type'] ),
				$a['media'] ? 'رسانه/سایت: ' . $a['media'] : '',
				$a['target_url'] ? 'صفحه مقصد: ' . rawurldecode( $a['target_url'] ) : '',
				$a['anchor'] ? 'انکر: ' . $a['anchor'] . ' (' . $a['anchor_type'] . ')' : '',
				$a['price'] ? 'هزینه تقریبی: ' . sap_num( $a['price'] ) . ' تومان' : 'رایگان',
				'هفته ' . sap_num( $a['week'] ),
			) ) ),
			'context'     => 'offpage',
			'url'         => $a['target_url'],
			'keyword'     => $a['keyword'],
			'priority'    => $a['priority'],
			'effort'      => 'reportage' === $a['type'] ? 1.5 : 1,
			'section'     => $a['type'],
			'kind'        => $a['type'],
			'meta'        => array( 'price' => (float) $a['price'], 'week' => (int) $a['week'] ),
		);
	}
	$res['actions'] = $actions;
	$res['spend']   = array_sum( array_column( $actions, 'price' ) );
	$n              = sap_proposals_put( $project, 'offpage', $items );
	$res['summary_line'] = sprintf( 'برنامه آف‌پیج با %s اقدام (هزینه حدود %s تومان) آماده شد و برای تأیید شما در تب آف‌پیج است.', sap_num( $n ), sap_num( $res['spend'] ) );
	return $res;
}, 10, 4 );
