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
				<?php $link( 'learning', sap_url( 'learning' ), 'brain', 'یادگیری بین پروژه‌ها' ); ?>
				<?php $link( 'settings', sap_url( 'settings' ), 'settings', 'تنظیمات و اتصال‌ها' ); ?>
			<?php endif; ?>
			<?php $link( 'guide', sap_url( 'guide' ), 'log', 'راهنما و ساختار شیت' ); ?>
		</nav>
		<div class="sap-side-foot">
			<button type="button" class="sap-user" data-sap-profile-open title="ویرایش پروفایل"><?php echo sap_avatar( $user->ID, 30 ); // phpcs:ignore ?><span><?php echo esc_html( $user->display_name ); ?></span></button>
			<span class="sap-side-links">
				<?php if ( current_user_can( 'edit_posts' ) ) : ?>
					<a href="<?php echo esc_url( admin_url() ); ?>" title="پیشخوان وردپرس">وردپرس</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( wp_logout_url( sap_url() ) ); ?>">خروج</a>
			</span>
		</div>
	</aside>
	<div class="sap-backdrop" data-sap-close-nav></div>
	<?php $u_phone = (string) get_user_meta( $user->ID, 'sap_phone', true ); ?>
	<dialog class="sap-profile" data-sap-profile>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="sap-form">
			<?php sap_form_fields( 'sap_profile_save', array( '_return' => ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ) ) ); // phpcs:ignore ?>
			<header class="sap-profile-head">
				<h2>پروفایل من</h2>
				<button type="button" class="sap-icon-btn" data-sap-profile-close aria-label="بستن"><?php echo sap_icon( 'x', 18 ); // phpcs:ignore ?></button>
			</header>
			<div class="sap-profile-photo">
				<span class="sap-profile-preview" data-sap-avatar-preview><?php echo sap_avatar( $user->ID, 84 ); // phpcs:ignore ?></span>
				<div>
					<label class="sap-btn is-soft is-small sap-file-btn"><?php echo sap_icon( 'image', 14 ); // phpcs:ignore ?>انتخاب تصویر<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-sap-avatar-input hidden></label>
					<?php if ( '' !== sap_avatar_url( $user->ID ) ) : ?><label class="sap-check sap-small"><input type="checkbox" name="remove_avatar" value="1"> حذف تصویر</label><?php endif; ?>
					<small class="sap-muted">JPG، PNG یا WebP تا ۵ مگابایت؛ مربع برش داده می‌شود.</small>
				</div>
			</div>
			<div class="sap-grid-2">
				<label>نام و نام خانوادگی<input type="text" name="name" value="<?php echo esc_attr( $user->display_name ); ?>" required></label>
				<label>شماره موبایل<input type="tel" name="phone" dir="ltr" value="<?php echo esc_attr( $u_phone ); ?>" placeholder="09121234567"></label>
				<label class="sap-span-2">ایمیل<input type="email" name="email" dir="ltr" value="<?php echo esc_attr( $user->user_email ); ?>" required></label>
				<label>رمز عبور جدید<input type="password" name="password" dir="ltr" minlength="8" autocomplete="new-password" placeholder="خالی = بدون تغییر"></label>
				<label>رمز فعلی<input type="password" name="current" dir="ltr" autocomplete="current-password" placeholder="فقط برای تغییر ایمیل یا رمز"></label>
			</div>
			<p class="sap-small sap-muted">موبایل و ایمیل برای پیامک و ایمیل کارها و یادآوری‌ها استفاده می‌شوند.</p>
			<footer class="sap-profile-foot">
				<button type="button" class="sap-btn is-ghost" data-sap-profile-close>انصراف</button>
				<button type="submit" class="sap-btn is-primary"><?php echo sap_icon( 'check', 15 ); // phpcs:ignore ?>ذخیره پروفایل</button>
			</footer>
		</form>
	</dialog>

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
