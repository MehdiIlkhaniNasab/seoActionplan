<?php
/**
 * Notifications: email (branded RTL template), SMS (Kavenegar, SMS.ir,
 * IPPanel / FarazSMS or any HTTP gateway), Telegram and Bale bots — with a send
 * log, so alerts, task assignments and daily reminders reach people where they are.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * SMS providers: key => label.
 */
function sap_sms_providers() {
	return array(
		'none'      => 'غیرفعال',
		'kavenegar' => 'کاوه‌نگار',
		'smsir'     => 'SMS.ir',
		'ippanel'   => 'IPPanel / فراز اس‌ام‌اس',
		'melipayamak' => 'ملی پیامک',
		'custom'    => 'درگاه سفارشی (آدرس با {phone} و {text})',
	);
}

/**
 * Channels: key => label.
 */
function sap_notify_channels() {
	return array( 'email' => 'ایمیل', 'sms' => 'پیامک', 'tg' => 'تلگرام', 'bale' => 'بله', 'digest' => 'فقط خلاصه روزانه' );
}

/**
 * Record a send (last 200).
 */
function sap_notify_log( $channel, $to, $text, $ok, $err = '', $meta = array() ) {
	$log   = (array) get_option( 'sap_notify_log', array() );
	$log[] = array_merge( array( 'at' => sap_now(), 'ch' => $channel, 'to' => mb_substr( (string) $to, 0, 60 ), 'text' => mb_substr( wp_strip_all_tags( (string) $text ), 0, 160 ), 'ok' => (bool) $ok, 'err' => mb_substr( (string) $err, 0, 200 ) ), (array) $meta );
	update_option( 'sap_notify_log', array_slice( $log, -200 ), false );
}

/**
 * Iranian mobile numbers to 09xxxxxxxxx; '' when not a mobile number.
 */
function sap_phone_norm( $phone ) {
	$p = preg_replace( '/\D/', '', sap_norm_text( (string) $phone ) );
	if ( preg_match( '/^(?:0098|98)(9\d{9})$/', $p, $m ) ) {
		return '0' . $m[1];
	}
	if ( preg_match( '/^0?(9\d{9})$/', $p, $m ) ) {
		return '0' . $m[1];
	}
	return '';
}

/**
 * Branded RTL email.
 *
 * @param string|string[] $to
 * @param array           $args title, intro, rows [label => value], items [text], button [label, url], footer.
 */
function sap_notify_email( $to, $subject, $args ) {
	if ( ! sap_setting( 'notify_email', 1 ) ) {
		sap_notify_log( 'email', implode( ', ', (array) $to ), $subject, false, 'ارسال ایمیل در تنظیمات خاموش است.' );
		return false;
	}
	$to = array_filter( (array) $to, 'is_email' );
	if ( ! $to ) {
		return false;
	}
	$a    = wp_parse_args( $args, array( 'title' => $subject, 'intro' => '', 'rows' => array(), 'items' => array(), 'button' => null, 'footer' => '', 'tone' => '#2563EB', 'badge' => '', 'preheader' => '', 'note' => '' ) );
	$html = sap_email_html( $a );
	// Plain-text part: better inbox placement than HTML-only mail.
	$GLOBALS['sap_mail_alt'] = sap_email_text( $a );
	$GLOBALS['sap_mail_error'] = '';
	$ok = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	unset( $GLOBALS['sap_mail_alt'] );
	sap_notify_log( 'email', implode( ', ', $to ), $subject, $ok, $ok ? '' : sap_mail_error_text() );
	return $ok;
}

/**
 * Branded RTL email (table layout + inline styles: renders in Gmail, Outlook and mobile apps).
 */
