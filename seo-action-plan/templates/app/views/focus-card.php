<?php
/**
 * One focus at a glance: where it is now (with the exact dates), where it started,
 * the target, the pace against the deadline and a weekly chart since the start.
 *
 * @package SeoActionPlan
 * @var array $project
 * @var array $f
 */

defined( 'ABSPATH' ) || exit;

$pid    = $project['id'];
$k      = sap_focus_kpis( $f, $pid );
$st     = sap_focus_status( $f, $k );
$m      = $f['measure'];
$metric = $f['kpi_metric'];
$labels = array( 'clicks' => 'کلیک', 'impressions' => 'نمایش', 'top10' => 'کلمه در صفحه اول', 'top3' => 'کلمه در رتبه ۱ تا ۳' );
$range  = function ( $from, $to ) {
	return $from && $to ? sap_jdate( $from, 'day' ) . ' تا ' . sap_jdate( $to, 'day' ) : '';
};
$states = array( 'reached' => array( 'به هدف رسید', 'is-good' ), 'on' => array( 'در مسیر', 'is-info' ), 'behind' => array( 'عقب از برنامه', 'is-bad' ), 'nogoal' => array( 'بدون هدف عددی', '' ) );
$sname  = array( 'active' => 'فعال', 'paused' => 'متوقف', 'done' => 'تمام‌شده' )[ $f['status'] ] ?? '';
$weeks  = (array) ( $m['weeks'] ?? array() );
$is_traffic = in_array( $metric, array( 'clicks', 'impressions' ), true );
?>
<article class="sap-fc is-<?php echo esc_attr( $st['state'] ?? 'nogoal' ); ?>" id="focus-<?php echo esc_attr( $f['id'] ); ?>">
	<header class="sap-fc-head">
		<div>
			<h3><?php echo sap_icon( 'target', 18 ); // phpcs:ignore ?><?php echo esc_html( $f['name'] ); ?></h3>
			<p class="sap-muted sap-small">
				<?php echo esc_html( $sname ); ?>
				<?php if ( ! empty( $m['pages'] ) ) : ?> · <?php echo esc_html( sap_num( $m['pages'] ) ); ?> صفحه<?php endif; ?>
				· شروع <?php echo esc_html( sap_jdate( $f['started_on'] ) ); ?>
				<?php if ( $f['deadline'] ) : ?> · مهلت <?php echo esc_html( sap_jdate( $f['deadline'] ) ); ?><?php endif; ?>
			</p>
		</div>
		<?php if ( $st ) : ?><span class="sap-chip <?php echo esc_attr( $states[ $st['state'] ][1] ); ?>"><?php echo esc_html( $states[ $st['state'] ][0] ); ?></span><?php endif; ?>
	</header>

	<?php if ( ! $st ) : ?>
		<p class="sap-muted">داده سرچ کنسول هنوز برای این Focus نیست.</p>
	<?php else : ?>
		<div class="sap-fc-nums">
			<div class="sap-fc-now">
				<span>الان (<?php echo esc_html( $labels[ $metric ] ); ?><?php echo $is_traffic ? ' در ۲۸ روز' : ''; ?>)</span>
				<b><?php echo esc_html( sap_num( $st['current'] ) ); ?></b>
				<?php if ( $is_traffic && ! empty( $m['cur']['from'] ) ) : ?><small><?php echo esc_html( $range( $m['cur']['from'], $m['cur']['to'] ) ); ?></small><?php endif; ?>
			</div>
			<div>
				<span>نقطه شروع<?php echo $st['baseline']['manual'] ? ' (دستی)' : ''; ?></span>
				<b><?php echo esc_html( sap_num( $st['baseline']['value'] ) ); ?></b>
				<small>
					<?php if ( $st['baseline']['from'] ) : ?><?php echo esc_html( $range( $st['baseline']['from'], $st['baseline']['to'] ) ); ?><?php endif; ?>
					<?php if ( null !== $st['baseline']['measured'] && abs( $st['baseline']['measured'] - $st['baseline']['value'] ) > 0.5 ) : ?><br>سنجش واقعی همان بازه: <?php echo esc_html( sap_num( $st['baseline']['measured'] ) ); ?><?php endif; ?>
				</small>
			</div>
			<div>
				<span>هدف</span>
				<b><?php echo $st['target'] ? esc_html( sap_num( $st['target'] ) ) : '—'; ?></b>
				<small><?php echo null !== $st['days_left'] ? ( $st['days_left'] >= 0 ? esc_html( sap_num( $st['days_left'] ) ) . ' روز مانده' : 'مهلت گذشته' ) : ''; ?></small>
			</div>
			<div>
				<span>تغییر نسبت به شروع</span>
				<?php $d = $st['current'] - $st['baseline']['value']; $pc = $st['baseline']['value'] > 0 ? round( 100 * $d / $st['baseline']['value'] ) : null; ?>
				<b class="<?php echo $d > 0 ? 'sap-good' : ( $d < 0 ? 'sap-bad' : '' ); ?>"><?php echo esc_html( ( $d > 0 ? '+' : '' ) . sap_num( $d ) ); ?></b>
				<small><?php echo null !== $pc ? esc_html( ( $pc > 0 ? '+' : '' ) . sap_num( $pc ) ) . '٪' : ''; ?></small>
			</div>
		</div>
		<?php if ( null !== $st['pct'] ) : ?>
			<div class="sap-fc-bar"><i style="width:<?php echo esc_attr( round( 100 * $st['pct'] ) ); ?>%"></i></div>
			<p class="sap-fc-text"><b><?php echo esc_html( sap_num( round( 100 * $st['pct'] ) ) ); ?>٪ از مسیر</b> · <?php echo esc_html( $st['text'] ); ?></p>
		<?php else : ?>
			<p class="sap-fc-text sap-muted"><?php echo esc_html( $st['text'] ); ?></p>
		<?php endif; ?>

		<?php if ( $is_traffic && count( $weeks ) >= 3 ) : ?>
			<?php
			$idx  = 'clicks' === $metric ? 2 : 3;
			$vals = array_map( function ( $w ) use ( $idx ) {
				return (int) $w[ $idx ];
			}, $weeks );
			$tw   = $st['target'] ? $st['target'] / 4 : 0;
			$max  = max( 1, max( $vals ), $tw );
			$n    = count( $weeks );
			$bw   = 600 / $n;
			?>
			<figure class="sap-fc-chart">
				<svg viewBox="0 0 600 120" preserveAspectRatio="none" role="img" aria-label="روند هفتگی">
					<?php foreach ( $weeks as $i => $w ) : ?>
						<?php $h = 100 * $w[ $idx ] / $max; $after = $w[1] >= $f['started_on']; ?>
						<rect x="<?php echo esc_attr( round( $i * $bw + 2, 1 ) ); ?>" y="<?php echo esc_attr( round( 110 - $h, 1 ) ); ?>" width="<?php echo esc_attr( round( $bw - 4, 1 ) ); ?>" height="<?php echo esc_attr( round( $h, 1 ) ); ?>" rx="3" class="<?php echo $after ? 'is-after' : ''; ?>"><title><?php echo esc_html( $range( $w[0], $w[1] ) . ': ' . sap_num( $w[ $idx ] ) ); ?></title></rect>
					<?php endforeach; ?>
					<?php if ( $tw ) : ?><line x1="0" x2="600" y1="<?php echo esc_attr( round( 110 - 100 * $tw / $max, 1 ) ); ?>" y2="<?php echo esc_attr( round( 110 - 100 * $tw / $max, 1 ) ); ?>" class="is-target"/><?php endif; ?>
				</svg>
				<figcaption><span class="is-before">قبل از شروع</span><span class="is-after">بعد از شروع</span><?php echo $tw ? '<span class="is-target">هدف هفتگی (' . esc_html( sap_num( round( $tw ) ) ) . ')</span>' : ''; ?><span>هر ستون = یک هفته</span></figcaption>
			</figure>
		<?php endif; ?>
		<?php if ( ! empty( $m['err'] ) ) : ?><p class="sap-alert is-warn sap-small">سنجش زنده از سرچ کنسول انجام نشد (<?php echo esc_html( $m['err'] ); ?>)؛ عدد از آخرین همگام‌سازی است.</p><?php endif; ?>
		<?php if ( 'local' === ( $m['src'] ?? '' ) && empty( $m['err'] ) && ! empty( $m['pages'] ) ) : ?><p class="sap-muted sap-small">عدد از آخرین همگام‌سازی سرچ کنسول است؛ بعد از اتصال حساب گوگل، روند هفتگی و نقطه شروع دقیق هم حساب می‌شود.</p><?php endif; ?>
		<?php if ( empty( $m['pages'] ) && $is_traffic ) : ?><p class="sap-alert is-warn sap-small">برای این Focus صفحه‌ای انتخاب نشده؛ عدد فقط از کلمات کلیدی حساب شده و ناقص است. صفحه‌ها یا دسته‌ها را در ویرایش انتخاب کنید.</p><?php endif; ?>
	<?php endif; ?>

	<footer class="sap-fc-foot">
		<button type="button" class="sap-btn is-small is-soft" data-sap-focus-measure="<?php echo esc_attr( $f['id'] ); ?>"><?php echo sap_icon( 'refresh', 14 ); // phpcs:ignore ?>به‌روزرسانی از سرچ کنسول</button>
		<?php if ( ! empty( $m['at'] ) ) : ?><small class="sap-muted">آخرین سنجش: <?php echo esc_html( sap_jdate( $m['at'], 'day' ) . ' ' . substr( $m['at'], 11, 5 ) ); ?></small><?php endif; ?>
	</footer>
</article>
