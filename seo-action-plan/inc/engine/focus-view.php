<?php
/**
 * «دیدگاه من»: the SEO manager writes their own view on what the project should
 * focus on; the AI weighs it against the project data, says whether it agrees and
 * why, what it would change, the expected effect, and proposes a ready focus
 * (categories / pages, metric, target, deadline) that can be created in one click.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clicks of each category of the site (the category page + its products), last
 * 28 days and the 28 days before. One pass over the products.
 *
 * @return array[] {url, title, ctype, products, clicks, prev, impr}
 */
function sap_focus_category_stats( $project_id ) {
	$inv  = sap_site_inventory( $project_id );
	$gp   = sap_dataset_payload( $project_id, 'gsc_pages' );
	$cur  = array();
	$prev = array();
	foreach ( (array) ( $gp['cur'] ?? array() ) as $r ) {
		$k         = sap_norm_url( $r['page'] );
		$cur[ $k ] = array( ( $cur[ $k ][0] ?? 0 ) + $r['clicks'], ( $cur[ $k ][1] ?? 0 ) + $r['impressions'] );
	}
	foreach ( (array) ( $gp['prev'] ?? array() ) as $r ) {
		$k          = sap_norm_url( $r['page'] );
		$prev[ $k ] = ( $prev[ $k ] ?? 0 ) + $r['clicks'];
	}
	$cats    = array();
	$by_name = array();
	$terms   = array();
	foreach ( $inv as $k => $it ) {
		if ( 'term' === $it['kind'] && in_array( $it['ctype'], array( 'product_cat', 'brand', 'post_cat' ), true ) && '' === (string) $it['redirect'] ) {
			$cats[ $k ]                                  = array( 'url' => $it['url'], 'title' => $it['title'], 'ctype' => $it['ctype'], 'products' => 0, 'clicks' => $cur[ $k ][0] ?? 0, 'impr' => $cur[ $k ][1] ?? 0, 'prev' => $prev[ $k ] ?? 0 );
			$terms[ $it['type'] . ':' . $it['id'] ]      = array( $k, $it['parent'], $it['type'] );
			$by_name[ $it['type'] ][ sap_norm_text( $it['title'] ) ] = $k;
		}
	}
	$by_key = array();
	foreach ( $terms as $tt ) {
		$by_key[ $tt[0] ] = $tt;
	}
	foreach ( $inv as $k => $it ) {
		if ( 'product' !== $it['ctype'] || empty( $it['product']['cats'] ) ) {
			continue;
		}
		$hit = array();
		foreach ( $it['product']['cats'] as $name ) {
			$ck = $by_name['product_cat'][ sap_norm_text( $name ) ] ?? '';
			// The product counts for its category and every parent category.
			for ( $guard = 0; '' !== $ck && $guard < 6 && ! isset( $hit[ $ck ] ); $guard++ ) {
				$hit[ $ck ] = true;
				$t          = $by_key[ $ck ] ?? null;
				$ck         = $t && $t[1] ? ( $terms[ $t[2] . ':' . $t[1] ][0] ?? '' ) : '';
			}
		}
		foreach ( array_keys( $hit ) as $ck ) {
			++$cats[ $ck ]['products'];
			$cats[ $ck ]['clicks'] += $cur[ $k ][0] ?? 0;
			$cats[ $ck ]['impr']   += $cur[ $k ][1] ?? 0;
			$cats[ $ck ]['prev']   += $prev[ $k ] ?? 0;
		}
	}
	$cats = array_values( $cats );
	usort( $cats, function ( $a, $b ) {
		return $b['impr'] <=> $a['impr'];
	} );
	return $cats;
}

/**
 * Saved views of a project (newest last).
 */
function sap_focus_views( $project_id ) {
	$v = get_option( 'sap_focus_views_' . (int) $project_id, array() );
	return is_array( $v ) ? $v : array();
}

/**
 * Ask the AI about a view.
 *
 * @return array|WP_Error The answer (also saved).
 */
