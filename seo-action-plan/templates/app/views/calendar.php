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
$filter  = isset( $_GET['f'] ) ? ( $picked ?: array( -1 ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
if ( count( array_intersect( array_merge( array( 0 ), array_map( 'intval', wp_list_pluck( $all_u, 'ID' ) ) ), $filter ) ) === count( $all_u ) + 1 ) {
	$filter = array(); // Everyone ticked = no filter.
}
$by_day  = sap_calendar_tasks( $period, $pid, $filter );
$today   = current_time( 'Y-m-d' );
list( $jy, $jm ) = array_map( 'intval', explode( '-', $period ) );
$days    = sap_jmonth_days( $jy, $jm );
list( $start ) = sap_period_range( $period );
$projects = sap_projects();
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
$empty_days = $days - count( $by_day );
$is_on      = function ( $uid ) use ( $filter ) {
	return ! $filter || in_array( (int) $uid, $filter, true );
};
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان · میز کار</p>
		<h1>تقویم کارهای <?php echo esc_html( sap_period_label( $period ) ); ?></h1>
		<p class="sap-muted">همه کارهای ددلاین‌دار این ماه، روز به روز. روزهای خلوت را ببینید و کار برایشان بگذارید.</p>
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
	<div class="sap-cal-legend">
		<span class="is-open">باز</span><span class="is-doing">در حال انجام</span><span class="is-review">منتظر تأیید</span><span class="is-late">عقب‌افتاده</span><span class="is-done">انجام شد</span>
	</div>
</form>

<div class="sap-cal-stats">
	<div><b><?php echo esc_html( sap_num( $n_all ) ); ?></b><span>کار ددلاین‌دار</span></div>
	<div class="is-good"><b><?php echo esc_html( sap_num( $n_done ) ); ?></b><span>انجام شده</span></div>
	<div><b><?php echo esc_html( sap_num( $n_all - $n_done ) ); ?></b><span>باقی‌مانده</span></div>
	<div class="is-soft"><b><?php echo esc_html( sap_num( $empty_days ) ); ?></b><span>روز خالی</span></div>
</div>

<div class="sap-cal-table" role="table">
	<?php for ( $i = 0; $i < $days; $i++ ) : ?>
		<?php
		$date  = gmdate( 'Y-m-d', strtotime( $start ) + $i * DAY_IN_SECONDS );
		$list  = $by_day[ $date ] ?? array();
		$wd    = (int) gmdate( 'w', strtotime( $date ) );
		$cls   = array( 'sap-cal-row' );
		$cls[] = $date === $today ? 'is-today' : '';
		$cls[] = 5 === $wd ? 'is-friday' : '';
		$cls[] = $list ? '' : 'is-empty';
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
					<a class="sap-cal-task <?php echo esc_attr( $st ); ?>" href="<?php echo esc_url( sap_project_url( $t['project_id'], 'plan', array( 'task' => $t['id'] ) ) ); ?>" title="<?php echo esc_attr( ( $pmap[ $t['project_id'] ]['name'] ?? '' ) . ' · ' . ( sap_task_statuses()[ $t['status'] ] ?? '' ) ); ?>">
						<?php if ( ! $pid ) : ?><i class="sap-dot" style="background:<?php echo esc_attr( sap_member_color( $t['project_id'] + 3 ) ); ?>"></i><?php endif; ?>
						<span class="sap-cal-title"><?php echo esc_html( $t['title'] ); ?></span>
						<?php if ( $prog ) : ?><em class="sap-cal-prog"><?php echo esc_html( sap_num( $prog['done'] ) . '/' . sap_num( $prog['total'] ) ); ?></em><?php endif; ?>
						<span class="sap-cal-who"><?php echo $t['assignee'] ? sap_avatar( $t['assignee'], 18 ) . esc_html( sap_first_name( sap_user_name( $t['assignee'] ) ) ) : '<span class="sap-cal-noone">؟</span>بدون مسئول'; // phpcs:ignore ?></span>
					</a>
				<?php endforeach; ?>
				<?php if ( ! $list ) : ?><span class="sap-cal-free">روز خالی</span><?php endif; ?>
				<?php if ( $date >= $today ) : ?>
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
