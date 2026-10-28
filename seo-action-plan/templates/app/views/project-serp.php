<?php
/**
 * SERP analysis before a brief: Google's top 10 for a keyword, the structure of
 * the ranking pages, and the AI content gap with our page (intent, must-cover
 * topics with coverage, gaps, angle, outline, FAQ, terms), turned into a
 * calendar article or a page update in one click.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$kw    = isset( $_GET['kw'] ) ? sanitize_text_field( wp_unslash( $_GET['kw'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$all   = (array) sap_dataset_payload( $pid, 'serp_analysis' );
uasort( $all, function ( $a, $b ) {
	return strcmp( $b['at'], $a['at'] );
} );
$a     = '' !== $kw ? ( $all[ sap_kw_key( $kw ) ] ?? null ) : ( $all ? reset( $all ) : null );
$kw    = $a ? $a['keyword'] : $kw;
$sugg  = array_slice( array_values( array_filter( sap_rank_keywords( $project ), function ( $i ) use ( $all ) {
	return ! isset( $all[ sap_kw_key( $i['keyword'] ) ] ) && ( '' !== $i['focus'] || $i['volume'] > 0 );
} ) ), 0, 10 );
$dom   = sap_project_domain( $project );
$intents = array( 'informational' => 'اطلاعاتی', 'commercial' => 'تحقیق برای خرید', 'transactional' => 'خرید', 'navigational' => 'ناوبری', 'mixed' => 'ترکیبی' );
?>
<section class="sap-panel sap-pillar-head">
	<div class="sap-pillar-intro">
		<h2><?php echo sap_icon( 'research' ); // phpcs:ignore ?>تحلیل نتایج گوگل و شکاف محتوایی</h2>
		<p class="sap-muted">قبل از نوشتن یا آپدیت هر محتوا: ۱۰ نتیجه اول گوگل گرفته می‌شود، همه صفحات برتر خوانده می‌شوند (تیترها، طول متن، اسکیما، FAQ) و با صفحه ما مقایسه می‌شوند. هوش مصنوعی نیت جستجو، موضوعاتی که همه برندگان پوشش داده‌اند، شکاف‌های صفحه ما، زاویه متمایز و سرفصل کامل را می‌دهد. این تحلیل خودکار در بریف آپدیت و بریف مقاله تقویم استفاده می‌شود.</p>
		<?php if ( ! sap_serp_ready() ) : ?>
			<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?>برای گرفتن نتایج گوگل، کلید Serper.dev یا SerpApi را در تنظیمات وارد کنید. <a href="<?php echo esc_url( sap_url( 'settings' ) . '#serp' ); ?>">تنظیمات</a></p>
		<?php endif; ?>
		<form class="sap-serp-form" data-sap-serp-form="<?php echo esc_attr( $pid ); ?>">
			<label>کلمه کلیدی<input type="text" name="keyword" value="<?php echo esc_attr( $a ? '' : $kw ); ?>" placeholder="مثلاً: کفی طبی" required></label>
			<label>صفحه ما (اختیاری)<input type="url" name="url" dir="ltr" placeholder="خالی = صفحه صاحب کلمه در نقشه کلمات"></label>
			<button class="sap-btn is-primary" type="submit" <?php disabled( ! sap_serp_ready() ); ?>><?php echo sap_icon( 'research', 16 ); // phpcs:ignore ?>تحلیل ۱۰ نتیجه اول</button>
		</form>
		<div class="sap-serp-progress" data-sap-serp-progress hidden><i></i><span></span></div>
		<?php if ( $sugg ) : ?>
			<p class="sap-serp-sugg"><span class="sap-muted sap-small">پیشنهاد (کلمات هدف بدون تحلیل):</span>
				<?php foreach ( $sugg as $s ) : ?><button type="button" class="sap-chip" data-sap-serp-pick="<?php echo esc_attr( $s['keyword'] ); ?>"><?php echo esc_html( $s['keyword'] ); ?></button><?php endforeach; ?>
			</p>
		<?php endif; ?>
	</div>
</section>

<?php if ( $all ) : ?>
	<nav class="sap-serp-history">
		<?php foreach ( array_slice( $all, 0, 20 ) as $h ) : ?>
			<a class="<?php echo $a && $h['keyword'] === $a['keyword'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'serp', array( 'kw' => $h['keyword'] ) ) ); ?>"><b><?php echo esc_html( $h['keyword'] ); ?></b><small><?php echo esc_html( sap_jdate( $h['at'], 'short' ) ); ?><?php echo $h['our_rank'] ? ' · ما: ' . esc_html( sap_num( $h['our_rank'] ) ) : ''; ?></small></a>
		<?php endforeach; ?>
	</nav>
<?php endif; ?>

<?php if ( ! $a ) : ?>
	<div class="sap-empty"><?php echo sap_icon( 'research', 32 ); // phpcs:ignore ?><p>هنوز تحلیلی نیست. یک کلمه کلیدی وارد کنید.</p></div>
	<?php return; ?>
<?php endif; ?>

<?php
$s   = $a['stats'];
$g   = (array) ( $a['gap'] ?? array() );
$org = (array) $a['serp']['organic'];
?>
<section class="sap-panel sap-serp-head">
	<div class="sap-serp-title">
		<h2>«<?php echo esc_html( $a['keyword'] ); ?>»</h2>
		<p>
			<?php if ( ! empty( $g['intent'] ) ) : ?><span class="sap-chip is-info">نیت: <?php echo esc_html( $intents[ $g['intent'] ] ?? $g['intent'] ); ?></span><?php endif; ?>
			<?php if ( ! empty( $g['page_type'] ) ) : ?><span class="sap-chip">نوع صفحه برنده: <?php echo esc_html( $g['page_type'] ); ?></span><?php endif; ?>
			<span class="sap-chip <?php echo $a['our_rank'] ? ( $a['our_rank'] <= 3 ? 'is-good' : 'is-warn' ) : 'is-soft'; ?>"><?php echo $a['our_rank'] ? 'رتبه ما: ' . esc_html( sap_num( $a['our_rank'] ) ) : 'ما در ۱۰ نتیجه اول نیستیم'; ?></span>
			<span class="sap-muted sap-small">تحلیل: <?php echo esc_html( sap_jdate( $a['at'], 'short' ) ); ?></span>
		</p>
	</div>
	<div class="sap-serp-acts">
		<button type="button" class="sap-btn is-soft" data-sap-serp-idea="<?php echo esc_attr( $pid ); ?>" data-keyword="<?php echo esc_attr( $a['keyword'] ); ?>"><?php echo sap_icon( 'plus', 14 ); // phpcs:ignore ?>مقاله جدید در تقویم با این بریف</button>
		<?php if ( $a['our_url'] ) : ?>
			<button type="button" class="sap-btn is-soft" data-sap-serp-update="<?php echo esc_attr( $pid ); ?>" data-url="<?php echo esc_attr( $a['our_url'] ); ?>" data-reason="<?php echo esc_attr( 'تحلیل ۱۰ نتیجه اول گوگل برای «' . $a['keyword'] . '»: ' . implode( '؛ ', array_slice( (array) ( $g['gaps'] ?? array() ), 0, 6 ) ) ); ?>"><?php echo sap_icon( 'refresh', 14 ); // phpcs:ignore ?>آپدیت صفحه ما در تقویم</button>
		<?php endif; ?>
		<button type="button" class="sap-btn is-ghost" data-sap-serp-rerun="<?php echo esc_attr( $pid ); ?>" data-keyword="<?php echo esc_attr( $a['keyword'] ); ?>" data-url="<?php echo esc_attr( $a['our_url'] ); ?>">تحلیل دوباره</button>
	</div>
</section>

<div class="sap-serp-stats">
	<div><span>طول متن برندگان (میانه)</span><b><?php echo esc_html( sap_num( $s['median'] ) ); ?></b><em><?php echo esc_html( sap_num( $s['min'] ) . ' تا ' . sap_num( $s['max'] ) ); ?> کلمه</em></div>
	<div><span>پیشنهاد طول ما</span><b><?php echo esc_html( sap_num( (int) ( $g['words_target'] ?? $s['median'] ) ) ); ?></b><em>کلمه<?php echo $a['ours'] ? ' · فعلی ' . esc_html( sap_num( $a['ours']['words'] ) ) : ''; ?></em></div>
	<div><span>میانگین سرفصل H2</span><b><?php echo esc_html( sap_num( $s['h2_avg'] ) ); ?></b><em><?php echo $a['ours'] ? 'ما: ' . esc_html( sap_num( count( $a['ours']['h2'] ) ) ) : ''; ?></em></div>
	<div><span>دارای FAQ</span><b><?php echo esc_html( sap_num( $s['faq'] ) . ' از ' . sap_num( $s['read'] ) ); ?></b><em><?php echo $a['ours'] ? ( $a['ours']['faq'] ? 'ما: دارد' : 'ما: ندارد' ) : ''; ?></em></div>
	<div class="is-wide"><span>اسکیمای برندگان</span><p><?php foreach ( array_slice( $s['schema'], 0, 6, true ) as $t => $n ) : ?><span class="sap-chip is-soft"><?php echo esc_html( $t . ' ×' . sap_num( $n ) ); ?></span><?php endforeach; ?></p><em>ویژگی‌های نتایج: <?php echo esc_html( implode( '، ', (array) $a['serp']['features'] ) ?: '—' ); ?></em></div>
</div>

<div class="sap-serp-grid">
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>۱۰ نتیجه اول گوگل</h2></div>
		<ol class="sap-serp-list">
			<?php foreach ( $org as $i => $o ) : ?>
				<?php $p = $a['pages'][ $i ] ?? array(); ?>
				<li class="<?php echo $o['domain'] === $dom ? 'is-us' : ''; ?>">
					<span class="sap-serp-pos"><?php echo esc_html( sap_num( $o['position'] ) ); ?></span>
					<div>
						<p class="sap-serp-domain" dir="ltr"><?php echo esc_html( $o['domain'] ); ?></p>
						<a href="<?php echo esc_url( $o['url'] ); ?>" target="_blank" rel="noopener" class="sap-serp-t"><?php echo esc_html( $o['title'] ); ?></a>
						<p class="sap-serp-meta">
							<?php if ( ! empty( $p['error'] ) ) : ?><span class="sap-muted">صفحه خوانده نشد</span>
							<?php elseif ( $p ) : ?>
								<span><?php echo esc_html( sap_num( $p['words'] ) ); ?> کلمه</span><span><?php echo esc_html( sap_num( count( $p['h2'] ) ) ); ?> H2</span><?php echo $p['faq'] ? '<span class="is-good">FAQ ✓</span>' : ''; ?><?php echo $p['schema'] ? '<span>' . esc_html( implode( '، ', array_slice( $p['schema'], 0, 3 ) ) ) . '</span>' : ''; ?>
							<?php endif; ?>
						</p>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php if ( $a['serp']['paa'] ) : ?>
			<h3 class="sap-h3">People also ask</h3>
			<ul class="sap-serp-paa"><?php foreach ( $a['serp']['paa'] as $q ) : ?><li><?php echo esc_html( $q ); ?></li><?php endforeach; ?></ul>
		<?php endif; ?>
	</section>

	<section class="sap-panel sap-serp-gap">
		<div class="sap-panel-head"><h2><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>شکاف محتوایی و نقشه شکست برندگان</h2></div>
		<?php if ( ! $g ) : ?>
			<p class="sap-muted"><?php echo esc_html( $a['gap_error'] ?? 'تحلیل هوش مصنوعی انجام نشده (کلید هوش مصنوعی را وارد کنید و «تحلیل دوباره» را بزنید).' ); ?></p>
		<?php else : ?>
			<?php if ( ! empty( $g['summary'] ) ) : ?><p class="sap-serp-summary"><?php echo esc_html( $g['summary'] ); ?></p><?php endif; ?>
			<?php if ( ! empty( $g['must_cover'] ) ) : ?>
				<h3 class="sap-h3">موضوعاتی که برندگان پوشش داده‌اند</h3>
				<ul class="sap-cover">
					<?php foreach ( $g['must_cover'] as $m ) : ?>
						<?php $c = min( (int) ( $m['coverage'] ?? 0 ), max( 1, $s['read'] ) ); ?>
						<li class="<?php echo ! empty( $m['ours'] ) ? 'is-have' : 'is-miss'; ?>"><span class="sap-cover-ico"><?php echo ! empty( $m['ours'] ) ? '✓' : '✗'; ?></span><b><?php echo esc_html( $m['topic'] ?? '' ); ?></b><i style="--w:<?php echo esc_attr( min( 100, (int) round( 100 * $c / max( 1, $s['read'] ) ) ) ); ?>%"></i><em><?php echo esc_html( sap_num( $c ) . ' از ' . sap_num( max( $s['read'], 1 ) ) ); ?></em></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $g['gaps'] ) ) : ?>
				<h3 class="sap-h3">صفحه ما این‌ها را ندارد</h3>
				<ul class="sap-gaps"><?php foreach ( $g['gaps'] as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<?php if ( ! empty( $g['angle'] ) ) : ?><div class="sap-angle"><b>زاویه متمایز:</b> <?php echo esc_html( $g['angle'] ); ?></div><?php endif; ?>
			<?php if ( ! empty( $g['outline'] ) ) : ?>
				<h3 class="sap-h3">سرفصل پیشنهادی</h3>
				<ol class="sap-outline">
					<?php foreach ( $g['outline'] as $o ) : ?>
						<li><b><?php echo esc_html( $o['h2'] ?? '' ); ?></b><?php if ( ! empty( $o['h3'] ) ) : ?><ul><?php foreach ( (array) $o['h3'] as $h3 ) : ?><li><?php echo esc_html( $h3 ); ?></li><?php endforeach; ?></ul><?php endif; ?><?php echo ! empty( $o['note'] ) ? '<small>' . esc_html( $o['note'] ) . '</small>' : ''; ?></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<div class="sap-serp-two">
				<?php if ( ! empty( $g['faq'] ) ) : ?><div><h3 class="sap-h3">FAQ</h3><ul class="sap-small"><?php foreach ( $g['faq'] as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
				<div>
					<?php if ( ! empty( $g['title'] ) ) : ?><h3 class="sap-h3">تایتل و متا</h3><p class="sap-serp-snip"><b><?php echo esc_html( $g['title'] ); ?></b><small><?php echo esc_html( $g['meta'] ?? '' ); ?></small></p><?php endif; ?>
					<?php if ( ! empty( $g['schema'] ) || ! empty( $g['formats'] ) ) : ?><p><?php foreach ( array_merge( (array) ( $g['schema'] ?? array() ), (array) ( $g['formats'] ?? array() ) ) as $x ) : ?><span class="sap-chip is-soft"><?php echo esc_html( $x ); ?></span><?php endforeach; ?></p><?php endif; ?>
				</div>
			</div>
			<?php if ( ! empty( $g['terms'] ) ) : ?><h3 class="sap-h3">اصطلاحات و موجودیت‌های لازم</h3><p class="sap-terms"><?php foreach ( $g['terms'] as $x ) : ?><span><?php echo esc_html( $x ); ?></span><?php endforeach; ?></p><?php endif; ?>
		<?php endif; ?>
	</section>
</div>
