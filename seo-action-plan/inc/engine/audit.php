<?php
/**
 * Content pillar: audit of the site's content by type, through the lens of the
 * project's focus and goal (more sales / leads).
 *
 * Types: pages, product categories / tags / brands, products, post categories,
 * posts. Every page gets rule checks specific to its type and a keyword-intent
 * check against the keyword map (a generic buying keyword such as «خرید چسب
 * کفش» belongs to a category page, not to the page of one product). On demand
 * an AI audit per type adds a diagnosis, title / meta, the exact actions and an
 * update brief; actions become proposals for approval, and any page can be
 * sent to the «content update» part of the calendar.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content groups (tabs of the content audit): label, icon, content types.
 */
function sap_content_groups() {
	return array(
		'products'    => array( 'محصولات', 'grid', array( 'product' ) ),
		'product_tax' => array( 'دسته، برچسب و برند محصول', 'list', array( 'product_cat', 'product_tag', 'brand' ) ),
		'posts'       => array( 'مقالات', 'log', array( 'post' ) ),
		'post_cat'    => array( 'دسته و برچسب مقالات', 'list', array( 'post_cat', 'post_tag' ) ),
		'pages'       => array( 'برگه‌ها', 'grid', array( 'page' ) ),
	);
}

/**
 * Content type labels.
 */
function sap_content_types() {
	return array(
		'product'     => 'محصول',
		'product_cat' => 'دسته‌بندی محصول',
		'product_tag' => 'برچسب محصول',
		'brand'       => 'برند',
		'post'        => 'مقاله',
		'post_cat'    => 'دسته‌بندی مقاله',
		'post_tag'    => 'برچسب مقاله',
		'page'        => 'برگه',
	);
}

/**
 * Content type of a keyword-map row (page type + URL structure).
 */
function sap_content_type( $m ) {
	if ( ! empty( $m['project_id'] ) && ( $it = sap_site_item( (int) $m['project_id'], (string) $m['url'] ) ) ) {
		return $it['ctype']; // The real WordPress type beats any guess from the URL.
	}
	$path  = strtolower( trim( (string) wp_parse_url( rawurldecode( (string) $m['url'] ), PHP_URL_PATH ), '/' ) );
	$first = explode( '/', $path )[0] ?? '';
	switch ( $m['page_type'] ) {
		case 'product':
			return 'product';
		case 'article':
			return 'post';
		case 'category':
			if ( in_array( $first, array( 'product-tag' ), true ) ) {
				return 'product_tag';
			}
			if ( in_array( $first, array( 'brand', 'brands', 'product-brand', 'pa_brand', 'pwb-brand' ), true ) ) {
				return 'brand';
			}
			if ( in_array( $first, array( 'category', 'tag', 'blog', 'mag' ), true ) ) {
				return 'post_cat';
			}
			return 'product_cat';
	}
	if ( in_array( $first, array( 'brand', 'brands', 'product-brand' ), true ) ) {
		return 'brand';
	}
	return 'page';
}

/* ---------------------------------------------------------------------------
 * Keyword intent → right page type
 * ------------------------------------------------------------------------- */

/**
 * Search intent of a keyword (rules; the AI refines it in the audit).
 */
function sap_kw_intent( $kw ) {
	$t   = ' ' . sap_norm_text( $kw ) . ' ';
	$has = function ( $words ) use ( $t ) {
		foreach ( $words as $w ) {
			if ( false !== mb_strpos( $t, ' ' . sap_norm_text( $w ) . ' ' ) ) {
				return true;
			}
		}
		return false;
	};
	if ( $has( array( 'خرید', 'قیمت', 'فروش', 'سفارش', 'ارزان', 'تخفیف', 'عمده', 'نمایندگی', 'فروشگاه', 'اقساط', 'اقساطی', 'ارسال', 'buy', 'price' ) ) ) {
		return 'transactional';
	}
	if ( $has( array( 'بهترین', 'مقایسه', 'بررسی', 'نقد', 'vs', 'برترین', 'لیست', 'مدل‌های', 'مدلهای', 'انواع', 'معرفی', 'تفاوت', 'فرق' ) ) ) {
		return 'commercial';
	}
	if ( sap_is_info_query( $kw ) ) {
		return 'informational';
	}
	return 'transactional'; // A bare product / category noun is a buying search on a shop.
}

/**
 * Is a keyword about one specific product (model number, Latin code, or every
 * word inside the product's own name)?
 */
function sap_kw_is_specific( $kw, $title = '' ) {
	if ( preg_match( '/[0-9۰-۹]|[a-z]{2,}/iu', (string) $kw ) ) {
		return true;
	}
	$words = array_diff( sap_tokens( $kw ), array_map( 'sap_norm_text', sap_kw_modifiers() ) );
	if ( '' !== $title && count( $words ) >= 3 && ! array_diff( $words, sap_tokens( $title ) ) ) {
		return true;
	}
	return false;
}

/**
 * Which content type should rank for a keyword.
 */
function sap_kw_target_type( $kw, $title = '' ) {
	switch ( sap_kw_intent( $kw ) ) {
		case 'informational':
			return 'post';
		case 'commercial':
			return preg_match( '/بهترین|مقایسه|تفاوت|فرق|vs|بررسی|نقد/u', sap_norm_text( $kw ) ) ? 'post' : 'product_cat';
		case 'navigational':
			return 'page';
	}
	return sap_kw_is_specific( $kw, $title ) ? 'product' : 'product_cat';
}

/**
 * Does a keyword fit a page of this content type? Returns '' when it fits,
 * else the content type it belongs to.
 */
function sap_kw_misfit( $kw, $type, $title = '' ) {
	$target = sap_kw_target_type( $kw, $title );
	$fits   = array(
		'product'     => array( 'product' ),
		'product_cat' => array( 'product_cat', 'product' ),
		'product_tag' => array( 'product_cat', 'product' ),
		'brand'       => array( 'product_cat', 'product' ),
		'post'        => array( 'post' ),
		'post_cat'    => array( 'post' ),
		'post_tag'    => array( 'post' ),
		'page'        => array( 'page', 'product_cat', 'post', 'product' ),
	);
	return in_array( $target, $fits[ $type ] ?? array( $target ), true ) ? '' : $target;
}

/* ---------------------------------------------------------------------------
 * Rule checks per page
 * ------------------------------------------------------------------------- */

/**
 * Pages of the site grouped by content group, with facts and rule checks.
 *
 * With the WordPress inventory (connector 1.3) every published post, page,
 * product and term is listed with its real type, matched with Search Console
 * by URL; keyword-map pages that are not in WordPress (home of a blog,
 * archives…) are added from the map. Without it, the keyword map is the list.
 *
 * @return array group => list of rows [url, title, type, kw, kw_src, secondary, queries, pos, clicks, impr, words, score, checks[], misfits[], wp]
 */
