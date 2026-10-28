<?php
/**
 * Project hub: the one next step, the AI diagnosis and plan, focus progress, this month.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid     = $project['id'];
$period  = sap_period_current();
$path    = sap_project_path( $project );
$tasks   = $path['tasks'];
$review  = $path['review'];
$focuses = sap_focuses( $pid );
$counts  = sap_findings_counts( $pid );
$ai_ds   = sap_dataset( $pid, 'ai_analysis' );
$ai      = $ai_ds ? $ai_ds['payload'] : null;
$here    = sap_project_url( $pid );
$post    = esc_url( admin_url( 'admin-post.php' ) );
$hours   = 0;
$done_h  = 0;
$by_st   = array();
$ai_refs = array();
foreach ( $tasks as $t ) {
	$by_st[ $t['status'] ] = ( $by_st[ $t['status'] ] ?? 0 ) + 1;
	if ( 'dropped' !== $t['status'] ) {
		$hours += $t['effort'];
	}
	if ( 'done' === $t['status'] ) {
		$done_h += $t['effort'];
	}
	if ( $t['ai_ref'] ) {
		$ai_refs[ $t['ai_ref'] ] = true;
	}
}
$next_task = null;
foreach ( $tasks as $t ) {
	if ( in_array( $t['status'], array( 'doing', 'next' ), true ) && ( ! $next_task || 'doing' === $t['status'] ) ) {
		$next_task = $t;
		if ( 'doing' === $t['status'] ) {
			break;
		}
	}
}
?>
<section class="sap-next">
	<ol class="sap-stepper">
		<?php
		$i = 0;
		foreach ( $path['steps'] as $key => $s ) :
			++$i;
			$state = $s[1] ? 'is-done' : ( $key === $path['current'] ? 'is-current' : '' );
			?>
			<li class="<?php echo esc_attr( $state ); ?>"><span><?php echo $s[1] ? sap_icon( 'check', 14 ) : esc_html( sap_fa( $i ) ); // phpcs:ignore ?></span><?php echo esc_html( $s[0] ); ?></li>
		<?php endforeach; ?>
	</ol>

	<div class="sap-next-body">
		<p class="sap-next-eyebrow">قدم بعدی</p>
		<?php switch ( $path['current'] ) : case 'connect': ?>
			<h2>داده‌های پروژه را وصل کنید</h2>
			<p>پراپرتی سرچ کنسول و لینک گوگل شیت پروژه را وارد کنید تا ابزار بتواند سایت را تحلیل کند.</p>
			<a class="sap-btn is-primary" href="<?php echo esc_url( sap_project_url( $pid, 'settings' ) ); ?>">اتصال سرچ کنسول و شیت</a>
		<?php break; case 'analyze': ?>
			<h2>اولین تحلیل را اجرا کنید</h2>
			<p>داده‌های سرچ کنسول، شیت، سئو سیگنال و خزش گرفته می‌شود، ایرادها و فرصت‌ها کشف می‌شوند<?php echo sap_ai_connected() ? ' و هوش مصنوعی وضعیت پروژه را تشخیص می‌دهد و اکشن پلن پیشنهاد می‌کند' : ''; ?>. حدود ۱ تا ۳ دقیقه.</p>
			<button type="button" class="sap-btn is-primary is-lg" data-sap-sync="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>تحلیل کامل پروژه</button>
		<?php break; case 'focus': ?>
			<h2>روی کدام بخش سایت تمرکز دارید؟</h2>
			<p>با انتخاب Focus، تحلیل‌ها و اکشن پلن در راستای همان بخش اولویت می‌گیرند. می‌توانید بعداً تغییرش دهید.</p>
			<?php sap_view( 'focus-quick', array( 'project' => $project, 'return' => $here ) ); ?>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-skip">
				<?php sap_form_fields( 'sap_focus_skip', array( 'project_id' => $pid ) ); ?>
				<button class="sap-btn is-ghost is-small" type="submit">فعلاً کل سایت (بدون Focus)</button>
			</form>
		<?php break; case 'plan': ?>
			<h2>اکشن پلن <?php echo esc_html( sap_period_label( $period ) ); ?> را بسازید</h2>
			<p><?php echo $ai ? sprintf( 'هوش مصنوعی %s کار مشخص برای این ماه پیشنهاد داده (پایین همین صفحه). با یک کلیک به برنامه اضافه می‌شوند.', esc_html( sap_num( count( $ai['plan'] ) ) ) ) : sprintf( 'از میان %s یافته باز، مهم‌ترین‌ها تا سقف %s ساعت ظرفیت ماه انتخاب می‌شوند.', esc_html( sap_num( $counts['total'] ) ), esc_html( sap_num( $project['capacity_hours'] ) ) ); ?></p>
			<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
				<?php sap_form_fields( 'sap_plan_generate', array( 'project_id' => $pid, 'period' => $period ) ); ?>
				<button class="sap-btn is-primary is-lg" type="submit"><?php echo sap_icon( 'spark' ); // phpcs:ignore ?>ساخت اکشن پلن هوشمند</button>
			</form>
		<?php break; case 'run': ?>
			<?php if ( $review['overdue'] ) : ?>
				<h2><?php echo esc_html( sap_num( count( $review['overdue'] ) ) ); ?> کار از سررسید گذشته</h2>
				<p>این‌ها را انجام دهید یا به هفته بعد منتقل کنید: <?php echo esc_html( implode( '، ', array_slice( wp_list_pluck( $review['overdue'], 'title' ), 0, 3 ) ) ); ?></p>
				<a class="sap-btn is-primary" href="<?php echo esc_url( sap_project_url( $pid, 'plan' ) ); ?>">رفتن به اکشن پلن</a>
			<?php else : ?>
				<h2>بازبینی هفتگی</h2>
				<p>۵ دقیقه: صندوق ورودی (<?php echo esc_html( sap_num( $counts['total'] ) ); ?> یافته)، موارد در انتظار (<?php echo esc_html( sap_num( $by_st['waiting'] ?? 0 ) ); ?>) و پیشرفت ماه را مرور کنید.</p>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
					<?php sap_form_fields( 'sap_review_done', array( 'project_id' => $pid ) ); ?>
					<button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'check' ); // phpcs:ignore ?>بازبینی این هفته انجام شد</button>
				</form>
			<?php endif; ?>
		<?php break; default: ?>
			<?php if ( $next_task ) : ?>
				<h2><?php echo esc_html( $next_task['title'] ); ?></h2>
				<p><?php echo 'doing' === $next_task['status'] ? 'در حال انجام' : 'مهم‌ترین کار باز این ماه'; ?> · <?php echo esc_html( sap_context_label( $next_task['context'] ) ); ?> · <?php echo esc_html( sap_num( $next_task['effort'], 1 ) ); ?> ساعت</p>
				<a class="sap-btn is-primary" href="<?php echo esc_url( sap_project_url( $pid, 'plan', array( 'task' => $next_task['id'] ) ) ); ?>">باز کردن کار</a>
			<?php else : ?>
				<h2>همه کارهای این ماه انجام شده</h2>
				<p>برای ماه بعد برنامه بسازید یا تحلیل را بروز کنید.</p>
				<button type="button" class="sap-btn is-primary" data-sap-sync="<?php echo esc_attr( $pid ); ?>">تحلیل دوباره</button>
			<?php endif; ?>
		<?php endswitch; ?>
	</div>
</section>

<?php
// «Start here»: only the three most important open tasks, so a beginner is not lost in the analysis.
$today = current_time( 'Y-m-d' );
$open  = array_values( array_filter( $tasks, function ( $t ) {
	return in_array( $t['status'], array( 'doing', 'next', 'inbox' ), true );
} ) );
usort( $open, function ( $a, $b ) use ( $today ) {
	$ra = ( 'doing' === $a['status'] ? 0 : 2 ) - ( $a['due_date'] && $a['due_date'] < $today ? 1 : 0 );
	$rb = ( 'doing' === $b['status'] ? 0 : 2 ) - ( $b['due_date'] && $b['due_date'] < $today ? 1 : 0 );
	return $ra <=> $rb ?: $b['score'] <=> $a['score'];
} );
$top3 = array_slice( $open, 0, 3 );
?>
<?php if ( $top3 ) : ?>
	<section class="sap-panel sap-start">
		<div class="sap-panel-head">
			<h2><?php echo sap_icon( 'target' ); // phpcs:ignore ?>از اینجا شروع کن</h2>
			<small class="sap-muted">بقیه کارها را فعلاً نادیده بگیر؛ با تمام شدن هر کدام، کار بعدی اینجا می‌آید.</small>
		</div>
		<ol class="sap-start-list">
			<?php foreach ( $top3 as $n => $t ) : ?>
				<?php
				$why = '';
				foreach ( preg_split( '/\r?\n/', (string) $t['notes'] ) as $line ) {
					$line = trim( preg_replace( '/^چرا:\s*/u', '', $line ) );
					if ( '' !== $line ) {
						$why = $line;
						break;
					}
				}
				?>
				<li class="is-<?php echo esc_attr( $t['status'] ); ?>">
					<span class="sap-start-n"><?php echo esc_html( sap_fa( $n + 1 ) ); ?></span>
					<div>
						<b><?php echo esc_html( $t['title'] ); ?></b>
						<?php if ( $why ) : ?><p class="sap-small"><span class="sap-muted">چرا اول این؟</span> <?php echo esc_html( mb_substr( $why, 0, 220 ) ); ?></p><?php endif; ?>
						<small class="sap-muted"><?php echo esc_html( sap_context_label( $t['context'] ) . ' · ' . sap_num( $t['effort'], 1 ) . ' ساعت' . ( $t['due_date'] ? ' · سررسید ' . sap_jdate( $t['due_date'], 'day' ) : '' ) . ( 'doing' === $t['status'] ? ' · در حال انجام' : '' ) ); ?></small>
					</div>
					<a class="sap-btn is-small <?php echo 0 === $n ? 'is-primary' : ''; ?>" href="<?php echo esc_url( sap_project_url( $pid, 'plan', array( 'task' => $t['id'] ) ) ); ?>"><?php echo 'doing' === $t['status'] ? 'ادامه' : 'شروع'; ?></a>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>
