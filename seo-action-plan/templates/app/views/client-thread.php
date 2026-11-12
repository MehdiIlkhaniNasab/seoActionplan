<?php
/**
 * The conversation with the client on a task: what the client wrote in Pilot
 * and the team's answers. What is written here is shown to the client.
 *
 * @package SeoActionPlan
 * @var array $t     The task.
 * @var bool  $open  Show the thread expanded.
 */

defined( 'ABSPATH' ) || exit;

$msgs = sap_client_messages( $t['id'] );
$can  = sap_client_can_talk( $t );
$last = $msgs ? end( $msgs ) : null;
?>
<details class="sap-ct" data-sap-ct="<?php echo esc_attr( $t['id'] ); ?>" <?php echo ! empty( $open ) || ( $last && 'client' === $last['side'] ) ? 'open' : ''; ?>>
	<summary>
		<span class="sap-ct-title">💬 گفتگو با کارفرما</span>
		<?php if ( $msgs ) : ?><span class="sap-chip is-soft"><?php echo esc_html( sap_num( count( $msgs ) ) ); ?> پیام</span><?php endif; ?>
		<?php if ( $last && 'client' === $last['side'] ) : ?><span class="sap-chip is-warn">منتظر جواب شما</span><?php endif; ?>
	</summary>
	<p class="sap-ct-hint">هر چه اینجا بنویسید، کارفرما در پایلوت می‌بیند و خبردار می‌شود. یادداشت داخلی را در «دیدگاه من» بنویسید.</p>
	<ol class="sap-ct-list" data-sap-ct-list>
		<?php foreach ( $msgs as $m ) : ?>
			<?php sap_view( 'client-msg', array( 'm' => $m ) ); ?>
		<?php endforeach; ?>
		<?php if ( ! $msgs ) : ?><li class="sap-ct-empty" data-sap-ct-empty>هنوز پیامی رد و بدل نشده است.</li><?php endif; ?>
	</ol>
	<?php if ( $can ) : ?>
		<div class="sap-ct-compose">
			<textarea rows="1" placeholder="جواب به کارفرما…" data-sap-ct-text aria-label="پیام به کارفرما"></textarea>
			<button type="button" class="sap-btn is-primary is-small" data-sap-ct-send disabled>ارسال</button>
		</div>
	<?php endif; ?>
</details>