function sap_content_inventory( $project ) {
	static $cache = array();
	$pid = $project['id'];
	if ( isset( $cache[ $pid ] ) ) {
		return $cache[ $pid ];
	}
	$pages = (array) ( sap_dataset_payload( $pid, 'pages' )['pages'] ?? array() );
	$crawl = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'crawl' )['rows'] ?? array() ) as $r ) {
		$crawl[ sap_norm_url( $r['url'] ) ] = $r;
	}
	$gsc = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$gsc[ sap_norm_url( $r['page'] ) ] = $r;
	}
	$has_gsc = (bool) $gsc;
	$best    = array();
	$by_page = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
		$pk                                     = sap_norm_url( $r['page'] );
		$best[ $pk . '|' . sap_kw_key( $r['query'] ) ] = $r;
		$by_page[ $pk ][]                       = $r;
	}
	$map   = sap_keymap( $pid );
	$site  = sap_site_inventory( $pid );
	$meta  = sap_dataset_payload( $pid, 'site_inventory' );
	$front = (int) ( $meta['front'] ?? 0 );
	$seo_p = (string) ( $meta['seo_plugin'] ?? '' );
	$money = array();
	foreach ( $map as $k => $m ) {
		if ( in_array( $m['page_type'], array( 'product', 'category' ), true ) ) {
			$money[ $k ] = true;
		}
	}
	foreach ( $site as $k => $it ) {
		if ( in_array( $it['ctype'], array( 'product', 'product_cat', 'brand' ), true ) ) {
			$money[ $k ] = true;
		}
	}
	// Source list: WordPress first (complete and typed), then mapped pages it does not have.
	// Not pages any more: redirected in WordPress (Rank Math / Redirection / Yoast), answering
	// 3xx / 4xx in the crawl, pruned in the keyword map, or — once the WordPress list is in —
	// addresses Search Console still remembers but WordPress no longer has.
	$gone = function ( $k, $m ) use ( $crawl ) {
		$code = (int) ( $crawl[ $k ]['code'] ?? 200 );
		return ( $code >= 300 && $code < 500 ) || ( $m && in_array( $m['status'], array( 'prune_410', 'prune_301' ), true ) );
	};
	$list   = array();
	$hidden = 0;
	foreach ( $site as $k => $it ) {
		if ( '' !== (string) ( $it['redirect'] ?? '' ) || $gone( $k, $map[ $k ] ?? null ) ) {
			++$hidden;
			continue;
		}
		$list[ $k ] = array( $map[ $k ] ?? null, $it );
	}
	foreach ( $map as $k => $m ) {
		if ( isset( $list[ $k ] ) || isset( $site[ $k ] ) ) {
			continue;
		}
		if ( $gone( $k, $m ) || ( $site && 'home' !== $m['page_type'] ) ) {
			++$hidden;
			continue;
		}
		$list[ $k ] = array( $m, null );
	}
	$GLOBALS['sap_content_hidden'][ $pid ] = $hidden;
	$group_of = array();
	foreach ( sap_content_groups() as $g => $def ) {
		foreach ( $def[2] as $t ) {
			$group_of[ $t ] = $g;
		}
	}
	$groups = array_fill_keys( array_keys( sap_content_groups() ), array() );
	foreach ( $list as $k => $pair ) {
		list( $m, $it ) = $pair;
		$type  = $it ? $it['ctype'] : sap_content_type( $m );
		$group = $group_of[ $type ] ?? '';
		if ( ! $group ) {
			continue;
		}
		$p      = $pages[ $k ] ?? null;
		$c      = $crawl[ $k ] ?? null;
		$km_st  = $m ? $m['status'] : '';
		$kw     = $m && sap_keymap_has_kw( $km_st ) ? (string) $m['primary_kw'] : '';
		$kw_src = '' !== $kw ? 'map' : '';
		$focus  = $it ? trim( (string) ( $it['seo']['focus_keyword'] ?? '' ) ) : '';
		if ( '' === $kw && '' !== $focus && ! in_array( $km_st, array( 'none', 'noindex', 'prune_410', 'prune_301' ), true ) ) {
			$kw     = $focus;
			$kw_src = 'seo';
		}
		$queries = array();
		foreach ( array_slice( sap_sort( (array) ( $by_page[ $k ] ?? array() ), 'impressions' ), 0, 5 ) as $q ) {
			$queries[] = array( $q['query'], round( (float) $q['position'], 1 ), (int) $q['impressions'], (int) $q['clicks'] );
		}
		$home = ( $m && 'home' === $m['page_type'] ) || ( $it && $front && 'post' === $it['kind'] && (int) $it['id'] === $front );
		$row  = array(
			'id'        => $m ? $m['id'] : 0,
			'url'       => $it ? $it['url'] : $m['url'],
			'title'     => $it ? $it['title'] : ( $m['title'] ?: ( $p['title'] ?? '' ) ),
			'type'      => $type,
			'kw'        => $kw,
			'kw_src'    => $kw_src,
			'secondary' => $m && sap_keymap_has_kw( $km_st ) ? $m['secondary'] : array(),
			'queries'   => $queries,
			'focus_id'  => $m ? (int) $m['focus_id'] : 0,
			'pos'       => '' !== $kw && isset( $best[ $k . '|' . sap_kw_key( $kw ) ] ) ? round( (float) $best[ $k . '|' . sap_kw_key( $kw ) ]['position'], 1 ) : null,
			'clicks'    => (int) ( $gsc[ $k ]['clicks'] ?? 0 ),
			'impr'      => (int) ( $gsc[ $k ]['impressions'] ?? 0 ),
			'words'     => $it ? (int) $it['words'] : ( $p ? (int) $p['words'] : ( $c ? (int) ( $c['words'] ?? 0 ) : null ) ),
			'read'      => (bool) ( $p || $it ),
			'home'      => $home,
			'km_status' => $km_st,
			'checks'    => array(),
			'misfits'   => array(),
			'wp'        => $it ?: null, // Shared, not copied: 10,000 products stay light.
		);
		if ( null === $row['pos'] && $queries && '' === $kw ) {
			$row['pos'] = $queries[0][1];
		}
		$fail = function ( $msg, $weight = 10 ) use ( &$row ) {
			$row['checks'][] = array( $msg, $weight );
		};
		$seo_title = $it ? (string) ( $it['seo']['seo_title'] ?? '' ) : '';
		$title     = $p['title'] ?? ( $c['title'] ?? ( '' !== $seo_title && false === strpos( $seo_title, '%' ) ? $seo_title : '' ) );
		$meta_d    = $p['meta'] ?? ( $c['meta'] ?? ( $it ? (string) ( $it['seo']['meta_description'] ?? '' ) : null ) );
		$h1        = $p ? implode( ' ', $p['h1'] ) : ( $c['h1'] ?? ( $it ? $it['title'] : '' ) );
		$is_term   = in_array( $type, array( 'product_cat', 'product_tag', 'brand', 'post_cat', 'post_tag' ), true );
		// Words of the description itself: a category page's HTML also counts the product grid.
		$words     = $it ? (int) $it['words'] : ( $p ? (int) $p['words'] : null );
		if ( '' === $kw && 'page' !== $type && ! $home && ! in_array( $km_st, array( 'none', 'noindex', 'prune_410', 'prune_301' ), true ) ) {
			$fail( 'کلمه کلیدی اصلی ندارد (نه در نقشه کلمات، نه در افزونه سئو)', 15 );
		}
		if ( '' !== $kw && $title && false === mb_strpos( sap_norm_text( $title ), sap_norm_text( sap_kw_core( $kw ) ) ) && ! array_intersect( sap_tokens( sap_kw_core( $kw ) ), sap_tokens( $title ) ) ) {
			$fail( 'کلمه اصلی در تایتل نیست', 12 );
		}
		if ( '' !== $kw && $h1 && ! array_intersect( sap_tokens( sap_kw_core( $kw ) ), sap_tokens( $h1 ) ) ) {
			$fail( 'کلمه اصلی در H1 (عنوان) نیست', 8 );
		}
		$tl = mb_strlen( $title );
		if ( $title && ( $tl < 25 || $tl > 65 ) ) {
			$fail( sprintf( 'طول تایتل %s کاراکتر (مناسب ۳۰ تا ۶۰)', sap_num( $tl ) ), 5 );
		}
		if ( null !== $meta_d && '' === trim( (string) $meta_d ) && ( $p || $c || '' !== $seo_p ) ) {
			$fail( $p || $c ? 'متا دیسکریپشن ندارد' : 'متا دیسکریپشن اختصاصی در افزونه سئو ندارد', $p || $c ? 8 : 6 );
		}
		if ( $p && ! $p['h1'] ) {
			$fail( 'H1 ندارد', 10 );
		}
		if ( $it && 'map' === $kw_src && '' !== $focus && sap_kw_core( $focus ) !== sap_kw_core( $kw ) ) {
			$fail( sprintf( 'کلمه کانونی افزونه سئو («%s») با کلمه اصلی نقشه یکی نیست', $focus ), 4 );
		}
		if ( $it && ! empty( $it['noindex'] ) && ! in_array( $type, array( 'product_tag', 'post_tag' ), true ) ) {
			$fail( 'در افزونه سئو noindex شده' . ( $row['impr'] ? ' ولی در گوگل نمایش دارد' : '' ), 10 );
		}
		if ( $it && $has_gsc && ! $row['impr'] && empty( $it['noindex'] ) && strtotime( (string) ( $it['date'] ?? '' ) ?: 'now' ) < time() - 45 * DAY_IN_SECONDS && ( ! $is_term || (int) ( $it['count'] ?? 0 ) > 0 ) ) {
			$fail( 'در ۲۸ روز اخیر هیچ نمایشی در گوگل نداشته (ایندکس، کلمه هدف و لینک داخلی بررسی شود)', 8 );
		}
		$schema         = $p ? (array) $p['schema'] : array();
		$links_to_money = 0;
		if ( $p ) {
			foreach ( (array) $p['links'] as $l ) {
				if ( isset( $money[ sap_norm_url( $l[0] ) ] ) && sap_norm_url( $l[0] ) !== $k ) {
					++$links_to_money;
				}
			}
		}
		$text = $p ? sap_norm_text( $p['text'] . ' ' . implode( ' ', $p['h2'] ) . ' ' . implode( ' ', (array) ( $p['h3'] ?? array() ) ) ) : '';
		$wc   = $it['product'] ?? null;
		switch ( $type ) {
			case 'product':
				if ( null !== $words && $words < 250 ) {
					$fail( sprintf( 'توضیحات کم (%s کلمه)؛ صفحه محصول فروشنده باید جامع باشد', sap_num( $words ) ), 15 );
				}
				if ( $p && ! in_array( 'Product', $schema, true ) ) {
					$fail( 'اسکیمای Product ندارد', 12 );
				}
				if ( $wc ) {
					if ( (int) $wc['attrs'] < 3 ) {
						$fail( sprintf( 'ویژگی‌های محصول (Attributes) ناقص: %s ویژگی', sap_num( (int) $wc['attrs'] ) ), 12 );
					}
					if ( '' === (string) $wc['price'] ) {
						$fail( 'قیمت ندارد (در گوگل Offer نمایش داده نمی‌شود)', 8 );
					}
					if ( 'outofstock' === $wc['stock'] ) {
						$fail( 'ناموجود است (اگر برنمی‌گردد، ریدایرکت به دسته یا جایگزین بررسی شود)', 3 );
					}
					if ( (int) $wc['gallery'] < 2 ) {
						$fail( 'گالری تصاویر کم (کمتر از ۳ تصویر)', 4 );
					}
					if ( ! (int) $wc['reviews'] ) {
						$fail( 'بدون نظر و امتیاز کاربر', 4 );
					}
					if ( (int) $wc['short'] < 20 ) {
						$fail( 'توضیح کوتاه محصول (بالای دکمه خرید) ندارد یا خیلی کوتاه است', 4 );
					}
				} elseif ( $p && ! preg_match( '/مشخصات|ویژگی|ابعاد|جنس|وزن|سایز|اندازه|رنگ/u', $text ) ) {
					$fail( 'جدول مشخصات/ویژگی‌های محصول دیده نشد', 12 );
				}
				if ( $p && ! $wc && ! array_intersect( array( 'AggregateRating', 'Review' ), $schema ) ) {
					$fail( 'امتیاز و نظرات کاربران در اسکیما نیست', 5 );
				}
				if ( $p && ! preg_match( '/سوالات|پرسش|سوال/u', $text ) ) {
					$fail( 'بخش سوالات متداول ندارد', 5 );
				}
				break;
			case 'product_cat':
			case 'product_tag':
			case 'brand':
				if ( $it && ! (int) ( $it['count'] ?? 0 ) ) {
					$fail( 'خالی است (هیچ محصولی ندارد)؛ پر یا noindex/حذف شود', 12 );
				}
				if ( null !== $words && $words < 300 ) {
					$fail( sprintf( 'متن معرفی و راهنمای خرید %s', $words ? sap_num( $words ) . ' کلمه (کوتاه)' : 'ندارد' ), 15 );
				}
				if ( $p && ! in_array( 'BreadcrumbList', $schema, true ) ) {
					$fail( 'اسکیمای BreadcrumbList ندارد', 5 );
				}
				if ( $p && ! preg_match( '/سوالات|پرسش|سوال/u', $text ) ) {
					$fail( 'سوالات متداول ندارد', 5 );
				}
				if ( 'product_tag' === $type && $row['impr'] < 10 && empty( $it['noindex'] ) ) {
					$fail( 'برچسب کم‌ارزش (بدون نمایش)؛ noindex یا ادغام بررسی شود', 6 );
				}
				break;
			case 'post':
				if ( null !== $words && $words < 700 ) {
					$fail( sprintf( 'مقاله کوتاه (%s کلمه)', sap_num( $words ) ), 12 );
				}
				if ( $p && count( $p['h2'] ) < 3 ) {
					$fail( 'ساختار تیترها ضعیف (کمتر از ۳ H2)', 6 );
				}
				if ( $p && $links_to_money < 2 ) {
					$fail( sprintf( 'فقط %s لینک به صفحات دسته/محصول؛ مقاله باید فروش را تقویت کند', sap_num( $links_to_money ) ), 12 );
				}
				if ( $p && ! array_intersect( array( 'Article', 'BlogPosting', 'NewsArticle' ), $schema ) ) {
					$fail( 'اسکیمای Article ندارد', 6 );
				}
				if ( $it && ! empty( $it['modified'] ) && strtotime( $it['modified'] ) < time() - 365 * DAY_IN_SECONDS && $row['impr'] ) {
					$fail( 'بیش از یک سال به‌روزرسانی نشده', 5 );
				}
				break;
			case 'post_cat':
			case 'post_tag':
				if ( null !== $words && $words < 150 ) {
					$fail( 'متن معرفی ندارد', 6 );
				}
				if ( $it && ! (int) ( $it['count'] ?? 0 ) ) {
					$fail( 'خالی است (هیچ مطلبی ندارد)', 8 );
				}
				break;
			case 'page':
				if ( null !== $words && ! $home && $words < 150 && ( ! $it || 'elementor' !== ( $it['editor'] ?? '' ) ) ) {
					$fail( 'محتوای صفحه خیلی کم است', 6 );
				}
				break;
		}
		$no_alt = $p ? (int) $p['no_alt'] : ( $it ? (int) $it['no_alt'] : 0 );
		if ( $no_alt > 2 ) {
			$fail( sprintf( '%s تصویر بدون Alt', sap_num( $no_alt ) ), 3 );
		}
		if ( $it && 'product' !== $type && ! $is_term && ! (int) $it['images'] && ! $home ) {
			$fail( 'هیچ تصویری ندارد', 4 );
		}
		if ( null !== $row['pos'] && $row['pos'] > 10 && $row['pos'] <= 20 ) {
			$fail( sprintf( 'کلمه اصلی در جایگاه %s (نزدیک صفحه اول)', sap_num( $row['pos'], 1 ) ), 0 );
		}
		// Keyword intent vs page type.
		foreach ( array_merge( array( $kw ), (array) $row['secondary'] ) as $i => $s ) {
			if ( '' === trim( (string) $s ) || $home ) {
				continue;
			}
			$mis = sap_kw_misfit( $s, $type, $row['title'] );
			// A product's own name is often a plain noun («شارژر اسکوتر»): only flag its primary keyword for a wrong intent, not for being generic.
			if ( 0 === $i && 'product' === $type && 'product_cat' === $mis ) {
				$mis = '';
			}
			if ( $mis ) {
				$row['misfits'][] = array( 'keyword' => $s, 'primary' => 0 === $i, 'better' => $mis );
			}
		}
		if ( $row['misfits'] ) {
			$fail( sprintf( '%s کلمه با نیت جستجوی این نوع صفحه نمی‌خواند', sap_num( count( $row['misfits'] ) ) ), $row['misfits'][0]['primary'] ? 15 : 6 );
		}
		$row['score']       = max( 0, 100 - array_sum( array_column( $row['checks'], 1 ) ) );
		$groups[ $group ][] = $row;
	}
	foreach ( $groups as &$rows ) {
		usort( $rows, function ( $a, $b ) {
			return array( $b['impr'], $b['clicks'] ) <=> array( $a['impr'], $a['clicks'] );
		} );
	}
	unset( $rows );
	$cache[ $pid ] = $groups;
	return $groups;
}

