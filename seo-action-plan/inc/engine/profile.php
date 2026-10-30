<?php
/**
 * Personal profile for everyone in the panel: photo, name, mobile, email and
 * password, from the name at the bottom of the side menu. The photo is shown as
 * a round avatar next to people's names (tasks, team, comments).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Photo URL of a person ('' = none).
 */
function sap_avatar_url( $user_id ) {
	return (string) get_user_meta( (int) $user_id, 'sap_avatar', true );
}

/**
 * Round avatar: the photo, or the first letter on the person's colour.
 */
function sap_avatar( $user_id, $size = 24, $class = '' ) {
	$user_id = (int) $user_id;
	$u       = $user_id ? get_userdata( $user_id ) : null;
	$name    = $u ? $u->display_name : '';
	$url     = $user_id ? sap_avatar_url( $user_id ) : '';
	$style   = sprintf( 'width:%1$dpx;height:%1$dpx;font-size:%2$dpx', $size, max( 10, (int) round( $size * 0.45 ) ) );
	if ( '' !== $url ) {
		return sprintf( '<img class="sap-av %s" src="%s" alt="%s" title="%s" style="%s" loading="lazy">', esc_attr( $class ), esc_url( $url ), esc_attr( $name ), esc_attr( $name ), esc_attr( $style ) );
	}
	return sprintf( '<span class="sap-av is-letter %s" title="%s" style="%s;background:%s">%s</span>', esc_attr( $class ), esc_attr( $name ), esc_attr( $style ), esc_attr( sap_member_color( $user_id ) ), esc_html( '' !== $name ? mb_substr( $name, 0, 1 ) : '?' ) );
}

/**
 * Avatar + name chip.
 */
function sap_person( $user_id, $size = 22 ) {
	return '<span class="sap-person">' . sap_avatar( $user_id, $size ) . '<span>' . esc_html( sap_user_name( (int) $user_id ) ) . '</span></span>';
}

/**
 * Save an uploaded photo: square-cropped to 256px, stored in uploads/sokan-avatars.
 *
 * @return string|WP_Error URL
 */
function sap_avatar_store( $user_id, $file ) {
	if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'sap_avatar', 'فایلی آپلود نشد.' );
	}
	if ( (int) $file['size'] > 5 * MB_IN_BYTES ) {
		return new WP_Error( 'sap_avatar', 'حجم تصویر حداکثر ۵ مگابایت است.' );
	}
	$type = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' ) );
	if ( empty( $type['type'] ) || 0 !== strpos( (string) $type['type'], 'image/' ) ) {
		return new WP_Error( 'sap_avatar', 'فقط تصویر JPG، PNG یا WebP.' );
	}
	$ed = wp_get_image_editor( $file['tmp_name'] );
	if ( is_wp_error( $ed ) ) {
		return new WP_Error( 'sap_avatar', 'تصویر قابل خواندن نیست.' );
	}
	$ed->resize( 256, 256, true );
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'sokan-avatars';
	wp_mkdir_p( $dir );
	$name  = 'u' . (int) $user_id . '-' . wp_generate_password( 8, false ) . '.jpg';
	$saved = $ed->save( $dir . '/' . $name, 'image/jpeg' );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	// Remove the previous photo.
	$old = sap_avatar_url( $user_id );
	if ( '' !== $old && false !== strpos( $old, '/sokan-avatars/' ) ) {
		wp_delete_file( $dir . '/' . basename( $old ) );
	}
	$url = trailingslashit( $up['baseurl'] ) . 'sokan-avatars/' . $name;
	update_user_meta( $user_id, 'sap_avatar', $url );
	return $url;
}

/**
 * Save the current person's profile.
 *
 * @return true|WP_Error
 */
