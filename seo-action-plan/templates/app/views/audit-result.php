<?php
/**
 * Content audit › the full analysis of one page (opened in a drawer): page
 * facts, keywords and real queries, the AI diagnosis, ready title / meta,
 * actions, update brief, WordPress data (product attributes, images with alt)
 * and the rule checks.
 *
 * @package SeoActionPlan
 * @var array      $project
 * @var array      $row     Inventory row (sap_content_inventory).
 * @var array|null $a       AI audit result of the page.
 * @var array|null $wp      Connector resolve answer (details: images, product, term).
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$types    = sap_content_types();
$verdicts = array( 'good' => array( 'خوب', 'is-good' ), 'improve' => array( 'قابل بهبود', 'is-warn' ), 'weak' => array( 'ضعیف', 'is-bad' ) );
$prio     = array( 'high' => 'فوری', 'medium' => 'متوسط', 'low' => 'کم' );
$act_lbl  = array( 'content' => 'محتوا', 'title_meta' => 'تایتل و متا', 'attributes' => 'ویژگی‌ها', 'faq' => 'FAQ', 'schema' => 'اسکیما', 'links' => 'لینک داخلی', 'cta' => 'CTA', 'images' => 'تصاویر', 'merge' => 'ادغام', 'noindex' => 'noindex' );
$details  = (array) ( $wp['details'] ?? array() );
$product  = $details['product'] ?? null;
$images   = (array) ( $details['images'] ?? array() );
$cur_t    = (string) ( $wp['seo']['seo_title'] ?? '' );
$cur_m    = (string) ( $wp['seo']['meta_description'] ?? '' );
$page_rd  = sap_dataset_payload( $pid, 'pages' )['pages'][ sap_norm_url( $row['url'] ) ] ?? null;
if ( ( '' === $cur_t || false !== strpos( $cur_t, '%' ) ) && $page_rd ) {
	$cur_t = $page_rd['title'];
}
if ( '' === $cur_m && $page_rd ) {
	$cur_m = $page_rd['meta'];
}
if ( '' === $cur_t || false !== strpos( $cur_t, '%' ) ) {
	$cur_t = (string) $row['title'];
}
$len = function ( $s, $min, $max ) {
	$n = mb_strlen( (string) $s );
	return '<small class="sap-len ' . ( $n >= $min && $n <= $max ? 'is-good' : 'is-warn' ) . '">' . esc_html( sap_num( $n ) ) . ' کاراکتر</small>';
};
?>
<div class="sap-ar">
	<header class="sap-ar-head">
		<?php if ( ! empty( $row['wp']['thumb'] ) ) : ?><img class="sap-ar-thumb" src="<?php echo esc_url( $row['wp']['thumb'] ); ?>" alt="" loading="lazy"><?php endif; ?>
		<div class="sap-ar-title">
			<span class="sap-chip"><?php echo esc_html( $types[ $row['type'] ] ?? $row['type'] ); ?></span>
			<h3><?php echo esc_html( $row['title'] ?: rawurldecode( $row['url'] ) ); ?></h3>
			<a class="sap-small" href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html( sap_short_url( $row['url'], 70 ) ); ?> ↗</a>
		</div>
		<div class="sap-ar-verdict">
			<?php if ( $a ) : ?><span class="sap-verdict-pill <?php echo esc_attr( $verdicts[ $a['verdict'] ][1] ?? 'is-warn' ); ?>"><?php echo esc_html( $verdicts[ $a['verdict'] ][0] ?? $a['verdict'] ); ?></span><?php endif; ?>
			<span class="sap-score-pill <?php echo $row['score'] >= 85 ? 'is-good' : ( $row['score'] >= 60 ? 'is-warn' : 'is-bad' ); ?>" title="امتیاز چک‌لیست خودکار"><?php echo esc_html( sap_num( $row['score'] ) ); ?></span>
		</div>
	</header>

	<div class="sap-ar-kpis">
		<div><b><?php echo esc_html( sap_num( $row['clicks'] ) ); ?></b><span>کلیک ۲۸ روز</span></div>
		<div><b><?php echo esc_html( sap_num( $row['impr'] ) ); ?></b><span>نمایش</span></div>
		<div><b><?php echo null === $row['pos'] ? '—' : esc_html( sap_num( $row['pos'], 1 ) ); ?></b><span>جایگاه کلمه اصلی</span></div>
		<div><b><?php echo null === $row['words'] ? '—' : esc_html( sap_num( $row['words'] ) ); ?></b><span>کلمه محتوا</span></div>
		<div><b><?php echo esc_html( sap_num( $images ? count( $images ) : (int) ( $row['wp']['images'] ?? 0 ) ) ); ?></b><span>تصویر</span></div>
	</div>

	<?php if ( $a && '' !== $a['diagnosis'] ) : ?>
		<div class="sap-ar-diag"><?php echo sap_icon( 'spark', 16 ); // phpcs:ignore ?><p><?php echo esc_html( $a['diagnosis'] ); ?></p></div>
	<?php elseif ( ! $a ) : ?>
		<div class="sap-ar-diag is-empty"><p>این صفحه هنوز با هوش مصنوعی تحلیل نشده؛ پایین، نتیجه چک‌لیست خودکار را می‌بینید. دکمه «تحلیل هوشمند» را بزنید.</p></div>
	<?php endif; ?>

	<section class="sap-ar-sec">
		<h4>کلمات کلیدی صفحه</h4>
		<div class="sap-ar-kw">
			<div><span class="sap-muted sap-small">کلمه اصلی</span><b><?php echo '' !== $row['kw'] ? esc_html( $row['kw'] ) : '—'; ?></b><?php if ( 'seo' === $row['kw_src'] ) : ?><small class="sap-chip">از افزونه سئو</small><?php endif; ?></div>
			<?php if ( $row['secondary'] ) : ?><div><span class="sap-muted sap-small">کلمات فرعی</span><div class="sap-chips"><?php foreach ( array_slice( $row['secondary'], 0, 12 ) as $s ) : ?><span class="sap-chip"><?php echo esc_html( $s ); ?></span><?php endforeach; ?></div></div><?php endif; ?>
		</div>
		<?php if ( $row['queries'] ) : ?>
			<table class="sap-table is-compact sap-ar-q">
				<thead><tr><th>کوئری واقعی سرچ کنسول</th><th>جایگاه</th><th>نمایش</th><th>کلیک</th></tr></thead>
				<tbody><?php foreach ( $row['queries'] as $q ) : ?><tr><td><?php echo esc_html( $q[0] ); ?></td><td><?php echo esc_html( sap_num( $q[1], 1 ) ); ?></td><td><?php echo esc_html( sap_num( $q[2] ) ); ?></td><td><?php echo esc_html( sap_num( $q[3] ) ); ?></td></tr><?php endforeach; ?></tbody>
			</table>
		<?php endif; ?>
		<?php foreach ( array_merge( $row['misfits'], $a ? (array) $a['keyword_fit'] : array() ) as $mf ) : ?>
			<p class="sap-alert is-warn sap-small">«<?php echo esc_html( $mf['keyword'] ?? '' ); ?>» برای این صفحه مناسب نیست؛ هدف <?php echo esc_html( ( $types + array( 'new_page' => 'صفحه جدید' ) )[ $mf['better'] ?? '' ] ?? 'صفحه دیگر' ); ?> است.<?php echo ! empty( $mf['why'] ) ? ' ' . esc_html( $mf['why'] ) : ''; ?></p>
		<?php endforeach; ?>
	</section>

	<?php if ( $a && ( $a['title'] || $a['meta'] ) ) : ?>
		<section class="sap-ar-sec">
			<h4>تایتل و متای پیشنهادی</h4>
			<div class="sap-ar-tm">
				<?php if ( $a['title'] ) : ?>
					<div class="sap-ar-cmp">
						<span class="sap-muted sap-small">تایتل فعلی</span><p class="is-old"><?php echo '' !== $cur_t ? esc_html( $cur_t ) : '—'; ?></p>
						<span class="sap-muted sap-small">پیشنهادی <?php echo $len( $a['title'], 30, 60 ); // phpcs:ignore ?></span>
						<p class="is-new" data-sap-copy><?php echo esc_html( $a['title'] ); ?></p>
					</div>
				<?php endif; ?>
				<?php if ( $a['meta'] ) : ?>
					<div class="sap-ar-cmp">
						<span class="sap-muted sap-small">متای فعلی</span><p class="is-old"><?php echo '' !== $cur_m ? esc_html( $cur_m ) : '—'; ?></p>
						<span class="sap-muted sap-small">پیشنهادی <?php echo $len( $a['meta'], 120, 160 ); // phpcs:ignore ?></span>
						<p class="is-new" data-sap-copy><?php echo esc_html( $a['meta'] ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<p class="sap-small sap-muted">روی متن پیشنهادی بزنید تا کپی شود.</p>
		</section>
	<?php endif; ?>

	<?php if ( $a && $a['actions'] ) : ?>
		<section class="sap-ar-sec">
			<h4>اقدامات پیشنهادی <small class="sap-muted">(در «پیشنهادها» برای تأیید شما)</small></h4>
			<ol class="sap-ar-actions">
				<?php foreach ( $a['actions'] as $act ) : ?>
					<li>
						<div class="sap-ar-act-head">
							<span class="sap-chip"><?php echo esc_html( $act_lbl[ $act['type'] ?? '' ] ?? ( $act['type'] ?? '' ) ); ?></span>
							<b><?php echo esc_html( $act['title'] ?? '' ); ?></b>
							<span class="sap-chip is-prio-<?php echo esc_attr( $act['priority'] ?? 'medium' ); ?>"><?php echo esc_html( $prio[ $act['priority'] ?? 'medium' ] ?? '' ); ?></span>
							<?php if ( ! empty( $act['hours'] ) ) : ?><small class="sap-muted"><?php echo esc_html( sap_num( (float) $act['hours'], 1 ) ); ?> ساعت</small><?php endif; ?>
						</div>
						<?php if ( ! empty( $act['detail'] ) ) : ?><p><?php echo esc_html( $act['detail'] ); ?></p><?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
	<?php endif; ?>

	<?php if ( $a && '' !== $a['update_brief'] ) : ?>
		<section class="sap-ar-sec">
			<h4>بریف آپدیت</h4>
			<div class="sap-ar-brief"><?php echo sap_rich_text( $a['update_brief'] ); // phpcs:ignore ?></div>
		</section>
	<?php endif; ?>

	<?php if ( $product ) : ?>
		<section class="sap-ar-sec">
			<h4>داده محصول در ووکامرس</h4>
			<div class="sap-ar-prod">
				<div><span>قیمت</span><b><?php echo '' !== $product['price'] ? esc_html( sap_num( (float) $product['price'] ) . ' ' . $product['currency'] ) : '<span class="sap-bad">ندارد</span>'; // phpcs:ignore ?></b></div>
				<div><span>موجودی</span><b><?php echo esc_html( array( 'instock' => 'موجود', 'outofstock' => 'ناموجود', 'onbackorder' => 'پیش‌سفارش' )[ $product['stock'] ] ?? $product['stock'] ); ?></b></div>
				<div><span>SKU</span><b dir="ltr"><?php echo '' !== $product['sku'] ? esc_html( $product['sku'] ) : '—'; ?></b></div>
				<div><span>نظرات</span><b><?php echo esc_html( sap_num( $product['reviews'] ) . ( $product['rating'] ? ' · ' . sap_num( $product['rating'], 1 ) . '★' : '' ) ); ?></b></div>
			</div>
			<p class="sap-label">ویژگی‌ها (Attributes) — <?php echo esc_html( sap_num( count( $product['attributes'] ) ) ); ?> مورد</p>
			<?php if ( $product['attributes'] ) : ?>
				<table class="sap-table is-compact"><tbody><?php foreach ( $product['attributes'] as $at ) : ?><tr><th><?php echo esc_html( $at['name'] ); ?></th><td><?php echo esc_html( implode( '، ', (array) $at['values'] ) ); ?><?php echo ! empty( $at['variation'] ) ? ' <small class="sap-chip">متغیر</small>' : ''; ?></td></tr><?php endforeach; ?></tbody></table>
			<?php else : ?>
				<p class="sap-alert is-warn sap-small">هیچ ویژگی‌ای ثبت نشده؛ جدول مشخصات برای گوگل و خریدار خالی است.</p>
			<?php endif; ?>
			<?php if ( '' !== trim( wp_strip_all_tags( (string) $product['short'] ) ) ) : ?>
				<p class="sap-label">توضیح کوتاه</p><div class="sap-ar-brief"><?php echo wp_kses( $product['short'], sap_content_allowed_html() ); // phpcs:ignore ?></div>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( $images ) : ?>
		<section class="sap-ar-sec">
			<h4>تصاویر صفحه <small class="sap-muted">(<?php echo esc_html( sap_num( count( array_filter( $images, function ( $i ) { return '' === trim( (string) $i['alt'] ); } ) ) ) ); ?> بدون Alt)</small></h4>
			<div class="sap-ar-imgs">
				<?php foreach ( array_slice( $images, 0, 24 ) as $im ) : ?>
					<figure class="<?php echo '' === trim( (string) $im['alt'] ) ? 'is-noalt' : ''; ?>">
						<img src="<?php echo esc_url( $im['src'] ); ?>" alt="" loading="lazy">
						<figcaption><?php echo '' !== trim( (string) $im['alt'] ) ? esc_html( $im['alt'] ) : 'بدون Alt'; ?></figcaption>
					</figure>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( $row['checks'] ) : ?>
		<section class="sap-ar-sec">
			<h4>چک‌لیست خودکار (<?php echo esc_html( sap_num( count( $row['checks'] ) ) ); ?> مورد)</h4>
			<ul class="sap-ar-checks"><?php foreach ( $row['checks'] as $c ) : ?><li class="<?php echo $c[1] >= 12 ? 'is-bad' : ( $c[1] >= 5 ? 'is-warn' : '' ); ?>"><?php echo esc_html( $c[0] ); ?></li><?php endforeach; ?></ul>
		</section>
	<?php endif; ?>

	<footer class="sap-ar-foot">
		<button type="button" class="sap-btn is-primary" data-sap-update-add="<?php echo esc_attr( $row['url'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'content_update', 16 ); // phpcs:ignore ?>افزودن به تقویم آپدیت</button>
		<a class="sap-btn is-soft" href="<?php echo esc_url( sap_project_url( $pid, 'content', array( 'url' => $row['url'] ) ) ); ?>"><?php echo sap_icon( 'spark', 16 ); // phpcs:ignore ?>بازنویسی متن با هایلایت تغییرات</a>
		<?php if ( ! empty( $row['wp']['id'] ) ) : ?>
			<?php $site = (string) ( $project['options']['site_url'] ?? '' ) ?: ( $project['site_url'] ?: 'https://' . sap_project_domain( $project ) . '/' ); ?>
			<a class="sap-btn is-ghost" target="_blank" rel="noopener" href="<?php echo esc_url( trailingslashit( $site ) . ( 'term' === $row['wp']['kind'] ? 'wp-admin/term.php?taxonomy=' . rawurlencode( $row['wp']['type'] ) . '&tag_ID=' . (int) $row['wp']['id'] : 'wp-admin/post.php?action=edit&post=' . (int) $row['wp']['id'] ) ); ?>">ویرایش در وردپرس ↗</a>
		<?php endif; ?>
	</footer>
</div>
