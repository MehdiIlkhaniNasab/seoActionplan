<?php
/**
 * Team workflow: a member's «done» goes to the manager's desk for review
 * (approve → done, feedback → back to the member), questions to the managers,
 * tasks proposed by project managers waiting for approval, escalation of tasks
 * 7 days past their deadline, and the cheap-model helpers (task brief, step-by-step help).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * Desk
 * ------------------------------------------------------------------------- */

/**
 * Everything waiting for a full-access manager.
 *
 * @return array {review, questions, proposed, late}
 */
function sap_desk_items() {
	global $wpdb;
	$late_day = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ); // phpcs:ignore
	$late     = array_values( array_filter( sap_tasks( array( 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) ), function ( $t ) use ( $late_day ) {
		return $t['assignee'] && $t['due_date'] && $t['due_date'] <= $late_day;
	} ) );
	usort( $late, function ( $a, $b ) {
		return strcmp( $a['due_date'], $b['due_date'] );
	} );
	$review = sap_tasks( array( 'status' => 'review' ) );
	usort( $review, function ( $a, $b ) {
		return strcmp( (string) $a['submitted_at'], (string) $b['submitted_at'] );
	} );
	return array(
		'review'    => $review,
		'questions' => (array) $wpdb->get_results( "SELECT * FROM " . sap_table( 'questions' ) . " WHERE status = 'open' ORDER BY id ASC LIMIT 200", ARRAY_A ), // phpcs:ignore
		'proposed'  => sap_tasks( array( 'status' => 'proposed' ) ),
		'late'      => $late,
	);
}

/**
 * Badge of the desk in the menu.
 */
function sap_desk_count() {
	static $n = null;
	if ( null === $n ) {
		$d = sap_desk_items();
		$n = count( $d['review'] ) + count( $d['questions'] ) + count( $d['proposed'] ) + count( $d['late'] );
	}
	return $n;
}

/**
 * Email every full-access manager.
 */
function sap_notify_full( $subject, $args ) {
	foreach ( sap_full_users() as $u ) {
		$m = sap_member( $u->ID );
		if ( $m && $m['notify_email'] ) {
			sap_notify_email( $u->user_email, $subject, $args );
		}
	}
}

/**
 * Days between a date and today (positive = late).
 */
function sap_days_late( $date ) {
	return (int) floor( ( strtotime( current_time( 'Y-m-d' ) ) - strtotime( (string) $date ) ) / DAY_IN_SECONDS );
}

/* ---------------------------------------------------------------------------
 * Review: submit → approve / feedback
 * ------------------------------------------------------------------------- */

/**
 * A member finishes a task: report, hours and documents go to the managers' desk.
 * A full-access manager's own «done» is final at once.
 *
 * @return array|WP_Error
 */
function sap_task_submit( $task, $result, $spent = null, $attachments = null ) {
	if ( '' === trim( (string) $result ) ) {
		return new WP_Error( 'sap_report', 'گزارش کار را بنویسید: دقیقاً چه کاری انجام شد.' );
	}
	$data = array( 'result' => $result );
	if ( null !== $spent ) {
		$data['spent'] = $spent;
	}
	if ( null !== $attachments ) {
		$data['attachments'] = $attachments;
	}
	if ( sap_can() ) {
		$data['status'] = 'done';
		sap_task_save( $data, $task['id'] );
		return array( 'status' => 'done', 'msg' => 'خسته نباشید! کار ثبت شد و در گزارش می‌آید.' );
	}
	$data['status']       = 'review';
	$data['submitted_at'] = sap_now();
	$data['review_note']  = '';
	sap_task_save( $data, $task['id'] );
	return array( 'status' => 'review', 'msg' => 'گزارش ثبت شد و برای تأیید مدیر ارسال شد. دمت گرم! 🌿' );
}

/**
 * Manager decision on a submitted task.
 *
 * @param string $do approve|feedback.
 * @return array|WP_Error
 */
