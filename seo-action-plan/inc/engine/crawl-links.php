<?php
/**
 * Deep crawl data from Screaming Frog beyond Internal:All, the way technical
 * SEOs work with it:
 *  - Bulk Export › Links › All Inlinks: every internal link with its «Link Position»
 *    (Navigation / Header / Footer / Aside / Content). Only body (Content) links count
 *    as editorial internal links; menus, sidebars and footers are site-wide boilerplate.
 *    Broken links and links to redirects are kept from every position.
 *  - Reports › Redirects › All Redirects: chains, loops and where they end.
 *  - The site's redirect manager list (Rank Math / Redirection / Yoast via the connector)
 *    and its 404 monitor.
 * Files are read as streams (plain or gzip) and only aggregates are stored.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Open a CSV export (plain or gzip) as a stream and find its header row.
 *
 * @return array|WP_Error {fh, delim, head: string[] lowercase}
 */
function sap_sf_csv_open( $path ) {
	$fh = fopen( 'compress.zlib://' . $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return new WP_Error( 'sap_sf_file', 'فایل قابل خواندن نیست.' );
	}
	$first = (string) fgets( $fh, 65536 );
	if ( 0 === strpos( $first, "\xFF\xFE" ) || 0 === strpos( $first, "\xFE\xFF" ) ) {
		fclose( $fh ); // phpcs:ignore
		return new WP_Error( 'sap_sf_enc', 'فایل UTF-16 است؛ از نسخه جدید ایجنت سکان استفاده کنید یا خروجی را با UTF-8 بگیرید.' );
	}
	$delim = ',';
	foreach ( array( ';', "\t" ) as $d ) {
		if ( substr_count( $first, $d ) > substr_count( $first, $delim ) ) {
			$delim = $d;
		}
	}
	$norm = function ( $h ) {
		return strtolower( trim( preg_replace( '/^(?:\xEF\xBB\xBF)+/', '', (string) $h ), " \t\r\n\"'" ) );
	};
	$head = array_map( $norm, str_getcsv( $first, $delim, '"', '\\' ) );
	// Older exports put a title row («All Inlinks») above the header.
	if ( count( array_filter( $head ) ) < 2 ) {
		$head = array_map( $norm, (array) fgetcsv( $fh, 0, $delim, '"', '\\' ) );
	}
	return array( 'fh' => $fh, 'delim' => $delim, 'head' => $head );
}

/**
 * What a Screaming Frog CSV is, from its columns.
 *
 * @return string internal|inlinks|redirects|''
 */
function sap_sf_kind( $head ) {
	$has = function ( $c ) use ( $head ) {
		return in_array( $c, $head, true );
	};
	if ( $has( 'source' ) && $has( 'destination' ) ) {
		return 'inlinks';
	}
	if ( $has( 'number of redirects' ) || ( $has( 'final address' ) && $has( 'address' ) ) || $has( 'redirect chain' ) ) {
		return 'redirects';
	}
	if ( $has( 'address' ) || $has( 'url' ) ) {
		return 'internal';
	}
	return '';
}

/**
 * Ingest any supported Screaming Frog file into the project.
 *
 * @return array|WP_Error {kind, count, msg}
 */
function sap_sf_ingest_file( $project_id, $path, $name, $source = 'agent', $meta = array() ) {
	$o = sap_sf_csv_open( $path );
	if ( is_wp_error( $o ) ) {
		return $o;
	}
	$kind = sap_sf_kind( $o['head'] );
	fclose( $o['fh'] ); // phpcs:ignore
	if ( 'inlinks' === $kind ) {
		return sap_sf_links_ingest( $project_id, $path, $meta );
	}
	if ( 'redirects' === $kind ) {
		return sap_sf_redirects_ingest( $project_id, $path );
	}
	if ( 'internal' === $kind ) {
		$rows = sap_crawl_rows_from_file( $path, $name );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$kept = sap_sf_store_crawl( $project_id, $rows, $source, $meta );
		return array( 'kind' => 'internal', 'count' => count( $rows ), 'kept' => $kept, 'msg' => sprintf( 'خزش با %s آدرس دریافت شد', sap_num( count( $rows ) ) ) );
	}
	return new WP_Error( 'sap_sf_kind', 'نوع فایل شناخته نشد. فایل‌های قابل قبول: internal_all.csv، خروجی Bulk Export › Links › All Inlinks و گزارش Reports › Redirects › All Redirects (زبان انگلیسی).' );
}

/**
 * Is a link position editorial (body content)?
 */
function sap_sf_is_content_pos( $pos ) {
	$pos = strtolower( trim( (string) $pos ) );
	return '' === $pos || 'content' === $pos;
}

/**
 * Stream the All Inlinks export into aggregates (dataset «crawl_links»).
 */
