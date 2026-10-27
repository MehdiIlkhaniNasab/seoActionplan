<?php
/**
 * Shared UI helpers for the app views.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Flash message from a redirect.
 */
function sap_flash() {
	if ( empty( $_GET['sap_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$ok = ! isset( $_GET['sap_ok'] ) || '1' === $_GET['sap_ok']; // phpcs:ignore
	printf(
		'<div class="sap-flash %s">%s</div>',
		$ok ? 'is-ok' : 'is-err',
		esc_html( sanitize_text_field( wp_unslash( $_GET['sap_msg'] ) ) ) // phpcs:ignore
	);
}

/**
 * Display mode of the current user: «simple» (guided, fewer tabs) or «pro».
 */
function sap_ui_mode() {
	$m = get_user_meta( get_current_user_id(), 'sap_mode', true );
	return 'pro' === $m ? 'pro' : 'simple';
}

/**
 * Project tabs. In simple mode only the everyday ones are shown; the others stay
 * reachable by URL and from the links that point to them.
 *
 * @param string $mode simple|pro|all (default: the user's mode).
 */
function sap_project_tabs( $mode = '' ) {
	$mode = $mode ?: sap_ui_mode();
	$all  = sap_project_tabs_all();
	if ( 'simple' !== $mode ) {
		return $all;
	}
	return array_intersect_key( $all, array_flip( array( 'overview', 'plan', 'calendar', 'ideas', 'keywords', 'activity', 'settings' ) ) );
}

/**
 * Every project tab.
 */
function sap_project_tabs_all() {
	return array(
		'overview' => array( 'نمای کلی', 'grid' ),
		'insights' => array( 'تحلیل هوشمند', 'spark' ),
		'findings' => array( 'ایرادها و فرصت‌ها', 'inbox' ),
		'keywords' => array( 'نقشه کلمات', 'research' ),
		'content'  => array( 'بازنویسی محتوا', 'log' ),
		'plan'     => array( 'اکشن پلن ماهانه', 'list' ),
		'calendar' => array( 'تقویم محتوا', 'clock' ),
		'ideas'    => array( 'ایده‌های محتوا', 'spark' ),
		'activity' => array( 'لاگ اقدامات', 'log' ),
		'focuses'  => array( 'Focus و اهداف', 'target' ),
		'settings' => array( 'تنظیمات پروژه', 'settings' ),
	);
}

/**
 * Hidden fields for an admin-post form.
 */
function sap_form_fields( $action, $extra = array() ) {
	printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
	wp_nonce_field( $action, '_sap_nonce' );
	foreach ( $extra as $k => $v ) {
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
	}
}

/**
 * Score badge.
 */
function sap_score_badge( $score ) {
	$score = (float) $score;
	$cls   = $score >= 60 ? 'is-high' : ( $score >= 35 ? 'is-mid' : 'is-low' );
	return sprintf( '<span class="sap-score %s" title="امتیاز اولویت">%s</span>', $cls, esc_html( sap_num( $score ) ) );
}

/**
 * Work area chip.
 */
function sap_context_chip( $ctx ) {
	return sprintf( '<span class="sap-chip ctx-%s">%s</span>', esc_attr( $ctx ), esc_html( sap_context_label( $ctx ) ) );
}

/**
 * Render a finding's evidence table.
 */
function sap_evidence_table( $evidence, $project_id = 0 ) {
	$cols  = (array) ( $evidence['cols'] ?? array() );
	$items = (array) ( $evidence['items'] ?? array() );
	if ( ! $cols || ! $items ) {
		return;
	}
	echo '<div class="sap-table-wrap"><table class="sap-table is-compact"><thead><tr>';
	foreach ( $cols as $label ) {
		echo '<th>' . esc_html( $label ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $items as $it ) {
		echo '<tr>';
		foreach ( array_keys( $cols ) as $k ) {
			$v = $it[ $k ] ?? '';
			if ( 'page' === $k && ! empty( $it['url'] ) ) {
				// Older findings stored a short URL here: show the page title instead.
				$label = $project_id && ( '' === (string) $v || preg_match( '#^(/|https?:|[\w.-]+\.[a-z]{2,}/)#i', (string) $v ) ) ? sap_page_title( $project_id, $it['url'] ) : (string) $v;
				echo '<td>' . sap_page_link( $it['url'], $label ) . '</td>'; // phpcs:ignore
			} else {
				echo '<td>' . esc_html( is_numeric( $v ) ? sap_num( $v, floor( (float) $v ) != $v ? 1 : 0 ) : (string) $v ) . '</td>';
			}
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * Hidden inputs that keep a URL's query args in a GET filter form
 * (needed when the site has no pretty permalinks and routes via ?sap_route=).
 *
 * @return string Form action (URL without query).
 */
function sap_filter_form( $url ) {
	$parts = wp_parse_url( $url );
	parse_str( (string) ( $parts['query'] ?? '' ), $q );
	foreach ( $q as $k => $v ) {
		if ( is_scalar( $v ) ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
		}
	}
	return strtok( $url, '?' );
}

/**
 * Toggle switch (checkbox).
 */
function sap_switch( $name, $checked, $label, $hint = '' ) {
	printf(
		'<label class="sap-switch"><input type="checkbox" name="%s" value="1" %s><span class="sap-switch-track" aria-hidden="true"></span><span class="sap-switch-text"><b>%s</b>%s</span></label>',
		esc_attr( $name ),
		checked( (bool) $checked, true, false ),
		esc_html( $label ),
		$hint ? '<small>' . esc_html( $hint ) . '</small>' : ''
	);
}

/**
 * Segmented control (radio group).
 *
 * @param array $options value => label.
 */
function sap_segment( $name, $options, $current ) {
	echo '<div class="sap-segment" role="radiogroup">';
	foreach ( $options as $value => $label ) {
		printf(
			'<label><input type="radio" name="%s" value="%s" %s><span>%s</span></label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( (string) $current, (string) $value, false ),
			esc_html( $label )
		);
	}
	echo '</div>';
}

/**
 * Multi-select chips (checkboxes).
 */
function sap_chip_pick( $name, $options, $selected = array() ) {
	$selected = array_map( 'sap_kw_key', (array) $selected );
	echo '<div class="sap-chip-pick">';
	foreach ( $options as $value => $label ) {
		printf(
			'<label><input type="checkbox" name="%s[]" value="%s" %s><span>%s</span></label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( in_array( sap_kw_key( $value ), $selected, true ), true, false ),
			esc_html( $label )
		);
	}
	echo '</div>';
}

/**
 * Guided path of a project: five steps and the one thing to do now.
 *
 * @return array{steps: array, current: string}
 */
function sap_project_path( $project ) {
	$pid    = $project['id'];
	$period = sap_period_current();
	$tasks  = sap_tasks( array( 'project_id' => $pid, 'period' => $period ) );
	$review = sap_weekly_review( $pid );
	$steps  = array(
		'connect' => array( 'اتصال داده‌ها', '' !== $project['gsc_property'] || '' !== $project['sheet_id'] ),
		'analyze' => array( 'تحلیل', ! empty( $project['options']['last_sync'] ) ),
		'focus'   => array( 'Focus و هدف', (bool) sap_focuses( $pid, true ) || ! empty( $project['options']['focus_skipped'] ) ),
		'plan'    => array( 'اکشن پلن', (bool) $tasks ),
		'run'     => array( 'اجرا و بازبینی', $tasks && ! $review['due'] && ! $review['overdue'] ),
	);
	$current = 'done';
	foreach ( $steps as $k => $s ) {
		if ( ! $s[1] ) {
			$current = $k;
			break;
		}
	}
	return array( 'steps' => $steps, 'current' => $current, 'review' => $review, 'tasks' => $tasks );
}

/**
 * Labels of the AI health areas.
 */
function sap_health_areas() {
	return array(
		'technical' => 'تکنیکال',
		'content'   => 'محتوا',
		'onpage'    => 'سئو داخلی',
		'authority' => 'اعتبار و لینک',
		'focus'     => 'Focus',
	);
}

/**
 * Human title of a page: keyword map → page reader → crawl → short URL.
 */
function sap_page_title( $project_id, $url ) {
	static $idx = array();
	if ( '' === (string) $url ) {
		return '';
	}
	if ( ! isset( $idx[ $project_id ] ) ) {
		$t = array();
		foreach ( (array) ( sap_dataset_payload( $project_id, 'crawl' )['rows'] ?? array() ) as $r ) {
			$v = sap_clean_title( $r['title'] ?? '', $r['h1'] ?? '' );
			if ( '' !== $v ) {
				$t[ sap_norm_url( $r['url'] ) ] = $v;
			}
		}
		foreach ( (array) ( sap_dataset_payload( $project_id, 'pages' )['pages'] ?? array() ) as $k => $p ) {
			$v = sap_clean_title( $p['title'] ?? '', $p['h1'][0] ?? '' );
			if ( '' !== $v ) {
				$t[ $k ] = $v;
			}
		}
		foreach ( sap_keymap( $project_id ) as $k => $m ) {
			if ( '' !== $m['title'] ) {
				$t[ $k ] = $m['title'];
			}
		}
		$idx[ $project_id ] = $t;
	}
	$k = sap_norm_url( $url );
	if ( isset( $idx[ $project_id ][ $k ] ) ) {
		return $idx[ $project_id ][ $k ];
	}
	return '' === trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ? 'صفحه اصلی' : sap_short_url( $url, 60 );
}

/**
 * Page title with a "view page" button (opens in a new tab).
 *
 * @param string $url   Page URL.
 * @param string $label Title (use sap_page_title()).
 */
function sap_page_link( $url, $label ) {
	if ( '' === (string) $url ) {
		return esc_html( $label );
	}
	return sprintf(
		'<span class="sap-pagelink"><span class="sap-pagelink-t" title="%1$s">%2$s</span><a class="sap-view" href="%1$s" target="_blank" rel="noopener" title="مشاهده صفحه" aria-label="مشاهده صفحه">%3$s</a></span>',
		esc_url( $url ),
		esc_html( $label ),
		sap_icon( 'link', 14 )
	);
}

/**
 * Absolute URL of a page the AI wrote as a path ("/product-category/x/").
 */
function sap_full_url( $project, $url ) {
	$url = trim( (string) $url );
	if ( '' === $url || preg_match( '#^https?://#i', $url ) ) {
		return $url;
	}
	if ( 'صفحه اصلی' === $url ) {
		$url = '/';
	}
	$base = $project['site_url'] ? untrailingslashit( $project['site_url'] ) : 'https://' . sap_project_domain( $project );
	return '/' === $url[0] ? preg_replace( '#^(https?://[^/]+).*$#i', '$1', $base ) . $url : $url;
}

/**
 * Page link (title + view button) for a URL or path written by the AI.
 */
function sap_page_ref( $project, $url ) {
	$full = sap_full_url( $project, $url );
	return preg_match( '#^https?://#i', $full ) ? sap_page_link( $full, sap_page_title( $project['id'], $full ) ) : esc_html( $url );
}