function sap_task_review( $task, $do, $note = '' ) {
	if ( 'review' !== $task['status'] ) {
		return new WP_Error( 'sap_review', 'این کار در انتظار تأیید نیست.' );
	}
	$m = $task['assignee'] ? sap_member( $task['assignee'] ) : null;
	$p = sap_project( $task['project_id'] );
	if ( 'approve' === $do ) {
		sap_task_save( array( 'status' => 'done', 'review_note' => '' ), $task['id'] );
		return array( 'msg' => 'تأیید شد و در لاگ اقدامات و گزارش ماهانه ثبت شد.' );
	}
	$note = trim( (string) $note );
	if ( '' === $note ) {
		return new WP_Error( 'sap_review', 'بازخورد را بنویسید تا مسئول بداند چه چیزی باید اصلاح شود.' );
	}
	sap_task_save( array( 'status' => 'doing', 'review_note' => $note, 'submitted_at' => null ), $task['id'] );
	sap_comment_add( $task['project_id'], 'task', $task['id'], '🔁 بازخورد مدیر: ' . $note );
	if ( $m && $m['notify_email'] ) {
		sap_notify_email( $m['email'], '🔁 ' . sap_first_name( $m['name'] ) . ' عزیز، کار «' . mb_substr( $task['title'], 0, 60 ) . '» نیاز به اصلاح دارد', array(
			'title'     => 'یک قدم تا تأیید نهایی',
			'badge'     => '🔁 بازخورد مدیر · ' . ( $p['name'] ?? '' ),
			'tone'      => '#EA580C',
			'preheader' => mb_substr( $note, 0, 120 ),
			'intro'     => $m['name'] . ' عزیز، گزارش کار «' . $task['title'] . '» بررسی شد. با اعمال نکات زیر دوباره «انجام شد» را بزنید:',
			'items'     => array_map( 'esc_html', array_filter( array_map( 'trim', explode( "\n", $note ) ) ) ),
			'button'    => array( 'باز کردن کار', sap_url( 'my' ) . '#task-' . $task['id'] ),
		) );
	}
	return array( 'msg' => 'بازخورد برای ' . ( $m['name'] ?? 'مسئول' ) . ' ارسال شد و کار به «در حال انجام» برگشت.' );
}

/* ---------------------------------------------------------------------------
 * Questions to the managers
 * ------------------------------------------------------------------------- */

function sap_question_add( $task, $text ) {
	global $wpdb;
	$text = trim( sanitize_textarea_field( (string) $text ) );
	if ( '' === $text ) {
		return new WP_Error( 'sap_q', 'سوال خالی است.' );
	}
	$wpdb->insert( sap_table( 'questions' ), array(
		'project_id' => (int) $task['project_id'],
		'task_id'    => (int) $task['id'],
		'user_id'    => get_current_user_id(),
		'question'   => $text,
		'status'     => 'open',
		'created_at' => sap_now(),
	) );
	$id = (int) $wpdb->insert_id;
	$me = sap_member( get_current_user_id() );
	$p  = sap_project( $task['project_id'] );
	sap_notify_full( '❓ ' . ( $me['name'] ?? '' ) . ' درباره «' . mb_substr( $task['title'], 0, 50 ) . '» سوال دارد', array(
		'title'     => 'یک سوال منتظر پاسخ شماست',
		'badge'     => '❓ سوال از تیم · ' . ( $p['name'] ?? '' ),
		'tone'      => '#7C3AED',
		'preheader' => mb_substr( $text, 0, 120 ),
		'intro'     => ( $me['name'] ?? 'یکی از اعضا' ) . ' هنگام انجام کار «' . $task['title'] . '» این سوال را پرسیده. پاسخ سریع شما کار را جلو می‌اندازد:',
		'rows'      => array( 'سوال' => $text, 'پروژه' => $p['name'] ?? '', 'سررسید کار' => $task['due_date'] ? sap_jdate( $task['due_date'] ) : '—' ),
		'button'    => array( 'پاسخ در میز کار', sap_url( 'desk' ) . '#q-' . $id ),
	) );
	return $id;
}

