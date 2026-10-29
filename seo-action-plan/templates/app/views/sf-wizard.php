<?php
/**
 * Technical › Screaming Frog connection, step by step: install, crawl scope,
 * agent download, connection test (polled), first crawl and schedule.
 *
 * @package SeoActionPlan
 * @var array $project
 * @var bool  $open    Show expanded.
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$st    = sap_sf_state( $project );
$set   = $st['settings'];
$ping  = $st['ping'];
$done  = $st['steps'];
$cur   = $st['current'];
$win   = 'sh' !== $set['os'];
$n_imp = count( sap_sf_important_urls( $project, $set['limit'] ) );
$post  = admin_url( 'admin-post.php' );
$cls   = function ( $k ) use ( $done, $cur ) {
	return $done[ $k ] ? 'is-done' : ( $cur === $k ? 'is-current' : 'is-later' );
};
$run_test = $win ? 'powershell -ExecutionPolicy Bypass -File sokan-sf-agent.ps1 -Test' : 'bash sokan-sf-agent.sh --test';
$run      = $win ? 'powershell -ExecutionPolicy Bypass -File sokan-sf-agent.ps1' : 'bash sokan-sf-agent.sh';
?>
<details class="sap-panel sap-sfw" id="sf-wizard" data-sap-sfw="<?php echo esc_attr( $pid ); ?>" <?php echo $open || $cur ? 'open' : ''; ?>>
	<summary class="sap-sfw-head">
		<span class="sap-tech-ico"><?php echo sap_icon( 'link' ); // phpcs:ignore ?></span>
		<span class="sap-sfw-title"><b>اتصال Screaming Frog</b><small><?php echo $cur ? esc_html( sprintf( 'مرحله %s از ۵', sap_num( array_search( $cur, array_keys( $done ), true ) + 1 ) ) ) : 'وصل است · آخرین خزش ' . esc_html( sap_jdate( $st['crawl']['fetched_at'], 'short' ) . ' (' . sap_num( (int) ( $st['crawl']['meta']['urls'] ?? 0 ) ) . ' آدرس)' ); ?></small></span>
		<span class="sap-sfw-dots"><?php foreach ( $done as $k => $d ) : ?><i class="<?php echo esc_attr( $cls( $k ) ); ?>"></i><?php endforeach; ?></span>
	</summary>

	<ol class="sap-sfw-steps">
		<li class="<?php echo esc_attr( $cls( 'install' ) ); ?>">
			<div class="sap-sfw-n">۱</div>
			<div class="sap-sfw-body">
				<b>نصب Screaming Frog SEO Spider روی کامپیوتر</b>
				<p>از <a href="https://www.screamingfrog.co.uk/seo-spider/" target="_blank" rel="noopener">سایت Screaming Frog</a> دانلود و نصب کنید. <b>یک بار برنامه را باز کنید</b>، شرایط استفاده را بپذیرید و اگر لایسنس دارید وارد کنید (نسخه رایگان تا ۵۰۰ آدرس خزش می‌کند؛ خزش سریع سکان با همین محدودیت هم کار می‌کند). اگر JavaScript Rendering می‌خواهید، آن را در خود برنامه تنظیم و با گزینه -Config بدهید.</p>
				<?php if ( ! $done['install'] ) : ?><button type="button" class="sap-btn is-small is-primary" data-sap-sf-config="installed">نصب کردم، مرحله بعد</button><?php endif; ?>
			</div>
		</li>
		<li class="<?php echo esc_attr( $cls( 'scope' ) ); ?>">
			<div class="sap-sfw-n">۲</div>
			<div class="sap-sfw-body">
				<b>محدوده خزش</b>
				<p>برای سئو تکنیکال لازم نیست هر بار کل سایت خزش شود. «خزش سریع» فقط صفحات مهم را در حالت List Mode می‌خزد: صفحه اصلی، صفحات Focus، صفحات نقشه کلمات، همه دسته‌ها و برندهای محصول، پربازدیدترین محصولات و مقالات و صفحاتی که در سایت‌مپ جا مانده‌اند. چند دقیقه طول می‌کشد، نه چند ساعت.</p>
				<form class="sap-sfw-scope" data-sap-sf-scope>
					<label class="sap-sfw-opt"><input type="radio" name="mode" value="quick" <?php checked( 'quick', $set['mode'] ); ?>><span><b>خزش سریع (پیشنهادی)</b><small>حداکثر <input type="number" name="limit" min="20" max="5000" step="10" value="<?php echo esc_attr( $set['limit'] ); ?>"> آدرس مهم · الان <?php echo esc_html( sap_num( $n_imp ) ); ?> آدرس آماده است</small></span></label>
					<label class="sap-sfw-opt"><input type="radio" name="mode" value="full" <?php checked( 'full', $set['mode'] ); ?>><span><b>خزش کامل سایت</b><small>لازمه تحلیل کامل لینک داخلی (لینک‌های داخل متن، صفحات بدون لینک، لینک‌های شکسته با صفحه مبدا و زنجیره ریدایرکت)؛ ماهی یک بار کافی است (در سایت بزرگ طولانی است). خزش سریع فقط همان آدرس‌ها را می‌خواند.</small></span></label>
					<button type="submit" class="sap-btn is-small <?php echo $done['scope'] ? '' : 'is-primary'; ?>">ذخیره محدوده</button>
				</form>
			</div>
		</li>
		<li class="<?php echo esc_attr( $cls( 'download' ) ); ?>">
			<div class="sap-sfw-n">۳</div>
			<div class="sap-sfw-body">
				<b>دانلود ایجنت آماده این پروژه</b>
				<p>آدرس، کلید و محدوده خزش داخل فایل قرار گرفته؛ چیزی برای تنظیم نیست. فایل را در یک پوشه (مثلاً <code dir="ltr">C:\Sokan</code>) ذخیره کنید. بعد از تغییر محدوده، فایل را دوباره دانلود کنید.</p>
				<div class="sap-actions">
					<?php foreach ( array( 'win' => 'ویندوز (PowerShell)', 'sh' => 'مک / لینوکس' ) as $os => $label ) : ?>
						<form method="post" action="<?php echo esc_url( $post ); ?>" data-sap-sf-download>
							<?php sap_form_fields( 'sap_sf_agent', array( 'project_id' => $pid, 'os' => $os ) ); ?>
							<button type="submit" class="sap-btn is-small <?php echo ( 'win' === $os && ! $done['download'] ) ? 'is-primary' : 'is-soft'; ?>"><?php echo sap_icon( 'download', 14 ); // phpcs:ignore ?><?php echo esc_html( $label ); ?></button>
						</form>
					<?php endforeach; ?>
				</div>
			</div>
		</li>
		<li class="<?php echo esc_attr( $cls( 'test' ) ); ?>">
			<div class="sap-sfw-n">۴</div>
			<div class="sap-sfw-body">
				<b>تست اتصال</b>
				<p>در پوشه فایل، ترمینال (PowerShell یا Terminal) را باز کنید و این دستور را اجرا کنید. ایجنت به سکان پیام می‌دهد و بررسی می‌کند Screaming Frog پیدا می‌شود؛ خزشی انجام نمی‌شود.</p>
				<pre dir="ltr" data-sap-copy><?php echo esc_html( $run_test ); ?></pre>
				<div class="sap-sfw-test">
					<button type="button" class="sap-btn is-small <?php echo $cur === 'test' ? 'is-primary' : 'is-soft'; ?>" data-sap-sf-check><?php echo sap_icon( 'refresh', 14 ); // phpcs:ignore ?>بررسی اتصال</button>
					<span data-sap-sf-result class="sap-small">
						<?php if ( $ping ) : ?>
							<?php if ( ! empty( $ping['sf_found'] ) ) : ?>
								<span class="sap-ok">✓ وصل است · Screaming Frog پیدا شد (<?php echo esc_html( $ping['os'] . ' · ' . $ping['host'] ); ?>) · <?php echo esc_html( human_time_diff( strtotime( $ping['at'] ), strtotime( sap_now() ) ) ); ?> پیش</span>
							<?php else : ?>
								<span class="sap-bad">ایجنت به سکان وصل شد ولی Screaming Frog را پیدا نکرد؛ برنامه را نصب کنید یا مسیر آن را با <code dir="ltr">-SpiderPath</code> بدهید.</span>
							<?php endif; ?>
						<?php else : ?>
							<span class="sap-muted">هنوز پیامی از ایجنت نرسیده.</span>
						<?php endif; ?>
					</span>
				</div>
			</div>
		</li>
		<li class="<?php echo esc_attr( $cls( 'crawl' ) ); ?>">
			<div class="sap-sfw-n">۵</div>
			<div class="sap-sfw-body">
				<b>اولین خزش و زمان‌بندی ماهانه</b>
				<p>همین دستور بدون Test خزش را انجام می‌دهد و نتیجه را می‌فرستد؛ بعد از آن کارت‌های «خطاهای صفحات» و «تحلیل خزش» پر می‌شوند.</p>
				<pre dir="ltr" data-sap-copy><?php echo esc_html( $run ); ?></pre>
				<p class="sap-small sap-muted">زمان‌بندی خودکار، اول هر ماه ساعت ۳ بامداد (مسیر فایل را درست کنید):</p>
				<?php if ( $win ) : ?>
					<pre dir="ltr" data-sap-copy>schtasks /Create /SC MONTHLY /D 1 /ST 03:00 /TN "Sokan SF <?php echo esc_html( sap_project_domain( $project ) ); ?>" /TR "powershell -ExecutionPolicy Bypass -File C:\Sokan\sokan-sf-agent.ps1"</pre>
				<?php else : ?>
					<pre dir="ltr" data-sap-copy>0 3 1 * * bash $HOME/sokan/sokan-sf-agent.sh</pre>
				<?php endif; ?>
				<?php if ( $st['crawl'] ) : ?>
					<p class="sap-ok sap-small">✓ آخرین خزش: <?php echo esc_html( sap_jdate( $st['crawl']['fetched_at'] ) . ' · ' . sap_num( (int) ( $st['crawl']['meta']['urls'] ?? 0 ) ) . ' آدرس' ); ?></p>
				<?php elseif ( 'crawling' === ( $ping['stage'] ?? '' ) ) : ?>
					<p class="sap-small"><span class="sap-spin is-tiny"></span> در حال خزش روی کامپیوتر شما (شروع <?php echo esc_html( human_time_diff( strtotime( $ping['at'] ), strtotime( sap_now() ) ) ); ?> پیش)…</p>
				<?php elseif ( 'failed' === ( $ping['stage'] ?? '' ) ) : ?>
					<p class="sap-bad sap-small">خزش ناموفق بود: <?php echo esc_html( $ping['note'] ); ?></p>
				<?php endif; ?>
				<p class="sap-small sap-muted">ایجنت علاوه بر Internal › All، خروجی <b>Bulk Export › Links › All Inlinks</b> (همه لینک‌های داخلی با «Link Position»: متن، منو، هدر، فوتر، سایدبار) و گزارش <b>Reports › Redirects › All Redirects</b> را هم می‌گیرد؛ لینک‌ها را روی کامپیوتر خودتان فیلتر می‌کند (فقط لینک‌های داخل متن و لینک‌های غیر ۲۰۰) تا حجم ارسال کم بماند.</p>
				<p class="sap-small sap-muted">بدون ایجنت هم می‌شود: همین سه خروجی را از Screaming Frog بگیرید (CSV) و با هم در <a href="<?php echo esc_url( sap_project_url( $pid, 'settings' ) ); ?>">تنظیمات پروژه</a> آپلود کنید.</p>
			</div>
		</li>
	</ol>
</details>
