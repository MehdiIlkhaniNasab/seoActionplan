<?php
/**
 * Execution projects («پروژه اجرایی»): projects that are not SEO — product
 * upload, website design, a marketplace shop, checkout optimisation… — and only
 * need task management, follow-up and a clean report. No Search Console, no
 * analysis, a short set of tabs, starter task templates and a client report.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * SEO project (default) or execution project.
 */
function sap_project_is_seo( $p ) {
	return 'ops' !== ( $p['options']['kind'] ?? 'seo' );
}

/**
 * Only SEO projects (for analysis, alerts, ranks and other SEO jobs).
 */
function sap_seo_projects() {
	return array_values( array_filter( sap_projects(), 'sap_project_is_seo' ) );
}

/**
 * Kinds of execution projects (label for the project and its report).
 */
function sap_ops_types() {
	return array(
		'products' => 'بارگذاری و تکمیل محصولات',
		'website'  => 'طراحی / راه‌اندازی سایت',
		'market'   => 'غرفه در مارکت‌پلیس (باسلام، دیجی‌کالا…)',
		'checkout' => 'بهینه‌سازی فرایند خرید',
		'content'  => 'تولید محتوا و شبکه‌های اجتماعی',
		'other'    => 'سایر',
	);
}

/**
 * Starter task templates: title, area, hours, checklist. Created as «اقدام بعدی»
 * so the team can start at once; everything stays editable.
 */
function sap_ops_templates() {
	return array(
		'products' => array(
			'label' => 'بارگذاری محصول',
			'tasks' => array(
				array( 'دریافت لیست محصولات و اطلاعات از کارفرما', 'management', 2, array( 'فایل اکسل محصولات (نام، قیمت، موجودی، کد)', 'عکس‌های محصولات', 'ویژگی‌ها و مشخصات فنی', 'دسته‌بندی‌ها' ) ),
				array( 'ساخت دسته‌بندی‌ها و ویژگی‌های ووکامرس', 'content_update', 2, array( 'ساختار دسته‌بندی تأیید شد', 'ویژگی‌ها (attribute) ساخته شد', 'مقادیر ویژگی‌ها وارد شد' ) ),
				array( 'آماده‌سازی و بهینه‌سازی تصاویر محصولات', 'content_update', 3, array( 'برش و هم‌اندازه‌سازی', 'فشرده‌سازی (WebP)', 'نام‌گذاری فایل‌ها' ) ),
				array( 'بارگذاری محصولات', 'content_new', 8, array() ),
				array( 'کنترل کیفیت و تست نمایش محصولات', 'management', 2, array( 'قیمت و موجودی درست است', 'تصاویر و گالری درست است', 'نمایش در موبایل', 'افزودن به سبد و خرید آزمایشی' ) ),
			),
		),
		'website'  => array(
			'label' => 'طراحی سایت',
			'tasks' => array(
				array( 'جلسه نیازسنجی و تعیین صفحات سایت', 'management', 2, array( 'اهداف و مخاطب', 'لیست صفحات', 'نمونه سایت‌های مورد علاقه', 'محتوا و لوگو از کارفرما' ) ),
				array( 'طراحی وایرفریم و ساختار صفحات', 'cro', 4, array() ),
				array( 'طراحی ظاهر (UI) صفحه اصلی و صفحات کلیدی', 'cro', 8, array() ),
				array( 'پیاده‌سازی قالب و صفحات', 'technical', 16, array() ),
				array( 'تست، بهینه‌سازی سرعت و تحویل', 'technical', 4, array( 'تست موبایل', 'سرعت (PageSpeed)', 'فرم‌ها و درگاه', 'آموزش کارفرما' ) ),
			),
		),
		'market'   => array(
			'label' => 'غرفه مارکت‌پلیس',
			'tasks' => array(
				array( 'ثبت‌نام و احراز هویت غرفه', 'management', 2, array( 'مدارک هویتی', 'اطلاعات بانکی', 'تأیید غرفه' ) ),
				array( 'طراحی لوگو، بنر و معرفی غرفه', 'cro', 3, array() ),
				array( 'بارگذاری محصولات در غرفه', 'content_new', 6, array() ),
				array( 'نوشتن عنوان و توضیحات جذاب محصولات', 'content_new', 4, array() ),
				array( 'تنظیم ارسال، قیمت‌گذاری و تخفیف‌ها', 'management', 2, array() ),
			),
		),
		'checkout' => array(
			'label' => 'بهینه‌سازی فرایند خرید',
			'tasks' => array(
				array( 'بررسی قیف خرید و نقاط ریزش', 'cro', 3, array( 'آمار سبد رهاشده', 'ضبط رفتار کاربر (Clarity/Hotjar)', 'تست خرید کامل' ) ),
				array( 'ساده‌سازی صفحه سبد خرید و تسویه حساب', 'cro', 4, array( 'حذف فیلدهای اضافه', 'نمایش هزینه ارسال از اول', 'دکمه‌های واضح' ) ),
				array( 'افزودن نشانه‌های اعتماد', 'cro', 2, array( 'نماد اعتماد و ضمانت', 'نظرات مشتریان', 'شرایط بازگشت کالا' ) ),
				array( 'تست A/B و اندازه‌گیری نتیجه', 'cro', 3, array() ),
			),
		),
	);
}