function sap_sf_links_ingest( $project_id, $path, $meta = array() ) {
	wp_raise_memory_limit( 'admin' );
	$o = sap_sf_csv_open( $path );
	if ( is_wp_error( $o ) ) {
		return $o;
	}
	$h   = array_flip( $o['head'] );
	$col = function ( $names ) use ( $h ) {
		foreach ( (array) $names as $n ) {
			if ( isset( $h[ $n ] ) ) {
				return $h[ $n ];
			}
		}
		return null;
	};
	$c = array(
		'type'   => $col( 'type' ),
		'src'    => $col( 'source' ),
		'dst'    => $col( 'destination' ),
		'anchor' => $col( array( 'anchor', 'anchor text' ) ),
		'alt'    => $col( 'alt text' ),
		'code'   => $col( 'status code' ),
		'follow' => $col( 'follow' ),
		'pos'    => $col( 'link position' ),
	);
	$has_pos = null !== $c['pos'];
	$ids     = array(); // norm url => int.
	$urls    = array(); // int => original url.
	$id      = function ( $u ) use ( &$ids, &$urls ) {
		$k = sap_norm_url( $u );
		if ( ! isset( $ids[ $k ] ) ) {
			$ids[ $k ]           = count( $urls );
			$urls[ $ids[ $k ] ] = $u;
		}
		return $ids[ $k ];
	};
	$cin = array(); // dst => [src => 1] body links.
	$nin = array(); // dst => [src => 1] boilerplate links.
	$out = array(); // src => [dst => 1] body links.
	$anc = array(); // dst => [anchor => n].
	$nf  = array(); // dst => n nofollow internal links.
	$nav_n = array(); // dst => n working boilerplate links (agent summary rows).
	$broken = array();
	$redir  = array();
	$imgs   = array();
	$rows   = 0;
	$codes  = array();
	while ( ( $l = fgetcsv( $o['fh'], 0, $o['delim'], '"', '\\' ) ) !== false ) {
		if ( count( $l ) < 3 ) {
			continue;
		}
		++$rows;
		if ( $rows > 3000000 ) {
			break;
		}
		$type = strtolower( (string) ( null !== $c['type'] ? ( $l[ $c['type'] ] ?? '' ) : 'hyperlink' ) );
		$src  = (string) ( $l[ $c['src'] ] ?? '' );
		$dst  = (string) ( $l[ $c['dst'] ] ?? '' );
		if ( '' === $dst ) {
			continue;
		}
		$code = null !== $c['code'] ? (int) ( $l[ $c['code'] ] ?? 0 ) : 0;
		$pos  = $has_pos ? (string) ( $l[ $c['pos'] ] ?? '' ) : '';
		$a    = trim( (string) ( null !== $c['anchor'] ? ( $l[ $c['anchor'] ] ?? '' ) : '' ) );
		if ( '' === $src && 'navcount' !== $type ) {
			continue;
		}
		if ( 'navcount' === $type ) {
			// Summary row of the Sokan agent: working menu/footer links to a page (filtered out to save space).
			$d          = $id( $dst );
			$nav_n[ $d ] = ( $nav_n[ $d ] ?? 0 ) + (int) $a;
			continue;
		}
		$is_img = in_array( $type, array( 'image', 'img' ), true );
		if ( $is_img ) {
			if ( $code >= 400 && count( $imgs ) < 1000 ) {
				$imgs[] = array( 's' => $src, 'd' => $dst, 'c' => $code );
			}
			continue;
		}
		if ( ! in_array( $type, array( 'hyperlink', 'ahref', 'a', '' ), true ) ) {
			continue; // Canonicals, CSS, JS, hreflang…
		}
		$s = $id( $src );
		$d = $id( $dst );
		if ( $s === $d ) {
			continue;
		}
		$codes[ $code ] = ( $codes[ $code ] ?? 0 ) + 1;
		if ( $code >= 400 ) {
			if ( count( $broken ) < 4000 ) {
				$broken[] = array( 's' => $s, 'd' => $d, 'a' => mb_substr( $a, 0, 80 ), 'c' => $code, 'p' => strtolower( $pos ) );
			}
		} elseif ( $code >= 300 ) {
			if ( count( $redir ) < 4000 ) {
				$redir[] = array( 's' => $s, 'd' => $d, 'a' => mb_substr( $a, 0, 80 ), 'c' => $code, 'p' => strtolower( $pos ) );
			}
		}
		if ( null !== $c['follow'] && 'false' === strtolower( (string) ( $l[ $c['follow'] ] ?? '' ) ) ) {
			$nf[ $d ] = ( $nf[ $d ] ?? 0 ) + 1;
		}
		if ( sap_sf_is_content_pos( $pos ) ) {
			$cin[ $d ][ $s ] = 1;
			$out[ $s ][ $d ] = 1;
			if ( '' !== $a ) {
				$ak               = mb_strtolower( mb_substr( $a, 0, 80 ) );
				$anc[ $d ][ $ak ] = ( $anc[ $d ][ $ak ] ?? 0 ) + 1;
			}
		} else {
			$nin[ $d ][ $s ] = 1;
		}
	}
	fclose( $o['fh'] ); // phpcs:ignore
	if ( ! $rows ) {
		return new WP_Error( 'sap_sf_empty', 'فایل لینک‌ها ردیفی نداشت.' );
	}
	$pages = array();
	foreach ( $urls as $i => $u ) {
		$ci = isset( $cin[ $i ] ) ? count( $cin[ $i ] ) : 0;
		$ni = ( isset( $nin[ $i ] ) ? count( $nin[ $i ] ) : 0 ) + (int) ( $nav_n[ $i ] ?? 0 );
		$co = isset( $out[ $i ] ) ? count( $out[ $i ] ) : 0;
		if ( ! $ci && ! $ni && ! $co ) {
			continue;
		}
		$a = $anc[ $i ] ?? array();
		arsort( $a );
		$pages[ sap_norm_url( $u ) ] = array(
			'u'  => $u,
			'ci' => $ci,
			'ni' => $ni,
			'co' => $co,
			'nf' => (int) ( $nf[ $i ] ?? 0 ),
			'a'  => array_slice( $a, 0, 6, true ),
			's'  => array_map( function ( $x ) use ( $urls ) {
				return $urls[ $x ];
			}, array_slice( array_keys( $cin[ $i ] ?? array() ), 0, 8 ) ),
		);
	}
	$expand = function ( $list ) use ( $urls ) {
		return array_map( function ( $x ) use ( $urls ) {
			$x['s'] = $urls[ $x['s'] ];
			$x['d'] = $urls[ $x['d'] ];
			return $x;
		}, $list );
	};
	sap_dataset_put( $project_id, 'crawl_links', array(
		'pages'    => $pages,
		'broken'   => $expand( $broken ),
		'redirect' => $expand( $redir ),
		'images'   => $imgs,
	), array_merge( (array) $meta, array( 'rows' => $rows, 'positions' => $has_pos, 'pages' => count( $pages ), 'broken' => count( $broken ), 'redirect' => count( $redir ), 'codes' => $codes ) ) );
	return array( 'kind' => 'inlinks', 'count' => $rows, 'msg' => sprintf( 'لینک‌های داخلی دریافت شد: %s لینک، %s لینک شکسته، %s لینک به ریدایرکت', sap_num( $rows ), sap_num( count( $broken ) ), sap_num( count( $redir ) ) ) );
}

/**
 * The All Redirects report (dataset «crawl_redirects»).
 */