function sap_email_html( $a ) {
	$tone  = preg_match( '/^#[0-9a-f]{6}$/i', (string) $a['tone'] ) ? $a['tone'] : '#2563EB';
	$site  = (string) ( sap_setting( 'smtp_from_name' ) ?: get_bloginfo( 'name' ) );
	$font  = "Vazirmatn,Tahoma,'Segoe UI',Arial,sans-serif";
	$rows  = '';
	foreach ( (array) $a['rows'] as $k => $v ) {
		if ( '' === trim( (string) $v ) ) {
			continue;
		}
		$val   = preg_match( '#^https?://#', (string) $v ) ? '<a href="' . esc_url( $v ) . '" style="color:' . $tone . ';text-decoration:none;direction:ltr;unicode-bidi:embed">' . esc_html( rawurldecode( (string) $v ) ) . '</a>' : nl2br( esc_html( (string) $v ) );
		$rows .= '<tr><td style="padding:9px 14px;color:#64748b;font-size:12.5px;width:110px;vertical-align:top;border-bottom:1px solid #eef2f7;white-space:nowrap">' . esc_html( $k ) . '</td><td style="padding:9px 14px;color:#0f172a;font-size:13.5px;font-weight:600;border-bottom:1px solid #eef2f7">' . $val . '</td></tr>';
	}
	$items = '';
	$n     = 0;
	foreach ( (array) $a['items'] as $it ) {
		++$n;
		$body   = wp_kses( (string) $it, array( 'b' => array(), 'br' => array(), 'a' => array( 'href' => array() ), 'small' => array(), 'span' => array( 'style' => array() ) ) );
		$body   = str_replace( '<small>', '<small style="color:#64748b;font-size:12px">', $body );
		$items .= '<tr><td style="padding:0 0 8px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border-radius:10px;border-right:3px solid ' . $tone . '"><tr>'
			. '<td style="padding:10px 12px;width:26px;vertical-align:top;color:' . $tone . ';font-weight:800;font-size:13px">' . esc_html( sap_num( $n ) ) . '</td>'
			. '<td style="padding:10px 4px 10px 12px;color:#0f172a;font-size:13.5px;line-height:1.9">' . $body . '</td></tr></table></td></tr>';
	}
	$btn = '';
	if ( $a['button'] ) {
		$btn = '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0 6px"><tr><td style="border-radius:12px;background:' . $tone . ';box-shadow:0 6px 16px ' . $tone . '40">'
			. '<a href="' . esc_url( $a['button'][1] ) . '" style="display:inline-block;padding:13px 28px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;font-family:' . $font . '">' . esc_html( $a['button'][0] ) . ' &larr;</a></td></tr></table>'
			. '<p style="margin:8px 0 0;color:#94a3b8;font-size:11.5px">اگر دکمه باز نشد این آدرس را در مرورگر باز کنید:<br><a href="' . esc_url( $a['button'][1] ) . '" style="color:#64748b;direction:ltr;unicode-bidi:embed;word-break:break-all">' . esc_html( rawurldecode( $a['button'][1] ) ) . '</a></p>';
	}
	$badge = '' !== $a['badge'] ? '<span style="display:inline-block;background:#ffffff26;color:#ffffff;border:1px solid #ffffff55;border-radius:999px;padding:3px 12px;font-size:12px;margin-bottom:10px">' . esc_html( $a['badge'] ) . '</span><br>' : '';
	$pre   = '' !== $a['preheader'] ? $a['preheader'] : wp_strip_all_tags( (string) $a['intro'] );
	return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap" rel="stylesheet"><title>' . esc_html( $a['title'] ) . '</title></head>'
		. '<body style="margin:0;padding:0;background:#eef2f7;font-family:' . $font . '">'
		. '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . esc_html( mb_substr( $pre, 0, 140 ) ) . str_repeat( '&#8204;&nbsp;', 40 ) . '</div>'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f7"><tr><td align="center" style="padding:28px 12px">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px">'
		// Brand line.
		. '<tr><td style="padding:0 6px 12px;direction:rtl;text-align:right;font-family:' . $font . '"><span style="display:inline-block;width:30px;height:30px;line-height:30px;text-align:center;border-radius:9px;background:' . $tone . ';color:#fff;font-weight:800;font-size:15px;vertical-align:middle">س</span> <b style="color:#0f172a;font-size:15px;vertical-align:middle">سُکان</b> <span style="color:#94a3b8;font-size:12px;vertical-align:middle">· ' . esc_html( $site ) . '</span></td></tr>'
		. '<tr><td style="background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 8px 28px rgba(15,23,42,.08)">'
		// Hero.
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="background:' . $tone . ';background-image:linear-gradient(135deg,' . $tone . ' 0%,#0f172a 140%);padding:26px 28px;direction:rtl;text-align:right;font-family:' . $font . '">'
		. $badge . '<h1 style="margin:0;color:#ffffff;font-size:21px;line-height:1.6;font-weight:800">' . esc_html( $a['title'] ) . '</h1></td></tr></table>'
		// Body.
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="padding:24px 28px 28px;direction:rtl;text-align:right;line-height:1.95;font-size:14px;color:#334155;font-family:' . $font . '">'
		. ( $a['intro'] ? '<p style="margin:0 0 16px">' . nl2br( esc_html( $a['intro'] ) ) . '</p>' : '' )
		. ( $rows ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #eef2f7;border-radius:12px;margin:4px 0 16px;border-collapse:separate;overflow:hidden">' . $rows . '</table>' : '' )
		. ( $items ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:6px 0 4px">' . $items . '</table>' : '' )
		. ( '' !== $a['note'] ? '<p style="margin:14px 0 0;padding:10px 14px;background:#fffbeb;border-radius:10px;color:#92400e;font-size:13px">' . esc_html( $a['note'] ) . '</p>' : '' )
		. $btn
		. '</td></tr></table></td></tr>'
		// Footer.
		. '<tr><td style="padding:16px 10px;text-align:center;color:#94a3b8;font-size:11.5px;line-height:1.9;font-family:' . $font . '">' . esc_html( $a['footer'] ?: 'این پیام خودکار از سکان (' . $site . ') ارسال شده است؛ لازم نیست به آن پاسخ دهید.' ) . '</td></tr>'
		. '</table></td></tr></table></body></html>';
}

/**
 * Plain-text version of the same email.
 */
function sap_email_text( $a ) {
	$out = array( $a['title'], '' );
	if ( $a['intro'] ) {
		$out[] = wp_strip_all_tags( (string) $a['intro'] );
		$out[] = '';
	}
	foreach ( (array) $a['rows'] as $k => $v ) {
		if ( '' !== trim( (string) $v ) ) {
			$out[] = $k . ': ' . rawurldecode( (string) $v );
		}
	}
	foreach ( array_values( (array) $a['items'] ) as $i => $it ) {
		$out[] = sap_num( $i + 1 ) . ') ' . trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), ' — ', (string) $it ) ) ) );
	}
	if ( $a['button'] ) {
		$out[] = '';
		$out[] = $a['button'][0] . ': ' . $a['button'][1];
	}
	return implode( "\n", $out );
}

