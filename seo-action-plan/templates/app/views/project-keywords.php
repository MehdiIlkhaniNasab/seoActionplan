<?php
/**
 * Keyword map: the primary keyword of each page. Basis of the cannibalization
 * judgement (real vs double result), titles and internal-link anchors.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid     = $project['id'];
$map     = sap_keymap( $pid );
$idx     = sap_keymap_index( $map );
$sources = sap_keymap_sources();
$impr    = array();
foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
	$impr[ sap_norm_url( $r['page'] ) ] = $r;
}
// phpcs:ignore WordPress.Security.NonceVerification
$show = isset( $_GET['show'] ) ? sanitize_key( $_GET['show'] ) : 'all';
$rows = array();
foreach ( $map as $k => $m ) {
	$m['impr']     = (int) ( $impr[ $k ]['impressions'] ?? 0 );
	$m['clicks']   = (int) ( $impr[ $k ]['clicks'] ?? 0 );
	$m['conflict'] = '' !== $m['core'] && count( $idx['primary'][ $m['core'] ] ?? array() ) > 1 && 'home' !== $m['page_type'];
	if ( ( 'todo' === $show && ( $m['confirmed'] || 'sheet' === $m['source'] ) ) || ( 'conflict' === $show && ! $m['conflict'] ) ) {
		continue;
	}
	$rows[] = $m;
}
usort( $rows, function ( $a, $b ) {
	return $b['conflict'] <=> $a['conflict'] ?: $b['impr'] <=> $a['impr'];
} );
$n_conf     = count( array_filter( $map, function ( $m ) {
	return $m['confirmed'] || 'sheet' === $m['source'];
} ) );
$n_conflict = count( array_filter( $idx['primary'], function ( $keys ) use ( $map ) {
	return count( array_filter( $keys, function ( $k ) use ( $map ) {
		return 'home' !== $map[ $k ]['page_type'];
	} ) ) > 1;
} ) );
$base = sap_project_url( $pid, 'keywords' );
?>
<section class="sap-panel sap-keymap-intro">
	<div class="sap-keymap-head">
		<div>
			<h2><?php echo sap_icon( 'research' ); // phpcs:ignore ?>نقشه کلمات: هر صفحه برای چه کلمه‌ای ساخته شده؟</h2>
			<p class="sap-muted">پایه همه تحلیل‌ها: کنیبال واقعی فقط وقتی است که دو صفحه برای <b>کلمه اصلی یکسان</b> رقابت کنند. اگر صفحه «کفی ورزشی» برای «کفی» هم نمایش بگیرد، کنیبال نیست؛ نمایش جانبی است. کلمه‌ها از شیت کلاسترینگ و تقویم محتوا خوانده می‌شوند و بقیه را ابزار یا هوش مصنوعی پیشنهاد می‌دهد؛ شما فقط تأیید یا اصلاح کنید.</p>
		</div>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-primary" data-sap-step="ai_keymap" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تشخیص هوشمند کلمه اصلی</button>
			<button type="button" class="sap-btn" data-sap-step="keymap,analyze" data-project="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>بازخوانی از شیت و اعمال در تحلیل</button>
		</div>
	</div>
	<div class="sap-stats">
		<div class="sap-stat"><b><?php echo esc_html( sap_num( count( $map ) ) ); ?></b><span>صفحه در نقشه</span></div>
		<div class="sap-stat"><b><?php echo esc_html( sap_num( $n_conf ) ); ?></b><span>قطعی (شیت یا تأیید شما)</span></div>
		<div class="sap-stat"><b><?php echo esc_html( sap_num( count( $map ) - $n_conf ) ); ?></b><span>منتظر تأیید</span></div>
		<div class="sap-stat <?php echo $n_conflict ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $n_conflict ) ); ?></b><span>کلمه با دو صاحب</span></div>
	</div>
</section>

<a class="sap-graph-cta" href="<?php echo esc_url( sap_project_url( $pid, 'graph' ) ); ?>"><?php echo sap_icon( 'link', 18 ); // phpcs:ignore ?><span><b>گراف کلمات و صفحات</b><small>همه صفحات، کلمات، لینک‌های داخلی و مقالات تقویم در یک نقشه شناور، خوشه‌بندی‌شده بر اساس موضوع</small></span><i>←</i></a>

<div class="sap-toolbar">
	<div class="sap-seg">
		<?php foreach ( array( 'all' => 'همه', 'todo' => 'منتظر تأیید', 'conflict' => 'تداخل' ) as $k => $label ) : ?>
			<a class="<?php echo $k === $show ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'show', $k, $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</div>
	<?php if ( count( $map ) > $n_conf ) : ?>
		<button type="button" class="sap-btn is-small" data-sap-keymap-confirm-all="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>تأیید همه پیشنهادها</button>
	<?php endif; ?>
</div>

<?php if ( ! $map ) : ?>
	<div class="sap-empty">
		<?php echo sap_icon( 'research', 32 ); // phpcs:ignore ?>
		<p>نقشه کلمات هنوز ساخته نشده. «تحلیل کامل» را بزنید یا دکمه «بازخوانی از شیت» را.</p>
	</div>
<?php else : ?>
	<div class="sap-keymap" data-sap-keymap="<?php echo esc_attr( $pid ); ?>">
		<?php foreach ( $rows as $m ) : ?>
			<form class="sap-km-row<?php echo $m['conflict'] ? ' is-conflict' : ''; ?><?php echo $m['confirmed'] || 'sheet' === $m['source'] ? ' is-confirmed' : ''; ?>" data-id="<?php echo esc_attr( $m['id'] ); ?>">
				<input type="hidden" name="url" value="<?php echo esc_attr( $m['url'] ); ?>">
				<div class="sap-km-page">
					<?php echo sap_page_link( $m['url'], '' !== $m['title'] ? $m['title'] : sap_page_title( $pid, $m['url'] ) ); // phpcs:ignore ?>
					<small class="sap-muted" dir="ltr"><?php echo esc_html( sap_short_url( $m['url'], 60 ) ); ?></small>
					<small class="sap-muted">
						<?php echo esc_html( sap_keymap_page_types()[ $m['page_type'] ] ?? '' ); ?>
						<?php if ( $m['impr'] ) : ?>
							· <?php echo esc_html( sap_num( $m['impr'] ) ); ?> نمایش · <?php echo esc_html( sap_num( $m['clicks'] ) ); ?> کلیک
						<?php endif; ?>
					</small>
				</div>
				<label class="sap-km-field">
					<span>کلمه اصلی</span>
					<input type="text" name="primary_kw" value="<?php echo esc_attr( $m['primary_kw'] ); ?>" placeholder="مثلاً کفی ورزشی">
				</label>
				<label class="sap-km-field is-wide">
					<span>کلمات فرعی و هم‌کانسپت (با ، جدا کنید)</span>
					<input type="text" name="secondary" value="<?php echo esc_attr( implode( '، ', $m['secondary'] ) ); ?>">
				</label>
				<label class="sap-km-field is-small">
					<span>نیت</span>
					<select name="intent">
						<option value="">—</option>
						<?php foreach ( sap_keymap_intents() as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $m['intent'], $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="sap-km-meta">
					<span class="sap-chip is-src-<?php echo esc_attr( $m['source'] ); ?>" title="<?php echo esc_attr( $m['reason'] ); ?>"><?php echo esc_html( $m['confirmed'] ? 'تأیید شده' : ( $sources[ $m['source'] ] ?? $m['source'] ) ); ?></span>
					<?php if ( $m['conflict'] ) : ?>
						<span class="sap-chip is-bad" title="این کلمه، کلمه اصلی صفحه دیگری هم هست">تداخل</span>
					<?php endif; ?>
					<button type="submit" class="sap-btn is-small is-primary" title="ذخیره و تأیید"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>تأیید</button>
					<a class="sap-icon-btn" title="تحلیل و بازنویسی محتوای این صفحه" href="<?php echo esc_url( sap_project_url( $pid, 'content', array( 'url' => $m['url'] ) ) ); ?>"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?></a>
				</div>
				<?php if ( '' !== $m['reason'] ) : ?>
					<p class="sap-km-reason"><?php echo sap_icon( 'spark', 12 ); // phpcs:ignore ?><?php echo esc_html( $m['reason'] ); ?></p>
				<?php endif; ?>
			</form>
		<?php endforeach; ?>
	</div>

	<details class="sap-panel sap-km-add">
		<summary><?php echo sap_icon( 'plus', 16 ); // phpcs:ignore ?>افزودن صفحه به نقشه کلمات</summary>
		<form class="sap-km-row" data-id="0" data-sap-keymap-new="<?php echo esc_attr( $pid ); ?>">
			<label class="sap-km-field is-wide"><span>آدرس صفحه</span><input type="url" name="url" dir="ltr" required placeholder="https://"></label>
			<label class="sap-km-field"><span>کلمه اصلی</span><input type="text" name="primary_kw" required></label>
			<label class="sap-km-field is-wide"><span>کلمات فرعی</span><input type="text" name="secondary"></label>
			<div class="sap-km-meta"><button type="submit" class="sap-btn is-small is-primary">افزودن</button></div>
		</form>
	</details>
<?php endif; ?>
