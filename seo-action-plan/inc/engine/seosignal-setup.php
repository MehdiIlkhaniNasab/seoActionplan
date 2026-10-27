<?php
/**
 * SEO Signal setup guide: what the specialist has to configure in the SEO
 * Signal panel so every tool of the tab can analyse this project, with the
 * current state of each item. Nothing here spends quota.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keywords worth tracking in the rank tracker: focus keywords, the primary
 * keyword of the most visible pages, then planned / published articles.
 *
 * @return string[]
 */
function sap_ss_track_suggestions( $project, $limit = 150 ) {
	$pid  = $project['id'];
	$kw   = array();
	$push = function ( $k ) use ( &$kw, $project ) {
		$k = trim( (string) $k );
		if ( '' !== $k && ! sap_is_brand( $k, $project ) && ! isset( $kw[ sap_kw_key( $k ) ] ) ) {
			$kw[ sap_kw_key( $k ) ] = $k;
		}
	};
	foreach ( sap_focuses( $pid, true ) as $f ) {
		array_map( $push, (array) $f['keywords'] );
	}
	$impr = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$impr[ sap_norm_url( $r['page'] ) ] = (int) $r['impressions'];
	}
	$map = sap_keymap( $pid );
	uksort( $map, function ( $a, $b ) use ( $impr ) {
		return ( $impr[ $b ] ?? 0 ) <=> ( $impr[ $a ] ?? 0 );
	} );
	foreach ( $map as $m ) {
		if ( 'home' !== $m['page_type'] ) {
			$push( $m['primary_kw'] );
		}
	}
	foreach ( sap_ideas( $pid, array( 'status' => array( 'scheduled', 'approved', 'published' ) ) ) as $i ) {
		$push( $i['keyword'] );
	}
	return array_slice( array_values( $kw ), 0, $limit );
}

/**
 * Setup checklist.
 *
 * Item `ok`: true = done, false = to do, null = information only.
 *
 * @return array{items: array, track: string[], missing: string[], competitors: string[], tracked: int}
 */