add_action( 'phpmailer_init', function ( $mailer ) {
	if ( ! empty( $GLOBALS['sap_mail_alt'] ) ) {
		$mailer->AltBody = (string) $GLOBALS['sap_mail_alt'];
	}
}, 30 );

/**
 * Why the last email failed, in words a person can act on.
 */
function sap_mail_error_text() {
	$err = trim( (string) ( $GLOBALS['sap_mail_error'] ?? '' ) );
	if ( '' === sap_setting( 'smtp_host' ) && ! sap_other_smtp_plugin() ) {
		return 'سرور ایمیل (SMTP) تنظیم نشده؛ روی لوکال‌هاست و بیشتر هاست‌ها تابع mail() کار نمی‌کند. در «تنظیمات › اعلان‌ها › ارسال ایمیل (SMTP)» سرور ایمیل را وارد کنید.' . ( '' !== $err ? ' (' . $err . ')' : '' );
	}
	if ( false !== stripos( $err, 'authenticate' ) ) {
		return 'نام کاربری یا رمز SMTP پذیرفته نشد (برای Gmail «رمز برنامه» App Password لازم است، نه رمز اصلی). ' . $err;
	}
	if ( false !== stripos( $err, 'connect' ) ) {
		return 'اتصال به سرور SMTP برقرار نشد؛ آدرس، پورت (۵۸۷ با TLS یا ۴۶۵ با SSL) و فایروال را بررسی کنید. ' . $err;
	}
	return '' !== $err ? $err : 'wp_mail ناموفق بود.';
}

/**
 * Does another plugin (WP Mail SMTP, FluentSMTP, …) configure the mailer?
 */
function sap_other_smtp_plugin() {
	global $wp_filter;
	$n = 0;
	if ( isset( $wp_filter['phpmailer_init'] ) ) {
		foreach ( $wp_filter['phpmailer_init']->callbacks as $cbs ) {
			$n += count( $cbs );
		}
	}
	return $n > 1 || defined( 'WPMS_ON' ) || class_exists( 'WPMailSMTP\\Core' ) || defined( 'FLUENTMAIL' );
}

// Remember the exact reason of a failed email (PHPMailer message).
add_action( 'wp_mail_failed', function ( $error ) {
	$GLOBALS['sap_mail_error'] = is_wp_error( $error ) ? $error->get_error_message() : '';
} );

/*
 * A valid sender: the SMTP mailbox when set; otherwise WordPress's default
 * "wordpress@<host>" is replaced when the host is not a real domain
 * (wordpress@localhost is rejected as an invalid address before sending).
 */
add_filter( 'wp_mail_from', function ( $from ) {
	$set = (string) sap_setting( 'smtp_from' ) ?: ( is_email( (string) sap_setting( 'smtp_user' ) ) ? (string) sap_setting( 'smtp_user' ) : '' );
	if ( '' !== sap_setting( 'smtp_host' ) && '' !== $set ) {
		return $set;
	}
	if ( ! is_email( $from ) || ! preg_match( '/@[^@]+\.[a-z]{2,}$/i', (string) $from ) ) {
		$alt = '' !== $set ? $set : (string) get_option( 'admin_email' );
		return is_email( $alt ) ? $alt : $from;
	}
	return $from;
}, 99 );

add_filter( 'wp_mail_from_name', function ( $name ) {
	$n = (string) sap_setting( 'smtp_from_name' );
	return '' !== sap_setting( 'smtp_host' ) && '' !== $n ? $n : $name;
}, 99 );

// Send WordPress email through the SMTP server set in Sokan's settings (when one is set).
add_action( 'phpmailer_init', function ( $mailer ) {
	$host = (string) sap_setting( 'smtp_host' );
	if ( '' === $host ) {
		return;
	}
	$secure = (string) sap_setting( 'smtp_secure', 'tls' );
	$mailer->isSMTP();
	$mailer->Host        = $host;
	$mailer->Port        = (int) sap_setting( 'smtp_port', 587 );
	$mailer->SMTPSecure  = 'none' === $secure ? '' : $secure;
	$mailer->SMTPAutoTLS = 'none' !== $secure;
	$mailer->Timeout     = 20;
	$user = (string) sap_setting( 'smtp_user' );
	if ( '' !== $user ) {
		$mailer->SMTPAuth = true;
		$mailer->Username = $user;
		$mailer->Password = (string) sap_setting( 'smtp_pass' );
	}
	// Most servers only accept the authenticated mailbox as the sender.
	$from = (string) sap_setting( 'smtp_from' ) ?: ( is_email( $user ) ? $user : '' );
	if ( '' !== $from ) {
		$mailer->setFrom( $from, (string) sap_setting( 'smtp_from_name' ) ?: get_bloginfo( 'name' ), false );
	}
}, 20 );

/**
 * Send an SMS through the configured gateway.
 *
 * @return true|WP_Error
 */
