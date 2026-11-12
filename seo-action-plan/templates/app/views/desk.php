<?php
/**
 * Manager's desk: reports waiting for approval, questions from the team,
 * tasks proposed by project managers, and tasks 7+ days past their deadline.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

$d     = sap_desk_items();
$pc    = array();
$proj  = function ( $id ) use ( &$pc ) {
	if ( ! isset( $pc[ $id ] ) ) {
		$pc[ $id ] = sap_project( $id );
	}
	return $pc[ $id ];
};
$ptag  = function ( $id ) use ( $proj ) {
	$p = $proj( $id );
	return '<span class="sap-ptag" style="--c:' . esc_attr( sap_member_color( $id + 3 ) ) . '">' . esc_html( $p['name'] ?? '' ) . '</span>';
};
$total = count( $d['review'] ) + count( $d['questions'] ) + count( $d['requests'] ) + count( $d['proposed'] ) + count( $d['client'] ) + count( $d['late'] );
$answered = sap_threads_answered();
$long     = sap_long_tasks();
$att   = function ( $list ) {
	if ( ! $list ) {
		return '';
	}
	$o = '<div class="sap-att-list">';
	foreach ( $list as $a ) {
		if ( 'image' === ( $a['type'] ?? '' ) ) {
			$o .= '<a class="sap-att is-img" href="' . esc_url( $a['url'] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $a['url'] ) . '" alt="" loading="lazy"></a>';
		} else {
			$o .= '<a class="sap-att" href="' . esc_url( $a['url'] ) . '" target="_blank" rel="noopener">' . sap_icon( 'file' === ( $a['type'] ?? '' ) ? 'log' : 'link', 13 ) . '<bdi dir="ltr">' . esc_html( '' !== ( $a['name'] ?? '' ) ? $a['name'] : sap_short_url( $a['url'], 40 ) ) . '</bdi></a>';
		}
	}
	return $o . '</div>';
};
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان</p>
		<h1>میز کار</h1>
		<p class="sap-muted">هر چیزی که منتظر تصمیم شماست: گزارش‌هایی که باید تأیید شوند، سوال‌ها و درخواست‌های تیم، کارهای پیشنهادی مدیران پروژه و کارهایی که بیش از ۷ روز از سررسیدشان گذشته.</p>
	</div>
	<a class="sap-btn is-soft" href="<?php echo esc_url( sap_url( 'calendar' ) ); ?>"><?php echo sap_icon( 'calendar', 16 ); // phpcs:ignore ?>تقویم کارهای ماه</a>
</header>

<div class="sap-desk-stats">
	<a href="#client" class="<?php echo $d['client'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( count( $d['client'] ) ) ); ?></b><span>درخواست کارفرما</span></a>
	<a href="#review" class="<?php echo $d['review'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( count( $d['review'] ) ) ); ?></b><span>در انتظار تأیید</span></a>
	<a href="#questions" class="<?php echo $d['questions'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( count( $d['questions'] ) ) ); ?></b><span>سوال تیم</span></a>
	<a href="#requests" class="<?php echo $d['requests'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( count( $d['requests'] ) ) ); ?></b><span>درخواست</span></a>
	<a href="#proposed" class="<?php echo $d['proposed'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( count( $d['proposed'] ) ) ); ?></b><span>کار پیشنهادی</span></a>
	<a href="#long" class="<?php echo $long ? 'is-warn' : ''; ?>"><b><?php echo esc_html( sap_num( count( $long ) ) ); ?></b><span>طول‌کشیده (<?php echo esc_html( sap_num( sap_long_days() ) ); ?>+ روز)</span></a>
	<a href="#late" class="<?php echo $d['late'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( count( $d['late'] ) ) ); ?></b><span>عقب‌افتاده (۷+ روز)</span></a>
</div>

<?php if ( ! $total && ! $long ) : ?>
	<div class="sap-empty sap-empty-good"><?php echo sap_icon( 'check', 32 ); // phpcs:ignore ?><p>میز کار خالی است؛ همه‌چیز مرتب است. 🌿</p></div>
<?php endif; ?>

<?php if ( $d['review'] ) : ?>
	<section class="sap-desk-sec" id="review">
		<h2><?php echo sap_icon( 'check', 18 ); // phpcs:ignore ?>گزارش‌های در انتظار تأیید <small><?php echo esc_html( sap_num( count( $d['review'] ) ) ); ?></small></h2>
		<div class="sap-desk-list">
			<?php foreach ( $d['review'] as $t ) : ?>
				<article class="sap-desk-card" data-sap-desk-card>
					<header>
						<?php echo sap_person( $t['assignee'], 26 ); // phpcs:ignore ?>
						<?php echo $ptag( $t['project_id'] ); // phpcs:ignore ?>
						<?php echo sap_context_chip( $t['context'] ); // phpcs:ignore ?>
						<small class="sap-muted"><?php echo esc_html( $t['submitted_at'] ? 'ثبت ' . sap_jdate( substr( $t['submitted_at'], 0, 10 ), 'short' ) . ' ' . sap_fa( substr( $t['submitted_at'], 11, 5 ) ) : '' ); ?></small>
					</header>
					<h3><?php echo esc_html( $t['title'] ); ?></h3>
					<?php if ( $t['created_by'] && 'pm' === sap_user_role( $t['created_by'] ) ) : ?><p class="sap-small sap-muted">📋 ثبت‌شده توسط مدیر پروژه <?php echo esc_html( sap_user_name( $t['created_by'] ) ); ?> — او هم می‌تواند این گزارش را تأیید کند.</p><?php endif; ?>
					<div class="sap-desk-report">
						<span>گزارش کار</span>
						<div><?php echo sap_rich_text( $t['result'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div>
						<?php if ( $t['spent'] ) : ?><small class="sap-muted"><?php echo esc_html( sap_num( $t['spent'], 1 ) ); ?> ساعت کار</small><?php endif; ?>
					</div>
					<?php echo $att( $t['attachments'] ); // phpcs:ignore ?>
					<?php if ( $t['progress'] ) : ?><details class="sap-desk-brief"><summary>🗒️ <?php echo esc_html( sap_num( count( $t['progress'] ) ) ); ?> گزارش روزانه در طول کار</summary><?php sap_view( 'progress-log', array( 't' => $t, 'form' => false ) ); ?></details><?php endif; ?>
					<?php if ( '' !== $t['url'] ) : ?><p class="sap-small">صفحه: <?php echo sap_url_tag( $t['url'], 60 ); // phpcs:ignore ?></p><?php endif; ?>
					<details class="sap-desk-brief"><summary>شرح کار</summary><div class="sap-notes"><?php echo sap_rich_text( $t['notes'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div></details>
					<form class="sap-desk-fb" data-sap-desk-feedback hidden>
						<textarea name="note" rows="3" placeholder="چه چیزی باید اصلاح یا اضافه شود؟ (برای مسئول ایمیل می‌شود)"></textarea>
					</form>
					<footer>
						<button type="button" class="sap-btn is-primary is-small" data-sap-review="approve" data-id="<?php echo esc_attr( $t['id'] ); ?>"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>تأیید و ثبت در گزارش</button>
						<button type="button" class="sap-btn is-ghost is-small" data-sap-review="feedback" data-id="<?php echo esc_attr( $t['id'] ); ?>">🔁 بازخورد و برگشت</button>
						<a class="sap-btn is-ghost is-small" href="<?php echo esc_url( sap_project_url( $t['project_id'], 'plan', array( 'task' => $t['id'] ) ) ); ?>">باز کردن کار</a>
					</footer>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $long ) : ?>
	<section class="sap-desk-sec" id="long">
		<h2>⏳ کارهای طول‌کشیده <small><?php echo esc_html( sap_num( count( $long ) ) ); ?> کار بیش از <?php echo esc_html( sap_num( sap_long_days() ) ); ?> روز از شروعش باز مانده · اقدامات انجام‌شده و علت نرسیدن به مرحله نهایی</small></h2>
		<div class="sap-long-list">
			<?php foreach ( $long as $t ) : ?>
				<?php
				$ps   = sap_progress_summary( $t );
				$kind = $ps['blocked'] ? array( 'is-bad', '⚠️ گیر کرده' ) : ( $ps['count'] ? ( $ps['quiet'] >= 2 ? array( 'is-warn', 'بدون گزارش در ' . sap_num( $ps['quiet'] ) . ' روز اخیر' ) : array( 'is-info', 'در حال پیگیری' ) ) : array( 'is-warn', 'بدون هیچ گزارشی' ) );
				?>
				<details class="sap-long<?php echo $ps['blocked'] ? ' is-blocked' : ''; ?>">
					<summary>
						<span class="sap-long-days"><b><?php echo esc_html( sap_num( $t['open_days'] ) ); ?></b>روز</span>
						<div class="sap-long-main">
							<b><?php echo esc_html( $t['title'] ); ?></b>
							<span class="sap-long-meta"><?php echo $t['assignee'] ? sap_person( $t['assignee'], 18 ) : ''; // phpcs:ignore ?><?php echo $ptag( $t['project_id'] ); // phpcs:ignore ?><span class="sap-chip <?php echo esc_attr( $kind[0] ); ?>"><?php echo esc_html( $kind[1] ); ?></span><span class="sap-chip is-soft">🗒️ <?php echo esc_html( sap_num( $ps['count'] ) ); ?> گزارش</span><?php if ( $ps['stuck_n'] ) : ?><span class="sap-chip is-soft"><?php echo esc_html( sap_num( $ps['stuck_n'] ) ); ?> بار گیر کرده</span><?php endif; ?></span>
							<?php if ( $ps['blocked'] ) : ?><span class="sap-long-why">علت: <?php echo esc_html( $t['waiting_for'] ); ?></span><?php elseif ( $ps['last'] ) : ?><span class="sap-long-last">آخرین گزارش (<?php echo esc_html( sap_jdate( substr( $ps['last']['at'], 0, 10 ), 'day' ) ); ?>): <?php echo esc_html( mb_strimwidth( $ps['last']['text'], 0, 140, '…' ) ); ?></span><?php endif; ?>
						</div>
						<span class="sap-long-acts"><?php sap_view( 'nudge-btn', array( 't' => $t, 'compact' => true ) ); ?><a class="sap-btn is-ghost is-small" href="<?php echo esc_url( sap_project_url( $t['project_id'], 'plan', array( 'task' => $t['id'] ) ) ); ?>#task-<?php echo esc_attr( $t['id'] ); ?>">باز کردن</a></span>
					</summary>
					<?php sap_view( 'progress-log', array( 't' => $t, 'form' => false ) ); ?>
				</details>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $d['questions'] ) : ?>
	<section class="sap-desk-sec" id="questions">
		<h2>❓ سوال‌های تیم <small><?php echo esc_html( sap_num( count( $d['questions'] ) ) ); ?></small></h2>
		<div class="sap-desk-list">
			<?php foreach ( $d['questions'] as $q ) : ?>
				<?php $t = sap_task( (int) $q['task_id'] ); ?>
				<article class="sap-desk-card is-q" id="q-<?php echo esc_attr( $q['id'] ); ?>" data-sap-desk-card>
					<header>
						<?php echo sap_person( $q['user_id'], 26 ); // phpcs:ignore ?>
						<?php echo $t ? $ptag( $t['project_id'] ) : ''; // phpcs:ignore ?>
						<?php if ( count( sap_thread( $q ) ) > 1 ) : ?><span class="sap-chip is-info">ادامه گفتگو</span><?php endif; ?>
					</header>
					<?php if ( $t ) : ?><p class="sap-small sap-muted">درباره: <a href="<?php echo esc_url( sap_project_url( $t['project_id'], 'plan', array( 'task' => $t['id'] ) ) ); ?>"><?php echo esc_html( $t['title'] ); ?></a></p><?php endif; ?>
					<?php sap_view( 'thread', array( 'q' => $q, 'side' => 'manager' ) ); ?>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $d['requests'] ) : ?>
	<section class="sap-desk-sec" id="requests">
		<h2>📨 درخواست‌های تیم <small><?php echo esc_html( sap_num( count( $d['requests'] ) ) ); ?> · فقط مدیران می‌بینند</small></h2>
		<div class="sap-desk-list">
			<?php foreach ( $d['requests'] as $q ) : ?>
				<article class="sap-desk-card is-req" id="r-<?php echo esc_attr( $q['id'] ); ?>" data-sap-desk-card>
					<header>
						<?php echo sap_person( $q['user_id'], 26 ); // phpcs:ignore ?>
						<?php if ( count( sap_thread( $q ) ) > 1 ) : ?><span class="sap-chip is-info">ادامه گفتگو</span><?php endif; ?>
					</header>
					<h3><?php echo esc_html( $q['title'] ); ?></h3>
					<?php sap_view( 'thread', array( 'q' => $q, 'side' => 'manager' ) ); ?>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $d['client'] ) : ?>
	<section class="sap-desk-sec" id="client">
		<h2>🧑‍💼 درخواست‌های کارفرما (پایلوت) <small><?php echo esc_html( sap_num( count( $d['client'] ) ) ); ?> منتظر تأیید شما</small></h2>
		<p class="sap-muted sap-small">هر کاری که کارفرما در پایلوت ثبت می‌کند اول اینجا می‌آید و تا شما تأیید نکنید به کسی سپرده نمی‌شود. مسئول، موعد، ساعت و فوریت را تنظیم کنید و «تأیید و واگذاری» بزنید؛ کارفرما پیامک «کار شروع شد» می‌گیرد. اگر سؤالی دارید، در گفتگو بپرسید.</p>
		<div class="sap-desk-list">
			<?php foreach ( $d['client'] as $t ) : ?>
				<?php
				$sugg   = (int) $t['assignee'];
				$urgent = false !== strpos( (string) $t['title'], '🚨' );
				$clean  = preg_replace( '/^(🚨\s*)?(درخواست کارفرما:\s*)?/u', '', (string) $t['title'] );
				?>
				<article class="sap-desk-card is-client" data-sap-desk-card data-sap-creq="<?php echo esc_attr( $t['id'] ); ?>">
					<header>
						<span class="sap-chip <?php echo $urgent ? 'is-bad' : 'is-warn'; ?>"><?php echo $urgent ? '🚨 مشکل فوری' : '🧑‍💼 درخواست کارفرما'; ?></span>
						<?php if ( '' !== $t['client_note'] ) : ?><b class="sap-small"><?php echo esc_html( $t['client_note'] ); ?></b><?php endif; ?>
						<?php echo $ptag( $t['project_id'] ); // phpcs:ignore ?>
						<span class="sap-small sap-muted"><?php echo esc_html( sap_jdate( substr( $t['created_at'], 0, 10 ), 'day' ) . ' · ' . substr( (string) $t['created_at'], 11, 5 ) ); ?></span>
					</header>
					<label class="sap-creq-title">عنوان کار<input type="text" data-sap-creq-field="title" value="<?php echo esc_attr( $clean ); ?>"></label>
					<?php if ( '' !== trim( $t['notes'] ) ) : ?><details class="sap-desk-brief" open><summary>توضیحات کارفرما</summary><div class="sap-notes"><?php echo sap_rich_text( $t['notes'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div></details><?php endif; ?>
					<?php if ( $sugg || $t['due_date'] ) : ?>
						<p class="sap-creq-sugg"><?php echo esc_html( 'پیشنهاد کارفرما: ' . ( $sugg ? sap_user_name( $sugg ) . ' انجام دهد' : 'مسئول را شما تعیین کنید' ) . ( $t['due_date'] ? ' · تخمین پایلوت برای تحویل ' . sap_jdate( $t['due_date'] ) : '' ) . ( (float) $t['effort'] ? ' · حدود ' . sap_num( (float) $t['effort'] ) . ' ساعت' : '' ) ); ?></p>
					<?php endif; ?>
					<div class="sap-creq-grid">
						<label>مسئول<select data-sap-creq-field="assignee"><option value="0">انتخاب کنید</option><?php foreach ( sap_client_assignable( $t['project_id'], $sugg ) as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( (int) $u->ID, $sugg ); ?>><?php echo esc_html( $u->display_name . ( (int) $u->ID === $sugg ? ' (پیشنهاد کارفرما)' : '' ) ); ?></option><?php endforeach; ?></select></label>
						<label>موعد تحویل<input type="date" data-sap-creq-field="due_date" value="<?php echo esc_attr( (string) $t['due_date'] ); ?>"></label>
						<label>ساعت کار<input type="number" min="0.25" step="0.25" data-sap-creq-field="effort" value="<?php echo esc_attr( (float) $t['effort'] ?: 1 ); ?>" dir="ltr"></label>
						<label>فوریت<select data-sap-creq-field="urgent"><option value="0">عادی</option><option value="1" <?php selected( $urgent ); ?>>فوری</option></select></label>
						<label>نوع کار<select data-sap-creq-field="context"><?php foreach ( sap_contexts_all() as $k => $c ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $t['context'] ); ?>><?php echo esc_html( is_array( $c ) ? ( $c['label'] ?? $k ) : $c ); ?></option><?php endforeach; ?></select></label>
					</div>
					<label class="sap-creq-note">پیام به کارفرما <small class="sap-muted">(اختیاری برای تأیید؛ برای رد لازم است)</small><textarea rows="2" data-sap-creq-field="note" placeholder="مثلاً: سلام، کار به سارا سپرده شد و تا پنجشنبه تحویل می‌شود."></textarea></label>
					<?php sap_view( 'client-thread', array( 't' => $t ) ); ?>
					<footer>
						<button type="button" class="sap-btn is-primary is-small" data-sap-creq="approve"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>تأیید و واگذاری</button>
						<?php if ( '' !== $t['client_link'] ) : ?><a class="sap-btn is-ghost is-small" href="<?php echo esc_url( $t['client_link'] ); ?>" target="_blank" rel="noopener">نمای کارفرما</a><?php endif; ?>
						<button type="button" class="sap-btn is-ghost is-danger is-small" data-sap-creq="decline">رد درخواست</button>
					</footer>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $d['proposed'] ) : ?>
	<section class="sap-desk-sec" id="proposed">
		<h2>📝 کارهای پیشنهادی مدیران پروژه <small><?php echo esc_html( sap_num( count( $d['proposed'] ) ) ); ?></small></h2>
		<div class="sap-desk-list">
			<?php foreach ( $d['proposed'] as $t ) : ?>
				<article class="sap-desk-card" data-sap-desk-card>
					<header>
						<span class="sap-small sap-muted">پیشنهاد</span><?php echo sap_person( $t['created_by'], 22 ); // phpcs:ignore ?>
						<?php echo $ptag( $t['project_id'] ); // phpcs:ignore ?>
						<?php echo sap_context_chip( $t['context'] ); // phpcs:ignore ?>
					</header>
					<h3><?php echo esc_html( $t['title'] ); ?></h3>
					<?php if ( '' !== trim( $t['notes'] ) ) : ?><details class="sap-desk-brief" open><summary>توضیحات</summary><div class="sap-notes"><?php echo sap_rich_text( $t['notes'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div></details><?php endif; ?>
					<div class="sap-desk-assign">
						<label>مسئول<select data-sap-prop-assignee><option value="0">—</option><?php foreach ( sap_team_users() as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( (int) $u->ID, $t['assignee'] ); ?>><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?></select></label>
						<label>ددلاین<input type="date" data-sap-prop-due value="<?php echo esc_attr( (string) $t['due_date'] ); ?>"></label>
					</div>
					<footer>
						<button type="button" class="sap-btn is-primary is-small" data-sap-proposal="approve" data-id="<?php echo esc_attr( $t['id'] ); ?>"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>تأیید و واگذاری</button>
						<button type="button" class="sap-btn is-ghost is-danger is-small" data-sap-proposal="reject" data-id="<?php echo esc_attr( $t['id'] ); ?>">رد</button>
					</footer>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $d['late'] ) : ?>
	<section class="sap-desk-sec" id="late">
		<h2>⏰ نیاز به پیگیری فوری <small><?php echo esc_html( sap_num( count( $d['late'] ) ) ); ?> کار بیش از ۷ روز از سررسید گذشته</small></h2>
		<div class="sap-late-list">
			<?php foreach ( $d['late'] as $t ) : ?>
				<div class="sap-late-item">
				<a class="sap-late-row" href="<?php echo esc_url( sap_project_url( $t['project_id'], 'plan', array( 'task' => $t['id'] ) ) ); ?>">
					<?php echo sap_avatar( $t['assignee'], 32 ); // phpcs:ignore ?>
					<div><b><?php echo esc_html( $t['title'] ); ?></b><small><?php echo esc_html( sap_user_name( $t['assignee'] ) ); ?> · <?php echo esc_html( $proj( $t['project_id'] )['name'] ?? '' ); ?> · <?php echo esc_html( sap_task_statuses()[ $t['status'] ] ?? '' ); ?></small></div>
					<span class="sap-late-days"><?php echo esc_html( sap_num( sap_days_late( $t['due_date'] ) ) ); ?> روز</span>
				</a>
				<?php sap_view( 'nudge-btn', array( 't' => $t, 'compact' => true ) ); ?>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $answered ) : ?>
	<details class="sap-desk-sec sap-answered" id="answered">
		<summary><h2>✅ پاسخ داده شده‌ها <small><?php echo esc_html( sap_num( count( $answered ) ) ); ?> گفتگو در ۴۵ روز اخیر · اگر فرد دوباره بنویسد، به بالا برمی‌گردد</small></h2></summary>
		<div class="sap-desk-list">
			<?php foreach ( $answered as $q ) : ?>
				<?php $t = $q['task_id'] ? sap_task( (int) $q['task_id'] ) : null; ?>
				<details class="sap-answered-item">
					<summary>
						<?php echo sap_person( $q['user_id'], 22 ); // phpcs:ignore ?>
						<span class="sap-chip <?php echo 'request' === $q['kind'] ? 'is-soft' : 'is-info'; ?>"><?php echo 'request' === $q['kind'] ? 'درخواست' : 'سوال'; ?></span>
						<b><?php echo esc_html( 'request' === $q['kind'] ? $q['title'] : ( $t['title'] ?? 'کار حذف‌شده' ) ); ?></b>
						<small class="sap-muted"><?php echo esc_html( sap_jdate( substr( (string) ( $q['answered_at'] ?: $q['created_at'] ), 0, 10 ), 'day' ) ); ?></small>
					</summary>
					<?php sap_view( 'thread', array( 'q' => $q, 'side' => 'manager' ) ); ?>
				</details>
			<?php endforeach; ?>
		</div>
	</details>
<?php endif; ?>
