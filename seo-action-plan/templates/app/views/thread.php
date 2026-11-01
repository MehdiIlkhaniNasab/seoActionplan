<?php
/**
 * A question / request conversation: messages as chat bubbles, then the reply box.
 *
 * @package SeoActionPlan
 * @var array  $q     questions row
 * @var string $side  manager | member
 */

defined( 'ABSPATH' ) || exit;

$msgs   = sap_thread( $q );
$side   = $side ?? 'member';
$waits  = 'open' === $q['status'];
?>
<div class="sap-thread" data-sap-thread="<?php echo esc_attr( $q['id'] ); ?>">
	<?php foreach ( $msgs as $m ) : ?>
		<?php $mine = ( 'manager' === $side ) === ! empty( $m['a'] ); ?>
		<div class="sap-msg <?php echo $mine ? 'is-mine' : 'is-other'; ?> <?php echo ! empty( $m['a'] ) ? 'is-manager' : ''; ?>">
			<?php echo sap_avatar( (int) $m['u'], 26 ); // phpcs:ignore ?>
			<div class="sap-msg-bubble">
				<span class="sap-msg-who"><?php echo esc_html( sap_user_name( (int) $m['u'] ) ?: ( ! empty( $m['a'] ) ? 'مدیر' : '' ) ); ?><time><?php echo esc_html( $m['at'] ? sap_jdate( substr( $m['at'], 0, 10 ), 'day' ) . ' · ' . sap_fa( substr( $m['at'], 11, 5 ) ) : '' ); ?></time></span>
				<div class="sap-msg-text"><?php echo sap_rich_text( (string) $m['text'] ); // phpcs:ignore ?></div>
			</div>
		</div>
	<?php endforeach; ?>
	<?php if ( 'member' === $side && $waits ) : ?>
		<p class="sap-thread-wait">⏳ منتظر پاسخ مدیر…</p>
	<?php endif; ?>
	<form class="sap-thread-form" data-sap-thread-form="<?php echo esc_attr( 'manager' === $side ? 'answer' : 'reply' ); ?>">
		<textarea name="text" rows="2" required placeholder="<?php echo esc_attr( 'manager' === $side ? 'پاسخ شما (با ایمیل و اعلان برایش می‌رود)' : ( $waits ? 'نکته‌ای اضافه کنید…' : 'سوال دیگری دارید؟ گفتگو را ادامه دهید…' ) ); ?>"></textarea>
		<button class="sap-btn is-primary is-small" type="submit"><?php echo sap_icon( 'send', 13 ); // phpcs:ignore ?><?php echo 'manager' === $side ? 'ارسال پاسخ' : 'ارسال'; ?></button>
	</form>
</div>