function sap_sf_redirects_ingest( $project_id, $path ) {
	$o = sap_sf_csv_open( $path );
	if ( is_wp_error( $o ) ) {
		return $o;
	}
	$h     = array_flip( $o['head'] );
	$get   = function ( $l, $names ) use ( $h ) {
		foreach ( (array) $names as $n ) {
			if ( isset( $h[ $n ] ) ) {
				return trim( (string) ( $l[ $h[ $n ] ] ?? '' ) );
			}
		}
		return '';
	};
	$items = array();
	while ( ( $l = fgetcsv( $o['fh'], 0, $o['delim'], '"', '\\' ) ) !== false ) {
		$addr = $get( $l, array( 'address', 'url' ) );
		if ( '' === $addr ) {
			continue;
		}
		$items[] = array(
			'from'  => $addr,
			'src'   => $get( $l, array( 'source' ) ),
			'to'    => $get( $l, array( 'final address', 'redirect url' ) ),
			'hops'  => (int) $get( $l, array( 'number of redirects', 'redirects' ) ),
			'loop'  => 'true' === strtolower( $get( $l, array( 'redirect loop', 'loop' ) ) ),
			'code'  => (int) $get( $l, array( 'final status code', 'final content status code' ) ),
			'type'  => $get( $l, array( 'chain type', 'redirect type' ) ),
			'temp'  => 'true' === strtolower( $get( $l, array( 'temp redirect in chain' ) ) ),
		);
		if ( count( $items ) >= 10000 ) {
			break;
		}
	}
	fclose( $o['fh'] ); // phpcs:ignore
	sap_dataset_put( $project_id, 'crawl_redirects', array( 'items' => $items ), array( 'count' => count( $items ) ) );
	return array( 'kind' => 'redirects', 'count' => count( $items ), 'msg' => sprintf( 'گزارش ریدایرکت‌ها دریافت شد (%s مورد)', sap_num( count( $items ) ) ) );
}

/**
 * Fetch the redirect manager list and 404 monitor from the site (dataset «site_redirects»).
 *
 * @return array|WP_Error
 */
function sap_site_redirects_sync( $project ) {
	if ( ! sap_site_connected( $project ) ) {
		return new WP_Error( 'sap_site', 'افزونه رابط به این پروژه وصل نیست.' );
	}
	$r = sap_site_request( $project, 'GET', 'redirects', array(), 90 );
	if ( is_wp_error( $r ) ) {
		return 404 === (int) ( $r->get_error_data()['status'] ?? 0 ) ? new WP_Error( 'sap_site_old', 'افزونه رابط سایت قدیمی است؛ نسخه ۱.۶ به بعد را نصب کنید.' ) : $r;
	}
	sap_dataset_put( $project['id'], 'site_redirects', array( 'home' => (string) ( $r['home'] ?? '' ), 'items' => (array) ( $r['items'] ?? array() ), 'logs' => (array) ( $r['logs'] ?? array() ) ), array( 'plugins' => (array) ( $r['plugins'] ?? array() ), 'count' => count( (array) ( $r['items'] ?? array() ) ) ) );
	return $r;
}

/**
 * Redirects seen in the crawl, followed hop by hop: chains, loops and final codes.
 *
 * @return array[] {from, to, hops, loop, final_code, codes[], temp}
 */
function sap_redirect_chains( $crawl_rows, $report = array() ) {
	$by = array();
	foreach ( (array) $crawl_rows as $r ) {
		$by[ sap_norm_url( $r['url'] ) ] = $r;
	}
	$out = array();
	foreach ( (array) $crawl_rows as $r ) {
		if ( $r['code'] < 300 || $r['code'] >= 400 || '' === (string) $r['redirect'] ) {
			continue;
		}
		$seen  = array( sap_norm_url( $r['url'] ) => true );
		$codes = array( (int) $r['code'] );
		$cur   = (string) $r['redirect'];
		$loop  = false;
		$final = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$k = sap_norm_url( $cur );
			if ( isset( $seen[ $k ] ) ) {
				$loop = true;
				break;
			}
			$seen[ $k ] = true;
			$n          = $by[ $k ] ?? null;
			if ( ! $n ) {
				break; // Not crawled: final unknown.
			}
			if ( $n['code'] >= 300 && $n['code'] < 400 && '' !== (string) $n['redirect'] ) {
				$codes[] = (int) $n['code'];
				$cur     = (string) $n['redirect'];
				continue;
			}
			$final = (int) $n['code'];
			break;
		}
		$out[ sap_norm_url( $r['url'] ) ] = array( 'from' => $r['url'], 'to' => $cur, 'hops' => count( $codes ), 'loop' => $loop, 'final_code' => $final, 'codes' => $codes, 'temp' => (bool) array_intersect( $codes, array( 302, 303, 307 ) ) );
	}
	foreach ( (array) $report as $it ) {
		$k = sap_norm_url( $it['from'] );
		if ( ! isset( $out[ $k ] ) || $it['hops'] > $out[ $k ]['hops'] || $it['loop'] ) {
			$out[ $k ] = array( 'from' => $it['from'], 'to' => $it['to'], 'hops' => max( 1, (int) $it['hops'] ), 'loop' => (bool) $it['loop'], 'final_code' => (int) $it['code'], 'codes' => $out[ $k ]['codes'] ?? array(), 'temp' => (bool) $it['temp'] || ( $out[ $k ]['temp'] ?? false ) );
		}
	}
	return $out;
}

/**
 * Junk 404 paths (bots probing) that need no redirect.
 */
function sap_is_junk_404( $url ) {
	$p = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	return (bool) preg_match( '#(\.php|\.env|\.git|\.sql|\.bak|\.zip|wp-admin|wp-login|xmlrpc|wp-includes|/cgi-bin|/\.well-known|/vendor/|/ads\.txt|/apple-touch|\.map$|/feed/?$)#', $p );
}

/**
 * The redirect & removed-pages audit: every group with its rows and the fix.
 *
 * @return array {have: string[], groups: array[], stats}
 */
