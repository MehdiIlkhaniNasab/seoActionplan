<?php
/**
 * App document: sidebar + main area.
 *
 * @package SeoActionPlan
 * @var string     $view
 * @var string     $title
 * @var string     $nav
 * @var array      $vars
 * @var array|null $project
 */

defined( 'ABSPATH' ) || exit;

$user     = wp_get_current_user();
$manager  = sap_can();
$projects = $manager ? sap_projects() : array();
$my_open  = count( sap_tasks( array( 'assignee' => $user->ID, 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) ) );
$cur_pid  = $project ? $project['id'] : 0;
$link     = function ( $key, $url, $icon, $label, $badge = 0 ) use ( $nav ) {
	printf(
		'<a class="%s" href="%s">%s<span>%s</span>%s</a>',
		$key === $nav ? 'is-active' : '',
		esc_url( $url ),
		sap_icon( $icon, 18 ), // phpcs:ignore
		esc_html( $label ),
		$badge ? '<em>' . esc_html( sap_num( $badge ) ) . '</em>' : ''
	);
};
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title . ' · سُکان' ); ?></title>
	<?php sap_app_assets(); ?>
</head>
<body class="sap-app view-<?php echo esc_attr( $view ); ?>">
<div class="sap-shell">
	<aside class="sap-side" id="sap-side">
		<a class="sap-brand" href="<?php echo esc_url( sap_url() ); ?>">
			<span class="sap-brand-mark"><?php echo sap_icon( 'helm', 24 ); // phpcs:ignore ?></span>
			<span><b>سُکان</b><small>هدایت هوشمند سئو · رونق</small></span>
		</a>
		<nav class="sap-nav">
			<?php
			if ( $manager ) {
				$link( 'dashboard', sap_url(), 'grid', 'داشبورد' );
			}
			$link( 'my', sap_url( 'my' ), 'check', 'کارهای من', $my_open );
			?>
			<?php if ( $manager ) : ?>
				<p class="sap-nav-h">پروژه‌ها</p>
				<?php
				foreach ( $projects as $p ) :
					$open = sap_findings_counts( $p['id'] )['total'];
					?>
					<a class="sap-nav-project <?php echo $cur_pid === $p['id'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $p['id'] ) ); ?>">
						<i class="sap-dot"></i><span><?php echo esc_html( $p['name'] ); ?></span><?php echo $open ? '<em>' . esc_html( sap_num( $open ) ) . '</em>' : ''; ?>
					</a>
				<?php endforeach; ?>
				<a class="sap-nav-add <?php echo 'new' === $nav ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_url( 'new' ) ); ?>"><?php echo sap_icon( 'plus', 16 ); // phpcs:ignore ?><span>پروژه جدید</span></a>
				<p class="sap-nav-h">ابزار</p>
				<?php $link( 'team', sap_url( 'team' ), 'users', 'تیم و اعضا' ); ?>
				<?php $link( 'settings', sap_url( 'settings' ), 'settings', 'تنظیمات و اتصال‌ها' ); ?>
			<?php endif; ?>
			<?php $link( 'guide', sap_url( 'guide' ), 'log', 'راهنما و ساختار شیت' ); ?>
		</nav>
		<div class="sap-side-foot">
			<span class="sap-user"><i><?php echo esc_html( mb_substr( $user->display_name, 0, 1 ) ); ?></i><?php echo esc_html( $user->display_name ); ?></span>
			<span class="sap-side-links">
				<?php if ( current_user_can( 'edit_posts' ) ) : ?>
					<a href="<?php echo esc_url( admin_url() ); ?>" title="پیشخوان وردپرس">وردپرس</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( wp_logout_url( sap_url() ) ); ?>">خروج</a>
			</span>
		</div>
	</aside>
	<div class="sap-backdrop" data-sap-close-nav></div>

	<main class="sap-main">
		<div class="sap-topbar">
			<button type="button" class="sap-menu-btn" data-sap-open-nav aria-label="منو"><?php echo sap_icon( 'list', 22 ); // phpcs:ignore ?></button>
			<a class="sap-topbar-brand" href="<?php echo esc_url( sap_url() ); ?>">سُکان</a>
		</div>
		<div class="sap-wrap">
			<?php
			sap_flash();
			sap_view( $view, $vars );
			?>
		</div>
	</main>
</div>
</body>
</html>