function sap_question_answer( $id, $answer ) {
	global $wpdb;
	$q = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'questions' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
	$answer = trim( sanitize_textarea_field( (string) $answer ) );
	if ( ! $q || '' === $answer ) {
		return new WP_Error( 'sap_q', 'پاسخ خالی است.' );
	}
	$wpdb->update( sap_table( 'questions' ), array( 'answer' => $answer, 'answered_by' => get_current_user_id(), 'status' => 'answered', 'answered_at' => sap_now() ), array( 'id' => (int) $id ) );
	$t = sap_task( (int) $q['task_id'] );
	$m = sap_member( (int) $q['user_id'] );
	if ( $m && $m['notify_email'] && $t ) {
		sap_notify_email( $m['email'], '💬 پاسخ سوال شما درباره «' . mb_substr( $t['title'], 0, 50 ) . '»', array(
			'title'  => 'سوال شما پاسخ داده شد',
			'badge'  => '💬 پاسخ مدیر',
			'tone'   => '#7C3AED',
			'intro'  => $m['name'] . ' عزیز، پاسخ سوالتان درباره کار «' . $t['title'] . '»:',
			'rows'   => array( 'سوال شما' => $q['question'], 'پاسخ' => $answer ),
			'button' => array( 'ادامه کار', sap_url( 'my' ) . '#task-' . $t['id'] ),
		) );
	}
	return true;
}

/**
 * Questions of a task (newest last).
 */
function sap_task_questions( $task_id ) {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'questions' ) . ' WHERE task_id = %d ORDER BY id ASC', $task_id ), ARRAY_A ); // phpcs:ignore
}

/* ---------------------------------------------------------------------------
 * Overdue escalation (7 days past the deadline)
 * ------------------------------------------------------------------------- */

/**
 * Tasks 7+ days late: the managers get an email at once, the person an SMS (once per task).
 *
 * @return int tasks escalated
 */
function sap_late_escalate() {
	$n = 0;
	foreach ( sap_desk_items()['late'] as $t ) {
		if ( '' !== $t['late_alert'] ) {
			continue;
		}
		sap_task_save( array( 'late_alert' => sap_now() ), $t['id'] );
		$m    = sap_member( $t['assignee'] );
		$p    = sap_project( $t['project_id'] );
		$days = sap_days_late( $t['due_date'] );
		sap_notify_full( sprintf( '⏰ پیگیری فوری: «%s» از %s %s روز عقب افتاده', mb_substr( $t['title'], 0, 50 ), $m['name'] ?? '', sap_num( $days ) ), array(
			'title'     => 'کاری که نیاز به پیگیری دارد',
			'badge'     => '⏰ ' . sap_num( $days ) . ' روز بعد از سررسید',
			'tone'      => '#DC2626',
			'preheader' => ( $m['name'] ?? '' ) . ' هنوز این کار را انجام نداده است.',
			'intro'     => sprintf( 'کار «%s» که به %s سپرده شده بود، %s روز از تاریخ سررسیدش گذشته و هنوز انجام نشده. به مسئول هم پیامک اطلاع داده شد.', $t['title'], $m['name'] ?? '—', sap_num( $days ) ),
			'rows'      => array( 'مسئول' => $m['name'] ?? '—', 'پروژه' => $p['name'] ?? '', 'سررسید' => sap_jdate( $t['due_date'] ), 'وضعیت' => sap_task_statuses()[ $t['status'] ] ?? $t['status'] ),
			'button'    => array( 'مشاهده در میز کار', sap_url( 'desk' ) . '#late' ),
		) );
		if ( $m && $m['notify_sms'] && '' !== $m['phone'] ) {
			$first = sap_first_name( $m['name'] );
			sap_sms_send( $m['phone'], sprintf( "⏰ سکان | %s عزیز\nیکی از کارهای شما %s روز از تاریخ سررسید گذشته و به مدیر گزارش شد.\nلطفاً امروز انجامش دهید یا وضعیتش را در پنل ثبت کنید.", $first, sap_num( $days ) ), 'late', array( $first, sap_num( $days ) ) );
		}
		++$n;
	}
	return $n;
}
add_action( 'sap_hourly', 'sap_late_escalate' );

