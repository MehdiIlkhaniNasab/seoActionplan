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
function sap_site_inventory( $project_id, $refresh = false ) {
	static $cache = array();
	if ( ! $refresh && isset( $cache[ $project_id ] ) ) {
		return $cache[ $project_id ];
	}
	$cache[ $project_id ] = null; // Free the old copy before building the new one.
	global $wpdb;
	$out = array();
	// Rows live in their own dataset as compact tuples (10,000 products stay light); read it
	// directly so the decoded payload is not kept in the dataset cache too.
	$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . sap_table( 'datasets' ) . ' WHERE project_id = %d AND source = %s', $project_id, 'site_inventory_rows' ) ); // phpcs:ignore
	if ( $raw ) {
		$chunks = (array) ( sap_unpack( $raw )['chunks'] ?? array() );
		unset( $raw );
		// One chunk decoded at a time keeps the peak memory low.
		foreach ( $chunks as $c => $packed ) {
			foreach ( (array) sap_unpack( (string) $packed ) as $t ) {
				$it = sap_inventory_unpack_item( $t );
				if ( '' !== $it['url'] ) {
					$out[ sap_norm_url( $it['url'] ) ] = $it;
				}
			}
			unset( $chunks[ $c ] );
		}
	} else {
		// Inventories stored before 0.13.1.
		foreach ( (array) ( sap_dataset_payload( $project_id, 'site_inventory' )['items'] ?? array() ) as $it ) {
			if ( ! empty( $it['url'] ) ) {
				$out[ sap_norm_url( $it['url'] ) ] = sap_inventory_unpack_item( sap_inventory_pack_item( $it ) );
			}
		}
	}
	$cache[ $project_id ] = $out;
	return $out;
}

/**
 * Inventory item → compact tuple (no repeated keys in storage).
 */
function sap_inventory_pack_item( $it ) {
	$seo = (array) ( $it['seo'] ?? array() );
	$pr  = isset( $it['product'] ) ? (array) $it['product'] : null;
	return array(
		(string) ( $it['kind'] ?? '' ), (int) ( $it['id'] ?? 0 ), (string) ( $it['type'] ?? '' ), (string) ( $it['ctype'] ?? '' ),
		(string) ( $it['title'] ?? '' ), (string) ( $it['url'] ?? '' ), (string) ( $it['date'] ?? '' ), (string) ( $it['modified'] ?? '' ),
		(int) ( $it['parent'] ?? 0 ), (string) ( $it['editor'] ?? '' ), (int) ( $it['words'] ?? 0 ), (int) ( $it['images'] ?? 0 ),
		(int) ( $it['no_alt'] ?? 0 ), (string) ( $it['thumb'] ?? '' ), empty( $it['noindex'] ) ? 0 : 1, (int) ( $it['count'] ?? 0 ),
		array( (string) ( $seo['seo_title'] ?? '' ), (string) ( $seo['meta_description'] ?? '' ), (string) ( $seo['focus_keyword'] ?? '' ) ),
		null === $pr ? 0 : array( (string) ( $pr['price'] ?? '' ), (string) ( $pr['sku'] ?? '' ), (string) ( $pr['stock'] ?? '' ), (string) ( $pr['ptype'] ?? '' ), (int) ( $pr['attrs'] ?? 0 ), (int) ( $pr['gallery'] ?? 0 ), (int) ( $pr['reviews'] ?? 0 ), (float) ( $pr['rating'] ?? 0 ), (int) ( $pr['short'] ?? 0 ), array_values( (array) ( $pr['cats'] ?? array() ) ) ),
		(string) ( $it['redirect'] ?? '' ),
	);
}

/**
 * Compact tuple → inventory item. Keys are literals and common values are shared
 * strings, so 10,000 items take a fraction of the memory of decoded JSON objects.
 */
