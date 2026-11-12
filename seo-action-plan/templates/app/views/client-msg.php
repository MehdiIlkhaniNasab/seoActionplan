<?php
/**
 * One message of the conversation with the client.
 *
 * @package SeoActionPlan
 * @var array $m {side, who, role, avatar, at, text}
 */

defined( 'ABSPATH' ) || exit;
?>
<li class="sap-ct-msg is-<?php echo esc_attr( $m['side'] ); ?>">
	<span class="sap-ct-av" aria-hidden="true"><?php echo esc_html( mb_substr( $m['who'], 0, 1 ) ); ?><?php if ( $m['avatar'] ) : ?><img src="<?php echo esc_url( $m['avatar'] ); ?>" alt="" onerror="this.remove()"><?php endif; ?></span>
	<div class="sap-ct-bubble">
		<div class="sap-ct-head"><b><?php echo esc_html( $m['who'] ); ?></b><span><?php echo esc_html( $m['role'] ); ?></span><time><?php echo esc_html( sap_jdate( $m['at'] ) . ' · ' . substr( (string) $m['at'], 11, 5 ) ); ?></time></div>
		<p><?php echo nl2br( esc_html( $m['text'] ) ); // phpcs:ignore ?></p>
	</div>
</li>
