<?php
/**
 * Team: every member with profile, projects, open work and this month's
 * output; add / edit members; reminder and notification status.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

list( $from, $to ) = sap_period_range( sap_period_current() );
$users    = sap_team_users();
$roles    = sap_member_roles();
$projects = sap_projects();
$post     = esc_url( admin_url( 'admin-post.php' ) );
$sms_ok   = 'none' !== sap_setting( 'sms_provider', 'none' );
?>
<header class="sap-head">
	<div>
		<p class="sap-eyebrow">سُکان</p>
		<h1>تیم و اعضا</h1>
		<p class="sap-muted">اعضای تیم را اضافه کنید و در هر پروژه نقش بدهید. هر کار که به کسی سپرده شود، با ایمیل و پیامک خبردار می‌شود؛ هر روز <?php echo esc_html( implode( ' و ', array_map( function ( $h ) { return 'ساعت ' . sap_num( (int) $h ); }, sap_list( (string) sap_setting( 'reminder_hours', '8,14' ) ) ) ) ); ?> یادآوری کارهای بازش را می‌گیرد و بعد از انجام کار، پیام «خسته نباشید» برایش می‌رود.</p>
	</div>
</header>

<section class="sap-panel sap-team-access">
	<div>
		<h2><?php echo sap_icon( 'users' ); // phpcs:ignore ?>اعضا چطور وارد پنل خودشان می‌شوند؟</h2>
		<ol class="sap-small">
			<li>عضو را پایین همین صفحه اضافه کنید؛ ایمیل دعوت با آدرس پنل، نام کاربری و لینک تعیین رمز برایش می‌رود (اگر موبایل دارد، پیامک دعوت هم).</li>
			<li>اگر ایمیل هنوز تنظیم نشده (مثلاً روی لوکال‌هاست)، یا در فرم یک رمز برایش بگذارید، یا دکمه «لینک تعیین رمز» را بزنید و لینک را خودتان برایش بفرستید.</li>
			<li>عضو از آدرس زیر وارد می‌شود و فقط «کارهای من» را می‌بیند: کارهای همه پروژه‌هایش با برچسب پروژه، وضعیت، هدف و گزارش انجام. ورود به پیشخوان وردپرس هم او را به همین پنل برمی‌گرداند.</li>
			<li>دسترسی کامل فقط با مدیر سئو است. اگر می‌خواهید عضوی همه‌چیز را ببیند و مدیریت کند، در «ویرایش پروفایل» او تیک «دسترسی کامل به سکان» را بزنید.</li>
		</ol>
	</div>
	<div class="sap-team-url">
		<span>آدرس پنل اعضا</span>
		<code dir="ltr" data-sap-copy><?php echo esc_html( sap_url( 'my' ) ); ?></code>
		<small class="sap-muted">کلیک برای کپی</small>
	</div>
</section>
<?php if ( ! sap_setting( 'notify_email', 1 ) || ( '' === sap_setting( 'smtp_host' ) && ! sap_other_smtp_plugin() ) ) : ?>
	<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?>سرور ایمیل (SMTP) تنظیم نشده؛ روی لوکال‌هاست و بسیاری از هاست‌ها ایمیل دعوت و کارها ارسال نمی‌شود. <a href="<?php echo esc_url( sap_url( 'settings' ) . '#smtp' ); ?>">تنظیم ایمیل</a></p>
<?php endif; ?>
<?php if ( ! $sms_ok ) : ?>
	<p class="sap-callout"><?php echo sap_icon( 'alert', 16 ); // phpcs:ignore ?>درگاه پیامک تنظیم نشده؛ یادآوری‌ها فعلاً فقط با ایمیل ارسال می‌شوند. <a href="<?php echo esc_url( sap_url( 'settings' ) . '#notify' ); ?>">تنظیم پیامک</a></p>
<?php endif; ?>
<?php $pending = sap_assign_pending(); ?>
<?php if ( $pending ) : ?>
	<section class="sap-panel sap-assign-queue">
		<div>
			<b><?php echo sap_icon( 'clock', 16 ); // phpcs:ignore ?>اطلاع‌رسانی کارهای تازه در صف</b>
			<p class="sap-small sap-muted">
				<?php
				echo esc_html( implode( '، ', array_map( function ( $uid, $info ) {
					$mm = sap_member( $uid );
					return ( $mm['name'] ?? '#' . $uid ) . ': ' . sap_num( $info[0] ) . ' کار (ارسال ~' . sap_num( max( 1, (int) ceil( ( $info[1] - time() ) / 60 ) ) ) . ' دقیقه دیگر)';
				}, array_keys( $pending ), $pending ) ) );
				?>
				— تا وقتی کار پشت سر هم می‌سپارید منتظر می‌ماند و بعد یک ایمیل و یک پیامک خلاصه می‌فرستد.
			</p>
		</div>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>"><?php sap_form_fields( 'sap_assign_flush' ); ?><button class="sap-btn is-primary is-small" type="submit">ارسال همین حالا</button></form>
	</section>
<?php endif; ?>

<div class="sap-team-grid">
	<?php foreach ( $users as $u ) : ?>
		<?php
		$m     = sap_member( $u->ID );
		$st    = sap_member_stats( $u->ID, $from, $to );
		$mine  = array_filter( $projects, function ( $p ) use ( $u ) {
			return isset( sap_project_members( $p['id'] )[ (int) $u->ID ] );
		} );
		?>
		<article class="sap-member">
			<header>
				<span class="sap-avatar" style="background:<?php echo esc_attr( $m['color'] ); ?>"><?php echo esc_html( mb_substr( $m['name'], 0, 1 ) ); ?></span>
				<div>
					<b><?php echo esc_html( $m['name'] ); ?></b>
					<small><?php echo esc_html( $m['title'] ?: ( $m['manager'] ? 'مدیر' : 'عضو تیم' ) ); ?></small>
				</div>
				<?php if ( user_can( $u->ID, 'manage_options' ) ) : ?>
					<span class="sap-chip is-info sap-access-chip">مدیر سئو</span>
				<?php elseif ( sap_member_full( $u->ID ) ) : ?>
					<span class="sap-chip is-good sap-access-chip">دسترسی کامل</span>
				<?php else : ?>
					<span class="sap-chip is-soft sap-access-chip">فقط کارهای خودش</span>
				<?php endif; ?>
			</header>
			<p class="sap-member-contact"><span dir="ltr"><?php echo esc_html( $m['email'] ); ?></span><?php echo '' !== $m['phone'] ? ' · <span dir="ltr">' . esc_html( $m['phone'] ) . '</span>' : ' · <span class="sap-muted">بدون موبایل</span>'; ?></p>
			<div class="sap-member-stats">
				<div><b><?php echo esc_html( sap_num( $st['open'] ) ); ?></b><span>کار باز</span></div>
				<div><b><?php echo esc_html( sap_num( $st['done'] ) ); ?></b><span>انجام‌شده این ماه</span></div>
				<div><b><?php echo esc_html( sap_num( $st['spent'] ?: $st['hours'], 1 ) ); ?></b><span>ساعت کار</span></div>
			</div>
			<?php if ( $mine ) : ?>
				<p class="sap-member-projects"><?php foreach ( $mine as $p ) : ?><span class="sap-chip" style="--c:<?php echo esc_attr( sap_member_color( $p['id'] + 3 ) ); ?>"><i class="sap-dot" style="background:<?php echo esc_attr( sap_member_color( $p['id'] + 3 ) ); ?>"></i><?php echo esc_html( $p['name'] . ' · ' . ( $roles[ sap_project_members( $p['id'] )[ (int) $u->ID ] ] ?? '' ) ); ?></span><?php endforeach; ?></p>
			<?php endif; ?>
			<?php if ( sap_is_member_only( $u ) ) : ?>
				<div class="sap-member-acts">
					<form method="post" action="<?php echo $post; // phpcs:ignore ?>"><?php sap_form_fields( 'sap_member_invite', array( 'user_id' => $u->ID ) ); ?><button class="sap-btn is-ghost is-small" type="submit"><?php echo sap_icon( 'bell', 13 ); // phpcs:ignore ?>ارسال دوباره دعوت</button></form>
					<button type="button" class="sap-btn is-ghost is-small" data-sap-team-link="<?php echo esc_attr( $u->ID ); ?>"><?php echo sap_icon( 'link', 13 ); // phpcs:ignore ?>لینک تعیین رمز</button>
					<span class="sap-muted sap-small">نام کاربری: <b dir="ltr"><?php echo esc_html( get_userdata( $u->ID )->user_login ); ?></b></span>
				</div>
			<?php endif; ?>
			<details class="sap-member-edit">
				<summary>ویرایش پروفایل و پروژه‌ها</summary>
				<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
					<?php sap_form_fields( 'sap_member_save', array( 'user_id' => $u->ID ) ); ?>
					<div class="sap-grid-2">
						<label>نام<input type="text" name="name" value="<?php echo esc_attr( $m['name'] ); ?>"></label>
						<label>سمت<input type="text" name="title" value="<?php echo esc_attr( $m['title'] ); ?>" placeholder="کارشناس محتوا"></label>
						<label>موبایل<input type="text" name="phone" dir="ltr" value="<?php echo esc_attr( $m['phone'] ); ?>" placeholder="09121234567"></label>
						<div class="sap-check-col"><label class="sap-check"><input type="checkbox" name="notify_sms" value="1" <?php checked( $m['notify_sms'] ); ?>> پیامک</label><label class="sap-check"><input type="checkbox" name="notify_email" value="1" <?php checked( $m['notify_email'] ); ?>> ایمیل</label></div>
						<?php if ( current_user_can( 'manage_options' ) && ! user_can( $u->ID, 'manage_options' ) && (int) $u->ID !== get_current_user_id() ) : ?>
							<div class="sap-access-field">
								<input type="hidden" name="full_access" value="0">
								<label class="sap-check"><input type="checkbox" name="full_access" value="1" <?php checked( sap_member_full( $u->ID ) ); ?>> <b>دسترسی کامل به سکان</b></label>
								<small>مثل مدیر سئو: همه پروژه‌ها، تحلیل‌ها، اکشن پلن، اتوماسیون و تنظیمات. خاموش = فقط «کارهای من» با توضیحات کارهای خودش.</small>
							</div>
						<?php endif; ?>
						<?php if ( sap_is_member_only( $u ) ) : ?><label>رمز عبور جدید (اختیاری)<input type="password" name="password" dir="ltr" minlength="8" autocomplete="new-password" placeholder="خالی = بدون تغییر"></label><?php endif; ?>
					</div>
					<p class="sap-label">نقش در پروژه‌ها</p>
					<div class="sap-member-roles">
						<?php foreach ( $projects as $p ) : ?>
							<?php $r = sap_project_members( $p['id'] )[ (int) $u->ID ] ?? ''; ?>
							<label><?php echo esc_html( $p['name'] ); ?><select name="roles[<?php echo esc_attr( $p['id'] ); ?>]"><option value="">عضو نیست</option><?php foreach ( $roles as $rk => $rl ) : ?><option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $rk, $r ); ?>><?php echo esc_html( $rl ); ?></option><?php endforeach; ?></select></label>
						<?php endforeach; ?>
					</div>
					<div class="sap-form-foot"><button class="sap-btn is-small is-primary" type="submit">ذخیره</button></div>
				</form>
			</details>
		</article>
	<?php endforeach; ?>

	<article class="sap-member is-add">
		<header><span class="sap-avatar"><?php echo sap_icon( 'plus', 18 ); // phpcs:ignore ?></span><div><b>افزودن عضو جدید</b><small>کاربر وردپرس با نقش «عضو تیم سئو» ساخته می‌شود و دعوت (ایمیل و پیامک) می‌گیرد</small></div></header>
		<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="sap-form">
			<?php sap_form_fields( 'sap_member_save', array( 'user_id' => 0 ) ); ?>
			<div class="sap-grid-2">
				<label>نام و نام خانوادگی<input type="text" name="name" required></label>
				<label>ایمیل<input type="email" name="email" dir="ltr" required></label>
				<label>موبایل<input type="text" name="phone" dir="ltr" placeholder="09121234567"></label>
				<label>سمت<input type="text" name="title" placeholder="نویسنده"></label>
				<label>رمز عبور (اختیاری)<input type="password" name="password" dir="ltr" minlength="8" autocomplete="new-password" placeholder="خالی = لینک تعیین رمز با ایمیل"><small>اگر ایمیل تنظیم نیست، رمز بگذارید و خودتان به عضو بدهید.</small></label>
			</div>
			<input type="hidden" name="notify_sms" value="1"><input type="hidden" name="notify_email" value="1">
			<div class="sap-form-foot"><button class="sap-btn is-primary" type="submit"><?php echo sap_icon( 'plus', 15 ); // phpcs:ignore ?>افزودن به تیم</button></div>
		</form>
	</article>
</div>

<section class="sap-panel">
	<div class="sap-panel-head"><h2><?php echo sap_icon( 'clock' ); // phpcs:ignore ?>یادآوری‌ها</h2></div>
	<p class="sap-muted sap-small">یادآوری بعدی: <?php echo esc_html( implode( ' و ', array_map( function ( $h ) { return 'ساعت ' . sap_num( (int) $h ) . ':۰۰'; }, sap_list( (string) sap_setting( 'reminder_hours', '8,14' ) ) ) ) ); ?> (جمعه‌ها ارسال نمی‌شود). آخرین ارسال: <?php echo esc_html( get_option( 'sap_remind_slot' ) ? sap_jdate( substr( (string) get_option( 'sap_remind_slot' ), 0, 10 ), 'short' ) . ' ساعت ' . sap_num( (int) substr( (string) get_option( 'sap_remind_slot' ), 11 ) ) : '—' ); ?></p>
	<button type="button" class="sap-btn is-soft" data-sap-remind-now>ارسال یادآوری همین الان</button>
</section>
