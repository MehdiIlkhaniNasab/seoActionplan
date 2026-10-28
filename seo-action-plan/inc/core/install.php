<?php
/**
 * Database tables, roles and scheduled sync.
 *
 * All tables use the {prefix}sap_ prefix so the plugin never collides with
 * SeoReport or anything else installed on the same WordPress.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Table name helper.
 */
function sap_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'sap_' . $name;
}

/**
 * Create / upgrade tables, grant capabilities, schedule the sync.
 */
function sap_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();

	dbDelta( 'CREATE TABLE ' . sap_table( 'projects' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(190) NOT NULL DEFAULT '',
		site_url varchar(255) NOT NULL DEFAULT '',
		gsc_property varchar(255) NOT NULL DEFAULT '',
		sheet_id varchar(190) NOT NULL DEFAULT '',
		sheet_calendar_tab varchar(190) NOT NULL DEFAULT '',
		sheet_cluster_tab varchar(190) NOT NULL DEFAULT '',
		ss_project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		ss_device varchar(10) NOT NULL DEFAULT 'mobile',
		competitors text NULL,
		brand_terms text NULL,
		capacity_hours decimal(6,2) NOT NULL DEFAULT 40,
		api_key varchar(64) NOT NULL DEFAULT '',
		options longtext NULL,
		status varchar(20) NOT NULL DEFAULT 'active',
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'focuses' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		name varchar(190) NOT NULL DEFAULT '',
		url_patterns text NULL,
		clusters text NULL,
		keywords text NULL,
		goal text NULL,
		kpi_metric varchar(20) NOT NULL DEFAULT 'clicks',
		kpi_baseline decimal(14,2) NOT NULL DEFAULT 0,
		kpi_target decimal(14,2) NOT NULL DEFAULT 0,
		deadline date NULL,
		weight decimal(4,2) NOT NULL DEFAULT 1.5,
		ai_meta longtext NULL,
		status varchar(20) NOT NULL DEFAULT 'active',
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY project_id (project_id)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'datasets' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		source varchar(64) NOT NULL DEFAULT '',
		payload longtext NULL,
		meta text NULL,
		fetched_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY project_source (project_id,source)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'findings' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		focus_id bigint(20) unsigned NOT NULL DEFAULT 0,
		detector varchar(40) NOT NULL DEFAULT '',
		fingerprint varchar(64) NOT NULL DEFAULT '',
		context varchar(30) NOT NULL DEFAULT '',
		title varchar(255) NOT NULL DEFAULT '',
		detail text NULL,
		url text NULL,
		keyword varchar(255) NOT NULL DEFAULT '',
		evidence longtext NULL,
		impact decimal(12,2) NOT NULL DEFAULT 0,
		confidence decimal(4,2) NOT NULL DEFAULT 0.7,
		effort decimal(6,2) NOT NULL DEFAULT 1,
		score decimal(6,2) NOT NULL DEFAULT 0,
		status varchar(20) NOT NULL DEFAULT 'open',
		task_id bigint(20) unsigned NOT NULL DEFAULT 0,
		first_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY project_fp (project_id,fingerprint),
		KEY project_status (project_id,status)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'tasks' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		focus_id bigint(20) unsigned NOT NULL DEFAULT 0,
		finding_id bigint(20) unsigned NOT NULL DEFAULT 0,
		title varchar(255) NOT NULL DEFAULT '',
		notes text NULL,
		result text NULL,
		context varchar(30) NOT NULL DEFAULT 'management',
		status varchar(20) NOT NULL DEFAULT 'inbox',
		period varchar(7) NOT NULL DEFAULT '',
		assignee bigint(20) unsigned NOT NULL DEFAULT 0,
		waiting_for varchar(190) NOT NULL DEFAULT '',
		due_date date NULL,
		effort decimal(6,2) NOT NULL DEFAULT 1,
		score decimal(6,2) NOT NULL DEFAULT 0,
		url text NULL,
		keyword varchar(255) NOT NULL DEFAULT '',
		recurring varchar(64) NOT NULL DEFAULT '',
		source varchar(20) NOT NULL DEFAULT 'manual',
		ai_ref varchar(64) NOT NULL DEFAULT '',
		ai_output longtext NULL,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		done_at datetime NULL,
		PRIMARY KEY  (id),
		KEY project_period (project_id,period),
		KEY assignee (assignee)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'activity' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		focus_id bigint(20) unsigned NOT NULL DEFAULT 0,
		task_id bigint(20) unsigned NOT NULL DEFAULT 0,
		category varchar(30) NOT NULL DEFAULT 'management',
		action varchar(40) NOT NULL DEFAULT 'manual',
		title varchar(255) NOT NULL DEFAULT '',
		url text NULL,
		before_value text NULL,
		after_value text NULL,
		source varchar(20) NOT NULL DEFAULT 'manual',
		ref varchar(64) NOT NULL DEFAULT '',
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		happened_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		meta longtext NULL,
		PRIMARY KEY  (id),
		KEY project_time (project_id,happened_at),
		KEY ref (project_id,ref)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'keymap' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		url_key varchar(190) NOT NULL DEFAULT '',
		url text NULL,
		title varchar(255) NOT NULL DEFAULT '',
		primary_kw varchar(190) NOT NULL DEFAULT '',
		secondary text NULL,
		intent varchar(20) NOT NULL DEFAULT '',
		page_type varchar(20) NOT NULL DEFAULT '',
		focus_id bigint(20) unsigned NOT NULL DEFAULT 0,
		source varchar(10) NOT NULL DEFAULT 'auto',
		confirmed tinyint(1) NOT NULL DEFAULT 0,
		reason text NULL,
		status varchar(12) NOT NULL DEFAULT 'target',
		redirect_to text NULL,
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY project_url (project_id,url_key)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'revisions' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		task_id bigint(20) unsigned NOT NULL DEFAULT 0,
		url text NULL,
		title varchar(255) NOT NULL DEFAULT '',
		kind varchar(20) NOT NULL DEFAULT 'content',
		status varchar(20) NOT NULL DEFAULT 'analyzing',
		original longtext NULL,
		audit longtext NULL,
		edits longtext NULL,
		progress text NULL,
		cost decimal(10,5) NOT NULL DEFAULT 0,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY project_id (project_id)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'comments' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		object_type varchar(20) NOT NULL DEFAULT '',
		object_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		body text NULL,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY object (object_type,object_id)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'ideas' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		focus_id bigint(20) unsigned NOT NULL DEFAULT 0,
		keyword varchar(190) NOT NULL DEFAULT '',
		secondary text NULL,
		title varchar(255) NOT NULL DEFAULT '',
		brief text NULL,
		goal text NULL,
		intent varchar(20) NOT NULL DEFAULT '',
		cluster varchar(190) NOT NULL DEFAULT '',
		volume int(11) NOT NULL DEFAULT 0,
		kp int(11) NOT NULL DEFAULT 0,
		priority varchar(10) NOT NULL DEFAULT 'medium',
		links longtext NULL,
		outline longtext NULL,
		source_note text NULL,
		evidence text NULL,
		origin varchar(10) NOT NULL DEFAULT 'ai',
		status varchar(20) NOT NULL DEFAULT 'new',
		period varchar(7) NOT NULL DEFAULT '',
		slot int(11) NOT NULL DEFAULT 0,
		planned_date date NULL,
		published_url text NULL,
		task_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY project_status (project_id,status)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'updates' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		focus_id bigint(20) unsigned NOT NULL DEFAULT 0,
		url text NULL,
		title varchar(255) NOT NULL DEFAULT '',
		content_type varchar(20) NOT NULL DEFAULT '',
		keyword varchar(190) NOT NULL DEFAULT '',
		secondary text NULL,
		reason text NULL,
		brief longtext NULL,
		priority varchar(10) NOT NULL DEFAULT 'medium',
		status varchar(20) NOT NULL DEFAULT 'planned',
		period varchar(7) NOT NULL DEFAULT '',
		planned_date date NULL,
		task_id bigint(20) unsigned NOT NULL DEFAULT 0,
		source varchar(20) NOT NULL DEFAULT 'manual',
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		done_at datetime NULL,
		PRIMARY KEY  (id),
		KEY project_period (project_id,period)
	) $c;" );

	dbDelta( 'CREATE TABLE ' . sap_table( 'months' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		period varchar(7) NOT NULL DEFAULT '',
		data longtext NULL,
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY project_period (project_id,period)
	) $c;" );

	foreach ( array( 'administrator' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->add_cap( SAP_CAP );
			$role->add_cap( SAP_CAP_WORK );
		}
	}
	add_role( 'sap_member', 'عضو تیم سئو', array( 'read' => true, SAP_CAP_WORK => true ) );

	if ( ! wp_next_scheduled( 'sap_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sap_daily' );
	}
	update_option( 'sap_db_version', SAP_DB_VERSION, false );
}

/**
 * Stop scheduled jobs on deactivation (data is kept).
 */
function sap_deactivate() {
	wp_clear_scheduled_hook( 'sap_daily' );
	wp_clear_scheduled_hook( 'sap_sync_project' );
	delete_option( 'sap_rewrite_version' );
	flush_rewrite_rules( false );
}

add_action( 'plugins_loaded', function () {
	if ( get_option( 'sap_db_version' ) !== SAP_DB_VERSION ) {
		sap_install();
	}
} );
