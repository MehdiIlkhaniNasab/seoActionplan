<?php
/**
 * One task card (plan board, someday list): compact summary; inside, the
 * essentials (assignee, deadline, type, page), the description for the person
 * doing it, the report and documents, and tidy actions.
 *
 * @package SeoActionPlan
 * @var array  $t
 * @var array  $fnames  focus id => name
 * @var array  $focuses
 * @var string $here    return URL
 * @var int    $open_id task to open
 */

defined( 'ABSPATH' ) || exit;

$manager = sap_can();
$today   = current_time( 'Y-m-d' );
$closed  = in_array( $t['status'], array( 'done', 'dropped' ), true );
$overdue = $t['due_date'] && $t['due_date'] < $today && ! $closed;
$due_cls = $overdue ? 'is-late' : ( $t['due_date'] === $today ? 'is-today' : '' );
$post    = esc_url( admin_url( 'admin-post.php' ) );
$ret     = $here . '#task-' . $t['id'];
$prog    = $t['checklist'] ? sap_checklist_progress( $t ) : null;
$psum    = sap_progress_summary( $t );
$odays   = $closed || 'review' === $t['status'] ? null : sap_task_open_days( $t );
$flow    = array( 'next' => 'اقدام بعدی', 'doing' => 'در حال انجام', 'waiting' => 'در انتظار', 'done' => 'انجام شد' );
?>
<details class="sap-task is-<?php echo esc_attr( $t['status'] ); ?>" id="task-<?php echo esc_attr( $t['id'] ); ?>" <?php echo ( (int) ( $open_id ?? 0 ) === $t['id'] ) ? 'open' : ''; ?>>
	<summary>
		<?php if ( $manager ) : ?>
			<input type="checkbox" class="sap-task-pick" value="<?php echo esc_attr( $t['id'] ); ?>" aria-label="انتخاب">
		<?php endif; ?>
		<span class="sap-task-title"><?php echo esc_html( $t['title'] ); ?></span>
		<?php if ( $t['checklist'] ) : ?>
			<ul class="sap-task-checks<?php echo count( $t['checklist'] ) > 4 ? ' is-more' : ''; ?>">
				<?php foreach ( array_slice( $t['checklist'], 0, 6 ) as $it ) : ?>
					<li class="<?php echo $it['d'] ? 'is-done' : ''; ?>"><i><?php echo $it['d'] ? '✓' : ''; ?></i><span><?php echo esc_html( $it['t'] ); ?></span><?php echo sap_checklist_due_tag( $it ); // phpcs:ignore ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $t['checklist'] ) > 4 ) : ?><small class="sap-task-checks-more">+ <?php echo esc_html( sap_num( count( $t['checklist'] ) - 4 ) ); ?> مورد دیگر</small><?php endif; ?>
		<?php endif; ?>
		<span class="sap-task-meta">
			<?php echo sap_context_chip( $t['context'] ); // phpcs:ignore ?>
			<?php if ( $t['due_date'] ) : ?>
				<span class="sap-due <?php echo esc_attr( $due_cls ); ?>"><?php echo sap_icon( 'clock', 12 ); // phpcs:ignore ?><?php echo esc_html( $overdue ? sap_num( sap_days_late( $t['due_date'] ) ) . ' روز تأخیر' : ( $t['due_date'] === $today ? 'امروز' : sap_jdate( $t['due_date'], 'day' ) ) ); ?></span>
			<?php endif; ?>
			<?php if ( 'review' === $t['status'] ) : ?><span class="sap-chip is-info">منتظر تأیید</span><?php endif; ?>
			<?php if ( '' !== $t['review_note'] && 'doing' === $t['status'] ) : ?><span class="sap-chip is-warn">برگشت با بازخورد</span><?php endif; ?>
			<?php if ( $t['recurring'] ) : ?><span class="sap-chip is-rec">دوره‌ای</span><?php endif; ?>
			<?php if ( '' !== $t['repeat_every'] ) : ?><span class="sap-chip is-rec" title="بعد از تأیید، نوبت بعدی خودکار ساخته می‌شود">🔁 <?php echo esc_html( sap_repeat_options()[ $t['repeat_every'] ] ?? '' ); ?></span><?php endif; ?>
			<?php if ( sap_is_client_request( $t ) ) : ?><span class="sap-chip is-warn">🧑‍💼 درخواست کارفرما</span><?php endif; ?>
			<?php echo sap_client_chip( $t ); // phpcs:ignore ?>
			<?php if ( sap_nudge_open( $t ) ) : ?><span class="sap-chip is-warn" title="یادآوری فرستاده شده و هنوز گزارشی نیامده">📣 منتظر پاسخ</span><?php endif; ?>
			<?php if ( $psum['blocked'] ) : ?><span class="sap-chip is-bad" title="<?php echo esc_attr( $t['waiting_for'] ); ?>">⚠️ گیر کرده</span><?php endif; ?>
			<?php if ( null !== $odays && $odays >= sap_long_days() ) : ?><span class="sap-chip is-warn" title="روز از شروع کار"><?php echo esc_html( sap_num( $odays ) ); ?> روز باز</span><?php endif; ?>
			<?php if ( $psum['count'] ) : ?><span class="sap-chip is-soft" title="گزارش روزانه">🗒️ <?php echo esc_html( sap_num( $psum['count'] ) ); ?></span><?php endif; ?>
			<?php if ( $prog ) : ?><span class="sap-mini-prog<?php echo $prog['done'] === $prog['total'] ? ' is-full' : ''; ?>" title="پیشرفت چک‌لیست"><i style="width:<?php echo esc_attr( $prog['pct'] ); ?>%"></i><em><?php echo esc_html( sap_num( $prog['done'] ) . '/' . sap_num( $prog['total'] ) ); ?></em></span><?php endif; ?>
			<span data-sap-ti="avatar"><?php if ( $t['assignee'] ) : ?><?php echo sap_avatar( $t['assignee'], 22 ); // phpcs:ignore ?><?php endif; ?></span>
		</span>
	</summary>
	<?php if ( ! empty( $lazy ) && (int) ( $open_id ?? 0 ) !== $t['id'] ) : ?>
		<div class="sap-task-body" data-sap-lazy="<?php echo esc_attr( $t['id'] ); ?>"><p class="sap-lazy-wait"><span class="sap-spin"></span> در حال باز کردن کار…</p></div>
	<?php else : ?>
		<?php sap_view( 'task-body', get_defined_vars() ); ?>
	<?php endif; ?>
</details>
