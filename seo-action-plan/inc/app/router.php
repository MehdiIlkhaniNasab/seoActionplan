<?php
/**
 * Front-end app: the tool lives at {site}/seo/plan/ as a standalone, full-screen
 * dashboard (own login screen, no theme, no admin bar, no wp-admin chrome).
 *
 * Routes (relative to the base):
 *   /                      dashboard (all projects)
 *   /new/                  new project
 *   /project/{id}/{tab}/   a project (overview, findings, plan, activity, focuses, settings)
 *   /my/                   my tasks
 *   /settings/             connections & settings
 *   /guide/                guide & sheet structure
 *
 * Without pretty permalinks the same routes work as /?sap_app=1&sap_route=…
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

const SAP_REWRITE_VERSION = '1';

/**
 * Base path of the app, e.g. "seo/plan". Filterable.
 */
function sap_app_base() {
	return trim( (string) apply_filters( 'sap_app_base', 'seo/plan' ), '/' );
}

/**
 * App URL of a route.
 *
 * @param string $route e.g. '', 'new', 'my', 'settings', 'guide', 'project/3/plan'.
 * @param array  $args  Query args.
 */
function sap_url( $route = '', $args = array() ) {
	$route = trim( (string) $route, '/' );
	if ( get_option( 'permalink_structure' ) ) {
		$url = home_url( '/' . sap_app_base() . '/' . ( '' !== $route ? $route . '/' : '' ) );
	} else {
		$url = add_query_arg( array( 'sap_app' => 1, 'sap_route' => $route ), home_url( '/' ) );
	}
	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * Project screen URL.
 */
function sap_project_url( $project_id, $tab = 'overview', $args = array() ) {
	$route = 'project/' . (int) $project_id . ( 'overview' !== $tab ? '/' . $tab : '' );
	return sap_url( $route, $args );
}

add_action( 'init', function () {
	add_rewrite_rule( '^' . preg_quote( sap_app_base(), '#' ) . '/?(.*)$', 'index.php?sap_app=1&sap_route=$matches[1]', 'top' );
	// The bare parent (/seo/) leads to the app too.
	$parent = dirname( sap_app_base() );
	if ( '.' !== $parent && '' !== $parent ) {
		add_rewrite_rule( '^' . preg_quote( $parent, '#' ) . '/?$', 'index.php?sap_app=1&sap_route=', 'top' );
	}
	if ( get_option( 'sap_rewrite_version' ) !== SAP_REWRITE_VERSION . '|' . sap_app_base() ) {
		flush_rewrite_rules( false );
		update_option( 'sap_rewrite_version', SAP_REWRITE_VERSION . '|' . sap_app_base(), false );
	}
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'sap_app';
	$vars[] = 'sap_route';
	return $vars;
} );

/**
 * wp-admin keeps one menu item (and an admin-bar link) that opens the app.
 */
add_action( 'admin_menu', function () {
	add_menu_page( 'سُکان', 'سُکان (سئو)', SAP_CAP_WORK, 'sap', '__return_null', 'dashicons-yes-alt', 3 );
} );
add_action( 'load-toplevel_page_sap', function () {
	wp_safe_redirect( sap_url() );
	exit;
} );
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( sap_can_work() ) {
		$bar->add_node( array( 'id' => 'sap', 'title' => 'سُکان', 'href' => sap_url() ) );
	}
}, 80 );

/**
 * Administrators always have the tool capabilities.
 */
add_filter( 'user_has_cap', function ( $all ) {
	if ( ! empty( $all['manage_options'] ) ) {
		$all[ SAP_CAP ]      = true;
		$all[ SAP_CAP_WORK ] = true;
	}
	return $all;
} );

/**
 * Render the app before the theme (and before canonical redirects).
 */
