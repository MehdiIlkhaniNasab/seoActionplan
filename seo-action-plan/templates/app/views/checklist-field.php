<?php
/**
 * «کار چک‌لیستی» switch + items (one per line) for task forms, with AI fill.
 *
 * @package SeoActionPlan
 * @var array $items current checklist items (may be empty)
 */

defined( 'ABSPATH' ) || exit;

$items = (array) ( $items ?? array() );
$on    = (bool) $items;
?>
<div class="sap-check-field<?php echo $on ? ' is-on' : ''; ?>" data-sap-check-field>
	<input type="hidden" name="checklist_sent" value="1">
	<label class="sap-switch">
		<input type="checkbox" name="has_checklist" value="1" data-sap-check-toggle <?php checked( $on ); ?>>
		<span class="sap-switch-track" aria-hidden="true"></span>
		<span class="sap-switch-text"><b>✅ کار چک‌لیستی</b><small class="sap-muted">مسئول هر مورد را جدا تیک می‌زند و نوار پیشرفت می‌بیند؛ با آخرین تیک، گزارش کار باز می‌شود.</small></span>
	</label>
	<div class="sap-check-items" <?php echo $on ? '' : 'hidden'; ?>>
		<textarea name="checklist" rows="5" data-sap-check-text <?php disabled( ! $on ); ?> placeholder="هر خط یک مورد؛ مثلاً:&#10;محصول ۱: پاشنه کش فلزی بلند&#10;محصول ۲: پاشنه کش پلاستیکی"><?php echo esc_textarea( implode( "\n", wp_list_pluck( $items, 't' ) ) ); ?></textarea>
		<div class="sap-check-tools">
			<button type="button" class="sap-btn is-soft is-small" data-sap-check-ai <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 13 ); // phpcs:ignore ?>ساخت موارد با هوش مصنوعی</button>
			<small class="sap-muted" data-sap-check-n></small>
		</div>
	</div>
</div>
