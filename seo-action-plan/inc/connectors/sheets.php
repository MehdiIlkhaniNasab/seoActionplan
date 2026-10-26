<?php
/**
 * Google Sheets: the project's content calendar and clustering sheets.
 *
 * The plugin defines one standard structure (see sap_sheet_schema()). Columns are
 * matched by header text, so their order does not matter and extra columns are
 * ignored. The «auto» columns are written back by the plugin after each sync.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Standard sheet structure.
 *
 * Each column: key => [header (as written in the template), aliases[], required].
 */
function sap_sheet_schema() {
	return array(
		'calendar' => array(
			'columns' => array(
				'code'      => array( 'کد', array( 'شناسه', 'ردیف', 'id' ), false ),
				'cluster'   => array( 'کلاستر', array( 'خوشه', 'cluster' ), false ),
				'keyword'   => array( 'کلمه کلیدی اصلی', array( 'کلمه کلیدی', 'کیورد اصلی', 'کیورد', 'main keyword', 'keyword' ), true ),
				'related'   => array( 'کلمات هم‌کانسپت', array( 'کلمات هم کانسپت', 'هم کانسپت', 'کلمات مرتبط', 'کلمات فرعی', 'lsi', 'related keywords' ), false ),
				'page_type' => array( 'نوع صفحه', array( 'نوع محتوا', 'page type' ), false ),
				'intent'    => array( 'نیت جستجو', array( 'نیت', 'intent' ), false ),
				'status'    => array( 'وضعیت', array( 'status' ), false ),
				'owner'     => array( 'مسئول', array( 'نویسنده', 'owner' ), false ),
				'due'       => array( 'سررسید', array( 'تاریخ سررسید', 'deadline', 'due' ), false ),
				'published' => array( 'تاریخ انتشار', array( 'انتشار', 'publish date' ), false ),
				'url'       => array( 'لینک', array( 'لینک مقاله', 'آدرس', 'آدرس مقاله', 'url' ), false ),
				'notes'     => array( 'یادداشت', array( 'توضیحات', 'notes' ), false ),
			),
			'auto'    => array(
				'a_volume'   => 'حجم جستجو',
				'a_position' => 'جایگاه فعلی',
				'a_clicks'   => 'کلیک ۲۸ روز',
				'a_impr'     => 'نمایش ۲۸ روز',
				'a_missing'  => 'هم‌کانسپت‌های بدون رتبه',
				'a_updated'  => 'بروزرسانی ابزار',
			),
		),
		'clusters' => array(
			'columns' => array(
				'cluster'    => array( 'کلاستر', array( 'خوشه', 'cluster' ), true ),
				'subcluster' => array( 'زیرکلاستر', array( 'زیر کلاستر', 'زیرخوشه', 'subcluster' ), false ),
				'keyword'    => array( 'کلمه کلیدی', array( 'کلمه', 'کیورد', 'keyword' ), true ),
				'role'       => array( 'نقش', array( 'نوع کلمه', 'role' ), false ),
				'target'     => array( 'صفحه هدف', array( 'لینک هدف', 'url هدف', 'آدرس هدف', 'target url', 'target' ), false ),
				'priority'   => array( 'اولویت', array( 'priority' ), false ),
				'intent'     => array( 'نیت جستجو', array( 'نیت', 'intent' ), false ),
				'notes'      => array( 'یادداشت', array( 'توضیحات', 'notes' ), false ),
			),
			'auto'    => array(
				'a_volume'      => 'حجم جستجو',
				'a_competition' => 'رقابت',
				'a_position'    => 'جایگاه فعلی',
				'a_rank_page'   => 'صفحه رتبه‌گرفته',
				'a_clicks'      => 'کلیک ۲۸ روز',
				'a_coverage'    => 'وضعیت پوشش',
				'a_updated'     => 'بروزرسانی ابزار',
			),
		),
	);
}

/**
 * Content calendar status values (as typed in the sheet) → internal key.
 */
function sap_calendar_status( $text ) {
	$t = sap_norm_text( $text );
	$map = array(
		'منتشر'    => 'published',
		'آپدیت'    => 'needs_update',
		'بروزرسانی' => 'needs_update',
		'به روز'   => 'needs_update',
		'بازبینی'  => 'review',
		'ویرایش'   => 'review',
		'نگارش'    => 'writing',
		'نوشتن'    => 'writing',
		'سفارش'    => 'ordered',
		'صف'       => 'queued',
		'ایده'     => 'idea',
	);
	foreach ( $map as $needle => $key ) {
		if ( '' !== $t && false !== mb_strpos( $t, $needle ) ) {
			return $key;
		}
	}
	return '' === $t ? '' : 'idea';
}

/**
 * Extract a spreadsheet id from a full Google Sheets URL (or return the id).
 */
