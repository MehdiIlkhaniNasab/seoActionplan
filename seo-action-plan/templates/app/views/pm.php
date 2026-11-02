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
$st_label = array( 'proposed' => array( 'منتظر تأیید مدیر سئو', 'is-warn' ), 'dropped' => array( 'رد شد', 'is-bad' ), 'done' => array( 'انجام شد', 'is-good' ), 'review' => array( 'گزارش ثبت شد · منتظر تأیید', 'is-info' ), 'doing' => array( 'در حال انجام', 'is-info' ), 'waiting' => array( 'در انتظار', 'is-soft' ), 'someday' => array( 'شاید بعداً', 'is-soft' ) );
$today    = current_time( 'Y-m-d' );
$bucket   = function ( $t ) use ( $today ) {
	if ( in_array( $t['status'], array( 'proposed', 'review', 'done', 'dropped' ), true ) ) {
		return $t['status'];
	}
	return $t['due_date'] && $t['due_date'] < $today ? 'late' : 'open';
};
$counts = array( 'all' => count( $mine ), 'open' => 0, 'late' => 0, 'review' => 0, 'done' => 0, 'proposed' => 0 );
foreach ( $mine as $t ) {
	$b = $bucket( $t );
	if ( isset( $counts[ $b ] ) ) {
		++$counts[ $b ];
	}
}
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان · مدیر پروژه</p>
		<h1>میز کار مدیر پروژه</h1>
		<p class="sap-muted">کار جدید را با توضیح شفاف ثبت کنید. بعد از تأیید مدیر سئو، کار به مسئول واگذار و برایش ایمیل و پیامک می‌رود.</p>
	</div>
</header>

