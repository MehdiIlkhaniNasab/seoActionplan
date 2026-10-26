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
