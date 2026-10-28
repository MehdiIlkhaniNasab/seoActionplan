<?php
/**
 * Competitors of the project: saved list, SEO Signal gap data and content
 * ideas from what competitors rank for (never what the site already targets
 * or ranks 1–5 for).
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$comps = (array) $project['competitors'];
$cmp   = (array) ( sap_dataset_payload( $pid, 'ss_compare' )['competitors'] ?? array() );
$off   = sap_offpage_competitor_summary( (array) sap_dataset_payload( $pid, 'offpage_data' ) );
$pool  = sap_dataset_payload( $pid, 'ideas_pool' );
$from_comp = $pool ? count( array_filter( (array) $pool['blog'], function ( $t ) {
	return in_array( 'competitor', (array) $t['sources'], true );
} ) ) : 0;
?>
<section class="sap-panel">
	<div class="sap-panel-head">
		<h2><?php echo sap_icon( 'target' ); // phpcs:ignore ?>رقبای پروژه<small>همیشه ذخیره می‌شوند؛ در مقایسه سایت، ایده محتوا، ردیاب رتبه و برنامه آف‌پیج استفاده می‌شوند.</small></h2>
	</div>
	<form class="sap-comp-add" data-sap-comp-add="<?php echo esc_attr( $pid ); ?>">
		<label class="sap-field"><span>دامنه رقیب</span><input type="text" name="domain" dir="ltr" placeholder="rival.ir" required></label>
		<button type="submit" class="sap-btn is-primary">افزودن رقیب</button>
	</form>
	<?php if ( ! $comps ) : ?>
		<p class="sap-muted">هنوز رقیبی ثبت نشده. ۳ تا ۵ رقیب اصلی که در کلمات Focus شما رتبه دارند را اضافه کنید.</p>
	<?php else : ?>
		<div class="sap-table-wrap">
			<table class="sap-table">
				<thead><tr><th>رقیب</th><th>گپ کلمات</th><th>کلمات بهتر از ما</th><th>آخرین مقایسه</th><th>رپورتاژ ۹۰ روز</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $comps as $c ) : ?>
						<?php $d = $cmp[ $c ] ?? null; ?>
						<tr>
							<td dir="ltr"><a href="https://<?php echo esc_attr( $c ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $c ); ?></a></td>
							<td><?php echo $d ? esc_html( sap_num( count( (array) $d['gap'] ) ) ) : '—'; ?></td>
							<td><?php echo $d ? esc_html( sap_num( count( (array) $d['worse'] ) ) ) : '—'; ?></td>
							<td class="sap-nowrap"><?php echo $d && ! empty( $d['at'] ) ? esc_html( sap_jdate( gmdate( 'Y-m-d', (int) $d['at'] ), 'short' ) ) : 'انجام نشده'; ?></td>
							<td><?php echo isset( $off[ $c ] ) ? esc_html( sap_num( $off[ $c ]['count'] ) . ' رپورتاژ' . ( $off[ $c ]['spend'] ? ' · ' . sap_num( $off[ $c ]['spend'] ) . ' تومان' : '' ) ) : '—'; ?></td>
							<td><button type="button" class="sap-icon-btn" data-sap-comp-remove="<?php echo esc_attr( $c ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" title="حذف رقیب"><?php echo sap_icon( 'trash', 16 ); // phpcs:ignore ?></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-soft" data-sap-step="ss_compare" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ss_connected() ); ?>>مقایسه با رقبا در سئو سیگنال (<?php echo esc_html( sap_num( 4 * min( 3, count( $comps ) ) ) ); ?> واحد؛ کش ۲۵ روزه)</button>
			<button type="button" class="sap-btn is-primary" data-sap-ideas-gen="<?php echo esc_attr( $pid ); ?>" data-source="competitors" <?php disabled( ! sap_ai_connected() || ! $cmp ); ?>><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>ایده مقاله از گپ رقبا</button>
		</div>
		<p class="sap-small sap-muted">ایده‌ها فقط از کلماتی ساخته می‌شوند که رقبا رتبه دارند، سایت شما در آن‌ها رتبه ۱ تا ۵ ندارد، در نقشه کلمات/تقویم هدف‌گیری نشده‌اند و نیتشان اطلاعاتی یا مقایسه‌ای است؛ کلمات خرید به‌صورت «فرصت صفحه دسته/محصول» در پیشنهادهای محتوا می‌آیند.<?php echo $pool ? ' الان ' . esc_html( sap_num( $from_comp ) ) . ' موضوع مقاله از گپ رقبا آماده است.' : ''; ?></p>
	<?php endif; ?>
</section>

<?php
$h2h    = $comps ? sap_radar_h2h( $project ) : array( 'rows' => array(), 'summary' => array(), 'last' => '' );
$events = sap_radar_events( $pid, 200 );
$etypes = array(
	'overtake'    => array( 'جلو زدن رقیب', 'is-bad', 'down' ),
	'we_pass'     => array( 'جلو زدن ما', 'is-good', 'up' ),
	'new_page'    => array( 'صفحه جدید رقیب', 'is-info', 'plus' ),
	'page_change' => array( 'تغییر صفحه رقیب', 'is-warn', 'refresh' ),
);
$ecount = array_count_values( array_column( $events, 'type' ) );
$week   = count( array_filter( $events, function ( $e ) {
	return strtotime( $e['at'] ) >= current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS; // phpcs:ignore
} ) );
$rpage  = max( 1, (int) ( $_GET['rp'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
$rtype  = sanitize_key( $_GET['rt'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
$shown  = $rtype ? array_values( array_filter( $events, function ( $e ) use ( $rtype ) {
	return $e['type'] === $rtype;
} ) ) : $events;
$pages  = max( 1, (int) ceil( count( $shown ) / 20 ) );
$rpage  = min( $rpage, $pages );
$hrows  = array_slice( $h2h['rows'], 0, 60 );
?>
<?php if ( $comps ) : ?>
<section class="sap-panel sap-radar">
	<div class="sap-panel-head">
		<h2><?php echo sap_icon( 'radar' ); // phpcs:ignore ?>رادار رقبا<small>هر روز جایگاه رقبا روی کلمات هدف شما ثبت می‌شود؛ هر هفته نقشه سایت و صفحات رتبه‌دارشان بررسی می‌شود. هر جلو زدن روی کلمه Focus هشدار می‌دهد.</small></h2>
		<button type="button" class="sap-btn is-soft" data-sap-step="radar" data-project="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'refresh', 14 ); // phpcs:ignore ?>بررسی همین حالا</button>
	</div>

	<div class="sap-radar-cards">
		<div class="sap-radar-card is-us">
			<span>سهم دیده‌شدن ما</span><b><?php echo esc_html( sap_num( $h2h['us_sov'] ?? 0, 1 ) ); ?>٪</b>
			<em><?php echo esc_html( sap_num( $week ) ); ?> رویداد در ۷ روز اخیر</em>
		</div>
		<?php foreach ( $h2h['summary'] as $c => $s ) : ?>
			<div class="sap-radar-card">
				<span dir="ltr"><?php echo esc_html( $c ); ?></span>
				<b><?php echo esc_html( sap_num( $s['sov'], 1 ) ); ?>٪</b>
				<p><span class="is-bad" title="کلماتی که رقیب از ما بالاتر است"><?php echo esc_html( sap_num( $s['ahead'] ) ); ?> جلوتر</span><span class="is-good" title="کلماتی که ما بالاتریم"><?php echo esc_html( sap_num( $s['behind'] ) ); ?> عقب‌تر</span></p>
				<?php $tot = max( 1, $s['ahead'] + $s['behind'] ); ?>
				<i class="sap-radar-bar<?php echo $s['ahead'] + $s['behind'] ? '' : ' is-empty'; ?>"><u style="width:<?php echo esc_attr( round( 100 * $s['behind'] / $tot ) ); ?>%"></u></i>
				<em><?php echo esc_html( sap_num( $s['top10'] ) ); ?> کلمه در ۱۰ نتیجه اول<?php echo null !== $s['avg'] ? ' · میانگین ' . esc_html( sap_num( $s['avg'], 1 ) ) : ''; ?></em>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( ! $h2h['rows'] ) : ?>
		<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?>هنوز جایگاه روزانه رقبا ثبت نشده. با اتصال سئو سیگنال، هر روز صبح رتبه شما و رقبا روی کلمات هدف ذخیره می‌شود (مرحله «ردیابی رتبه» در همگام‌سازی).</p>
	<?php else : ?>
		<h3 class="sap-h3">رودررو روی کلمات هدف <small class="sap-muted">(آخرین ثبت: <?php echo esc_html( sap_jdate( $h2h['last'], 'short' ) ); ?>)</small></h3>
		<div class="sap-table-wrap">
			<table class="sap-table sap-h2h">
				<thead><tr><th>کلمه</th><th>ما</th><?php foreach ( $comps as $c ) : ?><th dir="ltr" title="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( sap_short_url( $c, 18 ) ); ?></th><?php endforeach; ?></tr></thead>
				<tbody>
					<?php foreach ( $hrows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['keyword'] ); ?><?php echo '' !== $r['focus'] ? ' <span class="sap-chip is-focus">' . esc_html( $r['focus'] ) . '</span>' : ''; ?><?php echo $r['volume'] ? ' <small class="sap-muted">' . esc_html( sap_num( $r['volume'] ) ) . '</small>' : ''; ?></td>
							<td><b class="sap-h2h-pos is-us"><?php echo null === $r['us'] ? '—' : esc_html( sap_num( $r['us'] ) ); ?></b></td>
							<?php foreach ( $comps as $c ) : ?>
								<?php
								$p   = $r['comps'][ $c ] ?? null;
								$cls = null === $p ? 'is-none' : ( null === $r['us'] || $p < $r['us'] ? 'is-ahead' : ( $p > $r['us'] ? 'is-behind' : 'is-tie' ) );
								?>
								<td><b class="sap-h2h-pos <?php echo esc_attr( $cls ); ?>"><?php echo null === $p ? '—' : esc_html( sap_num( $p ) ); ?></b></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="sap-small sap-muted">قرمز = رقیب از ما بالاتر است؛ سبز = ما بالاتریم؛ خاکستری = هم‌رتبه یا بدون رتبه. <?php echo count( $h2h['rows'] ) > 60 ? esc_html( sap_num( 60 ) . ' کلمه از ' . sap_num( count( $h2h['rows'] ) ) . ' نمایش داده شد (کلمات Focus اول).' ) : ''; ?></p>
	<?php endif; ?>

	<div class="sap-radar-feed-head">
		<h3 class="sap-h3">رویدادهای رادار</h3>
		<nav class="sap-seg">
			<a class="<?php echo '' === $rtype ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'competitors' ) . '#radar' ); ?>">همه <small><?php echo esc_html( sap_num( count( $events ) ) ); ?></small></a>
			<?php foreach ( $etypes as $k => $et ) : ?>
				<a class="<?php echo $rtype === $k ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'competitors', array( 'rt' => $k ) ) . '#radar' ); ?>"><?php echo esc_html( $et[0] ); ?> <small><?php echo esc_html( sap_num( $ecount[ $k ] ?? 0 ) ); ?></small></a>
			<?php endforeach; ?>
		</nav>
	</div>
	<?php if ( ! $shown ) : ?>
		<p class="sap-muted" id="radar">هنوز رویدادی ثبت نشده. رادار هر روز جلو زدن‌ها و هر شنبه صفحات تازه و تغییر صفحات رقبا را بررسی می‌کند.</p>
	<?php else : ?>
		<ol class="sap-radar-feed" id="radar">
			<?php foreach ( array_slice( $shown, ( $rpage - 1 ) * 20, 20 ) as $e ) : ?>
				<?php $et = $etypes[ $e['type'] ] ?? array( $e['type'], 'is-info', 'radar' ); ?>
				<li class="<?php echo esc_attr( $et[1] ); ?>">
					<span class="sap-radar-dot"><?php echo sap_icon( $et[2], 14 ); // phpcs:ignore ?></span>
					<div>
						<p class="sap-radar-top"><b><?php echo esc_html( $et[0] ); ?></b><span dir="ltr"><?php echo esc_html( $e['domain'] ); ?></span><?php echo ! empty( $e['focus'] ) ? '<span class="sap-chip is-focus">' . esc_html( $e['focus'] ) . '</span>' : ''; ?><time><?php echo esc_html( sap_jdate( $e['at'], 'long' ) ); ?></time></p>
						<p dir="auto"><?php echo esc_html( $e['detail'] ); ?></p>
						<?php if ( ! empty( $e['url'] ) ) : ?><p class="sap-radar-acts"><?php echo sap_url_tag( $e['url'] ); // phpcs:ignore ?>
							<?php if ( ! empty( $e['keyword'] ) && 'new_page' !== $e['type'] ) : ?><a class="sap-btn is-ghost is-small" href="<?php echo esc_url( sap_project_url( $pid, 'serp', array( 'kw' => $e['keyword'] ) ) ); ?>">تحلیل نتایج گوگل این کلمه</a><?php endif; ?></p>
						<?php elseif ( ! empty( $e['keyword'] ) ) : ?>
							<p class="sap-radar-acts"><a class="sap-btn is-ghost is-small" href="<?php echo esc_url( sap_project_url( $pid, 'serp', array( 'kw' => $e['keyword'] ) ) ); ?>">تحلیل نتایج گوگل «<?php echo esc_html( $e['keyword'] ); ?>»</a></p>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php if ( $pages > 1 ) : ?>
			<nav class="sap-pager">
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<a class="<?php echo $i === $rpage ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'competitors', array_filter( array( 'rp' => $i, 'rt' => $rtype ) ) ) . '#radar' ); ?>"><?php echo esc_html( sap_num( $i ) ); ?></a>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</section>
<?php endif; ?>
