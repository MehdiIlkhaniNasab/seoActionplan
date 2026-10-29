<?php
/**
 * Project memory: decisions, client requests, constraints, lessons and facts
 * that should not be forgotten. People read it on the memory tab; every AI
 * call of the project gets the pinned and recent items (plus the anonymised
 * playbook learned from all projects), so advice never contradicts what was
 * decided or already learned.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kinds of memory.
 */
function sap_memory_kinds() {
	return array(
		'decision'   => array( 'تصمیم', 'check', '#2563EB' ),
		'client'     => array( 'خواسته کارفرما', 'user', '#7C3AED' ),
		'constraint' => array( 'محدودیت / ممنوع', 'alert', '#DC2626' ),
		'lesson'     => array( 'درس آموخته', 'brain', '#059669' ),
		'fact'       => array( 'اطلاعات کسب‌وکار', 'inbox', '#0891B2' ),
		'note'       => array( 'یادداشت', 'log', '#64748B' ),
	);
}

/**
 * Memory items of a project (pinned first, newest first).
 */
function sap_memory( $project_id, $args = array() ) {
	global $wpdb;
	$args  = wp_parse_args( $args, array( 'kind' => '', 'q' => '', 'limit' => 300 ) );
	$where = $wpdb->prepare( 'project_id = %d', $project_id );
	if ( $args['kind'] ) {
		$where .= $wpdb->prepare( ' AND kind = %s', $args['kind'] );
	}
	if ( '' !== $args['q'] ) {
		$like   = '%' . $wpdb->esc_like( $args['q'] ) . '%';
		$where .= $wpdb->prepare( ' AND (title LIKE %s OR body LIKE %s OR keyword LIKE %s OR url LIKE %s)', $like, $like, $like, $like );
	}
	return (array) $wpdb->get_results( 'SELECT * FROM ' . sap_table( 'memory' ) . " WHERE $where ORDER BY pinned DESC, created_at DESC, id DESC LIMIT " . (int) $args['limit'], ARRAY_A ); // phpcs:ignore
}

/**
 * Add (or, with the same ref, update) a memory item.
 *
 * @return int id
 */
function sap_memory_add( $project_id, $data ) {
	global $wpdb;
	$kinds = sap_memory_kinds();
	$row   = array(
		'project_id' => (int) $project_id,
		'kind'       => isset( $kinds[ $data['kind'] ?? '' ] ) ? $data['kind'] : 'note',
		'title'      => mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 250 ),
		'body'       => sanitize_textarea_field( (string) ( $data['body'] ?? '' ) ),
		'url'        => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
		'keyword'    => mb_substr( sanitize_text_field( (string) ( $data['keyword'] ?? '' ) ), 0, 180 ),
		'tags'       => mb_substr( sanitize_text_field( (string) ( $data['tags'] ?? '' ) ), 0, 250 ),
		'source'     => sanitize_key( (string) ( $data['source'] ?? 'manual' ) ),
		'ref'        => mb_substr( sanitize_text_field( (string) ( $data['ref'] ?? '' ) ), 0, 64 ),
		'pinned'     => empty( $data['pinned'] ) ? 0 : 1,
		'user_id'    => get_current_user_id(),
	);
	if ( '' === $row['title'] && '' === $row['body'] ) {
		return 0;
	}
	if ( '' !== $row['ref'] ) {
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . sap_table( 'memory' ) . ' WHERE project_id = %d AND ref = %s', $project_id, $row['ref'] ) ); // phpcs:ignore
		if ( $id ) {
			unset( $row['pinned'], $row['user_id'] );
			$wpdb->update( sap_table( 'memory' ), $row, array( 'id' => $id ) );
			return $id;
		}
	}
	$row['created_at'] = sap_now();
	$wpdb->insert( sap_table( 'memory' ), $row );
	return (int) $wpdb->insert_id;
}

function sap_memory_delete( $project_id, $id ) {
	global $wpdb;
	return (bool) $wpdb->delete( sap_table( 'memory' ), array( 'id' => (int) $id, 'project_id' => (int) $project_id ) );
}

function sap_memory_pin( $project_id, $id, $on ) {
	global $wpdb;
	return (bool) $wpdb->update( sap_table( 'memory' ), array( 'pinned' => $on ? 1 : 0 ), array( 'id' => (int) $id, 'project_id' => (int) $project_id ) );
}

/**
 * Memory as short lines for the AI: everything pinned, then the most recent.
 */