function sap_sheet_id_from_url( $value ) {
	$value = trim( (string) $value );
	if ( preg_match( '#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $value, $m ) ) {
		return $m[1];
	}
	return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );
}

/**
 * Quoted A1 range for a tab name.
 */
function sap_sheet_range( $tab, $cells = '' ) {
	return "'" . str_replace( "'", "''", $tab ) . "'" . ( $cells ? '!' . $cells : '' );
}

/**
 * Column letter from a 0-based index (0 → A, 26 → AA).
 */
function sap_sheet_col( $i ) {
	$s = '';
	for ( $n = $i + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
		$s = chr( 65 + ( ( $n - 1 ) % 26 ) ) . $s;
	}
	return $s;
}

/**
 * Raw values of a tab.
 */
function sap_sheet_values( $sheet_id, $tab ) {
	$url  = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $sheet_id ) . '/values/' . rawurlencode( sap_sheet_range( $tab ) ) . '?valueRenderOption=FORMATTED_VALUE&majorDimension=ROWS';
	$data = sap_google_request( 'GET', $url, null, 45 );
	if ( is_wp_error( $data ) ) {
		$status = (int) ( $data->get_error_data()['status'] ?? 0 );
		if ( 404 === $status || 400 === $status ) {
			return new WP_Error( 'sap_sheet_tab', sprintf( 'تب «%s» در گوگل شیت پیدا نشد (یا شناسه شیت اشتباه است).', $tab ) );
		}
		if ( 403 === $status ) {
			return new WP_Error( 'sap_sheet_access', 'به این گوگل شیت دسترسی وجود ندارد. شیت را با حساب گوگل متصل (یا ایمیل سرویس اکانت) به‌اشتراک بگذارید و دسترسی Editor بدهید.' );
		}
		return $data;
	}
	return (array) ( $data['values'] ?? array() );
}

/**
 * Map header cells to schema keys.
 *
 * @return array key => column index
 */
function sap_sheet_map_header( $header, $schema ) {
	$norm = array();
	foreach ( (array) $header as $i => $h ) {
		$norm[ $i ] = sap_kw_key( $h );
	}
	$map  = array();
	$used = array();
	foreach ( $schema['columns'] as $key => $def ) {
		foreach ( array_merge( array( $def[0] ), $def[1] ) as $name ) {
			$pos = array_search( sap_kw_key( $name ), $norm, true );
			if ( false !== $pos && ! isset( $used[ $pos ] ) ) {
				$map[ $key ]  = $pos;
				$used[ $pos ] = true;
				break;
			}
		}
	}
	foreach ( $schema['auto'] as $key => $name ) {
		$pos = array_search( sap_kw_key( $name ), $norm, true );
		if ( false !== $pos && ! isset( $used[ $pos ] ) ) {
			$map[ $key ]  = $pos;
			$used[ $pos ] = true;
		}
	}
	return $map;
}

/**
 * Read and parse a tab with the standard structure.
 *
 * @param string $type calendar|clusters.
 * @return array|WP_Error {rows, header, map, missing}
 */
function sap_sheet_read( $sheet_id, $tab, $type ) {
	$schema = sap_sheet_schema()[ $type ];
	$values = sap_sheet_values( $sheet_id, $tab );
	if ( is_wp_error( $values ) ) {
		return $values;
	}
	if ( empty( $values ) ) {
		return new WP_Error( 'sap_sheet_empty', sprintf( 'تب «%s» خالی است.', $tab ) );
	}
	$header  = array_map( 'trim', (array) $values[0] );
	$map     = sap_sheet_map_header( $header, $schema );
	$missing = array();
	foreach ( $schema['columns'] as $key => $def ) {
		if ( $def[2] && ! isset( $map[ $key ] ) ) {
			$missing[] = $def[0];
		}
	}
	if ( $missing ) {
		return new WP_Error( 'sap_sheet_columns', sprintf( 'ستون‌های الزامی در تب «%s» پیدا نشد: %s. ساختار استاندارد را در صفحه راهنما ببینید.', $tab, implode( '، ', $missing ) ) );
	}
	$rows    = array();
	$last    = '';
	$last_sc = '';
	foreach ( array_slice( $values, 1 ) as $i => $line ) {
		$r = array( '_row' => $i + 2 );
		foreach ( array_keys( $schema['columns'] ) as $key ) {
			$r[ $key ] = '';
		}
		foreach ( $map as $key => $pos ) {
			$r[ $key ] = trim( (string) ( $line[ $pos ] ?? '' ) );
		}
		if ( '' === ( $r['keyword'] ?? '' ) ) {
			continue;
		}
		// Clustering sheets often write the cluster name only on the first row of a block.
		if ( 'clusters' === $type ) {
			if ( '' === $r['cluster'] ) {
				$r['cluster'] = $last;
			} elseif ( $r['cluster'] !== $last ) {
				$last_sc = '';
			}
			$last = $r['cluster'];
			if ( '' === ( $r['subcluster'] ?? '' ) ) {
				$r['subcluster'] = $last_sc;
			}
			$last_sc = $r['subcluster'];
		} else {
			$r['related_list'] = sap_list( $r['related'] ?? '' );
			$r['status_key']   = sap_calendar_status( $r['status'] ?? '' );
			if ( '' === $r['status_key'] && '' !== ( $r['url'] ?? '' ) ) {
				$r['status_key'] = 'published';
			}
			$r['due_date']       = sap_parse_date( $r['due'] ?? '' );
			$r['published_date'] = sap_parse_date( $r['published'] ?? '' );
		}
		$rows[] = $r;
	}
	return array(
		'rows'   => $rows,
		'header' => $header,
		'map'    => $map,
		'tab'    => $tab,
	);
}

