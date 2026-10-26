<?php
/**
 * Logged in, but without access to the tool.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>دسترسی ندارید · سُکان</title>
	<?php sap_app_assets(); ?>
</head>
<body class="sap-app sap-auth">
	<div class="sap-login">
		<span class="sap-brand-mark is-lg"><?php echo sap_icon( 'alert', 28 ); // phpcs:ignore ?></span>
		<h1>دسترسی ندارید</h1>
		<p class="sap-muted">حساب شما به سُکان دسترسی ندارد. از مدیر بخواهید نقش «عضو تیم سئو» را به شما بدهد.</p>
		<a class="sap-btn is-block" href="<?php echo esc_url( wp_logout_url( sap_url() ) ); ?>">خروج و ورود با حساب دیگر</a>
	</div>
</body>
</html>
