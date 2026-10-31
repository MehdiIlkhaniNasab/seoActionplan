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
$flow    = array( 'next' => 'اقدام بعدی', 'doing' => 'در حال انجام', 'waiting' => 'در انتظار', 'done' => 'انجام شد' );
?>
<details class="sap-task is-<?php echo esc_attr( $t['status'] ); ?>" id="task-<?php echo esc_attr( $t['id'] ); ?>" <?php echo ( (int) ( $open_id ?? 0 ) === $t['id'] ) ? 'open' : ''; ?>>
	<summary>
		<?php if ( $manager ) : ?>
			<input type="checkbox" class="sap-task-pick" value="<?php echo esc_attr( $t['id'] ); ?>" aria-label="انتخاب">
		<?php endif; ?>
		<span class="sap-task-title"><?php echo esc_html( $t['title'] ); ?></span>
		<span class="sap-task-meta">
			<?php echo sap_context_chip( $t['context'] ); // phpcs:ignore ?>
			<?php if ( $t['due_date'] ) : ?>
				<span class="sap-due <?php echo esc_attr( $due_cls ); ?>"><?php echo sap_icon( 'clock', 12 ); // phpcs:ignore ?><?php echo esc_html( $overdue ? sap_num( sap_days_late( $t['due_date'] ) ) . ' روز تأخیر' : ( $t['due_date'] === $today ? 'امروز' : sap_jdate( $t['due_date'], 'day' ) ) ); ?></span>
			<?php endif; ?>
			<?php if ( 'review' === $t['status'] ) : ?><span class="sap-chip is-info">منتظر تأیید</span><?php endif; ?>
			<?php if ( '' !== $t['review_note'] && 'doing' === $t['status'] ) : ?><span class="sap-chip is-warn">برگشت با بازخورد</span><?php endif; ?>
			<?php if ( $t['recurring'] ) : ?><span class="sap-chip is-rec">دوره‌ای</span><?php endif; ?>
			<?php if ( $t['assignee'] ) : ?><?php echo sap_avatar( $t['assignee'], 22 ); // phpcs:ignore ?><?php endif; ?>
		</span>
	</summary>
	<div class="sap-task-body">
		<div class="sap-tinfo">
			<div><span>مسئول</span><b><?php echo $t['assignee'] ? sap_person( $t['assignee'], 20 ) : '<em class="sap-muted">تعیین نشده</em>'; // phpcs:ignore ?></b></div>
			<div><span>ددلاین</span><b class="sap-due <?php echo esc_attr( $due_cls ); ?>"><?php echo esc_html( $t['due_date'] ? sap_jdate( $t['due_date'] ) : '—' ); ?></b></div>
			<div><span>نوع کار</span><b><?php echo esc_html( sap_context_label( $t['context'] ) ); ?></b></div>
			<div><span>وضعیت</span><b><?php echo esc_html( sap_task_statuses()[ $t['status'] ] ?? $t['status'] ); ?></b></div>
			<?php if ( $t['url'] ) : ?><div class="is-wide"><span>صفحه</span><b><?php echo sap_page_link( $t['url'], sap_page_title( $t['project_id'], $t['url'] ) ?: sap_short_url( $t['url'], 60 ) ); // phpcs:ignore ?></b></div><?php endif; ?>
		</div>

		<?php if ( '' !== $t['review_note'] ) : ?>
			<p class="sap-tnote"><b>🔁 بازخورد شما:</b> <?php echo esc_html( $t['review_note'] ); ?></p>
		<?php endif; ?>

		<div class="sap-tdesc">
			<div class="sap-tdesc-head">
				<span>توضیحات برای مسئول</span>
				<?php if ( $manager && sap_ai_connected() && ! $closed ) : ?>
					<button type="button" class="sap-btn is-ghost is-small" data-sap-brief-task="<?php echo esc_attr( $t['id'] ); ?>" data-project="<?php echo esc_attr( $t['project_id'] ); ?>" title="هدف، مراحل، خروجی و اینکه در گزارش دقیقاً چه بنویسد"><?php echo sap_icon( 'spark', 13 ); // phpcs:ignore ?>شفاف‌سازی با هوش مصنوعی</button>
				<?php endif; ?>
			</div>
			<div class="sap-notes" data-sap-task-notes><?php echo '' !== trim( $t['notes'] ) ? sap_rich_text( $t['notes'], sap_task_site_base( $t ) ) : '<em class="sap-muted">توضیحی ندارد.</em>'; // phpcs:ignore ?></div>
			<?php if ( '' !== $t['outcome'] ) : ?><p class="sap-task-outcome"><b>خروجی مطلوب:</b> <?php echo esc_html( $t['outcome'] ); ?></p><?php endif; ?>
		</div>

		<?php if ( '' !== $t['result'] || $t['attachments'] ) : ?>
			<div class="sap-treport">
				<span>گزارش کار<?php echo $t['spent'] ? ' · ' . esc_html( sap_num( $t['spent'], 1 ) ) . ' ساعت' : ''; ?></span>
				<?php if ( '' !== $t['result'] ) : ?><div><?php echo sap_rich_text( $t['result'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div><?php endif; ?>
				<?php if ( $t['attachments'] ) : ?>
					<div class="sap-att-list">
						<?php foreach ( $t['attachments'] as $a ) : ?>
							<?php if ( 'image' === ( $a['type'] ?? '' ) ) : ?>
								<a class="sap-att is-img" href="<?php echo esc_url( $a['url'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $a['url'] ); ?>" alt="" loading="lazy"></a>
							<?php else : ?>
								<a class="sap-att" href="<?php echo esc_url( $a['url'] ); ?>" target="_blank" rel="noopener"><?php echo sap_icon( 'link', 13 ); // phpcs:ignore ?><bdi dir="ltr"><?php echo esc_html( '' !== ( $a['name'] ?? '' ) ? $a['name'] : sap_short_url( $a['url'], 40 ) ); ?></bdi></a>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( 'review' === $t['status'] && $manager ) : ?>
			<div class="sap-tactions is-review">
				<button type="button" class="sap-btn is-primary is-small" data-sap-review="approve" data-id="<?php echo esc_attr( $t['id'] ); ?>"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>تأیید گزارش</button>
				<button type="button" class="sap-btn is-ghost is-small" data-sap-review="feedback" data-id="<?php echo esc_attr( $t['id'] ); ?>">🔁 بازخورد</button>
				<form class="sap-desk-fb" data-sap-desk-feedback hidden><textarea name="note" rows="2" placeholder="چه چیزی باید اصلاح شود؟"></textarea></form>
			</div>
		<?php elseif ( 'proposed' !== $t['status'] ) : ?>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-tactions">
				<?php sap_form_fields( 'sap_task_save', array( 'task_id' => $t['id'], '_return' => $ret ) ); ?>
				<span class="sap-tactions-label">وضعیت:</span>
				<div class="sap-seg-mini">
					<?php foreach ( $flow as $k => $label ) : ?>
						<button class="<?php echo $k === $t['status'] ? 'is-active' : ''; ?>" name="status" value="<?php echo esc_attr( $k ); ?>" <?php disabled( $k === $t['status'] ); ?>><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
				<?php if ( $manager ) : ?>
					<button class="sap-btn is-ghost is-small" name="status" value="someday" title="به فهرست «شاید بعداً»">شاید بعداً</button>
				<?php endif; ?>
			</form>
		<?php endif; ?>

		<?php sap_view( 'comments', array( 'type' => 'task', 'object_id' => $t['id'], 'project_id' => $t['project_id'] ) ); ?>

		<?php if ( $manager ) : ?>
			<details class="sap-more sap-tedit">
				<summary><?php echo sap_icon( 'settings', 14 ); // phpcs:ignore ?>ویرایش کار</summary>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
					<?php sap_form_fields( 'sap_task_save', array( 'task_id' => $t['id'], '_return' => $ret ) ); ?>
					<label>عنوان<input type="text" name="title" value="<?php echo esc_attr( $t['title'] ); ?>"></label>
					<div class="sap-grid-3">
						<label>مسئول<select name="assignee"><option value="0">—</option><?php foreach ( sap_team_users() as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( (int) $u->ID, $t['assignee'] ); ?>><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?></select></label>
						<label>ددلاین<input type="date" name="due_date" value="<?php echo esc_attr( (string) $t['due_date'] ); ?>"></label>
						<label>نوع کار<select name="context"><?php foreach ( sap_contexts() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $t['context'] ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					</div>
					<label>آدرس صفحه<input type="url" name="url" dir="ltr" value="<?php echo esc_attr( $t['url'] ); ?>"></label>
					<label>توضیحات<textarea name="notes" rows="6" data-sap-task-notes-input><?php echo esc_textarea( $t['notes'] ); ?></textarea></label>
					<details class="sap-more">
						<summary>بیشتر <small>ماه، Focus، ساعت، خروجی مطلوب، گزارش</small></summary>
						<div class="sap-grid-3">
							<label>ماه<select name="period"><option value="">بدون ماه</option><?php foreach ( sap_period_options( 6, 6 ) as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $t['period'] ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
							<label>Focus<select name="focus_id"><option value="0">—</option><?php foreach ( (array) $focuses as $f ) : ?><option value="<?php echo esc_attr( $f['id'] ); ?>" <?php selected( $f['id'], $t['focus_id'] ); ?>><?php echo esc_html( $f['name'] ); ?></option><?php endforeach; ?></select></label>
							<label>زمان (ساعت)<input type="number" name="effort" min="0.25" step="0.25" value="<?php echo esc_attr( $t['effort'] ); ?>"></label>
						</div>
						<label>خروجی مطلوب<textarea name="outcome" rows="2"><?php echo esc_textarea( $t['outcome'] ); ?></textarea></label>
						<label>گزارش کار<textarea name="result" rows="2"><?php echo esc_textarea( $t['result'] ); ?></textarea></label>
					</details>
					<div class="sap-form-foot sap-tedit-foot">
						<button class="sap-btn is-small is-primary" type="submit">ذخیره تغییرات</button>
						<button type="button" class="sap-btn is-small is-ghost<?php echo 0 === strpos( (string) $t['recurring'], 'p' ) ? ' is-on' : ''; ?>" data-sap-task-repeat="<?php echo esc_attr( $t['id'] ); ?>" title="هر ماه خودکار به اکشن پلن اضافه شود"><?php echo sap_icon( 'refresh', 13 ); // phpcs:ignore ?>تکرار ماهانه</button>
						<button type="button" class="sap-btn is-small is-ghost is-danger" data-sap-task-del="<?php echo esc_attr( $t['id'] ); ?>"><?php echo sap_icon( 'trash', 13 ); // phpcs:ignore ?>حذف</button>
					</div>
				</form>
			</details>
		<?php endif; ?>
	</div>
</details>
