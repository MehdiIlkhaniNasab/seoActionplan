<?php
/**
 * Admin menu, screens and assets.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_menu_page( 'اکشن پلن سئو', 'اکشن پلن سئو', SAP_CAP_WORK, 'sap', 'sap_screen_main', 'dashicons-yes-alt', 3 );
	add_submenu_page( 'sap', 'پروژه‌ها', 'پروژه‌ها', SAP_CAP_WORK, 'sap', 'sap_screen_main' );
	add_submenu_page( 'sap', 'کارهای من', 'کارهای من', SAP_CAP_WORK, 'sap-my', 'sap_screen_my' );
	add_submenu_page( 'sap', 'تنظیمات', 'تنظیمات و اتصال‌ها', SAP_CAP, 'sap-settings', 'sap_screen_settings' );
	add_submenu_page( 'sap', 'راهنما', 'راهنما و ساختار شیت', SAP_CAP_WORK, 'sap-guide', 'sap_screen_guide' );
} );

/**
 * Administrators get the capability even if the plugin was activated before they existed.
 */
add_filter( 'user_has_cap', function ( $all ) {
	if ( ! empty( $all['manage_options'] ) ) {
		$all[ SAP_CAP ]      = true;
		$all[ SAP_CAP_WORK ] = true;
	}
	return $all;
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false === strpos( (string) $hook, 'sap' ) ) {
		return;
	}
	wp_enqueue_style( 'sap-admin', SAP_URL . 'assets/css/admin.css', array(), SAP_VERSION );
	wp_enqueue_script( 'sap-admin', SAP_URL . 'assets/js/admin.js', array(), SAP_VERSION, true );
	wp_localize_script( 'sap-admin', 'SAP', array(
		'rest'  => esc_url_raw( rest_url( 'seoactionplan/v1/' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
		'steps' => sap_sync_steps(),
	) );
} );

add_filter( 'admin_body_class', function ( $classes ) {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	return 0 === strpos( $page, 'sap' ) ? $classes . ' sap-screen' : $classes;
} );

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
 * Main screen: project list, a project, or the new-project form.
 */
function sap_screen_main() {
	if ( ! sap_can() ) {
		sap_screen_my();
		return;
	}
	$pid = isset( $_GET['project'] ) ? absint( $_GET['project'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap sap-wrap" dir="rtl">';
	sap_flash();
	if ( isset( $_GET['new'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		sap_view( 'project-form', array( 'project' => null ) );
	} elseif ( $pid && ( $project = sap_project( $pid ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
		$tabs = sap_project_tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore
		$tab  = array_key_exists( $tab, $tabs ) ? $tab : 'overview';
		sap_view( 'project', array( 'project' => $project, 'tab' => $tab, 'tabs' => $tabs ) );
	} else {
		sap_view( 'dashboard' );
	}
	echo '</div>';
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
 * My tasks (team members and managers).
 */
function sap_screen_my() {
	echo '<div class="wrap sap-wrap" dir="rtl">';
	sap_flash();
	sap_view( 'my-tasks' );
	echo '</div>';
}

/**
 * Settings.
 */
function sap_screen_settings() {
	echo '<div class="wrap sap-wrap" dir="rtl">';
	sap_flash();
	sap_view( 'settings' );
	echo '</div>';
}

/**
 * Guide.
 */
function sap_screen_guide() {
	echo '<div class="wrap sap-wrap" dir="rtl">';
	sap_view( 'guide' );
	echo '</div>';
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