function sap_sms_send( $phone, $text, $event = '', $args = array() ) {
	$provider = sap_setting( 'sms_provider', 'none' );
	$phone    = sap_phone_norm( $phone );
	$key      = (string) sap_setting( 'sms_key' );
	$from     = (string) sap_setting( 'sms_sender' );
	if ( 'none' === $provider ) {
		return new WP_Error( 'sap_sms_off', 'درگاه پیامک تنظیم نشده.' );
	}
	if ( '' === $phone ) {
		return new WP_Error( 'sap_sms_phone', 'شماره موبایل معتبر نیست.' );
	}
	$text = trim( (string) $text );
	$body = 'melipayamak' === $provider ? sap_sms_pattern( $event ) : 0;
	if ( $body ) {
		return sap_melipayamak_pattern( $phone, $body, (array) $args, $text );
	}
	$foot = trim( (string) sap_setting( 'sms_footer' ) );
	if ( '' !== $foot && false === mb_strpos( $text, $foot ) ) {
		$text .= "\n" . $foot; // E.g. «لغو11» when the panel requires it.
	}
	$fail = '';
	$rec  = '';
	switch ( $provider ) {
		case 'kavenegar':
			$res = wp_remote_get( add_query_arg( array( 'receptor' => $phone, 'sender' => $from, 'message' => rawurlencode( $text ) ), 'https://api.kavenegar.com/v1/' . rawurlencode( $key ) . '/sms/send.json' ), array( 'timeout' => 20 ) );
			break;
		case 'smsir':
			$res = wp_remote_post( 'https://api.sms.ir/v1/send/bulk', array( 'timeout' => 20, 'headers' => array( 'X-API-KEY' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), 'body' => wp_json_encode( array( 'lineNumber' => (int) $from, 'messageText' => $text, 'mobiles' => array( $phone ) ) ) ) );
			break;
		case 'ippanel':
			$res = wp_remote_post( 'https://api2.ippanel.com/api/v1/sms/send/webservice/single', array( 'timeout' => 20, 'headers' => array( 'apikey' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), 'body' => wp_json_encode( array( 'recipient' => array( $phone ), 'sender' => $from, 'message' => $text ) ) ) );
			break;
		case 'melipayamak':
			$user = (string) sap_setting( 'sms_user' );
			if ( '' !== $user ) {
				// Classic web service: username + password (or the panel's API key as the password).
				$res = wp_remote_post( 'https://rest.payamak-panel.com/api/SendSMS/SendSMS', array( 'timeout' => 20, 'body' => array( 'username' => $user, 'password' => $key, 'to' => $phone, 'from' => $from, 'text' => $text, 'isflash' => 'false' ) ) );
				if ( ! is_wp_error( $res ) ) {
					$j    = json_decode( (string) wp_remote_retrieve_body( $res ), true, 512, JSON_BIGINT_AS_STRING );
					$fail = 1 === (int) ( $j['RetStatus'] ?? 0 ) ? '' : sap_melipayamak_error( $j['Value'] ?? ( $j['RetStatus'] ?? '' ), (string) ( $j['StrRetStatus'] ?? '' ) );
					$rec  = '' === $fail ? (string) ( $j['Value'] ?? '' ) : '';
				}
			} else {
				// Console API: the key from console.melipayamak.com › web service.
				$res = wp_remote_post( 'https://console.melipayamak.com/api/send/simple/' . rawurlencode( $key ), array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'from' => $from, 'to' => $phone, 'text' => $text ) ) ) );
				if ( ! is_wp_error( $res ) ) {
					$j    = json_decode( (string) wp_remote_retrieve_body( $res ), true, 512, JSON_BIGINT_AS_STRING );
					$fail = ! empty( $j['recId'] ) && (float) $j['recId'] > 1000 ? '' : sap_melipayamak_error( empty( $j['status'] ) ? ( $j['recId'] ?? '' ) : '', (string) ( $j['status'] ?? '' ) );
					$rec  = '' === $fail ? (string) $j['recId'] : '';
				}
			}
			break;
		default:
			$url = (string) sap_setting( 'sms_custom_url' );
			if ( '' === $url ) {
				return new WP_Error( 'sap_sms_url', 'آدرس درگاه سفارشی وارد نشده.' );
			}
			$url = str_replace( array( '{phone}', '{text}', '{sender}', '{key}' ), array( rawurlencode( $phone ), rawurlencode( $text ), rawurlencode( $from ), rawurlencode( $key ) ), $url );
			$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
	}
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	// Gateways that answer HTTP 200 with an error inside.
	if ( ! is_wp_error( $res ) && '' === $fail && $code >= 200 && $code < 300 ) {
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true, 512, JSON_BIGINT_AS_STRING );
		if ( 'kavenegar' === $provider && isset( $j['return']['status'] ) && 200 !== (int) $j['return']['status'] ) {
			$fail = (string) ( $j['return']['message'] ?? 'خطای کاوه‌نگار' );
		} elseif ( 'smsir' === $provider && isset( $j['status'] ) && 1 !== (int) $j['status'] ) {
			$fail = (string) ( $j['message'] ?? 'خطای SMS.ir' );
		}
	}
	$ok   = ! is_wp_error( $res ) && $code >= 200 && $code < 300 && '' === $fail;
	$err  = is_wp_error( $res ) ? $res->get_error_message() : ( $ok ? '' : ( '' !== $fail ? $fail : 'HTTP ' . $code . ': ' . mb_substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $res ) ), 0, 160 ) ) );
	sap_sms_logged( $phone, $text, $ok, $err, $rec );
	return $ok ? true : new WP_Error( 'sap_sms', 'ارسال پیامک ناموفق بود: ' . $err );
}

