<?php
/**
 * Keyword map: which page owns which keyword, by content type (product
 * categories, brands, tags, posts, pages, products), with strict ownership,
 * a fit score per keyword, page status (target / product title / no keyword /
 * noindex / prune 410 / 301) and ranking conflicts. Paged, so a shop with
 * thousands of products stays light.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$pid    = $project['id'];
$tabs   = sap_keymap_tabs();
$tab    = isset( $_GET['tab'] ) && isset( $tabs[ sanitize_key( $_GET['tab'] ) ] ) ? sanitize_key( $_GET['tab'] ) : '';
$q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$filter = isset( $_GET['f'] ) ? sanitize_key( $_GET['f'] ) : '';
$pg     = max( 1, isset( $_GET['pg'] ) ? absint( $_GET['pg'] ) : 1 );
// phpcs:enable
$map      = sap_keymap( $pid );
$statuses = sap_keymap_statuses();
$sources  = sap_keymap_sources();
$types    = sap_content_types();
$inv_on   = (bool) sap_site_inventory( $pid );
$counts   = array();
foreach ( $tabs as $t => $def ) {
	$counts[ $t ] = count( sap_keymap_rows( $project, $t ) );
}
if ( ! $tab ) {
	foreach ( $tabs as $t => $def ) {
		if ( $counts[ $t ] ) {
			$tab = $t;
			break;
		}
	}
	$tab = $tab ?: 'product_cat';
}
$all       = sap_keymap_rows( $project, $tab );
$conflicts = sap_keymap_rank_conflicts( $project );
$rank_by   = array();
foreach ( $conflicts as $c ) {
	$rank_by[ sap_norm_url( $c['url'] ) ] = $c;
}
$owners = array();
foreach ( $map as $k => $m ) {
	if ( '' !== $m['core'] && 'home' !== $m['page_type'] ) {
		$owners[ $m['core'] ][] = $k;
	}
}
// Suggestion and flags for every row of the tab (cheap), fit only for the shown page.
$st = array( 'kw' => 0, 'todo' => 0, 'dup' => 0, 'rank' => 0, 'prune' => 0, 'none' => 0 );
foreach ( $all as $k => &$r ) {
	$r['sg']   = $r['m'] ? null : sap_keymap_suggest( $project, $r['url'], $r['ctype'], $r['title'], $r['it'] );
	$core      = $r['m'] ? $r['m']['core'] : '';
	$r['dup']  = '' !== $core && count( $owners[ $core ] ?? array() ) > 1;
	$r['rank'] = $rank_by[ $k ] ?? null;
	$eff       = $r['m'] ? $r['status'] : $r['sg']['status'];
	$r['eff']  = $eff;
	$st['kw']    += $r['m'] && '' !== $core ? 1 : 0;
	$st['todo']  += ! $r['m'] || ( ! $r['m']['confirmed'] && 'sheet' !== $r['m']['source'] && 'manual' !== $r['m']['source'] ) ? 1 : 0;
	$st['dup']   += $r['dup'] ? 1 : 0;
	$st['rank']  += $r['rank'] ? 1 : 0;
	$st['prune'] += in_array( $eff, array( 'prune_410', 'prune_301' ), true ) ? 1 : 0;
	$st['none']  += in_array( $eff, array( 'none', 'noindex' ), true ) ? 1 : 0;
}
unset( $r );
$rows = array_filter( $all, function ( $r ) use ( $q, $filter ) {
	if ( '' !== $q && false === mb_stripos( $r['title'] . ' ' . rawurldecode( $r['url'] ) . ' ' . $r['kw'], $q ) ) {
		return false;
	}
	switch ( $filter ) {
		case 'todo':
			return ! $r['m'] || ( ! $r['m']['confirmed'] && ! in_array( $r['m']['source'], array( 'sheet', 'manual' ), true ) );
		case 'kw':
			return $r['m'] && '' !== $r['m']['core'];
		case 'dup':
			return $r['dup'];
		case 'rank':
			return (bool) $r['rank'];
		case 'prune':
			return in_array( $r['eff'], array( 'prune_410', 'prune_301' ), true );
		case 'none':
			return in_array( $r['eff'], array( 'none', 'noindex' ), true );
		case 'traffic':
			return $r['impr'] > 0;
	}
	return true;
} );
uasort( $rows, function ( $a, $b ) {
	return array( (int) $b['dup'] + (int) (bool) $b['rank'], $b['impr'], $b['clicks'] ) <=> array( (int) $a['dup'] + (int) (bool) $a['rank'], $a['impr'], $a['clicks'] );
} );
$per   = 30;
$pages = max( 1, (int) ceil( count( $rows ) / $per ) );
$pg    = min( $pg, $pages );
$shown = array_slice( $rows, ( $pg - 1 ) * $per, $per, true );
$args  = array_filter( array( 'tab' => $tab, 'q' => $q, 'f' => $filter ) );
$ctx   = sap_keymap_rules_ctx( $project );
?>
<section class="sap-panel sap-keymap-intro">
	<div class="sap-keymap-head">
		<div>
			<h2><?php echo sap_icon( 'research' ); // phpcs:ignore ?>نقشه کلمات: هر صفحه صاحب کدام کلمه است؟</h2>
			<p class="sap-muted">قوانین سخت‌گیرانه: هر کلمه فقط <b>یک صاحب</b> دارد؛ کلمه عمومی مال دسته‌بندی، برند و برچسب است، کلمه اطلاعاتی مال مقاله، و محصول فقط نام خودش را می‌گیرد. صفحاتی مثل تماس با ما یا قوانین «بدون کلمه» می‌مانند و صفحات بی‌بازدید و بی‌کلمه پیشنهاد هرس (410 یا 301) می‌گیرند. «تناسب» نشان می‌دهد کلمه چقدر واقعاً مال همین صفحه است.</p>
		</div>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-primary" data-sap-step="keymap,ai_keymap,analyze" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تشخیص هوشمند و اعمال قوانین</button>
			<button type="button" class="sap-btn" data-sap-step="keymap,analyze" data-project="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>بازخوانی و اعمال قوانین (رایگان)</button>
		</div>
	</div>
	<?php if ( ! $inv_on ) : ?>
		<p class="sap-alert is-warn">فهرست وردپرس گرفته نشده؛ فقط صفحاتی که در سرچ کنسول نمایش دارند دیده می‌شوند و نوع آن‌ها از آدرس حدس زده می‌شود. در <a href="<?php echo esc_url( sap_project_url( $pid, 'audit' ) ); ?>">تحلیل محتوا</a> «دریافت فهرست کامل از وردپرس» را بزنید.</p>
	<?php endif; ?>
</section>

<?php if ( $conflicts ) : ?>
	<section class="sap-panel sap-km-alerts">
		<h3><?php echo sap_icon( 'alert' ); // phpcs:ignore ?>احتمال کنیبال: گوگل برای این کلمات صفحه دیگری را بالاتر نشان می‌دهد <small class="sap-muted">(<?php echo esc_html( sap_num( count( $conflicts ) ) ); ?>)</small></h3>
		<ul>
			<?php foreach ( array_slice( $conflicts, 0, 6 ) as $c ) : ?>
				<li>
					<b>«<?php echo esc_html( $c['kw'] ); ?>»</b>
					<span>هدف: <?php echo sap_page_link( $c['url'], $c['title'] ); // phpcs:ignore ?> <small class="sap-muted"><?php echo null === $c['pos'] ? 'بدون رتبه' : 'جایگاه ' . esc_html( sap_num( $c['pos'], 1 ) ); ?></small></span>
					<span class="sap-km-vs">← گوگل: <?php echo sap_page_link( $c['other'], $c['other_title'] ); // phpcs:ignore ?> <small class="sap-bad">جایگاه <?php echo esc_html( sap_num( $c['other_pos'], 1 ) . ' · ' . sap_num( $c['other_impr'] ) ); ?> نمایش</small></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="sap-small sap-muted">این موارد در «پیشنهادها»ی سئو محتوا هم برای تأیید و افزودن به اکشن پلن آمده‌اند. اگر صفحه دیگر واقعاً بهتر جواب نیت را می‌دهد، صفحه هدف را عوض کنید.</p>
	</section>
<?php endif; ?>

<nav class="sap-seg sap-audit-groups" aria-label="نوع صفحه">
	<?php foreach ( $tabs as $t => $def ) : ?>
		<a class="<?php echo $t === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'keywords', array( 'tab' => $t ) ) ); ?>"><?php echo esc_html( $def[0] ); ?> <small><?php echo esc_html( sap_num( $counts[ $t ] ) ); ?></small></a>
	<?php endforeach; ?>
</nav>

<div class="sap-audit-stats">
	<?php
	foreach ( array(
		''      => array( count( $all ), 'کل صفحات', '' ),
		'kw'    => array( $st['kw'], 'دارای کلمه کلیدی', 'is-score' ),
		'todo'  => array( $st['todo'], 'منتظر تصمیم/تأیید', $st['todo'] ? 'is-warn' : '' ),
		'dup'   => array( $st['dup'], 'کلمه با دو صاحب', $st['dup'] ? 'is-bad' : '' ),
		'rank'  => array( $st['rank'], 'رتبه با صفحه دیگر', $st['rank'] ? 'is-bad' : '' ),
		'none'  => array( $st['none'], 'بدون کلمه / noindex', '' ),
		'prune' => array( $st['prune'], 'هرس (410 / 301)', $st['prune'] ? 'is-warn' : '' ),
	) as $fk => $s ) :
		?>
		<a href="<?php echo esc_url( sap_project_url( $pid, 'keywords', array_filter( array( 'tab' => $tab, 'f' => $fk ) ) ) ); ?>" class="<?php echo $fk === $filter ? 'is-active' : ''; ?> <?php echo esc_attr( $s[2] ); ?>"><b><?php echo esc_html( sap_num( $s[0] ) ); ?></b><span><?php echo esc_html( $s[1] ); ?></span></a>
	<?php endforeach; ?>
</div>

<?php if ( 'product' === $tab ) : ?>
	<p class="sap-alert">در فروشگاه بزرگ لازم نیست برای همه محصولات کلمه بسازید: <b>کلمه اصلی هر محصول همان نام محصول است</b> (وضعیت «عنوان = کلمه اصلی») و کلمات عمومی مثل «بند کفش تخت صورتی» مال صفحه دسته است، نه ۱۰ محصول. فقط محصولاتی که خودشان جستجو می‌شوند (مدل گوشی، نام عطر) کلمه هدف جدا لازم دارند. فیلتر «دارای ترافیک» محصولات مهم را جدا می‌کند.</p>
<?php endif; ?>

<section class="sap-panel">
	<form class="sap-audit-filter" method="get" action="<?php echo esc_url( sap_project_url( $pid, 'keywords' ) ); ?>">
		<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
		<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="جستجو در عنوان، آدرس یا کلمه…">
		<select name="f">
			<?php foreach ( array( '' => 'همه', 'traffic' => 'دارای ترافیک در گوگل', 'todo' => 'منتظر تصمیم/تأیید', 'kw' => 'دارای کلمه کلیدی', 'dup' => 'کلمه با دو صاحب', 'rank' => 'رتبه با صفحه دیگر (کنیبال)', 'none' => 'بدون کلمه / noindex', 'prune' => 'هرس' ) as $fk => $fl ) : ?>
				<option value="<?php echo esc_attr( $fk ); ?>" <?php selected( $fk, $filter ); ?>><?php echo esc_html( $fl ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="sap-btn is-small" type="submit">اعمال</button>
		<span class="sap-muted sap-small"><?php echo esc_html( sap_num( count( $rows ) ) ); ?> صفحه</span>
	</form>

	<?php if ( ! $rows ) : ?>
		<div class="sap-empty"><p>صفحه‌ای با این فیلتر نیست.</p></div>
	<?php else : ?>
		<div class="sap-keymap" data-sap-keymap="<?php echo esc_attr( $pid ); ?>">
			<?php foreach ( $shown as $k => $r ) : ?>
				<?php
				$m       = $r['m'];
				$sg      = $r['sg'];
				$status  = $m ? $m['status'] : $sg['status'];
				$kw      = $m ? $m['primary_kw'] : ( 'title' === $sg['status'] ? sap_product_title_kw( $r['title'] ) : '' );
				$fit     = sap_keymap_has_kw( $status ) && '' !== $kw ? sap_kw_fit( $project, $kw, $r['url'], $r['ctype'], $r['title'] ) : null;
				$queries = array_slice( sap_sort( (array) ( $ctx['by_page'][ $k ] ?? array() ), 'impressions' ), 0, 4 );
				$decided = $m && ( $m['confirmed'] || in_array( $m['source'], array( 'manual', 'sheet' ), true ) );
				$ptype   = $r['it'] ? sap_inventory_page_type( $r['ctype'], $r['it'] ) : ( $m ? $m['page_type'] : 'other' );
				?>
				<form class="sap-km-row is-st-<?php echo esc_attr( $status ); ?><?php echo $r['dup'] || $r['rank'] ? ' is-conflict' : ''; ?><?php echo $decided ? ' is-confirmed' : ''; ?>" data-id="<?php echo esc_attr( $m ? $m['id'] : 0 ); ?>">
					<input type="hidden" name="url" value="<?php echo esc_attr( $r['url'] ); ?>">
					<input type="hidden" name="title" value="<?php echo esc_attr( $r['title'] ); ?>">
					<input type="hidden" name="page_type" value="<?php echo esc_attr( $ptype ); ?>">
					<div class="sap-km-page">
						<label class="sap-km-pick"><input type="checkbox" data-sap-km-pick value="<?php echo esc_attr( $r['url'] ); ?>" aria-label="انتخاب"></label>
						<div>
							<?php echo sap_page_link( $r['url'], $r['title'] ?: rawurldecode( $r['url'] ) ); // phpcs:ignore ?>
							<small class="sap-muted"><?php echo esc_html( $types[ $r['ctype'] ] ?? $r['ctype'] ); ?><?php echo $r['impr'] ? ' · ' . esc_html( sap_num( $r['impr'] ) . ' نمایش · ' . sap_num( $r['clicks'] ) . ' کلیک' ) : ' · بدون نمایش'; ?><?php echo isset( $r['it']['count'] ) ? ' · ' . esc_html( sap_num( (int) $r['it']['count'] ) ) . ' آیتم' : ''; ?></small>
							<?php if ( $queries ) : ?>
								<span class="sap-km-qs"><?php foreach ( $queries as $qr ) : ?><button type="button" class="sap-km-q" data-sap-km-q="<?php echo esc_attr( $qr['query'] ); ?>" title="<?php echo esc_attr( 'جایگاه ' . sap_num( $qr['position'], 1 ) . ' · ' . sap_num( $qr['impressions'] ) . ' نمایش — کلیک: کلمه اصلی شود' ); ?>"><?php echo esc_html( $qr['query'] ); ?> <i><?php echo esc_html( sap_num( $qr['position'], 0 ) ); ?></i></button><?php endforeach; ?></span>
							<?php endif; ?>
						</div>
					</div>
					<label class="sap-km-field">
						<span>کلمه اصلی</span>
						<input type="text" name="primary_kw" value="<?php echo esc_attr( $kw ); ?>" placeholder="<?php echo 'product' === $r['ctype'] ? esc_attr( sap_product_title_kw( $r['title'] ) ) : 'بدون کلمه'; ?>">
					</label>
					<label class="sap-km-field is-wide">
						<span>کلمات فرعی (با ، جدا کنید)</span>
						<input type="text" name="secondary" value="<?php echo esc_attr( $m && sap_keymap_has_kw( $status ) ? implode( '، ', $m['secondary'] ) : '' ); ?>">
					</label>
					<label class="sap-km-field is-status">
						<span>وضعیت</span>
						<select name="status" data-sap-km-status>
							<?php foreach ( $statuses as $sk => $sl ) : ?>
								<?php if ( 'title' === $sk && 'product' !== $r['ctype'] ) { continue; } ?>
								<option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $status, $sk ); ?>><?php echo esc_html( $sl ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="sap-km-field is-wide is-redirect" <?php echo 'prune_301' === $status ? '' : 'hidden'; ?>>
						<span>ریدایرکت 301 به</span>
						<input type="url" name="redirect_to" dir="ltr" value="<?php echo esc_attr( $m ? $m['redirect_to'] : ( $sg['redirect'] ?? '' ) ); ?>" placeholder="https://">
					</label>
					<div class="sap-km-meta">
						<?php if ( $fit ) : ?>
							<span class="sap-fit is-<?php echo esc_attr( $fit['tone'] ); ?>" title="<?php echo esc_attr( $fit['reasons'] ? implode( ' · ', $fit['reasons'] ) : 'کلمه با نوع، عنوان و داده گوگل این صفحه می‌خواند' ); ?>">تناسب <b><?php echo esc_html( sap_num( $fit['score'] ) ); ?></b></span>
						<?php endif; ?>
						<?php if ( $m ) : ?>
							<span class="sap-chip is-src-<?php echo esc_attr( $m['source'] ); ?>"><?php echo esc_html( $m['confirmed'] ? 'تأیید شده' : ( $sources[ $m['source'] ] ?? $m['source'] ) ); ?></span>
						<?php else : ?>
							<span class="sap-chip is-warn" title="<?php echo esc_attr( $sg['reason'] ); ?>">پیشنهاد: <?php echo esc_html( $statuses[ $sg['status'] ] ); ?></span>
						<?php endif; ?>
						<?php if ( $r['dup'] ) : ?><span class="sap-chip is-bad" title="این کلمه، کلمه اصلی صفحه دیگری هم هست">دو صاحب</span><?php endif; ?>
						<?php if ( $r['rank'] ) : ?><span class="sap-chip is-bad" title="<?php echo esc_attr( 'گوگل «' . $r['rank']['other_title'] . '» را در جایگاه ' . sap_num( $r['rank']['other_pos'], 1 ) . ' نشان می‌دهد' ); ?>">کنیبال؟</span><?php endif; ?>
						<button type="submit" class="sap-btn is-small is-primary" title="ذخیره و تأیید"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>تأیید</button>
					</div>
					<?php
					$why = $m ? $m['reason'] : $sg['reason'];
					if ( $fit && $fit['reasons'] && $fit['score'] < 75 ) {
						$why = trim( $why . ' · تناسب: ' . implode( '، ', $fit['reasons'] ), ' ·' );
					}
					if ( $r['rank'] ) {
						$why = trim( $why . ' · گوگل برای «' . $r['rank']['kw'] . '» صفحه «' . $r['rank']['other_title'] . '» را در جایگاه ' . sap_num( $r['rank']['other_pos'], 1 ) . ' نشان می‌دهد؛ احتمال کنیبال.', ' ·' );
					}
					?>
					<?php if ( '' !== $why ) : ?>
						<p class="sap-km-reason"><?php echo sap_icon( 'spark', 12 ); // phpcs:ignore ?><?php echo esc_html( $why ); ?></p>
					<?php endif; ?>
				</form>
			<?php endforeach; ?>
		</div>

		<?php if ( $pages > 1 ) : ?>
			<nav class="sap-pager">
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<?php if ( $i <= 2 || $i > $pages - 2 || abs( $i - $pg ) <= 2 ) : ?>
						<a class="<?php echo $i === $pg ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'keywords', $args + array( 'pg' => $i ) ) ); ?>"><?php echo esc_html( sap_num( $i ) ); ?></a>
					<?php elseif ( 3 === $i || $pages - 2 === $i ) : ?>
						<span>…</span>
					<?php endif; ?>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>

		<div class="sap-audit-bulk" data-sap-km-bulk="<?php echo esc_attr( $pid ); ?>">
			<label class="sap-check"><input type="checkbox" data-sap-km-all> همه این صفحه</label>
			<select data-sap-km-bulk-status>
				<option value="suggest">اعمال پیشنهاد سکان</option>
				<?php foreach ( $statuses as $sk => $sl ) : ?>
					<?php if ( 'title' === $sk && 'product' !== $tab ) { continue; } ?>
					<option value="<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $sl ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="sap-btn is-small" data-sap-km-bulk-go>اعمال روی انتخاب‌شده‌ها</button>
			<button type="button" class="sap-btn is-small is-ghost" data-sap-km-prune-task>افزودن همه هرس‌ها به اکشن پلن</button>
		</div>
	<?php endif; ?>
</section>

<a class="sap-graph-cta" href="<?php echo esc_url( sap_project_url( $pid, 'graph' ) ); ?>"><?php echo sap_icon( 'link', 18 ); // phpcs:ignore ?><span><b>گراف کلمات و صفحات</b><small>همه صفحات، کلمات، لینک‌های داخلی و مقالات تقویم در یک نقشه شناور، خوشه‌بندی‌شده بر اساس موضوع</small></span><i>←</i></a>

<details class="sap-panel sap-km-add">
	<summary><?php echo sap_icon( 'plus', 16 ); // phpcs:ignore ?>افزودن صفحه‌ای که در فهرست نیست</summary>
	<form class="sap-km-row" data-id="0" data-sap-keymap-new="<?php echo esc_attr( $pid ); ?>">
		<label class="sap-km-field is-wide"><span>آدرس صفحه</span><input type="url" name="url" dir="ltr" required placeholder="https://"></label>
		<label class="sap-km-field"><span>کلمه اصلی</span><input type="text" name="primary_kw" required></label>
		<label class="sap-km-field is-wide"><span>کلمات فرعی</span><input type="text" name="secondary"></label>
		<div class="sap-km-meta"><button type="submit" class="sap-btn is-small is-primary">افزودن</button></div>
	</form>
</details>
