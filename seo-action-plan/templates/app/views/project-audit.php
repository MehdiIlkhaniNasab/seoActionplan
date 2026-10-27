<?php
/**
 * Content pillar › content audit by type (products, product taxonomies, posts,
 * post categories, pages) with rule checks, keyword-intent checks, the AI
 * audit through the focus lens, and the content proposals.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$pid    = $project['id'];
$inv    = sap_content_inventory( $project );
$groups = sap_content_groups();
$group  = isset( $_GET['group'] ) && isset( $groups[ sanitize_key( $_GET['group'] ) ] ) ? sanitize_key( $_GET['group'] ) : '';
// phpcs:enable
if ( ! $group ) {
	foreach ( $groups as $g => $def ) {
		if ( ! empty( $inv[ $g ] ) ) {
			$group = $g;
			break;
		}
	}
	$group = $group ?: 'products';
}
$rows   = $inv[ $group ] ?? array();
$audit  = (array) ( sap_dataset_payload( $pid, 'ai_audit' )['groups'][ $group ] ?? array() );
$types  = sap_content_types();
$here   = sap_project_url( $pid, 'audit', array( 'group' => $group ) );
$period = sap_period_current();
$labels = array_map( function ( $d ) {
	return $d[0];
}, $groups ) + array( 'links' => 'لینک داخلی', 'landing' => 'فرصت صفحه جدید', 'optimize' => 'بهینه‌سازی صفحه فعلی' );
?>
<section class="sap-panel sap-pillar-head">
	<div class="sap-pillar-intro">
		<h2><?php echo sap_icon( 'log' ); // phpcs:ignore ?>تحلیل محتوا بر اساس نوع صفحه</h2>
		<p class="sap-muted">هر نوع محتوا با چک‌لیست مخصوص خودش و از دید Focus و هدف فروش/لید بررسی می‌شود. کلماتی که با نیت جستجوی صفحه نمی‌خوانند (مثل کلمه عمومی خرید روی صفحه یک محصول) علامت می‌خورند. اقدامات به «پیشنهادها» می‌روند و با تأیید شما وارد اکشن پلن می‌شوند؛ هر صفحه را هم می‌توانید به بخش «آپدیت محتوا»ی تقویم بفرستید.</p>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-primary" data-sap-step="audit_<?php echo esc_attr( $group ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" data-force <?php disabled( ! sap_ai_connected() || ! $rows ); ?>><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تحلیل هوشمند <?php echo esc_html( sap_num( min( 15, count( $rows ) ) ) ); ?> صفحه پربازدید «<?php echo esc_html( $groups[ $group ][0] ); ?>»</button>
			<button type="button" class="sap-btn is-soft" data-sap-cluster-sheet="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! $project['sheet_id'] ); ?>>نوشتن کلاسترینگ در گوگل شیت</button>
			<a class="sap-btn is-ghost" href="<?php echo esc_url( sap_project_url( $pid, 'keywords' ) ); ?>">نقشه کلمات</a>
		</div>
		<?php if ( ! $project['sheet_id'] ) : ?><p class="sap-small sap-muted">برای نوشتن کلاسترینگ، اول در تب تقویم محتوا گوگل شیت پروژه را بسازید یا وصل کنید.</p><?php endif; ?>
	</div>
</section>

<nav class="sap-seg sap-audit-groups" aria-label="نوع محتوا">
	<?php foreach ( $groups as $g => $def ) : ?>
		<a class="<?php echo $g === $group ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'audit', array( 'group' => $g ) ) ); ?>"><?php echo esc_html( $def[0] ); ?> <small><?php echo esc_html( sap_num( count( $inv[ $g ] ?? array() ) ) ); ?></small></a>
	<?php endforeach; ?>
</nav>

<?php if ( ! $rows ) : ?>
	<div class="sap-empty"><p>صفحه‌ای از نوع «<?php echo esc_html( $groups[ $group ][0] ); ?>» در نقشه کلمات نیست. نقشه کلمات از صفحاتی که در سرچ کنسول نمایش دارند ساخته می‌شود.</p></div>
<?php else : ?>
	<section class="sap-panel">
		<div class="sap-table-wrap">
			<table class="sap-table sap-audit-table">
				<thead><tr><th><input type="checkbox" data-sap-audit-all aria-label="انتخاب همه"></th><th>صفحه</th><th>کلمه اصلی</th><th>جایگاه</th><th>کلیک / نمایش</th><th>کلمات</th><th>امتیاز</th><th>بررسی</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<?php $a = $audit[ sap_norm_url( $r['url'] ) ] ?? null; ?>
						<tr>
							<td><input type="checkbox" data-sap-audit-pick value="<?php echo esc_attr( $r['url'] ); ?>"></td>
							<td>
								<?php echo sap_page_link( $r['url'], $r['title'] ?: rawurldecode( $r['url'] ) ); // phpcs:ignore ?>
								<?php if ( count( $groups[ $group ][2] ) > 1 ) : ?><span class="sap-chip"><?php echo esc_html( $types[ $r['type'] ] ); ?></span><?php endif; ?>
								<?php if ( $a ) : ?>
									<details class="sap-audit-ai"><summary><span class="sap-chip <?php echo 'good' === $a['verdict'] ? 'is-good' : ( 'weak' === $a['verdict'] ? 'is-bad' : 'is-warn' ); ?>"><?php echo esc_html( array( 'good' => 'خوب', 'improve' => 'قابل بهبود', 'weak' => 'ضعیف' )[ $a['verdict'] ] ?? $a['verdict'] ); ?></span> تحلیل هوشمند</summary>
										<p class="sap-small"><?php echo esc_html( $a['diagnosis'] ); ?></p>
										<?php if ( $a['title'] ) : ?><p class="sap-small"><b>تایتل:</b> <?php echo esc_html( $a['title'] ); ?></p><?php endif; ?>
										<?php if ( $a['meta'] ) : ?><p class="sap-small"><b>متا:</b> <?php echo esc_html( $a['meta'] ); ?></p><?php endif; ?>
										<?php if ( $a['actions'] ) : ?><ul class="sap-small"><?php foreach ( $a['actions'] as $act ) : ?><li><?php echo esc_html( ( $act['title'] ?? '' ) . ( ! empty( $act['detail'] ) ? ' — ' . $act['detail'] : '' ) ); ?></li><?php endforeach; ?></ul><?php endif; ?>
										<?php if ( $a['update_brief'] ) : ?><p class="sap-small"><b>بریف آپدیت:</b> <?php echo esc_html( $a['update_brief'] ); ?></p><?php endif; ?>
									</details>
								<?php endif; ?>
							</td>
							<td>
								<?php echo '' !== $r['kw'] ? esc_html( $r['kw'] ) : '<span class="sap-muted">—</span>'; // phpcs:ignore ?>
								<?php foreach ( $r['misfits'] as $mf ) : ?>
									<span class="sap-chip is-warn" title="<?php echo esc_attr( 'نیت این کلمه مناسب ' . ( $types[ $mf['better'] ] ?? $mf['better'] ) . ' است' ); ?>">«<?php echo esc_html( $mf['keyword'] ); ?>» ← <?php echo esc_html( $types[ $mf['better'] ] ?? $mf['better'] ); ?></span>
								<?php endforeach; ?>
							</td>
							<td><?php echo null === $r['pos'] ? '—' : esc_html( sap_num( $r['pos'], 1 ) ); ?></td>
							<td class="sap-nowrap"><?php echo esc_html( sap_num( $r['clicks'] ) . ' / ' . sap_num( $r['impr'] ) ); ?></td>
							<td><?php echo null === $r['words'] ? '—' : esc_html( sap_num( $r['words'] ) ); ?></td>
							<td><span class="sap-score-pill <?php echo $r['score'] >= 85 ? 'is-good' : ( $r['score'] >= 60 ? 'is-warn' : 'is-bad' ); ?>"><?php echo esc_html( sap_num( $r['score'] ) ); ?></span></td>
							<td class="sap-small">
								<?php if ( $r['checks'] ) : ?>
									<details><summary><?php echo esc_html( sap_num( count( $r['checks'] ) ) ); ?> مورد</summary><ul><?php foreach ( $r['checks'] as $c ) : ?><li><?php echo esc_html( $c[0] ); ?></li><?php endforeach; ?></ul></details>
								<?php else : ?>
									<span class="sap-ok">✓</span>
								<?php endif; ?>
								<?php if ( ! $r['read'] ) : ?><br><span class="sap-muted">متن صفحه خوانده نشده</span><?php endif; ?>
							</td>
							<td class="sap-nowrap">
								<button type="button" class="sap-btn is-small is-soft" data-sap-audit-one="<?php echo esc_attr( $r['url'] ); ?>" data-group="<?php echo esc_attr( $group ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ai_connected() ); ?>>تحلیل</button>
								<button type="button" class="sap-btn is-small is-ghost" data-sap-update-add="<?php echo esc_attr( $r['url'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" title="افزودن به بخش آپدیت محتوای تقویم <?php echo esc_attr( sap_period_label( $period ) ); ?>">به تقویم آپدیت</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="sap-audit-bulk">
			<span class="sap-muted sap-small">انتخاب‌شده‌ها:</span>
			<select data-sap-update-period><?php foreach ( sap_period_options( 0, 2 ) as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
			<button type="button" class="sap-btn is-small" data-sap-update-bulk="<?php echo esc_attr( $pid ); ?>">افزودن به تقویم آپدیت</button>
		</div>
	</section>
<?php endif; ?>

<?php
sap_view( 'proposals', array(
	'project'  => $project,
	'items'    => sap_proposals( $pid, 'content', array( 'section' => $group ) ),
	'title'    => 'پیشنهادهای «' . $groups[ $group ][0] . '»',
	'here'     => $here,
	'sections' => $labels,
	'empty'    => 'پیشنهادی برای این نوع محتوا در انتظار تأیید نیست. «تحلیل هوشمند» را بزنید.',
) );
sap_view( 'proposals', array(
	'project'  => $project,
	'items'    => sap_proposals( $pid, 'content', array( 'filter' => function ( $f ) use ( $groups ) {
		return ! isset( $groups[ $f['evidence']['section'] ?? '' ] ) && ! sap_tech_section_of( $f );
	} ) ),
	'title'    => 'سایر پیشنهادهای محتوا (لینک داخلی، فرصت کلمات، قوانین خودکار)',
	'here'     => $here,
	'sections' => $labels,
	'empty'    => 'موردی نیست.',
) );