function sap_redirect_audit( $project ) {
	$pid    = $project['id'];
	$crawl  = (array) ( sap_dataset_payload( $pid, 'crawl' )['rows'] ?? array() );
	$links  = sap_dataset_payload( $pid, 'crawl_links' );
	$report = (array) ( sap_dataset_payload( $pid, 'crawl_redirects' )['items'] ?? array() );
	$site   = sap_dataset_payload( $pid, 'site_redirects' );
	$gsc    = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$gsc[ sap_norm_url( $r['page'] ?? '' ) ] = $r;
	}
	$by = array();
	foreach ( $crawl as $r ) {
		$by[ sap_norm_url( $r['url'] ) ] = $r;
	}
	$chains = sap_redirect_chains( $crawl, $report );
	$clicks = function ( $u ) use ( $gsc ) {
		$g = $gsc[ sap_norm_url( $u ) ] ?? null;
		return array( (int) ( $g['clicks'] ?? 0 ), (int) ( $g['impressions'] ?? 0 ) );
	};
	$groups = array();

	// 1) Internal links that point to redirects: grouped by destination.
	if ( $links ) {
		$g = array();
		foreach ( (array) $links['redirect'] as $l ) {
			$k = sap_norm_url( $l['d'] );
			if ( ! isset( $g[ $k ] ) ) {
				$ch      = $chains[ $k ] ?? null;
				$g[ $k ] = array( 'url' => $l['d'], 'to' => $ch ? $ch['to'] : (string) ( $by[ $k ]['redirect'] ?? '' ), 'code' => $l['c'], 'n' => 0, 'body' => 0, 'sources' => array() );
			}
			++$g[ $k ]['n'];
			if ( sap_sf_is_content_pos( $l['p'] ) ) {
				++$g[ $k ]['body'];
			}
			if ( count( $g[ $k ]['sources'] ) < 6 ) {
				$g[ $k ]['sources'][] = array( 'url' => $l['s'], 'anchor' => $l['a'], 'pos' => $l['p'] );
			}
		}
		uasort( $g, function ( $a, $b ) {
			return $b['n'] <=> $a['n'];
		} );
		$groups['links_to_redirect'] = array(
			'title' => 'لینک‌های داخلی که به ریدایرکت می‌روند',
			'why'   => 'هر لینک به آدرس ریدایرکت‌شده یک درخواست اضافه برای گوگل و کاربر است و بخشی از اعتبار لینک در مسیر کم می‌شود. لینک را در همان صفحه مبدا مستقیم به آدرس نهایی عوض کنید (لینک‌های منو/فوتر یک بار در قالب اصلاح می‌شوند).',
			'sev'   => 'warn',
			'items' => array_values( $g ),
		);
	} else {
		$items = array();
		foreach ( $chains as $c ) {
			$items[] = array( 'url' => $c['from'], 'to' => $c['to'], 'code' => $c['codes'][0] ?? 301, 'n' => 0, 'body' => 0, 'sources' => array() );
		}
		if ( $items ) {
			$groups['links_to_redirect'] = array(
				'title' => 'آدرس‌های ریدایرکت‌شده‌ای که در خزش پیدا شدند',
				'why'   => 'خزنده از لینک‌های داخلی به این آدرس‌ها رسیده است. برای دیدن صفحه و انکر هر لینک، ایجنت جدید را اجرا کنید (خروجی All Inlinks).',
				'sev'   => 'warn',
				'items' => $items,
			);
		}
	}

	// 2) Broken internal links (4xx/5xx), grouped by destination.
	$g = array();
	foreach ( (array) ( $links['broken'] ?? array() ) as $l ) {
		$k = sap_norm_url( $l['d'] );
		if ( ! isset( $g[ $k ] ) ) {
			list( $cl, $im ) = $clicks( $l['d'] );
			$g[ $k ]         = array( 'url' => $l['d'], 'code' => $l['c'], 'n' => 0, 'body' => 0, 'sources' => array(), 'clicks' => $cl, 'impr' => $im );
		}
		++$g[ $k ]['n'];
		if ( sap_sf_is_content_pos( $l['p'] ) ) {
			++$g[ $k ]['body'];
		}
		if ( count( $g[ $k ]['sources'] ) < 6 ) {
			$g[ $k ]['sources'][] = array( 'url' => $l['s'], 'anchor' => $l['a'], 'pos' => $l['p'] );
		}
	}
	if ( ! $links ) {
		foreach ( $crawl as $r ) {
			if ( $r['code'] >= 400 ) {
				list( $cl, $im ) = $clicks( $r['url'] );
				$g[ sap_norm_url( $r['url'] ) ] = array( 'url' => $r['url'], 'code' => $r['code'], 'n' => (int) $r['inlinks'], 'body' => 0, 'sources' => array(), 'clicks' => $cl, 'impr' => $im );
			}
		}
	}
	if ( $g ) {
		uasort( $g, function ( $a, $b ) {
			return $b['n'] <=> $a['n'];
		} );
		$groups['broken'] = array(
			'title' => 'لینک‌های شکسته (به صفحات 404 / 410 / 5xx)',
			'why'   => 'کاربر به بن‌بست می‌رسد و گوگل بودجه خزش را هدر می‌دهد. در صفحه مبدا لینک را به صفحه درست عوض یا حذف کنید؛ اگر آدرس مقصد کلیک/بک‌لینک داشته، ریدایرکت ۳۰۱ به مرتبط‌ترین صفحه بگذارید.',
			'sev'   => 'bad',
			'items' => array_values( $g ),
		);
	}

	// 3) Chains and loops.
	$items = array_values( array_filter( $chains, function ( $c ) {
		return $c['hops'] >= 2 || $c['loop'];
	} ) );
	if ( $items ) {
		$groups['chains'] = array(
			'title' => 'زنجیره و حلقه ریدایرکت',
			'why'   => 'گوگل چند پرش را دنبال می‌کند ولی هر پرش کندی و ریسک دارد؛ حلقه یعنی صفحه هرگز باز نمی‌شود. در افزونه ریدایرکت، آدرس اول را مستقیم به مقصد نهایی ببرید و لینک‌های داخلی را هم به مقصد نهایی عوض کنید.',
			'sev'   => 'bad',
			'items' => $items,
		);
	}

	// 4) Redirects that end on an error page.
	$items = array_values( array_filter( $chains, function ( $c ) {
		return $c['final_code'] >= 400;
	} ) );
	if ( $items ) {
		$groups['dead_end'] = array(
			'title' => 'ریدایرکت به صفحه خطا',
			'why'   => 'ریدایرکت به صفحه‌ای می‌رود که خودش 404/410 است؛ اعتبار آدرس قدیمی کاملاً از دست می‌رود. مقصد را به یک صفحه زنده و مرتبط عوض کنید.',
			'sev'   => 'bad',
			'items' => $items,
		);
	}

	// 5) Temporary redirects.
	$temp = array();
	foreach ( $chains as $c ) {
		if ( $c['temp'] ) {
			$tc = array_values( array_intersect( $c['codes'], array( 302, 303, 307 ) ) );
			$temp[ sap_norm_url( $c['from'] ) ] = array( 'url' => $c['from'], 'to' => $c['to'], 'code' => $tc[0] ?? 302, 'plugin' => '' );
		}
	}
	foreach ( (array) ( $site['items'] ?? array() ) as $it ) {
		if ( $it['on'] && in_array( (int) $it['code'], array( 302, 303, 307 ), true ) ) {
			$temp[ sap_norm_url( $it['src_url'] ?: $it['src'] ) ] = array( 'url' => $it['src_url'] ?: $it['src'], 'to' => $it['to_url'], 'code' => $it['code'], 'plugin' => $it['plugin'], 'hits' => $it['hits'] );
		}
	}
	if ( $temp ) {
		$groups['temp'] = array(
			'title' => 'ریدایرکت موقت (302/307) به‌جای دائمی',
			'why'   => 'ریدایرکت موقت به گوگل می‌گوید آدرس قدیمی برمی‌گردد، پس آدرس قدیمی در ایندکس می‌ماند و اعتبار کامل منتقل نمی‌شود. اگر جابه‌جایی دائمی است، نوع را ۳۰۱ کنید.',
			'sev'   => 'warn',
			'items' => array_values( $temp ),
		);
	}

	// 6) From the redirect manager list.
	if ( $site ) {
		$hide = array();
		$home = 0;
		$home_items = array();
		$noto = array();
		$gone = array();
		$bad  = array();
		$all  = 0;
		$homk = sap_norm_url( trailingslashit( (string) ( $site['home'] ?? '' ) ?: $project['site_url'] ) );
		foreach ( (array) $site['items'] as $it ) {
			if ( ! $it['on'] ) {
				continue;
			}
			++$all;
			$code = (int) $it['code'];
			if ( in_array( $code, array( 410, 451 ), true ) ) {
				list( $cl, $im ) = $clicks( $it['src_url'] );
				$gone[]          = array( 'url' => $it['src_url'] ?: $it['src'], 'code' => $code, 'plugin' => $it['plugin'], 'hits' => $it['hits'], 'clicks' => $cl, 'impr' => $im, 'live' => $it['src_live'] );
				continue;
			}
			if ( $it['src_live'] ) {
				$hide[] = array( 'url' => $it['src_url'], 'to' => $it['to_url'], 'code' => $code, 'plugin' => $it['plugin'], 'hits' => $it['hits'] );
			}
			if ( '' === $it['to_url'] ) {
				$noto[] = array( 'url' => $it['src_url'] ?: $it['src'], 'code' => $code, 'plugin' => $it['plugin'] );
				continue;
			}
			$tk = sap_norm_url( $it['to_url'] );
			if ( $tk === $homk ) {
				++$home;
				$home_items[] = array( 'url' => $it['src_url'] ?: $it['src'], 'to' => $it['to_url'], 'code' => $code, 'plugin' => $it['plugin'], 'hits' => $it['hits'] );
			}
			$tr = $by[ $tk ] ?? null;
			if ( $tr && ( $tr['code'] >= 400 || ( $tr['code'] >= 300 && $tr['code'] < 400 ) || ( 200 === $tr['code'] && ! $tr['idx'] ) ) ) {
				$bad[] = array( 'url' => $it['src_url'] ?: $it['src'], 'to' => $it['to_url'], 'code' => $code, 'plugin' => $it['plugin'], 'why' => $tr['code'] >= 400 ? 'مقصد ' . $tr['code'] . ' است' : ( $tr['code'] >= 300 ? 'مقصد خودش ریدایرکت می‌شود (زنجیره)' : 'مقصد noindex / کنونیکال‌شده است' ) );
			}
		}
		if ( $hide ) {
			$groups['hides_live'] = array(
				'title' => 'ریدایرکت روی صفحه‌ای که هنوز منتشر است',
				'why'   => 'برای این آدرس‌ها در افزونه ریدایرکت قانون هست، ولی همان آدرس هنوز یک صفحه منتشرشده در وردپرس است؛ کاربر و گوگل هرگز آن صفحه را نمی‌بینند و محتوایش هدر است. یا ریدایرکت را حذف کنید یا صفحه را پیش‌نویس/ادغام کنید.',
				'sev'   => 'bad',
				'items' => $hide,
			);
		}
		if ( $bad ) {
			$groups['bad_target'] = array(
				'title' => 'قانون ریدایرکت با مقصد نادرست',
				'why'   => 'مقصد این قانون‌ها طبق خزش خطا، ریدایرکت یا غیرقابل‌ایندکس است. مقصد را به آدرس نهایی و قابل‌ایندکس عوض کنید.',
				'sev'   => 'bad',
				'items' => $bad,
			);
		}
		if ( $noto ) {
			$groups['no_target'] = array(
				'title' => 'قانون ریدایرکت بدون مقصد',
				'why'   => 'این قانون‌ها مقصدی ندارند و ممکن است خطا یا صفحه خالی برگردانند.',
				'sev'   => 'warn',
				'items' => $noto,
			);
		}
		if ( $home >= 5 && $home >= 0.2 * max( 1, $all ) ) {
			$groups['to_home'] = array(
				'title' => sprintf( '%s ریدایرکت به صفحه اصلی', sap_num( $home ) ),
				'why'   => 'گوگل ریدایرکت انبوه صفحات حذف‌شده به صفحه اصلی را «Soft 404» حساب می‌کند و اعتبارشان منتقل نمی‌شود. هر آدرس را به مرتبط‌ترین دسته/محصول ببرید و اگر جایگزینی ندارد، ۴۱۰ بدهید.',
				'sev'   => 'warn',
				'items' => $home_items,
			);
		}
		if ( $gone ) {
			usort( $gone, function ( $a, $b ) {
				return array( $b['clicks'], $b['impr'] ) <=> array( $a['clicks'], $a['impr'] );
			} );
			$groups['gone'] = array(
				'title' => 'صفحات حذف‌شده با ۴۱۰',
				'why'   => '۴۱۰ برای محتوایی که دیگر جایگزین ندارد درست است و گوگل سریع‌تر از ایندکس خارجش می‌کند. ولی اگر آدرس هنوز در گوگل کلیک/نمایش دارد یا صفحه مشابهی دارید، ریدایرکت ۳۰۱ ارزش بیشتری نگه می‌دارد؛ و هیچ لینک داخلی نباید به آدرس ۴۱۰ برود.',
				'sev'   => 'info',
				'items' => $gone,
			);
		}
		// 404s real users/bots still hit.
		$logs = array_values( array_filter( (array) ( $site['logs'] ?? array() ), function ( $l ) {
			return $l['hits'] >= 3 && ! sap_is_junk_404( $l['url'] );
		} ) );
		if ( $logs ) {
			foreach ( $logs as &$l ) {
				list( $l['clicks'], $l['impr'] ) = $clicks( $l['url'] );
			}
			unset( $l );
			$groups['hits_404'] = array(
				'title' => 'آدرس‌های 404 که هنوز بازدید می‌گیرند (مانیتور 404)',
				'why'   => 'این آدرس‌ها طبق مانیتور 404 افزونه سئو بارها باز شده‌اند (از گوگل، بک‌لینک یا لینک قدیمی). برای هرکدام ریدایرکت ۳۰۱ به مرتبط‌ترین صفحه بگذارید؛ آدرس‌های بی‌ربط/اسپم نیاز به کاری ندارند.',
				'sev'   => 'warn',
				'items' => array_slice( $logs, 0, 100 ),
			);
		}
	}

	// 7) Redirected or removed URLs that Google still shows.
	$items = array();
	foreach ( $gsc as $k => $r ) {
		$c = $by[ $k ] ?? null;
		if ( $c && $c['code'] >= 300 && (int) ( $r['impressions'] ?? 0 ) > 0 ) {
			$items[] = array( 'url' => $r['page'], 'code' => $c['code'], 'to' => $c['redirect'], 'clicks' => (int) $r['clicks'], 'impr' => (int) $r['impressions'] );
		}
	}
	if ( $items ) {
		usort( $items, function ( $a, $b ) {
			return $b['impr'] <=> $a['impr'];
		} );
		$groups['in_google'] = array(
			'title' => 'آدرس‌های ریدایرکت/حذف‌شده‌ای که هنوز در نتایج گوگل هستند',
			'why'   => 'طبیعی است تا گوگل دوباره بخزد مدتی بمانند. این آدرس‌ها در تحلیل صفحات و محتوا حساب نمی‌شوند (داده‌شان به مقصد تعلق دارد). اگر بعد از چند هفته هنوز نمایش زیادی دارند: مقصد ریدایرکت را در سایت‌مپ و لینک‌های داخلی بگذارید و آدرس قدیمی را با URL Inspection بررسی کنید.',
			'sev'   => 'info',
			'items' => array_slice( $items, 0, 100 ),
		);
	}
	return array(
		'groups' => $groups,
		'have'   => array( 'crawl' => (bool) $crawl, 'links' => (bool) $links, 'report' => (bool) $report, 'site' => (bool) $site ),
		'site'   => $site ? sap_dataset( $pid, 'site_redirects' )['meta'] : array(),
		'links'  => $links ? sap_dataset( $pid, 'crawl_links' )['meta'] : array(),
	);
}

