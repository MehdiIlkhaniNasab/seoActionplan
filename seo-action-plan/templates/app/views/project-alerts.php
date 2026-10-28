<?php
/**
 * Alerts of the project: what needs attention now, uptime of the last week,
 * and the rules (on / off, thresholds) with the channels they reach.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid    = $project['id'];
$view   = isset( $_GET['v'] ) ? sanitize_key( $_GET['v'] ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification
$status = array( 'open' => array( 'open' ), 'ack' => array( 'ack' ), 'muted' => array( 'muted' ), 'resolved' => array( 'resolved' ) );
$list   = sap_alerts( $pid, array( 'status' => $status[ $view ] ?? array( 'open' ), 'limit' => 200 ) );
$counts = sap_alert_counts( $pid );
$sev    = sap_alert_severities();
$rules  = sap_alert_rules();
$state  = sap_alert_state( $pid );
$log    = (array) ( $state['uptime_log'] ?? array() );
$ok     = count( array_filter( $log, function ( $l ) {
	return $l[1] > 0 && $l[1] < 500;
} ) );
$uptime = $log ? round( $ok / count( $log ) * 100, 1 ) : null;
$avg_ms = $log ? (int) round( array_sum( array_column( $log, 2 ) ) / count( $log ) ) : null;
global $wpdb;
$resolved30 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . sap_table( 'alerts' ) . " WHERE project_id = %d AND status = 'resolved' AND resolved_at > %s", $pid, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
$chan       = array();
foreach ( array( 'email', 'sms', 'tg', 'bale' ) as $ch ) {
	$chan[ $ch ] = sap_channel_ready( $ch );
}
$base = sap_project_url( $pid, 'alerts' );
?>
<section class="sap-panel sap-pillar-head">
	<div class="sap-pillar-intro">
		<h2><?php echo sap_icon( 'bell' ); // phpcs:ignore ?>هشدارها و پایش ۲۴ ساعته</h2>
		<p class="sap-muted">سکان هر ساعت در دسترس بودن سایت را و هر روز ترافیک، ایندکس صفحات مهم، خطاها، robots.txt، سایت‌مپ، رتبه کلمات هدف، سرعت و تغییرات پرریسکی را که خارج از سکان روی سایت انجام می‌شود بررسی می‌کند. هر مشکل یک‌بار هشدار داده می‌شود و وقتی برطرف شد خودش بسته می‌شود.</p>
		<div class="sap-alert-channels">
			<?php foreach ( array( 'email' => 'ایمیل', 'sms' => 'پیامک', 'tg' => 'تلگرام', 'bale' => 'بله' ) as $ch => $l ) : ?>
				<span class="sap-chip <?php echo $chan[ $ch ] ? 'is-good' : 'is-soft'; ?>"><?php echo $chan[ $ch ] ? '✓ ' : ''; ?><?php echo esc_html( $l ); ?></span>
			<?php endforeach; ?>
			<a class="sap-small" href="<?php echo esc_url( sap_url( 'settings' ) . '#notify' ); ?>">تنظیم کانال‌های اعلان ←</a>
		</div>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-primary" data-sap-alerts-check="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>بررسی همین الان</button>
		</div>
	</div>
</section>

<div class="sap-alert-kpis">
	<a href="<?php echo esc_url( $base ); ?>" class="is-bad <?php echo $counts['critical'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['critical'] ) ); ?></b><span>بحرانی باز</span></a>
	<a href="<?php echo esc_url( $base ); ?>" class="is-warn <?php echo $counts['warning'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['warning'] ) ); ?></b><span>هشدار باز</span></a>
	<a href="<?php echo esc_url( $base ); ?>" class="is-info"><b><?php echo esc_html( sap_num( $counts['info'] ) ); ?></b><span>اطلاع</span></a>
	<a href="<?php echo esc_url( add_query_arg( 'v', 'resolved', $base ) ); ?>" class="is-good"><b><?php echo esc_html( sap_num( $resolved30 ) ); ?></b><span>حل‌شده در ۳۰ روز</span></a>
	<div class="sap-uptime">
		<p><b><?php echo null === $uptime ? '—' : esc_html( sap_num( $uptime, 1 ) . '٪' ); ?></b><span>در دسترس بودن ۷ روز<?php echo $avg_ms ? ' · میانگین پاسخ ' . esc_html( sap_num( $avg_ms / 1000, 2 ) ) . ' ثانیه' : ''; ?><?php echo isset( $state['ssl_days'] ) ? ' · SSL: ' . esc_html( sap_num( (int) $state['ssl_days'] ) ) . ' روز' : ''; ?></span></p>
		<div class="sap-uptime-bars" aria-hidden="true">
			<?php foreach ( array_slice( $log, -84 ) as $l ) : ?>
				<i class="<?php echo ( 0 === (int) $l[1] || (int) $l[1] >= 500 ) ? 'is-down' : ( (int) $l[2] > 4000 ? 'is-slow' : '' ); ?>" title="<?php echo esc_attr( sap_jdate( $l[0], 'short' ) . ' ' . substr( $l[0], 11, 5 ) . ' — ' . ( $l[1] ? 'HTTP ' . $l[1] : 'بدون پاسخ' ) . ' · ' . sap_num( $l[2] / 1000, 1 ) . 's' ); ?>"></i>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<nav class="sap-seg" aria-label="وضعیت">
	<?php foreach ( array( 'open' => 'باز', 'ack' => 'دیده‌شده', 'muted' => 'بی‌صدا', 'resolved' => 'حل‌شده' ) as $k => $l ) : ?>
		<a class="<?php echo $k === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( 'open' === $k ? $base : add_query_arg( 'v', $k, $base ) ); ?>"><?php echo esc_html( $l ); ?></a>
	<?php endforeach; ?>
</nav>

<section class="sap-panel">
	<?php if ( ! $list ) : ?>
		<div class="sap-empty sap-empty-good"><?php echo sap_icon( 'check', 32 ); // phpcs:ignore ?><p><?php echo 'open' === $view ? 'همه چیز آرام است؛ هشدار بازی نیست.' : 'موردی نیست.'; ?></p></div>
	<?php else : ?>
		<ul class="sap-alert-list">
			<?php foreach ( $list as $a ) : ?>
				<li class="sap-alert is-<?php echo esc_attr( $a['severity'] ); ?>" data-sap-alert="<?php echo esc_attr( $a['id'] ); ?>">
					<span class="sap-alert-ico"><?php echo sap_icon( 'critical' === $a['severity'] ? 'alert' : 'bell', 18 ); // phpcs:ignore ?></span>
					<div class="sap-alert-main">
						<p class="sap-alert-top">
							<span class="sap-chip is-<?php echo esc_attr( $sev[ $a['severity'] ][1] ); ?>"><?php echo esc_html( $sev[ $a['severity'] ][0] ); ?></span>
							<b><?php echo esc_html( $a['title'] ); ?></b>
						</p>
						<?php if ( '' !== $a['detail'] ) : ?><p class="sap-alert-detail"><?php echo implode( '<br>', array_map( function ( $l ) { return '<span dir="auto">' . esc_html( $l ) . '</span>'; }, explode( "\n", $a['detail'] ) ) ); // phpcs:ignore ?></p><?php endif; ?>
						<p class="sap-alert-meta">
							<?php if ( '' !== $a['url'] ) : ?><?php echo sap_url_tag( $a['url'], 60 ); // phpcs:ignore ?><?php endif; ?>
							<span><?php echo esc_html( $rules[ $a['rule'] ][0] ?? $a['rule'] ); ?></span>
							<span>اولین بار: <?php echo esc_html( sap_jdate( $a['first_seen'], 'short' ) . ' ' . substr( $a['first_seen'], 11, 5 ) ); ?></span>
							<?php if ( $a['last_seen'] !== $a['first_seen'] ) : ?><span>آخرین بررسی: <?php echo esc_html( sap_jdate( $a['last_seen'], 'short' ) ); ?></span><?php endif; ?>
							<?php if ( $a['notify_count'] ) : ?><span>اعلان: <?php echo esc_html( sap_num( $a['notify_count'] ) ); ?> بار</span><?php endif; ?>
							<?php if ( $a['task_id'] ) : ?><a href="<?php echo esc_url( sap_project_url( $pid, 'plan', array( 'task' => $a['task_id'] ) ) ); ?>">کار اکشن پلن ←</a><?php endif; ?>
						</p>
					</div>
					<div class="sap-alert-acts">
						<?php if ( 'resolved' === $a['status'] || 'muted' === $a['status'] ) : ?>
							<button type="button" class="sap-btn is-small is-ghost" data-sap-alert-do="open">باز کردن</button>
						<?php else : ?>
							<?php if ( ! $a['task_id'] ) : ?><button type="button" class="sap-btn is-small is-soft" data-sap-alert-do="task">افزودن به اکشن پلن</button><?php endif; ?>
							<?php if ( 'open' === $a['status'] ) : ?><button type="button" class="sap-btn is-small is-ghost" data-sap-alert-do="ack">دیدم</button><?php endif; ?>
							<button type="button" class="sap-btn is-small is-ghost is-good" data-sap-alert-do="resolve">✓ حل شد</button>
							<button type="button" class="sap-btn is-small is-ghost" data-sap-alert-do="mute" title="۷ روز اعلان نده">بی‌صدا</button>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<details class="sap-panel sap-alert-rules">
	<summary><h2><?php echo sap_icon( 'settings' ); // phpcs:ignore ?>قوانین هشدار این پروژه</h2></summary>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php sap_form_fields( 'sap_alert_rules', array( 'project_id' => $pid ) ); ?>
		<div class="sap-rule-grid">
			<?php foreach ( $rules as $rk => $r ) : ?>
				<?php $cfg = sap_alert_rule_cfg( $project, $rk ); ?>
				<div class="sap-rule">
					<label class="sap-check"><input type="checkbox" name="rules[<?php echo esc_attr( $rk ); ?>][on]" value="1" <?php checked( ! empty( $cfg['on'] ) ); ?>> <b><?php echo esc_html( $r[0] ); ?></b> <small class="sap-muted"><?php echo esc_html( 'hourly' === $r[2] ? 'هر ساعت' : ( 'sync' === $r[2] ? 'همزمان با لاگ سایت' : 'روزانه' ) ); ?></small></label>
					<p class="sap-small sap-muted"><?php echo esc_html( $r[1] ); ?></p>
					<?php
					$labels = array( 'slow' => 'آستانه کندی (ثانیه)', 'days' => 'هشدار قبل از انقضا (روز)', 'drop' => 'آستانه افت', 'page_drop' => 'افت صفحه (٪)', 'min_clicks' => 'حداقل کلیک ۷ روزه', 'min' => 'حداقل افزایش خطا', 'crawl_days' => 'کهنگی خزش (روز)' );
					foreach ( $cfg as $ck => $cv ) :
						if ( 'on' === $ck ) {
							continue;
						}
						?>
						<label class="sap-small"><?php echo esc_html( $labels[ $ck ] ?? $ck ); ?><?php echo 'drop' === $ck ? esc_html( 'ranks' === $rk ? ' (پله)' : ( 'cwv' === $rk ? ' (امتیاز)' : ' (٪)' ) ) : ''; ?><input type="number" min="0" name="rules[<?php echo esc_attr( $rk ); ?>][<?php echo esc_attr( $ck ); ?>]" value="<?php echo esc_attr( $cv ); ?>"></label>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<button class="sap-btn is-primary" type="submit">ذخیره قوانین</button>
	</form>
</details>
