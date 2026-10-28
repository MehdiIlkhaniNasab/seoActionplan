<?php
/**
 * Cross-project learning: which kinds of work worked, across all projects
 * (anonymised: type of work × page type × measured change only).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

$l     = sap_learning( isset( $_GET['fresh'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
$types = sap_learning_page_types();
$best  = array_values( array_filter( $l['groups'], function ( $g ) {
	return $g['n'] >= 3 && $g['median'] >= 10;
} ) );
$bad   = array_values( array_filter( $l['groups'], function ( $g ) {
	return $g['n'] >= 3 && $g['median'] <= -5;
} ) );
?>
<section class="sap-panel sap-learn-head">
	<div>
		<h2><?php echo sap_icon( 'brain' ); // phpcs:ignore ?>یادگیری بین پروژه‌ها</h2>
		<p class="sap-muted">سکان از نتیجه واقعی همه اقدامات سنجیده‌شده در همه پروژه‌ها یاد می‌گیرد کدام نوع کار روی کدام نوع صفحه اثر دارد. داده‌ها قاطی نمی‌شوند: فقط «نوع کار × نوع صفحه × درصد تغییر خالص» جمع می‌شود — بدون آدرس، کلمه، نام یا عدد هیچ مشتری. این تجربه در پیشنهادهای هوش مصنوعی هر پروژه و در پیش‌بینی بازگشت سرمایه کارها استفاده می‌شود (هر پروژه فقط تجربه پروژه‌های دیگر را می‌بیند؛ نتیجه خودش جدا لحاظ می‌شود).</p>
	</div>
	<div class="sap-learn-kpis">
		<div><b><?php echo esc_html( sap_num( $l['projects'] ) ); ?></b><span>پروژه</span></div>
		<div><b><?php echo esc_html( sap_num( $l['actions'] ) ); ?></b><span>اقدام سنجیده‌شده</span></div>
		<div><b><?php echo esc_html( sap_num( count( $l['groups'] ) ) ); ?></b><span>الگو</span></div>
	</div>
</section>

<?php if ( ! $l['groups'] ) : ?>
	<div class="sap-empty"><?php echo sap_icon( 'brain', 30 ); // phpcs:ignore ?><p>هنوز اقدامی سنجیده نشده. وقتی کارهای انجام‌شده (با آدرس صفحه) در تب «ارزیابی اثر» سنجیده شوند، الگوها اینجا ساخته می‌شوند.</p></div>
<?php else : ?>
	<div class="sap-rank-grid">
		<section class="sap-panel">
			<div class="sap-panel-head"><h2>بیشترین اثر</h2></div>
			<?php if ( ! $best ) : ?><p class="sap-muted">هنوز الگوی قطعی (حداقل ۳ اقدام با میانه رشد ۱۰٪+) نیست.</p><?php endif; ?>
			<ul class="sap-learn-cards">
				<?php foreach ( array_slice( $best, 0, 6 ) as $g ) : ?>
					<li class="is-good"><b><?php echo esc_html( sap_context_label( $g['context'] ) ); ?></b><span>روی <?php echo esc_html( $types[ $g['page'] ] ?? $g['page'] ); ?></span><em>میانه <?php echo esc_html( '+' . sap_num( $g['median'] ) ); ?>٪ · <?php echo esc_html( sap_num( $g['n'] ) ); ?> اقدام در <?php echo esc_html( sap_num( $g['projects'] ) ); ?> پروژه</em></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<section class="sap-panel">
			<div class="sap-panel-head"><h2>بی‌اثر یا منفی</h2></div>
			<?php if ( ! $bad ) : ?><p class="sap-muted">الگوی منفی قطعی دیده نشده.</p><?php endif; ?>
			<ul class="sap-learn-cards">
				<?php foreach ( array_slice( $bad, 0, 6 ) as $g ) : ?>
					<li class="is-bad"><b><?php echo esc_html( sap_context_label( $g['context'] ) ); ?></b><span>روی <?php echo esc_html( $types[ $g['page'] ] ?? $g['page'] ); ?></span><em>میانه <?php echo esc_html( sap_num( $g['median'] ) ); ?>٪ · <?php echo esc_html( sap_num( $g['n'] ) ); ?> اقدام</em></li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>همه الگوها</h2><a class="sap-btn is-ghost is-small" href="<?php echo esc_url( add_query_arg( 'fresh', 1, sap_url( 'learning' ) ) ); ?>">به‌روزرسانی</a></div>
		<div class="sap-table-wrap">
			<table class="sap-table sap-learn-table">
				<thead><tr><th>نوع کار</th><th>نوع صفحه</th><th>اقدام</th><th>پروژه</th><th>نرخ موفقیت</th><th>میانه تغییر خالص کلیک</th><th>میانگین بهبود جایگاه</th></tr></thead>
				<tbody>
					<?php foreach ( $l['groups'] as $g ) : ?>
						<?php $rate = round( 100 * $g['wins'] / max( 1, $g['n'] ) ); ?>
						<tr class="<?php echo $g['n'] < 3 ? 'is-thin' : ''; ?>">
							<td><?php echo esc_html( sap_context_label( $g['context'] ) ); ?></td>
							<td><?php echo esc_html( $types[ $g['page'] ] ?? $g['page'] ); ?></td>
							<td><?php echo esc_html( sap_num( $g['n'] ) ); ?></td>
							<td><?php echo esc_html( sap_num( $g['projects'] ) ); ?></td>
							<td><span class="sap-learn-rate"><i style="width:<?php echo esc_attr( $rate ); ?>%"></i></span> <?php echo esc_html( sap_num( $rate ) ); ?>٪</td>
							<td><b class="sap-rd <?php echo $g['median'] > 0 ? 'is-up' : ( $g['median'] < 0 ? 'is-down' : '' ); ?>"><?php echo esc_html( ( $g['median'] > 0 ? '+' : '' ) . sap_num( $g['median'] ) ); ?>٪</b></td>
							<td><?php echo null === $g['pos'] ? '—' : esc_html( sap_num( $g['pos'], 1 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="sap-small sap-muted">ردیف‌های کم‌رنگ کمتر از ۳ اقدام دارند و هنوز در پیشنهادها استفاده نمی‌شوند. تغییر خالص = تغییر کلیک صفحه منهای تغییر کل سایت در همان بازه (اثر فصل و آپدیت گوگل حذف می‌شود). به‌روزرسانی: <?php echo esc_html( sap_jdate( $l['at'], 'long' ) ); ?></p>
	</section>
<?php endif; ?>