/**
 * Inventory row of one URL with its group, or null.
 *
 * @return array|null [group, row]
 */
function sap_audit_row( $project, $url ) {
	$k = sap_norm_url( $url );
	foreach ( sap_content_inventory( $project ) as $g => $rows ) {
		foreach ( $rows as $r ) {
			if ( sap_norm_url( $r['url'] ) === $k ) {
				return array( $g, $r );
			}
		}
	}
	return null;
}

/**
 * HTML of the full analysis of one page (drawer of the content audit).
 */
function sap_audit_result_html( $project, $url ) {
	$found = sap_audit_row( $project, $url );
	if ( ! $found ) {
		return '';
	}
	list( $g, $row ) = $found;
	$a  = sap_dataset_payload( $project['id'], 'ai_audit' )['groups'][ $g ][ sap_norm_url( $url ) ] ?? null;
	$wp = $row['wp'] ? sap_site_page_details( $project, $url ) : null;
	ob_start();
	sap_view( 'audit-result', array( 'project' => $project, 'row' => $row, 'a' => $a, 'wp' => $wp ) );
	return (string) ob_get_clean();
}

/* ---------------------------------------------------------------------------
 * AI audit per content group (stages audit_<group>)
 * ------------------------------------------------------------------------- */

/**
 * What to look at for each group (the expert's lens).
 */
