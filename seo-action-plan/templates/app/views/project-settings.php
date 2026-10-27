<?php
/**
 * Project settings: data sources, integrations (crawl agent, SeoReport API), danger zone.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$post     = esc_url( admin_url( 'admin-post.php' ) );
$crawl    = rest_url( 'seoactionplan/v1/crawl/' . $pid );
$report   = rest_url( 'seoactionplan/v1/report/' . $pid );
$key      = $project['api_key'];
$crawl_ds = sap_dataset( $pid, 'crawl' );
$site     = $project['site_url'] ?: 'https://' . sap_project_domain( $project ) . '/';
?>
<?php sap_view( 'project-form', array( 'project' => $project ) ); ?>

<?php $conn = sap_site_conn( $project ); ?>
<section class="sap-panel" id="site-connector">
	<h2><?php echo sap_icon( 'link' ); // phpcs:ignore ?>اتصال به سایت کارفرما (افزونه رابط)</h2>
	<p class="sap-muted">افزونه «SeoReport Connector» نسخه ۱٫۲ به بالا را روی سایت کارفرما نصب کنید (همان افزونه رابط SeoReport؛ یک افزونه با دو کلید جدا). در پیشخوان سایت کارفرما › تنظیمات › SeoReport Connector، بخش «سکان»، آدرس سایت و «کلید سکان» را بردارید و اینجا وارد کنید. با این اتصال:</p>
	<ul class="sap-small">
		<li>هر تغییری که روی سایت انجام شود (تایتل، متا، کلمه کانونی، محتوا، نامک، دسته‌ها، ویژگی محصول، انتشار) با «قبل ← بعد» در لاگ اقدامات می‌آید.</li>
		<li>تحلیل محتوا، متن دقیق نوشته را از خود وردپرس می‌خواند (نه HTML صفحه).</li>
		<li>تغییرات تأییدشده محتوا اول به‌صورت پیش‌نویس با لینک پیش‌نمایش روی سایت ساخته و بعد از تأیید شما منتشر می‌شوند (با امکان برگشت).</li>
	</ul>
	<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
		<?php sap_form_fields( 'sap_site_save', array( 'project_id' => $pid ) ); ?>
		<div class="sap-grid-2">
			<label>آدرس سایت کارفرما<input type="url" name="site_conn_url" dir="ltr" value="<?php echo esc_attr( (string) ( $project['options']['site_url'] ?? '' ) ?: $site ); ?>"></label>
			<label>کلید سکان<input type="password" name="site_conn_key" dir="ltr" autocomplete="off" placeholder="<?php echo $conn ? esc_attr( sap_mask( $conn['key'] ) . ' (ذخیره شده — برای تغییر، کلید جدید را وارد کنید)' ) : ''; ?>"></label>
		</div>
		<div class="sap-form-foot">
			<button class="sap-btn is-primary" type="submit">ذخیره اتصال</button>
			<?php if ( $conn ) : ?>
				<button type="button" class="sap-btn" data-sap-site-test="<?php echo esc_attr( $pid ); ?>">تست اتصال</button>
				<button type="button" class="sap-btn" data-sap-step="site_log" data-project="<?php echo esc_attr( $pid ); ?>">دریافت لاگ تغییرات سایت</button>
				<span class="sap-muted" data-sap-site-status></span>
			<?php endif; ?>
		</div>
	</form>
</section>

<section class="sap-panel">
	<h2><?php echo sap_icon( 'link' ); // phpcs:ignore ?>اتصال‌ها و API پروژه</h2>
	<div class="sap-kv">
		<div><span>کلید API پروژه</span><code dir="ltr" data-sap-copy><?php echo esc_html( $key ); ?></code></div>
		<div><span>دریافت خزش Screaming Frog</span><code dir="ltr" data-sap-copy><?php echo esc_html( $crawl ); ?></code></div>
		<div><span>API گزارش برای SeoReport</span><code dir="ltr" data-sap-copy><?php echo esc_html( $report ); ?>?period=<?php echo esc_html( sap_period_current() ); ?></code></div>
	</div>

	<h3>ایجنت Screaming Frog (همان ایجنت SeoReport)</h3>
	<p class="sap-muted">ایجنت موجود در ریپوی SeoReport (<code>connectors/screaming-frog/</code>) را با این Endpoint و کلید اجرا کنید. اگر خزش را برای SeoReport هم می‌فرستید، ایجنت را دو بار با دو Endpoint زمان‌بندی کنید.</p>
	<pre dir="ltr" data-sap-copy>powershell -ExecutionPolicy Bypass -File sf-agent.ps1 -Url "<?php echo esc_html( $site ); ?>" -Endpoint "<?php echo esc_html( $crawl ); ?>" -Key "<?php echo esc_html( $key ); ?>"</pre>
	<pre dir="ltr" data-sap-copy>./sf-agent.sh --url <?php echo esc_html( $site ); ?> --endpoint <?php echo esc_html( $crawl ); ?> --key <?php echo esc_html( $key ); ?></pre>

	<h3>آپلود دستی خزش</h3>
	<form method="post" action="<?php echo $post; // phpcs:ignore ?>" enctype="multipart/form-data" class="sap-inline">
		<?php sap_form_fields( 'sap_crawl_upload', array( 'project_id' => $pid ) ); ?>
		<input type="file" name="crawl" accept=".csv,.gz" required>
		<button class="sap-btn is-small" type="submit">آپلود internal_all.csv</button>
		<span class="sap-muted"><?php echo $crawl_ds ? 'آخرین خزش: ' . esc_html( sap_jdate( $crawl_ds['fetched_at'] ) . ' — ' . sap_num( $crawl_ds['meta']['urls'] ?? 0 ) . ' آدرس' ) : 'هنوز خزشی دریافت نشده.'; ?></span>
	</form>

	<h3>اتصال به SeoReport</h3>
	<p class="sap-muted">SeoReport با این درخواست، لاگ اقدامات ماه و برنامه ماه بعد را می‌خواند (هدر <code dir="ltr">X-SAP-Key</code>). پاسخ شامل <code dir="ltr">activity.items</code>، <code dir="ltr">activity.work_done_lines</code> (همان قالب «دسته | اقدام» ویرایشگر SeoReport)، <code dir="ltr">next_plan</code> و پیشرفت اهداف Focusهاست.</p>
	<pre dir="ltr" data-sap-copy>curl -H "X-SAP-Key: <?php echo esc_html( $key ); ?>" "<?php echo esc_html( $report ); ?>?period=<?php echo esc_html( sap_period_current() ); ?>"</pre>

	<form method="post" action="<?php echo $post; // phpcs:ignore ?>" onsubmit="return confirm('کلید عوض شود؟ اتصال‌های قبلی قطع می‌شوند.')">
		<?php sap_form_fields( 'sap_regen_key', array( 'project_id' => $pid ) ); ?>
		<button class="sap-btn is-small" type="submit"><?php echo sap_icon( 'refresh', 14 ); // phpcs:ignore ?>ساخت کلید جدید</button>
	</form>
</section>

<section class="sap-panel is-danger">
	<h2>حذف پروژه</h2>
	<p class="sap-muted">همه Focusها، یافته‌ها، کارها، لاگ اقدامات و داده‌های دریافتی این پروژه حذف می‌شود.</p>
	<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-inline">
		<?php sap_form_fields( 'sap_delete_project', array( 'project_id' => $pid ) ); ?>
		<input type="text" name="confirm" placeholder="DELETE" dir="ltr" class="is-tiny">
		<button class="sap-btn is-small is-danger" type="submit">حذف دائمی</button>
	</form>
</section>
