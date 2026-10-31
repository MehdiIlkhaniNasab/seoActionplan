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
$total = count( $d['review'] ) + count( $d['questions'] ) + count( $d['proposed'] ) + count( $d['late'] );
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
		<p class="sap-muted">هر چیزی که منتظر تصمیم شماست: گزارش‌هایی که باید تأیید شوند، سوال‌های تیم، کارهای پیشنهادی مدیران پروژه و کارهایی که بیش از ۷ روز از سررسیدشان گذشته.</p>
	</div>
</header>

<div class="sap-desk-stats">
	<a href="#review" class="<?php echo $d['review'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( count( $d['review'] ) ) ); ?></b><span>در انتظار تأیید</span></a>
	<a href="#questions" class="<?php echo $d['questions'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( count( $d['questions'] ) ) ); ?></b><span>سوال تیم</span></a>
	<a href="#proposed" class="<?php echo $d['proposed'] ? 'is-on' : ''; ?>"><b><?php echo esc_html( sap_num( count( $d['proposed'] ) ) ); ?></b><span>کار پیشنهادی</span></a>
	<a href="#late" class="<?php echo $d['late'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( count( $d['late'] ) ) ); ?></b><span>عقب‌افتاده (۷+ روز)</span></a>
</div>

<?php if ( ! $total ) : ?>
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
					<div class="sap-desk-report">
						<span>گزارش کار</span>
						<div><?php echo sap_rich_text( $t['result'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div>
						<?php if ( $t['spent'] ) : ?><small class="sap-muted"><?php echo esc_html( sap_num( $t['spent'], 1 ) ); ?> ساعت کار</small><?php endif; ?>
					</div>
					<?php echo $att( $t['attachments'] ); // phpcs:ignore ?>
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
						<small class="sap-muted"><?php echo esc_html( sap_jdate( substr( $q['created_at'], 0, 10 ), 'short' ) . ' ' . sap_fa( substr( $q['created_at'], 11, 5 ) ) ); ?></small>
					</header>
					<?php if ( $t ) : ?><p class="sap-small sap-muted">درباره: <a href="<?php echo esc_url( sap_project_url( $t['project_id'], 'plan', array( 'task' => $t['id'] ) ) ); ?>"><?php echo esc_html( $t['title'] ); ?></a></p><?php endif; ?>
					<blockquote class="sap-desk-q"><?php echo sap_rich_text( $q['question'] ); // phpcs:ignore ?></blockquote>
					<textarea rows="3" data-sap-answer placeholder="پاسخ شما (با ایمیل برایش می‌رود و زیر همان کار نمایش داده می‌شود)"></textarea>
					<footer><button type="button" class="sap-btn is-primary is-small" data-sap-answer-send="<?php echo esc_attr( $q['id'] ); ?>">ارسال پاسخ</button></footer>
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
				<a class="sap-late-row" href="<?php echo esc_url( sap_project_url( $t['project_id'], 'plan', array( 'task' => $t['id'] ) ) ); ?>">
					<?php echo sap_avatar( $t['assignee'], 32 ); // phpcs:ignore ?>
					<div><b><?php echo esc_html( $t['title'] ); ?></b><small><?php echo esc_html( sap_user_name( $t['assignee'] ) ); ?> · <?php echo esc_html( $proj( $t['project_id'] )['name'] ?? '' ); ?> · <?php echo esc_html( sap_task_statuses()[ $t['status'] ] ?? '' ); ?></small></div>
					<span class="sap-late-days"><?php echo esc_html( sap_num( sap_days_late( $t['due_date'] ) ) ); ?> روز</span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>
