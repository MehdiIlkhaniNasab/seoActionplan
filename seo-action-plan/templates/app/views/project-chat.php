<?php
/**
 * Chat with the project's data (answers grounded in Search Console, ranks,
 * competitors, pages, tasks and impact; tables, charts, tasks to the plan).
 *
 * @package SeoActionPlan
 * @var array $project
 */

defined( 'ABSPATH' ) || exit;

$pid     = $project['id'];
$history = sap_chat_history( $pid );
$starts  = array(
	'چرا کلیک سایت در ۲۸ روز اخیر نسبت به قبل تغییر کرد؟',
	'سریع‌ترین فرصت‌ها کدام‌اند؟ کلمات جایگاه ۴ تا ۱۵ با نمایش بالا',
	'کدام صفحات بیشترین افت کلیک را داشتند و چه کار کنیم؟',
	'رقبا روی کدام کلمات هدف از ما جلو هستند؟',
	'اثر کارهایی که انجام دادیم چه بوده؟ کدام نوع کار جواب داده؟',
	'وضعیت محتوای سایت: صفحات کم‌محتوا، بدون alt و noindex',
);
?>
<section class="sap-panel sap-chat" data-sap-chat data-project="<?php echo esc_attr( $pid ); ?>" data-labels="<?php echo esc_attr( wp_json_encode( sap_chat_tool_labels(), JSON_UNESCAPED_UNICODE ) ); ?>" data-history="<?php echo esc_attr( wp_json_encode( $history, JSON_UNESCAPED_UNICODE ) ); ?>">
	<div class="sap-chat-head">
		<div>
			<h2><?php echo sap_icon( 'chat' ); // phpcs:ignore ?>گفتگو با داده‌ها</h2>
			<p class="sap-muted sap-small">سوال بپرسید؛ سکان خودش سرچ کنسول، رتبه‌ها، رقبا، صفحات، اکشن پلن و نتیجه کارها را می‌خواند و فقط از روی همین داده‌ها جواب می‌دهد — با جدول، نمودار و کارهای مشخص که با یک کلیک به اکشن پلن می‌روند.</p>
		</div>
		<button type="button" class="sap-btn is-ghost is-small" data-sap-chat-clear <?php echo $history ? '' : 'hidden'; ?>>گفتگوی جدید</button>
	</div>
	<?php if ( ! sap_ai_connected() ) : ?>
		<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?>برای گفتگو، کلید هوش مصنوعی را در تنظیمات وارد کنید.</p>
	<?php endif; ?>
	<div class="sap-chat-log" data-sap-chat-log>
		<div class="sap-chat-welcome" data-sap-chat-welcome <?php echo $history ? 'hidden' : ''; ?>>
			<span class="sap-chat-bot"><?php echo sap_icon( 'bot', 22 ); // phpcs:ignore ?></span>
			<p>سلام! از داده‌های «<?php echo esc_html( $project['name'] ); ?>» هر چه می‌خواهید بپرسید. چند نمونه:</p>
			<div class="sap-chat-starts">
				<?php foreach ( $starts as $s ) : ?><button type="button" class="sap-chip" data-sap-chat-ask="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></button><?php endforeach; ?>
			</div>
		</div>
	</div>
	<form class="sap-chat-input" data-sap-chat-form>
		<textarea name="message" rows="1" placeholder="سوالتان را بنویسید… (Enter برای ارسال، Shift+Enter خط جدید)" <?php disabled( ! sap_ai_connected() ); ?>></textarea>
		<button class="sap-btn is-primary" type="submit" <?php disabled( ! sap_ai_connected() ); ?>><?php echo sap_icon( 'chat', 16 ); // phpcs:ignore ?>ارسال</button>
	</form>
</section>
