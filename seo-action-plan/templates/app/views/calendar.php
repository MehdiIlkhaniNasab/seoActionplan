<?php
/**
 * Monthly calendar of tasks (full-access managers): every day of a Jalali month
 * in a row, with the tasks due that day and who does them — at a glance which
 * days are busy and which are empty. Filters: month, project, people.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$period  = sap_period_sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ?? '' ) ) );
$pid     = absint( $_GET['p'] ?? 0 );
$all_u   = sap_team_users();
$picked  = isset( $_GET['u'] ) ? array_map( 'intval', (array) wp_unslash( $_GET['u'] ) ) : array();
// phpcs:enable
// Who is looking: full manager (everything, add tasks), project manager (everything, read only), member (own tasks only).
$mode    = sap_can() ? 'full' : ( sap_is_pm() ? 'pm' : 'member' );
$me      = get_current_user_id();
if ( 'pm' === $mode ) {
	// The people filter lists only those this project manager gave tasks to (and themselves).
	global $wpdb;
	$given = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT assignee FROM ' . sap_table( 'tasks' ) . ' WHERE created_by = %d', $me ) ) ); // phpcs:ignore
	$given[] = $me;
	$all_u   = array_values( array_filter( $all_u, function ( $u ) use ( $given ) {
		return in_array( (int) $u->ID, $given, true );
	} ) );
}
$filter  = isset( $_GET['f'] ) ? ( $picked ?: array( -1 ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
if ( count( array_intersect( array_merge( array( 0 ), array_map( 'intval', wp_list_pluck( $all_u, 'ID' ) ) ), $filter ) ) === count( $all_u ) + 1 ) {
	$filter = array(); // Everyone ticked = no filter.
}
if ( 'member' === $mode ) {
	$filter = array( $me );
}
// Only full managers see everyone; a project manager sees their own tasks and the ones they assigned.
$owner   = 'pm' === $mode || sap_is_coord() ? $me : 0;
if ( sap_is_coord() ) {
	$filter = array(); // Own tasks and the ones they delegated.
}
$by_day  = sap_calendar_tasks( $period, $pid, $filter, $owner );
$by_item = sap_calendar_check_items( $period, $pid, $filter, $owner );
// Where a chip leads, by viewer.
$task_link = function ( $t ) use ( $mode, $me ) {
	if ( 'full' === $mode ) {
		return sap_project_url( $t['project_id'], 'plan', array( 'task' => $t['id'] ) ) . '#task-' . $t['id'];
	}
	if ( sap_is_coord() && $t['coord_id'] === $me && $t['assignee'] !== $me ) {
		return sap_url( 'coord' ) . '#f-' . $t['id'];
	}
	if ( 'pm' === $mode && $t['created_by'] === $me ) {
		return sap_url( 'pmp', array( 'p' => $t['project_id'] ) ) . '#t-' . $t['id'];
	}
	return $t['assignee'] === $me && ! in_array( $t['status'], array( 'done', 'review', 'dropped' ), true ) ? sap_url( 'my' ) . '#task-' . $t['id'] : '';
};
$today   = current_time( 'Y-m-d' );
list( $jy, $jm ) = array_map( 'intval', explode( '-', $period ) );
$days    = sap_jmonth_days( $jy, $jm );
list( $start ) = sap_period_range( $period );
$projects = sap_projects();
if ( 'full' !== $mode ) {
	global $wpdb;
	$mine_p   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT project_id FROM ' . sap_table( 'tasks' ) . ' WHERE assignee = %d OR coord_id = %d OR ( %s = %s AND created_by = %d )', $me, $me, $mode, 'pm', $me ) ) ); // phpcs:ignore
	$projects = array_values( array_filter( $projects, function ( $p ) use ( $mine_p ) {
		return in_array( (int) $p['id'], $mine_p, true );
	} ) );
}
$pmap     = array();
foreach ( $projects as $p ) {
	$pmap[ $p['id'] ] = $p;
}
$n_all  = 0;
$n_done = 0;
foreach ( $by_day as $list ) {
	foreach ( $list as $t ) {
		++$n_all;
		$n_done += 'done' === $t['status'] ? 1 : 0;
	}
}
$empty_days = 0;
for ( $i = 0; $i < $days; $i++ ) {
	$d0 = gmdate( 'Y-m-d', strtotime( $start ) + $i * DAY_IN_SECONDS );
	if ( empty( $by_day[ $d0 ] ) && empty( $by_item[ $d0 ] ) && 5 !== (int) gmdate( 'w', strtotime( $d0 ) ) ) {
		++$empty_days;
	}
}
$is_on      = function ( $uid ) use ( $filter ) {
	return ! $filter || in_array( (int) $uid, $filter, true );
};
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان · <?php echo 'member' === $mode ? 'تقویم من' : ( 'pm' === $mode ? 'مدیر پروژه' : 'میز کار' ); ?></p>
		<h1><?php echo 'full' === $mode ? 'تقویم کارهای' : 'تقویم کارهای من'; ?> · <?php echo esc_html( sap_period_label( $period ) ); ?></h1>
		<p class="sap-muted"><?php echo 'member' === $mode ? 'کارهای این ماهت روز به روز: انجام‌شده‌ها خط خورده‌اند. ماه‌های قبل را هم از بالا ببین.' : ( 'pm' === $mode ? 'کارهایی که واگذار کرده‌اید (و کارهای خودتان) روز به روز: انجام‌شده، در حال انجام، عقب‌افتاده و منتظر تأیید.' : 'همه کارهای ددلاین‌دار این ماه و موارد تاریخ‌دار چک‌لیست‌ها، روز به روز. روزهای خلوت را ببینید و کار برایشان بگذارید.' ); ?></p>
	</div>
	<div class="sap-cal-nav">
		<a class="sap-btn is-ghost is-small" href="<?php echo esc_url( add_query_arg( 'period', sap_period_shift( $period, -1 ) ) ); ?>">‹ <?php echo esc_html( sap_period_label( sap_period_shift( $period, -1 ) ) ); ?></a>
		<a class="sap-btn is-ghost is-small" href="<?php echo esc_url( add_query_arg( 'period', sap_period_shift( $period, 1 ) ) ); ?>"><?php echo esc_html( sap_period_label( sap_period_shift( $period, 1 ) ) ); ?> ›</a>
	</div>
</header>

<form method="get" class="sap-cal-filters" action="<?php echo esc_url( strtok( sap_url( 'calendar' ), '?' ) ); ?>">
	<?php sap_filter_form( sap_url( 'calendar' ) ); ?>
	<input type="hidden" name="f" value="1">
	<label>ماه<select name="period" onchange="this.form.submit()">
		<?php foreach ( sap_period_options( 12, 6 ) as $k => $label ) : ?>
			<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( $label ); ?><?php echo sap_period_current() === $k ? ' (ماه جاری)' : ''; ?></option>
		<?php endforeach; ?>
	</select></label>
	<label>پروژه<select name="p" onchange="this.form.submit()">
		<option value="0">همه پروژه‌ها</option>
		<?php foreach ( $projects as $p ) : ?>
			<option value="<?php echo esc_attr( $p['id'] ); ?>" <?php selected( $p['id'], $pid ); ?>><?php echo esc_html( $p['name'] ); ?></option>
		<?php endforeach; ?>
	</select></label>
	<?php if ( 'member' !== $mode ) : ?>
	<details class="sap-cal-people">
		<summary><?php echo sap_icon( 'users', 15 ); // phpcs:ignore ?>افراد: <b><?php echo ! $filter ? 'همه' : ( array( -1 ) === $filter ? 'هیچ‌کس' : esc_html( sap_num( count( $filter ) ) . ' نفر' ) ); ?></b></summary>
		<div class="sap-cal-people-list">
			<?php foreach ( $all_u as $u ) : ?>
				<label class="sap-check"><input type="checkbox" name="u[]" value="<?php echo esc_attr( $u->ID ); ?>" <?php checked( $is_on( $u->ID ) ); ?>><?php echo sap_avatar( $u->ID, 22 ); // phpcs:ignore ?><?php echo esc_html( $u->display_name ); ?></label>
			<?php endforeach; ?>
			<label class="sap-check"><input type="checkbox" name="u[]" value="0" <?php checked( $is_on( 0 ) ); ?>><span class="sap-cal-noone">؟</span>بدون مسئول</label>
			<div class="sap-cal-people-foot">
				<button type="button" class="sap-linkbtn" data-sap-cal-all="1">همه</button>
				<button type="button" class="sap-linkbtn" data-sap-cal-all="0">هیچ‌کس</button>
				<button class="sap-btn is-primary is-small" type="submit">اعمال</button>
			</div>
		</div>
	</details>
	<?php endif; ?>
	<div class="sap-cal-legend">
		<span class="is-open">باز</span><span class="is-doing">در حال انجام</span><span class="is-review">منتظر تأیید</span><span class="is-late">عقب‌افتاده</span><span class="is-done">انجام شد</span>
	</div>
</form>

<div class="sap-cal-stats">
	<div><b><?php echo esc_html( sap_num( $n_all ) ); ?></b><span><?php echo 'member' === $mode ? 'کار این ماه' : 'کار ددلاین‌دار'; ?></span></div>
	<div class="is-good"><b><?php echo esc_html( sap_num( $n_done ) ); ?></b><span>انجام شده</span></div>
	<div><b><?php echo esc_html( sap_num( $n_all - $n_done ) ); ?></b><span>باقی‌مانده</span></div>
	<div class="is-soft"><b><?php echo esc_html( sap_num( $empty_days ) ); ?></b><span>روز خالی</span></div>
</div>

<div class="sap-cal-table" role="table">
	<?php for ( $i = 0; $i < $days; $i++ ) : ?>
		<?php
		$date  = gmdate( 'Y-m-d', strtotime( $start ) + $i * DAY_IN_SECONDS );
		$list  = $by_day[ $date ] ?? array();
		$items = $by_item[ $date ] ?? array();
		$wd    = (int) gmdate( 'w', strtotime( $date ) );
		$cls   = array( 'sap-cal-row' );
		$cls[] = $date === $today ? 'is-today' : '';
		$cls[] = 5 === $wd ? 'is-friday' : '';
		$cls[] = $list || $items ? '' : 'is-empty';
		$cls[] = $date < $today ? 'is-past' : '';
		?>
		<div class="<?php echo esc_attr( trim( implode( ' ', array_filter( $cls ) ) ) ); ?>" role="row" id="d-<?php echo esc_attr( $date ); ?>">
			<div class="sap-cal-date" role="cell">
				<b><?php echo esc_html( sap_fa( $i + 1 ) ); ?></b>
				<span><?php echo esc_html( sap_weekday_fa( $date ) ); ?></span>
				<small><?php echo esc_html( sap_jdate( $date, 'short' ) ); ?></small>
			</div>
			<div class="sap-cal-tasks" role="cell">
				<?php foreach ( $list as $t ) : ?>
					<?php
					$late = $t['due_date'] < $today && ! in_array( $t['status'], array( 'done', 'review' ), true );
					$st   = 'done' === $t['status'] ? 'is-done' : ( 'review' === $t['status'] ? 'is-review' : ( $late ? 'is-late' : ( 'doing' === $t['status'] ? 'is-doing' : 'is-open' ) ) );
					$prog = $t['checklist'] ? sap_checklist_progress( $t ) : null;
					?>
					<?php $href = $task_link( $t ); ?>
					<<?php echo $href ? 'a href="' . esc_url( $href ) . '"' : 'span'; // phpcs:ignore ?> class="sap-cal-task <?php echo esc_attr( $st ); ?>" title="<?php echo esc_attr( ( $pmap[ $t['project_id'] ]['name'] ?? '' ) . ' · ' . ( sap_task_statuses()[ $t['status'] ] ?? '' ) ); ?>">
						<?php if ( ! $pid ) : ?><i class="sap-dot" style="background:<?php echo esc_attr( sap_member_color( $t['project_id'] + 3 ) ); ?>"></i><?php endif; ?>
						<span class="sap-cal-title"><?php echo esc_html( $t['title'] ); ?></span>
						<?php $ps = sap_progress_summary( $t ); ?>
						<?php if ( $ps['blocked'] ) : ?><em class="sap-cal-flag" title="<?php echo esc_attr( 'گیر کرده: ' . $t['waiting_for'] ); ?>">⚠️</em><?php endif; ?>
						<?php if ( $ps['count'] ) : ?><em class="sap-cal-logs" title="گزارش روزانه">🗒️<?php echo esc_html( sap_num( $ps['count'] ) ); ?></em><?php endif; ?>
						<?php if ( $prog ) : ?><em class="sap-cal-prog"><?php echo esc_html( sap_num( $prog['done'] ) . '/' . sap_num( $prog['total'] ) ); ?></em><?php endif; ?>
						<?php if ( 'member' !== $mode ) : ?><span class="sap-cal-who"><?php echo $t['assignee'] ? sap_avatar( $t['assignee'], 18 ) . esc_html( sap_first_name( sap_user_name( $t['assignee'] ) ) ) : '<span class="sap-cal-noone">؟</span>بدون مسئول'; // phpcs:ignore ?></span><?php endif; ?>
					</<?php echo $href ? 'a' : 'span'; ?>>
				<?php endforeach; ?>
				<?php foreach ( $items as $x ) : ?>
					<?php
					$it   = $x['item'];
					$xt   = $x['task'];
					$ist  = $it['d'] ? 'is-done' : ( $date < $today ? 'is-late' : 'is-open' );
					$href = $task_link( $xt );
					?>
					<<?php echo $href ? 'a href="' . esc_url( $href ) . '"' : 'span'; // phpcs:ignore ?> class="sap-cal-task is-item <?php echo esc_attr( $ist ); ?>" title="<?php echo esc_attr( 'مورد چک‌لیست از «' . $xt['title'] . '»' ); ?>">
						<span class="sap-cal-item-ico"><?php echo $it['d'] ? '☑' : '☐'; ?></span>
						<span class="sap-cal-title"><?php echo esc_html( $it['t'] ); ?></span>
						<small class="sap-cal-of">از «<?php echo esc_html( mb_strimwidth( $xt['title'], 0, 34, '…' ) ); ?>»</small>
						<?php if ( 'member' !== $mode && $xt['assignee'] ) : ?><span class="sap-cal-who"><?php echo sap_avatar( $xt['assignee'], 18 ); // phpcs:ignore ?><?php echo esc_html( sap_first_name( sap_user_name( $xt['assignee'] ) ) ); ?></span><?php endif; ?>
					</<?php echo $href ? 'a' : 'span'; ?>>
				<?php endforeach; ?>
				<?php if ( ! $list && ! $items ) : ?><span class="sap-cal-free"><?php echo 5 === $wd ? 'تعطیل' : 'روز خالی'; ?></span><?php endif; ?>
				<?php if ( 'full' === $mode && $date >= $today && 5 !== $wd ) : ?>
					<?php if ( $pid ) : ?>
						<a class="sap-cal-add" href="<?php echo esc_url( sap_project_url( $pid, 'plan', array( 'new_due' => $date, 'period' => sap_period_of( $date ) ) ) ); ?>#capture" title="افزودن کار برای این روز">+</a>
					<?php else : ?>
						<details class="sap-cal-addmenu">
							<summary class="sap-cal-add" title="افزودن کار برای این روز">+</summary>
							<div><?php foreach ( $projects as $p ) : ?><a href="<?php echo esc_url( sap_project_url( $p['id'], 'plan', array( 'new_due' => $date, 'period' => sap_period_of( $date ) ) ) ); ?>#capture"><i class="sap-dot" style="background:<?php echo esc_attr( sap_member_color( $p['id'] + 3 ) ); ?>"></i><?php echo esc_html( $p['name'] ); ?></a><?php endforeach; ?></div>
						</details>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	<?php endfor; ?>
</div>
