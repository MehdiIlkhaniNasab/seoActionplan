<?php
/**
 * Focuses («زاویه دید») and goals.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$focuses  = sap_focuses( $pid );
$clusters = array_values( array_unique( array_filter( array_column( (array) ( sap_dataset_payload( $pid, 'sheet_clusters' )['rows'] ?? array() ), 'cluster' ) ) ) );
$post     = esc_url( admin_url( 'admin-post.php' ) );

$views = array_reverse( sap_focus_views( $pid ), true );
?>
<section class="sap-panel sap-fc-wrap">
	<div class="sap-panel-head">
		<h2><?php echo sap_icon( 'target' ); // phpcs:ignore ?>Focus و اهداف</h2>
		<a class="sap-btn is-primary is-small" href="#focus-new"><?php echo sap_icon( 'plus', 14 ); // phpcs:ignore ?>Focus جدید</a>
	</div>
	<p class="sap-muted">هر Focus مجموعه‌ای از صفحه‌هاست با یک هدف عددی. عددها دقیقاً همان چیزی است که سرچ کنسول برای همان صفحه‌ها نشان می‌دهد و تاریخ هر بازه کنارش نوشته شده.</p>
	<?php if ( ! $focuses ) : ?>
		<div class="sap-empty"><p>هنوز Focus ندارید. از «Focus جدید» یا «دیدگاه من» شروع کنید.</p></div>
	<?php endif; ?>
	<div class="sap-fc-grid">
		<?php foreach ( $focuses as $f ) : ?>
			<div class="sap-fc-item">
				<?php sap_view( 'focus-card', array( 'project' => $project, 'f' => $f ) ); ?>
				<details class="sap-fc-edit">
					<summary><?php echo sap_icon( 'settings', 14 ); // phpcs:ignore ?>ویرایش صفحه‌ها و هدف</summary>
					<?php if ( ! empty( $f['ai_meta']['rationale'] ) ) : ?>
						<div class="sap-ai-note"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?><div><b>دلیل هدف و اولویت:</b> <?php echo esc_html( $f['ai_meta']['rationale'] ); ?></div></div>
					<?php endif; ?>
					<?php sap_view( 'focus-form', array( 'project' => $project, 'f' => $f ) ); ?>
					<form method="post" action="<?php echo $post; // phpcs:ignore ?>" onsubmit="return confirm('Focus حذف شود؟')" class="sap-fc-del">
						<?php sap_form_fields( 'sap_delete_focus', array( 'project_id' => $pid, 'focus_id' => $f['id'] ) ); ?>
						<button class="sap-btn is-small is-ghost is-danger" type="submit">حذف Focus</button>
					</form>
				</details>
			</div>
		<?php endforeach; ?>
	</div>
</section>

<section class="sap-panel sap-fv-wrap" data-sap-fv="<?php echo esc_attr( $pid ); ?>">
	<h2><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>دیدگاه من</h2>
	<p class="sap-muted">نظر خودتان را درباره اینکه این پروژه روی چه چیزی تمرکز کند بنویسید؛ هوش مصنوعی با داده پروژه می‌سنجد، می‌گوید موافق است یا نه و چرا، چه اثری دارد، و یک Focus آماده پیشنهاد می‌دهد.</p>
	<form class="sap-fv-form" data-sap-fv-form>
		<textarea rows="3" maxlength="2000" required placeholder="مثلاً: فکر می‌کنم به‌جای کفی طبی، باید روی واکس و تمیزکننده‌های چرم تمرکز کنیم چون حاشیه سودش بیشتر است و رقبا ضعیف‌ترند…"></textarea>
		<button type="submit" class="sap-btn is-primary" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 16 ); // phpcs:ignore ?>تحلیل دیدگاه</button>
	</form>
	<div class="sap-fv-list" data-sap-fv-list>
		<?php $shown = 0; foreach ( $views as $i => $v ) : ?>
			<?php if ( ++$shown > 3 ) { break; } ?>
			<?php sap_view( 'focus-view-answer', array( 'project' => $project, 'v' => $v, 'idx' => $i ) ); ?>
		<?php endforeach; ?>
	</div>
</section>

<section class="sap-panel" id="focus-new">
	<h2><?php echo sap_icon( 'plus' ); // phpcs:ignore ?>Focus جدید</h2>
	<?php sap_view( 'focus-form', array( 'project' => $project, 'f' => null ) ); ?>
</section>

<?php
$ai_focus = sap_dataset( $pid, 'ai_focus' );
$metrics  = sap_kpi_metrics();
?>
<details class="sap-panel sap-focus-ai" <?php echo $focuses ? '' : 'open'; ?>>
	<summary class="sap-panel-head">
		<h2><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>هدف‌گذاری هوشمند (پیشنهاد خودکار Focus)</h2>
		<button type="button" class="sap-btn is-primary is-small" data-sap-step="ai_focus" data-force="1" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?><?php echo $ai_focus ? 'تحلیل دوباره' : 'پیشنهاد Focus و هدف'; ?></button>
	</summary>
	<p class="sap-muted">هوش مصنوعی بخش‌های سایت و Focusهای فعلی را با داده واقعی (کلیک، نمایش، کلمات نزدیک صفحه اول، حجم جستجو، ایرادها) می‌سنجد و برای هر کدام نام فارسی، هدف عددی، بهترین شاخص شروع، مهلت و وزن اولویت را با دلیل پیشنهاد می‌دهد. شما فقط تأیید می‌کنید.</p>
	<?php if ( $ai_focus ) : ?>
		<div class="sap-focus-recs">
			<?php foreach ( (array) ( $ai_focus['payload']['focuses'] ?? array() ) as $r ) : ?>
				<article class="sap-focus-rec">
					<header>
						<h3><?php echo esc_html( $r['name'] ); ?></h3>
						<span class="sap-chip <?php echo $r['existing'] ? '' : 'is-ai'; ?>"><?php echo $r['existing'] ? 'Focus فعلی' : 'پیشنهاد جدید'; ?></span>
						<span class="sap-weight" title="وزن اولویت"><?php echo esc_html( sap_num( $r['weight'], 1 ) ); ?>×</span>
					</header>
					<p class="sap-focus-goal"><?php echo esc_html( $r['goal'] ); ?></p>
					<div class="sap-scores">
						<?php foreach ( array( 'potential' => 'ظرفیت رشد', 'value' => 'ارزش کسب‌وکار', 'urgency' => 'فوریت' ) as $k => $label ) : ?>
							<div><span><?php echo esc_html( $label ); ?></span><i style="--v:<?php echo esc_attr( min( 10, max( 0, (int) $r[ $k ] ) ) * 10 ); ?>%"></i><b><?php echo esc_html( sap_num( $r[ $k ] ) ); ?></b></div>
						<?php endforeach; ?>
					</div>
					<dl class="sap-kv-mini">
						<dt>شاخص شروع</dt><dd><b><?php echo esc_html( $metrics[ $r['metric'] ] ?? $r['metric'] ); ?></b> — <?php echo esc_html( $r['metric_reason'] ); ?></dd>
						<dt>از ← تا</dt><dd><?php echo esc_html( ( null !== $r['current'] ? sap_num( $r['current'] ) : '—' ) . ' ← ' . sap_num( $r['target'] ) . ' در ' . sap_num( $r['months'] ) . ' ماه' ); ?></dd>
						<dt>چرا</dt><dd><?php echo esc_html( $r['rationale'] ); ?></dd>
					</dl>
					<?php if ( $r['first_steps'] ) : ?>
						<ol class="sap-small"><?php foreach ( $r['first_steps'] as $st ) : ?><li><?php echo esc_html( $st ); ?></li><?php endforeach; ?></ol>
					<?php endif; ?>
					<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
						<?php sap_form_fields( 'sap_ai_focus_apply', array( 'project_id' => $pid, 'ref' => $r['ref'] ) ); ?>
						<button class="sap-btn is-small is-primary" type="submit"><?php echo sap_icon( 'check', 14 ); // phpcs:ignore ?><?php echo $r['existing'] ? 'اعمال هدف و وزن' : 'ساخت این Focus'; ?></button>
					</form>
				</article>
			<?php endforeach; ?>
		</div>
		<small class="sap-muted"><?php echo esc_html( sap_jdate( $ai_focus['fetched_at'] ) . ( isset( $ai_focus['meta']['cost'] ) ? ' · ' . sap_num( $ai_focus['meta']['cost'], 3 ) . ' دلار' : '' ) ); ?></small>
	<?php endif; ?>
</details>
