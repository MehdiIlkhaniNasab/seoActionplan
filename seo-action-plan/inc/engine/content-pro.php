<?php
/**
 * Professional rewrite rules on top of the audit / rewrite engine (content.php):
 *
 * - The page kind decides how the text is written: a product (description,
 *   short description and attributes as separate WooCommerce fields), a product
 *   category (an introduction to the category, not an article) or an article.
 * - Nothing that changes over time goes into the text: no price, discount,
 *   stock or delivery time, no «this year» dates.
 * - The text is final and ready to publish: no notes to the writer, no
 *   placeholders, no half-made tables.
 * - Every rewritten block passes a quality check; failures are sent back to the
 *   model once with the reasons, and anything still failing is held back and
 *   cannot be published.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kind of the page being rewritten: product | category | article | page.
 */
function sap_content_kind( $rev, $facts = array() ) {
	$site = (array) ( $rev['original']['site'] ?? array() );
	if ( 'product' === ( $site['type'] ?? '' ) || ! empty( $site['product'] ) ) {
		return 'product';
	}
	if ( 'term' === ( $site['kind'] ?? '' ) || ! empty( $site['term'] ) ) {
		return 'category';
	}
	$t = (string) ( $facts['page_type'] ?? sap_guess_page_type( $rev['url'] ) );
	if ( in_array( $t, array( 'product', 'category', 'article' ), true ) ) {
		return $t;
	}
	return 'post' === ( $site['type'] ?? '' ) ? 'article' : 'page';
}

/**
 * Labels of the kinds.
 */
function sap_content_kind_labels() {
	return array( 'product' => 'صفحه محصول', 'category' => 'صفحه دسته‌بندی محصولات', 'article' => 'مقاله بلاگ', 'page' => 'صفحه' );
}

/**
 * Rules for every rewrite (both the audit and the rewrite prompts).
 */
function sap_content_global_rules() {
	return array(
		'قواعد قطعی (هرگز نقض نشوند):',
		'- قیمت، مبلغ، تومان/ریال، درصد تخفیف، «موجود/ناموجود»، تعداد موجودی، زمان ارسال و هر اطلاعاتی که با تغییر قیمت یا انبار کهنه می‌شود را در متن ننویس. اگر در متن فعلی هست، حذفش کن. به جای آن از مزیت، کاربرد و مشخصات ثابت بنویس.',
		'- تاریخ‌ها و عبارت‌های زمانی گذرا («امسال»، «سال ۱۴۰۵»، «این روزها»، «جدیدترین مدل امسال») ننویس، مگر جزو نام رسمی محصول باشد.',
		'- متن باید نهایی و آماده انتشار باشد: هیچ دستور یا توضیحی خطاب به نویسنده و ویراستار («اینجا یک جدول قرار دهید»، «تصویر محصول را اضافه کنید»، «[نام برند]»، «…»، «XXX») ننویس. اگر جدول لازم است، خود جدول را با داده واقعی و کامل بساز؛ اگر داده کافی نداری، جدول نساز.',
		'- عدد، آمار، گواهی، افتخار، نظر مشتری یا ادعای ساختگی ننویس. فقط از داده داده‌شده (ویژگی‌ها، متن فعلی، کوئری‌ها) و دانش عمومی مطمئن استفاده کن.',
		'- جمله‌های کلیشه‌ای و پرکننده («در این مقاله قصد داریم…»، «همان‌طور که می‌دانید»، «در دنیای امروز») ننویس؛ مستقیم و مفید بنویس.',
		'- فارسی معیار با نیم‌فاصله درست و علائم فارسی؛ کلمه کلیدی طبیعی و بدون انباشت.',
	);
}

/**
 * Rules for one kind of page.
 */
