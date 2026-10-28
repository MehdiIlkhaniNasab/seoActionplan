<?php
/**
 * Rank tracking: visibility index and its trend (us and competitors), position
 * distribution, winners and losers, share of voice and the keyword table with
 * 1 / 7 / 30-day changes, best position, 30-day trend, ranking URL (switches =
 * cannibalisation) and Search Console clicks.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$pid    = $project['id'];
$f      = isset( $_GET['f'] ) ? sanitize_key( $_GET['f'] ) : '';
$q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$pg     = max( 1, isset( $_GET['rp'] ) ? absint( $_GET['rp'] ) : 1 );
$src    = isset( $_GET['src'] ) && in_array( $_GET['src'], array( 'ss', 'gsc' ), true ) ? sanitize_key( $_GET['src'] ) : '';
// phpcs:enable
$tab    = sap_rank_table( $project, $src );
$rows   = $tab['rows'];
$source = $tab['source'];
$dist   = sap_rank_distribution( $rows );
$vis    = sap_rank_visibility( $project, 90, $source );
$vis_n  = $vis ? end( $vis ) : null;
$vis_7  = $vis ? ( array_values( $vis )[ max( 0, count( $vis ) - 8 ) ] ?? null ) : null;
$comps  = sap_rank_competitors( $pid );
$cdoms  = array();
foreach ( $comps as $kw => $ds ) {
	foreach ( array_keys( $ds ) as $d ) {
		$cdoms[ $d ] = true;
	}
}
$cdoms  = array_slice( array_keys( $cdoms ), 0, 4 );
$series = array( 'سایت ما' => $vis );
foreach ( $cdoms as $d ) {
	$cv = sap_rank_visibility( $project, 90, 'ss', $d );
	if ( $cv ) {
		$series[ $d ] = $cv;
	}
}
$sov    = sap_rank_share_of_voice( $project );
$ranked = array_filter( array_column( $rows, 'now' ), function ( $v ) {
	return null !== $v && $v <= 100;
} );
$avg    = $ranked ? array_sum( $ranked ) / count( $ranked ) : null;
$win    = array_values( array_filter( $rows, function ( $r ) {
	return null !== $r['now'] && null !== $r['d7'] && $r['d7'] - $r['now'] >= 1;
} ) );
$lose   = array_values( array_filter( $rows, function ( $r ) {
	return null !== $r['now'] && null !== $r['d7'] && $r['now'] - $r['d7'] >= 1;
} ) );
usort( $win, function ( $a, $b ) {
	return ( $b['d7'] - $b['now'] ) <=> ( $a['d7'] - $a['now'] );
} );
usort( $lose, function ( $a, $b ) {
	return ( $b['now'] - $b['d7'] ) <=> ( $a['now'] - $a['d7'] );
} );
$focuses = array_unique( array_filter( array_column( $rows, 'focus' ) ) );
// Filter + search + sort.
$list = array_values( array_filter( $rows, function ( $r ) use ( $f, $q ) {
	if ( '' !== $q && false === mb_stripos( $r['keyword'], $q ) ) {
		return false;
	}
	switch ( $f ) {
		case 'up':
			return null !== $r['now'] && null !== $r['d7'] && $r['now'] < $r['d7'];
		case 'down':
			return null !== $r['now'] && null !== $r['d7'] && $r['now'] > $r['d7'];
		case 'top10':
			return null !== $r['now'] && $r['now'] <= 10;
		case 'near':
			return null !== $r['now'] && $r['now'] > 10 && $r['now'] <= 20;
		case 'out':
			return null === $r['now'] || $r['now'] > 50;
		case 'cannibal':
			return $r['url_count'] > 1;
	}
	return 0 === strpos( $f, 'fx' ) ? md5( $r['focus'] ) === substr( $f, 2 ) : true;
} ) );
usort( $list, function ( $a, $b ) {
	return array( null === $a['now'] ? 999 : $a['now'], -$a['volume'] ) <=> array( null === $b['now'] ? 999 : $b['now'], -$b['volume'] );
} );
$per   = 50;
$pages = max( 1, (int) ceil( count( $list ) / $per ) );
$pg    = min( $pg, $pages );
$shown = array_slice( $list, ( $pg - 1 ) * $per, $per );
$base  = sap_project_url( $pid, 'ranks', array_filter( array( 'src' => $src ) ) );
$fl    = array( '' => 'همه', 'up' => 'صعود ۷ روزه', 'down' => 'نزول ۷ روزه', 'top10' => 'صفحه اول', 'near' => 'نزدیک صفحه اول (۱۱–۲۰)', 'out' => 'خارج از ۵۰', 'cannibal' => 'تغییر صفحه رتبه‌گیرنده' );
?>
<section class="sap-panel sap-pillar-head">
	<div class="sap-pillar-intro">
		<h2><?php echo sap_icon( 'chart' ); // phpcs:ignore ?>ردیابی رتبه و دیده‌شدن</h2>
		<p class="sap-muted">رتبه هر کلمه هر روز ذخیره می‌شود و تاریخچه برای همیشه در سکان می‌ماند: رتبه دقیق گوگل از ردیاب سئو سیگنال (سایت ما و رقبا) و میانگین جایگاه، کلیک و نمایش روزانه از سرچ کنسول (۹۰ روز گذشته هم یک‌جا گرفته می‌شود). «شاخص دیده‌شدن» سهم کلیک‌هایی است که با رتبه‌های فعلی از کل کلیک ممکن (همه کلمات در رتبه ۱) می‌گیریم، وزن‌دار با حجم جستجو.</p>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-primary" data-sap-step="ranks" data-project="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>بروزرسانی رتبه‌ها</button>
			<nav class="sap-seg is-small">
				<a class="<?php echo 'ss' === $source ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'ranks', array( 'src' => 'ss' ) ) ); ?>">رتبه دقیق (سئو سیگنال)</a>
				<a class="<?php echo 'gsc' === $source ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'ranks', array( 'src' => 'gsc' ) ) ); ?>">میانگین جایگاه (سرچ کنسول)</a>
			</nav>
		</div>
		<details class="sap-rank-add">
			<summary>کلمات ردیابی‌شده (<?php echo esc_html( sap_num( count( $rows ) ) ); ?>) — افزودن کلمه</summary>
			<p class="sap-small sap-muted">به‌طور خودکار ردیابی می‌شوند: کلمات ردیاب سئو سیگنال، کلمات Focusها و کلمه اصلی صفحات دارای نمایش در نقشه کلمات. کلمات دیگر را اینجا اضافه کنید (هر خط یکی):</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php sap_form_fields( 'sap_rank_keywords', array( 'project_id' => $pid ) ); ?>
				<textarea name="rank_keywords" rows="3"><?php echo esc_textarea( (string) ( $project['options']['rank_keywords'] ?? '' ) ); ?></textarea>
				<button class="sap-btn is-small" type="submit">ذخیره</button>
			</form>
		</details>
	</div>
</section>

<?php if ( ! $tab['last'] ) : ?>
	<div class="sap-empty"><?php echo sap_icon( 'chart', 32 ); // phpcs:ignore ?><p>هنوز تاریخچه رتبه‌ای ذخیره نشده. «بروزرسانی رتبه‌ها» را بزنید (ردیاب سئو سیگنال را در تنظیمات پروژه وصل کنید یا سرچ کنسول متصل باشد).</p></div>
	<?php return; ?>
<?php endif; ?>

<div class="sap-rank-kpis">
	<div class="is-main">
		<span>شاخص دیده‌شدن</span>
		<b><?php echo null === $vis_n ? '—' : esc_html( sap_num( $vis_n, 1 ) ) . '<small>٪</small>'; // phpcs:ignore ?></b>
		<?php if ( null !== $vis_n && null !== $vis_7 ) : ?><em class="<?php echo $vis_n >= $vis_7 ? 'is-up' : 'is-down'; ?>"><?php echo esc_html( ( $vis_n >= $vis_7 ? '▲ ' : '▼ ' ) . sap_num( abs( $vis_n - $vis_7 ), 1 ) . ' نسبت به ۷ روز قبل' ); ?></em><?php endif; ?>
	</div>
	<div><span>میانگین رتبه</span><b><?php echo null === $avg ? '—' : esc_html( sap_num( $avg, 1 ) ); ?></b><em>کلمات رتبه‌دار</em></div>
	<div><span>سه نتیجه اول</span><b><?php echo esc_html( sap_num( $dist['top3'] ) ); ?></b><em>از <?php echo esc_html( sap_num( count( $rows ) ) ); ?> کلمه</em></div>
	<div><span>صفحه اول</span><b><?php echo esc_html( sap_num( $dist['top3'] + $dist['top10'] ) ); ?></b><em>رتبه ۱ تا ۱۰</em></div>
	<div class="is-good"><span>صعود ۷ روزه</span><b><?php echo esc_html( sap_num( count( $win ) ) ); ?></b><em>کلمه</em></div>
	<div class="is-bad"><span>نزول ۷ روزه</span><b><?php echo esc_html( sap_num( count( $lose ) ) ); ?></b><em>کلمه</em></div>
</div>

<div class="sap-rank-grid">
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>روند شاخص دیده‌شدن (۹۰ روز)</h2><small class="sap-muted">آخرین داده: <?php echo esc_html( sap_jdate( $tab['last'], 'short' ) ); ?></small></div>
		<?php echo sap_svg_lines( $series, array( 'height' => 200, 'min' => 0, 'unit' => '٪' ) ); // phpcs:ignore ?>
	</section>
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>توزیع رتبه‌ها</h2></div>
		<?php $tot = max( 1, array_sum( $dist ) ); ?>
		<div class="sap-dist-bar">
			<?php foreach ( array( 'top3' => array( '۱–۳', '#15803D' ), 'top10' => array( '۴–۱۰', '#4ADE80' ), 'top20' => array( '۱۱–۲۰', '#FBBF24' ), 'top50' => array( '۲۱–۵۰', '#FB923C' ), 'out' => array( '۵۰+ / بدون رتبه', '#CBD5E1' ) ) as $k => $d ) : ?>
				<?php if ( $dist[ $k ] ) : ?><i style="width:<?php echo esc_attr( round( $dist[ $k ] / $tot * 100, 2 ) ); ?>%;background:<?php echo esc_attr( $d[1] ); ?>" title="<?php echo esc_attr( $d[0] . ': ' . $dist[ $k ] ); ?>"></i><?php endif; ?>
			<?php endforeach; ?>
		</div>
		<ul class="sap-dist-legend">
			<?php foreach ( array( 'top3' => array( 'رتبه ۱ تا ۳', '#15803D' ), 'top10' => array( 'رتبه ۴ تا ۱۰', '#4ADE80' ), 'top20' => array( 'رتبه ۱۱ تا ۲۰', '#FBBF24' ), 'top50' => array( 'رتبه ۲۱ تا ۵۰', '#FB923C' ), 'out' => array( 'بالای ۵۰ یا بدون رتبه', '#CBD5E1' ) ) as $k => $d ) : ?>
				<li><i style="background:<?php echo esc_attr( $d[1] ); ?>"></i><?php echo esc_html( $d[0] ); ?><b><?php echo esc_html( sap_num( $dist[ $k ] ) ); ?></b></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $sov ) : ?>
			<h3 class="sap-h3">سهم از دیده‌شدن (Share of Voice)</h3>
			<ul class="sap-sov">
				<?php foreach ( array_slice( $sov, 0, 6, true ) as $d => $v ) : ?>
					<li class="<?php echo sap_project_domain( $project ) === $d ? 'is-us' : ''; ?>"><span dir="ltr"><?php echo esc_html( $d ); ?></span><i style="width:<?php echo esc_attr( max( 2, $v ) ); ?>%"></i><b><?php echo esc_html( sap_num( $v, 1 ) ); ?>٪</b></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</div>

<div class="sap-rank-grid">
	<?php foreach ( array( array( 'بیشترین صعود (۷ روز)', $win, 'is-up' ), array( 'بیشترین نزول (۷ روز)', $lose, 'is-down' ) ) as $mv ) : ?>
		<section class="sap-panel">
			<div class="sap-panel-head"><h2><?php echo esc_html( $mv[0] ); ?></h2></div>
			<?php if ( ! $mv[1] ) : ?><p class="sap-muted sap-small">موردی نیست.</p><?php endif; ?>
			<ul class="sap-movers">
				<?php foreach ( array_slice( $mv[1], 0, 8 ) as $r ) : ?>
					<li><b><?php echo esc_html( $r['keyword'] ); ?></b><span dir="ltr"><?php echo esc_html( sap_num( $r['d7'], 0 ) . ' → ' . sap_num( $r['now'], 0 ) ); ?></span><?php echo sap_rank_delta( $r['now'], $r['d7'] ); // phpcs:ignore ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endforeach; ?>
</div>

<section class="sap-panel">
	<div class="sap-rank-filter">
		<nav class="sap-seg is-small">
			<?php foreach ( $fl as $k => $l ) : ?>
				<a class="<?php echo $k === $f ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_filter( array( 'f' => $k, 'q' => $q ) ), $base ) ); ?>"><?php echo esc_html( $l ); ?></a>
			<?php endforeach; ?>
			<?php foreach ( $focuses as $fo ) : ?>
				<a class="<?php echo 'fx' . md5( $fo ) === $f ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'f' => 'fx' . md5( $fo ) ), $base ) ); ?>">◎ <?php echo esc_html( $fo ); ?></a>
			<?php endforeach; ?>
		</nav>
		<form method="get" action="<?php echo esc_url( $base ); ?>" class="sap-inline"><input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="جستجوی کلمه…"><?php if ( $f ) : ?><input type="hidden" name="f" value="<?php echo esc_attr( $f ); ?>"><?php endif; ?></form>
	</div>
	<div class="sap-table-wrap">
		<table class="sap-table sap-rank-table">
			<thead><tr><th>کلمه کلیدی</th><th>رتبه</th><th>۱ روز</th><th>۷ روز</th><th>۳۰ روز</th><th>بهترین</th><th>روند ۳۰ روز</th><th>صفحه رتبه‌گیرنده</th><th>کلیک / نمایش ۲۸ روز</th><?php if ( $cdoms ) : ?><th>رقبا</th><?php endif; ?></tr></thead>
			<tbody>
				<?php foreach ( $shown as $r ) : ?>
					<?php $ck = sap_norm_text( $r['keyword'] ); ?>
					<tr>
						<td><b><?php echo esc_html( $r['keyword'] ); ?></b><br><small class="sap-muted"><?php echo $r['volume'] ? esc_html( sap_num( $r['volume'] ) . ' جستجو' ) : ''; ?><?php echo '' !== $r['focus'] ? ' · ◎ ' . esc_html( $r['focus'] ) : ''; ?></small></td>
						<td class="sap-rank-now"><?php echo null === $r['now'] ? '<span class="sap-muted">—</span>' : '<b class="' . ( $r['now'] <= 3 ? 'is-top3' : ( $r['now'] <= 10 ? 'is-top10' : '' ) ) . '">' . esc_html( sap_num( $r['now'], 'gsc' === $source ? 1 : 0 ) ) . '</b>'; // phpcs:ignore ?></td>
						<td><?php echo sap_rank_delta( $r['now'], $r['d1'] ); // phpcs:ignore ?></td>
						<td><?php echo sap_rank_delta( $r['now'], $r['d7'] ); // phpcs:ignore ?></td>
						<td><?php echo sap_rank_delta( $r['now'], $r['d30'] ); // phpcs:ignore ?></td>
						<td class="sap-muted"><?php echo null === $r['best'] ? '—' : esc_html( sap_num( $r['best'], 0 ) ); ?></td>
						<td><?php echo sap_svg_spark( $r['spark'] ); // phpcs:ignore ?></td>
						<td class="sap-rank-url"><?php echo '' !== $r['url'] ? sap_url_tag( $r['url'], 42 ) : '<span class="sap-muted">—</span>'; // phpcs:ignore ?><?php echo $r['url_count'] > 1 ? '<br><span class="sap-chip is-warn" title="در ۱۴ روز اخیر بیش از یک صفحه برای این کلمه رتبه گرفته">⚠ ' . esc_html( sap_num( $r['url_count'] ) ) . ' صفحه</span>' : ''; ?></td>
						<td class="sap-nowrap sap-small"><?php echo esc_html( sap_num( $r['clicks'] ) . ' / ' . sap_num( $r['impr'] ) ); ?></td>
						<?php if ( $cdoms ) : ?>
							<td class="sap-rank-comps"><?php foreach ( $cdoms as $d ) : ?><?php $cp = $comps[ $ck ][ $d ] ?? null; ?><span title="<?php echo esc_attr( $d ); ?>" class="<?php echo null !== $cp && null !== $r['now'] && $cp < $r['now'] ? 'is-ahead' : ''; ?>"><i dir="ltr"><?php echo esc_html( mb_substr( $d, 0, 12 ) ); ?></i><?php echo null === $cp ? '—' : esc_html( sap_num( $cp, 0 ) ); ?></span><?php endforeach; ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php if ( $pages > 1 ) : ?>
		<nav class="sap-pager"><?php for ( $i = 1; $i <= $pages; $i++ ) : ?><a class="<?php echo $i === $pg ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_filter( array( 'f' => $f, 'q' => $q, 'rp' => $i ) ), $base ) ); ?>"><?php echo esc_html( sap_num( $i ) ); ?></a><?php endfor; ?></nav>
	<?php endif; ?>
</section>
