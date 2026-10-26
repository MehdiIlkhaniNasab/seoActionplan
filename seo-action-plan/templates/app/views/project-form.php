<?php
/**
 * New / edit project form: two required fields, the rest under «تنظیمات بیشتر».
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
			<p class="sap-eyebrow"><a href="<?php echo esc_url( sap_url() ); ?>">داشبورد</a></p>
			<h1>پروژه جدید</h1>
			<p class="sap-muted">نام و آدرس سایت کافی است؛ پراپرتی سرچ کنسول خودکار پیدا می‌شود.</p>
		</div>
	</header>
<?php endif; ?>

<form class="sap-panel sap-form sap-project-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php sap_form_fields( 'sap_save_project', array( 'project_id' => $p['id'] ) ); ?>
	<?php if ( ! $new ) : ?>
		<h2>اتصال‌های پروژه</h2>
	<?php endif; ?>
	<div class="sap-grid-2">
		<label>نام پروژه / کارفرما<input type="text" name="name" required value="<?php echo esc_attr( $p['name'] ); ?>" placeholder="فروشگاه اسکوتر"></label>
		<label>آدرس سایت<input type="url" name="site_url" dir="ltr" required value="<?php echo esc_attr( $p['site_url'] ); ?>" placeholder="https://example.com/" data-sap-site></label>
	</div>
	<div class="sap-grid-2">
		<label>پراپرتی سرچ کنسول
			<select name="gsc_property" dir="ltr" data-sap-gsc-select data-current="<?php echo esc_attr( $p['gsc_property'] ); ?>">
				<option value="<?php echo esc_attr( $p['gsc_property'] ); ?>"><?php echo $p['gsc_property'] ? esc_html( $p['gsc_property'] ) : 'خودکار از روی آدرس سایت'; ?></option>
			</select>
			<small><?php echo sap_google_connected() ? 'فهرست پراپرتی‌های حساب گوگل متصل.' : 'اول حساب گوگل را در تنظیمات متصل کنید.'; ?></small>
		</label>
		<label>لینک گوگل شیت پروژه (اختیاری)<input type="text" name="sheet_id" dir="ltr" value="<?php echo esc_attr( $p['sheet_id'] ? 'https://docs.google.com/spreadsheets/d/' . $p['sheet_id'] . '/' : '' ); ?>" placeholder="https://docs.google.com/spreadsheets/d/…">
			<small>شیت با دو تب «تقویم محتوا» و «کلاسترینگ» — <a href="<?php echo esc_url( sap_url( 'guide' ) ); ?>" target="_blank">ساختار استاندارد</a></small>
		</label>
	</div>

	<details class="sap-more" <?php echo $new ? '' : 'open'; ?>>
		<summary>تنظیمات بیشتر <small>سئو سیگنال، رقبا، تب‌های شیت، ظرفیت ماه</small></summary>
		<div class="sap-grid-3">
			<label>پروژه ردیاب رتبه سئو سیگنال
				<span class="sap-inline">
					<input type="number" name="ss_project_id" dir="ltr" value="<?php echo esc_attr( $p['ss_project_id'] ?: '' ); ?>" placeholder="شناسه">
					<button type="button" class="sap-btn is-small" data-sap-load-ss>انتخاب</button>
				</span>
				<small data-sap-ss-list></small>
			</label>
			<div><p class="sap-label">دستگاه ردیاب رتبه</p><?php sap_segment( 'ss_device', array( 'mobile' => 'موبایل', 'desktop' => 'دسکتاپ' ), $p['ss_device'] ); ?></div>
			<label>ظرفیت کاری ماه (ساعت)<input type="number" name="capacity_hours" min="1" step="1" value="<?php echo esc_attr( (int) $p['capacity_hours'] ); ?>"></label>
		</div>
		<div class="sap-grid-2">
			<label>رقبا (حداکثر ۳ دامنه)<textarea name="competitors" rows="2" dir="ltr" data-sap-tags placeholder="competitor.com"><?php echo esc_textarea( implode( "\n", $p['competitors'] ) ); ?></textarea>
				<small>هر رقیب ماهی ۲ واحد سهمیه سئو سیگنال مصرف می‌کند.</small>
			</label>
			<label>کلمات برند<textarea name="brand_terms" rows="2" data-sap-tags placeholder="نام برند"><?php echo esc_textarea( implode( "\n", $p['brand_terms'] ) ); ?></textarea>
				<small>کوئری‌های برند از تحلیل فرصت‌ها جدا می‌شوند.</small>
			</label>
		</div>
		<div class="sap-grid-2">
			<label>نام تب تقویم محتوا<input type="text" name="sheet_calendar_tab" value="<?php echo esc_attr( $p['sheet_calendar_tab'] ); ?>"></label>
			<label>نام تب کلاسترینگ<input type="text" name="sheet_cluster_tab" value="<?php echo esc_attr( $p['sheet_cluster_tab'] ); ?>"></label>
		</div>
		<?php sap_switch( 'writeback', empty( $p['options']['no_writeback'] ), 'نوشتن ستون‌های خودکار در شیت', 'حجم جستجو، جایگاه، کلیک و وضعیت پوشش هر کلمه بعد از هر تحلیل در شیت نوشته می‌شود.' ); ?>
	</details>

	<div class="sap-form-foot">
		<button class="sap-btn is-primary is-lg" type="submit"><?php echo $new ? 'ساخت پروژه' : 'ذخیره'; ?></button>
	</div>
</form>
