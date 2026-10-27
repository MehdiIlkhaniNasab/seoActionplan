<?php
/**
 * Datasets: the latest snapshot of each data source per project
 * (GSC queries, sheets, SEO Signal results, crawl). Stored compressed.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Labels for data sources shown in the sync panel.
 */
function sap_dataset_labels() {
	return array(
		'gsc_qp'         => 'سرچ کنسول: کوئری × صفحه (۲۸ روز اخیر)',
		'gsc_pages'      => 'سرچ کنسول: صفحات (۲۸ روز اخیر و قبل)',
		'gsc_sitemaps'   => 'سرچ کنسول: سایت‌مپ‌ها',
		'sheet_calendar' => 'گوگل شیت: تقویم محتوا',
		'sheet_clusters' => 'گوگل شیت: کلاسترینگ',
		'ss_volumes'     => 'سئو سیگنال: حجم جستجوی کلمات شیت',
		'ss_rank'        => 'سئو سیگنال: ردیاب رتبه',
		'ss_compare'     => 'سئو سیگنال: مقایسه با رقبا',
		'ss_questions'   => 'سئو سیگنال: سوالات کاربران (Focusها)',
		'crawl'          => 'Screaming Frog: آخرین خزش',
		'pages'          => 'محتوای صفحات کلیدی (متن، تیترها، اسکیما، لینک‌ها)',
		'cwv'            => 'Core Web Vitals (PageSpeed Insights)',
		'robots'         => 'فایل robots.txt',
		'sitemap_check'  => 'بررسی فایل‌های سایت‌مپ XML',
		'gsc_inspect'    => 'سرچ کنسول: وضعیت ایندکس صفحات مهم (URL Inspection)',
		'ai_audit'       => 'هوش مصنوعی: تحلیل محتوا بر اساس نوع صفحه',
		'ai_offpage'     => 'هوش مصنوعی: برنامه آف‌پیج و رپورتاژ',
		'ai_briefs'      => 'هوش مصنوعی: بریف آپدیت محتوا',
		'offpage_data'   => 'سئو سیگنال: رسانه‌های پیشنهادی و رپورتاژ رقبا',
		'ideas_pool'     => 'کاندیداهای ایده محتوای جدید (فیلترشده)',
		'ai_keymap'      => 'هوش مصنوعی: کلمه اصلی صفحات',
		'ai_focus'       => 'هوش مصنوعی: Focus و هدف‌گذاری',
		'ai_ss'          => 'هوش مصنوعی: پیشنهاد استفاده از سئو سیگنال',
		'ai_cannibal'    => 'هوش مصنوعی: کنیبالیزیشن',
		'ai_content'     => 'هوش مصنوعی: صفحات و محتوا',
		'ai_links'       => 'هوش مصنوعی: لینک‌سازی داخلی',
		'ai_technical'   => 'هوش مصنوعی: تکنیکال، اسکیما و سرعت',
		'ai_analysis'    => 'هوش مصنوعی: جمع‌بندی و اکشن پلن',
	);
}

/**
 * Store a dataset (replaces the previous snapshot).
 */
function sap_dataset_put( $project_id, $source, $payload, $meta = array() ) {
	global $wpdb;
	$table = sap_table( 'datasets' );
	$data  = array(
		'project_id' => (int) $project_id,
		'source'     => $source,
		'payload'    => sap_pack( $payload ),
		'meta'       => wp_json_encode( $meta ),
		'fetched_at' => sap_now(),
	);
	$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE project_id = %d AND source = %s", $project_id, $source ) ); // phpcs:ignore
	if ( $id ) {
		$wpdb->update( $table, $data, array( 'id' => (int) $id ) );
	} else {
		$wpdb->insert( $table, $data );
	}
	unset( $GLOBALS['sap_ds_cache'][ $project_id . ':' . $source ] );
}

/**
 * Dataset row: payload, meta, fetched_at (or null).
 */
function sap_dataset( $project_id, $source ) {
	global $wpdb;
	$k = $project_id . ':' . $source;
	if ( isset( $GLOBALS['sap_ds_cache'] ) && array_key_exists( $k, $GLOBALS['sap_ds_cache'] ) ) {
		return $GLOBALS['sap_ds_cache'][ $k ];
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'datasets' ) . ' WHERE project_id = %d AND source = %s', $project_id, $source ), ARRAY_A ); // phpcs:ignore
	$out = $row ? array(
		'payload'    => sap_unpack( $row['payload'] ),
		'meta'       => json_decode( (string) $row['meta'], true ) ?: array(),
		'fetched_at' => $row['fetched_at'],
	) : null;
	$GLOBALS['sap_ds_cache'][ $k ] = $out;
	return $out;
}

/**
 * Dataset payload or empty array.
 */
function sap_dataset_payload( $project_id, $source ) {
	$d = sap_dataset( $project_id, $source );
	return $d ? $d['payload'] : array();
}

/**
 * Freshness of all datasets of a project: source => fetched_at.
 */
function sap_dataset_status( $project_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source, fetched_at, meta FROM ' . sap_table( 'datasets' ) . ' WHERE project_id = %d', $project_id ), ARRAY_A ); // phpcs:ignore
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r['source'] ] = array(
			'fetched_at' => $r['fetched_at'],
			'meta'       => json_decode( (string) $r['meta'], true ) ?: array(),
		);
	}
	return $out;
}

/**
 * Age of a dataset in days (null when missing).
 */
function sap_dataset_age_days( $project_id, $source ) {
	$d = sap_dataset( $project_id, $source );
	if ( ! $d ) {
		return null;
	}
	return ( current_time( 'timestamp' ) - strtotime( $d['fetched_at'] ) ) / DAY_IN_SECONDS; // phpcs:ignore
}
