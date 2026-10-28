<?php
/**
 * Content calendar and the project's Google Sheet.
 *
 * The calendar is the scheduled ideas, month by month. In the Google Sheet it is
 * written in the agency's own layout:
 *
 *   «Blog»       monthly blocks, newest first: M3, M2, M1 …
 *                [M#] Writing Guide (Main Idea, KP, Title, Desc) · Out Links
 *                (L1 K1 … L5 K5) · Publish (Content Source, Status, Publish
 *                Date, Link) · planned date
 *   «B keyword»  per month a column pair: each article's keyword cluster with
 *                search volumes and a total.
 *
 * The team keeps working in the sheet: Status, Publish Date, Link and Content
 * Source typed there are read back before every write, so nothing is lost.
 *
 * The project sheet can also be created automatically (after your approval)
 * with the tabs Blog, B keyword, کلاسترینگ, نقشه کلمات and لاگ تغییرات; the
 * change log and keyword map are kept in sync on every analysis.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

const SAP_TAB_BLOG     = 'Blog';
const SAP_TAB_BKEYWORD = 'B keyword';
const SAP_TAB_KEYMAP   = 'نقشه کلمات';
const SAP_TAB_LOG      = 'لاگ تغییرات';
const SAP_TAB_UPDATES  = 'آپدیت محتوا';

/* ---------------------------------------------------------------------------
 * Calendar
 * ------------------------------------------------------------------------- */

/**
 * Months that have calendar content, oldest first, with their M-number.
 *
 * @return array period => label (M1, M2 …)
 */
function sap_calendar_months( $project_id ) {
	global $wpdb;
	$periods = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT period FROM ' . sap_table( 'ideas' ) . " WHERE project_id = %d AND period <> '' AND status IN ('scheduled','published') ORDER BY period ASC", $project_id ) ); // phpcs:ignore
	$out     = array();
	foreach ( $periods as $i => $p ) {
		$out[ $p ] = 'M' . ( $i + 1 );
	}
	return $out;
}

/**
 * Months shown in the sheet: months with articles or with content updates.
 */
function sap_calendar_sheet_months( $project_id ) {
	$periods = array_keys( sap_calendar_months( $project_id ) );
	foreach ( sap_updates( $project_id ) as $u ) {
		if ( '' !== (string) $u['period'] && 'cancelled' !== $u['status'] ) {
			$periods[] = $u['period'];
		}
	}
	$periods = array_values( array_unique( $periods ) );
	sort( $periods );
	$out = array();
	foreach ( $periods as $i => $p ) {
		$out[ $p ] = 'M' . ( $i + 1 );
	}
	return $out;
}

/**
 * Articles of a month, in slot order.
 */
function sap_calendar_items( $project_id, $period ) {
	$items = sap_ideas( $project_id, array( 'period' => $period, 'status' => array( 'scheduled', 'published' ) ) );
	usort( $items, function ( $a, $b ) {
		return $a['slot'] <=> $b['slot'] ?: $a['id'] <=> $b['id'];
	} );
	return $items;
}

/**
 * Put ideas into a month (appended after its current articles).
 */
function sap_calendar_schedule( $project_id, $ids, $period ) {
	$period = sap_period_sanitize( $period );
	$slot   = count( sap_calendar_items( $project_id, $period ) );
	foreach ( (array) $ids as $id ) {
		$idea = sap_idea( (int) $id );
		if ( ! $idea || $idea['project_id'] !== (int) $project_id ) {
			continue;
		}
		$links = sap_idea_fill_links( sap_project( $project_id ), $idea['keyword'], $idea['links'] );
		sap_idea_save( array( 'status' => 'published' === $idea['status'] ? 'published' : 'scheduled', 'period' => $period, 'slot' => ++$slot, 'links' => $links ), $idea['id'] );
	}
	sap_calendar_spread_dates( $project_id, $period );
}

/**
 * Spread the planned dates of a month's articles evenly over the month.
 */
function sap_calendar_spread_dates( $project_id, $period ) {
	$items = sap_calendar_items( $project_id, $period );
	if ( ! $items ) {
		return;
	}
	list( $start, $end ) = sap_period_range( $period );
	$days = max( 1, ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS + 1 );
	$n    = count( $items );
	foreach ( $items as $i => $it ) {
		$day = (int) floor( ( $i + 0.5 ) * $days / $n );
		sap_idea_save( array( 'slot' => $i + 1, 'planned_date' => gmdate( 'Y-m-d', strtotime( $start ) + $day * DAY_IN_SECONDS ) ), $it['id'] );
	}
}

/**
 * Fill a month to $target articles with new AI ideas.
 *
 * @return array|WP_Error
 */
function sap_calendar_fill_month( $project, $period, $target = 8 ) {
	$have = count( sap_calendar_items( $project['id'], $period ) );
	if ( $have >= $target ) {
		return array( 'added' => 0, 'msg' => 'این ماه از قبل کامل است.' );
	}
	$res = sap_ideas_generate( $project, $target - $have, $period );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return $res + array( 'msg' => sprintf( '%s مقاله به تقویم %s اضافه شد.', sap_num( $res['added'] ), sap_period_label( $period ) ) );
}

/* ---------------------------------------------------------------------------
 * Google Sheets: low level
 * ------------------------------------------------------------------------- */

function sap_gs_url( $sheet_id, $path = '' ) {
	return 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $sheet_id ) . $path;
}

/**
 * Tabs of a spreadsheet: title => sheetId.
 */