<?php endif; ?>

<?php if ( $ai ) : ?>
	<section class="sap-panel sap-ai">
		<div class="sap-panel-head">
			<h2><span class="sap-ai-badge"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>AI</span>تشخیص هوشمند وضعیت پروژه</h2>
			<small class="sap-muted" dir="auto"><?php echo esc_html( sap_jdate( $ai_ds['fetched_at'] ) . ' · ' . ( $ai_ds['meta']['model'] ?? '' ) . ( isset( $ai_ds['meta']['cost'] ) ? ' · ' . sap_num( $ai_ds['meta']['cost'], 3 ) . ' دلار' : '' ) ); ?></small>
		</div>
		<p class="sap-ai-summary"><?php echo esc_html( $ai['summary'] ); ?></p>
		<p><a href="<?php echo esc_url( sap_project_url( $pid, 'insights' ) ); ?>">جزئیات تحلیل: صفحات و محتوا، لینک داخلی، کنیبالیزیشن، تکنیکال و سرعت ←</a></p>
		<?php if ( $ai['health'] ) : ?>
			<div class="sap-health">
				<?php
				foreach ( $ai['health'] as $h ) :
					$score = max( 0, min( 100, (int) $h['score'] ) );
					$cls   = $score >= 70 ? 'is-good' : ( $score >= 45 ? 'is-mid' : 'is-bad' );
					?>
					<div class="sap-health-item <?php echo esc_attr( $cls ); ?>" title="<?php echo esc_attr( $h['note'] ); ?>">
						<div class="sap-health-top"><span><?php echo esc_html( sap_health_areas()[ $h['area'] ] ?? $h['area'] ); ?></span><b><?php echo esc_html( sap_num( $score ) ); ?></b></div>
						<div class="sap-meter"><i style="width:<?php echo esc_attr( $score ); ?>%"></i></div>
						<small><?php echo esc_html( $h['note'] ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<div class="sap-grid-2 sap-ai-lists">
			<?php if ( $ai['wins'] ) : ?>
				<div><p class="sap-label is-good">نقاط قوت</p><ul><?php foreach ( $ai['wins'] as $w ) : ?><li><?php echo esc_html( $w ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>
			<?php if ( $ai['risks'] ) : ?>
				<div><p class="sap-label is-bad">ریسک‌ها</p><ul><?php foreach ( $ai['risks'] as $r ) : ?><li><?php echo esc_html( $r ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>
		</div>
		<?php foreach ( $ai['focus_insights'] as $fi ) : ?>
			<p class="sap-ai-focus"><?php echo sap_icon( 'target', 14 ); // phpcs:ignore ?><b><?php echo esc_html( $fi['focus'] ); ?>:</b> <?php echo esc_html( $fi['insight'] ); ?></p>
		<?php endforeach; ?>

		<div class="sap-panel-head sap-ai-plan-head">
			<h3>اکشن پلن پیشنهادی (<?php echo esc_html( sap_num( count( $ai['plan'] ) ) ); ?> کار · <?php echo esc_html( sap_num( array_sum( array_column( $ai['plan'], 'effort_hours' ) ), 1 ) ); ?> ساعت)</h3>
			<?php if ( count( array_intersect_key( array_flip( array_column( $ai['plan'], 'ref' ) ), $ai_refs ) ) < count( $ai['plan'] ) ) : ?>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
					<?php sap_form_fields( 'sap_plan_generate', array( 'project_id' => $pid, 'period' => $period ) ); ?>
					<button class="sap-btn is-primary is-small" type="submit"><?php echo sap_icon( 'plus', 14 ); // phpcs:ignore ?>افزودن به اکشن پلن <?php echo esc_html( sap_period_label( $period ) ); ?></button>
				</form>
			<?php else : ?>
				<a class="sap-btn is-small" href="<?php echo esc_url( sap_project_url( $pid, 'plan' ) ); ?>">در اکشن پلن است ←</a>
			<?php endif; ?>
		</div>
		<ol class="sap-ai-plan">
			<?php foreach ( $ai['plan'] as $item ) : ?>
				<li class="<?php echo isset( $ai_refs[ $item['ref'] ] ) ? 'is-added' : ''; ?>">
					<details>
						<summary>
							<span class="sap-impact is-<?php echo esc_attr( $item['impact'] ); ?>"><?php echo esc_html( array( 'high' => 'اثر بالا', 'medium' => 'اثر متوسط', 'low' => 'اثر کم' )[ $item['impact'] ] ?? '' ); ?></span>
							<b><?php echo esc_html( $item['title'] ); ?></b>
							<span class="sap-muted sap-small">هفته <?php echo esc_html( sap_fa( max( 1, (int) $item['week'] ) ) ); ?> · <?php echo esc_html( sap_num( $item['effort_hours'], 1 ) ); ?> ساعت<?php echo isset( $ai_refs[ $item['ref'] ] ) ? ' · ✓ در برنامه' : ''; ?></span>
						</summary>
						<p><b>چرا:</b> <?php echo esc_html( $item['why'] ); ?></p>
						<?php if ( $item['steps'] ) : ?>
							<ol class="sap-steps-list"><?php foreach ( $item['steps'] as $st ) : ?><li><?php echo esc_html( $st ); ?></li><?php endforeach; ?></ol>
						<?php endif; ?>
						<?php if ( '' !== trim( $item['deliverable'] ) ) : ?>
							<div class="sap-ai-output"><div class="sap-ai-output-head">خروجی آماده<button type="button" class="sap-icon-btn" data-sap-copy-target title="کپی"><?php echo sap_icon( 'log', 14 ); // phpcs:ignore ?></button></div><pre><?php echo esc_html( $item['deliverable'] ); ?></pre></div>
						<?php endif; ?>
					</details>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>
<?php elseif ( ! sap_ai_connected() && 'connect' !== $path['current'] ) : ?>
	<section class="sap-panel sap-ai is-empty">
		<h2><span class="sap-ai-badge"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>AI</span>تحلیل هوشمند را فعال کنید</h2>
		<p>با اتصال کلید API هوش مصنوعی (Claude)، ابزار وضعیت پروژه را از روی سرچ کنسول، خزش و شیت‌ها تشخیص می‌دهد و اکشن پلنی با کارهای مشخص (کدام صفحه، کدام کلمه، چرا و چطور) و خروجی‌های آماده مثل تایتل و متا و FAQ می‌سازد.</p>
		<a class="sap-btn is-primary" href="<?php echo esc_url( sap_url( 'settings' ) ); ?>">اتصال هوش مصنوعی</a>
	</section>
<?php endif; ?>

<?php
$wk     = sap_week_digest( $project );
$tscore = sap_tech_score( sap_tech_checklist( $project ) );
$kh     = sap_keymap_health( $project );
$imp    = sap_impact_summary( $pid );
$tone   = function ( $v ) {
	return null === $v ? '' : ( $v >= 85 ? 'is-good' : ( $v >= 60 ? 'is-warn' : 'is-bad' ) );
};
?>
<section class="sap-panel sap-week">
	<div class="sap-panel-head">
		<h2><?php echo sap_icon( 'clock' ); // phpcs:ignore ?>این هفته و کارنامه پروژه</h2>
		<a href="<?php echo esc_url( sap_project_url( $pid, 'impact' ) ); ?>">ارزیابی اثر اقدامات ←</a>
	</div>
	<div class="sap-week-cards">
		<a class="<?php echo esc_attr( $tone( $tscore ) ); ?>" href="<?php echo esc_url( sap_project_url( $pid, 'technical' ) ); ?>"><b><?php echo null === $tscore ? '—' : esc_html( sap_num( $tscore ) ); ?></b><span>سلامت تکنیکال</span></a>
		<a class="<?php echo esc_attr( $tone( $kh['score'] ) ); ?>" href="<?php echo esc_url( sap_project_url( $pid, 'keywords' ) ); ?>"><b><?php echo null === $kh['score'] ? '—' : esc_html( sap_num( $kh['score'] ) ); ?></b><span>سلامت نقشه کلمات</span><small><?php echo esc_html( sprintf( '%s از %s صفحه مهم تعیین‌تکلیف شده', sap_num( $kh['decided'] ), sap_num( $kh['important'] ) ) ); ?></small></a>
		<a href="<?php echo esc_url( sap_project_url( $pid, 'impact' ) ); ?>"><b><span class="sap-ok"><?php echo esc_html( sap_num( $imp['growth'] ) ); ?></span> / <span class="sap-bad"><?php echo esc_html( sap_num( $imp['decline'] ) ); ?></span></b><span>اقدامات با رشد / افت</span><small><?php echo esc_html( sprintf( '%s اقدام سنجیده‌شده', sap_num( $imp['total'] ) ) ); ?></small></a>
		<a href="<?php echo esc_url( sap_project_url( $pid, 'plan' ) ); ?>" class="<?php echo $wk['overdue'] ? 'is-bad' : ''; ?>"><b><?php echo esc_html( sap_num( count( $wk['done'] ) ) ); ?></b><span>کار انجام‌شده در ۷ روز</span><small><?php echo esc_html( sprintf( '%s عقب‌افتاده · %s سررسید هفته آینده', sap_num( count( $wk['overdue'] ) ), sap_num( count( $wk['due'] ) ) ) ); ?></small></a>
	</div>
	<?php if ( $wk['by_focus'] ) : ?>
		<ul class="sap-week-focus">
			<?php foreach ( $wk['by_focus'] as $fname => $x ) : ?>
				<li><b><?php echo esc_html( $fname ); ?></b><span><?php echo esc_html( sprintf( '%s کار انجام شد (%s ساعت)', sap_num( $x['done'] ), sap_num( $x['hours'], 1 ) ) ); ?></span><?php if ( $x['overdue'] ) : ?><span class="sap-bad"><?php echo esc_html( sap_num( $x['overdue'] ) ); ?> عقب‌افتاده</span><?php endif; ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( $wk['overdue'] ) : ?>
		<p class="sap-small sap-muted">عقب‌افتاده‌ها: <?php echo esc_html( implode( ' · ', array_map( function ( $t ) {
			return $t['title'];
		}, array_slice( $wk['overdue'], 0, 4 ) ) ) ); ?></p>
	<?php endif; ?>
</section>

<div class="sap-grid-2">
	<section class="sap-panel">
		<div class="sap-panel-head">
			<h2><?php echo sap_icon( 'target' ); // phpcs:ignore ?>Focus و اهداف</h2>
			<a href="<?php echo esc_url( sap_project_url( $pid, 'focuses' ) ); ?>">مدیریت ←</a>
		</div>
		<?php if ( ! $focuses ) : ?>
			<p class="sap-muted">بدون Focus؛ کل سایت با وزن برابر تحلیل می‌شود.</p>
		<?php else : ?>
			<?php
			foreach ( $focuses as $f ) :
				$k    = sap_focus_kpis( $f, $pid );
				$cur  = $k ? $k[ $f['kpi_metric'] ] : null;
				$prog = sap_focus_progress( $f, $cur );
				?>
				<div class="sap-focus-row <?php echo 'active' !== $f['status'] ? 'is-muted' : ''; ?>">
					<div class="sap-focus-row-top">
						<b><?php echo esc_html( $f['name'] ); ?></b>
						<span><?php echo esc_html( sap_kpi_metrics()[ $f['kpi_metric'] ] ); ?>: <b><?php echo null === $cur ? '—' : esc_html( sap_num( $cur ) ); ?></b><?php echo $f['kpi_target'] > 0 ? ' / ' . esc_html( sap_num( $f['kpi_target'] ) ) : ''; ?></span>
					</div>
					<?php if ( null !== $prog ) : ?>
						<div class="sap-progress"><i style="width:<?php echo esc_attr( round( $prog * 100 ) ); ?>%"></i></div>
					<?php endif; ?>
					<small class="sap-muted"><?php echo esc_html( sprintf( '%s یافته باز', sap_num( $counts['focus'][ $f['id'] ] ?? 0 ) ) ); ?><?php echo $k ? esc_html( sprintf( ' · %s از %s کلمه در صفحه اول', sap_num( $k['top10'] ), sap_num( $k['keywords'] ) ) ) : ''; ?></small>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</section>

	<section class="sap-panel">
		<div class="sap-panel-head">
			<h2><?php echo sap_icon( 'list' ); // phpcs:ignore ?><?php echo esc_html( sap_period_label( $period ) ); ?></h2>
			<a href="<?php echo esc_url( sap_project_url( $pid, 'plan' ) ); ?>">اکشن پلن ←</a>
		</div>
		<?php if ( ! $tasks ) : ?>
			<p class="sap-muted">برنامه این ماه هنوز ساخته نشده.</p>
		<?php else : ?>
			<div class="sap-mini-stats">
				<?php foreach ( array( 'next' => 'باقی‌مانده', 'doing' => 'در حال انجام', 'waiting' => 'در انتظار', 'done' => 'انجام شد' ) as $s => $label ) : ?>
					<span><b><?php echo esc_html( sap_num( $by_st[ $s ] ?? 0 ) ); ?></b><?php echo esc_html( $label ); ?></span>
				<?php endforeach; ?>
			</div>
			<div class="sap-progress"><i style="width:<?php echo esc_attr( round( 100 * $done_h / max( 1, $hours ) ) ); ?>%"></i></div>
			<small class="sap-muted"><?php echo esc_html( sprintf( '%s از %s ساعت · بازبینی هفتگی: %s', sap_num( $done_h, 1 ), sap_num( $hours, 1 ), $review['last'] ? sap_jdate( $review['last'], 'day' ) : 'انجام نشده' ) ); ?></small>
		<?php endif; ?>
	</section>
</div>

<details class="sap-panel">
	<summary><?php echo sap_icon( 'grid' ); // phpcs:ignore ?>منابع داده و آخرین بروزرسانی</summary>
	<?php $status = sap_dataset_status( $pid ); ?>
	<table class="sap-table">
		<tbody>
			<?php foreach ( sap_dataset_labels() as $src => $label ) : ?>
				<tr>
					<td><?php echo esc_html( $label ); ?></td>
					<td class="sap-muted"><?php echo isset( $status[ $src ] ) ? esc_html( sap_jdate( $status[ $src ]['fetched_at'] ) ) : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</details>
