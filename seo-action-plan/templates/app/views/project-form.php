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
$new  = ! $p['id'];
$kind = (string) ( $p['options']['kind'] ?? 'seo' );
// phpcs:ignore WordPress.Security.NonceVerification
if ( $new && isset( $_GET['kind'] ) && 'ops' === $_GET['kind'] ) {
	$kind = 'ops';
}
?>
<?php if ( $new ) : ?>
	<header class="sap-head">
		<div>
			<p class="sap-eyebrow"><a href="<?php echo esc_url( sap_url() ); ?>">داشبورد</a></p>
			<h1>پروژه جدید</h1>
			<p class="sap-muted">پروژه سئو: نام و آدرس سایت کافی است. پروژه اجرایی (بارگذاری محصول، طراحی سایت، غرفه…): فقط نام؛ کارها را مدیریت و گزارش می‌کنید.</p>
		</div>
	</header>
<?php endif; ?>

<form class="sap-panel sap-form sap-project-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php sap_form_fields( 'sap_save_project', array( 'project_id' => $p['id'] ) ); ?>
	<?php if ( ! $new ) : ?>
		<h2>اتصال‌های پروژه</h2>
	<?php endif; ?>
	<div class="sap-kind" data-sap-kind>
		<label class="sap-kind-opt"><input type="radio" name="kind" value="seo" <?php checked( 'seo', $kind ); ?>><span><b>🔎 پروژه سئو</b><small>سرچ کنسول، تحلیل کامل، اکشن پلن هوشمند، رتبه و گزارش سئو</small></span></label>
		<label class="sap-kind-opt"><input type="radio" name="kind" value="ops" <?php checked( 'ops', $kind ); ?>><span><b>🧩 پروژه اجرایی</b><small>فقط مدیریت کار، پیگیری و گزارش؛ بدون سرچ کنسول و تحلیل (بارگذاری محصول، طراحی سایت، غرفه، فرایند خرید…)</small></span></label>
	</div>
	<div class="sap-grid-2">
		<label>نام پروژه / کارفرما<input type="text" name="name" required value="<?php echo esc_attr( $p['name'] ); ?>" placeholder="فروشگاه اسکوتر"></label>
		<label>آدرس سایت<span class="sap-ops-only sap-muted"> (اختیاری)</span><input type="url" name="site_url" dir="ltr" <?php echo 'seo' === $kind ? 'required' : ''; ?> value="<?php echo esc_attr( $p['site_url'] ); ?>" placeholder="https://example.com/" data-sap-site></label>
	</div>
	<div class="sap-ops-fields" data-sap-ops-only <?php echo 'ops' === $kind ? '' : 'hidden'; ?>>
		<div class="sap-grid-3">
			<label>نوع پروژه<select name="ops_type"><?php foreach ( sap_ops_types() as $ok => $ol ) : ?><option value="<?php echo esc_attr( $ok ); ?>" <?php selected( $ok, (string) ( $p['options']['ops_type'] ?? '' ) ); ?>><?php echo esc_html( $ol ); ?></option><?php endforeach; ?></select></label>
			<label>تاریخ شروع پروژه<input type="date" name="ops_start" value="<?php echo esc_attr( (string) ( $p['options']['ops_start'] ?? '' ) ); ?>"></label>
			<label>تاریخ تحویل / پایان<input type="date" name="ops_end" value="<?php echo esc_attr( (string) ( $p['options']['ops_end'] ?? '' ) ); ?>"></label>
		</div>
		<?php if ( $new ) : ?>
			<label>کارهای اولیه از قالب (اختیاری)<select name="ops_template">
				<option value="">— بدون قالب؛ کارها را خودم تعریف می‌کنم —</option>
				<?php foreach ( sap_ops_templates() as $tk => $tv ) : ?><option value="<?php echo esc_attr( $tk ); ?>"><?php echo esc_html( $tv['label'] . ' (' . sap_num( count( $tv['tasks'] ) ) . ' کار با چک‌لیست)' ); ?></option><?php endforeach; ?>
			</select><small>کارها با چک‌لیست ساخته می‌شوند و همه قابل ویرایش‌اند؛ فقط مسئول و ددلاین را تعیین کنید.</small></label>
		<?php endif; ?>
	</div>
	<div data-sap-seo-only <?php echo 'ops' === $kind ? 'hidden' : ''; ?>>
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

	<label>نام برند (فارسی و انگلیسی)<textarea name="brand_terms" rows="1" data-sap-tags placeholder="مثلاً: شهر واکس"><?php echo esc_textarea( implode( "\n", $p['brand_terms'] ) ); ?></textarea>
		<small>جستجوهای برند (مثل «شهر واکس» که صفحه اصلی و فروشگاه را نشان می‌دهد) فرصت یا کنیبالیزیشن حساب نمی‌شوند.</small>
	</label>
	</div>

	<details class="sap-more" data-sap-seo-only <?php echo $new ? '' : 'open'; ?> <?php echo 'ops' === $kind ? 'hidden' : ''; ?>>
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
			<label>ارزش هر کلیک ارگانیک (تومان)<input type="number" name="click_value" min="0" step="100" value="<?php echo esc_attr( (int) ( $p['options']['click_value'] ?? 0 ) ?: '' ); ?>" placeholder="مثلاً ۵۰۰۰">
				<small>برای پیش‌بینی بازگشت سرمایه هر کار. ساده: (نرخ تبدیل × میانگین سود هر سفارش). خالی = فقط تعداد کلیک.</small>
			</label>
			<label>هزینه هر ساعت کار (تومان)<input type="number" name="hourly_cost" min="0" step="1000" value="<?php echo esc_attr( (int) ( $p['options']['hourly_cost'] ?? 0 ) ?: '' ); ?>" placeholder="مثلاً ۴۰۰۰۰۰">
				<small>هزینه ساعت تیم برای محاسبه ROI؛ خالی = بدون محاسبه ROI.</small>
			</label>
		</div>
		<div class="sap-grid-2">
			<label>رقبا (حداکثر ۳ دامنه)<textarea name="competitors" rows="2" dir="ltr" data-sap-tags placeholder="competitor.com"><?php echo esc_textarea( implode( "\n", $p['competitors'] ) ); ?></textarea>
				<small>هر رقیب ماهی ۲ واحد سهمیه سئو سیگنال مصرف می‌کند.</small>
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