/**
 * Log an SMS; a Melipayamak message id (recId) queues a delivery check, because the
 * API accepting a message does not mean the operator delivered it.
 */
function sap_sms_logged( $phone, $text, $ok, $err, $rec = '', $mode = 'text' ) {
	$meta = array( 'mode' => $mode );
	if ( $ok && preg_match( '/^\d{6,}$/', (string) $rec ) ) {
		$meta['rec'] = (string) $rec;
		$meta['dlv'] = 'wait';
		if ( ! wp_next_scheduled( 'sap_sms_delivery_check' ) ) {
			wp_schedule_single_event( time() + 150, 'sap_sms_delivery_check' );
		}
	}
	sap_notify_log( 'sms', $phone, $text, $ok, $err, $meta );
}

/**
 * Pattern events: key => [label, variables, suggested template text]. Variables are
 * sent in this order as {0}, {1}…; links are not allowed inside variables, so the
 * panel address is written into the template itself.
 */
function sap_sms_events() {
	$panel = preg_replace( '#^https?://#', '', untrailingslashit( function_exists( 'sap_url' ) ? sap_url( 'my' ) : home_url() ) );
	return array(
		'task'   => array( 'واگذاری یک کار', array( 'نام عضو', 'عنوان کار', 'پروژه', 'سررسید' ), "{0} عزیز، کار جدید در سکان:\n{1}\nپروژه: {2}\nسررسید: {3}" ),
		'tasks'  => array( 'واگذاری چند کار پشت سر هم (یک پیامک خلاصه)', array( 'نام عضو', 'تعداد کار', 'پروژه', 'عنوان اولین کار' ), "{0} عزیز، {1} کار جدید در سکان برای شما ثبت شد.\nپروژه: {2}\nاز جمله: {3}" ),
		'done'   => array( 'تشکر بعد از انجام کار', array( 'نام عضو', 'عنوان کار' ), "سکان | خسته نباشید {0}! «{1}» انجام شد و ثبت شد." ),
		'remind' => array( 'یادآوری کارهای باز', array( 'نام عضو', 'تعداد کار باز', 'تعداد عقب از موعد' ), "سکان | یادآوری {0}\n{1} کار باز دارید ({2} عقب از موعد)." ),
		'invite' => array( 'دعوت عضو جدید', array( 'نام عضو', 'نام کاربری' ), "{0} عزیز، به تیم سئو اضافه شدید.\nنام کاربری: {1}\nپنل کارها: " . $panel ),
		'alert'  => array( 'هشدار و پیام به مدیر سئو', array( 'خلاصه پیام' ), "سکان | {0}" ),
		'test'   => array( 'پیام آزمایشی', array( 'تاریخ' ), "پیام آزمایشی سکان — ارسال با پترن درست است. {0}" ),
	);
}

/**
 * Approved Melipayamak bodyId for an event (0 = send as plain text).
 */
function sap_sms_pattern( $event ) {
	if ( '' === (string) $event ) {
		return 0;
	}
	$all = (array) sap_setting( 'sms_patterns', array() );
	return (int) ( $all[ $event ] ?? 0 );
}

/**
 * Send through a Melipayamak service-line pattern (BaseServiceNumber / console "shared").
 * Service messages bypass the operators' advertising blacklist and need no «لغو11».
 *
 * @return true|WP_Error
 */
function sap_melipayamak_pattern( $phone, $body_id, $args, $text = '' ) {
	$key  = (string) sap_setting( 'sms_key' );
	$user = (string) sap_setting( 'sms_user' );
	$args = array_map( function ( $a ) {
		// Variables: one line, no «;», no links (the panel rejects links in variables).
		$a = preg_replace( '#https?://\S+#u', '', (string) $a );
		return mb_substr( trim( preg_replace( '/\s+/u', ' ', str_replace( ';', '،', $a ) ) ), 0, 60 ) ?: '-';
	}, array_values( (array) $args ) );
	$fail = '';
	$rec  = '';
	if ( '' !== $user ) {
		$res = wp_remote_post( 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', array( 'timeout' => 20, 'body' => array( 'username' => $user, 'password' => $key, 'text' => implode( ';', $args ), 'to' => $phone, 'bodyId' => (int) $body_id ) ) );
		if ( ! is_wp_error( $res ) ) {
			$j   = json_decode( (string) wp_remote_retrieve_body( $res ), true, 512, JSON_BIGINT_AS_STRING );
			$val = (string) ( $j['Value'] ?? '' );
			if ( strlen( $val ) > 12 && ctype_digit( $val ) ) {
				$rec = $val;
			} else {
				$fail = sap_melipayamak_error( '' !== $val ? $val : ( $j['RetStatus'] ?? '' ), (string) ( $j['StrRetStatus'] ?? '' ) );
			}
		}
	} else {
		$res = wp_remote_post( 'https://console.melipayamak.com/api/send/shared/' . rawurlencode( $key ), array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'bodyId' => (int) $body_id, 'to' => $phone, 'args' => $args ) ) ) );
		if ( ! is_wp_error( $res ) ) {
			$j = json_decode( (string) wp_remote_retrieve_body( $res ), true, 512, JSON_BIGINT_AS_STRING );
			if ( ! empty( $j['recId'] ) && (float) $j['recId'] > 1000 ) {
				$rec = (string) $j['recId'];
			} else {
				$fail = sap_melipayamak_error( empty( $j['status'] ) ? ( $j['recId'] ?? '' ) : '', (string) ( $j['status'] ?? '' ) );
			}
		}
	}
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	$ok   = ! is_wp_error( $res ) && $code >= 200 && $code < 300 && '' === $fail;
	$err  = is_wp_error( $res ) ? $res->get_error_message() : ( $ok ? '' : ( '' !== $fail ? $fail : 'HTTP ' . $code ) );
	sap_sms_logged( $phone, ( '' !== $text ? $text : implode( ' · ', $args ) ), $ok, $err, $rec, 'pattern' );
	return $ok ? true : new WP_Error( 'sap_sms', 'ارسال پیامک (پترن ' . (int) $body_id . ') ناموفق بود: ' . $err );
}