/* ---------------------------------------------------------------------------
 * Project manager proposals
 * ------------------------------------------------------------------------- */

/**
 * A project manager adds a task: it waits on the desk until a full-access manager approves it.
 *
 * @return int|WP_Error task id
 */
function sap_pm_propose( $data ) {
	$p = sap_project( absint( $data['project_id'] ?? 0 ) );
	if ( ! $p ) {
		return new WP_Error( 'sap_pm', 'پروژه را انتخاب کنید.' );
	}
	if ( '' === trim( (string) ( $data['title'] ?? '' ) ) ) {
		return new WP_Error( 'sap_pm', 'عنوان کار الزامی است.' );
	}
	$id = sap_task_save( array(
		'project_id' => $p['id'],
		'title'      => $data['title'],
		'notes'      => $data['notes'] ?? '',
		'outcome'    => $data['outcome'] ?? '',
		'context'    => $data['context'] ?? 'management',
		'assignee'   => absint( $data['assignee'] ?? 0 ),
		'due_date'   => $data['due_date'] ?? '',
		'url'        => $data['url'] ?? '',
		'effort'     => $data['effort'] ?? 1,
		'status'     => sap_can() ? 'next' : 'proposed',
		'period'     => sap_period_current(),
		'source'     => 'pm',
	) );
	if ( ! sap_can() ) {
		$me = sap_member( get_current_user_id() );
		sap_notify_full( '📝 ' . ( $me['name'] ?? '' ) . ' یک کار برای تأیید ثبت کرد: «' . mb_substr( (string) $data['title'], 0, 60 ) . '»', array(
			'title'  => 'کار جدید منتظر تأیید شماست',
			'badge'  => '📝 پیشنهاد مدیر پروژه · ' . $p['name'],
			'tone'   => '#0891B2',
			'intro'  => 'بعد از تأیید شما، کار به مسئول واگذار و برایش اطلاع‌رسانی می‌شود.',
			'rows'   => array( 'عنوان' => (string) $data['title'], 'مسئول' => sap_user_name( absint( $data['assignee'] ?? 0 ) ) ?: '—', 'سررسید' => (string) ( $data['due_date'] ?? '' ) ),
			'button' => array( 'بررسی در میز کار', sap_url( 'desk' ) . '#proposed' ),
		) );
	}
	return $id;
}

/* ---------------------------------------------------------------------------
 * Cheap-model helpers
 * ------------------------------------------------------------------------- */

/**
 * Turn a short task description into a clear brief for the person doing it:
 * goal, steps, expected output and exactly what to write in the report.
 *
 * @return array|WP_Error {notes, outcome}
 */
