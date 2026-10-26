<?php
/**
 * «کارهای من»: open tasks assigned to the current user, across projects.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

$uid   = get_current_user_id();
$tasks = sap_tasks( array( 'assignee' => $uid, 'status' => array( 'inbox', 'next', 'doing', 'waiting' ) ) );
$by_p  = array();
foreach ( $tasks as $t ) {
	$by_p[ $t['project_id'] ][] = $t;
}
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">اکشن پلن سئو</p>
		<h1>کارهای من</h1>
		<p class="sap-muted">کارهای باز که به شما سپرده شده‌اند. با «انجام شد»، کار در لاگ اقدامات و گزارش ماهانه ثبت می‌شود.</p>
	</div>
</header>
<?php if ( ! $tasks ) : ?>
	<div class="sap-empty"><?php echo sap_icon( 'check', 32 ); // phpcs:ignore ?><p>کار بازی ندارید.</p></div>
<?php endif; ?>
<?php
foreach ( $by_p as $pid => $list ) :
	$project = sap_project( $pid );
	if ( ! $project ) {
		continue;
	}
	$focuses = sap_focuses( $pid );
	$args    = array(
		'fnames'  => wp_list_pluck( $focuses, 'name', 'id' ),
		'focuses' => $focuses,
		'here'    => sap_url( 'my' ),
		'open_id' => 0,
	);
	?>
	<section class="sap-panel">
		<div class="sap-panel-head">
			<h2><?php echo esc_html( $project['name'] ); ?><small><?php echo esc_html( sap_num( count( $list ) ) ); ?> کار</small></h2>
			<?php if ( sap_can() ) : ?>
				<a href="<?php echo esc_url( sap_project_url( $pid, 'plan' ) ); ?>">اکشن پلن پروژه ←</a>
			<?php endif; ?>
		</div>
		<div class="sap-stack">
			<?php
			foreach ( $list as $t ) {
				sap_view( 'task-card', array_merge( $args, array( 't' => $t ) ) );
			}
			?>
		</div>
	</section>
<?php endforeach; ?>
