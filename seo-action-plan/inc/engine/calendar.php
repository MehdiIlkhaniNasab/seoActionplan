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

/**
 * Keep long text inside its cell (no spill over the next empty columns) and
 * align it to the start, for data rows.
 */
function sap_gs_clip( $sid, $r1, $r2, $c1, $c2, $align = 'RIGHT' ) {
	return array( 'repeatCell' => array(
		'range'  => array( 'sheetId' => $sid, 'startRowIndex' => $r1, 'endRowIndex' => $r2, 'startColumnIndex' => $c1, 'endColumnIndex' => $c2 ),
		'cell'   => array( 'userEnteredFormat' => array( 'wrapStrategy' => 'CLIP', 'horizontalAlignment' => $align, 'verticalAlignment' => 'MIDDLE' ) ),
		'fields' => 'userEnteredFormat(wrapStrategy,horizontalAlignment,verticalAlignment)',
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
	$kind  = 'idea';
	$names = array( 'main idea' => 'keyword', 'update page' => 'keyword', 'kp' => 'kp', 'title' => 'title', 'title (پیشنهادی)' => 'title', 'desc' => 'desc', 'هدف و بریف آپدیت' => 'desc', 'content source' => 'source', 'status' => 'status', 'publish date' => 'publish', 'done date' => 'publish', 'link' => 'link', 'تاریخ برنامه' => 'planned', 'id' => 'id', 'تایتل پیشنهادی' => 'title', 'هدف آپدیت' => 'desc', 'رتبه قبل' => 'rank', 'کلیک ۲۸ روز قبل' => 'clicks' );
	foreach ( (array) $values as $i => $row ) {
		$a = trim( (string) ( $row[0] ?? '' ) );
		if ( preg_match( '/^m\s*(\d+)$/i', $a, $mm ) ) {
			$m = (int) $mm[1];
			continue;
		}
		$lower = array_map( function ( $v ) {
			return strtolower( trim( (string) $v ) );
		}, (array) $row );
		if ( in_array( 'main idea', $lower, true ) || in_array( 'update page', $lower, true ) ) {
			$kind = in_array( 'update page', $lower, true ) ? 'update' : 'idea';
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
		$id    = $get( 'id' );
		$out[] = array(
			'kind'    => $kind,
			'id'      => preg_match( '/^[iu](\d+)$/i', $id, $im ) ? (int) $im[1] : 0,
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
			'rank'    => $get( 'rank' ),
			'clicks'  => $get( 'clicks' ),
			'has_id'  => isset( $cols['id'] ),
			'has'     => array_keys( $cols ),
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
		if ( 'update' === ( $it['kind'] ?? 'idea' ) ) {
			continue; // Update rows are existing pages, not articles of the calendar.
		}
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
 * One sheet cell in a comparable form (spaces, Persian digits / separators,
 * link lists), so what Sokan wrote and what the sheet returns can be compared.
 */
function sap_blog_norm( $field, $v ) {
	if ( 'links' === $field ) {
		return implode( "\n", array_map( function ( $l ) {
			return trim( rawurldecode( (string) ( $l['url'] ?? '' ) ) ) . '|' . trim( preg_replace( '/\s+/u', ' ', (string) ( $l['anchor'] ?? '' ) ) );
		}, array_values( array_filter( (array) $v, function ( $l ) {
			return '' !== trim( (string) ( $l['url'] ?? '' ) );
		} ) ) ) );
	}
	if ( 'status' === $field ) {
		return $v ? '1' : '0';
	}
	if ( in_array( $field, array( 'kp', 'rank', 'clicks' ), true ) ) {
		$n = sap_cplan_number( (string) $v, 'rank' === $field );
		return null === $n || ! is_numeric( $n ) ? '' : rtrim( rtrim( number_format( (float) $n, 1, '.', '' ), '0' ), '.' );
	}
	return trim( preg_replace( '/\s+/u', ' ', sap_norm_text( (string) $v ) ) );
}

/**
 * The cells of a calendar row as Sokan writes them to the Blog tab (the snapshot
 * that tells later which cells the team changed in the sheet).
 */
function sap_blog_snap_row( $kind, $row, $period ) {
	if ( 'update' === $kind ) {
		$b = (array) $row['brief'];
		return array(
			'keyword' => sap_blog_norm( 'keyword', $row['keyword'] ?: $row['title'] ),
			'title'   => sap_blog_norm( 'title', ( $b['title'] ?? '' ) ?: $row['title'] ),
			'desc'    => sap_blog_norm( 'desc', sap_update_goal( $row ) ),
			'links'   => sap_blog_norm( 'links', array_slice( (array) $row['links'], 0, 3 ) ),
			'source'  => sap_blog_norm( 'source', $row['source_note'] ),
			'status'  => sap_blog_norm( 'status', 'done' === $row['status'] ),
			'rank'    => sap_blog_norm( 'rank', null === $row['rank_before'] ? '' : $row['rank_before'] ),
			'clicks'  => sap_blog_norm( 'clicks', null === $row['clicks_before'] ? '' : $row['clicks_before'] ),
			'period'  => $period,
		);
	}
	return array(
		'keyword' => sap_blog_norm( 'keyword', $row['keyword'] ),
		'kp'      => sap_blog_norm( 'kp', $row['kp'] ?: '' ),
		'title'   => sap_blog_norm( 'title', $row['title'] ),
		'desc'    => sap_blog_norm( 'desc', $row['brief'] ),
		'links'   => sap_blog_norm( 'links', array_slice( (array) $row['links'], 0, 5 ) ),
		'source'  => sap_blog_norm( 'source', $row['source_note'] ),
		'status'  => sap_blog_norm( 'status', 'published' === $row['status'] ),
		'link'    => sap_blog_norm( 'link', $row['published_url'] ),
		'period'  => $period,
	);
}

/**
 * Snapshot of the Blog tab as Sokan last wrote it: «i{id}» / «u{id}» => cells.
 */
function sap_blog_snap( $project ) {
	$s = get_option( 'sap_blog_snap_' . (int) $project['id'] . '_' . md5( (string) $project['sheet_id'] ), array() );
	return is_array( $s ) ? $s : array();
}

/**
 * Bring back what the team changed in the Blog tab, and only that.
 *
 *  - A cell comes back only when it differs from what Sokan wrote there last
 *    time (the snapshot): an edit made in the panel since then is never undone
 *    by the older copy still in the sheet.
 *  - Rows are matched by their ID column only (i12 / u5). A row whose item no
 *    longer exists in Sokan is ignored (never re-created).
 *  - A row the team added without an ID becomes a new article — unless its
 *    keyword or link is already an article, an idea or a page in the updates.
 *  - A row Sokan wrote that is missing now leaves the calendar only when the
 *    read is clearly complete (its month block is there and most rows are) and
 *    nobody is working on it (no live task in the action plan).
 *
 * @param string $tab The tab read (deletions are trusted only for the Blog tab).
 * @return int Articles imported.
 */
function sap_calendar_import_sheet( $project, $items, $tab = SAP_TAB_BLOG ) {
	if ( ! $items ) {
		return 0;
	}
	$pid    = $project['id'];
	$snap   = sap_blog_snap( $project );
	$by_id  = array();
	$known  = array(); // Keyword keys and URLs that already have a place.
	foreach ( sap_ideas( $pid ) as $i ) {
		$by_id[ (int) $i['id'] ] = $i;
		if ( 'rejected' !== $i['status'] ) {
			$known[ sap_kw_key( $i['keyword'] ) ] = true;
			if ( '' !== $i['published_url'] ) {
				$known[ sap_norm_url( $i['published_url'] ) ] = true;
			}
		}
	}
	foreach ( sap_updates( $pid ) as $u ) {
		if ( 'cancelled' !== $u['status'] ) {
			$known[ sap_kw_key( $u['keyword'] ) ] = true;
			$known[ sap_kw_key( $u['title'] ) ]   = true;
			$known[ sap_norm_url( $u['url'] ) ]   = true;
		}
	}
	unset( $known[''] );
	// M-label => period as last written by the tool.
	$months = (array) ( $project['options']['calendar_labels'][ $project['sheet_id'] ] ?? array() );
	$max_m  = max( array_column( $items, 'm' ) );
	$new    = 0;
	$seen   = array( 'idea' => array(), 'update' => array() );
	$blocks = array();
	$get_p  = function ( $m ) use ( $months, $max_m ) {
		return $months[ 'M' . $m ] ?? sap_period_shift( sap_period_current(), $m - $max_m );
	};
	// Cells the team changed: differ from the snapshot. No snapshot for the row (first
	// read after an upgrade): only the «published» tick and the link are trusted.
	$changed = function ( $was, $field, $value ) {
		if ( null === $was ) {
			return in_array( $field, array( 'status', 'link' ), true ) && '' !== $value && '0' !== $value;
		}
		return array_key_exists( $field, $was ) && $value !== (string) $was[ $field ];
	};
	foreach ( $items as $it ) {
		$period             = $get_p( $it['m'] );
		$blocks[ $period ] = true;
		$is_upd             = 'update' === $it['kind'];
		$was                = $it['id'] ? ( $snap[ ( $is_upd ? 'u' : 'i' ) . $it['id'] ] ?? null ) : null;
		$moved              = $was && isset( $months[ 'M' . $it['m'] ] ) && $period !== (string) ( $was['period'] ?? $period );
		if ( $is_upd ) {
			$u = $it['id'] ? sap_update( $it['id'] ) : null;
			if ( ! $u || (int) $u['project_id'] !== (int) $pid ) {
				continue;
			}
			$seen['update'][ (int) $u['id'] ] = true;
			$has = array_flip( (array) ( $it['has'] ?? array() ) );
			if ( ! isset( $has['rank'] ) ) {
				$was = null; // A block written before 0.32: only the tick comes back.
			}
			$b   = (array) $u['brief'];
			$upd = array();
			$v   = array(
				'keyword' => sap_blog_norm( 'keyword', $it['keyword'] ),
				'title'   => sap_blog_norm( 'title', $it['title'] ),
				'desc'    => sap_blog_norm( 'desc', $it['desc'] ),
				'links'   => sap_blog_norm( 'links', $it['links'] ),
				'source'  => sap_blog_norm( 'source', $it['source'] ),
				'status'  => sap_blog_norm( 'status', $it['status'] ),
				'rank'    => sap_blog_norm( 'rank', $it['rank'] ),
				'clicks'  => sap_blog_norm( 'clicks', $it['clicks'] ),
			);
			if ( $was ) {
				if ( $changed( $was, 'keyword', $v['keyword'] ) && '' !== $v['keyword'] ) {
					$upd['keyword'] = $it['keyword'];
				}
				if ( $changed( $was, 'title', $v['title'] ) ) {
					$b['title']   = $it['title'];
					$upd['brief'] = $b;
				}
				if ( $changed( $was, 'desc', $v['desc'] ) ) {
					$b['goal']    = $it['desc'];
					$upd['brief'] = $b;
				}
				if ( $changed( $was, 'links', $v['links'] ) ) {
					$upd['links'] = array_merge( $it['links'], array_slice( (array) $u['links'], 3 ) );
				}
				if ( $changed( $was, 'source', $v['source'] ) ) {
					$upd['source_note'] = $it['source'];
				}
				foreach ( array( 'rank' => 'rank_before', 'clicks' => 'clicks_before' ) as $ik => $uk ) {
					if ( $changed( $was, $ik, $v[ $ik ] ) ) {
						$upd[ $uk ] = '' === $v[ $ik ] ? null : $v[ $ik ];
					}
				}
			}
			if ( $changed( $was, 'status', $v['status'] ) || ( null === $was && '1' === $v['status'] ) ) {
				if ( '1' === $v['status'] && 'done' !== $u['status'] ) {
					$upd['status'] = 'done';
				} elseif ( '0' === $v['status'] && 'done' === $u['status'] && $was ) {
					$upd['status'] = $u['task_id'] ? 'in_progress' : 'planned';
				}
			}
			if ( $moved ) {
				$upd['period'] = $period;
			}
			if ( $upd ) {
				sap_update_save( $upd, $u['id'] );
			}
			continue;
		}
		// Articles: by the ID column only.
		if ( $it['id'] ) {
			$idea = $by_id[ $it['id'] ] ?? null;
			if ( ! $idea ) {
				continue; // Deleted in Sokan: the old sheet row does not bring it back.
			}
			$seen['idea'][ (int) $idea['id'] ] = true;
			$v   = array(
				'keyword' => sap_blog_norm( 'keyword', $it['keyword'] ),
				'kp'      => sap_blog_norm( 'kp', $it['kp'] ?: '' ),
				'title'   => sap_blog_norm( 'title', $it['title'] ),
				'desc'    => sap_blog_norm( 'desc', $it['desc'] ),
				'links'   => sap_blog_norm( 'links', $it['links'] ),
				'source'  => sap_blog_norm( 'source', $it['source'] ),
				'status'  => sap_blog_norm( 'status', $it['status'] ),
				'link'    => sap_blog_norm( 'link', $it['link'] ),
			);
			$upd = array();
			foreach ( array( 'keyword' => 'keyword', 'title' => 'title', 'desc' => 'brief', 'source' => 'source_note' ) as $f => $col ) {
				if ( $was && $changed( $was, $f, $v[ $f ] ) && ( 'keyword' !== $f || '' !== $v[ $f ] ) ) {
					$upd[ $col ] = 'desc' === $f ? $it['desc'] : $it[ $f ];
				}
			}
			if ( $was && $changed( $was, 'kp', $v['kp'] ) ) {
				$upd['kp'] = (int) $v['kp'];
			}
			if ( $was && $changed( $was, 'links', $v['links'] ) ) {
				$upd['links'] = $it['links'];
			}
			if ( $changed( $was, 'link', $v['link'] ) && '' !== $v['link'] ) {
				$upd['published_url'] = $it['link'];
				$upd['status']        = 'published';
			} elseif ( $changed( $was, 'status', $v['status'] ) ) {
				if ( '1' === $v['status'] ) {
					$upd['status'] = 'published';
				} elseif ( $was && '' === $v['link'] && 'published' === $idea['status'] ) {
					$upd['status']        = 'scheduled'; // Unticked in the sheet and no link.
					$upd['published_url'] = '';
				}
			}
			if ( isset( $upd['status'] ) && 'published' === $upd['status'] ) {
				$pub = sap_parse_date( $it['publish'] );
				if ( $pub ) {
					$upd['published_at'] = $pub;
				}
			}
			if ( $moved ) {
				$upd['period'] = $period; // Moved to another month block.
			}
			if ( $upd ) {
				sap_idea_save( $upd, $idea['id'] );
			}
			continue;
		}
		// A row the team added by hand (no ID).
		if ( isset( $known[ sap_kw_key( $it['keyword'] ) ] ) || ( '' !== $it['link'] && isset( $known[ sap_norm_url( $it['link'] ) ] ) ) ) {
			continue;
		}
		$id = sap_idea_save( array(
			'project_id'   => $pid,
			'keyword'      => $it['keyword'],
			'title'        => $it['title'],
			'brief'        => $it['desc'],
			'kp'           => $it['kp'],
			'volume'       => $it['kp'],
			'links'        => $it['links'],
			'source_note'  => $it['source'],
			'origin'       => 'sheet',
			'status'       => '' !== $it['link'] || $it['status'] ? 'published' : 'scheduled',
			'published_url' => $it['link'],
			'period'       => $period,
			'slot'         => $it['row'],
			'planned_date' => sap_parse_date( $it['planned'] ),
		) );
		if ( $id ) {
			$seen['idea'][ (int) $id ] = true;
			$known[ sap_kw_key( $it['keyword'] ) ] = true;
			++$new;
		}
	}
	// Rows deleted from the sheet: only for the tab Sokan writes, only when the read is
	// clearly complete, and never for an article someone is working on.
	$snap_ideas = array_filter( array_keys( $snap ), function ( $k ) {
		return 'i' === $k[0];
	} );
	if ( SAP_TAB_BLOG === $tab && $snap_ideas ) {
		$seen_n = count( array_intersect( array_map( function ( $k ) {
			return (int) substr( $k, 1 );
		}, $snap_ideas ), array_keys( $seen['idea'] ) ) );
		// A complete read: at most a few rows (2, or 30% of a big month) are missing.
		if ( count( $snap_ideas ) - $seen_n <= max( 2, (int) ceil( 0.3 * count( $snap_ideas ) ) ) ) {
			foreach ( $snap_ideas as $k ) {
				$idea = $by_id[ (int) substr( $k, 1 ) ] ?? null;
				if ( ! $idea || isset( $seen['idea'][ $idea['id'] ] ) || ! in_array( $idea['status'], array( 'scheduled', 'approved' ), true ) || ! isset( $blocks[ (string) ( $snap[ $k ]['period'] ?? '' ) ] ) ) {
					continue;
				}
				$t = $idea['task_id'] ? sap_task( $idea['task_id'] ) : null;
				if ( $t && 'dropped' !== $t['status'] ) {
					continue;
				}
				sap_idea_save( array( 'status' => 'rejected' ), $idea['id'] );
			}
		}
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
	$count   = 0;
	$written = array( 'idea' => array(), 'update' => array() );
	$snapw   = array();
	$ntrack  = function_exists( 'sap_track_all' ) ? sap_track_all( $project['id'] ) : array();
	$kinds   = sap_update_kinds();
	$kc      = array( 'cat' => '#EDE7F6', 'product' => '#E0F2F1', 'post' => '#E3F2FD', 'other' => '#F3F3F3' );
	$fmt[]   = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $sid, 'dimension' => 'COLUMNS', 'startIndex' => 20, 'endIndex' => 21 ), 'properties' => array( 'pixelSize' => 60 ), 'fields' => 'pixelSize' ) );
	$fmt[]   = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $sid, 'dimension' => 'COLUMNS', 'startIndex' => 21, 'endIndex' => 24 ), 'properties' => array( 'pixelSize' => 96 ), 'fields' => 'pixelSize' ) );
	foreach ( array_reverse( $months, true ) as $period => $label ) {
		$r0       = count( $values );
		$values[] = array_merge( array( $label, 'Writing Guide', '', '', '', '  Out Links' ), array_fill( 0, 9, '' ), array( 'Publish', '', '', '', sap_period_label( $period ) ) );
		// «جدید» marks the new-content block; the results columns after ID are filled by Sokan.
		$values[] = array( 'جدید', 'Main Idea', 'KP', 'Title', 'Desc', 'L1', 'K1', 'L2', 'K2', 'L3', 'K3', 'L4', 'K4', 'L5', 'K5', 'Content Source', 'Status', 'Publish Date', 'Link', 'تاریخ برنامه', 'ID', 'نمایش از انتشار', 'کلیک از انتشار', 'رتبه کلمه' );
		$items    = sap_calendar_items( $project['id'], $period );
		foreach ( $items as $n => $it ) {
			$row = array( $n + 1, $it['keyword'], $it['kp'] ?: '', $it['title'], $it['brief'] );
			for ( $l = 0; $l < 5; $l++ ) {
				$row[] = $it['links'][ $l ]['url'] ?? '';
				$row[] = $it['links'][ $l ]['anchor'] ?? '';
			}
			$tr       = (array) ( $ntrack[ 'i' . $it['id'] ] ?? array() );
			$row[]    = $it['source_note'];
			$row[]    = 'published' === $it['status'];
			$row[]    = 'published' === $it['status'] && $it['published_at'] ? sap_jdate( $it['published_at'], 'short' ) : '';
			$row[]    = $it['published_url'];
			$row[]    = $it['planned_date'] ? sap_jdate( $it['planned_date'], 'day' ) : '';
			$row[]    = 'i' . (int) $it['id']; // Keeps the row tied to its article when you edit the keyword.
			$row[]    = isset( $tr['impr'] ) ? $tr['impr'] : '';
			$row[]    = isset( $tr['clicks'] ) ? $tr['clicks'] : '';
			$row[]    = isset( $tr['kw_pos'] ) && null !== $tr['kw_pos'] ? $tr['kw_pos'] : '';
			$values[] = $row;
			$written['idea'][] = (int) $it['id'];
			$snapw[ 'i' . (int) $it['id'] ] = sap_blog_snap_row( 'idea', $it, $period );
			++$count;
		}
		$r1 = count( $values );
		// Content updates of the month: their own block under the new articles, with a
		// banner and their own header — page link, kind of page (coloured), position,
		// clicks and impressions of the 28 days before the update — grouped by kind, and
		// every cell clipped so long text never spills over the empty columns.
		$ups = array_values( array_filter( sap_updates( $project['id'], array( 'period' => $period ) ), function ( $u ) {
			return 'cancelled' !== $u['status'];
		} ) );
		$order = array( 'cat' => 0, 'product' => 1, 'post' => 2, 'other' => 3 );
		usort( $ups, function ( $a, $b ) use ( $order ) {
			return array( $order[ sap_update_kind( $a ) ], $a['id'] ) <=> array( $order[ sap_update_kind( $b ) ], $b['id'] );
		} );
		$u0    = null;
		$ukind = array();
		if ( $ups ) {
			$values[] = array( 'آپدیت محتوا — ' . sap_num( count( $ups ) ) . ' صفحه (' . sap_period_label( $period ) . ')' ); // Column A only: never read back as an article.
			$u0       = count( $values );
			$values[] = array( 'آپدیت', 'Update Page', 'نوع صفحه', 'Title', 'هدف آپدیت', 'Link', 'رتبه قبل', 'کلیک ۲۸ روز قبل', 'نمایش ۲۸ روز قبل', 'L1', 'K1', 'L2', 'K2', 'L3', 'K3', 'Content Source', 'Status', 'Done Date', 'نتیجه بعد از آپدیت', 'وضعیت کار', 'ID' );
			foreach ( $ups as $n => $u ) {
				$b   = (array) $u['brief'];
				$res = function_exists( 'sap_track_line' ) ? sap_track_line( $ntrack[ 'u' . $u['id'] ] ?? null, 'upd' ) : array( 'text' => '' );
				$row = array( 'U' . ( $n + 1 ), $u['keyword'] ?: $u['title'], $kinds[ sap_update_kind( $u ) ], ( $b['title'] ?? '' ) ?: $u['title'], sap_update_goal( $u ), rawurldecode( $u['url'] ), null === $u['rank_before'] ? '' : $u['rank_before'], null === $u['clicks_before'] ? '' : $u['clicks_before'], null === $u['impr_before'] ? '' : $u['impr_before'] );
				$lo  = array_values( (array) $u['links'] );
				for ( $l = 0; $l < 3; $l++ ) {
					$row[] = isset( $lo[ $l ]['url'] ) ? rawurldecode( $lo[ $l ]['url'] ) : '';
					$row[] = $lo[ $l ]['anchor'] ?? '';
				}
				$row[]    = $u['source_note'];
				$row[]    = 'done' === $u['status'];
				$row[]    = $u['done_at'] ? sap_jdate( substr( $u['done_at'], 0, 10 ), 'short' ) : '';
				$row[]    = (string) $res['text'];
				$row[]    = sap_update_statuses()[ $u['status'] ] ?? $u['status'];
				$row[]    = 'u' . (int) $u['id'];
				$values[] = $row;
				$ukind[]  = sap_update_kind( $u );
				$written['update'][] = (int) $u['id'];
				$snapw[ 'u' . (int) $u['id'] ] = sap_blog_snap_row( 'update', $u, $period );
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
		$fmt[] = sap_gs_fmt( $sid, $r0 + 1, $r0 + 2, 1, 21, '#D9D2E9', '#000000', true );
		$fmt[] = sap_gs_fmt( $sid, $r0 + 1, $r0 + 2, 21, 24, '#D9EAD3', '#000000', true );
		if ( $r1 > $r0 + 2 ) {
			$fmt[] = sap_gs_fmt( $sid, $r0 + 2, $r1, 0, 24 );
			$fmt[] = sap_gs_clip( $sid, $r0 + 2, $r1, 1, 16 );
			$fmt[] = sap_gs_fmt( $sid, $r0 + 2, $r1, 0, 1, '#E8F5E9', '#1B5E20', true );
			$fmt[] = array( 'setDataValidation' => array( 'range' => array( 'sheetId' => $sid, 'startRowIndex' => $r0 + 2, 'endRowIndex' => $r1, 'startColumnIndex' => 16, 'endColumnIndex' => 17 ), 'rule' => array( 'condition' => array( 'type' => 'BOOLEAN' ) ) ) );
		}
		if ( null !== $u0 ) {
			$fmt[] = sap_gs_merge( $sid, $u0 - 1, $u0, 0, 21 );
			$fmt[] = sap_gs_fmt( $sid, $u0 - 1, $u0, 0, 21, '#E69138', '#FFFFFF', true );
			$fmt[] = sap_gs_fmt( $sid, $u0, $u0 + 1, 0, 21, '#F9CB9C', '#000000', true );
			$fmt[] = sap_gs_fmt( $sid, $u0 + 1, $r2, 0, 21, '#FFF7EE' );
			$fmt[] = sap_gs_clip( $sid, $u0 + 1, $r2, 1, 16 );
			$fmt[] = sap_gs_clip( $sid, $u0 + 1, $r2, 18, 19 );
			foreach ( $ukind as $ri => $kk ) {
				$fmt[] = sap_gs_fmt( $sid, $u0 + 1 + $ri, $u0 + 2 + $ri, 2, 3, $kc[ $kk ], '#000000', true );
			}
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
	$wr           = (array) ( $project['options']['calendar_written'] ?? array() );
	$wr[ $sheet ] = $written;
	sap_project_set_option( $project['id'], 'calendar_written', $wr );
	// What the sheet holds now, cell by cell: the next read takes back only what the team changes.
	update_option( 'sap_blog_snap_' . (int) $project['id'] . '_' . md5( (string) $sheet ), $snapw, false );
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
	$pid    = $project['id'];
	$status = sap_update_statuses();
	$kinds  = sap_update_kinds();
	$head   = array( 'ماه', 'نوع صفحه', 'آدرس', 'صفحه', 'کلمه اصلی', 'کلمات فرعی', 'رتبه قبل', 'کلیک ۲۸ روز قبل', 'نمایش ۲۸ روز قبل', 'هدف آپدیت', 'دیدگاه مدیر سئو', 'برنامه آپدیت', 'تایتل پیشنهادی', 'لینک‌های داخلی و انکر', 'وضعیت', 'تاریخ انجام', 'نتیجه بعد از آپدیت', 'ID' );
	// What Sokan wrote last time, per update: a cell is taken back only when the team
	// changed it in this tab (so it never undoes an edit made in the Blog tab or the panel).
	$snap_key = 'sap_updtab_' . $pid;
	$snap     = get_option( $snap_key, array() );
	$snap     = is_array( $snap ) ? $snap : array();
	$cells    = function ( $u ) use ( $status ) {
		$b = (array) $u['brief'];
		return array(
			'keyword' => (string) $u['keyword'],
			'goal'    => sap_update_goal( $u ),
			'reason'  => (string) $u['reason'],
			'title'   => (string) ( $b['title'] ?? '' ),
			'links'   => sap_cplan_links_text( $u['links'] ),
			'status'  => $status[ $u['status'] ] ?? $u['status'],
		);
	};
	$old = sap_sheet_values( $sheet, SAP_TAB_UPDATES );
	if ( ! is_wp_error( $old ) && count( (array) $old ) > 1 ) {
		$col = array();
		foreach ( (array) $old[0] as $ci => $h ) {
			$col[ trim( (string) $h ) ] = $ci;
		}
		$by = array();
		foreach ( sap_updates( $pid ) as $u ) {
			$by[ 'u' . $u['id'] ]                                              = $u;
			$by[ sap_norm_url( $u['url'] ) . '|' . sap_period_label( $u['period'] ) ] = $u;
		}
		$get = function ( $r, $name ) use ( $col ) {
			return isset( $col[ $name ] ) ? trim( (string) ( $r[ $col[ $name ] ] ?? '' ) ) : null;
		};
		$st_back = array_flip( $status );
		foreach ( array_slice( (array) $old, 1 ) as $r ) {
			$id = (string) $get( $r, 'ID' );
			$u  = $by[ $id ] ?? ( $by[ sap_norm_url( (string) $get( $r, 'آدرس' ) ) . '|' . (string) $get( $r, 'ماه' ) ] ?? null );
			if ( ! $u ) {
				continue;
			}
			$was  = (array) ( $snap[ $u['id'] ] ?? array() );
			$now  = $cells( $u );
			$b    = (array) $u['brief'];
			$upd  = array();
			$sent = array( 'keyword' => 'کلمه اصلی', 'goal' => 'هدف آپدیت', 'reason' => 'برنامه آپدیت', 'title' => 'تایتل پیشنهادی', 'links' => 'لینک‌های داخلی و انکر', 'status' => 'وضعیت' );
			foreach ( $sent as $f => $name ) {
				$v = $get( $r, $name );
				if ( null === $v || ! array_key_exists( $f, $was ) || $v === (string) $was[ $f ] || $v === $now[ $f ] ) {
					continue; // Column missing, never written, or not changed by the team.
				}
				if ( 'keyword' === $f && '' !== $v ) {
					$upd['keyword'] = $v;
				} elseif ( 'reason' === $f ) {
					$upd['reason'] = $v;
				} elseif ( in_array( $f, array( 'goal', 'title' ), true ) ) {
					$b[ $f ]      = $v;
					$upd['brief'] = $b;
				} elseif ( 'links' === $f ) {
					$upd['links'] = sap_cplan_links_parse( $project, $v );
				} elseif ( 'status' === $f && isset( $st_back[ $v ] ) ) {
					$upd['status'] = $st_back[ $v ];
				}
			}
			if ( $upd ) {
				sap_update_save( $upd, $u['id'] );
			}
		}
	}
	$track  = function_exists( 'sap_track_all' ) ? sap_track_all( $pid ) : array();
	$values = array( $head );
	$kind_r = array();
	$snap   = array();
	$ups    = array_values( array_filter( sap_updates( $pid ), function ( $u ) {
		return 'cancelled' !== $u['status'];
	} ) );
	$order  = array( 'cat' => 0, 'product' => 1, 'post' => 2, 'other' => 3 );
	usort( $ups, function ( $a, $b ) use ( $order ) {
		return array( $b['period'], $order[ sap_update_kind( $a ) ], $a['id'] ) <=> array( $a['period'], $order[ sap_update_kind( $b ) ], $b['id'] );
	} );
	foreach ( $ups as $u ) {
		$c        = $cells( $u );
		$res      = function_exists( 'sap_track_line' ) ? sap_track_line( $track[ 'u' . $u['id'] ] ?? null, 'upd' ) : array( 'text' => '' );
		$values[] = array(
			sap_period_label( $u['period'] ),
			$kinds[ sap_update_kind( $u ) ],
			rawurldecode( $u['url'] ),
			$u['title'],
			$c['keyword'],
			implode( '، ', (array) $u['secondary'] ),
			null === $u['rank_before'] ? '' : $u['rank_before'],
			null === $u['clicks_before'] ? '' : $u['clicks_before'],
			null === $u['impr_before'] ? '' : $u['impr_before'],
			$c['goal'],
			$u['my_view'],
			$c['reason'],
			$c['title'],
			$c['links'],
			$c['status'],
			$u['done_at'] ? sap_jdate( substr( $u['done_at'], 0, 10 ), 'short' ) : '',
			(string) $res['text'],
			'u' . (int) $u['id'],
		);
		$kind_r[]          = sap_update_kind( $u );
		$snap[ $u['id'] ] = $c;
	}
	$res = sap_gs_put_tab( $sheet, SAP_TAB_UPDATES, $values );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	update_option( $snap_key, $snap, false );
	$n     = count( $values );
	$kc    = array( 'cat' => '#EDE7F6', 'product' => '#E0F2F1', 'post' => '#E3F2FD', 'other' => '#F3F3F3' );
	$fmt   = sap_gs_reset( $sid, array( 0 => 90, 1 => 90, 2 => 240, 3 => 180, 4 => 150, 5 => 200, 6 => 70, 7 => 90, 8 => 90, 9 => 240, 10 => 240, 11 => 300, 12 => 220, 13 => 260, 14 => 100, 15 => 90, 16 => 260, 17 => 60 ) );
	$fmt[] = sap_gs_fmt( $sid, 0, 1, 0, 18, '#E69138', '#FFFFFF', true );
	if ( $n > 1 ) {
		$fmt[] = sap_gs_clip( $sid, 1, $n, 0, 18 );
		foreach ( $kind_r as $ri => $kk ) {
			$fmt[] = sap_gs_fmt( $sid, $ri + 1, $ri + 2, 1, 2, $kc[ $kk ], '#000000', true );
		}
	}
	$fmt[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => $sid, 'gridProperties' => array( 'frozenRowCount' => 1 ) ), 'fields' => 'gridProperties.frozenRowCount' ) );
	sap_gs_batch( $sheet, $fmt );
	return sprintf( 'آپدیت محتوا (%s صفحه)', sap_num( $n - 1 ) );
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