/**
 * Body-content inlinks of a URL when the inlinks export is available (null = unknown).
 */
function sap_content_inlinks( $ctx, $url ) {
	if ( empty( $ctx['crawl_links']['pages'] ) ) {
		return null;
	}
	return (int) ( $ctx['crawl_links']['pages'][ sap_norm_url( $url ) ]['ci'] ?? 0 );
}

/**
 * Status of a URL in the crawl and redirect data: 'ok' | 'redirect' | 'gone' | 'error' | 'noindex' | '' (unknown).
 */
function sap_url_crawl_state( $project_id, $url ) {
	static $cache = array();
	if ( ! isset( $cache[ $project_id ] ) ) {
		$map = array();
		foreach ( (array) ( sap_dataset_payload( $project_id, 'crawl' )['rows'] ?? array() ) as $r ) {
			$map[ sap_norm_url( $r['url'] ) ] = $r['code'] >= 400 ? ( in_array( $r['code'], array( 404, 410 ), true ) ? 'gone' : 'error' ) : ( $r['code'] >= 300 ? 'redirect' : ( $r['idx'] ? 'ok' : 'noindex' ) );
		}
		foreach ( (array) ( sap_dataset_payload( $project_id, 'site_redirects' )['items'] ?? array() ) as $it ) {
			if ( $it['on'] && $it['src_url'] && empty( $it['src_live'] ) ) {
				$k = sap_norm_url( $it['src_url'] );
				if ( ! isset( $map[ $k ] ) || 'ok' !== $map[ $k ] ) {
					$map[ $k ] = in_array( (int) $it['code'], array( 410, 451 ), true ) ? 'gone' : 'redirect';
				}
			}
		}
		$cache[ $project_id ] = $map;
	}
	return $cache[ $project_id ][ sap_norm_url( $url ) ] ?? '';
}