/**
 * Melipayamak delivery status → [state, label]; state: ok|wait|fail.
 */
function sap_melipayamak_delivery_label( $code ) {
	$c   = (string) (int) $code;
	$map = array(
		'1'   => array( 'ok', 'به گوشی گیرنده رسید' ),
		'0'   => array( 'wait', 'به مخابرات ارسال شد؛ گزارش تحویل هنوز نیامده' ),
		'8'   => array( 'wait', 'مخابرات دریافت کرد؛ در حال تحویل' ),
		'200' => array( 'wait', 'ارسال شد؛ گزارش تحویل هنوز نیامده' ),
		'400' => array( 'wait', 'در صف ارسال' ),
		'2'   => array( 'fail', 'به گوشی نرسید (گوشی خاموش/خارج از دسترس یا مسدودی پیامک تبلیغاتی)' ),
		'3'   => array( 'fail', 'خطای مخابراتی' ),
		'5'   => array( 'fail', 'خطای نامشخص در گزارش تحویل' ),
		'16'  => array( 'fail', 'مخابرات نپذیرفت — معمولاً یعنی گیرنده پیامک تبلیغاتی را مسدود کرده و خط شما تبلیغاتی/عمومی است؛ با پترن (خط خدماتی) بفرستید' ),
		'35'  => array( 'fail', 'شماره گیرنده در لیست سیاه مخابرات است — فقط با پترن (خط خدماتی) می‌رسد' ),
		'100' => array( 'fail', 'وضعیت نامشخص' ),
		'300' => array( 'fail', 'متن پیامک فیلتر شد (کلمه یا لینک غیرمجاز)' ),
		'500' => array( 'fail', 'پیامک توسط اپراتور پذیرفته نشد (معمولاً لینک یا محتوای تبلیغاتی روی خط تبلیغاتی)' ),
	);
	return $map[ $c ] ?? array( 'fail', 'وضعیت ' . $c );
}

/**
 * Query delivery for one Melipayamak message id. Returns the status code or WP_Error.
 */