/**
 * Write the plugin's auto columns back to a tab.
 *
 * Missing auto headers are appended after the last used column. Only auto columns
 * are touched; the team's own columns are never overwritten.
 *
 * @param array $parsed  Output of sap_sheet_read().
 * @param array $columns auto key => [sheet row number => value].
 * @return true|WP_Error
 */
function sap_sheet_write_auto( $sheet_id, $parsed, $type, $columns ) {
	$schema = sap_sheet_schema()[ $type ];
	$header = $parsed['header'];
	$map    = $parsed['map'];
	$data   = array();
	$next   = count( $header );
	foreach ( $schema['auto'] as $key => $name ) {
		if ( ! isset( $columns[ $key ] ) ) {
			continue;
		}
		if ( ! isset( $map[ $key ] ) ) {
			$map[ $key ] = $next++;
			$data[]      = array(
				'range'  => sap_sheet_range( $parsed['tab'], sap_sheet_col( $map[ $key ] ) . '1' ),
				'values' => array( array( $name ) ),
			);
		}
		$max = 1;
		foreach ( $parsed['rows'] as $r ) {
			$max = max( $max, (int) $r['_row'] );
		}
		$vals = array();
		for ( $row = 2; $row <= $max; $row++ ) {
			$vals[] = array( (string) ( $columns[ $key ][ $row ] ?? '' ) );
		}
		$col    = sap_sheet_col( $map[ $key ] );
		$data[] = array(
			'range'  => sap_sheet_range( $parsed['tab'], $col . '2:' . $col . $max ),
			'values' => $vals,
		);
	}
	if ( ! $data ) {
		return true;
	}
	$url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $sheet_id ) . '/values:batchUpdate';
	$res = sap_google_request( 'POST', $url, array(
		'valueInputOption' => 'RAW',
		'data'             => $data,
	), 60 );
	return is_wp_error( $res ) ? $res : true;
}

/**
 * CSV template of a sheet type (for the guide's download buttons).
 */
function sap_sheet_template_csv( $type ) {
	$schema  = sap_sheet_schema()[ $type ];
	$headers = array();
	foreach ( $schema['columns'] as $def ) {
		$headers[] = $def[0];
	}
	if ( 'calendar' === $type ) {
		$sample = array(
			array( 'C-01', 'اسکوتر برقی', 'خرید اسکوتر برقی', 'قیمت اسکوتر برقی، بهترین اسکوتر برقی، اسکوتر برقی بزرگسال', 'دسته‌بندی', 'تراکنشی', 'منتشر شد', 'سارا', '1405/08/10', '1405/08/12', 'https://example.com/product-category/electric-scooter/', '' ),
			array( 'C-02', 'اسکوتر برقی', 'راهنمای خرید اسکوتر برقی', 'نکات خرید اسکوتر، اسکوتر برقی مناسب', 'مقاله', 'اطلاعاتی', 'در حال نگارش', 'علی', '1405/08/20', '', '', '' ),
		);
	} else {
		$sample = array(
			array( 'اسکوتر برقی', 'خرید', 'خرید اسکوتر برقی', 'پیلار', 'https://example.com/product-category/electric-scooter/', '1', 'تراکنشی', '' ),
			array( 'اسکوتر برقی', 'خرید', 'قیمت اسکوتر برقی', 'اصلی', 'https://example.com/product-category/electric-scooter/', '1', 'تراکنشی', '' ),
			array( 'اسکوتر برقی', 'راهنما', 'بهترین اسکوتر برقی', 'فرعی', '', '2', 'تجاری', '' ),
		);
	}
	$fh = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fputcsv( $fh, array_merge( $headers, array_values( $schema['auto'] ) ), ',', '"', '\\' );
	foreach ( $sample as $row ) {
		fputcsv( $fh, $row, ',', '"', '\\' );
	}
	rewind( $fh );
	$csv = stream_get_contents( $fh );
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $csv;
}
