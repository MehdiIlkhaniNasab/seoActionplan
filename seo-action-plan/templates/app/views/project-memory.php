<?php
/**
 * Project memory: decisions, client requests, constraints, lessons, business
 * facts. Pinned items always reach the AI; recent ones too.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid   = $project['id'];
$kinds = sap_memory_kinds();
$kind  = sanitize_key( $_GET['k'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
$kind  = isset( $kinds[ $kind ] ) ? $kind : '';
$q     = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
$all   = sap_memory( $pid, array( 'limit' => 1000 ) );
$items = sap_memory( $pid, array( 'kind' => $kind, 'q' => $q, 'limit' => 300 ) );
$count = array_count_values( array_column( $all, 'kind' ) );
$ai    = sap_memory_for_ai( $pid );
$srcs  = array( 'manual' => 'دستی', 'impact' => 'ارزیابی اثر', 'experiment' => 'آزمایش', 'chat' => 'گفتگو' );
$here  = sap_project_url( $pid, 'memory' );
?>
<section class="sap-panel sap-mem-head">
	<div>
		<h2><?php echo sap_icon( 'brain' ); // phpcs:ignore ?>حافظه پروژه</h2>
		<p class="sap-muted">هر چیزی که نباید فراموش شود: تصمیم‌ها، خواسته‌ها و محدودیت‌های کارفرما، اطلاعات کسب‌وکار و درس‌هایی که از نتیجه کارها گرفته شده. موارد سنجاق‌شده و جدید در همه تحلیل‌ها و پیشنهادهای هوش مصنوعی همین پروژه لحاظ می‌شوند؛ درس‌ها از ارزیابی اثر و آزمایش‌ها خودکار اضافه می‌شوند.</p>
	</div>
	<div class="sap-mem-stat"><b><?php echo esc_html( sap_num( count( $all ) ) ); ?></b><span>مورد ثبت‌شده</span><em><?php echo esc_html( sap_num( count( $ai ) ) ); ?> مورد الان به هوش مصنوعی داده می‌شود</em></div>
</section>

<section class="sap-panel">
	<form class="sap-mem-form" data-sap-mem-form data-project="<?php echo esc_attr( $pid ); ?>">
		<div class="sap-mem-kinds">
			<?php foreach ( $kinds as $k => $kd ) : ?>
				<label class="sap-mem-kind" style="--c:<?php echo esc_attr( $kd[2] ); ?>"><input type="radio" name="kind" value="<?php echo esc_attr( $k ); ?>" <?php checked( 'decision', $k ); ?>><span><?php echo sap_icon( $kd[1], 14 ); // phpcs:ignore ?><?php echo esc_html( $kd[0] ); ?></span></label>
			<?php endforeach; ?>
		</div>
		<input type="text" name="title" required maxlength="250" placeholder="خلاصه (مثلاً: کارفرما نمی‌خواهد قیمت در تایتل بیاید)">
		<textarea name="body" rows="2" placeholder="جزئیات، دلیل، تاریخ، منبع (اختیاری)"></textarea>
		<div class="sap-mem-row">
			<input type="url" name="url" dir="ltr" placeholder="صفحه مرتبط (اختیاری)">
			<input type="text" name="keyword" placeholder="کلمه مرتبط (اختیاری)">
			<label class="sap-check"><input type="checkbox" name="pinned" value="1"> سنجاق (همیشه به هوش مصنوعی داده شود)</label>
			<button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'plus', 14 ); // phpcs:ignore ?>افزودن به حافظه</button>
		</div>
	</form>
</section>

<section class="sap-panel">
	<div class="sap-auto-filter">
		<nav class="sap-seg is-small">
			<a class="<?php echo '' === $kind ? 'is-active' : ''; ?>" href="<?php echo esc_url( $here ); ?>">همه <small><?php echo esc_html( sap_num( count( $all ) ) ); ?></small></a>
			<?php foreach ( $kinds as $k => $kd ) : ?>
				<?php if ( ! empty( $count[ $k ] ) ) : ?>
					<a class="<?php echo $k === $kind ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'k', $k, $here ) ); ?>"><?php echo esc_html( $kd[0] ); ?> <small><?php echo esc_html( sap_num( $count[ $k ] ) ); ?></small></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
		<form method="get" action="<?php echo esc_url( $here ); ?>" class="sap-auto-search"><input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="جستجو در حافظه"></form>
	</div>
	<?php if ( ! $items ) : ?>
		<div class="sap-empty"><?php echo sap_icon( 'brain', 28 ); // phpcs:ignore ?><p>هنوز چیزی در حافظه نیست. اولین تصمیم یا خواسته کارفرما را ثبت کنید.</p></div>
	<?php else : ?>
		<ul class="sap-mem-list">
			<?php foreach ( $items as $m ) : ?>
				<?php $kd = $kinds[ $m['kind'] ] ?? $kinds['note']; ?>
				<li class="<?php echo $m['pinned'] ? 'is-pinned' : ''; ?>" style="--c:<?php echo esc_attr( $kd[2] ); ?>">
					<span class="sap-mem-ico"><?php echo sap_icon( $kd[1], 16 ); // phpcs:ignore ?></span>
					<div>
						<p class="sap-mem-top"><span class="sap-mem-k"><?php echo esc_html( $kd[0] ); ?></span><b><?php echo esc_html( $m['title'] ); ?></b></p>
						<?php if ( $m['body'] ) : ?><p class="sap-mem-body" dir="auto"><?php echo nl2br( esc_html( $m['body'] ) ); ?></p><?php endif; ?>
						<p class="sap-mem-meta">
							<?php echo esc_html( sap_jdate( $m['created_at'], 'long' ) ); ?> · <?php echo esc_html( $srcs[ $m['source'] ] ?? $m['source'] ); ?><?php echo $m['user_id'] ? ' · ' . esc_html( sap_user_name( (int) $m['user_id'] ) ) : ''; ?>
							<?php echo $m['keyword'] ? ' · «' . esc_html( $m['keyword'] ) . '»' : ''; ?>
							<?php echo $m['url'] ? ' · ' . sap_url_tag( $m['url'], 40 ) : ''; // phpcs:ignore ?>
						</p>
					</div>
					<div class="sap-mem-acts">
						<button type="button" class="sap-icon-btn <?php echo $m['pinned'] ? 'is-on' : ''; ?>" data-sap-mem="<?php echo $m['pinned'] ? 'unpin' : 'pin'; ?>" data-id="<?php echo esc_attr( $m['id'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" title="<?php echo $m['pinned'] ? 'برداشتن سنجاق' : 'سنجاق کردن'; ?>">📌</button>
						<button type="button" class="sap-icon-btn" data-sap-mem="delete" data-id="<?php echo esc_attr( $m['id'] ); ?>" data-project="<?php echo esc_attr( $pid ); ?>" title="حذف"><?php echo sap_icon( 'trash', 16 ); // phpcs:ignore ?></button>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
