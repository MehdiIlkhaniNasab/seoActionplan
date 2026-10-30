<?php
/**
 * Global settings & connections.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

$s     = sap_settings();
$post  = esc_url( admin_url( 'admin-post.php' ) );
$usage = sap_ss_usage();
$auth  = sap_google_auth_url();
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان</p>
		<h1>تنظیمات و اتصال‌ها</h1>
		<p class="sap-muted">کلیدها و توکن‌ها رمزنگاری‌شده در دیتابیس ذخیره می‌شوند و هرگز به مرورگر ارسال نمی‌شوند.</p>
	</div>
</header>

<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
	<?php sap_form_fields( 'sap_save_settings' ); ?>

	<section class="sap-panel">
		<h2>Google (Search Console + Sheets)</h2>
		<div class="sap-status-row">
			<span class="sap-conn <?php echo sap_google_connected() ? 'is-on' : ''; ?>"><i></i>
				<?php
				if ( ! sap_google_connected() ) {
					echo 'متصل نیست';
				} elseif ( 'service' === $s['google_auth_mode'] ) {
					echo 'سرویس اکانت: <span dir="ltr">' . esc_html( sap_google_service_email() ) . '</span>';
				} else {
					echo 'متصل: <span dir="ltr">' . esc_html( $s['google_account_email'] ) . '</span>' . ( sap_google_has_sheets() ? '' : ' — <b>بدون دسترسی Sheets</b>' );
				}
				?>
			</span>
		</div>
		<div class="sap-grid-2">
			<div><p class="sap-label">روش اتصال</p><?php sap_segment( 'google_auth_mode', array( 'oauth' => 'OAuth (حساب گوگل)', 'service' => 'Service Account' ), $s['google_auth_mode'] ); ?></div>
			<label>Redirect URI (در Google Cloud ثبت کنید)<code dir="ltr" data-sap-copy><?php echo esc_html( sap_google_redirect_uri() ); ?></code></label>
		</div>
		<div class="sap-grid-2">
			<label>OAuth Client ID<input type="text" name="google_client_id" dir="ltr" value="<?php echo esc_attr( $s['google_client_id'] ); ?>"></label>
			<label>OAuth Client Secret<input type="text" name="google_client_secret" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['google_client_secret'] ) ); ?>" autocomplete="off"></label>
		</div>
		<label>JSON سرویس اکانت (روش جایگزین)<textarea name="google_service_account" rows="3" dir="ltr" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, … }"><?php echo $s['google_service_account'] ? esc_textarea( '•••• ذخیره شده (' . sap_google_service_email() . ')' ) : ''; ?></textarea>
			<small>ایمیل سرویس اکانت را در پراپرتی‌های سرچ کنسول (Restricted) اضافه کنید و گوگل شیت‌ها را با آن (Editor) به‌اشتراک بگذارید.</small>
		</label>
		<label>کلید API گوگل برای PageSpeed Insights (اختیاری)<input type="text" name="psi_key" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['psi_key'] ) ); ?>" autocomplete="off">
			<small>برای Core Web Vitals. بدون کلید هم کار می‌کند ولی سهمیه محدودتری دارد؛ PageSpeed Insights API را در Google Cloud فعال کنید.</small>
		</label>
		<p class="sap-muted">در Google Cloud Console دو API را فعال کنید (روی هر لینک بزنید و ENABLE را بزنید): <a href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com" target="_blank" rel="noopener"><b>Google Search Console API</b></a> و <a href="https://console.cloud.google.com/apis/library/sheets.googleapis.com" target="_blank" rel="noopener"><b>Google Sheets API</b></a>. اگر Sheets API فعال نباشد، ساخت و خواندن شیت خطای «has not been used in project… or it is disabled» می‌دهد. اگر از SeoReport استفاده می‌کنید می‌توانید از همان پروژه Google Cloud و همان OAuth Client استفاده کنید؛ فقط Redirect URI بالا را هم به Client اضافه کنید.</p>
	</section>

	<section class="sap-panel">
		<h2><span class="sap-ai-badge"><?php echo sap_icon( 'spark', 14 ); // phpcs:ignore ?>AI</span>هوش مصنوعی</h2>
		<?php
		$ai_usage = sap_ai_usage();
		$pct      = $ai_usage['budget'] > 0 ? min( 100, round( 100 * $ai_usage['cost'] / $ai_usage['budget'] ) ) : 0;
		?>
		<div class="sap-status-row">
			<span class="sap-conn <?php echo sap_ai_connected() ? 'is-on' : ''; ?>"><i></i><?php echo sap_ai_connected() ? 'متصل' : 'کلید وارد نشده'; ?></span>
			<span class="sap-muted">این ماه: <?php echo esc_html( sap_num( $ai_usage['requests'] ) ); ?> درخواست · <b><?php echo esc_html( sap_num( $ai_usage['cost'], 3 ) ); ?> دلار</b><?php echo $ai_usage['budget'] > 0 ? ' از سقف ' . esc_html( sap_num( $ai_usage['budget'], 2 ) ) . ' دلار' : ''; ?><?php echo ! empty( $ai_usage['failed'] ) ? ' · <span class="sap-bad">' . esc_html( sprintf( '%s پاسخ ناقص بی‌نتیجه (%s دلار)', sap_num( $ai_usage['failed'] ), sap_num( $ai_usage['wasted'] ?? 0, 3 ) ) ) . '</span>' : ''; ?></span>
		</div>
		<?php if ( $ai_usage['budget'] > 0 ) : ?>
			<div class="sap-progress sap-budget <?php echo $pct >= 90 ? 'is-bad' : ''; ?>"><i style="width:<?php echo esc_attr( $pct ); ?>%"></i></div>
		<?php endif; ?>
		<p class="sap-label">سرویس</p>
		<?php sap_segment( 'ai_provider', array( 'gateway' => 'GapGPT / سازگار با OpenAI', 'anthropic' => 'Anthropic مستقیم' ), $s['ai_provider'] ); ?>
		<div class="sap-grid-2" data-sap-show="ai_provider=gateway">
			<label>آدرس API<input type="url" name="ai_base_url" dir="ltr" value="<?php echo esc_attr( $s['ai_base_url'] ); ?>" placeholder="https://api.gapgpt.app/v1"></label>
			<label>کلید API سرویس<input type="text" name="ai_gateway_key" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['ai_gateway_key'] ) ); ?>" autocomplete="off" placeholder="sk-…">
				<small>کلید فقط روی سرور و رمزنگاری‌شده نگه داشته می‌شود.</small>
			</label>
		</div>
		<div class="sap-grid-2" data-sap-show="ai_provider=anthropic">
			<label>کلید API (Anthropic)<input type="text" name="anthropic_key" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['anthropic_key'] ) ); ?>" autocomplete="off" placeholder="sk-ant-…"></label>
		</div>
		<datalist id="sap-ai-models"><?php foreach ( sap_ai_models() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></datalist>
		<p class="sap-label">پروفایل هزینه (سه فیلد زیر را پر می‌کند؛ بعد ذخیره کنید)</p>
		<div class="sap-ai-profiles">
			<?php foreach ( sap_ai_profiles() as $pk => $pf ) : ?>
				<button type="button" class="sap-ai-profile" data-sap-ai-profile='<?php echo esc_attr( wp_json_encode( array( 'ai_model' => $pf[1], 'ai_model_light' => $pf[2], 'ai_model_assist' => $pf[3] ) ) ); ?>'>
					<b><?php echo esc_html( $pf[0] ); ?></b>
					<span dir="ltr"><?php echo esc_html( $pf[1] . ' · ' . $pf[2] ); ?></span>
					<small><?php echo esc_html( $pf[4] ); ?></small>
					<em>حدود <?php echo esc_html( sap_num( sap_ai_estimate_run( $pf[1], $pf[2] ), 2 ) ); ?> دلار هر تحلیل کامل</em>
				</button>
			<?php endforeach; ?>
		</div>
		<div class="sap-grid-3">
			<label>مدل تحلیل عمیق<input type="text" name="ai_model" dir="ltr" list="sap-ai-models" value="<?php echo esc_attr( $s['ai_model'] ); ?>">
				<small>Focus، تحلیل محتوا، تکنیکال، آف‌پیج، ایده‌ها و جمع‌بندی اکشن پلن.</small>
			</label>
			<label>مدل سبک (کارهای حجیم و ساده)<input type="text" name="ai_model_light" dir="ltr" list="sap-ai-models" value="<?php echo esc_attr( $s['ai_model_light'] ); ?>" placeholder="خالی = همان مدل تحلیل">
				<small>نقشه کلمات، کنیبالیزیشن، لینک داخلی و بریف‌ها؛ بیشترین توکن را مصرف می‌کنند.</small>
			</label>
			<label>مدل «بنویس برام»<input type="text" name="ai_model_assist" dir="ltr" list="sap-ai-models" value="<?php echo esc_attr( $s['ai_model_assist'] ); ?>">
				<small>خروجی آماده هر تسک (تایتل، متا، FAQ و…).</small>
			</label>
			<label>سقف بودجه ماهانه (دلار)<input type="number" name="ai_budget" min="0" step="0.5" value="<?php echo esc_attr( $s['ai_budget'] ); ?>">
				<small>وقتی پر شود، هوش مصنوعی تا ماه بعد متوقف می‌شود. ۰ = بدون سقف.</small>
			</label>
		</div>
		<details class="sap-ai-prices">
			<summary>مقایسه قیمت مدل‌ها (تقریبی)</summary>
			<div class="sap-table-wrap">
				<table class="sap-table is-compact">
					<thead><tr><th>مدل</th><th>ورودی (دلار / ۱ میلیون توکن)</th><th>خروجی</th><th>یک تحلیل کامل اگر مدل تحلیل باشد</th></tr></thead>
					<tbody>
						<?php foreach ( sap_ai_model_prices() as $mk => $mp ) : ?>
							<tr><td dir="ltr"><code><?php echo esc_html( $mk ); ?></code></td><td><?php echo esc_html( sap_num( $mp[0], 2 ) ); ?></td><td><?php echo esc_html( sap_num( $mp[1], 2 ) ); ?></td><td>~<?php echo esc_html( sap_num( sap_ai_estimate_run( $mk, sap_ai_model_for( 'light' ) ), 2 ) ); ?> دلار</td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="sap-small sap-muted">قیمت‌ها تقریبی و بر اساس قیمت رسمی مدل‌هاست؛ قیمت دقیق را پنل GapGPT نشان می‌دهد. مدل‌های خیلی ارزان (nano، flash-lite) برای تحلیل عمیق فارسی ضعیف‌ترند؛ برای «مدل سبک» مناسب‌اند. هزینه واقعی هر تحلیل بعد از اجرا در همین صفحه ثبت می‌شود.</p>
		</details>
		<?php sap_switch( 'ai_auto', $s['ai_auto'], 'تحلیل هوشمند در همگام‌سازی خودکار هفتگی هم اجرا شود', 'اگر داده‌ها تغییر نکرده باشند، تحلیل قبلی بدون هزینه نگه داشته می‌شود.' ); ?>
		<?php if ( 'gateway' === $s['ai_provider'] && sap_ai_connected() ) : ?>
			<div class="sap-ai-test">
				<button type="button" class="sap-btn is-soft" data-sap-ai-test><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>تست کلید و مدل‌ها</button>
				<span class="sap-muted sap-small">کلید، فهرست مدل‌های حساب شما و یک درخواست چندتوکنی با هر دو مدل بالا را امتحان می‌کند (هزینه تقریباً صفر). اگر قبلاً تنظیمات را عوض کرده‌اید، اول ذخیره کنید.</span>
				<div data-sap-ai-test-out hidden></div>
			</div>
		<?php endif; ?>
	</section>

	<section class="sap-panel">
		<h2>سئو سیگنال</h2>
		<div class="sap-status-row">
			<span class="sap-conn <?php echo sap_ss_connected() ? 'is-on' : ''; ?>"><i></i><?php echo sap_ss_connected() ? 'کلید ذخیره شده' : 'کلید وارد نشده'; ?></span>
			<span class="sap-muted">مصرف امروز این افزونه: <?php echo esc_html( sap_num( $usage['used'] ) . ' از ' . sap_num( $usage['cap'] ) ); ?><?php echo $usage['last_error'] ? ' · آخرین خطا: ' . esc_html( $usage['last_error'] ) : ''; ?></span>
		</div>
		<div class="sap-grid-3">
			<label>کلید API<input type="text" name="seosignal_key" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['seosignal_key'] ) ); ?>" autocomplete="off">
				<small>پنل سئو سیگنال › منوی کاربری › مدیریت API › ساخت کلید. کلید را کامل و دقیقاً همان‌طور که پنل نشان می‌دهد کپی کنید. بعد از ذخیره، در تب «سئو سیگنال» هر پروژه دکمه «تست اتصال» علت هر خطا را نشان می‌دهد.</small>
			</label>
			<label>سقف درخواست روزانه برای این افزونه<input type="number" name="seosignal_daily_cap" min="1" value="<?php echo esc_attr( $s['seosignal_daily_cap'] ); ?>">
				<small>سقف پیش‌فرض سئو سیگنال ۵۰ درخواست در روز (مشترک بین همه ابزارها) است؛ ردیاب رتبه شامل سقف نیست.</small>
			</label>
			<label>تازه بودن کش (روز)<input type="number" name="seosignal_cache_days" min="1" max="90" value="<?php echo esc_attr( $s['seosignal_cache_days'] ); ?>">
				<small>حجم جستجو، گپ رقبا و سوالات تا این مدت دوباره درخواست نمی‌شوند.</small>
			</label>
		</div>
		<?php sap_switch( 'ss_auto', $s['ss_auto'], 'همگام‌سازی خودکار (زمان‌بندی‌شده) هم از سهمیه سئو سیگنال استفاده کند', 'خاموش باشد، مراحل پولی سئو سیگنال فقط با «تحلیل کامل» و تأیید شما اجرا می‌شوند؛ ردیاب رتبه (رایگان) همیشه اجرا می‌شود.' ); ?>
	</section>

	<section class="sap-panel" id="notify">
		<h2><?php echo sap_icon( 'bell' ); // phpcs:ignore ?>اعلان‌ها (هشدارها، کارهای تیم، یادآوری‌ها)</h2>
		<p class="sap-muted sap-small">هشدارهای بحرانی، سپردن کار به اعضای تیم و یادآوری روزانه از این کانال‌ها ارسال می‌شوند. بعد از ذخیره، دکمه «ارسال آزمایشی» هر کانال را امتحان کنید.</p>
		<div class="sap-grid-3">
			<label>ایمیل مدیر پروژه‌ها<input type="email" name="notify_manager_email" dir="ltr" value="<?php echo esc_attr( $s['notify_manager_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><small>خالی = ایمیل مدیر وردپرس</small></label>
			<label>موبایل مدیر (برای پیامک هشدار)<input type="text" name="notify_manager_phone" dir="ltr" value="<?php echo esc_attr( $s['notify_manager_phone'] ); ?>" placeholder="09121234567"></label>
			<div class="sap-check-col"><?php sap_switch( 'notify_email', $s['notify_email'], 'ارسال ایمیل فعال باشد', 'ایمیل‌های دعوت اعضا، سپردن کار، یادآوری و هشدار.' ); ?></div>
		</div>
		<h3 class="sap-h3" id="smtp">ارسال ایمیل (SMTP)</h3>
		<p class="sap-muted sap-small">روی لوکال‌هاست و بیشتر هاست‌ها ایمیل بدون سرور SMTP ارسال نمی‌شود یا به اسپم می‌رود. اطلاعات یک صندوق ایمیل را وارد کنید (ایمیل هاست/دامنه خودتان، Gmail با «رمز برنامه»، Zoho و…). این تنظیم برای همه ایمیل‌های وردپرس (از جمله بازیابی رمز) استفاده می‌شود؛ اگر افزونه SMTP دیگری فعال دارید، این بخش را خالی بگذارید.<?php echo '' === $s['smtp_host'] && sap_other_smtp_plugin() ? ' <b>یک افزونه SMTP دیگر روی این سایت فعال است.</b>' : ''; ?></p>
		<div class="sap-grid-3">
			<label>سرور SMTP<input type="text" name="smtp_host" dir="ltr" value="<?php echo esc_attr( $s['smtp_host'] ); ?>" placeholder="mail.yourdomain.com / smtp.gmail.com"></label>
			<label>پورت و رمزنگاری<span class="sap-inline"><input type="number" name="smtp_port" dir="ltr" value="<?php echo esc_attr( (int) $s['smtp_port'] ); ?>" style="width:90px"><select name="smtp_secure"><option value="tls" <?php selected( 'tls', $s['smtp_secure'] ); ?>>TLS (۵۸۷)</option><option value="ssl" <?php selected( 'ssl', $s['smtp_secure'] ); ?>>SSL (۴۶۵)</option><option value="none" <?php selected( 'none', $s['smtp_secure'] ); ?>>بدون رمزنگاری</option></select></span></label>
			<label>نام کاربری (آدرس ایمیل)<input type="text" name="smtp_user" dir="ltr" value="<?php echo esc_attr( $s['smtp_user'] ); ?>" autocomplete="off" placeholder="seo@yourdomain.com"></label>
			<label>رمز عبور<input type="password" name="smtp_pass" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['smtp_pass'] ) ); ?>" autocomplete="new-password"><small>Gmail: حساب گوگل › امنیت › App passwords (رمز ۱۶ حرفی).</small></label>
			<label>ایمیل فرستنده<input type="email" name="smtp_from" dir="ltr" value="<?php echo esc_attr( $s['smtp_from'] ); ?>" placeholder="خالی = همان نام کاربری"></label>
			<label>نام فرستنده<input type="text" name="smtp_from_name" value="<?php echo esc_attr( $s['smtp_from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></label>
		</div>
		<h3 class="sap-h3">پیامک</h3>
		<div class="sap-grid-3">
			<label>سرویس پیامک<select name="sms_provider"><?php foreach ( sap_sms_providers() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $s['sms_provider'] ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
			<label>کلید API سرویس پیامک<input type="text" name="sms_key" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['sms_key'] ) ); ?>" autocomplete="off"></label>
			<label>شماره فرستنده (خط)<input type="text" name="sms_sender" dir="ltr" value="<?php echo esc_attr( $s['sms_sender'] ); ?>" placeholder="10008663"></label>
		</div>
		<div class="sap-grid-2">
			<label>نام کاربری پنل (فقط ملی پیامک، روش نام کاربری)<input type="text" name="sms_user" dir="ltr" value="<?php echo esc_attr( $s['sms_user'] ); ?>" autocomplete="off" placeholder="خالی = روش کلید API کنسول">
				<small>ملی پیامک دو روش دارد: ۱) کلید API از console.melipayamak.com › وب‌سرویس: فقط «کلید API» و «شماره فرستنده» را پر کنید و این فیلد را خالی بگذارید. ۲) وب‌سرویس قدیمی: نام کاربری پنل اینجا و رمز (یا کلید) در «کلید API». اگر پنل IP مجاز خواست، IP سرور سایت را در پنل ثبت کنید.</small>
			</label>
			<label>متن پایانی پیامک (اختیاری)<input type="text" name="sms_footer" value="<?php echo esc_attr( $s['sms_footer'] ); ?>" placeholder="لغو11"><small>اگر خط شما خدماتی نیست و پنل درج «لغو11» را الزام می‌کند، اینجا بنویسید تا به انتهای همه پیامک‌ها اضافه شود.</small></label>
		</div>
		<label>آدرس درگاه سفارشی (فقط برای «درگاه سفارشی»)<input type="url" name="sms_custom_url" dir="ltr" value="<?php echo esc_attr( $s['sms_custom_url'] ); ?>" placeholder="https://panel.example/send?to={phone}&amp;text={text}&amp;from={sender}&amp;key={key}"></label>
		<?php $pats = (array) $s['sms_patterns']; ?>
		<details class="sap-sms-patterns" id="sms-patterns" <?php echo 'melipayamak' === $s['sms_provider'] && ( ! $pats || sap_sms_line_is_public() ) ? 'open' : ''; ?>>
			<summary><b>ملی پیامک: ارسال با پترن (خط خدماتی)</b> <?php echo $pats ? '<span class="sap-chip is-good">' . esc_html( sap_num( count( $pats ) ) ) . ' پترن فعال</span>' : '<span class="sap-chip is-warn">پترنی ثبت نشده</span>'; ?></summary>
			<?php if ( sap_sms_line_is_public() && ! $pats ) : ?>
				<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?><span>خط <b dir="ltr"><?php echo esc_html( $s['sms_sender'] ); ?></b> یک خط عمومی/تبلیغاتی است. پیامک متنی از این خط‌ها به شماره‌هایی که دریافت پیامک تبلیغاتی را بسته‌اند (بیشتر شماره‌ها) نمی‌رسد؛ برای همین در پنل «ارسال نشده» ثبت می‌شود، در حالی که وب‌سرویس خطایی نمی‌دهد. راه درست: ارسال با پترن که از خط خدماتی و بدون «لغو۱۱» می‌رود.</span></p>
			<?php endif; ?>
			<ol class="sap-small">
				<li>در پنل ملی پیامک: «وب‌سرویس خدماتی» (یا «ارسال با الگو/پترن») › «متن جدید». برای هر رویداد زیر، متن پیشنهادی را کپی کنید و ثبت کنید (متغیرها همان <code dir="ltr">{0}</code>، <code dir="ltr">{1}</code>… هستند؛ ترتیب را عوض نکنید).</li>
				<li>بعد از تایید متن توسط ملی پیامک (معمولاً چند ساعت کاری)، «کد متن» (bodyId) را جلوی همان رویداد بنویسید و ذخیره کنید. رویدادی که کد ندارد مثل قبل به‌صورت متن عادی ارسال می‌شود.</li>
				<li><b>قانون تایید ملی پیامک:</b> هر متغیر باید با فاصله از بقیه متن جدا باشد؛ <code dir="ltr">{0}!</code>، <code dir="ltr">«{1}»</code>، <code dir="ltr">({2}</code> یا دو متغیر پشت سر هم <code dir="ltr">{0} {1}</code> رد می‌شوند («نام متغیرها را به درستی مشخص نمایید»). متنی که فقط یک متغیر است هم رد می‌شود. «لغو11» را در پترن خدماتی ننویسید.</li>
				<li>لینک داخل متغیر مجاز نیست؛ آدرس پنل در متن خود پترن نوشته شده است. «ارسال آزمایشی پیامک» از پترن «پیام آزمایشی» استفاده می‌کند.</li>
			</ol>
			<div class="sap-pattern-list">
				<?php foreach ( sap_sms_events() as $ev => $def ) : ?>
					<div class="sap-pattern">
						<div class="sap-pattern-head"><b><?php echo esc_html( $def[0] ); ?></b><small><?php echo esc_html( implode( ' · ', array_map( function ( $v, $i ) { return '{' . $i . '} ' . $v; }, $def[1], array_keys( $def[1] ) ) ) ); ?></small></div>
						<pre class="sap-pattern-text" dir="rtl"><?php echo esc_html( $def[2] ); ?></pre>
						<div class="sap-pattern-foot">
							<button type="button" class="sap-btn is-ghost is-small" data-sap-copy="<?php echo esc_attr( $def[2] ); ?>">کپی متن</button>
							<label>کد متن (bodyId)<input type="text" inputmode="numeric" name="sms_patterns[<?php echo esc_attr( $ev ); ?>]" dir="ltr" value="<?php echo esc_attr( $pats[ $ev ] ?? '' ); ?>" placeholder="مثلاً 123456"></label>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</details>
		<h3 class="sap-h3">ربات تلگرام و بله</h3>
		<div class="sap-grid-2">
			<label>توکن ربات تلگرام<input type="text" name="tg_token" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['tg_token'] ) ); ?>" autocomplete="off" placeholder="123456:ABC…"><small>از ‎@BotFather بسازید؛ تلگرام از مسیر پروکسی ارسال می‌شود.</small></label>
			<label>شناسه چت/کانال تلگرام<input type="text" name="tg_chat" dir="ltr" value="<?php echo esc_attr( $s['tg_chat'] ); ?>" placeholder="-1001234567890 یا ‎@channel"></label>
			<label>توکن ربات بله<input type="text" name="bale_token" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['bale_token'] ) ); ?>" autocomplete="off"><small>در بله از ‎@botfather ربات بسازید؛ مستقیم و بدون پروکسی ارسال می‌شود.</small></label>
			<label>شناسه چت/کانال بله<input type="text" name="bale_chat" dir="ltr" value="<?php echo esc_attr( $s['bale_chat'] ); ?>"></label>
		</div>
		<h3 class="sap-h3">هشدارها از چه کانالی؟</h3>
		<div class="sap-sev-grid">
			<?php foreach ( array( 'critical' => 'بحرانی', 'warning' => 'هشدار', 'info' => 'اطلاع' ) as $sv => $sl ) : ?>
				<?php $on = sap_list( (string) $s[ 'alert_' . $sv ] ); ?>
				<div class="sap-sev is-<?php echo esc_attr( $sv ); ?>"><b><?php echo esc_html( $sl ); ?></b>
					<?php foreach ( sap_notify_channels() as $ck => $cl ) : ?>
						<label class="sap-check"><input type="checkbox" name="alert_<?php echo esc_attr( $sv ); ?>[]" value="<?php echo esc_attr( $ck ); ?>" <?php checked( in_array( $ck, $on, true ) ); ?>> <?php echo esc_html( $cl ); ?></label>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<h3 class="sap-h3">یادآوری کارهای تیم</h3>
		<div class="sap-grid-3">
			<label>ساعت‌های یادآوری (به وقت سایت)<input type="text" name="reminder_hours" dir="ltr" value="<?php echo esc_attr( $s['reminder_hours'] ); ?>" placeholder="8,14"><small>مثلاً 8,14 یعنی ۸ صبح و ۲ بعدازظهر</small></label>
			<div class="sap-check-col"><?php sap_switch( 'reminder_sms', $s['reminder_sms'], 'یادآوری پیامکی به اعضا' ); ?></div>
			<div class="sap-check-col"><?php sap_switch( 'reminder_email', $s['reminder_email'], 'خلاصه صبحگاهی کارها با ایمیل' ); ?></div>
		</div>
		<label class="sap-batch-field">اطلاع‌رسانی واگذاری کار، چند دقیقه بعد از آخرین واگذاری<input type="number" name="notify_batch_minutes" min="0" max="30" dir="ltr" value="<?php echo esc_attr( (int) $s['notify_batch_minutes'] ); ?>" style="width:90px"><small>اگر پشت سر هم چند کار به یک نفر بسپارید، بعد از این چند دقیقه سکوت فقط یک ایمیل فهرست‌دار و یک پیامک خلاصه برایش می‌رود (حداکثر ۳۰ دقیقه بعد از اولین کار). ۰ = هر کار فوری و جدا. در صفحه «تیم» دکمه «ارسال همین حالا» هم هست.</small></label>
		<div class="sap-actions sap-notify-tests">
			<?php foreach ( array( 'email' => 'ایمیل', 'sms' => 'پیامک', 'tg' => 'تلگرام', 'bale' => 'بله' ) as $ck => $cl ) : ?>
				<button type="button" class="sap-btn is-small is-soft" data-sap-notify-test="<?php echo esc_attr( $ck ); ?>">ارسال آزمایشی <?php echo esc_html( $cl ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php $nlog = array_reverse( array_slice( (array) get_option( 'sap_notify_log', array() ), -12 ) ); ?>
		<?php if ( $nlog ) : ?>
			<?php $dlv_wait = count( array_filter( $nlog, function ( $l ) { return 'wait' === ( $l['dlv'] ?? '' ); } ) ); ?>
			<details class="sap-small" id="notify-log" <?php echo $dlv_wait || isset( $_GET['sap_msg'] ) ? 'open' : ''; // phpcs:ignore ?>><summary>آخرین ارسال‌ها</summary>
				<ul class="sap-notify-log"><?php foreach ( $nlog as $l ) : ?>
					<?php
					$dlv = $l['dlv'] ?? '';
					$cls = ! $l['ok'] || 'fail' === $dlv ? 'is-err' : ( 'wait' === $dlv ? 'is-wait' : 'is-ok' );
					?>
					<li class="<?php echo esc_attr( $cls ); ?>">
						<div><b><?php echo esc_html( sap_notify_channels()[ $l['ch'] ] ?? $l['ch'] ); ?></b><?php echo 'pattern' === ( $l['mode'] ?? '' ) ? ' <span class="sap-chip is-soft">پترن</span>' : ''; ?> <span dir="ltr"><?php echo esc_html( $l['to'] ); ?></span> — <?php echo esc_html( $l['text'] ); ?></div>
						<small><?php echo esc_html( sap_jdate( $l['at'], 'short' ) . ' ' . substr( $l['at'], 11, 5 ) ); ?><?php echo $l['ok'] ? '' : ' · ' . esc_html( $l['err'] ); ?></small>
						<?php if ( $dlv ) : ?>
							<span class="sap-dlv is-<?php echo esc_attr( $dlv ); ?>"><?php echo esc_html( array( 'ok' => '✓ تحویل شد', 'wait' => '⏳ در انتظار گزارش تحویل', 'fail' => '✗ نرسید', 'unknown' => '؟ نامشخص' )[ $dlv ] ?? $dlv ); ?><?php echo ! empty( $l['dlv_t'] ) && 'ok' !== $dlv ? ': ' . esc_html( $l['dlv_t'] ) : ''; ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?></ul>
				<?php if ( $dlv_wait ) : ?><button type="submit" class="sap-btn is-ghost is-small" form="sap-dlv-form">بررسی وضعیت تحویل پیامک‌ها</button><?php endif; ?>
				<p class="sap-muted">«ثبت شد» یعنی ملی پیامک پیام را پذیرفت؛ رسیدن به گوشی را گزارش تحویل می‌گوید که سکان چند دقیقه بعد خودکار می‌گیرد.</p>
			</details>
		<?php endif; ?>
	</section>

	<section class="sap-panel" id="serp">
		<h2><?php echo sap_icon( 'research' ); // phpcs:ignore ?>داده نتایج گوگل (تحلیل ۱۰ نتیجه اول و رادار رقبا)</h2>
		<p class="sap-muted sap-small">برای تحلیل نتایج گوگل قبل از هر بریف و رادار رقبا، ۱۰ نتیجه اول هر کلمه از یک سرویس SERP گرفته می‌شود (Serper.dev ارزان و سریع؛ SerpApi کامل‌تر). بدون کلید، تحلیل ۱۰ نتیجه اول غیرفعال است و رادار رقبا فقط با رتبه روزانه سئو سیگنال و نقشه سایت رقبا کار می‌کند.</p>
		<div class="sap-grid-3">
			<label>سرویس<select name="serp_provider"><option value="serper" <?php selected( 'serper', $s['serp_provider'] ); ?>>Serper.dev</option><option value="serpapi" <?php selected( 'serpapi', $s['serp_provider'] ); ?>>SerpApi</option></select></label>
			<label>کلید API<input type="text" name="serp_key" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['serp_key'] ) ); ?>" autocomplete="off"></label>
			<label>کشور / زبان<span class="sap-inline"><input type="text" name="serp_gl" dir="ltr" value="<?php echo esc_attr( $s['serp_gl'] ); ?>" style="width:70px"><input type="text" name="serp_hl" dir="ltr" value="<?php echo esc_attr( $s['serp_hl'] ); ?>" style="width:70px"></span></label>
		</div>
	</section>

	<section class="sap-panel" id="geo">
		<h2><?php echo sap_icon( 'globe' ); // phpcs:ignore ?>دیده شدن در هوش مصنوعی (GEO) و هزینه‌ها</h2>
		<p class="sap-muted sap-small">برای پیگیری حضور برند در پاسخ‌های هوش مصنوعی، سوال‌های مشتری‌ها هر هفته از این مدل پرسیده می‌شود. مدلی که به وب دسترسی دارد (مثلاً مدل‌های search در GapGPT) تصویر واقعی‌تری از ChatGPT/Perplexity می‌دهد؛ خالی = مدل اصلی تحلیل.</p>
		<div class="sap-grid-2">
			<label>مدل سنجش GEO<input type="text" name="geo_model" dir="ltr" value="<?php echo esc_attr( $s['geo_model'] ); ?>" placeholder="gpt-4o-search-preview"></label>
			<label>هزینه پیش‌فرض هر ساعت کار (تومان)<input type="number" name="hourly_cost" min="0" step="1000" value="<?php echo esc_attr( (int) $s['hourly_cost'] ?: '' ); ?>"><small>برای ROI کارها؛ در تنظیمات هر پروژه قابل تغییر است.</small></label>
		</div>
	</section>

	<section class="sap-panel" id="network">
		<h2>شبکه و پروکسی</h2>
		<?php if ( sap_proxy_defined() ) : ?>
			<p class="sap-muted">پروکسی وردپرس شناسایی شد: <code dir="ltr"><?php echo esc_html( sap_proxy_label() ); ?></code> (در wp-config.php). در حالت «هوشمند» فقط گوگل (سرچ کنسول، شیت، PageSpeed) و APIهای خارجی هوش مصنوعی از پروکسی رد می‌شوند؛ سایت کارفرما، GapGPT و سئو سیگنال مستقیم وصل می‌شوند. اگر پروکسی خاموش باشد، درخواست گوگل یک بار مستقیم هم امتحان می‌شود.</p>
		<?php else : ?>
			<p class="sap-muted">پروکسی‌ای در wp-config.php تعریف نشده؛ همه اتصال‌ها مستقیم‌اند.</p>
		<?php endif; ?>
		<div class="sap-grid-2">
			<div><p class="sap-label">مسیر اتصال</p><?php sap_segment( 'proxy_mode', array( 'smart' => 'هوشمند (پیشنهادی)', 'all' => 'همه از پروکسی', 'none' => 'همه مستقیم' ), $s['proxy_mode'] ); ?></div>
			<label>دامنه‌های دیگری که باید از پروکسی بروند (هر خط یکی)<textarea name="proxy_force_hosts" rows="2" dir="ltr" placeholder="example.com"><?php echo esc_textarea( $s['proxy_force_hosts'] ); ?></textarea></label>
		</div>
		<div class="sap-net-tests">
			<?php foreach ( array( 'google' => array( 'گوگل', 'https://sheets.googleapis.com/$discovery/rest?version=v4' ), 'gapgpt' => array( 'هوش مصنوعی', untrailingslashit( $s['ai_base_url'] ?: 'https://api.gapgpt.app/v1' ) . '/models' ), 'seosignal' => array( 'سئو سیگنال', 'https://panel.seosignal.net/' ) ) as $k => $t ) : ?>
				<button type="button" class="sap-btn is-small" data-sap-net-test="<?php echo esc_attr( $t[1] ); ?>">تست اتصال <?php echo esc_html( $t[0] ); ?></button>
			<?php endforeach; ?>
			<span class="sap-muted" data-sap-net-result></span>
		</div>
	</section>

	<section class="sap-panel">
		<h2>قوانین تحلیل</h2>
		<div class="sap-grid-3">
			<label>حداقل نمایش ۲۸ روزه (Striking Distance و کنیبالیزیشن)<input type="number" name="min_impressions" min="1" value="<?php echo esc_attr( $s['min_impressions'] ); ?>"></label>
			<label>حداقل نمایش برای CTR پایین<input type="number" name="ctr_min_impressions" min="1" value="<?php echo esc_attr( $s['ctr_min_impressions'] ); ?>"></label>
			<div><p class="sap-label">همگام‌سازی خودکار</p><?php sap_segment( 'auto_sync', array( 'weekly' => 'هفتگی', 'daily' => 'روزانه', 'off' => 'خاموش' ), $s['auto_sync'] ); ?></div>
			<label>افت محتوا: حداقل کلیک ۲۸ روز قبل<input type="number" name="decay_min_clicks" min="1" value="<?php echo esc_attr( $s['decay_min_clicks'] ); ?>"></label>
			<label>افت محتوا: درصد افت<input type="number" name="decay_drop" min="5" max="95" value="<?php echo esc_attr( $s['decay_drop'] ); ?>"></label>
		</div>
	</section>

	<section class="sap-panel">
		<h2>کارهای دوره‌ای ماهانه</h2>
		<?php sap_switch( 'use_recurring', $s['use_recurring'], 'افزودن کارهای دوره‌ای به اکشن پلن هر ماه', 'خاموش باشد، فقط کارهای مشخص و تحلیل‌محور در برنامه می‌آیند.' ); ?>
		<p class="sap-muted">هر خط: <code>حوزه | عنوان کار | ساعت</code> — حوزه‌ها: <?php echo esc_html( implode( '، ', array_keys( sap_contexts() ) ) ); ?>. این کارها هنگام ساخت اکشن پلن هر ماه خودکار اضافه می‌شوند.</p>
		<textarea name="recurring" rows="7" dir="rtl"><?php echo esc_textarea( $s['recurring'] ); ?></textarea>
	</section>

	<div class="sap-form-foot">
		<button class="sap-btn is-primary" type="submit">ذخیره تنظیمات</button>
	</div>
</form>
<form method="post" action="<?php echo $post; // phpcs:ignore ?>" id="sap-dlv-form" hidden><?php sap_form_fields( 'sap_sms_delivery' ); ?></form>

<section class="sap-panel">
	<h2>اتصال حساب گوگل</h2>
	<?php if ( 'oauth' !== $s['google_auth_mode'] ) : ?>
		<p class="sap-muted">روش Service Account فعال است؛ نیازی به اتصال OAuth نیست.</p>
	<?php elseif ( ! $auth ) : ?>
		<p class="sap-muted">اول Client ID و Client Secret را ذخیره کنید.</p>
	<?php else : ?>
		<div class="sap-actions">
			<a class="sap-btn is-primary" href="<?php echo esc_url( $auth ); ?>"><?php echo sap_google_connected() ? 'اتصال دوباره حساب گوگل' : 'اتصال حساب گوگل'; ?></a>
			<?php if ( sap_google_connected() ) : ?>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
					<?php sap_form_fields( 'sap_google_disconnect' ); ?>
					<button class="sap-btn is-ghost" type="submit">قطع اتصال</button>
				</form>
			<?php endif; ?>
		</div>
		<p class="sap-muted">در صفحه گوگل تیک هر دو دسترسی (Search Console و Google Sheets) را بزنید.</p>
	<?php endif; ?>
</section>

<section class="sap-panel sap-transfer" id="transfer">
	<h2><?php echo sap_icon( 'refresh' ); // phpcs:ignore ?>انتقال پروژه‌ها بین سایت‌ها (مثلاً از لوکال‌هاست به سایت اصلی)</h2>
	<p class="sap-muted sap-small">همه داده‌های پروژه بدون تغییر منتقل می‌شود: حوزه‌های تمرکز، یافته‌ها، اکشن پلن و کارها با توضیحات و گزارش انجام، لاگ اقدامات، نقشه کلمات، بازنویسی‌ها، یادداشت‌ها، ایده‌ها و آپدیت‌ها، آرشیو ماه‌ها، هشدارها، تاریخچه رتبه، حافظه پروژه و داده‌های دریافت‌شده. شناسه‌ها در سایت مقصد بازسازی می‌شوند و اعضا با ایمیل پیدا می‌شوند.</p>
	<div class="sap-transfer-grid">
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-transfer-box">
			<?php sap_form_fields( 'sap_transfer_export' ); ?>
			<h3>۱) خروجی گرفتن (در سایت مبدا)</h3>
			<div class="sap-transfer-projects">
				<?php foreach ( sap_projects() as $tp ) : ?>
					<label class="sap-check"><input type="checkbox" name="projects[]" value="<?php echo esc_attr( $tp['id'] ); ?>" checked> <?php echo esc_html( $tp['name'] ); ?> <small dir="ltr" class="sap-muted"><?php echo esc_html( sap_clean_domain( $tp['site_url'] ) ); ?></small></label>
				<?php endforeach; ?>
			</div>
			<label class="sap-check"><input type="checkbox" name="raw" value="1" checked> داده‌های خام هم بیاید (سرچ کنسول، کرال، فهرست محتوای سایت، رتبه‌ها)</label>
			<label class="sap-check"><input type="checkbox" name="secrets" value="1" checked> کلید اتصال سایت (افزونه رابط) هم منتقل شود</label>
			<label class="sap-check"><input type="checkbox" name="settings" value="1"> تنظیمات کلی سکان هم بیاید (کلیدهای هوش مصنوعی، پیامک، SMTP…)</label>
			<p class="sap-small sap-muted">کلیدها در دیتابیس با «salt» همین سایت رمز شده‌اند و در سایت دیگر باز نمی‌شوند؛ برای همین داخل فایل باز (غیررمز) قرار می‌گیرند و در مقصد دوباره رمز می‌شوند. فایل را جایی عمومی نگذارید و بعد از انتقال پاکش کنید.</p>
			<button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'download', 15 ); // phpcs:ignore ?>دانلود فایل انتقال</button>
		</form>
		<div class="sap-transfer-box" data-sap-transfer>
			<h3>۲) وارد کردن (در سایت مقصد)</h3>
			<label class="sap-file-drop"><input type="file" accept=".gz,.json,.sokan" data-sap-transfer-file><span>فایل <b dir="ltr">.sokan.gz</b> را انتخاب کنید</span></label>
			<div class="sap-transfer-preview" data-sap-transfer-preview hidden></div>
			<label class="sap-check"><input type="checkbox" data-sap-transfer-users checked> اعضایی که در این سایت حساب ندارند ساخته شوند (بدون ارسال دعوت؛ بعداً از «تیم» دعوت بفرستید)</label>
			<label class="sap-check" data-sap-transfer-settings-row hidden><input type="checkbox" data-sap-transfer-settings> تنظیمات کلی داخل فایل روی این سایت اعمال شود (اتصال گوگل باید دوباره انجام شود)</label>
			<div class="sap-serp-progress" data-sap-transfer-progress hidden><i></i><span></span></div>
			<div class="sap-actions"><button type="button" class="sap-btn is-primary" data-sap-transfer-go disabled>وارد کردن پروژه‌ها</button></div>
			<div data-sap-transfer-result></div>
			<p class="sap-small sap-muted">پروژه‌ها به‌عنوان پروژه جدید اضافه می‌شوند و چیزی از پروژه‌های فعلی این سایت تغییر نمی‌کند. بعد از ورود: ۱) در «اتصال گوگل» حساب را وصل کنید ۲) اگر پروژه به افزونه رابط وصل بود، «تست اتصال» را بزنید ۳) اسکریپت عامل اسکریمینگ فراگ را از نو دانلود کنید (شناسه پروژه عوض شده).</p>
		</div>
	</div>
</section>