function sap_profile_save( $data, $file = null ) {
	$uid = get_current_user_id();
	$u   = get_userdata( $uid );
	if ( ! $u ) {
		return new WP_Error( 'sap_profile', 'وارد نشده‌اید.' );
	}
	$name  = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
	$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
	$phone = (string) ( $data['phone'] ?? '' );
	$pass  = (string) ( $data['password'] ?? '' );
	$cur   = (string) ( $data['current'] ?? '' );
	if ( '' === $name ) {
		return new WP_Error( 'sap_profile', 'نام و نام خانوادگی خالی است.' );
	}
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'sap_profile', 'ایمیل معتبر نیست.' );
	}
	if ( '' !== trim( $phone ) && '' === sap_phone_norm( $phone ) ) {
		return new WP_Error( 'sap_profile', 'شماره موبایل معتبر نیست (مثل ۰۹۱۲۱۲۳۴۵۶۷).' );
	}
	$email_changed = strtolower( $email ) !== strtolower( $u->user_email );
	if ( $email_changed ) {
		$other = get_user_by( 'email', $email );
		if ( $other && (int) $other->ID !== $uid ) {
			return new WP_Error( 'sap_profile', 'این ایمیل برای کاربر دیگری ثبت شده.' );
		}
	}
	// Email and password are the keys of the account: confirm with the current password.
	if ( ( $email_changed || '' !== $pass ) && ! wp_check_password( $cur, $u->user_pass, $uid ) ) {
		return new WP_Error( 'sap_profile', 'برای تغییر ایمیل یا رمز، رمز فعلی را درست وارد کنید.' );
	}
	if ( '' !== $pass && strlen( $pass ) < 8 ) {
		return new WP_Error( 'sap_profile', 'رمز جدید حداقل ۸ کاراکتر باشد.' );
	}
	if ( $file && ! empty( $file['tmp_name'] ) ) {
		$r = sap_avatar_store( $uid, $file );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
	}
	if ( ! empty( $data['remove_avatar'] ) ) {
		update_user_meta( $uid, 'sap_avatar', '' );
	}
	$parts = preg_split( '/\s+/u', $name, 2 );
	$upd   = array( 'ID' => $uid, 'display_name' => $name, 'first_name' => $parts[0], 'last_name' => $parts[1] ?? '', 'user_email' => $email );
	$r     = wp_update_user( $upd );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	update_user_meta( $uid, 'sap_phone', sap_phone_norm( $phone ) );
	if ( '' !== $pass ) {
		wp_set_password( $pass, $uid );
		// Stay logged in after the password change.
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true );
	}
	return true;
}

add_action( 'admin_post_sap_profile_save', function () {
	if ( ! is_user_logged_in() || ! sap_can_work() || ! isset( $_POST['_sap_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sap_nonce'] ) ), 'sap_profile_save' ) ) {
		wp_die( 'دسترسی غیرمجاز', 403 );
	}
	$back = sap_return_url( sap_url() );
	$r    = sap_profile_save( array(
		'name'          => sap_post( 'name' ),
		'email'         => sap_post( 'email' ),
		'phone'         => sap_post( 'phone' ),
		'password'      => (string) ( $_POST['password'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passwords are not sanitised.
		'current'       => (string) ( $_POST['current'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		'remove_avatar' => sap_post( 'remove_avatar' ),
	), $_FILES['avatar'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	sap_back( $back, is_wp_error( $r ) ? $r->get_error_message() : 'پروفایل شما ذخیره شد.', ! is_wp_error( $r ) );
} );

/**
 * WordPress avatars (admin bar, comments) use the Sokan photo too.
 */
add_filter( 'pre_get_avatar_data', function ( $args, $id ) {
	$uid = 0;
	if ( is_numeric( $id ) ) {
		$uid = (int) $id;
	} elseif ( $id instanceof WP_User ) {
		$uid = $id->ID;
	} elseif ( is_string( $id ) && is_email( $id ) ) {
		$u   = get_user_by( 'email', $id );
		$uid = $u ? $u->ID : 0;
	}
	$url = $uid ? sap_avatar_url( $uid ) : '';
	if ( '' !== $url ) {
		$args['url']          = $url;
		$args['found_avatar'] = true;
	}
	return $args;
}, 10, 2 );
