<?php
/**
 * Quick focus: one-click suggestions + a 3-field form.
 *
 * @package SeoActionPlan
 * @var array  $project
 * @var string $return
 */

defined( 'ABSPATH' ) || exit;

$pid         = $project['id'];
$suggestions = sap_focus_suggestions( $project );
$clusters    = array_values( array_unique( array_filter( array_column( (array) ( sap_dataset_payload( $pid, 'sheet_clusters' )['rows'] ?? array() ), 'cluster' ) ) ) );
$post        = esc_url( admin_url( 'admin-post.php' ) );
?>
<?php if ( $suggestions ) : ?>
	<p class="sap-label">پیشنهاد از روی داده‌های پروژه — با یک کلیک اضافه کنید:</p>
	<div class="sap-suggest">
		<?php foreach ( $suggestions as $s ) : ?>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
				<?php sap_form_fields( 'sap_quick_focus', array( 'project_id' => $pid, 'name' => $s['name'], 'url_patterns' => $s['url_patterns'], 'clusters' => $s['clusters'], 'keywords' => $s['keywords'], 'kpi_metric' => 'clicks', '_return' => $return ) ); ?>
				<button type="submit" class="sap-suggest-item">
					<?php echo sap_icon( 'target', 16 ); // phpcs:ignore ?>
					<span><b><?php echo esc_html( $s['name'] ); ?></b><small><?php echo esc_html( $s['reason'] ); ?></small></span>
					<i><?php echo sap_icon( 'plus', 14 ); // phpcs:ignore ?></i>
				</button>
			</form>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<details class="sap-inline-form" <?php echo $suggestions ? '' : 'open'; ?>>
	<summary><?php echo sap_icon( 'plus', 16 ); // phpcs:ignore ?>Focus دلخواه</summary>
	<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
		<?php sap_form_fields( 'sap_quick_focus', array( 'project_id' => $pid, '_return' => $return ) ); ?>
		<div class="sap-grid-2">
			<label>روی چه بخشی تمرکز دارید؟<input type="text" name="name" required placeholder="مثلاً: اسکوتر برقی"></label>
			<label>آدرس بخش (اختیاری)<input type="text" name="url_patterns" dir="ltr" placeholder="/product-category/electric-scooter/"></label>
		</div>
		<?php if ( $clusters ) : ?>
			<p class="sap-label">کلاسترهای مرتبط در شیت</p>
			<?php sap_chip_pick( 'cluster_pick', array_combine( $clusters, $clusters ) ); ?>
		<?php endif; ?>
		<div class="sap-grid-2">
			<div>
				<p class="sap-label">هدف را با چه چیزی بسنجیم؟</p>
				<?php sap_segment( 'kpi_metric', array( 'clicks' => 'کلیک', 'top10' => 'کلمات صفحه اول', 'impressions' => 'نمایش' ), 'clicks' ); ?>
			</div>
			<label>عدد هدف (اختیاری)<input type="number" name="kpi_target" min="0" step="any" placeholder="مثلاً ۱۵۰۰"><small>مقدار فعلی خودکار به‌عنوان نقطه شروع ثبت می‌شود.</small></label>
		</div>
		<button class="sap-btn is-primary" type="submit">افزودن Focus</button>
	</form>
</details>
