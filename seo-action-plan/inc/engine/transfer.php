<?php
/**
 * Move projects between Sokan installs (e.g. localhost → the live site) with
 * everything they hold: focuses, findings, plan and tasks, log, keyword map,
 * revisions, notes, ideas, updates, monthly archives, alerts, rank history,
 * memory and the stored data sets. IDs are remapped, people are matched by
 * email, and secrets (encrypted with the source site's salts) travel decrypted
 * and are encrypted again with the destination's salts.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

const SAP_TRANSFER_FORMAT = 'sokan-projects';

/**
 * Project tables in import order: table => columns that point to other rows.
 */
function sap_transfer_tables() {
	return array(
		'focuses'   => array(),
		'datasets'  => array(),
		'findings'  => array( 'focus_id' => 'focuses' ),
		'tasks'     => array( 'focus_id' => 'focuses', 'finding_id' => 'findings', 'assignee' => 'users' ),
		'activity'  => array( 'focus_id' => 'focuses', 'task_id' => 'tasks', 'user_id' => 'users' ),
		'keymap'    => array( 'focus_id' => 'focuses' ),
		'revisions' => array( 'task_id' => 'tasks', 'user_id' => 'users' ),
		'ideas'     => array( 'focus_id' => 'focuses', 'task_id' => 'tasks' ),
		'updates'   => array( 'focus_id' => 'focuses', 'task_id' => 'tasks' ),
		'comments'  => array( 'user_id' => 'users' ), // object_id by object_type, below.
		'months'    => array(),
		'alerts'    => array( 'task_id' => 'tasks' ),
		'ranks'     => array(),
		'memory'    => array( 'user_id' => 'users' ),
	);
}

/**
 * Per-project options stored outside the projects table.
 */
function sap_transfer_options() {
	return array( 'sap_user_resolved_', 'sap_triage_', 'sap_audit_only_', 'sap_ss_recent_', 'sap_ranks_backfill_' );
}

/**
 * Build the export of some projects.
 *
 * @param int[] $ids     Project ids.
 * @param array $opts    secrets (bool: project connector key), settings (bool: global settings incl. API keys), raw (bool: large data sets).
 * @return array
 */
function sap_transfer_export( $ids, $opts = array() ) {
	global $wpdb;
	$opts  = wp_parse_args( $opts, array( 'secrets' => true, 'settings' => false, 'raw' => true ) );
	$out   = array(
		'format'      => SAP_TRANSFER_FORMAT,
		'version'     => 1,
		'plugin'      => SAP_VERSION,
		'exported_at' => sap_now(),
		'source'      => home_url( '/' ),
		'users'       => array(),
		'projects'    => array(),
	);
	$users = array();
	foreach ( array_map( 'absint', (array) $ids ) as $pid ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'projects' ) . ' WHERE id = %d', $pid ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			continue;
		}
		$options = json_decode( (string) $row['options'], true ) ?: array();
		if ( ! empty( $options['site_key'] ) ) {
			$options['site_key'] = $opts['secrets'] ? array( 'plain' => sap_decrypt( $options['site_key'] ) ) : '';
		}
		foreach ( array_keys( (array) ( $options['members'] ?? array() ) ) as $uid ) {
			$users[ (int) $uid ] = true;
		}
		$row['options'] = $options;
		$item           = array( 'project' => $row, 'tables' => array(), 'options' => array() );
		foreach ( array_keys( sap_transfer_tables() ) as $t ) {
			$sql = 'SELECT * FROM ' . sap_table( $t ) . ' WHERE project_id = %d ORDER BY id ASC';
			if ( 'datasets' === $t && ! $opts['raw'] ) {
				// Without raw data the destination re-fetches (GSC, crawl, inventory) on its first analysis.
				$sql = 'SELECT * FROM ' . sap_table( $t ) . " WHERE project_id = %d AND source NOT IN ('gsc_qp','gsc_pages','gsc_sitemaps','gsc_inspect','crawl','site_inventory','site_inventory_rows','cwv','ss_rank','ss_compare','ss_volumes','ss_questions') ORDER BY id ASC";
			}
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $pid ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $r ) {
				foreach ( array( 'assignee', 'user_id' ) as $uc ) {
					if ( ! empty( $r[ $uc ] ) ) {
						$users[ (int) $r[ $uc ] ] = true;
					}
				}
			}
			$item['tables'][ $t ] = $rows;
		}
		foreach ( sap_transfer_options() as $o ) {
			$v = get_option( $o . $pid, null );
			if ( null !== $v ) {
				$item['options'][ $o ] = $v;
			}
		}
		$out['projects'][] = $item;
	}
	foreach ( array_keys( $users ) as $uid ) {
		$u = get_userdata( $uid );
		if ( ! $u ) {
			continue;
		}
		$out['users'][ $uid ] = array(
			'email'        => $u->user_email,
			'login'        => $u->user_login,
			'name'         => $u->display_name,
			'phone'        => (string) get_user_meta( $uid, 'sap_phone', true ),
			'title'        => (string) get_user_meta( $uid, 'sap_title', true ),
			'notify_sms'   => '0' !== (string) get_user_meta( $uid, 'sap_notify_sms', true ),
			'notify_email' => '0' !== (string) get_user_meta( $uid, 'sap_notify_email', true ),
			'manager'      => user_can( $u, 'manage_options' ),
			'full'         => function_exists( 'sap_member_full' ) && sap_member_full( $u ),
		);
	}
	if ( $opts['settings'] ) {
		$out['settings'] = sap_settings(); // Decrypted; encrypted again on import.
	}
	return $out;
}