function sap_audit_lens( $group ) {
	$l = array(
		'products'    => 'صفحه محصول را از دید فروش و تبدیل بررسی کن: توضیحات کامل و جامع بر اساس کلمه اصلی (نام محصول/مدل) و کلمات فرعی، مزایا و کاربرد واقعی، جدول مشخصات و ویژگی‌های ووکامرس (Attributes) کامل و دقیق (جنس، ابعاد، وزن، سازگاری، کشور سازنده و…)، راهنمای انتخاب/سایز، تصاویر با Alt، نظرات و امتیاز، قیمت و موجودی و اسکیمای Product/Offer/AggregateRating، FAQ، اعتمادسازی (گارانتی، ارسال، مرجوعی)، CTA روشن، و لینک به دسته‌بندی و محصولات مرتبط. کلمه عمومی خرید (مثل «خرید چسب کفش» یا «چسب کتونی») هدف صفحه دسته است نه یک محصول؛ اگر چنین کلمه‌ای به محصول داده شده، در keyword_fit بگو کجا باید برود.',
		'product_tax' => 'صفحه دسته/برچسب/برند را از دید فروش بررسی کن: کلمه اصلی عمومی خرید (مثل «خرید X»، «قیمت X») باید هدف همین صفحه باشد؛ H1 و تایتل با کلمه اصلی، متن معرفی کوتاه بالای محصولات و متن راهنمای خرید جامع پایین صفحه (انواع، نحوه انتخاب، قیمت‌ها)، FAQ، لینک به زیردسته‌ها، محصولات پرفروش و مقالات راهنمای خرید، اسکیمای BreadcrumbList و FAQ، و برچسب‌های کم‌ارزش که باید noindex یا ادغام شوند.',
		'posts'       => 'مقاله را از دید جذب ترافیک و هدایت به فروش بررسی کن: پوشش کامل نیت اطلاعاتی کلمه اصلی و فرعی‌ها، ساختار H2/H3، به‌روز بودن اطلاعات، لینک داخلی به صفحات پولساز (دسته/محصول) با انکر مناسب و CTA به محصول، لینک خارجی به منبع معتبر، اسکیمای Article و FAQ، و خطر کنیبال با مقالات یا دسته‌های دیگر.',
		'post_cat'    => 'دسته مقالات را بررسی کن: متن معرفی، لینک به مقالات پیلار و صفحات پولساز، جلوگیری از محتوای تکراری و صفحه‌بندی بی‌ارزش در ایندکس.',
		'pages'       => 'برگه‌ها (صفحه اصلی، خدمات/لندینگ، درباره ما، تماس) را از دید هدف کسب‌وکار (فروش یا لید) بررسی کن: پیام اصلی و CTA، اعتمادسازی و E-E-A-T، کلمه اصلی مناسب، لینک به دسته‌ها و صفحات مهم، فرم/راه تماس.',
	);
	return $l[ $group ] ?? '';
}

/**
 * Register the audit stages in the AI pipeline.
 */
add_filter( 'sap_ai_stages', function ( $stages ) {
	$str = array( 'type' => 'string' );
	foreach ( sap_content_groups() as $g => $def ) {
		$stages[ 'audit_' . $g ] = array(
			'label'  => 'تحلیل محتوا: ' . $def[0],
			'tokens' => 6000,
			'batch'  => array( 'in' => 'pages', 'out' => 'pages', 'size' => 3 ),
			'schema' => sap_ai_obj( array( 'pages' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
				'url'         => $str,
				'verdict'     => array( 'type' => 'string', 'enum' => array( 'good', 'improve', 'weak' ) ),
				'diagnosis'   => array( 'type' => 'string', 'description' => 'تشخیص در ۲ تا ۳ جمله از دید Focus و هدف (فروش/لید) با استناد به داده' ),
				'keyword_fit' => array( 'type' => 'array', 'description' => 'کلماتی (اصلی یا فرعی) که با نیت این صفحه نمی‌خوانند؛ خالی اگر همه درست‌اند', 'items' => sap_ai_obj( array(
					'keyword' => $str,
					'better'  => array( 'type' => 'string', 'enum' => array( 'product_cat', 'product', 'post', 'page', 'new_page' ) ),
					'why'     => $str,
				) ) ),
				'title'       => array( 'type' => 'string', 'description' => 'تایتل پیشنهادی (حداکثر ۶۰ کاراکتر) یا خالی اگر فعلی خوب است' ),
				'meta'        => array( 'type' => 'string', 'description' => 'متای پیشنهادی (۱۲۰ تا ۱۵۵ کاراکتر) یا خالی' ),
				'actions'     => array( 'type' => 'array', 'items' => sap_ai_obj( array(
					'type'     => array( 'type' => 'string', 'enum' => array( 'content', 'title_meta', 'attributes', 'faq', 'schema', 'links', 'cta', 'images', 'merge', 'noindex' ) ),
					'title'    => array( 'type' => 'string', 'description' => 'کار مشخص با نام صفحه' ),
					'detail'   => array( 'type' => 'string', 'description' => 'دقیقاً چه چیزی اضافه/اصلاح شود' ),
					'priority' => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
					'hours'    => array( 'type' => 'number' ),
				) ) ),
				'update_brief' => array( 'type' => 'string', 'description' => 'اگر صفحه آپدیت کامل لازم دارد: بریف کوتاه (کلمه اصلی و فرعی، بخش‌هایی که اضافه شود، ویژگی‌ها، FAQ، لینک‌ها) وگرنه خالی' ),
			) ) ) ) ),
			'task'   => '',
		);
	}
	return $stages;
} );