add_action( 'template_redirect', function () {
	if ( ! get_query_var( 'sap_app' ) ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	status_header( 200 );
	show_admin_bar( false );

	$route = trim( (string) get_query_var( 'sap_route' ), '/' );
	if ( ! is_user_logged_in() ) {
		sap_render( 'login', array( 'redirect' => sap_url( $route ) ) );
	}
	if ( ! sap_can_work() ) {
		status_header( 403 );
		sap_render( 'denied' );
	}
	sap_render( 'shell', sap_resolve_route( $route ) );
}, 1 );

/**
 * Route → view + variables.
 */
function sap_resolve_route( $route ) {
	$parts = array_values( array_filter( explode( '/', $route ), 'strlen' ) );
	$first = $parts[0] ?? '';
	$out   = array( 'route' => $route, 'nav' => $first ?: 'dashboard', 'project' => null );
	// Team members only have their tasks and the guide.
	if ( ! sap_can() && ! in_array( $first, array( 'my', 'guide' ), true ) ) {
		$first = 'my';
	}
	switch ( $first ) {
		case 'new':
			return array_merge( $out, array( 'view' => 'project-form', 'title' => 'پروژه جدید', 'vars' => array( 'project' => null ) ) );
		case 'my':
			return array_merge( $out, array( 'view' => 'my-tasks', 'title' => 'کارهای من', 'nav' => 'my', 'vars' => array() ) );
		case 'settings':
			return array_merge( $out, array( 'view' => 'settings', 'title' => 'تنظیمات و اتصال‌ها', 'vars' => array() ) );
		case 'guide':
			return array_merge( $out, array( 'view' => 'guide', 'title' => 'راهنما', 'vars' => array() ) );
		case 'project':
			$project = sap_project( absint( $parts[1] ?? 0 ) );
			if ( $project ) {
				$tabs = sap_project_tabs();
				$tab  = sanitize_key( $parts[2] ?? 'overview' );
				$all  = $tabs + array( 'content' => array( 'تحلیل و بازنویسی محتوا', 'log' ) ); // Pages reached from a task, not in the tab bar.
				$tab  = array_key_exists( $tab, $all ) ? $tab : 'overview';
				return array_merge( $out, array(
					'view'    => 'project',
					'title'   => $project['name'] . ' — ' . $all[ $tab ][0],
					'nav'     => 'project',
					'project' => $project,
					'vars'    => array( 'project' => $project, 'tab' => $tab, 'tabs' => $tabs ),
				) );
			}
			status_header( 404 );
			return array_merge( $out, array( 'view' => 'not-found', 'title' => 'یافت نشد', 'vars' => array() ) );
		default:
			return array_merge( $out, array( 'view' => 'dashboard', 'title' => 'داشبورد', 'nav' => 'dashboard', 'vars' => array() ) );
	}
}

/**
 * Output a full-page app template and stop.
 */
function sap_render( $sap_template, $sap_args = array() ) {
	extract( $sap_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
	include SAP_DIR . '/templates/app/' . $sap_template . '.php';
	exit;
}

/**
 * Asset tags for the app document (no theme, no wp_head).
 */
function sap_app_assets() {
	printf( '<link rel="stylesheet" href="%s">' . "\n", esc_url( SAP_URL . 'assets/css/app.css?ver=' . SAP_VERSION ) );
	printf(
		'<script>window.SAP = %s;</script>' . "\n",
		wp_json_encode( array(
			'rest'  => esc_url_raw( rest_url( 'seoactionplan/v1/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) )
	);
	printf( '<script src="%s" defer></script>' . "\n", esc_url( SAP_URL . 'assets/js/app.js?ver=' . SAP_VERSION ) );
}

/**
 * Login form failures come back to the app's own login screen.
 */
add_action( 'wp_login_failed', function () {
	$to = isset( $_POST['sap_login'], $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : ''; // phpcs:ignore
	if ( $to ) {
		wp_safe_redirect( add_query_arg( 'sap_login', 'failed', $to ) );
		exit;
	}
} );
add_filter( 'authenticate', function ( $user, $username, $password ) {
	// Empty fields skip wp_login_failed; send them back too.
	if ( isset( $_POST['sap_login'], $_POST['redirect_to'] ) && ( '' === (string) $username || '' === (string) $password ) ) { // phpcs:ignore
		wp_safe_redirect( add_query_arg( 'sap_login', 'empty', wp_unslash( $_POST['redirect_to'] ) ) ); // phpcs:ignore
		exit;
	}
	return $user;
}, 30, 3 );
