<?php
/**
 * Content inventory from the client's WordPress (connector 1.3+): every
 * published post, page, product, post category / tag, product category / tag
 * and brand, with its real type, SEO fields (Rank Math / Yoast), word count,
 * images, noindex and WooCommerce data.
 *
 * Without it the content type of a page is guessed from its URL, which fails
 * on sites with flat permalinks (every product or post looks like a page).
 * With it the content audit lists the whole site by type, matched with Search
 * Console by URL, and the keyword map gets the right page type.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content type (audit) of a WordPress post type / taxonomy.
 */
function sap_inventory_ctype( $item ) {
	if ( ! empty( $item['brand'] ) ) {
		return 'brand';
	}
	$map = array(
		'post'        => 'post',
		'page'        => 'page',
		'product'     => 'product',
		'category'    => 'post_cat',
		'post_tag'    => 'post_tag',
		'product_cat' => 'product_cat',
		'product_tag' => 'product_tag',
	);
	return $map[ $item['type'] ?? '' ] ?? 'page';
}

/**
 * Keyword-map page type of a content type.
 */
function sap_inventory_page_type( $ctype, $item = array(), $front = 0 ) {
	if ( $front && 'post' === ( $item['kind'] ?? '' ) && (int) $item['id'] === (int) $front ) {
		return 'home';
	}
	$map = array( 'product' => 'product', 'product_cat' => 'category', 'product_tag' => 'category', 'brand' => 'category', 'post' => 'article', 'post_cat' => 'category', 'post_tag' => 'category', 'page' => 'page' );
	return $map[ $ctype ] ?? 'other';
}

/**
 * Inventory items keyed by normalized URL (empty when not synced).
 */
function sap_site_inventory( $project_id ) {
	static $cache = array();
	if ( isset( $cache[ $project_id ] ) ) {
		return $cache[ $project_id ];
	}
	$out = array();
	foreach ( (array) ( sap_dataset_payload( $project_id, 'site_inventory' )['items'] ?? array() ) as $it ) {
		if ( ! empty( $it['url'] ) ) {
			$out[ sap_norm_url( $it['url'] ) ] = $it;
		}
	}
	$cache[ $project_id ] = $out;
	return $out;
}

/**
 * Inventory item of one URL, or null.
 */
function sap_site_item( $project_id, $url ) {
	return sap_site_inventory( $project_id )[ sap_norm_url( $url ) ] ?? null;
}

/**
 * Pull the whole inventory from the connector, a few pages per call (the
 * step runner calls again while 'more' is set).
 */
