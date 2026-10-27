<?php
/**
 * Content audit & rewrite: pick a page, see the audit, review each proposed
 * edit highlighted against the original, approve.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$pid     = $project['id'];
$rev     = isset( $_GET['rev'] ) ? sap_revision( absint( $_GET['rev'] ) ) : null;
$url     = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
$task_id = isset( $_GET['task'] ) ? absint( $_GET['task'] ) : 0;
// phpcs:enable
if ( $rev && $rev['project_id'] !== $pid ) {
	$rev = null;
}
$statuses = sap_revision_statuses();
$types    = sap_content_edit_types();
$start    = function ( $u, $label = 'تحلیل و بازنویسی محتوا', $cls = 'is-primary' ) use ( $pid, $task_id ) {
	printf(
		'<button type="button" class="sap-btn is-small %s" data-sap-content-start="%d" data-url="%s" data-task="%d" %s>%s%s</button>',
		esc_attr( $cls ),
		(int) $pid,
		esc_attr( $u ),
		(int) $task_id,
		disabled( ! sap_ai_connected(), true, false ),
		sap_icon( 'spark', 14 ), // phpcs:ignore
		esc_html( $label )
	);
};

if ( ! $rev ) :
	$map  = sap_keymap( $pid );
	$impr = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$impr[ sap_norm_url( $r['page'] ) ] = (int) $r['impressions'];
	}
	uksort( $map, function ( $a, $b ) use ( $impr ) {
		return ( $impr[ $b ] ?? 0 ) <=> ( $impr[ $a ] ?? 0 );
	} );
	?>
	<section class="sap-panel sap-content-intro">
		<h2><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تحلیل و بازنویسی هوشمند محتوا</h2>
		<p class="sap-muted">فقط محتوای اصلی صفحه (بدنه مقاله یا توضیحات محصول/دسته، بدون هدر، منو، سایدبار و فوتر) خوانده می‌شود. هوش مصنوعی آن را با کلمه اصلی و فرعی صفحه، کوئری‌های واقعی سرچ کنسول و سوالات کاربران می‌سنجد، می‌گوید چه چیزی نیاز به بروزرسانی دارد (لحن، واژه‌ها، نگارش، بخش جاافتاده، اطلاعات قدیمی) و تغییرات را پیشنهاد می‌دهد. هر تغییر با هایلایت کنار متن قبلی نمایش داده می‌شود؛ شما انتخاب و تأیید می‌کنید.</p>
		<?php if ( $url ) : ?>
			<div class="sap-content-pick">
				<?php echo sap_page_link( $url, sap_page_title( $pid, $url ) ); // phpcs:ignore ?>
				<?php $start( $url ); ?>
			</div>
		<?php endif; ?>
		<form class="sap-content-url" data-sap-content-form="<?php echo esc_attr( $pid ); ?>">
			<input type="url" name="url" dir="ltr" required placeholder="آدرس صفحه دیگر: https://…">
			<button class="sap-btn is-small" type="submit" <?php disabled( ! sap_ai_connected() ); ?>>شروع</button>
		</form>
		<?php if ( ! sap_ai_connected() ) : ?>
			<p class="sap-warn">کلید هوش مصنوعی در تنظیمات وارد نشده است.</p>
		<?php endif; ?>
	</section>

	<?php $old = sap_revisions( $pid ); ?>
	<?php if ( $old ) : ?>
		<section class="sap-panel">
			<h3>بازنویسی‌های قبلی</h3>
			<div class="sap-table-wrap"><table class="sap-table">
				<thead><tr><th>صفحه</th><th>نتیجه ممیزی</th><th>تغییرات</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $old as $r ) : ?>
					<tr>
						<td><?php echo sap_page_link( $r['url'], $r['title'] ?: sap_page_title( $pid, $r['url'] ) ); // phpcs:ignore ?></td>
						<td><?php echo esc_html( sap_content_verdicts()[ $r['audit']['verdict'] ?? '' ] ?? '—' ); ?></td>
						<td><?php echo esc_html( sap_num( count( $r['edits'] ) ) ); ?></td>
						<td><span class="sap-chip is-rev-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $statuses[ $r['status'] ] ?? $r['status'] ); ?></span></td>
						<td class="sap-muted"><?php echo esc_html( sap_jdate( $r['created_at'] ) ); ?></td>
						<td><a class="sap-btn is-small" href="<?php echo esc_url( sap_project_url( $pid, 'content', array( 'rev' => $r['id'] ) ) ); ?>">مشاهده</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		</section>
	<?php endif; ?>

	<section class="sap-panel">
		<h3>صفحات کلیدی سایت</h3>
		<div class="sap-content-pages">
			<?php foreach ( array_slice( $map, 0, 40, true ) as $k => $m ) : ?>
				<div class="sap-content-page">
					<div>
						<?php echo sap_page_link( $m['url'], $m['title'] ?: sap_page_title( $pid, $m['url'] ) ); // phpcs:ignore ?>
						<small class="sap-muted"><?php echo esc_html( ( $m['primary_kw'] ? 'کلمه اصلی: ' . $m['primary_kw'] : '' ) . ( ! empty( $impr[ $k ] ) ? ' · ' . sap_num( $impr[ $k ] ) . ' نمایش' : '' ) ); ?></small>
					</div>
					<?php $start( $m['url'], 'تحلیل', '' ); ?>
				</div>
			<?php endforeach; ?>
			<?php if ( ! $map ) : ?>
				<p class="sap-muted">بعد از «تحلیل کامل»، صفحات کلیدی اینجا می‌آیند. فعلاً آدرس صفحه را در کادر بالا وارد کنید.</p>
			<?php endif; ?>
		</div>
	</section>
	<?php
	return;
endif;

$audit  = $rev['audit'];
$blocks = (array) ( $rev['original']['blocks'] ?? array() );
$back   = sap_project_url( $pid, 'content' );
?>
<header class="sap-rev-head">
	<div>
		<p class="sap-eyebrow"><a href="<?php echo esc_url( $back ); ?>">تحلیل محتوا</a> / <?php echo esc_html( $statuses[ $rev['status'] ] ?? $rev['status'] ); ?></p>
		<h2><?php echo sap_page_link( $rev['url'], $rev['title'] ?: sap_page_title( $pid, $rev['url'] ) ); // phpcs:ignore ?></h2>
		<small class="sap-muted"><?php echo esc_html( sap_jdate( $rev['created_at'] ) . ' · ' . sap_num( count( $blocks ) ) . ' بلوک محتوا · هزینه ' . sap_num( $rev['cost'], 3 ) . ' دلار' ); ?></small>
	</div>
</header>

<?php if ( 'analyzing' === $rev['status'] ) : ?>
	<section class="sap-panel sap-rev-run" data-sap-rev-run="<?php echo esc_attr( $rev['id'] ); ?>">
		<h3><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>در حال تحلیل و بازنویسی…</h3>
		<div class="sap-progress"><i data-bar style="width:5%"></i></div>
		<p class="sap-muted" data-msg>ممیزی محتوا (چند ثانیه تا یک دقیقه)…</p>
	</section>
	<?php
	return;
endif;
?>

<section class="sap-panel sap-audit">
	<div class="sap-audit-top">
		<span class="sap-verdict is-<?php echo esc_attr( $audit['verdict'] ?? '' ); ?>"><?php echo esc_html( sap_content_verdicts()[ $audit['verdict'] ?? '' ] ?? '' ); ?></span>
		<p><?php echo esc_html( $audit['summary'] ?? '' ); ?></p>
	</div>
	<div class="sap-scores is-grid">
		<?php foreach ( sap_content_score_labels() as $k => $label ) : ?>
			<?php $v = (int) ( $audit['scores'][ $k ] ?? 0 ); ?>
			<div class="<?php echo $v <= 4 ? 'is-low' : ( $v >= 8 ? 'is-high' : '' ); ?>"><span><?php echo esc_html( $label ); ?></span><i style="--v:<?php echo esc_attr( $v * 10 ); ?>%"></i><b><?php echo esc_html( sap_num( $v ) ); ?></b></div>
		<?php endforeach; ?>
	</div>
	<?php if ( ! empty( $audit['tone_guide'] ) ) : ?>
		<p class="sap-small"><b>لحن هدف:</b> <?php echo esc_html( $audit['tone_guide'] ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $audit['missing_sections'] ) ) : ?>
		<p class="sap-label">بخش‌های جاافتاده</p>
		<ul class="sap-small"><?php foreach ( $audit['missing_sections'] as $m ) : ?><li><b><?php echo esc_html( $m['heading'] ); ?></b> — <?php echo esc_html( $m['brief'] ); ?></li><?php endforeach; ?></ul>
	<?php endif; ?>
	<?php if ( ! empty( $audit['issues'] ) ) : ?>
		<details class="sap-more"><summary><?php echo esc_html( sap_num( count( $audit['issues'] ) ) ); ?> مشکل شناسایی‌شده</summary>
			<ul class="sap-small"><?php foreach ( $audit['issues'] as $i ) : ?><li><span class="sap-chip"><?php echo esc_html( $types[ $i['type'] ] ?? $i['type'] ); ?></span> <?php echo $i['block'] ? '<a href="#blk-' . esc_attr( $i['block'] ) . '">بلوک ' . esc_html( sap_num( $i['block'] ) ) . '</a>: ' : ''; ?><?php echo esc_html( $i['detail'] ); ?></li><?php endforeach; ?></ul>
		</details>
	<?php endif; ?>
	<?php if ( ! empty( $audit['title'] ) || ! empty( $audit['meta'] ) ) : ?>
		<div class="sap-ai-output"><div class="sap-ai-output-head"><span>تایتل و متای پیشنهادی</span><button type="button" class="sap-icon-btn" data-sap-copy-target title="کپی"><?php echo sap_icon( 'log', 14 ); // phpcs:ignore ?></button></div><pre><?php echo esc_html( trim( ( $audit['title'] ? 'تایتل: ' . $audit['title'] . "\n" : '' ) . ( $audit['meta'] ? 'متا: ' . $audit['meta'] : '' ) ) ); ?></pre></div>
	<?php endif; ?>
</section>

<section class="sap-panel"><?php sap_view( 'comments', array( 'type' => 'revision', 'object_id' => $rev['id'], 'project_id' => $pid ) ); ?></section>

<?php
$by_block = array();
foreach ( $rev['edits'] as $e ) {
	$by_block[ $e['block'] ][] = $e;
}
$locked = in_array( $rev['status'], array( 'approved', 'published', 'rejected' ), true );
?>
<form class="sap-rev" data-sap-rev="<?php echo esc_attr( $rev['id'] ); ?>">
	<div class="sap-rev-bar">
		<div class="sap-seg" data-sap-rev-mode>
			<a href="#" class="is-active" data-mode="diff">تغییرات (هایلایت)</a>
			<a href="#" data-mode="new">متن جدید</a>
			<a href="#" data-mode="old">متن قبلی</a>
		</div>
		<span class="sap-muted"><b data-sap-rev-count><?php echo esc_html( sap_num( count( array_filter( $rev['edits'], function ( $e ) { return ! empty( $e['accept'] ); } ) ) ) ); ?></b> از <?php echo esc_html( sap_num( count( $rev['edits'] ) ) ); ?> تغییر انتخاب شده</span>
		<span class="sap-legend"><ins>افزوده</ins> <del>حذف‌شده</del></span>
	</div>

	<?php if ( ! $rev['edits'] ) : ?>
		<div class="sap-empty"><?php echo sap_icon( 'check', 28 ); // phpcs:ignore ?><p>هوش مصنوعی تغییری برای این محتوا پیشنهاد نکرد.</p></div>
	<?php endif; ?>

	<div class="sap-doc">
		<?php foreach ( $blocks as $b ) : ?>
			<?php
			$edits = $by_block[ $b['id'] ] ?? array();
			$rep   = null;
			foreach ( $edits as $e ) {
				if ( 'insert_after' !== $e['op'] ) {
					$rep = $e;
				}
			}
			?>
			<div class="sap-blk<?php echo $rep ? ' is-changed is-' . esc_attr( $rep['op'] ) : ''; ?>" id="blk-<?php echo esc_attr( $b['id'] ); ?>">
				<span class="sap-blk-n"><?php echo esc_html( sap_num( $b['id'] ) ); ?></span>
				<?php if ( $rep ) : ?>
					<div class="sap-edit<?php echo empty( $rep['accept'] ) ? ' is-off' : ''; ?>" data-edit="<?php echo esc_attr( $rep['id'] ); ?>">
						<div class="sap-edit-head">
							<label class="sap-check"><input type="checkbox" name="accepted[]" value="<?php echo esc_attr( $rep['id'] ); ?>" <?php checked( ! empty( $rep['accept'] ) ); ?> <?php disabled( $locked ); ?>> پذیرفتن</label>
							<span class="sap-chip is-type-<?php echo esc_attr( $rep['type'] ); ?>"><?php echo esc_html( $types[ $rep['type'] ] ?? $rep['type'] ); ?></span>
							<span class="sap-small"><?php echo esc_html( $rep['reason'] ); ?></span>
						</div>
						<div class="sap-v is-diff" data-v="diff"><<?php echo esc_attr( 'delete' === $rep['op'] ? 'p' : 'div' ); ?> class="sap-diff"><?php echo 'delete' === $rep['op'] ? '<del>' . esc_html( $b['text'] ) . '</del>' : sap_word_diff( $b['text'], sap_block_text( $rep['html'] ) ); // phpcs:ignore ?></<?php echo esc_attr( 'delete' === $rep['op'] ? 'p' : 'div' ); ?>></div>
						<div class="sap-v is-new" data-v="new" hidden><?php echo 'delete' === $rep['op'] ? '' : wp_kses( $rep['html'], sap_content_allowed_html() ); // phpcs:ignore ?></div>
						<div class="sap-v is-old" data-v="old" hidden><?php echo wp_kses( $b['html'], sap_content_allowed_html() ); // phpcs:ignore ?></div>
					</div>
				<?php else : ?>
					<div class="sap-v-same"><?php echo wp_kses( $b['html'], sap_content_allowed_html() ); // phpcs:ignore ?></div>
				<?php endif; ?>
			</div>
			<?php foreach ( $edits as $e ) : ?>
				<?php if ( 'insert_after' === $e['op'] ) : ?>
					<div class="sap-blk is-changed is-insert">
						<span class="sap-blk-n">+</span>
						<div class="sap-edit<?php echo empty( $e['accept'] ) ? ' is-off' : ''; ?>" data-edit="<?php echo esc_attr( $e['id'] ); ?>">
							<div class="sap-edit-head">
								<label class="sap-check"><input type="checkbox" name="accepted[]" value="<?php echo esc_attr( $e['id'] ); ?>" <?php checked( ! empty( $e['accept'] ) ); ?> <?php disabled( $locked ); ?>> پذیرفتن</label>
								<span class="sap-chip is-type-<?php echo esc_attr( $e['type'] ); ?>"><?php echo esc_html( $types[ $e['type'] ] ?? $e['type'] ); ?></span>
								<span class="sap-small"><?php echo esc_html( $e['reason'] ); ?></span>
							</div>
							<div class="sap-v is-insert-body" data-v="diff new"><?php echo wp_kses( $e['html'], sap_content_allowed_html() ); // phpcs:ignore ?></div>
						</div>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</div>

	<div class="sap-rev-foot">
		<?php if ( ! $locked ) : ?>
			<button type="submit" class="sap-btn is-primary"><?php echo sap_icon( 'check' ); // phpcs:ignore ?>تأیید تغییرات انتخاب‌شده</button>
			<button type="button" class="sap-btn is-ghost is-danger" data-sap-rev-reject>رد کل پیشنهاد</button>
		<?php else : ?>
			<span class="sap-chip is-rev-<?php echo esc_attr( $rev['status'] ); ?>"><?php echo esc_html( $statuses[ $rev['status'] ] ); ?></span>
		<?php endif; ?>
		<button type="button" class="sap-btn" data-sap-copy-html><?php echo sap_icon( 'log', 16 ); // phpcs:ignore ?>کپی HTML نهایی</button>
		<?php $start( $rev['url'], 'تحلیل دوباره', '' ); ?>
	</div>
	<textarea hidden data-sap-final-html><?php echo esc_textarea( sap_content_merge( $rev ) ); ?></textarea>
	<script type="application/json" data-sap-rev-edits><?php echo wp_json_encode( array_map( function ( $e ) { return array( 'id' => $e['id'], 'block' => $e['block'], 'op' => $e['op'], 'html' => $e['html'] ); }, $rev['edits'] ) ); ?></script>
	<script type="application/json" data-sap-rev-blocks><?php echo wp_json_encode( array_map( function ( $b ) { return array( 'id' => $b['id'], 'html' => $b['html'] ); }, $blocks ) ); ?></script>
</form>

<?php
$site_state = $rev['progress']['site'] ?? array();
$connected  = sap_site_connected( $project );
?>
<section class="sap-panel sap-publish" data-sap-rev-site="<?php echo esc_attr( $rev['id'] ); ?>">
	<h3><?php echo sap_icon( 'link' ); // phpcs:ignore ?>انتشار روی سایت</h3>
	<?php if ( ! $connected ) : ?>
		<p class="sap-muted">برای انتشار مستقیم، افزونه رابط (نسخه ۱٫۲) را روی سایت کارفرما نصب و در <a href="<?php echo esc_url( sap_project_url( $pid, 'settings' ) . '#site-connector' ); ?>">تنظیمات پروژه</a> وصل کنید. تا آن موقع «کپی HTML نهایی» را بزنید و در ویرایشگر وردپرس جایگزین کنید.</p>
	<?php elseif ( 'site' !== ( $rev['original']['source'] ?? '' ) ) : ?>
		<p class="sap-warn">این تحلیل از HTML صفحه انجام شده است. «تحلیل دوباره» را بزنید تا متن دقیق از خود وردپرس خوانده شود؛ بعد می‌توانید مستقیم منتشر کنید.</p>
	<?php elseif ( ! in_array( $rev['status'], array( 'approved', 'published' ), true ) ) : ?>
		<p class="sap-muted">اول تغییرات موردنظر را انتخاب و «تأیید» کنید؛ بعد اینجا پیش‌نویس روی سایت ساخته می‌شود.</p>
	<?php else : ?>
		<ol class="sap-publish-steps">
			<li class="<?php echo $site_state ? 'is-done' : 'is-current'; ?>">
				<b>۱. ساخت پیش‌نویس روی سایت</b>
				<span class="sap-muted">محتوا منتشر نمی‌شود؛ فقط یک لینک پیش‌نمایش ساخته می‌شود.</span>
				<?php if ( ! empty( $rev['audit']['title'] ) || ! empty( $rev['audit']['meta'] ) ) : ?>
					<label class="sap-check"><input type="checkbox" data-sap-site-seo> تایتل و متای پیشنهادی هم اعمال شود</label>
				<?php endif; ?>
				<button type="button" class="sap-btn is-small <?php echo $site_state ? '' : 'is-primary'; ?>" data-sap-site-do="draft"><?php echo $site_state ? 'ساخت دوباره پیش‌نویس' : 'ساخت پیش‌نویس'; ?></button>
			</li>
			<li class="<?php echo 'draft' === ( $site_state['state'] ?? '' ) ? 'is-current' : ( in_array( $site_state['state'] ?? '', array( 'published', 'rolled_back' ), true ) ? 'is-done' : '' ); ?>">
				<b>۲. بررسی پیش‌نمایش و انتشار</b>
				<?php if ( ! empty( $site_state['preview'] ) && 'draft' === $site_state['state'] ) : ?>
					<a class="sap-btn is-small" href="<?php echo esc_url( $site_state['preview'] ); ?>" target="_blank" rel="noopener">مشاهده پیش‌نمایش</a>
					<button type="button" class="sap-btn is-small is-primary" data-sap-site-do="publish">تأیید و انتشار</button>
					<button type="button" class="sap-btn is-small is-ghost" data-sap-site-do="discard">حذف پیش‌نویس</button>
				<?php elseif ( 'published' === ( $site_state['state'] ?? '' ) ) : ?>
					<span class="sap-chip is-src-manual">منتشر شد · <?php echo esc_html( sap_jdate( $site_state['published_at'] ?? '' ) ); ?></span>
				<?php endif; ?>
			</li>
			<?php if ( 'published' === ( $site_state['state'] ?? '' ) ) : ?>
				<li>
					<b>برگشت</b>
					<span class="sap-muted">اگر نتیجه مطلوب نبود، محتوا و فیلدهای تغییرکرده به نسخه قبل برمی‌گردند.</span>
					<button type="button" class="sap-btn is-small is-ghost is-danger" data-sap-site-do="rollback">برگشت به نسخه قبل</button>
				</li>
			<?php endif; ?>
		</ol>
	<?php endif; ?>
</section>