add_filter( 'sap_ai_stage_task', function ( $task, $stage ) {
	if ( 0 !== strpos( $stage, 'audit_' ) ) {
		return $task;
	}
	$g = substr( $stage, 6 );
	return implode( "\n", array(
		'تو یک متخصص ارشد سئو محتوا و CRO برای سایت‌های فارسی هستی. این صفحات از نوع «' . ( sap_content_groups()[ $g ][0] ?? $g ) . '» هستند، با کلمه اصلی و فرعی نقشه کلمات، کوئری‌های واقعی سرچ کنسول (کوئری، جایگاه، نمایش، CTR%)، تیترها، اسکیما، بخشی از متن و نتیجه چک‌لیست خودکار (checks). داده wordpress مستقیم از خود وردپرس است: ویژگی‌ها (Attributes)، قیمت، موجودی، توضیح کوتاه، گالری، تصاویر و Alt آن‌ها، نظرات، و برای دسته‌ها تعداد و محصولات پرفروش؛ به آن استناد کن.',
		'Focusها و هدف پروژه در focuses آمده‌اند؛ تحلیل را از دید همین هدف انجام بده: چه چیزی باعث فروش یا لید بیشتر از این صفحه می‌شود.',
		sap_audit_lens( $g ),
		'recent_work کارهایی است که در ۷۵ روز اخیر روی همان صفحه انجام شده یا در برنامه است (تغییر تایتل/متا، آپدیت محتوا، لینک…). آن‌ها را دوباره پیشنهاد نده؛ اگر تایتل/متا یا محتوا تازه تغییر کرده، فرصت بده نتیجه‌اش دیده شود و فقط اشتباه واضح را بگو.',
		'my_notes دیدگاه متخصص سئوی پروژه درباره همان صفحه است؛ بر هر چیز دیگری مقدم است و پیشنهادهایت باید با آن بخواند.',
		'قواعد: کلی‌گویی نکن، به متن و کوئری واقعی استناد کن؛ برای هر صفحه حداکثر ۴ اقدام مشخص، عملی و پراثر با ساعت تخمینی بنویس (هر اقدام یک آیتم چک‌لیست همان صفحه است، نه یک کار جدا)؛ کارهای کوچک هم‌نوع را در یک اقدام ادغام کن؛ اقدامی که سخت، مبهم یا کم‌اثر است ننویس؛ اگر صفحه خوب است verdict را good بگذار و اقدام اضافه نساز. نیت هر کلمه را دقیق بسنج و کلمات نامناسب را در keyword_fit بیاور (فله‌ای پیشنهاد نده).',
	) );
}, 10, 2 );

add_filter( 'sap_ai_stage_input', function ( $input, $project, $stage, $ctx ) {
	if ( 0 !== strpos( $stage, 'audit_' ) ) {
		return $input;
	}
	$g    = substr( $stage, 6 );
	$inv  = sap_content_inventory( $project )[ $g ] ?? array();
	$only = (array) get_option( 'sap_audit_only_' . $project['id'], array() );
	if ( $only ) {
		$inv = array_values( array_filter( $inv, function ( $r ) use ( $only ) {
			return in_array( sap_norm_url( $r['url'] ), $only, true );
		} ) );
	} else {
		$inv = array_slice( $inv, 0, 15 ); // Most visible pages of the type.
	}
	if ( ! $inv ) {
		return null;
	}
	$out = array();
	foreach ( $inv as $r ) {
		$k = sap_norm_url( $r['url'] );
		if ( ! isset( $ctx['pages'][ $k ] ) ) {
			$p = sap_page_fetch( $r['url'] ); // Not read yet: read it now for the audit.
			if ( ! is_wp_error( $p ) ) {
				$ctx['pages'][ $k ] = $p;
			}
		}
		$facts               = sap_ai_page_facts( $ctx, $r['url'], 2000 );
		$facts['type']       = sap_content_types()[ $r['type'] ] ?? $r['type'];
		$facts['primary_kw'] = $r['kw'];
		$facts['secondary']  = array_slice( (array) $r['secondary'], 0, 8 );
		$facts['checks']     = array_column( $r['checks'], 0 );
		$facts['clicks28']   = $r['clicks'];
		if ( $r['wp'] ) {
			$facts['wordpress'] = sap_site_ai_facts( sap_site_page_details( $project, $r['url'] ) );
		}
		$recent = array_slice( array_column( sap_recent_work( $project['id'] )[ sap_norm_url( $r['url'] ) ] ?? array(), 'what' ), 0, 6 );
		if ( $recent ) {
			$facts['recent_work'] = $recent;
		}
		$notes = sap_page_notes( $r['url'] );
		if ( $notes ) {
			$facts['my_notes'] = array_slice( $notes, -5 );
		}
		$out[]               = $facts;
	}
	$focuses = array();
	foreach ( $ctx['focuses'] as $f ) {
		$focuses[] = array( 'name' => $f['name'], 'goal' => $f['goal'] );
	}
	return array( 'site' => sap_project_domain( $project ), 'business_goal' => $project['options']['business_goal'] ?? 'فروش آنلاین', 'focuses' => $focuses, 'pages' => $out );
}, 10, 4 );

add_filter( 'sap_ai_stage_postprocess', function ( $res, $project, $stage, $input ) {
	if ( 0 !== strpos( $stage, 'audit_' ) ) {
		return $res;
	}
	$g     = substr( $stage, 6 );
	$pages = array();
	foreach ( array_values( array_filter( (array) ( $res['pages'] ?? array() ), 'is_array' ) ) as $p ) {
		$p = wp_parse_args( $p, array( 'url' => '', 'verdict' => 'improve', 'diagnosis' => '', 'keyword_fit' => array(), 'title' => '', 'meta' => '', 'actions' => array(), 'update_brief' => '' ) );
		$p['url']         = sap_full_url( $project, $p['url'] );
		$p['actions']     = array_values( array_filter( (array) $p['actions'], 'is_array' ) );
		$p['keyword_fit'] = array_values( array_filter( (array) $p['keyword_fit'], 'is_array' ) );
		$pages[ sap_norm_url( $p['url'] ) ] = $p;
	}
	// Keep earlier results of pages not in this run (single-page re-audits).
	$old   = (array) ( sap_dataset_payload( $project['id'], 'ai_audit' )['groups'][ $g ] ?? array() );
	$all   = array_merge( (array) ( sap_dataset_payload( $project['id'], 'ai_audit' )['groups'] ?? array() ), array( $g => $pages + $old ) );
	sap_dataset_put( $project['id'], 'ai_audit', array( 'groups' => $all ) );
	delete_option( 'sap_audit_only_' . $project['id'] );
	$n = sap_audit_to_proposals( $project, $g, $pages + $old );
	return array( 'pages' => array_values( $pages ), 'summary_line' => sprintf( 'تحلیل %s صفحه «%s» انجام شد؛ %s اقدام برای تأیید شما آماده است.', sap_num( count( $pages ) ), sap_content_groups()[ $g ][0] ?? $g, sap_num( $n ) ) );
}, 10, 4 );

/**
 * Turn audit results into proposals.
 */
