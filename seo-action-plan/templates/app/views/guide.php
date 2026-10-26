<?php
/**
 * Guide: standard sheet structure + monthly workflow.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

$schema = sap_sheet_schema();
$dl     = function ( $type ) {
	return add_query_arg( array( 'action' => 'sap_sheet_template', 'type' => $type ), admin_url( 'admin-post.php' ) );
};
$uses   = array(
	'calendar' => array(
		'code'      => 'اختیاری؛ شناسه ردیف برای هماهنگی تیم.',
		'cluster'   => 'کلاستری که محتوا به آن تعلق دارد (همان نام شیت کلاسترینگ).',
		'keyword'   => 'الزامی. کلمه اصلی محتوا؛ رتبه و نمایش آن در سرچ کنسول برای همین آدرس بررسی می‌شود.',
		'related'   => 'کلمات هم‌کانسپت با «،» یا «,» جدا شوند؛ کلماتی که صفحه برایشان نمایشی ندارد گزارش می‌شوند.',
		'page_type' => 'مقاله، دسته‌بندی، محصول، لندینگ.',
		'intent'    => 'اطلاعاتی، تجاری، تراکنشی، ناوبری.',
		'status'    => 'ایده، در صف، سفارش داده شد، در حال نگارش، بازبینی، منتشر شد، نیاز به آپدیت.',
		'owner'     => 'نویسنده یا مسئول؛ برای کارهای «در انتظار» استفاده می‌شود.',
		'due'       => 'سررسید (شمسی ۱۴۰۵/۰۸/۲۰ یا میلادی).',
		'published' => 'تاریخ انتشار؛ انتشار جدید خودکار در لاگ اقدامات ثبت می‌شود.',
		'url'       => 'لینک محتوای منتشرشده. خالی بودن = هنوز منتشر نشده.',
		'notes'     => 'یادداشت آزاد.',
	),
	'clusters' => array(
		'cluster'    => 'الزامی. نام کلاستر (مثلاً «اسکوتر برقی»)؛ می‌توانید فقط در اولین ردیف هر بلوک بنویسید.',
		'subcluster' => 'زیرکلاستر/نیت (مثلاً «خرید»، «راهنما»)؛ کلمات بدون صفحه بر اساس آن گروه‌بندی می‌شوند.',
		'keyword'    => 'الزامی. یک کلمه در هر ردیف.',
		'role'       => 'پیلار، اصلی، فرعی. صفحه هدفِ ردیف «پیلار» صفحه اصلی Focus برای FAQ است.',
		'target'     => 'صفحه‌ای که باید برای این کلمه رتبه بگیرد؛ رتبه با صفحه دیگر = هشدار «صفحه اشتباه».',
		'priority'   => '۱ (بالا) تا ۳ (پایین).',
		'intent'     => 'نیت جستجو.',
		'notes'      => 'یادداشت آزاد.',
	),
);
$titles = array(
	'calendar' => 'تب ۱: «تقویم محتوا»',
	'clusters' => 'تب ۲: «کلاسترینگ»',
);
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">اکشن پلن سئو</p>
		<h1>راهنما و ساختار استاندارد گوگل شیت</h1>
		<p class="sap-muted">هر پروژه یک گوگل شیت با دو تب دارد. ستون‌ها با نام سرستون شناخته می‌شوند؛ ترتیب ستون‌ها مهم نیست و ستون‌های اضافه شما نادیده گرفته می‌شوند.</p>
	</div>
	<div class="sap-actions">
		<a class="sap-btn" href="<?php echo esc_url( $dl( 'calendar' ) ); ?>"><?php echo sap_icon( 'download' ); // phpcs:ignore ?>نمونه تقویم محتوا (CSV)</a>
		<a class="sap-btn" href="<?php echo esc_url( $dl( 'clusters' ) ); ?>"><?php echo sap_icon( 'download' ); // phpcs:ignore ?>نمونه کلاسترینگ (CSV)</a>
	</div>
</header>

<?php foreach ( array( 'calendar', 'clusters' ) as $type ) : ?>
	<section class="sap-panel">
		<h2><?php echo esc_html( $titles[ $type ] ); ?></h2>
		<div class="sap-table-wrap">
			<table class="sap-table">
				<thead><tr><th>سرستون</th><th>نام‌های قابل قبول دیگر</th><th>کاربرد در ابزار</th></tr></thead>
				<tbody>
					<?php foreach ( $schema[ $type ]['columns'] as $key => $def ) : ?>
						<tr>
							<td><b><?php echo esc_html( $def[0] ); ?></b><?php echo $def[2] ? ' <span class="sap-chip is-req">الزامی</span>' : ''; ?></td>
							<td class="sap-muted sap-small"><?php echo esc_html( implode( '، ', $def[1] ) ); ?></td>
							<td class="sap-small"><?php echo esc_html( $uses[ $type ][ $key ] ?? '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="sap-muted">ستون‌های خودکار (ابزار می‌نویسد؛ اگر نباشند، انتهای ردیف سرستون اضافه می‌شوند): <b><?php echo esc_html( implode( '، ', $schema[ $type ]['auto'] ) ); ?></b>. این ستون‌ها را دستی ویرایش نکنید؛ هر بار بازنویسی می‌شوند.</p>
	</section>
<?php endforeach; ?>

<section class="sap-panel">
	<h2>گردش کار ماهانه (GTD)</h2>
	<ol class="sap-guide-steps">
		<li><b>Capture (جمع‌آوری):</b> «تحلیل کامل» داده سرچ کنسول، شیت‌ها، سئو سیگنال و آخرین خزش Screaming Frog را می‌گیرد و ۲۵ قانون تحلیل، ایرادها و فرصت‌ها را در صندوق ورودی (تب «ایرادها و فرصت‌ها») می‌ریزد. همگام‌سازی خودکار هفتگی هم انجام می‌شود.</li>
		<li><b>Clarify (روشن‌سازی):</b> هر یافته را به اکشن پلن ماه اضافه کنید، به «شاید بعداً» بفرستید یا رد کنید. یافته‌ها با امتیاز اولویت (اثر تخمینی × اطمینان × وزن Focus ÷ زمان) مرتب‌اند.</li>
		<li><b>Organize (سازمان‌دهی):</b> «ساخت خودکار اکشن پلن» کارهای دوره‌ای و پرامتیازترین یافته‌ها را تا سقف ظرفیت ماه برمی‌دارد. کارها حوزه، Focus، مسئول، سررسید و وضعیت «در انتظار» دارند؛ محتوای سفارش‌داده‌شده خودکار «در انتظار» نویسنده است.</li>
		<li><b>Reflect (بازبینی):</b> هر هفته کارت «بازبینی هفتگی» در نمای کلی: صندوق ورودی، عقب‌افتاده‌ها و موارد در انتظار. اول ماه بعد، ناتمام‌ها را منتقل کنید.</li>
		<li><b>Engage (اجرا):</b> اعضای تیم در «کارهای من» کارشان را انجام‌شده می‌زنند و نتیجه را می‌نویسند. هر کار انجام‌شده، هر انتشار محتوا در شیت و هر خزش خودکار در لاگ اقدامات ثبت و از طریق API در گزارش SeoReport استفاده می‌شود.</li>
	</ol>
</section>

<section class="sap-panel">
	<h2>قوانین تحلیل</h2>
	<div class="sap-table-wrap">
		<table class="sap-table">
			<thead><tr><th>قانون</th><th>داده لازم</th></tr></thead>
			<tbody>
				<?php foreach ( sap_detectors() as $def ) : ?>
					<tr><td><?php echo esc_html( $def[0] ); ?></td><td class="sap-muted sap-small"><?php echo esc_html( implode( '، ', array_map( function ( $s ) {
						return sap_dataset_labels()[ $s ] ?? $s;
					}, $def[1] ) ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
