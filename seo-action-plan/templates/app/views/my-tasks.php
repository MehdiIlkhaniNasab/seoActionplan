<?php
/**
 * «کارهای من»: a member's own dashboard — numbers, a roadmap of where to start,
 * and clean task cards with the description, deadline, the report (with links
 * and images), step-by-step help from AI and questions to the managers.
 * Full-access managers can look at any member's list.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$uid = get_current_user_id();
if ( sap_can() && ! empty( $_GET['u'] ) ) {
	$uid = absint( $_GET['u'] );
}
// phpcs:enable
$me      = sap_member( $uid );
$today   = current_time( 'Y-m-d' );
$open    = sap_tasks( array( 'assignee' => $uid, 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) );
$review  = sap_tasks( array( 'assignee' => $uid, 'status' => 'review' ) );
$someday = sap_tasks( array( 'assignee' => $uid, 'status' => 'someday' ) );
list( $mf, $mt ) = sap_period_range( sap_period_current() );
$done_m  = sap_member_done_count( $uid, $mf, $mt );
$done    = array_values( array_filter( sap_tasks( array( 'assignee' => $uid, 'status' => 'done' ) ), function ( $t ) {
	return strtotime( (string) $t['done_at'] ) > current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS; // phpcs:ignore
} ) );
$overdue = count( array_filter( $open, function ( $t ) use ( $today ) {
	return $t['due_date'] && $t['due_date'] < $today;
} ) );
$due_tod = count( array_filter( $open, function ( $t ) use ( $today ) {
	return $t['due_date'] === $today;
} ) );
// Roadmap order: returned with feedback, late, due today, in progress, nearest deadline, priority.
$rank = function ( $t ) use ( $today ) {
	$d = (string) $t['due_date'];
	if ( '' !== $t['review_note'] ) {
		return array( 0, $d ?: '9999', -$t['score'] );
	}
	if ( '' !== $d && $d < $today ) {
		return array( 1, $d, -$t['score'] );
	}
	if ( $d === $today ) {
		return array( 2, $d, -$t['score'] );
	}
	if ( 'doing' === $t['status'] ) {
		return array( 3, $d ?: '9999', -$t['score'] );
	}
	return array( 4, $d ?: '9999', -$t['score'] );
};
usort( $open, function ( $a, $b ) use ( $rank ) {
	return $rank( $a ) <=> $rank( $b );
} );
$why = function ( $t ) use ( $today ) {
	$d = (string) $t['due_date'];
	if ( '' !== $t['review_note'] ) {
		return array( 'برگشت با بازخورد مدیر', 'is-warn' );
	}
	if ( '' !== $d && $d < $today ) {
		return array( sap_num( sap_days_late( $d ) ) . ' روز تأخیر', 'is-bad' );
	}
	if ( $d === $today ) {
		return array( 'ددلاین امروز', 'is-warn' );
	}
	if ( 'doing' === $t['status'] ) {
		return array( 'در حال انجام', 'is-info' );
	}
	return '' !== $d ? array( 'ددلاین ' . sap_jdate( $d, 'day' ), 'is-soft' ) : array( 'بدون ددلاین', 'is-soft' );
};
$pcache = array();
$pname  = function ( $id ) use ( &$pcache ) {
	if ( ! isset( $pcache[ $id ] ) ) {
		$pcache[ $id ] = sap_project( $id );
	}
	return $pcache[ $id ];
};
$pcount = array();
foreach ( $open as $t ) {
	$pcount[ $t['project_id'] ] = ( $pcount[ $t['project_id'] ] ?? 0 ) + 1;
}
$total = count( $open ) + count( $review ) + $done_m;
$pct   = $total ? (int) round( 100 * $done_m / $total ) : 0;
$self  = $uid === get_current_user_id();
// Report guide = the «در گزارش کار…» part of the brief.
$guide = function ( $notes ) {
	if ( ! preg_match( '/(?:در گزارش کار[^\n]*)\n((?:\s*[-•*].*\n?)+)/u', (string) $notes, $m ) ) {
		return array();
	}
	return array_values( array_filter( array_map( function ( $l ) {
		return trim( preg_replace( '/^\s*[-•*]\s*/u', '', $l ) );
	}, explode( "\n", $m[1] ) ) ) );
};
?>
<header class="sap-head sap-my-head">
	<div class="sap-my-hello">
		<?php echo sap_avatar( $uid, 52 ); // phpcs:ignore ?>
		<div>
			<p class="sap-eyebrow"><?php echo esc_html( sap_jdate( $today, 'long' ) ); ?></p>
			<h1><?php echo $self ? 'سلام ' . esc_html( sap_first_name( $me['name'] ?? '' ) ) . ' 👋' : 'کارهای ' . esc_html( $me['name'] ?? '' ); ?></h1>
			<p class="sap-muted"><?php echo $open ? esc_html( sprintf( '%s کار باز داری%s. از «مسیر امروز» شروع کن.', sap_num( count( $open ) ), $overdue ? '، ' . sap_num( $overdue ) . ' تا عقب افتاده' : '' ) ) : 'همه کارها انجام شده؛ عالی! 🌿'; ?></p>
		</div>
	</div>
	<?php if ( sap_can() ) : ?>
		<form method="get" class="sap-inline">
			<select name="u" onchange="this.form.submit()">
				<?php foreach ( sap_team_users() as $u ) : ?>
					<option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( (int) $u->ID, $uid ); ?>><?php echo esc_html( $u->display_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>
	<?php endif; ?>
</header>

<div class="sap-my-kpis">
	<div class="is-main">
		<span>پیشرفت این ماه</span>
		<b><?php echo esc_html( sap_num( $pct ) ); ?>٪</b>
		<div class="sap-progress"><i class="is-done" style="width:<?php echo esc_attr( $pct ); ?>%"></i></div>
		<em><?php echo esc_html( sprintf( '%s از %s کار', sap_num( $done_m ), sap_num( $total ) ) ); ?></em>
	</div>
	<div><span>کل کارها</span><b><?php echo esc_html( sap_num( $total ) ); ?></b><em>این ماه</em></div>
	<div class="is-good"><span>انجام‌شده</span><b><?php echo esc_html( sap_num( $done_m ) ); ?></b><em>این ماه</em></div>
	<div><span>باقی‌مانده</span><b><?php echo esc_html( sap_num( count( $open ) ) ); ?></b><em><?php echo $due_tod ? esc_html( sap_num( $due_tod ) . ' تا امروز' ) : 'کار باز'; ?></em></div>
	<div class="<?php echo $overdue ? 'is-bad' : ''; ?>"><span>عقب‌افتاده</span><b><?php echo esc_html( sap_num( $overdue ) ); ?></b><em>از ددلاین گذشته</em></div>
	<div class="is-info"><span>منتظر تأیید</span><b><?php echo esc_html( sap_num( count( $review ) ) ); ?></b><em>گزارش ثبت‌شده</em></div>
</div>

<?php if ( $open ) : ?>
	<section class="sap-roadmap">
		<h2><?php echo sap_icon( 'target', 18 ); // phpcs:ignore ?>مسیر امروز: از کجا شروع کنم؟</h2>
		<ol>
			<?php foreach ( array_slice( $open, 0, 5 ) as $i => $t ) : ?>
				<?php $w = $why( $t ); ?>
				<li><a href="#task-<?php echo esc_attr( $t['id'] ); ?>"><span class="sap-roadmap-n"><?php echo esc_html( sap_num( $i + 1 ) ); ?></span><b><?php echo esc_html( $t['title'] ); ?></b><span class="sap-chip <?php echo esc_attr( $w[1] ); ?>"><?php echo esc_html( $w[0] ); ?></span></a></li>
			<?php endforeach; ?>
		</ol>
		<p class="sap-small sap-muted">ترتیب: اول کارهایی که با بازخورد برگشته‌اند، بعد عقب‌افتاده‌ها، ددلاین امروز، کارهای در حال انجام و نزدیک‌ترین ددلاین‌ها.</p>
	</section>

	<div class="sap-my-filter">
		<div class="sap-my-projects">
			<button type="button" class="is-active" data-sap-my-project="">همه <em><?php echo esc_html( sap_num( count( $open ) ) ); ?></em></button>
			<?php foreach ( $pcount as $pid => $n ) : ?>
				<?php $p = $pname( $pid ); ?>
				<?php if ( $p ) : ?><button type="button" data-sap-my-project="<?php echo esc_attr( $pid ); ?>"><i class="sap-dot" style="background:<?php echo esc_attr( sap_member_color( $pid + 3 ) ); ?>"></i><?php echo esc_html( $p['name'] ); ?> <em><?php echo esc_html( sap_num( $n ) ); ?></em></button><?php endif; ?>
			<?php endforeach; ?>
		</div>
		<input type="search" data-sap-my-search placeholder="جستجو در کارها…">
	</div>

	<section class="sap-my-group" data-sap-my-group>
		<div class="sap-my-list">
			<?php foreach ( $open as $t ) : ?>
				<?php
				$p    = $pname( $t['project_id'] );
				$late = $t['due_date'] && $t['due_date'] < $today;
				$qs   = sap_task_questions( $t['id'] );
				$gd   = $guide( $t['notes'] );
				?>
				<article class="sap-mytask<?php echo $late ? ' is-late' : ''; ?>" id="task-<?php echo esc_attr( $t['id'] ); ?>" data-sap-mytask="<?php echo esc_attr( $t['id'] ); ?>" data-project="<?php echo esc_attr( $t['project_id'] ); ?>" data-text="<?php echo esc_attr( mb_strtolower( $t['title'] . ' ' . ( $p['name'] ?? '' ) . ' ' . $t['keyword'] ) ); ?>">
					<div class="sap-mytask-top">
						<span class="sap-ptag" style="--c:<?php echo esc_attr( sap_member_color( $t['project_id'] + 3 ) ); ?>"><?php echo esc_html( $p['name'] ?? '' ); ?></span>
						<span class="sap-chip is-soft"><?php echo esc_html( sap_context_label( $t['context'] ) ); ?></span>
						<?php if ( 'doing' === $t['status'] ) : ?><span class="sap-chip is-info">در حال انجام</span><?php endif; ?>
						<span class="sap-mytask-due <?php echo $late ? 'is-late' : ( $t['due_date'] === $today ? 'is-today' : '' ); ?>"><?php echo sap_icon( 'clock', 13 ); // phpcs:ignore ?><?php echo esc_html( $t['due_date'] ? ( $late ? 'عقب افتاده · ' : 'ددلاین ' ) . sap_jdate( $t['due_date'] ) : 'بدون ددلاین' ); ?></span>
					</div>
					<h3><?php echo esc_html( $t['title'] ); ?></h3>
					<?php if ( '' !== $t['review_note'] ) : ?>
						<div class="sap-mytask-fb"><b>🔁 بازخورد مدیر</b><p><?php echo esc_html( $t['review_note'] ); ?></p></div>
					<?php endif; ?>
					<?php if ( '' !== trim( $t['notes'] ) ) : ?>
						<div class="sap-mytask-desc<?php echo mb_strlen( $t['notes'] ) > 700 ? ' is-long' : ''; ?>" data-sap-desc>
							<?php echo sap_rich_text( $t['notes'], sap_task_site_base( $t ) ); // phpcs:ignore ?>
						</div>
						<?php if ( mb_strlen( $t['notes'] ) > 700 ) : ?><button type="button" class="sap-linkbtn" data-sap-desc-more>نمایش کامل توضیحات</button><?php endif; ?>
					<?php endif; ?>
					<?php if ( '' !== $t['outcome'] || '' !== $t['url'] ) : ?>
						<div class="sap-mytask-facts">
							<?php if ( '' !== $t['outcome'] ) : ?><p><span>خروجی مطلوب</span><?php echo esc_html( $t['outcome'] ); ?></p><?php endif; ?>
							<?php if ( '' !== $t['url'] ) : ?><p><span>صفحه</span><?php echo sap_url_tag( $t['url'], 60 ); // phpcs:ignore ?></p><?php endif; ?>
						</div>
					<?php endif; ?>
					<?php if ( $t['checklist'] ) : ?>
						<?php $pg = sap_checklist_progress( $t ); ?>
						<div class="sap-checklist<?php echo $pg['done'] === $pg['total'] ? ' is-complete' : ''; ?>" data-sap-checklist="<?php echo esc_attr( $t['id'] ); ?>">
							<div class="sap-checklist-head">
								<b>✅ چک‌لیست کار</b>
								<span data-sap-check-count><?php echo esc_html( sap_num( $pg['done'] ) . ' از ' . sap_num( $pg['total'] ) ); ?></span>
							</div>
							<div class="sap-checklist-bar"><i data-sap-check-bar style="width:<?php echo esc_attr( $pg['pct'] ); ?>%"></i><em data-sap-check-pct><?php echo esc_html( sap_num( $pg['pct'] ) ); ?>٪</em></div>
							<ul>
								<?php foreach ( $t['checklist'] as $ci => $it ) : ?>
									<li class="<?php echo $it['d'] ? 'is-done' : ''; ?>"><label><input type="checkbox" data-sap-check="<?php echo esc_attr( $ci ); ?>" <?php checked( $it['d'] ); ?>><span><?php echo esc_html( $it['t'] ); ?></span></label></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
					<?php if ( $qs ) : ?>
						<div class="sap-mytask-qs">
							<b class="sap-mytask-qs-h">💬 گفتگو با مدیر</b>
							<?php foreach ( $qs as $q ) : ?>
								<?php sap_view( 'thread', array( 'q' => $q, 'side' => 'member' ) ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<div class="sap-mytask-acts">
						<?php if ( 'doing' !== $t['status'] ) : ?><button type="button" class="sap-btn is-small is-soft" data-sap-my-status="doing"><?php echo sap_icon( 'bolt', 13 ); // phpcs:ignore ?>شروع کار</button><?php endif; ?>
						<button type="button" class="sap-btn is-small is-primary" data-sap-my-done><?php echo sap_icon( 'check', 13 ); // phpcs:ignore ?>انجام شد و ثبت گزارش</button>
						<span class="sap-mytask-acts-sep"></span>
						<button type="button" class="sap-btn is-small is-ghost" data-sap-my-panel="ai" <?php disabled( ! sap_ai_connected() ); ?>>🤖 از هوش مصنوعی بپرس</button>
						<button type="button" class="sap-btn is-small is-ghost" data-sap-my-panel="ask">❓ سوال از مدیر</button>
					</div>

					<form class="sap-mytask-report" data-sap-my-report hidden>
						<?php if ( $gd ) : ?>
							<div class="sap-report-guide"><b>📝 در گزارش دقیقاً این‌ها را بنویس:</b><ul><?php foreach ( $gd as $g ) : ?><li><?php echo esc_html( $g ); ?></li><?php endforeach; ?></ul></div>
						<?php endif; ?>
						<label>گزارش کار — دقیقاً چه کاری انجام شد؟<textarea name="result" rows="4" required placeholder="مثلاً: تایتل صفحه /x/ از «…» به «…» تغییر کرد؛ ۴ سوال FAQ اضافه شد؛ لینک داخلی از مقاله Y با انکر Z گذاشته شد."><?php echo esc_textarea( '' !== $t['result'] ? $t['result'] : ( $t['checklist'] && sap_checklist_progress( $t )['done'] ? sap_checklist_report( $t ) : '' ) ); ?></textarea></label>
						<label>لینک‌ها و مستندات (هر خط یک لینک)<textarea name="links" rows="2" dir="ltr" placeholder="https://"><?php echo esc_textarea( implode( "\n", array_map( function ( $a ) { return $a['url']; }, array_filter( $t['attachments'], function ( $a ) { return 'link' === ( $a['type'] ?? '' ); } ) ) ) ); ?></textarea></label>
						<div class="sap-report-files">
							<label class="sap-btn is-ghost is-small sap-file-btn"><?php echo sap_icon( 'image', 14 ); // phpcs:ignore ?>افزودن تصویر یا PDF<input type="file" accept="image/*,application/pdf" multiple data-sap-attach hidden></label>
							<div class="sap-att-list" data-sap-att-list>
								<?php foreach ( $t['attachments'] as $a ) : ?>
									<?php if ( 'link' === ( $a['type'] ?? '' ) ) { continue; } ?>
									<span class="sap-att<?php echo 'image' === $a['type'] ? ' is-img' : ''; ?>"><?php echo 'image' === $a['type'] ? '<img src="' . esc_url( $a['url'] ) . '" alt="">' : esc_html( $a['name'] ); ?><button type="button" data-sap-att-rm="<?php echo esc_attr( $a['url'] ); ?>" aria-label="حذف">×</button></span>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="sap-mytask-report-row">
							<label>زمان صرف‌شده (ساعت)<input type="number" name="spent" min="0" step="0.25" value="<?php echo esc_attr( $t['spent'] ?: $t['effort'] ); ?>"></label>
							<button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>ثبت و ارسال برای تأیید</button>
							<button class="sap-btn is-ghost" type="button" data-sap-my-cancel>انصراف</button>
						</div>
					</form>

					<div class="sap-mytask-panel" data-sap-panel="ai" hidden>
						<div class="sap-help-log" data-sap-help-log><p class="sap-help-msg is-bot">سلام! هر جای این کار گیر کردی بپرس؛ قدم به قدم راهنمایی‌ات می‌کنم. 🙂</p></div>
						<form class="sap-help-form" data-sap-help-form>
							<input type="text" name="q" placeholder="مثلاً: اسکیمای FAQ را در رنک‌مث کجا اضافه کنم؟" autocomplete="off">
							<button class="sap-btn is-primary is-small" type="submit">بپرس</button>
						</form>
					</div>
					<div class="sap-mytask-panel" data-sap-panel="ask" hidden>
						<form class="sap-ask-form" data-sap-ask-form>
							<textarea name="q" rows="3" placeholder="سوالت را دقیق بنویس؛ برای مدیران سئو ایمیل می‌شود و پاسخ همین‌جا می‌آید."></textarea>
							<button class="sap-btn is-primary is-small" type="submit">ارسال سوال</button>
						</form>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php else : ?>
	<div class="sap-empty sap-empty-good"><?php echo sap_icon( 'check', 32 ); // phpcs:ignore ?><p>کار بازی نداری. 🌿</p></div>
<?php endif; ?>

<?php if ( $review ) : ?>
	<details class="sap-panel sap-my-done" open>
		<summary><h2>⏳ منتظر تأیید مدیر <small><?php echo esc_html( sap_num( count( $review ) ) ); ?></small></h2></summary>
		<ul>
			<?php foreach ( $review as $t ) : ?>
				<?php $p = $pname( $t['project_id'] ); ?>
				<li><span class="sap-ptag" style="--c:<?php echo esc_attr( sap_member_color( $t['project_id'] + 3 ) ); ?>"><?php echo esc_html( $p['name'] ?? '' ); ?></span> <b><?php echo esc_html( $t['title'] ); ?></b> <small class="sap-muted"><?php echo esc_html( $t['submitted_at'] ? 'ثبت ' . sap_jdate( substr( $t['submitted_at'], 0, 10 ), 'short' ) : '' ); ?></small></li>
			<?php endforeach; ?>
		</ul>
	</details>
<?php endif; ?>

<?php if ( $done ) : ?>
	<details class="sap-panel sap-my-done">
		<summary><h2><?php echo sap_icon( 'check', 16 ); // phpcs:ignore ?>تأییدشده در ۷ روز اخیر <small><?php echo esc_html( sap_num( count( $done ) ) ); ?></small></h2></summary>
		<ul>
			<?php foreach ( $done as $t ) : ?>
				<?php $p = $pname( $t['project_id'] ); ?>
				<li><span class="sap-ptag" style="--c:<?php echo esc_attr( sap_member_color( $t['project_id'] + 3 ) ); ?>"><?php echo esc_html( $p['name'] ?? '' ); ?></span> <b><?php echo esc_html( $t['title'] ); ?></b> <small class="sap-muted"><?php echo esc_html( sap_jdate( $t['done_at'], 'short' ) . ( $t['spent'] ? ' · ' . sap_num( $t['spent'], 1 ) . ' ساعت' : '' ) ); ?></small></li>
			<?php endforeach; ?>
		</ul>
	</details>
<?php endif; ?>

<?php if ( $someday ) : ?>
	<details class="sap-panel sap-my-done">
		<summary><h2>شاید بعداً <small><?php echo esc_html( sap_num( count( $someday ) ) ); ?></small></h2></summary>
		<ul><?php foreach ( $someday as $t ) : ?><li><b><?php echo esc_html( $t['title'] ); ?></b></li><?php endforeach; ?></ul>
	</details>
<?php endif; ?>