function sap_melipayamak_delivery( $rec ) {
	$key  = (string) sap_setting( 'sms_key' );
	$user = (string) sap_setting( 'sms_user' );
	if ( '' !== $user ) {
		$res = wp_remote_post( 'https://rest.payamak-panel.com/api/SendSMS/GetDeliveries2', array( 'timeout' => 15, 'body' => array( 'username' => $user, 'password' => $key, 'recId' => $rec ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true, 512, JSON_BIGINT_AS_STRING );
		if ( ! isset( $j['Value'] ) || ( isset( $j['RetStatus'] ) && 1 !== (int) $j['RetStatus'] ) ) {
			return new WP_Error( 'sap_dlv', sap_melipayamak_error( $j['RetStatus'] ?? '', (string) ( $j['StrRetStatus'] ?? '' ) ) );
		}
		return (int) $j['Value'];
	}
	$res = wp_remote_post( 'https://console.melipayamak.com/api/receive/status/' . rawurlencode( $key ), array( 'timeout' => 15, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => '{"recIds":[' . preg_replace( '/\D/', '', (string) $rec ) . ']}' ) ); // Raw digits: ids exceed float precision.
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$j = json_decode( (string) wp_remote_retrieve_body( $res ), true, 512, JSON_BIGINT_AS_STRING );
	if ( ! isset( $j['results'][0] ) ) {
		return new WP_Error( 'sap_dlv', 'ملی پیامک: ' . ( (string) ( $j['status'] ?? 'گزارش تحویل در دسترس نیست' ) ) );
	}
	return (int) $j['results'][0];
}

/**
 * Update pending delivery states in the send log (cron + «بررسی تحویل» button).
 *
 * @return int Messages whose status was refreshed.
 */
function sap_sms_check_deliveries() {
	$log  = (array) get_option( 'sap_notify_log', array() );
	$done = 0;
	$wait = false;
	foreach ( $log as $i => $l ) {
		if ( 'sms' !== ( $l['ch'] ?? '' ) || empty( $l['rec'] ) || 'wait' !== ( $l['dlv'] ?? '' ) ) {
			continue;
		}
		if ( strtotime( $l['at'] ) < current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS ) {
			$log[ $i ]['dlv']   = 'unknown';
			$log[ $i ]['dlv_t'] = 'گزارش تحویل تا دو روز نیامد';
			continue;
		}
		$code = sap_melipayamak_delivery( $l['rec'] );
		if ( is_wp_error( $code ) ) {
			$log[ $i ]['dlv_t'] = $code->get_error_message();
			$wait               = true;
			continue;
		}
		$lb                  = sap_melipayamak_delivery_label( $code );
		$log[ $i ]['dlv']    = $lb[0];
		$log[ $i ]['dlv_c']  = $code;
		$log[ $i ]['dlv_t']  = $lb[1];
		$wait                = $wait || 'wait' === $lb[0];
		++$done;
	}
	update_option( 'sap_notify_log', $log, false );
	if ( $wait && ! wp_next_scheduled( 'sap_sms_delivery_check' ) ) {
		wp_schedule_single_event( time() + 900, 'sap_sms_delivery_check' );
	}
	return $done;
}
add_action( 'sap_sms_delivery_check', 'sap_sms_check_deliveries' );

/**
 * Plain-text line that looks advertising/public (Melipayamak 5000… / 2000… / 9000… shared lines).
 */
function sap_sms_line_is_public() {
	$from = (string) sap_setting( 'sms_sender' );
	return 'melipayamak' === sap_setting( 'sms_provider' ) && preg_match( '/^(5000|2000|9000|3000)\d{6,}$/', $from );
}

/**
 * Melipayamak result code → a clear message.
 */
function sap_melipayamak_error( $code, $status = '' ) {
	$map = array(
		'-10' => 'متغیرهای پترن نباید لینک داشته باشند (لینک را در متن خود پترن بنویسید)',
		'-7'  => 'خطا در شماره فرستنده؛ با پشتیبانی ملی پیامک تماس بگیرید',
		'-6'  => 'خطای داخلی ملی پیامک',
		'-5'  => 'تعداد متغیرها با متن پترن نمی‌خواند (متغیرهای {0}، {1}… پترن را با راهنمای سکان مقایسه کنید)',
		'-4'  => 'کد پترن (bodyId) اشتباه است یا هنوز توسط ملی پیامک تایید نشده',
		'-3'  => 'خط خدماتی برای این پنل تعریف نشده؛ با پشتیبانی ملی پیامک تماس بگیرید',
		'-2'  => 'در ارسال با پترن هر بار فقط یک گیرنده مجاز است',
		'-1'  => 'وب‌سرویس ارسال با پترن برای پنل شما فعال نیست؛ از پشتیبانی بخواهید فعال کنند',
		'0'   => 'نام کاربری یا رمز عبور (یا کلید API) اشتباه است',
		'2'   => 'اعتبار پنل کافی نیست',
		'3'   => 'محدودیت ارسال روزانه',
		'4'   => 'محدودیت حجم ارسال',
		'5'   => 'شماره فرستنده معتبر نیست (شماره خط را از پنل ملی پیامک بردارید)',
		'6'   => 'سامانه ملی پیامک در حال به‌روزرسانی است',
		'7'   => 'متن پیامک حاوی کلمه فیلترشده است',
		'10'  => 'کاربر فعال نیست',
		'11'  => 'ارسال نشد',
		'12'  => 'مدارک کاربری پنل کامل نیست',
		'16'  => 'شماره گیرنده پیدا نشد',
		'17'  => 'متن پیامک خالی است',
		'35'  => 'شماره گیرنده در لیست سیاه مخابرات است',
		'108' => 'IP سرور به‌خاطر تلاش ناموفق مسدود شده',
		'109' => 'باید IP سرور را در پنل ملی پیامک به‌عنوان IP مجاز ثبت کنید',
		'110' => 'پنل از شما می‌خواهد به‌جای رمز عبور از کلید API استفاده کنید (کلید را در فیلد «کلید API» بگذارید)',
	);
	$c = trim( (string) $code );
	return 'ملی پیامک: ' . ( $map[ $c ] ?? ( '' !== $status ? $status : 'کد ' . $c ) );
}

/**
 * Send a message through a Telegram-compatible bot (Telegram or Bale).
 *
 * @return true|WP_Error
 */
function sap_bot_send( $channel, $text, $chat = '' ) {
	$token = (string) sap_setting( 'tg' === $channel ? 'tg_token' : 'bale_token' );
	$chat  = '' !== $chat ? $chat : (string) sap_setting( 'tg' === $channel ? 'tg_chat' : 'bale_chat' );
	if ( '' === $token || '' === $chat ) {
		return new WP_Error( 'sap_bot_off', ( 'tg' === $channel ? 'تلگرام' : 'بله' ) . ' تنظیم نشده.' );
	}
	$base = 'tg' === $channel ? 'https://api.telegram.org/bot' : 'https://tapi.bale.ai/bot';
	$res  = sap_http( $base . $token . '/sendMessage', array( 'method' => 'POST', 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true ) ) ) );
	$body = is_wp_error( $res ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $res ), true, 512, JSON_BIGINT_AS_STRING );
	$ok   = ! is_wp_error( $res ) && ! empty( $body['ok'] );
	$err  = is_wp_error( $res ) ? $res->get_error_message() : (string) ( $body['description'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
	sap_notify_log( $channel, $chat, $text, $ok, $ok ? '' : $err );
	return $ok ? true : new WP_Error( 'sap_bot', ( 'tg' === $channel ? 'تلگرام' : 'بله' ) . ': ' . $err );
}

/**
 * Is a channel configured?
 */
function sap_channel_ready( $channel ) {
	switch ( $channel ) {
		case 'email':
			return (bool) sap_setting( 'notify_email', 1 );
		case 'sms':
			return 'none' !== sap_setting( 'sms_provider', 'none' ) && '' !== sap_phone_norm( sap_setting( 'notify_manager_phone' ) );
		case 'tg':
			return '' !== (string) sap_setting( 'tg_token' ) && '' !== (string) sap_setting( 'tg_chat' );
		case 'bale':
			return '' !== (string) sap_setting( 'bale_token' ) && '' !== (string) sap_setting( 'bale_chat' );
	}
	return false;
}

/**
 * The manager's email (settings, else the site admin).
 */
function sap_manager_email() {
	$e = (string) sap_setting( 'notify_manager_email' );
	return is_email( $e ) ? $e : (string) get_option( 'admin_email' );
}

/**
 * Send one message to the manager on the given channels.
 *
 * @param string[] $channels email|sms|tg|bale.
 * @param array    $msg      subject, text (SMS/bot plain), email (sap_notify_email args).
 * @return array channel => true|WP_Error
 */
function sap_notify_manager( $channels, $msg ) {
	$out = array();
	foreach ( array_unique( (array) $channels ) as $ch ) {
		if ( ! sap_channel_ready( $ch ) ) {
			continue;
		}
		if ( 'email' === $ch ) {
			$out[ $ch ] = sap_notify_email( sap_manager_email(), $msg['subject'], $msg['email'] ?? array( 'intro' => $msg['text'] ) ) ?: new WP_Error( 'sap_mail', 'wp_mail' );
		} elseif ( 'sms' === $ch ) {
			$sms        = $msg['sms'] ?? $msg['text'];
			$out[ $ch ] = sap_sms_send( sap_setting( 'notify_manager_phone' ), $sms, 'alert', array( mb_substr( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $msg['subject'] ?? $sms ) ) ), 0, 60 ) ) );
		} else {
			$out[ $ch ] = sap_bot_send( $ch, $msg['bot'] ?? esc_html( $msg['text'] ) );
		}
	}
	return $out;
}

