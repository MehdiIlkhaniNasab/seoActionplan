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
		if ( ! empty( $data['password'] ) ) {
			wp_set_password( (string) $data['password'], $uid );
		}
	}
	sap_member_update( $uid, $data );
	// Existing WordPress users already have a password: they get the panel address only.
	$GLOBALS['sap_last_invite'] = sap_member_invite( $uid, ! $existing && empty( $data['password'] ) );
	return $uid;
}

/**
 * A one-time link to set the password (valid about a day; a new one cancels the old).
 */
function sap_member_password_link( $user_id ) {
	$u   = get_userdata( $user_id );
	$key = $u ? get_password_reset_key( $u ) : new WP_Error( 'sap_user', '' );
	return is_wp_error( $key ) ? wp_lostpassword_url( sap_url( 'my' ) ) : network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $u->user_login ), 'login' );
}

/**
 * Invite a member: email with the panel address, the username and a link to
 * set the password; SMS with the panel address when a mobile is known.
 *
 * @return array {email: bool, sms: bool|null, link}
 */
function sap_member_invite( $user_id, $with_password_link = true ) {
	$u = get_userdata( $user_id );
	if ( ! $u ) {
		return array( 'email' => false, 'sms' => null, 'link' => '' );
	}
	$m     = sap_member( $user_id );
	$panel = sap_url( 'my' );
	$link  = $with_password_link ? sap_member_password_link( $user_id ) : '';
	$mail  = sap_notify_email( $u->user_email, '🎉 ' . $m['name'] . ' عزیز، به تیم سئو ' . get_bloginfo( 'name' ) . ' خوش آمدید', array(
		'title'  => 'به تیم سئو خوش آمدید 🎉',
		'badge'  => 'دعوت به تیم',
		'tone'   => '#7C3AED',
		'preheader' => $link ? 'رمز عبورتان را تعیین کنید و وارد پنل کارهای خودتان شوید.' : 'از همین حالا کارهایتان در پنل «کارهای من» است.',
		'intro'  => sprintf( "%s عزیز، شما به تیم سئو در سکان اضافه شدید. کارهایی که به شما سپرده می‌شود در پنل «کارهای من» می‌آید و یادآوری آن‌ها برایتان ارسال می‌شود.", $m['name'] ),
		'rows'   => array( 'آدرس پنل شما' => $panel, 'نام کاربری' => $u->user_login ),
		'items'  => $link ? array( '۱) با دکمه زیر رمز عبور خود را تعیین کنید (لینک حدود یک روز معتبر است).', '۲) بعد از آن همیشه از «آدرس پنل شما» وارد شوید.' ) : array(),
		'button' => $link ? array( 'تعیین رمز عبور', $link ) : array( 'ورود به پنل', $panel ),
	) );
	$sms = null;
	if ( '' !== $m['phone'] && 'none' !== sap_setting( 'sms_provider', 'none' ) ) {
		$sms = true === sap_sms_send( $m['phone'], sprintf( "%s عزیز، به تیم سئو اضافه شدید.\nپنل کارها: %s\nنام کاربری: %s", $m['name'], $panel, $u->user_login ), 'invite', array( sap_first_name( $m['name'] ), $u->user_login ) );
	}
	return array( 'email' => $mail, 'sms' => $sms, 'link' => $link );
}

/*
 * Members live in the Sokan panel: after any login they land on «my tasks»,
 * and wp-admin (except their profile) sends them back there.
 */
function sap_is_member_only( $user ) {
	if ( ! $user instanceof WP_User ) {
		$user = get_userdata( is_object( $user ) ? (int) ( $user->ID ?? 0 ) : (int) $user );
	}
	return $user instanceof WP_User && $user->exists() && user_can( $user, SAP_CAP_WORK ) && ! user_can( $user, 'manage_options' ) && ! user_can( $user, 'edit_posts' );
}

add_filter( 'login_redirect', function ( $to, $requested, $user ) {
	return sap_is_member_only( $user ) && ( '' === (string) $requested || false !== strpos( (string) $requested, '/wp-admin' ) ) ? sap_url( sap_member_full( $user ) ? '' : 'my' ) : $to;
}, 20, 3 );