/**
 * A quick look at an export file before importing.
 */
function sap_transfer_summary( $data ) {
	$list = array();
	foreach ( (array) ( $data['projects'] ?? array() ) as $it ) {
		$n = array();
		foreach ( (array) $it['tables'] as $t => $rows ) {
			$n[ $t ] = count( (array) $rows );
		}
		$list[] = array( 'name' => (string) ( $it['project']['name'] ?? '' ), 'site' => (string) ( $it['project']['site_url'] ?? '' ), 'counts' => $n );
	}
	return $list;
}

/**
 * Decode an export file (JSON or gzip JSON).
 *
 * @return array|WP_Error
 */
function sap_transfer_read( $path ) {
	$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( "\x1f\x8b" === substr( $raw, 0, 2 ) ) {
		$raw = (string) gzdecode( $raw );
	}
	$data = json_decode( $raw, true );
	unset( $raw );
	if ( ! is_array( $data ) || SAP_TRANSFER_FORMAT !== ( $data['format'] ?? '' ) || empty( $data['projects'] ) ) {
		return new WP_Error( 'sap_transfer', 'این فایل خروجی پروژه سکان نیست یا ناقص آپلود شده.' );
	}
	return $data;
}

/**
 * Destination user for each source user: same email → that user; the source
 * manager → you; otherwise a new team member (no invite is sent) or nobody.
 */
function sap_transfer_users( $users, $create ) {
	$map = array();
	foreach ( (array) $users as $old => $u ) {
		$email = sanitize_email( (string) ( $u['email'] ?? '' ) );
		$found = $email ? get_user_by( 'email', $email ) : null;
		if ( $found ) {
			$map[ (int) $old ] = (int) $found->ID;
			if ( ! user_can( $found, SAP_CAP_WORK ) && ! user_can( $found, 'manage_options' ) ) {
				$found->add_role( 'sap_member' );
			}
			continue;
		}
		if ( ! empty( $u['manager'] ) ) {
			$map[ (int) $old ] = get_current_user_id();
			continue;
		}
		if ( ! $create || ! is_email( $email ) ) {
			$map[ (int) $old ] = 0;
			continue;
		}
		$login = sanitize_user( (string) ( $u['login'] ?? '' ), true ) ?: ( sanitize_user( strtok( $email, '@' ), true ) ?: 'member' );
		while ( username_exists( $login ) ) {
			$login .= wp_rand( 1, 9 );
		}
		$uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 20 ), 'display_name' => sanitize_text_field( (string) ( $u['name'] ?? $login ) ), 'role' => 'sap_member' ) );
		if ( is_wp_error( $uid ) ) {
			$map[ (int) $old ] = 0;
			continue;
		}
		sap_member_update( $uid, array( 'phone' => $u['phone'] ?? '', 'title' => $u['title'] ?? '', 'notify_sms' => ! empty( $u['notify_sms'] ), 'notify_email' => ! empty( $u['notify_email'] ) ) );
		if ( ! empty( $u['full'] ) ) {
			get_userdata( $uid )->add_cap( SAP_CAP );
		}
		$map[ (int) $old ] = (int) $uid;
	}
	return $map;
}

/**
 * Columns of a table on this install (so files from older/newer versions import).
 */
function sap_transfer_columns( $table ) {
	global $wpdb;
	static $cache = array();
	if ( ! isset( $cache[ $table ] ) ) {
		$cache[ $table ] = (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . sap_table( $table ) ); // phpcs:ignore
	}
	return $cache[ $table ];
}

/**
 * Insert one row, return its new id.
 */
function sap_transfer_insert( $table, $row ) {
	global $wpdb;
	$cols = array_flip( sap_transfer_columns( $table ) );
	$row  = array_intersect_key( $row, $cols );
	unset( $row['id'] );
	foreach ( $row as $k => $v ) {
		if ( is_array( $v ) || is_object( $v ) ) {
			$row[ $k ] = wp_json_encode( $v );
		}
	}
	$wpdb->insert( sap_table( $table ), $row );
	return (int) $wpdb->insert_id;
}

