<?php
/**
 * Team: members with a profile (email, mobile, title), their role in each
 * project, notifications when a task is given to them (email + SMS) and a
 * thank-you when it is done, and reminders every working day (default 08:00
 * and 14:00) of the open tasks of all their projects.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Roles in a project.
 */
function sap_member_roles() {
	return array(
		'manager' => 'مدیر سئو',
		'tech'    => 'کارشناس فنی / توسعه‌دهنده',
		'content' => 'کارشناس محتوا',
		'writer'  => 'نویسنده',
		'link'    => 'لینک‌ساز / آف‌پیج',
		'design'  => 'طراح / گرافیست',
	);
}

/**
 * A member's profile.
 */
function sap_member( $user_id ) {
	$u = get_userdata( (int) $user_id );
	if ( ! $u ) {
		return null;
	}
	return array(
		'id'           => (int) $u->ID,
		'name'         => $u->display_name,
		'email'        => $u->user_email,
		'phone'        => (string) get_user_meta( $u->ID, 'sap_phone', true ),
		'title'        => (string) get_user_meta( $u->ID, 'sap_title', true ),
		'notify_sms'   => '0' !== (string) get_user_meta( $u->ID, 'sap_notify_sms', true ),
		'notify_email' => '0' !== (string) get_user_meta( $u->ID, 'sap_notify_email', true ),
		'color'        => sap_member_color( $u->ID ),
		'manager'      => user_can( $u, SAP_CAP ) || user_can( $u, 'manage_options' ),
	);
}

/**
 * A stable colour per person / project for labels.
 */
function sap_member_color( $id ) {
	$c = array( '#2563EB', '#16A34A', '#D97706', '#9333EA', '#DB2777', '#0891B2', '#65A30D', '#DC2626', '#4F46E5', '#0D9488' );
	return $c[ abs( (int) $id ) % count( $c ) ];
}

/**
 * Members of a project: user id => role.
 */
function sap_project_members( $project_id ) {
	$p = sap_project( $project_id );
	return $p ? array_map( 'sanitize_key', (array) ( $p['options']['members'] ?? array() ) ) : array();
}

/**
 * Projects a user belongs to (member, or any task given to them).
 */
function sap_user_projects( $user_id ) {
	$out = array();
	foreach ( sap_projects() as $p ) {
		if ( isset( sap_project_members( $p['id'] )[ (int) $user_id ] ) ) {
			$out[ $p['id'] ] = $p;
		}
	}
	return $out;
}

/**
 * Create a team member (WordPress user with the team role) and email them a
 * link to set their password.
 *
 * @return int|WP_Error user id
 */
function sap_member_create( $data ) {
	$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'sap_email', 'ایمیل معتبر نیست.' );
	}
	$existing = get_user_by( 'email', $email );
	if ( $existing ) {
		$uid = (int) $existing->ID;
		if ( ! user_can( $existing, SAP_CAP_WORK ) && ! user_can( $existing, 'manage_options' ) ) {
			$existing->add_role( 'sap_member' );
		}
	} else {
		$login = sanitize_user( strtok( $email, '@' ), true ) ?: 'member';
		while ( username_exists( $login ) ) {
			$login .= wp_rand( 1, 9 );
		}
		$uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 20 ), 'display_name' => sanitize_text_field( (string) ( $data['name'] ?? $login ) ), 'role' => 'sap_member' ) );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$key = get_password_reset_key( get_userdata( $uid ) );
		$set = is_wp_error( $key ) ? wp_lostpassword_url() : network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $login ), 'login' );
		sap_notify_email( $email, 'دعوت به تیم سئو در سکان', array(
			'title'  => 'به تیم سئو خوش آمدید',
			'intro'  => sprintf( "%s عزیز، شما به تیم سئو در سکان اضافه شدید. کارهایی که به شما سپرده می‌شود در پنل «کارهای من» می‌آید و یادآوری آن‌ها برایتان ارسال می‌شود.\nنام کاربری: %s", sanitize_text_field( (string) ( $data['name'] ?? '' ) ), $login ),
			'button' => array( 'تعیین رمز عبور و ورود', $set ),
		) );
	}
	sap_member_update( $uid, $data );
	return $uid;
}

/**
 * Update a member's profile fields.
 */
function sap_member_update( $user_id, $data ) {
	if ( isset( $data['name'] ) && '' !== trim( (string) $data['name'] ) ) {
		wp_update_user( array( 'ID' => (int) $user_id, 'display_name' => sanitize_text_field( (string) $data['name'] ) ) );
	}
	if ( isset( $data['phone'] ) ) {
		update_user_meta( $user_id, 'sap_phone', sap_phone_norm( (string) $data['phone'] ) );
	}
	if ( isset( $data['title'] ) ) {
		update_user_meta( $user_id, 'sap_title', sanitize_text_field( (string) $data['title'] ) );
	}
	foreach ( array( 'notify_sms', 'notify_email' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			update_user_meta( $user_id, 'sap_' . $k, $data[ $k ] ? '1' : '0' );
		}
	}
}

