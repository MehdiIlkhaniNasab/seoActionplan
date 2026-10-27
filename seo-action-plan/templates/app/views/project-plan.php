<?php
/**
 * Monthly action plan board.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$pid    = $project['id'];
$open   = isset( $_GET['task'] ) ? sap_task( absint( $_GET['task'] ) ) : null;
$period = $open && $open['period'] ? $open['period'] : sap_period_sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ?? '' ) ) );
$ctx    = isset( $_GET['context'] ) && array_key_exists( sanitize_key( $_GET['context'] ), sap_contexts() ) ? sanitize_key( $_GET['context'] ) : '';
// phpcs:enable
$tasks   = sap_tasks( array( 'project_id' => $pid, 'period' => $period, 'context' => $ctx ) );
$someday = sap_tasks( array( 'project_id' => $pid, 'status' => 'someday' ) );
$focuses = sap_focuses( $pid );
$fnames  = wp_list_pluck( $focuses, 'name', 'id' );
$hours   = 0;
$done_h  = 0;
$cols    = array( 'inbox' => array(), 'next' => array(), 'doing' => array(), 'waiting' => array(), 'done' => array() );
$dropped = array();
foreach ( $tasks as $t ) {
	if ( 'someday' === $t['status'] ) {
		continue;
	}
	if ( 'dropped' === $t['status'] ) {
		$dropped[] = $t;
		continue;
	}
	$cols[ $t['status'] ][] = $t;
	$hours                 += $t['effort'];
	if ( 'done' === $t['status'] ) {
		$done_h += $t['effort'];
	}
}
$cap  = (float) $project['capacity_hours'];
$live = array_sum( array_map( 'count', $cols ) );
$here = sap_project_url( $pid, 'plan', array_filter( array( 'period' => $period, 'context' => $ctx ), 'strlen' ) );
$args = array( 'fnames' => $fnames, 'here' => $here, 'focuses' => $focuses, 'open_id' => $open ? $open['id'] : 0 );
?>
<div class="sap-toolbar">
	<?php $fbase = sap_project_url( $pid, 'plan' ); ?>
	<form method="get" class="sap-filters" action="<?php echo esc_url( strtok( $fbase, '?' ) ); ?>">
		<?php sap_filter_form( $fbase ); ?>
		<select name="period" onchange="this.form.submit()">
			<?php foreach ( sap_period_options( 12, 3 ) as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( $label ); ?><?php echo sap_period_current() === $k ? ' (ماه جاری)' : ''; ?></option>
			<?php endforeach; ?>
		</select>
		<select name="context" onchange="this.form.submit()">
			<option value="">همه حوزه‌ها</option>
			<?php foreach ( sap_contexts() as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $ctx, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</form>
	<div class="sap-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sap-inline">
			<?php sap_form_fields( 'sap_plan_generate', array( 'project_id' => $pid, 'period' => $period ) ); ?>
			<label class="sap-inline-label">ظرفیت <input type="number" name="capacity" min="1" value="<?php echo esc_attr( (int) $cap ); ?>" class="is-tiny"> ساعت</label>
			<button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'spark' ); // phpcs:ignore ?><?php echo $tasks ? 'تکمیل هوشمند برنامه' : 'ساخت اکشن پلن هوشمند'; ?></button>
		</form>
		<?php if ( $cols['next'] || $cols['doing'] || $cols['waiting'] || $cols['inbox'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php sap_form_fields( 'sap_plan_carry', array( 'project_id' => $pid, 'from' => $period ) ); ?>
				<button class="sap-btn" type="submit" title="کارهای ناتمام (غیر دوره‌ای) به ماه بعد منتقل می‌شوند">انتقال ناتمام‌ها به <?php echo esc_html( sap_period_label( sap_period_shift( $period, 1 ) ) ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>

<?php
// A new month has started: show what happened last month and offer to carry over / build.
$prev_p = sap_period_shift( $period, -1 );
if ( sap_period_current() === $period && ! $tasks ) :
	$prev_t    = sap_tasks( array( 'project_id' => $pid, 'period' => $prev_p ) );
	$prev_open = count( array_filter( $prev_t, function ( $t ) {
		return in_array( $t['status'], array( 'inbox', 'next', 'doing', 'waiting' ), true ) && ! $t['recurring'];
	} ) );
	$prev_done = count( array_filter( $prev_t, function ( $t ) {
		return 'done' === $t['status'];
	} ) );
	if ( $prev_t ) :
		sap_month_snapshot( $project, $prev_p );
		?>
		<section class="sap-panel sap-newmonth">
			<div>
				<b><?php echo esc_html( sap_period_label( $period ) ); ?> شروع شده و هنوز برنامه‌ای ندارد.</b>
				<p class="sap-muted sap-small"><?php echo esc_html( sprintf( '%s: %s کار از %s کار انجام شد و کارنامه آن ماه ذخیره شده (در تب «لاگ اقدامات» و گزارش سئو). کارهای انجام‌شده ماه قبل همان‌جا می‌مانند و در برنامه این ماه تکرار نمی‌شوند.', sap_period_label( $prev_p ), sap_num( $prev_done ), sap_num( count( $prev_t ) ) ) ); ?></p>
			</div>
			<div class="sap-actions">
				<?php if ( $prev_open ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php sap_form_fields( 'sap_plan_carry', array( 'project_id' => $pid, 'from' => $prev_p ) ); ?>
						<button class="sap-btn" type="submit">۱. انتقال <?php echo esc_html( sap_num( $prev_open ) ); ?> کار ناتمام به این ماه</button>
					</form>
				<?php endif; ?>
				<a class="sap-btn is-primary" href="<?php echo esc_url( sap_project_url( $pid, 'overview' ) ); ?>"><?php echo $prev_open ? '۲. ' : ''; ?>تحلیل کامل با داده‌های تازه</a>
			</div>
		</section>
		<?php
	endif;
endif;
?>

<section class="sap-panel sap-capacity">
	<div>
		<b>اکشن پلن <?php echo esc_html( sap_period_label( $period ) ); ?></b>
		<span class="sap-muted"><?php echo esc_html( sprintf( '%s کار · %s ساعت برنامه‌ریزی‌شده از ظرفیت %s ساعت · %s ساعت انجام شده', sap_num( $live ), sap_num( $hours, 1 ), sap_num( $cap ), sap_num( $done_h, 1 ) ) ); ?></span>
	</div>
	<div class="sap-progress is-cap"><i class="is-done" style="width:<?php echo esc_attr( min( 100, round( 100 * $done_h / max( 1, $cap ) ) ) ); ?>%"></i><i style="width:<?php echo esc_attr( max( 0, min( 100, round( 100 * ( $hours - $done_h ) / max( 1, $cap ) ) ) ) ); ?>%"></i></div>
	<?php if ( $hours > $cap ) : ?>
		<p class="sap-warn">برنامه از ظرفیت ماه بیشتر است؛ کارهای کم‌امتیاز را به «شاید بعداً» یا ماه بعد منتقل کنید.</p>
	<?php endif; ?>
</section>

<details class="sap-panel sap-capture">
	<summary><?php echo sap_icon( 'plus' ); // phpcs:ignore ?>ثبت کار جدید (Capture)</summary>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sap-form">
		<?php sap_form_fields( 'sap_task_save', array( 'project_id' => $pid, '_return' => $here ) ); ?>
		<div class="sap-grid-3">
			<label class="is-wide">عنوان کار<input type="text" name="title" required placeholder="مثلاً: کیورد ریسرچ زیرکلاستر اسکوتر برقی بچگانه"></label>
			<label>حوزه<select name="context"><?php foreach ( sap_contexts() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label>وضعیت<select name="status"><?php foreach ( sap_task_statuses() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, 'next' ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label>Focus<select name="focus_id"><option value="0">—</option><?php foreach ( $focuses as $f ) : ?><option value="<?php echo esc_attr( $f['id'] ); ?>"><?php echo esc_html( $f['name'] ); ?></option><?php endforeach; ?></select></label>
			<label>زمان تخمینی (ساعت)<input type="number" name="effort" min="0.25" step="0.25" value="1"></label>
			<label>مسئول<select name="assignee"><option value="0">—</option><?php foreach ( sap_team_users() as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?></select></label>
			<label>سررسید<input type="text" name="due_date" placeholder="۱۴۰۵/۰۸/۲۰"></label>
			<label>آدرس صفحه<input type="url" name="url" dir="ltr"></label>
			<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>">
		</div>
		<label>توضیحات<textarea name="notes" rows="2"></textarea></label>
		<button class="sap-btn is-primary" type="submit">ثبت</button>
	</form>
</details>

<div class="sap-bulkbar" data-sap-bulkbar>
	<label class="sap-check"><input type="checkbox" data-sap-bulk-toggle> انتخاب چندتایی</label>
	<span class="sap-bulk-tools" hidden>
		<label class="sap-check"><input type="checkbox" data-sap-bulk-all> همه کارهای این ماه</label>
		<b data-sap-bulk-n>۰</b> انتخاب‌شده
		<select data-sap-bulk-do>
			<option value="">— اقدام —</option>
			<option value="delete">حذف</option>
			<optgroup label="تغییر وضعیت"><?php foreach ( sap_task_statuses() as $k => $label ) : ?><option value="status:<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></optgroup>
			<optgroup label="انتقال به ماه"><?php foreach ( sap_period_options( 1, 3 ) as $k => $label ) : ?><option value="period:<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></optgroup>
		</select>
		<button type="button" class="sap-btn is-small is-primary" data-sap-bulk-go>اعمال</button>
	</span>
</div>

<div class="sap-board">
	<?php
	foreach ( $cols as $status => $items ) :
		if ( 'inbox' === $status && ! $items ) {
			continue;
		}
		?>
		<section class="sap-col is-<?php echo esc_attr( $status ); ?>">
			<h3><?php echo esc_html( sap_task_statuses()[ $status ] ); ?><small><?php echo esc_html( sap_num( count( $items ) ) ); ?></small></h3>
			<?php
			foreach ( $items as $t ) {
				sap_view( 'task-card', array_merge( $args, array( 't' => $t ) ) );
			}
			if ( ! $items ) {
				echo '<p class="sap-muted sap-col-empty">—</p>';
			}
			?>
		</section>
	<?php endforeach; ?>
</div>

<?php if ( $someday ) : ?>
	<details class="sap-panel">
		<summary>شاید بعداً (Someday / Maybe) — <?php echo esc_html( sap_num( count( $someday ) ) ); ?> مورد</summary>
		<div class="sap-stack">
			<?php
			foreach ( $someday as $t ) {
				sap_view( 'task-card', array_merge( $args, array( 't' => $t ) ) );
			}
			?>
		</div>
	</details>
<?php endif; ?>
<?php if ( $dropped ) : ?>
	<details class="sap-panel">
		<summary>حذف‌شده‌های این ماه — <?php echo esc_html( sap_num( count( $dropped ) ) ); ?> مورد</summary>
		<div class="sap-stack">
			<?php
			foreach ( $dropped as $t ) {
				sap_view( 'task-card', array_merge( $args, array( 't' => $t ) ) );
			}
			?>
		</div>
	</details>
<?php endif; ?>