function sap_focus_view_ask( $project, $text ) {
	$text = trim( sanitize_textarea_field( (string) $text ) );
	if ( mb_strlen( $text ) < 10 ) {
		return new WP_Error( 'sap_view', 'دیدگاهتان را کمی کامل‌تر بنویسید.' );
	}
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_view', 'هوش مصنوعی در تنظیمات وصل نشده است.' );
	}
	$pid  = $project['id'];
	$cats = array_slice( sap_focus_category_stats( $pid ), 0, 40 );
	$fs   = array();
	foreach ( sap_focuses( $pid ) as $f ) {
		$k    = sap_focus_kpis( $f, $pid );
		$fs[] = array( 'name' => $f['name'], 'status' => $f['status'], 'metric' => $f['kpi_metric'], 'current' => $k[ $f['kpi_metric'] ] ?? null, 'target' => $f['kpi_target'], 'deadline' => $f['deadline'] ? sap_jdate( $f['deadline'] ) : '' );
	}
	$qp  = sap_sort( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ), 'impressions' );
	$top = array();
	foreach ( array_slice( $qp, 0, 40 ) as $r ) {
		$top[] = sprintf( '%s | %s نمایش | جایگاه %s | %s کلیک', $r['query'], $r['impressions'], round( $r['position'], 1 ), $r['clicks'] );
	}
	$ctx = array(
		'site'           => sap_project_domain( $project ),
		'business_goal'  => $project['options']['business_goal'] ?? 'فروش آنلاین',
		'current_focuses' => $fs,
		'categories'     => array_map( function ( $c ) {
			return array( 'url' => $c['url'], 'title' => $c['title'], 'products' => $c['products'], 'clicks_28d' => $c['clicks'], 'clicks_prev_28d' => $c['prev'], 'impressions_28d' => $c['impr'] );
		}, $cats ),
		'top_queries'    => $top,
	);
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array(
		'verdict'    => array( 'type' => 'string', 'enum' => array( 'agree', 'partly', 'disagree' ) ),
		'analysis'   => array( 'type' => 'string', 'description' => 'تحلیل دیدگاه با عدد از داده پروژه، ۳ تا ۵ جمله' ),
		'why'        => array( 'type' => 'string', 'description' => 'علت این نظر؛ کدام داده آن را نشان می‌دهد' ),
		'impact'     => array( 'type' => 'string', 'description' => 'اثر احتمالی اجرای این Focus روی کلیک/فروش، با بازه عددی محتاطانه' ),
		'risks'      => array( 'type' => 'string', 'description' => 'ریسک یا چیزی که باید مراقبش بود؛ خالی اگر ندارد' ),
		'suggestion' => sap_ai_obj( array(
			'name'          => $str,
			'cats'          => array( 'type' => 'array', 'items' => $str, 'description' => 'آدرس دقیق دسته‌ها از فهرست categories' ),
			'with_products' => array( 'type' => 'boolean' ),
			'keywords'      => array( 'type' => 'array', 'items' => $str ),
			'metric'        => array( 'type' => 'string', 'enum' => array( 'clicks', 'impressions', 'top10', 'top3' ) ),
			'target'        => array( 'type' => 'number', 'description' => 'عدد هدف برای ۲۸ روز در پایان مهلت' ),
			'months'        => array( 'type' => 'integer' ),
			'weight'        => array( 'type' => 'number' ),
			'goal'          => array( 'type' => 'string', 'description' => 'هدف به زبان ساده با عدد' ),
		) ),
	) );
	$system = implode( "\n", array(
		'تو مشاور ارشد استراتژی سئو هستی. مدیر سئو دیدگاه خودش را درباره اینکه پروژه روی چه بخشی تمرکز کند نوشته است. منصفانه و با داده جواب بده: اگر درست است تأیید کن و دقیق‌ترش کن، اگر داده خلافش را نشان می‌دهد صریح بگو و جایگزین بهتر پیشنهاد بده.',
		'- فقط از داده‌های داده‌شده استناد کن (categories با کلیک و نمایش ۲۸ روز و ۲۸ روز قبل، top_queries، current_focuses). عدد بساز نکن.',
		'- suggestion یک Focus قابل ساخت است: دسته‌ها را دقیقاً از آدرس‌های categories بردار؛ اگر هدف فروش محصولات است with_products را true بگذار.',
		'- هدف واقع‌بینانه: معمولاً ۱۵ تا ۴۰٪ رشد کلیک در ۳ ماه برای بخشی که نزدیک صفحه اول است؛ کمتر برای بخش‌های اشباع. target عدد ۲۸ روزه در پایان مهلت است.',
		'- اگر دیدگاه با Focusهای فعلی تداخل دارد بگو کدام را ادغام یا متوقف کند.',
		'- فارسی، روشن، بدون مقدمه.',
	) );
	$user = 'داده پروژه:' . "\n" . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE ) . "\n\nدیدگاه مدیر سئو:\n" . mb_substr( $text, 0, 2000 );
	$res  = sap_ai_request( $system, $user, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 2500 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$sug   = (array) ( $res['suggestion'] ?? array() );
	$urls  = array();
	foreach ( (array) ( $sug['cats'] ?? array() ) as $u ) {
		foreach ( $cats as $c ) {
			if ( sap_norm_url( $c['url'] ) === sap_norm_url( $u ) ) {
				$urls[] = $c['url'];
			}
		}
	}
	$entry = array(
		'at'       => sap_now(),
		'text'     => $text,
		'verdict'  => in_array( $res['verdict'] ?? '', array( 'agree', 'partly', 'disagree' ), true ) ? $res['verdict'] : 'partly',
		'analysis' => (string) ( $res['analysis'] ?? '' ),
		'why'      => (string) ( $res['why'] ?? '' ),
		'impact'   => (string) ( $res['impact'] ?? '' ),
		'risks'    => (string) ( $res['risks'] ?? '' ),
		'sug'      => array(
			'name'          => sanitize_text_field( (string) ( $sug['name'] ?? '' ) ),
			'cats'          => array_values( array_unique( $urls ) ),
			'with_products' => ! empty( $sug['with_products'] ),
			'keywords'      => array_slice( array_map( 'sanitize_text_field', (array) ( $sug['keywords'] ?? array() ) ), 0, 10 ),
			'metric'        => array_key_exists( $sug['metric'] ?? '', sap_kpi_metrics() ) ? $sug['metric'] : 'clicks',
			'target'        => max( 0, (float) ( $sug['target'] ?? 0 ) ),
			'months'        => max( 1, min( 12, (int) ( $sug['months'] ?? 3 ) ) ),
			'weight'        => max( 1, min( 3, (float) ( $sug['weight'] ?? 2 ) ) ),
			'goal'          => sanitize_text_field( (string) ( $sug['goal'] ?? '' ) ),
		),
	);
	$views   = sap_focus_views( $pid );
	$views[] = $entry;
	update_option( 'sap_focus_views_' . $pid, array_slice( $views, -10 ), false );
	return $entry;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'seoactionplan/v1', '/focus-view/(?P<project>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$p = sap_project( (int) $req['project'] );
			if ( ! $p ) {
				return new WP_Error( 'sap_view', 'پروژه پیدا نشد.', array( 'status' => 404 ) );
			}
			$r = sap_focus_view_ask( $p, (string) $req->get_param( 'text' ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$views = sap_focus_views( $p['id'] );
			ob_start();
			sap_view( 'focus-view-answer', array( 'project' => $p, 'v' => $r, 'idx' => count( $views ) - 1 ) );
			return array( 'ok' => true, 'html' => (string) ob_get_clean() );
		},
	) );
} );