function sap_ai_task_brief( $f ) {
	$p      = sap_project( absint( $f['project_id'] ?? 0 ) );
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'goal', 'steps', 'output', 'report', 'tips' ),
		'properties'           => array(
			'goal'   => array( 'type' => 'string' ),
			'steps'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'output' => array( 'type' => 'string' ),
			'report' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'tips'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		),
	);
	$system = 'تو سرپرست یک تیم سئو هستی و برای عضو تیم شرح کار می‌نویسی. فارسی ساده، دقیق و قابل اجرا بنویس؛ بدون کلی‌گویی. فقط بر اساس اطلاعات داده‌شده؛ چیزی از خودت درباره سایت نساز.';
	$user   = "اطلاعات کار:\n" . wp_json_encode( array(
		'project'     => $p['name'] ?? '',
		'site'        => $p['site_url'] ?? '',
		'title'       => (string) ( $f['title'] ?? '' ),
		'type'        => sap_context_label( (string) ( $f['context'] ?? '' ) ),
		'page'        => (string) ( $f['url'] ?? '' ),
		'description' => mb_substr( (string) ( $f['notes'] ?? '' ), 0, 3000 ),
	), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\nبنویس: goal = هدف کار در یک جمله؛ steps = ۳ تا ۸ قدم دقیق و پشت سر هم؛ output = خروجی نهایی که باید تحویل شود؛ report = ۳ تا ۶ مورد که عضو باید دقیقاً در «گزارش کار» بنویسد (مثلاً آدرس صفحه، تایتل قبل و بعد، تعداد موارد، لینک فایل)، به شکل قالب پرکردنی؛ tips = حداکثر ۳ نکته یا اشتباه رایج.";
	$r = sap_ai_request( $system, $user, $schema, array( 'purpose' => 'help', 'max_tokens' => 1800 ) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$lines   = array( '🎯 هدف: ' . trim( (string) $r['goal'] ), '', '🪜 مراحل انجام:' );
	foreach ( (array) $r['steps'] as $i => $st ) {
		$lines[] = sap_num( $i + 1 ) . '. ' . trim( (string) $st );
	}
	if ( ! empty( $r['tips'] ) ) {
		$lines[] = '';
		$lines[] = '💡 نکته‌ها:';
		foreach ( (array) $r['tips'] as $tp ) {
			$lines[] = '- ' . trim( (string) $tp );
		}
	}
	$lines[] = '';
	$lines[] = '📝 در گزارش کار دقیقاً این‌ها را بنویسید:';
	foreach ( (array) $r['report'] as $rp ) {
		$lines[] = '- ' . trim( (string) $rp );
	}
	return array( 'notes' => implode( "\n", $lines ), 'outcome' => trim( (string) $r['output'] ) );
}

/**
 * Step-by-step help for a member stuck on a task.
 *
 * @return string|WP_Error
 */
function sap_ai_task_help( $task, $question, $history = array() ) {
	$p      = sap_project( $task['project_id'] );
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'answer' ),
		'properties'           => array( 'answer' => array( 'type' => 'string' ) ),
	);
	$system = 'تو مربی صبور یک تیم سئو هستی. به سوال عضو تیم درباره همین کار، گام به گام، کوتاه و عملی جواب بده (قدم‌ها را شماره بزن). اگر جواب به تصمیم مدیر یا اطلاعات کارفرما نیاز دارد، صادقانه بگو و پیشنهاد کن از دکمه «سوال از مدیر» بپرسد. وردپرس، ووکامرس و رنک‌مث را می‌شناسی.';
	$ctx    = array(
		'project' => $p['name'] ?? '',
		'site'    => $p['site_url'] ?? '',
		'task'    => $task['title'],
		'type'    => sap_context_label( $task['context'] ),
		'page'    => $task['url'],
		'brief'   => mb_substr( $task['notes'], 0, 2500 ),
		'output'  => $task['outcome'],
		'feedback_from_manager' => $task['review_note'],
	);
	$conv = '';
	foreach ( array_slice( (array) $history, -6 ) as $h ) {
		$conv .= ( 'user' === ( $h['role'] ?? '' ) ? 'عضو: ' : 'تو: ' ) . mb_substr( wp_strip_all_tags( (string) ( $h['text'] ?? '' ) ), 0, 800 ) . "\n";
	}
	$user = "کار:\n" . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ( '' !== $conv ? "\n\nگفتگوی قبلی:\n" . $conv : '' ) . "\n\nسوال تازه عضو:\n" . mb_substr( (string) $question, 0, 1500 );
	$r    = sap_ai_request( $system, $user, $schema, array( 'purpose' => 'help', 'max_tokens' => 1500 ) );
	return is_wp_error( $r ) ? $r : trim( (string) $r['answer'] );
}

/* ---------------------------------------------------------------------------
 * Attachments (documents of a report)
 * ------------------------------------------------------------------------- */

/**
 * Store an uploaded file (image or PDF, ≤ 8 MB) for a task.
 *
 * @return array|WP_Error {type, url, name}
 */
