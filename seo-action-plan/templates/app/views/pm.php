<?php
/**
 * Project manager: the projects and the team, a form to add tasks (approved by
 * a full-access manager before they reach the person), and their own proposals.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;
$projects = sap_projects();
$users    = sap_team_users();
$roles    = sap_member_roles();
$mine     = array_map( 'sap_task_hydrate', (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'tasks' ) . ' WHERE created_by = %d ORDER BY id DESC LIMIT 150', get_current_user_id() ) ) ); // phpcs:ignore
$reviews  = sap_pm_reviews();
$todayr   = sap_pm_today_reports();
$counts   = array( 'all' => count( $mine ), 'open' => 0, 'late' => 0, 'stuck' => 0, 'review' => 0, 'done' => 0, 'proposed' => 0 );
foreach ( $mine as $t ) {
	$b = sap_task_bucket( $t );
	if ( isset( $counts[ $b ] ) ) {
		++$counts[ $b ];
	}
}
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان · مدیر پروژه</p>
		<h1>میز کار مدیر پروژه</h1>
		<p class="sap-muted">گزارش کارهایی که ثبت کرده‌اید اینجا برای تأیید شما می‌آید. کار جدید بعد از تأیید مدیر سئو به مسئول واگذار می‌شود.</p>
	</div>
	<a class="sap-btn is-soft" href="<?php echo esc_url( sap_url( 'pmp' ) ); ?>"><?php echo sap_icon( 'grid', 16 ); // phpcs:ignore ?>همه کارهای هر پروژه</a>
</header>

<?php if ( $reviews ) : ?>
	<section class="sap-desk-sec" id="review">
		<h2><?php echo sap_icon( 'check', 18 ); // phpcs:ignore ?>منتظر تأیید شما <small><?php echo esc_html( sap_num( count( $reviews ) ) ); ?> گزارش · تأیید کنید یا با بازخورد برگردانید</small></h2>
		<div class="sap-pm-rows">
			<?php foreach ( $reviews as $t ) : ?>
				<?php sap_view( 'pm-row', array( 't' => $t ) ); ?>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $todayr ) : ?>
	<section class="sap-desk-sec" id="today">
		<h2>🗒️ گزارش‌های روزانه امروز <small><?php echo esc_html( sap_num( count( $todayr ) ) ); ?> گزارش روی کارهای شما</small></h2>
		<div class="sap-today-list">
			<?php foreach ( $todayr as $x ) : ?>
				<a class="sap-today<?php echo 'blocked' === ( $x['entry']['state'] ?? '' ) ? ' is-blocked' : ''; ?>" href="<?php echo esc_url( sap_url( 'pmp', array( 'p' => $x['task']['project_id'] ) ) . '#t-' . $x['task']['id'] ); ?>">
					<?php echo sap_avatar( (int) $x['entry']['u'], 28 ); // phpcs:ignore ?>
					<span><b><?php echo esc_html( $x['task']['title'] ); ?></b><em><?php echo esc_html( mb_strimwidth( $x['entry']['text'], 0, 160, '…' ) ); ?></em><?php if ( 'blocked' === ( $x['entry']['state'] ?? '' ) ) : ?><small class="sap-plog-why">⚠️ <?php echo esc_html( $x['entry']['why'] ); ?></small><?php endif; ?></span>
					<time><?php echo esc_html( sap_fa( substr( $x['entry']['at'], 11, 5 ) ) ); ?></time>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $mine ) : ?>
	<div class="sap-desk-stats sap-pm-stats">
		<a href="#tracking" data-sap-pm-tab="open" class="<?php echo $counts['open'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['open'] ) ); ?></b><span>در جریان</span></a>
		<a href="#tracking" data-sap-pm-tab="review" class="<?php echo $counts['review'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['review'] ) ); ?></b><span>گزارش ثبت‌شده</span></a>
		<a href="#tracking" data-sap-pm-tab="late" class="<?php echo $counts['late'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['late'] ) ); ?></b><span>عقب‌افتاده</span></a>
		<a href="#tracking" data-sap-pm-tab="stuck" class="<?php echo $counts['stuck'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['stuck'] ) ); ?></b><span>گیر کرده</span></a>
		<a href="#tracking" data-sap-pm-tab="done"><b><?php echo esc_html( sap_num( $counts['done'] ) ); ?></b><span>انجام شده</span></a>
		<a href="#tracking" data-sap-pm-tab="proposed"><b><?php echo esc_html( sap_num( $counts['proposed'] ) ); ?></b><span>منتظر تأیید مدیر سئو</span></a>
	</div>
<?php endif; ?>

<div class="sap-pm-grid">
	<section class="sap-panel sap-pm-form">
		<div class="sap-panel-head"><h2><?php echo sap_icon( 'plus' ); // phpcs:ignore ?>ثبت کار جدید</h2></div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sap-form" data-sap-brief-form>
			<?php sap_form_fields( 'sap_pm_task' ); ?>
			<div class="sap-grid-2">
				<label>پروژه<select name="project_id" required><?php foreach ( $projects as $p ) : ?><option value="<?php echo esc_attr( $p['id'] ); ?>"><?php echo esc_html( $p['name'] ); ?></option><?php endforeach; ?></select></label>
				<label>نوع کار<select name="context"><?php foreach ( sap_contexts() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
			</div>
			<label>عنوان کار<input type="text" name="title" required placeholder="مثلاً: نوشتن توضیحات ۱۰ محصول دسته اسکوتر برقی"></label>
			<label>توضیحات کار
				<textarea name="notes" rows="6" data-sap-brief-notes placeholder="چه کاری، روی کدام صفحه‌ها، با چه نتیجه‌ای؟ کوتاه هم بنویسید، «شفاف‌سازی» کاملش می‌کند."></textarea>
			</label>
			<input type="hidden" name="outcome" data-sap-brief-outcome>
			<div class="sap-brief-bar">
				<button type="button" class="sap-btn is-soft is-small" data-sap-brief <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>شفاف‌سازی با هوش مصنوعی</button>
				<small class="sap-muted">هدف، مراحل، خروجی و اینکه مسئول دقیقاً چه چیزی در گزارش بنویسد.</small>
			</div>
			<div class="sap-grid-3">
				<label>مسئول<select name="assignee"><option value="0">—</option><?php foreach ( $users as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?></select></label>
				<label>ددلاین<input type="date" name="due_date"></label>
				<?php sap_view( 'repeat-field', array( 'value' => '' ) ); ?>
				<label>زمان تخمینی (ساعت)<input type="number" name="effort" min="0.25" step="0.25" value="1"></label>
			</div>
			<label>آدرس صفحه (اگر لازم است)<input type="url" name="url" dir="ltr" placeholder="https://"></label>
			<?php sap_view( 'checklist-field', array( 'items' => array() ) ); ?>
			<div class="sap-form-foot"><button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'check', 15 ); // phpcs:ignore ?>ثبت برای تأیید</button></div>
		</form>
	</section>

	<div class="sap-pm-side">
		<section class="sap-panel">
			<div class="sap-panel-head"><h2><?php echo sap_icon( 'grid' ); // phpcs:ignore ?>پروژه‌ها</h2></div>
			<ul class="sap-pm-projects">
				<?php foreach ( $projects as $p ) : ?>
					<?php $open = count( sap_tasks( array( 'project_id' => $p['id'], 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) ) ); ?>
					<li><i class="sap-dot" style="background:<?php echo esc_attr( sap_member_color( $p['id'] + 3 ) ); ?>"></i><b><?php echo esc_html( $p['name'] ); ?></b><small dir="ltr"><?php echo esc_html( sap_clean_domain( $p['site_url'] ) ); ?></small><em><?php echo esc_html( sap_num( $open ) ); ?> کار باز</em></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<section class="sap-panel">
			<div class="sap-panel-head"><h2><?php echo sap_icon( 'users' ); // phpcs:ignore ?>اعضای تیم</h2></div>
			<ul class="sap-pm-people">
				<?php foreach ( $users as $u ) : ?>
					<?php $m = sap_member( $u->ID ); ?>
					<li><?php echo sap_avatar( $u->ID, 34 ); // phpcs:ignore ?><div><b><?php echo esc_html( $m['name'] ); ?></b><small><?php echo esc_html( $m['title'] ?: ( sap_roles_sokan()[ $m['role'] ] ?? '' ) ); ?></small></div><em><?php echo esc_html( sap_num( count( sap_member_open_tasks( $u->ID ) ) ) ); ?> کار</em></li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>
</div>

<?php if ( $mine ) : ?>
	<section class="sap-panel sap-pm-track" id="tracking" data-sap-pm-track>
		<div class="sap-panel-head">
			<h2><?php echo sap_icon( 'list' ); // phpcs:ignore ?>کارهایی که ثبت کرده‌اید</h2>
			<div class="sap-seg-mini">
				<?php foreach ( array( 'all' => 'همه', 'open' => 'در جریان', 'late' => 'عقب‌افتاده', 'stuck' => 'گیر کرده', 'review' => 'گزارش ثبت‌شده', 'done' => 'انجام شده', 'proposed' => 'منتظر تأیید' ) as $k => $l ) : ?>
					<button type="button" class="<?php echo 'all' === $k ? 'is-active' : ''; ?>" data-sap-pm-tab="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?> <em><?php echo esc_html( sap_num( $counts[ $k ] ) ); ?></em></button>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="sap-pm-rows">
			<?php foreach ( $mine as $t ) : ?>
				<?php sap_view( 'pm-row', array( 't' => $t ) ); ?>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>