function sap_content_kind_rules( $kind ) {
	switch ( $kind ) {
		case 'product':
			return array(
				'این صفحه محصول ووکامرس است. «توضیحات کامل» را بازنویسی می‌کنی (توضیح کوتاه و ویژگی‌ها در مرحله جدا ساخته می‌شوند):',
				'- ساختار: یک پاراگراف معرفی (محصول چیست و برای چه کسی است)، مزایا و کاربردها، مشخصات فنی به صورت فهرست یا جدول فقط از ویژگی‌های واقعی محصول، راهنمای استفاده یا نگهداری اگر مرتبط است، و در صورت نیاز ۳ تا ۵ سوال متداول درباره کاربرد، اندازه، جنس و نگهداری (نه قیمت و ارسال).',
				'- ویژگی‌های داده‌شده (wordpress.product.attributes) مرجع مشخصات است؛ مشخصه‌ای که در داده نیست را از خودت نساز.',
				'- لحن فروش حرفه‌ای و قابل اعتماد، نه اغراق‌آمیز.',
			);
		case 'category':
			return array(
				'این صفحه دسته‌بندی محصولات فروشگاه است، نه مقاله. توضیحات دسته باید:',
				'- معرفی کوتاه این دسته و کاربردها؛ انواع/زیرگروه‌های محصولات همین دسته؛ راهنمای کوتاه انتخاب و خرید (معیارهای انتخاب، نه قیمت)؛ و در صورت نیاز ۳ تا ۵ سوال متداول.',
				'- کوتاه‌تر و کاربردی‌تر از مقاله باشد (معمولاً ۳۰۰ تا ۸۰۰ کلمه)، با تیترهای h2/h3 و فهرست.',
				'- به محصولات یا زیردسته‌های همین فروشگاه که در داده آمده اشاره کن؛ نام محصولی که در داده نیست نساز.',
				'- از جمله‌هایی مثل «در این مقاله» استفاده نکن؛ مخاطب در حال خرید است.',
			);
		case 'article':
			return array(
				'این مقاله بلاگ است:',
				'- مقاله کامل، دقیق و آماده انتشار؛ هر بخش جدید باید متن کامل داشته باشد، نه سرتیتر خالی یا خلاصه.',
				'- مقدمه کوتاه که مستقیم به نیاز جستجوگر جواب بدهد؛ تیترهای سلسله‌مراتبی؛ پاراگراف‌های کوتاه؛ فهرست برای مراحل؛ جدول فقط با داده کامل.',
				'- اگر به محصول یا دسته فروشگاه اشاره می‌کنی، بدون قیمت و بدون وعده موجودی.',
			);
	}
	return array( 'این یک صفحه عمومی سایت است: متن روشن، کوتاه و مفید؛ همان ساختار فعلی صفحه را حفظ کن.' );
}

/**
 * Quality problems of a piece of generated HTML.
 *
 * @return string[] Persian reasons (empty = OK)
 */
