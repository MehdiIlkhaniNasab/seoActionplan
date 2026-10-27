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

<div class="sap-ss-grid">
	<section class="sap-panel sap-ss-tool">
		<h3>تحقیق کلمه کلیدی <small class="sap-muted">۱ واحد</small></h3>
		<p class="sap-muted sap-small">حجم، رقابت، روند ماهانه و سهم کلیک رقبا برای یک کلمه، با ۵ مدل جستجو.</p>
		<form data-sap-ss-form="research">
			<input type="text" name="keyword" required placeholder="مثلاً: کفی طبی">
			<select name="type"><?php foreach ( sap_ss_research_types() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
			<input type="number" name="count" value="200" min="10" max="1000" class="is-tiny" title="حداکثر تعداد نتیجه">
			<button type="submit" class="sap-btn is-small is-primary">جستجو</button>
		</form>
	</section>
	<section class="sap-panel sap-ss-tool">
		<h3>تحقیق دسته‌ای کلمات <small class="sap-muted">۱ واحد تا ۱۰۰۰ کلمه</small></h3>
		<form data-sap-ss-form="bulk">
			<textarea name="keywords" rows="3" required placeholder="هر خط یک کلمه"></textarea>
			<button type="submit" class="sap-btn is-small is-primary">گرفتن حجم</button>
		</form>
	</section>
	<section class="sap-panel sap-ss-tool">
		<h3>آنالیز کلمات سایت <small class="sap-muted">۱ واحد</small></h3>
		<p class="sap-muted sap-small">کلمات و صفحات برتر یک دامنه یا آدرس (مثلاً رقیب)، به‌همراه سایت‌های مرتبط.</p>
		<form data-sap-ss-form="analyzer">
			<input type="text" name="url" dir="ltr" required list="sap-competitors" placeholder="rival.ir یا آدرس صفحه">
			<select name="type"><option value="domain">کل دامنه</option><option value="page">یک صفحه</option><option value="starting_with">آدرس‌هایی که با این شروع می‌شوند</option><option value="contains">آدرس‌هایی که شامل عبارت هستند</option></select>
			<input type="text" name="contains" dir="ltr" placeholder="عبارت (برای «شامل»)" class="is-tiny" style="width:150px">
			<input type="number" name="count" value="500" min="10" max="2000" class="is-tiny" title="حداکثر تعداد">
			<button type="submit" class="sap-btn is-small is-primary">آنالیز</button>
		</form>
	</section>
	<section class="sap-panel sap-ss-tool">
		<h3>مقایسه دو سایت <small class="sap-muted">۲ واحد</small></h3>
		<form data-sap-ss-form="compare">
			<input type="text" name="site1" dir="ltr" value="<?php echo esc_attr( sap_project_domain( $project ) ); ?>">
			<input type="text" name="site2" dir="ltr" required list="sap-competitors" placeholder="رقیب: rival.ir">
			<datalist id="sap-competitors"><?php foreach ( $project['competitors'] as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"><?php endforeach; ?></datalist>
			<select name="type">
				<option value="gap">گپ: کلماتی که رقیب دارد و ما نداریم</option>
				<option value="worse_than">کلماتی که رقیب از ما بهتر است</option>
				<option value="better_than">کلماتی که ما از رقیب بهتریم</option>
				<option value="common">کلمات مشترک</option>
			</select>
			<button type="submit" class="sap-btn is-small is-primary">مقایسه</button>
		</form>
	</section>
	<section class="sap-panel sap-ss-tool is-wide">
		<h3>دستیار رپورتاژ <small class="sap-muted">۱ واحد هر درخواست · فقط پلن‌های دارای دستیار رپورتاژ</small></h3>
		<div class="sap-ss-sub">
			<form data-sap-ss-form="rp_suggest">
				<b>پیشنهاد رسانه</b>
				<input type="text" name="keywords" required placeholder="تا ۴ کلمه، با ، جدا کنید">
				<button type="submit" class="sap-btn is-small is-primary">پیشنهاد بده</button>
			</form>
			<form data-sap-ss-form="rp_check">
				<b>جزئیات رسانه</b>
				<input type="text" name="domain" dir="ltr" required placeholder="دامنه رسانه">
				<input type="text" name="keywords" required placeholder="کلمات (تا ۴)">
				<button type="submit" class="sap-btn is-small">بررسی</button>
			</form>
			<form data-sap-ss-form="rp_seller">
				<b>آنالیز فروشنده</b>
				<input type="text" name="domain" dir="ltr" required placeholder="دامنه رسانه">
				<select name="report_type"><option value="reportages">رپورتاژهای منتشرشده</option><option value="out_domains">دامنه‌های لینک‌گرفته</option><option value="out_links">لینک‌های خروجی</option><option value="out_anchors">انکرهای خروجی</option><option value="linked_pages">صفحات لینک‌گرفته</option><option value="range_domains">بازه دامنه‌ها</option><option value="serp">رتبه خود رسانه در گوگل</option></select>
				<select name="date_range"><option value="all">همه زمان‌ها</option><option value="last_90">۹۰ روز اخیر</option></select>
				<button type="submit" class="sap-btn is-small">آنالیز</button>
			</form>
			<form data-sap-ss-form="rp_buyer">
				<b>آنالیز خریدار</b>
				<input type="text" name="domain" dir="ltr" required list="sap-competitors" placeholder="دامنه (مثلاً رقیب)">
				<select name="report_type"><option value="reportages">رپورتاژهای خریداری‌شده</option><option value="inbound_media">رسانه‌های لینک‌دهنده</option><option value="linked_pages">صفحات لینک‌گرفته</option><option value="inbound_anchors">انکرهای ورودی</option><option value="cost_analysis">تحلیل هزینه</option></select>
				<select name="date_range"><option value="all">همه زمان‌ها</option><option value="last_90">۹۰ روز اخیر</option></select>
				<select name="search_type"><option value="domain">کل دامنه</option><option value="page">یک صفحه</option></select>
				<button type="submit" class="sap-btn is-small">آنالیز</button>
			</form>
		</div>
		<p class="sap-muted sap-small">آنالیز خریدار علاوه بر سقف روزانه، از سقف ماهانه آنالیز سایت (SRLimit) هم مصرف می‌کند.</p>
	</section>
	<section class="sap-panel sap-ss-tool is-wide">
		<h3>ردیاب رتبه <small class="sap-muted">رایگان</small></h3>
		<form data-sap-ss-form="rt_status">
			<input type="number" name="project_id" value="<?php echo esc_attr( $project['ss_project_id'] ?: '' ); ?>" placeholder="شناسه پروژه ردیاب" class="is-tiny" style="width:140px">
			<select name="device"><option value="mobile" <?php selected( $project['ss_device'], 'mobile' ); ?>>موبایل</option><option value="desktop" <?php selected( $project['ss_device'], 'desktop' ); ?>>دسکتاپ</option></select>
			<input type="text" name="domain" dir="ltr" placeholder="دامنه (خالی = دامنه اصلی، یا رقیب پروژه)" style="width:240px">
			<select name="days"><option value="1">دیروز تا امروز</option><option value="7">۷ روز</option><option value="30" selected>۳۰ روز</option><option value="90">۹۰ روز</option></select>
			<button type="submit" class="sap-btn is-small is-primary">وضعیت تغییر رتبه‌ها</button>
			<button type="submit" class="sap-btn is-small" data-sap-ss-alt="rt_history">تاریخچه روزبه‌روز</button>
			<button type="button" class="sap-btn is-small" data-sap-ss-run='{"tool":"rt_projects","params":{}}'>لیست پروژه‌ها</button>
		</form>
	</section>
</div>

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
