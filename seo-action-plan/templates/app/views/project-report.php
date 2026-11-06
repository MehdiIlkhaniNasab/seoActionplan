<?php
/**
 * Project report for the client (any project, made for execution projects):
 * summary, what was done (with the person's report and date), what is in
 * progress and what is blocked, time spent per person. Printable / PDF.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid    = $project['id'];
$period = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
$period = 'all' === $period ? 'all' : sap_period_sanitize( $period );
$st     = sap_project_task_stats( $pid, 'all' === $period ? '' : $period );
$pct    = $st['all'] ? (int) round( 100 * $st['done'] / $st['all'] ) : 0;
$done   = array_values( array_filter( $st['tasks'], function ( $t ) {
	return 'done' === $t['status'];
} ) );
usort( $done, function ( $a, $b ) {
	return strcmp( (string) $b['done_at'], (string) $a['done_at'] );
} );
$open   = array_values( array_filter( $st['tasks'], function ( $t ) {
	return 'done' !== $t['status'];
} ) );
$stuck  = array_values( array_filter( $open, function ( $t ) {
	return 'stuck' === sap_task_bucket( $t );
} ) );
$people = array();
foreach ( $st['tasks'] as $t ) {
	if ( ! $t['assignee'] ) {
		continue;
	}
	$k = $t['assignee'];
	$people[ $k ] = $people[ $k ] ?? array( 'done' => 0, 'all' => 0, 'spent' => 0.0 );
	++$people[ $k ]['all'];
	$people[ $k ]['done']  += 'done' === $t['status'] ? 1 : 0;
	$people[ $k ]['spent'] += $t['spent'];
}
$label = 'all' === $period ? 'از ابتدای پروژه تا ' . sap_jdate( current_time( 'Y-m-d' ) ) : sap_period_label( $period );
?>
<div class="sap-report-tools no-print">
	<form method="get" class="sap-inline" action="<?php echo esc_url( strtok( sap_project_url( $pid, 'report' ), '?' ) ); ?>">
		<?php sap_filter_form( sap_project_url( $pid, 'report' ) ); ?>
		<select name="period" onchange="this.form.submit()">
			<option value="all" <?php selected( 'all', $period ); ?>>کل پروژه</option>
			<?php foreach ( sap_period_options( 12, 1 ) as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
		</select>
	</form>
	<button type="button" class="sap-btn is-primary" onclick="window.print()"><?php echo sap_icon( 'download', 15 ); // phpcs:ignore ?>چاپ / ذخیره PDF</button>
</div>

<article class="sap-preport">
	<header class="sap-preport-head">
		<div>
			<p class="sap-eyebrow">گزارش پروژه · <?php echo esc_html( $label ); ?></p>
			<h2><?php echo esc_html( $project['name'] ); ?></h2>
			<?php if ( ! sap_project_is_seo( $project ) ) : ?><p class="sap-muted"><?php echo esc_html( sap_ops_types()[ $project['options']['ops_type'] ?? 'other' ] ?? '' ); ?><?php echo ! empty( $project['options']['ops_end'] ) ? ' · تحویل ' . esc_html( sap_jdate( $project['options']['ops_end'] ) ) : ''; ?></p><?php endif; ?>
		</div>
		<div class="sap-preport-pct"><b><?php echo esc_html( sap_num( $pct ) ); ?>٪</b><span>پیشرفت</span></div>
	</header>

	<div class="sap-preport-kpis">
		<div><b><?php echo esc_html( sap_num( $st['done'] ) ); ?></b><span>کار انجام شده</span></div>
		<div><b><?php echo esc_html( sap_num( count( $open ) ) ); ?></b><span>در دست انجام</span></div>
		<div><b><?php echo esc_html( sap_num( $st['spent'] ?: $st['hours'], 1 ) ); ?></b><span><?php echo $st['spent'] ? 'ساعت کار ثبت‌شده' : 'ساعت کار برآوردی (کل)'; ?></span></div>
		<div class="<?php echo $stuck ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( count( $stuck ) ) ); ?></b><span>مانع باز</span></div>
	</div>

	<section>
		<h3>✅ کارهای انجام‌شده</h3>
		<?php if ( $done ) : ?>
			<table class="sap-preport-table">
				<thead><tr><th>کار</th><th>تاریخ</th><th>انجام‌دهنده</th><th>شرح انجام</th></tr></thead>
				<tbody>
					<?php foreach ( $done as $t ) : ?>
						<tr>
							<td><b><?php echo esc_html( $t['title'] ); ?></b><?php if ( $t['checklist'] ) : ?><small><?php echo esc_html( sap_num( count( $t['checklist'] ) ) ); ?> مورد چک‌لیست</small><?php endif; ?></td>
							<td class="sap-nowrap"><?php echo esc_html( $t['done_at'] ? sap_jdate( substr( $t['done_at'], 0, 10 ), 'day' ) : '—' ); ?></td>
							<td class="sap-nowrap"><?php echo esc_html( sap_user_name( $t['assignee'] ) ?: '—' ); ?></td>
							<td><?php echo '' !== $t['result'] ? nl2br( esc_html( mb_strimwidth( $t['result'], 0, 400, '…' ) ) ) : '<span class="sap-muted">—</span>'; // phpcs:ignore ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="sap-muted">در این بازه کاری تمام نشده.</p>
		<?php endif; ?>
	</section>

	<?php if ( $open ) : ?>
		<section>
			<h3>⏳ در دست انجام</h3>
			<table class="sap-preport-table">
				<thead><tr><th>کار</th><th>وضعیت</th><th>مسئول</th><th>ددلاین</th><th>آخرین گزارش</th></tr></thead>
				<tbody>
					<?php foreach ( $open as $t ) : ?>
						<?php $sl = sap_task_state_chip( $t ); $ps = sap_progress_summary( $t ); $prog = $t['checklist'] ? sap_checklist_progress( $t ) : null; ?>
						<tr>
							<td><b><?php echo esc_html( $t['title'] ); ?></b><?php if ( $prog ) : ?><small><?php echo esc_html( sap_num( $prog['done'] ) . ' از ' . sap_num( $prog['total'] ) . ' مورد' ); ?></small><?php endif; ?></td>
							<td><span class="sap-chip <?php echo esc_attr( $sl[1] ); ?>"><?php echo esc_html( $sl[0] ); ?></span></td>
							<td class="sap-nowrap"><?php echo esc_html( sap_user_name( $t['assignee'] ) ?: '—' ); ?></td>
							<td class="sap-nowrap"><?php echo esc_html( $t['due_date'] ? sap_jdate( $t['due_date'], 'day' ) : '—' ); ?></td>
							<td><?php echo $ps['last'] ? esc_html( mb_strimwidth( $ps['last']['text'], 0, 160, '…' ) ) . ( 'blocked' === ( $ps['last']['state'] ?? '' ) ? '<br><small class="sap-plog-why">⚠️ ' . esc_html( $ps['last']['why'] ) . '</small>' : '' ) : '<span class="sap-muted">—</span>'; // phpcs:ignore ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>

	<?php if ( $people ) : ?>
		<section>
			<h3>👥 سهم اعضای تیم</h3>
			<table class="sap-preport-table is-small">
				<thead><tr><th>نام</th><th>کار انجام‌شده</th><th>کل کارها</th><th>ساعت ثبت‌شده</th></tr></thead>
				<tbody>
					<?php foreach ( $people as $uid => $pp ) : ?>
						<tr><td><?php echo esc_html( sap_user_name( $uid ) ); ?></td><td><?php echo esc_html( sap_num( $pp['done'] ) ); ?></td><td><?php echo esc_html( sap_num( $pp['all'] ) ); ?></td><td><?php echo esc_html( sap_num( $pp['spent'], 1 ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>
	<p class="sap-preport-foot">تهیه‌شده با سُکان · رونق — <?php echo esc_html( sap_jdate( current_time( 'Y-m-d' ) ) ); ?></p>
</article>
