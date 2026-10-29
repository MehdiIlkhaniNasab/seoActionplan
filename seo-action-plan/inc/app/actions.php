<?php
/**
 * Form handlers (admin-post.php).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verify nonce + capability, or die.
 */
function sap_guard( $action, $manager = true ) {
	$ok = $manager ? sap_can() : sap_can_work();
	if ( ! $ok || ! isset( $_POST['_sap_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sap_nonce'] ) ), $action ) ) {
		wp_die( 'دسترسی غیرمجاز', 403 );
	}
}

/**
 * Redirect back with a flash message.
 */
function sap_back( $url, $msg, $ok = true ) {
	wp_safe_redirect( add_query_arg( array( 'sap_msg' => rawurlencode( $msg ), 'sap_ok' => $ok ? 1 : 0 ), $url ) );
	exit;
}

/**
 * Read a POST field (unslashed).
 */
function sap_post( $key, $default = '' ) {
	return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore
}

/**
 * Return URL posted by the form (same-site only), or a fallback.
 */
function sap_return_url( $fallback ) {
	$r = (string) sap_post( '_return' );
	return $r ? wp_validate_redirect( $r, $fallback ) : $fallback;
}

/* ---- Settings ---------------------------------------------------------- */

add_action( 'admin_post_sap_save_settings', function () {
	sap_guard( 'sap_save_settings' );
	$changes = array(
		'google_auth_mode'     => 'service' === sap_post( 'google_auth_mode' ) ? 'service' : 'oauth',
		'google_client_id'     => sanitize_text_field( sap_post( 'google_client_id' ) ),
		'seosignal_daily_cap'  => min( 1000, max( 1, absint( sap_post( 'seosignal_daily_cap', 40 ) ) ) ),
		'seosignal_cache_days' => min( 90, max( 1, absint( sap_post( 'seosignal_cache_days', 25 ) ) ) ),
		'min_impressions'      => max( 1, absint( sap_post( 'min_impressions', 50 ) ) ),
		'ctr_min_impressions'  => max( 1, absint( sap_post( 'ctr_min_impressions', 200 ) ) ),
		'decay_min_clicks'     => max( 1, absint( sap_post( 'decay_min_clicks', 30 ) ) ),
		'decay_drop'           => min( 95, max( 5, absint( sap_post( 'decay_drop', 30 ) ) ) ),
		'auto_sync'            => in_array( sap_post( 'auto_sync' ), array( 'off', 'weekly', 'daily' ), true ) ? sap_post( 'auto_sync' ) : 'weekly',
		'recurring'            => sanitize_textarea_field( sap_post( 'recurring' ) ),
		'use_recurring'        => sap_post( 'use_recurring' ) ? 1 : 0,
		'ai_auto'              => sap_post( 'ai_auto' ) ? 1 : 0,
		'ai_provider'          => 'anthropic' === sap_post( 'ai_provider' ) ? 'anthropic' : 'gateway',
		'ai_base_url'          => esc_url_raw( trim( (string) sap_post( 'ai_base_url' ) ) ) ?: 'https://api.gapgpt.app/v1',
		'ai_model'             => preg_replace( '/[^a-zA-Z0-9._:\/-]/', '', (string) sap_post( 'ai_model' ) ) ?: 'claude-sonnet-5-5',
		'ai_model_assist'      => preg_replace( '/[^a-zA-Z0-9._:\/-]/', '', (string) sap_post( 'ai_model_assist' ) ) ?: 'claude-haiku-5-5',
		'ai_model_light'       => preg_replace( '/[^a-zA-Z0-9._:\/-]/', '', (string) sap_post( 'ai_model_light' ) ),
		'ai_budget'            => max( 0, (float) sap_norm_text( sap_post( 'ai_budget', 10 ) ) ),
		'proxy_mode'           => in_array( sap_post( 'proxy_mode' ), array( 'smart', 'all', 'none' ), true ) ? sap_post( 'proxy_mode' ) : 'smart',
		'proxy_force_hosts'    => sanitize_textarea_field( (string) sap_post( 'proxy_force_hosts' ) ),
		'ss_auto'              => sap_post( 'ss_auto' ) ? 1 : 0,
	);
	delete_transient( 'sap_ai_denied' ); // New key / model: let the next analysis try again.
	foreach ( array( 'google_client_secret', 'seosignal_key', 'anthropic_key', 'ai_gateway_key', 'psi_key' ) as $k ) {
		$v = trim( (string) sap_post( $k ) );
		if ( '' !== $v && false === strpos( $v, '•' ) ) {
			$changes[ $k ] = sanitize_text_field( $v );
		}
	}
	$sa = trim( (string) sap_post( 'google_service_account' ) );
	if ( '' !== $sa && false === strpos( $sa, '•' ) ) {
		$json = json_decode( $sa, true );
		if ( empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
			sap_back( sap_url( 'settings' ), 'JSON سرویس اکانت نامعتبر است.', false );
		}
		$changes['google_service_account'] = $sa;
	}
	// Notifications.
	$changes['notify_email']         = sap_post( 'notify_email' ) ? 1 : 0;
	$changes['notify_manager_email'] = sanitize_email( (string) sap_post( 'notify_manager_email' ) );
	$changes['notify_manager_phone'] = sap_phone_norm( (string) sap_post( 'notify_manager_phone' ) );
	$changes['sms_provider']         = array_key_exists( (string) sap_post( 'sms_provider' ), sap_sms_providers() ) ? (string) sap_post( 'sms_provider' ) : 'none';
	$changes['sms_sender']           = sanitize_text_field( (string) sap_post( 'sms_sender' ) );
	$changes['sms_custom_url']       = esc_url_raw( trim( (string) sap_post( 'sms_custom_url' ) ) );
	$changes['sms_user']             = sanitize_text_field( (string) sap_post( 'sms_user' ) );
	$changes['sms_footer']           = sanitize_text_field( (string) sap_post( 'sms_footer' ) );
	$changes['smtp_host']            = preg_replace( '/[^a-zA-Z0-9.\-]/', '', (string) sap_post( 'smtp_host' ) );
	$changes['smtp_port']            = max( 1, min( 65535, (int) sap_post( 'smtp_port', 587 ) ) );
	$changes['smtp_secure']          = in_array( sap_post( 'smtp_secure' ), array( 'tls', 'ssl', 'none' ), true ) ? (string) sap_post( 'smtp_secure' ) : 'tls';
	$changes['smtp_user']            = sanitize_text_field( (string) sap_post( 'smtp_user' ) );
	$changes['smtp_from']            = sanitize_email( (string) sap_post( 'smtp_from' ) );
	$changes['smtp_from_name']       = sanitize_text_field( (string) sap_post( 'smtp_from_name' ) );
	// The SMTP password is kept as typed (sanitizing would break special characters); stored encrypted.
	$sp = str_replace( array( "\r", "\n" ), '', trim( (string) sap_post( 'smtp_pass' ) ) );
	if ( '' !== $sp && false === strpos( $sp, '•' ) ) {
		$changes['smtp_pass'] = $sp;
	}
	$changes['tg_chat']              = sanitize_text_field( (string) sap_post( 'tg_chat' ) );
	$changes['bale_chat']            = sanitize_text_field( (string) sap_post( 'bale_chat' ) );
	foreach ( array( 'critical', 'warning', 'info' ) as $sv ) {
		$changes[ 'alert_' . $sv ] = implode( ',', array_intersect( array_map( 'sanitize_key', (array) sap_post( 'alert_' . $sv, array() ) ), array_keys( sap_notify_channels() ) ) );
	}
	$hours = array_filter( array_map( 'absint', explode( ',', sap_norm_text( (string) sap_post( 'reminder_hours' ) ) ) ), function ( $h ) {
		return $h >= 0 && $h <= 23;
	} );
	$changes['reminder_hours'] = implode( ',', array_unique( $hours ) );
	$changes['reminder_sms']   = sap_post( 'reminder_sms' ) ? 1 : 0;
	$changes['reminder_email'] = sap_post( 'reminder_email' ) ? 1 : 0;
	$changes['serp_provider']  = 'serpapi' === sap_post( 'serp_provider' ) ? 'serpapi' : 'serper';
	$changes['serp_gl']        = sanitize_key( (string) sap_post( 'serp_gl', 'ir' ) ) ?: 'ir';
	$changes['serp_hl']        = sanitize_key( (string) sap_post( 'serp_hl', 'fa' ) ) ?: 'fa';
	$changes['geo_model']      = preg_replace( '/[^a-zA-Z0-9._:\/-]/', '', (string) sap_post( 'geo_model', '' ) );
	$changes['hourly_cost']    = max( 0, (float) sap_post( 'hourly_cost', 0 ) );
	foreach ( array( 'sms_key', 'tg_token', 'bale_token', 'serp_key' ) as $k ) {
		$v = trim( (string) sap_post( $k ) );
		if ( '' !== $v && false === strpos( $v, '•' ) ) {
			$changes[ $k ] = sanitize_text_field( $v );
		}
	}
	sap_update_settings( $changes );
	delete_transient( 'sap_google_access_token' );
	delete_transient( 'sap_gsc_sites' );
	sap_back( sap_url( 'settings' ), 'تنظیمات ذخیره شد.' );
} );