function sap_inventory_unpack_item( $t ) {
	static $v = array( '' => '', 'post' => 'post', 'term' => 'term', 'page' => 'page', 'product' => 'product', 'product_cat' => 'product_cat', 'product_tag' => 'product_tag', 'post_cat' => 'post_cat', 'post_tag' => 'post_tag', 'brand' => 'brand', 'category' => 'category', 'gutenberg' => 'gutenberg', 'elementor' => 'elementor', 'classic' => 'classic', 'instock' => 'instock', 'outofstock' => 'outofstock', 'onbackorder' => 'onbackorder', 'simple' => 'simple', 'variable' => 'variable', 'grouped' => 'grouped', 'external' => 'external' );
	$s  = function ( $x ) use ( $v ) {
		$x = (string) $x;
		return $v[ $x ] ?? $x;
	};
	$it = array(
		'kind'     => $s( $t[0] ?? '' ),
		'id'       => (int) ( $t[1] ?? 0 ),
		'type'     => $s( $t[2] ?? '' ),
		'ctype'    => $s( $t[3] ?? '' ),
		'title'    => (string) ( $t[4] ?? '' ),
		'url'      => (string) ( $t[5] ?? '' ),
		'date'     => (string) ( $t[6] ?? '' ),
		'modified' => (string) ( $t[7] ?? '' ),
		'parent'   => (int) ( $t[8] ?? 0 ),
		'editor'   => $s( $t[9] ?? '' ),
		'words'    => (int) ( $t[10] ?? 0 ),
		'images'   => (int) ( $t[11] ?? 0 ),
		'no_alt'   => (int) ( $t[12] ?? 0 ),
		'thumb'    => (string) ( $t[13] ?? '' ),
		'noindex'  => ! empty( $t[14] ),
		'count'    => (int) ( $t[15] ?? 0 ),
		'redirect' => (string) ( $t[18] ?? '' ),
		'seo'      => array(
			'seo_title'        => (string) ( $t[16][0] ?? '' ),
			'meta_description' => (string) ( $t[16][1] ?? '' ),
			'focus_keyword'    => (string) ( $t[16][2] ?? '' ),
		),
	);
	if ( ! empty( $t[17] ) && is_array( $t[17] ) ) {
		$p             = $t[17];
		$it['product'] = array(
			'price'   => (string) $p[0],
			'sku'     => (string) $p[1],
			'stock'   => $s( $p[2] ),
			'ptype'   => $s( $p[3] ),
			'attrs'   => (int) $p[4],
			'gallery' => (int) $p[5],
			'reviews' => (int) $p[6],
			'rating'  => (float) $p[7],
			'short'   => (int) $p[8],
			'cats'    => (array) ( $p[9] ?? array() ),
		);
	}
	return $it;
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
	// A run left half-way more than a day ago starts over (the site has changed since).
	if ( is_array( $prog ) && ( ! isset( $prog['per'] ) || (int) ( $prog['at'] ?? 0 ) < time() - DAY_IN_SECONDS ) ) {
		sap_inventory_chunks_clear( $pid, $prog );
		$prog = null;
	}
	if ( ! is_array( $prog ) ) {
		if ( ! $force && $ds && strtotime( $ds['fetched_at'] ) > time() - DAY_IN_SECONDS ) {
			return sap_skip( sprintf( 'فهرست محتوای سایت امروز گرفته شده (%s صفحه).', sap_num( (int) ( $ds['meta']['count'] ?? 0 ) ) ) );
		}
		// Items are kept in small chunks (one option per page) so a 10,000-product store
		// never rewrites one huge option on every call.
		$prog = array( 'sources' => null, 'i' => 0, 'done' => 0, 'per' => 100, 'count' => 0, 'counts' => array(), 'chunks' => 0, 'meta' => array(), 'started' => time() );
	}
	// Short calls: a host or proxy that cuts requests at 30–60 s never kills the step,
	// and the next call goes on from the same point.
	$t0   = microtime( true );
	$errs = 0;
	while ( microtime( true ) - $t0 < 15 ) {
		$src = null === $prog['sources'] ? '' : ( $prog['sources'][ $prog['i'] ]['key'] ?? null );
		if ( null === $src ) {
			break; // All sources read.
		}
		$per = (int) $prog['per'];
		$t1  = microtime( true );
		$res = sap_site_request( $project, 'GET', 'inventory', array( 'source' => $src, 'page' => (int) floor( $prog['done'] / $per ) + 1, 'per' => $per ), 45 );
		if ( is_wp_error( $res ) ) {
			if ( false !== strpos( $res->get_error_message(), 'قدیمی' ) || 'sap_site_404' === $res->get_error_code() ) {
				sap_inventory_chunks_clear( $pid, $prog );
				delete_option( $opt );
				return new WP_Error( 'sap_inv_old', 'افزونه رابط روی سایت کارفرما قدیمی است؛ برای فهرست کامل محتوا نسخه ۱٫۳ لازم است (فایل seoreport-connector-1.3.0.zip).' );
			}
			// Slow site (timeout / 5xx): smaller pages and try again; the progress is kept.
			if ( $per > 25 && $errs < 2 ) {
				$prog['per'] = (int) ( $per / 2 );
				++$errs;
				continue;
			}
			$prog['at'] = time();
			update_option( $opt, $prog, false );
			return new WP_Error( 'sap_inv_slow', sprintf( 'سایت کارفرما دیر جواب داد (%s). %s صفحه تا اینجا دریافت شده و ذخیره است؛ دوباره اجرا کنید تا از همین نقطه ادامه دهد.', $res->get_error_message(), sap_num( $prog['count'] ) ) );
		}
		if ( null === $prog['sources'] ) {
			$prog['sources'] = array_values( array_filter( (array) ( $res['sources'] ?? array() ), function ( $s ) {
				return (int) $s['total'] > 0;
			} ) );
			$prog['meta']    = array( 'seo_plugin' => (string) ( $res['seo_plugin'] ?? '' ), 'front' => (int) ( $res['front'] ?? 0 ), 'shop' => (int) ( $res['shop'] ?? 0 ) );
			continue;
		}
		$items = array();
		foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
			if ( ! empty( $it['url'] ) ) {
				$it['ctype'] = sap_inventory_ctype( $it );
				$items[]     = sap_inventory_pack_item( $it );
				$prog['counts'][ $it['ctype'] ] = ( $prog['counts'][ $it['ctype'] ] ?? 0 ) + 1;
			}
		}
		if ( $items ) {
			update_option( 'sap_inv_chunk_' . $pid . '_' . $prog['chunks'], sap_pack( $items ), false );
			++$prog['chunks'];
			$prog['count'] += count( $items );
		}
		$got           = count( (array) ( $res['items'] ?? array() ) );
		$prog['done'] += $got;
		if ( ! empty( $res['more'] ) && $got > 0 && $prog['count'] < 20000 ) {
			// Pages that take long: halve the page size (the offset stays a multiple of it).
			if ( microtime( true ) - $t1 > 12 && $per > 25 ) {
				$prog['per'] = (int) ( $per / 2 );
			}
		} else {
			++$prog['i'];
			$prog['done'] = 0;
		}
	}
	$total = array_sum( array_column( (array) $prog['sources'], 'total' ) );
	if ( null === $prog['sources'] || isset( $prog['sources'][ $prog['i'] ] ) ) {
		$prog['at'] = time();
		update_option( $opt, $prog, false );
		$cur  = $prog['sources'][ $prog['i'] ]['label'] ?? '';
		$pct  = $total ? min( 99, (int) floor( $prog['count'] / $total * 100 ) ) : 0;
		$rate = $prog['count'] / max( 1, time() - $prog['started'] );
		$left = $rate > 0 && $total > $prog['count'] ? (int) ceil( ( $total - $prog['count'] ) / $rate / 60 ) : 0;
		return array(
			'more' => true,
			'pct'  => $pct,
			'msg'  => sprintf( 'دریافت فهرست محتوا از وردپرس: %s از %s (%s٪)%s%s', sap_num( $prog['count'] ), sap_num( $total ), sap_num( $pct ), '' !== $cur ? ' — ' . $cur : '', $left ? ' · حدود ' . sap_num( $left ) . ' دقیقه مانده' : '' ),
		);
	}
	// The stored chunks (compact tuples, already packed) become the rows dataset as they are.
	$chunks = array();
	for ( $c = 0; $c < $prog['chunks']; $c++ ) {
		$chunks[] = (string) get_option( 'sap_inv_chunk_' . $pid . '_' . $c, '' );
	}
	$counts = (array) $prog['counts'];
	$count  = (int) $prog['count'];
	sap_inventory_chunks_clear( $pid, $prog );
	delete_option( $opt );
	// Rows apart from the small meta dataset that many screens read.
	sap_dataset_put( $pid, 'site_inventory_rows', array( 'chunks' => $chunks ), array( 'count' => $count ) );
	sap_dataset_put( $pid, 'site_inventory', array_merge( $prog['meta'], array( 'sources' => $prog['sources'] ) ), array( 'count' => $count, 'counts' => $counts ) );
	unset( $chunks, $prog );
	sap_site_inventory( $pid, true );
	$fixed = sap_keymap_fix_types( sap_project( $pid ) );
	$types = sap_content_types();
	$parts = array();
	foreach ( $counts as $t => $c ) {
		$parts[] = sap_num( $c ) . ' ' . ( $types[ $t ] ?? $t );
	}
	return array( 'msg' => sprintf( 'فهرست کامل محتوای وردپرس دریافت شد: %s.%s', implode( '، ', $parts ), $fixed ? ' نوع ' . sap_num( $fixed ) . ' صفحه در نقشه کلمات اصلاح شد.' : '' ) );
}

/**
 * Remove the stored chunks of an inventory run.
 */
function sap_inventory_chunks_clear( $project_id, $prog ) {
	for ( $c = 0; $c < (int) ( $prog['chunks'] ?? 0 ); $c++ ) {
		delete_option( 'sap_inv_chunk_' . $project_id . '_' . $c );
	}
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
