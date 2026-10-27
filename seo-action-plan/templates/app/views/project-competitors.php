<?php
/**
 * Competitors of the project: saved list, SEO Signal gap data and content
 * ideas from what competitors rank for (never what the site already targets
 * or ranks 1–5 for).
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$comps = (array) $project['competitors'];
$cmp   = (array) ( sap_dataset_payload( $pid, 'ss_compare' )['competitors'] ?? array() );
$off   = sap_offpage_competitor_summary( (array) sap_dataset_payload( $pid, 'offpage_data' ) );
$pool  = sap_dataset_payload( $pid, 'ideas_pool' );
$from_comp = $pool ? count( array_filter( (array) $pool['blog'], function ( $t ) {
	return in_array( 'competitor', (array) $t['sources'], true );
} ) ) : 0;
?>
<section class="sap-panel">
	<div class="sap-panel-head">
		<h2><?php echo sap_icon( 'target' ); // phpcs:ignore ?>رقبای پروژه<small>همیشه ذخیره می‌شوند؛ در مقایسه سایت، ایده محتوا، ردیاب رتبه و برنامه آف‌پیج استفاده می‌شوند.</small></h2>
	</div>
	<form class="sap-comp-add" data-sap-comp-add="<?php echo esc_attr( $pid ); ?>">
		<label class="sap-field"><span>دامنه رقیب</span><input type="text" name="domain" dir="ltr" placeholder="rival.ir" required></label>
		<button type="submit" class="sap-btn is-primary">افزودن رقیب</button>
	</form>
	<?php if ( ! $comps ) : ?>
		<p class="sap-muted">هنوز رقیبی ثبت نشده. ۳ تا ۵ رقیب اصلی که در کلمات Focus شما رتبه دارند را اضافه کنید.</p>
	<?php else : ?>
		<div class="sap-table-wrap">
			<table class="sap-table">
				<thead><tr><th>رقیب</th><th>گپ کلمات</th><th>کلمات بهتر از ما</th><th>آخرین مقایسه</th><th>رپورتاژ ۹۰ روز</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $comps as $c ) : ?>
						<?php $d = $cmp[ $c ] ?? null; ?>
						<tr>
							<td dir="ltr"><a href="https://<?php echo esc_attr( $c ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $c ); ?></a></td>
							<td><?php echo $d ? esc_html( sap_num( count( (array) $d['gap'] ) ) ) : '—'; ?></td>
							<td><?php echo $d ? esc_html( sap_num( count( (array) $d['worse'] ) ) ) : '—'; ?></td>
							<td class="sap-nowrap"><?php echo $d && ! empty( $d['at'] ) ? esc_html( sap_jdate( gmdate( 'Y-m-d', (int) $d['at'] ), 'short' ) ) : 'انجام نشده'; ?></td>
							<td><?php echo isset( $off[ $c ] ) ? esc_html( sap_num( $off[ $c ]['count'] ) . ' رپورتاژ' . ( $off[ $c ]['spend'] ? ' · ' . sap_num( $off[ $c ]['spend'] ) . ' تومان' : '' ) ) : '—'; ?></td>
							<td><button type="button" class="sap-icon-btn" data-sap-comp-remove="<?php echo esc_attr( $c ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" title="حذف رقیب"><?php echo sap_icon( 'trash', 16 ); // phpcs:ignore ?></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-soft" data-sap-step="ss_compare" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ss_connected() ); ?>>مقایسه با رقبا در سئو سیگنال (<?php echo esc_html( sap_num( 4 * min( 3, count( $comps ) ) ) ); ?> واحد؛ کش ۲۵ روزه)</button>
			<button type="button" class="sap-btn is-primary" data-sap-ideas-gen="<?php echo esc_attr( $pid ); ?>" data-source="competitors" <?php disabled( ! sap_ai_connected() || ! $cmp ); ?>><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>ایده مقاله از گپ رقبا</button>
		</div>
		<p class="sap-small sap-muted">ایده‌ها فقط از کلماتی ساخته می‌شوند که رقبا رتبه دارند، سایت شما در آن‌ها رتبه ۱ تا ۵ ندارد، در نقشه کلمات/تقویم هدف‌گیری نشده‌اند و نیتشان اطلاعاتی یا مقایسه‌ای است؛ کلمات خرید به‌صورت «فرصت صفحه دسته/محصول» در پیشنهادهای محتوا می‌آیند.<?php echo $pool ? ' الان ' . esc_html( sap_num( $from_comp ) ) . ' موضوع مقاله از گپ رقبا آماده است.' : ''; ?></p>
	<?php endif; ?>
</section>
