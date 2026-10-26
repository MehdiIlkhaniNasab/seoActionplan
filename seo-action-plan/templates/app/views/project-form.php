<?php
/**
 * New / edit project form.
 *
 * @package SeoActionPlan
 * @var array|null $project
 */

defined( 'ABSPATH' ) || exit;

$p   = $project ?: array(
	'id' => 0, 'name' => '', 'site_url' => '', 'gsc_property' => '', 'sheet_id' => '', 'sheet_calendar_tab' => 'تقویم محتوا',
	'sheet_cluster_tab' => 'کلاسترینگ', 'ss_project_id' => 0, 'ss_device' => 'mobile', 'competitors' => array(), 'brand_terms' => array(),
	'capacity_hours' => 40, 'options' => array(),
);
$new = ! $p['id'];
?>
<?php if ( $new ) : ?>
	<header class="sap-head">
		<div>
			<p class="sap-eyebrow"><a href="<?php echo esc_url( sap_url() ); ?>">پروژه‌ها</a></p>
			<h1>پروژه جدید</h1>
			<p class="sap-muted">هر پروژه یک سایت کارفرماست. بعد از ساخت، Focus (زاویه دید) و هدف آن را تعریف می‌کنید.</p>
		</div>
	</header>
<?php endif; ?>

<form class="sap-panel sap-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php sap_form_fields( 'sap_save_project', array( 'project_id' => $p['id'] ) ); ?>
	<h2>مشخصات پروژه</h2>
	<div class="sap-grid-2">
		<label>نام پروژه / کارفرما<input type="text" name="name" required value="<?php echo esc_attr( $p['name'] ); ?>" placeholder="فروشگاه اسکوتر"></label>
		<label>آدرس سایت<input type="url" name="site_url" dir="ltr" value="<?php echo esc_attr( $p['site_url'] ); ?>" placeholder="https://example.com/"></label>
	</div>

	<h2>Google Search Console</h2>
	<div class="sap-grid-2">
		<label>پراپرتی سرچ کنسول
			<span class="sap-inline">
				<input type="text" name="gsc_property" dir="ltr" list="sap-gsc-sites" value="<?php echo esc_attr( $p['gsc_property'] ); ?>" placeholder="sc-domain:example.com">
				<button type="button" class="sap-btn is-small" data-sap-load-gsc>دریافت فهرست</button>
			</span>
			<datalist id="sap-gsc-sites"></datalist>
			<small>حساب گوگل متصل باید به این پراپرتی دسترسی داشته باشد.</small>
		</label>
		<label>کلمات برند (برای جدا کردن کوئری‌های برند)<input type="text" name="brand_terms" value="<?php echo esc_attr( implode( '، ', $p['brand_terms'] ) ); ?>" placeholder="اسکوتریار، scooteryar"></label>
	</div>

	<h2>Google Sheets</h2>
	<div class="sap-grid-3">
		<label>لینک یا شناسه گوگل شیت<input type="text" name="sheet_id" dir="ltr" value="<?php echo esc_attr( $p['sheet_id'] ); ?>" placeholder="https://docs.google.com/spreadsheets/d/…"></label>
		<label>نام تب تقویم محتوا<input type="text" name="sheet_calendar_tab" value="<?php echo esc_attr( $p['sheet_calendar_tab'] ); ?>"></label>
		<label>نام تب کلاسترینگ<input type="text" name="sheet_cluster_tab" value="<?php echo esc_attr( $p['sheet_cluster_tab'] ); ?>"></label>
	</div>
	<label class="sap-check"><input type="checkbox" name="writeback" value="1" <?php checked( empty( $p['options']['no_writeback'] ) ); ?>> بعد از هر تحلیل، ستون‌های خودکار (حجم، جایگاه، کلیک، وضعیت پوشش) در شیت نوشته شود</label>
	<p class="sap-muted">ساختار استاندارد ستون‌ها و فایل نمونه را در <a href="<?php echo esc_url( sap_url( 'guide' ) ); ?>">راهنما</a> ببینید. شیت را با حساب گوگل متصل به‌اشتراک بگذارید (دسترسی Editor برای نوشتن ستون‌های خودکار).</p>

	<h2>سئو سیگنال</h2>
	<div class="sap-grid-3">
		<label>پروژه ردیاب رتبه
			<span class="sap-inline">
				<input type="number" name="ss_project_id" dir="ltr" value="<?php echo esc_attr( $p['ss_project_id'] ?: '' ); ?>" placeholder="37113">
				<button type="button" class="sap-btn is-small" data-sap-load-ss>فهرست پروژه‌ها</button>
			</span>
			<small data-sap-ss-list></small>
		</label>
		<label>دستگاه ردیاب رتبه
			<select name="ss_device">
				<option value="mobile" <?php selected( $p['ss_device'], 'mobile' ); ?>>موبایل</option>
				<option value="desktop" <?php selected( $p['ss_device'], 'desktop' ); ?>>دسکتاپ</option>
			</select>
		</label>
		<label>رقبا (هر خط یک دامنه، حداکثر ۳)<textarea name="competitors" rows="3" dir="ltr" placeholder="competitor1.com&#10;competitor2.ir"><?php echo esc_textarea( implode( "\n", $p['competitors'] ) ); ?></textarea>
			<small>هر رقیب ماهی ۲ واحد سهمیه (گپ + رتبه بهتر) مصرف می‌کند.</small>
		</label>
	</div>

	<h2>ظرفیت ماهانه</h2>
	<div class="sap-grid-3">
		<label>ساعت کاری قابل‌برنامه‌ریزی در ماه<input type="number" name="capacity_hours" min="1" step="1" value="<?php echo esc_attr( (int) $p['capacity_hours'] ); ?>">
			<small>اکشن پلن خودکار تا همین سقف کار برمی‌دارد.</small>
		</label>
	</div>

	<div class="sap-form-foot">
		<button class="sap-btn is-primary" type="submit"><?php echo $new ? 'ساخت پروژه' : 'ذخیره تنظیمات'; ?></button>
	</div>
</form>