function sap_gs_tabs( $sheet_id ) {
	$res = sap_google_request( 'GET', sap_gs_url( $sheet_id, '?fields=sheets.properties(sheetId,title)' ), null, 30 );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$out = array();
	foreach ( (array) ( $res['sheets'] ?? array() ) as $s ) {
		$out[ (string) $s['properties']['title'] ] = (int) $s['properties']['sheetId'];
	}
	return $out;
}

/**
 * Make sure a tab exists; returns its sheetId.
 */
function sap_gs_ensure_tab( $sheet_id, $title, &$tabs = null ) {
	if ( null === $tabs ) {
		$tabs = sap_gs_tabs( $sheet_id );
		if ( is_wp_error( $tabs ) ) {
			return $tabs;
		}
	}
	if ( isset( $tabs[ $title ] ) ) {
		return $tabs[ $title ];
	}
	$res = sap_google_request( 'POST', sap_gs_url( $sheet_id, ':batchUpdate' ), array( 'requests' => array( array( 'addSheet' => array( 'properties' => array( 'title' => $title, 'rightToLeft' => true ) ) ) ) ), 30 );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$id             = (int) ( $res['replies'][0]['addSheet']['properties']['sheetId'] ?? 0 );
	$tabs[ $title ] = $id;
	return $id;
}

/**
 * Replace the whole content of a tab.
 */
function sap_gs_put_tab( $sheet_id, $tab, $values ) {
	$clear = sap_google_request( 'POST', sap_gs_url( $sheet_id, '/values/' . rawurlencode( sap_sheet_range( $tab ) ) . ':clear' ), array(), 30 );
	if ( is_wp_error( $clear ) ) {
		return $clear;
	}
	if ( ! $values ) {
		return true;
	}
	$res = sap_google_request( 'PUT', sap_gs_url( $sheet_id, '/values/' . rawurlencode( sap_sheet_range( $tab, 'A1' ) ) . '?valueInputOption=USER_ENTERED' ), array( 'values' => $values ), 60 );
	return is_wp_error( $res ) ? $res : true;
}

/**
 * Spreadsheet batchUpdate (formatting).
 */
function sap_gs_batch( $sheet_id, $requests ) {
	if ( ! $requests ) {
		return true;
	}
	$res = sap_google_request( 'POST', sap_gs_url( $sheet_id, ':batchUpdate' ), array( 'requests' => $requests ), 60 );
	return is_wp_error( $res ) ? $res : true;
}

/**
 * Color helper: "#20124D" → Sheets color.
 */
function sap_gs_color( $hex ) {
	$hex = ltrim( $hex, '#' );
	return array( 'red' => hexdec( substr( $hex, 0, 2 ) ) / 255, 'green' => hexdec( substr( $hex, 2, 2 ) ) / 255, 'blue' => hexdec( substr( $hex, 4, 2 ) ) / 255 );
}

/**
 * Format request for a range.
 */
function sap_gs_fmt( $sid, $r1, $r2, $c1, $c2, $bg = '', $fg = '', $bold = false ) {
	$fmt = array( 'horizontalAlignment' => 'CENTER', 'verticalAlignment' => 'MIDDLE', 'textFormat' => array( 'bold' => $bold ) );
	if ( $bg ) {
		$fmt['backgroundColor'] = sap_gs_color( $bg );
	}
	if ( $fg ) {
		$fmt['textFormat']['foregroundColor'] = sap_gs_color( $fg );
	}
	return array( 'repeatCell' => array(
		'range'  => array( 'sheetId' => $sid, 'startRowIndex' => $r1, 'endRowIndex' => $r2, 'startColumnIndex' => $c1, 'endColumnIndex' => $c2 ),
		'cell'   => array( 'userEnteredFormat' => $fmt ),
		'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)',
	) );
}

function sap_gs_merge( $sid, $r1, $r2, $c1, $c2 ) {
	return array( 'mergeCells' => array( 'range' => array( 'sheetId' => $sid, 'startRowIndex' => $r1, 'endRowIndex' => $r2, 'startColumnIndex' => $c1, 'endColumnIndex' => $c2 ), 'mergeType' => 'MERGE_ALL' ) );
}

/**
 * Reset a tab's layout (merges, formats, validation) and set it right-to-left.
 */
function sap_gs_reset( $sid, $widths = array() ) {
	$req = array(
		array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => $sid, 'rightToLeft' => true ), 'fields' => 'rightToLeft' ) ),
		array( 'unmergeCells' => array( 'range' => array( 'sheetId' => $sid ) ) ),
		array( 'repeatCell' => array( 'range' => array( 'sheetId' => $sid ), 'cell' => array( 'userEnteredFormat' => new stdClass() ), 'fields' => 'userEnteredFormat' ) ),
		array( 'setDataValidation' => array( 'range' => array( 'sheetId' => $sid ) ) ),
	);
	foreach ( $widths as $col => $px ) {
		$req[] = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $sid, 'dimension' => 'COLUMNS', 'startIndex' => $col, 'endIndex' => $col + 1 ), 'properties' => array( 'pixelSize' => $px ), 'fields' => 'pixelSize' ) );
	}
	return $req;
}

/* ---------------------------------------------------------------------------
 * «Blog» layout: read
 * ------------------------------------------------------------------------- */

/**
 * Parse a Blog-layout tab into articles.
 *
 * @return array list of {m, row, keyword, kp, title, desc, links, source, status, publish, link, planned}
 */