/**
 * Import every project of an export.
 *
 * @param array $data  Decoded export.
 * @param array $opts  create_users (bool), settings (bool: apply the global settings in the file).
 * @return array[] {id, name, counts}
 */
function sap_transfer_import( $data, $opts = array() ) {
	global $wpdb;
	$opts = wp_parse_args( $opts, array( 'create_users' => true, 'settings' => false ) );
	wp_raise_memory_limit( 'admin' );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	$users = sap_transfer_users( $data['users'] ?? array(), $opts['create_users'] );
	$done  = array();
	foreach ( (array) $data['projects'] as $it ) {
		$p       = (array) $it['project'];
		$options = (array) ( $p['options'] ?? array() );
		if ( isset( $options['site_key']['plain'] ) ) {
			$options['site_key'] = '' !== $options['site_key']['plain'] ? sap_encrypt( $options['site_key']['plain'] ) : '';
		} elseif ( isset( $options['site_key'] ) && ! is_string( $options['site_key'] ) ) {
			$options['site_key'] = '';
		}
		$members = array();
		foreach ( (array) ( $options['members'] ?? array() ) as $old => $role ) {
			if ( ! empty( $users[ (int) $old ] ) ) {
				$members[ $users[ (int) $old ] ] = $role;
			}
		}
		$options['members'] = $members;
		// A key already used by another project here (importing twice) gets a fresh one.
		$key = (string) ( $p['api_key'] ?? '' );
		if ( '' === $key || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . sap_table( 'projects' ) . ' WHERE api_key = %s', $key ) ) ) { // phpcs:ignore
			$key = sap_token( 32 );
		}
		$p['api_key']    = $key;
		$p['options']    = wp_json_encode( $options );
		$p['updated_at'] = sap_now();
		$pid             = sap_transfer_insert( 'projects', $p );
		if ( ! $pid ) {
			continue;
		}
		unset( $GLOBALS['sap_project_cache'] );
		$map    = array( 'users' => $users );
		$counts = array();
		$later  = array(); // findings.task_id once tasks exist.
		foreach ( sap_transfer_tables() as $t => $refs ) {
			$map[ $t ] = array();
			$counts[ $t ] = 0;
			foreach ( (array) ( $it['tables'][ $t ] ?? array() ) as $row ) {
				$old               = (int) ( $row['id'] ?? 0 );
				$row['project_id'] = $pid;
				foreach ( $refs as $col => $target ) {
					if ( ! empty( $row[ $col ] ) ) {
						$row[ $col ] = (int) ( $map[ $target ][ (int) $row[ $col ] ] ?? 0 );
					}
				}
				if ( 'findings' === $t ) {
					$task_ref       = (int) ( $row['task_id'] ?? 0 );
					$row['task_id'] = 0;
				}
				if ( 'comments' === $t ) {
					$types = array( 'task' => 'tasks', 'finding' => 'findings', 'idea' => 'ideas', 'revision' => 'revisions', 'update' => 'updates' );
					$type  = (string) ( $row['object_type'] ?? '' );
					if ( isset( $types[ $type ] ) ) {
						$row['object_id'] = (int) ( $map[ $types[ $type ] ][ (int) $row['object_id'] ] ?? 0 );
						if ( ! $row['object_id'] ) {
							continue;
						}
					}
				}
				$new = sap_transfer_insert( $t, $row );
				if ( $new ) {
					$map[ $t ][ $old ] = $new;
					++$counts[ $t ];
					if ( 'findings' === $t && $task_ref ) {
						$later[ $new ] = $task_ref;
					}
				}
			}
		}
		foreach ( $later as $fid => $old_task ) {
			if ( ! empty( $map['tasks'][ $old_task ] ) ) {
				$wpdb->update( sap_table( 'findings' ), array( 'task_id' => $map['tasks'][ $old_task ] ), array( 'id' => $fid ) );
			}
		}
		foreach ( (array) ( $it['options'] ?? array() ) as $o => $v ) {
			if ( in_array( $o, sap_transfer_options(), true ) ) {
				update_option( $o . $pid, $v, false );
			}
		}
		$done[] = array( 'id' => $pid, 'name' => (string) $p['name'], 'counts' => $counts );
	}
	if ( $opts['settings'] && ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
		$keep = array( 'google_refresh_token', 'google_account_email', 'google_scopes' ); // Google OAuth is bound to the redirect URL of each site.
		sap_update_settings( array_diff_key( $data['settings'], array_flip( $keep ) ) );
	}
	unset( $GLOBALS['sap_project_cache'] );
	return $done;
}

/**
 * Folder for uploads in progress (not web-readable).
 */