function sap_task_attach_file( $task, $file ) {
	if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'sap_att', 'فایلی نرسید.' );
	}
	if ( (int) $file['size'] > 8 * MB_IN_BYTES ) {
		return new WP_Error( 'sap_att', 'حداکثر حجم فایل ۸ مگابایت است.' );
	}
	$ok   = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf' );
	$type = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], $ok );
	if ( empty( $type['ext'] ) ) {
		return new WP_Error( 'sap_att', 'فقط تصویر (JPG، PNG، WebP) یا PDF.' );
	}
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'sokan-files/' . (int) $task['project_id'];
	wp_mkdir_p( $dir );
	$name = 't' . (int) $task['id'] . '-' . wp_generate_password( 10, false ) . '.' . $type['ext'];
	if ( ! @move_uploaded_file( $file['tmp_name'], $dir . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'sap_att', 'ذخیره فایل ناموفق بود.' );
	}
	return array( 'type' => 0 === strpos( (string) $type['type'], 'image/' ) ? 'image' : 'file', 'url' => trailingslashit( $up['baseurl'] ) . 'sokan-files/' . (int) $task['project_id'] . '/' . $name, 'name' => sanitize_file_name( (string) $file['name'] ) );
}

/* ---------------------------------------------------------------------------
 * Endpoints
 * ------------------------------------------------------------------------- */

/**
 * The task, when the current user may work on it (assignee, or a full manager).
 */
function sap_task_for_user( $id ) {
	$t = sap_task( (int) $id );
	if ( ! $t ) {
		return new WP_Error( 'sap_task', 'کار پیدا نشد.', array( 'status' => 404 ) );
	}
	if ( ! sap_can() && (int) $t['assignee'] !== get_current_user_id() ) {
		return new WP_Error( 'sap_forbidden', 'این کار به شما سپرده نشده.', array( 'status' => 403 ) );
	}
	return $t;
}

