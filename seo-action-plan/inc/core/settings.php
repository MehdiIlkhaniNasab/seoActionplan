<?php
/**
 * Global settings: API credentials, detector thresholds, recurring tasks.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

const SAP_SECRET_KEYS = array( 'google_client_secret', 'google_refresh_token', 'google_service_account', 'seosignal_key', 'anthropic_key', 'ai_gateway_key' );

/**
 * Default settings.
 */
function sap_settings_defaults() {
	return array(
		'google_auth_mode'       => 'oauth', // oauth|service.
		'google_client_id'       => '',
		'google_client_secret'   => '',
		'google_refresh_token'   => '',
		'google_account_email'   => '',
		'google_scopes'          => '',
		'google_service_account' => '',
		'seosignal_key'          => '',
		'ai_provider'            => 'gateway', // gateway (OpenAI-compatible, e.g. GapGPT) | anthropic (direct).
		'ai_base_url'            => 'https://api.gapgpt.app/v1',
		'ai_gateway_key'         => '',
		'anthropic_key'          => '',
		'ai_model'               => 'claude-sonnet-5-5', // Analysis.
		'ai_model_assist'        => 'claude-haiku-5-5',  // «بنویس برام».
		'ai_budget'              => 10,  // Monthly AI budget in USD; AI stops when reached.
		'ai_auto'                => 0,   // Run the AI analysis in the scheduled weekly sync too (costs API credit).
		'use_recurring'          => 0,   // Add the recurring tasks below when a plan is generated.
		'seosignal_daily_cap'    => 40,  // Of the 50 daily SEO Signal requests, leave some for the panel / SeoReport.
		'seosignal_cache_days'   => 25,  // Search volumes are monthly data: refresh at most once a month.
		'min_impressions'        => 50,  // Striking distance / cannibalization noise floor (28 days).
		'ctr_min_impressions'    => 200, // Low-CTR detector floor.
		'decay_min_clicks'       => 30,  // Content decay: page must have had this many clicks.
		'decay_drop'             => 30,  // Content decay: % drop.
		'auto_sync'              => 'weekly', // off|weekly|daily.
		'recurring'              => implode( "\n", array(
			'management | تهیه گزارش ماهانه سئو | 3',
		) ),
	);
}

/**
 * All settings (secrets decrypted).
 */
function sap_settings() {
	if ( isset( $GLOBALS['sap_settings_cache'] ) ) {
		return $GLOBALS['sap_settings_cache'];
	}
	$stored = get_option( 'sap_settings', array() );
	$s      = wp_parse_args( is_array( $stored ) ? $stored : array(), sap_settings_defaults() );
	foreach ( SAP_SECRET_KEYS as $k ) {
		$s[ $k ] = sap_decrypt( $s[ $k ] );
	}
	$GLOBALS['sap_settings_cache'] = $s;
	return $s;
}

/**
 * One setting.
 */
function sap_setting( $key, $default = '' ) {
	$s = sap_settings();
	return isset( $s[ $key ] ) && '' !== $s[ $key ] ? $s[ $key ] : $default;
}

/**
 * Partial update. Secrets are encrypted at rest.
 */
function sap_update_settings( $changes ) {
	$stored = get_option( 'sap_settings', array() );
	$stored = is_array( $stored ) ? $stored : array();
	foreach ( $changes as $k => $v ) {
		$stored[ $k ] = in_array( $k, SAP_SECRET_KEYS, true ) ? sap_encrypt( $v ) : $v;
	}
	update_option( 'sap_settings', $stored, false );
	unset( $GLOBALS['sap_settings_cache'] );
}

/**
 * Recurring monthly tasks parsed from settings: [context, title, hours].
 */
function sap_recurring_tasks() {
	$out = array();
	foreach ( sap_rows( sap_setting( 'recurring' ) ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( count( $parts ) < 2 ) {
			$parts = array( 'management', $parts[0] );
		}
		$ctx   = array_key_exists( $parts[0], sap_contexts() ) ? $parts[0] : 'management';
		$out[] = array(
			'context' => $ctx,
			'title'   => $parts[1],
			'hours'   => isset( $parts[2] ) ? max( 0.25, (float) sap_norm_text( $parts[2] ) ) : 1,
		);
	}
	return $out;
}
