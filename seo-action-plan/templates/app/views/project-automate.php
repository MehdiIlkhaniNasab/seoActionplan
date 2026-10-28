<?php
/**
 * Site automation with approval: internal links in the right sentence,
 * structured data by page type, smart image alt text and WooCommerce
 * attributes per category. Every change: preview → approve → applied on the
 * site → logged (activity + impact) → can be rolled back.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$sec   = sanitize_key( $_GET['s'] ?? 'links' ); // phpcs:ignore WordPress.Security.NonceVerification
$secs  = array(
	'links'      => array( 'لینک داخلی در جمله', 'link' ),
	'schema'     => array( 'اسکیما', 'code' ),
	'alts'       => array( 'آلت تصاویر', 'image' ),
	'attributes' => array( 'ویژگی محصولات', 'tag' ),
	'log'        => array( 'تغییرات اعمال‌شده', 'clock' ),
);
$sec   = isset( $secs[ $sec ] ) ? $sec : 'links';
$here  = sap_project_url( $pid, 'automate', array( 's' => $sec ) );
$ready = sap_auto_ready( $project, 'schema' );
$log   = sap_auto_log( $pid, '', 600 );
$live  = array_count_values( array_column( array_filter( $log, function ( $e ) {
	return 'live' === $e['status'];
} ), 'type' ) );
$types = sap_auto_types();
$pg    = max( 1, (int) ( $_GET['pg'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
?>
<section class="sap-panel sap-auto-head">
	<div class="sap-auto-intro">
		<h2><?php echo sap_icon( 'bolt' ); // phpcs:ignore ?>اتوماسیون سایت با تایید</h2>
		<p class="sap-muted">سکان تغییر را دقیق می‌سازد و پیش‌نمایش می‌دهد؛ شما تایید می‌کنید، روی سایت اعمال می‌شود، به‌صورت کار انجام‌شده در لاگ اقدامات و ارزیابی اثر ثبت می‌شود و هر وقت بخواهید با یک کلیک برمی‌گردد.</p>
		<?php if ( is_wp_error( $ready ) ) : ?>
			<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?><?php echo esc_html( $ready->get_error_message() ); ?> <a href="<?php echo esc_url( sap_project_url( $pid, 'settings' ) ); ?>">تنظیمات اتصال</a></p>
		<?php endif; ?>
	</div>
	<div class="sap-auto-kpis">
		<?php foreach ( $types as $k => $t ) : ?>
			<div><?php echo sap_icon( $t[1], 16 ); // phpcs:ignore ?><b><?php echo esc_html( sap_num( $live[ $k ] ?? 0 ) ); ?></b><span><?php echo esc_html( $t[0] ); ?></span></div>
		<?php endforeach; ?>
	</div>
</section>

<nav class="sap-seg sap-auto-nav">
	<?php foreach ( $secs as $k => $s ) : ?>
		<a class="<?php echo $k === $sec ? 'is-active' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'automate', array( 's' => $k ) ) ); ?>"><?php echo sap_icon( $s[1], 14 ); // phpcs:ignore ?><?php echo esc_html( $s[0] ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( 'links' === $sec ) : ?>
	<?php
	$queue = sap_link_queue( $project );
	$open  = array_values( array_filter( $queue, function ( $r ) {
		return ! $r['done'];
	} ) );
	$npg   = max( 1, (int) ceil( count( $queue ) / 20 ) );
	$cur   = min( $pg, $npg );
	?>
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>درج خودکار لینک داخلی در جمله درست<small>اگر عبارت انکر عیناً در متن باشد فقط لینک روی همان عبارت گذاشته می‌شود (نه در تیترها و نه روی لینک‌های موجود)؛ اگر نباشد، هوش مصنوعی مرتبط‌ترین جمله را با کمترین تغییر بازنویسی می‌کند. قبل از اعمال، جمله قبل و بعد را می‌بینید.</small></h2></div>
		<form class="sap-auto-form" data-sap-auto-form="link" data-project="<?php echo esc_attr( $pid ); ?>">
			<label>صفحه مبدأ (جایی که لینک می‌نشیند)<input type="url" name="source" dir="ltr" required placeholder="https://"></label>
			<label>صفحه مقصد<input type="url" name="target" dir="ltr" required placeholder="https://"></label>
			<label>انکر<input type="text" name="anchor" required placeholder="مثلاً: خرید نیم کفی طبی"></label>
			<button class="sap-btn is-primary" type="submit" <?php disabled( is_wp_error( $ready ) ); ?>>پیش‌نمایش درج</button>
		</form>
		<?php if ( ! $queue ) : ?>
			<div class="sap-empty"><?php echo sap_icon( 'link', 28 ); // phpcs:ignore ?><p>پیشنهاد لینک داخلی بازی نیست. پیشنهادهای لینک‌سازی از تحلیل محتوا (تب «سئو محتوا») می‌آیند؛ یا بالا دستی وارد کنید.</p></div>
		<?php else : ?>
			<h3 class="sap-h3">پیشنهادهای لینک‌سازی (<?php echo esc_html( sap_num( count( $open ) ) ); ?> باز از <?php echo esc_html( sap_num( count( $queue ) ) ); ?>)</h3>
			<ul class="sap-link-q">
				<?php foreach ( array_slice( $queue, ( $cur - 1 ) * 20, 20 ) as $r ) : ?>
					<li class="<?php echo $r['done'] ? 'is-done' : ''; ?>">
						<div class="sap-link-q-path">
							<p><span class="sap-muted sap-small">از</span> <b><?php echo esc_html( $r['source_t'] ?: sap_page_title( $pid, $r['source'] ) ?: sap_short_url( $r['source'], 40 ) ); ?></b> <?php echo sap_url_tag( $r['source'], 40 ); // phpcs:ignore ?></p>
							<p><span class="sap-muted sap-small">به</span> <b><?php echo esc_html( sap_page_title( $pid, $r['target'] ) ?: sap_short_url( $r['target'], 40 ) ); ?></b> <?php echo sap_url_tag( $r['target'], 40 ); // phpcs:ignore ?></p>
							<p class="sap-link-q-anchor">انکر: <mark>«<?php echo esc_html( $r['anchor'] ); ?>»</mark><?php echo $r['placement'] ? ' <span class="sap-muted sap-small">· ' . esc_html( $r['placement'] ) . '</span>' : ''; ?></p>
						</div>
						<?php if ( $r['done'] ) : ?>
							<span class="sap-chip is-good">درج شد · <?php echo esc_html( sap_jdate( $r['done']['at'], 'short' ) ); ?></span>
						<?php else : ?>
							<button type="button" class="sap-btn is-soft" data-sap-auto-preview="link" data-project="<?php echo esc_attr( $pid ); ?>" data-source="<?php echo esc_attr( $r['source'] ); ?>" data-target="<?php echo esc_attr( $r['target'] ); ?>" data-anchor="<?php echo esc_attr( $r['anchor'] ); ?>" data-finding_id="<?php echo esc_attr( $r['finding_id'] ); ?>" <?php disabled( is_wp_error( $ready ) ); ?>>پیش‌نمایش درج</button>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php echo sap_pager_html( $npg, $cur, $here ); // phpcs:ignore ?>
		<?php endif; ?>
	</section>

<?php elseif ( 'schema' === $sec ) : ?>
	<?php
	$inv     = sap_site_inventory( $pid );
	$front   = (int) ( sap_dataset_payload( $pid, 'site_inventory' )['front'] ?? 0 );
	$natures = array( 'product' => 'محصولات', 'article' => 'مقالات', 'category' => 'دسته‌ها', 'page' => 'برگه‌ها', 'home' => 'صفحه اصلی' );
	$nat     = sanitize_key( $_GET['t'] ?? 'product' ); // phpcs:ignore WordPress.Security.NonceVerification
	$nat     = isset( $natures[ $nat ] ) ? $nat : 'product';
	$q       = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$has     = array();
	foreach ( $log as $e ) {
		if ( 'schema' === $e['type'] && 'live' === $e['status'] && ! isset( $has[ sap_norm_url( $e['url'] ) ] ) ) {
			$has[ sap_norm_url( $e['url'] ) ] = $e;
		}
	}
	$counts = array();
	$rows   = array();
	foreach ( $inv as $k => $it ) {
		if ( $it['redirect'] || $it['noindex'] ) {
			continue;
		}
		$t            = sap_inventory_page_type( $it['ctype'], $it, $front );
		$counts[ $t ] = ( $counts[ $t ] ?? 0 ) + 1;
		if ( $t === $nat && ( '' === $q || false !== mb_stripos( $it['title'] . ' ' . rawurldecode( $it['url'] ), $q ) ) ) {
			$rows[] = $it + array( 'done' => $has[ $k ] ?? null );
		}
	}
	usort( $rows, function ( $a, $b ) {
		return ( $a['done'] ? 1 : 0 ) <=> ( $b['done'] ? 1 : 0 );
	} );
	$npg  = max( 1, (int) ceil( count( $rows ) / 20 ) );
	$cur  = min( $pg, $npg );
	$base = add_query_arg( array_filter( array( 't' => $nat, 'q' => $q ) ), $here );
	?>
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>اسکیمای هوشمند بر اساس ماهیت صفحه<small>محصول: Product با قیمت (تومان به ریال تبدیل می‌شود)، موجودی، برند، امتیاز و مشخصات · مقاله: BlogPosting · دسته: CollectionPage با فهرست محصولات · همه: Breadcrumb · FAQ از سوال‌وجواب‌های خود متن. نوعی که افزونه دیگری (Rank Math، Yoast، ووکامرس) از قبل چاپ می‌کند تکراری اضافه نمی‌شود.</small></h2></div>
		<form class="sap-auto-form is-one" data-sap-auto-form="schema" data-project="<?php echo esc_attr( $pid ); ?>">
			<label>آدرس صفحه<input type="url" name="url" dir="ltr" required placeholder="https://"></label>
			<button class="sap-btn is-primary" type="submit" <?php disabled( is_wp_error( $ready ) ); ?>>ساخت و پیش‌نمایش اسکیما</button>
		</form>
		<?php if ( ! $inv ) : ?>
			<p class="sap-muted">برای فهرست صفحات، همگام‌سازی «فهرست محتوای سایت» را در تحلیل کامل اجرا کنید.</p>
		<?php else : ?>
			<div class="sap-auto-filter">
				<nav class="sap-seg is-small">
					<?php foreach ( $natures as $k => $l ) : ?>
						<a class="<?php echo $k === $nat ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 't', $k, $here ) ); ?>"><?php echo esc_html( $l ); ?> <small><?php echo esc_html( sap_num( $counts[ $k ] ?? 0 ) ); ?></small></a>
					<?php endforeach; ?>
				</nav>
				<form method="get" action="<?php echo esc_url( $here ); ?>" class="sap-auto-search">
					<input type="hidden" name="s" value="schema"><input type="hidden" name="t" value="<?php echo esc_attr( $nat ); ?>">
					<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="جستجوی عنوان یا آدرس">
				</form>
			</div>
			<ul class="sap-auto-pages">
				<?php foreach ( array_slice( $rows, ( $cur - 1 ) * 20, 20 ) as $it ) : ?>
					<li>
						<?php echo $it['thumb'] ? '<img src="' . esc_url( $it['thumb'] ) . '" alt="" loading="lazy">' : '<span class="sap-auto-ph">' . sap_icon( 'code', 16 ) . '</span>'; // phpcs:ignore ?>
						<div><b><?php echo esc_html( $it['title'] ); ?></b><?php echo sap_url_tag( $it['url'], 46 ); // phpcs:ignore ?></div>
						<?php if ( $it['done'] ) : ?><span class="sap-chip is-good" title="<?php echo esc_attr( $it['done']['label'] ); ?>">اسکیما دارد</span><?php endif; ?>
						<button type="button" class="sap-btn is-soft is-small" data-sap-auto-preview="schema" data-project="<?php echo esc_attr( $pid ); ?>" data-url="<?php echo esc_attr( $it['url'] ); ?>" <?php disabled( is_wp_error( $ready ) ); ?>><?php echo $it['done'] ? 'بازسازی' : 'ساخت اسکیما'; ?></button>
					</li>
				<?php endforeach; ?>
				<?php if ( ! $rows ) : ?><li class="sap-muted">صفحه‌ای پیدا نشد.</li><?php endif; ?>
			</ul>
			<?php echo sap_pager_html( $npg, $cur, $base ); // phpcs:ignore ?>
		<?php endif; ?>
	</section>

<?php elseif ( 'alts' === $sec ) : ?>
	<?php
	$aq  = sap_alt_queue( $project, 2000 );
	$npg = max( 1, (int) ceil( count( $aq['rows'] ) / 20 ) );
	$cur = min( $pg, $npg );
	?>
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>متن جایگزین هوشمند تصاویر<small>برای هر تصویر یک alt اختصاصی: از نام فایل، جای تصویر (شاخص، گالری، داخل متن)، تیتری که تصویر زیر آن است و مشخصات محصول. کلمه کلیدی فقط در تصویر شاخص و حداکثر یک تصویر دیگر می‌آید (بدون تکرار). هم در کتابخانه رسانه و هم داخل متن ثبت می‌شود.</small></h2></div>
		<form class="sap-auto-form is-one" data-sap-auto-form="alt" data-project="<?php echo esc_attr( $pid ); ?>">
			<label>آدرس صفحه<input type="url" name="url" dir="ltr" required placeholder="https://"></label>
			<label class="sap-check"><input type="checkbox" name="all" value="1"> همه تصاویر (نه فقط بدون alt یا ضعیف)</label>
			<button class="sap-btn is-primary" type="submit" <?php disabled( is_wp_error( $ready ) ); ?>>ساخت alt و پیش‌نمایش</button>
		</form>
		<h3 class="sap-h3">صفحات دارای تصویر بدون alt (<?php echo esc_html( sap_num( $aq['total'] ) ); ?>)</h3>
		<?php if ( ! $aq['rows'] ) : ?>
			<p class="sap-muted">در فهرست محتوای سایت صفحه‌ای با تصویر بدون alt نیست (یا فهرست هنوز همگام نشده).</p>
		<?php else : ?>
			<ul class="sap-auto-pages">
				<?php foreach ( array_slice( $aq['rows'], ( $cur - 1 ) * 20, 20 ) as $r ) : ?>
					<li>
						<?php echo $r['thumb'] ? '<img src="' . esc_url( $r['thumb'] ) . '" alt="" loading="lazy">' : '<span class="sap-auto-ph">' . sap_icon( 'image', 16 ) . '</span>'; // phpcs:ignore ?>
						<div><b><?php echo esc_html( $r['title'] ); ?></b><?php echo sap_url_tag( $r['url'], 46 ); // phpcs:ignore ?></div>
						<span class="sap-chip is-warn"><?php echo esc_html( sap_num( $r['no_alt'] ) . ' از ' . sap_num( $r['images'] ) ); ?> بدون alt</span>
						<button type="button" class="sap-btn is-soft is-small" data-sap-auto-preview="alt" data-project="<?php echo esc_attr( $pid ); ?>" data-url="<?php echo esc_attr( $r['url'] ); ?>" <?php disabled( is_wp_error( $ready ) ); ?>>ساخت alt</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php echo sap_pager_html( $npg, $cur, $here ); // phpcs:ignore ?>
		<?php endif; ?>
	</section>

<?php elseif ( 'attributes' === $sec ) : ?>
	<?php
	$site = is_wp_error( $ready ) ? $ready : sap_attr_site( $project );
	$sets = sap_attr_sets( $project );
	$cats = is_wp_error( $site ) ? array() : array_values( array_filter( (array) $site['categories'], function ( $c ) {
		return 'uncategorized' !== $c['slug'];
	} ) );
	usort( $cats, function ( $a, $b ) {
		return $b['count'] <=> $a['count'];
	} );
	$cid = absint( $_GET['cat'] ?? ( $cats[0]['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$cat = null;
	foreach ( $cats as $c ) {
		if ( (int) $c['id'] === $cid ) {
			$cat = $c;
		}
	}
	$set   = $cat ? ( $sets[ $cid ] ?? null ) : null;
	$prods = $cat ? sap_attr_products( $project, $cat['name'], 5000 ) : array();
	$empty = array_values( array_filter( $prods, function ( $r ) {
		return 0 === $r['attrs'];
	} ) );
	$npg   = max( 1, (int) ceil( count( $prods ) / 20 ) );
	$cur   = min( $pg, $npg );
	?>
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>ویژگی‌های ثابت هر دسته (ویژگی سراسری ووکامرس)<small>یک بار برای هر دسته مجموعه ویژگی تعریف می‌شود و به‌صورت «ویژگی سراسری» در ووکامرس ساخته می‌شود؛ در هر محصول فقط مقدار انتخاب می‌شود. این یعنی داده تمیز و یکدست و فیلترهای سایدبار فروشگاه بعداً بدون دردسر کار می‌کنند.</small></h2></div>
		<?php if ( is_wp_error( $site ) ) : ?>
			<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?><?php echo esc_html( $site->get_error_message() ); ?></p>
		<?php elseif ( empty( $site['woocommerce'] ) ) : ?>
			<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?>ووکامرس روی سایت این پروژه فعال نیست.</p>
		<?php else : ?>
			<div class="sap-attr-layout">
				<aside class="sap-attr-cats">
					<?php foreach ( $cats as $c ) : ?>
						<a class="<?php echo (int) $c['id'] === $cid ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'cat', $c['id'], $here ) ); ?>"><span><?php echo esc_html( $c['name'] ); ?></span><small><?php echo esc_html( sap_num( $c['count'] ) ); ?></small><?php echo isset( $sets[ $c['id'] ] ) ? '<i class="' . ( $sets[ $c['id'] ]['synced'] ? 'is-ok' : 'is-draft' ) . '" title="' . ( $sets[ $c['id'] ]['synced'] ? 'در ووکامرس ثبت شده' : 'ذخیره شده، ثبت نشده' ) . '"></i>' : ''; ?></a>
					<?php endforeach; ?>
				</aside>
				<?php if ( $cat ) : ?>
					<div class="sap-attr-set" data-sap-attr-set data-project="<?php echo esc_attr( $pid ); ?>" data-cat="<?php echo esc_attr( $cid ); ?>" data-cat_name="<?php echo esc_attr( $cat['name'] ); ?>">
						<div class="sap-attr-set-head">
							<h3>مجموعه ویژگی «<?php echo esc_html( $cat['name'] ); ?>»</h3>
							<?php if ( $set ) : ?><span class="sap-chip <?php echo $set['synced'] ? 'is-good' : 'is-warn'; ?>"><?php echo $set['synced'] ? 'در ووکامرس ثبت شده · ' . esc_html( sap_jdate( $set['synced'], 'short' ) ) : 'ذخیره شده؛ هنوز در ووکامرس ثبت نشده'; ?></span><?php endif; ?>
							<button type="button" class="sap-btn is-ghost is-small" data-sap-attr-suggest <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>پیشنهاد هوشمند</button>
						</div>
						<div class="sap-attr-rows" data-sap-attr-rows>
							<?php foreach ( ( $set['attrs'] ?? array( array( 'name' => '', 'slug' => '', 'values' => array(), 'filter' => true ) ) ) as $a ) : ?>
								<div class="sap-attr-row">
									<input type="text" data-f="name" value="<?php echo esc_attr( $a['name'] ); ?>" placeholder="نام ویژگی (مثلاً رنگ)">
									<input type="text" data-f="slug" value="<?php echo esc_attr( $a['slug'] ); ?>" dir="ltr" placeholder="slug (color)">
									<textarea data-f="values" rows="2" placeholder="مقادیر مجاز، با ویرگول یا خط جدا"><?php echo esc_textarea( implode( '، ', (array) $a['values'] ) ); ?></textarea>
									<label class="sap-check"><input type="checkbox" data-f="filter" <?php checked( ! empty( $a['filter'] ) ); ?>> فیلتر</label>
									<button type="button" class="sap-icon-btn" data-sap-attr-del title="حذف"><?php echo sap_icon( 'trash', 16 ); // phpcs:ignore ?></button>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="sap-actions">
							<button type="button" class="sap-btn is-ghost" data-sap-attr-add><?php echo sap_icon( 'plus', 14 ); // phpcs:ignore ?>ویژگی</button>
							<button type="button" class="sap-btn is-soft" data-sap-attr-save="0">ذخیره</button>
							<button type="button" class="sap-btn is-primary" data-sap-attr-save="1">ذخیره و ساخت در ووکامرس</button>
						</div>
						<p class="sap-small sap-muted">اگر ویژگی سراسری هم‌نام از قبل در سایت باشد همان استفاده می‌شود (ویژگی تکراری ساخته نمی‌شود). ویژگی‌های فعلی سایت: <?php echo esc_html( implode( '، ', array_column( (array) $site['attributes'], 'name' ) ) ?: 'هیچ' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php if ( $cat && ! is_wp_error( $site ) ) : ?>
		<section class="sap-panel">
			<div class="sap-panel-head">
				<h2>محصولات «<?php echo esc_html( $cat['name'] ); ?>»<small>توضیحات محصول را بدهید (یا خالی بگذارید تا از متن خود محصول خوانده شود)؛ مقدار هر ویژگی فقط از مقادیر مجاز همان دسته انتخاب می‌شود.</small></h2>
				<?php if ( $set && $set['synced'] && $empty ) : ?>
					<button type="button" class="sap-btn is-soft" data-sap-attr-bulk data-project="<?php echo esc_attr( $pid ); ?>" data-cat="<?php echo esc_attr( $cid ); ?>" data-urls="<?php echo esc_attr( wp_json_encode( array_slice( array_column( $empty, 'url' ), 0, 20 ) ) ); ?>"><?php echo sap_icon( 'bolt', 14 ); // phpcs:ignore ?>پردازش گروهی <?php echo esc_html( sap_num( min( 20, count( $empty ) ) ) ); ?> محصول بدون ویژگی</button>
				<?php endif; ?>
			</div>
			<?php if ( ! $set || ! $set['synced'] ) : ?>
				<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?>اول مجموعه ویژگی این دسته را ذخیره و در ووکامرس بسازید.</p>
			<?php endif; ?>
			<div data-sap-attr-bulk-out></div>
			<ul class="sap-auto-pages">
				<?php foreach ( array_slice( $prods, ( $cur - 1 ) * 20, 20 ) as $r ) : ?>
					<li class="has-form">
						<?php echo $r['thumb'] ? '<img src="' . esc_url( $r['thumb'] ) . '" alt="" loading="lazy">' : '<span class="sap-auto-ph">' . sap_icon( 'tag', 16 ) . '</span>'; // phpcs:ignore ?>
						<div><b><?php echo esc_html( $r['title'] ); ?></b><?php echo sap_url_tag( $r['url'], 46 ); // phpcs:ignore ?></div>
						<span class="sap-chip <?php echo $r['attrs'] ? 'is-soft' : 'is-warn'; ?>"><?php echo $r['attrs'] ? esc_html( sap_num( $r['attrs'] ) ) . ' ویژگی' : 'بدون ویژگی'; ?></span>
						<button type="button" class="sap-btn is-soft is-small" data-sap-attr-open <?php disabled( ! $set || ! $set['synced'] ); ?>>تکمیل ویژگی‌ها</button>
						<form class="sap-attr-desc" data-sap-auto-form="attr" data-project="<?php echo esc_attr( $pid ); ?>" hidden>
							<input type="hidden" name="url" value="<?php echo esc_attr( $r['url'] ); ?>"><input type="hidden" name="cat" value="<?php echo esc_attr( $cid ); ?>">
							<textarea name="description" rows="4" placeholder="توضیحات یا مشخصات محصول (مثلاً از کاتالوگ تامین‌کننده). خالی = متن فعلی محصول"></textarea>
							<button class="sap-btn is-primary is-small" type="submit"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>استخراج ویژگی‌ها</button>
						</form>
					</li>
				<?php endforeach; ?>
				<?php if ( ! $prods ) : ?><li class="sap-muted">محصولی از این دسته در فهرست محتوای سایت نیست (فهرست را همگام کنید).</li><?php endif; ?>
			</ul>
			<?php echo sap_pager_html( $npg, $cur, add_query_arg( 'cat', $cid, $here ) ); // phpcs:ignore ?>
		</section>
	<?php endif; ?>

<?php else : ?>
	<?php
	$npg = max( 1, (int) ceil( count( $log ) / 25 ) );
	$cur = min( $pg, $npg );
	?>
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>تغییرات اعمال‌شده روی سایت<small>هر تغییر با نسخه پشتیبان ذخیره شده و قابل برگشت است.</small></h2></div>
		<?php if ( ! $log ) : ?>
			<div class="sap-empty"><?php echo sap_icon( 'clock', 28 ); // phpcs:ignore ?><p>هنوز تغییری اعمال نشده.</p></div>
		<?php else : ?>
			<ul class="sap-auto-log">
				<?php foreach ( array_slice( $log, ( $cur - 1 ) * 25, 25 ) as $e ) : ?>
					<?php $t = $types[ $e['type'] ] ?? array( $e['type'], 'bolt' ); ?>
					<li class="<?php echo 'live' === $e['status'] ? '' : 'is-rb'; ?>">
						<span class="sap-auto-ico"><?php echo sap_icon( $t[1], 16 ); // phpcs:ignore ?></span>
						<div>
							<b><?php echo esc_html( $e['label'] ); ?></b>
							<p class="sap-muted sap-small"><?php echo esc_html( $t[0] . ' · ' . sap_jdate( $e['at'], 'long' ) . ( $e['user'] ? ' · ' . $e['user'] : '' ) ); ?><?php echo 'live' !== $e['status'] ? ' · برگردانده شد' . ( ! empty( $e['rb_at'] ) ? ' در ' . esc_html( sap_jdate( $e['rb_at'], 'short' ) ) : '' ) : ''; ?></p>
							<details><summary>جزئیات</summary><pre dir="auto"><?php echo esc_html( $e['detail'] ); ?></pre></details>
						</div>
						<?php echo sap_url_tag( $e['url'], 34 ); // phpcs:ignore ?>
						<?php if ( 'live' === $e['status'] ) : ?>
							<button type="button" class="sap-btn is-ghost is-small" data-sap-auto-rollback="<?php echo esc_attr( $e['ref'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>">برگرداندن</button>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php echo sap_pager_html( $npg, $cur, $here ); // phpcs:ignore ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<div class="sap-modal" data-sap-auto-modal hidden>
	<div class="sap-modal-box" role="dialog" aria-modal="true">
		<button type="button" class="sap-modal-x" data-sap-auto-close aria-label="بستن">×</button>
		<div data-sap-auto-body></div>
	</div>
</div>