function sap_audit_to_proposals( $project, $group, $pages ) {
	$ctx_of = array( 'content' => 'content_update', 'title_meta' => 'onpage', 'attributes' => 'content_update', 'faq' => 'content_update', 'schema' => 'onpage', 'links' => 'internal_links', 'cta' => 'cro', 'images' => 'onpage', 'merge' => 'content_update', 'noindex' => 'technical' );
	$labels = array( 'content_update' => 'محتوا', 'onpage' => 'سئو داخلی', 'internal_links' => 'لینک داخلی', 'cro' => 'نرخ تبدیل' );
	$items  = array();
	foreach ( $pages as $p ) {
		$name = sap_page_title( $project['id'], $p['url'] ) ?: sap_short_url( $p['url'], 50 );
		if ( $p['title'] || $p['meta'] ) {
			$items[] = array(
				'key'         => 'tm:' . sap_norm_url( $p['url'] ),
				'title'       => 'اصلاح تایتل و متای «' . $name . '»',
				'detail'      => $p['diagnosis'],
				'deliverable' => trim( ( $p['title'] ? 'تایتل: ' . $p['title'] : '' ) . "\n" . ( $p['meta'] ? 'متا: ' . $p['meta'] : '' ) ),
				'context'     => 'onpage',
				'url'         => $p['url'],
				'priority'    => 'weak' === $p['verdict'] ? 'high' : 'medium',
				'effort'      => 0.25,
				'section'     => $group,
				'kind'        => 'title_meta',
			);
		}
		foreach ( $p['actions'] as $a ) {
			$a = wp_parse_args( $a, array( 'type' => 'content', 'title' => '', 'detail' => '', 'priority' => 'medium', 'hours' => 1 ) );
			if ( 'title_meta' === $a['type'] && ( $p['title'] || $p['meta'] ) ) {
				continue; // Already above with the ready text.
			}
			$items[] = array(
				'key'      => md5( $p['url'] . '|' . $a['type'] . '|' . $a['title'] ),
				'title'    => $a['title'] ?: ( $labels[ $ctx_of[ $a['type'] ] ?? 'content_update' ] ?? '' ) . ' «' . $name . '»',
				'detail'   => $a['detail'] . ( $p['diagnosis'] ? "\n\nتشخیص: " . $p['diagnosis'] : '' ),
				'context'  => $ctx_of[ $a['type'] ] ?? 'content_update',
				'url'      => $p['url'],
				'priority' => $a['priority'],
				'effort'   => (float) $a['hours'],
				'section'  => $group,
				'kind'     => $a['type'],
			);
		}
		foreach ( $p['keyword_fit'] as $kf ) {
			$types   = sap_content_types() + array( 'new_page' => 'صفحه جدید' );
			$items[] = array(
				'key'      => 'kw:' . sap_kw_key( $kf['keyword'] ?? '' ) . ':' . sap_norm_url( $p['url'] ),
				'title'    => sprintf( 'هدف‌گیری درست کلمه «%s» (مناسب %s، نه «%s»)', $kf['keyword'] ?? '', $types[ $kf['better'] ?? '' ] ?? 'صفحه دیگر', $name ),
				'detail'   => (string) ( $kf['why'] ?? '' ) . "\n\nکلمه را از کلمات این صفحه بردارید و به صفحه درست در نقشه کلمات بدهید (یا اگر صفحه درستی نیست، بسازید).",
				'context'  => 'research',
				'url'      => $p['url'],
				'keyword'  => (string) ( $kf['keyword'] ?? '' ),
				'priority' => 'medium',
				'effort'   => 0.5,
				'section'  => $group,
				'kind'     => 'keyword_fit',
			);
		}
	}
	return sap_proposals_put( $project, 'audit_' . $group, $items );
}

/**
 * Convert the old «content» AI stage answer into proposals.
 */
function sap_content_ai_to_proposals( $project, $pages ) {
	$items = array();
	foreach ( $pages as $p ) {
		$url  = sap_full_url( $project, $p['url'] );
		$name = sap_page_title( $project['id'], $url ) ?: sap_short_url( $url, 50 );
		if ( $p['title'] || $p['meta'] ) {
			$items[] = array( 'key' => 'tm:' . sap_norm_url( $url ), 'title' => 'اصلاح تایتل و متای «' . $name . '»', 'detail' => $p['diagnosis'], 'deliverable' => trim( ( $p['title'] ? 'تایتل: ' . $p['title'] : '' ) . "\n" . ( $p['meta'] ? 'متا: ' . $p['meta'] : '' ) ), 'context' => 'onpage', 'url' => $url, 'priority' => 'medium', 'effort' => 0.25, 'kind' => 'title_meta' );
		}
		if ( $p['missing_topics'] || $p['faq'] || $p['actions'] ) {
			$items[] = array(
				'key'         => 'upd:' . sap_norm_url( $url ),
				'title'       => 'به‌روزرسانی محتوای «' . $name . '»',
				'detail'      => $p['diagnosis'],
				'deliverable' => implode( "\n", array_filter( array(
					$p['actions'] ? "اقدامات:\n- " . implode( "\n- ", $p['actions'] ) : '',
					$p['missing_topics'] ? "موضوعات جاافتاده:\n- " . implode( "\n- ", $p['missing_topics'] ) : '',
					$p['faq'] ? "FAQ:\n- " . implode( "\n- ", $p['faq'] ) : '',
				) ) ),
				'context'     => 'content_update',
				'url'         => $url,
				'priority'    => 'medium',
				'effort'      => 2,
				'kind'        => 'content',
			);
		}
	}
	return sap_proposals_put( $project, 'content', $items );
}

/* ---------------------------------------------------------------------------
 * Clustering sheet from the keyword map
 * ------------------------------------------------------------------------- */

/**
 * Cluster name of a mapped page: its focus, else its most shared topic word.
 */
function sap_cluster_names( $project ) {
	$map    = sap_keymap( $project['id'] );
	$fnames = wp_list_pluck( sap_focuses( $project['id'] ), 'name', 'id' );
	$generic = array_flip( sap_graph_generic_words() );
	$df     = array();
	foreach ( $map as $m ) {
		foreach ( array_unique( sap_tokens( $m['primary_kw'] ) ) as $w ) {
			$df[ $w ] = ( $df[ $w ] ?? 0 ) + 1;
		}
	}
	$out = array();
	foreach ( $map as $k => $m ) {
		if ( $m['focus_id'] && isset( $fnames[ $m['focus_id'] ] ) ) {
			$out[ $k ] = $fnames[ $m['focus_id'] ];
			continue;
		}
		$pick = '';
		$max  = 0;
		foreach ( sap_tokens( $m['primary_kw'] ) as $w ) {
			if ( ! isset( $generic[ $w ] ) && mb_strlen( $w ) >= 3 && ( $df[ $w ] ?? 0 ) > $max ) {
				$max  = $df[ $w ];
				$pick = $w;
			}
		}
		$out[ $k ] = $max >= 2 ? $pick : ( 'home' === $m['page_type'] ? 'صفحه اصلی' : ( sap_content_types()[ sap_content_type( $m ) ] ?? 'سایر' ) );
	}
	return $out;
}

/**
 * Write the clustering tab (same layout the tool reads back): every target
 * keyword with its role, target page, intent, volume, position and clicks.
 *
 * @param bool $overwrite Replace a tab that was not written by the tool.
 * @return string|WP_Error
 */
