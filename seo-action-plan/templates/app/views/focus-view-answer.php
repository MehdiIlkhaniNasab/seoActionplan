<?php
/**
 * The AI's answer to a «دیدگاه من» (my view) entry, with a ready focus to create.
 *
 * @package SeoActionPlan
 * @var array $project
 * @var array $v
 * @var int   $idx
 */

defined( 'ABSPATH' ) || exit;

$verdicts = array( 'agree' => array( 'موافق', 'is-good', '👍' ), 'partly' => array( 'تا حدی موافق', 'is-warn', '🤔' ), 'disagree' => array( 'مخالف', 'is-bad', '✋' ) );
$vd       = $verdicts[ $v['verdict'] ] ?? $verdicts['partly'];
$s        = $v['sug'];
$inv      = sap_site_inventory( $project['id'] );
$metrics  = sap_kpi_metrics();
?>
<article class="sap-fv">
	<blockquote class="sap-fv-q"><?php echo esc_html( $v['text'] ); ?><small><?php echo esc_html( sap_jdate( $v['at'], 'day' ) ); ?></small></blockquote>
	<div class="sap-fv-a">
		<p class="sap-fv-verdict"><span class="sap-chip <?php echo esc_attr( $vd[1] ); ?>"><?php echo esc_html( $vd[2] . ' ' . $vd[0] ); ?></span></p>
		<p><?php echo esc_html( $v['analysis'] ); ?></p>
		<dl class="sap-fv-dl">
			<?php if ( '' !== $v['why'] ) : ?><dt>چرا</dt><dd><?php echo esc_html( $v['why'] ); ?></dd><?php endif; ?>
			<?php if ( '' !== $v['impact'] ) : ?><dt>اثر احتمالی</dt><dd><?php echo esc_html( $v['impact'] ); ?></dd><?php endif; ?>
			<?php if ( '' !== $v['risks'] ) : ?><dt>مراقب باشید</dt><dd><?php echo esc_html( $v['risks'] ); ?></dd><?php endif; ?>
		</dl>
		<?php if ( '' !== $s['name'] ) : ?>
			<div class="sap-fv-sug">
				<p class="sap-label">Focus پیشنهادی</p>
				<h4><?php echo sap_icon( 'target', 16 ); // phpcs:ignore ?><?php echo esc_html( $s['name'] ); ?></h4>
				<?php if ( '' !== $s['goal'] ) : ?><p><?php echo esc_html( $s['goal'] ); ?></p><?php endif; ?>
				<div class="sap-chips">
					<?php foreach ( $s['cats'] as $u ) : ?><span class="sap-chip"><?php echo esc_html( ( $inv[ sap_norm_url( $u ) ]['title'] ?? sap_short_url( $u, 40 ) ) . ( $s['with_products'] ? ' + محصولات' : '' ) ); ?></span><?php endforeach; ?>
				</div>
				<p class="sap-small sap-muted"><?php echo esc_html( sprintf( 'شاخص: %s · هدف: %s · مهلت: %s ماه · وزن %s', $metrics[ $s['metric'] ] ?? $s['metric'], sap_num( $s['target'] ), sap_num( $s['months'] ), sap_num( $s['weight'], 1 ) ) ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php sap_form_fields( 'sap_focus_from_view', array( 'project_id' => $project['id'], 'idx' => $idx ) ); ?>
					<button type="submit" class="sap-btn is-primary is-small"><?php echo sap_icon( 'plus', 14 ); // phpcs:ignore ?>ساخت این Focus</button>
				</form>
			</div>
		<?php endif; ?>
	</div>
</article>
