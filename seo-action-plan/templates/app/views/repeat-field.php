<?php
/**
 * «تکرار» select for task forms: after approval the next copy is created.
 *
 * @package SeoActionPlan
 * @var string $value current repeat_every
 */

defined( 'ABSPATH' ) || exit;

$value = (string) ( $value ?? '' );
?>
<label>تکرار<select name="repeat_every" title="بعد از تأیید هر نوبت، نوبت بعدی خودکار ساخته می‌شود">
	<option value="">بدون تکرار</option>
	<?php foreach ( sap_repeat_options() as $rk => $rl ) : ?>
		<option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $rk, $value ); ?>>🔁 <?php echo esc_html( $rl ); ?></option>
	<?php endforeach; ?>
</select></label>