<?php if ( $mine ) : ?>
	<div class="sap-desk-stats sap-pm-stats">
		<a href="#tracking" data-sap-pm-tab="open" class="<?php echo $counts['open'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['open'] ) ); ?></b><span>در جریان</span></a>
		<a href="#tracking" data-sap-pm-tab="review" class="<?php echo $counts['review'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['review'] ) ); ?></b><span>گزارش ثبت‌شده</span></a>
		<a href="#tracking" data-sap-pm-tab="late" class="<?php echo $counts['late'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['late'] ) ); ?></b><span>عقب‌افتاده</span></a>
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
			<h2><?php echo sap_icon( 'list' ); // phpcs:ignore ?>پیگیری کارهایی که ثبت کرده‌اید</h2>
			<div class="sap-seg-mini">
				<?php foreach ( array( 'all' => 'همه', 'open' => 'در جریان', 'late' => 'عقب‌افتاده', 'review' => 'گزارش ثبت‌شده', 'done' => 'انجام شده', 'proposed' => 'منتظر تأیید' ) as $k => $l ) : ?>
					<button type="button" class="<?php echo 'all' === $k ? 'is-active' : ''; ?>" data-sap-pm-tab="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?> <em><?php echo esc_html( sap_num( $counts[ $k ] ) ); ?></em></button>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="sap-pm-rows">
			<?php foreach ( $mine as $t ) : ?>
				<?php
				$b    = $bucket( $t );
				$sl   = 'late' === $b ? array( sap_num( sap_days_late( $t['due_date'] ) ) . ' روز تأخیر', 'is-bad' ) : ( $st_label[ $t['status'] ] ?? array( 'واگذار شد · هنوز شروع نشده', 'is-soft' ) );
				$prog = $t['checklist'] ? sap_checklist_progress( $t ) : null;
				$pj   = sap_project( $t['project_id'] );
				$rep  = '' !== $t['result'] || $t['attachments'];
				?>
				<details class="sap-pm-row is-<?php echo esc_attr( $b ); ?>" id="t-<?php echo esc_attr( $t['id'] ); ?>" data-bucket="<?php echo esc_attr( $b ); ?>">
					<summary>
						<span class="sap-chip <?php echo esc_attr( $sl[1] ); ?>"><?php echo esc_html( $sl[0] ); ?></span>
						<b><?php echo esc_html( $t['title'] ); ?></b>
						<?php $ps = sap_progress_summary( $t ); ?>
						<?php if ( $ps['blocked'] ) : ?><span class="sap-chip is-bad" title="<?php echo esc_attr( $t['waiting_for'] ); ?>">⚠️ گیر کرده</span><?php endif; ?>
						<?php if ( $ps['count'] ) : ?><span class="sap-chip is-soft">🗒️ <?php echo esc_html( sap_num( $ps['count'] ) ); ?> گزارش</span><?php endif; ?>
						<span class="sap-ptag" style="--c:<?php echo esc_attr( sap_member_color( $t['project_id'] + 3 ) ); ?>"><?php echo esc_html( $pj['name'] ?? '' ); ?></span>
						<?php if ( $prog ) : ?><span class="sap-mini-prog<?php echo $prog['done'] === $prog['total'] ? ' is-full' : ''; ?>"><i style="width:<?php echo esc_attr( $prog['pct'] ); ?>%"></i><em><?php echo esc_html( sap_num( $prog['done'] ) . '/' . sap_num( $prog['total'] ) ); ?></em></span><?php endif; ?>
						<?php echo $t['assignee'] ? sap_person( $t['assignee'], 20 ) : '<em class="sap-muted sap-small">بدون مسئول</em>'; // phpcs:ignore ?>
						<small class="sap-muted"><?php echo esc_html( $t['due_date'] ? 'ددلاین ' . sap_jdate( $t['due_date'], 'day' ) : 'بدون ددلاین' ); ?></small>
					</summary>
					<div class="sap-pm-row-body">
						<?php sap_view( 'progress-log', array( 't' => $t, 'form' => false ) ); ?>
						<?php if ( $prog ) : ?>
							<ul class="sap-pm-check"><?php foreach ( $t['checklist'] as $it ) : ?><li class="<?php echo $it['d'] ? 'is-done' : ''; ?>"><?php echo $it['d'] ? '✔' : '○'; ?> <?php echo esc_html( $it['t'] ); ?></li><?php endforeach; ?></ul>
						<?php endif; ?>
						<?php if ( $rep ) : ?>
							<div class="sap-treport">
								<span>گزارش کار<?php echo $t['spent'] ? ' · ' . esc_html( sap_num( $t['spent'], 1 ) ) . ' ساعت' : ''; ?><?php echo $t['submitted_at'] ? ' · ' . esc_html( sap_jdate( substr( $t['submitted_at'], 0, 10 ), 'day' ) ) : ''; ?></span>
								<?php if ( '' !== $t['result'] ) : ?><div><?php echo sap_rich_text( $t['result'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div><?php endif; ?>
								<?php if ( $t['attachments'] ) : ?>
									<div class="sap-att-list"><?php foreach ( $t['attachments'] as $a ) : ?><a class="sap-att<?php echo 'image' === ( $a['type'] ?? '' ) ? ' is-img' : ''; ?>" href="<?php echo esc_url( $a['url'] ); ?>" target="_blank" rel="noopener"><?php echo 'image' === ( $a['type'] ?? '' ) ? '<img src="' . esc_url( $a['url'] ) . '" alt="" loading="lazy">' : esc_html( '' !== ( $a['name'] ?? '' ) ? $a['name'] : sap_short_url( $a['url'], 40 ) ); ?></a><?php endforeach; ?></div>
								<?php endif; ?>
							</div>
						<?php else : ?>
							<p class="sap-muted sap-small">هنوز گزارشی ثبت نشده.</p>
						<?php endif; ?>
						<?php if ( '' !== $t['review_note'] ) : ?><p class="sap-tnote"><b>🔁 بازخورد مدیر سئو:</b> <?php echo esc_html( $t['review_note'] ); ?></p><?php endif; ?>
						<?php if ( '' !== trim( $t['notes'] ) ) : ?><details class="sap-desk-brief"><summary>شرح کار</summary><div class="sap-notes"><?php echo sap_rich_text( $t['notes'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div></details><?php endif; ?>
					</div>
				</details>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>