function sap_memory_for_ai( $project_id, $max_chars = 2600 ) {
	$kinds = sap_memory_kinds();
	$lines = array();
	$len   = 0;
	foreach ( sap_memory( $project_id, array( 'limit' => 60 ) ) as $m ) {
		$line = '- [' . $kinds[ $m['kind'] ][0] . '] ' . trim( $m['title'] . ( $m['body'] ? ': ' . preg_replace( '/\s+/u', ' ', $m['body'] ) : '' ) );
		$line = mb_substr( $line, 0, 320 ) . ( $m['url'] ? ' (' . sap_short_url( $m['url'], 60 ) . ')' : '' );
		if ( $len + mb_strlen( $line ) > $max_chars ) {
			break;
		}
		$lines[] = $line;
		$len    += mb_strlen( $line );
	}
	return $lines;
}

/**
 * Lessons from measured actions become memory automatically (one per action).
 */
function sap_memory_from_impact( $project_id ) {
	$n = 0;
	foreach ( sap_impact_items( $project_id ) as $ref => $i ) {
		if ( ! in_array( $i['verdict'] ?? '', array( 'growth', 'decline' ), true ) || ! empty( $i['early'] ) ) {
			continue;
		}
		$up    = 'growth' === $i['verdict'];
		$title = sprintf( '%s «%s» %s', sap_context_label( $i['context'] ), $i['title'], $up ? 'جواب داد' : 'نتیجه منفی داشت' );
		$body  = sprintf( 'انجام: %s · کلیک روزانه قبل %s، بعد %s (خالص نسبت به کل سایت %s٪)%s', sap_jdate( $i['done'], 'short' ), sap_num( $i['before']['clicks'] / max( 1, $i['before']['days'] ), 1 ), sap_num( $i['after']['clicks'] / max( 1, $i['after']['days'] ), 1 ), sap_num( (int) $i['net_change'] ), null !== ( $i['pos_gain'] ?? null ) ? ' · تغییر جایگاه ' . sap_num( $i['pos_gain'], 1 ) : '' );
		sap_memory_add( $project_id, array( 'kind' => 'lesson', 'title' => $title, 'body' => $body, 'url' => $i['url'], 'keyword' => $i['keyword'], 'source' => 'impact', 'ref' => 'impact:' . $ref ) );
		++$n;
	}
	return $n;
}

/**
 * The extra context of the current project for every AI call.
 */
function sap_ai_project_context() {
	$project = $GLOBALS['sap_ai_project'] ?? null;
	if ( ! is_array( $project ) || empty( $project['id'] ) ) {
		return '';
	}
	$out = '';
	$mem = sap_memory_for_ai( $project['id'] );
	if ( $mem ) {
		$out .= "\n\nحافظه این پروژه (تصمیم‌ها، خواسته‌های کارفرما، محدودیت‌ها و درس‌های آموخته؛ پیشنهادت نباید با این‌ها تناقض داشته باشد و محدودیت‌ها را رعایت کن):\n" . implode( "\n", $mem );
	}
	// Analysis runs: what is already on the table, so a new run continues the work instead of
	// re-wording it (dismissed ones are never proposed again).
	if ( ! empty( $GLOBALS['sap_ai_known'] ) ) {
		$known = sap_known_proposals_for_ai( $project['id'] );
		if ( $known ) {
			$out .= "\n\nپیشنهادهایی که قبلاً ثبت شده (وضعیت | نوع | صفحه | عنوان). قوانین: موارد «رد شده» را هرگز دوباره پیشنهاد نده؛ برای همان صفحه و همان نوع کاری که «باز» یا «در برنامه» است پیشنهاد تازه نساز مگر داده واقعاً تغییر کرده باشد (در آن صورت همان url و kind را بده تا جایگزین شود)؛ فقط کارهای مرتبط با Focusهای پروژه:\n" . implode( "\n", $known );
		}
	}
	$play = function_exists( 'sap_learning_for_ai' ) ? sap_learning_for_ai( $project ) : array();
	if ( $play ) {
		$out .= "\n\nتجربه سکان در پروژه‌های دیگر (ناشناس، فقط نوع کار و میانگین اثر؛ برای اولویت‌بندی استفاده کن، نه به‌عنوان داده این سایت):\n" . implode( "\n", $play );
	}
	return $out;
}

/**
 * Compact list of existing proposals for the AI (open / planned / dismissed).
 */
function sap_known_proposals_for_ai( $project_id ) {
	$out = array();
	$lab = array( 'open' => 'باز', 'planned' => 'در برنامه', 'dismissed' => 'رد شده' );
	foreach ( array( 'dismissed' => 40, 'planned' => 30, 'open' => 40 ) as $st => $n ) {
		foreach ( sap_findings( $project_id, array( 'status' => $st, 'limit' => $n ) ) as $f ) {
			$kind  = (string) ( $f['evidence']['kind'] ?? $f['context'] );
			$out[] = sprintf( '%s | %s | %s | %s', $lab[ $st ], $kind, '' !== (string) $f['url'] ? rawurldecode( (string) wp_parse_url( $f['url'], PHP_URL_PATH ) ) : '-', mb_substr( $f['title'], 0, 90 ) );
		}
	}
	return $out;
}