add_action( 'rest_api_init', function () {
	$ns = 'seoactionplan/v1';
	register_rest_route( $ns, '/task/(?P<id>\d+)/submit', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task_for_user( $req['id'] );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$links = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', (array) $req->get_param( 'links' ) ) ) ) );
			$keep  = array_values( array_filter( $t['attachments'], function ( $a ) {
				return 'link' !== ( $a['type'] ?? '' );
			} ) );
			foreach ( $links as $l ) {
				$keep[] = array( 'type' => 'link', 'url' => $l, 'name' => '' );
			}
			$r = sap_task_submit( $t, (string) $req->get_param( 'result' ), null !== $req->get_param( 'spent' ) ? (string) $req->get_param( 'spent' ) : null, $keep );
			return is_wp_error( $r ) ? $r : array_merge( array( 'ok' => true ), $r );
		},
	) );
	register_rest_route( $ns, '/task/(?P<id>\d+)/attach', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task_for_user( $req['id'] );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			if ( 'remove' === $req->get_param( 'do' ) ) {
				$url = (string) $req->get_param( 'url' );
				$att = array_values( array_filter( $t['attachments'], function ( $a ) use ( $url ) {
					return $a['url'] !== $url;
				} ) );
				sap_task_save( array( 'attachments' => $att ), $t['id'] );
				return array( 'ok' => true );
			}
			$files = $req->get_file_params();
			$a     = sap_task_attach_file( $t, $files['file'] ?? array() );
			if ( is_wp_error( $a ) ) {
				return $a;
			}
			$att   = $t['attachments'];
			$att[] = $a;
			sap_task_save( array( 'attachments' => $att ), $t['id'] );
			return array( 'ok' => true, 'file' => $a );
		},
	) );
	register_rest_route( $ns, '/task/(?P<id>\d+)/help', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task_for_user( $req['id'] );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$a = sap_ai_task_help( $t, (string) $req->get_param( 'question' ), (array) $req->get_param( 'history' ) );
			return is_wp_error( $a ) ? $a : array( 'ok' => true, 'answer' => $a );
		},
	) );
	register_rest_route( $ns, '/task/(?P<id>\d+)/ask', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can_work',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task_for_user( $req['id'] );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$r = sap_question_add( $t, (string) $req->get_param( 'question' ) );
			return is_wp_error( $r ) ? $r : array( 'ok' => true, 'msg' => 'سوال برای مدیران ارسال شد؛ پاسخ با ایمیل و همین‌جا می‌رسد.' );
		},
	) );
	register_rest_route( $ns, '/task/(?P<id>\d+)/review', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task( (int) $req['id'] );
			if ( ! $t ) {
				return new WP_Error( 'sap_task', 'کار پیدا نشد.', array( 'status' => 404 ) );
			}
			$r = sap_task_review( $t, sanitize_key( (string) $req->get_param( 'do' ) ), (string) $req->get_param( 'note' ) );
			return is_wp_error( $r ) ? $r : array_merge( array( 'ok' => true ), $r );
		},
	) );
	register_rest_route( $ns, '/question/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$r = sap_question_answer( (int) $req['id'], (string) $req->get_param( 'answer' ) );
			return is_wp_error( $r ) ? $r : array( 'ok' => true, 'msg' => 'پاسخ ارسال شد.' );
		},
	) );
	register_rest_route( $ns, '/proposal/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$t = sap_task( (int) $req['id'] );
			if ( ! $t || 'proposed' !== $t['status'] ) {
				return new WP_Error( 'sap_task', 'پیشنهاد پیدا نشد.', array( 'status' => 404 ) );
			}
			if ( 'reject' === $req->get_param( 'do' ) ) {
				sap_task_save( array( 'status' => 'dropped' ), $t['id'] );
				return array( 'ok' => true, 'msg' => 'پیشنهاد رد شد.' );
			}
			$data = array( 'status' => 'next' );
			if ( null !== $req->get_param( 'assignee' ) ) {
				$data['assignee'] = absint( $req->get_param( 'assignee' ) );
			}
			if ( '' !== (string) $req->get_param( 'due_date' ) ) {
				$data['due_date'] = (string) $req->get_param( 'due_date' );
			}
			sap_task_save( $data, $t['id'] );
			return array( 'ok' => true, 'msg' => 'تأیید شد و به مسئول واگذار شد.' );
		},
	) );
	register_rest_route( $ns, '/task-brief', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return sap_can() || sap_is_pm();
		},
		'callback'            => function ( WP_REST_Request $req ) {
			$r = sap_ai_task_brief( array(
				'project_id' => absint( $req->get_param( 'project_id' ) ),
				'title'      => (string) $req->get_param( 'title' ),
				'notes'      => (string) $req->get_param( 'notes' ),
				'context'    => sanitize_key( (string) $req->get_param( 'context' ) ),
				'url'        => esc_url_raw( (string) $req->get_param( 'url' ) ),
			) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			// Saved straight into the task when asked from a task card.
			$tid = absint( $req->get_param( 'task_id' ) );
			if ( $tid && sap_can() && sap_task( $tid ) ) {
				sap_task_save( array( 'notes' => $r['notes'], 'outcome' => $r['outcome'] ), $tid );
			}
			return array( 'ok' => true, 'notes' => $r['notes'], 'outcome' => $r['outcome'] );
		},
	) );
} );

/**
 * Project manager's form (admin-post).
 */
add_action( 'admin_post_sap_pm_task', function () {
	if ( ! ( sap_can() || sap_is_pm() ) || ! isset( $_POST['_sap_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sap_nonce'] ) ), 'sap_pm_task' ) ) {
		wp_die( 'دسترسی غیرمجاز', 403 );
	}
	$r = sap_pm_propose( array(
		'project_id' => sap_post( 'project_id' ),
		'title'      => sap_post( 'title' ),
		'notes'      => sap_post( 'notes' ),
		'outcome'    => sap_post( 'outcome' ),
		'context'    => sap_post( 'context' ),
		'assignee'   => sap_post( 'assignee' ),
		'due_date'   => sap_post( 'due_date' ),
		'url'        => sap_post( 'url' ),
		'effort'     => sap_post( 'effort' ),
	) );
	sap_back( sap_url( 'pm' ), is_wp_error( $r ) ? $r->get_error_message() : ( sap_can() ? 'کار ثبت و واگذار شد.' : 'کار ثبت شد و بعد از تأیید مدیر سئو به مسئول واگذار می‌شود.' ), ! is_wp_error( $r ) );
} );