/**
 * Create a template's tasks in a project.
 *
 * @return int tasks created
 */
function sap_ops_seed( $project_id, $tpl ) {
	$t = sap_ops_templates()[ $tpl ] ?? null;
	if ( ! $t ) {
		return 0;
	}
	$n = 0;
	foreach ( $t['tasks'] as $row ) {
		sap_task_save( array(
			'project_id' => $project_id,
			'title'      => $row[0],
			'context'    => $row[1],
			'effort'     => $row[2],
			'checklist'  => implode( "\n", $row[3] ),
			'status'     => 'next',
			'source'     => 'template',
		) );
		++$n;
	}
	return $n;
}

/**
 * Tabs of an execution project.
 */
function sap_ops_tabs() {
	return array( 'overview', 'plan', 'activity', 'report', 'settings' );
}

/**
 * Task numbers of a project (optionally one Jalali month).
 *
 * @return array {all, done, open, late, stuck, review, hours, done_hours, spent, tasks}
 */
function sap_project_task_stats( $project_id, $period = '' ) {
	$tasks = array_values( array_filter( sap_tasks( array( 'project_id' => $project_id, 'period' => $period ) ), function ( $t ) {
		return ! in_array( $t['status'], array( 'dropped', 'proposed', 'someday' ), true );
	} ) );
	$s = array( 'all' => count( $tasks ), 'done' => 0, 'open' => 0, 'late' => 0, 'stuck' => 0, 'review' => 0, 'hours' => 0.0, 'done_hours' => 0.0, 'spent' => 0.0, 'tasks' => $tasks );
	foreach ( $tasks as $t ) {
		$b           = sap_task_bucket( $t );
		$s['hours'] += $t['effort'];
		$s['spent'] += $t['spent'];
		if ( 'done' === $b ) {
			++$s['done'];
			$s['done_hours'] += $t['effort'];
		} elseif ( isset( $s[ $b ] ) ) {
			++$s[ $b ];
		}
	}
	return $s;
}

/**
 * Seed a template into an existing (empty) execution project.
 */
add_action( 'admin_post_sap_ops_seed', function () {
	sap_guard( 'sap_ops_seed' );
	$pid = absint( sap_post( 'project_id' ) );
	$n   = sap_ops_seed( $pid, sanitize_key( (string) sap_post( 'tpl' ) ) );
	sap_back( sap_project_url( $pid, 'plan' ), $n ? sap_num( $n ) . ' کار از قالب اضافه شد؛ مسئول و ددلاین هر کار را تعیین کنید.' : 'قالب پیدا نشد.', (bool) $n );
} );