/* ---------------------------------------------------------------------------
 * Detectors on the deep crawl data
 * ------------------------------------------------------------------------- */

/**
 * Audit once per analysis run.
 */
function sap_ctx_redirect_audit( $ctx ) {
	static $cache = array();
	$pid = $ctx['project']['id'];
	if ( ! isset( $cache[ $pid ] ) ) {
		$cache[ $pid ] = sap_redirect_audit( $ctx['project'] );
	}
	return $cache[ $pid ];
}

/**
 * Broken internal links, by the page that holds them (that is where the fix happens).
 */
function sap_detect_broken_links( $ctx ) {
	$g = sap_ctx_redirect_audit( $ctx )['groups']['broken'] ?? null;
	if ( ! $g || empty( $ctx['crawl_links'] ) ) {
		return array();
	}
	$items = array();
	foreach ( (array) $ctx['crawl_links']['broken'] as $l ) {
		$items[] = array( 'url' => $l['s'], 'page' => sap_ctx_title( $ctx, $l['s'] ), 'to' => sap_short_url( $l['d'], 50 ), 'anchor' => '' !== $l['a'] ? $l['a'] : '(بدون انکر)', 'code' => $l['c'], 'where' => sap_link_pos_label( $l['p'] ), 'impr' => sap_ctx_impr( $ctx, $l['s'] ) );
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => 'broken_links',
		'context'  => 'technical',
		'title'    => 'اصلاح %s لینک داخلی شکسته',
		'detail'   => 'این لینک‌ها به صفحه 404/410/5xx می‌روند. در صفحه مبدا (ستون «صفحه») لینک را به صفحه درست عوض یا حذف کنید. لینک‌های «منو/فوتر/سایدبار» یک بار در قالب یا فهرست‌ها اصلاح می‌شوند.',
		'severity' => 65,
		'cols'     => array( 'page' => 'صفحه', 'anchor' => 'انکر', 'to' => 'لینک شکسته', 'code' => 'کد', 'where' => 'محل' ),
		'per_item' => 0.05,
	) );
}

