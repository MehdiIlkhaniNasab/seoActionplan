<?php
/**
 * Technical › redirects / 404 / 410 and internal links: the audit groups with
 * every row (source page, anchor, where the link sits, target, final URL).
 *
 * @package SeoActionPlan
 * @var array  $project
 * @var string $mode redirects|links
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$audit = sap_redirect_audit( $project );
$post  = esc_url( admin_url( 'admin-post.php' ) );
$keys  = 'links' === $mode ? array( 'broken', 'links_to_redirect' ) : array( 'chains', 'dead_end', 'temp', 'hides_live', 'bad_target', 'no_target', 'to_home', 'gone', 'hits_404', 'in_google' );
$sev   = array( 'bad' => array( 'is-bad', 'مهم' ), 'warn' => array( 'is-warn', 'بهبود' ), 'info' => array( 'is-info', 'اطلاع' ) );
$plug  = array( 'rank_math' => 'رنک‌مث', 'redirection' => 'Redirection', 'yoast' => 'یوآست' );
$code  = function ( $c ) {
	$c = (int) $c;
	return '<span class="sap-code is-' . ( $c >= 500 ? '5' : ( $c >= 400 ? '4' : ( $c >= 300 ? '3' : '2' ) ) ) . '">' . ( $c ? $c : '؟' ) . '</span>';
};
?>
<div class="sap-redir" id="redirects">
	<div class="sap-redir-sources">
		<span class="sap-chip <?php echo $audit['have']['crawl'] ? 'is-good' : 'is-soft'; ?>"><?php echo $audit['have']['crawl'] ? '✓' : '○'; ?> خزش Internal</span>
		<span class="sap-chip <?php echo $audit['have']['links'] ? 'is-good' : 'is-soft'; ?>" title="Bulk Export › Links › All Inlinks"><?php echo $audit['have']['links'] ? '✓' : '○'; ?> لینک‌های داخلی (All Inlinks)<?php echo $audit['have']['links'] && empty( $audit['links']['positions'] ) ? ' — بدون ستون Link Position' : ''; ?></span>
		<span class="sap-chip <?php echo $audit['have']['report'] ? 'is-good' : 'is-soft'; ?>"><?php echo $audit['have']['report'] ? '✓' : '○'; ?> گزارش ریدایرکت‌ها</span>
		<?php if ( 'redirects' === $mode ) : ?>
			<span class="sap-chip <?php echo $audit['have']['site'] ? 'is-good' : 'is-soft'; ?>"><?php echo $audit['have']['site'] ? '✓ فهرست ریدایرکت سایت: ' . esc_html( implode( '، ', array_map( function ( $k, $n ) use ( $plug ) { return ( $plug[ $k ] ?? $k ) . ' ' . sap_num( $n ); }, array_keys( (array) ( $audit['site']['plugins'] ?? array() ) ), (array) ( $audit['site']['plugins'] ?? array() ) ) ) ) : '○ فهرست ریدایرکت سایت (رنک‌مث)'; ?></span>
			<?php if ( sap_site_connected( $project ) ) : ?>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-inline"><?php sap_form_fields( 'sap_redirects_sync', array( 'project_id' => $pid ) ); ?><button class="sap-btn is-soft is-small" type="submit"><?php echo sap_icon( 'refresh', 14 ); // phpcs:ignore ?><?php echo $audit['have']['site'] ? 'دریافت دوباره از سایت' : 'دریافت فهرست ریدایرکت‌ها و مانیتور 404 از سایت'; ?></button></form>
			<?php else : ?>
				<a class="sap-btn is-ghost is-small" href="<?php echo esc_url( sap_project_url( $pid, 'settings' ) . '#site-connector' ); ?>">اتصال افزونه رابط برای خواندن ریدایرکت‌های رنک‌مث</a>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php if ( ! $audit['have']['links'] ) : ?>
		<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?><span>برای دیدن صفحه مبدا، انکر و محل هر لینک (متن / منو / فوتر / سایدبار) و جدا کردن لینک‌های داخل محتوا، اسکریپت ایجنت را از «اتصال Screaming Frog» دوباره دانلود و اجرا کنید (نسخه جدید خروجی All Inlinks و گزارش ریدایرکت را هم می‌فرستد) یا این دو فایل را در تنظیمات پروژه › آپلود دستی بفرستید.</span></p>
	<?php endif; ?>

	<?php
	$shown = 0;
	foreach ( $keys as $gk ) :
		$g = $audit['groups'][ $gk ] ?? null;
		if ( ! $g ) {
			continue;
		}
		++$shown;
		$items = (array) $g['items'];
		?>
		<details class="sap-redir-group is-<?php echo esc_attr( $g['sev'] ); ?>" <?php echo $shown <= 2 && 'info' !== $g['sev'] ? 'open' : ''; ?>>
			<summary>
				<span class="sap-chip <?php echo esc_attr( $sev[ $g['sev'] ][0] ); ?>"><?php echo esc_html( $sev[ $g['sev'] ][1] ); ?></span>
				<b><?php echo esc_html( $g['title'] ); ?></b>
				<?php if ( $items ) : ?><span class="sap-redir-n"><?php echo esc_html( sap_num( count( $items ) ) ); ?></span><?php endif; ?>
			</summary>
			<p class="sap-redir-why"><?php echo esc_html( $g['why'] ); ?></p>
			<?php if ( $items ) : ?>
				<ul class="sap-redir-list">
					<?php foreach ( array_slice( $items, 0, 60 ) as $i => $it ) : ?>
						<?php $it['url'] = (string) ( $it['url'] ?? ( $it['from'] ?? '' ) ); ?>
						<li <?php echo $i >= 12 ? 'class="is-more" hidden' : ''; ?>>
							<div class="sap-redir-row">
								<?php echo sap_url_tag( $it['url'], 60 ); // phpcs:ignore ?>
								<?php if ( ! empty( $it['code'] ) ) : ?><?php echo $code( $it['code'] ); // phpcs:ignore ?><?php endif; ?>
								<?php if ( isset( $it['to'] ) && '' !== (string) $it['to'] ) : ?><span class="sap-redir-arrow">←</span><?php echo sap_url_tag( $it['to'], 60 ); // phpcs:ignore ?><?php endif; ?>
								<?php if ( isset( $it['final_code'] ) ) : ?><?php echo $code( $it['final_code'] ); // phpcs:ignore ?><?php endif; ?>
								<span class="sap-redir-meta">
									<?php if ( isset( $it['hops'] ) ) : ?><span class="sap-chip <?php echo ! empty( $it['loop'] ) ? 'is-bad' : 'is-warn'; ?>"><?php echo ! empty( $it['loop'] ) ? 'حلقه' : esc_html( sap_num( $it['hops'] ) ) . ' پرش'; ?></span><?php endif; ?>
									<?php if ( isset( $it['n'] ) && $it['n'] ) : ?><span class="sap-chip is-soft"><?php echo esc_html( sap_num( $it['n'] ) ); ?> لینک<?php echo ! empty( $it['body'] ) ? ' · ' . esc_html( sap_num( $it['body'] ) ) . ' از متن' : ''; ?></span><?php endif; ?>
									<?php if ( ! empty( $it['hits'] ) ) : ?><span class="sap-chip is-soft"><?php echo esc_html( sap_num( $it['hits'] ) ); ?> بازدید</span><?php endif; ?>
									<?php if ( ! empty( $it['impr'] ) || ! empty( $it['clicks'] ) ) : ?><span class="sap-chip is-info"><?php echo esc_html( sap_num( (int) ( $it['clicks'] ?? 0 ) ) . ' کلیک · ' . sap_num( (int) ( $it['impr'] ?? 0 ) ) . ' نمایش' ); ?></span><?php endif; ?>
									<?php if ( ! empty( $it['plugin'] ) ) : ?><span class="sap-chip is-soft"><?php echo esc_html( $plug[ $it['plugin'] ] ?? $it['plugin'] ); ?></span><?php endif; ?>
									<?php if ( ! empty( $it['why'] ) ) : ?><span class="sap-chip is-bad"><?php echo esc_html( $it['why'] ); ?></span><?php endif; ?>
									<?php if ( ! empty( $it['live'] ) ) : ?><span class="sap-chip is-warn">صفحه هنوز منتشر است</span><?php endif; ?>
								</span>
							</div>
							<?php if ( ! empty( $it['sources'] ) ) : ?>
								<ul class="sap-redir-src">
									<?php foreach ( $it['sources'] as $src ) : ?>
										<li><span class="sap-redir-pos"><?php echo esc_html( sap_link_pos_label( $src['pos'] ) ); ?></span> در <?php echo sap_url_tag( $src['url'], 55 ); // phpcs:ignore ?><?php echo '' !== $src['anchor'] ? ' با انکر <q>' . esc_html( $src['anchor'] ) . '</q>' : ''; ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( count( $items ) > 12 ) : ?>
					<button type="button" class="sap-btn is-ghost is-small" data-sap-more-rows>نمایش <?php echo esc_html( sap_num( min( 60, count( $items ) ) - 12 ) ); ?> مورد دیگر<?php echo count( $items ) > 60 ? ' (از ' . esc_html( sap_num( count( $items ) ) ) . ')' : ''; ?></button>
				<?php endif; ?>
			<?php endif; ?>
		</details>
	<?php endforeach; ?>
	<?php if ( ! $shown ) : ?>
		<p class="sap-empty-good"><?php echo $audit['have']['crawl'] || $audit['have']['site'] ? '✓ مشکلی در این بخش پیدا نشد.' : 'هنوز داده‌ای برای این بخش نیست.'; ?></p>
	<?php endif; ?>
</div>
