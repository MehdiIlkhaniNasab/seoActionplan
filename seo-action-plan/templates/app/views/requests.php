<?php
/**
 * A member's requests: anything outside the projects (a question, leave, an
 * issue). Only full-access managers see and answer them, on their desk.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

$list = sap_user_threads( get_current_user_id(), 'request' );
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان</p>
		<h1>درخواست‌ها</h1>
		<p class="sap-muted">سوال کلی، درخواست یا موردی که به پروژه‌ها مربوط نیست را اینجا بنویسید. فقط مدیر سئو آن را می‌بیند و پاسخ می‌دهد؛ پاسخ در «اعلان‌ها» و همین‌جا می‌آید.</p>
	</div>
</header>

<section class="sap-panel sap-req-new">
	<div class="sap-panel-head"><h2><?php echo sap_icon( 'plus' ); // phpcs:ignore ?>درخواست جدید</h2></div>
	<form class="sap-form" data-sap-request-form>
		<label>موضوع<input type="text" name="title" maxlength="120" placeholder="مثلاً: مرخصی پنجشنبه، دسترسی به هاست، ایده بهبود فرایند"></label>
		<label>متن درخواست<textarea name="text" rows="4" required placeholder="کامل بنویسید تا پاسخ دقیق بگیرید."></textarea></label>
		<div class="sap-form-foot"><button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'send', 14 ); // phpcs:ignore ?>ثبت درخواست</button></div>
	</form>
</section>

<?php if ( $list ) : ?>
	<section class="sap-req-list">
		<?php foreach ( $list as $q ) : ?>
			<article class="sap-req" id="r-<?php echo esc_attr( $q['id'] ); ?>">
				<header>
					<h3><?php echo esc_html( $q['title'] ); ?></h3>
					<?php echo 'open' === $q['status'] ? '<span class="sap-chip is-warn">منتظر پاسخ</span>' : '<span class="sap-chip is-good">پاسخ داده شد</span>'; ?>
					<small class="sap-muted"><?php echo esc_html( sap_jdate( substr( $q['created_at'], 0, 10 ), 'long' ) ); ?></small>
				</header>
				<?php sap_view( 'thread', array( 'q' => $q, 'side' => 'member' ) ); ?>
			</article>
		<?php endforeach; ?>
	</section>
<?php endif; ?>
