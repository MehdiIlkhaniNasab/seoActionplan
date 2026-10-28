<?php
/**
 * Impact of the work: every finished action with a page, its Search Console
 * numbers before and after, net of the whole site, the verdict, and what this
 * site's results teach (fed back into the AI action plan).
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$pid    = $project['id'];
$period = isset( $_GET['period'] ) ? sap_period_sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ) ) ) : '';
$show   = isset( $_GET['v'] ) ? sanitize_key( $_GET['v'] ) : '';
// phpcs:enable
if ( isset( $_GET['period'] ) && 'all' === $_GET['period'] ) { // phpcs:ignore WordPress.Security.NonceVerification
	$period = '';
}
$ds       = sap_dataset( $pid, 'impact' );
$items    = sap_impact_items( $pid, $period );
$s        = sap_impact_summary( $pid, $period );
$verdicts = sap_impact_verdicts();
$pillars  = sap_pillars();
$pending  = count( sap_impact_actions( $project ) ) - count( (array) ( $ds['payload']['items'] ?? array() ) );
$rows     = array_filter( $items, function ( $i ) use ( $show ) {
	return '' === $show || ( $i['verdict'] ?? 'wait' ) === $show;
} );
uasort( $rows, function ( $a, $b ) {
	return strcmp( $b['done'], $a['done'] );
} );
$pct = function ( $v, $plus = true ) {
	if ( null === $v ) {
		return '<span class="sap-muted">—</span>';
	}
	$cls = $v > 0 ? 'is-up' : ( $v < 0 ? 'is-down' : '' );
	return '<span class="sap-delta ' . $cls . '" dir="ltr">' . ( $plus && $v > 0 ? '+' : '' ) . esc_html( sap_num( $v ) ) . '٪</span>';
};
$base = sap_project_url( $pid, 'impact' );
?>
<section class="sap-panel sap-pillar-head">
	<div class="sap-pillar-intro">
		<h2><?php echo sap_icon( 'target' ); // phpcs:ignore ?>ارزیابی اثر اقدامات</h2>
		<p class="sap-muted">هر کار انجام‌شده‌ای که صفحه مشخص دارد (کار اکشن پلن، آپدیت و بازنویسی محتوا، ریدایرکت هرس) با داده سرچ کنسول سنجیده می‌شود: ۲۸ روز قبل از انجام در برابر روزهای بعد از آن (از روز سوم؛ خوانش ۳۰ و ۹۰ روزه)، به ازای هر روز. «اثر خالص» یعنی تغییر صفحه منهای تغییر کل سایت در همان بازه، تا فصل و تغییرات گوگل اثر کار ما حساب نشوند. با کلمه کلیدی کار، جایگاه قبل و بعد هم می‌آید. نتیجه به هوش مصنوعی برمی‌گردد تا اکشن پلن بعدی روی کارهایی که روی این سایت جواب داده تمرکز کند.</p>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-primary" data-sap-step="impact" data-project="<?php echo esc_attr( $pid ); ?>" data-force <?php disabled( '' === $project['gsc_property'] ); ?>><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>سنجش اثر اقدامات</button>
			<form method="get" action="<?php echo esc_url( $base ); ?>" class="sap-inline">
				<select name="period" onchange="this.form.submit()">
					<option value="all">همه ماه‌ها</option>
					<?php foreach ( sap_period_options( 6, 0 ) as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>>انجام‌شده در <?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
		</div>
		<?php if ( $ds ) : ?><p class="sap-small sap-muted">آخرین سنجش: <?php echo esc_html( sap_jdate( $ds['fetched_at'] ) ); ?><?php echo $pending > 0 ? ' · ' . esc_html( sap_num( $pending ) ) . ' اقدام تازه هنوز سنجیده نشده' : ''; ?> · سنجش خودکار در «تحلیل کامل» و همگام‌سازی هفتگی هم انجام می‌شود.</p><?php endif; ?>
	</div>
</section>

<?php if ( ! $items ) : ?>
	<div class="sap-empty"><?php echo sap_icon( 'target', 32 ); // phpcs:ignore ?><p>هنوز اقدامی سنجیده نشده. کارهای اکشن پلن را با آدرس صفحه «انجام شد» بزنید و آپدیت‌های محتوا را تکمیل کنید؛ ۱۰ روز بعد اولین خوانش و ۳۰ روز بعد نتیجه کامل می‌آید. بعد دکمه «سنجش اثر اقدامات» را بزنید.</p></div>
<?php else : ?>
	<div class="sap-audit-stats">
		<?php foreach ( array( '' => array( $s['total'], 'اقدام سنجیده‌شده', '' ), 'growth' => array( $s['growth'], 'رشد', 'is-good' ), 'decline' => array( $s['decline'], 'افت', $s['decline'] ? 'is-bad' : '' ), 'neutral' => array( $s['neutral'], 'بدون تغییر محسوس', '' ), 'wait' => array( $s['wait'] + $s['low'], 'در انتظار / داده کم', '' ) ) as $k => $st ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array_filter( array( 'v' => $k, 'period' => $period ) ), $base ) ); ?>" class="<?php echo $k === $show ? 'is-active' : ''; ?> <?php echo esc_attr( $st[2] ); ?>"><b><?php echo esc_html( sap_num( $st[0] ) ); ?></b><span><?php echo esc_html( $st[1] ); ?></span></a>
		<?php endforeach; ?>
		<div class="is-score"><b dir="ltr"><?php echo esc_html( sap_num( $s['clicks_before'] ) ); ?> → <?php echo esc_html( sap_num( $s['clicks_after'] ) ); ?></b><span>کلیک ۲۸ روزه صفحات (قبل → بعد)</span></div>
	</div>

	<div class="sap-impact-pillars">
		<?php foreach ( $pillars as $pk => $pl ) : ?>
			<?php $bp = $s['by_pillar'][ $pk ] ?? null; ?>
			<div class="sap-impact-pillar">
				<span class="sap-tech-ico"><?php echo sap_icon( $pl[1] ); // phpcs:ignore ?></span>
				<div>
					<b><?php echo esc_html( $pl[0] ); ?></b>
					<?php if ( $bp ) : ?>
						<small><?php echo esc_html( sprintf( '%s اقدام · %s رشد · %s افت', sap_num( $bp['total'] ), sap_num( $bp['growth'] ), sap_num( $bp['decline'] ) ) ); ?></small>
					<?php else : ?>
						<small class="sap-muted">اقدام سنجیده‌شده‌ای نیست</small>
					<?php endif; ?>
				</div>
				<?php if ( $bp && null !== $bp['avg'] ) : ?><span class="sap-impact-avg"><?php echo $pct( $bp['avg'] ); // phpcs:ignore ?><small>میانگین اثر خالص</small></span><?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( $s['lessons'] ) : ?>
		<section class="sap-panel sap-impact-lessons">
			<h3><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>بازخورد: چه چیزی روی این سایت جواب داده؟</h3>
			<ul><?php foreach ( $s['lessons'] as $l ) : ?><li><?php echo esc_html( $l ); ?></li><?php endforeach; ?></ul>
			<p class="sap-small sap-muted">این بازخورد در ساخت اکشن پلن بعدی به هوش مصنوعی داده می‌شود.</p>
		</section>
	<?php endif; ?>

	<section class="sap-panel">
		<div class="sap-table-wrap">
			<table class="sap-table sap-impact-table">
				<thead><tr><th>اقدام</th><th>تاریخ</th><th>کلیک در روز (قبل → بعد)</th><th>نمایش</th><th>جایگاه</th><th>اثر خالص</th><th>نتیجه</th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $i ) : ?>
						<?php $v = $i['verdict'] ?? 'wait'; ?>
						<tr>
							<td class="sap-impact-act">
								<b><?php echo esc_html( $i['title'] ); ?></b>
								<span><?php echo sap_page_link( $i['url'], sap_page_title( $pid, $i['url'] ) ?: sap_short_url( $i['url'], 50 ) ); // phpcs:ignore ?></span>
								<small><span class="sap-chip is-soft"><?php echo esc_html( $pillars[ $i['pillar'] ][0] ?? '' ); ?></span> <?php echo esc_html( sap_context_label( $i['context'] ) ); ?><?php echo '' !== $i['keyword'] ? ' · «' . esc_html( $i['keyword'] ) . '»' : ''; ?></small>
							</td>
							<td class="sap-nowrap sap-small"><?php echo esc_html( sap_jdate( $i['done'], 'short' ) ); ?></td>
							<?php if ( isset( $i['before'] ) ) : ?>
								<td class="sap-nowrap"><span dir="ltr"><?php echo esc_html( sap_num( $i['before']['clicks'] / $i['before']['days'], 1 ) . ' → ' . sap_num( $i['after']['clicks'] / $i['after']['days'], 1 ) ); ?></span> <?php echo $pct( $i['clicks_change'] ); // phpcs:ignore ?><?php if ( ! empty( $i['early'] ) ) : ?><br><small class="sap-muted">خوانش اولیه <?php echo esc_html( sap_num( $i['after_days'] ) ); ?> روزه</small><?php endif; ?></td>
								<td><?php echo $pct( $i['impr_change'] ); // phpcs:ignore ?></td>
								<td class="sap-nowrap sap-small">
									<?php
									$pb = isset( $i['kw_before'] ) ? $i['kw_before'] : $i['before']['position'];
									$pa = isset( $i['kw_after'] ) ? $i['kw_after'] : $i['after']['position'];
									echo null === $pb && null === $pa ? '—' : '<span dir="ltr">' . esc_html( ( null === $pb ? '—' : sap_num( $pb, 1 ) ) . ' → ' . ( null === $pa ? '—' : sap_num( $pa, 1 ) ) ) . '</span>'; // phpcs:ignore
									?>
									<?php if ( isset( $i['kw_before'] ) ) : ?><br><small class="sap-muted">کلمه کار</small><?php endif; ?>
								</td>
								<td><?php echo $pct( $i['net_change'] ); // phpcs:ignore ?><?php if ( null !== $i['site_change'] ) : ?><br><small class="sap-muted">سایت: <?php echo esc_html( ( $i['site_change'] > 0 ? '+' : '' ) . sap_num( $i['site_change'] ) ); ?>٪</small><?php endif; ?></td>
							<?php else : ?>
								<td colspan="4" class="sap-muted sap-small">هنوز <?php echo esc_html( sap_num( (int) ( $i['after_days'] ?? 0 ) ) ); ?> روز داده بعد از انجام دارد؛ از ۱۰ روز به بعد سنجیده می‌شود.</td>
							<?php endif; ?>
							<td>
								<span class="sap-chip <?php echo $verdicts[ $v ][1] ? 'is-' . esc_attr( $verdicts[ $v ][1] ) : ''; ?>"><?php echo esc_html( $verdicts[ $v ][0] ); ?></span>
								<?php if ( ! empty( $i['after90'] ) && ! empty( $i['before'] ) ) : ?>
									<?php $c90 = $i['before']['clicks'] ? round( ( $i['after90']['clicks'] / $i['after90']['days'] - $i['before']['clicks'] / $i['before']['days'] ) / ( $i['before']['clicks'] / $i['before']['days'] ) * 100 ) : null; ?>
									<br><small class="sap-muted">۹۰ روز: <?php echo $pct( $c90 ); // phpcs:ignore ?></small>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
<?php endif; ?>
