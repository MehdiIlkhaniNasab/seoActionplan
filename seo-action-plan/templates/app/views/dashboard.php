<?php
/**
 * Command center: every project at a glance, this week's work, what needs attention.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

$user     = wp_get_current_user();
$projects = sap_projects();
$period   = sap_period_current();
$today    = current_time( 'Y-m-d' );
$week     = gmdate( 'Y-m-d', strtotime( '+7 days', strtotime( $today ) ) );
$usage    = sap_ss_usage();
$open_all = 0;
$done_n   = 0;
$live_n   = 0;
$overdue  = 0;
$queue    = array();
$alerts   = array();
$cards    = array();

if ( ! sap_google_connected() ) {
	$alerts[] = array( 'bad', 'حساب گوگل متصل نیست؛ سرچ کنسول و شیت‌ها خوانده نمی‌شوند.', sap_url( 'settings' ), 'اتصال' );
} elseif ( ! sap_google_has_sheets() ) {
	$alerts[] = array( 'warn', 'اجازه Google Sheets داده نشده؛ حساب گوگل را دوباره متصل کنید.', sap_url( 'settings' ), 'اتصال دوباره' );
}
if ( ! sap_ss_connected() ) {
	$alerts[] = array( 'warn', 'کلید سئو سیگنال وارد نشده؛ حجم جستجو، رقبا و ردیاب رتبه غیرفعال است.', sap_url( 'settings' ), 'وارد کردن کلید' );
}

foreach ( $projects as $p ) {
	$pid    = $p['id'];
	$counts = sap_findings_counts( $pid );
	$tasks  = sap_tasks( array( 'project_id' => $pid, 'period' => $period ) );
	$done   = 0;
	$live   = 0;
	$late   = 0;
	foreach ( $tasks as $t ) {
		if ( 'dropped' === $t['status'] || 'someday' === $t['status'] ) {
			continue;
		}
		++$live;
		if ( 'done' === $t['status'] ) {
			++$done;
			continue;
		}
		$is_late = $t['due_date'] && $t['due_date'] < $today;
		$late   += $is_late ? 1 : 0;
		if ( in_array( $t['status'], array( 'next', 'doing' ), true ) ) {
			$rank    = ( 'doing' === $t['status'] ? 0 : 1000 ) + ( $is_late ? 0 : 100 ) + ( $t['due_date'] && $t['due_date'] <= $week ? 0 : 50 ) - $t['score'] / 10;
			$queue[] = array( 't' => $t, 'p' => $p, 'rank' => $rank, 'late' => $is_late );
		}
	}
	$open_all += $counts['total'];
	$done_n   += $done;
	$live_n   += $live;
	$overdue  += $late;

	$last   = (string) ( $p['options']['last_sync'] ?? '' );
	$review = sap_weekly_review( $pid );
	if ( ! $last ) {
		$alerts[] = array( 'warn', sprintf( '«%s» هنوز همگام‌سازی نشده است.', $p['name'] ), sap_project_url( $pid ), 'همگام‌سازی' );
	} elseif ( strtotime( $last ) < strtotime( '-8 days', current_time( 'timestamp' ) ) ) { // phpcs:ignore
		$alerts[] = array( 'warn', sprintf( 'داده‌های «%s» بیش از یک هفته است بروز نشده.', $p['name'] ), sap_project_url( $pid ), 'همگام‌سازی' );
	}
	if ( $last && ! $tasks ) {
		$alerts[] = array( 'info', sprintf( 'اکشن پلن %s برای «%s» هنوز ساخته نشده (%s یافته باز).', sap_period_label( $period ), $p['name'], sap_num( $counts['total'] ) ), sap_project_url( $pid, 'plan' ), 'ساخت اکشن پلن' );
	}
	if ( $late ) {
		$alerts[] = array( 'bad', sprintf( '%s کار عقب‌افتاده در «%s».', sap_num( $late ), $p['name'] ), sap_project_url( $pid, 'plan' ), 'مشاهده' );
	}
	if ( $review['waiting'] ) {
		$alerts[] = array( 'info', sprintf( '%s مورد «در انتظار» در «%s» بیش از ۷ روز پیگیری نشده.', sap_num( count( $review['waiting'] ) ), $p['name'] ), sap_project_url( $pid, 'plan' ), 'پیگیری' );
	}
	if ( $tasks && $review['due'] ) {
		$alerts[] = array( 'info', sprintf( 'بازبینی هفتگی «%s» انجام نشده.', $p['name'] ), sap_project_url( $pid ), 'بازبینی' );
	}
	$cards[] = array( 'p' => $p, 'counts' => $counts, 'done' => $done, 'live' => $live, 'late' => $late, 'last' => $last );
}
usort( $queue, function ( $a, $b ) {
	return $a['rank'] <=> $b['rank'];
} );
$queue = array_slice( $queue, 0, 10 );
list( $jy, $jm, $jd ) = sap_jnow();
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow"><?php echo esc_html( sap_jdate( $today ) ); ?></p>
		<h1>سلام <?php echo esc_html( $user->display_name ); ?></h1>
		<p class="sap-muted">نمای کلی همه پروژه‌ها در <?php echo esc_html( sap_period_label( $period ) ); ?> — <?php echo esc_html( sap_num( sap_jmonth_days( $jy, $jm ) - $jd ) ); ?> روز تا پایان ماه.</p>
	</div>
	<div class="sap-actions">
		<a class="sap-btn is-primary" href="<?php echo esc_url( sap_url( 'new' ) ); ?>"><?php echo sap_icon( 'plus' ); // phpcs:ignore ?>پروژه جدید</a>
	</div>
</header>

<?php if ( ! $projects ) : ?>
	<div class="sap-empty is-hero">
		<?php echo sap_icon( 'target', 40 ); // phpcs:ignore ?>
		<h2>اولین پروژه را بسازید</h2>
		<p>۱. در «تنظیمات و اتصال‌ها» حساب گوگل، هوش مصنوعی و سئو سیگنال را وصل کنید. ۲. پروژه بسازید (فقط نام و آدرس سایت). ۳. از کارت «قدم بعدی» پروژه پیش بروید.</p>
		<div class="sap-actions is-center">
			<a class="sap-btn" href="<?php echo esc_url( sap_url( 'settings' ) ); ?>">تنظیمات و اتصال‌ها</a>
			<a class="sap-btn is-primary" href="<?php echo esc_url( sap_url( 'new' ) ); ?>">ساخت پروژه</a>
		</div>
	</div>
	<?php
	return;
endif;
?>

<div class="sap-stats">
	<div class="sap-stat"><span>پروژه فعال</span><b><?php echo esc_html( sap_num( count( $projects ) ) ); ?></b></div>
	<div class="sap-stat"><span>یافته باز در صندوق‌ها</span><b><?php echo esc_html( sap_num( $open_all ) ); ?></b></div>
	<div class="sap-stat"><span>پیشرفت کارهای این ماه</span><b><?php echo esc_html( sap_num( $done_n ) ); ?><small> / <?php echo esc_html( sap_num( $live_n ) ); ?></small></b>
		<div class="sap-progress"><i style="width:<?php echo esc_attr( $live_n ? round( 100 * $done_n / $live_n ) : 0 ); ?>%"></i></div>
	</div>
	<div class="sap-stat <?php echo $overdue ? 'is-bad' : ''; ?>"><span>کار عقب‌افتاده</span><b><?php echo esc_html( sap_num( $overdue ) ); ?></b></div>
</div>

<div class="sap-grid-dash">
	<section class="sap-panel">
		<div class="sap-panel-head">
			<h2><?php echo sap_icon( 'list' ); // phpcs:ignore ?>کارهای این هفته</h2>
			<small class="sap-muted">در حال انجام، عقب‌افتاده، نزدیک سررسید و پرامتیازترین‌ها</small>
		</div>
		<?php if ( ! $queue ) : ?>
			<p class="sap-muted">کار باز «اقدام بعدی» در این ماه نیست. از هر پروژه «ساخت خودکار اکشن پلن» را بزنید.</p>
		<?php else : ?>
			<ul class="sap-queue">
				<?php foreach ( $queue as $q ) : $t = $q['t']; ?>
					<li data-sap-row>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sap-quick is-inline" data-sap-remove-on-done>
							<?php sap_form_fields( 'sap_task_save', array( 'task_id' => $t['id'], '_return' => sap_url() ) ); ?>
							<button class="sap-done-btn" name="status" value="done" title="انجام شد"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?></button>
						</form>
						<a class="sap-queue-title" href="<?php echo esc_url( sap_project_url( $q['p']['id'], 'plan', array( 'task' => $t['id'] ) ) ); ?>"><?php echo esc_html( $t['title'] ); ?></a>
						<span class="sap-queue-meta">
							<span class="sap-muted"><?php echo esc_html( $q['p']['name'] ); ?></span>
							<?php echo sap_context_chip( $t['context'] ); // phpcs:ignore ?>
							<?php if ( 'doing' === $t['status'] ) : ?><span class="sap-chip is-doing">در حال انجام</span><?php endif; ?>
							<?php if ( $t['due_date'] ) : ?><span class="<?php echo $q['late'] ? 'sap-overdue' : 'sap-muted'; ?>"><?php echo esc_html( sap_jdate( $t['due_date'], 'day' ) ); ?></span><?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="sap-panel">
		<div class="sap-panel-head">
			<h2><?php echo sap_icon( 'alert' ); // phpcs:ignore ?>نیاز به توجه</h2>
			<?php if ( sap_ss_connected() ) : ?>
				<small class="sap-muted">سئو سیگنال امروز: <?php echo esc_html( sap_num( $usage['used'] ) . ' / ' . sap_num( $usage['cap'] ) ); ?></small>
			<?php endif; ?>
		</div>
		<?php if ( ! $alerts ) : ?>
			<p class="sap-ok-note"><?php echo sap_icon( 'check', 16 ); // phpcs:ignore ?>همه‌چیز مرتب است.</p>
		<?php else : ?>
			<ul class="sap-alerts">
				<?php foreach ( array_slice( $alerts, 0, 12 ) as $a ) : ?>
					<li class="is-<?php echo esc_attr( $a[0] ); ?>"><span><?php echo esc_html( $a[1] ); ?></span><a href="<?php echo esc_url( $a[2] ); ?>"><?php echo esc_html( $a[3] ); ?> ←</a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</div>

<h2 class="sap-section-title">پروژه‌ها</h2>
<div class="sap-cards">
	<?php
	foreach ( $cards as $c ) :
		$p   = $c['p'];
		$fcs = sap_focuses( $p['id'], true );
		?>
		<article class="sap-card">
			<a class="sap-card-top" href="<?php echo esc_url( sap_project_url( $p['id'] ) ); ?>">
				<h3><?php echo esc_html( $p['name'] ); ?></h3>
				<small dir="ltr"><?php echo esc_html( sap_project_domain( $p ) ); ?></small>
			</a>
			<?php if ( $fcs ) : ?>
				<p class="sap-card-focus"><?php echo sap_icon( 'target', 14 ); // phpcs:ignore ?><?php echo esc_html( implode( '، ', wp_list_pluck( $fcs, 'name' ) ) ); ?></p>
			<?php endif; ?>
			<div class="sap-mini-stats">
				<span><b><?php echo esc_html( sap_num( $c['counts']['total'] ) ); ?></b>یافته باز</span>
				<span><b><?php echo esc_html( sap_num( $c['done'] ) . '/' . sap_num( $c['live'] ) ); ?></b>کار این ماه</span>
				<?php if ( $c['late'] ) : ?><span class="is-bad"><b><?php echo esc_html( sap_num( $c['late'] ) ); ?></b>عقب‌افتاده</span><?php endif; ?>
			</div>
			<div class="sap-progress"><i style="width:<?php echo esc_attr( $c['live'] ? round( 100 * $c['done'] / $c['live'] ) : 0 ); ?>%"></i></div>
			<div class="sap-card-foot">
				<small class="sap-muted"><?php echo $c['last'] ? 'بروزرسانی: ' . esc_html( sap_jdate( $c['last'], 'day' ) ) : 'همگام‌سازی نشده'; ?></small>
				<span class="sap-card-links">
					<a href="<?php echo esc_url( sap_project_url( $p['id'], 'findings' ) ); ?>">یافته‌ها</a>
					<a href="<?php echo esc_url( sap_project_url( $p['id'], 'plan' ) ); ?>">اکشن پلن</a>
				</span>
			</div>
		</article>
	<?php endforeach; ?>
	<a class="sap-card is-add" href="<?php echo esc_url( sap_url( 'new' ) ); ?>"><?php echo sap_icon( 'plus', 26 ); // phpcs:ignore ?><span>پروژه جدید</span></a>
</div>
