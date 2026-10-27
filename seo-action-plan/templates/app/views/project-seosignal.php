<?php
/**
 * SEO Signal tab: every tool of the API on demand (nothing runs by itself),
 * cached results, quota, and AI advice on which queries are worth it now.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid    = $project['id'];
$usage  = sap_ss_usage();
$left   = sap_ss_remaining();
$advice = sap_dataset( $pid, 'ai_ss' );
$recent = (array) get_option( 'sap_ss_recent_' . $pid, array() );
$pages  = sap_keymap( $pid );
$tools  = array( 'research' => 'تحقیق کلمه', 'bulk' => 'حجم دسته‌ای', 'analyzer' => 'آنالیز سایت', 'compare' => 'مقایسه سایت', 'rp_suggest' => 'پیشنهاد رسانه', 'rp_check' => 'جزئیات رسانه', 'rp_seller' => 'آنالیز فروشنده', 'rp_buyer' => 'آنالیز خریدار', 'rt_status' => 'وضعیت رتبه‌ها', 'rt_history' => 'تاریخچه رتبه', 'rt_projects' => 'پروژه‌های ردیاب' );
$reported = (array) get_option( 'sap_ss_reported', array() );
?>
<?php if ( ! sap_ss_connected() ) : ?>
	<div class="sap-empty"><?php echo sap_icon( 'research', 32 ); // phpcs:ignore ?><p>کلید API سئو سیگنال را در <a href="<?php echo esc_url( sap_url( 'settings' ) ); ?>">تنظیمات</a> وارد کنید.</p></div>
	<?php
	return;
endif;
?>
<section class="sap-panel sap-ss-head">
	<div class="sap-keymap-head">
		<div>
			<h2><?php echo sap_icon( 'research' ); // phpcs:ignore ?>سئو سیگنال</h2>
			<p class="sap-muted">هر ابزار فقط وقتی شما بخواهید اجرا می‌شود؛ نتایج تا <?php echo esc_html( sap_num( (int) sap_setting( 'seosignal_cache_days', 25 ) ) ); ?> روز کش می‌شوند و تکرار همان درخواست سهمیه مصرف نمی‌کند. ردیاب رتبه رایگان است. در «تحلیل کامل» هم قبل از هر بخش سئو سیگنال از شما پرسیده می‌شود.</p>
		</div>
		<div class="sap-ss-quota">
			<b><?php echo esc_html( sap_num( $left ) ); ?></b><span>واحد باقی‌مانده امروز از <?php echo esc_html( sap_num( $usage['cap'] ) ); ?></span>
			<div class="sap-progress"><i style="width:<?php echo esc_attr( min( 100, round( 100 * $usage['used'] / max( 1, $usage['cap'] ) ) ) ); ?>%"></i></div>
			<?php if ( $reported ) : ?>
				<?php $last_rep = end( $reported ); ?>
				<small class="sap-muted" title="آخرین عددی که خود سئو سیگنال در پاسخ گزارش کرد">گزارش سئو سیگنال: مصرف <?php echo esc_html( sap_num( $last_rep['usage'] ) . ( $last_rep['limit'] ? ' از ' . sap_num( $last_rep['limit'] ) : '' ) ); ?></small>
			<?php endif; ?>
		</div>
	</div>
	<?php
	$ss_log  = array_reverse( (array) get_option( 'sap_ss_log', array() ) );
	$ss_last = $ss_log[0] ?? null;
	?>
	<?php if ( $ss_last && ! $ss_last['ok'] ) : ?>
		<div class="sap-alert is-bad">
			<?php echo sap_icon( 'alert' ); // phpcs:ignore ?>
			<div><b>آخرین درخواست به سئو سیگنال ناموفق بود</b> (<?php echo esc_html( sap_jdate( $ss_last['at'], 'short' ) . ' · ' . $ss_last['endpoint'] ); ?>)<br><span><?php echo esc_html( $usage['last_error'] ?: $ss_last['msg'] ); ?></span></div>
		</div>
	<?php endif; ?>
	<div class="sap-ss-diag">
		<button type="button" class="sap-btn" data-sap-ss-test="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>تست اتصال سئو سیگنال (رایگان)</button>
		<?php if ( $ss_log ) : ?>
			<details>
				<summary>گزارش آخرین درخواست‌ها (<?php echo esc_html( sap_num( count( $ss_log ) ) ); ?>)</summary>
				<div class="sap-table-wrap">
					<table class="sap-table is-compact">
						<thead><tr><th>زمان</th><th>Endpoint</th><th>نتیجه</th><th>جزئیات</th><th>مدت</th></tr></thead>
						<tbody>
							<?php foreach ( $ss_log as $l ) : ?>
								<tr>
									<td class="sap-nowrap"><?php echo esc_html( sap_jdate( $l['at'], 'short' ) . ' ' . substr( $l['at'], 11, 5 ) ); ?></td>
									<td dir="ltr"><code><?php echo esc_html( $l['endpoint'] ); ?></code></td>
									<td><span class="sap-chip <?php echo $l['ok'] ? 'is-good' : 'is-bad'; ?>"><?php echo $l['ok'] ? 'موفق' : 'خطا'; ?><?php echo $l['code'] ? ' ' . esc_html( $l['code'] ) : ''; ?></span></td>
									<td class="sap-small" dir="auto"><?php echo esc_html( $l['msg'] ); ?></td>
									<td class="sap-nowrap sap-small"><?php echo esc_html( sap_num( $l['ms'] ) ); ?>ms</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</details>
		<?php endif; ?>
	</div>
	<div class="sap-ss-diag-out" data-sap-ss-test-out hidden></div>
</section>

<?php
$setup = sap_ss_setup( $project );
$todo  = count( array_filter( $setup['items'], function ( $i ) {
	return false === $i['ok'];
} ) );
?>
<details class="sap-panel sap-ss-setup" <?php echo $todo ? 'open' : ''; ?>>
	<summary>
		<h3><?php echo sap_icon( 'check' ); // phpcs:ignore ?>راهنمای راه‌اندازی: در پنل سئو سیگنال چه چیزهایی باید اضافه کنم؟</h3>
		<span class="sap-chip <?php echo $todo ? 'is-warn' : 'is-good'; ?>"><?php echo $todo ? esc_html( sap_num( $todo ) . ' کار مانده' ) : 'کامل است'; ?></span>
	</summary>
	<p class="sap-muted sap-small">سکان برای تحلیل به این موارد در حساب سئو سیگنال شما نیاز دارد. وضعیت هر مورد از داده‌های همین پروژه خوانده می‌شود و هیچ واحدی مصرف نمی‌کند.</p>
	<ol class="sap-ss-steps">
		<?php foreach ( $setup['items'] as $it ) : ?>
			<li class="<?php echo true === $it['ok'] ? 'is-done' : ( false === $it['ok'] ? 'is-todo' : 'is-info' ); ?>">
				<i aria-hidden="true"><?php echo true === $it['ok'] ? '✓' : ( false === $it['ok'] ? '!' : 'i' ); ?></i>
				<div>
					<b><?php echo esc_html( $it['title'] ); ?></b>
					<p class="sap-small"><?php echo esc_html( $it['how'] ); ?></p>
					<?php if ( 'link' === ( $it['action'] ?? '' ) ) : ?>
						<button type="button" class="sap-btn is-small" data-sap-ss-link="<?php echo esc_attr( $pid ); ?>"><?php echo $project['ss_project_id'] ? 'اتصال دوباره' : 'پیدا کردن و اتصال خودکار'; ?> (رایگان)</button>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php if ( $setup['track'] ) : ?>
		<div class="sap-ss-track">
			<div class="sap-panel-head">
				<h4><?php echo $setup['tracked'] ? 'کلمات جا مانده از ردیاب' : 'کلمات پیشنهادی برای ردیاب رتبه'; ?> <small class="sap-muted"><?php echo esc_html( sap_num( count( $setup['missing'] ) ) ); ?> کلمه</small></h4>
				<button type="button" class="sap-btn is-small" data-sap-copy-from="#sap-ss-track-list">کپی (هر خط یک کلمه)</button>
			</div>
			<textarea id="sap-ss-track-list" rows="5" readonly><?php echo esc_textarea( implode( "\n", $setup['missing'] ) ); ?></textarea>
		</div>
	<?php endif; ?>
</details>

<section class="sap-panel sap-ss-advice">
	<div class="sap-panel-head">
		<h3><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>پیشنهاد هوشمند: الان از سئو سیگنال چه بگیریم؟</h3>
		<button type="button" class="sap-btn is-small is-primary" data-sap-ss-advise="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo $advice ? 'پیشنهاد دوباره' : 'دریافت پیشنهاد'; ?></button>
	</div>
	<p class="sap-muted sap-small">هوش مصنوعی داده‌های پروژه (کلمات بدون حجم، Focusهای بدون سوال، رقبای مقایسه‌نشده، افت رتبه‌ها) را می‌بیند و پرارزش‌ترین درخواست‌ها را با پارامتر دقیق و هزینه پیشنهاد می‌دهد.</p>
	<?php if ( $advice && ! empty( $advice['payload']['actions'] ) ) : ?>
		<div class="sap-ss-actions">
			<?php foreach ( $advice['payload']['actions'] as $a ) : ?>
				<div class="sap-ss-action">
					<div>
						<span class="sap-chip is-prio-<?php echo esc_attr( $a['priority'] ); ?>"><?php echo esc_html( $tools[ $a['tool'] ] ?? $a['tool'] ); ?></span>
						<b><?php echo esc_html( $a['title'] ); ?></b>
						<p class="sap-small sap-muted"><?php echo esc_html( $a['why'] ); ?></p>
					</div>
					<button type="button" class="sap-btn is-small" data-sap-ss-run='<?php echo esc_attr( wp_json_encode( array( 'tool' => $a['tool'], 'params' => $a['params'] ) ) ); ?>'><?php echo esc_html( $a['units'] ? 'اجرا (' . sap_num( $a['units'] ) . ' واحد)' : 'اجرا (رایگان)' ); ?></button>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>

<?php
$cost  = function ( $u ) {
	return $u ? '<span class="sap-cost">' . esc_html( sap_num( $u ) ) . ' واحد</span>' : '<span class="sap-cost is-free">رایگان</span>';
};
$f     = function ( $label, $html, $wide = false ) {
	return '<label class="sap-field' . ( $wide ? ' is-wide' : '' ) . '"><span>' . esc_html( $label ) . '</span>' . $html . '</label>';
};
$opts  = function ( $list, $sel = '' ) {
	$o = '';
	foreach ( $list as $k => $l ) {
		$o .= '<option value="' . esc_attr( $k ) . '"' . selected( $sel, $k, false ) . '>' . esc_html( $l ) . '</option>';
	}
	return $o;
};
$dates = array( 'all' => 'همه زمان‌ها', 'last_90' => '۹۰ روز اخیر' );
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- the helpers above escape.
?>
<datalist id="sap-competitors"><?php foreach ( $project['competitors'] as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"><?php endforeach; ?></datalist>
<h2 class="sap-section-title">ابزارهای سئو سیگنال</h2>
<div class="sap-ss-grid">
	<section class="sap-panel sap-ss-tool">
		<header><h3>تحقیق کلمه کلیدی</h3><?php echo $cost( 1 ); ?></header>
		<p class="sap-muted sap-small">حجم، تغییر، روند ماهانه و سهم کلیک رقبا برای یک کلمه، با ۵ مدل جستجو.</p>
		<form data-sap-ss-form="research" class="sap-ss-form">
			<?php echo $f( 'کلمه کلیدی', '<input type="text" name="keyword" required placeholder="مثلاً: کفی طبی">', true ); ?>
			<?php echo $f( 'مدل جستجو', '<select name="type">' . $opts( sap_ss_research_types() ) . '</select>' ); ?>
			<?php echo $f( 'حداکثر نتیجه', '<input type="number" name="count" value="200" min="10" max="1000">' ); ?>
			<button type="submit" class="sap-btn is-primary is-block">جستجوی کلمات</button>
		</form>
	</section>
	<section class="sap-panel sap-ss-tool">
		<header><h3>حجم دسته‌ای کلمات</h3><?php echo $cost( 1 ); ?></header>
		<p class="sap-muted sap-small">حجم و رقابت تا ۱۰۰۰ کلمه با یک درخواست.</p>
		<form data-sap-ss-form="bulk" class="sap-ss-form">
			<?php echo $f( 'کلمات (هر خط یک کلمه)', '<textarea name="keywords" rows="5" required placeholder="کفی طبی&#10;کفش طبی زنانه"></textarea>', true ); ?>
			<button type="submit" class="sap-btn is-primary is-block">گرفتن حجم</button>
		</form>
	</section>
	<section class="sap-panel sap-ss-tool">
		<header><h3>آنالیز کلمات سایت</h3><?php echo $cost( 1 ); ?></header>
		<p class="sap-muted sap-small">کلمات و صفحات برتر یک دامنه یا آدرس (مثلاً رقیب)، با سایت‌های مرتبط.</p>
		<form data-sap-ss-form="analyzer" class="sap-ss-form">
			<?php echo $f( 'دامنه یا آدرس', '<input type="text" name="url" dir="ltr" required list="sap-competitors" placeholder="rival.ir">', true ); ?>
			<?php echo $f( 'محدوده', '<select name="type">' . $opts( array( 'domain' => 'کل دامنه', 'page' => 'یک صفحه', 'starting_with' => 'آدرس‌هایی که با این شروع می‌شوند', 'contains' => 'آدرس‌هایی که شامل عبارت هستند' ) ) . '</select>' ); ?>
			<?php echo $f( 'حداکثر نتیجه', '<input type="number" name="count" value="500" min="10" max="2000">' ); ?>
			<?php echo $f( 'عبارت (فقط برای «شامل»)', '<input type="text" name="contains" dir="ltr" placeholder="/blog/">', true ); ?>
			<button type="submit" class="sap-btn is-primary is-block">آنالیز سایت</button>
		</form>
	</section>
	<section class="sap-panel sap-ss-tool">
		<header><h3>مقایسه دو سایت</h3><?php echo $cost( 2 ); ?></header>
		<p class="sap-muted sap-small">کلماتی که رقیب دارد و شما ندارید، یا جاهایی که از شما جلوتر است.</p>
		<form data-sap-ss-form="compare" class="sap-ss-form">
			<?php echo $f( 'سایت شما', '<input type="text" name="site1" dir="ltr" value="' . esc_attr( sap_project_domain( $project ) ) . '">' ); ?>
			<?php echo $f( 'رقیب', '<input type="text" name="site2" dir="ltr" required list="sap-competitors" placeholder="rival.ir">' ); ?>
			<?php echo $f( 'نوع مقایسه', '<select name="type">' . $opts( array( 'gap' => 'گپ: کلماتی که رقیب دارد و ما نداریم', 'worse_than' => 'کلماتی که رقیب از ما بهتر است', 'better_than' => 'کلماتی که ما از رقیب بهتریم', 'common' => 'کلمات مشترک' ) ) . '</select>', true ); ?>
			<button type="submit" class="sap-btn is-primary is-block">مقایسه</button>
		</form>
	</section>

	<section class="sap-panel sap-ss-tool is-wide">
		<header><h3>ردیاب رتبه</h3><?php echo $cost( 0 ); ?></header>
		<p class="sap-muted sap-small">وضعیت و تغییر رتبه کلمات پروژه ردیاب در بازه انتخابی، یا تاریخچه روزبه‌روز هر کلمه.</p>
		<form data-sap-ss-form="rt_status" class="sap-ss-form is-row">
			<?php echo $f( 'شناسه پروژه ردیاب', '<input type="number" name="project_id" value="' . esc_attr( $project['ss_project_id'] ?: '' ) . '" placeholder="مثلاً 37113">' ); ?>
			<?php echo $f( 'دستگاه', '<select name="device">' . $opts( array( 'mobile' => 'موبایل', 'desktop' => 'دسکتاپ' ), $project['ss_device'] ) . '</select>' ); ?>
			<?php echo $f( 'دامنه (خالی = سایت اصلی)', '<input type="text" name="domain" dir="ltr" list="sap-competitors" placeholder="یا یکی از رقبای پروژه">' ); ?>
			<?php echo $f( 'بازه', '<select name="days">' . $opts( array( '1' => 'دیروز تا امروز', '7' => '۷ روز', '30' => '۳۰ روز', '90' => '۹۰ روز' ), '30' ) . '</select>' ); ?>
			<div class="sap-ss-btns">
				<button type="submit" class="sap-btn is-primary">وضعیت تغییر رتبه‌ها</button>
				<button type="submit" class="sap-btn is-soft" data-sap-ss-alt="rt_history">تاریخچه روزبه‌روز</button>
				<button type="button" class="sap-btn is-ghost" data-sap-ss-run='{"tool":"rt_projects","params":{}}'>لیست پروژه‌ها</button>
			</div>
		</form>
	</section>

	<section class="sap-panel sap-ss-tool is-wide">
		<header><h3>دستیار رپورتاژ</h3><?php echo $cost( 1 ); ?></header>
		<p class="sap-muted sap-small">فقط در پلن‌های دارای «دستیار رپورتاژ». آنالیز خریدار علاوه بر سقف روزانه، از سقف ماهانه رپورتاژ (SRLimit) هم مصرف می‌کند.</p>
		<div class="sap-ss-rp">
			<form data-sap-ss-form="rp_suggest" class="sap-ss-form">
				<h4>پیشنهاد رسانه</h4>
				<?php echo $f( 'کلمات هدف (تا ۴، با ، جدا کنید)', '<input type="text" name="keywords" required placeholder="کفی طبی، کفش طبی">', true ); ?>
				<button type="submit" class="sap-btn is-primary is-block">پیشنهاد رسانه</button>
			</form>
			<form data-sap-ss-form="rp_check" class="sap-ss-form">
				<h4>جزئیات یک رسانه</h4>
				<?php echo $f( 'دامنه رسانه', '<input type="text" name="domain" dir="ltr" required placeholder="media.ir">' ); ?>
				<?php echo $f( 'کلمات (تا ۴)', '<input type="text" name="keywords" required>' ); ?>
				<button type="submit" class="sap-btn is-soft is-block">بررسی رسانه</button>
			</form>
			<form data-sap-ss-form="rp_seller" class="sap-ss-form">
				<h4>آنالیز فروشنده (رسانه)</h4>
				<?php echo $f( 'دامنه رسانه', '<input type="text" name="domain" dir="ltr" required placeholder="media.ir">', true ); ?>
				<?php echo $f( 'گزارش', '<select name="report_type">' . $opts( array( 'reportages' => 'رپورتاژهای منتشرشده', 'out_domains' => 'دامنه‌های لینک‌گرفته', 'out_links' => 'لینک‌های خروجی', 'out_anchors' => 'انکرهای خروجی', 'linked_pages' => 'صفحات لینک‌گرفته', 'range_domains' => 'بازه دامنه‌ها', 'serp' => 'رتبه خود رسانه در گوگل' ) ) . '</select>' ); ?>
				<?php echo $f( 'بازه', '<select name="date_range">' . $opts( $dates ) . '</select>' ); ?>
				<button type="submit" class="sap-btn is-soft is-block">آنالیز فروشنده</button>
			</form>
			<form data-sap-ss-form="rp_buyer" class="sap-ss-form">
				<h4>آنالیز خریدار (مثلاً رقیب)</h4>
				<?php echo $f( 'دامنه یا صفحه', '<input type="text" name="domain" dir="ltr" required list="sap-competitors" placeholder="rival.ir">' ); ?>
				<?php echo $f( 'محدوده', '<select name="search_type">' . $opts( array( 'domain' => 'کل دامنه', 'page' => 'یک صفحه' ) ) . '</select>' ); ?>
				<?php echo $f( 'گزارش', '<select name="report_type">' . $opts( array( 'reportages' => 'رپورتاژهای خریداری‌شده', 'inbound_media' => 'رسانه‌های لینک‌دهنده', 'linked_pages' => 'صفحات لینک‌گرفته', 'inbound_anchors' => 'انکرهای ورودی', 'cost_analysis' => 'تحلیل هزینه' ) ) . '</select>' ); ?>
				<?php echo $f( 'بازه', '<select name="date_range">' . $opts( $dates ) . '</select>' ); ?>
				<button type="submit" class="sap-btn is-soft is-block">آنالیز خریدار</button>
			</form>
		</div>
	</section>
</div>
<?php // phpcs:enable ?>

<section class="sap-panel sap-ss-results" data-sap-ss-results hidden>
	<div class="sap-panel-head">
		<h3 data-sap-ss-title>نتیجه</h3>
		<span class="sap-muted sap-small" data-sap-ss-meta></span>
	</div>
	<div class="sap-ss-extra" data-sap-ss-extra></div>
	<div class="sap-ss-use">
		<label class="sap-check"><input type="checkbox" data-sap-ss-all> انتخاب همه</label>
		<button type="button" class="sap-btn is-small" data-sap-ss-use="idea">ساخت یک ایده (اولی = کلمه اصلی)</button>
		<button type="button" class="sap-btn is-small" data-sap-ss-use="ideas">هر کدام یک ایده</button>
		<select data-sap-ss-page>
			<option value="">افزودن به کلمات فرعی صفحه…</option>
			<?php foreach ( $pages as $m ) : ?>
				<?php if ( '' !== $m['primary_kw'] ) : ?><option value="<?php echo esc_attr( $m['url'] ); ?>"><?php echo esc_html( $m['title'] . ' — ' . $m['primary_kw'] ); ?></option><?php endif; ?>
			<?php endforeach; ?>
		</select>
		<button type="button" class="sap-btn is-small" data-sap-ss-use="secondary">افزودن</button>
		<button type="button" class="sap-btn is-small is-ghost" data-sap-ss-use="volumes">ذخیره حجم‌ها در پروژه</button>
		<button type="button" class="sap-btn is-small is-ghost" data-sap-ss-refresh>دریافت تازه (مصرف سهمیه)</button>
	</div>
	<div class="sap-table-wrap"><table class="sap-table is-compact"><thead data-sap-ss-head></thead><tbody data-sap-ss-body></tbody></table></div>
</section>

<?php if ( $recent ) : ?>
	<section class="sap-panel">
		<h3>درخواست‌های اخیر</h3>
		<ul class="sap-ss-recent">
			<?php foreach ( $recent as $r ) : ?>
				<li><button type="button" class="sap-link" data-sap-ss-run='<?php echo esc_attr( wp_json_encode( array( 'tool' => $r['tool'], 'params' => $r['params'] ) ) ); ?>'><?php echo esc_html( ( $tools[ $r['tool'] ] ?? $r['tool'] ) . ': ' . implode( ' · ', array_filter( array_map( function ( $v ) { return is_scalar( $v ) ? mb_substr( (string) $v, 0, 40 ) : ''; }, (array) $r['params'] ) ) ) ); ?></button> <small class="sap-muted"><?php echo esc_html( sap_num( $r['count'] ) . ' ردیف · ' . sap_jdate( $r['at'] ) ); ?></small></li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
<input type="hidden" data-sap-ss-project value="<?php echo esc_attr( $pid ); ?>">
