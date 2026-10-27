<?php
/**
 * Proposals awaiting approval: a list of suggestions (rules + AI) that enter
 * the monthly plan only when the specialist approves them.
 *
 * @package SeoActionPlan
 * @var array  $project
 * @var array  $items    Findings (open).
 * @var string $title
 * @var string $here     Return URL.
 * @var string $empty    Text when there is nothing.
 * @var array  $sections Optional section key => label.
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$sections = $sections ?? array();
$fnames   = wp_list_pluck( sap_focuses( $pid ), 'name', 'id' );
$prio     = array( 'high' => 'اولویت بالا', 'medium' => 'متوسط', 'low' => 'کم' );
?>
<section class="sap-panel sap-proposals">
	<div class="sap-panel-head">
		<h2><?php echo sap_icon( 'inbox' ); // phpcs:ignore ?><?php echo esc_html( $title ); ?><small><?php echo esc_html( sap_num( count( $items ) ) ); ?> پیشنهاد در انتظار تأیید</small></h2>
	</div>
	<?php if ( ! $items ) : ?>
		<p class="sap-muted"><?php echo esc_html( $empty ?? 'پیشنهادی در انتظار تأیید نیست.' ); ?></p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php sap_form_fields( 'sap_findings', array( 'project_id' => $pid, '_return' => $here ) ); ?>
			<div class="sap-proposal-bar">
				<label class="sap-check"><input type="checkbox" data-sap-check-all> انتخاب همه</label>
				<select name="period">
					<?php foreach ( sap_period_options( 2, 1 ) as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, sap_period_current() ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="sap-btn is-primary" type="submit" name="do" value="plan"><?php echo sap_icon( 'check' ); // phpcs:ignore ?>تأیید و افزودن به اکشن پلن</button>
				<button class="sap-btn is-ghost" type="submit" name="do" value="someday">شاید بعداً</button>
				<button class="sap-btn is-ghost is-danger" type="submit" name="do" value="dismiss">رد</button>
			</div>
			<ul class="sap-proposal-list">
				<?php foreach ( $items as $f ) : ?>
					<?php
					$p   = $f['evidence']['priority'] ?? ( $f['score'] >= 60 ? 'high' : ( $f['score'] >= 35 ? 'medium' : 'low' ) );
					$sec = $f['evidence']['section'] ?? ( function_exists( 'sap_tech_section_of' ) ? sap_tech_section_of( $f ) : '' );
					$ai  = 0 === strpos( $f['detector'], 'ai_' );
					?>
					<li class="sap-proposal is-<?php echo esc_attr( $p ); ?>">
						<input type="checkbox" name="ids[]" value="<?php echo esc_attr( $f['id'] ); ?>" aria-label="انتخاب">
						<div class="sap-proposal-main">
							<div class="sap-proposal-top">
								<b><?php echo esc_html( $f['title'] ); ?></b>
								<span class="sap-chip is-prio-<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $prio[ $p ] ?? $p ); ?></span>
								<span class="sap-chip"><?php echo esc_html( $ai ? 'تحلیل هوشمند' : 'قانون خودکار' ); ?></span>
								<?php if ( $sec && isset( $sections[ $sec ] ) ) : ?><span class="sap-chip is-soft"><?php echo esc_html( $sections[ $sec ] ); ?></span><?php endif; ?>
								<?php if ( $f['focus_id'] && isset( $fnames[ $f['focus_id'] ] ) ) : ?><span class="sap-chip is-focus"><?php echo esc_html( $fnames[ $f['focus_id'] ] ); ?></span><?php endif; ?>
								<span class="sap-muted sap-small"><?php echo esc_html( sap_num( $f['effort'], 1 ) ); ?> ساعت</span>
							</div>
							<?php if ( $f['url'] ) : ?>
								<div class="sap-small"><?php echo sap_page_link( $f['url'], sap_page_title( $pid, $f['url'] ) ); // phpcs:ignore ?></div>
							<?php endif; ?>
							<details>
								<summary>جزئیات و راه‌حل</summary>
								<div class="sap-proposal-detail"><?php echo nl2br( esc_html( $f['detail'] ) ); ?></div>
								<?php if ( ! empty( $f['evidence']['deliverable'] ) ) : ?>
									<pre class="sap-deliverable" dir="auto"><?php echo esc_html( $f['evidence']['deliverable'] ); ?></pre>
								<?php endif; ?>
								<?php if ( ! empty( $f['evidence']['items'] ) && is_array( $f['evidence']['items'] ) ) : ?>
									<ul class="sap-small">
										<?php foreach ( array_slice( $f['evidence']['items'], 0, 12 ) as $it ) : ?>
											<li dir="auto"><?php echo esc_html( is_array( $it ) ? implode( ' · ', array_map( function ( $v ) {
												return is_scalar( $v ) ? rawurldecode( (string) $v ) : '';
											}, array_slice( $it, 0, 4 ) ) ) : (string) $it ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</details>
						</div>
						<button class="sap-btn is-small is-soft" type="submit" name="quick" value="plan:<?php echo esc_attr( $f['id'] ); ?>">تأیید</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</form>
	<?php endif; ?>
</section>
