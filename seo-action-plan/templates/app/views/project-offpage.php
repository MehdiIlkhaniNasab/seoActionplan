<?php
/**
 * Off-page pillar: budget, target keywords per focus, SEO Signal media and
 * competitor reportage data, the AI plan and its proposals.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid     = $project['id'];
$set     = sap_offpage_settings( $project );
$targets = sap_offpage_targets( $project );
$data    = (array) sap_dataset_payload( $pid, 'offpage_data' );
$comp    = sap_offpage_competitor_summary( $data );
$plan    = sap_dataset( $pid, 'ai_offpage' );
$types   = sap_offpage_types();
$here    = sap_project_url( $pid, 'offpage' );
?>
<section class="sap-panel sap-pillar-head">
	<div class="sap-pillar-intro">
		<h2><?php echo sap_icon( 'link' ); // phpcs:ignore ?>سئو آف‌پیج و لینک‌سازی</h2>
		<p class="sap-muted">برنامه آف‌پیج از کلمات هدف Focusها، جایگاه فعلی‌شان، بودجه ماهانه، رسانه‌های پیشنهادی سئو سیگنال و پروفایل رپورتاژ رقبا ساخته می‌شود: لینک‌سازی رایگان، برند منشن، گست پست و رپورتاژ روی رسانه مشخص با صفحه مقصد، انکر و قیمت. هر اقدام بعد از تأیید شما وارد اکشن پلن ماه می‌شود.</p>
	</div>
</section>

<div class="sap-grid-2 sap-offpage-top">
	<section class="sap-panel">
		<h3>بودجه و تنظیمات</h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sap-form">
			<?php sap_form_fields( 'sap_offpage_settings', array( 'project_id' => $pid ) ); ?>
			<div class="sap-grid-2">
				<label>بودجه آف‌پیج این ماه (تومان)<input type="text" name="budget" dir="ltr" value="<?php echo esc_attr( $set['budget'] ? number_format( $set['budget'] ) : '' ); ?>" placeholder="مثلاً 20,000,000"><small>صفر یا خالی = فقط روش‌های رایگان.</small></label>
				<label>نام برند (برای انکر برند و منشن)<input type="text" name="brand" value="<?php echo esc_attr( $set['brand'] ); ?>" placeholder="<?php echo esc_attr( implode( '، ', (array) $project['brand_terms'] ) ); ?>"></label>
			</div>
			<label>یادداشت برای برنامه<textarea name="notes" rows="2" placeholder="مثلاً: این ماه روی دسته کفی طبی تمرکز کن؛ رپورتاژ فقط در رسانه‌های پزشکی"><?php echo esc_textarea( $set['notes'] ); ?></textarea></label>
			<label>رسانه‌ها یا روش‌هایی که نمی‌خواهید<textarea name="avoid" rows="2"><?php echo esc_textarea( $set['avoid'] ); ?></textarea></label>
			<button class="sap-btn is-primary" type="submit">ذخیره</button>
		</form>
	</section>
	<section class="sap-panel">
		<h3>داده‌های سئو سیگنال برای برنامه</h3>
		<ul class="sap-ss-steps">
			<li class="<?php echo ! empty( $data['media'] ) ? 'is-done' : 'is-todo'; ?>"><i><?php echo ! empty( $data['media'] ) ? '✓' : '!'; ?></i><div><b>رسانه‌های پیشنهادی برای کلمات هر Focus</b><p class="sap-small"><?php echo ! empty( $data['media'] ) ? esc_html( implode( '، ', array_map( function ( $f, $m ) {
				return $f . ': ' . sap_num( count( (array) $m['rows'] ) );
			}, array_keys( $data['media'] ), $data['media'] ) ) ) : 'پیشنهاد رسانه رپورتاژ سئو سیگنال؛ هر Focus یک واحد (فقط پلن دارای دستیار رپورتاژ).'; ?></p>
				<button type="button" class="sap-btn is-small is-soft" data-sap-offpage-collect="media" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ss_connected() || ! $targets ); ?>>دریافت (<?php echo esc_html( sap_num( count( $targets ) ) ); ?> واحد)</button></div></li>
			<li class="<?php echo $comp ? 'is-done' : 'is-todo'; ?>"><i><?php echo $comp ? '✓' : '!'; ?></i><div><b>پروفایل رپورتاژ رقبا (۹۰ روز اخیر)</b><p class="sap-small"><?php echo $comp ? esc_html( implode( '، ', array_map( function ( $d, $c ) {
				return $d . ': ' . sap_num( $c['count'] );
			}, array_keys( $comp ), $comp ) ) ) : 'رقبا کجا رپورتاژ خریده‌اند و چقدر هزینه کرده‌اند؛ هر رقیب یک واحد + سقف ماهانه SRLimit.'; ?></p>
				<button type="button" class="sap-btn is-small is-soft" data-sap-offpage-collect="competitors" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ss_connected() || ! $project['competitors'] ); ?>>دریافت (<?php echo esc_html( sap_num( min( 4, count( (array) $project['competitors'] ) ) ) ); ?> واحد)</button>
				<?php if ( ! $project['competitors'] ) : ?><a class="sap-small" href="<?php echo esc_url( sap_project_url( $pid, 'competitors' ) ); ?>">ثبت رقبا</a><?php endif; ?></div></li>
		</ul>
		<button type="button" class="sap-btn is-primary is-block" data-sap-step="ai_offpage" data-project="<?php echo esc_attr( $pid ); ?>" data-force <?php disabled( ! sap_ai_connected() || ! $targets ); ?>><?php echo sap_icon( 'spark' ); // phpcs:ignore ?><?php echo $plan ? 'ساخت دوباره برنامه آف‌پیج' : 'ساخت برنامه آف‌پیج این ماه'; ?></button>
		<?php if ( ! $targets ) : ?><p class="sap-small sap-warn">اول Focusها و کلمات هدفشان را تعریف کنید (تب نمای کلی › Focus و اهداف).</p><?php endif; ?>
	</section>
</div>

<?php if ( $targets ) : ?>
	<section class="sap-panel">
		<h3>کلمات هدف Focusها</h3>
		<div class="sap-offpage-targets">
			<?php foreach ( $targets as $t ) : ?>
				<div class="sap-offpage-focus">
					<b><?php echo esc_html( $t['focus'] ); ?></b>
					<?php if ( $t['goal'] ) : ?><p class="sap-small sap-muted"><?php echo esc_html( $t['goal'] ); ?></p><?php endif; ?>
					<ul>
						<?php foreach ( $t['keywords'] as $k ) : ?>
							<li><span><?php echo esc_html( $k['keyword'] ); ?></span><span class="sap-chip <?php echo null === $k['pos'] ? '' : ( $k['pos'] <= 3 ? 'is-good' : ( $k['pos'] <= 10 ? 'is-warn' : 'is-bad' ) ); ?>"><?php echo null === $k['pos'] ? 'بدون رتبه' : 'جایگاه ' . esc_html( sap_num( $k['pos'], 1 ) ); ?></span><?php if ( $k['volume'] ) : ?><small class="sap-muted"><?php echo esc_html( sap_num( $k['volume'] ) ); ?> جستجو</small><?php endif; ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $plan && ! empty( $plan['payload']['actions'] ) ) : ?>
	<?php $p = $plan['payload']; ?>
	<section class="sap-panel">
		<div class="sap-panel-head">
			<h3>برنامه آف‌پیج <small class="sap-muted"><?php echo esc_html( sap_jdate( $plan['fetched_at'], 'short' ) ); ?> · هزینه حدود <?php echo esc_html( sap_num( $p['spend'] ?? 0 ) ); ?> تومان<?php echo $set['budget'] ? ' از ' . esc_html( sap_num( $set['budget'] ) ) : ''; ?></small></h3>
		</div>
		<p><?php echo esc_html( $p['strategy'] ); ?></p>
		<?php if ( $p['budget_split'] ) : ?>
			<div class="sap-kw-chips"><?php foreach ( $p['budget_split'] as $b ) : ?><span title="<?php echo esc_attr( $b['why'] ?? '' ); ?>"><?php echo esc_html( ( $b['channel'] ?? '' ) . ': ' . sap_num( (float) ( $b['amount'] ?? 0 ) ) . ' تومان' ); ?></span><?php endforeach; ?></div>
		<?php endif; ?>
		<?php if ( $p['anchor_policy'] ) : ?><p class="sap-small"><b>سیاست انکرها:</b> <?php echo esc_html( $p['anchor_policy'] ); ?></p><?php endif; ?>
		<div class="sap-table-wrap">
			<table class="sap-table is-compact">
				<thead><tr><th>هفته</th><th>نوع</th><th>اقدام</th><th>رسانه/سایت</th><th>صفحه مقصد</th><th>انکر</th><th>هزینه (تومان)</th></tr></thead>
				<tbody>
					<?php foreach ( sap_sort( $p['actions'], 'week', false ) as $a ) : ?>
						<tr>
							<td><?php echo esc_html( sap_num( $a['week'] ) ); ?></td>
							<td><span class="sap-chip"><?php echo esc_html( $types[ $a['type'] ] ?? $a['type'] ); ?></span></td>
							<td><b><?php echo esc_html( $a['title'] ); ?></b><br><span class="sap-small sap-muted"><?php echo esc_html( $a['why'] ); ?></span></td>
							<td dir="ltr" class="sap-small"><?php echo esc_html( $a['media'] ); ?></td>
							<td class="sap-small"><?php echo $a['target_url'] ? sap_page_link( $a['target_url'], sap_page_title( $pid, $a['target_url'] ) ?: rawurldecode( $a['target_url'] ) ) : '—'; // phpcs:ignore ?></td>
							<td class="sap-small"><?php echo esc_html( $a['anchor'] ); ?><?php echo $a['anchor'] ? ' <span class="sap-muted">(' . esc_html( $a['anchor_type'] ) . ')</span>' : ''; ?></td>
							<td><?php echo $a['price'] ? esc_html( sap_num( $a['price'] ) ) : 'رایگان'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $p['kpis'] ) : ?><p class="sap-small"><b>سنجش نتیجه:</b> <?php echo esc_html( implode( ' · ', $p['kpis'] ) ); ?></p><?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( ! empty( $data['media'] ) ) : ?>
	<details class="sap-panel">
		<summary>رسانه‌های پیشنهادی سئو سیگنال</summary>
		<?php foreach ( $data['media'] as $focus => $m ) : ?>
			<p class="sap-label"><?php echo esc_html( $focus . ' — ' . implode( '، ', (array) $m['keywords'] ) ); ?></p>
			<div class="sap-table-wrap"><table class="sap-table is-compact">
				<thead><tr><th>رسانه</th><th>امتیاز</th><th>لینک‌ها</th><th>میانگین رتبه</th><th>قیمت</th></tr></thead>
				<tbody><?php foreach ( array_slice( (array) $m['rows'], 0, 12 ) as $r ) : ?><tr><td dir="ltr"><?php echo esc_html( $r['Domain'] ?? '' ); ?></td><td><?php echo esc_html( sap_num( (float) ( $r['Score'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( sap_num( (int) ( $r['Count'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( sap_num( (float) ( $r['RankAvg'] ?? 0 ), 1 ) ); ?></td><td><?php echo esc_html( sap_num( (int) ( $r['Price'] ?? 0 ) ) ); ?></td></tr><?php endforeach; ?></tbody>
			</table></div>
		<?php endforeach; ?>
	</details>
<?php endif; ?>

<?php
sap_view( 'proposals', array(
	'project'  => $project,
	'items'    => sap_proposals( $pid, 'offpage' ),
	'title'    => 'پیشنهادهای آف‌پیج',
	'here'     => $here,
	'sections' => $types,
	'empty'    => 'پیشنهاد آف‌پیجی در انتظار تأیید نیست. «ساخت برنامه آف‌پیج» را بزنید.',
) );
