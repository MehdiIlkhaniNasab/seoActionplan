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
		<p class="sap-muted">در Google Cloud Console دو API را فعال کنید: <b>Google Search Console API</b> و <b>Google Sheets API</b>. اگر از SeoReport استفاده می‌کنید می‌توانید از همان پروژه Google Cloud و همان OAuth Client استفاده کنید؛ فقط Redirect URI بالا را هم به Client اضافه کنید.</p>
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
		<div class="sap-grid-3">
			<label>مدل تحلیل پروژه<input type="text" name="ai_model" dir="ltr" list="sap-ai-models" value="<?php echo esc_attr( $s['ai_model'] ); ?>">
				<small>پیشنهاد: claude-sonnet-5-5 (حدود ۰٫۱ دلار هر تحلیل)</small>
			</label>
			<label>مدل «بنویس برام»<input type="text" name="ai_model_assist" dir="ltr" list="sap-ai-models" value="<?php echo esc_attr( $s['ai_model_assist'] ); ?>">
				<small>پیشنهاد: claude-haiku-5-5 (حدود ۰٫۰۰۱ دلار هر بار)</small>
			</label>
			<label>سقف بودجه ماهانه (دلار)<input type="number" name="ai_budget" min="0" step="0.5" value="<?php echo esc_attr( $s['ai_budget'] ); ?>">
				<small>وقتی پر شود، هوش مصنوعی تا ماه بعد متوقف می‌شود. ۰ = بدون سقف.</small>
			</label>
		</div>
		<?php sap_switch( 'ai_auto', $s['ai_auto'], 'تحلیل هوشمند در همگام‌سازی خودکار هفتگی هم اجرا شود', 'اگر داده‌ها تغییر نکرده باشند، تحلیل قبلی بدون هزینه نگه داشته می‌شود.' ); ?>
	</section>

	<section class="sap-panel">
		<h2>سئو سیگنال</h2>
		<div class="sap-status-row">
			<span class="sap-conn <?php echo sap_ss_connected() ? 'is-on' : ''; ?>"><i></i><?php echo sap_ss_connected() ? 'کلید ذخیره شده' : 'کلید وارد نشده'; ?></span>
			<span class="sap-muted">مصرف امروز این افزونه: <?php echo esc_html( sap_num( $usage['used'] ) . ' از ' . sap_num( $usage['cap'] ) ); ?><?php echo $usage['last_error'] ? ' · آخرین خطا: ' . esc_html( $usage['last_error'] ) : ''; ?></span>
		</div>
		<div class="sap-grid-3">
			<label>کلید API (ایمیل:توکن)<input type="text" name="seosignal_key" dir="ltr" value="<?php echo esc_attr( sap_mask( $s['seosignal_key'] ) ); ?>" autocomplete="off">
				<small>پنل سئو سیگنال › منوی کاربری › مدیریت API › ساخت کلید.</small>
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
