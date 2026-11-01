<?php
/**
 * Notifications: answers from managers, feedback and approvals, new tasks.
 * Opening the page marks them as read.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

$uid   = get_current_user_id();
$items = array_reverse( sap_notices( $uid ) );
sap_notices_read( $uid );
$today = current_time( 'Y-m-d' );
$group = function ( $at ) use ( $today ) {
	$d = substr( (string) $at, 0, 10 );
	if ( $d === $today ) {
		return 'امروز';
	}
	if ( gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) === $d ) {
		return 'دیروز';
	}
	return sap_jdate( $d, 'long' );
};
$last = '';
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان</p>
		<h1>اعلان‌ها</h1>
		<p class="sap-muted">پاسخ سوال‌ها و درخواست‌ها، بازخورد و تأیید گزارش‌ها و کارهای تازه‌ای که به شما سپرده می‌شود.</p>
	</div>
</header>

<?php if ( ! $items ) : ?>
	<div class="sap-empty"><?php echo sap_icon( 'bell', 32 ); // phpcs:ignore ?><p>هنوز اعلانی ندارید. 🌿</p></div>
<?php else : ?>
	<section class="sap-notices">
		<?php foreach ( $items as $n ) : ?>
			<?php $g = $group( $n['at'] ); ?>
			<?php if ( $g !== $last ) : ?>
				<h3 class="sap-notices-day"><?php echo esc_html( $g ); ?></h3>
				<?php $last = $g; ?>
			<?php endif; ?>
			<a class="sap-notice<?php echo empty( $n['read'] ) ? ' is-new' : ''; ?>" href="<?php echo esc_url( $n['url'] ?: '#' ); ?>">
				<span class="sap-notice-icon"><?php echo esc_html( $n['icon'] ); ?></span>
				<span class="sap-notice-body">
					<b><?php echo esc_html( $n['title'] ); ?></b>
					<?php if ( '' !== $n['text'] ) : ?><span><?php echo esc_html( mb_strimwidth( preg_replace( '/\s+/u', ' ', $n['text'] ), 0, 220, '…' ) ); ?></span><?php endif; ?>
				</span>
				<time><?php echo esc_html( sap_fa( substr( $n['at'], 11, 5 ) ) ); ?></time>
			</a>
		<?php endforeach; ?>
	</section>
<?php endif; ?>
