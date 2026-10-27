<?php
/**
 * Your notes on an item (task, finding, idea, revision). Passed to the AI too.
 *
 * @package SeoActionPlan
 * @var string $type
 * @var int    $object_id
 * @var int    $project_id
 */

defined( 'ABSPATH' ) || exit;

$list = sap_comments( $type, $object_id );
?>
<div class="sap-comments" data-sap-comments="<?php echo esc_attr( $type . ':' . (int) $object_id . ':' . (int) $project_id ); ?>">
	<p class="sap-label"><?php echo sap_icon( 'user', 13 ); // phpcs:ignore ?>دیدگاه من <small class="sap-muted">— هوش مصنوعی در خروجی‌های بعدی این را در نظر می‌گیرد</small></p>
	<ul class="sap-comment-list">
		<?php foreach ( $list as $c ) : ?>
			<li data-id="<?php echo esc_attr( $c['id'] ); ?>">
				<p><?php echo nl2br( esc_html( $c['body'] ) ); ?></p>
				<small class="sap-muted"><?php echo esc_html( sap_user_name( $c['user_id'] ) . ' · ' . sap_jdate( $c['created_at'] ) ); ?></small>
				<button type="button" class="sap-icon-btn" data-sap-comment-del title="حذف"><?php echo sap_icon( 'trash', 13 ); // phpcs:ignore ?></button>
			</li>
		<?php endforeach; ?>
	</ul>
	<div class="sap-comment-add">
		<textarea rows="1" placeholder="نظر، اصلاح یا توضیح خودتان را بنویسید…"></textarea>
		<button type="button" class="sap-btn is-small" data-sap-comment-add>افزودن</button>
	</div>
</div>
