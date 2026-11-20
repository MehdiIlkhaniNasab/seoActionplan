<?php
/**
 * Progress reports of a task: a dated timeline (newest first) and, for the
 * person doing it, the form for today's report («پیش رفت» or «گیر کرده» + reason).
 *
 * @package SeoActionPlan
 * @var array $t    task
 * @var bool  $form show the form
 */

defined( 'ABSPATH' ) || exit;

$form  = ! empty( $form ) && ! in_array( $t['status'], array( 'done', 'dropped', 'review' ), true );
$list  = array_reverse( (array) $t['progress'], true ); // Keys = index in the task (for editing).
$can_e = sap_can_review( $t );
$days  = sap_task_open_days( $t );
$sum   = sap_progress_summary( $t );
if ( ! $list && ! $form ) {
	return;
}
?>
<div class="sap-plog<?php echo $sum['blocked'] ? ' is-blocked' : ''; ?>" data-sap-plog="<?php echo esc_attr( $t['id'] ); ?>" data-ai="<?php echo sap_ai_connected() ? '1' : ''; ?>">
	<div class="sap-plog-head">
		<b>🗒️ گزارش‌های روزانه</b>
		<span class="sap-plog-meta">
			<?php if ( null !== $days ) : ?><span class="sap-chip <?php echo $days >= sap_long_days() ? 'is-warn' : 'is-soft'; ?>"><?php echo esc_html( $days ? sap_num( $days ) . ' روز از شروع' : 'امروز شروع شد' ); ?></span><?php endif; ?>
			<?php if ( $sum['count'] ) : ?><span class="sap-chip is-soft"><?php echo esc_html( sap_num( $sum['count'] ) ); ?> گزارش</span><?php endif; ?>
			<?php if ( $sum['blocked'] ) : ?><span class="sap-chip is-bad">⚠️ گیر کرده</span><?php endif; ?>
		</span>
	</div>
	<?php if ( $form ) : ?>
		<form class="sap-plog-form" data-sap-plog-form>
			<textarea name="text" rows="2" required placeholder="امروز روی این کار چه کردی؟ مثلاً: مدارک برای اینماد بارگذاری شد؛ با پشتیبانی تماس گرفتم."></textarea>
			<div class="sap-plog-row">
				<div class="sap-seg-mini sap-plog-state">
					<label><input type="radio" name="state" value="progress" checked><span>✅ پیش رفت</span></label>
					<label><input type="radio" name="state" value="blocked"><span>⚠️ گیر کرده</span></label>
				</div>
				<input type="text" name="why" data-sap-plog-why placeholder="علت / منتظر چه چیزی؟ (مثلاً تأیید اینماد، پاسخ کارفرما)" hidden>
				<button class="sap-btn is-primary is-small" type="submit"><?php echo sap_icon( 'send', 13 ); // phpcs:ignore ?>ثبت گزارش امروز</button>
			</div>
		</form>
	<?php endif; ?>
	<?php if ( $list ) : ?>
		<ol class="sap-plog-list<?php echo count( $list ) > 3 ? ' is-collapsed' : ''; ?>">
			<?php foreach ( $list as $i => $p ) : ?>
				<?php if ( 'edit' === ( $p['kind'] ?? '' ) ) : ?>
					<li class="is-edit"><span class="sap-plog-dot"></span><div><span class="sap-plog-when">✎ <?php echo esc_html( sap_first_name( sap_user_name( (int) $p['u'] ) ) . ' گزارش کار را ویرایش کرد · ' . sap_jdate( substr( $p['at'], 0, 10 ), 'day' ) ); ?></span><?php if ( $can_e && '' !== trim( (string) $p['text'] ) ) : ?><details class="sap-plog-orig"><summary>متن قبلی</summary><p><?php echo nl2br( esc_html( $p['text'] ) ); ?></p></details><?php endif; ?></div></li>
					<?php continue; ?>
				<?php endif; ?>
				<?php $own = ! in_array( $p['kind'] ?? '', array( 'delegate', 'follow' ), true ); ?>
				<li class="is-<?php echo esc_attr( $p['state'] ?? 'progress' ); ?>" data-sap-pentry="<?php echo esc_attr( $i ); ?>">
					<span class="sap-plog-dot"></span>
					<div>
						<span class="sap-plog-when"><?php echo esc_html( sap_weekday_fa( substr( $p['at'], 0, 10 ) ) . ' ' . sap_jdate( substr( $p['at'], 0, 10 ), 'day' ) . ' · ' . sap_fa( substr( $p['at'], 11, 5 ) ) ); ?> · <?php echo esc_html( sap_first_name( sap_user_name( (int) $p['u'] ) ) ); ?><?php if ( ! empty( $p['eu'] ) ) : ?> <em class="sap-plog-ed" title="<?php echo esc_attr( 'ویرایش ' . sap_jdate( substr( (string) $p['eat'], 0, 10 ), 'day' ) ); ?>">✎ ویرایش <?php echo esc_html( sap_first_name( sap_user_name( (int) $p['eu'] ) ) ); ?></em><?php endif; ?>
							<?php if ( $can_e && $own ) : ?><button type="button" class="sap-linkbtn sap-plog-edit" data-sap-pedit aria-label="ویرایش این گزارش">ویرایش</button><?php endif; ?>
						</span>
						<p data-sap-ptext><?php echo nl2br( esc_html( $p['text'] ) ); ?></p>
						<?php if ( $can_e && ! empty( $p['orig'] ) ) : ?><details class="sap-plog-orig"><summary>متن اصلی عضو</summary><p><?php echo nl2br( esc_html( $p['orig'] ) ); ?></p></details><?php endif; ?>
						<?php if ( 'blocked' === ( $p['state'] ?? '' ) ) : ?><p class="sap-plog-why">⚠️ گیر کرده: <?php echo esc_html( $p['why'] ); ?></p><?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php if ( count( $list ) > 3 ) : ?><button type="button" class="sap-linkbtn" data-sap-plog-more>نمایش همه <?php echo esc_html( sap_num( count( $list ) ) ); ?> گزارش</button><?php endif; ?>
	<?php endif; ?>
</div>