function sap_blog_parse( $values ) {
	$out   = array();
	$m     = 0;
	$cols  = array();
	$names = array( 'main idea' => 'keyword', 'kp' => 'kp', 'title' => 'title', 'desc' => 'desc', 'content source' => 'source', 'status' => 'status', 'publish date' => 'publish', 'link' => 'link', 'تاریخ برنامه' => 'planned' );
	foreach ( (array) $values as $i => $row ) {
		$a = trim( (string) ( $row[0] ?? '' ) );
		if ( preg_match( '/^m\s*(\d+)$/i', $a, $mm ) ) {
			$m = (int) $mm[1];
			continue;
		}
		$lower = array_map( function ( $v ) {
			return strtolower( trim( (string) $v ) );
		}, (array) $row );
		if ( in_array( 'update page', $lower, true ) ) {
			$cols = array(); // «Update Page» block: content updates written by Sokan, not articles.
			continue;
		}
		if ( in_array( 'main idea', $lower, true ) ) {
			$cols = array();
			foreach ( $lower as $ci => $h ) {
				if ( isset( $names[ $h ] ) ) {
					$cols[ $names[ $h ] ] = $ci;
				} elseif ( preg_match( '/^([lk])([1-9])$/', $h, $lk ) ) {
					$cols[ $lk[1] . $lk[2] ] = $ci;
				}
			}
			continue;
		}
		if ( ! $m || ! isset( $cols['keyword'] ) ) {
			continue;
		}
		$kw = trim( (string) ( $row[ $cols['keyword'] ] ?? '' ) );
		if ( '' === $kw ) {
			continue;
		}
		$get   = function ( $k ) use ( $row, $cols ) {
			return isset( $cols[ $k ] ) ? trim( (string) ( $row[ $cols[ $k ] ] ?? '' ) ) : '';
		};
		$links = array();
		for ( $n = 1; $n <= 5; $n++ ) {
			if ( '' !== $get( 'l' . $n ) ) {
				$links[] = array( 'url' => $get( 'l' . $n ), 'anchor' => $get( 'k' . $n ) );
			}
		}
		$st    = strtoupper( $get( 'status' ) );
		$out[] = array(
			'm'       => $m,
			'row'     => $i + 1,
			'keyword' => $kw,
			'kp'      => (int) sap_norm_text( $get( 'kp' ) ),
			'title'   => $get( 'title' ),
			'desc'    => $get( 'desc' ),
			'links'   => $links,
			'source'  => $get( 'source' ),
			'status'  => in_array( $st, array( 'TRUE', '1', 'YES' ), true ) || false !== mb_strpos( $get( 'status' ), 'منتشر' ),
			'publish' => $get( 'publish' ),
			'link'    => $get( 'link' ),
			'planned' => $get( 'planned' ),
		);
	}
	return $out;
}

/**
 * Does a tab use the Blog layout?
 */
