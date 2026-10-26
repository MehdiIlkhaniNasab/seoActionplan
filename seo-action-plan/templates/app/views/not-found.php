<?php
/**
 * Unknown project / route.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sap-empty">
	<?php echo sap_icon( 'alert', 32 ); // phpcs:ignore ?>
	<h2>صفحه یا پروژه پیدا نشد</h2>
	<a class="sap-btn" href="<?php echo esc_url( sap_url() ); ?>">بازگشت به داشبورد</a>
</div>
