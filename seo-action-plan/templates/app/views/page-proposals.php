<?php
/**
 * Proposals as page cards: one card per page with all its actions as a
 * checklist, the page brief, your notes (given to the AI) and one approval
 * that adds a single task with the whole brief to the action plan.
 *
 * @package SeoActionPlan
 * @var array  $project
 * @var array  $pages   sap_proposals_by_page() result.
 * @var string $group   Content group.
 * @var string $title
 * @var string $here
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$kinds   = sap_page_action_kinds();
$site_ok = sap_site_connected( $project );
$prio  = array( 'high' => 'اولویت بالا', 'medium' => 'متوسط', 'low' => 'کم' );
$loose = $pages[''] ?? null;
unset( $pages[''] );
// phpcs:ignore WordPress.Security.NonceVerification
$pp    = max( 1, isset( $_GET['pp'] ) ? absint( $_GET['pp'] ) : 1 );
$per   = 8;
$total = count( $pages );
$npg   = max( 1, (int) ceil( $total / $per ) );
$pp    = min( $pp, $npg );
$shown = array_slice( $pages, ( $pp - 1 ) * $per, $per, true );
?>
<section class="sap-panel sap-proposals sap-pagecards">
	<div class="sap-panel-head">
		<h2><?php echo sap_icon( 'inbox' ); // phpcs:ignore ?><?php echo esc_html( $title ); ?><small><?php echo esc_html( sap_num( $total ) ); ?> صفحه در انتظار تأیید</small></h2>
		<?php if ( $total ) : ?>
			<label class="sap-small">ماه اکشن پلن:
				<select data-sap-pp-period>
					<?php foreach ( sap_period_options( 2, 1 ) as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, sap_period_current() ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endif; ?>
	</div>
	<?php if ( ! $total ) : ?>
		<p class="sap-muted">پیشنهادی برای این نوع صفحه در انتظار تأیید نیست. در «فهرست صفحات» تحلیل هوشمند را بزنید.</p>
	<?php else : ?>
		<p class="sap-small sap-muted">همه اقدامات هر صفحه در کارت خودش است. موارد لازم را تیک بزنید و «تأیید صفحه» را بزنید: یک کار با چک‌لیست و بریف کامل به اکشن پلن اضافه می‌شود<?php echo 'posts' === $group ? ' و مقاله در تقویم محتوا (آپدیت) ثبت می‌شود' : '؛ اگر محتوای صفحه تغییر می‌کند در تقویم محتوا (آپدیت) هم ثبت می‌شود'; ?>.</p>
		<div class="sap-pagecard-list">
			<?php foreach ( $shown as $k => $pg ) : ?>
				<?php sap_view( 'page-card', array( 'project' => $project, 'pg' => $pg ) ); ?>
			<?php endforeach; ?>
		</div>
		<?php if ( $npg > 1 ) : ?>
			<nav class="sap-pager" aria-label="صفحه‌بندی پیشنهادها">
				<?php for ( $i = 1; $i <= $npg; $i++ ) : ?>
					<a class="<?php echo $i === $pp ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'pp', $i, $here ) . '#proposals' ); ?>"><?php echo esc_html( sap_num( $i ) ); ?></a>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</section>
<?php
if ( $loose ) {
	sap_view( 'proposals', array(
		'project' => $project,
		'items'   => $loose['items'],
		'title'   => 'پیشنهادهای بدون صفحه مشخص',
		'here'    => $here,
		'empty'   => '',
	) );
}