function sap_content_qc( $html ) {
	$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) ) );
	$norm = sap_norm_text( $text );
	$out  = array();
	// Prices and money.
	if ( preg_match( '/\d[\d,٫.\s]*\s*(تومان|تومن|ریال|هزار\s*تومان|میلیون\s*تومان)|(قیمت|مبلغ|فقط)\s*[:：]?\s*\d|\d+\s*(٪|%)\s*تخفیف|تخفیف\s*\d+\s*(٪|%)/u', $norm ) ) {
		$out[] = 'قیمت، مبلغ یا درصد تخفیف در متن آمده';
	}
	// Stock and delivery promises.
	if ( preg_match( '/(موجود\s*(است|می‌باشد|میباشد|نیست)|ناموجود|اتمام\s*موجودی|موجودی\s*(محدود|انبار)|ارسال\s*(فوری|رایگان|در\s*\d)|تحویل\s*(در\s*)?\d+\s*(روز|ساعت))/u', $text ) ) {
		$out[] = 'وضعیت موجودی یا زمان ارسال در متن آمده';
	}
	// Notes to the writer and placeholders.
	if ( preg_match( '/(اینجا|در\s*این\s*(بخش|قسمت)|زیر\s*این).{0,40}(قرار\s*(دهید|بدهید|داده\s*شود)|اضافه\s*(کنید|شود)|درج\s*(کنید|شود)|بگذارید)|(جدول|تصویر|عکس|ویدیو|ویدئو|لینک|نمودار|اینفوگرافیک).{0,25}(قرار\s*(دهید|بدهید|داده\s*شود)|اضافه\s*(کنید|شود)|درج\s*(کنید|شود)|بگذارید)/u', $text ) ) {
		$out[] = 'دستور یا یادداشت خطاب به نویسنده در متن مانده';
	}
	if ( preg_match( '/\[[^\]]{2,60}\]|\{\{|\}\}|\bTODO\b|\bXXX\b|lorem ipsum|\.{4,}|…\s*…/iu', $text ) ) {
		$out[] = 'جای خالی یا متن نمونه ([…]، XXX، …) در متن مانده';
	}
	// Half-made tables: empty cells.
	if ( preg_match_all( '#<t[dh][^>]*>\s*(?:&nbsp;|-|—|…|\.\.\.)?\s*</t[dh]>#iu', (string) $html, $m ) && count( $m[0] ) >= 2 ) {
		$out[] = 'جدول با خانه‌های خالی';
	}
	// Dated expressions.
	if ( preg_match( '/(سال\s*(۱۴۰|140|202)\d|امسال|در\s*سال\s*جاری)/u', $text ) ) {
		$out[] = 'تاریخ یا عبارت زمانی گذرا (کهنه می‌شود)';
	}
	return $out;
}

/**
 * Send failing edits back to the model once, with the reasons.
 *
 * @param array $edits    edits (with 'qc' reasons) that failed.
 * @return array|WP_Error edit id => fixed html
 */
function sap_content_qc_fix( $edits, $kind ) {
	$schema = sap_ai_obj( array( 'fixed' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
		'id'   => array( 'type' => 'integer' ),
		'html' => array( 'type' => 'string' ),
	) ) ) ) );
	$prompt = implode( "\n", array_merge(
		array( 'این بلوک‌های HTML در کنترل کیفیت رد شده‌اند. هر کدام را با همان معنی و ساختار، بدون آن ایرادها، کامل و آماده انتشار بازنویسی کن. اگر ایراد «جدول خالی» است، یا جدول را با داده واقعی پر کن یا آن را به فهرست تبدیل کن.' ),
		sap_content_global_rules(),
		sap_content_kind_rules( $kind ),
		array( '', wp_json_encode( array_map( function ( $e ) {
			return array( 'id' => $e['id'], 'problems' => $e['qc'], 'html' => $e['html'] );
		}, array_values( $edits ) ), JSON_UNESCAPED_UNICODE ) )
	) );
	$res = sap_ai_request( sap_ai_system_prompt(), $prompt, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 5000, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$out = array();
	foreach ( (array) ( $res['fixed'] ?? array() ) as $f ) {
		if ( is_array( $f ) && ! empty( $f['id'] ) && '' !== trim( (string) ( $f['html'] ?? '' ) ) ) {
			$out[ (int) $f['id'] ] = trim( wp_kses( (string) $f['html'], sap_content_allowed_html() ) );
		}
	}
	return $out;
}

/**
 * Check new edits, repair the failing ones once, hold back what still fails.
 *
 * @param array $edits all edits of the revision.
 * @param int   $from  check edits with id > $from (the ones just added).
 * @return array edits
 */
