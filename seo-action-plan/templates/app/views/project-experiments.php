<?php
/**
 * SEO experiments: test pages vs control pages, measured daily.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid      = $project['id'];
$items    = sap_experiments( $pid );
$metrics  = sap_exp_metrics();
$verdicts = sap_exp_verdicts();
$gsc      = '' !== $project['gsc_property'] && sap_google_connected();
?>
<section class="sap-panel">
	<div class="sap-panel-head"><h2><?php echo sap_icon( 'flask' ); // phpcs:ignore ?>آزمایش‌های سئو (تست و کنترل)<small>یک تغییر را روی گروهی از صفحات (تست) اجرا کنید و صفحات مشابه (کنترل) را دست نزنید. سکان هر روز داده سرچ کنسول هر دو گروه را می‌گیرد؛ چون فصل و آپدیت گوگل روی هر دو گروه اثر دارد، تفاوت باقی‌مانده اثر واقعی تغییر شماست (Difference-in-Differences + آزمون معناداری).</small></h2></div>
	<?php if ( ! $gsc ) : ?><p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?>برای آزمایش، سرچ کنسول باید به پروژه متصل باشد.</p><?php endif; ?>
	<details class="sap-exp-new" <?php echo $items ? '' : 'open'; ?>>
		<summary class="sap-btn is-primary"><?php echo sap_icon( 'plus', 14 ); // phpcs:ignore ?>آزمایش جدید</summary>
		<form class="sap-exp-form" data-sap-exp-form data-project="<?php echo esc_attr( $pid ); ?>">
			<div class="sap-grid-2">
				<label>نام آزمایش<input type="text" name="name" required placeholder="مثلاً: قیمت در تایتل صفحات محصول"></label>
				<label>معیار اصلی<select name="metric"><?php foreach ( $metrics as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
			</div>
			<label>فرضیه<textarea name="hypothesis" rows="2" placeholder="اگر قیمت را در تایتل بیاوریم، CTR صفحات محصول بیشتر می‌شود چون…"></textarea></label>
			<label>دقیقاً چه تغییری روی صفحات تست داده می‌شود؟<textarea name="change" rows="2" placeholder="افزودن «قیمت از X تومان» به ابتدای تایتل سئو"></textarea></label>
			<div class="sap-grid-2">
				<label>صفحات تست (هر خط یک آدرس)<textarea name="test" rows="5" dir="ltr" required></textarea></label>
				<label>صفحات کنترل (خالی = پیشنهاد خودکار صفحات مشابه)<textarea name="control" rows="5" dir="ltr"></textarea><button type="button" class="sap-btn is-ghost is-small" data-sap-exp-control>پیشنهاد صفحات کنترل</button></label>
			</div>
			<div class="sap-grid-3">
				<label>تاریخ اعمال تغییر<input type="date" name="start" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
				<label>مدت سنجش (روز)<input type="number" name="days" min="14" max="90" value="28"></label>
				<div class="sap-exp-tip"><small>نکته: تغییر را دقیقاً در همین تاریخ روی صفحات تست اعمال کنید (از اتوماسیون سایت یا دستی) و تا پایان آزمایش به صفحات کنترل دست نزنید.</small></div>
			</div>
			<button class="sap-btn is-primary" type="submit" <?php disabled( ! $gsc ); ?>>شروع آزمایش</button>
		</form>
	</details>
</section>

<?php if ( ! $items ) : ?>
	<div class="sap-empty"><?php echo sap_icon( 'flask', 30 ); // phpcs:ignore ?><p>هنوز آزمایشی نساخته‌اید. با یک آزمایش کوچک شروع کنید: مثلاً تغییر قالب تایتل در ۱۰ صفحه محصول.</p></div>
<?php endif; ?>

<?php foreach ( $items as $e ) : ?>
	<?php
	$r   = $e['result'];
	$v   = $verdicts[ $r['verdict'] ?? 'wait' ];
	$pos = 'position' === $e['metric'];
	$ser = array();
	if ( $r && ! empty( $r['series'] ) ) {
		foreach ( $r['series'] as $d => $pair ) {
			$ser['تست'][ $d ]             = $pair[0];
			$ser['کنترل (هم‌مقیاس)'][ $d ] = $pair[1];
		}
	}
	?>
	<section class="sap-panel sap-exp" id="exp-<?php echo esc_attr( $e['id'] ); ?>">
		<div class="sap-exp-head">
			<div>
				<h3><?php echo esc_html( $e['name'] ); ?> <span class="sap-chip <?php echo esc_attr( $v[1] ); ?>"><?php echo esc_html( $v[0] ); ?></span><?php echo 'done' === $e['status'] ? ' <span class="sap-chip is-soft">پایان یافته</span>' : ''; ?></h3>
				<p class="sap-muted sap-small">از <?php echo esc_html( sap_jdate( $e['start'], 'long' ) ); ?> · <?php echo esc_html( sap_num( $e['days'] ) ); ?> روز · معیار: <?php echo esc_html( $metrics[ $e['metric'] ] ); ?> · <?php echo esc_html( sap_num( count( $e['test'] ) ) ); ?> صفحه تست، <?php echo esc_html( sap_num( count( $e['control'] ) ) ); ?> کنترل · <?php echo esc_html( $e['user'] ); ?></p>
			</div>
			<div class="sap-actions">
				<button type="button" class="sap-btn is-soft is-small" data-sap-exp="measure" data-id="<?php echo esc_attr( $e['id'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" <?php disabled( ! $gsc ); ?>>سنجش الان</button>
				<?php if ( 'running' === $e['status'] ) : ?><button type="button" class="sap-btn is-ghost is-small" data-sap-exp="stop" data-id="<?php echo esc_attr( $e['id'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>">پایان</button><?php endif; ?>
				<button type="button" class="sap-icon-btn" data-sap-exp="delete" data-id="<?php echo esc_attr( $e['id'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" title="حذف"><?php echo sap_icon( 'trash', 16 ); // phpcs:ignore ?></button>
			</div>
		</div>
		<?php if ( $e['hypothesis'] || $e['change'] ) : ?>
			<p class="sap-exp-hyp"><?php echo $e['hypothesis'] ? '<b>فرضیه:</b> ' . esc_html( $e['hypothesis'] ) . '<br>' : ''; ?><?php echo $e['change'] ? '<b>تغییر:</b> ' . esc_html( $e['change'] ) : ''; ?></p>
		<?php endif; ?>
		<?php if ( ! $r ) : ?>
			<p class="sap-muted">هنوز سنجیده نشده (هر روز خودکار سنجیده می‌شود؛ یا «سنجش الان»).</p>
		<?php else : ?>
			<div class="sap-exp-stats">
				<div class="is-main <?php echo isset( $r['lift'] ) ? ( $r['lift'] > 0 ? 'is-good' : ( $r['lift'] < 0 ? 'is-bad' : '' ) ) : ''; ?>"><span>اثر خالص تغییر</span><b><?php echo isset( $r['lift'] ) ? esc_html( ( $r['lift'] > 0 ? '+' : '' ) . sap_num( $r['lift'], 1 ) . ( $pos ? ' جایگاه' : '٪' ) ) : '—'; ?></b><em><?php echo ! empty( $r['conf'] ) && $r['conf'] > 0 ? 'اطمینان ' . esc_html( sap_num( $r['conf'] ) ) . '٪' : ( isset( $r['t'] ) ? 'هنوز معنادار نیست' : 'در انتظار ۷ روز داده' ); ?></em></div>
				<div><span>تست: قبل ← بعد</span><b><?php echo esc_html( sap_num( $r['test_pre'], 1 ) . ' ← ' . ( isset( $r['test_post'] ) ? sap_num( $r['test_post'], 1 ) : '…' ) ); ?></b><em>میانگین روزانه</em></div>
				<div><span>کنترل: قبل ← بعد</span><b><?php echo esc_html( sap_num( $r['control_pre'], 1 ) . ' ← ' . ( isset( $r['control_post'] ) ? sap_num( $r['control_post'], 1 ) : '…' ) ); ?></b><em>میانگین روزانه</em></div>
				<div><span>روزهای بعد از تغییر</span><b><?php echo esc_html( sap_num( $r['post_days'] ) . ' / ' . sap_num( $e['days'] ) ); ?></b><em>سنجش: <?php echo esc_html( sap_jdate( $r['measured'], 'short' ) ); ?></em></div>
			</div>
			<?php if ( $ser ) : ?>
				<?php echo sap_svg_lines( $ser, array( 'height' => 170, 'invert' => $pos, 'colors' => array( '#2563EB', '#94A3B8' ), 'mark' => $e['start'], 'mark_label' => 'تغییر · ' . sap_jdate( $e['start'], 'day' ) ) ); // phpcs:ignore ?>
				<p class="sap-small sap-muted">خط خاکستری: صفحات کنترل، هم‌مقیاس‌شده با سطح صفحات تست قبل از تغییر (یعنی «اگر تغییر نمی‌دادیم چه می‌شد»). فاصله دو خط بعد از تاریخ تغییر = اثر تغییر.</p>
			<?php endif; ?>
		<?php endif; ?>
		<details class="sap-exp-pages"><summary>صفحات تست و کنترل</summary>
			<div class="sap-grid-2"><div><b>تست</b><?php foreach ( $e['test'] as $u ) : ?><p><?php echo sap_url_tag( $u, 60 ); // phpcs:ignore ?></p><?php endforeach; ?></div><div><b>کنترل</b><?php foreach ( $e['control'] as $u ) : ?><p><?php echo sap_url_tag( $u, 60 ); // phpcs:ignore ?></p><?php endforeach; ?></div></div>
		</details>
	</section>
<?php endforeach; ?>
