<?php
/**
 * Focuses («زاویه دید») and goals.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$focuses  = sap_focuses( $pid );
$clusters = array_values( array_unique( array_filter( array_column( (array) ( sap_dataset_payload( $pid, 'sheet_clusters' )['rows'] ?? array() ), 'cluster' ) ) ) );
$post     = esc_url( admin_url( 'admin-post.php' ) );

/**
 * Focus form fields.
 */
$fields = function ( $f ) use ( $clusters ) {
	?>
	<div class="sap-grid-3">
		<label>نام Focus<input type="text" name="name" required value="<?php echo esc_attr( $f['name'] ?? '' ); ?>" placeholder="اسکوتر برقی"></label>
		<div><p class="sap-label">وضعیت</p><?php sap_segment( 'status', array( 'active' => 'فعال', 'paused' => 'متوقف', 'done' => 'به هدف رسید' ), $f['status'] ?? 'active' ); ?></div>
		<label>وزن اولویت (۱ تا ۳)<input type="number" name="weight" min="1" max="3" step="0.1" value="<?php echo esc_attr( $f['weight'] ?? 1.5 ); ?>">
			<small>یافته‌های این Focus با این ضریب اولویت بیشتری می‌گیرند.</small>
		</label>
	</div>
	<div class="sap-grid-3">
		<label>الگوی آدرس‌ها (هر خط یکی)<textarea name="url_patterns" rows="2" dir="ltr" data-sap-tags placeholder="/product-category/electric-scooter/&#10;/blog/scooter-*"><?php echo esc_textarea( implode( "\n", $f['url_patterns'] ?? array() ) ); ?></textarea>
			<small>پیشوند مسیر؛ * برای هر عبارت.</small>
		</label>
		<label>کلاسترهای شیت کلاسترینگ<textarea name="clusters" rows="2" data-sap-tags placeholder="اسکوتر برقی"><?php echo esc_textarea( implode( "\n", $f['clusters'] ?? array() ) ); ?></textarea>
			<small><?php echo $clusters ? 'کلاسترهای موجود در شیت: ' . esc_html( implode( '، ', array_slice( $clusters, 0, 15 ) ) ) : 'بعد از همگام‌سازی شیت، نام کلاسترها اینجا پیشنهاد می‌شود.'; ?></small>
		</label>
		<label>کلمات کلیدی اضافه (اولی = کلمه اصلی)<textarea name="keywords" rows="2" data-sap-tags placeholder="اسکوتر برقی&#10;خرید اسکوتر برقی"><?php echo esc_textarea( implode( "\n", $f['keywords'] ?? array() ) ); ?></textarea>
			<small>کلمه اول برای دریافت سوالات کاربران (FAQ) از سئو سیگنال استفاده می‌شود.</small>
		</label>
	</div>
	<label>هدف (به زبان ساده)<input type="text" name="goal" value="<?php echo esc_attr( $f['goal'] ?? '' ); ?>" placeholder="افزایش ۳۰٪ کلیک ارگانیک دسته اسکوتر برقی تا پایان دی"></label>
	<div class="sap-grid-3">
		<div><p class="sap-label">شاخص اندازه‌گیری</p><?php sap_segment( 'kpi_metric', array( 'clicks' => 'کلیک', 'impressions' => 'نمایش', 'top10' => 'صفحه اول', 'top3' => 'رتبه ۱-۳' ), $f['kpi_metric'] ?? 'clicks' ); ?></div>
		<label>نقطه شروع (Baseline)<input type="number" step="any" name="kpi_baseline" value="<?php echo esc_attr( $f['kpi_baseline'] ?? '' ); ?>" placeholder="خالی = مقدار فعلی"></label>
		<label>مقدار هدف<input type="number" step="any" name="kpi_target" value="<?php echo esc_attr( ( $f['kpi_target'] ?? 0 ) ?: '' ); ?>"></label>
		<label>مهلت<input type="text" name="deadline" value="<?php echo esc_attr( ! empty( $f['deadline'] ) ? sap_jdate( $f['deadline'], 'short' ) : '' ); ?>" placeholder="۱۴۰۵/۱۰/۳۰"></label>
	</div>
	<?php
};
?>
<?php
$ai_focus = sap_dataset( $pid, 'ai_focus' );
$metrics  = sap_kpi_metrics();
?>
<section class="sap-panel sap-focus-ai">
	<div class="sap-panel-head">
		<h2><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>هدف‌گذاری هوشمند</h2>
		<button type="button" class="sap-btn is-primary is-small" data-sap-step="ai_focus" data-force="1" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?><?php echo $ai_focus ? 'تحلیل دوباره' : 'پیشنهاد Focus و هدف'; ?></button>
	</div>
	<p class="sap-muted">هوش مصنوعی بخش‌های سایت و Focusهای فعلی را با داده واقعی (کلیک، نمایش، کلمات نزدیک صفحه اول، حجم جستجو، ایرادها) می‌سنجد و برای هر کدام نام فارسی، هدف عددی، بهترین شاخص شروع، مهلت و وزن اولویت را با دلیل پیشنهاد می‌دهد. شما فقط تأیید می‌کنید.</p>
	<?php if ( $ai_focus ) : ?>
		<div class="sap-focus-recs">
			<?php foreach ( (array) ( $ai_focus['payload']['focuses'] ?? array() ) as $r ) : ?>
				<article class="sap-focus-rec">
					<header>
						<h3><?php echo esc_html( $r['name'] ); ?></h3>
						<span class="sap-chip <?php echo $r['existing'] ? '' : 'is-ai'; ?>"><?php echo $r['existing'] ? 'Focus فعلی' : 'پیشنهاد جدید'; ?></span>
						<span class="sap-weight" title="وزن اولویت"><?php echo esc_html( sap_num( $r['weight'], 1 ) ); ?>×</span>
					</header>
					<p class="sap-focus-goal"><?php echo esc_html( $r['goal'] ); ?></p>
					<div class="sap-scores">
						<?php foreach ( array( 'potential' => 'ظرفیت رشد', 'value' => 'ارزش کسب‌وکار', 'urgency' => 'فوریت' ) as $k => $label ) : ?>
							<div><span><?php echo esc_html( $label ); ?></span><i style="--v:<?php echo esc_attr( min( 10, max( 0, (int) $r[ $k ] ) ) * 10 ); ?>%"></i><b><?php echo esc_html( sap_num( $r[ $k ] ) ); ?></b></div>
						<?php endforeach; ?>
					</div>
					<dl class="sap-kv-mini">
						<dt>شاخص شروع</dt><dd><b><?php echo esc_html( $metrics[ $r['metric'] ] ?? $r['metric'] ); ?></b> — <?php echo esc_html( $r['metric_reason'] ); ?></dd>
						<dt>از ← تا</dt><dd><?php echo esc_html( ( null !== $r['current'] ? sap_num( $r['current'] ) : '—' ) . ' ← ' . sap_num( $r['target'] ) . ' در ' . sap_num( $r['months'] ) . ' ماه' ); ?></dd>
						<dt>چرا</dt><dd><?php echo esc_html( $r['rationale'] ); ?></dd>
					</dl>
					<?php if ( $r['first_steps'] ) : ?>
						<ol class="sap-small"><?php foreach ( $r['first_steps'] as $st ) : ?><li><?php echo esc_html( $st ); ?></li><?php endforeach; ?></ol>
					<?php endif; ?>
					<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
						<?php sap_form_fields( 'sap_ai_focus_apply', array( 'project_id' => $pid, 'ref' => $r['ref'] ) ); ?>
						<button class="sap-btn is-small is-primary" type="submit"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?><?php echo $r['existing'] ? 'اعمال هدف و وزن' : 'ساخت این Focus'; ?></button>
					</form>
				</article>
			<?php endforeach; ?>
		</div>
		<small class="sap-muted"><?php echo esc_html( sap_jdate( $ai_focus['fetched_at'] ) . ( isset( $ai_focus['meta']['cost'] ) ? ' · ' . sap_num( $ai_focus['meta']['cost'], 3 ) . ' دلار' : '' ) ); ?></small>
	<?php endif; ?>
