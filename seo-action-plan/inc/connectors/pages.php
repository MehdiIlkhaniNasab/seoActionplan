<?php
/**
 * Page reader: fetches the project's key pages and extracts what the analysis
 * needs from the HTML — main text, headings, JSON-LD schema, internal links with
 * anchors, images without alt. This gives the AI the real content of a page for
 * content updates and internal linking, independent of the crawler's export.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetch and parse one page.
 *
 * @return array|WP_Error
 */
function sap_page_fetch( $url ) {
	$res = wp_remote_get( $url, array(
		'timeout'     => 12,
		'redirection' => 3,
		'user-agent'  => 'Mozilla/5.0 (compatible; SEOActionPlan/1.0; +' . home_url( '/' ) . ')',
		'headers'     => array( 'Accept' => 'text/html' ),
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$html = (string) wp_remote_retrieve_body( $res );
	if ( $code >= 400 || '' === $html ) {
		return new WP_Error( 'sap_page_http', 'HTTP ' . $code );
	}
	return array_merge( array( 'url' => $url, 'code' => $code ), sap_page_parse( $html, $url ) );
}

/**
 * Extract SEO facts from HTML.
 */
function sap_page_parse( $html, $url ) {
	$out = array(
		'title' => '', 'meta' => '', 'canonical' => '', 'robots' => '', 'h1' => array(), 'h2' => array(), 'h3' => array(),
		'words' => 0, 'text' => '', 'schema' => array(), 'schema_errors' => 0, 'links' => array(), 'images' => 0, 'no_alt' => 0,
	);
	if ( ! class_exists( 'DOMDocument' ) ) {
		return $out;
	}
	$prev = libxml_use_internal_errors( true );
	// Keep words of neighbouring blocks apart once tags are stripped.
	$html = preg_replace( '#(</(?:p|div|li|h[1-6]|td|th|section|article|blockquote|figcaption)>|<br\s*/?>)#i', '$1 ', $html );
	$dom  = new DOMDocument();
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	$xp   = new DOMXPath( $dom );
	$text = function ( $node ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $node->textContent ) );
	};

	$t = $xp->query( '//title' );
	$out['title'] = $t->length ? $text( $t->item( 0 ) ) : '';
	foreach ( $xp->query( '//meta[@name]' ) as $m ) {
		$name = strtolower( $m->getAttribute( 'name' ) );
		if ( 'description' === $name ) {
			$out['meta'] = trim( $m->getAttribute( 'content' ) );
		} elseif ( 'robots' === $name ) {
			$out['robots'] = trim( $m->getAttribute( 'content' ) );
		}
	}
	$c = $xp->query( '//link[@rel="canonical"]' );
	$out['canonical'] = $c->length ? $c->item( 0 )->getAttribute( 'href' ) : '';

	// Structured data (JSON-LD): every @type, including inside @graph.
	foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $s ) {
		$json = json_decode( trim( $s->textContent ), true );
		if ( ! is_array( $json ) ) {
			++$out['schema_errors'];
			continue;
		}
		$walk = function ( $node ) use ( &$walk, &$out ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['@type'] ) ) {
				foreach ( (array) $node['@type'] as $type ) {
					$out['schema'][] = (string) $type;
				}
			}
			foreach ( $node as $v ) {
				if ( is_array( $v ) ) {
					$walk( $v );
				}
			}
		};
		$walk( $json );
	}
	foreach ( $xp->query( '//*[@itemtype]' ) as $m ) {
		$out['schema'][] = basename( $m->getAttribute( 'itemtype' ) ) . ' (microdata)';
	}
	$out['schema'] = array_values( array_unique( $out['schema'] ) );

	$doc_h1 = array();
	foreach ( $xp->query( '//h1' ) as $n ) {
		$doc_h1[] = mb_substr( $text( $n ), 0, 120 );
	}
	// Remove chrome before reading the content (never <html>/<body>, whatever their classes).
	$chrome = '(contains(@class,"breadcrumb") or contains(@class,"menu") or contains(@class,"related") or contains(@class,"comment"))';
	foreach ( $xp->query( '//script|//style|//noscript|//svg|//footer|//nav|//form|//aside|//body//header[not(ancestor::article) and not(ancestor::main)]|//body//*[' . $chrome . ' and not(self::main) and not(self::article)]' ) as $n ) {
		if ( $n->parentNode ) {
			$n->parentNode->removeChild( $n );
		}
	}
	$main = null;
	foreach ( array( '//main', '//article', '//*[contains(@class,"entry-content")]', '//*[contains(@class,"woocommerce-product-details__short-description")]/..', '//*[contains(@class,"term-description")]', '//body' ) as $q ) {
		$n = $xp->query( $q );
		if ( $n->length && mb_strlen( $text( $n->item( 0 ) ) ) > 200 ) {
			$main = $n->item( 0 );
			break;
		}
	}
	$main = $main ?: $dom->documentElement;
	foreach ( array( 'h1', 'h2', 'h3' ) as $h ) {
		foreach ( $xp->query( './/' . $h, $main ) as $n ) {
			$v = $text( $n );
			if ( '' !== $v && count( $out[ $h ] ) < 20 ) {
				$out[ $h ][] = mb_substr( $v, 0, 120 );
			}
		}
	}
	if ( ! $out['h1'] ) {
		$out['h1'] = array_slice( $doc_h1, 0, 5 );
	}
	$body          = $text( $main );
	$out['words']  = count( preg_split( '/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY ) );
	$out['text']   = mb_substr( $body, 0, 6000 );

	// Internal links in the content area (anchor + target).
	$host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
	foreach ( $xp->query( './/a[@href]', $main ) as $a ) {
		$href = WP_Http::make_absolute_url( $a->getAttribute( 'href' ), $url );
		$h    = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $href, PHP_URL_HOST ) ) );
		$anc  = mb_substr( $text( $a ), 0, 80 );
		if ( $h === $host && '' !== $anc && count( $out['links'] ) < 80 ) {
			$out['links'][] = array( strtok( $href, '#' ), $anc );
		}
	}
	foreach ( $xp->query( './/img', $main ) as $img ) {
		++$out['images'];
		if ( '' === trim( $img->getAttribute( 'alt' ) ) ) {
			++$out['no_alt'];
		}
	}
	return $out;
}

