<?php
/**
 * Content pillar › content audit by type. With the connector (1.3+) the list is
 * the site's whole WordPress content (posts, pages, products, post / product
 * categories, tags, brands) matched with Search Console by URL; each row shows
 * the page's keywords (keyword map, SEO plugin, real queries), rule checks and
 * the AI analysis, opened in a drawer.
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
$q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$filter = isset( $_GET['f'] ) ? sanitize_key( $_GET['f'] ) : '';
$sort   = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'impr';
$pg     = max( 1, isset( $_GET['pg'] ) ? absint( $_GET['pg'] ) : 1 );
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
$all     = $inv[ $group ] ?? array();
$audit   = (array) ( sap_dataset_payload( $pid, 'ai_audit' )['groups'][ $group ] ?? array() );
$types   = sap_content_types();
$period  = sap_period_current();
$inv_ds  = sap_dataset( $pid, 'site_inventory' );
$site_ok = sap_site_connected( $project );
$labels  = array_map( function ( $d ) {
	return $d[0];
}, $groups ) + array( 'links' => 'لینک داخلی', 'landing' => 'فرصت صفحه جدید', 'optimize' => 'بهینه‌سازی صفحه فعلی' );

// Stats of the group.
$st = array( 'total' => count( $all ), 'impr' => 0, 'zero' => 0, 'nokw' => 0, 'weak' => 0, 'noidx' => 0, 'score' => 0 );
foreach ( $all as $r ) {
	$st['impr']  += $r['impr'] ? 1 : 0;
	$st['zero']  += $r['impr'] ? 0 : 1;
	$st['nokw']  += '' === $r['kw'] ? 1 : 0;
	$st['weak']  += $r['score'] < 60 ? 1 : 0;
	$st['noidx'] += ! empty( $r['wp']['noindex'] ) ? 1 : 0;
	$st['score'] += $r['score'];
}
$st['score'] = $all ? (int) round( $st['score'] / count( $all ) ) : 0;

// Filter, search, sort, page.
$rows = array_values( array_filter( $all, function ( $r ) use ( $q, $filter, $audit ) {
	if ( '' !== $q && false === mb_stripos( $r['title'] . ' ' . rawurldecode( $r['url'] ) . ' ' . $r['kw'], $q ) ) {
		return false;
	}
	switch ( $filter ) {
		case 'zero':
			return ! $r['impr'];
		case 'nokw':
			return '' === $r['kw'];
		case 'weak':
			return $r['score'] < 60;
		case 'misfit':
			return (bool) $r['misfits'];
		case 'near':
			return null !== $r['pos'] && $r['pos'] > 3 && $r['pos'] <= 20;
		case 'done':
			return isset( $audit[ sap_norm_url( $r['url'] ) ] );
		case 'noidx':
			return ! empty( $r['wp']['noindex'] );
	}
	return true;
} ) );
if ( 'score' === $sort ) {
	usort( $rows, function ( $a, $b ) {
		return $a['score'] <=> $b['score'];
	} );
} elseif ( 'words' === $sort ) {
	usort( $rows, function ( $a, $b ) {
		return (int) $a['words'] <=> (int) $b['words'];
	} );
} elseif ( 'clicks' === $sort ) {
	usort( $rows, function ( $a, $b ) {
		return $b['clicks'] <=> $a['clicks'];
	} );
}
$per   = 40;
$pages = max( 1, (int) ceil( count( $rows ) / $per ) );
$pg    = min( $pg, $pages );
$shown = array_slice( $rows, ( $pg - 1 ) * $per, $per );
$args  = array_filter( array( 'group' => $group, 'q' => $q, 'f' => $filter, 'sort' => 'impr' !== $sort ? $sort : '' ) );
$here  = sap_project_url( $pid, 'audit', $args + ( $pg > 1 ? array( 'pg' => $pg ) : array() ) );
?>
<section class="sap-panel sap-pillar-head">
	<div class="sap-pillar-intro">
		<h2><?php echo sap_icon( 'log' ); // phpcs:ignore ?>تحلیل محتوا بر اساس نوع صفحه</h2>
		<p class="sap-muted">فهرست کامل محتوای سایت مستقیم از وردپرس خوانده می‌شود (نوشته‌ها، برگه‌ها، محصولات، دسته و برچسب مقالات و محصولات، برندها) و با آدرس هر صفحه به داده سرچ کنسول و نقشه کلمات وصل می‌شود. هر صفحه با چک‌لیست مخصوص نوعش و از دید Focus و فروش/لید بررسی می‌شود؛ اقدامات بعد از تأیید شما وارد اکشن پلن می‌شوند.</p>
		<div class="sap-inv-status <?php echo $inv_ds ? 'is-ok' : 'is-off'; ?>">
			<?php if ( $inv_ds ) : ?>
				<?php echo sap_icon( 'check', 16 ); // phpcs:ignore ?>
				<span>فهرست وردپرس: <b><?php echo esc_html( sap_num( (int) ( $inv_ds['meta']['count'] ?? 0 ) ) ); ?></b> صفحه · <?php echo esc_html( sap_jdate( $inv_ds['fetched_at'], 'short' ) ); ?><?php echo ! empty( $inv_ds['payload']['seo_plugin'] ) ? ' · افزونه سئو: ' . esc_html( 'rank_math' === $inv_ds['payload']['seo_plugin'] ? 'Rank Math' : 'Yoast' ) : ''; ?></span>
			<?php elseif ( $site_ok ) : ?>
				<?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?><span>فهرست محتوای وردپرس هنوز گرفته نشده؛ تا آن موقع نوع صفحات از روی آدرس حدس زده می‌شود.</span>
			<?php else : ?>
				<?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?><span>افزونه رابط (نسخه ۱٫۳) به این پروژه وصل نیست؛ فقط صفحاتی که در سرچ کنسول نمایش دارند دیده می‌شوند و نوع آن‌ها از آدرس حدس زده می‌شود. <a href="<?php echo esc_url( sap_project_url( $pid, 'settings' ) . '#site-connector' ); ?>">اتصال سایت</a></span>
			<?php endif; ?>
			<?php if ( $site_ok ) : ?>
				<button type="button" class="sap-btn is-small <?php echo $inv_ds ? 'is-ghost' : 'is-primary'; ?>" data-sap-step="site_inventory,keymap" data-project="<?php echo esc_attr( $pid ); ?>" data-force><?php echo sap_icon( 'refresh', 14 ); // phpcs:ignore ?><?php echo $inv_ds ? 'بروزرسانی فهرست' : 'دریافت فهرست کامل از وردپرس'; ?></button>
			<?php endif; ?>
		</div>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-primary" data-sap-step="audit_<?php echo esc_attr( $group ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" data-force <?php disabled( ! sap_ai_connected() || ! $all ); ?>><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تحلیل هوشمند <?php echo esc_html( sap_num( min( 15, count( $all ) ) ) ); ?> صفحه پربازدید «<?php echo esc_html( $groups[ $group ][0] ); ?>»</button>
			<button type="button" class="sap-btn is-soft" data-sap-cluster-sheet="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! $project['sheet_id'] ); ?>>نوشتن کلاسترینگ و فهرست محتوا در شیت</button>
			<a class="sap-btn is-ghost" href="<?php echo esc_url( sap_project_url( $pid, 'keywords' ) ); ?>">نقشه کلمات</a>
		</div>
		<?php if ( ! $project['sheet_id'] ) : ?><p class="sap-small sap-muted">برای نوشتن در شیت، اول در تب تقویم محتوا گوگل شیت پروژه را بسازید یا وصل کنید.</p><?php endif; ?>
	</div>
</section>

<nav class="sap-seg sap-audit-groups" aria-label="نوع محتوا">
	<?php foreach ( $groups as $g => $def ) : ?>
		<a class="<?php echo $g === $group ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'audit', array( 'group' => $g ) ) ); ?>"><?php echo esc_html( $def[0] ); ?> <small><?php echo esc_html( sap_num( count( $inv[ $g ] ?? array() ) ) ); ?></small></a>
	<?php endforeach; ?>
</nav>

<?php if ( ! $all ) : ?>
	<div class="sap-empty"><p>صفحه‌ای از نوع «<?php echo esc_html( $groups[ $group ][0] ); ?>» پیدا نشد.<?php echo $inv_ds ? '' : ' فهرست کامل وردپرس را بگیرید تا همه صفحات این نوع (حتی بدون نمایش در گوگل) بیایند.'; ?></p></div>
<?php else : ?>
	<div class="sap-audit-stats">
		<a href="<?php echo esc_url( sap_project_url( $pid, 'audit', array( 'group' => $group ) ) ); ?>" class="<?php echo '' === $filter ? 'is-active' : ''; ?>"><b><?php echo esc_html( sap_num( $st['total'] ) ); ?></b><span>کل صفحات</span></a>
		<a href="<?php echo esc_url( sap_project_url( $pid, 'audit', array( 'group' => $group, 'f' => 'zero' ) ) ); ?>" class="<?php echo 'zero' === $filter ? 'is-active' : ''; ?> <?php echo $st['zero'] ? 'is-warn' : ''; ?>"><b><?php echo esc_html( sap_num( $st['zero'] ) ); ?></b><span>بدون نمایش در گوگل</span></a>
		<a href="<?php echo esc_url( sap_project_url( $pid, 'audit', array( 'group' => $group, 'f' => 'nokw' ) ) ); ?>" class="<?php echo 'nokw' === $filter ? 'is-active' : ''; ?> <?php echo $st['nokw'] ? 'is-warn' : ''; ?>"><b><?php echo esc_html( sap_num( $st['nokw'] ) ); ?></b><span>بدون کلمه کلیدی</span></a>
		<a href="<?php echo esc_url( sap_project_url( $pid, 'audit', array( 'group' => $group, 'f' => 'weak' ) ) ); ?>" class="<?php echo 'weak' === $filter ? 'is-active' : ''; ?> <?php echo $st['weak'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( $st['weak'] ) ); ?></b><span>امتیاز زیر ۶۰</span></a>
		<?php if ( $st['noidx'] ) : ?><a href="<?php echo esc_url( sap_project_url( $pid, 'audit', array( 'group' => $group, 'f' => 'noidx' ) ) ); ?>" class="<?php echo 'noidx' === $filter ? 'is-active' : ''; ?>"><b><?php echo esc_html( sap_num( $st['noidx'] ) ); ?></b><span>noindex</span></a><?php endif; ?>
		<div class="is-score"><b><?php echo esc_html( sap_num( $st['score'] ) ); ?></b><span>میانگین امتیاز</span></div>
	</div>

	<section class="sap-panel">
		<form class="sap-audit-filter" method="get" action="<?php echo esc_url( sap_project_url( $pid, 'audit' ) ); ?>">
			<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>">
			<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="جستجو در عنوان، آدرس یا کلمه…">
			<select name="f">
				<?php foreach ( array( '' => 'همه صفحات', 'zero' => 'بدون نمایش در گوگل', 'nokw' => 'بدون کلمه کلیدی', 'weak' => 'امتیاز زیر ۶۰', 'misfit' => 'کلمه با نیت نامناسب', 'near' => 'جایگاه ۴ تا ۲۰ (فرصت)', 'done' => 'تحلیل‌شده با هوش مصنوعی', 'noidx' => 'noindex' ) as $fk => $fl ) : ?>
					<option value="<?php echo esc_attr( $fk ); ?>" <?php selected( $fk, $filter ); ?>><?php echo esc_html( $fl ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="sort">
				<?php foreach ( array( 'impr' => 'مرتب: بیشترین نمایش', 'clicks' => 'بیشترین کلیک', 'score' => 'کمترین امتیاز', 'words' => 'کمترین محتوا' ) as $sk => $sl ) : ?>
					<option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $sk, $sort ); ?>><?php echo esc_html( $sl ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="sap-btn is-small" type="submit">اعمال</button>
			<span class="sap-muted sap-small"><?php echo esc_html( sap_num( count( $rows ) ) ); ?> صفحه</span>
		</form>

		<div class="sap-table-wrap">
			<table class="sap-table sap-audit-table">
				<thead><tr><th><input type="checkbox" data-sap-audit-all aria-label="انتخاب همه"></th><th>صفحه</th><th>کلمات کلیدی</th><th>جایگاه</th><th>کلیک / نمایش</th><th>محتوا</th><th>امتیاز</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $shown as $r ) : ?>
						<?php $a = $audit[ sap_norm_url( $r['url'] ) ] ?? null; ?>
						<tr data-sap-audit-row="<?php echo esc_attr( $r['url'] ); ?>">
							<td><input type="checkbox" data-sap-audit-pick value="<?php echo esc_attr( $r['url'] ); ?>"></td>
							<td class="sap-audit-page">
								<?php if ( ! empty( $r['wp']['thumb'] ) ) : ?><img src="<?php echo esc_url( $r['wp']['thumb'] ); ?>" alt="" loading="lazy"><?php else : ?><span class="sap-audit-noimg"><?php echo sap_icon( $groups[ $group ][1], 16 ); // phpcs:ignore ?></span><?php endif; ?>
								<div>
									<a href="#" class="sap-audit-title" data-sap-audit-view="<?php echo esc_attr( $r['url'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $r['title'] ?: rawurldecode( $r['url'] ) ); ?></a>
									<small dir="ltr"><a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sap_short_url( $r['url'], 46 ) ); ?></a></small>
									<span class="sap-audit-tags">
										<?php if ( count( $groups[ $group ][2] ) > 1 ) : ?><span class="sap-chip"><?php echo esc_html( $types[ $r['type'] ] ?? $r['type'] ); ?></span><?php endif; ?>
										<?php if ( ! empty( $r['wp']['noindex'] ) ) : ?><span class="sap-chip is-bad">noindex</span><?php endif; ?>
										<?php if ( ! empty( $r['wp']['product'] ) && 'outofstock' === $r['wp']['product']['stock'] ) : ?><span class="sap-chip is-warn">ناموجود</span><?php endif; ?>
										<?php if ( $a ) : ?><span class="sap-chip <?php echo 'good' === $a['verdict'] ? 'is-good' : ( 'weak' === $a['verdict'] ? 'is-bad' : 'is-warn' ); ?>">تحلیل: <?php echo esc_html( array( 'good' => 'خوب', 'improve' => 'قابل بهبود', 'weak' => 'ضعیف' )[ $a['verdict'] ] ?? $a['verdict'] ); ?></span><?php endif; ?>
									</span>
								</div>
							</td>
							<td class="sap-audit-kw">
								<?php if ( '' !== $r['kw'] ) : ?>
									<b class="sap-audit-kwtxt" title="<?php echo esc_attr( $r['kw'] ); ?>"><?php echo esc_html( $r['kw'] ); ?></b><?php if ( 'seo' === $r['kw_src'] ) : ?> <small class="sap-chip" title="کلمه کانونی افزونه سئو؛ در نقشه کلمات نیست">افزونه سئو</small><?php endif; ?>
								<?php else : ?>
									<span class="sap-muted">بدون کلمه</span>
								<?php endif; ?>
								<?php if ( $r['secondary'] ) : ?><small class="sap-muted"> + <?php echo esc_html( sap_num( count( $r['secondary'] ) ) ); ?> فرعی</small><?php endif; ?>
								<?php if ( $r['queries'] && sap_kw_key( $r['queries'][0][0] ) !== sap_kw_key( $r['kw'] ) ) : ?><small class="sap-audit-topq" title="پرنمایش‌ترین کوئری سرچ کنسول">🔎 <?php echo esc_html( $r['queries'][0][0] ); ?></small><?php endif; ?>
								<?php if ( $r['misfits'] ) : ?>
									<span class="sap-audit-misfit" title="<?php echo esc_attr( implode( "\n", array_map( function ( $mf ) use ( $types ) {
										return '«' . $mf['keyword'] . '» ← مناسب ' . ( $types[ $mf['better'] ] ?? $mf['better'] );
									}, $r['misfits'] ) ) ); ?>">⚠ <?php echo esc_html( sap_num( count( $r['misfits'] ) ) ); ?> کلمه با نیت نامناسب</span>
								<?php endif; ?>
								<?php if ( ! empty( $r['km_status'] ) && ! sap_keymap_has_kw( $r['km_status'] ) ) : ?>
									<span class="sap-audit-misfit is-muted"><?php echo esc_html( sap_keymap_statuses()[ $r['km_status'] ] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo null === $r['pos'] ? '—' : esc_html( sap_num( $r['pos'], 1 ) ); ?></td>
							<td class="sap-nowrap"><?php echo esc_html( sap_num( $r['clicks'] ) . ' / ' . sap_num( $r['impr'] ) ); ?></td>
							<td class="sap-nowrap sap-small"><?php echo null === $r['words'] ? '—' : esc_html( sap_num( $r['words'] ) ) . ' کلمه'; ?><?php if ( isset( $r['wp']['count'] ) ) : ?><br><span class="sap-muted"><?php echo esc_html( sap_num( (int) $r['wp']['count'] ) ); ?> آیتم</span><?php endif; ?><?php if ( ! empty( $r['wp']['product'] ) ) : ?><br><span class="sap-muted"><?php echo esc_html( sap_num( (int) $r['wp']['product']['attrs'] ) ); ?> ویژگی</span><?php endif; ?></td>
							<td><span class="sap-score-pill <?php echo $r['score'] >= 85 ? 'is-good' : ( $r['score'] >= 60 ? 'is-warn' : 'is-bad' ); ?>" title="<?php echo esc_attr( implode( ' · ', array_column( $r['checks'], 0 ) ) ); ?>"><?php echo esc_html( sap_num( $r['score'] ) ); ?></span><?php if ( $r['checks'] ) : ?><small class="sap-muted sap-audit-nchk"><?php echo esc_html( sap_num( count( $r['checks'] ) ) ); ?> مورد</small><?php endif; ?></td>
							<td class="sap-nowrap sap-audit-btns">
								<?php if ( $a ) : ?>
									<button type="button" class="sap-btn is-small is-soft" data-sap-audit-view="<?php echo esc_attr( $r['url'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>">مشاهده تحلیل</button>
								<?php endif; ?>
								<button type="button" class="sap-btn is-small <?php echo $a ? 'is-ghost' : 'is-soft'; ?>" data-sap-audit-one="<?php echo esc_attr( $r['url'] ); ?>" data-group="<?php echo esc_attr( $group ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ai_connected() ); ?> title="تحلیل هوشمند این صفحه"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?><?php echo $a ? 'دوباره' : 'تحلیل'; ?></button>
								<button type="button" class="sap-btn is-small is-ghost" data-sap-update-add="<?php echo esc_attr( $r['url'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" title="افزودن به بخش آپدیت محتوای تقویم <?php echo esc_attr( sap_period_label( $period ) ); ?>">به تقویم</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $pages > 1 ) : ?>
			<nav class="sap-pager">
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<?php if ( $i <= 2 || $i > $pages - 2 || abs( $i - $pg ) <= 2 ) : ?>
						<a class="<?php echo $i === $pg ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'audit', $args + array( 'pg' => $i ) ) ); ?>"><?php echo esc_html( sap_num( $i ) ); ?></a>
					<?php elseif ( 3 === $i || $pages - 2 === $i ) : ?>
						<span>…</span>
					<?php endif; ?>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>
		<div class="sap-audit-bulk">
			<span class="sap-muted sap-small">انتخاب‌شده‌ها:</span>
			<button type="button" class="sap-btn is-small is-soft" data-sap-audit-picked="<?php echo esc_attr( $pid ); ?>" data-group="<?php echo esc_attr( $group ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>تحلیل هوشمند انتخاب‌شده‌ها</button>
			<select data-sap-update-period><?php foreach ( sap_period_options( 0, 2 ) as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $period ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
			<button type="button" class="sap-btn is-small" data-sap-update-bulk="<?php echo esc_attr( $pid ); ?>">افزودن به تقویم آپدیت</button>
		</div>
	</section>
<?php endif; ?>

<div class="sap-sheet" data-sap-audit-drawer hidden>
	<div class="sap-sheet-backdrop" data-sap-audit-close></div>
	<aside class="sap-sheet-panel" role="dialog" aria-label="تحلیل صفحه">
		<div class="sap-sheet-bar"><b>تحلیل صفحه</b><button type="button" class="sap-icon-btn" data-sap-audit-close aria-label="بستن"><?php echo sap_icon( 'x', 18 ); // phpcs:ignore ?></button></div>
		<div class="sap-sheet-body" data-sap-audit-body></div>
	</aside>
</div>

<div id="proposals">
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
?>
</div>
