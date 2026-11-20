<?php
/**
 * Content pillar › «آپدیت محتوا»: pages worth updating, split by kind of page
 * (categories, products, articles), each with a plain plan — what to do, why and
 * the goal — built from the five update strategies, the keyword map (a keyword
 * another page owns is not a target) and real Search Console data. Approving a
 * page (keyword, secondary keywords, goal, plan) puts it in the content calendar.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$pid    = $project['id'];
$defs   = sap_upd_strategies();
$types  = sap_upd_types();
$ds     = sap_dataset( $pid, 'upd_pages' );
$cards  = $ds ? (array) $ds['payload']['cards'] : array();
$period = sap_period_current( $pid );
$months = sap_period_options( 0, 2 );
$type   = isset( $_GET['t'] ) && isset( $types[ sanitize_key( $_GET['t'] ) ] ) ? sanitize_key( $_GET['t'] ) : '';
$st     = isset( $_GET['st'] ) && isset( $defs[ sanitize_key( $_GET['st'] ) ] ) ? sanitize_key( $_GET['st'] ) : '';
// phpcs:enable
$counts = (array) ( $ds['meta']['counts'] ?? array() );
if ( ! $type ) {
	foreach ( array( 'cat', 'product', 'post' ) as $t ) {
		if ( ! empty( $counts[ $t ] ) ) {
			$type = $t;
			break;
		}
	}
	$type = $type ?: 'cat';
}
$list    = array_filter( $cards, function ( $c ) use ( $type, $st ) {
	return $c['type'] === $type && ( ! $st || isset( $c['s'][ $st ] ) );
} );
$planned = array();
foreach ( sap_updates( $pid, array( 'period' => $period ) ) as $u ) {
	if ( 'cancelled' !== $u['status'] ) {
		$planned[ sap_norm_url( $u['url'] ) ] = $u;
	}
}
// Pages handled recently (open update in any month, updated in the last 90 days,
// or title / content changed on the site in the last 60 days) are not suggested
// again; they are listed apart so you can see why.
sap_update_reconcile( $pid );
$handled = sap_cplan_handled( $pid );
$resting = array();
foreach ( $list as $k => $x ) {
	if ( isset( $handled[ $k ] ) && ! isset( $planned[ $k ] ) ) {
		$resting[ $k ] = $x + array( 'handled' => $handled[ $k ] );
		unset( $list[ $k ] );
	}
}
$ai      = (array) get_option( 'sap_upd_ai_' . $pid, array() );
$c       = sap_commit( $project );
$fit_l   = array( 'own' => array( 'کلمه خود صفحه', 'is-good' ), 'fit' => array( 'مرتبط', 'is-info' ), 'other' => array( 'مال صفحه دیگر', 'is-warn' ), 'brand' => array( 'برند', '' ), 'info' => array( 'اطلاعاتی → مقاله', 'is-warn' ), 'buy' => array( 'خرید → دسته', 'is-warn' ) );
$ex      = (array) ( $ds['meta']['excluded'] ?? array() );
$base    = sap_project_url( $pid, 'updates' );
?>
<section class="sap-panel sap-upd-head" data-sap-upd="<?php echo esc_attr( $pid ); ?>">
	<div class="sap-panel-head">
		<div>
			<h2><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>آپدیت محتوا</h2>
			<p class="sap-muted">صفحه‌هایی که آپدیتشان بیشترین اثر را روی ترافیک و فروش دارد، جدا برای دسته‌بندی‌ها، محصولات و مقالات. هر صفحه یک برنامه روشن دارد: چه کار کنیم، چرا، و هدف. با «تأیید و افزودن به تقویم» (با کلمه، کلمات فرعی، هدف و برنامه‌ای که ویرایش می‌کنید) وارد بخش آپدیت تقویم محتوا و شیت می‌شود.</p>
		</div>
		<button type="button" class="sap-btn is-primary" data-sap-upd-refresh><?php echo sap_icon( 'refresh', 16 ); // phpcs:ignore ?><?php echo $ds ? 'تحلیل دوباره با داده تازه' : 'تحلیل صفحات'; ?></button>
	</div>
	<details class="sap-upd-how">
		<summary>معیار انتخاب صفحه‌ها چیست؟</summary>
		<ul>
			<li><b>فقط دسته‌بندی، محصول و مقاله</b> بررسی می‌شوند؛ صفحه اصلی، تماس، درباره ما، سبد خرید و … کنار گذاشته می‌شوند.</li>
			<li><b>جستجوهای برند</b> (نام سایت) حساب نمی‌شوند؛ آن‌ها آپدیت لازم ندارند.</li>
			<li><b>نقشه کلمات رعایت می‌شود:</b> کلمه‌ای که مال صفحه دیگری است هدف این صفحه نمی‌شود؛ فقط پیشنهاد لینک داخلی به صاحبش داده می‌شود.</li>
			<li><b>ماهیت صفحه:</b> جستجوی اطلاعاتی روی صفحه فروش → مقاله جدا؛ جستجوی خرید روی مقاله → لینک به دسته.</li>
			<li><b>۵ استراتژی</b> روی بقیه جستجوها اجرا می‌شود: <?php echo esc_html( implode( '، ', array_map( function ( $d ) {
				return $d['icon'] . ' ' . $d['name'];
			}, $defs ) ) ); ?>.</li>
			<li><b>امتیاز</b> = کلیک اضافه‌ای که آپدیت می‌آورد × وزن نوع صفحه (دسته‌بندی بیشتر از محصول و مقاله، چون فروش می‌آورد) × صفحه‌های Focus بیشتر.</li>
		</ul>
	</details>
	<?php if ( $ds ) : ?>
		<p class="sap-small sap-muted">آخرین تحلیل: <?php echo esc_html( sap_jdate( $ds['fetched_at'] ) . ' ' . substr( $ds['fetched_at'], 11, 5 ) ); ?> · کنار گذاشته شد: <?php echo esc_html( sprintf( '%s جستجوی برند، %s جستجو که مال صفحه دیگری است، %s صفحه غیرمحتوایی', sap_num( (int) ( $ex['brand'] ?? 0 ) ), sap_num( (int) ( $ex['other'] ?? 0 ) ), sap_num( (int) ( $ex['pages'] ?? 0 ) ) ) ); ?><?php echo ! empty( $ds['meta']['notes'] ) ? ' · ' . esc_html( implode( '؛ ', $ds['meta']['notes'] ) ) : ''; ?></p>
	<?php endif; ?>
	<div class="sap-upd-cycle">
		<div><span>آپدیت‌های <?php echo esc_html( sap_period_label( $period ) ); ?> در تقویم</span><b><?php echo esc_html( sap_num( count( $planned ) ) ); ?><?php echo $c['content_upd'] ? '<small> / ' . esc_html( sap_num( $c['content_upd'] ) ) . ' تعهد ماهانه</small>' : ''; ?></b></div>
		<div><span>دوره</span><b><small><?php echo esc_html( sap_period_dates( $period, $pid ) ); ?></small></b></div>
		<a class="sap-btn is-soft is-small" href="<?php echo esc_url( sap_project_url( $pid, 'calendar' ) . '#updates' ); ?>">دیدن در تقویم محتوا ←</a>
	</div>
	<details class="sap-upd-manual">
		<summary>+ افزودن دستی یک صفحه</summary>
		<form class="sap-upd-form" data-sap-upd-form>
			<div class="sap-grid-3">
				<label>آدرس صفحه<input type="url" name="url" dir="ltr" required></label>
				<label>کلمه اصلی<input type="text" name="keyword"></label>
				<label>ماه<select name="period"><?php foreach ( $months as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
			</div>
			<label>هدف<input type="text" name="goal" placeholder="مثلاً: CTR از ۱٪ به ۴٪"></label>
			<label>چه کار کنیم<textarea name="todo" rows="3"></textarea></label>
			<button type="submit" class="sap-btn is-primary is-small">افزودن به تقویم</button>
		</form>
	</details>
</section>

<?php sap_view( 'content-slots', array( 'project' => $project, 'kind' => 'upd' ) ); ?>


<h2 class="sap-section-title">پیشنهادهای آپدیت از داده سرچ کنسول <small class="sap-muted">برای انتخاب صفحه‌های بالا کمکتان می‌کند</small></h2>
<?php if ( ! $ds ) : ?>
	<div class="sap-empty"><p>«تحلیل صفحات» را بزنید. سکان داده سرچ کنسول (۲۸ و ۹۰ روزه)، متن صفحات پربازدید و وضعیت ایندکس صفحات مهم را می‌خواند (۳۰ تا ۹۰ ثانیه).</p></div>
	<?php return; ?>
<?php endif; ?>

<nav class="sap-seg-mini sap-upd-tabs">
	<?php foreach ( $types as $t => $l ) : ?>
		<a class="<?php echo $t === $type ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 't', $t, $base ) ); ?>"><?php echo esc_html( $l ); ?> <em><?php echo esc_html( sap_num( (int) ( $counts[ $t ] ?? 0 ) ) ); ?></em></a>
	<?php endforeach; ?>
</nav>
<div class="sap-upd-filter">
	<a class="<?php echo $st ? '' : 'is-active'; ?>" href="<?php echo esc_url( add_query_arg( 't', $type, $base ) ); ?>">همه استراتژی‌ها</a>
	<?php foreach ( $defs as $s => $d ) : ?>
		<a class="<?php echo $s === $st ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 't' => $type, 'st' => $s ), $base ) ); ?>" title="<?php echo esc_attr( $d['goal'] ); ?>"><?php echo esc_html( $d['icon'] . ' ' . $d['name'] ); ?></a>
	<?php endforeach; ?>
</div>

<section class="sap-panel">
	<?php if ( $st ) : ?>
		<div class="sap-upd-def"><b><?php echo esc_html( $defs[ $st ]['icon'] . ' ' . $defs[ $st ]['name'] ); ?>:</b> <?php echo esc_html( $defs[ $st ]['goal'] ); ?></div>
	<?php endif; ?>
	<?php if ( ! $list ) : ?>
		<p class="sap-muted">در این بخش صفحه‌ای برای آپدیت پیدا نشد.</p>
	<?php endif; ?>
	<?php foreach ( array_slice( $list, 0, 30, true ) as $k => $x ) : ?>
		<?php $in = $planned[ $k ] ?? null; $a = $ai[ $k ] ?? null; ?>
		<article class="sap-upd-c" data-url="<?php echo esc_attr( $x['url'] ); ?>">
			<div class="sap-upd-c-score" style="--v:<?php echo esc_attr( (int) $x['score'] ); ?>%" title="امتیاز اثر آپدیت"><b><?php echo esc_html( sap_num( (int) $x['score'] ) ); ?></b></div>
			<div class="sap-upd-c-body">
				<h4><a href="<?php echo esc_url( $x['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $x['title'] ); ?></a>
					<?php if ( '' !== $x['kw'] ) : ?><span class="sap-chip" title="کلمه اصلی صفحه در نقشه کلمات">🔑 <?php echo esc_html( $x['kw'] ); ?></span><?php endif; ?>
					<?php if ( $x['focus'] ) : ?><span class="sap-chip is-focus">🎯 <?php echo esc_html( $x['focus'] ); ?></span><?php endif; ?>
					<?php foreach ( array_keys( $x['s'] ) as $s ) : ?><span class="sap-chip is-s" title="<?php echo esc_attr( $defs[ $s ]['goal'] ); ?>"><?php echo esc_html( $defs[ $s ]['icon'] . ' ' . $defs[ $s ]['name'] ); ?></span><?php endforeach; ?>
				</h4>
				<p class="sap-upd-c-why"><b>چرا:</b> <?php echo esc_html( $x['why'] ); ?></p>
				<?php if ( $x['todo'] ) : ?>
					<div class="sap-upd-todo"><b>چه کار کنیم:</b><ol><?php foreach ( $x['todo'] as $t ) : ?><li><?php echo esc_html( $t ); ?></li><?php endforeach; ?></ol></div>
				<?php endif; ?>
				<?php if ( '' !== $x['goal'] ) : ?><p class="sap-upd-goal"><b>هدف:</b> <?php echo esc_html( $x['goal'] ); ?></p><?php endif; ?>
				<?php if ( $x['queries'] ) : ?>
					<details class="sap-upd-data"><summary>جستجوهای این صفحه (<?php echo esc_html( sap_num( count( $x['queries'] ) ) ); ?>)</summary>
						<div class="sap-table-wrap"><table class="sap-table is-compact">
							<thead><tr><th>جستجو</th><th>نمایش</th><th>CTR</th><th>جایگاه</th><th>نسبت با صفحه</th><th>تایتل · متن</th></tr></thead>
							<tbody>
								<?php foreach ( $x['queries'] as $q ) : ?>
									<tr class="<?php echo in_array( $q['fit'], array( 'brand', 'other' ), true ) ? 'is-muted' : ''; ?>">
										<td><?php echo esc_html( $q['q'] ); ?></td><td><?php echo esc_html( sap_num( $q['impr'] ) ); ?></td><td><?php echo esc_html( sap_num( $q['ctr'], 1 ) ); ?>٪</td><td><?php echo esc_html( sap_num( $q['pos'], 1 ) ); ?></td>
										<td><span class="sap-chip <?php echo esc_attr( $fit_l[ $q['fit'] ][1] ?? '' ); ?>"><?php echo esc_html( $fit_l[ $q['fit'] ][0] ?? $q['fit'] ); ?></span><?php echo 'other' === $q['fit'] ? '<small class="sap-muted"> ' . esc_html( sap_short_url( $q['owner'], 30 ) ) . '</small>' : ''; ?></td>
										<td class="sap-nowrap"><?php echo $q['in_title'] ? '✓' : '✗'; ?> · <?php echo null === $q['in_text'] ? '؟' : ( $q['in_text'] ? '✓' : '✗' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table></div>
					</details>
				<?php endif; ?>
				<div class="sap-upd-c-ai" data-sap-upd-ai-box><?php if ( $a ) { sap_view( 'upd-ai', array( 'a' => $a ) ); } ?></div>
				<?php if ( $in ) : ?>
					<div class="sap-upd-c-acts"><span class="sap-chip is-good">✓ در آپدیت‌های <?php echo esc_html( sap_period_label( $period ) ); ?></span><button type="button" class="sap-btn is-small is-soft" data-sap-upd-ai="<?php echo esc_attr( $x['url'] ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>تحلیل هوشمند</button></div>
				<?php else : ?>
					<details class="sap-upd-approve">
						<summary class="sap-btn is-small is-primary">تأیید و افزودن به تقویم</summary>
						<form data-sap-upd-form>
							<input type="hidden" name="url" value="<?php echo esc_attr( $x['url'] ); ?>">
							<div class="sap-grid-3">
								<label>کلمه اصلی<input type="text" name="keyword" value="<?php echo esc_attr( $x['kw'] ?: ( $x['secondary'][0] ?? '' ) ); ?>"></label>
								<label>کلمات فرعی<input type="text" name="secondary" value="<?php echo esc_attr( implode( '، ', $x['secondary'] ) ); ?>"></label>
								<label>ماه<select name="period"><?php foreach ( $months as $mk => $ml ) : ?><option value="<?php echo esc_attr( $mk ); ?>" <?php selected( $mk, $period ); ?>><?php echo esc_html( $ml ); ?></option><?php endforeach; ?></select></label>
							</div>
							<label>هدف<input type="text" name="goal" value="<?php echo esc_attr( $x['goal'] ); ?>"></label>
							<label>چه کار کنیم (برای نویسنده)<textarea name="todo" rows="5" data-sap-upd-todo><?php echo esc_textarea( implode( "\n", array_map( function ( $t, $i ) {
								return sap_fa( $i + 1 ) . '. ' . $t;
							}, $x['todo'], array_keys( $x['todo'] ) ) ) . ( $a ? "\n" . implode( "\n", sap_upd_ai_lines( $a ) ) : '' ) ); ?></textarea></label>
							<div class="sap-upd-c-acts">
								<button type="submit" class="sap-btn is-small is-primary">ثبت در تقویم</button>
								<button type="button" class="sap-btn is-small is-soft" data-sap-upd-ai="<?php echo esc_attr( $x['url'] ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>تکمیل برنامه با هوش مصنوعی</button>
							</div>
						</form>
					</details>
				<?php endif; ?>
			</div>
		</article>
	<?php endforeach; ?>
	<?php if ( $resting ) : ?>
		<details class="sap-upd-rest">
			<summary><?php echo esc_html( sprintf( '%s صفحه به‌تازگی آپدیت یا برنامه‌ریزی شده و دوباره پیشنهاد نمی‌شود', sap_num( count( $resting ) ) ) ); ?></summary>
			<ul>
				<?php foreach ( $resting as $x ) : ?>
					<li><a href="<?php echo esc_url( $x['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $x['title'] ); ?></a> <small class="sap-muted"><?php echo esc_html( $x['handled']['label'] ); ?></small></li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
</section>
