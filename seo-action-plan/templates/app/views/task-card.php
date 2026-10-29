<?php
/**
 * One task card (plan board, someday list, my tasks).
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
$overdue = $t['due_date'] && $t['due_date'] < current_time( 'Y-m-d' ) && ! in_array( $t['status'], array( 'done', 'dropped' ), true );
$post    = esc_url( admin_url( 'admin-post.php' ) );
?>
<details class="sap-task is-<?php echo esc_attr( $t['status'] ); ?>" id="task-<?php echo esc_attr( $t['id'] ); ?>" <?php echo ( (int) ( $open_id ?? 0 ) === $t['id'] ) ? 'open' : ''; ?>>
	<summary>
		<?php if ( $manager ) : ?>
			<input type="checkbox" class="sap-task-pick" value="<?php echo esc_attr( $t['id'] ); ?>" aria-label="انتخاب">
			<button type="button" class="sap-task-del" data-sap-task-del="<?php echo esc_attr( $t['id'] ); ?>" title="حذف کار" aria-label="حذف کار"><?php echo sap_icon( 'trash', 14 ); // phpcs:ignore ?></button>
			<button type="button" class="sap-task-repeat<?php echo 0 === strpos( (string) $t['recurring'], 'p' ) ? ' is-on' : ''; ?>" data-sap-task-repeat="<?php echo esc_attr( $t['id'] ); ?>" title="تکرار ماهانه: هر ماه خودکار به اکشن پلن اضافه شود" aria-label="تکرار ماهانه"><?php echo sap_icon( 'refresh', 14 ); // phpcs:ignore ?></button>
		<?php endif; ?>
		<span class="sap-task-title"><?php echo esc_html( $t['title'] ); ?></span>
		<span class="sap-task-meta">
			<?php echo sap_context_chip( $t['context'] ); // phpcs:ignore ?>
			<?php if ( $t['focus_id'] && isset( $fnames[ $t['focus_id'] ] ) ) : ?>
				<span class="sap-chip is-focus"><?php echo sap_icon( 'target', 12 ); // phpcs:ignore ?><?php echo esc_html( $fnames[ $t['focus_id'] ] ); ?></span>
			<?php endif; ?>
			<?php if ( 'ai' === $t['source'] ) : ?>
				<span class="sap-chip is-ai"><?php echo sap_icon( 'spark', 11 ); // phpcs:ignore ?>هوشمند</span>
			<?php endif; ?>
			<?php if ( $t['recurring'] ) : ?>
				<span class="sap-chip is-rec">دوره‌ای</span>
			<?php endif; ?>
			<span class="sap-muted"><?php echo esc_html( sap_num( $t['effort'], 1 ) ); ?>h</span>
			<?php
			$roi = 'done' !== $t['status'] && function_exists( 'sap_roi_estimate' ) ? sap_roi_estimate( sap_project( $t['project_id'] ), $t ) : null;
			$rb  = $roi ? sap_roi_badge( $roi ) : '';
			?>
			<?php if ( '' !== $rb ) : ?>
				<span class="sap-chip is-roi is-<?php echo esc_attr( $roi['confidence'] ); ?>" title="<?php echo esc_attr( 'پیش‌بینی اگر کار موفق شود · اطمینان: ' . array( 'low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد' )[ $roi['confidence'] ] . "\n" . implode( "\n", $roi['basis'] ) ); ?>"><?php echo sap_icon( 'chart', 11 ); // phpcs:ignore ?><?php echo esc_html( $rb ); ?></span>
			<?php endif; ?>
			<?php if ( $t['assignee'] ) : ?>
				<span class="sap-muted"><?php echo sap_icon( 'user', 12 ); // phpcs:ignore ?><?php echo esc_html( sap_user_name( $t['assignee'] ) ); ?></span>
			<?php endif; ?>
			<?php if ( $t['waiting_for'] ) : ?>
				<span class="sap-muted">منتظر: <?php echo esc_html( $t['waiting_for'] ); ?></span>
			<?php endif; ?>
			<?php if ( $t['due_date'] ) : ?>
				<span class="<?php echo $overdue ? 'sap-overdue' : 'sap-muted'; ?>"><?php echo sap_icon( 'clock', 12 ); // phpcs:ignore ?><?php echo esc_html( sap_jdate( $t['due_date'], 'day' ) ); ?></span>
			<?php endif; ?>
		</span>
	</summary>
	<div class="sap-task-body">
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-quick">
			<?php sap_form_fields( 'sap_task_save', array( 'task_id' => $t['id'], '_return' => $here . '#task-' . $t['id'] ) ); ?>
			<?php foreach ( sap_task_statuses() as $k => $label ) : ?>
				<?php if ( $k !== $t['status'] && ( $manager || in_array( $k, array( 'doing', 'waiting', 'done' ), true ) ) ) : ?>
					<button class="sap-btn is-small <?php echo 'done' === $k ? 'is-primary' : ''; ?>" name="status" value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php endif; ?>
			<?php endforeach; ?>
		</form>
		<?php if ( '' !== $rb ) : ?>
			<div class="sap-roi-box"><b><?php echo sap_icon( 'chart', 14 ); // phpcs:ignore ?>پیش‌بینی بازگشت: <?php echo esc_html( $rb ); ?></b><span class="sap-muted sap-small">اطمینان <?php echo esc_html( array( 'low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد' )[ $roi['confidence'] ] ); ?></span><ul><?php foreach ( $roi['basis'] as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul></div>
		<?php endif; ?>
		<?php if ( '' !== $t['outcome'] ) : ?>
			<p class="sap-task-outcome"><b>خروجی مطلوب:</b> <?php echo esc_html( $t['outcome'] ); ?></p>
		<?php endif; ?>
		<?php if ( $t['notes'] ) : ?>
			<div class="sap-notes"><?php echo sap_rich_text( $t['notes'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div>
		<?php endif; ?>
		<?php if ( $t['url'] ) : ?>
			<p class="sap-task-page">صفحه: <?php echo sap_page_link( $t['url'], sap_page_title( $t['project_id'], $t['url'] ) ); // phpcs:ignore ?>
				<?php if ( $manager && in_array( $t['context'], array( 'content_update', 'onpage', 'content_new', 'internal_links' ), true ) ) : ?>
					<a class="sap-btn is-small is-ai" href="<?php echo esc_url( sap_project_url( $t['project_id'], 'content', array( 'url' => $t['url'], 'task' => $t['id'] ) ) ); ?>"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>تحلیل و بازنویسی محتوا</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<div class="sap-ai-output" data-sap-ai-box <?php echo '' === trim( $t['ai_output'] ) ? 'hidden' : ''; ?>>
			<div class="sap-ai-output-head"><span><?php echo sap_icon( 'spark', 13 ); // phpcs:ignore ?>خروجی آماده</span><button type="button" class="sap-icon-btn" data-sap-copy-target title="کپی"><?php echo sap_icon( 'log', 14 ); // phpcs:ignore ?></button></div>
			<pre><?php echo esc_html( $t['ai_output'] ); ?></pre>
		</div>
		<?php if ( sap_ai_connected() && ! in_array( $t['status'], array( 'done', 'dropped' ), true ) ) : ?>
			<button type="button" class="sap-btn is-small is-ai" data-sap-ai-task="<?php echo esc_attr( $t['id'] ); ?>"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?><?php echo '' === trim( $t['ai_output'] ) ? 'بنویس برام' : 'نوشتن دوباره'; ?></button>
		<?php endif; ?>
		<?php sap_view( 'comments', array( 'type' => 'task', 'object_id' => $t['id'], 'project_id' => $t['project_id'] ) ); ?>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
			<?php sap_form_fields( 'sap_task_save', array( 'task_id' => $t['id'], '_return' => $here . '#task-' . $t['id'] ) ); ?>
			<label>نتیجه / چه کاری انجام شد (در گزارش ماهانه می‌آید)<textarea name="result" rows="2" placeholder="مثلاً: تایتل از «…» به «…» تغییر کرد؛ ۴ سوال FAQ اضافه شد"><?php echo esc_textarea( $t['result'] ); ?></textarea></label>
			<label>زمان صرف‌شده (ساعت)<input type="number" name="spent" min="0" step="0.25" value="<?php echo esc_attr( $t['spent'] ?: '' ); ?>" style="max-width:140px"></label>
			<?php if ( $manager ) : ?>
				<details class="sap-more">
				<summary>ویرایش جزئیات <small>عنوان، مسئول، سررسید، ماه، Focus…</small></summary>
				<div class="sap-grid-3">
					<label class="is-wide">عنوان<input type="text" name="title" value="<?php echo esc_attr( $t['title'] ); ?>"></label>
					<label>حوزه<select name="context"><?php foreach ( sap_contexts() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $t['context'] ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<label>وضعیت<select name="status"><?php foreach ( sap_task_statuses() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $t['status'] ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<label>ماه<select name="period"><option value="">بدون ماه</option><?php foreach ( sap_period_options( 6, 6 ) as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $t['period'] ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<label>Focus<select name="focus_id"><option value="0">—</option><?php foreach ( (array) $focuses as $f ) : ?><option value="<?php echo esc_attr( $f['id'] ); ?>" <?php selected( $f['id'], $t['focus_id'] ); ?>><?php echo esc_html( $f['name'] ); ?></option><?php endforeach; ?></select></label>
					<label>مسئول<select name="assignee"><option value="0">—</option><?php foreach ( sap_team_users() as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( (int) $u->ID, $t['assignee'] ); ?>><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?></select></label>
					<label>در انتظارِ (Waiting For)<input type="text" name="waiting_for" value="<?php echo esc_attr( $t['waiting_for'] ); ?>" placeholder="نویسنده / کارفرما / برنامه‌نویس"></label>
					<label>سررسید<input type="text" name="due_date" value="<?php echo esc_attr( $t['due_date'] ? sap_jdate( $t['due_date'], 'short' ) : '' ); ?>" placeholder="۱۴۰۵/۰۸/۲۰"></label>
					<label>زمان (ساعت)<input type="number" name="effort" min="0.25" step="0.25" value="<?php echo esc_attr( $t['effort'] ); ?>"></label>
					<label class="is-wide">آدرس صفحه<input type="url" name="url" dir="ltr" value="<?php echo esc_attr( $t['url'] ); ?>"></label>
				</div>
				<label>توضیحات<textarea name="notes" rows="3"><?php echo esc_textarea( $t['notes'] ); ?></textarea></label>
				<label>خروجی مطلوب (بهترین نتیجه این کار چیست؟)<textarea name="outcome" rows="2" placeholder="مثلاً: ۱۲ محصول با ویژگی کامل و توضیحات ۳۰۰ کلمه‌ای؛ امتیاز صفحه بالای ۸۰"><?php echo esc_textarea( $t['outcome'] ); ?></textarea></label>
				</details>
			<?php else : ?>
				<label>آدرس صفحه (مثلاً لینک مقاله منتشرشده)<input type="url" name="url" dir="ltr" value="<?php echo esc_attr( $t['url'] ); ?>"></label>
			<?php endif; ?>
			<div class="sap-form-foot">
				<button class="sap-btn is-small is-primary" type="submit">ذخیره</button>
			</div>
		</form>
		<?php if ( $manager ) : ?>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>" onsubmit="return confirm('این کار حذف شود؟')">
				<?php sap_form_fields( 'sap_task_delete', array( 'task_id' => $t['id'], '_return' => $here ) ); ?>
				<button class="sap-btn is-small is-ghost is-danger" type="submit"><?php echo sap_icon( 'trash', 14 ); // phpcs:ignore ?>حذف کار</button>
			</form>
		<?php endif; ?>
	</div>
</details>
