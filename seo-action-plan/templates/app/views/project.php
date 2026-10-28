<?php
/**
 * Project screen: header, sync panel, tabs.
 *
 * @package SeoActionPlan
 * @var array  $project
 * @var string $tab
 * @var array  $tabs
 */

defined( 'ABSPATH' ) || exit;

$log = (array) ( $project['options']['sync_log'] ?? array() );
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow"><a href="<?php echo esc_url( sap_url() ); ?>">داشبورد</a> / <span dir="ltr"><?php echo esc_html( sap_project_domain( $project ) ); ?></span></p>
		<h1><?php echo esc_html( $project['name'] ); ?></h1>
		<p class="sap-muted">
			<?php
			$last = $project['options']['last_sync'] ?? '';
			echo $last ? 'آخرین تحلیل: ' . esc_html( sap_jdate( $last ) . ' ' . sap_fa( substr( $last, 11, 5 ) ) ) : 'هنوز تحلیل نشده';
			?>
		</p>
	</div>
	<div class="sap-actions">
		<div class="sap-mode" title="حالت ساده فقط بخش‌های روزمره را نشان می‌دهد">
			<a class="<?php echo 'simple' === sap_ui_mode() ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'sap_mode', 'simple' ) ); ?>">ساده</a>
			<a class="<?php echo 'pro' === sap_ui_mode() ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'sap_mode', 'pro' ) ); ?>">حرفه‌ای</a>
		</div>
		<button type="button" class="sap-btn is-primary" data-sap-sync="<?php echo esc_attr( $project['id'] ); ?>"><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تحلیل کامل</button>
		<button type="button" class="sap-btn" data-sap-sync="<?php echo esc_attr( $project['id'] ); ?>" data-force="1" title="داده‌های سئو سیگنال را هم با وجود کش تازه دوباره می‌گیرد (مصرف سهمیه)"><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>بدون کش</button>
	</div>
</header>

<section class="sap-panel sap-sync" data-sap-sync-panel hidden>
	<h2><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تحلیل کامل پروژه</h2>
	<label class="sap-check sap-sync-ask"><input type="checkbox" data-sap-sync-ask checked> قبل از هر بخش سئو سیگنال از من بپرس (با نمایش مصرف سهمیه)</label>
	<ol class="sap-steps">
		<?php foreach ( sap_sync_steps() as $id => $label ) : ?>
			<li data-step="<?php echo esc_attr( $id ); ?>"<?php echo in_array( $id, sap_ss_ask_steps(), true ) ? ' data-ask="1"' : ''; ?>>
				<span class="sap-step-dot"></span>
				<b><?php echo esc_html( $label ); ?></b>
				<small data-msg><?php echo isset( $log[ $id ] ) ? esc_html( 'آخرین اجرا: ' . $log[ $id ]['msg'] ) : ''; ?></small>
			</li>
		<?php endforeach; ?>
	</ol>
	<div class="sap-sync-foot" data-sap-sync-done hidden>
		<a class="sap-btn is-primary" href="<?php echo esc_url( sap_project_url( $project['id'] ) ); ?>">مشاهده نتیجه تحلیل</a>
		<a class="sap-btn" href="<?php echo esc_url( sap_project_url( $project['id'], $tab ) ); ?>">بارگذاری دوباره صفحه</a>
	</div>
</section>

<?php $ac = sap_alert_counts( $project['id'] ); ?>
<?php if ( $ac['critical'] && 'alerts' !== $tab ) : ?>
	<a class="sap-alertbar" href="<?php echo esc_url( sap_project_url( $project['id'], 'alerts' ) ); ?>"><?php echo sap_icon( 'bell', 16 ); // phpcs:ignore ?><b><?php echo esc_html( sap_num( $ac['critical'] ) ); ?> هشدار بحرانی باز</b><span><?php echo esc_html( sap_alerts( $project['id'], array( 'status' => 'open', 'severity' => 'critical', 'limit' => 1 ) )[0]['title'] ?? '' ); ?></span><em>مشاهده ←</em></a>
<?php endif; ?>
<?php
$cur_group = sap_tab_group( $tab );
$all_tabs  = sap_project_tabs_all();
?>
<nav class="sap-tabs is-groups">
	<?php foreach ( sap_project_groups() as $g => $def ) : ?>
		<?php
		$subs = array_values( array_filter( $def[2], function ( $k ) use ( $tabs ) {
			return isset( $tabs[ $k ] );
		} ) );
		if ( ! $subs ) {
			continue;
		}
		?>
		<?php $pending = isset( sap_pillars()[ $g ] ) ? count( sap_proposals( $project['id'], $g, array( 'limit' => 500 ) ) ) : 0; ?>
		<?php
		if ( 'home' === $g ) {
			$ac      = sap_alert_counts( $project['id'] );
			$pending = $ac['critical'] + $ac['warning'];
		}
		?>
		<a class="<?php echo $g === $cur_group ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $project['id'], $g === $cur_group ? $tab : $subs[0] ) ); ?>"><?php echo sap_icon( $def[1], 16 ); // phpcs:ignore ?><?php echo esc_html( $def[0] ); ?><?php echo $pending ? '<em class="sap-tab-count" title="پیشنهاد در انتظار تأیید">' . esc_html( sap_num( $pending ) ) . '</em>' : ''; // phpcs:ignore ?></a>
	<?php endforeach; ?>
	<a class="sap-tab-gear <?php echo 'settings' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $project['id'], 'settings' ) ); ?>" title="تنظیمات پروژه"><?php echo sap_icon( 'settings', 16 ); // phpcs:ignore ?><span>تنظیمات</span></a>
</nav>
<?php
$group_def = sap_project_groups()[ $cur_group ] ?? null;
$subs      = $group_def ? array_values( array_filter( $group_def[2], function ( $k ) use ( $tabs, $tab ) {
	return isset( $tabs[ $k ] ) || $k === $tab;
} ) ) : array();
?>
<?php if ( count( $subs ) > 1 ) : ?>
	<nav class="sap-subtabs">
		<?php foreach ( $subs as $k ) : ?>
			<a class="<?php echo $k === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $project['id'], $k ) ); ?>"><?php echo sap_icon( $all_tabs[ $k ][1], 14 ); // phpcs:ignore ?><?php echo esc_html( $all_tabs[ $k ][0] ); ?></a>
		<?php endforeach; ?>
	</nav>
<?php endif; ?>

<?php sap_view( 'project-' . $tab, array( 'project' => $project ) ); ?>
