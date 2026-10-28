<?php
/**
 * Plugin Name:       سُکان — Sokan by Ronagh
 * Description:       سکان، هدایت هوشمند سئو (اکشن پلن سئو): تحلیل داده‌های سرچ کنسول، گوگل شیت، سئو سیگنال و اسکریمینگ فراگ، کشف ایرادها و فرصت‌ها، ساخت اکشن پلن ماهانه به روش GTD و ثبت صفر تا صد اقدامات برای گزارش سئو.
 * Version:           0.13.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Mehdi Ilkhani Nasab
 * License:           GPL-2.0-or-later
 * Text Domain:       seo-action-plan
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

const SAP_VERSION    = '0.13.1';
const SAP_DB_VERSION = '7';
const SAP_CAP        = 'sap_manage'; // Full access: projects, settings, plans.
const SAP_CAP_WORK   = 'sap_work';   // Team members: their own tasks.

define( 'SAP_FILE', __FILE__ );
define( 'SAP_DIR', __DIR__ );
define( 'SAP_URL', plugin_dir_url( __FILE__ ) );

foreach ( array(
	'core/helpers',
	'core/jalali',
	'core/settings',
	'core/network',
	'core/install',
	'models/projects',
	'models/focuses',
	'models/datasets',
	'models/findings',
	'models/tasks',
	'models/activity',
	'models/keymap',
	'models/revisions',
	'models/comments',
	'models/ideas',
	'models/updates',
	'connectors/google',
	'connectors/gsc',
	'connectors/sheets',
	'connectors/seosignal',
	'connectors/screaming-frog',
	'connectors/llm',
	'connectors/pages',
	'connectors/pagespeed',
	'connectors/site',
	'engine/detectors',
	'engine/kpi',
	'engine/ai',
	'engine/content',
	'engine/ideas',
	'engine/ideas-pool',
	'engine/calendar',
	'engine/graph',
	'engine/seosignal-tools',
	'engine/seosignal-setup',
	'engine/months',
	'engine/technical',
	'engine/pillars',
	'engine/inventory',
	'engine/keymap-rules',
	'engine/smart',
	'engine/custom',
	'engine/impact',
	'engine/report-export',
	'engine/sf-agent',
	'engine/audit',
	'engine/offpage',
	'engine/planner',
	'engine/sync',
	'rest',
	'app/ui',
	'app/router',
	'app/actions',
) as $sap_file ) {
	require_once SAP_DIR . '/inc/' . $sap_file . '.php';
}
unset( $sap_file );

register_activation_hook( __FILE__, 'sap_install' );
register_deactivation_hook( __FILE__, 'sap_deactivate' );
