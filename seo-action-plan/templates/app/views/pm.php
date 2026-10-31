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
$mine     = array_map( 'sap_task_hydrate', (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . sap_table( 'tasks' ) . " WHERE created_by = %d AND source = 'pm' ORDER BY id DESC LIMIT 40", get_current_user_id() ) ) ); // phpcs:ignore
$st_label = array( 'proposed' => array( 'منتظر تأیید', 'is-warn' ), 'dropped' => array( 'رد شد', 'is-bad' ), 'done' => array( 'انجام شد', 'is-good' ), 'review' => array( 'در انتظار تأیید گزارش', 'is-info' ) );
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان · مدیر پروژه</p>
		<h1>پروژه‌ها و ثبت کار</h1>
		<p class="sap-muted">کار جدید را با توضیح شفاف ثبت کنید. بعد از تأیید مدیر سئو، کار به مسئول واگذار و برایش ایمیل و پیامک می‌رود.</p>
	</div>
</header>

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
				<label>زمان تخمینی (ساعت)<input type="number" name="effort" min="0.25" step="0.25" value="1"></label>
			</div>
			<label>آدرس صفحه (اگر لازم است)<input type="url" name="url" dir="ltr" placeholder="https://"></label>
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
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>کارهایی که ثبت کرده‌اید</h2></div>
		<ul class="sap-pm-mine">
			<?php foreach ( $mine as $t ) : ?>
				<?php $sl = $st_label[ $t['status'] ] ?? array( 'تأیید و واگذار شد', 'is-good' ); ?>
				<li><span class="sap-chip <?php echo esc_attr( $sl[1] ); ?>"><?php echo esc_html( $sl[0] ); ?></span><b><?php echo esc_html( $t['title'] ); ?></b><?php echo $t['assignee'] ? sap_person( $t['assignee'], 20 ) : ''; // phpcs:ignore ?><small class="sap-muted"><?php echo esc_html( $t['due_date'] ? 'ددلاین ' . sap_jdate( $t['due_date'], 'short' ) : '' ); ?></small></li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