/**
 * Short plain description of a task for messages: project, goal, expected output.
 */
function sap_task_brief_line( $t ) {
	$p     = sap_project( $t['project_id'] );
	$focus = $t['focus_id'] ? ( wp_list_pluck( sap_focuses( $t['project_id'] ), 'name', 'id' )[ $t['focus_id'] ] ?? '' ) : '';
	return array(
		'پروژه'       => $p['name'] ?? '',
		'حوزه'        => sap_context_label( $t['context'] ),
		'هدف'         => $focus ?: '—',
		'خروجی مطلوب' => '' !== $t['outcome'] ? $t['outcome'] : 'طبق توضیحات کار',
		'زمان'        => sap_num( $t['effort'], 1 ) . ' ساعت',
		'سررسید'      => $t['due_date'] ? sap_jdate( $t['due_date'] ) : 'این ماه',
	);
}

/**
 * Notifications on task changes: given to someone → tell them; done by a member → thank them and tell the manager.
 */
add_action( 'sap_task_saved', function ( $before, $after ) {
	if ( ! $after ) {
		return;
	}
	$me = get_current_user_id();
	// Assigned (or re-assigned) to someone else.
	if ( $after['assignee'] && ( ! $before || (int) $before['assignee'] !== $after['assignee'] ) && $after['assignee'] !== $me && 'done' !== $after['status'] ) {
		$m = sap_member( $after['assignee'] );
		if ( $m ) {
			$link = sap_url( 'my' ) . '#task-' . $after['id'];
			if ( $m['notify_email'] ) {
				sap_notify_email( $m['email'], 'کار جدید: ' . $after['title'], array(
					'title'  => 'یک کار جدید به شما سپرده شد',
					'intro'  => $m['name'] . ' عزیز، کار زیر در سکان به شما سپرده شد:' . "\n" . $after['title'],
					'rows'   => sap_task_brief_line( $after ),
					'items'  => array_slice( array_filter( array_map( 'trim', explode( "\n", (string) $after['notes'] ) ) ), 0, 12 ),
					'button' => array( 'مشاهده در «کارهای من»', $link ),
				) );
			}
			if ( $m['notify_sms'] && '' !== $m['phone'] ) {
				$p = sap_project( $after['project_id'] );
				sap_sms_send( $m['phone'], sprintf( "سکان | کار جدید (%s):\n%s\n%s", $p['name'] ?? '', mb_substr( $after['title'], 0, 90 ), $after['due_date'] ? 'سررسید: ' . sap_jdate( $after['due_date'], 'short' ) : '' ) );
			}
		}
	}
	// Done: thank the person and tell the manager.
	if ( 'done' === $after['status'] && ( ! $before || 'done' !== $before['status'] ) && $after['assignee'] ) {
		$m = sap_member( $after['assignee'] );
		if ( $m ) {
			if ( $m['notify_email'] ) {
				sap_notify_email( $m['email'], 'خسته نباشید! «' . $after['title'] . '» انجام شد', array(
					'title' => 'خسته نباشید 🌿',
					'tone'  => '#16A34A',
					'intro' => $m['name'] . ' عزیز، کار «' . $after['title'] . '» انجام شد و در لاگ اقدامات و گزارش ماهانه ثبت شد. اثرش در سرچ کنسول هم سنجیده می‌شود. ممنون از زحمتت!',
				) );
			}
			if ( $m['notify_sms'] && '' !== $m['phone'] && sap_setting( 'reminder_sms', 1 ) ) {
				sap_sms_send( $m['phone'], 'سکان | خسته نباشید ' . $m['name'] . '! «' . mb_substr( $after['title'], 0, 70 ) . '» انجام شد و ثبت شد. 🌿' );
			}
			if ( ! $m['manager'] ) {
				$p = sap_project( $after['project_id'] );
				sap_notify_manager( array( 'tg', 'bale' ), array(
					'subject' => 'کار انجام شد',
					'text'    => sprintf( '✅ %s کار «%s» را در پروژه %s انجام داد.%s', $m['name'], $after['title'], $p['name'] ?? '', '' !== $after['result'] ? "\nگزارش: " . mb_substr( $after['result'], 0, 300 ) : '' ),
				) );
			}
		}
	}
}, 10, 2 );

/**
 * Open tasks of a person across all projects (this month and overdue).
 */
