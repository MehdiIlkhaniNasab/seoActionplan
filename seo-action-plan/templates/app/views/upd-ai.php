<?php
/**
 * Result of the deep AI analysis of a page for one update strategy.
 *
 * @package SeoActionPlan
 * @var array $a
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sap-upd-ai">
	<p class="sap-upd-ai-diag"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?><?php echo esc_html( $a['diagnosis'] ?? '' ); ?></p>
	<?php foreach ( array( 'title' => 'تایتل', 'meta' => 'متا', 'h1' => 'H1', 'intro' => 'مقدمه' ) as $f => $l ) : ?>
		<?php if ( '' !== trim( (string) ( $a[ $f ] ?? '' ) ) ) : ?>
			<div class="sap-upd-ai-row"><span><?php echo esc_html( $l ); ?></span><p data-sap-copy title="برای کپی بزنید"><?php echo esc_html( $a[ $f ] ); ?></p></div>
		<?php endif; ?>
	<?php endforeach; ?>
	<?php if ( ! empty( $a['sections'] ) ) : ?>
		<p class="sap-label">بخش‌های جدید</p>
		<ul class="sap-upd-ai-sec"><?php foreach ( $a['sections'] as $sec ) : ?><li><b><?php echo esc_html( strtoupper( $sec['tag'] ) ); ?></b> «<?php echo esc_html( $sec['heading'] ); ?>» — <?php echo esc_html( $sec['what'] ); ?></li><?php endforeach; ?></ul>
	<?php endif; ?>
	<?php if ( ! empty( $a['repeat'] ) ) : ?>
		<p class="sap-small"><b>تکرار بیشتر در متن:</b> <?php echo esc_html( implode( '، ', array_map( function ( $r ) {
			return $r['keyword'] . ' (' . sap_num( $r['target'] ) . ' بار)';
		}, $a['repeat'] ) ) ); ?></p>
	<?php endif; ?>
	<?php foreach ( array( 'faq' => 'سوالات متداول', 'links_in' => 'لینک داخلی به این صفحه با انکر', 'media' => 'تصویر، ویدیو، کامنت', 'new_pages' => 'ایده محتوای جدا' ) as $f => $l ) : ?>
		<?php if ( ! empty( $a[ $f ] ) ) : ?><p class="sap-small"><b><?php echo esc_html( $l ); ?>:</b> <?php echo esc_html( implode( ' · ', (array) $a[ $f ] ) ); ?></p><?php endif; ?>
	<?php endforeach; ?>
</div>
