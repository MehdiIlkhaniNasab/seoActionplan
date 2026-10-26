<?php
/**
 * Project overview: focuses & goals, weekly review, this month, inbox, data.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$period   = sap_period_current();
$focuses  = sap_focuses( $pid );
$counts   = sap_findings_counts( $pid );
$tasks    = sap_tasks( array( 'project_id' => $pid, 'period' => $period ) );
$review   = sap_weekly_review( $pid );
$status   = sap_dataset_status( $pid );
$top      = sap_findings( $pid, array( 'limit' => 6 ) );
$by_st    = array();
$hours    = 0;
$done_h   = 0;
foreach ( $tasks as $t ) {
	$by_st[ $t['status'] ] = ( $by_st[ $t['status'] ] ?? 0 ) + 1;
	if ( 'dropped' !== $t['status'] ) {
		$hours += $t['effort'];
	}
	if ( 'done' === $t['status'] ) {
		$done_h += $t['effort'];
	}
}
$analysis = $project['options']['last_analysis'] ?? null;
?>
<div class="sap-overview">
	<section class="sap-panel">
		<div class="sap-panel-head">
			<h2><?php echo sap_icon( 'target' ); // phpcs:ignore ?>Focus و اهداف</h2>
			<a href="<?php echo esc_url( sap_project_url( $pid, 'focuses' ) ); ?>">مدیریت ←</a>
		</div>
		<?php if ( ! $focuses ) : ?>
			<p class="sap-muted">هنوز Focus تعریف نشده. Focus مشخص می‌کند پروژه روی چه بخشی از سایت (مثلاً دسته «اسکوتر برقی») تمرکز دارد تا یافته‌ها و اکشن پلن در راستای آن اولویت بگیرند.</p>
			<a class="sap-btn" href="<?php echo esc_url( sap_project_url( $pid, 'focuses' ) ); ?>">تعریف Focus</a>
		<?php else : ?>
			<div class="sap-focus-grid">
				<?php
				foreach ( $focuses as $f ) :
					$k    = sap_focus_kpis( $f, $pid );
					$cur  = $k ? $k[ $f['kpi_metric'] ] : null;
					$prog = sap_focus_progress( $f, $cur );
					?>
					<div class="sap-focus-card <?php echo 'active' !== $f['status'] ? 'is-muted' : ''; ?>">
						<h3><?php echo esc_html( $f['name'] ); ?><small><?php echo esc_html( sap_num( $counts['focus'][ $f['id'] ] ?? 0 ) ); ?> یافته باز</small></h3>
						<?php if ( $f['goal'] ) : ?>
							<p><?php echo esc_html( $f['goal'] ); ?></p>
						<?php endif; ?>
						<div class="sap-kpi">
							<span><?php echo esc_html( sap_kpi_metrics()[ $f['kpi_metric'] ] ); ?></span>
							<b><?php echo null === $cur ? '—' : esc_html( sap_num( $cur ) ); ?></b>
							<?php if ( $f['kpi_target'] > 0 ) : ?>
								<small>شروع <?php echo esc_html( sap_num( $f['kpi_baseline'] ) ); ?> · هدف <?php echo esc_html( sap_num( $f['kpi_target'] ) ); ?><?php echo $f['deadline'] ? ' تا ' . esc_html( sap_jdate( $f['deadline'] ) ) : ''; ?></small>
							<?php endif; ?>
						</div>
						<?php if ( null !== $prog ) : ?>
							<div class="sap-progress"><i style="width:<?php echo esc_attr( round( $prog * 100 ) ); ?>%"></i></div>
							<small class="sap-muted"><?php echo esc_html( sap_num( $prog * 100 ) ); ?>٪ مسیر تا هدف</small>
						<?php endif; ?>
						<?php if ( $k ) : ?>
							<small class="sap-muted"><?php echo esc_html( sprintf( '%s کلمه هدف · %s رتبه‌دار · %s در صفحه اول', sap_num( $k['keywords'] ), sap_num( $k['ranking'] ), sap_num( $k['top10'] ) ) ); ?></small>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<div class="sap-grid-2">
		<section class="sap-panel">
			<div class="sap-panel-head">
				<h2><?php echo sap_icon( 'list' ); // phpcs:ignore ?>اکشن پلن <?php echo esc_html( sap_period_label( $period ) ); ?></h2>
				<a href="<?php echo esc_url( sap_project_url( $pid, 'plan' ) ); ?>">باز کردن ←</a>
			</div>
			<?php if ( ! $tasks ) : ?>
				<p class="sap-muted">برای این ماه هنوز برنامه‌ای ساخته نشده.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php sap_form_fields( 'sap_plan_generate', array( 'project_id' => $pid, 'period' => $period ) ); ?>
					<button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>ساخت خودکار اکشن پلن این ماه</button>
				</form>
			<?php else : ?>
				<div class="sap-mini-stats">
					<?php foreach ( array( 'next', 'doing', 'waiting', 'done' ) as $s ) : ?>
						<span><b><?php echo esc_html( sap_num( $by_st[ $s ] ?? 0 ) ); ?></b><?php echo esc_html( sap_task_statuses()[ $s ] ); ?></span>
					<?php endforeach; ?>
				</div>
				<div class="sap-progress"><i style="width:<?php echo esc_attr( round( 100 * $done_h / max( 1, $hours ) ) ); ?>%"></i></div>
				<small class="sap-muted"><?php echo esc_html( sprintf( '%s از %s ساعت انجام شده · ظرفیت %s ساعت', sap_num( $done_h, 1 ), sap_num( $hours, 1 ), sap_num( $project['capacity_hours'] ) ) ); ?></small>
			<?php endif; ?>
		</section>

		<section class="sap-panel <?php echo $review['due'] ? 'is-attention' : ''; ?>">
			<div class="sap-panel-head">
				<h2><?php echo sap_icon( 'clock' ); // phpcs:ignore ?>بازبینی هفتگی (GTD)</h2>
				<small class="sap-muted"><?php echo $review['last'] ? 'آخرین: ' . esc_html( sap_jdate( $review['last'] ) ) : 'هنوز انجام نشده'; ?></small>
			</div>
			<ul class="sap-review">
				<li><b><?php echo esc_html( sap_num( $counts['total'] ) ); ?></b> یافته باز در صندوق ورودی<?php echo null !== $review['new_found'] ? ' (' . esc_html( sap_num( $review['new_found'] ) ) . ' مورد جدید از بازبینی قبل)' : ''; ?></li>
				<li><b><?php echo esc_html( sap_num( count( $review['overdue'] ) ) ); ?></b> کار عقب‌افتاده از سررسید</li>
				<li><b><?php echo esc_html( sap_num( count( $review['waiting'] ) ) ); ?></b> مورد «در انتظار» بدون پیگیری بیش از ۷ روز</li>
				<li><b><?php echo esc_html( sap_num( $review['open_period'] ) ); ?></b> کار باز در ماه جاری</li>
			</ul>
			<?php foreach ( array_slice( array_merge( $review['overdue'], $review['waiting'] ), 0, 5 ) as $t ) : ?>
				<p class="sap-review-item">• <?php echo esc_html( $t['title'] ); ?><?php echo $t['waiting_for'] ? ' — منتظر ' . esc_html( $t['waiting_for'] ) : ''; ?></p>
			<?php endforeach; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php sap_form_fields( 'sap_review_done', array( 'project_id' => $pid ) ); ?>
				<button class="sap-btn is-small" type="submit"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>بازبینی این هفته انجام شد</button>
			</form>
		</section>
	</div>

	<section class="sap-panel">
		<div class="sap-panel-head">
			<h2><?php echo sap_icon( 'inbox' ); // phpcs:ignore ?>مهم‌ترین یافته‌های باز</h2>
			<a href="<?php echo esc_url( sap_project_url( $pid, 'findings' ) ); ?>">همه (<?php echo esc_html( sap_num( $counts['total'] ) ); ?>) ←</a>
		</div>
		<?php if ( $counts['context'] ) : ?>
			<div class="sap-chips">
				<?php foreach ( $counts['context'] as $c => $n ) : ?>
					<a class="sap-chip ctx-<?php echo esc_attr( $c ); ?>" href="<?php echo esc_url( sap_project_url( $pid, 'findings', array( 'context' => $c ) ) ); ?>"><?php echo esc_html( sap_context_label( $c ) . ' · ' . sap_num( $n ) ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php if ( ! $top ) : ?>
			<p class="sap-muted"><?php echo $analysis ? 'یافته بازی وجود ندارد.' : 'هنوز تحلیلی اجرا نشده.'; ?></p>
		<?php else : ?>
			<ul class="sap-list">
				<?php foreach ( $top as $f ) : ?>
					<li><?php echo sap_score_badge( $f['score'] ); // phpcs:ignore ?><span><?php echo esc_html( $f['title'] ); ?></span><?php echo sap_context_chip( $f['context'] ); // phpcs:ignore ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="sap-panel">
		<div class="sap-panel-head"><h2>منابع داده</h2></div>
		<table class="sap-table">
			<thead><tr><th>منبع</th><th>آخرین دریافت</th><th>جزئیات</th></tr></thead>
			<tbody>
				<?php foreach ( sap_dataset_labels() as $src => $label ) : ?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td><?php echo isset( $status[ $src ] ) ? esc_html( sap_jdate( $status[ $src ]['fetched_at'] ) ) : '<span class="sap-muted">—</span>'; ?></td>
						<td class="sap-muted">
							<?php
							$m = $status[ $src ]['meta'] ?? array();
							if ( isset( $m['count'] ) ) {
								echo esc_html( sap_num( $m['count'] ) . ' ردیف' );
							} elseif ( isset( $m['urls'] ) ) {
								echo esc_html( sap_num( $m['urls'] ) . ' آدرس' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $analysis && ! empty( $analysis['skipped'] ) ) : ?>
			<p class="sap-muted">قوانینی که به‌دلیل نبودن داده اجرا نشدند: <?php echo esc_html( implode( '، ', array_map( function ( $d ) {
				return sap_detectors()[ $d ][0] ?? $d;
			}, $analysis['skipped'] ) ) ); ?></p>
		<?php endif; ?>
	</section>
</div>