function sap_step_site_inventory( $project, $force = false ) {
	if ( ! sap_site_connected( $project ) ) {
		return sap_skip( 'اتصال به سایت کارفرما (افزونه رابط) تنظیم نشده — بدون آن نوع صفحات از روی آدرس حدس زده می‌شود.' );
	}
	$pid  = $project['id'];
	$ds   = sap_dataset( $pid, 'site_inventory' );
	$opt  = 'sap_inv_prog_' . $pid;
	$prog = get_option( $opt );
	if ( ! is_array( $prog ) ) {
		if ( ! $force && $ds && strtotime( $ds['fetched_at'] ) > time() - DAY_IN_SECONDS ) {
			return sap_skip( sprintf( 'فهرست محتوای سایت امروز گرفته شده (%s صفحه).', sap_num( count( (array) $ds['payload']['items'] ) ) ) );
		}
		$prog = array( 'sources' => null, 'i' => 0, 'page' => 1, 'items' => array(), 'meta' => array() );
	}
	$t0 = microtime( true );
	for ( $n = 0; $n < 6 && microtime( true ) - $t0 < 25; $n++ ) {
		$src = null === $prog['sources'] ? '' : ( $prog['sources'][ $prog['i'] ]['key'] ?? null );
		if ( null === $src ) {
			break; // All sources read.
		}
		$res = sap_site_request( $project, 'GET', 'inventory', array( 'source' => $src, 'page' => $prog['page'], 'per' => 150 ), 60 );
		if ( is_wp_error( $res ) ) {
			delete_option( $opt );
			if ( false !== strpos( $res->get_error_message(), 'قدیمی' ) || 'sap_site_404' === $res->get_error_code() ) {
				return new WP_Error( 'sap_inv_old', 'افزونه رابط روی سایت کارفرما قدیمی است؛ برای فهرست کامل محتوا نسخه ۱٫۳ لازم است (فایل seoreport-connector-1.3.0.zip).' );
			}
			return $res;
		}
		if ( null === $prog['sources'] ) {
			$prog['sources'] = array_values( array_filter( (array) ( $res['sources'] ?? array() ), function ( $s ) {
				return (int) $s['total'] > 0;
			} ) );
			$prog['meta']    = array( 'seo_plugin' => (string) ( $res['seo_plugin'] ?? '' ), 'front' => (int) ( $res['front'] ?? 0 ), 'shop' => (int) ( $res['shop'] ?? 0 ) );
			continue;
		}
		foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
			if ( empty( $it['url'] ) ) {
				continue;
			}
			$it['ctype']     = sap_inventory_ctype( $it );
			$prog['items'][] = $it;
		}
		if ( ! empty( $res['more'] ) && count( $prog['items'] ) < 20000 ) {
			++$prog['page'];
		} else {
			++$prog['i'];
			$prog['page'] = 1;
		}
	}
	$total = array_sum( array_column( (array) $prog['sources'], 'total' ) );
	if ( null !== $prog['sources'] && isset( $prog['sources'][ $prog['i'] ] ) ) {
		update_option( $opt, $prog, false );
		return array( 'more' => true, 'msg' => sprintf( 'دریافت فهرست محتوا از وردپرس: %s از %s…', sap_num( count( $prog['items'] ) ), sap_num( $total ) ) );
	}
	delete_option( $opt );
	$counts = array();
	foreach ( $prog['items'] as $it ) {
		$counts[ $it['ctype'] ] = ( $counts[ $it['ctype'] ] ?? 0 ) + 1;
	}
	sap_dataset_put( $pid, 'site_inventory', array_merge( $prog['meta'], array( 'items' => $prog['items'], 'sources' => $prog['sources'] ) ), array( 'count' => count( $prog['items'] ), 'counts' => $counts ) );
	$fixed = sap_keymap_fix_types( sap_project( $pid ) );
	$types = sap_content_types();
	$parts = array();
	foreach ( $counts as $t => $c ) {
		$parts[] = sap_num( $c ) . ' ' . ( $types[ $t ] ?? $t );
	}
	return array( 'msg' => sprintf( 'فهرست کامل محتوای وردپرس دریافت شد: %s.%s', implode( '، ', $parts ), $fixed ? ' نوع ' . sap_num( $fixed ) . ' صفحه در نقشه کلمات اصلاح شد.' : '' ) );
}

/**
 * Correct the page type (and title) of keyword-map rows from the inventory:
 * the type is a fact of the site, whatever the source of the keyword.
 *
 * @return int Rows changed.
 */
function sap_keymap_fix_types( $project ) {
	global $wpdb;
	$pid   = $project['id'];
	$inv   = sap_site_inventory( $pid );
	$front = (int) ( sap_dataset_payload( $pid, 'site_inventory' )['front'] ?? 0 );
	$n     = 0;
	foreach ( sap_keymap( $pid, true ) as $k => $m ) {
		if ( ! isset( $inv[ $k ] ) ) {
			continue;
		}
		$type = sap_inventory_page_type( $inv[ $k ]['ctype'], $inv[ $k ], $front );
		$set  = array();
		if ( $type !== $m['page_type'] ) {
			$set['page_type'] = $type;
		}
		if ( '' === $m['title'] && '' !== $inv[ $k ]['title'] ) {
			$set['title'] = mb_substr( $inv[ $k ]['title'], 0, 250 );
		}
		if ( $set ) {
			$wpdb->update( sap_table( 'keymap' ), $set, array( 'id' => $m['id'] ) );
			++$n;
		}
	}
	if ( $n ) {
		sap_keymap( $pid, true );
	}
	return $n;
}

/**
 * Inventory items that have Search Console impressions but no keyword-map
 * row yet: the keyword map is completed with their best query.
 *
 * @return int Rows added.
 */