function sap_ss_setup( $project ) {
	$detail  = (array) ( sap_dataset_payload( $project['id'], 'ss_rank' )['detail'] ?? array() );
	$tracked = array();
	foreach ( (array) ( $detail['keywords'] ?? array() ) as $k ) {
		$k = is_array( $k ) ? (string) ( $k['keyword'] ?? $k['Keyword'] ?? '' ) : (string) $k;
		if ( '' !== $k ) {
			$tracked[ sap_kw_key( $k ) ] = true;
		}
	}
	$track   = sap_ss_track_suggestions( $project );
	$missing = array_values( array_filter( $track, function ( $k ) use ( $tracked ) {
		return ! isset( $tracked[ sap_kw_key( $k ) ] );
	} ) );
	$comp    = array_values( array_filter( array_map( 'sap_clean_domain', (array) $project['competitors'] ) ) );
	$rt_comp = array_map( function ( $c ) {
		return sap_clean_domain( is_array( $c ) ? ( $c['domain'] ?? $c['Domain'] ?? '' ) : $c );
	}, (array) ( $detail['competitors'] ?? array() ) );
	$domain  = sap_project_domain( $project );

	$items = array(
		array(
			'ok'    => sap_ss_connected(),
			'title' => 'کلید API در تنظیمات سکان',
			'how'   => 'در پنل سئو سیگنال بخش API، توکن را بسازید و در «تنظیمات و اتصال‌ها»ی سکان وارد کنید. هر کسی این توکن را داشته باشد از سهمیه شما استفاده می‌کند؛ آن را جایی منتشر نکنید.',
		),
		array(
			'ok'     => (bool) $project['ss_project_id'],
			'title'  => $project['ss_project_id'] ? 'پروژه ردیاب رتبه وصل است (شناسه ' . sap_num( $project['ss_project_id'] ) . ( ! empty( $detail['name'] ) ? ' — ' . $detail['name'] : '' ) . ')' : 'پروژه ردیاب رتبه برای همین سایت',
			'how'    => sprintf( 'در پنل سئو سیگنال › ردیاب رتبه یک پروژه با دامنه اصلی «%s» بسازید، موقعیت را ایران بگذارید و هر دو دستگاه موبایل و دسکتاپ را فعال کنید؛ بعد «پیدا کردن و اتصال خودکار» را بزنید. ردیاب رتبه رایگان است و بدون آن وضعیت رتبه، تاریخچه و افت و صعود کلمات در اکشن پلن خالی می‌ماند.', $domain ),
			'action' => 'link',
		),
		array(
			'ok'    => $tracked ? ! $missing : false,
			'title' => $tracked ? sprintf( 'کلمات ردیاب: %s کلمه ردیابی می‌شود، %s کلمه مهم جا مانده', sap_num( count( $tracked ) ), sap_num( count( $missing ) ) ) : 'کلمات ردیاب رتبه',
			'how'   => 'کلمات فهرست زیر (کلمات Focusها، کلمه اصلی صفحات پربازدید و مقالات تقویم) را کپی کنید و در پروژه ردیاب اضافه کنید. با هر تغییر نقشه کلمات یا تقویم، این فهرست به‌روز می‌شود.' . ( $tracked ? '' : ' وضعیت دقیق بعد از اجرای «وضعیت رتبه‌ها» یا مرحله رتبه در تحلیل کامل معلوم می‌شود.' ),
		),
		array(
			'ok'    => $comp ? ! array_diff( $comp, $rt_comp ) : false,
			'title' => 'رقبا در پروژه ردیاب',
			'how'   => $comp ? 'همین دامنه‌ها را در بخش رقبای پروژه ردیاب هم وارد کنید تا رتبه آن‌ها کنار رتبه شما ثبت شود و مقایسه سایت معنی داشته باشد: ' . implode( '، ', $comp ) : 'اول در تنظیمات پروژه سکان دامنه ۳ تا ۵ رقیب اصلی را وارد کنید، بعد همان‌ها را در پروژه ردیاب سئو سیگنال اضافه کنید.',
		),
		array(
			'ok'    => null,
			'title' => 'دستیار رپورتاژ (اختیاری)',
			'how'   => 'پیشنهاد رسانه، جزئیات رسانه و آنالیز خریدار و فروشنده رپورتاژ فقط در پلن‌هایی کار می‌کند که «دستیار رپورتاژ» دارند؛ آنالیز خریدار از سقف ماهانه رپورتاژ هم کم می‌کند. اگر پلن شما ندارد این ابزارها خطای دسترسی می‌دهند و بقیه ابزارها عادی کار می‌کنند.',
		),
		array(
			'ok'    => null,
			'title' => 'سهمیه',
			'how'   => sprintf( 'تحقیق کلمه، حجم دسته‌ای (تا ۱۰۰۰ کلمه با ۱ درخواست) و آنالیز سایت هرکدام ۱ واحد، و مقایسه دو سایت ۲ واحد مصرف می‌کند؛ ردیاب رتبه رایگان است. سقف مصرف روزانه سکان %s واحد است و در تنظیمات عوض می‌شود. نتایج تکراری از کش خوانده می‌شوند.', sap_num( sap_ss_usage()['cap'] ) ),
		),
	);
	return array(
		'items'       => $items,
		'track'       => $track,
		'missing'     => $missing,
		'competitors' => $comp,
		'tracked'     => count( $tracked ),
	);
}

/**
 * Link the project to a rank tracker project: the given id, or the one whose
 * main domain matches the site (free call).
 */
function sap_ss_link_tracker( $project, $id = 0 ) {
	global $wpdb;
	$list = sap_ss_rt_projects();
	if ( is_wp_error( $list ) ) {
		return $list;
	}
	$domain = sap_project_domain( $project );
	$pick   = null;
	foreach ( $list as $p ) {
		if ( ( $id && $p['id'] === $id ) || ( ! $id && sap_clean_domain( $p['domain'] ) === $domain ) ) {
			$pick = $p;
			break;
		}
	}
	if ( ! $pick ) {
		return new WP_Error( 'sap_ss_link', sprintf( 'در ردیاب رتبه سئو سیگنال پروژه‌ای با دامنه %s پیدا نشد (%s پروژه دیگر هست). اول در پنل سئو سیگنال پروژه را بسازید.', $domain, sap_num( count( $list ) ) ), array( 'status' => 404 ) );
	}
	$wpdb->update( sap_table( 'projects' ), array( 'ss_project_id' => $pick['id'] ), array( 'id' => (int) $project['id'] ) );
	unset( $GLOBALS['sap_project_cache'] );
	return array(
		'ok'     => true,
		'msg'    => sprintf( 'به پروژه «%s» (%s کلمه) وصل شد. حالا «وضعیت رتبه‌ها» را اجرا کنید.', $pick['name'], sap_num( $pick['keywords'] ) ),
		'reload' => true,
	);
}
