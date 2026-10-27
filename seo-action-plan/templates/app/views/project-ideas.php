<?php
/**
 * Content ideas: new articles suggested by the AI from the project's data.
 * Approve one into a month of the content calendar, reject it (with a note, so
 * it is not suggested again), edit it or comment on it.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$statuses = sap_idea_statuses();
// phpcs:ignore WordPress.Security.NonceVerification
$show     = isset( $_GET['show'] ) && array_key_exists( sanitize_key( $_GET['show'] ), $statuses + array( 'all' => '' ) ) ? sanitize_key( $_GET['show'] ) : 'new';
$all      = sap_ideas( $pid );
$counts   = array_count_values( array_column( $all, 'status' ) );
$list     = 'all' === $show ? $all : array_values( array_filter( $all, function ( $i ) use ( $show ) {
	return $i['status'] === $show;
} ) );
$prio     = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
usort( $list, function ( $a, $b ) use ( $prio ) {
	return ( $prio[ $a['priority'] ] ?? 1 ) <=> ( $prio[ $b['priority'] ] ?? 1 ) ?: $b['volume'] <=> $a['volume'];
} );
$fnames   = wp_list_pluck( sap_focuses( $pid ), 'name', 'id' );
$months   = sap_period_options( 0, 4 );
$intents  = array( 'informational' => 'اطلاعاتی', 'commercial' => 'تجاری/مقایسه', 'transactional' => 'خرید' );
$plabel   = array( 'high' => 'اولویت بالا', 'medium' => 'اولویت متوسط', 'low' => 'اولویت پایین' );
$base     = sap_project_url( $pid, 'ideas' );
?>
<section class="sap-panel sap-ideas-intro">
	<div class="sap-keymap-head">
		<div>
			<h2><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>ایده‌های محتوای جدید</h2>
			<p class="sap-muted">هوش مصنوعی از کوئری‌های اطلاعاتی سرچ کنسول که صفحه ندارند، کلمات بدون صاحب، گپ رقبا و سوالات واقعی کاربران، ایده مقاله می‌سازد: کلمه اصلی، کلمات فرعی، عنوان، هدف (کدام صفحه پولساز یا Focus را تقویت می‌کند)، ساختار و ۵ لینک داخلی. موضوعات مقالات فعلی و ایده‌های ردشده تکرار نمی‌شوند. هر ایده را که تأیید کنید، با یک کلیک وارد تقویم محتوا و گوگل شیت می‌شود.</p>
		</div>
		<div class="sap-actions">
			<label class="sap-inline-label">تعداد <input type="number" class="is-tiny" min="3" max="16" value="10" data-sap-ideas-count></label>
			<button type="button" class="sap-btn is-primary" data-sap-ideas-gen="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>ساخت ایده‌های جدید</button>
		</div>
	</div>
</section>

<div class="sap-toolbar">
	<div class="sap-seg">
		<?php foreach ( array( 'new' => $statuses['new'], 'approved' => $statuses['approved'], 'scheduled' => $statuses['scheduled'], 'published' => $statuses['published'], 'rejected' => $statuses['rejected'], 'all' => 'همه' ) as $k => $label ) : ?>
			<a class="<?php echo $k === $show ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'show', $k, $base ) ); ?>"><?php echo esc_html( $label ); ?> <small><?php echo esc_html( sap_num( 'all' === $k ? count( $all ) : ( $counts[ $k ] ?? 0 ) ) ); ?></small></a>
		<?php endforeach; ?>
	</div>
	<a class="sap-btn is-small" href="<?php echo esc_url( sap_project_url( $pid, 'calendar' ) ); ?>"><?php echo sap_icon( 'clock', 14 ); // phpcs:ignore ?>تقویم محتوا</a>
</div>

<?php if ( ! $list ) : ?>
	<div class="sap-empty">
		<?php echo sap_icon( 'spark', 32 ); // phpcs:ignore ?>
		<p><?php echo 'new' === $show ? 'ایده جدیدی منتظر بررسی نیست. «ساخت ایده‌های جدید» را بزنید.' : 'موردی نیست.'; ?></p>
	</div>
<?php endif; ?>

<div class="sap-ideas">
	<?php foreach ( $list as $i ) : ?>
		<article class="sap-idea is-<?php echo esc_attr( $i['status'] ); ?>" data-sap-idea="<?php echo esc_attr( $i['id'] ); ?>">
			<header>
				<div>
					<h3><?php echo esc_html( $i['keyword'] ); ?></h3>
					<?php if ( $i['title'] ) : ?><p class="sap-idea-title"><?php echo esc_html( $i['title'] ); ?></p><?php endif; ?>
				</div>
				<div class="sap-idea-kp" title="حجم جستجوی ماهانه کلمه اصلی"><b><?php echo $i['kp'] ? esc_html( sap_num( $i['kp'] ) ) : '—'; ?></b><small>KP</small></div>
			</header>
			<div class="sap-idea-chips">
				<span class="sap-chip is-prio-<?php echo esc_attr( $i['priority'] ); ?>"><?php echo esc_html( $plabel[ $i['priority'] ] ?? '' ); ?></span>
				<?php if ( isset( $intents[ $i['intent'] ] ) ) : ?><span class="sap-chip"><?php echo esc_html( $intents[ $i['intent'] ] ); ?></span><?php endif; ?>
				<?php if ( $i['focus_id'] && isset( $fnames[ $i['focus_id'] ] ) ) : ?><span class="sap-chip is-focus"><?php echo sap_icon( 'target', 12 ); // phpcs:ignore ?><?php echo esc_html( $fnames[ $i['focus_id'] ] ); ?></span><?php endif; ?>
				<?php if ( $i['cluster'] ) : ?><span class="sap-chip"><?php echo esc_html( $i['cluster'] ); ?></span><?php endif; ?>
				<?php if ( 'scheduled' === $i['status'] && $i['period'] ) : ?><span class="sap-chip is-src-sheet">تقویم <?php echo esc_html( sap_period_label( $i['period'] ) ); ?></span><?php endif; ?>
			</div>
			<?php if ( $i['goal'] ) : ?><p class="sap-idea-goal"><b>هدف:</b> <?php echo esc_html( $i['goal'] ); ?></p><?php endif; ?>
			<?php if ( ! empty( $i['evidence'][0] ) ) : ?><p class="sap-small sap-muted"><b>استناد:</b> <?php echo esc_html( $i['evidence'][0] ); ?></p><?php endif; ?>
			<?php $sec = sap_idea_secondary( $i ); ?>
			<?php if ( $sec ) : ?>
				<div class="sap-kw-chips"><?php foreach ( $sec as $s ) : ?><span><?php echo esc_html( $s[0] ); ?><?php echo $s[1] ? '<small>' . esc_html( sap_num( $s[1] ) ) . '</small>' : ''; ?></span><?php endforeach; ?></div>
			<?php endif; ?>
			<details class="sap-more">
				<summary>بریف، ساختار و لینک‌های داخلی</summary>
				<?php if ( $i['brief'] ) : ?><p><?php echo esc_html( $i['brief'] ); ?></p><?php endif; ?>
				<?php if ( $i['outline'] ) : ?><p class="sap-label">ساختار پیشنهادی (H2)</p><ol class="sap-small"><?php foreach ( $i['outline'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ol><?php endif; ?>
				<?php if ( $i['links'] ) : ?>
					<p class="sap-label">لینک‌های خروجی (L / K)</p>
					<ul class="sap-small sap-idea-links"><?php foreach ( $i['links'] as $l ) : ?><li><?php echo sap_page_link( $l['url'], sap_page_title( $pid, $l['url'] ) ); // phpcs:ignore ?> ← «<?php echo esc_html( $l['anchor'] ); ?>»</li><?php endforeach; ?></ul>
				<?php endif; ?>
				<?php if ( $i['source_note'] ) : ?><p class="sap-small"><b>منبع پیشنهادی:</b> <?php echo esc_html( $i['source_note'] ); ?></p><?php endif; ?>
				<form class="sap-idea-edit" data-sap-idea-edit>
					<div class="sap-grid-2">
						<label>کلمه اصلی<input type="text" name="keyword" value="<?php echo esc_attr( $i['keyword'] ); ?>"></label>
						<label>عنوان<input type="text" name="title" value="<?php echo esc_attr( $i['title'] ); ?>"></label>
					</div>
					<label>کلمات فرعی (با ، جدا کنید)<input type="text" name="secondary" value="<?php echo esc_attr( implode( '، ', array_column( $sec, 0 ) ) ); ?>"></label>
					<label>هدف<input type="text" name="goal" value="<?php echo esc_attr( $i['goal'] ); ?>"></label>
					<button type="submit" class="sap-btn is-small">ذخیره ویرایش</button>
				</form>
			</details>
			<?php sap_view( 'comments', array( 'type' => 'idea', 'object_id' => $i['id'], 'project_id' => $pid ) ); ?>
			<footer class="sap-idea-actions">
				<?php if ( in_array( $i['status'], array( 'new', 'approved', 'rejected' ), true ) ) : ?>
					<select data-sap-idea-month>
						<?php foreach ( $months as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, sap_period_current() ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="sap-btn is-small is-primary" data-sap-idea-do="schedule"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?>تأیید و افزودن به تقویم</button>
				<?php endif; ?>
				<?php if ( 'new' === $i['status'] ) : ?>
					<button type="button" class="sap-btn is-small" data-sap-idea-do="approve">تأیید (زمان‌بندی بعداً)</button>
				<?php endif; ?>
				<?php if ( 'rejected' !== $i['status'] && 'published' !== $i['status'] ) : ?>
					<button type="button" class="sap-btn is-small is-ghost" data-sap-idea-do="reject" title="قبل از رد، دلیلش را در «دیدگاه من» بنویسید تا هوش مصنوعی یاد بگیرد">رد</button>
				<?php endif; ?>
				<button type="button" class="sap-btn is-small is-ghost is-danger" data-sap-idea-do="delete" title="حذف کامل"><?php echo sap_icon( 'trash', 14 ); // phpcs:ignore ?></button>
			</footer>
		</article>
	<?php endforeach; ?>
</div>