function sap_keymap_from_inventory( $project, $limit = 400 ) {
	$pid = $project['id'];
	$inv = sap_site_inventory( $pid );
	if ( ! $inv ) {
		return 0;
	}
	$front   = (int) ( sap_dataset_payload( $pid, 'site_inventory' )['front'] ?? 0 );
	$map     = sap_keymap( $pid );
	$by_page = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
		$by_page[ sap_norm_url( $r['page'] ) ][] = $r;
	}
	$focuses = sap_focus_context( $pid );
	$cands   = array();
	foreach ( $inv as $k => $it ) {
		if ( isset( $map[ $k ] ) || empty( $by_page[ $k ] ) ) {
			continue;
		}
		$cands[ $k ] = array_sum( array_column( $by_page[ $k ], 'impressions' ) );
	}
	arsort( $cands );
	$n = 0;
	foreach ( array_slice( $cands, 0, $limit, true ) as $k => $impr ) {
		$it    = $inv[ $k ];
		$type  = sap_inventory_page_type( $it['ctype'], $it, $front );
		$focus = (string) ( $it['seo']['focus_keyword'] ?? '' );
		$g     = sap_keymap_guess( $project, $by_page[ $k ], $it['title'] . ' ' . $focus );
		if ( '' !== $focus && '' === $g['primary'] ) {
			$g['primary'] = $focus;
		}
		if ( '' === $g['primary'] ) {
			continue;
		}
		list( $fid ) = sap_focus_match( $focuses, $it['url'], $g['primary'] );
		sap_keymap_save( $pid, array(
			'url'        => $it['url'],
			'title'      => $it['title'],
			'primary_kw' => $g['primary'],
			'secondary'  => $g['secondary'],
			'intent'     => in_array( $type, array( 'category', 'product' ), true ) ? 'transactional' : ( 'article' === $type ? 'informational' : '' ),
			'page_type'  => $type,
			'focus_id'   => $fid,
			'source'     => 'auto',
		) );
		++$n;
	}
	return $n;
}

/**
 * Rich data of one page from the connector (images, product attributes,
 * Elementor-rendered HTML), cached for a few hours.
 *
 * @return array|null
 */
function sap_site_page_details( $project, $url, $fresh = false ) {
	if ( ! $project || ! sap_site_connected( $project ) ) {
		return null;
	}
	$key = 'sap_sd_' . md5( $project['id'] . '|' . sap_norm_url( $url ) );
	$hit = $fresh ? false : get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$obj = sap_site_request( $project, 'GET', 'resolve', array( 'url' => $url ) );
	if ( is_wp_error( $obj ) ) {
		return null;
	}
	set_transient( $key, $obj, 6 * HOUR_IN_SECONDS );
	return $obj;
}

/**
 * Compact facts of the WordPress object for the AI (product data, images).
 */
function sap_site_ai_facts( $obj ) {
	if ( ! is_array( $obj ) ) {
		return array();
	}
	$d   = (array) ( $obj['details'] ?? array() );
	$out = array( 'wp_type' => (string) ( $obj['type'] ?? '' ), 'focus_keyword_in_seo_plugin' => (string) ( $obj['seo']['focus_keyword'] ?? '' ) );
	$img = (array) ( $d['images'] ?? array() );
	if ( $img ) {
		$out['images'] = array(
			'count'   => count( $img ),
			'no_alt'  => count( array_filter( $img, function ( $i ) {
				return '' === trim( (string) $i['alt'] );
			} ) ),
			'alts'    => array_slice( array_values( array_filter( array_column( $img, 'alt' ) ) ), 0, 12 ),
		);
	}
	if ( ! empty( $d['product'] ) ) {
		$p                = $d['product'];
		$out['product']   = array(
			'price'       => $p['price'] . ( $p['sale'] ? ' (حراج از ' . $p['regular'] . ')' : '' ) . ' ' . $p['currency'],
			'stock'       => $p['stock'],
			'sku'         => $p['sku'],
			'type'        => $p['type'],
			'attributes'  => array_map( function ( $a ) {
				return $a['name'] . ': ' . implode( '، ', (array) $a['values'] );
			}, (array) $p['attributes'] ),
			'short_description' => mb_substr( wp_strip_all_tags( (string) $p['short'] ), 0, 600 ),
			'reviews'     => $p['reviews'],
			'rating'      => $p['rating'],
			'categories'  => $p['categories'],
			'brands'      => $p['brands'],
			'gallery'     => count( array_filter( $img, function ( $i ) {
				return 'gallery' === $i['where'];
			} ) ),
			'weight_dimensions' => trim( $p['weight'] . ' ' . $p['dimensions'] ),
		);
	}
	if ( ! empty( $d['term'] ) ) {
		$out['term'] = array(
			'items'    => $d['term']['count'],
			'parent'   => $d['term']['parent'],
			'children' => array_slice( (array) $d['term']['children'], 0, 15 ),
			'top_products' => array_slice( array_column( (array) ( $d['term']['products'] ?? array() ), 'title' ), 0, 10 ),
		);
	}
	return $out;
}