function sap_transfer_dir() {
	$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'sokan-transfer';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', '<?php // Silence.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	// Leftovers of abandoned uploads.
	foreach ( (array) glob( $dir . '/*.part' ) as $f ) {
		if ( filemtime( $f ) < time() - DAY_IN_SECONDS ) {
			wp_delete_file( $f );
		}
	}
	return $dir;
}

/**
 * Chunked upload (works under small upload_max_filesize limits) + import.
 */
add_action( 'rest_api_init', function () {
	$ns = 'seoactionplan/v1';
	register_rest_route( $ns, '/transfer/chunk', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$id    = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $req->get_param( 'upload' ) ) );
			$index = absint( $req->get_param( 'index' ) );
			$files = $req->get_file_params();
			if ( strlen( $id ) < 12 || empty( $files['chunk']['tmp_name'] ) || ! is_uploaded_file( $files['chunk']['tmp_name'] ) ) {
				return new WP_Error( 'sap_transfer', 'بخش فایل نرسید (حجم هر بخش از محدودیت هاست بیشتر است؟).', array( 'status' => 400 ) );
			}
			$path = sap_transfer_dir() . '/' . $id . '.part';
			if ( 0 === $index && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			if ( $index > 0 && ( ! file_exists( $path ) || (int) get_transient( 'sap_tx_' . $id ) !== $index ) ) {
				return new WP_Error( 'sap_transfer', 'ترتیب بخش‌های فایل به هم خورد؛ دوباره آپلود کنید.', array( 'status' => 409 ) );
			}
			file_put_contents( $path, file_get_contents( $files['chunk']['tmp_name'] ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			set_transient( 'sap_tx_' . $id, $index + 1, DAY_IN_SECONDS );
			return array( 'ok' => true, 'size' => filesize( $path ) );
		},
	) );
	register_rest_route( $ns, '/transfer/import', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$id   = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $req->get_param( 'upload' ) ) );
			$path = sap_transfer_dir() . '/' . $id . '.part';
			if ( strlen( $id ) < 12 || ! file_exists( $path ) ) {
				return new WP_Error( 'sap_transfer', 'فایل آپلودشده پیدا نشد.', array( 'status' => 404 ) );
			}
			$data = sap_transfer_read( $path );
			if ( is_wp_error( $data ) ) {
				wp_delete_file( $path );
				return $data;
			}
			if ( 'preview' === $req->get_param( 'step' ) ) {
				return array( 'ok' => true, 'source' => $data['source'] ?? '', 'version' => $data['plugin'] ?? '', 'at' => $data['exported_at'] ?? '', 'projects' => sap_transfer_summary( $data ), 'users' => count( (array) ( $data['users'] ?? array() ) ), 'has_settings' => ! empty( $data['settings'] ) );
			}
			$done = sap_transfer_import( $data, array( 'create_users' => (bool) $req->get_param( 'create_users' ), 'settings' => (bool) $req->get_param( 'settings' ) ) );
			wp_delete_file( $path );
			delete_transient( 'sap_tx_' . $id );
			return array(
				'ok'       => true,
				'projects' => array_map( function ( $d ) {
					return array( 'name' => $d['name'], 'url' => sap_project_url( $d['id'], 'overview' ), 'tasks' => $d['counts']['tasks'] ?? 0, 'findings' => $d['counts']['findings'] ?? 0, 'activity' => $d['counts']['activity'] ?? 0 );
				}, $done ),
				'msg'      => sprintf( '%s پروژه وارد شد.', sap_num( count( $done ) ) ),
			);
		},
	) );
} );

/**
 * Download the export file.
 */
add_action( 'admin_post_sap_transfer_export', function () {
	sap_guard( 'sap_transfer_export' );
	$ids = array_filter( array_map( 'absint', (array) sap_post( 'projects', array() ) ) );
	if ( ! $ids ) {
		sap_back( sap_url( 'settings' ) . '#transfer', 'حداقل یک پروژه را انتخاب کنید.', false );
	}
	wp_raise_memory_limit( 'admin' );
	$data = sap_transfer_export( $ids, array( 'secrets' => (bool) sap_post( 'secrets' ), 'settings' => (bool) sap_post( 'settings' ), 'raw' => (bool) sap_post( 'raw' ) ) );
	$gz   = gzencode( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 6 );
	unset( $data );
	$name = 'sokan-' . sanitize_title( sap_clean_domain( home_url() ) ) . '-' . gmdate( 'Ymd-Hi' ) . '.sokan.gz';
	nocache_headers();
	header( 'Content-Type: application/gzip' );
	header( 'Content-Disposition: attachment; filename="' . $name . '"' );
	header( 'Content-Length: ' . strlen( $gz ) );
	echo $gz; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
} );
