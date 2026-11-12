<?php
/**
 * «دستیار پروژه»: chat with the AI about the tasks of one project.
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid     = (int) $project['id'];
$history = sap_pm_assist_history( $pid );
$users   = sap_team_users();
$ready   = sap_ai_connected();
$starts  = array( 'امروز در این پروژه چه خبر است؟', 'چه کارهایی عقب افتاده یا گیر کرده؟', 'این هفته چه چیزهایی باید تحویل شود؟', 'کار چه کسی بیشتر از همه مانده؟' );
?>
<section class="sap-assist<?php echo $history ? ' is-open' : ''; ?>" data-sap-assist="<?php echo esc_attr( $pid ); ?>">
	<header class="sap-assist-head">
		<span class="sap-assist-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.8 4.6L18.5 9l-4.7 1.6L12 15l-1.8-4.4L5.5 9l4.7-1.4z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8zM5 16l.6 1.4L7 18l-1.4.6L5 20l-.6-1.4L3 18l1.4-.6z"/></svg></span>
		<div>
			<b>دستیار پروژه</b>
			<small>درباره کارهای <?php echo sap_can() ? 'این پروژه' : 'که در این پروژه واگذار کرده‌اید'; ?> بپرسید: «سئو تکنیکال انجام شد؟»، «فلانی روی چه کاری است؟». اگر کاری ثبت نشده باشد، پیشنهادش را می‌دهد تا همین‌جا بسازید.</small>
		</div>
		<button type="button" class="sap-btn is-ghost is-small" data-sap-assist-clear <?php echo $history ? '' : 'hidden'; ?>>گفتگوی تازه</button>
	</header>

	<div class="sap-assist-log" data-sap-assist-log>
		<?php foreach ( $history as $m ) : ?>
			<?php if ( 'user' === $m['role'] ) : ?>
				<div class="sap-assist-msg is-me"><p><?php echo esc_html( $m['text'] ); ?></p></div>
			<?php else : ?>
				<div class="sap-assist-msg is-bot"><?php echo sap_pm_assist_render( $m['text'], (array) ( $m['refs'] ?? array() ), $pid ); // phpcs:ignore ?></div>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>

	<?php if ( $ready ) : ?>
		<div class="sap-assist-starts" data-sap-assist-starts <?php echo $history ? 'hidden' : ''; ?>>
			<?php foreach ( $starts as $s ) : ?><button type="button" class="sap-chip" data-sap-assist-ask="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></button><?php endforeach; ?>
		</div>
		<form class="sap-assist-input" data-sap-assist-form>
			<textarea name="q" rows="1" maxlength="1500" placeholder="مثلاً: بهینه‌سازی صفحه دسته‌بندی‌ها انجام شده؟" required></textarea>
			<button type="submit" class="sap-btn is-primary" aria-label="ارسال"><?php echo sap_icon( 'send', 16 ); // phpcs:ignore ?><span>بپرس</span></button>
		</form>
		<template data-sap-assist-users><option value="0">بدون مسئول</option><?php foreach ( $users as $u ) : ?><option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?></template>
	<?php else : ?>
		<p class="sap-muted sap-small">برای استفاده از دستیار، هوش مصنوعی باید در تنظیمات وصل باشد<?php echo sap_can() ? '' : ' (از مدیر سئو بخواهید)'; ?>.</p>
	<?php endif; ?>
</section>
