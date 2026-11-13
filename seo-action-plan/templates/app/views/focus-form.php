<?php
/**
 * Focus form in three steps: which pages (categories with their products, single
 * pages, or advanced URL patterns / keywords), how it is measured, and the goal
 * (start date, target, deadline). A live counter shows how many pages and how
 * many clicks the chosen set has right now.
 *
 * @package SeoActionPlan
 * @var array      $project
 * @var array|null $f       Focus being edited (null = new).
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$f        = $f ?? null;
$cats     = sap_focus_categories( $pid );
$types    = array( 'product_cat' => 'دسته محصول', 'brand' => 'برند', 'post_cat' => 'دسته مقاله' );
$chosen   = $f ? $f['scope']['cats'] : array();
$chosen_n = array();
foreach ( $chosen as $u => $w ) {
	$chosen_n[ sap_norm_url( $u ) ] = $w;
}
$inv      = sap_site_inventory( $pid );
$clusters = array_values( array_unique( array_filter( array_column( (array) ( sap_dataset_payload( $pid, 'sheet_clusters' )['rows'] ?? array() ), 'cluster' ) ) ) );
$uid      = 'ff' . ( $f ? $f['id'] : 'new' );
$advanced = $f && ( $f['url_patterns'] || $f['clusters'] || $f['keywords'] );
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sap-form sap-ff" data-sap-ff="<?php echo esc_attr( $pid ); ?>">
	<?php sap_form_fields( 'sap_save_focus', array( 'project_id' => $pid, 'focus_id' => $f ? $f['id'] : 0 ) ); ?>

	<div class="sap-ff-step">
		<div class="sap-ff-n">۱</div>
		<div class="sap-ff-body">
			<h3>کدام صفحه‌ها؟</h3>
			<p class="sap-muted sap-small">دسته‌ها را تیک بزنید؛ با «+ محصولات» همه محصولات آن دسته (و زیردسته‌هایش) هم حساب می‌شوند. صفحه یا محصول خاص را هم با جستجو اضافه کنید.</p>
			<?php if ( $cats ) : ?>
				<input type="search" class="sap-ff-filter" data-sap-ff-filter placeholder="جستجو در دسته‌ها…">
				<div class="sap-ff-cats" data-sap-ff-cats>
					<?php foreach ( $cats as $c ) : ?>
						<?php $k = sap_norm_url( $c['url'] ); $on = isset( $chosen_n[ $k ] ); ?>
						<div class="sap-ff-cat<?php echo $c['parent'] ? ' is-sub' : ''; ?>" data-name="<?php echo esc_attr( sap_norm_text( $c['title'] . ' ' . $c['parent'] ) ); ?>">
							<label class="sap-ff-cat-main"><input type="checkbox" name="scope_cats[]" value="<?php echo esc_attr( $c['url'] ); ?>" <?php checked( $on ); ?>><span><b><?php echo esc_html( $c['title'] ); ?></b><small><?php echo esc_html( $types[ $c['ctype'] ] ?? '' ); ?><?php echo $c['parent'] ? ' · زیر «' . esc_html( $c['parent'] ) . '»' : ''; ?><?php echo $c['count'] ? ' · ' . esc_html( sap_num( $c['count'] ) ) . ' مورد' : ''; ?></small></span></label>
							<?php if ( 'post_cat' !== $c['ctype'] ) : ?>
								<label class="sap-ff-withp" title="محصولات این دسته هم جزو Focus باشند"><input type="checkbox" name="scope_withp[]" value="<?php echo esc_attr( $c['url'] ); ?>" <?php checked( ! empty( $chosen_n[ $k ] ) ); ?>>+ محصولات</label>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="sap-alert is-warn sap-small">فهرست دسته‌ها از وردپرس هنوز دریافت نشده (کانکتور را وصل و «همگام‌سازی محتوای سایت» را اجرا کنید). فعلاً با جستجوی صفحه یا الگوی آدرس کار کنید.</p>
			<?php endif; ?>

			<div class="sap-ff-search">
				<input type="search" data-sap-ff-q placeholder="افزودن محصول یا صفحه: نام یا آدرس را بنویسید…" autocomplete="off">
				<div class="sap-ff-results" data-sap-ff-results hidden></div>
			</div>
			<div class="sap-ff-pages" data-sap-ff-pages>
				<?php foreach ( $f ? $f['scope']['pages'] : array() as $u ) : ?>
					<?php $it = $inv[ sap_norm_url( $u ) ] ?? null; ?>
					<span class="sap-ff-page"><input type="hidden" name="scope_pages[]" value="<?php echo esc_attr( $u ); ?>"><?php echo esc_html( $it ? $it['title'] : sap_short_url( $u, 50 ) ); ?><button type="button" data-sap-ff-rm aria-label="حذف">×</button></span>
				<?php endforeach; ?>
			</div>

			<details class="sap-ff-adv" <?php echo $advanced ? 'open' : ''; ?>>
				<summary>پیشرفته: الگوی آدرس، کلاستر و کلمات</summary>
				<div class="sap-grid-3">
					<label>الگوی آدرس‌ها (هر خط یکی)<textarea name="url_patterns" rows="2" dir="ltr" data-sap-tags data-sap-ff-pat placeholder="/product-category/electric-scooter/&#10;/blog/scooter-*"><?php echo esc_textarea( implode( "\n", $f['url_patterns'] ?? array() ) ); ?></textarea><small>پیشوند مسیر؛ * برای هر عبارت.</small></label>
					<label>کلاسترهای شیت کلاسترینگ<textarea name="clusters" rows="2" data-sap-tags><?php echo esc_textarea( implode( "\n", $f['clusters'] ?? array() ) ); ?></textarea><small><?php echo $clusters ? 'موجود: ' . esc_html( implode( '، ', array_slice( $clusters, 0, 10 ) ) ) : 'برای سنجش کلمات صفحه اول و رتبه.'; ?></small></label>
					<label>کلمات کلیدی (اولی = کلمه اصلی)<textarea name="keywords" rows="2" data-sap-tags><?php echo esc_textarea( implode( "\n", $f['keywords'] ?? array() ) ); ?></textarea><small>برای شاخص «صفحه اول» و «رتبه ۱ تا ۳».</small></label>
				</div>
			</details>
			<p class="sap-ff-live" data-sap-ff-live><span class="sap-muted">صفحه‌ها را انتخاب کنید تا تعداد و کلیک فعلی‌شان اینجا دیده شود.</span></p>
		</div>
	</div>

	<div class="sap-ff-step">
		<div class="sap-ff-n">۲</div>
		<div class="sap-ff-body">
			<h3>با چه چیزی بسنجیم؟</h3>
			<?php sap_segment( 'kpi_metric', array( 'clicks' => 'کلیک (۲۸ روز)', 'impressions' => 'نمایش (۲۸ روز)', 'top10' => 'کلمات صفحه اول', 'top3' => 'کلمات رتبه ۱ تا ۳' ), $f['kpi_metric'] ?? 'clicks' ); ?>
			<p class="sap-muted sap-small">کلیک و نمایش دقیقاً همان عددی است که سرچ کنسول برای همین صفحه‌ها در ۲۸ روز نشان می‌دهد. دو شاخص کلمات از «کلمات کلیدی» بالا حساب می‌شوند.</p>
		</div>
	</div>

	<div class="sap-ff-step">
		<div class="sap-ff-n">۳</div>
		<div class="sap-ff-body">
			<h3>هدف</h3>
			<div class="sap-grid-3">
				<label>نام Focus<input type="text" name="name" required value="<?php echo esc_attr( $f['name'] ?? '' ); ?>" placeholder="مثلاً: جیر و نبوک"></label>
				<label>تاریخ شروع<input type="date" name="started_on" value="<?php echo esc_attr( $f['started_on'] ?? current_time( 'Y-m-d' ) ); ?>"><small>نقطه شروع = ۲۸ روزِ قبل از این تاریخ.</small></label>
				<label>مهلت<input type="date" name="deadline" value="<?php echo esc_attr( $f['deadline'] ?? gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +3 months' ) ) ); ?>"></label>
			</div>
			<div class="sap-grid-3">
				<label>مقدار هدف (۲۸ روز در پایان مهلت)<input type="number" step="any" min="0" name="kpi_target" data-sap-ff-target value="<?php echo esc_attr( ( $f['kpi_target'] ?? 0 ) ?: '' ); ?>"><small data-sap-ff-hint></small></label>
				<label>نقطه شروع دستی (اختیاری)<input type="number" step="any" min="0" name="kpi_baseline" value="<?php echo esc_attr( ( $f['kpi_baseline'] ?? 0 ) ?: '' ); ?>" placeholder="خالی = خودکار از سرچ کنسول"></label>
				<label>وزن اولویت (۱ تا ۳)<input type="number" name="weight" min="1" max="3" step="0.1" value="<?php echo esc_attr( $f['weight'] ?? 1.5 ); ?>"></label>
			</div>
			<label>هدف به زبان ساده<input type="text" name="goal" value="<?php echo esc_attr( $f['goal'] ?? '' ); ?>" placeholder="کلیک دسته جیر و نبوک و محصولاتش از ۱٬۴۰۰ به ۲٬۱۰۰ در ۳ ماه"></label>
			<?php if ( $f ) : ?>
				<div><p class="sap-label">وضعیت</p><?php sap_segment( 'status', array( 'active' => 'فعال', 'paused' => 'متوقف', 'done' => 'به هدف رسید' ), $f['status'] ); ?></div>
			<?php else : ?>
				<input type="hidden" name="status" value="active">
			<?php endif; ?>
		</div>
	</div>
	<div class="sap-form-foot"><button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'check', 16 ); // phpcs:ignore ?><?php echo $f ? 'ذخیره و سنجش دوباره' : 'ساخت Focus'; ?></button></div>
</form>
