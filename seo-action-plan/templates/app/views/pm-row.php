<?php
/**
 * One task row in the project manager's lists: state, title, project, checklist
 * progress, person, deadline; inside: checklist, daily reports, final report,
 * feedback and (for their own tasks waiting for review) approve / feedback.
 *
 * @package SeoActionPlan
 * @var array $t
 * @var bool  $show_project
 */

defined( 'ABSPATH' ) || exit;

$b     = sap_task_bucket( $t );
$sl    = sap_task_state_chip( $t );
$prog  = $t['checklist'] ? sap_checklist_progress( $t ) : null;
$ps    = sap_progress_summary( $t );
$rep   = '' !== $t['result'] || $t['attachments'];
$today = current_time( 'Y-m-d' );
$fresh = $ps['last'] && 0 === strpos( (string) $ps['last']['at'], $today );
$show_project = $show_project ?? true;
?>
<details class="sap-pm-row is-<?php echo esc_attr( $b ); ?>" id="t-<?php echo esc_attr( $t['id'] ); ?>" data-bucket="<?php echo esc_attr( $b ); ?>" data-who="<?php echo esc_attr( $t['assignee'] ); ?>">
	<summary>
		<span class="sap-chip <?php echo esc_attr( $sl[1] ); ?>"><?php echo esc_html( $sl[0] ); ?></span>
		<b><?php echo esc_html( $t['title'] ); ?></b>
		<?php if ( sap_nudge_open( $t ) ) : ?><span class="sap-chip is-warn">📣 منتظر پاسخ</span><?php endif; ?>
		<?php if ( $fresh ) : ?><span class="sap-chip is-good" title="امروز گزارش روزانه ثبت شده">🗒️ گزارش امروز</span><?php elseif ( $ps['count'] ) : ?><span class="sap-chip is-soft">🗒️ <?php echo esc_html( sap_num( $ps['count'] ) ); ?></span><?php endif; ?>
		<?php if ( '' !== $t['repeat_every'] ) : ?><span class="sap-chip is-rec">🔁 <?php echo esc_html( sap_repeat_options()[ $t['repeat_every'] ] ?? '' ); ?></span><?php endif; ?>
		<?php if ( $show_project ) : ?><span class="sap-ptag" style="--c:<?php echo esc_attr( sap_member_color( $t['project_id'] + 3 ) ); ?>"><?php echo esc_html( sap_project( $t['project_id'] )['name'] ?? '' ); ?></span><?php endif; ?>
		<?php if ( $prog ) : ?><span class="sap-mini-prog<?php echo $prog['done'] === $prog['total'] ? ' is-full' : ''; ?>"><i style="width:<?php echo esc_attr( $prog['pct'] ); ?>%"></i><em><?php echo esc_html( sap_num( $prog['done'] ) . '/' . sap_num( $prog['total'] ) ); ?></em></span><?php endif; ?>
		<?php echo $t['assignee'] ? sap_person( $t['assignee'], 20 ) : '<em class="sap-muted sap-small">بدون مسئول</em>'; // phpcs:ignore ?>
		<small class="sap-muted"><?php echo esc_html( $t['due_date'] ? 'ددلاین ' . sap_jdate( $t['due_date'], 'day' ) : 'بدون ددلاین' ); ?></small>
	</summary>
	<div class="sap-pm-row-body">
		<?php if ( sap_can_nudge( $t ) ) : ?><div class="sap-tnudge"><?php sap_view( 'nudge-btn', array( 't' => $t ) ); ?></div><?php endif; ?>
		<?php if ( $ps['blocked'] ) : ?><p class="sap-tnote is-bad"><b>⚠️ گیر کرده:</b> <?php echo esc_html( $t['waiting_for'] ); ?></p><?php endif; ?>
		<?php if ( $prog ) : ?>
			<ul class="sap-pm-check"><?php foreach ( $t['checklist'] as $it ) : ?><li class="<?php echo $it['d'] ? 'is-done' : ''; ?>"><?php echo $it['d'] ? '✔' : '○'; ?> <?php echo esc_html( $it['t'] ); ?> <?php echo sap_checklist_due_tag( $it ); // phpcs:ignore ?></li><?php endforeach; ?></ul>
		<?php endif; ?>
		<?php sap_view( 'progress-log', array( 't' => $t, 'form' => false ) ); ?>
		<?php if ( $rep ) : ?>
			<div class="sap-treport">
				<span>گزارش نهایی<?php echo $t['spent'] ? ' · ' . esc_html( sap_num( $t['spent'], 1 ) ) . ' ساعت' : ''; ?><?php echo $t['submitted_at'] ? ' · ' . esc_html( sap_jdate( substr( $t['submitted_at'], 0, 10 ), 'day' ) ) : ''; ?></span>
				<?php if ( '' !== $t['result'] ) : ?><div><?php echo sap_rich_text( $t['result'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div><?php endif; ?>
				<?php if ( $t['attachments'] ) : ?>
					<div class="sap-att-list"><?php foreach ( $t['attachments'] as $a ) : ?><a class="sap-att<?php echo 'image' === ( $a['type'] ?? '' ) ? ' is-img' : ''; ?>" href="<?php echo esc_url( $a['url'] ); ?>" target="_blank" rel="noopener"><?php echo 'image' === ( $a['type'] ?? '' ) ? '<img src="' . esc_url( $a['url'] ) . '" alt="" loading="lazy">' : esc_html( '' !== ( $a['name'] ?? '' ) ? $a['name'] : sap_short_url( $a['url'], 40 ) ); ?></a><?php endforeach; ?></div>
				<?php endif; ?>
			</div>
		<?php elseif ( ! $ps['count'] ) : ?>
			<p class="sap-muted sap-small">هنوز گزارشی ثبت نشده.</p>
		<?php endif; ?>
		<?php if ( '' !== $t['review_note'] ) : ?><p class="sap-tnote"><b>🔁 بازخورد:</b> <?php echo esc_html( $t['review_note'] ); ?></p><?php endif; ?>
		<?php if ( '' !== trim( $t['notes'] ) ) : ?><details class="sap-desk-brief"><summary>شرح کار</summary><div class="sap-notes"><?php echo sap_rich_text( $t['notes'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div></details><?php endif; ?>
		<?php if ( 'review' === $t['status'] && sap_can_review( $t ) ) : ?>
			<div class="sap-tactions is-review" data-sap-desk-card>
				<form class="sap-desk-fb" data-sap-desk-feedback hidden><textarea name="note" rows="3" placeholder="چه چیزی باید اصلاح یا اضافه شود؟ (برای مسئول ایمیل و اعلان می‌شود)"></textarea></form>
				<button type="button" class="sap-btn is-primary is-small" data-sap-review="approve" data-id="<?php echo esc_attr( $t['id'] ); ?>"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>تأیید گزارش</button>
				<button type="button" class="sap-btn is-ghost is-small" data-sap-review="feedback" data-id="<?php echo esc_attr( $t['id'] ); ?>">🔁 بازخورد و برگشت</button>
			</div>
		<?php endif; ?>
	</div>
</details>
