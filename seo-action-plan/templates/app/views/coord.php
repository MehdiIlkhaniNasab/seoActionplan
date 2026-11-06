<?php
/**
 * Coordinator desk («میز پیگیری»): triage (delegate or keep), own plan with
 * start dates, and the follow-up board of delegated tasks.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

$uid     = get_current_user_id();
$today   = current_time( 'Y-m-d' );
$triage  = sap_coord_triage( $uid );
$mine    = sap_coord_mine( $uid );
$deleg   = sap_coord_delegated( $uid );
$auto    = '1' === (string) get_user_meta( $uid, 'sap_coord_auto', true );
$people  = array_values( array_filter( sap_team_users(), function ( $u ) use ( $uid ) {
	return (int) $u->ID !== $uid;
} ) );
$loadby  = array();
foreach ( $people as $u ) {
	$loadby[ $u->ID ] = count( sap_tasks( array( 'assignee' => $u->ID, 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) ) );
}
// Workload strip: today until the end of the month (at least two weeks).
list( , $m_end ) = sap_period_range( sap_period_current() );
$to      = max( $m_end, gmdate( 'Y-m-d', strtotime( $today . ' +13 days' ) ) );
$load    = sap_user_day_load( $uid, $today, $to );
$follow  = array( 'all' => 0, 'need' => 0, 'stuck' => 0, 'late' => 0, 'review' => 0, 'done' => 0 );
foreach ( $deleg as $t ) {
	$k = sap_coord_follow_state( $t )[2];
	++$follow['all'];
	if ( in_array( $k, array( 'stuck', 'late', 'silent', 'nudged' ), true ) ) {
		++$follow['need'];
	}
	if ( isset( $follow[ $k ] ) ) {
		++$follow[ $k ];
	}
}
$mine_today = count( array_filter( $mine, function ( $t ) use ( $today ) {
	return '' !== $t['start_date'] && $t['start_date'] <= $today && 'review' !== $t['status'];
} ) );
$pane = $triage ? 'triage' : ( $follow['need'] ? 'follow' : 'mine' );
$strip = function ( $name ) use ( $load, $today ) {
	$o = '<div class="sap-load" data-sap-load="' . esc_attr( $name ) . '">';
	foreach ( $load as $d => $n ) {
		$fri = 5 === (int) gmdate( 'w', strtotime( $d ) );
		$lvl = $n >= 4 ? 'is-full' : ( $n >= 2 ? 'is-mid' : ( $n ? 'is-low' : 'is-free' ) );
		$o  .= '<button type="button" class="' . ( $fri ? 'is-fri' : $lvl ) . ( $d === $today ? ' is-today' : '' ) . '" ' . ( $fri ? 'disabled' : 'data-day="' . esc_attr( $d ) . '"' ) . ' title="' . esc_attr( sap_weekday_fa( $d ) . ' ' . sap_jdate( $d, 'day' ) . ( $fri ? ' · تعطیل' : ' · ' . sap_num( $n ) . ' کار' ) ) . '"><small>' . esc_html( mb_substr( sap_weekday_fa( $d ), 0, 1 ) ) . '</small><b>' . esc_html( explode( ' ', sap_jdate( $d, 'day' ) )[0] ) . '</b><i>' . ( $fri ? '—' : esc_html( $n ? sap_num( $n ) : '·' ) ) . '</i></button>';
	}
	return $o . '</div>';
};
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان · هماهنگ‌کننده</p>
		<h1>میز پیگیری</h1>
		<p class="sap-muted">کارهایی که به شما می‌رسد را سریع تعیین تکلیف کنید: به مسئول درست واگذار کنید یا برای خودتان تاریخ شروع بگذارید. واگذارشده‌ها را از همین‌جا پیگیری کنید.</p>
	</div>
	<label class="sap-switch sap-coord-auto" title="هر روز ساعت ۱۰ صبح برای کارهای واگذارشده‌ای که ۲ روز گزارش نداشته‌اند، خودکار یادآوری (اعلان + ایمیل) می‌رود">
		<input type="checkbox" data-sap-coord-auto <?php checked( $auto ); ?>>
		<span class="sap-switch-track" aria-hidden="true"></span>
		<span class="sap-switch-text"><b>پیگیری خودکار</b><small class="sap-muted">یادآوری روزانه به کارهای بی‌گزارش</small></span>
	</label>
</header>

<div class="sap-desk-stats sap-coord-stats">
	<a href="#" data-sap-coord-pane="triage" class="<?php echo $triage ? 'is-warn' : ''; ?>"><b><?php echo esc_html( sap_num( count( $triage ) ) ); ?></b><span>منتظر تعیین تکلیف</span></a>
	<a href="#" data-sap-coord-pane="mine" class="<?php echo $mine_today ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( $mine_today ) ); ?></b><span>کار خودم برای امروز</span></a>
	<a href="#" data-sap-coord-pane="follow"><b><?php echo esc_html( sap_num( $follow['all'] - $follow['done'] ) ); ?></b><span>واگذارشده در جریان</span></a>
	<a href="#" data-sap-coord-pane="follow" data-filter="need" class="<?php echo $follow['need'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $follow['need'] ) ); ?></b><span>نیاز به پیگیری</span></a>
	<a href="#" data-sap-coord-pane="follow" data-filter="review"><b><?php echo esc_html( sap_num( $follow['review'] ) ); ?></b><span>تمام شده · منتظر تأیید</span></a>
</div>

<nav class="sap-seg-mini sap-coord-tabs">
	<button type="button" data-sap-coord-pane="triage" class="<?php echo 'triage' === $pane ? 'is-active' : ''; ?>">📥 تعیین تکلیف <em><?php echo esc_html( sap_num( count( $triage ) ) ); ?></em></button>
	<button type="button" data-sap-coord-pane="mine" class="<?php echo 'mine' === $pane ? 'is-active' : ''; ?>">🙋 کارهای خودم <em><?php echo esc_html( sap_num( count( $mine ) ) ); ?></em></button>
	<button type="button" data-sap-coord-pane="follow" class="<?php echo 'follow' === $pane ? 'is-active' : ''; ?>">📤 پیگیری واگذارشده‌ها <em><?php echo esc_html( sap_num( $follow['all'] ) ); ?></em></button>
</nav>

<!-- Triage -->
<section class="sap-coord-pane" data-pane="triage" <?php echo 'triage' === $pane ? '' : 'hidden'; ?>>
	<?php if ( $triage ) : ?>
		<div class="sap-coord-bulk" data-sap-coord-bulk>
			<label class="sap-check"><input type="checkbox" data-sap-tri-all> انتخاب همه</label>
			<b data-sap-tri-n>۰</b> انتخاب‌شده ·
			<select data-sap-bulk-who><option value="">واگذاری گروهی به…</option><?php foreach ( $people as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name . ' (' . sap_num( $loadby[ $u->ID ] ) . ' کار باز)' ); ?></option><?php endforeach; ?></select>
			<input type="date" data-sap-bulk-due placeholder="ددلاین">
			<button type="button" class="sap-btn is-primary is-small" data-sap-bulk-go>واگذار کن</button>
		</div>
		<div class="sap-tri-list">
			<?php foreach ( $triage as $t ) : ?>
				<?php $pj = sap_project( $t['project_id'] ); ?>
				<article class="sap-tri" data-sap-tri="<?php echo esc_attr( $t['id'] ); ?>" id="tri-<?php echo esc_attr( $t['id'] ); ?>">
					<header>
						<input type="checkbox" class="sap-tri-pick" value="<?php echo esc_attr( $t['id'] ); ?>" aria-label="انتخاب">
						<div class="sap-tri-title">
							<b><?php echo esc_html( $t['title'] ); ?></b>
							<span class="sap-tri-meta">
								<span class="sap-ptag" style="--c:<?php echo esc_attr( sap_member_color( $t['project_id'] + 3 ) ); ?>"><?php echo esc_html( $pj['name'] ?? '' ); ?></span>
								<?php if ( $t['created_by'] && $t['created_by'] !== $uid ) : ?><small class="sap-muted">از <?php echo esc_html( sap_user_name( $t['created_by'] ) ); ?></small><?php endif; ?>
								<small class="<?php echo $t['due_date'] && $t['due_date'] < $today ? 'is-bad' : 'sap-muted'; ?>"><?php echo esc_html( $t['due_date'] ? 'ددلاین ' . sap_jdate( $t['due_date'], 'day' ) : 'بدون ددلاین' ); ?></small>
								<?php if ( $t['checklist'] ) : ?><small class="sap-muted">✅ <?php echo esc_html( sap_num( count( $t['checklist'] ) ) ); ?> مورد</small><?php endif; ?>
							</span>
						</div>
						<div class="sap-tri-choose">
							<button type="button" class="sap-btn is-primary is-small" data-sap-tri-mode="delegate">📤 واگذاری</button>
							<button type="button" class="sap-btn is-soft is-small" data-sap-tri-mode="own">🙋 خودم انجام می‌دهم</button>
						</div>
					</header>
					<?php if ( '' !== trim( $t['notes'] ) ) : ?><div class="sap-tri-notes"><?php echo esc_html( mb_strimwidth( preg_replace( '/\s+/u', ' ', $t['notes'] ), 0, 220, '…' ) ); ?></div><?php endif; ?>

					<form class="sap-tri-form sap-form" data-sap-tri-form="delegate" hidden>
						<div class="sap-grid-2">
							<label>مسئول انجام<select name="assignee" required><option value="">— انتخاب کنید —</option><?php foreach ( $people as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name . ' · ' . sap_num( $loadby[ $u->ID ] ) . ' کار باز' ); ?></option><?php endforeach; ?></select></label>
							<label>ددلاین<input type="date" name="due_date" value="<?php echo esc_attr( (string) $t['due_date'] ); ?>" required></label>
						</div>
						<label>شرح کار برای مسئول (قابل ویرایش)<textarea name="notes" rows="4"><?php echo esc_textarea( $t['notes'] ); ?></textarea></label>
						<?php sap_view( 'checklist-field', array( 'items' => $t['checklist'] ) ); ?>
						<div class="sap-form-foot"><button class="sap-btn is-primary" type="submit">📤 واگذار کن و بعدی</button><small class="sap-muted">کار به «در حال انجام» می‌رود، در گزارش‌های کار «واگذار شد» ثبت می‌شود و برای مسئول اعلان و ایمیل می‌رود.</small></div>
					</form>

					<form class="sap-tri-form sap-form" data-sap-tri-form="own" hidden>
						<p class="sap-label">از کدام روز شروع می‌کنید؟ <small class="sap-muted">عدد زیر هر روز = تعداد کارهای شما در آن روز</small></p>
						<?php echo $strip( 'start_date' ); // phpcs:ignore ?>
						<div class="sap-grid-2">
							<label>تاریخ شروع<input type="date" name="start_date" required></label>
							<label>ددلاین<input type="date" name="due_date" value="<?php echo esc_attr( (string) $t['due_date'] ); ?>"></label>
						</div>
						<div class="sap-form-foot"><button class="sap-btn is-primary" type="submit">🗓️ برنامه‌ریزی و بعدی</button></div>
					</form>
				</article>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="sap-empty sap-empty-good"><?php echo sap_icon( 'check', 32 ); // phpcs:ignore ?><p>همه کارها تعیین تکلیف شده‌اند. 🌿</p></div>
	<?php endif; ?>
</section>

<!-- Own tasks -->
<section class="sap-coord-pane" data-pane="mine" <?php echo 'mine' === $pane ? '' : 'hidden'; ?>>
	<?php
	$wk     = gmdate( 'Y-m-d', strtotime( $today . ' +6 days' ) );
	$groups = array( 'now' => array( 'امروز و عقب‌مانده', array() ), 'week' => array( 'این هفته', array() ), 'later' => array( 'بعداً', array() ) );
	foreach ( $mine as $t ) {
		$s = $t['start_date'] ?: $today;
		$groups[ $s <= $today ? 'now' : ( $s <= $wk ? 'week' : 'later' ) ][1][] = $t;
	}
	?>
	<?php if ( $mine ) : ?>
		<?php foreach ( $groups as $g ) : ?>
			<?php if ( ! $g[1] ) { continue; } ?>
			<h3 class="sap-coord-h"><?php echo esc_html( $g[0] ); ?> <small><?php echo esc_html( sap_num( count( $g[1] ) ) ); ?></small></h3>
			<div class="sap-coord-rows">
				<?php foreach ( $g[1] as $t ) : ?>
					<?php $sl = sap_task_state_chip( $t ); $prog = $t['checklist'] ? sap_checklist_progress( $t ) : null; ?>
					<a class="sap-coord-row" href="<?php echo esc_url( sap_url( 'my' ) . '#task-' . $t['id'] ); ?>">
						<span class="sap-coord-date"><b><?php echo esc_html( $t['start_date'] ? sap_jdate( $t['start_date'], 'day' ) : '—' ); ?></b><small>شروع</small></span>
						<span class="sap-coord-main"><b><?php echo esc_html( $t['title'] ); ?></b><small><?php echo esc_html( ( sap_project( $t['project_id'] )['name'] ?? '' ) . ( $t['due_date'] ? ' · ددلاین ' . sap_jdate( $t['due_date'], 'day' ) : '' ) ); ?></small></span>
						<?php if ( $prog ) : ?><span class="sap-mini-prog"><i style="width:<?php echo esc_attr( $prog['pct'] ); ?>%"></i><em><?php echo esc_html( sap_num( $prog['done'] ) . '/' . sap_num( $prog['total'] ) ); ?></em></span><?php endif; ?>
						<span class="sap-chip <?php echo esc_attr( $sl[1] ); ?>"><?php echo esc_html( $sl[0] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		<p class="sap-small sap-muted">گزارش روزانه و گزارش نهایی کارهای خودتان را از «کارهای من» ثبت کنید (روی هر ردیف بزنید).</p>
	<?php else : ?>
		<div class="sap-empty"><p>کاری برای خودتان برنامه‌ریزی نکرده‌اید.</p></div>
	<?php endif; ?>
</section>

<!-- Follow-up -->
<section class="sap-coord-pane" data-pane="follow" <?php echo 'follow' === $pane ? '' : 'hidden'; ?> data-sap-follow>
	<?php if ( $deleg ) : ?>
		<div class="sap-seg-mini sap-follow-tabs">
			<?php foreach ( array( 'all' => 'همه', 'need' => 'نیاز به پیگیری', 'stuck' => 'گیر کرده', 'late' => 'عقب‌افتاده', 'review' => 'منتظر تأیید', 'done' => 'انجام شده' ) as $k => $l ) : ?>
				<?php if ( 'all' !== $k && ! $follow[ $k ] ) { continue; } ?>
				<button type="button" class="<?php echo 'all' === $k ? 'is-active' : ''; ?>" data-sap-follow-tab="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?> <em><?php echo esc_html( sap_num( $follow[ $k ] ) ); ?></em></button>
			<?php endforeach; ?>
		</div>
		<div class="sap-pm-rows">
			<?php foreach ( $deleg as $t ) : ?>
				<?php
				$fs   = sap_coord_follow_state( $t );
				$ps   = sap_progress_summary( $t );
				$prog = $t['checklist'] ? sap_checklist_progress( $t ) : null;
				$need = in_array( $fs[2], array( 'stuck', 'late', 'silent', 'nudged' ), true );
				?>
				<details class="sap-pm-row sap-follow is-<?php echo esc_attr( $fs[2] ); ?>" id="f-<?php echo esc_attr( $t['id'] ); ?>" data-state="<?php echo esc_attr( $fs[2] ); ?>" data-need="<?php echo $need ? '1' : '0'; ?>">
					<summary>
						<span class="sap-chip <?php echo esc_attr( $fs[1] ); ?>"><?php echo esc_html( $fs[0] ); ?></span>
						<b><?php echo esc_html( $t['title'] ); ?></b>
						<?php echo sap_person( $t['assignee'], 20 ); // phpcs:ignore ?>
						<?php if ( $prog ) : ?><span class="sap-mini-prog<?php echo $prog['done'] === $prog['total'] ? ' is-full' : ''; ?>"><i style="width:<?php echo esc_attr( $prog['pct'] ); ?>%"></i><em><?php echo esc_html( sap_num( $prog['done'] ) . '/' . sap_num( $prog['total'] ) ); ?></em></span><?php endif; ?>
						<small class="sap-muted"><?php echo esc_html( ( $t['due_date'] ? 'ددلاین ' . sap_jdate( $t['due_date'], 'day' ) : '' ) . ( $t['delegated_at'] ? ' · واگذاری ' . sap_jdate( substr( $t['delegated_at'], 0, 10 ), 'day' ) : '' ) ); ?></small>
						<?php if ( 'done' !== $fs[2] ) : ?><span class="sap-follow-acts"><?php sap_view( 'nudge-btn', array( 't' => $t, 'compact' => true ) ); ?></span><?php endif; ?>
					</summary>
					<div class="sap-pm-row-body">
						<?php if ( $ps['last'] ) : ?><p class="sap-small"><b>آخرین گزارش (<?php echo esc_html( sap_jdate( substr( $ps['last']['at'], 0, 10 ), 'day' ) ); ?>):</b> <?php echo esc_html( $ps['last']['text'] ); ?></p><?php endif; ?>
						<?php if ( 'done' !== $fs[2] ) : ?>
							<form class="sap-follow-form" data-sap-follow-form="<?php echo esc_attr( $t['id'] ); ?>">
								<input type="text" name="text" placeholder="نتیجه پیگیری (تماس، پیام…)؛ مثلاً: تلفنی پیگیری شد، فردا عکس‌ها را می‌فرستد" required>
								<button class="sap-btn is-small" type="submit">📞 ثبت پیگیری</button>
							</form>
						<?php endif; ?>
						<?php sap_view( 'progress-log', array( 't' => $t, 'form' => false ) ); ?>
						<?php if ( '' !== $t['result'] ) : ?><div class="sap-treport"><span>گزارش نهایی</span><div><?php echo sap_rich_text( $t['result'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div></div><?php endif; ?>
					</div>
				</details>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="sap-empty"><p>هنوز کاری واگذار نکرده‌اید.</p></div>
	<?php endif; ?>
</section>
