<?php
/**
 * App login screen (posts to wp-login.php, comes back to the requested route).
 *
 * @package SeoActionPlan
 * @var string $redirect
 */

defined( 'ABSPATH' ) || exit;

$err = isset( $_GET['sap_login'] ) ? sanitize_key( $_GET['sap_login'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$to  = remove_query_arg( 'sap_login', $redirect );
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>ورود · اکشن پلن سئو</title>
	<?php sap_app_assets(); ?>
</head>
<body class="sap-app sap-auth">
	<form class="sap-login" method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>">
		<span class="sap-brand-mark is-lg"><?php echo sap_icon( 'target', 28 ); // phpcs:ignore ?></span>
		<h1>اکشن پلن سئو</h1>
		<p class="sap-muted">برای ورود به داشبورد، با حساب وردپرس خود وارد شوید.</p>
		<?php if ( 'failed' === $err ) : ?>
			<div class="sap-flash is-err">نام کاربری یا رمز عبور اشتباه است.</div>
		<?php elseif ( 'empty' === $err ) : ?>
			<div class="sap-flash is-err">نام کاربری و رمز عبور را وارد کنید.</div>
		<?php endif; ?>
		<label>نام کاربری یا ایمیل<input type="text" name="log" autocomplete="username" dir="ltr" required autofocus></label>
		<label>رمز عبور<input type="password" name="pwd" autocomplete="current-password" dir="ltr" required></label>
		<label class="sap-check"><input type="checkbox" name="rememberme" value="forever" checked> مرا به خاطر بسپار</label>
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $to ); ?>">
		<input type="hidden" name="sap_login" value="1">
		<button class="sap-btn is-primary is-block" type="submit">ورود</button>
		<a class="sap-muted sap-small" href="<?php echo esc_url( wp_lostpassword_url( $to ) ); ?>">فراموشی رمز عبور</a>
	</form>
</body>
</html>
