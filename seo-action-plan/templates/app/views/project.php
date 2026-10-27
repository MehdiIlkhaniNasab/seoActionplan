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
	<ol class="sap-steps">
		<?php foreach ( sap_sync_steps() as $id => $label ) : ?>
			<li data-step="<?php echo esc_attr( $id ); ?>">
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

<nav class="sap-tabs">
	<?php foreach ( $tabs as $key => $t ) : ?>
		<a class="<?php echo $key === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $project['id'], $key ) ); ?>"><?php echo sap_icon( $t[1], 16 ); // phpcs:ignore ?><?php echo esc_html( $t[0] ); ?></a>
	<?php endforeach; ?>
</nav>

<?php sap_view( 'project-' . $tab, array( 'project' => $project ) ); ?>
