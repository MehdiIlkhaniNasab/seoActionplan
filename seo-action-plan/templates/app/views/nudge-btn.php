<?php
/**
 * «یادآوری و درخواست گزارش وضعیت» button with the state of the last reminder.
 *
 * @package SeoActionPlan
 * @var array $t
 * @var bool  $compact only the button (no state line)
 */

defined( 'ABSPATH' ) || exit;

if ( ! sap_can_nudge( $t ) ) {
	return;
}
$compact = ! empty( $compact );
$open    = sap_nudge_open( $t );
?>
<span class="sap-nudge<?php echo $compact ? ' is-compact' : ''; ?>">
	<button type="button" class="sap-btn is-ghost is-small sap-nudge-btn" data-sap-nudge="<?php echo esc_attr( $t['id'] ); ?>" data-name="<?php echo esc_attr( sap_first_name( sap_user_name( $t['assignee'] ) ) ); ?>" title="اعلان در پنل و ایمیل به مسئول: وضعیت این کار را گزارش کند">📣 یادآوری و درخواست گزارش</button>
	<?php if ( ! $compact && '' !== $t['nudged_at'] ) : ?>
		<small class="sap-nudge-state<?php echo $open ? ' is-wait' : ' is-ok'; ?>" data-sap-nudge-state>
			<?php echo esc_html( 'آخرین یادآوری: ' . sap_jdate( substr( $t['nudged_at'], 0, 10 ), 'day' ) . ' ' . sap_fa( substr( $t['nudged_at'], 11, 5 ) ) . ( $open ? ' · منتظر پاسخ' : ' · پاسخ داده شد' ) ); ?>
		</small>
	<?php endif; ?>
</span>