/**
 * Pages worth reading for a project: focus pages, top pages by clicks, sheet URLs.
 *
 * @return string[] URLs (max $limit)
 */
function sap_pages_to_read( $project, $limit = 30 ) {
	$pid   = $project['id'];
	$urls  = array();
	$pages = sap_sort( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ), 'clicks' );
	foreach ( sap_focuses( $pid, true ) as $f ) {
		foreach ( $pages as $r ) {
			if ( sap_url_matches( $r['page'], $f['url_patterns'] ) ) {
				$urls[ sap_norm_url( $r['page'] ) ] = $r['page'];
			}
		}
	}
	foreach ( (array) ( sap_dataset_payload( $pid, 'sheet_clusters' )['rows'] ?? array() ) as $r ) {
		if ( '' !== $r['target'] ) {
			$urls[ sap_norm_url( $r['target'] ) ] = $r['target'];
		}
	}
	foreach ( array_slice( $pages, 0, $limit ) as $r ) {
		$urls[ sap_norm_url( $r['page'] ) ] = $r['page'];
	}
	foreach ( (array) ( sap_dataset_payload( $pid, 'sheet_calendar' )['rows'] ?? array() ) as $r ) {
		if ( '' !== $r['url'] ) {
			$urls[ sap_norm_url( $r['url'] ) ] = $r['url'];
		}
	}
	return array_slice( array_values( $urls ), 0, $limit );
}
