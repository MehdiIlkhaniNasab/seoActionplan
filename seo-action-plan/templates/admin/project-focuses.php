<?php
/**
 * Focuses («زاویه دید») and goals.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$focuses  = sap_focuses( $pid );
$clusters = array_values( array_unique( array_filter( array_column( (array) ( sap_dataset_payload( $pid, 'sheet_clusters' )['rows'] ?? array() ), 'cluster' ) ) ) );
$post     = esc_url( admin_url( 'admin-post.php' ) );

/**
 * Focus form fields.
 */
$fields = function ( $f ) use ( $clusters ) {
	?>
	<div class="sap-grid-3">
		<label>نام Focus<input type="text" name="name" required value="<?php echo esc_attr( $f['name'] ?? '' ); ?>" placeholder="اسکوتر برقی"></label>
		<label>وضعیت
			<select name="status">
				<option value="active" <?php selected( $f['status'] ?? 'active', 'active' ); ?>>فعال</option>
				<option value="paused" <?php selected( $f['status'] ?? '', 'paused' ); ?>>متوقف</option>
				<option value="done" <?php selected( $f['status'] ?? '', 'done' ); ?>>به هدف رسید</option>
			</select>
		</label>
		<label>وزن اولویت (۱ تا ۳)<input type="number" name="weight" min="1" max="3" step="0.1" value="<?php echo esc_attr( $f['weight'] ?? 1.5 ); ?>">
			<small>یافته‌های این Focus با این ضریب اولویت بیشتری می‌گیرند.</small>
		</label>
	</div>
	<div class="sap-grid-3">
		<label>الگوی آدرس‌ها (هر خط یکی)<textarea name="url_patterns" rows="3" dir="ltr" placeholder="/product-category/electric-scooter/&#10;/blog/scooter-*"><?php echo esc_textarea( implode( "\n", $f['url_patterns'] ?? array() ) ); ?></textarea>
			<small>پیشوند مسیر؛ * برای هر عبارت.</small>
		</label>
		<label>کلاسترهای شیت کلاسترینگ<textarea name="clusters" rows="3" placeholder="اسکوتر برقی"><?php echo esc_textarea( implode( "\n", $f['clusters'] ?? array() ) ); ?></textarea>
			<small><?php echo $clusters ? 'کلاسترهای موجود در شیت: ' . esc_html( implode( '، ', array_slice( $clusters, 0, 15 ) ) ) : 'بعد از همگام‌سازی شیت، نام کلاسترها اینجا پیشنهاد می‌شود.'; ?></small>
		</label>
		<label>کلمات کلیدی اضافه (اولی = کلمه اصلی)<textarea name="keywords" rows="3" placeholder="اسکوتر برقی&#10;خرید اسکوتر برقی"><?php echo esc_textarea( implode( "\n", $f['keywords'] ?? array() ) ); ?></textarea>
			<small>کلمه اول برای دریافت سوالات کاربران (FAQ) از سئو سیگنال استفاده می‌شود.</small>
		</label>
	</div>
	<label>هدف (به زبان ساده)<input type="text" name="goal" value="<?php echo esc_attr( $f['goal'] ?? '' ); ?>" placeholder="افزایش ۳۰٪ کلیک ارگانیک دسته اسکوتر برقی تا پایان دی"></label>
	<div class="sap-grid-3">
		<label>شاخص اندازه‌گیری
			<select name="kpi_metric">
				<?php foreach ( sap_kpi_metrics() as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['kpi_metric'] ?? 'clicks', $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>نقطه شروع (Baseline)<input type="number" step="any" name="kpi_baseline" value="<?php echo esc_attr( $f['kpi_baseline'] ?? '' ); ?>" placeholder="خالی = مقدار فعلی"></label>
		<label>مقدار هدف<input type="number" step="any" name="kpi_target" value="<?php echo esc_attr( ( $f['kpi_target'] ?? 0 ) ?: '' ); ?>"></label>
		<label>مهلت<input type="text" name="deadline" value="<?php echo esc_attr( ! empty( $f['deadline'] ) ? sap_jdate( $f['deadline'], 'short' ) : '' ); ?>" placeholder="۱۴۰۵/۱۰/۳۰"></label>
	</div>
	<?php
};
?>
<section class="sap-panel sap-intro">
	<p><b>Focus (زاویه دید)</b> بخشی از سایت است که پروژه روی آن تمرکز دارد؛ مثلاً اول کار فقط دسته «اسکوتر برقی». تحلیل همیشه روی کل سایت انجام می‌شود، اما یافته‌های داخل Focus امتیاز بیشتری می‌گیرند، ایرادهای کل سایت که روی Focus اثر دارند جدا نمایش داده می‌شوند، و اکشن پلن ماهانه در راستای هدف Focus چیده می‌شود.</p>
</section>

<?php
foreach ( $focuses as $f ) :
	$k = sap_focus_kpis( $f, $pid );
	?>
	<details class="sap-panel sap-focus-edit">
		<summary>
			<b><?php echo esc_html( $f['name'] ); ?></b>
			<span class="sap-muted"><?php echo esc_html( 'active' === $f['status'] ? 'فعال' : ( 'paused' === $f['status'] ? 'متوقف' : 'به هدف رسید' ) ); ?></span>
			<?php if ( $k ) : ?>
				<span class="sap-muted"><?php echo esc_html( sprintf( '%s: %s', sap_kpi_metrics()[ $f['kpi_metric'] ], sap_num( $k[ $f['kpi_metric'] ] ) ) ); ?></span>
			<?php endif; ?>
		</summary>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
			<?php sap_form_fields( 'sap_save_focus', array( 'project_id' => $pid, 'focus_id' => $f['id'] ) ); ?>
			<?php $fields( $f ); ?>
			<div class="sap-form-foot">
				<button class="sap-btn is-primary" type="submit">ذخیره</button>
			</div>
		</form>
		<div class="sap-actions">
			<?php if ( $k ) : ?>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
					<?php sap_form_fields( 'sap_focus_baseline', array( 'project_id' => $pid, 'focus_id' => $f['id'] ) ); ?>
					<button class="sap-btn is-small" type="submit">ثبت مقدار فعلی (<?php echo esc_html( sap_num( $k[ $f['kpi_metric'] ] ) ); ?>) به‌عنوان نقطه شروع</button>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>" onsubmit="return confirm('Focus حذف شود؟')">
				<?php sap_form_fields( 'sap_delete_focus', array( 'project_id' => $pid, 'focus_id' => $f['id'] ) ); ?>
				<button class="sap-btn is-small is-ghost is-danger" type="submit">حذف Focus</button>
			</form>
		</div>
	</details>
<?php endforeach; ?>

<details class="sap-panel" <?php echo $focuses ? '' : 'open'; ?>>
	<summary><?php echo sap_icon( 'plus' ); // phpcs:ignore ?>Focus جدید</summary>
	<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
		<?php sap_form_fields( 'sap_save_focus', array( 'project_id' => $pid, 'focus_id' => 0 ) ); ?>
		<?php $fields( array() ); ?>
		<div class="sap-form-foot">
			<button class="sap-btn is-primary" type="submit">افزودن Focus</button>
		</div>
	</form>
</details>
