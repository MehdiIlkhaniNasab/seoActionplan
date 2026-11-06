<?php
/**
 * Project manager › projects: every task of one project, tidy and filterable —
 * what is in progress, late, stuck, waiting for approval and done, with the
 * daily reports and final reports inside each row.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$projects = sap_projects();
$pid      = absint( $_GET['p'] ?? 0 );
$period   = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : sap_period_current();
$period   = 'all' === $period ? 'all' : sap_period_sanitize( $period );
$who      = absint( $_GET['who'] ?? 0 );
// phpcs:enable
// A project manager follows only the tasks they assigned; full managers see every task.
$mine_only = ! sap_can();
$me        = get_current_user_id();
$scope     = function ( $ts ) use ( $mine_only, $me ) {
	return $mine_only ? array_values( array_filter( $ts, function ( $t ) use ( $me ) {
		return $t['created_by'] === $me;
	} ) ) : $ts;
};
if ( $mine_only ) {
	global $wpdb;
	$mine_p   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT project_id FROM ' . sap_table( 'tasks' ) . ' WHERE created_by = %d', $me ) ) ); // phpcs:ignore
	$projects = array_values( array_filter( $projects, function ( $p ) use ( $mine_p ) {
		return in_array( (int) $p['id'], $mine_p, true );
	} ) );
	if ( $pid && ! in_array( $pid, $mine_p, true ) ) {
		$pid = 0;
	}
}
if ( ! $pid && $projects ) {
	$pid = (int) $projects[0]['id'];
}
$project = $pid ? sap_project( $pid ) : null;

// Counts per project (this month's open tasks) for the project list.
$side = array();
foreach ( $projects as $p ) {
	$ts = $scope( sap_tasks( array( 'project_id' => $p['id'], 'period' => 'all' === $period ? '' : $period ) ) );
	$ts = array_filter( $ts, function ( $t ) {
		return ! in_array( $t['status'], array( 'dropped' ), true );
	} );
	$side[ $p['id'] ] = array(
		'all'  => count( $ts ),
		'done' => count( array_filter( $ts, function ( $t ) {
			return 'done' === $t['status'];
		} ) ),
		'late' => count( array_filter( $ts, function ( $t ) {
			return in_array( sap_task_bucket( $t ), array( 'late', 'stuck' ), true );
		} ) ),
	);
}

$tasks = $project ? $scope( sap_tasks( array( 'project_id' => $pid, 'period' => 'all' === $period ? '' : $period ) ) ) : array();
$tasks = array_values( array_filter( $tasks, function ( $t ) use ( $who ) {
	return 'dropped' !== $t['status'] && ( ! $who || $t['assignee'] === $who );
} ) );
// Order: needs attention first (stuck, late, review), then in progress, not started, done.
$rank = array( 'stuck' => 0, 'late' => 1, 'review' => 2, 'open' => 3, 'proposed' => 4, 'done' => 5 );
usort( $tasks, function ( $a, $b ) use ( $rank ) {
	return array( $rank[ sap_task_bucket( $a ) ] ?? 9, (string) ( $a['due_date'] ?: '9999' ) ) <=> array( $rank[ sap_task_bucket( $b ) ] ?? 9, (string) ( $b['due_date'] ?: '9999' ) );
} );
$counts = array( 'all' => count( $tasks ), 'open' => 0, 'late' => 0, 'stuck' => 0, 'review' => 0, 'done' => 0, 'proposed' => 0 );
$people = array();
foreach ( $tasks as $t ) {
	$b = sap_task_bucket( $t );
	if ( isset( $counts[ $b ] ) ) {
		++$counts[ $b ];
	}
}
foreach ( $project ? $scope( sap_tasks( array( 'project_id' => $pid ) ) ) : array() as $t ) {
	if ( $t['assignee'] ) {
		$people[ $t['assignee'] ] = true;
	}
}
$pct  = $counts['all'] ? (int) round( 100 * $counts['done'] / $counts['all'] ) : 0;
$base = array( 'p' => $pid, 'period' => $period, 'who' => $who );
$tabs = array( 'all' => 'همه', 'open' => 'در جریان', 'late' => 'عقب‌افتاده', 'stuck' => 'گیر کرده', 'review' => 'منتظر تأیید', 'done' => 'انجام شده', 'proposed' => 'پیشنهادی' );
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان · مدیر پروژه</p>
		<h1>پروژه‌ها</h1>
		<p class="sap-muted"><?php echo $mine_only ? 'کارهایی که در هر پروژه واگذار کرده‌اید، در یک نگاه: چه چیزی در جریان است، کدام عقب افتاده یا گیر کرده، چه چیزی منتظر تأیید شماست و چه کارهایی انجام شده.' : 'همه کارهای هر پروژه در یک نگاه: چه چیزی در جریان است، کدام عقب افتاده یا گیر کرده، چه چیزی منتظر تأیید است و چه کارهایی انجام شده.'; ?></p>
	</div>
</header>

<div class="sap-pmp">
	<aside class="sap-pmp-side">
		<?php foreach ( $projects as $p ) : ?>
			<?php $c = $side[ $p['id'] ]; ?>
			<a class="sap-pmp-proj<?php echo $p['id'] === $pid ? ' is-active' : ''; ?>" href="<?php echo esc_url( sap_url( 'pmp', array_merge( $base, array( 'p' => $p['id'], 'who' => 0 ) ) ) ); ?>">
				<i class="sap-dot" style="background:<?php echo esc_attr( sap_member_color( $p['id'] + 3 ) ); ?>"></i>
				<span><b><?php echo esc_html( $p['name'] ); ?></b><small dir="ltr"><?php echo esc_html( sap_clean_domain( $p['site_url'] ) ); ?></small></span>
				<em><?php echo esc_html( sap_num( $c['done'] ) . '/' . sap_num( $c['all'] ) ); ?></em>
				<?php if ( $c['late'] ) : ?><strong title="عقب‌افتاده یا گیر کرده"><?php echo esc_html( sap_num( $c['late'] ) ); ?></strong><?php endif; ?>
			</a>
		<?php endforeach; ?>
	</aside>

	<section class="sap-pmp-main">
		<?php if ( ! $project ) : ?>
			<div class="sap-empty"><p><?php echo $mine_only ? 'هنوز کاری واگذار نکرده‌اید. از «میز کار مدیر پروژه» کار ثبت کنید؛ بعد از تأیید، اینجا پیگیری‌اش می‌کنید.' : 'پروژه‌ای وجود ندارد.'; ?></p></div>
		<?php else : ?>
			<div class="sap-pmp-top">
				<div>
					<h2><?php echo esc_html( $project['name'] ); ?></h2>
					<div class="sap-progress is-cap"><i class="is-done" style="width:<?php echo esc_attr( $pct ); ?>%"></i></div>
					<small class="sap-muted"><?php echo esc_html( sprintf( '%s از %s کار انجام شده (%s٪)%s', sap_num( $counts['done'] ), sap_num( $counts['all'] ), sap_num( $pct ), 'all' === $period ? ' · همه ماه‌ها' : ' · ' . sap_period_label( $period ) ) ); ?></small>
				</div>
				<form method="get" class="sap-pmp-filters" action="<?php echo esc_url( strtok( sap_url( 'pmp' ), '?' ) ); ?>">
					<?php sap_filter_form( sap_url( 'pmp' ) ); ?>
					<input type="hidden" name="p" value="<?php echo esc_attr( $pid ); ?>">
					<select name="period" onchange="this.form.submit()">
						<option value="all" <?php selected( 'all', $period ); ?>>همه ماه‌ها</option>
						<?php foreach ( sap_period_options( 6, 2 ) as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( $l ); ?><?php echo sap_period_current() === $k ? ' (ماه جاری)' : ''; ?></option>
						<?php endforeach; ?>
					</select>
					<select name="who" onchange="this.form.submit()">
						<option value="0">همه افراد</option>
						<?php foreach ( array_keys( $people ) as $uid ) : ?>
							<option value="<?php echo esc_attr( $uid ); ?>" <?php selected( $uid, $who ); ?>><?php echo esc_html( sap_user_name( $uid ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>

			<div class="sap-pmp-kpis">
				<div class="is-info"><b><?php echo esc_html( sap_num( $counts['open'] ) ); ?></b><span>در جریان</span></div>
				<div class="<?php echo $counts['late'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['late'] ) ); ?></b><span>عقب‌افتاده</span></div>
				<div class="<?php echo $counts['stuck'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $counts['stuck'] ) ); ?></b><span>گیر کرده</span></div>
				<div class="is-warn"><b><?php echo esc_html( sap_num( $counts['review'] ) ); ?></b><span>منتظر تأیید</span></div>
				<div class="is-good"><b><?php echo esc_html( sap_num( $counts['done'] ) ); ?></b><span>انجام شده</span></div>
			</div>

			<div class="sap-pm-track" data-sap-pm-track>
				<div class="sap-seg-mini sap-pmp-tabs">
					<?php foreach ( $tabs as $k => $l ) : ?>
						<?php if ( 'all' !== $k && ! $counts[ $k ] ) { continue; } ?>
						<button type="button" class="<?php echo 'all' === $k ? 'is-active' : ''; ?>" data-sap-pm-tab="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?> <em><?php echo esc_html( sap_num( $counts[ $k ] ) ); ?></em></button>
					<?php endforeach; ?>
				</div>
				<?php if ( $tasks ) : ?>
					<?php
					$active = array_filter( $tasks, function ( $t ) {
						return 'done' !== $t['status'];
					} );
					$done_l = array_filter( $tasks, function ( $t ) {
						return 'done' === $t['status'];
					} );
					?>
					<div class="sap-pm-rows">
						<?php foreach ( $active as $t ) : ?>
							<?php sap_view( 'pm-row', array( 't' => $t, 'show_project' => false ) ); ?>
						<?php endforeach; ?>
						<?php if ( ! $active ) : ?><p class="sap-muted sap-small">کار بازی نمانده؛ همه انجام شده‌اند. 🌿</p><?php endif; ?>
					</div>
					<?php if ( $done_l ) : ?>
						<details class="sap-pmp-done" data-sap-pmp-done>
							<summary>✅ انجام‌شده‌ها <em><?php echo esc_html( sap_num( count( $done_l ) ) ); ?></em></summary>
							<div class="sap-pm-rows">
								<?php foreach ( $done_l as $t ) : ?>
									<?php sap_view( 'pm-row', array( 't' => $t, 'show_project' => false ) ); ?>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endif; ?>
				<?php else : ?>
					<div class="sap-empty"><p>در این بازه کاری برای این پروژه ثبت نشده.</p></div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>
</div>