/**
 * Internal links that point to redirects.
 */
function sap_detect_links_to_redirect( $ctx ) {
	if ( empty( $ctx['crawl_links']['redirect'] ) ) {
		return array();
	}
	$chains = sap_redirect_chains( (array) ( $ctx['crawl']['rows'] ?? array() ) );
	$items  = array();
	foreach ( (array) $ctx['crawl_links']['redirect'] as $l ) {
		$to      = $chains[ sap_norm_url( $l['d'] ) ]['to'] ?? (string) ( $ctx['crawl_by_url'][ sap_norm_url( $l['d'] ) ]['redirect'] ?? '' );
		$items[] = array( 'url' => $l['s'], 'page' => sap_ctx_title( $ctx, $l['s'] ), 'anchor' => '' !== $l['a'] ? $l['a'] : '(بدون انکر)', 'from' => sap_short_url( $l['d'], 45 ), 'to' => '' !== $to ? sap_short_url( $to, 45 ) : '؟', 'where' => sap_link_pos_label( $l['p'] ), 'impr' => sap_ctx_impr( $ctx, $l['s'] ) );
	}
	if ( count( $items ) < 3 ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => 'links_to_redirect',
		'context'  => 'technical',
		'title'    => 'اصلاح %s لینک داخلی که به آدرس ریدایرکت‌شده می‌رود',
		'detail'   => 'آدرس لینک را در صفحه مبدا مستقیم به مقصد نهایی (ستون «آدرس درست») عوض کنید تا پرش اضافه حذف شود و اعتبار لینک کامل برسد.',
		'severity' => 35,
		'cols'     => array( 'page' => 'صفحه', 'anchor' => 'انکر', 'from' => 'لینک فعلی', 'to' => 'آدرس درست', 'where' => 'محل' ),
		'per_item' => 0.04,
	) );
}

/**
 * Pages that matter but get no internal link from body content (only menus/footers or nothing).
 */
function sap_detect_orphans( $ctx ) {
	// Needs a full crawl: in list mode only the listed pages were read, so most links are unseen.
	if ( empty( $ctx['crawl_links']['pages'] ) || empty( $ctx['crawl_links_meta']['positions'] ) || 'quick' === ( $ctx['crawl_links_meta']['mode'] ?? '' ) ) {
		return array();
	}
	$min   = max( 20, (int) $ctx['settings']['min_impressions'] );
	$items = array();
	foreach ( sap_crawl_pages( $ctx ) as $r ) {
		$path = trim( (string) wp_parse_url( $r['url'], PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			continue;
		}
		$lp   = $ctx['crawl_links']['pages'][ sap_norm_url( $r['url'] ) ] ?? null;
		$ci   = (int) ( $lp['ci'] ?? 0 );
		$impr = sap_ctx_impr( $ctx, $r['url'] );
		if ( $ci > 0 || ( $impr < $min && 'high' !== sap_url_importance( $ctx['project']['id'], $r['url'] ) ) ) {
			continue;
		}
		$items[] = array( 'url' => $r['url'], 'page' => sap_ctx_title( $ctx, $r['url'] ), 'nav' => (int) ( $lp['ni'] ?? 0 ), 'depth' => $r['depth'], 'impr' => $impr );
	}
	if ( ! $items ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => 'orphans',
		'context'  => 'internal_links',
		'title'    => 'لینک از داخل متن به %s صفحه مهمی که هیچ لینک متنی ندارد',
		'detail'   => 'این صفحات نمایش یا اهمیت بالایی دارند ولی هیچ صفحه‌ای از داخل محتوا به آن‌ها لینک نداده (فقط منو/فوتر یا هیچ). لینک‌های داخل متن قوی‌ترین سیگنال موضوعی‌اند: از ۳ تا ۵ مقاله/دسته مرتبط با انکر کلمه اصلی صفحه لینک بدهید (اتوماسیون › لینک داخلی).',
		'severity' => 45,
		'cols'     => array( 'page' => 'صفحه', 'nav' => 'لینک منو/فوتر', 'depth' => 'عمق', 'impr' => 'نمایش' ),
		'per_item' => 0.3,
	) );
}

/**
 * Redirect chains, loops and redirects that end on an error.
 */
