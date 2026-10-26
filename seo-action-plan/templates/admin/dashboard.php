<?php
/**
 * Projects dashboard.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

$projects = sap_projects();
$period   = sap_period_current();
$usage    = sap_ss_usage();
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">اکشن پلن سئو · <?php echo esc_html( sap_period_label( $period ) ); ?></p>
		<h1>پروژه‌ها</h1>
		<p class="sap-muted">تحلیل داده‌ها، کشف ایرادها و فرصت‌ها، اکشن پلن ماهانه و لاگ اقدامات هر پروژه.</p>
	</div>
	<div class="sap-actions">
		<a class="sap-btn is-primary" href="<?php echo esc_url( sap_admin_url( 'sap', array( 'new' => 1 ) ) ); ?>"><?php echo sap_icon( 'plus' ); // phpcs:ignore ?>پروژه جدید</a>
	</div>
</header>

<div class="sap-status-row">
	<span class="sap-conn <?php echo sap_google_connected() ? 'is-on' : ''; ?>"><i></i>گوگل <?php echo sap_google_connected() ? ( sap_google_has_sheets() ? '(سرچ کنسول + شیت)' : '(بدون دسترسی شیت)' ) : 'متصل نیست'; ?></span>
	<span class="sap-conn <?php echo sap_ss_connected() ? 'is-on' : ''; ?>"><i></i>سئو سیگنال <?php echo sap_ss_connected() ? sprintf( '— مصرف امروز %s از %s', esc_html( sap_num( $usage['used'] ) ), esc_html( sap_num( $usage['cap'] ) ) ) : 'متصل نیست'; ?></span>
	<?php if ( ! sap_google_connected() || ! sap_ss_connected() ) : ?>
		<a href="<?php echo esc_url( sap_admin_url( 'sap-settings' ) ); ?>">اتصال‌ها ←</a>
	<?php endif; ?>
</div>

<?php if ( ! $projects ) : ?>
	<div class="sap-empty">
		<?php echo sap_icon( 'target', 36 ); // phpcs:ignore ?>
		<h2>هنوز پروژه‌ای ندارید</h2>
		<p>اول در «تنظیمات و اتصال‌ها» حساب گوگل و کلید سئو سیگنال را وصل کنید، بعد پروژه بسازید و Focus آن را تعریف کنید.</p>
		<a class="sap-btn is-primary" href="<?php echo esc_url( sap_admin_url( 'sap', array( 'new' => 1 ) ) ); ?>">ساخت اولین پروژه</a>
	</div>
<?php else : ?>
	<div class="sap-cards">
		<?php
		foreach ( $projects as $p ) :
			$counts = sap_findings_counts( $p['id'] );
			$tasks  = sap_tasks( array( 'project_id' => $p['id'], 'period' => $period ) );
			$done   = count( array_filter( $tasks, function ( $t ) {
				return 'done' === $t['status'];
			} ) );
			$live   = count( array_filter( $tasks, function ( $t ) {
				return 'dropped' !== $t['status'];
			} ) );
			$fcs    = sap_focuses( $p['id'], true );
			$last   = $p['options']['last_sync'] ?? '';
			?>
			<a class="sap-card is-link" href="<?php echo esc_url( sap_project_url( $p['id'] ) ); ?>">
				<div class="sap-card-top">
					<h3><?php echo esc_html( $p['name'] ); ?></h3>
					<small dir="ltr"><?php echo esc_html( sap_project_domain( $p ) ); ?></small>
				</div>
				<?php if ( $fcs ) : ?>
					<p class="sap-card-focus"><?php echo sap_icon( 'target', 14 ); // phpcs:ignore ?><?php echo esc_html( implode( '، ', wp_list_pluck( $fcs, 'name' ) ) ); ?></p>
				<?php endif; ?>
				<div class="sap-mini-stats">
					<span><b><?php echo esc_html( sap_num( $counts['total'] ) ); ?></b>یافته باز</span>
					<span><b><?php echo esc_html( sap_num( $done ) . '/' . sap_num( $live ) ); ?></b>کار این ماه</span>
				</div>
				<?php if ( $live ) : ?>
					<div class="sap-progress"><i style="width:<?php echo esc_attr( round( 100 * $done / max( 1, $live ) ) ); ?>%"></i></div>
				<?php endif; ?>
				<small class="sap-muted"><?php echo $last ? 'آخرین همگام‌سازی: ' . esc_html( sap_jdate( $last ) ) : 'هنوز همگام‌سازی نشده'; ?></small>
			</a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
