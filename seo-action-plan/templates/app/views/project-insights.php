<?php
/**
 * Results of the staged AI analysis: cannibalization, pages & content,
 * internal links, technical (sitemap, schema, speed).
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$get   = function ( $key ) use ( $pid ) {
	return sap_dataset( $pid, $key );
};
$can   = $get( 'ai_cannibal' );
$con   = $get( 'ai_content' );
$lnk   = $get( 'ai_links' );
$tec   = $get( 'ai_technical' );
$cwv   = $get( 'cwv' );
$pages = $get( 'pages' );
$when  = function ( $ds ) {
	return $ds ? sap_jdate( $ds['fetched_at'] ) . ( isset( $ds['meta']['cost'] ) ? ' · ' . sap_num( $ds['meta']['cost'], 3 ) . ' دلار' : '' ) : '';
};
$copy  = '<button type="button" class="sap-icon-btn" data-sap-copy-target title="کپی">' . sap_icon( 'log', 14 ) . '</button>';
$prio  = array( 'high' => 'اولویت بالا', 'medium' => 'اولویت متوسط', 'low' => 'اولویت کم' );
$areas = array( 'crawl' => 'خزش', 'index' => 'ایندکس', 'sitemap' => 'سایت‌مپ', 'schema' => 'اسکیما', 'cwv' => 'سرعت (CWV)', 'onpage' => 'سئو داخلی' );
?>
<?php if ( ! $can && ! $con && ! $lnk && ! $tec ) : ?>
	<div class="sap-empty">
		<?php echo sap_icon( 'spark', 32 ); // phpcs:ignore ?>
		<h2>هنوز تحلیل هوشمندی انجام نشده</h2>
		<p><?php echo sap_ai_connected() ? '«تحلیل کامل» را بزنید؛ تحلیل در پنج مرحله (کنیبالیزیشن، محتوا، لینک داخلی، تکنیکال، جمع‌بندی) انجام می‌شود.' : 'اول در تنظیمات، هوش مصنوعی را متصل کنید.'; ?></p>
		<?php if ( sap_ai_connected() ) : ?>
			<button type="button" class="sap-btn is-primary" data-sap-sync="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تحلیل کامل</button>
		<?php else : ?>
			<a class="sap-btn is-primary" href="<?php echo esc_url( sap_url( 'settings' ) ); ?>">تنظیمات هوش مصنوعی</a>
		<?php endif; ?>
	</div>
	<?php
	return;
endif;
?>
<nav class="sap-insight-nav">
	<a href="#ins-content">صفحات و محتوا</a>
	<a href="#ins-links">لینک داخلی</a>
	<a href="#ins-cannibal">کنیبالیزیشن</a>
	<a href="#ins-tech">تکنیکال، اسکیما و سرعت</a>
</nav>

<section class="sap-panel" id="ins-content">
	<div class="sap-panel-head"><h2><?php echo sap_icon( 'log' ); // phpcs:ignore ?>صفحات و محتوا</h2><small class="sap-muted"><?php echo esc_html( $when( $con ) ); ?></small></div>
	<?php if ( $pages ) : ?>
		<p class="sap-muted sap-small">محتوای <?php echo esc_html( sap_num( count( (array) ( $pages['payload']['pages'] ?? array() ) ) ) ); ?> صفحه کلیدی خوانده شده (متن، تیترها، اسکیما، لینک‌ها) — <?php echo esc_html( sap_jdate( $pages['fetched_at'] ) ); ?></p>
	<?php endif; ?>
	<?php foreach ( (array) ( $con['payload']['pages'] ?? array() ) as $p ) : ?>
		<div class="sap-insight-card">
			<h4><?php echo sap_page_ref( $project, $p['url'] ); // phpcs:ignore ?>
				<a class="sap-btn is-small is-ai" href="<?php echo esc_url( sap_project_url( $project['id'], 'content', array( 'url' => sap_full_url( $project, $p['url'] ) ) ) ); ?>"><?php echo sap_icon( 'spark', 13 ); // phpcs:ignore ?>بازنویسی محتوا</a></h4>
			<p><?php echo esc_html( $p['diagnosis'] ); ?></p>
			<?php if ( $p['title'] || $p['meta'] ) : ?>
				<div class="sap-ai-output"><div class="sap-ai-output-head"><span>تایتل و متای پیشنهادی</span><?php echo $copy; // phpcs:ignore ?></div><pre><?php echo esc_html( trim( ( $p['title'] ? 'تایتل: ' . $p['title'] : '' ) . "\n" . ( $p['meta'] ? 'متا: ' . $p['meta'] : '' ) ) ); ?></pre></div>
			<?php endif; ?>
			<?php if ( $p['missing_topics'] ) : ?>
				<p class="sap-label">موضوعات و زیرعنوان‌های جاافتاده</p><ul><?php foreach ( $p['missing_topics'] as $m ) : ?><li><?php echo esc_html( $m ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<?php if ( $p['faq'] ) : ?>
				<div class="sap-ai-output"><div class="sap-ai-output-head"><span>سوالات متداول پیشنهادی</span><?php echo $copy; // phpcs:ignore ?></div><pre><?php echo esc_html( implode( "\n", $p['faq'] ) ); ?></pre></div>
			<?php endif; ?>
			<?php if ( $p['actions'] ) : ?>
				<p class="sap-label">اقدامات</p><ul><?php foreach ( $p['actions'] as $a ) : ?><li><?php echo esc_html( $a ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
	<?php if ( ! $con ) : ?><p class="sap-muted">هنوز انجام نشده.</p><?php endif; ?>
</section>

<section class="sap-panel" id="ins-links">
	<div class="sap-panel-head"><h2><?php echo sap_icon( 'link' ); // phpcs:ignore ?>لینک‌سازی داخلی</h2><small class="sap-muted"><?php echo esc_html( $when( $lnk ) ); ?></small></div>
	<?php $links = (array) ( $lnk['payload']['links'] ?? array() ); ?>
	<?php if ( $links ) : ?>
		<div class="sap-table-wrap">
			<table class="sap-table">
				<thead><tr><th>از صفحه</th><th>به صفحه</th><th>انکر</th><th>محل قرارگیری و دلیل</th></tr></thead>
				<tbody>
					<?php foreach ( $links as $l ) : ?>
						<tr>
							<td class="sap-small"><?php echo sap_page_ref( $project, $l['source_url'] ); // phpcs:ignore ?></td>
							<td class="sap-small"><?php echo sap_page_ref( $project, $l['target_url'] ); // phpcs:ignore ?></td>
							<td><b><?php echo esc_html( $l['anchor'] ); ?></b></td>
							<td class="sap-small"><?php echo esc_html( $l['placement'] ); ?><br><span class="sap-muted"><?php echo esc_html( $l['reason'] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="sap-ai-output"><div class="sap-ai-output-head"><span>فهرست قابل کپی</span><?php echo $copy; // phpcs:ignore ?></div><pre><?php echo esc_html( implode( "\n", array_map( function ( $l ) {
			return $l['source_url'] . ' ← ' . $l['target_url'] . ' : «' . $l['anchor'] . '»';
		}, $links ) ) ); ?></pre></div>
	<?php else : ?>
		<p class="sap-muted"><?php echo $lnk ? 'پیشنهادی نبود.' : 'هنوز انجام نشده.'; ?></p>
	<?php endif; ?>
</section>

<section class="sap-panel" id="ins-cannibal">
	<div class="sap-panel-head"><h2><?php echo sap_icon( 'alert' ); // phpcs:ignore ?>کنیبالیزیشن</h2><small class="sap-muted"><?php echo esc_html( $when( $can ) ); ?></small></div>
	<?php
	$labels = array( 'real' => 'کنیبال واقعی', 'not_cannibal' => 'کنیبال نیست', 'check' => 'نیاز به بررسی' );
	$items  = (array) ( $can['payload']['items'] ?? array() );
	usort( $items, function ( $a, $b ) {
		$o = array( 'real' => 0, 'check' => 1, 'not_cannibal' => 2 );
		return ( $o[ $a['verdict'] ] ?? 3 ) <=> ( $o[ $b['verdict'] ] ?? 3 );
	} );
	foreach ( $items as $i ) :
		?>
		<div class="sap-insight-card">
			<h4><span class="sap-verdict is-<?php echo esc_attr( $i['verdict'] ); ?>"><?php echo esc_html( $labels[ $i['verdict'] ] ?? $i['verdict'] ); ?></span>«<?php echo esc_html( $i['query'] ); ?>»</h4>
			<p><?php echo esc_html( $i['reason'] ); ?></p>
			<?php if ( 'not_cannibal' !== $i['verdict'] && $i['action'] ) : ?>
				<div class="sap-kv-mini"><span>اقدام</span><b><?php echo esc_html( $i['action'] ); ?></b><?php if ( $i['primary_url'] ) : ?><span>صفحه اصلی</span><span><?php echo sap_page_ref( $project, $i['primary_url'] ); // phpcs:ignore ?></span><?php endif; ?></div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
	<?php if ( ! $items ) : ?><p class="sap-muted"><?php echo $can ? 'موردی نبود.' : 'موردی برای بررسی نیست یا هنوز انجام نشده.'; ?></p><?php endif; ?>
</section>

<section class="sap-panel" id="ins-tech">
	<div class="sap-panel-head"><h2><?php echo sap_icon( 'settings' ); // phpcs:ignore ?>تکنیکال، سایت‌مپ، اسکیما و سرعت</h2><small class="sap-muted"><?php echo esc_html( $when( $tec ) ); ?></small></div>
	<?php foreach ( (array) ( $tec['payload']['issues'] ?? array() ) as $i ) : ?>
		<div class="sap-insight-card">
			<h4><span class="sap-impact is-<?php echo esc_attr( $i['priority'] ); ?>"><?php echo esc_html( $prio[ $i['priority'] ] ?? '' ); ?></span><span class="sap-chip"><?php echo esc_html( $areas[ $i['area'] ] ?? $i['area'] ); ?></span><?php echo esc_html( $i['title'] ); ?></h4>
			<p><?php echo esc_html( $i['detail'] ); ?></p>
			<?php if ( $i['fix'] ) : ?>
				<div class="sap-ai-output"><div class="sap-ai-output-head"><span>راه‌حل</span><?php echo $copy; // phpcs:ignore ?></div><pre><?php echo esc_html( $i['fix'] ); ?></pre></div>
			<?php endif; ?>
			<?php if ( $i['urls'] ) : ?>
				<p class="sap-small sap-muted" dir="auto"><?php echo esc_html( implode( ' · ', array_slice( $i['urls'], 0, 6 ) ) ); ?></p>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
	<?php if ( $cwv && ! empty( $cwv['payload']['pages'] ) ) : ?>
		<p class="sap-label">Core Web Vitals موبایل (p75 کاربران واقعی؛ در نبود داده، آزمایشگاهی)</p>
		<div class="sap-table-wrap">
			<table class="sap-table">
				<thead><tr><th>صفحه</th><th>امتیاز</th><th>LCP</th><th>INP</th><th>CLS</th></tr></thead>
				<tbody>
					<?php
					foreach ( $cwv['payload']['pages'] as $c ) :
						$f   = $c['field'] ?: $c['origin'];
						$fmt = function ( $m, $cls = false ) {
							if ( ! $m ) {
								return '—';
							}
							$cat = array( 'FAST' => '✓', 'AVERAGE' => '!', 'SLOW' => '✗' )[ $m['cat'] ] ?? '';
							return ( $cls ? sap_num( $m['p75'], 2 ) : sap_num( $m['p75'] ) . 'ms' ) . ' ' . $cat;
						};
						?>
						<tr>
							<td class="sap-small"><?php echo sap_page_link( $c['url'], sap_page_title( $project['id'], $c['url'] ) ); // phpcs:ignore ?></td>
							<td><?php echo null === $c['score'] ? '—' : esc_html( sap_num( $c['score'] ) ); ?></td>
							<td><?php echo esc_html( $f ? $fmt( $f['lcp'] ?? null ) : ( $c['lab']['lcp'] ? sap_num( $c['lab']['lcp'] ) . 'ms' : '—' ) ); ?></td>
							<td><?php echo esc_html( $f ? $fmt( $f['inp'] ?? null ) : '—' ); ?></td>
							<td><?php echo esc_html( $f ? $fmt( $f['cls'] ?? null, true ) : ( null !== $c['lab']['cls'] ? sap_num( $c['lab']['cls'], 2 ) : '—' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php elseif ( $cwv && ! empty( $cwv['meta']['errors'] ) ) : ?>
		<p class="sap-warn"><?php echo esc_html( array_values( $cwv['meta']['errors'] )[0] ); ?></p>
	<?php endif; ?>
	<?php if ( ! $tec ) : ?><p class="sap-muted">هنوز انجام نشده.</p><?php endif; ?>
</section>