function sap_member_open_tasks( $user_id ) {
	$cur = sap_period_current();
	return array_values( array_filter( sap_tasks( array( 'assignee' => (int) $user_id, 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) ), function ( $t ) use ( $cur ) {
		return '' === $t['period'] || $t['period'] <= $cur;
	} ) );
}

/**
 * Send the reminders of one time slot (SMS to everyone with open tasks; the
 * morning slot also sends the email digest).
 *
 * @return int people reminded
 */
function sap_team_remind( $morning = true ) {
	$n     = 0;
	$today = current_time( 'Y-m-d' );
	foreach ( sap_team_users() as $u ) {
		$m = sap_member( $u->ID );
		if ( ! $m ) {
			continue;
		}
		$tasks = sap_member_open_tasks( $u->ID );
		if ( ! $tasks ) {
			continue;
		}
		usort( $tasks, function ( $a, $b ) {
			$order = array( 'doing' => 0, 'next' => 1, 'inbox' => 2, 'waiting' => 3 );
			return array( $order[ $a['status'] ] ?? 9, (string) ( $a['due_date'] ?: '9999' ) ) <=> array( $order[ $b['status'] ] ?? 9, (string) ( $b['due_date'] ?: '9999' ) );
		} );
		$overdue = count( array_filter( $tasks, function ( $t ) use ( $today ) {
			return $t['due_date'] && $t['due_date'] < $today;
		} ) );
		if ( sap_setting( 'reminder_sms', 1 ) && $m['notify_sms'] && '' !== $m['phone'] ) {
			$lines = array();
			foreach ( array_slice( $tasks, 0, 3 ) as $i => $t ) {
				$p       = sap_project( $t['project_id'] );
				$lines[] = sap_num( $i + 1 ) . ') ' . mb_substr( $t['title'], 0, 55 ) . ' [' . ( $p['name'] ?? '' ) . ']';
			}
			sap_sms_send( $m['phone'], sprintf( "سکان | %s %s\n%s کار باز%s:\n%s", $morning ? 'صبح بخیر' : 'یادآوری', $m['name'], sap_num( count( $tasks ) ), $overdue ? '، ' . sap_num( $overdue ) . ' عقب از موعد' : '', implode( "\n", $lines ) ) );
		}
		if ( $morning && sap_setting( 'reminder_email', 1 ) && $m['notify_email'] ) {
			sap_notify_email( $m['email'], sprintf( 'کارهای امروز شما (%s کار باز)', sap_num( count( $tasks ) ) ), array(
				'title'  => 'صبح بخیر ' . $m['name'],
				'intro'  => 'کارهای باز شما در همه پروژه‌ها، به ترتیب اولویت:',
				'items'  => array_map( function ( $t ) use ( $today ) {
					$p = sap_project( $t['project_id'] );
					return '<b>' . esc_html( $t['title'] ) . '</b><br><small>' . esc_html( ( $p['name'] ?? '' ) . ' · ' . ( sap_task_statuses()[ $t['status'] ] ?? '' ) . ( $t['due_date'] ? ' · سررسید ' . sap_jdate( $t['due_date'], 'short' ) . ( $t['due_date'] < $today ? ' (عقب افتاده)' : '' ) : '' ) ) . '</small>';
				}, array_slice( $tasks, 0, 15 ) ),
				'button' => array( 'باز کردن «کارهای من»', sap_url( 'my' ) ),
			) );
		}
		++$n;
	}
	return $n;
}

add_action( 'sap_hourly', function () {
	$hours = array_map( 'intval', sap_list( (string) sap_setting( 'reminder_hours', '8,14' ) ) );
	$now   = (int) current_time( 'G' );
	$day   = (int) current_time( 'w' ); // 5 = Friday.
	if ( ! $hours || ! in_array( $now, $hours, true ) || 5 === $day ) {
		return;
	}
	$slot = current_time( 'Y-m-d' ) . ':' . $now;
	if ( get_option( 'sap_remind_slot' ) === $slot ) {
		return;
	}
	update_option( 'sap_remind_slot', $slot, false );
	sap_team_remind( $now === min( $hours ) );
} );

/**
 * Work of a person in a date range: done tasks, hours planned / spent.
 */
function sap_member_stats( $user_id, $from, $to ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT effort, spent FROM ' . sap_table( 'tasks' ) . " WHERE assignee = %d AND status = 'done' AND done_at BETWEEN %s AND %s", $user_id, $from . ' 00:00:00', $to . ' 23:59:59' ) ); // phpcs:ignore
	return array(
		'done'   => count( (array) $rows ),
		'hours'  => array_sum( array_map( function ( $r ) {
			return (float) $r->effort;
		}, (array) $rows ) ),
		'spent'  => array_sum( array_map( function ( $r ) {
			return (float) $r->spent;
		}, (array) $rows ) ),
		'open'   => count( sap_member_open_tasks( $user_id ) ),
	);
}