function sap_cluster_write_sheet( $project, $overwrite = false ) {
	$sheet = $project['sheet_id'];
	if ( ! $sheet ) {
		return new WP_Error( 'sap_sheet', 'گوگل شیت پروژه هنوز ساخته یا وصل نشده است (تب تقویم محتوا › آماده‌سازی گوگل شیت).' );
	}
	$tab  = $project['sheet_cluster_tab'] ?: 'کلاسترینگ';
	$tabs = null;
	if ( ! $overwrite && empty( $project['options']['cluster_generated'] ) ) {
		$old = sap_sheet_values( $sheet, $tab );
		if ( ! is_wp_error( $old ) && count( (array) $old ) > 1 ) {
			return new WP_Error( 'sap_cluster_exists', sprintf( 'تب «%s» در شیت داده دارد و توسط سکان ساخته نشده است. برای جایگزینی، گزینه «جایگزین شود» را بزنید (بهتر است قبلش از شیت کپی بگیرید).', $tab ), array( 'status' => 409 ) );
		}
	}
	$sid = sap_gs_ensure_tab( $sheet, $tab, $tabs );
	if ( is_wp_error( $sid ) ) {
		return $sid;
	}
	$pid   = $project['id'];
	$vols  = (array) ( sap_dataset_payload( $pid, 'ss_volumes' )['volumes'] ?? array() );
	$qp    = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
		$k = sap_kw_key( $r['query'] );
		if ( ! isset( $qp[ $k ] ) || $r['impressions'] > $qp[ $k ]['impressions'] ) {
			$qp[ $k ] = $r;
		}
	}
	$names = sap_cluster_names( $project );
	$by    = array();
	foreach ( sap_keymap( $pid ) as $k => $m ) {
		if ( '' === $m['primary_kw'] ) {
			continue;
		}
		$by[ $names[ $k ] ?? 'سایر' ][] = $m;
	}
	ksort( $by );
	$head   = array( 'کلاستر', 'زیرکلاستر', 'کلمه کلیدی', 'نقش', 'صفحه هدف', 'اولویت', 'نیت جستجو', 'یادداشت', 'حجم جستجو', 'جایگاه فعلی', 'صفحه رتبه‌گرفته', 'کلیک ۲۸ روز', 'نمایش ۲۸ روز', 'حجم کل کلاستر', 'نوع صفحه' );
	$ctypes = sap_content_types();
	$values = array( $head );
	$fmt    = sap_gs_reset( $sid, array( 0 => 140, 1 => 200, 2 => 200, 3 => 70, 4 => 280, 5 => 60, 6 => 90, 7 => 200, 8 => 90, 9 => 90, 10 => 240, 11 => 80, 12 => 90, 13 => 110, 14 => 110 ) );
	$fmt[]  = sap_gs_fmt( $sid, 0, 1, 0, count( $head ), '#1D4ED8', '#FFFFFF', true );
	$intent = sap_keymap_intents();
	$kws    = 0;
	foreach ( $by as $cluster => $rows ) {
		$total = 0;
		foreach ( $rows as $m ) {
			foreach ( array_merge( array( $m['primary_kw'] ), $m['secondary'] ) as $kw ) {
				$total += (int) ( $vols[ sap_kw_key( $kw ) ]['volume'] ?? 0 );
			}
		}
		$first = true;
		$start = count( $values );
		usort( $rows, function ( $a, $b ) {
			return ( 'category' === $b['page_type'] ) <=> ( 'category' === $a['page_type'] );
		} );
		foreach ( $rows as $i => $m ) {
			foreach ( array_merge( array( $m['primary_kw'] ), $m['secondary'] ) as $j => $kw ) {
				$q        = $qp[ sap_kw_key( $kw ) ] ?? null;
				$role     = 0 === $j ? ( 0 === $i && 'category' === $m['page_type'] ? 'پیلار' : 'اصلی' ) : 'فرعی';
				$values[] = array(
					$first ? $cluster : '',
					0 === $j ? ( $m['title'] ?: $m['primary_kw'] ) : '',
					$kw,
					$role,
					rawurldecode( $m['url'] ),
					0 === $j ? ( $m['focus_id'] ? 'بالا' : 'متوسط' ) : '',
					$intent[ 0 === $j && $m['intent'] ? $m['intent'] : sap_kw_intent( $kw ) ] ?? '',
					0 === $j ? (string) $m['reason'] : ( sap_kw_misfit( $kw, sap_content_type( $m ), $m['title'] ) ? 'نیت با این صفحه نمی‌خواند' : '' ),
					isset( $vols[ sap_kw_key( $kw ) ] ) ? (int) $vols[ sap_kw_key( $kw ) ]['volume'] : '',
					$q ? round( (float) $q['position'], 1 ) : '',
					$q ? rawurldecode( $q['page'] ) : '',
					$q ? (int) $q['clicks'] : '',
					$q ? (int) $q['impressions'] : '',
					$first ? $total : '',
					0 === $j ? ( $ctypes[ sap_content_type( $m ) ] ?? '' ) : '',
				);
				$first = false;
				++$kws;
			}
		}
		$fmt[] = sap_gs_fmt( $sid, $start, $start + 1, 0, count( $head ), '#EEF4FF', '#1E3A8A', true );
	}
	$res = sap_gs_put_tab( $sheet, $tab, $values );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$fmt[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => $sid, 'gridProperties' => array( 'frozenRowCount' => 1 ) ), 'fields' => 'gridProperties.frozenRowCount' ) );
	sap_gs_batch( $sheet, $fmt );
	sap_project_set_option( $pid, 'cluster_generated', sap_now() );
	$inv = sap_inventory_write_sheet( sap_project( $pid ) );
	return sprintf( 'کلاسترینگ (%s کلاستر، %s کلمه) در تب «%s» نوشته شد', sap_num( count( $by ) ), sap_num( $kws ), $tab ) . ( is_wp_error( $inv ) ? '؛ فهرست محتوا: ' . $inv->get_error_message() : ( $inv ? '؛ ' . $inv : '' ) );
}

/**
 * Tab «فهرست محتوا»: every page of the site by type with its keywords,
 * Search Console numbers, content size, score and issues (tool-owned tab).
 *
 * @return string|WP_Error
 */
function sap_inventory_write_sheet( $project ) {
	$sheet = $project['sheet_id'];
	if ( ! $sheet ) {
		return '';
	}
	$tab  = 'فهرست محتوا';
	$tabs = null;
	$sid  = sap_gs_ensure_tab( $sheet, $tab, $tabs );
	if ( is_wp_error( $sid ) ) {
		return $sid;
	}
	$types  = sap_content_types();
	$head   = array( 'نوع', 'عنوان', 'آدرس', 'کلمه اصلی', 'منبع کلمه', 'کلمات فرعی', 'پرنمایش‌ترین کوئری', 'جایگاه', 'کلیک ۲۸ روز', 'نمایش ۲۸ روز', 'تعداد کلمات', 'امتیاز', 'مشکلات', 'noindex', 'آخرین بروزرسانی' );
	$values = array( $head );
	$src    = array( 'map' => 'نقشه کلمات', 'seo' => 'افزونه سئو' );
	foreach ( sap_content_inventory( $project ) as $rows ) {
		foreach ( $rows as $r ) {
			if ( count( $values ) > 6000 ) {
				break 2;
			}
			$values[] = array(
				$types[ $r['type'] ] ?? $r['type'],
				$r['title'],
				rawurldecode( $r['url'] ),
				$r['kw'],
				$src[ $r['kw_src'] ] ?? '',
				implode( '، ', array_slice( (array) $r['secondary'], 0, 8 ) ),
				$r['queries'][0][0] ?? '',
				null === $r['pos'] ? '' : $r['pos'],
				$r['clicks'],
				$r['impr'],
				null === $r['words'] ? '' : $r['words'],
				$r['score'],
				implode( ' | ', array_column( $r['checks'], 0 ) ),
				! empty( $r['wp']['noindex'] ) ? 'بله' : '',
				! empty( $r['wp']['modified'] ) ? sap_jdate( substr( $r['wp']['modified'], 0, 10 ), 'short' ) : '',
			);
		}
	}
	$fmt   = sap_gs_reset( $sid, array( 0 => 110, 1 => 240, 2 => 280, 3 => 170, 4 => 90, 5 => 240, 6 => 170, 7 => 60, 8 => 70, 9 => 80, 10 => 70, 11 => 60, 12 => 380, 13 => 60, 14 => 100 ) );
	$fmt[] = sap_gs_fmt( $sid, 0, 1, 0, count( $head ), '#0F766E', '#FFFFFF', true );
	$res   = sap_gs_put_tab( $sheet, $tab, $values );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$fmt[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => $sid, 'gridProperties' => array( 'frozenRowCount' => 1 ) ), 'fields' => 'gridProperties.frozenRowCount' ) );
	sap_gs_batch( $sheet, $fmt );
	return sprintf( '%s صفحه در تب «%s»', sap_num( count( $values ) - 1 ), $tab );
}

