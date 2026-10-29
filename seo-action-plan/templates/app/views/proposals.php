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
// Several proposals on the same page become one page card (checklist → one task).
$by_page  = function_exists( 'sap_proposals_by_page' ) && empty( $flat ) ? sap_proposals_by_page( array_filter( $items, 'sap_finding_is_page_level' ) ) : array();
$cards    = array();
foreach ( $by_page as $k => $pg ) {
	if ( '' !== $k && count( $pg['items'] ) >= 2 ) {
		$cards[ $k ] = $pg;
	}
}
if ( $cards ) {
	$in_card = array();
	foreach ( $cards as $pg ) {
		foreach ( $pg['items'] as $f ) {
			$in_card[ $f['id'] ] = true;
		}
	}
	$items = array_values( array_filter( $items, function ( $f ) use ( $in_card ) {
		return ! isset( $in_card[ $f['id'] ] );
	} ) );
}
$ckey   = ( $pager ?? 'op' ) . 'c';
$c_npg  = max( 1, (int) ceil( count( $cards ) / 6 ) );
$c_cur  = min( $c_npg, max( 1, isset( $_GET[ $ckey ] ) ? absint( $_GET[ $ckey ] ) : 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
$c_show = array_slice( $cards, ( $c_cur - 1 ) * 6, 6, true );
// Long lists are paged (15 per page) so a big site never means endless scrolling.
$all_n    = count( $items );
$pkey     = $pager ?? 'op';
$per      = 15;
$npg      = max( 1, (int) ceil( $all_n / $per ) );
$cur      = min( $npg, max( 1, isset( $_GET[ $pkey ] ) ? absint( $_GET[ $pkey ] ) : 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
$items    = array_slice( $items, ( $cur - 1 ) * $per, $per );
?>
<section class="sap-panel sap-proposals">
	<div class="sap-panel-head">
		<h2><?php echo sap_icon( 'inbox' ); // phpcs:ignore ?><?php echo esc_html( $title ); ?><small><?php echo esc_html( $cards ? sprintf( '%s صفحه با چند اقدام · %s پیشنهاد دیگر', sap_num( count( $cards ) ), sap_num( $all_n ) ) : sap_num( $all_n ) . ' پیشنهاد در انتظار تأیید' ); ?></small></h2>
		<?php if ( $cards ) : ?>
			<label class="sap-small">ماه اکشن پلن:
				<select data-sap-pp-period>
					<?php foreach ( sap_period_options( 2, 1 ) as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, sap_period_current() ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endif; ?>
	</div>
	<?php if ( $cards ) : ?>
		<p class="sap-small sap-muted">همه اقدامات هر صفحه در یک کارت است: موارد لازم را تیک بزنید و «تأیید صفحه» را بزنید تا یک کار با چک‌لیست و توضیح هر مورد به اکشن پلن برود.</p>
		<div class="sap-pagecard-list">
			<?php foreach ( $c_show as $pg ) : ?>
				<?php sap_view( 'page-card', array( 'project' => $project, 'pg' => $pg ) ); ?>
			<?php endforeach; ?>
		</div>
		<?php if ( $c_npg > 1 ) : ?>
			<nav class="sap-pager" aria-label="صفحه‌بندی کارت‌ها">
				<?php for ( $i = 1; $i <= $c_npg; $i++ ) : ?>
					<a class="<?php echo $i === $c_cur ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( $ckey, $i, $here ) ); ?>"><?php echo esc_html( sap_num( $i ) ); ?></a>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>
		<?php if ( $items ) : ?><h3 class="sap-h3">پیشنهادهای تکی و کل سایت</h3><?php endif; ?>
	<?php endif; ?>
	<?php if ( ! $items && ! $cards ) : ?>
		<p class="sap-muted"><?php echo esc_html( $empty ?? 'پیشنهادی در انتظار تأیید نیست.' ); ?></p>
	<?php elseif ( $items ) : ?>
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
				<button class="sap-btn is-ghost" type="submit" name="do" value="resolve">حل شده</button>
				<button class="sap-btn is-ghost is-danger" type="submit" name="do" value="dismiss">رد</button>
			</div>
			<ul class="sap-proposal-list">
				<?php foreach ( $items as $f ) : ?>
					<?php
					$p   = $f['evidence']['priority'] ?? ( $f['score'] >= 60 ? 'high' : ( $f['score'] >= 35 ? 'medium' : 'low' ) );
					$sec = $f['evidence']['section'] ?? ( function_exists( 'sap_tech_section_of' ) ? sap_tech_section_of( $f ) : '' );
					$ai  = 0 === strpos( $f['detector'], 'ai_' );
					$tri = $f['evidence']['triage'] ?? '';
					$tier = $f['evidence']['tier'] ?? '';
					$nc   = count( sap_comments( 'finding', $f['id'] ) );
					?>
					<li class="sap-proposal is-<?php echo esc_attr( $p ); ?>">
						<input type="checkbox" name="ids[]" value="<?php echo esc_attr( $f['id'] ); ?>" aria-label="انتخاب">
						<div class="sap-proposal-main">
							<div class="sap-proposal-top">
								<b><?php echo esc_html( $f['title'] ); ?></b>
								<span class="sap-chip is-prio-<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $prio[ $p ] ?? $p ); ?></span>
								<span class="sap-chip"><?php echo esc_html( $ai ? 'تحلیل هوشمند' : 'قانون خودکار' ); ?></span>
								<?php if ( 'minor' === $tier ) : ?><span class="sap-chip is-minor" title="<?php echo esc_attr( $tri ); ?>">جزئی — در گزارش کارفرما مهم حساب نمی‌شود</span><?php endif; ?>
								<?php if ( $sec && isset( $sections[ $sec ] ) ) : ?><span class="sap-chip is-soft"><?php echo esc_html( $sections[ $sec ] ); ?></span><?php endif; ?>
								<?php if ( $f['focus_id'] && isset( $fnames[ $f['focus_id'] ] ) ) : ?><span class="sap-chip is-focus"><?php echo esc_html( $fnames[ $f['focus_id'] ] ); ?></span><?php endif; ?>
								<span class="sap-muted sap-small"><?php echo esc_html( sap_num( $f['effort'], 1 ) ); ?> ساعت</span>
							</div>
							<?php if ( $f['url'] ) : ?>
								<div class="sap-small"><?php echo sap_page_link( $f['url'], sap_page_title( $pid, $f['url'] ) ); // phpcs:ignore ?></div>
							<?php endif; ?>
							<details>
								<summary>جزئیات و راه‌حل</summary>
								<div class="sap-proposal-detail"><?php echo sap_rich_text( $f['detail'], sap_site_base( $project ) ); // phpcs:ignore ?></div>
								<?php if ( ! empty( $f['evidence']['meta']['links'] ) ) : ?>
									<p class="sap-small">مقصد: <b><?php echo esc_html( sap_page_title( $pid, $f['url'] ) ?: sap_short_url( $f['url'], 50 ) ); ?></b> <?php echo sap_url_tag( $f['url'], 60 ); // phpcs:ignore ?></p>
									<div class="sap-table-wrap">
										<table class="sap-table sap-links-table">
											<thead><tr><th>صفحه مبدأ</th><th>انکر تکست</th><th>محل قرارگیری</th></tr></thead>
											<tbody>
												<?php foreach ( (array) $f['evidence']['meta']['links'] as $l ) : ?>
													<tr><td><b><?php echo esc_html( $l['page'] ?? '' ); ?></b><br><?php echo sap_url_tag( $l['url'] ?? '', 55 ); // phpcs:ignore ?></td><td><span class="sap-chip">«<?php echo esc_html( $l['anchor'] ?? '' ); ?>»</span></td><td class="sap-small" dir="auto"><?php echo esc_html( $l['placement'] ?? '' ); ?></td></tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
								<?php elseif ( ! empty( $f['evidence']['deliverable'] ) ) : ?>
									<div class="sap-deliverable" dir="auto"><?php echo sap_rich_text( $f['evidence']['deliverable'], sap_site_base( $project ) ); // phpcs:ignore ?></div>
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
							<?php if ( '' !== $tri ) : ?><p class="sap-small sap-proposal-triage"><?php echo sap_icon( 'spark', 13 ); // phpcs:ignore ?><span><b>بازبینی کارشناسی:</b> <?php echo esc_html( $tri ); ?></span></p><?php endif; ?>
							<details class="sap-proposal-notes" <?php echo $nc ? 'open' : ''; ?>>
								<summary>یادداشت من<?php echo $nc ? ' (' . esc_html( sap_num( $nc ) ) . ')' : ''; ?></summary>
								<?php sap_view( 'comments', array( 'type' => 'finding', 'object_id' => $f['id'], 'project_id' => $pid ) ); ?>
							</details>
						</div>
						<div class="sap-proposal-acts">
							<button class="sap-btn is-small is-soft" type="submit" name="quick" value="plan:<?php echo esc_attr( $f['id'] ); ?>" title="افزودن به اکشن پلن">تأیید</button>
							<button class="sap-btn is-small is-ghost is-good" type="submit" name="quick" value="resolve:<?php echo esc_attr( $f['id'] ); ?>" title="همین الان حل شده است؛ ۳۰ روز دوباره پیشنهاد نمی‌شود">✓ حل شد</button>
							<button class="sap-btn is-small is-ghost is-danger" type="submit" name="quick" value="dismiss:<?php echo esc_attr( $f['id'] ); ?>" title="این مورد مشکل نیست؛ حذف از پیشنهادها">حذف</button>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</form>
		<?php if ( $npg > 1 ) : ?>
			<nav class="sap-pager" aria-label="صفحه‌بندی پیشنهادها">
				<?php for ( $i = 1; $i <= $npg; $i++ ) : ?>
					<a class="<?php echo $i === $cur ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( $pkey, $i, $here ) ); ?>"><?php echo esc_html( sap_num( $i ) ); ?></a>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</section>