function sap_is_blog_layout( $values ) {
	foreach ( array_slice( (array) $values, 0, 6 ) as $row ) {
		foreach ( (array) $row as $v ) {
			if ( 'main idea' === strtolower( trim( (string) $v ) ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Blog tab → rows in the calendar shape the detectors use.
 */
function sap_blog_calendar_rows( $items, $keywords = array() ) {
	$rows = array();
	foreach ( $items as $it ) {
		$rel    = $keywords[ sap_kw_key( $it['keyword'] ) ] ?? array();
		$rows[] = array(
			'_row'           => $it['row'],
			'code'           => 'M' . $it['m'] . '-' . $it['row'],
			'cluster'        => '',
			'keyword'        => $it['keyword'],
			'related'        => implode( '، ', $rel ),
			'related_list'   => $rel,
			'page_type'      => 'مقاله',
			'intent'         => '',
			'status'         => $it['status'] ? 'منتشر شد' : '',
			'status_key'     => '' !== $it['link'] || $it['status'] ? 'published' : 'todo',
			'owner'          => '',
			'due'            => $it['planned'],
			'published'      => $it['publish'],
			'url'            => $it['link'],
			'notes'          => $it['title'],
			'due_date'       => sap_parse_date( $it['planned'] ),
			'published_date' => sap_parse_date( $it['publish'] ),
		);
	}
	return $rows;
}

/**
 * B keyword tab → main keyword => list of co-concept keywords (and volumes).
 *
 * @return array kw_key(main) => [keyword, …]
 */
function sap_bkeyword_parse( $values ) {
	$out   = array();
	$width = 0;
	foreach ( (array) $values as $row ) {
		$width = max( $width, count( (array) $row ) );
	}
	for ( $c = 0; $c < $width; $c++ ) {
		$main = '';
		foreach ( (array) $values as $row ) {
			$kw  = trim( (string) ( $row[ $c ] ?? '' ) );
			$vol = trim( (string) ( $row[ $c + 1 ] ?? '' ) );
			if ( '' === $kw || preg_match( '/^m\s*\d+$/i', $kw ) ) {
				if ( '' === $kw ) {
					$main = '';
				}
				continue;
			}
			if ( ! is_numeric( sap_norm_text( $vol ) ) && '-' !== $vol && '' !== $vol ) {
				continue; // Not a keyword/volume pair column.
			}
			if ( '' === $main ) {
				$main                       = $kw;
				$out[ sap_kw_key( $main ) ] = array();
			} else {
				$out[ sap_kw_key( $main ) ][] = $kw;
			}
		}
	}
	return $out;
}

/**
 * Bring what the team typed in the sheet back into the calendar: status,
 * publish date, link, content source; and import articles the tool does not know.
 *
 * @return int Articles imported.
 */
function sap_calendar_import_sheet( $project, $items ) {
	if ( ! $items ) {
		return 0;
	}
	$pid    = $project['id'];
	$by_kw  = array();
	foreach ( sap_ideas( $pid ) as $i ) {
		$by_kw[ sap_kw_key( $i['keyword'] ) ] = $i;
	}
	// M-label => period as last written by the tool; for a sheet the tool never
	// wrote, the newest block is taken to be the current month.
	$months = (array) ( $project['options']['calendar_labels'][ $project['sheet_id'] ] ?? array() );
	$max_m  = max( array_column( $items, 'm' ) );
	$new    = 0;
	foreach ( $items as $it ) {
		$idea = $by_kw[ sap_kw_key( $it['keyword'] ) ] ?? null;
		$upd  = array();
		if ( '' !== $it['link'] ) {
			$upd['published_url'] = $it['link'];
			$upd['status']        = 'published';
		} elseif ( $it['status'] ) {
			$upd['status'] = 'published';
		}
		if ( '' !== $it['source'] ) {
			$upd['source_note'] = $it['source'];
		}
		if ( $idea ) {
			if ( $upd ) {
				sap_idea_save( $upd, $idea['id'] );
			}
			continue;
		}
		// A month the tool does not know: the newest block is the current month.
		$period = $months[ 'M' . $it['m'] ] ?? sap_period_shift( sap_period_current(), $it['m'] - $max_m );
		sap_idea_save( $upd + array(
			'project_id'  => $pid,
			'keyword'     => $it['keyword'],
			'title'       => $it['title'],
			'brief'       => $it['desc'],
			'kp'          => $it['kp'],
			'volume'      => $it['kp'],
			'links'       => $it['links'],
			'source_note' => $it['source'],
			'origin'      => 'sheet',
			'status'      => 'scheduled',
			'period'      => $period,
			'slot'        => $it['row'],
			'planned_date' => sap_parse_date( $it['planned'] ),
		) );
		++$new;
	}
	return $new;
}

/* ---------------------------------------------------------------------------
 * «Blog» and «B keyword» layout: write
 * ------------------------------------------------------------------------- */

/**
 * Write the content calendar to the project sheet (Blog + B keyword tabs).
 *
 * @return string|WP_Error Summary.
 */
function sap_calendar_write_sheet( $project ) {
	$sheet = $project['sheet_id'];
	if ( '' === $sheet || ! sap_google_connected() || ! sap_google_has_sheets() ) {
		return new WP_Error( 'sap_sheet', 'گوگل شیت پروژه متصل نیست.' );
	}
	$tabs = null;
	$sid  = sap_gs_ensure_tab( $sheet, SAP_TAB_BLOG, $tabs );
	if ( is_wp_error( $sid ) ) {
		return $sid;
	}
	$kid = sap_gs_ensure_tab( $sheet, SAP_TAB_BKEYWORD, $tabs );
	if ( is_wp_error( $kid ) ) {
		return $kid;
	}
	// 1. Read back what the team typed in the sheet.
	$old = sap_sheet_values( $sheet, SAP_TAB_BLOG );
	if ( ! is_wp_error( $old ) && sap_is_blog_layout( $old ) ) {
		sap_calendar_import_sheet( $project, sap_blog_parse( $old ) );
	}
	$months = sap_calendar_sheet_months( $project['id'] );
	if ( ! $months ) {
		return 'تقویم هنوز مقاله یا آپدیتی ندارد.';
	}

	// 2. Blog tab, newest month first.
	$values = array();
	$fmt    = sap_gs_reset( $sid, array( 0 => 60, 1 => 260, 2 => 60, 3 => 380, 4 => 220, 15 => 220, 16 => 70, 17 => 110, 18 => 220, 19 => 110 ) );
	foreach ( array( 5, 7, 9, 11, 13 ) as $c ) {
		$fmt[] = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $sid, 'dimension' => 'COLUMNS', 'startIndex' => $c, 'endIndex' => $c + 1 ), 'properties' => array( 'pixelSize' => 120 ), 'fields' => 'pixelSize' ) );
		$fmt[] = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $sid, 'dimension' => 'COLUMNS', 'startIndex' => $c + 1, 'endIndex' => $c + 2 ), 'properties' => array( 'pixelSize' => 160 ), 'fields' => 'pixelSize' ) );
	}
	$count = 0;
	foreach ( array_reverse( $months, true ) as $period => $label ) {
		$r0       = count( $values );
		$values[] = array_merge( array( $label, 'Writing Guide', '', '', '', '  Out Links' ), array_fill( 0, 9, '' ), array( 'Publish', '', '', '', sap_period_label( $period ) ) );
		$values[] = array( '', 'Main Idea', 'KP', 'Title', 'Desc', 'L1', 'K1', 'L2', 'K2', 'L3', 'K3', 'L4', 'K4', 'L5', 'K5', 'Content Source', 'Status', 'Publish Date', 'Link', 'تاریخ برنامه' );
		$items    = sap_calendar_items( $project['id'], $period );
		foreach ( $items as $n => $it ) {
			$row = array( $n + 1, $it['keyword'], $it['kp'] ?: '', $it['title'], $it['brief'] );
			for ( $l = 0; $l < 5; $l++ ) {
				$row[] = $it['links'][ $l ]['url'] ?? '';
				$row[] = $it['links'][ $l ]['anchor'] ?? '';
			}
			$row[]    = $it['source_note'];
			$row[]    = 'published' === $it['status'];
			$row[]    = 'published' === $it['status'] && $it['updated_at'] ? sap_jdate( substr( $it['updated_at'], 0, 10 ), 'day' ) : '';
			$row[]    = $it['published_url'];
			$row[]    = $it['planned_date'] ? sap_jdate( $it['planned_date'], 'day' ) : '';
			$values[] = $row;
			++$count;
		}
		$r1 = count( $values );
		// Content updates of the month: their own block under the new articles.
		$ups = sap_updates( $project['id'], array( 'period' => $period ) );
		$u0  = null;
		if ( $ups ) {
			$u0       = count( $values );
			$values[] = array( '', 'Update Page', 'KP', 'Title (پیشنهادی)', 'هدف و بریف آپدیت', 'L1', 'K1', 'L2', 'K2', 'L3', 'K3', 'L4', 'K4', 'L5', 'K5', 'نوع صفحه', 'Status', 'Done Date', 'Link', 'تاریخ برنامه' );
			$ctypes   = sap_content_types();
			foreach ( $ups as $n => $u ) {
				$b   = (array) $u['brief'];
				$row = array( 'U' . ( $n + 1 ), $b['keyword'] ?? $u['keyword'], '', ( $b['title'] ?? '' ) ?: $u['title'], trim( (string) ( $b['summary'] ?? '' ) ?: (string) $u['reason'] ) );
				$lo  = array_values( (array) ( $b['links_out'] ?? array() ) );
				for ( $l = 0; $l < 5; $l++ ) {
					$row[] = isset( $lo[ $l ]['url'] ) ? rawurldecode( $lo[ $l ]['url'] ) : '';
					$row[] = $lo[ $l ]['anchor'] ?? '';
				}
				$row[]    = $ctypes[ $u['content_type'] ] ?? $u['content_type'];
				$row[]    = 'done' === $u['status'];
				$row[]    = $u['done_at'] ? sap_jdate( substr( $u['done_at'], 0, 10 ), 'day' ) : '';
				$row[]    = rawurldecode( $u['url'] );
				$row[]    = sap_update_statuses()[ $u['status'] ] ?? $u['status'];
				$values[] = $row;
			}
		}
		$r2 = count( $values );
		// Header colours and merges, as in the agency template.
		$fmt[] = sap_gs_merge( $sid, $r0, $r0 + 2, 0, 1 );
		$fmt[] = sap_gs_merge( $sid, $r0, $r0 + 1, 1, 5 );
		$fmt[] = sap_gs_merge( $sid, $r0, $r0 + 1, 5, 15 );
		$fmt[] = sap_gs_merge( $sid, $r0, $r0 + 1, 15, 19 );
		$fmt[] = sap_gs_fmt( $sid, $r0, $r0 + 2, 0, 1, '#FFFFFF', '#000000', true );
		$fmt[] = sap_gs_fmt( $sid, $r0, $r0 + 1, 1, 5, '#20124D', '#FFFFFF', true );
		$fmt[] = sap_gs_fmt( $sid, $r0, $r0 + 1, 5, 15, '#990000', '#FFFFFF', true );
		$fmt[] = sap_gs_fmt( $sid, $r0, $r0 + 1, 15, 19, '#9900FF', '#FFFFFF', true );
		$fmt[] = sap_gs_fmt( $sid, $r0 + 1, $r0 + 2, 1, 20, '#D9D2E9', '#000000', true );
		if ( $r1 > $r0 + 2 ) {
			$fmt[] = sap_gs_fmt( $sid, $r0 + 2, $r1, 0, 20 );
			$fmt[] = array( 'setDataValidation' => array( 'range' => array( 'sheetId' => $sid, 'startRowIndex' => $r0 + 2, 'endRowIndex' => $r1, 'startColumnIndex' => 16, 'endColumnIndex' => 17 ), 'rule' => array( 'condition' => array( 'type' => 'BOOLEAN' ) ) ) );
		}
		if ( null !== $u0 ) {
			$fmt[] = sap_gs_fmt( $sid, $u0, $u0 + 1, 1, 20, '#F9CB9C', '#000000', true );
			$fmt[] = sap_gs_fmt( $sid, $u0 + 1, $r2, 0, 20, '#FFF7EE' );
			$fmt[] = array( 'setDataValidation' => array( 'range' => array( 'sheetId' => $sid, 'startRowIndex' => $u0 + 1, 'endRowIndex' => $r2, 'startColumnIndex' => 16, 'endColumnIndex' => 17 ), 'rule' => array( 'condition' => array( 'type' => 'BOOLEAN' ) ) ) );
		}
		$values[] = array();
	}
	$res = sap_gs_put_tab( $sheet, SAP_TAB_BLOG, $values );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$labels           = (array) ( $project['options']['calendar_labels'] ?? array() );
	$labels[ $sheet ] = array_flip( $months );
	sap_project_set_option( $project['id'], 'calendar_labels', $labels );
	$res = sap_gs_batch( $sheet, $fmt );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	// 3. B keyword tab: per month a keyword/volume column pair (5 columns apart).
	$grid = array();
	$kfmt = sap_gs_reset( $kid );
	$col  = 1;
	foreach ( array_reverse( $months, true ) as $period => $label ) {
		$grid[1][ $col ] = $label;
		$kfmt[]          = sap_gs_merge( $kid, 1, 2, $col, $col + 2 );
		$kfmt[]          = sap_gs_fmt( $kid, 1, 2, $col, $col + 2, '#93C47D', '#000000', true );
		$kfmt[]          = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $kid, 'dimension' => 'COLUMNS', 'startIndex' => $col, 'endIndex' => $col + 1 ), 'properties' => array( 'pixelSize' => 260 ), 'fields' => 'pixelSize' ) );
		$r = 3;
		foreach ( sap_calendar_items( $project['id'], $period ) as $it ) {
			$first           = $r;
			$grid[ $r ][ $col ]     = $it['keyword'];
			$grid[ $r ][ $col + 1 ] = $it['kp'] ?: ( $it['volume'] ?: '' );
			$kfmt[]          = sap_gs_fmt( $kid, $r, $r + 1, $col, $col + 2, '#FFF2CC', '#000000', true );
			++$r;
			foreach ( sap_idea_secondary( $it ) as $s ) {
				$grid[ $r ][ $col ]     = $s[0];
				$grid[ $r ][ $col + 1 ] = $s[1] ?: '-';
				++$r;
			}
			$letter                 = sap_sheet_col( $col + 1 );
			$grid[ $r ][ $col + 1 ] = sprintf( '=SUM(%1$s%2$d:%1$s%3$d)', $letter, $first + 1, $r );
			$kfmt[]                 = sap_gs_fmt( $kid, $r, $r + 1, $col + 1, $col + 2, '#D9EAD3', '#000000', true );
			$r                     += 2;
		}
		$col += 5;
	}
	$kvalues = array();
	$maxr    = $grid ? max( array_keys( $grid ) ) : 0;
	for ( $i = 0; $i <= $maxr; $i++ ) {
		$line = array();
		if ( isset( $grid[ $i ] ) ) {
			for ( $c = 0; $c <= max( array_keys( $grid[ $i ] ) ); $c++ ) {
				$line[] = $grid[ $i ][ $c ] ?? '';
			}
		}
		$kvalues[] = $line;
	}
	$res = sap_gs_put_tab( $sheet, SAP_TAB_BKEYWORD, $kvalues );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	sap_gs_batch( $sheet, $kfmt );
	return sprintf( 'تقویم محتوا (%s مقاله در %s ماه) در تب‌های Blog و B keyword نوشته شد', sap_num( $count ), sap_num( count( $months ) ) );
}

