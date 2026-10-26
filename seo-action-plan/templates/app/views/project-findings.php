<?php
/**
 * Findings inbox: clarify each finding (plan it, someday, dismiss).
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$pid      = $project['id'];
$statuses = sap_finding_statuses();
$status   = isset( $_GET['fstatus'] ) && array_key_exists( sanitize_key( $_GET['fstatus'] ), $statuses ) ? sanitize_key( $_GET['fstatus'] ) : 'open';
$context  = isset( $_GET['context'] ) && array_key_exists( sanitize_key( $_GET['context'] ), sap_contexts() ) ? sanitize_key( $_GET['context'] ) : '';
$focus    = isset( $_GET['focus'] ) && '' !== $_GET['focus'] ? absint( $_GET['focus'] ) : '';
// phpcs:enable
$focuses  = sap_focuses( $pid );
$fnames   = wp_list_pluck( $focuses, 'name', 'id' );
$list     = sap_findings( $pid, array( 'status' => $status, 'context' => $context, 'focus_id' => $focus, 'limit' => 400 ) );
$period   = sap_period_current();
$here     = sap_project_url( $pid, 'findings', array_filter( array( 'fstatus' => $status, 'context' => $context, 'focus' => $focus ), 'strlen' ) );
?>
<div class="sap-toolbar">
	<div class="sap-seg">
		<?php foreach ( $statuses as $k => $label ) : ?>
			<a class="<?php echo $k === $status ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'findings', array_filter( array( 'fstatus' => $k, 'context' => $context, 'focus' => $focus ), 'strlen' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</div>
	<?php $fbase = sap_project_url( $pid, 'findings' ); ?>
	<form method="get" class="sap-filters" action="<?php echo esc_url( strtok( $fbase, '?' ) ); ?>">
		<?php sap_filter_form( $fbase ); ?>
		<input type="hidden" name="fstatus" value="<?php echo esc_attr( $status ); ?>">
		<select name="focus" onchange="this.form.submit()">
			<option value="">همه Focusها</option>
			<?php foreach ( $focuses as $f ) : ?>
				<option value="<?php echo esc_attr( $f['id'] ); ?>" <?php selected( $focus, $f['id'] ); ?>><?php echo esc_html( $f['name'] ); ?></option>
			<?php endforeach; ?>
			<option value="0" <?php selected( $focus, 0 ); ?>>خارج از Focus (کل سایت)</option>
		</select>
		<select name="context" onchange="this.form.submit()">
			<option value="">همه حوزه‌ها</option>
			<?php foreach ( sap_contexts() as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $context, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</form>
</div>

<?php if ( ! $list ) : ?>
	<div class="sap-empty">
		<?php echo sap_icon( 'inbox', 32 ); // phpcs:ignore ?>
		<p><?php echo 'open' === $status ? 'صندوق ورودی خالی است. اگر هنوز تحلیل نکرده‌اید، «تحلیل کامل» را بزنید.' : 'موردی نیست.'; ?></p>
	</div>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sap-findings">
		<?php sap_form_fields( 'sap_findings', array( 'project_id' => $pid, '_return' => $here ) ); ?>
		<div class="sap-bulk">
			<label class="sap-check"><input type="checkbox" data-sap-check-all> انتخاب همه</label>
			<select name="do">
				<option value="plan">افزودن به اکشن پلن</option>
				<option value="someday">شاید بعداً (Someday)</option>
				<option value="dismiss">رد کردن</option>
				<option value="reopen">بازگرداندن به صندوق ورودی</option>
			</select>
			<select name="period">
				<?php foreach ( sap_period_options( 0, 3 ) as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="sap-btn is-small" type="submit">اعمال روی انتخاب‌شده‌ها</button>
			<span class="sap-muted"><b data-sap-count="<?php echo esc_attr( count( $list ) ); ?>"><?php echo esc_html( sap_num( count( $list ) ) ); ?></b> مورد · مرتب‌شده بر اساس امتیاز اولویت</span>
		</div>

		<?php foreach ( $list as $f ) : ?>
			<details class="sap-finding">
				<summary>
					<input type="checkbox" name="ids[]" value="<?php echo esc_attr( $f['id'] ); ?>" onclick="event.stopPropagation()">
					<?php echo sap_score_badge( $f['score'] ); // phpcs:ignore ?>
					<span class="sap-finding-title"><?php echo esc_html( $f['title'] ); ?></span>
					<span class="sap-finding-meta">
						<?php echo sap_context_chip( $f['context'] ); // phpcs:ignore ?>
						<?php if ( $f['focus_id'] && isset( $fnames[ $f['focus_id'] ] ) ) : ?>
							<span class="sap-chip is-focus"><?php echo sap_icon( 'target', 12 ); // phpcs:ignore ?><?php echo esc_html( $fnames[ $f['focus_id'] ] ); ?></span>
						<?php endif; ?>
						<span class="sap-muted" title="زمان تخمینی"><?php echo esc_html( sap_num( $f['effort'], 1 ) ); ?> ساعت</span>
						<?php if ( $f['impact'] > 0 ) : ?>
							<span class="sap-muted" title="کلیک اضافه تخمینی در ماه">≈ <?php echo esc_html( sap_num( $f['impact'] ) ); ?> کلیک/ماه</span>
						<?php endif; ?>
					</span>
				</summary>
				<div class="sap-finding-body">
					<p><?php echo nl2br( esc_html( $f['detail'] ) ); ?></p>
					<?php if ( ! empty( $f['evidence']['ai']['reason'] ) ) : ?>
						<p class="sap-ai-note"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?><b>قضاوت هوش مصنوعی:</b> <?php echo esc_html( $f['evidence']['ai']['reason'] . ( $f['evidence']['ai']['action'] ? ' — ' . $f['evidence']['ai']['action'] : '' ) ); ?></p>
					<?php endif; ?>
					<?php if ( $f['url'] ) : ?>
						<p class="sap-finding-page">صفحه: <?php echo sap_page_link( $f['url'], sap_page_title( $pid, $f['url'] ) ); // phpcs:ignore ?></p>
					<?php endif; ?>
					<?php sap_evidence_table( $f['evidence'], $project['id'] ); ?>
					<div class="sap-finding-actions">
						<?php if ( 'open' === $f['status'] || 'dismissed' === $f['status'] || 'resolved' === $f['status'] ) : ?>
							<button class="sap-btn is-small is-primary" name="quick" value="plan:<?php echo esc_attr( $f['id'] ); ?>">افزودن به اکشن پلن <?php echo esc_html( sap_period_label( $period ) ); ?></button>
							<button class="sap-btn is-small" name="quick" value="someday:<?php echo esc_attr( $f['id'] ); ?>">شاید بعداً</button>
						<?php endif; ?>
						<?php if ( 'open' === $f['status'] ) : ?>
							<button class="sap-btn is-small is-ghost" name="quick" value="dismiss:<?php echo esc_attr( $f['id'] ); ?>">رد کردن</button>
						<?php elseif ( 'planned' !== $f['status'] ) : ?>
							<button class="sap-btn is-small is-ghost" name="quick" value="reopen:<?php echo esc_attr( $f['id'] ); ?>">بازگرداندن</button>
						<?php else : ?>
							<a class="sap-btn is-small" href="<?php echo esc_url( sap_project_url( $pid, 'plan', array( 'task' => $f['task_id'] ) ) ); ?>">مشاهده کار در اکشن پلن</a>
						<?php endif; ?>
						<span class="sap-muted">قانون: <?php echo esc_html( sap_detectors()[ $f['detector'] ][0] ?? $f['detector'] ); ?> · اولین مشاهده <?php echo esc_html( sap_jdate( $f['first_seen'] ) ); ?></span>
					</div>
				</div>
			</details>
		<?php endforeach; ?>
	</form>
<?php endif; ?>
