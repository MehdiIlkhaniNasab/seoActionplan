<?php
/**
 * GEO: visibility in AI answers (tracked customer questions), share of voice
 * against competitors in those answers, the GEO audit and llms.txt.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid     = $project['id'];
$prompts = sap_geo_prompts( $project );
$runs    = (array) ( sap_dataset_payload( $pid, 'geo' )['runs'] ?? array() );
$last    = $runs ? end( $runs ) : null;
$prev    = count( $runs ) > 1 ? $runs[ count( $runs ) - 2 ] : null;
$audit   = sap_geo_audit( $project );
$dom     = sap_project_domain( $project );
$share   = $last ? sap_geo_share( $last, $project ) : array();
$series  = array();
foreach ( $runs as $r ) {
	$series['دیده شدن در پاسخ‌های AI'][ $r['at'] ] = $r['visibility'];
}
$st = array( 'good' => array( '✓', 'is-good' ), 'warn' => array( '!', 'is-warn' ), 'bad' => array( '✗', 'is-bad' ) );
?>
<div class="sap-geo-kpis">
	<div class="is-main">
		<span>دیده شدن در پاسخ‌های هوش مصنوعی</span>
		<b><?php echo $last ? esc_html( sap_num( $last['visibility'] ) ) . '٪' : '—'; ?></b>
		<em><?php echo $last ? esc_html( sprintf( 'در %s از %s سوال · %s', sap_num( count( array_filter( $last['results'], function ( $r ) {
			return $r['us'];
		} ) ) ), sap_num( count( $last['results'] ) ), sap_jdate( $last['at'], 'short' ) ) ) : 'هنوز سنجیده نشده'; ?><?php if ( $last && $prev ) : ?><?php $dv = $last['visibility'] - $prev['visibility']; ?> · <span class="sap-rd <?php echo $dv > 0 ? 'is-up' : ( $dv < 0 ? 'is-down' : '' ); ?>"><?php echo esc_html( ( $dv > 0 ? '▲ ' : ( $dv < 0 ? '▼ ' : '' ) ) . sap_num( abs( $dv ) ) . '٪' ); ?></span><?php endif; ?></em>
	</div>
	<div><span>امتیاز آمادگی GEO</span><b><?php echo esc_html( sap_num( $audit['score'] ) ); ?></b><em>از ۱۰۰</em></div>
	<div><span>سوال‌های پیگیری</span><b><?php echo esc_html( sap_num( count( $prompts ) ) ); ?></b><em>هر شنبه خودکار</em></div>
	<div><span>مدل سنجش</span><b class="sap-geo-model" dir="ltr"><?php echo esc_html( sap_ai_model_for( 'geo' ) ); ?></b><em><a href="<?php echo esc_url( sap_url( 'settings' ) . '#geo' ); ?>">تغییر</a></em></div>
</div>

<div class="sap-rank-grid">
	<section class="sap-panel">
		<div class="sap-panel-head"><h2><?php echo sap_icon( 'globe' ); // phpcs:ignore ?>سوال‌های مشتری که از هوش مصنوعی پیگیری می‌شوند</h2></div>
		<p class="sap-muted sap-small">هر سوال، همان‌طور که مشتری از ChatGPT یا Gemini می‌پرسد، از مدل پرسیده می‌شود و در جواب دنبال نام برند/سایت شما و رقبا می‌گردیم. یک سوال در هر خط؛ بعد از «|» می‌توانید کلمه کلیدی مرتبط را بنویسید.</p>
		<form data-sap-geo-prompts data-project="<?php echo esc_attr( $pid ); ?>">
			<textarea name="prompts" rows="8" placeholder="بهترین اسکوتر برقی برای رفت و آمد شهری چیست؟ | اسکوتر برقی"><?php echo esc_textarea( implode( "\n", array_map( function ( $p ) {
				return $p['q'] . ( $p['kw'] ? ' | ' . $p['kw'] : '' );
			}, $prompts ) ) ); ?></textarea>
			<div class="sap-actions">
				<button type="button" class="sap-btn is-ghost" data-sap-geo-suggest <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>پیشنهاد سوال از کلمات هدف</button>
				<button type="submit" class="sap-btn is-soft">ذخیره سوال‌ها</button>
				<button type="button" class="sap-btn is-primary" data-sap-geo-run <?php disabled( ! $prompts || ! sap_ai_connected() ); ?>>سنجش همین حالا</button>
			</div>
			<div class="sap-serp-progress" data-sap-geo-progress hidden><i></i><span></span></div>
		</form>
	</section>
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>سهم حضور در پاسخ‌های AI</h2></div>
		<?php if ( ! $share ) : ?>
			<p class="sap-muted">بعد از اولین سنجش، سهم شما و رقبا از نام‌برده‌شدن در پاسخ‌ها اینجا می‌آید.</p>
		<?php else : ?>
			<?php $mx = max( 1, max( $share ) ); ?>
			<ul class="sap-sov">
				<?php foreach ( $share as $d => $n ) : ?>
					<li class="<?php echo $d === $dom ? 'is-us' : ''; ?>"><span dir="ltr"><?php echo esc_html( $d ); ?></span><i style="width:<?php echo esc_attr( round( $n / $mx * 100 ) ); ?>%"></i><b><?php echo esc_html( sap_num( $n ) ); ?></b></li>
				<?php endforeach; ?>
			</ul>
			<p class="sap-small sap-muted">عدد = تعداد سوال‌هایی که نام دامنه/برند در جواب آمده.</p>
		<?php endif; ?>
		<?php if ( count( $series[ 'دیده شدن در پاسخ‌های AI' ] ?? array() ) > 1 ) : ?>
			<h3 class="sap-h3">روند</h3>
			<?php echo sap_svg_lines( $series, array( 'height' => 150, 'min' => 0, 'max' => 100, 'unit' => '٪' ) ); // phpcs:ignore ?>
		<?php endif; ?>
	</section>
</div>

<?php if ( $last ) : ?>
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>پاسخ‌های آخرین سنجش<small><?php echo esc_html( sap_jdate( $last['at'], 'long' ) . ' · مدل ' . $last['model'] ); ?></small></h2></div>
		<ul class="sap-geo-results">
			<?php foreach ( $last['results'] as $r ) : ?>
				<li class="<?php echo $r['us'] ? 'is-yes' : 'is-no'; ?>">
					<span class="sap-geo-mark"><?php echo $r['us'] ? '✓' : '✗'; ?></span>
					<div>
						<p class="sap-geo-q"><b><?php echo esc_html( $r['q'] ); ?></b></p>
						<p class="sap-geo-meta">
							<?php if ( $r['us'] ) : ?><span class="sap-chip is-good">نام شما آمد<?php echo $r['rank'] ? ' · نفر ' . esc_html( sap_num( $r['rank'] ) ) : ''; ?></span><?php else : ?><span class="sap-chip is-soft">نام شما نیامد</span><?php endif; ?>
							<?php foreach ( $r['competitors'] as $c ) : ?><span class="sap-chip is-warn" dir="ltr"><?php echo esc_html( $c ); ?></span><?php endforeach; ?>
						</p>
						<?php if ( $r['mentions'] ) : ?><p class="sap-small sap-muted">نام‌برده‌شده‌ها: <?php echo esc_html( implode( '، ', $r['mentions'] ) ); ?></p><?php endif; ?>
						<details><summary>متن جواب هوش مصنوعی</summary><p class="sap-geo-answer" dir="auto"><?php echo nl2br( esc_html( $r['answer'] ) ); ?></p></details>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>

<div class="sap-rank-grid">
	<section class="sap-panel">
		<div class="sap-panel-head"><h2>چک‌لیست GEO (آمادگی برای موتورهای پاسخ)</h2><button type="button" class="sap-btn is-ghost is-small" data-sap-geo-audit data-project="<?php echo esc_attr( $pid ); ?>">بررسی دوباره</button></div>
		<ul class="sap-geo-checks">
			<?php foreach ( $audit['checks'] as $c ) : ?>
				<li class="<?php echo esc_attr( $st[ $c['status'] ][1] ); ?>">
					<span class="sap-cover-ico"><?php echo esc_html( $st[ $c['status'] ][0] ); ?></span>
					<div><b><?php echo esc_html( $c['label'] ); ?></b><p><?php echo esc_html( $c['detail'] ); ?></p><?php if ( $c['fix'] ) : ?><p class="sap-geo-fix"><?php echo esc_html( $c['fix'] ); ?></p><?php endif; ?></div>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="sap-small sap-muted">بررسی: <?php echo esc_html( sap_jdate( $audit['at'], 'long' ) ); ?></p>
	</section>
	<section class="sap-panel" data-sap-llms data-project="<?php echo esc_attr( $pid ); ?>">
		<div class="sap-panel-head"><h2>فایل llms.txt</h2></div>
		<p class="sap-muted sap-small">خلاصه‌ای ساختاریافته از مهم‌ترین صفحات سایت (بر اساس کلیک واقعی) برای مدل‌های زبانی. سکان می‌سازد، شما ویرایش و تایید می‌کنید و از طریق افزونه رابط در آدرس /llms.txt منتشر می‌شود (قابل برگشت).</p>
		<textarea data-sap-llms-text rows="10" dir="auto" placeholder="«ساخت پیش‌نویس» را بزنید"></textarea>
		<div class="sap-actions">
			<button type="button" class="sap-btn is-ghost" data-sap-llms="build">ساخت پیش‌نویس از صفحات کلیدی</button>
			<button type="button" class="sap-btn is-primary" data-sap-llms="publish" <?php disabled( ! sap_site_connected( $project ) ); ?>>تایید و انتشار روی سایت</button>
			<button type="button" class="sap-btn is-ghost is-small" data-sap-llms="rollback" <?php disabled( ! sap_site_connected( $project ) ); ?>>برگرداندن نسخه قبل</button>
		</div>
	</section>
</div>
