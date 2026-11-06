<?php
/**
 * Overview of an execution project: progress against the delivery date,
 * what needs attention, who is doing what, next deadlines and today's reports.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$st    = sap_project_task_stats( $pid );
$today = current_time( 'Y-m-d' );
$pct   = $st['all'] ? (int) round( 100 * $st['done'] / $st['all'] ) : 0;
$start = (string) ( $project['options']['ops_start'] ?? '' );
$end   = (string) ( $project['options']['ops_end'] ?? '' );
$time  = null;
if ( $start && $end && $end > $start ) {
	$time = (int) max( 0, min( 100, round( 100 * ( strtotime( $today ) - strtotime( $start ) ) / ( strtotime( $end ) - strtotime( $start ) ) ) ) );
}
$open  = array_values( array_filter( $st['tasks'], function ( $t ) {
	return ! in_array( $t['status'], array( 'done' ), true );
} ) );
$attn  = array_values( array_filter( $open, function ( $t ) {
	return in_array( sap_task_bucket( $t ), array( 'stuck', 'late', 'review' ), true ) || ! $t['assignee'] || ! $t['due_date'] || sap_nudge_open( $t );
} ) );
$next  = array_values( array_filter( $open, function ( $t ) use ( $today ) {
	return $t['due_date'] && $t['due_date'] >= $today;
} ) );
usort( $next, function ( $a, $b ) {
	return strcmp( $a['due_date'], $b['due_date'] );
} );
$people = array();
foreach ( $open as $t ) {
	$k = $t['assignee'];
	if ( ! isset( $people[ $k ] ) ) {
		$people[ $k ] = array( 'open' => 0, 'late' => 0, 'hours' => 0.0 );
	}
	++$people[ $k ]['open'];
	$people[ $k ]['hours'] += $t['effort'];
	$people[ $k ]['late']  += in_array( sap_task_bucket( $t ), array( 'late', 'stuck' ), true ) ? 1 : 0;
}
$reports = array();
foreach ( $st['tasks'] as $t ) {
	foreach ( $t['progress'] as $e ) {
		if ( strtotime( (string) $e['at'] ) >= strtotime( $today . ' -2 days' ) ) {
			$reports[] = array( 'task' => $t, 'entry' => $e );
		}
	}
}
usort( $reports, function ( $a, $b ) {
	return strcmp( $b['entry']['at'], $a['entry']['at'] );
} );
$plan = sap_project_url( $pid, 'plan' );
$why  = function ( $t ) {
	$b = sap_task_bucket( $t );
	if ( 'stuck' === $b ) {
		return array( '⚠️ گیر کرده: ' . $t['waiting_for'], 'is-bad' );
	}
	if ( 'late' === $b ) {
		return array( sap_num( sap_days_late( $t['due_date'] ) ) . ' روز تأخیر', 'is-bad' );
	}
	if ( 'review' === $b ) {
		return array( 'گزارش منتظر تأیید شماست', 'is-info' );
	}
	if ( ! $t['assignee'] ) {
		return array( 'مسئول ندارد', 'is-warn' );
	}
	if ( ! $t['due_date'] ) {
		return array( 'ددلاین ندارد', 'is-warn' );
	}
	return array( '📣 منتظر پاسخ یادآوری', 'is-warn' );
};
?>
<div class="sap-ops">
	<section class="sap-panel sap-ops-hero">
		<div class="sap-ops-ring" style="--p:<?php echo esc_attr( $pct ); ?>"><b><?php echo esc_html( sap_num( $pct ) ); ?>٪</b><span>پیشرفت</span></div>
		<div class="sap-ops-hero-main">
			<h2><?php echo esc_html( sap_ops_types()[ $project['options']['ops_type'] ?? 'other' ] ?? 'پروژه اجرایی' ); ?></h2>
			<p class="sap-muted"><?php echo esc_html( sprintf( '%s از %s کار انجام شده · %s ساعت از %s ساعت برآوردی', sap_num( $st['done'] ), sap_num( $st['all'] ), sap_num( $st['done_hours'], 1 ), sap_num( $st['hours'], 1 ) ) ); ?></p>
			<?php if ( null !== $time ) : ?>
				<div class="sap-ops-time">
					<div class="sap-ops-bar"><i class="is-work" style="width:<?php echo esc_attr( $pct ); ?>%"></i><i class="is-time" style="inset-inline-start:<?php echo esc_attr( $time ); ?>%" title="امروز"></i></div>
					<small><?php echo esc_html( sap_jdate( $start, 'day' ) ); ?></small>
					<small class="<?php echo $pct + 10 < $time ? 'is-bad' : 'is-good'; ?>"><?php echo esc_html( $pct + 10 < $time ? 'عقب‌تر از برنامه: ' . sap_num( $time ) . '٪ زمان گذشته، ' . sap_num( $pct ) . '٪ کار انجام شده' : 'طبق برنامه پیش می‌رود' ); ?></small>
					<small>تحویل <?php echo esc_html( sap_jdate( $end, 'day' ) ); ?><?php echo $end >= $today ? ' · ' . esc_html( sap_num( (int) round( ( strtotime( $end ) - strtotime( $today ) ) / DAY_IN_SECONDS ) ) ) . ' روز مانده' : ''; ?></small>
				</div>
			<?php elseif ( ! $start || ! $end ) : ?>
				<p class="sap-small"><a href="<?php echo esc_url( sap_project_url( $pid, 'settings' ) ); ?>">تاریخ شروع و تحویل پروژه را ثبت کنید</a> تا پیشرفت کار با گذر زمان مقایسه شود.</p>
			<?php endif; ?>
		</div>
		<div class="sap-ops-kpis">
			<a href="<?php echo esc_url( $plan ); ?>"><b><?php echo esc_html( sap_num( $st['open'] ) ); ?></b><span>در جریان</span></a>
			<a href="<?php echo esc_url( add_query_arg( 'due', 'overdue', $plan ) ); ?>" class="<?php echo $st['late'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $st['late'] ) ); ?></b><span>عقب‌افتاده</span></a>
			<div class="<?php echo $st['stuck'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $st['stuck'] ) ); ?></b><span>گیر کرده</span></div>
			<a href="<?php echo esc_url( sap_url( 'desk' ) ); ?>#review" class="<?php echo $st['review'] ? 'is-warn' : ''; ?>"><b><?php echo esc_html( sap_num( $st['review'] ) ); ?></b><span>منتظر تأیید</span></a>
		</div>
	</section>

	<?php if ( ! $st['all'] ) : ?>
		<section class="sap-panel sap-ops-empty">
			<h3>این پروژه هنوز کاری ندارد</h3>
			<p class="sap-muted">از «کارها و پیگیری» کار ثبت کنید، یا با یک قالب شروع کنید:</p>
			<div class="sap-ops-tpls">
				<?php foreach ( sap_ops_templates() as $tk => $tv ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php sap_form_fields( 'sap_ops_seed', array( 'project_id' => $pid, 'tpl' => $tk ) ); ?>
						<button class="sap-btn is-soft" type="submit">🧩 <?php echo esc_html( $tv['label'] ); ?> <small>(<?php echo esc_html( sap_num( count( $tv['tasks'] ) ) ); ?> کار)</small></button>
					</form>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<div class="sap-ops-grid">
		<section class="sap-panel">
			<div class="sap-panel-head"><h2>🔔 نیاز به توجه <small><?php echo esc_html( sap_num( count( $attn ) ) ); ?></small></h2></div>
			<?php if ( $attn ) : ?>
				<ul class="sap-ops-list">
					<?php foreach ( array_slice( $attn, 0, 12 ) as $t ) : ?>
						<?php $w = $why( $t ); ?>
						<li><a href="<?php echo esc_url( sap_project_url( $pid, 'plan', array( 'task' => $t['id'] ) ) . '#task-' . $t['id'] ); ?>"><b><?php echo esc_html( $t['title'] ); ?></b><span class="sap-chip <?php echo esc_attr( $w[1] ); ?>"><?php echo esc_html( mb_strimwidth( $w[0], 0, 60, '…' ) ); ?></span><?php echo $t['assignee'] ? sap_avatar( $t['assignee'], 20 ) : ''; // phpcs:ignore ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="sap-muted">همه‌چیز روی روال است. 🌿</p>
			<?php endif; ?>
		</section>

		<section class="sap-panel">
			<div class="sap-panel-head"><h2>📅 ددلاین‌های پیش رو</h2></div>
			<?php if ( $next ) : ?>
				<ul class="sap-ops-list">
					<?php foreach ( array_slice( $next, 0, 8 ) as $t ) : ?>
						<?php $prog = $t['checklist'] ? sap_checklist_progress( $t ) : null; ?>
						<li><a href="<?php echo esc_url( sap_project_url( $pid, 'plan', array( 'task' => $t['id'] ) ) . '#task-' . $t['id'] ); ?>"><time><?php echo esc_html( $t['due_date'] === $today ? 'امروز' : sap_weekday_fa( $t['due_date'] ) . ' ' . sap_jdate( $t['due_date'], 'day' ) ); ?></time><b><?php echo esc_html( $t['title'] ); ?></b><?php if ( $prog ) : ?><span class="sap-mini-prog"><i style="width:<?php echo esc_attr( $prog['pct'] ); ?>%"></i><em><?php echo esc_html( sap_num( $prog['done'] ) . '/' . sap_num( $prog['total'] ) ); ?></em></span><?php endif; ?><?php echo $t['assignee'] ? sap_avatar( $t['assignee'], 20 ) : ''; // phpcs:ignore ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="sap-muted">ددلاین نزدیکی ثبت نشده.</p>
			<?php endif; ?>
		</section>

		<section class="sap-panel">
			<div class="sap-panel-head"><h2>👥 چه کسی چه می‌کند</h2></div>
			<?php if ( $people ) : ?>
				<ul class="sap-ops-people">
					<?php foreach ( $people as $uid => $pp ) : ?>
						<li><?php echo $uid ? sap_avatar( $uid, 30 ) : '<span class="sap-cal-noone">؟</span>'; // phpcs:ignore ?><b><?php echo esc_html( $uid ? sap_user_name( $uid ) : 'بدون مسئول' ); ?></b><span><?php echo esc_html( sap_num( $pp['open'] ) ); ?> کار · <?php echo esc_html( sap_num( $pp['hours'], 1 ) ); ?> ساعت</span><?php if ( $pp['late'] ) : ?><em><?php echo esc_html( sap_num( $pp['late'] ) ); ?> عقب</em><?php endif; ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="sap-muted">کار بازی نمانده.</p>
			<?php endif; ?>
		</section>

		<section class="sap-panel">
			<div class="sap-panel-head"><h2>🗒️ گزارش‌های اخیر <small>۳ روز اخیر</small></h2></div>
			<?php if ( $reports ) : ?>
				<ul class="sap-ops-reports">
					<?php foreach ( array_slice( $reports, 0, 8 ) as $x ) : ?>
						<li class="<?php echo 'blocked' === ( $x['entry']['state'] ?? '' ) ? 'is-blocked' : ''; ?>"><?php echo sap_avatar( (int) $x['entry']['u'], 22 ); // phpcs:ignore ?><div><b><?php echo esc_html( $x['task']['title'] ); ?></b><span><?php echo esc_html( mb_strimwidth( $x['entry']['text'], 0, 140, '…' ) ); ?></span></div><time><?php echo esc_html( sap_jdate( substr( $x['entry']['at'], 0, 10 ), 'day' ) ); ?></time></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="sap-muted">در سه روز اخیر گزارشی ثبت نشده.</p>
			<?php endif; ?>
		</section>
	</div>
</div>
