<?php
/**
 * Content calendar: the month's plan as one editable sheet inside the panel.
 *
 * New articles and content updates sit in the same columns, so the plan reads
 * like the agency's sheet without its mess: every row says what it is (new / an
 * update of a category, product or article), updates carry the page link and
 * where it stood before (position, 28-day clicks and impressions), new articles
 * carry their exact publish date, and both show what they brought afterwards.
 * Keyword, title, brief, internal links and anchors, source, status, link and
 * dates are edited in place; the Google Sheet follows within a minute.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid    = $project['id'];
sap_update_reconcile( $pid );
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
$groups   = array( 'cat' => array(), 'product' => array(), 'post' => array(), 'other' => array() );
foreach ( $updates as $u ) {
	$groups[ sap_update_kind( $u ) ][] = $u;
}
$kinds    = sap_update_kinds();
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
// One editable cell: shown text, the raw value to edit and how to edit it.
$cell     = function ( $field, $value, $shown = null, $type = 'text', $attrs = '' ) {
	$shown = null === $shown ? esc_html( (string) $value ) : $shown;
	printf(
		'<td class="sap-sc is-%1$s" data-f="%2$s" data-type="%3$s" data-v="%4$s" tabindex="0" %6$s><div class="sap-sc-v">%5$s</div></td>',
		esc_attr( $type ),
		esc_attr( $field ),
		esc_attr( $type ),
		esc_attr( (string) $value ),
		'' !== trim( wp_strip_all_tags( (string) $shown ) ) ? $shown : '<span class="sap-sc-empty">—</span>', // phpcs:ignore
		$attrs // phpcs:ignore
	);
};
$dead_links = 0;
$links_html = function ( $links ) use ( $pid, &$dead_links ) {
	if ( ! $links ) {
		return '';
	}
	$out = '<ul class="sap-sc-links">';
	foreach ( array_slice( (array) $links, 0, 5 ) as $l ) {
		// A link to a pruned / redirected page is flagged so the anchor gets fixed.
		$tg   = sap_link_target( $pid, (string) $l['url'] );
		$flag = '';
		if ( 'ok' !== $tg['state'] ) {
			++$dead_links;
			$flag = '<em class="sap-sc-dead" title="' . esc_attr( $tg['why'] . ( 'moved' === $tg['state'] ? ' → ' . rawurldecode( $tg['url'] ) : '' ) ) . '">' . ( 'moved' === $tg['state'] ? 'ریدایرکت شده' : 'صفحه حذف شده' ) . '</em>';
		}
		$out .= '<li class="' . ( $flag ? 'is-dead' : '' ) . '"><bdi dir="ltr">' . esc_html( sap_short_url( (string) $l['url'], 34 ) ) . '</bdi>' . ( '' !== (string) ( $l['anchor'] ?? '' ) ? '<span>«' . esc_html( $l['anchor'] ) . '»</span>' : '' ) . $flag . '</li>';
	}
	return $out . ( count( $links ) > 5 ? '<li class="sap-muted">+' . esc_html( sap_num( count( $links ) - 5 ) ) . '</li>' : '' ) . '</ul>';
};
// Who does the row and where its task stands: the same task as the action plan.
$tusers   = sap_team_users();
$tflow    = array( 'next' => 'اقدام بعدی', 'doing' => 'در حال انجام', 'waiting' => 'در انتظار', 'done' => 'انجام شد' );
$task_ctl = function ( $tid ) use ( $tusers, $tflow ) {
	$t  = $tid ? sap_task( $tid ) : null;
	$st = $t ? $t['status'] : '';
	ob_start();
	?>
	<td class="sap-sc is-task<?php echo $t ? '' : ' is-notask'; ?>" data-sap-tk-cell>
		<label class="sap-tk"><span class="screen-reader-text">مسئول</span><select data-sap-tk="task_assignee"><option value="0"><?php echo $t ? 'بدون مسئول' : 'انتخاب مسئول…'; ?></option><?php foreach ( $tusers as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $t ? (int) $t['assignee'] : -1, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?></select></label>
		<label class="sap-tk is-st is-<?php echo esc_attr( $st ?: 'none' ); ?>"><span class="screen-reader-text">وضعیت کار</span><select data-sap-tk="task_status"><?php if ( ! $t ) : ?><option value="" selected>بدون کار در اکشن پلن</option><?php endif; ?><?php if ( $t && ! isset( $tflow[ $st ] ) ) : ?><option value="<?php echo esc_attr( $st ); ?>" selected><?php echo esc_html( sap_task_statuses()[ $st ] ?? $st ); ?></option><?php endif; ?><?php foreach ( $tflow as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $st, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
	</td>
	<?php
	return ob_get_clean();
};
$jd       = function ( $ymd ) {
	return $ymd ? sap_jdate( substr( (string) $ymd, 0, 10 ), 'short' ) : '';
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
		<div><h2>تقویم محتوای <?php echo esc_html( sap_period_label( $period ) ); ?> <small><?php echo esc_html( sap_period_dates( $period, $pid ) ); ?></small></h2></div>
	</div>
	<div class="sap-calx-kpis">
		<a href="<?php echo esc_url( sap_project_url( $pid, 'ideas' ) . '#slots' ); ?>" class="is-new <?php echo $c['content_new'] && count( $items ) >= $c['content_new'] ? 'is-ok' : ''; ?>">
			<span>محتوای جدید</span>
			<b><?php echo esc_html( sap_num( count( $items ) ) ); ?><?php echo $c['content_new'] ? '<small> / ' . esc_html( sap_num( $c['content_new'] ) ) . '</small>' : ''; ?></b>
			<small><?php echo esc_html( sprintf( '%s منتشر شده', sap_num( $pub ) ) ); ?><?php echo $c['content_new'] && count( $items ) < $c['content_new'] ? ' · ' . esc_html( sap_num( $c['content_new'] - count( $items ) ) ) . ' مورد مشخص نشده' : ''; ?></small>
		</a>
		<a href="<?php echo esc_url( sap_project_url( $pid, 'updates' ) . '#slots' ); ?>" class="is-upd <?php echo $c['content_upd'] && count( $updates ) >= $c['content_upd'] ? 'is-ok' : ''; ?>">
			<span>آپدیت محتوا</span>
			<b><?php echo esc_html( sap_num( count( $updates ) ) ); ?><?php echo $c['content_upd'] ? '<small> / ' . esc_html( sap_num( $c['content_upd'] ) ) . '</small>' : ''; ?></b>
			<small><?php echo esc_html( sprintf( 'دسته %s · محصول %s · مقاله %s · %s انجام شد', sap_num( count( $groups['cat'] ) ), sap_num( count( $groups['product'] ) ), sap_num( count( $groups['post'] ) ), sap_num( $udone ) ) ); ?></small>
		</a>
	</div>
</section>

<section class="sap-panel sap-ledger-panel" data-sap-sheet data-project="<?php echo esc_attr( $pid ); ?>">
	<div class="sap-ledger-bar">
		<div class="sap-seg-mini" role="group" aria-label="فیلتر ردیف‌ها" data-sap-sheet-filter>
			<button type="button" class="is-active" data-k="">همه <em><?php echo esc_html( sap_num( count( $items ) + count( $updates ) ) ); ?></em></button>
			<button type="button" data-k="new">محتوای جدید <em><?php echo esc_html( sap_num( count( $items ) ) ); ?></em></button>
			<?php foreach ( array( 'cat', 'product', 'post', 'other' ) as $g ) : ?>
				<?php if ( $groups[ $g ] ) : ?><button type="button" data-k="u-<?php echo esc_attr( $g ); ?>">آپدیت <?php echo esc_html( $kinds[ $g ] ); ?> <em><?php echo esc_html( sap_num( count( $groups[ $g ] ) ) ); ?></em></button><?php endif; ?>
			<?php endforeach; ?>
		</div>
		<p class="sap-small sap-muted">روی هر خانه کلیک کنید تا ویرایش شود؛ Enter ذخیره، Esc انصراف. مسئول و وضعیت کار همان‌جا در اکشن پلن هم ثبت می‌شود.</p>
	</div>
	<?php if ( ! $items && ! $updates ) : ?>
		<div class="sap-empty"><p>این ماه هنوز ردیفی ندارد. صفحه‌های آپدیت را در <a href="<?php echo esc_url( sap_project_url( $pid, 'updates' ) . '#slots' ); ?>">آپدیت محتوا</a> و کلمات محتوای جدید را در <a href="<?php echo esc_url( sap_project_url( $pid, 'ideas' ) . '#slots' ); ?>">ایده‌های محتوا</a> مشخص کنید.</p></div>
	<?php else : ?>
	<div class="sap-ledger-wrap" data-sap-xscroll>
		<table class="sap-ledger">
			<colgroup><col class="c-type"><col class="c-kw"><col class="c-title"><col class="c-brief"><col class="c-links"><col class="c-src"><col class="c-st"><col class="c-link"><col class="c-date"><col class="c-before"><col class="c-res"><col class="c-who"><col class="c-act"></colgroup>
			<thead>
				<tr>
					<th scope="col">نوع</th>
					<th scope="col">کلمه کلیدی</th>
					<th scope="col">عنوان</th>
					<th scope="col">توضیحات و بریف</th>
					<th scope="col">لینک‌های داخلی و انکر</th>
					<th scope="col">سورس</th>
					<th scope="col">وضعیت</th>
					<th scope="col">لینک</th>
					<th scope="col">تاریخ انتشار / انجام</th>
					<th scope="col">قبل از آپدیت<small>رتبه · کلیک ۲۸ روز</small></th>
					<th scope="col">نتیجه</th>
					<th scope="col">مسئول و وضعیت کار<small>همان کار اکشن پلن</small></th>
					<th scope="col"><span class="screen-reader-text">کارها</span></th>
				</tr>
			</thead>
			<?php if ( $items ) : ?>
			<tbody data-k="new">
				<tr class="sap-ledger-group is-new"><th colspan="13" scope="rowgroup"><span>محتوای جدید <em><?php echo esc_html( sap_num( count( $items ) ) . ' مقاله · ' . sap_num( $pub ) . ' منتشر شده' ); ?></em></span></th></tr>
				<?php foreach ( $items as $n => $it ) : ?>
					<?php $res = sap_track_line( $track[ 'i' . $it['id'] ] ?? null, 'new' ); $sec = sap_idea_secondary( $it ); $is_pub = 'published' === $it['status']; ?>
					<tr class="sap-sr is-new<?php echo $is_pub ? ' is-done' : ''; ?>" data-kind="idea" data-id="<?php echo esc_attr( $it['id'] ); ?>" data-sap-idea="<?php echo esc_attr( $it['id'] ); ?>">
						<th scope="row" class="sap-sc-type"><span class="sap-tag is-new">جدید</span><small><?php echo esc_html( sap_num( $n + 1 ) ); ?><?php echo $it['planned_date'] ? ' · برنامه ' . esc_html( sap_jdate( $it['planned_date'], 'day' ) ) : ''; ?></small></th>
						<?php $cell( 'keyword', $it['keyword'], '<b>' . esc_html( $it['keyword'] ) . '</b>' . ( $it['kp'] ? ' <small class="sap-muted">KP ' . esc_html( sap_num( $it['kp'] ) ) . '</small>' : '' ) . ( $sec ? '<small class="sap-sc-sub">' . esc_html( implode( '، ', array_slice( array_column( $sec, 0 ), 0, 4 ) ) ) . '</small>' : '' ) ); ?>
						<?php $cell( 'title', $it['title'] ); ?>
						<?php $cell( 'brief', $it['brief'], esc_html( mb_strimwidth( (string) $it['brief'], 0, 150, '…' ) ), 'long' ); ?>
						<?php $cell( 'links', sap_cplan_links_text( $it['links'] ), $links_html( $it['links'] ), 'links' ); ?>
						<?php $cell( 'source_note', $it['source_note'], esc_html( mb_strimwidth( (string) $it['source_note'], 0, 60, '…' ) ) ); ?>
						<?php $cell( 'status', $is_pub ? 'published' : 'scheduled', '<span class="sap-chip ' . ( $is_pub ? 'is-good' : '' ) . '">' . ( $is_pub ? 'منتشر شد' : 'در تقویم' ) . '</span>', 'select', 'data-opts="' . esc_attr( wp_json_encode( array( 'scheduled' => 'در تقویم', 'published' => 'منتشر شد' ), JSON_UNESCAPED_UNICODE ) ) . '"' ); ?>
						<?php $cell( 'published_url', rawurldecode( $it['published_url'] ), $it['published_url'] ? '<bdi dir="ltr" class="sap-sc-url">' . esc_html( sap_short_url( $it['published_url'], 30 ) ) . '</bdi>' : '', 'url' ); ?>
						<?php $cell( 'published_at', $jd( $it['published_at'] ), $it['published_at'] ? esc_html( $jd( $it['published_at'] ) ) : ( $is_pub ? '<span class="sap-sc-warn">تاریخ ثبت نشده</span>' : '' ), 'date' ); ?>
						<td class="sap-sc is-ro"><span class="sap-muted sap-small">محتوای جدید</span></td>
						<td class="sap-sc is-ro"><?php if ( '' !== $res['text'] ) : ?><span class="sap-calx-res <?php echo esc_attr( $res['tone'] ); ?>"><?php echo esc_html( $res['text'] ); ?></span><?php elseif ( $is_pub && ! $it['published_url'] ) : ?><span class="sap-sc-warn">لینک ثبت نشده</span><?php else : ?><span class="sap-muted sap-small">بعد از انتشار</span><?php endif; ?></td>
						<?php echo $task_ctl( $it['task_id'] ); // phpcs:ignore ?>
						<td class="sap-sc-act">
							<button type="button" class="sap-icon-btn" data-sap-sr-more aria-expanded="false" title="جزئیات"><?php echo sap_icon( 'list', 14 ); // phpcs:ignore ?></button>
														<button type="button" class="sap-icon-btn" data-sap-idea-do="unschedule" title="خارج کردن از تقویم"><?php echo sap_icon( 'x', 14 ); // phpcs:ignore ?></button>
						</td>
					</tr>
					<tr class="sap-sr-more" hidden><td colspan="13">
						<div class="sap-sr-more-in">
							<?php if ( '' !== $it['my_view'] ) : ?><div><b>دیدگاه مدیر سئو</b><p><?php echo nl2br( esc_html( $it['my_view'] ) ); ?></p></div><?php endif; ?>
							<?php if ( $it['goal'] ) : ?><div><b>هدف</b><p><?php echo esc_html( $it['goal'] ); ?></p></div><?php endif; ?>
							<?php if ( $it['outline'] ) : ?><div><b>سرفصل‌ها</b><ol><?php foreach ( $it['outline'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ol></div><?php endif; ?>
							<?php if ( $sec ) : ?><div><b>کلمات فرعی</b><p><?php echo esc_html( implode( '، ', array_column( $sec, 0 ) ) ); ?></p></div><?php endif; ?>
						</div>
					</td></tr>
				<?php endforeach; ?>
			</tbody>
			<?php endif; ?>
			<?php foreach ( $groups as $g => $list ) : ?>
				<?php if ( ! $list ) { continue; } ?>
				<tbody data-k="u-<?php echo esc_attr( $g ); ?>">
					<tr class="sap-ledger-group is-upd is-<?php echo esc_attr( $g ); ?>"><th colspan="13" scope="rowgroup"><span>آپدیت · <?php echo esc_html( $glabel[ $g ] ); ?> <em><?php echo esc_html( sap_num( count( $list ) ) . ' صفحه · ' . sap_num( count( array_filter( $list, function ( $x ) { return 'done' === $x['status']; } ) ) ) . ' انجام شد' ); ?></em></span></th></tr>
					<?php foreach ( $list as $n => $u ) : ?>
						<?php
						$res  = sap_track_line( $track[ 'u' . $u['id'] ] ?? null, 'upd' );
						$b    = (array) $u['brief'];
						$sc   = (array) ( $b['site_change'] ?? array() );
						$done = 'done' === $u['status'];
						$opts = sap_update_statuses();
						$stc  = $done ? 'is-good' : ( 'in_progress' === $u['status'] ? 'is-warn' : '' );
						?>
						<tr class="sap-sr is-upd is-<?php echo esc_attr( $g ); ?><?php echo $done ? ' is-done' : ''; ?>" data-kind="update" data-id="<?php echo esc_attr( $u['id'] ); ?>">
							<th scope="row" class="sap-sc-type"><span class="sap-tag is-upd">آپدیت</span><span class="sap-ptype is-<?php echo esc_attr( $g ); ?>"><?php echo esc_html( $kinds[ $g ] ); ?></span></th>
							<?php $cell( 'keyword', $u['keyword'], '<b>' . esc_html( $u['keyword'] ) . '</b>' . ( $u['secondary'] ? '<small class="sap-sc-sub">' . esc_html( implode( '، ', array_slice( (array) $u['secondary'], 0, 4 ) ) ) . '</small>' : '' ) ); ?>
							<?php $cell( 'title', (string) ( $b['title'] ?? '' ), esc_html( $u['title'] ) . ( ! empty( $b['title'] ) ? '<small class="sap-sc-sub">پیشنهادی: ' . esc_html( $b['title'] ) . '</small>' : '' ) ); ?>
							<?php $cell( 'reason', $u['reason'], esc_html( mb_strimwidth( sap_update_goal( $u ) ?: (string) $u['reason'], 0, 150, '…' ) ), 'long' ); ?>
							<?php $cell( 'links', sap_cplan_links_text( $u['links'] ), $links_html( $u['links'] ), 'links' ); ?>
							<?php $cell( 'source_note', $u['source_note'], esc_html( mb_strimwidth( (string) $u['source_note'], 0, 60, '…' ) ) ); ?>
							<?php
							$st_html = '<span class="sap-chip ' . $stc . '">' . esc_html( $opts[ $u['status'] ] ?? $u['status'] ) . '</span>';
							if ( $sc && ! $done && empty( $sc['kept'] ) ) {
								$st_html .= '<small class="sap-sc-hint">تغییر روی سایت ' . esc_html( sap_jdate( substr( $sc['at'], 0, 10 ), 'day' ) ) . ': ' . esc_html( mb_strimwidth( $sc['title'], 0, 50, '…' ) ) . ' — همین آپدیت بود؟</small>';
							} elseif ( $sc && $done && ! empty( $sc['auto'] ) ) {
								$st_html .= '<small class="sap-sc-hint is-auto">خودکار از لاگ تغییرات: ' . esc_html( mb_strimwidth( $sc['title'], 0, 50, '…' ) ) . '</small>';
							}
							$cell( 'status', $u['status'], $st_html, 'select', 'data-opts="' . esc_attr( wp_json_encode( array_diff_key( $opts, array( 'cancelled' => 1 ) ), JSON_UNESCAPED_UNICODE ) ) . '"' );
							?>
							<td class="sap-sc is-ro"><a class="sap-sc-url" href="<?php echo esc_url( $u['url'] ); ?>" target="_blank" rel="noopener"><bdi dir="ltr"><?php echo esc_html( sap_short_url( $u['url'], 30 ) ); ?></bdi></a></td>
							<?php $cell( 'done_at', $jd( $u['done_at'] ), $u['done_at'] ? esc_html( $jd( $u['done_at'] ) ) : '', 'date' ); ?>
							<td class="sap-sc is-before">
								<span class="sap-bf" data-f="rank_before" data-type="num" data-v="<?php echo esc_attr( null === $u['rank_before'] ? '' : $u['rank_before'] ); ?>" tabindex="0" title="میانگین جایگاه صفحه در ۲۸ روز قبل از آپدیت"><span class="sap-bf-l">رتبه</span><b><?php echo null === $u['rank_before'] ? '—' : esc_html( sap_num( $u['rank_before'], 1 ) ); ?></b></span>
								<span class="sap-bf" data-f="clicks_before" data-type="num" data-v="<?php echo esc_attr( null === $u['clicks_before'] ? '' : $u['clicks_before'] ); ?>" tabindex="0" title="کلیک ۲۸ روز قبل از آپدیت"><span class="sap-bf-l">کلیک</span><b><?php echo null === $u['clicks_before'] ? '—' : esc_html( sap_num( $u['clicks_before'] ) ); ?></b></span>
								<?php if ( null !== $u['kw_pos_before'] ) : ?><small class="sap-muted">کلمه اصلی <?php echo esc_html( sap_num( $u['kw_pos_before'], 1 ) ); ?></small><?php endif; ?>
								<?php if ( null !== $u['impr_before'] ) : ?><small class="sap-muted"><?php echo esc_html( sap_num( $u['impr_before'] ) ); ?> نمایش</small><?php endif; ?>
							</td>
							<td class="sap-sc is-ro"><?php if ( '' !== $res['text'] ) : ?><span class="sap-calx-res <?php echo esc_attr( $res['tone'] ); ?>"><?php echo esc_html( $res['text'] ); ?></span><?php else : ?><span class="sap-muted sap-small"><?php echo $done ? 'در حال سنجش' : 'بعد از انجام'; ?></span><?php endif; ?></td>
							<?php echo $task_ctl( $u['task_id'] ); // phpcs:ignore ?>
							<td class="sap-sc-act">
								<button type="button" class="sap-icon-btn" data-sap-sr-more aria-expanded="false" title="جزئیات و بریف"><?php echo sap_icon( 'list', 14 ); // phpcs:ignore ?></button>
																<button type="button" class="sap-icon-btn" data-sap-update-do="delete" data-id="<?php echo esc_attr( $u['id'] ); ?>" title="حذف از تقویم"><?php echo sap_icon( 'trash', 14 ); // phpcs:ignore ?></button>
							</td>
						</tr>
						<tr class="sap-sr-more" hidden><td colspan="13">
							<div class="sap-sr-more-in">
								<?php if ( '' !== $u['my_view'] ) : ?><div><b>دیدگاه مدیر سئو</b><p><?php echo nl2br( esc_html( $u['my_view'] ) ); ?></p></div><?php endif; ?>
								<?php if ( '' !== trim( $u['reason'] ) ) : ?><div class="is-wide"><b>برنامه آپدیت</b><p><?php echo nl2br( esc_html( $u['reason'] ) ); ?></p></div><?php endif; ?>
								<?php if ( ! empty( $b['summary'] ) || ! empty( $b['outline'] ) ) : ?>
									<div class="is-wide"><b>بریف حرفه‌ای<?php echo ! empty( $b['words_target'] ) ? ' · حدود ' . esc_html( sap_num( $b['words_target'] ) ) . ' کلمه' : ''; ?></b>
										<?php foreach ( (array) ( $b['warnings'] ?? array() ) as $w ) : ?><p class="sap-sc-warn">⚠️ <?php echo esc_html( $w ); ?></p><?php endforeach; ?>
										<?php if ( ! empty( $b['summary'] ) ) : ?><p><?php echo esc_html( $b['summary'] ); ?></p><?php endif; ?>
										<?php if ( ! empty( $b['meta'] ) ) : ?><p class="sap-small"><b>متا:</b> <?php echo esc_html( $b['meta'] ); ?></p><?php endif; ?>
										<?php if ( ! empty( $b['outline'] ) ) : ?><ol class="sap-small"><?php foreach ( (array) $b['outline'] as $o ) : ?><li><b><?php echo esc_html( is_array( $o ) ? ( $o['heading'] ?? '' ) : $o ); ?></b><?php echo is_array( $o ) && ! empty( $o['notes'] ) ? ' — ' . esc_html( $o['notes'] ) : ''; ?></li><?php endforeach; ?></ol><?php endif; ?>
										<?php if ( ! empty( $b['faq'] ) ) : ?><p class="sap-small"><b>FAQ:</b> <?php echo esc_html( implode( ' | ', (array) $b['faq'] ) ); ?></p><?php endif; ?>
									</div>
								<?php endif; ?>
								<div class="sap-sr-more-acts">
									<button type="button" class="sap-btn is-small is-soft" data-sap-update-do="brief" data-id="<?php echo esc_attr( $u['id'] ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo ! empty( $b['summary'] ) ? 'بریف دوباره' : 'ساخت بریف حرفه‌ای'; ?></button>
									<a class="sap-btn is-small is-ghost" href="<?php echo esc_url( sap_project_url( $pid, 'content', array( 'url' => $u['url'] ) ) ); ?>">بازنویسی با هوش مصنوعی</a>
								</div>
							</div>
						</td></tr>
					<?php endforeach; ?>
				</tbody>
			<?php endforeach; ?>
		</table>
	</div>
	<?php endif; ?>
	<?php if ( $dead_links ) : ?>
		<p class="sap-alert is-warn sap-small sap-ledger-note"><?php echo esc_html( sprintf( '%s لینک داخلی در این ماه به صفحه‌ای می‌رود که هرس شده (410 / 301 / noindex) یا ریدایرکت می‌شود؛ با برچسب قرمز مشخص است. روی خانه لینک‌ها کلیک کنید و مقصد را با صفحه زنده عوض کنید (فهرست جستجو فقط صفحه‌های زنده را نشان می‌دهد).', sap_num( $dead_links ) ) ); ?></p>
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
