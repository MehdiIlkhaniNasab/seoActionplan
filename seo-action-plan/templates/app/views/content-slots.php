<?php
/**
 * The month's content commitment as a checklist: one line per committed update
 * (or new article). Filled lines show what was chosen and where it stands; each
 * empty line opens the form that fills it — the page (or keyword), its keyword(s)
 * and «my view», which the AI can rewrite into a clear plan before it goes to the
 * content calendar and the action plan.
 *
 * @package SeoActionPlan
 * @var array  $project
 * @var string $kind upd|new
 */

defined( 'ABSPATH' ) || exit;

$pid    = $project['id'];
sap_cplan_repair( $pid );
$period = sap_period_current( $pid );
$is_upd = 'upd' === $kind;
$slots  = $is_upd ? sap_cplan_upd_slots( $project, $period ) : sap_cplan_new_slots( $project, $period );
$months = sap_period_options( 0, 2 );
$ai_ok  = sap_ai_connected();
$ustat  = sap_update_statuses();
$kinds  = sap_update_kinds();
$filled = count( $slots['items'] );
$total  = max( $slots['target'], $filled );
$done   = count( array_filter( $slots['items'], function ( $x ) use ( $is_upd ) {
	return $is_upd ? 'done' === $x['status'] : 'published' === $x['status'];
} ) );
$state  = function ( $x ) use ( $is_upd, $ustat ) {
	if ( $is_upd ) {
		return array( $ustat[ $x['status'] ] ?? $x['status'], 'done' === $x['status'] ? 'is-done' : ( 'in_progress' === $x['status'] ? 'is-doing' : '' ) );
	}
	return 'published' === $x['status'] ? array( 'منتشر شد', 'is-done' ) : array( 'در تقویم', '' );
};
?>
<section class="sap-panel sap-slots" id="slots" data-sap-slots="<?php echo esc_attr( $pid ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>">
	<header class="sap-slots-head">
		<div>
			<h2><?php echo esc_html( $is_upd ? 'آپدیت‌های تعهد این ماه' : 'محتوای جدید تعهد این ماه' ); ?></h2>
			<p class="sap-muted sap-small"><?php echo esc_html( sap_period_label( $period ) . ' · ' . sap_period_dates( $period, $pid ) ); ?></p>
		</div>
		<div class="sap-slots-count" title="<?php echo esc_attr( $is_upd ? 'مشخص‌شده از تعهد ماهانه' : 'در تقویم از تعهد ماهانه' ); ?>">
			<b><?php echo esc_html( sap_num( $filled ) ); ?></b><span>از <?php echo esc_html( sap_num( $slots['target'] ) ); ?> مشخص شده</span>
			<?php if ( $filled ) : ?><small><?php echo esc_html( sprintf( '%s %s', sap_num( $done ), $is_upd ? 'انجام شد' : 'منتشر شد' ) ); ?></small><?php endif; ?>
		</div>
	</header>

	<?php if ( ! $slots['target'] ) : ?>
		<p class="sap-muted sap-small">تعهد ماهانه‌ای برای <?php echo esc_html( $is_upd ? 'آپدیت محتوا' : 'محتوای جدید' ); ?> تعریف نشده. <a href="<?php echo esc_url( sap_project_url( $pid ) . '#cycle' ); ?>">تعهدات ماهانه را در نمای کلی پروژه مشخص کنید</a>؛ تا آن موقع هم می‌توانید از همین فرم مورد اضافه کنید.</p>
	<?php endif; ?>

	<ol class="sap-slot-list">
		<?php foreach ( $slots['items'] as $i => $x ) : ?>
			<?php $st = $state( $x ); $t = $x['task_id'] ? sap_task( $x['task_id'] ) : null; ?>
			<li class="sap-slot is-filled <?php echo esc_attr( $st[1] ); ?>">
				<span class="sap-slot-n" aria-hidden="true"><?php echo 'is-done' === $st[1] ? sap_icon( 'check', 14 ) : esc_html( sap_fa( $i + 1 ) ); // phpcs:ignore ?></span>
				<div class="sap-slot-main">
					<b><?php echo esc_html( $is_upd ? $x['title'] : ( $x['title'] ?: $x['keyword'] ) ); ?></b>
					<small>
						<?php if ( $is_upd ) : ?><span class="sap-ptype is-<?php echo esc_attr( sap_update_kind( $x ) ); ?>"><?php echo esc_html( $kinds[ sap_update_kind( $x ) ] ); ?></span><?php endif; ?>
						<?php echo $x['keyword'] ? '🔑 ' . esc_html( $x['keyword'] ) : ''; ?>
						<?php if ( $is_upd && null !== $x['rank_before'] ) : ?> · رتبه قبل <?php echo esc_html( sap_num( $x['rank_before'], 1 ) ); ?><?php endif; ?>
						<?php if ( $is_upd && null !== $x['clicks_before'] ) : ?> · <?php echo esc_html( sap_num( $x['clicks_before'] ) ); ?> کلیک ۲۸ روز<?php endif; ?>
					</small>
				</div>
				<span class="sap-slot-st"><span class="sap-chip <?php echo 'is-done' === $st[1] ? 'is-good' : ( 'is-doing' === $st[1] ? 'is-warn' : '' ); ?>"><?php echo esc_html( $st[0] ); ?></span><small><?php echo $t ? esc_html( sap_user_name( $t['assignee'] ) ?: 'بدون مسئول' ) : 'بدون کار'; ?></small></span>
			</li>
		<?php endforeach; ?>

		<?php for ( $e = 0; $e < max( 1, $slots['empty'] ); $e++ ) : ?>
			<?php $n = $filled + $e + 1; $extra = $n > $slots['target']; ?>
			<li class="sap-slot is-empty<?php echo $extra ? ' is-extra' : ''; ?>">
				<details class="sap-slot-open">
					<summary>
						<span class="sap-slot-n" aria-hidden="true"><?php echo $extra ? '+' : esc_html( sap_fa( $n ) ); ?></span>
						<span class="sap-slot-main"><b><?php echo esc_html( $extra ? ( $is_upd ? 'افزودن آپدیت دیگر' : 'افزودن محتوای دیگر' ) : ( $is_upd ? 'صفحه این آپدیت مشخص نشده' : 'کلمه این محتوا مشخص نشده' ) ); ?></b><small><?php echo esc_html( $is_upd ? 'صفحه، کلمه کلیدی و دیدگاهتان را بنویسید' : 'کلمه اصلی، کلمات فرعی و دیدگاهتان را بنویسید' ); ?></small></span>
						<span class="sap-btn is-small is-soft"><?php echo esc_html( $extra ? 'افزودن' : 'مشخص کردن' ); ?></span>
					</summary>
					<form class="sap-slot-form" data-sap-slot-form novalidate>
						<?php if ( $is_upd ) : ?>
							<div class="sap-pgpick" data-sap-pgpick>
								<div class="sap-pgpick-type" role="group" aria-label="نوع صفحه">
									<button type="button" class="is-active" data-t="">همه</button><button type="button" data-t="cat">دسته‌بندی</button><button type="button" data-t="product">محصول</button><button type="button" data-t="post">مقاله</button>
								</div>
								<div class="sap-pgpick-box">
									<label class="sap-field is-wide"><span>صفحه</span>
										<input type="search" data-sap-pick-q placeholder="نام صفحه، کلمه یا بخشی از آدرس را بنویسید…" autocomplete="off" aria-autocomplete="list">
									</label>
									<ul class="sap-pgpick-list" data-sap-pgpick-list role="listbox" hidden></ul>
								</div>
								<input type="hidden" name="url" data-sap-pick-url>
								<div class="sap-pgpick-chosen" data-sap-pgpick-chosen hidden></div>
							</div>
						<?php endif; ?>
						<div class="sap-grid-2">
							<label class="sap-field"><span>کلمه کلیدی اصلی</span><input type="text" name="keyword" <?php echo $is_upd ? '' : 'required'; ?>></label>
							<label class="sap-field"><span>کلمات فرعی <small class="sap-muted">(با ، جدا کنید)</small></span><input type="text" name="secondary"></label>
						</div>
						<label class="sap-field is-wide"><span><?php echo esc_html( $is_upd ? 'دیدگاه من برای آپدیت این صفحه' : 'دیدگاه و نظر من برای این محتوا' ); ?></span>
							<textarea name="view" rows="3" placeholder="<?php echo esc_attr( $is_upd ? 'مثلاً: تایتل کلمه اصلی را ندارد؛ بخش راهنمای انتخاب و مقایسه انواع اضافه شود؛ ۳ سوال متداول؛ لینک به مقاله نحوه استفاده.' : 'مثلاً: مخاطب تازه‌کار است؛ مقایسه دو روش با جدول؛ حتماً به دسته واکس کفش لینک بدهد.' ); ?>"></textarea>
						</label>
						<div class="sap-slot-ai">
							<button type="button" class="sap-btn is-small is-soft" data-sap-slot-ai <?php disabled( ! $ai_ok ); ?>><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>بازنویسی دیدگاه با هوش مصنوعی</button>
							<small class="sap-muted"><?php echo $ai_ok ? 'دیدگاه شما مرتب و اجرایی می‌شود؛ قبل از ثبت ویرایشش کنید.' : 'هوش مصنوعی وصل نیست؛ برنامه را خودتان بنویسید.'; ?></small>
						</div>
						<div class="sap-slot-plan" data-sap-slot-plan>
							<?php if ( $is_upd ) : ?>
								<label class="sap-field is-wide"><span>هدف آپدیت</span><input type="text" name="goal" placeholder="مثلاً: جایگاه «واکس بی‌رنگ» از ۱۴ به زیر ۱۰"></label>
								<label class="sap-field is-wide"><span>برنامه برای نویسنده <small class="sap-muted">(خالی بماند = همان دیدگاه شما)</small></span><textarea name="plan" rows="5"></textarea></label>
								<input type="hidden" name="title"><input type="hidden" name="meta">
							<?php else : ?>
								<div class="sap-grid-2">
									<label class="sap-field"><span>عنوان</span><input type="text" name="title"></label>
									<label class="sap-field"><span>هدف</span><input type="text" name="goal"></label>
								</div>
								<label class="sap-field is-wide"><span>بریف برای نویسنده <small class="sap-muted">(خالی بماند = همان دیدگاه شما)</small></span><textarea name="brief" rows="3"></textarea></label>
								<label class="sap-field is-wide"><span>سرفصل‌ها <small class="sap-muted">(هر خط یک تیتر)</small></span><textarea name="outline" rows="4"></textarea></label>
							<?php endif; ?>
						</div>
						<footer class="sap-slot-foot">
							<label class="sap-inline-label">ماه <select name="period"><?php foreach ( $months as $mk => $ml ) : ?><option value="<?php echo esc_attr( $mk ); ?>" <?php selected( $mk, $period ); ?>><?php echo esc_html( $ml ); ?></option><?php endforeach; ?></select></label>
							<button type="submit" class="sap-btn is-primary is-small"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>ثبت در تقویم و اکشن پلن</button>
						</footer>
					</form>
				</details>
			</li>
		<?php endfor; ?>
	</ol>
</section>