add_action( 'admin_post_sap_focus_from_view', function () {
	sap_guard( 'sap_focus_from_view' );
	$pid = absint( sap_post( 'project_id' ) );
	$v   = sap_focus_views( $pid )[ absint( sap_post( 'idx' ) ) ] ?? null;
	if ( ! $v || '' === $v['sug']['name'] ) {
		sap_back( sap_project_url( $pid, 'focuses' ), 'پیشنهاد پیدا نشد؛ دوباره بپرسید.', false );
	}
	$s    = $v['sug'];
	$cats = array();
	foreach ( $s['cats'] as $u ) {
		$cats[ $u ] = $s['with_products'];
	}
	$fid = sap_focus_save( $pid, array(
		'name'       => $s['name'],
		'keywords'   => implode( "\n", $s['keywords'] ),
		'goal'       => $s['goal'],
		'kpi_metric' => $s['metric'],
		'kpi_target' => $s['target'],
		'deadline'   => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . $s['months'] . ' months' ) ),
		'weight'     => $s['weight'],
		'status'     => 'active',
		'started_on' => current_time( 'Y-m-d' ),
		'scope'      => array( 'cats' => $cats, 'pages' => array() ),
		'ai_meta'    => array( 'rationale' => $v['why'], 'metric_reason' => $v['impact'], 'from_view' => $v['text'], 'at' => sap_now() ),
	) );
	sap_focus_after_save( $pid, $fid );
	sap_back( sap_project_url( $pid, 'focuses' ) . '#focus-' . $fid, sprintf( 'Focus «%s» از روی دیدگاه شما ساخته شد.', $s['name'] ) );
} );
