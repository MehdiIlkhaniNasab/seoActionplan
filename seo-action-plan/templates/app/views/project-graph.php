<?php
/**
 * Keyword & page graph: an animated, floating network of the site's pages,
 * keywords, internal links and planned articles, grouped by topic.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$graph = sap_keyword_graph( $project );
$st    = $graph['stats'];
?>
<?php if ( ! $st['pages'] ) : ?>
	<div class="sap-empty"><?php echo sap_icon( 'link', 32 ); // phpcs:ignore ?><p>گراف بعد از ساخته شدن نقشه کلمات نمایش داده می‌شود. «تحلیل کامل» را بزنید.</p></div>
	<?php
	return;
endif;
?>
<div class="sap-graph-insights">
	<div class="sap-stat"><span>موضوع (خوشه)</span><b><?php echo esc_html( sap_num( count( $graph['topics'] ) ) ); ?></b></div>
	<div class="sap-stat"><span>صفحه · کلمه</span><b><?php echo esc_html( sap_num( $st['pages'] ) . ' · ' . sap_num( $st['keywords'] ) ); ?></b></div>
	<div class="sap-stat"><span>لینک داخلی بین صفحات مهم</span><b><?php echo esc_html( sap_num( $st['links'] ) ); ?></b></div>
	<div class="sap-stat <?php echo $st['orphans'] ? 'is-bad' : ''; ?>" title="صفحاتی که هیچ صفحه مهم دیگری به آن‌ها لینک نمی‌دهد"><span>صفحه یتیم (بدون لینک ورودی)</span><b><?php echo $st['read'] ? esc_html( sap_num( $st['orphans'] ) ) : '—'; ?></b></div>
	<div class="sap-stat"><span>کلمه در صفحه دوم</span><b><?php echo esc_html( sap_num( $st['near'] ) ); ?></b></div>
</div>

<section class="sap-panel sap-graph-box">
	<div class="sap-graph-bar">
		<input type="search" placeholder="جستجوی صفحه یا کلمه…" data-sap-graph-search>
		<div class="sap-graph-layers">
			<label class="sap-check"><input type="checkbox" data-layer="kw" checked> کلمات</label>
			<label class="sap-check"><input type="checkbox" data-layer="link" checked> لینک‌های داخلی</label>
			<label class="sap-check"><input type="checkbox" data-layer="suggested" checked> لینک‌های پیشنهادی</label>
			<label class="sap-check"><input type="checkbox" data-layer="idea" checked> مقالات تقویم</label>
			<label class="sap-check"><input type="checkbox" data-sap-graph-float checked> حرکت شناور</label>
		</div>
		<button type="button" class="sap-btn is-small" data-sap-graph-fit>نمای کامل</button>
	</div>
	<div class="sap-graph-topics" data-sap-graph-topics></div>
	<div class="sap-graph-stage">
		<svg data-sap-graph aria-label="گراف کلمات و صفحات"></svg>
		<aside class="sap-graph-info" data-sap-graph-info hidden></aside>
		<div class="sap-graph-tip" data-sap-graph-tip hidden></div>
	</div>
	<div class="sap-graph-legend">
		<span><i class="is-page"></i>صفحه (اندازه = نمایش)</span>
		<span><i class="is-orphan"></i>صفحه یتیم</span>
		<span><i class="is-kw top3"></i>کلمه رتبه ۱-۳</span>
		<span><i class="is-kw top10"></i>صفحه اول</span>
		<span><i class="is-kw near"></i>۱۱ تا ۲۰</span>
		<span><i class="is-kw far"></i>دورتر</span>
		<span><i class="is-kw none"></i>بدون رتبه</span>
		<span><i class="is-idea"></i>مقاله تقویم</span>
		<span><b class="ln"></b>لینک داخلی</span>
		<span><b class="ln is-sug"></b>لینک پیشنهادی</span>
		<span><b class="ln is-plan"></b>لینک مقاله برنامه‌ریزی‌شده</span>
	</div>
	<p class="sap-muted sap-small">صفحات و کلمات هم‌موضوع کنار هم جمع می‌شوند. روی هر گره بروید تا ارتباط‌هایش روشن شود؛ کلیک کنید تا جزئیات را ببینید؛ بکشید تا جابه‌جا شود؛ با اسکرول بزرگ‌نمایی کنید.<?php echo $st['read'] ? '' : ' (لینک‌های داخلی بعد از مرحله «خواندن محتوای صفحات کلیدی» نمایش داده می‌شوند.)'; ?></p>
</section>
<script type="application/json" data-sap-graph-data><?php echo wp_json_encode( array( 'nodes' => $graph['nodes'], 'edges' => $graph['edges'], 'topics' => $graph['topics'], 'content' => sap_project_url( $pid, 'content' ), 'keywords' => sap_project_url( $pid, 'keywords' ) ) ); ?></script>
<script src="<?php echo esc_url( SAP_URL . 'assets/js/graph.js?ver=' . SAP_VERSION ); ?>" defer></script>