/* ---------------------------------------------------------------------------
 * Keyword map and change log tabs
 * ------------------------------------------------------------------------- */

/**
 * Write the keyword map tab (full rewrite).
 */
function sap_keymap_write_sheet( $project ) {
	$sheet = $project['sheet_id'];
	$tabs  = null;
	$sid   = sap_gs_ensure_tab( $sheet, SAP_TAB_KEYMAP, $tabs );
	if ( is_wp_error( $sid ) ) {
		return $sid;
	}
	$impr = array();
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$impr[ sap_norm_url( $r['page'] ) ] = $r;
	}
	$values = array( array( 'صفحه', 'آدرس', 'نوع صفحه', 'کلمه کلیدی اصلی', 'کلمات فرعی و هم‌کانسپت', 'نیت جستجو', 'منبع', 'تأیید شده', 'نمایش ۲۸ روز', 'کلیک ۲۸ روز', 'وضعیت', 'ریدایرکت به' ) );
	$ctypes = sap_content_types();
	foreach ( sap_keymap( $project['id'] ) as $k => $m ) {
		$values[] = array( $m['title'], rawurldecode( $m['url'] ), $ctypes[ sap_content_type( $m ) ] ?? ( sap_keymap_page_types()[ $m['page_type'] ] ?? '' ), sap_keymap_has_kw( $m['status'] ) ? $m['primary_kw'] : '', implode( '، ', $m['secondary'] ), sap_keymap_intents()[ $m['intent'] ] ?? '', sap_keymap_sources()[ $m['source'] ] ?? $m['source'], $m['confirmed'] || 'sheet' === $m['source'] ? 'بله' : '', (int) ( $impr[ $k ]['impressions'] ?? 0 ), (int) ( $impr[ $k ]['clicks'] ?? 0 ), sap_keymap_statuses()[ $m['status'] ] ?? '', rawurldecode( $m['redirect_to'] ) );
	}
	$res = sap_gs_put_tab( $sheet, SAP_TAB_KEYMAP, $values );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$fmt   = sap_gs_reset( $sid, array( 0 => 220, 1 => 280, 2 => 110, 3 => 180, 4 => 320, 5 => 100, 6 => 120, 7 => 80, 8 => 100, 9 => 100, 10 => 170, 11 => 240 ) );
	$fmt[] = sap_gs_fmt( $sid, 0, 1, 0, 12, '#1D4ED8', '#FFFFFF', true );
	$fmt[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => $sid, 'gridProperties' => array( 'frozenRowCount' => 1 ) ), 'fields' => 'gridProperties.frozenRowCount' ) );
	sap_gs_batch( $sheet, $fmt );
	return sprintf( 'نقشه کلمات (%s صفحه)', sap_num( count( $values ) - 1 ) );
}

