<?php
/**
 * Content pillar › «آپدیت محتوا»: this cycle's content updates and the five update
 * strategies, each a scored list of candidate pages from real data.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid    = $project['id'];
$defs   = sap_upd_strategies();
$ds     = sap_dataset( $pid, 'upd_strategies' );
$lists  = $ds ? (array) $ds['payload'] : array();
$period = sap_period_current( $pid );
$c      = sap_commit( $project );
$ups    = sap_updates( $pid, array( 'period' => $period ) );
$ups    = array_values( array_filter( $ups, function ( $u ) {
	return 'cancelled' !== $u['status'];
} ) );
$planned = array_flip( array_map( function ( $u ) {
	return sap_norm_url( $u['url'] );
}, $ups ) );
$ranked = $ds ? sap_upd_ranked( $pid ) : array();
$ai     = (array) get_option( 'sap_upd_ai_' . $pid, array() );
$stl    = sap_update_statuses();
$tab    = isset( $_GET['s'] ) && ( isset( $defs[ sanitize_key( $_GET['s'] ) ] ) || 'all' === $_GET['s'] ) ? sanitize_key( $_GET['s'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
$row    = function ( $x, $s, $strategies = array() ) use ( $pid, $planned, $ai, $defs, $period ) {
	$k  = sap_norm_url( $x['url'] );
	$in = isset( $planned[ $k ] );
	?>
	<article class="sap-upd-c">
		<div class="sap-upd-c-score" style="--v:<?php echo esc_attr( (int) $x['score'] ); ?>%" title="امتیاز بازدهی در این استراتژی"><b><?php echo esc_html( sap_num( (int) $x['score'] ) ); ?></b></div>
		<div class="sap-upd-c-body">
			<h4><a href="<?php echo esc_url( $x['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $x['title'] ); ?></a>
				<?php foreach ( $strategies as $ss => $unused ) : ?><span class="sap-chip is-s"><?php echo esc_html( $defs[ $ss ]['icon'] . ' ' . $defs[ $ss ]['name'] ); ?></span><?php endforeach; ?>
			</h4>
			<p class="sap-upd-c-why"><?php echo esc_html( $x['why'] ); ?></p>
			<?php if ( 's5' !== $s && null !== $x['pos'] ) : ?>
				<div class="sap-upd-c-kpi"><span><?php echo esc_html( sap_num( $x['impr'] ) ); ?> نمایش</span><span><?php echo esc_html( sap_num( $x['clicks'] ) ); ?> کلیک</span><span>CTR <?php echo esc_html( sap_num( $x['ctr'], 1 ) ); ?>٪</span><span>جایگاه <?php echo esc_html( sap_num( $x['pos'], 1 ) ); ?></span></div>
			<?php endif; ?>
			<?php if ( ! empty( $x['queries'] ) && in_array( $s, array( 's1', 's2' ), true ) ) : ?>
				<ul class="sap-upd-q">
					<?php foreach ( array_slice( $x['queries'], 0, 5 ) as $q ) : ?>
						<li><b><?php echo esc_html( $q['q'] ); ?></b><small><?php echo esc_html( sap_num( $q['impr'] ) . ' نمایش · ' . sap_num( $q['clicks'] ) . ' کلیک · جایگاه ' . sap_num( $q['pos'], 1 ) ); ?></small><?php if ( ! $q['in_title'] ) : ?><em class="is-n">نه در تایتل</em><?php endif; ?><?php if ( false === $q['in_text'] ) : ?><em class="is-n">نه در متن</em><?php endif; ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $x['gap'] ) || ! empty( $x['links'] ) ) : ?>
				<ul class="sap-upd-q">
					<?php foreach ( (array) ( $x['gap'] ?? array() ) as $g ) : ?><li><b><?php echo esc_html( $g['q'] ); ?></b><small><?php echo esc_html( sap_num( $g['impr'] ) . ' نمایش · جایگاه ' . sap_num( $g['pos'], 1 ) ); ?></small><em class="is-n"><?php echo 'text' === $g['missing'] ? 'در متن نیست → H2/H3 جدید' : 'در تیترها نیست → H3'; ?></em></li><?php endforeach; ?>
					<?php foreach ( (array) ( $x['links'] ?? array() ) as $l ) : ?><li><b><?php echo esc_html( $l['q'] ); ?></b><small><?php echo esc_html( sap_num( $l['impr'] ) ); ?> نمایش</small><em class="is-l">لینک داخلی به <?php echo esc_html( sap_short_url( $l['to'], 40 ) ); ?></em></li><?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $x['drops'] ) ) : ?>
				<ul class="sap-upd-q">
					<?php foreach ( array_slice( $x['drops'], 0, 5 ) as $d ) : ?><li><b><?php echo esc_html( $d['q'] ); ?></b><small>نمایش <?php echo esc_html( sap_num( $d['was'] ) . ' → ' . sap_num( $d['is'] ) ); ?><?php echo null !== $d['pos_is'] ? ' · جایگاه ' . esc_html( sap_num( $d['pos_was'], 1 ) . ' → ' . sap_num( $d['pos_is'], 1 ) ) : ''; ?></small><em class="is-n">افت</em></li><?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php $a = $ai[ $k . '|' . $s ] ?? null; ?>
			<div class="sap-upd-c-ai" data-sap-upd-ai-box><?php if ( $a ) { sap_view( 'upd-ai', array( 'a' => $a ) ); } ?></div>
			<div class="sap-upd-c-acts">
				<?php if ( $in ) : ?>
					<span class="sap-chip is-good">✓ در آپدیت‌های این دوره</span>
				<?php else : ?>
					<button type="button" class="sap-btn is-small is-primary" data-sap-upd-add="<?php echo esc_attr( $x['url'] ); ?>" data-s="<?php echo esc_attr( $strategies ? '' : $s ); ?>">+ آپدیت <?php echo esc_html( sap_period_label( $period ) ); ?></button>
				<?php endif; ?>
				<button type="button" class="sap-btn is-small is-soft" data-sap-upd-ai="<?php echo esc_attr( $x['url'] ); ?>" data-s="<?php echo esc_attr( $s ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?><?php echo $a ? 'تحلیل دوباره' : 'تحلیل هوشمند صفحه'; ?></button>
				<?php if ( empty( $x['read'] ) && 's5' !== $s ) : ?><small class="sap-muted">متن صفحه هنوز خوانده نشده</small><?php endif; ?>
			</div>
		</div>
	</article>
	<?php
};
?>
<section class="sap-panel sap-upd-head" data-sap-upd="<?php echo esc_attr( $pid ); ?>">
	<div class="sap-panel-head">
		<div>
			<h2><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>آپدیت محتوا</h2>
			<p class="sap-muted">صفحه‌هایی که آپدیتشان بیشترین بازدهی را دارد، بر اساس ۵ استراتژی آپدیت و داده واقعی سرچ کنسول. هر صفحه‌ای که انتخاب کنید به آپدیت‌های دوره و شیت آپدیت محتوا می‌رود و در کارهای هفتگی واگذار می‌شود.</p>
		</div>
		<button type="button" class="sap-btn is-primary" data-sap-upd-refresh><?php echo sap_icon( 'refresh', 16 ); // phpcs:ignore ?><?php echo $ds ? 'تحلیل دوباره با داده تازه' : 'تحلیل صفحات'; ?></button>
	</div>
	<?php if ( $ds ) : ?>
		<p class="sap-small sap-muted">آخرین تحلیل: <?php echo esc_html( sap_jdate( $ds['fetched_at'] ) . ' ' . substr( $ds['fetched_at'], 11, 5 ) ); ?><?php echo empty( $ds['meta']['w90'] ) ? ' · مقایسه افت با ۲۸ روز قبل (۹۰ روزه بعد از اتصال حساب گوگل)' : ' · مقایسه افت: ۹۰ روز با ۹۰ روز قبل'; ?><?php echo ! empty( $ds['meta']['notes'] ) ? ' · ' . esc_html( implode( '؛ ', $ds['meta']['notes'] ) ) : ''; ?></p>
	<?php endif; ?>

	<div class="sap-upd-cycle">
		<div><span>آپدیت‌های <?php echo esc_html( sap_period_label( $period ) ); ?></span><b><?php echo esc_html( sap_num( count( $ups ) ) ); ?><?php echo $c['content_upd'] ? '<small> / ' . esc_html( sap_num( $c['content_upd'] ) ) . ' تعهد ماهانه</small>' : ''; ?></b></div>
		<div><span>انجام‌شده</span><b><?php echo esc_html( sap_num( count( array_filter( $ups, function ( $u ) {
			return 'done' === $u['status'];
		} ) ) ) ); ?></b></div>
		<div><span>دوره</span><b><small><?php echo esc_html( sap_period_dates( $period, $pid ) ); ?></small></b></div>
	</div>
	<?php if ( $ups ) : ?>
		<div class="sap-table-wrap">
			<table class="sap-table is-compact sap-upd-list">
				<thead><tr><th>صفحه</th><th>استراتژی / دلیل</th><th>وضعیت</th><th>کار</th></tr></thead>
				<tbody>
					<?php foreach ( $ups as $u ) : ?>
						<?php $t = $u['task_id'] ? sap_task( $u['task_id'] ) : null; preg_match( '/◆ استراتژی: ([^\n]+)/u', $u['reason'], $mm ); ?>
						<tr>
							<td><a href="<?php echo esc_url( $u['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $u['title'] ); ?></a></td>
							<td class="sap-small"><?php echo esc_html( $mm[1] ?? mb_strimwidth( preg_replace( '/\s+/u', ' ', $u['reason'] ), 0, 90, '…' ) ); ?></td>
							<td><span class="sap-chip"><?php echo esc_html( $stl[ $u['status'] ] ?? $u['status'] ); ?></span></td>
							<td class="sap-small"><?php echo $t ? esc_html( sap_task_state_chip( $t )[0] . ' · ' . ( sap_user_name( $t['assignee'] ) ?: 'بدون مسئول' ) ) : '<span class="sap-muted">در کارهای هفتگی ساخته می‌شود</span>'; // phpcs:ignore ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<?php if ( ! $ds ) : ?>
	<div class="sap-empty"><p>برای دیدن صفحات هر استراتژی، «تحلیل صفحات» را بزنید. سکان داده سرچ کنسول (۲۸ و ۹۰ روزه)، متن صفحات مهم و وضعیت ایندکس را می‌خواند (حدود ۳۰ تا ۹۰ ثانیه).</p></div>
	<?php return; ?>
<?php endif; ?>

<nav class="sap-seg-mini sap-upd-tabs">
	<a class="<?php echo 'all' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'updates', array( 's' => 'all' ) ) ); ?>">⭐ بهترین‌ها <em><?php echo esc_html( sap_num( min( 15, count( $ranked ) ) ) ); ?></em></a>
	<?php foreach ( $defs as $s => $d ) : ?>
		<a class="<?php echo $s === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'updates', array( 's' => $s ) ) ); ?>"><?php echo esc_html( $d['icon'] . ' ' . $d['name'] ); ?> <em><?php echo esc_html( sap_num( count( (array) ( $lists[ $s ] ?? array() ) ) ) ); ?></em></a>
	<?php endforeach; ?>
</nav>

<section class="sap-panel">
	<?php if ( 'all' === $tab ) : ?>
		<h3 class="sap-upd-title">بهترین صفحه‌ها برای آپدیت</h3>
		<p class="sap-muted sap-small">همه استراتژی‌ها با هم؛ صفحه‌ای که چند استراتژی رویش توافق دارند بالاتر است. «+ آپدیت» همه شواهد و گام‌های همه استراتژی‌های آن صفحه را در بریف می‌گذارد.</p>
		<?php foreach ( array_slice( $ranked, 0, 15, true ) as $k => $r ) : ?>
			<?php $first = array_key_first( $r['strategies'] ); $x = $r['strategies'][ $first ]; $x['score'] = (int) min( 100, round( $r['value'] ) ); ?>
			<?php $row( $x, $first, $r['strategies'] ); ?>
		<?php endforeach; ?>
		<?php if ( ! $ranked ) : ?><p class="sap-muted">صفحه‌ای پیدا نشد.</p><?php endif; ?>
	<?php else : ?>
		<?php $d = $defs[ $tab ]; ?>
		<div class="sap-upd-def">
			<h3><?php echo esc_html( $d['icon'] . ' ' . $d['name'] ); ?></h3>
			<p><?php echo esc_html( $d['goal'] ); ?></p>
			<details><summary>گام‌های اجرا</summary><ol><?php foreach ( $d['steps'] as $st ) : ?><li><?php echo esc_html( $st ); ?></li><?php endforeach; ?></ol></details>
		</div>
		<?php foreach ( (array) ( $lists[ $tab ] ?? array() ) as $x ) : ?>
			<?php $row( $x, $tab ); ?>
		<?php endforeach; ?>
		<?php if ( empty( $lists[ $tab ] ) ) : ?><p class="sap-muted">در داده فعلی صفحه‌ای برای این استراتژی پیدا نشد.</p><?php endif; ?>
	<?php endif; ?>
</section>