function sap_detect_redirect_chain( $ctx ) {
	$a     = sap_ctx_redirect_audit( $ctx );
	$items = array();
	foreach ( array_merge( (array) ( $a['groups']['chains']['items'] ?? array() ), (array) ( $a['groups']['dead_end']['items'] ?? array() ) ) as $c ) {
		$items[ sap_norm_url( $c['from'] ) ] = array( 'url' => $c['from'], 'page' => sap_short_url( $c['from'], 50 ), 'to' => sap_short_url( $c['to'], 50 ), 'hops' => $c['loop'] ? 'حلقه' : sap_num( $c['hops'] ), 'end' => $c['final_code'] ?: '؟', 'impr' => sap_ctx_impr( $ctx, $c['from'] ) );
	}
	if ( ! $items ) {
		return array();
	}
	return array( array(
		'key'      => 'redirect_chain',
		'context'  => 'technical',
		'title'    => sprintf( 'اصلاح %s زنجیره/حلقه یا ریدایرکت به صفحه خطا', sap_num( count( $items ) ) ),
		'detail'   => 'در افزونه ریدایرکت (رنک‌مث › Redirections) آدرس اول را مستقیم به مقصد نهایی و زنده ببرید؛ حلقه‌ها را حذف کنید و برای مقصدهای 404 یک صفحه مرتبط بگذارید. بعد لینک‌های داخلی را هم به مقصد نهایی عوض کنید.',
		'url'      => array_values( $items )[0]['url'],
		'severity' => 60,
		'effort'   => min( 4, max( 0.5, 0.1 * count( $items ) ) ),
		'evidence' => array( 'section' => 'redirects', 'cols' => array( 'page' => 'آدرس', 'to' => 'مقصد نهایی', 'hops' => 'پرش', 'end' => 'کد مقصد' ), 'items' => array_slice( array_values( $items ), 0, 40 ) ),
	) );
}

/**
 * Temporary redirects used for permanent moves.
 */
function sap_detect_redirect_temp( $ctx ) {
	$g = sap_ctx_redirect_audit( $ctx )['groups']['temp'] ?? null;
	if ( ! $g ) {
		return array();
	}
	$items = array_map( function ( $t ) use ( $ctx ) {
		return array( 'url' => $t['url'], 'page' => sap_short_url( $t['url'], 50 ), 'to' => sap_short_url( (string) $t['to'], 50 ), 'code' => $t['code'], 'impr' => sap_ctx_impr( $ctx, $t['url'] ) );
	}, $g['items'] );
	return array( array(
		'key'      => 'redirect_temp',
		'context'  => 'technical',
		'title'    => sprintf( 'تبدیل %s ریدایرکت موقت (302/307) به دائمی (301)', sap_num( count( $items ) ) ),
		'detail'   => $g['why'],
		'url'      => $items[0]['url'],
		'severity' => 40,
		'effort'   => min( 2, max( 0.25, 0.05 * count( $items ) ) ),
		'evidence' => array( 'section' => 'redirects', 'cols' => array( 'page' => 'آدرس', 'to' => 'مقصد', 'code' => 'کد' ), 'items' => array_slice( $items, 0, 40 ) ),
	) );
}

/**
 * Problems in the redirect manager list (Rank Math / Redirection / Yoast).
 */
function sap_detect_redirect_manager( $ctx ) {
	$a   = sap_ctx_redirect_audit( $ctx );
	$out = array();
	foreach ( array( 'hides_live' => 70, 'bad_target' => 60, 'to_home' => 40 ) as $k => $sev ) {
		$g = $a['groups'][ $k ] ?? null;
		if ( ! $g ) {
			continue;
		}
		$items = array_map( function ( $t ) {
			return array( 'url' => $t['url'], 'page' => sap_short_url( $t['url'], 50 ), 'to' => sap_short_url( (string) ( $t['to'] ?? '' ), 50 ), 'code' => $t['code'], 'why' => $t['why'] ?? '', 'plugin' => array( 'rank_math' => 'رنک‌مث', 'redirection' => 'Redirection', 'yoast' => 'یوآست' )[ $t['plugin'] ] ?? '' );
		}, (array) $g['items'] );
		$out[] = array(
			'key'      => 'rm_' . $k,
			'context'  => 'technical',
			'title'    => $g['title'] . ( $items ? ' (' . sap_num( count( $items ) ) . ' قانون)' : '' ),
			'detail'   => $g['why'],
			'url'      => $items[0]['url'] ?? '',
			'severity' => $sev,
			'effort'   => min( 3, max( 0.25, 0.1 * max( 1, count( $items ) ) ) ),
			'evidence' => array( 'section' => 'redirects', 'cols' => array_filter( array( 'page' => 'آدرس مبدا', 'to' => 'مقصد', 'code' => 'کد', 'why' => 'bad_target' === $k ? 'مشکل' : '', 'plugin' => 'افزونه' ) ), 'items' => array_slice( $items, 0, 40 ) ),
		);
	}
	return $out;
}

/**
 * 404 URLs that still get visits (redirect manager's 404 monitor).
 */
function sap_detect_hits_404( $ctx ) {
	$g = sap_ctx_redirect_audit( $ctx )['groups']['hits_404'] ?? null;
	if ( ! $g ) {
		return array();
	}
	$items = array_map( function ( $l ) {
		return array( 'url' => $l['url'], 'page' => sap_short_url( $l['url'], 55 ), 'hits' => $l['hits'], 'impr' => $l['impr'], 'ref' => '' !== $l['ref'] ? sap_short_url( $l['ref'], 40 ) : '—' );
	}, $g['items'] );
	return array( array(
		'key'      => 'hits_404',
		'context'  => 'technical',
		'title'    => sprintf( 'ریدایرکت ۳۰۱ برای %s آدرس 404 که هنوز بازدید می‌گیرد', sap_num( count( $items ) ) ),
		'detail'   => $g['why'],
		'url'      => $items[0]['url'],
		'severity' => 50,
		'effort'   => min( 3, max( 0.25, 0.08 * count( $items ) ) ),
		'evidence' => array( 'section' => 'redirects', 'cols' => array( 'page' => 'آدرس', 'hits' => 'بازدید 404', 'impr' => 'نمایش گوگل', 'ref' => 'ارجاع‌دهنده' ), 'items' => array_slice( $items, 0, 40 ) ),
	) );
}

/**
 * Position name in Persian.
 */
function sap_link_pos_label( $pos ) {
	$p = strtolower( trim( (string) $pos ) );
	return array( '' => 'متن', 'content' => 'متن', 'navigation' => 'منو', 'header' => 'هدر', 'footer' => 'فوتر', 'aside' => 'سایدبار' )[ $p ] ?? $p;
}
