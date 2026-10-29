<?php
/**
 * «کارهای من»: every open task of the person across all projects in one place,
 * GTD-style (doing / next / waiting / someday), with project labels, the goal,
 * the expected output, steps, and a work report (what was done + hours) when
 * finishing. Managers can look at any member's list.
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
$open    = sap_tasks( array( 'assignee' => $uid, 'status' => array( 'inbox', 'next', 'doing', 'waiting', 'someday' ) ) );
$done    = array_values( array_filter( sap_tasks( array( 'assignee' => $uid, 'status' => 'done' ) ), function ( $t ) {
	return strtotime( (string) $t['done_at'] ) > current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS; // phpcs:ignore
} ) );
$groups  = array(
	'doing'   => array( 'در حال انجام', 'bolt' ),
	'next'    => array( 'اقدام بعدی', 'list' ),
	'waiting' => array( 'در انتظار دیگران', 'clock' ),
	'someday' => array( 'شاید بعداً', 'inbox' ),
);
$by      = array_fill_keys( array_keys( $groups ), array() );
$pcount  = array();
$overdue = 0;
foreach ( $open as $t ) {
	$g          = 'inbox' === $t['status'] ? 'next' : $t['status'];
	$by[ $g ][] = $t;
	$pcount[ $t['project_id'] ] = ( $pcount[ $t['project_id'] ] ?? 0 ) + 1;
	$overdue   += ( $t['due_date'] && $t['due_date'] < $today && 'someday' !== $t['status'] ) ? 1 : 0;
}
foreach ( $by as &$list ) {
	usort( $list, function ( $a, $b ) {
		return array( (string) ( $a['due_date'] ?: '9999' ), -$a['score'] ) <=> array( (string) ( $b['due_date'] ?: '9999' ), -$b['score'] );
	} );
}
unset( $list );
$pcache = array();
$pname  = function ( $id ) use ( &$pcache ) {
	if ( ! isset( $pcache[ $id ] ) ) {
		$pcache[ $id ] = sap_project( $id );
	}
	return $pcache[ $id ];
};
$fcache = array();
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان</p>
		<h1>کارهای <?php echo $uid === get_current_user_id() ? 'من' : esc_html( $me['name'] ?? '' ); ?></h1>
		<p class="sap-muted">همه کارهای باز در همه پروژه‌ها، به روش GTD. هر کار هدف، خروجی مطلوب و مراحلش را دارد؛ با «انجام شد» گزارش کار و زمان صرف‌شده را ثبت کنید تا در لاگ اقدامات و گزارش ماهانه بیاید.</p>
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

<div class="sap-my-stats">
	<div class="is-doing"><b><?php echo esc_html( sap_num( count( $by['doing'] ) ) ); ?></b><span>در حال انجام</span></div>
	<div><b><?php echo esc_html( sap_num( count( $by['next'] ) ) ); ?></b><span>اقدام بعدی</span></div>
	<div><b><?php echo esc_html( sap_num( count( $by['waiting'] ) ) ); ?></b><span>در انتظار</span></div>
	<div class="<?php echo $overdue ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $overdue ) ); ?></b><span>عقب از موعد</span></div>
	<div class="is-good"><b><?php echo esc_html( sap_num( count( $done ) ) ); ?></b><span>انجام‌شده ۷ روز اخیر</span></div>
</div>

<?php if ( $open ) : ?>
	<div class="sap-my-filter">
		<div class="sap-my-projects">
			<button type="button" class="is-active" data-sap-my-project="">همه پروژه‌ها <em><?php echo esc_html( sap_num( count( $open ) ) ); ?></em></button>
			<?php foreach ( $pcount as $pid => $n ) : ?>
				<?php $p = $pname( $pid ); ?>
				<?php if ( $p ) : ?><button type="button" data-sap-my-project="<?php echo esc_attr( $pid ); ?>"><i class="sap-dot" style="background:<?php echo esc_attr( sap_member_color( $pid + 3 ) ); ?>"></i><?php echo esc_html( $p['name'] ); ?> <em><?php echo esc_html( sap_num( $n ) ); ?></em></button><?php endif; ?>
			<?php endforeach; ?>
		</div>
		<input type="search" data-sap-my-search placeholder="جستجو در کارها…">
	</div>
<?php endif; ?>

<?php if ( ! $open ) : ?>
	<div class="sap-empty sap-empty-good"><?php echo sap_icon( 'check', 32 ); // phpcs:ignore ?><p>کار بازی ندارید. 🌿</p></div>
<?php endif; ?>

<?php foreach ( $groups as $g => $def ) : ?>
	<?php if ( ! $by[ $g ] ) { continue; } ?>
	<section class="sap-my-group" data-sap-my-group>
		<h2><?php echo sap_icon( $def[1], 16 ); // phpcs:ignore ?><?php echo esc_html( $def[0] ); ?> <small><?php echo esc_html( sap_num( count( $by[ $g ] ) ) ); ?></small></h2>
		<div class="sap-my-list">
			<?php foreach ( $by[ $g ] as $t ) : ?>
				<?php
				$p     = $pname( $t['project_id'] );
				if ( ! isset( $fcache[ $t['project_id'] ] ) ) {
					$fcache[ $t['project_id'] ] = array();
					foreach ( sap_focuses( $t['project_id'] ) as $f ) {
						$fcache[ $t['project_id'] ][ $f['id'] ] = $f;
					}
				}
				$focus = $fcache[ $t['project_id'] ][ $t['focus_id'] ] ?? null;
				$late  = $t['due_date'] && $t['due_date'] < $today;
				$prio  = $t['score'] >= 60 ? 'high' : ( $t['score'] >= 35 ? 'medium' : 'low' );
				?>
				<article class="sap-mytask is-<?php echo esc_attr( $prio ); ?>" id="task-<?php echo esc_attr( $t['id'] ); ?>" data-sap-mytask="<?php echo esc_attr( $t['id'] ); ?>" data-project="<?php echo esc_attr( $t['project_id'] ); ?>" data-text="<?php echo esc_attr( mb_strtolower( $t['title'] . ' ' . ( $p['name'] ?? '' ) . ' ' . $t['keyword'] ) ); ?>">
					<div class="sap-mytask-tags">
						<span class="sap-ptag" style="--c:<?php echo esc_attr( sap_member_color( $t['project_id'] + 3 ) ); ?>"><?php echo esc_html( $p['name'] ?? '' ); ?></span>
						<span class="sap-chip is-soft">@<?php echo esc_html( sap_context_label( $t['context'] ) ); ?></span>
						<?php if ( $focus ) : ?><span class="sap-chip is-focus"><?php echo sap_icon( 'target', 12 ); // phpcs:ignore ?><?php echo esc_html( $focus['name'] ); ?></span><?php endif; ?>
						<span class="sap-chip is-prio-<?php echo esc_attr( $prio ); ?>"><?php echo esc_html( array( 'high' => 'اولویت بالا', 'medium' => 'متوسط', 'low' => 'کم' )[ $prio ] ); ?></span>
						<span class="sap-muted sap-small"><?php echo esc_html( sap_num( $t['effort'], 1 ) ); ?> ساعت</span>
						<?php if ( $t['due_date'] ) : ?><span class="<?php echo $late ? 'sap-overdue' : 'sap-muted sap-small'; ?>"><?php echo sap_icon( 'clock', 12 ); // phpcs:ignore ?><?php echo esc_html( ( $late ? 'عقب افتاده · ' : 'سررسید ' ) . sap_jdate( $t['due_date'], 'short' ) ); ?></span><?php endif; ?>
					</div>
					<h3><?php echo esc_html( $t['title'] ); ?></h3>
					<div class="sap-mytask-why">
						<?php if ( $focus && '' !== (string) $focus['goal'] ) : ?><p><span>هدف</span><?php echo esc_html( $focus['goal'] ); ?></p><?php endif; ?>
						<?php if ( '' !== $t['outcome'] ) : ?><p><span>خروجی مطلوب</span><?php echo esc_html( $t['outcome'] ); ?></p><?php endif; ?>
						<?php if ( '' !== $t['waiting_for'] ) : ?><p><span>منتظرِ</span><?php echo esc_html( $t['waiting_for'] ); ?></p><?php endif; ?>
						<?php if ( '' !== $t['url'] ) : ?><p><span>صفحه</span><?php echo sap_url_tag( $t['url'], 60 ); // phpcs:ignore ?></p><?php endif; ?>
					</div>
					<?php if ( '' !== trim( $t['notes'] ) || '' !== trim( $t['ai_output'] ) ) : ?>
						<details class="sap-mytask-more">
							<summary>شرح کار، مراحل و بریف</summary>
							<?php if ( '' !== trim( $t['notes'] ) ) : ?><div class="sap-notes"><?php echo sap_rich_text( $t['notes'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div><?php endif; ?>
							<?php if ( '' !== trim( $t['ai_output'] ) ) : ?><div class="sap-deliverable" dir="auto"><?php echo sap_rich_text( $t['ai_output'], sap_task_site_base( $t ) ); // phpcs:ignore ?></div><?php endif; ?>
							<?php sap_view( 'comments', array( 'type' => 'task', 'object_id' => $t['id'], 'project_id' => $t['project_id'] ) ); ?>
						</details>
					<?php endif; ?>
					<div class="sap-mytask-acts">
						<?php if ( 'doing' !== $t['status'] ) : ?><button type="button" class="sap-btn is-small is-soft" data-sap-my-status="doing"><?php echo sap_icon( 'bolt', 13 ); // phpcs:ignore ?>شروع کار</button><?php endif; ?>
						<?php if ( 'waiting' !== $t['status'] ) : ?><button type="button" class="sap-btn is-small is-ghost" data-sap-my-status="waiting">در انتظار</button><?php endif; ?>
						<button type="button" class="sap-btn is-small is-primary" data-sap-my-done><?php echo sap_icon( 'check', 13 ); // phpcs:ignore ?>انجام شد</button>
					</div>
					<form class="sap-mytask-report" data-sap-my-report hidden>
						<label>گزارش کار — دقیقاً چه کاری انجام شد؟ <small>(در لاگ اقدامات و گزارش ماهانه کارفرما می‌آید)</small><textarea name="result" rows="3" required placeholder="مثلاً: ویژگی‌های ۱۲ محصول دسته کفی طبی ثبت شد؛ FAQ با ۵ سوال اضافه شد"><?php echo esc_textarea( $t['result'] ); ?></textarea></label>
						<div class="sap-mytask-report-row">
							<label>زمان صرف‌شده (ساعت)<input type="number" name="spent" min="0" step="0.25" value="<?php echo esc_attr( $t['spent'] ?: $t['effort'] ); ?>"></label>
							<button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>ثبت و اتمام کار</button>
							<button class="sap-btn is-ghost" type="button" data-sap-my-cancel>انصراف</button>
						</div>
					</form>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php endforeach; ?>

<?php if ( $done ) : ?>
	<details class="sap-panel sap-my-done">
		<summary><h2><?php echo sap_icon( 'check', 16 ); // phpcs:ignore ?>انجام‌شده در ۷ روز اخیر <small><?php echo esc_html( sap_num( count( $done ) ) ); ?></small></h2></summary>
		<ul>
			<?php foreach ( $done as $t ) : ?>
				<?php $p = $pname( $t['project_id'] ); ?>
				<li><span class="sap-ptag" style="--c:<?php echo esc_attr( sap_member_color( $t['project_id'] + 3 ) ); ?>"><?php echo esc_html( $p['name'] ?? '' ); ?></span> <b><?php echo esc_html( $t['title'] ); ?></b> <small class="sap-muted"><?php echo esc_html( sap_jdate( $t['done_at'], 'short' ) . ( $t['spent'] ? ' · ' . sap_num( $t['spent'], 1 ) . ' ساعت' : '' ) ); ?></small><?php echo '' !== $t['result'] ? '<br><small>' . esc_html( mb_strimwidth( $t['result'], 0, 200, '…' ) ) . '</small>' : ''; ?></li>
			<?php endforeach; ?>
		</ul>
	</details>
<?php endif; ?>
