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
function sap_notify_log( $channel, $to, $text, $ok, $err = '' ) {
	$log   = (array) get_option( 'sap_notify_log', array() );
	$log[] = array( 'at' => sap_now(), 'ch' => $channel, 'to' => mb_substr( (string) $to, 0, 60 ), 'text' => mb_substr( wp_strip_all_tags( (string) $text ), 0, 160 ), 'ok' => (bool) $ok, 'err' => mb_substr( (string) $err, 0, 200 ) );
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
	$a    = wp_parse_args( $args, array( 'title' => $subject, 'intro' => '', 'rows' => array(), 'items' => array(), 'button' => null, 'footer' => '', 'tone' => '#2563EB' ) );
	$rows = '';
	foreach ( $a['rows'] as $k => $v ) {
		$rows .= '<tr><td style="padding:6px 0;color:#64748b;width:120px;vertical-align:top">' . esc_html( $k ) . '</td><td style="padding:6px 0;color:#0f172a">' . nl2br( esc_html( (string) $v ) ) . '</td></tr>';
	}
	$items = '';
	foreach ( $a['items'] as $it ) {
		$items .= '<li style="margin:0 0 8px">' . wp_kses( (string) $it, array( 'b' => array(), 'a' => array( 'href' => array() ), 'small' => array(), 'span' => array( 'style' => array() ) ) ) . '</li>';
	}
	$btn  = $a['button'] ? '<p style="margin:22px 0 4px"><a href="' . esc_url( $a['button'][1] ) . '" style="background:' . esc_attr( $a['tone'] ) . ';color:#fff;text-decoration:none;padding:11px 22px;border-radius:10px;font-weight:700;display:inline-block">' . esc_html( $a['button'][0] ) . '</a></p>' : '';
	$html = '<!doctype html><html lang="fa" dir="rtl"><body style="margin:0;background:#f1f5f9;font-family:Tahoma,Vazirmatn,Arial,sans-serif">'
		. '<div style="max-width:600px;margin:0 auto;padding:24px 14px"><div style="background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0">'
		. '<div style="background:' . esc_attr( $a['tone'] ) . ';height:5px"></div><div style="padding:24px 26px;direction:rtl;text-align:right;line-height:1.9;font-size:14px">'
		. '<p style="margin:0 0 4px;color:#64748b;font-size:12px">سُکان — دستیار سئو</p>'
		. '<h1 style="margin:0 0 12px;font-size:19px;color:#0f172a">' . esc_html( $a['title'] ) . '</h1>'
		. ( $a['intro'] ? '<p style="margin:0 0 12px;color:#334155">' . nl2br( esc_html( $a['intro'] ) ) . '</p>' : '' )
		. ( $rows ? '<table style="width:100%;border-collapse:collapse;font-size:13.5px;margin:6px 0 10px">' . $rows . '</table>' : '' )
		. ( $items ? '<ul style="padding-right:18px;margin:8px 0;color:#0f172a">' . $items . '</ul>' : '' )
		. $btn
		. '</div></div><p style="text-align:center;color:#94a3b8;font-size:11px;margin:12px 0">' . esc_html( $a['footer'] ?: 'این پیام خودکار از سکان ارسال شده است.' ) . '</p></div></body></html>';
	$GLOBALS['sap_mail_error'] = '';
	$ok = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	sap_notify_log( 'email', implode( ', ', $to ), $subject, $ok, $ok ? '' : sap_mail_error_text() );
	return $ok;
}

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
function sap_sms_send( $phone, $text ) {
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
	$foot = trim( (string) sap_setting( 'sms_footer' ) );
	if ( '' !== $foot && false === mb_strpos( $text, $foot ) ) {
		$text .= "\n" . $foot; // E.g. «لغو11» when the panel requires it.
	}
	$fail = '';
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
					$j    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
					$fail = 1 === (int) ( $j['RetStatus'] ?? 0 ) ? '' : sap_melipayamak_error( $j['Value'] ?? ( $j['RetStatus'] ?? '' ), (string) ( $j['StrRetStatus'] ?? '' ) );
				}
			} else {
				// Console API: the key from console.melipayamak.com › web service.
				$res = wp_remote_post( 'https://console.melipayamak.com/api/send/simple/' . rawurlencode( $key ), array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'from' => $from, 'to' => $phone, 'text' => $text ) ) ) );
				if ( ! is_wp_error( $res ) ) {
					$j    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
					$fail = ! empty( $j['recId'] ) && (float) $j['recId'] > 1000 ? '' : sap_melipayamak_error( empty( $j['status'] ) ? ( $j['recId'] ?? '' ) : '', (string) ( $j['status'] ?? '' ) );
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
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 'kavenegar' === $provider && isset( $j['return']['status'] ) && 200 !== (int) $j['return']['status'] ) {
			$fail = (string) ( $j['return']['message'] ?? 'خطای کاوه‌نگار' );
		} elseif ( 'smsir' === $provider && isset( $j['status'] ) && 1 !== (int) $j['status'] ) {
			$fail = (string) ( $j['message'] ?? 'خطای SMS.ir' );
		}
	}
	$ok   = ! is_wp_error( $res ) && $code >= 200 && $code < 300 && '' === $fail;
	$err  = is_wp_error( $res ) ? $res->get_error_message() : ( $ok ? '' : ( '' !== $fail ? $fail : 'HTTP ' . $code . ': ' . mb_substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $res ) ), 0, 160 ) ) );
	sap_notify_log( 'sms', $phone, $text, $ok, $err );
	return $ok ? true : new WP_Error( 'sap_sms', 'ارسال پیامک ناموفق بود: ' . $err );
}

/**
 * Melipayamak result code → a clear message.
 */
function sap_melipayamak_error( $code, $status = '' ) {
	$map = array(
		'0'   => 'نام کاربری یا رمز عبور (یا کلید API) اشتباه است',
		'2'   => 'اعتبار پنل کافی نیست',
		'3'   => 'محدودیت ارسال روزانه',
		'4'   => 'محدودیت حجم ارسال',
		'5'   => 'شماره فرستنده معتبر نیست (شماره خط را از پنل ملی پیامک بردارید)',
		'7'   => 'متن پیامک حاوی کلمه فیلترشده است',
		'10'  => 'کاربر فعال نیست',
		'11'  => 'ارسال نشد',
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
	$body = is_wp_error( $res ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $res ), true );
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
			$out[ $ch ] = sap_sms_send( sap_setting( 'notify_manager_phone' ), $msg['sms'] ?? $msg['text'] );
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
			return sap_notify_email( sap_manager_email(), 'پیام آزمایشی سکان', array( 'title' => 'پیام آزمایشی', 'intro' => $text ) ) ? true : new WP_Error( 'sap_mail', 'ارسال ایمیل ناموفق بود: ' . sap_mail_error_text() );
		case 'sms':
			return sap_sms_send( sap_setting( 'notify_manager_phone' ), $text );
		default:
			return sap_bot_send( $channel, $text );
	}
}
