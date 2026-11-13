<?php
/**
 * Content calendar: what the month produces, in two parts.
 *
 *  1. New content — approved ideas (from «ایده‌های محتوا»), with who writes it,
 *     status, the published link and what it brought since publishing.
 *  2. Content updates — approved pages (from «آپدیت محتوا»), by kind of page,
 *     with goal, plan, task, status and before / after results.
 *
 * Both parts are counted against the monthly commitments and written to the
 * project's Google Sheet (Blog tab and «آپدیت محتوا» tab).
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid    = $project['id'];
$months = sap_calendar_months( $pid );
// phpcs:ignore WordPress.Security.NonceVerification
$period = isset( $_GET['period'] ) ? sap_period_sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ) ) ) : sap_period_current( $pid );
$opts   = sap_period_options( 2, 3 );
foreach ( array_keys( $months ) as $p ) {
	$opts[ $p ] = $opts[ $p ] ?? sap_period_label( $p );
}
krsort( $opts );
$items    = sap_calendar_items( $pid, $period );
$approved = sap_ideas( $pid, array( 'status' => 'approved' ) );
$sheet    = $project['sheet_id'];
$sheet_ok = '' !== $sheet && sap_google_connected() && sap_google_has_sheets();
$c        = sap_commit( $project );
$track    = sap_track_all( $pid );
$updates  = array_values( array_filter( sap_updates( $pid, array( 'period' => $period ) ), function ( $u ) {
	return 'cancelled' !== $u['status'];
} ) );
$ustats   = sap_update_statuses();
$gtype    = function ( $u ) {
	$t = (string) $u['content_type'];
	return in_array( $t, array( 'product_cat', 'product_tag', 'brand', 'category' ), true ) ? 'cat' : ( 'product' === $t ? 'product' : ( in_array( $t, array( 'post', 'article' ), true ) ? 'post' : 'other' ) );
};
$groups   = array( 'cat' => array(), 'product' => array(), 'post' => array(), 'other' => array() );
foreach ( $updates as $u ) {
	$groups[ $gtype( $u ) ][] = $u;
}
$glabel   = array( 'cat' => 'دسته‌بندی‌ها', 'product' => 'محصولات', 'post' => 'مقالات', 'other' => 'سایر صفحات' );
$pub      = count( array_filter( $items, function ( $i ) {
	return 'published' === $i['status'];
} ) );
$udone    = count( array_filter( $updates, function ( $u ) {
	return 'done' === $u['status'];
} ) );
$task_l   = function ( $tid ) {
	$t = $tid ? sap_task( $tid ) : null;
	if ( ! $t ) {
		return '<span class="sap-muted">هنوز کار نشده</span>';
	}
	$st = sap_task_state_chip( $t );
	return '<span class="sap-chip ' . esc_attr( $st[1] ) . '">' . esc_html( $st[0] ) . '</span> <small>' . esc_html( sap_user_name( $t['assignee'] ) ?: 'بدون مسئول' ) . '</small>';
};
$goal_of  = function ( $reason ) {
	if ( preg_match( '/^هدف:\s*(.+)$/mu', (string) $reason, $m ) ) {
		return trim( $m[1] );
	}
	$first = trim( (string) strtok( (string) $reason, "\n" ) );
	return '' !== $first ? mb_strimwidth( $first, 0, 110, '…' ) : '';
};
?>
<div class="sap-toolbar">
	<?php $fbase = sap_project_url( $pid, 'calendar' ); ?>
	<form method="get" class="sap-filters" action="<?php echo esc_url( strtok( $fbase, '?' ) ); ?>">
		<?php sap_filter_form( $fbase ); ?>
		<select name="period" onchange="this.form.submit()">
			<?php foreach ( $opts as $k => $l ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( $l . ( sap_period_current( $pid ) === $k ? ' (دوره جاری)' : '' ) ); ?></option>
			<?php endforeach; ?>
		</select>
	</form>
	<div class="sap-actions">
		<button type="button" class="sap-btn is-soft" data-sap-track="<?php echo esc_attr( $pid ); ?>" data-period="<?php echo esc_attr( $period ); ?>"><?php echo sap_icon( 'chart', 16 ); // phpcs:ignore ?>به‌روزرسانی نتایج از سرچ کنسول</button>
		<button type="button" class="sap-btn" data-sap-cal-sheet="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! $sheet_ok ); ?>><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>نوشتن در گوگل شیت</button>
		<?php if ( $sheet ) : ?>
			<a class="sap-btn is-ghost" href="<?php echo esc_url( 'https://docs.google.com/spreadsheets/d/' . $sheet . '/edit' ); ?>" target="_blank" rel="noopener">باز کردن شیت</a>
		<?php endif; ?>
	</div>
</div>

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

<section class="sap-panel sap-calx-sum">
	<div class="sap-calx-head">
		<div><p class="sap-eyebrow">تقویم محتوا</p><h2><?php echo esc_html( sap_period_label( $period ) ); ?> <small><?php echo esc_html( sap_period_dates( $period, $pid ) ); ?></small></h2></div>
	</div>
	<div class="sap-calx-kpis">
		<a href="#new" class="<?php echo $c['content_new'] && count( $items ) >= $c['content_new'] ? 'is-ok' : ''; ?>">
			<span>✍️ تولید محتوای جدید</span>
			<b><?php echo esc_html( sap_num( count( $items ) ) ); ?><?php echo $c['content_new'] ? '<small> / ' . esc_html( sap_num( $c['content_new'] ) ) . '</small>' : ''; ?></b>
			<small><?php echo esc_html( sprintf( '%s منتشر شده', sap_num( $pub ) ) ); ?><?php echo $c['content_new'] && count( $items ) < $c['content_new'] ? ' · ' . esc_html( sap_num( $c['content_new'] - count( $items ) ) ) . ' مورد کم' : ''; ?></small>
		</a>
		<a href="#updates" class="<?php echo $c['content_upd'] && count( $updates ) >= $c['content_upd'] ? 'is-ok' : ''; ?>">
			<span>🔄 آپدیت محتوا</span>
			<b><?php echo esc_html( sap_num( count( $updates ) ) ); ?><?php echo $c['content_upd'] ? '<small> / ' . esc_html( sap_num( $c['content_upd'] ) ) . '</small>' : ''; ?></b>
			<small><?php echo esc_html( sprintf( 'دسته %s · محصول %s · مقاله %s · %s انجام شد', sap_num( count( $groups['cat'] ) ), sap_num( count( $groups['product'] ) ), sap_num( count( $groups['post'] ) ), sap_num( $udone ) ) ); ?></small>
		</a>
	</div>
	<p class="sap-small sap-muted">ورود به تقویم فقط با تأیید شما: محتوای جدید از <a href="<?php echo esc_url( sap_project_url( $pid, 'ideas' ) ); ?>">ایده‌های محتوا</a> و آپدیت‌ها از <a href="<?php echo esc_url( sap_project_url( $pid, 'updates' ) ); ?>">آپدیت محتوا</a>. هر مورد در کارهای هفتگی به مسئولش واگذار می‌شود و بعد از انتشار/انجام، نتیجه‌اش از سرچ کنسول سنجیده می‌شود.</p>
</section>

<h2 class="sap-section-title" id="new">۱. تولید محتوای جدید <small class="sap-muted"><?php echo esc_html( sprintf( '%s مقاله · %s منتشر شده', sap_num( count( $items ) ), sap_num( $pub ) ) ); ?></small></h2>
<section class="sap-panel">
	<?php if ( ! $items ) : ?>
		<p class="sap-muted">این ماه هنوز محتوای جدیدی ندارد. در <a href="<?php echo esc_url( sap_project_url( $pid, 'ideas' ) ); ?>">ایده‌های محتوا</a> ایده بسازید یا دستی ثبت کنید و «تأیید و افزودن به تقویم» را بزنید.</p>
	<?php else : ?>
		<div class="sap-table-wrap">
			<table class="sap-table sap-calx-table">
				<thead><tr><th>#</th><th>کلمه اصلی و عنوان</th><th>بریف</th><th>نویسنده / کار</th><th>انتشار</th><th>نتیجه</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $items as $n => $it ) : ?>
					<?php $res = sap_track_line( $track[ 'i' . $it['id'] ] ?? null, 'new' ); $sec = sap_idea_secondary( $it ); ?>
					<tr data-sap-idea="<?php echo esc_attr( $it['id'] ); ?>" class="<?php echo 'published' === $it['status'] ? 'is-published' : ''; ?>">
						<td><?php echo esc_html( sap_num( $n + 1 ) ); ?><br><small class="sap-muted"><?php echo esc_html( $it['planned_date'] ? sap_jdate( $it['planned_date'], 'day' ) : '' ); ?></small></td>
						<td><b><?php echo esc_html( $it['keyword'] ); ?></b><?php echo $it['kp'] ? ' <small class="sap-muted">KP ' . esc_html( sap_num( $it['kp'] ) ) . '</small>' : ''; ?><br><span class="sap-small"><?php echo esc_html( $it['title'] ); ?></span><?php if ( $sec ) : ?><br><small class="sap-muted"><?php echo esc_html( 'فرعی: ' . implode( '، ', array_slice( array_column( $sec, 0 ), 0, 4 ) ) ); ?></small><?php endif; ?></td>
						<td class="sap-small sap-calx-brief"><?php echo esc_html( mb_strimwidth( (string) $it['brief'], 0, 140, '…' ) ); ?><?php if ( $it['links'] ) : ?><br><small class="sap-muted"><?php echo esc_html( sap_num( count( $it['links'] ) ) ); ?> لینک داخلی در بریف</small><?php endif; ?></td>
						<td class="sap-small"><?php echo $task_l( $it['task_id'] ); // phpcs:ignore ?></td>
						<td class="sap-small"><?php echo 'published' === $it['status'] ? ( $it['published_url'] ? sap_page_link( $it['published_url'], '✓ ' . ( sap_page_title( $pid, $it['published_url'] ) ?: 'منتشر شد' ) ) : '✓ منتشر شد' ) : '<span class="sap-muted">—</span>'; // phpcs:ignore ?></td>
						<td class="sap-small"><?php if ( '' !== $res['text'] ) : ?><span class="sap-calx-res <?php echo esc_attr( $res['tone'] ); ?>"><?php echo esc_html( $res['text'] ); ?></span><?php elseif ( 'published' === $it['status'] && ! $it['published_url'] ) : ?><span class="sap-muted">لینک ثبت نشده</span><?php endif; ?></td>
						<td class="sap-cal-actions">
							<?php if ( 'published' !== $it['status'] ) : ?><button type="button" class="sap-icon-btn" data-sap-idea-do="published" title="ثبت انتشار (لینک)"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?></button><?php endif; ?>
							<?php if ( ! $it['task_id'] ) : ?><button type="button" class="sap-icon-btn" data-sap-idea-do="task" title="ساخت کار در اکشن پلن"><?php echo sap_icon( 'list', 14 ); // phpcs:ignore ?></button><?php endif; ?>
							<button type="button" class="sap-icon-btn" data-sap-idea-do="unschedule" title="خارج کردن از تقویم"><?php echo sap_icon( 'x', 14 ); // phpcs:ignore ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
	<?php if ( $approved ) : ?>
		<details class="sap-calx-wait">
			<summary>ایده‌های تأییدشده که هنوز ماه ندارند (<?php echo esc_html( sap_num( count( $approved ) ) ); ?>)</summary>
			<div class="sap-content-pages">
				<?php foreach ( $approved as $a ) : ?>
					<div class="sap-content-page" data-sap-idea="<?php echo esc_attr( $a['id'] ); ?>">
						<div><b><?php echo esc_html( $a['keyword'] ); ?></b><small class="sap-muted"><?php echo esc_html( $a['title'] ); ?></small></div>
						<input type="hidden" data-sap-idea-month value="<?php echo esc_attr( $period ); ?>">
						<button type="button" class="sap-btn is-small" data-sap-idea-do="schedule">افزودن به <?php echo esc_html( sap_period_label( $period ) ); ?></button>
					</div>
				<?php endforeach; ?>
			</div>
		</details>
	<?php endif; ?>
</section>

<h2 class="sap-section-title" id="updates">۲. آپدیت محتوا <small class="sap-muted"><?php echo esc_html( sprintf( '%s صفحه · %s انجام شد', sap_num( count( $updates ) ), sap_num( $udone ) ) ); ?></small></h2>
<section class="sap-panel sap-calx-upd">
	<?php if ( ! $updates ) : ?>
		<p class="sap-muted">این ماه هنوز آپدیتی ندارد. در <a href="<?php echo esc_url( sap_project_url( $pid, 'updates' ) ); ?>">آپدیت محتوا</a> صفحه‌ها را بررسی و «تأیید و افزودن به تقویم» را بزنید.</p>
	<?php endif; ?>
	<?php foreach ( $groups as $g => $list ) : ?>
		<?php if ( ! $list ) { continue; } ?>
		<h3 class="sap-calx-g"><?php echo esc_html( $glabel[ $g ] ); ?> <em><?php echo esc_html( sap_num( count( $list ) ) ); ?></em></h3>
		<?php foreach ( $list as $u ) : ?>
			<?php $res = sap_track_line( $track[ 'u' . $u['id'] ] ?? null, 'upd' ); $goal = $goal_of( $u['reason'] ); $b = $u['brief']; ?>
			<details class="sap-calx-u is-<?php echo esc_attr( $u['status'] ); ?>">
				<summary>
					<span class="sap-calx-u-t"><b><?php echo esc_html( $u['title'] ); ?></b><?php if ( $u['keyword'] ) : ?><small>🔑 <?php echo esc_html( $u['keyword'] ); ?></small><?php endif; ?></span>
					<span class="sap-calx-u-g"><?php echo '' !== $goal ? esc_html( $goal ) : '<span class="sap-muted">هدف ثبت نشده</span>'; // phpcs:ignore ?></span>
					<span class="sap-calx-u-s"><span class="sap-chip <?php echo 'done' === $u['status'] ? 'is-good' : ( 'in_progress' === $u['status'] ? 'is-warn' : '' ); ?>"><?php echo esc_html( $ustats[ $u['status'] ] ?? $u['status'] ); ?></span></span>
					<span class="sap-calx-u-r"><?php if ( '' !== $res['text'] ) : ?><span class="sap-calx-res <?php echo esc_attr( $res['tone'] ); ?>"><?php echo esc_html( $res['text'] ); ?></span><?php else : ?><?php echo $task_l( $u['task_id'] ); // phpcs:ignore ?><?php endif; ?></span>
				</summary>
				<div class="sap-calx-u-body">
					<p class="sap-small"><?php echo sap_page_link( $u['url'], rawurldecode( $u['url'] ) ); // phpcs:ignore ?><?php echo $u['secondary'] ? ' · فرعی: ' . esc_html( implode( '، ', array_slice( (array) $u['secondary'], 0, 6 ) ) ) : ''; ?></p>
					<?php if ( '' !== trim( $u['reason'] ) ) : ?><div class="sap-calx-plan"><?php echo nl2br( esc_html( $u['reason'] ) ); // phpcs:ignore ?></div><?php endif; ?>
					<?php if ( $b ) : ?>
						<details class="sap-brief"><summary>بریف حرفه‌ای<?php echo ! empty( $b['words_target'] ) ? ' · حدود ' . esc_html( sap_num( $b['words_target'] ) ) . ' کلمه' : ''; ?></summary>
							<p><?php echo esc_html( $b['summary'] ?? '' ); ?></p>
							<?php if ( ! empty( $b['title'] ) ) : ?><p class="sap-small"><b>تایتل:</b> <?php echo esc_html( $b['title'] ); ?></p><?php endif; ?>
							<?php if ( ! empty( $b['meta'] ) ) : ?><p class="sap-small"><b>متا:</b> <?php echo esc_html( $b['meta'] ); ?></p><?php endif; ?>
							<?php if ( ! empty( $b['outline'] ) ) : ?><ol class="sap-small"><?php foreach ( $b['outline'] as $o ) : ?><li><b><?php echo esc_html( $o['heading'] ?? '' ); ?></b> — <?php echo esc_html( $o['notes'] ?? '' ); ?></li><?php endforeach; ?></ol><?php endif; ?>
							<?php if ( ! empty( $b['faq'] ) ) : ?><p class="sap-small"><b>FAQ:</b> <?php echo esc_html( implode( ' | ', $b['faq'] ) ); ?></p><?php endif; ?>
						</details>
					<?php endif; ?>
					<div class="sap-calx-u-acts">
						<span class="sap-small"><?php echo $task_l( $u['task_id'] ); // phpcs:ignore ?></span>
						<button type="button" class="sap-btn is-small is-soft" data-sap-update-do="brief" data-id="<?php echo esc_attr( $u['id'] ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo $b ? 'بریف دوباره' : 'ساخت بریف حرفه‌ای'; ?></button>
						<a class="sap-btn is-small is-ghost" href="<?php echo esc_url( sap_project_url( $pid, 'content', array( 'url' => $u['url'] ) ) ); ?>">بازنویسی با هوش مصنوعی</a>
						<?php if ( ! $u['task_id'] ) : ?><button type="button" class="sap-btn is-small is-ghost" data-sap-update-do="task" data-id="<?php echo esc_attr( $u['id'] ); ?>">ساخت کار</button><?php endif; ?>
						<?php if ( 'done' !== $u['status'] ) : ?><button type="button" class="sap-btn is-small is-ghost" data-sap-update-do="status" data-status="done" data-id="<?php echo esc_attr( $u['id'] ); ?>">انجام شد</button><?php endif; ?>
						<button type="button" class="sap-icon-btn" data-sap-update-do="delete" data-id="<?php echo esc_attr( $u['id'] ); ?>" title="حذف از تقویم"><?php echo sap_icon( 'trash', 15 ); // phpcs:ignore ?></button>
					</div>
				</div>
			</details>
		<?php endforeach; ?>
	<?php endforeach; ?>
</section>