add_action( 'admin_post_sap_google_disconnect', function () {
	sap_guard( 'sap_google_disconnect' );
	sap_update_settings( array( 'google_refresh_token' => '', 'google_account_email' => '', 'google_scopes' => '' ) );
	delete_transient( 'sap_google_access_token' );
	delete_transient( 'sap_gsc_sites' );
	sap_back( sap_url( 'settings' ), 'اتصال گوگل قطع شد.' );
} );

add_action( 'admin_post_sap_sheet_template', function () {
	if ( ! sap_can_work() ) {
		wp_die( 'دسترسی غیرمجاز', 403 );
	}
	$type = 'clusters' === ( $_GET['type'] ?? '' ) ? 'clusters' : 'calendar'; // phpcs:ignore WordPress.Security.NonceVerification
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="seo-' . $type . '-template.csv"' );
	echo sap_sheet_template_csv( $type ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
} );

/* ---- Projects ---------------------------------------------------------- */

add_action( 'admin_post_sap_save_project', function () {
	sap_guard( 'sap_save_project' );
	$id   = absint( sap_post( 'project_id' ) );
	$data = array();
	foreach ( array( 'name', 'site_url', 'gsc_property', 'sheet_id', 'sheet_calendar_tab', 'sheet_cluster_tab', 'ss_project_id', 'ss_device', 'competitors', 'brand_terms', 'capacity_hours' ) as $k ) {
		$data[ $k ] = sap_post( $k );
	}
	if ( '' === trim( (string) $data['name'] ) ) {
		sap_back( $id ? sap_project_url( $id, 'settings' ) : sap_url( 'new' ), 'نام پروژه الزامی است.', false );
	}
	// No property chosen: pick the Search Console property that matches the site.
	if ( '' === trim( (string) $data['gsc_property'] ) && $data['site_url'] && sap_google_connected() ) {
		$sites  = sap_gsc_sites();
		$domain = sap_clean_domain( $data['site_url'] );
		foreach ( is_wp_error( $sites ) ? array() : $sites as $site ) {
			if ( sap_clean_domain( str_replace( 'sc-domain:', '', $site['url'] ) ) === $domain ) {
				$data['gsc_property'] = $site['url'];
				if ( 0 === strpos( $site['url'], 'sc-domain:' ) ) {
					break; // Prefer the domain property.
				}
			}
		}
	}
	if ( null === sap_post( 'capacity_hours', null ) && $id ) {
		$data['capacity_hours'] = sap_project( $id )['capacity_hours'];
	}
	$new = ! $id;
	$id  = sap_project_save( $data, $id );
	sap_project_set_option( $id, 'no_writeback', sap_post( 'writeback' ) ? 0 : 1 );
	if ( null !== sap_post( 'click_value', null ) ) {
		sap_project_set_option( $id, 'click_value', max( 0, (float) sap_post( 'click_value' ) ) );
		sap_project_set_option( $id, 'hourly_cost', max( 0, (float) sap_post( 'hourly_cost' ) ) );
	}
	sap_back( sap_project_url( $id, $new ? 'overview' : 'settings' ), $new ? 'پروژه ساخته شد. قدم بعدی را در کارت «قدم بعدی» دنبال کنید.' : 'تنظیمات پروژه ذخیره شد.' );
} );

add_action( 'admin_post_sap_delete_project', function () {
	sap_guard( 'sap_delete_project' );
	$id = absint( sap_post( 'project_id' ) );
	if ( 'DELETE' !== sap_post( 'confirm' ) ) {
		sap_back( sap_project_url( $id, 'settings' ), 'برای حذف، کلمه DELETE را تایپ کنید.', false );
	}
	sap_project_delete( $id );
	sap_back( sap_url(), 'پروژه و همه داده‌هایش حذف شد.' );
} );

add_action( 'admin_post_sap_offpage_settings', function () {
	sap_guard( 'sap_offpage_settings' );
	$pid = absint( sap_post( 'project_id' ) );
	sap_project_set_option( $pid, 'offpage', array(
		'budget' => (int) preg_replace( '/\D/', '', sap_norm_text( (string) sap_post( 'budget' ) ) ),
		'brand'  => sanitize_text_field( (string) sap_post( 'brand' ) ),
		'notes'  => sanitize_textarea_field( (string) sap_post( 'notes' ) ),
		'avoid'  => sanitize_textarea_field( (string) sap_post( 'avoid' ) ),
	) );
	sap_back( sap_project_url( $pid, 'offpage' ), 'تنظیمات آف‌پیج ذخیره شد.' );
} );

add_action( 'admin_post_sap_project_reset', function () {
	sap_guard( 'sap_project_reset' );
	$id = absint( sap_post( 'project_id' ) );
	if ( 'RESET' !== strtoupper( trim( sap_post( 'confirm' ) ) ) ) {
		sap_back( sap_project_url( $id, 'settings' ) . '#reset', 'برای پاکسازی، کلمه RESET را تایپ کنید.', false );
	}
	$parts = array_map( 'sanitize_key', (array) ( $_POST['parts'] ?? array() ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$done  = sap_project_reset( $id, $parts );
	if ( ! $done ) {
		sap_back( sap_project_url( $id, 'settings' ) . '#reset', 'هیچ بخشی انتخاب نشده بود.', false );
	}
	sap_back( sap_project_url( $id, 'overview' ), sprintf( 'پاک شد: %s. کارنامه ماه ذخیره شده است؛ حالا «تحلیل کامل» را بزنید تا همه چیز از نو ساخته شود.', implode( '، ', $done ) ) );
} );

add_action( 'admin_post_sap_regen_key', function () {
	sap_guard( 'sap_regen_key' );
	global $wpdb;
	$id = absint( sap_post( 'project_id' ) );
	$wpdb->update( sap_table( 'projects' ), array( 'api_key' => sap_token( 32 ) ), array( 'id' => $id ) );
	sap_back( sap_project_url( $id, 'settings' ), 'کلید API پروژه عوض شد؛ ایجنت خزش و اتصال SeoReport را با کلید جدید بروز کنید.' );
} );

add_action( 'admin_post_sap_crawl_upload', function () {
	sap_guard( 'sap_crawl_upload' );
	$id = absint( sap_post( 'project_id' ) );
	if ( empty( $_FILES['crawl']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		sap_back( sap_project_url( $id, 'settings' ), 'فایلی انتخاب نشده است.', false );
	}
	$rows = sap_crawl_rows_from_file( $_FILES['crawl']['tmp_name'], sanitize_file_name( wp_unslash( $_FILES['crawl']['name'] ?? '' ) ) ); // phpcs:ignore
	if ( is_wp_error( $rows ) ) {
		sap_back( sap_project_url( $id, 'settings' ), $rows->get_error_message(), false );
	}
	sap_sf_store_crawl( $id, $rows, 'upload' );
	sap_back( sap_project_url( $id, 'overview' ), sprintf( 'خزش با %s آدرس دریافت شد. برای اعمال در تحلیل، «همگام‌سازی و تحلیل» را اجرا کنید.', sap_num( count( $rows ) ) ) );
} );

add_action( 'admin_post_sap_review_done', function () {
	sap_guard( 'sap_review_done' );
	$id = absint( sap_post( 'project_id' ) );
	sap_project_set_option( $id, 'last_review', sap_now() );
	sap_activity_add( $id, array(
		'category' => 'management',
		'action'   => 'weekly_review',
		'title'    => 'بازبینی هفتگی اکشن پلن (Weekly Review)',
		'source'   => 'system',
	) );
	sap_back( sap_project_url( $id, 'overview' ), 'بازبینی هفتگی ثبت شد.' );
} );

/* ---- Focuses ----------------------------------------------------------- */

add_action( 'admin_post_sap_save_focus', function () {
	sap_guard( 'sap_save_focus' );
	$pid  = absint( sap_post( 'project_id' ) );
	$fid  = absint( sap_post( 'focus_id' ) );
	$data = array();
	foreach ( array( 'name', 'url_patterns', 'clusters', 'keywords', 'goal', 'kpi_metric', 'kpi_baseline', 'kpi_target', 'deadline', 'weight', 'status' ) as $k ) {
		$data[ $k ] = sap_post( $k );
	}
	if ( '' === trim( (string) $data['name'] ) ) {
		sap_back( sap_project_url( $pid, 'focuses' ), 'نام Focus الزامی است.', false );
	}
	$fid   = sap_focus_save( $pid, $data, $fid );
	$focus = sap_focus( $fid );
	// A new focus without baseline starts from today's value.
	if ( $focus && ! $focus['kpi_baseline'] ) {
		$k = sap_focus_kpis( $focus, $pid );
		if ( $k ) {
			global $wpdb;
			$wpdb->update( sap_table( 'focuses' ), array( 'kpi_baseline' => $k[ $focus['kpi_metric'] ] ), array( 'id' => $fid ) );
		}
	}
	sap_back( sap_project_url( $pid, 'focuses' ), 'Focus ذخیره شد. برای اعمال روی یافته‌ها، تحلیل را دوباره اجرا کنید.' );
} );

add_action( 'admin_post_sap_quick_focus', function () {
	sap_guard( 'sap_quick_focus' );
	$pid  = absint( sap_post( 'project_id' ) );
	$name = trim( (string) sap_post( 'name' ) );
	if ( '' === $name ) {
		sap_back( sap_project_url( $pid, 'focuses' ), 'نام Focus را وارد کنید.', false );
	}
	$clusters = (array) sap_post( 'cluster_pick', array() );
	$fid      = sap_focus_save( $pid, array(
		'name'         => $name,
		'url_patterns' => sap_post( 'url_patterns' ),
		'clusters'     => implode( "\n", array_filter( array_merge( $clusters, sap_rows( sap_post( 'clusters' ) ) ) ) ),
		'keywords'     => sap_post( 'keywords' ) ?: $name,
		'goal'         => sap_post( 'goal' ),
		'kpi_metric'   => sap_post( 'kpi_metric', 'clicks' ),
		'kpi_target'   => sap_post( 'kpi_target' ),
		'weight'       => 1.5,
		'status'       => 'active',
	) );
	$focus = sap_focus( $fid );
	$k     = $focus ? sap_focus_kpis( $focus, $pid ) : null;
	if ( $k ) {
		global $wpdb;
		$wpdb->update( sap_table( 'focuses' ), array( 'kpi_baseline' => $k[ $focus['kpi_metric'] ] ), array( 'id' => $fid ) );
	}
	sap_back( sap_return_url( sap_project_url( $pid ) ), sprintf( 'Focus «%s» اضافه شد. با «تحلیل کامل» یافته‌ها و اکشن پلن در راستای آن چیده می‌شوند.', $name ) );
} );

add_action( 'admin_post_sap_focus_skip', function () {
	sap_guard( 'sap_focus_skip' );
	$pid = absint( sap_post( 'project_id' ) );
	sap_project_set_option( $pid, 'focus_skipped', 1 );
	sap_back( sap_project_url( $pid ), 'بدون Focus ادامه می‌دهیم؛ هر وقت خواستید از تب «Focus و اهداف» اضافه کنید.' );
} );

add_action( 'admin_post_sap_delete_focus', function () {
	sap_guard( 'sap_delete_focus' );
	$pid = absint( sap_post( 'project_id' ) );
	sap_focus_delete( $pid, absint( sap_post( 'focus_id' ) ) );
	sap_back( sap_project_url( $pid, 'focuses' ), 'Focus حذف شد.' );
} );

add_action( 'admin_post_sap_focus_baseline', function () {
	sap_guard( 'sap_focus_baseline' );
	global $wpdb;
	$pid   = absint( sap_post( 'project_id' ) );
	$focus = sap_focus( absint( sap_post( 'focus_id' ) ) );
	$k     = $focus ? sap_focus_kpis( $focus, $pid ) : null;
	if ( ! $k ) {
		sap_back( sap_project_url( $pid, 'focuses' ), 'داده سرچ کنسول هنوز دریافت نشده است.', false );
	}
	$wpdb->update( sap_table( 'focuses' ), array( 'kpi_baseline' => $k[ $focus['kpi_metric'] ] ), array( 'id' => $focus['id'] ) );
	sap_back( sap_project_url( $pid, 'focuses' ), 'مقدار فعلی به‌عنوان نقطه شروع (Baseline) ثبت شد.' );
} );

/* ---- Findings (inbox) -------------------------------------------------- */

add_action( 'admin_post_sap_findings', function () {
	sap_guard( 'sap_findings' );
	$pid    = absint( sap_post( 'project_id' ) );
	$do     = sanitize_key( sap_post( 'do' ) );
	$period = sap_period_sanitize( sap_post( 'period', sap_period_current() ) );
	$ids    = array_map( 'absint', (array) sap_post( 'ids', array() ) );
	// Per-row buttons send quick="do:id" inside the bulk form.
	if ( preg_match( '/^([a-z]+):(\d+)$/', (string) sap_post( 'quick' ), $m ) ) {
		$do  = $m[1];
		$ids = array( (int) $m[2] );
	}
	$n = 0;
	foreach ( $ids as $id ) {
		$f = sap_finding( $id );
		if ( ! $f || $f['project_id'] !== $pid ) {
			continue;
		}
		if ( 'plan' === $do ) {
			sap_finding_to_task( $f, $period );
		} elseif ( 'someday' === $do ) {
			sap_finding_to_task( $f, '', 'someday' );
		} elseif ( 'dismiss' === $do ) {
			sap_finding_set_status( $id, 'dismissed' );
		} elseif ( 'reopen' === $do ) {
			sap_finding_set_status( $id, 'open' );
		} elseif ( 'resolve' === $do ) {
			sap_finding_mark_resolved( $f );
		}
		++$n;
	}
	$labels = array(
		'plan'    => 'به اکشن پلن ' . sap_period_label( $period ) . ' اضافه شد',
		'someday' => 'به «شاید بعداً» منتقل شد',
		'dismiss' => 'رد شد',
		'reopen'  => 'دوباره باز شد',
		'resolve' => 'به‌عنوان حل‌شده ثبت شد',
	);
	sap_back( sap_return_url( sap_project_url( $pid, 'findings' ) ), sprintf( '%s مورد %s.', sap_num( $n ), $labels[ $do ] ?? 'بروز شد' ), $n > 0 );
} );

/* ---- Plan & tasks ------------------------------------------------------ */

add_action( 'admin_post_sap_plan_generate', function () {
	sap_guard( 'sap_plan_generate' );
	$pid    = absint( sap_post( 'project_id' ) );
	$period = sap_period_sanitize( sap_post( 'period' ) );
	$cap    = (float) sap_norm_text( sap_post( 'capacity' ) );
	$res    = sap_plan_generate( $pid, $period, $cap > 0 ? array( 'capacity' => $cap ) : array() );
	$parts = array();
	if ( $res['ai'] ) {
		$parts[] = sap_num( $res['ai'] ) . ' کار هوشمند';
	}
	if ( $res['added'] ) {
		$parts[] = sap_num( $res['added'] ) . ' اقدام از یافته‌ها';
	}
	if ( $res['recurring'] ) {
		$parts[] = sap_num( $res['recurring'] ) . ' کار دوره‌ای';
	}
	sap_back( sap_project_url( $pid, 'plan', array( 'period' => $period ) ), $parts
		? sprintf( 'اکشن پلن %s آماده شد: %s — %s از %s ساعت ظرفیت.', sap_period_label( $period ), implode( '، ', $parts ), sap_num( $res['hours'], 1 ), sap_num( $res['capacity'] ) )
		: 'کار جدیدی اضافه نشد؛ ظرفیت ماه پر است یا یافته/پیشنهاد جدیدی نیست.', (bool) $parts );
} );

add_action( 'admin_post_sap_plan_carry', function () {
	sap_guard( 'sap_plan_carry' );
	$pid  = absint( sap_post( 'project_id' ) );
	$from = sap_period_sanitize( sap_post( 'from' ) );
	$to   = sap_period_shift( $from, 1 );
	$n    = sap_plan_carry_over( $pid, $from, $to );
	sap_back( sap_project_url( $pid, 'plan', array( 'period' => $to ) ), sprintf( '%s کار ناتمام از %s به %s منتقل شد.', sap_num( $n ), sap_period_label( $from ), sap_period_label( $to ) ) );
} );

add_action( 'admin_post_sap_task_save', function () {
	sap_guard( 'sap_task_save', false );
	$id   = absint( sap_post( 'task_id' ) );
	$task = $id ? sap_task( $id ) : null;
	$pid  = $task ? $task['project_id'] : absint( sap_post( 'project_id' ) );
	if ( ! sap_can() && ( ! $task || (int) $task['assignee'] !== get_current_user_id() ) ) {
		wp_die( 'فقط کارهای خودتان را می‌توانید ویرایش کنید.', 403 );
	}
	$fields = sap_can()
		? array( 'title', 'notes', 'result', 'context', 'status', 'period', 'assignee', 'waiting_for', 'due_date', 'effort', 'url', 'keyword', 'focus_id', 'outcome', 'spent' )
		: array( 'status', 'result', 'url', 'spent' );
	$data   = array();
	foreach ( $fields as $k ) {
		if ( isset( $_POST[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$data[ $k ] = sap_post( $k );
		}
	}
	if ( ! $id ) {
		$data['project_id'] = $pid;
		if ( '' === trim( (string) ( $data['title'] ?? '' ) ) ) {
			sap_back( sap_return_url( sap_project_url( $pid, 'plan' ) ), 'عنوان کار الزامی است.', false );
		}
	}
	sap_task_save( $data, $id );
	sap_back( sap_return_url( sap_project_url( $pid, 'plan' ) ), $id ? 'کار بروز شد.' : 'کار به اکشن پلن اضافه شد.' );
} );

add_action( 'admin_post_sap_task_delete', function () {
	sap_guard( 'sap_task_delete' );
	$task = sap_task( absint( sap_post( 'task_id' ) ) );
	if ( $task ) {
		sap_task_delete( $task['id'] );
	}
	sap_back( sap_return_url( sap_project_url( $task ? $task['project_id'] : 0, 'plan' ) ), 'کار حذف شد (یافته مرتبط به صندوق ورودی برگشت).' );
} );

/* ---- Activity log ------------------------------------------------------ */

add_action( 'admin_post_sap_activity_add', function () {
	sap_guard( 'sap_activity_add', false );
	$pid  = absint( sap_post( 'project_id' ) );
	$date = sap_parse_date( sap_post( 'date' ) );
	sap_activity_add( $pid, array(
		'focus_id'     => absint( sap_post( 'focus_id' ) ),
		'category'     => sanitize_key( sap_post( 'category' ) ),
		'action'       => 'manual',
		'title'        => sap_post( 'title' ),
		'url'          => sap_post( 'url' ),
		'before_value' => sap_post( 'before_value' ),
		'after_value'  => sap_post( 'after_value' ),
		'source'       => 'manual',
		'happened_at'  => $date ? $date . ' ' . current_time( 'H:i:s' ) : sap_now(),
	) );
	sap_back( sap_return_url( sap_project_url( $pid, 'activity' ) ), 'اقدام در لاگ ثبت شد.' );
} );

add_action( 'admin_post_sap_activity_delete', function () {
	sap_guard( 'sap_activity_delete' );
	$pid = absint( sap_post( 'project_id' ) );
	sap_activity_delete( $pid, absint( sap_post( 'activity_id' ) ) );
	sap_back( sap_return_url( sap_project_url( $pid, 'activity' ) ), 'ردیف لاگ حذف شد.' );
} );

add_action( 'admin_post_sap_activity_csv', function () {
	if ( ! sap_can() || ! isset( $_GET['_sap_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_sap_nonce'] ) ), 'sap_activity_csv' ) ) {
		wp_die( 'دسترسی غیرمجاز', 403 );
	}
	$project = sap_project( absint( $_GET['project'] ?? 0 ) );
	if ( ! $project ) {
		wp_die( 'پروژه یافت نشد', 404 );
	}
	$period          = sap_period_sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ?? '' ) ) );
	list( $s, $e )   = sap_period_range( $period );
	$export          = sap_activity_export( $project, $s, $e );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="activity-' . $project['id'] . '-' . $period . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fputcsv( $out, array( 'تاریخ', 'حوزه', 'Focus', 'اقدام', 'آدرس', 'قبل', 'بعد', 'منبع', 'انجام‌دهنده' ), ',', '"', '\\' );
	foreach ( $export['items'] as $i ) {
		fputcsv( $out, array( $i['date_jalali'], $i['category_label'], $i['focus'], $i['title'], rawurldecode( (string) $i['url'] ), $i['before'], $i['after'], sap_activity_sources()[ $i['source'] ] ?? $i['source'], $i['user'] ), ',', '"', '\\' );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
} );

/**
 * Apply an AI focus recommendation: create the suggested focus, or update the
 * goal, metric, target, deadline and weight of an existing one.
 */
add_action( 'admin_post_sap_ai_focus_apply', function () {
	sap_guard( 'sap_ai_focus_apply' );
	$pid  = absint( sap_post( 'project_id' ) );
	$ref  = sanitize_key( (string) sap_post( 'ref' ) );
	$back = sap_project_url( $pid, 'focuses' );
	$rec  = null;
	foreach ( (array) ( sap_dataset_payload( $pid, 'ai_focus' )['focuses'] ?? array() ) as $f ) {
		if ( $f['ref'] === $ref ) {
			$rec = $f;
		}
	}
	if ( ! $rec ) {
		sap_back( $back, 'پیشنهاد پیدا نشد؛ تحلیل Focus را دوباره اجرا کنید.', false );
	}
	$old  = 'f' === $ref[0] ? sap_focus( (int) substr( $ref, 1 ) ) : null;
	$data = array(
		'name'         => $rec['name'],
		'goal'         => $rec['goal'],
		'kpi_metric'   => $rec['metric'],
		'kpi_baseline' => null !== $rec['current'] ? $rec['current'] : 0,
		'kpi_target'   => $rec['target'],
		'deadline'     => gmdate( 'Y-m-d', strtotime( '+' . (int) $rec['months'] . ' months' ) ),
		'weight'       => $rec['weight'],
		'status'       => 'active',
		'ai_meta'      => array(
			'scores'        => array( 'potential' => (int) $rec['potential'], 'value' => (int) $rec['value'], 'urgency' => (int) $rec['urgency'] ),
			'metric_reason' => $rec['metric_reason'],
			'rationale'     => $rec['rationale'],
			'first_steps'   => $rec['first_steps'],
			'at'            => sap_now(),
		),
	);
	if ( $old ) {
		$data += array( 'url_patterns' => implode( "\n", $old['url_patterns'] ), 'clusters' => implode( "\n", $old['clusters'] ), 'keywords' => implode( "\n", $old['keywords'] ) );
		sap_focus_save( $pid, $data, $old['id'] );
		sap_back( $back, sprintf( 'هدف و اولویت «%s» بروز شد.', $rec['name'] ) );
	}
	sap_focus_save( $pid, $data + (array) ( $rec['def'] ?? array() ) );
	sap_back( $back, sprintf( 'Focus «%s» با هدف و شاخص پیشنهادی ساخته شد. برای اعمال روی یافته‌ها، تحلیل را دوباره اجرا کنید.', $rec['name'] ) );
} );

/**
 * Client site connection (connector plugin URL + Sokan key).
 */
add_action( 'admin_post_sap_site_save', function () {
	sap_guard( 'sap_site_save' );
	$pid = absint( sap_post( 'project_id' ) );
	sap_project_set_option( $pid, 'site_url', esc_url_raw( trim( (string) sap_post( 'site_conn_url' ) ) ) );
	$key = trim( (string) sap_post( 'site_conn_key' ) );
	if ( '' !== $key ) {
		sap_project_set_option( $pid, 'site_key', sap_encrypt( $key ) );
	}
	$project = sap_project( $pid );
	$ping    = sap_site_connected( $project ) ? sap_site_request( $project, 'GET', 'ping' ) : null;
	if ( is_wp_error( $ping ) ) {
		sap_back( sap_project_url( $pid, 'settings' ) . '#site-connector', 'ذخیره شد، اما اتصال برقرار نشد: ' . $ping->get_error_message(), false );
	}
	sap_back( sap_project_url( $pid, 'settings' ) . '#site-connector', $ping ? sprintf( 'اتصال به «%s» برقرار شد (افزونه رابط %s%s).', $ping['site'] ?? '', $ping['version'] ?? '', empty( $ping['write'] ) ? '، اعمال تغییرات غیرفعال' : '' ) : 'ذخیره شد.' );
} );

/* ---- Alert rules of a project ------------------------------------------ */

add_action( 'admin_post_sap_alert_rules', function () {
	sap_guard( 'sap_alert_rules' );
	$pid   = absint( sap_post( 'project_id' ) );
	$in    = (array) sap_post( 'rules', array() );
	$rules = array();
	foreach ( sap_alert_rules() as $rk => $def ) {
		$rules[ $rk ] = array( 'on' => empty( $in[ $rk ]['on'] ) ? 0 : 1 );
		foreach ( $def[3] as $ck => $cv ) {
			if ( 'on' !== $ck && isset( $in[ $rk ][ $ck ] ) ) {
				$rules[ $rk ][ $ck ] = max( 0, (float) sap_norm_text( (string) $in[ $rk ][ $ck ] ) );
			}
		}
	}
	sap_project_set_option( $pid, 'alert_rules', $rules );
	sap_back( sap_project_url( $pid, 'alerts' ), 'قوانین هشدار ذخیره شد.' );
} );

/* ---- Team members ------------------------------------------------------ */

add_action( 'admin_post_sap_member_save', function () {
	sap_guard( 'sap_member_save' );
	$uid  = absint( sap_post( 'user_id' ) );
	$data = array(
		'name'         => sap_post( 'name' ),
		'email'        => sap_post( 'email' ),
		'phone'        => sap_post( 'phone' ),
		'title'        => sap_post( 'title' ),
		'notify_sms'   => sap_post( 'notify_sms' ) ? 1 : 0,
		'notify_email' => sap_post( 'notify_email' ) ? 1 : 0,
		'password'     => (string) sap_post( 'password' ),
	);
	if ( '' !== $data['password'] && strlen( $data['password'] ) < 8 ) {
		sap_back( sap_url( 'team' ), 'رمز عبور باید حداقل ۸ کاراکتر باشد.', false );
	}
	if ( ! $uid ) {
		$uid = sap_member_create( $data );
		if ( is_wp_error( $uid ) ) {
			sap_back( sap_url( 'team' ), $uid->get_error_message(), false );
		}
		sap_back( sap_url( 'team' ), sap_invite_message( $GLOBALS['sap_last_invite'] ?? array(), '' !== $data['password'] ), ! empty( $GLOBALS['sap_last_invite']['email'] ) || '' !== $data['password'] );
	}
	sap_member_update( $uid, $data );
	// A new password only for team members (never for managers from this screen).
	if ( '' !== $data['password'] && sap_is_member_only( get_userdata( $uid ) ) ) {
		wp_set_password( $data['password'], $uid );
	}
	foreach ( (array) sap_post( 'roles', array() ) as $pid => $role ) {
		$p = sap_project( absint( $pid ) );
		if ( ! $p ) {
			continue;
		}
		$members = (array) ( $p['options']['members'] ?? array() );
		if ( '' === $role || ! isset( sap_member_roles()[ $role ] ) ) {
			unset( $members[ $uid ] );
		} else {
			$members[ $uid ] = $role;
		}
		sap_project_set_option( $p['id'], 'members', $members );
	}
	sap_back( sap_url( 'team' ), 'پروفایل ذخیره شد.' );
} );

/**
 * Result of an invitation in words.
 */
function sap_invite_message( $r, $has_password = false ) {
	$parts = array( 'عضو اضافه شد.' );
	if ( ! empty( $r['email'] ) ) {
		$parts[] = 'ایمیل دعوت (آدرس پنل و ' . ( $has_password ? 'نام کاربری' : 'لینک تعیین رمز' ) . ') ارسال شد.';
	} else {
		$log     = (array) get_option( 'sap_notify_log', array() );
		$last    = end( $log );
		$parts[] = 'ایمیل دعوت ارسال نشد' . ( $last && 'email' === $last['ch'] && $last['err'] ? ': ' . $last['err'] : '.' ) . ( $has_password ? ' عضو با رمزی که تعیین کردید از آدرس پنل وارد می‌شود.' : ' از دکمه «لینک تعیین رمز» در کارت عضو، لینک را کپی و برایش بفرستید.' );
	}
	if ( true === ( $r['sms'] ?? null ) ) {
		$parts[] = 'پیامک دعوت هم ارسال شد.';
	}
	return implode( ' ', $parts );
}

add_action( 'admin_post_sap_member_invite', function () {
	sap_guard( 'sap_member_invite' );
	$uid = absint( sap_post( 'user_id' ) );
	if ( ! get_userdata( $uid ) ) {
		sap_back( sap_url( 'team' ), 'عضو پیدا نشد.', false );
	}
	$r = sap_member_invite( $uid, sap_is_member_only( get_userdata( $uid ) ) );
	sap_back( sap_url( 'team' ), str_replace( 'عضو اضافه شد. ', '', sap_invite_message( $r ) ), ! empty( $r['email'] ) || true === $r['sms'] );
} );

add_action( 'admin_post_sap_rank_keywords', function () {
	sap_guard( 'sap_rank_keywords' );
	$pid = absint( sap_post( 'project_id' ) );
	sap_project_set_option( $pid, 'rank_keywords', implode( "\n", array_slice( sap_list( sanitize_textarea_field( (string) sap_post( 'rank_keywords' ) ) ), 0, 300 ) ) );
	sap_back( sap_project_url( $pid, 'ranks' ), 'کلمات ردیابی ذخیره شد؛ از بروزرسانی بعدی ردیابی می‌شوند.' );
} );
