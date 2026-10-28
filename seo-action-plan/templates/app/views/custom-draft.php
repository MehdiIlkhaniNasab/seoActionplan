<?php
/**
 * Action plan › tasks drafted from the SEO manager's brief, for approval.
 *
 * @package SeoActionPlan
 * @var array $project
 * @var array $draft
 */

defined( 'ABSPATH' ) || exit;

$total = array_sum( array_map( function ( $t ) {
	return (float) $t['hours'];
}, $draft['tasks'] ) );
?>
<div class="sap-custom-draft">
	<p class="sap-ar-diag"><?php echo sap_icon( 'spark', 16 ); // phpcs:ignore ?><span><?php echo esc_html( $draft['summary'] ); ?><?php echo $draft['pages'] ? ' · ' . esc_html( sap_num( $draft['pages'] ) ) . ' صفحه مرتبط از وردپرس پیدا شد.' : ''; ?></span></p>
	<?php if ( ! $draft['tasks'] ) : ?>
		<p class="sap-muted">کاری ساخته نشد؛ توضیح را دقیق‌تر بنویسید.</p>
	<?php else : ?>
		<ol class="sap-ar-actions">
			<?php foreach ( $draft['tasks'] as $i => $t ) : ?>
				<li>
					<div class="sap-ar-act-head">
						<label class="sap-check"><input type="checkbox" data-sap-custom-pick value="<?php echo esc_attr( $i ); ?>" checked></label>
						<b><?php echo esc_html( $t['title'] ); ?></b>
						<span class="sap-chip"><?php echo esc_html( sap_context_label( $t['area'] ) ); ?></span>
						<?php if ( '' !== $t['role'] ) : ?><span class="sap-chip is-soft"><?php echo esc_html( $t['role'] ); ?></span><?php endif; ?>
						<small class="sap-muted"><?php echo esc_html( sap_num( (float) $t['hours'], 1 ) ); ?> ساعت · هفته <?php echo esc_html( sap_num( (int) $t['week'] ) ); ?></small>
					</div>
					<?php if ( '' !== $t['why'] ) : ?><p><?php echo esc_html( $t['why'] ); ?></p><?php endif; ?>
					<?php if ( '' !== $t['depends'] ) : ?><p class="sap-small"><b>پیش‌نیاز:</b> <?php echo esc_html( $t['depends'] ); ?></p><?php endif; ?>
					<?php if ( $t['steps'] ) : ?><details class="sap-more"><summary><?php echo esc_html( sap_num( count( $t['steps'] ) ) ); ?> مرحله</summary><ol class="sap-small"><?php foreach ( $t['steps'] as $s ) : ?><li><?php echo esc_html( $s ); ?></li><?php endforeach; ?></ol></details><?php endif; ?>
					<?php if ( $t['checklist'] ) : ?><details class="sap-more"><summary>چک‌لیست <?php echo esc_html( sap_num( count( $t['checklist'] ) ) ); ?> صفحه</summary><ul class="sap-small"><?php foreach ( $t['checklist'] as $c ) : ?><li><?php echo esc_html( $c ); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-primary" data-sap-custom-commit><?php echo sap_icon( 'check', 16 ); // phpcs:ignore ?>افزودن انتخاب‌شده‌ها به اکشن پلن</button>
			<span class="sap-muted sap-small">جمع: <?php echo esc_html( sap_num( $total, 1 ) ); ?> ساعت</span>
		</div>
	<?php endif; ?>
</div>