function sap_content_qc_pass( $edits, $from, $kind ) {
	$bad = array();
	foreach ( $edits as $i => $e ) {
		if ( $e['id'] <= $from || 'delete' === $e['op'] ) {
			continue;
		}
		$q = sap_content_qc( $e['html'] );
		if ( $q ) {
			$edits[ $i ]['qc'] = $q;
			$bad[ $e['id'] ]   = $edits[ $i ];
		}
	}
	if ( ! $bad ) {
		return $edits;
	}
	$fixed = sap_content_qc_fix( $bad, $kind );
	foreach ( $edits as $i => $e ) {
		if ( ! isset( $bad[ $e['id'] ] ) ) {
			continue;
		}
		if ( ! is_wp_error( $fixed ) && isset( $fixed[ $e['id'] ] ) && ! sap_content_qc( $fixed[ $e['id'] ] ) ) {
			$edits[ $i ]['html']  = $fixed[ $e['id'] ];
			$edits[ $i ]['qc']    = array();
			$edits[ $i ]['fixed'] = 1; // Repaired by the quality check.
		} else {
			$edits[ $i ]['accept'] = false; // Held back: cannot be published as is.
		}
	}
	return $edits;
}

/**
 * Product step: short description and attributes, written for their own
 * WooCommerce fields. Attributes come only from the product's existing
 * attributes and its current text — never invented; what is missing is listed
 * as information to get from the client.
 *
 * @return array|WP_Error {short, attributes[], needs[]}
 */
function sap_content_product_step( $rev, $facts ) {
	$p = (array) ( $rev['original']['site']['product'] ?? array() );
	if ( ! $p ) {
		return array( 'short' => '', 'attributes' => array(), 'needs' => array() );
	}
	$text = '';
	foreach ( (array) ( $rev['original']['blocks'] ?? array() ) as $b ) {
		$text .= $b['text'] . "\n";
		if ( mb_strlen( $text ) > 7000 ) {
			break;
		}
	}
	$schema = sap_ai_obj( array(
		'short'      => array( 'type' => 'string', 'description' => 'HTML توضیح کوتاه' ),
		'attributes' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
			'name'   => array( 'type' => 'string' ),
			'values' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'source' => array( 'type' => 'string', 'enum' => array( 'existing', 'text' ) ),
		) ) ),
		'needs'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
	) );
	$prompt = implode( "\n", array_merge(
		array(
			'برای این محصول ووکامرس دو فیلد جدا بساز:',
			'۱) short: «توضیح کوتاه» محصول که کنار تصویر و دکمه خرید دیده می‌شود: یک جمله معرفی و سپس فهرست ۳ تا ۵ مزیت/مشخصه کلیدی (<p> و <ul><li>)، حداکثر حدود ۶۰ کلمه. بدون قیمت، تخفیف، موجودی و زمان ارسال.',
			'۲) attributes: ویژگی‌های محصول برای جدول «توضیحات تکمیلی». فقط از ویژگی‌های فعلی (source=existing: نام و مقدار را مرتب و یکدست کن، مثلاً واحدها) یا مشخصاتی که صریحاً در متن فعلی آمده (source=text). هیچ مقداری را حدس نزن. قیمت و موجودی ویژگی نیست.',
			'۳) needs: مشخصاتی که خریدار این نوع محصول معمولاً می‌پرسد ولی در داده نیست (مثلاً «جنس بدنه»، «ابعاد») تا از کارفرما گرفته شود. حداکثر ۶ مورد.',
		),
		sap_content_global_rules(),
		array( '', wp_json_encode( array(
			'title'             => $rev['original']['h1'] ?? $rev['title'],
			'primary_keyword'   => $facts['primary_keyword'] ?? '',
			'categories'        => $p['categories'] ?? array(),
			'brands'            => $p['brands'] ?? array(),
			'current_short'     => wp_strip_all_tags( (string) ( $p['short'] ?? '' ) ),
			'current_attributes' => array_map( function ( $a ) {
				return array( 'name' => $a['name'], 'values' => (array) $a['values'] );
			}, (array) ( $p['attributes'] ?? array() ) ),
			'description_text'  => $text,
		), JSON_UNESCAPED_UNICODE ) )
	) );
	$res = sap_ai_request( sap_ai_system_prompt(), $prompt, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 2500, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$short = trim( wp_kses( (string) ( $res['short'] ?? '' ), sap_content_allowed_html() ) );
	$qc    = sap_content_qc( $short );
	if ( $qc && '' !== $short ) {
		$fix   = sap_content_qc_fix( array( array( 'id' => 1, 'html' => $short, 'qc' => $qc ) ), 'product' );
		$short = ! is_wp_error( $fix ) && isset( $fix[1] ) ? $fix[1] : $short;
		$qc    = sap_content_qc( $short );
	}
	$old   = array();
	foreach ( (array) ( $p['attributes'] ?? array() ) as $a ) {
		$old[ sap_norm_text( (string) $a['name'] ) ] = array_map( 'strval', (array) $a['values'] );
	}
	$attrs = array();
	foreach ( (array) ( $res['attributes'] ?? array() ) as $a ) {
		$name = trim( sanitize_text_field( (string) ( $a['name'] ?? '' ) ) );
		$vals = array_values( array_filter( array_map( function ( $v ) {
			return trim( sanitize_text_field( (string) $v ) );
		}, (array) ( $a['values'] ?? array() ) ), 'strlen' ) );
		if ( '' === $name || ! $vals || sap_content_qc( implode( ' ', $vals ) ) || preg_match( '/قیمت|موجودی|تخفیف/u', $name ) ) {
			continue;
		}
		$was     = $old[ sap_norm_text( $name ) ] ?? null;
		$attrs[] = array(
			'name'   => $name,
			'values' => array_slice( $vals, 0, 12 ),
			'old'    => $was,
			'state'  => null === $was ? 'new' : ( array_map( 'sap_norm_text', $was ) === array_map( 'sap_norm_text', $vals ) ? 'same' : 'changed' ),
			'source' => 'text' === ( $a['source'] ?? '' ) ? 'text' : 'existing',
			'accept' => null === $was || array_map( 'sap_norm_text', $was ) !== array_map( 'sap_norm_text', $vals ),
		);
	}
	return array(
		'short'        => $short,
		'short_old'    => (string) ( $p['short'] ?? '' ),
		'short_qc'     => $qc,
		'short_accept' => '' !== $short && ! $qc,
		'attributes'   => array_slice( $attrs, 0, 30 ),
		'needs'        => array_slice( array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $res['needs'] ?? array() ) ) ) ) ), 0, 6 ),
	);
}

