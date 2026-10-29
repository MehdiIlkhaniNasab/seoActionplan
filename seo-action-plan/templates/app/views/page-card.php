<?php
/**
 * One page card: every proposal of the page as a checklist, its brief, your
 * notes and one approval that makes a single task (used by the content pillar,
 * the technical pillar and the findings inbox).
 *
 * @package SeoActionPlan
 * @var array $project
 * @var array $pg      {url, items, score}
 */

defined( 'ABSPATH' ) || exit;

$pid     = $project['id'];
$kinds   = sap_page_action_kinds();
$site_ok = sap_site_connected( $project );
$prio    = array( 'high' => 'اولویت بالا', 'medium' => 'متوسط', 'low' => 'کم' );
?>
			<?php
			$b     = sap_page_brief( $project, $pg['url'], $pg['items'] );
			$hours = array_sum( array_map( function ( $f ) {
				return (float) $f['effort'];
			}, $pg['items'] ) );
			$top   = $pg['score'] >= 60 ? 'high' : ( $pg['score'] >= 35 ? 'medium' : 'low' );
			$cid   = sap_page_comment_id( $pg['url'] );
			?>
			<article class="sap-pagecard is-<?php echo esc_attr( $top ); ?>" data-sap-pagecard="<?php echo esc_attr( $pg['url'] ); ?>">
				<header class="sap-pagecard-head">
					<div class="sap-pagecard-name">
						<b><?php echo esc_html( $b['title'] ); ?></b>
						<?php echo sap_url_tag( $b['url'], 60 ); // phpcs:ignore ?>
					</div>
					<div class="sap-pagecard-meta">
						<?php if ( '' !== $b['type'] ) : ?><span class="sap-chip is-soft"><?php echo esc_html( $b['type'] ); ?></span><?php endif; ?>
						<?php if ( '' !== $b['keyword'] ) : ?><span class="sap-chip" title="کلمه اصلی">«<?php echo esc_html( $b['keyword'] ); ?>»</span><?php endif; ?>
						<?php if ( '' !== $b['focus'] ) : ?><span class="sap-chip is-focus"><?php echo esc_html( $b['focus'] ); ?></span><?php endif; ?>
						<span class="sap-chip is-prio-<?php echo esc_attr( $top ); ?>"><?php echo esc_html( $prio[ $top ] ); ?></span>
						<span class="sap-muted sap-small"><?php echo esc_html( sprintf( '%s نمایش · %s کلیک · %s ساعت', sap_num( $b['impr'] ), sap_num( $b['clicks'] ), sap_num( $hours, 1 ) ) ); ?></span>
						<button type="button" class="sap-btn is-small is-ghost" data-sap-audit-view="<?php echo esc_attr( $pg['url'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>">تحلیل کامل صفحه</button>
					</div>
				</header>

				<?php if ( '' !== $b['diagnosis'] ) : ?><p class="sap-pagecard-diag"><?php echo esc_html( $b['diagnosis'] ); ?></p><?php endif; ?>

				<ul class="sap-pagecard-checks">
					<?php foreach ( $pg['items'] as $f ) : ?>
						<?php
						$kind = $f['evidence']['kind'] ?? '';
						$det  = trim( (string) ( $f['evidence']['deliverable'] ?? '' ) ) ?: trim( preg_replace( '/\n+تشخیص:.*$/su', '', (string) $f['detail'] ) );
						?>
						<li data-sap-pp-item="<?php echo esc_attr( $f['id'] ); ?>">
							<label class="sap-check"><input type="checkbox" value="<?php echo esc_attr( $f['id'] ); ?>" checked></label>
							<div>
								<p><span class="sap-chip is-soft"><?php echo esc_html( $kinds[ $kind ][0] ?? sap_context_label( $f['context'] ) ); ?></span> <b><?php echo esc_html( $f['title'] ); ?></b> <small class="sap-muted"><?php echo esc_html( sap_num( $f['effort'], 1 ) ); ?> ساعت</small></p>
								<?php $seo = sap_finding_seo_change( $f ); ?>
								<?php if ( $seo ) : ?>
									<div class="sap-seo-diff">
										<div><span>فعلی</span><b dir="auto"><?php echo esc_html( $seo['before']['title'] ?: '—' ); ?></b><small dir="auto"><?php echo esc_html( $seo['before']['meta'] ?: '—' ); ?></small></div>
										<div class="is-new"><span>پیشنهادی</span><b dir="auto"><?php echo esc_html( $seo['after']['title'] ?: $seo['before']['title'] ); ?></b><small dir="auto"><?php echo esc_html( $seo['after']['meta'] ?: $seo['before']['meta'] ); ?></small><em><?php echo esc_html( sprintf( 'تایتل %s کاراکتر · متا %s کاراکتر', sap_num( mb_strlen( $seo['after']['title'] ) ), sap_num( mb_strlen( $seo['after']['meta'] ) ) ) ); ?></em></div>
									</div>
									<?php if ( $site_ok && $seo['site'] ) : ?>
										<button type="button" class="sap-btn is-small is-soft" data-sap-seo-apply="<?php echo esc_attr( $f['id'] ); ?>"><?php echo sap_icon( 'check', 13 ); // phpcs:ignore ?>اعمال همین الان روی سایت</button>
									<?php endif; ?>
								<?php elseif ( '' !== $det || ! empty( $f['evidence']['items'] ) ) : ?>
									<details><summary>توضیح و راه‌حل</summary>
										<?php if ( '' !== $det ) : ?><div class="sap-deliverable" dir="auto"><?php echo sap_rich_text( $det, sap_site_base( $project ) ); // phpcs:ignore ?></div><?php endif; ?>
										<?php sap_evidence_table( $f['evidence'], $pid ); ?>
									</details>
								<?php endif; ?>
							</div>
							<button type="button" class="sap-icon-btn" data-sap-pp-drop="<?php echo esc_attr( $f['id'] ); ?>" title="این اقدام لازم نیست (حذف)"><?php echo sap_icon( 'x', 14 ); // phpcs:ignore ?></button>
						</li>
					<?php endforeach; ?>
				</ul>

				<details class="sap-pagecard-brief">
					<summary>بریف صفحه (<?php echo esc_html( '' !== $b['type'] ? $b['type'] : 'صفحه' ); ?>)</summary>
					<div class="sap-brief-grid">
						<?php if ( '' !== $b['keyword'] ) : ?><div><span>کلمه اصلی</span><b><?php echo esc_html( $b['keyword'] ); ?></b><?php echo $b['secondary'] ? '<small>فرعی: ' . esc_html( implode( '، ', $b['secondary'] ) ) . '</small>' : ''; ?></div><?php endif; ?>
						<?php if ( $b['queries'] ) : ?><div><span>کوئری‌های واقعی</span><small><?php echo esc_html( implode( '، ', array_map( function ( $q ) { return $q[0] . ' (' . sap_num( $q[1], 1 ) . ')'; }, $b['queries'] ) ) ); ?></small></div><?php endif; ?>
						<?php if ( '' !== $b['new_title'] ) : ?><div><span>تایتل پیشنهادی</span><b dir="auto"><?php echo esc_html( $b['new_title'] ); ?></b></div><?php endif; ?>
						<?php if ( '' !== $b['new_meta'] ) : ?><div><span>متای پیشنهادی</span><small dir="auto"><?php echo esc_html( $b['new_meta'] ); ?></small></div><?php endif; ?>
					</div>
					<?php if ( '' !== $b['update'] ) : ?><h4>بریف آپدیت محتوا</h4><p class="sap-small" dir="auto"><?php echo sap_rich_text( $b['update'], sap_site_base( $project ) ); // phpcs:ignore ?></p><?php endif; ?>
					<?php if ( $b['kw_fit'] ) : ?>
						<h4>کلمات نامناسب این صفحه</h4>
						<ul class="sap-small"><?php foreach ( $b['kw_fit'] as $kf ) : ?><li>«<?php echo esc_html( $kf['keyword'] ?? '' ); ?>» — <?php echo esc_html( $kf['why'] ?? '' ); ?></li><?php endforeach; ?></ul>
					<?php endif; ?>
					<h4>استاندارد یک «<?php echo esc_html( '' !== $b['type'] ? $b['type'] : 'صفحه' ); ?>» کامل</h4>
					<ul class="sap-small sap-brief-std"><?php foreach ( $b['standard'] as $s ) : ?><li><?php echo esc_html( $s ); ?></li><?php endforeach; ?></ul>
					<?php if ( $b['checks'] ) : ?><p class="sap-small sap-muted">ایرادهای خودکار: <?php echo esc_html( implode( ' · ', $b['checks'] ) ); ?></p><?php endif; ?>
					<?php if ( $b['recent'] ) : ?><p class="sap-small sap-muted">کارهای اخیر روی این صفحه: <?php echo esc_html( implode( ' · ', $b['recent'] ) ); ?></p><?php endif; ?>
				</details>

				<details class="sap-pagecard-notes" <?php echo $b['notes'] ? 'open' : ''; ?>>
					<summary>دیدگاه من درباره این صفحه<?php echo $b['notes'] ? ' (' . esc_html( sap_num( count( $b['notes'] ) ) ) . ')' : ''; ?></summary>
					<?php sap_view( 'comments', array( 'type' => 'page', 'object_id' => $cid, 'project_id' => $pid ) ); ?>
				</details>

				<footer class="sap-pagecard-foot">
					<button type="button" class="sap-btn is-primary" data-sap-pp-approve="<?php echo esc_attr( $pid ); ?>"><?php echo sap_icon( 'check', 15 ); // phpcs:ignore ?>تأیید صفحه و افزودن به اکشن پلن</button>
					<button type="button" class="sap-btn is-ghost is-danger" data-sap-pp-reject>رد همه اقدامات این صفحه</button>
				</footer>
			</article>
