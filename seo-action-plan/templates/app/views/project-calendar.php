<?php
/**
 * Content calendar: monthly blocks in the agency's Blog layout, synced with the
 * project's Google Sheet (Blog + B keyword tabs).
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid    = $project['id'];
$months = sap_calendar_months( $pid );
// phpcs:ignore WordPress.Security.NonceVerification
$period = isset( $_GET['period'] ) ? sap_period_sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ) ) ) : sap_period_current();
$opts   = sap_period_options( 0, 4 );
foreach ( array_keys( $months ) as $p ) {
	$opts[ $p ] = $opts[ $p ] ?? sap_period_label( $p );
}
krsort( $opts );
$items    = sap_calendar_items( $pid, $period );
$approved = sap_ideas( $pid, array( 'status' => 'approved' ) );
$label    = $months[ $period ] ?? 'M' . ( count( $months ) + 1 );
$done     = count( array_filter( $items, function ( $i ) {
	return 'published' === $i['status'];
} ) );
$kp       = array_sum( array_column( $items, 'kp' ) );
$sheet    = $project['sheet_id'];
$sheet_ok = '' !== $sheet && sap_google_connected() && sap_google_has_sheets();
?>
<?php if ( '' === $sheet || empty( $project['options']['sheet_created'] ) ) : ?>
	<section class="sap-panel sap-sheet-create">
		<div class="sap-keymap-head">
			<div>
				<h2><?php echo sap_icon( 'log' ); // phpcs:ignore ?><?php echo '' === $sheet ? 'ساخت گوگل شیت پروژه' : 'آماده‌سازی گوگل شیت پروژه'; ?></h2>
				<p class="sap-muted">بعد از تأیید شما، یک گوگل شیت با این تب‌ها در حساب گوگل متصل ساخته و به پروژه وصل می‌شود و از این به بعد خودکار بروز می‌ماند:</p>
				<ul class="sap-tab-list">
					<li><b>Blog</b> — تقویم محتوا با ساختار شما: بلوک‌های ماهانه M1، M2…، Main Idea، KP، Title، پنج لینک خروجی، Content Source، Status و Link</li>
					<li><b>B keyword</b> — خوشه کلمات هر مقاله با حجم جستجو و جمع</li>
					<li><b>کلاسترینگ</b> — ساخته‌شده از نقشه کلمات (کلمه اصلی هر صفحه = پیلار)</li>
					<li><b>نقشه کلمات</b> — کلمه اصلی و فرعی همه صفحات</li>
					<li><b>لاگ تغییرات</b> — همه اقدامات با جزئیات قبل/بعد، برای گزارش ماهانه</li>
				</ul>
			</div>
		</div>
		<div class="sap-sheet-create-actions">
			<?php if ( '' === $sheet ) : ?>
				<button type="button" class="sap-btn is-primary" data-sap-sheet-create="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_google_connected() ); ?>><?php echo sap_icon( 'check' ); // phpcs:ignore ?>تأیید و ساخت گوگل شیت</button>
				<span class="sap-muted">یا شیت خالی موجود:</span>
			<?php else : ?>
				<span class="sap-muted">شیت فعلی پروژه به این ساختار تبدیل شود؟ (تب‌های فعلی دست نمی‌خورند؛ تب‌های جدید اضافه می‌شوند)</span>
			<?php endif; ?>
			<input type="url" dir="ltr" placeholder="https://docs.google.com/spreadsheets/d/…" value="<?php echo $sheet ? esc_attr( 'https://docs.google.com/spreadsheets/d/' . $sheet . '/edit' ) : ''; ?>" data-sap-sheet-existing>
			<button type="button" class="sap-btn" data-sap-sheet-create="<?php echo esc_attr( $pid ); ?>" data-existing="1" <?php disabled( ! sap_google_connected() ); ?>>آماده‌سازی این شیت</button>
		</div>
		<?php if ( ! sap_google_connected() ) : ?><p class="sap-warn">اول حساب گوگل را در تنظیمات متصل کنید.</p><?php endif; ?>
	</section>
<?php endif; ?>

<div class="sap-toolbar">
	<?php $fbase = sap_project_url( $pid, 'calendar' ); ?>
	<form method="get" class="sap-filters" action="<?php echo esc_url( strtok( $fbase, '?' ) ); ?>">
		<?php sap_filter_form( $fbase ); ?>
		<select name="period" onchange="this.form.submit()">
			<?php foreach ( $opts as $k => $l ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( ( $months[ $k ] ?? '' ) . ( isset( $months[ $k ] ) ? ' · ' : '' ) . $l ); ?></option>
			<?php endforeach; ?>
		</select>
	</form>
	<div class="sap-actions">
		<label class="sap-inline-label">تعداد مقاله در ماه <input type="number" class="is-tiny" min="1" max="20" value="<?php echo esc_attr( max( 8, count( $items ) ) ); ?>" data-sap-cal-target></label>
		<button type="button" class="sap-btn is-primary" data-sap-cal-fill="<?php echo esc_attr( $pid ); ?>" data-period="<?php echo esc_attr( $period ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تکمیل هوشمند تقویم این ماه</button>
		<button type="button" class="sap-btn" data-sap-cal-sheet="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! $sheet_ok ); ?>><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>نوشتن در گوگل شیت</button>
		<?php if ( $sheet ) : ?>
			<a class="sap-btn is-ghost" href="<?php echo esc_url( 'https://docs.google.com/spreadsheets/d/' . $sheet . '/edit' ); ?>" target="_blank" rel="noopener">باز کردن شیت</a>
		<?php endif; ?>
	</div>
</div>

<?php
$updates = sap_updates( $pid, array( 'period' => $period ) );
$ustats  = sap_update_statuses();
$utypes  = sap_content_types();
$updone  = count( array_filter( $updates, function ( $u ) {
	return 'done' === $u['status'];
} ) );
?>
<h2 class="sap-section-title">۱. آپدیت محتوای قبلی <small class="sap-muted"><?php echo esc_html( sprintf( '%s صفحه · %s انجام شده', sap_num( count( $updates ) ), sap_num( $updone ) ) ); ?></small></h2>
<section class="sap-panel sap-updates">
	<p class="sap-muted sap-small">صفحاتی که این ماه کامل و حرفه‌ای آپدیت می‌شوند (محصول: محتوا بر اساس کلمه کلیدی، ویژگی‌ها و مشخصات کامل، FAQ و اسکیما؛ دسته: متن راهنمای خرید؛ مقاله: پوشش کامل نیت، لینک داخلی به صفحات پولساز و منبع معتبر). از تب «سئو محتوا › تحلیل محتوا» یا از این فرم اضافه کنید و برای هر کدام بریف حرفه‌ای بگیرید.</p>
	<form class="sap-update-add" data-sap-update-form="<?php echo esc_attr( $pid ); ?>" data-period="<?php echo esc_attr( $period ); ?>">
		<label class="sap-field"><span>صفحه</span>
			<select name="url" required>
				<option value="">انتخاب صفحه از نقشه کلمات…</option>
				<?php foreach ( sap_keymap( $pid ) as $m ) : ?>
					<option value="<?php echo esc_attr( $m['url'] ); ?>"><?php echo esc_html( ( $utypes[ sap_content_type( $m ) ] ?? '' ) . ' — ' . ( $m['title'] ?: rawurldecode( $m['url'] ) ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="sap-field"><span>دلیل آپدیت (اختیاری)</span><input type="text" name="reason" placeholder="مثلاً افت رتبه، محتوای قدیمی، ویژگی‌های ناقص"></label>
		<button type="submit" class="sap-btn is-primary">افزودن به آپدیت <?php echo esc_html( sap_period_label( $period ) ); ?></button>
	</form>
	<?php if ( $updates ) : ?>
		<div class="sap-update-list">
			<?php foreach ( $updates as $u ) : ?>
				<?php $b = $u['brief']; ?>
				<article class="sap-update is-<?php echo esc_attr( $u['status'] ); ?>">
					<div class="sap-update-head">
						<div>
							<b><?php echo sap_page_link( $u['url'], $u['title'] ); // phpcs:ignore ?></b>
							<span class="sap-chip"><?php echo esc_html( $utypes[ $u['content_type'] ] ?? $u['content_type'] ); ?></span>
							<?php if ( $u['keyword'] ) : ?><span class="sap-chip is-soft"><?php echo esc_html( $u['keyword'] ); ?></span><?php endif; ?>
							<span class="sap-chip <?php echo 'done' === $u['status'] ? 'is-good' : ( 'in_progress' === $u['status'] ? 'is-warn' : '' ); ?>"><?php echo esc_html( $ustats[ $u['status'] ] ?? $u['status'] ); ?></span>
						</div>
						<div class="sap-update-actions">
							<button type="button" class="sap-btn is-small is-soft" data-sap-update-do="brief" data-id="<?php echo esc_attr( $u['id'] ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo $b ? 'بریف دوباره' : 'ساخت بریف حرفه‌ای'; ?></button>
							<a class="sap-btn is-small is-ghost" href="<?php echo esc_url( sap_project_url( $pid, 'content', array( 'url' => $u['url'] ) ) ); ?>">بازنویسی با هوش مصنوعی</a>
							<?php if ( ! $u['task_id'] ) : ?><button type="button" class="sap-btn is-small is-ghost" data-sap-update-do="task" data-id="<?php echo esc_attr( $u['id'] ); ?>">به اکشن پلن</button><?php endif; ?>
							<?php if ( 'done' !== $u['status'] ) : ?><button type="button" class="sap-btn is-small is-ghost" data-sap-update-do="status" data-status="done" data-id="<?php echo esc_attr( $u['id'] ); ?>">انجام شد</button><?php endif; ?>
							<button type="button" class="sap-icon-btn" data-sap-update-do="delete" data-id="<?php echo esc_attr( $u['id'] ); ?>" title="حذف"><?php echo sap_icon( 'trash', 15 ); // phpcs:ignore ?></button>
						</div>
					</div>
					<?php if ( $u['reason'] ) : ?><p class="sap-small sap-muted"><?php echo esc_html( $u['reason'] ); ?></p><?php endif; ?>
					<?php if ( $b ) : ?>
						<details class="sap-brief" open>
							<summary>بریف آپدیت<?php echo ! empty( $b['words_target'] ) ? ' · حدود ' . esc_html( sap_num( $b['words_target'] ) ) . ' کلمه' : ''; ?></summary>
							<p><?php echo esc_html( $b['summary'] ?? '' ); ?></p>
							<div class="sap-brief-grid">
								<div>
									<p class="sap-label">کلمات</p>
									<p class="sap-small"><b><?php echo esc_html( $b['keyword'] ?? '' ); ?></b> · <?php echo esc_html( implode( '، ', (array) ( $b['secondary'] ?? array() ) ) ); ?></p>
									<?php if ( ! empty( $b['title'] ) ) : ?><p class="sap-small"><b>تایتل:</b> <?php echo esc_html( $b['title'] ); ?></p><?php endif; ?>
									<?php if ( ! empty( $b['meta'] ) ) : ?><p class="sap-small"><b>متا:</b> <?php echo esc_html( $b['meta'] ); ?></p><?php endif; ?>
									<?php if ( ! empty( $b['h1'] ) ) : ?><p class="sap-small"><b>H1:</b> <?php echo esc_html( $b['h1'] ); ?></p><?php endif; ?>
									<?php if ( ! empty( $b['outline'] ) ) : ?>
										<p class="sap-label">ساختار و بخش‌ها</p>
										<ol class="sap-small"><?php foreach ( $b['outline'] as $o ) : ?><li><b><?php echo esc_html( $o['heading'] ?? '' ); ?></b> — <?php echo esc_html( $o['notes'] ?? '' ); ?></li><?php endforeach; ?></ol>
									<?php endif; ?>
								</div>
								<div>
									<?php if ( ! empty( $b['attributes'] ) ) : ?>
										<p class="sap-label">ویژگی‌های محصول که باید کامل شوند</p>
										<ul class="sap-small"><?php foreach ( $b['attributes'] as $at ) : ?><li><b><?php echo esc_html( $at['name'] ?? '' ); ?>:</b> <?php echo esc_html( $at['hint'] ?? '' ); ?></li><?php endforeach; ?></ul>
									<?php endif; ?>
									<?php if ( ! empty( $b['faq'] ) ) : ?><p class="sap-label">FAQ</p><ul class="sap-small"><?php foreach ( $b['faq'] as $q ) : ?><li><?php echo esc_html( $q ); ?></li><?php endforeach; ?></ul><?php endif; ?>
									<?php foreach ( array( 'links_out' => 'لینک داخلی از این صفحه', 'links_in' => 'لینک داخلی به این صفحه (از)' ) as $lk => $ll ) : ?>
										<?php if ( ! empty( $b[ $lk ] ) ) : ?><p class="sap-label"><?php echo esc_html( $ll ); ?></p><ul class="sap-small"><?php foreach ( $b[ $lk ] as $l ) : ?><li><?php echo sap_page_link( $l['url'] ?? '', sap_page_title( $pid, $l['url'] ?? '' ) ?: rawurldecode( $l['url'] ?? '' ) ); // phpcs:ignore ?> ← «<?php echo esc_html( $l['anchor'] ?? '' ); ?>»</li><?php endforeach; ?></ul><?php endif; ?>
									<?php endforeach; ?>
									<?php if ( ! empty( $b['external'] ) ) : ?><p class="sap-label">منبع/لینک خارجی معتبر</p><ul class="sap-small"><?php foreach ( $b['external'] as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul><?php endif; ?>
									<?php if ( ! empty( $b['schema'] ) ) : ?><p class="sap-small"><b>اسکیما:</b> <?php echo esc_html( implode( '، ', $b['schema'] ) ); ?></p><?php endif; ?>
									<?php if ( ! empty( $b['cta'] ) ) : ?><p class="sap-small"><b>CTA:</b> <?php echo esc_html( $b['cta'] ); ?></p><?php endif; ?>
								</div>
							</div>
							<?php if ( ! empty( $b['checklist'] ) ) : ?><p class="sap-label">چک‌لیست نهایی</p><ul class="sap-check-list"><?php foreach ( $b['checklist'] as $c ) : ?><li><i>☐</i><span><?php echo esc_html( $c ); ?></span></li><?php endforeach; ?></ul><?php endif; ?>
						</details>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>

<h2 class="sap-section-title">۲. تولید محتوای جدید</h2>
<section class="sap-panel sap-cal">
	<div class="sap-cal-head">
		<span class="sap-cal-m"><?php echo esc_html( $label ); ?></span>
		<div>
			<h2><?php echo esc_html( sap_period_label( $period ) ); ?></h2>
			<span class="sap-muted"><?php echo esc_html( sprintf( '%s مقاله · %s منتشر شده · مجموع KP %s', sap_num( count( $items ) ), sap_num( $done ), sap_num( $kp ) ) ); ?></span>
		</div>
	</div>
	<?php if ( ! $items ) : ?>
		<p class="sap-muted">این ماه هنوز مقاله‌ای ندارد. «تکمیل هوشمند تقویم» را بزنید، یا از تب «ایده‌های محتوا» ایده‌ها را تأیید و به این ماه اضافه کنید.</p>
	<?php else : ?>
		<div class="sap-table-wrap">
			<table class="sap-table sap-cal-table">
				<thead>
					<tr class="sap-cal-groups"><th></th><th colspan="4" class="is-guide">Writing Guide</th><th colspan="5" class="is-links">Out Links</th><th colspan="4" class="is-publish">Publish</th><th></th></tr>
					<tr><th>#</th><th>Main Idea</th><th>KP</th><th>Title</th><th>Desc</th><th>L1 / K1</th><th>L2 / K2</th><th>L3 / K3</th><th>L4 / K4</th><th>L5 / K5</th><th>Content Source</th><th>Status</th><th>Publish Date</th><th>Link</th><th></th></tr>
				</thead>
				<tbody>
				<?php foreach ( $items as $n => $it ) : ?>
					<tr data-sap-idea="<?php echo esc_attr( $it['id'] ); ?>" class="<?php echo 'published' === $it['status'] ? 'is-published' : ''; ?>">
						<td><?php echo esc_html( sap_num( $n + 1 ) ); ?><br><small class="sap-muted"><?php echo esc_html( $it['planned_date'] ? sap_jdate( $it['planned_date'], 'day' ) : '' ); ?></small></td>
						<td><b><?php echo esc_html( $it['keyword'] ); ?></b><?php $sec = sap_idea_secondary( $it ); ?><?php if ( $sec ) : ?><br><small class="sap-muted" title="<?php echo esc_attr( implode( '، ', array_column( $sec, 0 ) ) ); ?>"><?php echo esc_html( sap_num( count( $sec ) ) ); ?> کلمه فرعی</small><?php endif; ?></td>
						<td><?php echo $it['kp'] ? esc_html( sap_num( $it['kp'] ) ) : '—'; ?></td>
						<td class="sap-cal-title"><?php echo esc_html( $it['title'] ); ?></td>
						<td class="sap-small sap-cal-desc"><?php echo esc_html( mb_substr( $it['brief'], 0, 120 ) ); ?></td>
						<?php for ( $l = 0; $l < 5; $l++ ) : ?>
							<td class="sap-small"><?php if ( ! empty( $it['links'][ $l ] ) ) : ?><a href="<?php echo esc_url( $it['links'][ $l ]['url'] ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( rawurldecode( $it['links'][ $l ]['url'] ) ); ?>"><?php echo esc_html( $it['links'][ $l ]['anchor'] ); ?></a><?php else : ?>—<?php endif; ?></td>
						<?php endfor; ?>
						<td class="sap-small"><?php echo esc_html( mb_substr( $it['source_note'], 0, 80 ) ); ?></td>
						<td><?php echo 'published' === $it['status'] ? '<span class="sap-chip is-src-manual">✓</span>' : '<span class="sap-chip">—</span>'; ?></td>
						<td class="sap-small"><?php echo 'published' === $it['status'] ? esc_html( sap_jdate( substr( $it['updated_at'], 0, 10 ), 'day' ) ) : ''; ?></td>
						<td class="sap-small"><?php echo $it['published_url'] ? sap_page_link( $it['published_url'], sap_page_title( $pid, $it['published_url'] ) ) : ''; // phpcs:ignore ?></td>
						<td class="sap-cal-actions">
							<button type="button" class="sap-icon-btn" data-sap-idea-do="move" data-dir="up" title="بالا">▲</button>
							<button type="button" class="sap-icon-btn" data-sap-idea-do="move" data-dir="down" title="پایین">▼</button>
							<?php if ( 'published' !== $it['status'] ) : ?><button type="button" class="sap-icon-btn" data-sap-idea-do="published" title="ثبت انتشار (لینک)"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?></button><?php endif; ?>
							<?php if ( ! $it['task_id'] ) : ?><button type="button" class="sap-icon-btn" data-sap-idea-do="task" title="افزودن به اکشن پلن"><?php echo sap_icon( 'list', 14 ); // phpcs:ignore ?></button><?php endif; ?>
							<button type="button" class="sap-icon-btn" data-sap-idea-do="unschedule" title="خارج کردن از تقویم"><?php echo sap_icon( 'x', 14 ); // phpcs:ignore ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<?php if ( $approved ) : ?>
	<section class="sap-panel">
		<h3>ایده‌های تأییدشده بدون زمان (<?php echo esc_html( sap_num( count( $approved ) ) ); ?>)</h3>
		<div class="sap-content-pages">
			<?php foreach ( $approved as $a ) : ?>
				<div class="sap-content-page" data-sap-idea="<?php echo esc_attr( $a['id'] ); ?>">
					<div><b><?php echo esc_html( $a['keyword'] ); ?></b><small class="sap-muted"><?php echo esc_html( $a['title'] ); ?></small></div>
					<input type="hidden" data-sap-idea-month value="<?php echo esc_attr( $period ); ?>">
					<button type="button" class="sap-btn is-small" data-sap-idea-do="schedule">افزودن به <?php echo esc_html( sap_period_label( $period ) ); ?></button>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>
