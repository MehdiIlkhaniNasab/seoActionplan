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
 * Project tabs.
 */
function sap_project_tabs() {
	return array(
		'overview' => array( 'نمای کلی', 'grid' ),
		'findings' => array( 'ایرادها و فرصت‌ها', 'inbox' ),
		'plan'     => array( 'اکشن پلن ماهانه', 'list' ),
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
function sap_evidence_table( $evidence ) {
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
				printf( '<td><a href="%s" target="_blank" rel="noopener">%s</a></td>', esc_url( $it['url'] ), esc_html( $v ) );
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
