<?php
/**
 * Overview › the reporting cycle: which period and week we are in, progress of the
 * monthly commitments, this week's tasks, and the commitments settings.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$c     = sap_commit( $project );
$day   = sap_cycle_day( $pid );
$loc   = sap_cycle_locate( $project );
$P     = $loc['period'];
$W     = count( $loc['weeks'] );
$prog  = sap_cycle_progress( $project, $P );
$post  = esc_url( admin_url( 'admin-post.php' ) );
$users = sap_team_users();
$days  = array( 6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه' );
$built = ! empty( $project['options'][ 'cycle_built_' . $loc['week']['from'] ] );
// This week's tasks: open ones due by the end of the week (overdue included; a
// Friday deadline moves to Saturday) and the ones done this week.
$wend       = gmdate( 'Y-m-d', strtotime( $loc['week']['to'] . ' +1 day' ) );
$week_tasks = array_values( array_filter( sap_tasks( array( 'project_id' => $pid ) ), function ( $t ) use ( $loc, $wend ) {
	if ( 'dropped' === $t['status'] || ! $t['due_date'] ) {
		return false;
	}
	return 'done' === $t['status'] ? $t['due_date'] >= $loc['week']['from'] && $t['due_date'] <= $wend : $t['due_date'] <= $wend;
} ) );
usort( $week_tasks, function ( $a, $b ) {
	return array( 'done' === $a['status'] ? 1 : 0, $a['due_date'] ) <=> array( 'done' === $b['status'] ? 1 : 0, $b['due_date'] );
} );
$who = function ( $name, $label ) use ( $c, $users ) {
	?>
	<label><?php echo esc_html( $label ); ?><select name="who_<?php echo esc_attr( $name ); ?>"><option value="0">—</option><?php foreach ( $users as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( (int) $u->ID, $c['people'][ $name ] ); ?>><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?></select></label>
	<?php
};
?>
<section class="sap-panel sap-cycle" id="cycle">
	<div class="sap-cycle-head">
		<div>
			<p class="sap-eyebrow">دوره گزارش<?php echo $day > 1 ? ' · از ' . esc_html( sap_fa( $day ) ) . ' هر ماه' : ''; ?></p>
			<h2><?php echo esc_html( sap_period_label( $P ) ); ?> <small><?php echo esc_html( sap_period_dates( $P, $pid ) ); ?></small></h2>
		</div>
		<?php if ( $loc['n'] ) : ?>
			<div class="sap-cycle-weeks" title="هفته‌های کاری این دوره">
				<?php foreach ( $loc['weeks'] as $w ) : ?>
					<span class="<?php echo $w['n'] === $loc['n'] ? 'is-now' : ( $w['to'] < $loc['week']['from'] ? 'is-past' : '' ); ?>">هفته <?php echo esc_html( sap_fa( $w['n'] ) ); ?><small><?php echo esc_html( sap_jdate( $w['from'], 'day' ) ); ?></small></span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( ! $c['on'] ) : ?>
		<div class="sap-cycle-empty">
			<p><b>تعهدات ماهانه این پروژه را تعریف کنید</b> تا سکان هر هفته خودش کارها را بسازد: تعداد محتوای جدید و آپدیت، آنالیز تکنیکال هفته اول و آخر، آف‌پیج و گزارش ماهانه؛ به‌علاوه کارهای فرعی از تحلیل‌ها تا سقف ساعت هفتگی.</p>
		</div>
	<?php else : ?>
		<div class="sap-cycle-prog">
			<?php foreach ( $prog as $k => $x ) : ?>
				<?php if ( ! $x['target'] && ! $x['made'] ) { continue; } ?>
				<?php $tgt = max( $x['target'], $x['made'] ); ?>
				<div class="<?php echo $x['target'] && $x['done'] >= $x['target'] ? 'is-done' : ''; ?>">
					<span><?php echo esc_html( $x['label'] ); ?></span>
					<b><?php echo esc_html( sap_num( $x['done'] ) ); ?><small> / <?php echo esc_html( sap_num( $x['target'] ?: $x['made'] ) ); ?></small></b>
					<i style="--p:<?php echo esc_attr( $tgt ? round( 100 * $x['done'] / $tgt ) : 0 ); ?>%"></i>
					<small><?php echo esc_html( in_array( $k, array( 'new', 'upd' ), true ) ? sprintf( '%s در برنامه (دستی یا خودکار)', sap_num( $x['made'] ) ) : sprintf( '%s کار ساخته شده', sap_num( $x['made'] ) ) ); ?></small>
				</div>
			<?php endforeach; ?>
		</div>
		<?php $short = (array) ( $project['options'][ 'cycle_short_' . $P ] ?? array() ); ?>
		<?php if ( ! empty( $short['new'] ) || ! empty( $short['upd'] ) ) : ?>
			<p class="sap-alert is-warn sap-small">
				<?php if ( ! empty( $short['new'] ) ) : ?><?php echo esc_html( sprintf( '%s محتوای جدید از سهم تا این هفته در تقویم نیست؛ ', sap_num( $short['new'] ) ) ); ?><a href="<?php echo esc_url( sap_project_url( $pid, 'ideas' ) ); ?>">از ایده‌های محتوا تأیید کنید</a>. <?php endif; ?>
				<?php if ( ! empty( $short['upd'] ) ) : ?><?php echo esc_html( sprintf( '%s آپدیت از سهم تا این هفته در تقویم نیست؛ ', sap_num( $short['upd'] ) ) ); ?><a href="<?php echo esc_url( sap_project_url( $pid, 'updates' ) ); ?>">از تب آپدیت محتوا تأیید کنید</a>.<?php endif; ?>
			</p>
		<?php endif; ?>
		<div class="sap-cycle-week">
			<div class="sap-panel-head">
				<h3>کارهای این هفته <small class="sap-muted"><?php echo esc_html( sap_jdate( $loc['week']['from'], 'day' ) . ' تا ' . sap_jdate( $loc['week']['to'], 'day' ) ); ?></small></h3>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
					<?php sap_form_fields( 'sap_cycle_build', array( 'project_id' => $pid ) ); ?>
					<button class="sap-btn is-small <?php echo $built ? 'is-ghost' : 'is-primary'; ?>" type="submit"><?php echo sap_icon( 'refresh', 14 ); // phpcs:ignore ?><?php echo $built ? 'تکمیل کارهای هفته' : 'ساخت کارهای این هفته'; ?></button>
				</form>
			</div>
			<?php if ( $week_tasks ) : ?>
				<ul class="sap-cycle-tasks">
					<?php foreach ( array_slice( $week_tasks, 0, 8 ) as $t ) : ?>
						<?php $st = sap_task_state_chip( $t ); ?>
						<li class="<?php echo 'done' === $t['status'] ? 'is-done' : ''; ?>">
							<span class="sap-chip <?php echo esc_attr( $st[1] ); ?>"><?php echo esc_html( $st[0] ); ?></span>
							<b><?php echo esc_html( $t['title'] ); ?></b>
							<small><?php echo esc_html( ( sap_user_name( $t['assignee'] ) ?: 'بدون مسئول' ) . ' · ' . sap_jdate( $t['due_date'], 'day' ) ); ?><?php echo 'cycle' === $t['source'] ? ' · ' . ( 0 === strpos( $t['recurring'], 'cyc_side_' ) ? 'فرعی' : 'تعهد ماهانه' ) : ''; ?></small>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( count( $week_tasks ) > 8 ) : ?><a class="sap-small" href="<?php echo esc_url( sap_project_url( $pid, 'plan' ) ); ?>">و <?php echo esc_html( sap_num( count( $week_tasks ) - 8 ) ); ?> کار دیگر در اکشن پلن ←</a><?php endif; ?>
			<?php else : ?>
				<p class="sap-muted sap-small"><?php echo $built ? 'کاری با سررسید این هفته نیست.' : esc_html( sprintf( 'کارهای این هفته %s ساعت %s خودکار ساخته می‌شود؛ یا همین حالا بسازید.', $days[ $c['week_start'] ], sap_fa( $c['gen_hour'] ) ) ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<details class="sap-cycle-set" <?php echo $c['on'] ? '' : 'open'; ?>>
		<summary><?php echo sap_icon( 'settings', 14 ); // phpcs:ignore ?>تعهدات ماهانه و دوره گزارش</summary>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
			<?php sap_form_fields( 'sap_commit_save', array( 'project_id' => $pid ) ); ?>
			<div class="sap-grid-3">
				<label>روز شروع دوره گزارش<input type="number" name="cycle_day" min="1" max="28" value="<?php echo esc_attr( $day ); ?>"><small>۱ = اول تا آخر ماه؛ ۱۸ = هجدهم تا هفدهم ماه بعد.</small></label>
				<label>محتوای جدید در ماه<input type="number" name="content_new" min="0" max="100" value="<?php echo esc_attr( $c['content_new'] ); ?>"><small>از مقاله‌های تقویم محتوای همان دوره.</small></label>
				<label>آپدیت محتوا در ماه<input type="number" name="content_upd" min="0" max="100" value="<?php echo esc_attr( $c['content_upd'] ); ?>"><small>از آپدیت‌های برنامه‌ریزی‌شده؛ کم بود، بهترین‌ها از تب آپدیت محتوا.</small></label>
			</div>
			<div class="sap-cycle-checks">
				<label><input type="checkbox" name="tech_first" value="1" <?php checked( $c['tech_first'] ); ?>>آنالیز کامل تکنیکال در هفته اول</label>
				<label><input type="checkbox" name="tech_last" value="1" <?php checked( $c['tech_last'] ); ?>>آنالیز کامل تکنیکال در هفته آخر</label>
				<label><input type="checkbox" name="report" value="1" <?php checked( $c['report'] ); ?>>گزارش ماهانه در هفته آخر</label>
				<label><input type="checkbox" name="offpage" value="1" <?php checked( $c['offpage'] ); ?>>آف‌پیج دارد</label>
			</div>
			<div class="sap-grid-2">
				<label>بودجه آف‌پیج<input type="text" name="off_budget" value="<?php echo esc_attr( $c['off_budget'] ); ?>" placeholder="مثلاً ۱۵ میلیون تومان"></label>
				<label>کلمات هدف آف‌پیج<input type="text" name="off_kw" value="<?php echo esc_attr( $c['off_kw'] ); ?>" placeholder="کفی طبی، واکس کفش"></label>
			</div>
			<div class="sap-grid-3">
				<label>سقف ساعت کارهای فرعی در هفته<input type="number" name="side_hours" min="0" max="60" step="0.5" value="<?php echo esc_attr( $c['side_hours'] ); ?>"><small>کارهای فرعی از ایرادها و فرصت‌های تحلیل، به ترتیب امتیاز.</small></label>
				<label>روز ساخت کارهای هفته<select name="week_start"><?php foreach ( $days as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $c['week_start'] ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
				<label>ساعت ساخت<input type="number" name="gen_hour" min="0" max="23" value="<?php echo esc_attr( $c['gen_hour'] ); ?>"></label>
			</div>
			<p class="sap-label">مسئول هر بخش</p>
			<div class="sap-grid-3">
				<?php $who( 'content', 'محتوای جدید' ); ?>
				<?php $who( 'update', 'آپدیت محتوا' ); ?>
				<?php $who( 'tech', 'تکنیکال' ); ?>
				<?php $who( 'offpage', 'آف‌پیج' ); ?>
				<?php $who( 'report', 'گزارش ماهانه' ); ?>
				<?php $who( 'side', 'سایر کارهای فرعی' ); ?>
			</div>
			<div class="sap-cycle-checks">
				<label><input type="checkbox" name="assign" value="1" <?php checked( $c['assign'] ); ?>>کارها مستقیم به مسئول واگذار شوند</label>
				<label><input type="checkbox" name="on" value="1" <?php checked( $c['on'] || ! isset( $project['options']['commit'] ) ); ?>>ساخت خودکار کارهای هفتگی فعال باشد</label>
			</div>
			<button class="sap-btn is-primary" type="submit">ذخیره تعهدات</button>
		</form>
	</details>
</section>
