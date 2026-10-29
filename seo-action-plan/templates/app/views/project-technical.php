<?php
/**
 * Technical SEO pillar: health checklist by section, the facts behind each
 * section, and the technical proposals waiting for approval.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$pid     = $project['id'];
$list    = sap_tech_checklist( $project );
$score   = sap_tech_score( $list );
$open    = isset( $_GET['sec'] ) && isset( $list[ sanitize_key( $_GET['sec'] ) ] ) ? sanitize_key( $_GET['sec'] ) : '';
// phpcs:enable
$labels  = wp_list_pluck( sap_tech_sections(), 0 );
$ai      = sap_dataset( $pid, 'ai_technical' );
$st_txt  = array( 'good' => 'سالم', 'warn' => 'نیاز به بهبود', 'bad' => 'مشکل جدی', 'nodata' => 'داده ندارد' );
$here    = sap_project_url( $pid, 'technical', array_filter( array( 'sec' => $open ) ) );
$robots  = sap_dataset_payload( $pid, 'robots' );
$smap    = sap_dataset_payload( $pid, 'sitemap_check' );
$inspect = (array) ( sap_dataset_payload( $pid, 'gsc_inspect' )['rows'] ?? array() );
?>
<section class="sap-panel sap-pillar-head">
	<div class="sap-pillar-score <?php echo null === $score ? '' : ( $score >= 85 ? 'is-good' : ( $score >= 60 ? 'is-warn' : 'is-bad' ) ); ?>">
		<b><?php echo null === $score ? '—' : esc_html( sap_num( $score ) ); ?></b><span>امتیاز سلامت تکنیکال</span>
	</div>
	<div class="sap-pillar-intro">
		<h2><?php echo sap_icon( 'settings' ); // phpcs:ignore ?>سئو تکنیکال</h2>
		<p class="sap-muted">هفت بخش اصلی سلامت فنی سایت بررسی می‌شود؛ هر مشکل با راه‌حل دقیق به «پیشنهادها»ی پایین صفحه می‌رود و فقط بعد از تأیید شما وارد اکشن پلن ماه می‌شود.</p>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-primary" data-sap-step="robots,sitemap_check,gsc_inspect,analyze" data-project="<?php echo esc_attr( $pid ); ?>" data-force><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>بررسی دوباره robots، سایت‌مپ و ایندکس (رایگان)</button>
			<button type="button" class="sap-btn is-soft" data-sap-step="cwv,analyze" data-project="<?php echo esc_attr( $pid ); ?>" data-force>تست دوباره سرعت</button>
			<button type="button" class="sap-btn is-soft" data-sap-step="ai_technical" data-project="<?php echo esc_attr( $pid ); ?>" data-force <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تحلیل هوشمند تکنیکال</button>
			<button type="button" class="sap-btn is-soft" data-sap-step="ai_triage" data-project="<?php echo esc_attr( $pid ); ?>" data-force <?php disabled( ! sap_ai_connected() ); ?> title="هوش مصنوعی هر مشکل را با داده خزش بازبینی می‌کند: واقعی، جزئی یا غیرمشکل (با دلیل)"><?php echo sap_icon( 'check' ); // phpcs:ignore ?>بازبینی کارشناسی مشکلات</button>
		</div>
		<?php if ( $ai ) : ?><p class="sap-small sap-muted">آخرین تحلیل هوشمند: <?php echo esc_html( sap_jdate( $ai['fetched_at'], 'short' ) . ' · ' . ( $ai['payload']['summary_line'] ?? '' ) ); ?></p><?php endif; ?>
	</div>
</section>

<div class="sap-tech-grid">
	<?php foreach ( $list as $key => $s ) : ?>
		<a class="sap-tech-card is-<?php echo esc_attr( $s['status'] ); ?><?php echo $open === $key ? ' is-open' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'technical', array( 'sec' => $key ) ) . ( ! $s['kpis'] && in_array( $key, array( 'crawl', 'pages' ), true ) ? '#sf-wizard' : '#sec-detail' ) ); ?>">
			<div class="sap-tech-card-head">
				<span class="sap-tech-ico"><?php echo sap_icon( $s['icon'] ); // phpcs:ignore ?></span>
				<div class="sap-tech-name">
					<b><?php echo esc_html( $s['label'] ); ?></b>
					<small><?php echo esc_html( $st_txt[ $s['status'] ] ); ?><?php echo $s['items'] ? ' · ' . esc_html( sap_num( count( $s['items'] ) ) ) . ' مشکل' : ''; ?></small>
				</div>
				<span class="sap-tech-ring" style="--v:<?php echo esc_attr( (int) $s['score'] ); ?>"><b><?php echo null === $s['score'] ? '—' : esc_html( sap_num( $s['score'] ) ); ?></b></span>
			</div>
			<?php if ( $s['kpis'] ) : ?>
				<div class="sap-tech-kpis">
					<?php foreach ( $s['kpis'] as $kp ) : ?>
						<div class="<?php echo $kp[2] ? 'is-' . esc_attr( $kp[2] ) : ''; ?>"><b dir="ltr"><?php echo esc_html( $kp[0] ); ?></b><span><?php echo esc_html( $kp[1] ); ?></span></div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="sap-tech-empty"><?php echo 'crawl' === $key || 'pages' === $key ? 'Screaming Frog وصل نیست — راهنمای اتصال ←' : 'هنوز داده‌ای دریافت نشده'; ?></p>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</div>

<?php sap_view( 'sf-wizard', array( 'project' => $project, 'open' => in_array( $open, array( 'crawl', 'pages' ), true ) ) ); ?>

<?php if ( $open ) : ?>
	<?php $s = $list[ $open ]; ?>
	<section class="sap-panel" id="sec-detail">
		<div class="sap-panel-head">
			<h2><?php echo sap_icon( $s['icon'] ); // phpcs:ignore ?><?php echo esc_html( $s['label'] ); ?><small><?php echo esc_html( $st_txt[ $s['status'] ] ); ?></small></h2>
			<a class="sap-btn is-ghost is-small" href="<?php echo esc_url( sap_project_url( $pid, 'technical' ) ); ?>">بستن</a>
		</div>
		<p class="sap-muted sap-small"><b>چه چیزی بررسی می‌شود:</b> <?php echo esc_html( $s['checks'] ); ?></p>
		<?php if ( 'robots' === $open && $robots ) : ?>
			<div class="sap-grid-2">
				<div>
					<p class="sap-label">مسیرهای مهم</p>
					<ul class="sap-check-list">
						<?php foreach ( (array) $robots['tests'] as $t ) : ?>
							<li class="<?php echo $t['allowed'] ? 'is-ok' : 'is-bad'; ?>"><i><?php echo $t['allowed'] ? '✓' : '✕'; ?></i><span dir="ltr"><?php echo esc_html( rawurldecode( $t['path'] ) ); ?></span><small><?php echo esc_html( $t['label'] ); ?></small></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div><p class="sap-label">محتوای فایل (<a href="<?php echo esc_url( $robots['url'] ); ?>" target="_blank" rel="noopener">باز کردن</a>)</p><pre dir="ltr"><?php echo esc_html( $robots['body'] ); ?></pre></div>
			</div>
		<?php elseif ( 'sitemap' === $open && $smap ) : ?>
			<?php if ( $smap['root'] ) : ?>
				<div class="sap-table-wrap"><table class="sap-table is-compact">
					<thead><tr><th>فایل</th><th>نوع</th><th>آدرس‌ها</th><th>lastmod</th><th>جدیدترین</th></tr></thead>
					<tbody>
						<?php foreach ( (array) $smap['maps'] as $m ) : ?>
							<tr><td dir="ltr"><a href="<?php echo esc_url( $m['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sap_short_url( $m['url'], 60 ) ); ?></a></td><td><?php echo ! empty( $m['index'] ) ? 'ایندکس سایت‌مپ' : 'سایت‌مپ'; ?></td><td><?php echo esc_html( sap_num( $m['count'] ) ); ?></td><td><?php echo esc_html( sap_num( $m['lastmod'] ) ); ?></td><td><?php echo ! empty( $m['newest'] ) ? esc_html( sap_jdate( $m['newest'], 'short' ) ) : '—'; ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table></div>
				<?php if ( ! empty( $smap['skipped'] ) ) : ?>
					<p class="sap-small sap-muted"><?php echo esc_html( sap_num( $smap['skipped'] ) ); ?> آدرس (فیلتر و پارامتردار، صفحه‌بندی، سبد خرید، کنونیکال‌شده به صفحه‌ای که در سایت‌مپ هست، noindex) لازم نیست در سایت‌مپ باشد و حساب نشد.</p>
				<?php endif; ?>
				<?php if ( $smap['missing'] ) : ?>
					<p class="sap-label">صفحات مهمی که در سایت‌مپ نیستند</p>
					<ul class="sap-small"><?php foreach ( array_slice( $smap['missing'], 0, 15 ) as $m ) : ?><li><?php echo sap_page_link( $m['url'], $m['title'] ?: rawurldecode( $m['url'] ) ); // phpcs:ignore ?> <span class="sap-muted">· <?php echo esc_html( sap_num( $m['impr'] ) ); ?> نمایش</span></li><?php endforeach; ?></ul>
				<?php endif; ?>
			<?php else : ?>
				<p class="sap-warn">سایت‌مپی پیدا نشد. مسیرهای امتحان‌شده: <span dir="ltr"><?php echo esc_html( implode( ' , ', (array) ( $smap['tried'] ?? array() ) ) ); ?></span></p>
			<?php endif; ?>
		<?php elseif ( 'index' === $open && $inspect ) : ?>
			<div class="sap-table-wrap"><table class="sap-table is-compact">
				<thead><tr><th>صفحه</th><th>وضعیت</th><th>آخرین خزش</th><th>کنونیکال گوگل</th><th>Rich Results</th></tr></thead>
				<tbody>
					<?php foreach ( $inspect as $r ) : ?>
						<tr>
							<td><?php echo sap_page_link( $r['url'], sap_page_title( $pid, $r['url'] ) ?: rawurldecode( $r['url'] ) ); // phpcs:ignore ?></td>
							<td><?php if ( ! empty( $r['error'] ) ) : ?><span class="sap-chip is-bad">خطا</span> <small><?php echo esc_html( $r['error'] ); ?></small><?php else : ?><span class="sap-chip <?php echo 'PASS' === $r['verdict'] ? 'is-good' : 'is-bad'; ?>"><?php echo esc_html( sap_coverage_label( $r['coverage'] ) ?: $r['verdict'] ); ?></span><?php endif; ?></td>
							<td class="sap-nowrap"><?php echo ! empty( $r['last_crawl'] ) ? esc_html( sap_jdate( substr( $r['last_crawl'], 0, 10 ), 'short' ) ) : '—'; ?></td>
							<td class="sap-small" dir="ltr"><?php echo ! empty( $r['g_canon'] ) && sap_norm_url( $r['g_canon'] ) !== sap_norm_url( $r['url'] ) ? esc_html( sap_short_url( $r['g_canon'], 50 ) ) : '—'; ?></td>
							<td class="sap-small"><?php echo ! empty( $r['rich_types'] ) ? esc_html( implode( '، ', $r['rich_types'] ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php elseif ( 'speed' === $open && ( $cwv = (array) ( sap_dataset_payload( $pid, 'cwv' )['pages'] ?? array() ) ) ) : ?>
			<?php
			$cv = function ( $v, $good, $poor, $unit = 's', $div = 1000 ) {
				if ( null === $v ) {
					return '<span class="sap-muted">—</span>';
				}
				$n   = $v / $div;
				$cls = $n <= $good ? 'is-good' : ( $n <= $poor ? 'is-warn' : 'is-bad' );
				return '<span class="sap-chip ' . $cls . '" dir="ltr">' . esc_html( sap_num( $n, 's' === $unit ? 1 : ( 'ms' === $unit ? 0 : 2 ) ) . ( '' !== $unit && 'cls' !== $unit ? $unit : '' ) ) . '</span>';
			};
			?>
			<div class="sap-table-wrap"><table class="sap-table is-compact">
				<thead><tr><th>صفحه</th><th>امتیاز موبایل</th><th>LCP</th><th>INP</th><th>CLS</th><th>منبع</th></tr></thead>
				<tbody>
					<?php foreach ( $cwv as $c ) : ?>
						<?php $fd = ! empty( $c['field']['lcp'] ); ?>
						<tr>
							<td><?php echo sap_page_link( $c['url'], sap_page_title( $pid, $c['url'] ) ?: rawurldecode( $c['url'] ) ); // phpcs:ignore ?></td>
							<td><span class="sap-score-pill <?php echo null === $c['score'] ? '' : ( $c['score'] >= 90 ? 'is-good' : ( $c['score'] >= 50 ? 'is-warn' : 'is-bad' ) ); ?>"><?php echo null === $c['score'] ? '—' : esc_html( sap_num( $c['score'] ) ); ?></span></td>
							<td><?php echo $cv( $c['field']['lcp']['p75'] ?? ( $c['lab']['lcp'] ?? null ), 2.5, 4 ); // phpcs:ignore ?></td>
							<td><?php echo $cv( $c['field']['inp']['p75'] ?? null, 200, 500, 'ms', 1 ); // phpcs:ignore ?></td>
							<td><?php echo $cv( $c['field']['cls']['p75'] ?? ( $c['lab']['cls'] ?? null ), 0.1, 0.25, 'cls', 1 ); // phpcs:ignore ?></td>
							<td class="sap-small sap-muted"><?php echo $fd ? 'کاربران واقعی (CrUX)' : 'آزمایشگاهی'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php elseif ( 'schema' === $open && ( $spages = (array) ( sap_dataset_payload( $pid, 'pages' )['pages'] ?? array() ) ) ) : ?>
			<?php
			$stypes = array();
			$nosch  = array();
			foreach ( $spages as $sp ) {
				foreach ( (array) $sp['schema'] as $t ) {
					$stypes[ $t ] = ( $stypes[ $t ] ?? 0 ) + 1;
				}
				if ( ! $sp['schema'] ) {
					$nosch[] = $sp;
				}
			}
			arsort( $stypes );
			?>
			<div class="sap-grid-2">
				<div>
					<p class="sap-label">نوع‌های اسکیمای پیدا‌شده (در <?php echo esc_html( sap_num( count( $spages ) ) ); ?> صفحه)</p>
					<div class="sap-chips"><?php foreach ( $stypes as $t => $n ) : ?><span class="sap-chip" dir="ltr"><?php echo esc_html( $t ); ?> · <?php echo esc_html( sap_num( $n ) ); ?></span><?php endforeach; ?></div>
				</div>
				<div>
					<p class="sap-label">صفحات بدون اسکیما (<?php echo esc_html( sap_num( count( $nosch ) ) ); ?>)</p>
					<ul class="sap-small"><?php foreach ( array_slice( $nosch, 0, 10 ) as $sp ) : ?><li><?php echo sap_page_link( $sp['url'], $sp['title'] ?: rawurldecode( $sp['url'] ) ); // phpcs:ignore ?></li><?php endforeach; ?></ul>
				</div>
			</div>
		<?php elseif ( in_array( $open, array( 'redirects', 'links' ), true ) ) : ?>
			<?php sap_view( 'tech-redirects', array( 'project' => $project, 'mode' => $open ) ); ?>
		<?php elseif ( 'nodata' === $s['status'] ) : ?>
			<p class="sap-warn">داده این بخش هنوز دریافت نشده. <?php echo in_array( 'crawl', $s['needs'], true ) ? 'مراحل «اتصال Screaming Frog» در بالای همین بخش را دنبال کنید.' : 'دکمه‌های بالای صفحه یا «تحلیل کامل» را بزنید.'; ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $ai['payload']['issues'] ) ) : ?>
			<?php
			$map_area = array( 'sitemap' => 'sitemap', 'robots' => 'robots', 'cwv' => 'speed', 'schema' => 'schema', 'index' => 'index', 'pages' => 'pages', 'crawl' => 'crawl', 'onpage' => 'crawl', 'redirects' => 'redirects', 'links' => 'links' );
			$ai_here  = array_filter( $ai['payload']['issues'], function ( $i ) use ( $map_area, $open ) {
				return ( $map_area[ $i['area'] ] ?? '' ) === $open;
			} );
			?>
			<?php if ( $ai_here ) : ?>
				<p class="sap-label">تحلیل هوشمند این بخش</p>
				<ul class="sap-ai-issues"><?php foreach ( $ai_here as $i ) : ?><li><b><?php echo esc_html( $i['title'] ); ?></b> <span class="sap-chip is-prio-<?php echo esc_attr( $i['priority'] ); ?>"><?php echo esc_html( $i['priority'] ); ?></span><p class="sap-small"><?php echo esc_html( $i['detail'] ); ?></p></li><?php endforeach; ?></ul>
			<?php endif; ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php
sap_view( 'proposals', array(
	'project'  => $project,
	'items'    => sap_proposals( $pid, 'technical', array( 'section' => $open ) ),
	'title'    => $open ? 'پیشنهادهای «' . $labels[ $open ] . '»' : 'پیشنهادهای تکنیکال',
	'here'     => $here,
	'sections' => $labels,
	'empty'    => 'پیشنهاد تکنیکالی در انتظار تأیید نیست. بعد از دریافت داده‌ها و «تحلیل هوشمند تکنیکال» اینجا پر می‌شود.',
) );
