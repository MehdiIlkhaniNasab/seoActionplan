<?php
/**
 * Activity log of a month.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$pid    = $project['id'];
$period = sap_period_sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ?? '' ) ) );
$cat    = isset( $_GET['category'] ) && array_key_exists( sanitize_key( $_GET['category'] ), sap_contexts() ) ? sanitize_key( $_GET['category'] ) : '';
// phpcs:enable
list( $start, $end ) = sap_period_range( $period );
$entries = sap_activity_list( $pid, $start, $end, $cat );
$focuses = sap_focuses( $pid );
$fnames  = wp_list_pluck( $focuses, 'name', 'id' );
$by_cat  = array();
foreach ( sap_activity_list( $pid, $start, $end ) as $e ) {
	$by_cat[ $e['category'] ] = ( $by_cat[ $e['category'] ] ?? 0 ) + 1;
}
$here    = sap_project_url( $pid, 'activity', array_filter( array( 'period' => $period, 'category' => $cat ), 'strlen' ) );
$csv     = wp_nonce_url( add_query_arg( array( 'action' => 'sap_activity_csv', 'project' => $pid, 'period' => $period ), admin_url( 'admin-post.php' ) ), 'sap_activity_csv', '_sap_nonce' );
$sources = sap_activity_sources();
?>
<div class="sap-toolbar">
	<?php $fbase = sap_project_url( $pid, 'activity' ); ?>
	<form method="get" class="sap-filters" action="<?php echo esc_url( strtok( $fbase, '?' ) ); ?>">
		<?php sap_filter_form( $fbase ); ?>
		<select name="period" onchange="this.form.submit()">
			<?php foreach ( sap_period_options( 12, 0 ) as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="category" onchange="this.form.submit()">
			<option value="">همه حوزه‌ها</option>
			<?php foreach ( sap_contexts() as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cat, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</form>
	<div class="sap-actions">
		<a class="sap-btn" href="<?php echo esc_url( $csv ); ?>"><?php echo sap_icon( 'download' ); // phpcs:ignore ?>خروجی CSV</a>
	</div>
</div>

<?php if ( $by_cat ) : ?>
	<div class="sap-chips">
		<?php foreach ( $by_cat as $c => $n ) : ?>
			<span class="sap-chip ctx-<?php echo esc_attr( $c ); ?>"><?php echo esc_html( sap_context_label( $c ) . ' · ' . sap_num( $n ) ); ?></span>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<details class="sap-panel sap-capture">
	<summary><?php echo sap_icon( 'plus' ); // phpcs:ignore ?>ثبت دستی اقدام</summary>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sap-form">
		<?php sap_form_fields( 'sap_activity_add', array( 'project_id' => $pid, '_return' => $here ) ); ?>
		<div class="sap-grid-3">
			<label class="is-wide">اقدام<input type="text" name="title" required placeholder="مثلاً: تغییر تایتل صفحه دسته اسکوتر برقی"></label>
			<label>حوزه<select name="category"><?php foreach ( sap_contexts() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label>تاریخ<input type="text" name="date" value="<?php echo esc_attr( sap_jdate( current_time( 'Y-m-d' ), 'short' ) ); ?>"></label>
			<label>Focus<select name="focus_id"><option value="0">—</option><?php foreach ( $focuses as $f ) : ?><option value="<?php echo esc_attr( $f['id'] ); ?>"><?php echo esc_html( $f['name'] ); ?></option><?php endforeach; ?></select></label>
			<label class="is-wide">آدرس صفحه<input type="url" name="url" dir="ltr"></label>
		</div>
		<div class="sap-grid-2">
			<label>قبل<textarea name="before_value" rows="2" placeholder="مقدار قبلی (مثلاً تایتل قبلی)"></textarea></label>
			<label>بعد<textarea name="after_value" rows="2" placeholder="مقدار جدید / توضیح نتیجه"></textarea></label>
		</div>
		<button class="sap-btn is-primary" type="submit">ثبت در لاگ</button>
	</form>
</details>

<section class="sap-panel">
	<div class="sap-panel-head">
		<h2>اقدامات <?php echo esc_html( sap_period_label( $period ) ); ?><small><?php echo esc_html( sap_num( count( $entries ) ) ); ?> مورد</small></h2>
	</div>
	<?php if ( ! $entries ) : ?>
		<p class="sap-muted">در این ماه اقدامی ثبت نشده. کارهای انجام‌شده اکشن پلن، محتوای منتشرشده در تقویم محتوا (شیت) و خزش‌ها خودکار اینجا ثبت می‌شوند.</p>
	<?php else : ?>
		<div class="sap-table-wrap">
			<table class="sap-table">
				<thead><tr><th>تاریخ</th><th>حوزه</th><th>اقدام</th><th>قبل ← بعد</th><th>منبع</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $entries as $e ) : ?>
						<tr>
							<td class="sap-nowrap"><?php echo esc_html( sap_jdate( $e['happened_at'], 'short' ) ); ?></td>
							<td><?php echo sap_context_chip( $e['category'] ); // phpcs:ignore ?></td>
							<td>
								<b><?php echo esc_html( $e['title'] ); ?></b>
								<?php if ( $e['focus_id'] && isset( $fnames[ $e['focus_id'] ] ) ) : ?>
									<span class="sap-chip is-focus"><?php echo esc_html( $fnames[ $e['focus_id'] ] ); ?></span>
								<?php endif; ?>
								<?php if ( $e['url'] ) : ?>
									<br><span class="sap-small"><?php echo sap_page_link( $e['url'], sap_page_title( $project['id'], $e['url'] ) ); // phpcs:ignore ?></span>
								<?php endif; ?>
							</td>
							<td class="sap-small">
								<?php if ( $e['before_value'] ) : ?>
									<span class="sap-before"><?php echo esc_html( $e['before_value'] ); ?></span> ←
								<?php endif; ?>
								<?php echo esc_html( $e['after_value'] ); ?>
							</td>
							<td class="sap-small sap-muted"><?php echo esc_html( $sources[ $e['source'] ] ?? $e['source'] ); ?><?php echo $e['user_id'] ? '<br>' . esc_html( sap_user_name( $e['user_id'] ) ) : ''; ?></td>
							<td>
								<?php if ( 'task' !== $e['source'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('این ردیف حذف شود؟')">
										<?php sap_form_fields( 'sap_activity_delete', array( 'project_id' => $pid, 'activity_id' => $e['id'], '_return' => $here ) ); ?>
										<button class="sap-icon-btn" title="حذف"><?php echo sap_icon( 'trash', 14 ); // phpcs:ignore ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
	<p class="sap-muted sap-small">این لاگ از طریق API گزارش (<code dir="ltr">/wp-json/seoactionplan/v1/report/<?php echo esc_html( $pid ); ?>?period=<?php echo esc_html( $period ); ?></code>) در بخش «اقدامات انجام‌شده» گزارش SeoReport قابل استفاده است. جزئیات در تب تنظیمات پروژه.</p>
</section>