</section>

<section class="sap-panel">
	<h2><?php echo sap_icon( 'plus' ); // phpcs:ignore ?>Focus جدید</h2>
	<p class="sap-muted">Focus بخشی از سایت است که الان روی آن کار می‌کنید (مثلاً یک دسته محصول). یافته‌ها و اکشن پلن در راستای آن اولویت می‌گیرند؛ ایرادهای حیاتی کل سایت هم دیده می‌شوند.</p>
	<?php sap_view( 'focus-quick', array( 'project' => $project, 'return' => sap_project_url( $pid, 'focuses' ) ) ); ?>
</section>

<?php
foreach ( $focuses as $f ) :
	$k = sap_focus_kpis( $f, $pid );
	?>
	<details class="sap-panel sap-focus-edit">
		<summary><?php echo sap_icon( 'target', 16 ); // phpcs:ignore ?>
			<b><?php echo esc_html( $f['name'] ); ?></b>
			<span class="sap-muted"><?php echo esc_html( 'active' === $f['status'] ? 'فعال' : ( 'paused' === $f['status'] ? 'متوقف' : 'به هدف رسید' ) ); ?></span>
			<?php if ( $k ) : ?>
				<span class="sap-muted"><?php echo esc_html( sprintf( '%s: %s', sap_kpi_metrics()[ $f['kpi_metric'] ], sap_num( $k[ $f['kpi_metric'] ] ) ) ); ?></span>
			<?php endif; ?>
		</summary>
		<?php if ( ! empty( $f['ai_meta']['rationale'] ) ) : ?>
			<div class="sap-ai-note"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?><div><b>دلیل هدف و اولویت:</b> <?php echo esc_html( $f['ai_meta']['rationale'] ); ?><?php if ( ! empty( $f['ai_meta']['metric_reason'] ) ) : ?><br><b>شاخص:</b> <?php echo esc_html( $f['ai_meta']['metric_reason'] ); ?><?php endif; ?></div></div>
		<?php endif; ?>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
			<?php sap_form_fields( 'sap_save_focus', array( 'project_id' => $pid, 'focus_id' => $f['id'] ) ); ?>
			<?php $fields( $f ); ?>
			<div class="sap-form-foot">
				<button class="sap-btn is-primary" type="submit">ذخیره</button>
			</div>
		</form>
		<div class="sap-actions">
			<?php if ( $k ) : ?>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
					<?php sap_form_fields( 'sap_focus_baseline', array( 'project_id' => $pid, 'focus_id' => $f['id'] ) ); ?>
					<button class="sap-btn is-small" type="submit">ثبت مقدار فعلی (<?php echo esc_html( sap_num( $k[ $f['kpi_metric'] ] ) ); ?>) به‌عنوان نقطه شروع</button>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>" onsubmit="return confirm('Focus حذف شود؟')">
				<?php sap_form_fields( 'sap_delete_focus', array( 'project_id' => $pid, 'focus_id' => $f['id'] ) ); ?>
				<button class="sap-btn is-small is-ghost is-danger" type="submit">حذف Focus</button>
			</form>
		</div>
	</details>
<?php endforeach; ?>