/**
 * A team member the manager gave full access (projects, plans, settings) to.
 */
function sap_member_full( $user ) {
	$user = $user instanceof WP_User ? $user : get_userdata( is_object( $user ) ? (int) ( $user->ID ?? 0 ) : (int) $user );
	return $user instanceof WP_User && ! user_can( $user, 'manage_options' ) && ! empty( $user->caps[ SAP_CAP ] );
}

/**
 * Grant or take back full access (only administrators — the SEO manager — may).
 */
function sap_member_set_full( $user_id, $on ) {
	$u = get_userdata( (int) $user_id );
	if ( ! $u || user_can( $u, 'manage_options' ) || (int) $user_id === get_current_user_id() || ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	if ( $on ) {
		$u->add_cap( SAP_CAP );
	} else {
		$u->remove_cap( SAP_CAP );
	}
	return true;
}

add_action( 'admin_init', function () {
	global $pagenow;
	if ( wp_doing_ajax() || wp_doing_cron() || in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php', 'profile.php', 'async-upload.php' ), true ) ) {
		return;
	}
	if ( sap_is_member_only( wp_get_current_user() ) ) {
		wp_safe_redirect( sap_url( 'my' ) );
		exit;
	}
} );

// WooCommerce sends non-editors to «My account»; members are handled above (panel, profile allowed).
add_filter( 'woocommerce_prevent_admin_access', function ( $prevent ) {
	return sap_is_member_only( wp_get_current_user() ) ? false : $prevent;
} );

add_filter( 'show_admin_bar', function ( $show ) {
	return sap_is_member_only( wp_get_current_user() ) ? false : $show;
} );

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
	// Assigned (or re-assigned) to someone else: queued, so a burst of assignments becomes one message.
	if ( $after['assignee'] && ( ! $before || (int) $before['assignee'] !== $after['assignee'] ) && $after['assignee'] !== $me && 'done' !== $after['status'] ) {
		sap_assign_queue_add( $after['assignee'], $after['id'] );
	}
	// Done: thank the person and tell the manager.
	if ( 'done' === $after['status'] && ( ! $before || 'done' !== $before['status'] ) && $after['assignee'] ) {
		$m = sap_member( $after['assignee'] );
		if ( $m ) {
			// One motivating email per finished task; the thank-you SMS goes once a night (sap_done_digest).
			if ( $m['notify_email'] ) {
				$today_n = sap_member_done_count( $m['id'], current_time( 'Y-m-d' ) );
				list( $mf, $mt ) = sap_period_range( sap_period_current() );
				$month_n = sap_member_done_count( $m['id'], $mf, $mt );
				$p       = sap_project( $after['project_id'] );
				$cheers  = array( 'عالی پیش می‌روی!', 'یک قدم دیگر به هدف نزدیک‌تر شدیم.', 'کار تمیز و دقیق، دمت گرم!', 'همین پیوستگی است که نتیجه می‌سازد.', 'با هر کار، سایت یک پله بالاتر می‌رود.' );
				sap_notify_email( $m['email'], sprintf( '🌿 دمت گرم %s! «%s» انجام شد', sap_first_name( $m['name'] ), mb_substr( $after['title'], 0, 60 ) ), array(
					'title'     => 'خسته نباشید ' . sap_first_name( $m['name'] ) . ' 🌿',
					'badge'     => '✅ کار انجام شد · ' . ( $p['name'] ?? '' ),
					'tone'      => '#16A34A',
					'preheader' => $cheers[ $today_n % count( $cheers ) ] . ' امروز ' . sap_num( $today_n ) . ' کار انجام دادی.',
					'intro'     => $m['name'] . ' عزیز، ' . $cheers[ $today_n % count( $cheers ) ] . "\nکار «" . $after['title'] . '» انجام شد و در لاگ اقدامات و گزارش ماهانه پروژه ثبت شد. اثرش روی رتبه و ترافیک هم در سرچ کنسول سنجیده می‌شود.',
					'rows'      => array_filter( array(
						'کارهای امروز'   => sap_num( $today_n ) . ' کار',
						'کارهای این ماه' => sap_num( $month_n ) . ' کار',
						'گزارش شما'      => '' !== (string) $after['result'] ? mb_substr( (string) $after['result'], 0, 300 ) : '',
					) ),
					'note'      => 'شب‌ها ساعت ' . sap_num( (int) sap_setting( 'done_sms_hour', 21 ) ) . ' خلاصه کارهای انجام‌شده همان روز با یک پیامک برایت ارسال می‌شود.',
					'button'    => array( 'کارهای بعدی من', sap_url( 'my' ) ),
				) );
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
 * Minutes of quiet after the last assignment before the person is told (0 = at once).
 */
function sap_assign_wait() {
	return max( 0, min( 30, (int) sap_setting( 'notify_batch_minutes', 3 ) ) );
}

/**
 * Queue an assignment notice. The person hears once the manager has stopped
 * assigning for a few minutes (or after 30 minutes at most): one task → the full
 * email and SMS of that task; several → one list email and one summary SMS.
 */
function sap_assign_queue_add( $user_id, $task_id ) {
	$q   = (array) get_option( 'sap_assign_queue', array() );
	$e   = $q[ $user_id ] ?? array( 'tasks' => array(), 'first' => time() );
	$e['tasks'][] = (int) $task_id;
	$e['tasks']   = array_values( array_unique( $e['tasks'] ) );
	$e['last']    = time();
	$q[ $user_id ] = $e;
	update_option( 'sap_assign_queue', $q, false );
	if ( ! sap_assign_wait() ) {
		sap_assign_flush( true, (int) $user_id );
		return;
	}
	sap_assign_schedule( $q );
}

/**
 * When a queue entry is due.
 */
function sap_assign_due_at( $e ) {
	return min( (int) $e['last'] + sap_assign_wait() * MINUTE_IN_SECONDS, (int) $e['first'] + 30 * MINUTE_IN_SECONDS );
}

/**
 * (Re)schedule the flush for the earliest due entry.
 */
function sap_assign_schedule( $q = null ) {
	$q = null === $q ? (array) get_option( 'sap_assign_queue', array() ) : $q;
	wp_clear_scheduled_hook( 'sap_assign_flush' );
	if ( $q ) {
		wp_schedule_single_event( max( time() + 30, min( array_map( 'sap_assign_due_at', $q ) ) ), 'sap_assign_flush' );
	}
}

/**
 * Send the due (or, forced, all / one person's) queued assignment notices.
 *
 * @return int people notified
 */
function sap_assign_flush( $force = false, $only = 0 ) {
	$q = (array) get_option( 'sap_assign_queue', array() );
	$n = 0;
	foreach ( $q as $uid => $e ) {
		if ( ( $only && (int) $uid !== $only ) || ( ! $force && time() < sap_assign_due_at( $e ) ) ) {
			continue;
		}
		unset( $q[ $uid ] );
		update_option( 'sap_assign_queue', $q, false ); // Before sending: a slow gateway must not cause doubles.
		$tasks = array_values( array_filter( array_map( 'sap_task', $e['tasks'] ), function ( $t ) use ( $uid ) {
			return $t && (int) $t['assignee'] === (int) $uid && 'done' !== $t['status'];
		} ) );
		if ( $tasks && sap_assign_notify( (int) $uid, $tasks ) ) {
			++$n;
		}
	}
	sap_assign_schedule( $q );
	return $n;
}
add_action( 'sap_assign_flush', function () {
	sap_assign_flush();
} );

/**
 * Email + SMS for one or more newly assigned tasks.
 */
function sap_assign_notify( $user_id, $tasks ) {
	$m = sap_member( $user_id );
	if ( ! $m ) {
		return false;
	}
	$one   = 1 === count( $tasks );
	$first = $tasks[0];
	$p     = sap_project( $first['project_id'] );
	$pn    = implode( '، ', array_unique( array_map( function ( $t ) {
		return sap_project( $t['project_id'] )['name'] ?? '';
	}, $tasks ) ) );
	if ( $m['notify_email'] ) {
		if ( $one ) {
			sap_notify_email( $m['email'], '📌 کار تازه برای شما: ' . mb_substr( $first['title'], 0, 70 ) . ( $first['due_date'] ? ' (تا ' . sap_jdate( $first['due_date'], 'short' ) . ')' : '' ), array(
				'title'  => $first['title'],
				'badge'  => '📌 کار جدید · ' . ( $p['name'] ?? '' ),
				'preheader' => $m['name'] . ' عزیز، این کار به شما سپرده شد' . ( $first['due_date'] ? '؛ سررسید ' . sap_jdate( $first['due_date'], 'short' ) : '' ) . '.',
				'intro'  => $m['name'] . ' عزیز، سلام! کار بالا در سکان به شما سپرده شد. جزئیات و قدم‌های انجامش اینجاست:',
				'rows'   => sap_task_brief_line( $first ),
				'items'  => sap_email_note_items( $first['notes'] ),
				'button' => array( 'مشاهده در «کارهای من»', sap_url( 'my' ) . '#task-' . $first['id'] ),
			) );
		} else {
			sap_notify_email( $m['email'], sprintf( '📋 %s کار تازه در سکان منتظر شماست — %s', sap_num( count( $tasks ) ), $pn ), array(
				'title'  => sprintf( '%s کار تازه برای شما', sap_num( count( $tasks ) ) ),
				'badge'  => '📋 ' . $pn,
				'preheader' => 'از جمله: ' . mb_substr( $first['title'], 0, 80 ),
				'intro'  => $m['name'] . ' عزیز، این کارها در سکان به شما سپرده شد (به ترتیب ثبت):',
				'items'  => array_map( function ( $t ) {
					$tp = sap_project( $t['project_id'] );
					return '<b>' . esc_html( $t['title'] ) . '</b><br><small>' . esc_html( ( $tp['name'] ?? '' ) . ' · ' . sap_num( $t['effort'], 1 ) . ' ساعت' . ( $t['due_date'] ? ' · سررسید ' . sap_jdate( $t['due_date'], 'short' ) : '' ) ) . '</small>';
				}, array_slice( $tasks, 0, 25 ) ),
				'button' => array( 'باز کردن «کارهای من»', sap_url( 'my' ) ),
			) );
		}
	}
	if ( $m['notify_sms'] && '' !== $m['phone'] ) {
		if ( $one ) {
			// Always a date (the support of the SMS line approves «تاریخ سررسید» only with a date): end of the plan month when none is set.
			$due = sap_jdate( $first['due_date'] ?: sap_period_range( $first['period'] ?: sap_period_current() )[1], 'short' );
			sap_sms_send( $m['phone'], sprintf( "سکان | کار جدید (%s):\n%s\nسررسید: %s", $p['name'] ?? '', mb_substr( $first['title'], 0, 90 ), $due ), 'task', array( sap_first_name( $m['name'] ), $due ) );
		} else {
			$lines = array();
			foreach ( array_slice( $tasks, 0, 3 ) as $i => $t ) {
				$lines[] = sap_num( $i + 1 ) . ') ' . mb_substr( $t['title'], 0, 50 );
			}
			if ( count( $tasks ) > 3 ) {
				$lines[] = '… و ' . sap_num( count( $tasks ) - 3 ) . ' کار دیگر';
			}
			$text  = sprintf( "سکان | %s عزیز، %s کار جدید برای شما (%s):\n%s", $m['name'], sap_num( count( $tasks ) ), $pn, implode( "\n", $lines ) );
			sap_sms_send( $m['phone'], $text, 'tasks', array( sap_first_name( $m['name'] ), sap_num( count( $tasks ) ) ) );
		}
	}
	return true;
}

/**
 * Task notes → checklist lines for an email (headings and own numbering removed).
 */
function sap_email_note_items( $notes, $max = 12 ) {
	$out = array();
	foreach ( explode( "\n", (string) $notes ) as $l ) {
		$l = trim( $l );
		if ( '' === $l || preg_match( '/[:：]$/u', $l ) ) {
			continue;
		}
		$l = preg_replace( '/^(?:[-•*]|[0-9۰-۹]+[.)\-])\s*/u', '', $l );
		if ( '' !== $l ) {
			// Addresses stay left-to-right inside the Persian line.
			$out[] = preg_replace( '#(https?://\S+|/[A-Za-z0-9\-_./%]{3,})#u', '&#8206;<span style="direction:ltr;unicode-bidi:isolate;color:#2563EB">$1</span>&#8206;', esc_html( rawurldecode( $l ) ) );
		}
	}
	return array_slice( $out, 0, $max );
}

/**
 * People waiting for an assignment notice: user id => [count, due timestamp].
 */
function sap_assign_pending() {
	$out = array();
	foreach ( (array) get_option( 'sap_assign_queue', array() ) as $uid => $e ) {
		$out[ $uid ] = array( count( $e['tasks'] ), sap_assign_due_at( $e ) );
	}
	return $out;
}

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
			sap_sms_send( $m['phone'], sprintf( "سکان | %s %s\n%s کار باز%s:\n%s", $morning ? 'صبح بخیر' : 'یادآوری', $m['name'], sap_num( count( $tasks ) ), $overdue ? '، ' . sap_num( $overdue ) . ' عقب از موعد' : '', implode( "\n", $lines ) ), 'remind', array( sap_first_name( $m['name'] ), sap_num( count( $tasks ) ), sap_num( $overdue ) ) );
		}
		if ( $morning && sap_setting( 'reminder_email', 1 ) && $m['notify_email'] ) {
			sap_notify_email( $m['email'], sprintf( '☀️ صبح بخیر %s — %s کار امروز%s', $m['name'], sap_num( count( $tasks ) ), $overdue ? '، ' . sap_num( $overdue ) . ' عقب از موعد' : '' ), array(
				'title'  => 'صبح بخیر ' . $m['name'] . ' ☀️',
				'badge'  => sprintf( '%s کار باز%s', sap_num( count( $tasks ) ), $overdue ? ' · ' . sap_num( $overdue ) . ' عقب افتاده' : '' ),
				'tone'   => $overdue ? '#EA580C' : '#2563EB',
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

/**
 * Tasks a person finished between two dates (local Y-m-d, inclusive).
 */
function sap_member_done_count( $user_id, $from, $to = '' ) {
	global $wpdb;
	$to = '' !== $to ? $to : $from;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . sap_table( 'tasks' ) . " WHERE assignee = %d AND status = 'done' AND done_at >= %s AND done_at <= %s", (int) $user_id, $from . ' 00:00:00', $to . ' 23:59:59' ) ); // phpcs:ignore
}

/**
 * Nightly thank-you SMS: one message to everyone who finished tasks today.
 *
 * @return int people messaged
 */
function sap_done_digest( $force = false ) {
	$today = current_time( 'Y-m-d' );
	if ( ! $force && get_option( 'sap_done_digest_day' ) === $today ) {
		return 0;
	}
	update_option( 'sap_done_digest_day', $today, false );
	if ( 'none' === sap_setting( 'sms_provider', 'none' ) ) {
		return 0;
	}
	$n = 0;
	foreach ( sap_team_users() as $u ) {
		$m = sap_member( $u->ID );
		if ( ! $m || ! $m['notify_sms'] || '' === $m['phone'] ) {
			continue;
		}
		$c = sap_member_done_count( $u->ID, $today );
		if ( ! $c ) {
			continue;
		}
		$first = sap_first_name( $m['name'] );
		$text  = sprintf( "سکان | %s عزیز، عالی بودی\nامروز %s کار را با موفقیت انجام دادی و همه در گزارش ثبت شد.\nدمت گرم، شب خوبی داشته باشی.", $first, sap_num( $c ) );
		if ( true === sap_sms_send( $m['phone'], $text, 'done', array( $first, sap_num( $c ) ) ) ) {
			++$n;
		}
	}
	return $n;
}

add_action( 'sap_hourly', function () {
	$h = (int) sap_setting( 'done_sms_hour', 21 );
	if ( $h > 0 && (int) current_time( 'G' ) >= $h ) {
		sap_done_digest();
	}
} );

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