/* ---------------------------------------------------------------------------
 * Update brief (content calendar › updates)
 * ------------------------------------------------------------------------- */

/**
 * Write the professional update brief of a page: keyword placement, sections
 * to add, product attributes, FAQ, internal links in / out, external sources,
 * schema and a final checklist.
 *
 * @return array|WP_Error The brief (also saved on the update).
 */
function sap_update_brief( $project, $update ) {
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید هوش مصنوعی در تنظیمات وارد نشده است.' );
	}
	$ctx          = sap_analysis_context( $project );
	$ctx['pages'] = (array) ( $ctx['pages']['pages'] ?? array() );
	$k            = sap_norm_url( $update['url'] );
	if ( ! isset( $ctx['pages'][ $k ] ) ) {
		$p = sap_page_fetch( $update['url'] );
		if ( ! is_wp_error( $p ) ) {
			$ctx['pages'][ $k ] = $p;
		}
	}
	$facts = sap_ai_page_facts( $ctx, $update['url'], 3500 );
	if ( sap_site_item( $project['id'], $update['url'] ) ) {
		$facts['wordpress'] = sap_site_ai_facts( sap_site_page_details( $project, $update['url'] ) );
	}
	$group = 'pages';
	foreach ( sap_content_groups() as $g => $def ) {
		if ( in_array( $update['content_type'], $def[2], true ) ) {
			$group = $g;
		}
	}
	$audit = sap_dataset_payload( $project['id'], 'ai_audit' )['groups'][ $group ][ $k ] ?? null;
	$money = array();
	$posts = array();
	foreach ( $ctx['keymap'] as $mk => $m ) {
		if ( $mk === $k || '' === $m['primary_kw'] ) {
			continue;
		}
		$row = array( sap_short_url( $m['url'], 80 ), $m['primary_kw'] );
		if ( in_array( $m['page_type'], array( 'category', 'product' ), true ) ) {
			$money[] = $row;
		} elseif ( 'article' === $m['page_type'] ) {
			$posts[] = $row;
		}
	}
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array(
		'summary'      => array( 'type' => 'string', 'description' => 'هدف آپدیت در ۲ جمله: چه چیزی و چرا، از دید Focus و فروش' ),
		'keyword'      => $str,
		'secondary'    => array( 'type' => 'array', 'items' => $str ),
		'title'        => array( 'type' => 'string', 'description' => 'تایتل پیشنهادی ≤ ۶۰ کاراکتر' ),
		'meta'         => array( 'type' => 'string', 'description' => 'متا ۱۲۰ تا ۱۵۵ کاراکتر' ),
		'h1'           => $str,
		'outline'      => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'heading' => $str, 'notes' => array( 'type' => 'string', 'description' => 'چه چیزی در این بخش نوشته شود و کدام کلمه فرعی' ) ) ) ),
		'attributes'   => array( 'type' => 'array', 'description' => 'فقط برای محصول: ویژگی‌های ووکامرس که باید کامل شوند', 'items' => sap_ai_obj( array( 'name' => $str, 'hint' => $str ) ) ),
		'faq'          => array( 'type' => 'array', 'items' => $str ),
		'links_out'    => array( 'type' => 'array', 'description' => 'لینک داخلی از این صفحه به صفحات دیگر', 'items' => sap_ai_obj( array( 'url' => $str, 'anchor' => $str ) ) ),
		'links_in'     => array( 'type' => 'array', 'description' => 'صفحاتی که باید به این صفحه لینک بدهند', 'items' => sap_ai_obj( array( 'url' => $str, 'anchor' => $str ) ) ),
		'external'     => array( 'type' => 'array', 'description' => 'منابع معتبر برای لینک خارجی یا استناد (نوع منبع/عبارت جستجو)', 'items' => $str ),
		'schema'       => array( 'type' => 'array', 'items' => $str ),
		'cta'          => array( 'type' => 'string', 'description' => 'دعوت به اقدام پیشنهادی' ),
		'checklist'    => array( 'type' => 'array', 'items' => $str ),
		'words_target' => array( 'type' => 'integer', 'description' => 'حدود تعداد کلمات هدف' ),
	) );
	$task   = implode( "\n", array(
		'تو سرپرست محتوای سئو هستی. برای آپدیت کامل و حرفه‌ای این صفحه یک بریف دقیق و قابل اجرا برای نویسنده بنویس.',
		sap_audit_lens( $group ),
		'کلمه اصلی و فرعی‌ها را از نقشه کلمات و کوئری‌های واقعی بگیر و جای دقیقشان را مشخص کن (تایتل، H1، پاراگراف اول، تیترها). بخش‌های موجود خوب را نگه دار و فقط کمبودها را اضافه کن.',
		'لینک‌های داخلی فقط از money_pages و posts آمده باشند با انکر طبیعی؛ links_in از مقالات مرتبط به این صفحه باشد.',
		'برای محصول، attributes را کامل بنویس (نام ویژگی و راهنمای مقدار). برای مقاله، منبع معتبر خارجی پیشنهاد بده.',
	) );
	$input  = array(
		'page'        => $facts,
		'type'        => sap_content_types()[ $update['content_type'] ] ?? $update['content_type'],
		'keyword'     => $update['keyword'],
		'secondary'   => $update['secondary'],
		'reason'      => $update['reason'],
		'audit'       => $audit ? array( 'diagnosis' => $audit['diagnosis'], 'actions' => array_column( $audit['actions'], 'title' ), 'brief' => $audit['update_brief'] ) : null,
		'focuses'     => array_map( function ( $f ) {
			return array( 'name' => $f['name'], 'goal' => $f['goal'] );
		}, $ctx['focuses'] ),
		'money_pages' => array_slice( $money, 0, 30 ),
		'posts'       => array_slice( $posts, 0, 30 ),
	);
	// What Google's top 10 cover for this keyword (SERP analysis), when it exists.
	$serp = '' !== (string) $update['keyword'] ? sap_serp_brief_facts( $project['id'], $update['keyword'] ) : null;
	if ( $serp ) {
		$input['serp_gap'] = $serp;
		$task             .= "\nserp_gap تحلیل ۱۰ نتیجه اول گوگل است: موضوعات must_cover، شکاف‌ها (gaps)، زاویه متمایز و سرفصل برندگان؛ بریف باید این شکاف‌ها را ببندد و صفحه را از برندگان کامل‌تر کند.";
	}
	$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'light', 'max_tokens' => 4500, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	unset( $res['_partial'] );
	foreach ( array( 'links_out', 'links_in' ) as $lk ) {
		foreach ( (array) ( $res[ $lk ] ?? array() ) as $i => $l ) {
			$res[ $lk ][ $i ]['url'] = sap_full_url( $project, (string) ( $l['url'] ?? '' ) );
		}
	}
	$res['at'] = sap_now();
	sap_update_save( array( 'brief' => $res ), $update['id'] );
	return $res;
}