/**
 * Append new change log entries to the change log tab.
 */
function sap_log_write_sheet( $project ) {
	global $wpdb;
	$sheet = $project['sheet_id'];
	$tabs  = null;
	$sid   = sap_gs_ensure_tab( $sheet, SAP_TAB_LOG, $tabs );
	if ( is_wp_error( $sid ) ) {
		return $sid;
	}
	$last = (int) ( $project['options']['sheet_log_last'] ?? 0 );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'activity' ) . ' WHERE project_id = %d AND id > %d ORDER BY id ASC LIMIT 1000', $project['id'], $last ), ARRAY_A ); // phpcs:ignore
	$head = array( 'شناسه', 'تاریخ', 'حوزه', 'اقدام', 'صفحه', 'آدرس', 'قبل', 'بعد', 'منبع', 'Focus', 'انجام‌دهنده' );
	if ( ! $last ) {
		$res = sap_gs_put_tab( $sheet, SAP_TAB_LOG, array( $head ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$fmt   = sap_gs_reset( $sid, array( 0 => 60, 1 => 110, 2 => 140, 3 => 360, 4 => 200, 5 => 260, 6 => 220, 7 => 220, 8 => 120, 9 => 120, 10 => 120 ) );
		$fmt[] = sap_gs_fmt( $sid, 0, 1, 0, 11, '#1D4ED8', '#FFFFFF', true );
		$fmt[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => $sid, 'gridProperties' => array( 'frozenRowCount' => 1 ) ), 'fields' => 'gridProperties.frozenRowCount' ) );
		sap_gs_batch( $sheet, $fmt );
	}
	if ( ! $rows ) {
		return 'لاگ تغییرات بروز است';
	}
	$fnames  = wp_list_pluck( sap_focuses( $project['id'] ), 'name', 'id' );
	$sources = sap_activity_sources();
	$values  = array();
	foreach ( $rows as $r ) {
		$values[] = array(
			(int) $r['id'],
			sap_jdate( substr( $r['happened_at'], 0, 10 ), 'short' ),
			sap_context_label( $r['category'] ),
			$r['title'],
			$r['url'] ? sap_page_title( $project['id'], $r['url'] ) : '',
			rawurldecode( (string) $r['url'] ),
			mb_substr( (string) $r['before_value'], 0, 500 ),
			mb_substr( (string) $r['after_value'], 0, 500 ),
			$sources[ $r['source'] ] ?? $r['source'],
			$fnames[ (int) $r['focus_id'] ] ?? '',
			$r['user_id'] ? sap_user_name( (int) $r['user_id'] ) : '',
		);
	}
	$res = sap_google_request( 'POST', sap_gs_url( $sheet, '/values/' . rawurlencode( sap_sheet_range( SAP_TAB_LOG, 'A1' ) ) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS' ), array( 'values' => $values ), 60 );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	sap_project_set_option( $project['id'], 'sheet_log_last', (int) end( $rows )['id'] );
	return sprintf( '%s اقدام جدید در لاگ تغییرات', sap_num( count( $values ) ) );
}

/* ---------------------------------------------------------------------------
 * Create the project sheet
 * ------------------------------------------------------------------------- */

/**
 * Create the project's Google Sheet (in the connected Google account) with all
 * tabs, connect it to the project and fill it.
 *
 * @param string $existing Optional: an existing (empty) sheet to set up instead.
 * @return array|WP_Error {id, url, msg}
 */
function sap_project_sheet_create( $project, $existing = '' ) {
	if ( ! sap_google_connected() || ! sap_google_has_sheets() ) {
		return new WP_Error( 'sap_sheet', 'حساب گوگل با دسترسی Google Sheets متصل نیست (تنظیمات › اتصال حساب گوگل).' );
	}
	$existing = sap_sheet_id_from_url( $existing );
	if ( '' === $existing && 'service' === sap_setting( 'google_auth_mode' ) ) {
		return new WP_Error( 'sap_sheet_sa', sprintf( 'با سرویس اکانت، شیت ساخته‌شده در Drive خود سرویس اکانت می‌ماند و شما آن را نمی‌بینید. یک گوگل شیت خالی بسازید، با %s (Editor) به‌اشتراک بگذارید و لینکش را در کادر «شیت موجود» وارد کنید.', sap_google_service_email() ) );
	}
	$cluster_tab = 'کلاسترینگ';
	if ( '' === $existing ) {
		$res = sap_google_request( 'POST', 'https://sheets.googleapis.com/v4/spreadsheets', array(
			'properties' => array( 'title' => 'سکان · ' . $project['name'], 'locale' => 'fa_IR', 'timeZone' => 'Asia/Tehran' ),
			'sheets'     => array_map( function ( $t ) {
				return array( 'properties' => array( 'title' => $t, 'rightToLeft' => true ) );
			}, array( SAP_TAB_BLOG, SAP_TAB_BKEYWORD, $cluster_tab, SAP_TAB_KEYMAP, SAP_TAB_LOG ) ),
		), 60 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$sheet_id = (string) ( $res['spreadsheetId'] ?? '' );
		if ( '' === $sheet_id ) {
			return new WP_Error( 'sap_sheet', 'گوگل شیت ساخته نشد.' );
		}
	} else {
		$sheet_id = $existing;
		$tabs     = null;
		foreach ( array( SAP_TAB_BLOG, SAP_TAB_BKEYWORD, $cluster_tab, SAP_TAB_KEYMAP, SAP_TAB_LOG ) as $t ) {
			$r = sap_gs_ensure_tab( $sheet_id, $t, $tabs );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
	}
	global $wpdb;
	$wpdb->update( sap_table( 'projects' ), array( 'sheet_id' => $sheet_id, 'sheet_calendar_tab' => SAP_TAB_BLOG, 'sheet_cluster_tab' => $cluster_tab ), array( 'id' => $project['id'] ) );
	unset( $GLOBALS['sap_project_cache'] );
	sap_project_set_option( $project['id'], 'sheet_created', sap_now() );
	sap_project_set_option( $project['id'], 'sheet_log_last', 0 );
	$project = sap_project( $project['id'] );

	// Clustering tab seeded from the keyword map: each page's primary keyword is
	// its «پیلار», its secondary keywords «فرعی».
	$schema = sap_sheet_schema()['clusters'];
	$head   = array();
	foreach ( $schema['columns'] as $def ) {
		$head[] = $def[0];
	}
	$values = array( $head );
	$fnames = wp_list_pluck( sap_focuses( $project['id'] ), 'name', 'id' );
	$cl_tab = sap_gs_tabs( $sheet_id );
	foreach ( sap_keymap( $project['id'] ) as $m ) {
		if ( '' === $m['primary_kw'] || 'home' === $m['page_type'] ) {
			continue;
		}
		$cluster  = $fnames[ $m['focus_id'] ] ?? $m['title'];
		$values[] = array( $cluster, $m['title'], $m['primary_kw'], 'پیلار', $m['url'], '', sap_keymap_intents()[ $m['intent'] ] ?? '', '' );
		foreach ( array_slice( $m['secondary'], 0, 10 ) as $s ) {
			$values[] = array( $cluster, $m['title'], $s, 'فرعی', $m['url'], '', '', '' );
		}
	}
	$msgs = array();
	$r    = sap_gs_put_tab( $sheet_id, $cluster_tab, $values );
	if ( ! is_wp_error( $r ) && ! is_wp_error( $cl_tab ) && isset( $cl_tab[ $cluster_tab ] ) ) {
		$sid   = $cl_tab[ $cluster_tab ];
		$fmt   = sap_gs_reset( $sid, array( 0 => 160, 1 => 180, 2 => 220, 3 => 80, 4 => 300, 5 => 70, 6 => 100, 7 => 200 ) );
		$fmt[] = sap_gs_fmt( $sid, 0, 1, 0, count( $head ), '#1D4ED8', '#FFFFFF', true );
		$fmt[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => $sid, 'gridProperties' => array( 'frozenRowCount' => 1 ) ), 'fields' => 'gridProperties.frozenRowCount' ) );
		sap_gs_batch( $sheet_id, $fmt );
		$msgs[] = sprintf( 'کلاسترینگ (%s کلمه از نقشه کلمات)', sap_num( count( $values ) - 1 ) );
	}
	foreach ( array( 'sap_calendar_write_sheet', 'sap_keymap_write_sheet', 'sap_log_write_sheet' ) as $fn ) {
		$r = call_user_func( $fn, $project );
		$msgs[] = is_wp_error( $r ) ? $r->get_error_message() : $r;
		$project = sap_project( $project['id'] );
	}
	return array(
		'id'  => $sheet_id,
		'url' => 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/edit',
		'msg' => 'گوگل شیت پروژه آماده شد: ' . implode( ' · ', array_filter( $msgs ) ),
	);
}

/**
 * Write the «content update» half of the calendar to its own tab.
 */
function sap_updates_write_sheet( $project ) {
	$sheet = $project['sheet_id'];
	$tabs  = null;
	$sid   = sap_gs_ensure_tab( $sheet, SAP_TAB_UPDATES, $tabs );
	if ( is_wp_error( $sid ) ) {
		return $sid;
	}
	$types  = sap_content_types();
	$status = sap_update_statuses();
	$values = array( array( 'ماه', 'صفحه', 'آدرس', 'نوع', 'کلمه اصلی', 'کلمات فرعی', 'دلیل / هدف آپدیت', 'تایتل پیشنهادی', 'بخش‌های جدید', 'ویژگی‌ها', 'FAQ', 'وضعیت', 'تاریخ انجام' ) );
	foreach ( sap_updates( $project['id'] ) as $u ) {
		$b        = $u['brief'];
		$values[] = array(
			sap_period_label( $u['period'] ),
			$u['title'],
			rawurldecode( $u['url'] ),
			$types[ $u['content_type'] ] ?? $u['content_type'],
			$b['keyword'] ?? $u['keyword'],
			implode( '، ', (array) ( $b['secondary'] ?? $u['secondary'] ) ),
			$b['summary'] ?? $u['reason'],
			$b['title'] ?? '',
			implode( "\n", array_map( function ( $o ) {
				return ( $o['heading'] ?? '' );
			}, (array) ( $b['outline'] ?? array() ) ) ),
			implode( "\n", array_map( function ( $a ) {
				return ( $a['name'] ?? '' ) . ': ' . ( $a['hint'] ?? '' );
			}, (array) ( $b['attributes'] ?? array() ) ) ),
			implode( "\n", (array) ( $b['faq'] ?? array() ) ),
			$status[ $u['status'] ] ?? $u['status'],
			$u['done_at'] ? sap_jdate( substr( $u['done_at'], 0, 10 ), 'short' ) : '',
		);
	}
	$res = sap_gs_put_tab( $sheet, SAP_TAB_UPDATES, $values );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$fmt   = sap_gs_reset( $sid, array( 0 => 100, 1 => 200, 2 => 260, 3 => 100, 4 => 160, 5 => 220, 6 => 300, 7 => 240, 8 => 240, 9 => 220, 10 => 240, 11 => 100, 12 => 100 ) );
	$fmt[] = sap_gs_fmt( $sid, 0, 1, 0, 13, '#1D4ED8', '#FFFFFF', true );
	$fmt[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => $sid, 'gridProperties' => array( 'frozenRowCount' => 1 ) ), 'fields' => 'gridProperties.frozenRowCount' ) );
	sap_gs_batch( $sheet, $fmt );
	return sprintf( 'آپدیت محتوا (%s صفحه)', sap_num( count( $values ) - 1 ) );
}

/**
 * Push the calendar (articles + content updates) to the project sheet right
 * after a change, so the sheet is the team's live plan.
 *
 * @return string Short suffix for the user message ('' when no sheet).
 */
function sap_calendar_sync_sheet( $project ) {
	if ( empty( $project['sheet_id'] ) || ! sap_google_connected() || ! sap_google_has_sheets() ) {
		return '.';
	}
	$a = sap_calendar_write_sheet( $project );
	$b = sap_updates_write_sheet( $project );
	if ( is_wp_error( $a ) || is_wp_error( $b ) ) {
		return '؛ نوشتن در گوگل شیت ناموفق بود: ' . ( is_wp_error( $a ) ? $a->get_error_message() : $b->get_error_message() );
	}
	return ' و در تقویم گوگل شیت (تب Blog و «' . SAP_TAB_UPDATES . '») هم ثبت شد.';
}