/**
 * Accepted product fields as connector changes (short description → excerpt,
 * attributes → only new or changed ones; nothing is removed).
 */
function sap_content_product_changes( $rev ) {
	$pp  = (array) ( $rev['audit']['product'] ?? array() );
	$out = array();
	if ( ! empty( $pp['short_accept'] ) && '' !== (string) ( $pp['short'] ?? '' ) && ! sap_content_qc( $pp['short'] ) ) {
		$out['excerpt'] = (string) $pp['short'];
	}
	$attrs = array();
	foreach ( (array) ( $pp['attributes'] ?? array() ) as $a ) {
		if ( ! empty( $a['accept'] ) && 'same' !== $a['state'] ) {
			$attrs[] = array( 'name' => $a['name'], 'values' => $a['values'] );
		}
	}
	if ( $attrs ) {
		$out['attributes'] = $attrs;
	}
	return $out;
}

/**
 * Badge of an edit's quality check: repaired, or held back with the reasons.
 */
function sap_content_qc_badge( $e ) {
	if ( ! empty( $e['qc'] ) ) {
		return '<span class="sap-chip is-bad" title="قابل انتشار نیست">⛔ کنترل کیفیت: ' . esc_html( implode( '، ', (array) $e['qc'] ) ) . '</span>';
	}
	return ! empty( $e['fixed'] ) ? '<span class="sap-chip is-good" title="ایراد خودکار برطرف شد">✓ اصلاح‌شده در کنترل کیفیت</span>' : '';
}