/**
 * Hourly heartbeat for alerts and reminders (WordPress cron).
 */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'sap_hourly' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'sap_hourly' );
	}
} );

/**
 * Test a channel from the settings screen.
 */
function sap_notify_test( $channel ) {
	$text = 'پیام آزمایشی سکان — اگر این را می‌بینید، کانال «' . ( sap_notify_channels()[ $channel ] ?? $channel ) . '» درست تنظیم شده. ' . sap_jdate( current_time( 'Y-m-d' ) );
	switch ( $channel ) {
		case 'email':
			if ( ! sap_setting( 'notify_email', 1 ) ) {
				return new WP_Error( 'sap_mail_off', 'ارسال ایمیل در تنظیمات خاموش است؛ کلید «ارسال ایمیل فعال باشد» را روشن کنید.' );
			}
			return sap_notify_email( sap_manager_email(), '✅ ایمیل سکان درست کار می‌کند — پیام آزمایشی', array( 'title' => 'تبریک! ارسال ایمیل فعال است', 'badge' => '✅ پیام آزمایشی', 'tone' => '#16A34A', 'intro' => 'اگر این ایمیل را در صندوق ورودی (نه اسپم) می‌بینید، تنظیمات SMTP سکان کامل است و از این به بعد دعوت اعضا، واگذاری کارها، یادآوری‌ها و هشدارها با همین ظاهر ارسال می‌شوند.', 'rows' => array( 'سرور' => (string) sap_setting( 'smtp_host' ), 'فرستنده' => (string) ( sap_setting( 'smtp_from' ) ?: sap_setting( 'smtp_user' ) ), 'تاریخ' => sap_jdate( current_time( 'Y-m-d' ) ) . ' ساعت ' . sap_fa( current_time( 'H:i' ) ) ), 'items' => array( '<b>واگذاری کار</b><br><small>هر کار با هدف، خروجی مطلوب، سررسید و چک‌لیست برای عضو ارسال می‌شود.</small>', '<b>یادآوری صبحگاهی</b><br><small>فهرست کارهای باز هر عضو به ترتیب اولویت.</small>', '<b>هشدارها</b><br><small>افت ترافیک، خطاهای ایندکس و مشکلات فنی برای مدیر سئو.</small>' ), 'button' => array( 'باز کردن سکان', sap_url() ) ) ) ? true : new WP_Error( 'sap_mail', 'ارسال ایمیل ناموفق بود: ' . sap_mail_error_text() );
		case 'sms':
			return sap_sms_send( sap_setting( 'notify_manager_phone' ), $text, 'test', array( sap_jdate( current_time( 'Y-m-d' ) ) ) );
		default:
			return sap_bot_send( $channel, $text );
	}
}
