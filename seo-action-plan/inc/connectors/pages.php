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

/* ---------------------------------------------------------------------------
 * Main content as editable blocks (for the content audit & rewrite).
 * Only the article / description itself: header, menus, sidebar, footer,
 * related posts and comments are left out.
 * ------------------------------------------------------------------------- */

/**
 * Tags allowed in content blocks (and in AI edits).
 */
function sap_content_allowed_html() {
	$a = array();
	foreach ( array( 'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'blockquote', 'br', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'figure', 'figcaption', 'span', 'sup', 'sub' ) as $t ) {
		$a[ $t ] = array();
	}
	$a['a']   = array( 'href' => true, 'title' => true, 'rel' => true, 'target' => true );
	$a['img'] = array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true );
	$a['td']  = array( 'colspan' => true, 'rowspan' => true );
	$a['th']  = array( 'colspan' => true, 'rowspan' => true );
	// FAQ accordion, sections and the key-points box of the semantic article output.
	$a['section']    = array( 'class' => true );
	$a['details']    = array( 'class' => true, 'open' => true );
	$a['summary']    = array();
	$a['blockquote'] = array( 'class' => true );
	return $a;
}

/**
 * Main content of a page as blocks.
 *
 * The source can be replaced (filter sap_content_source), e.g. by the raw
 * post_content from the client-site connector, which is the most exact source.
 *
 * @return array|WP_Error {title, h1, meta, source, blocks: [{id, tag, html, text}]}
 */
function sap_page_content_blocks( $url, $project = null ) {
	$pre = apply_filters( 'sap_content_source', null, $url, $project );
	if ( is_array( $pre ) || is_wp_error( $pre ) ) {
		return $pre;
	}
	$res = wp_remote_get( $url, array(
		'timeout'     => 20,
		'redirection' => 3,
		'user-agent'  => 'Mozilla/5.0 (compatible; SokanSEO/1.0; +' . home_url( '/' ) . ')',
		'headers'     => array( 'Accept' => 'text/html' ),
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$html = (string) wp_remote_retrieve_body( $res );
	if ( $code >= 400 || '' === $html ) {
		return new WP_Error( 'sap_page_http', 'صفحه باز نشد (HTTP ' . $code . ').' );
	}
	return sap_content_blocks_from_html( $html, $url );
}

/**
 * Split the main content of an HTML document into blocks.
 */
function sap_content_blocks_from_html( $html, $url = '' ) {
	$facts = sap_page_parse( $html, $url ?: 'https://example.com/' );
	$out   = array( 'title' => $facts['title'], 'h1' => $facts['h1'][0] ?? '', 'meta' => $facts['meta'], 'source' => 'html', 'blocks' => array() );
	if ( ! class_exists( 'DOMDocument' ) ) {
		return $out;
	}
	$prev = libxml_use_internal_errors( true );
	$dom  = new DOMDocument();
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	$xp = new DOMXPath( $dom );
	foreach ( $xp->query( '//script|//style|//noscript|//svg|//form|//iframe|//nav|//aside|//footer' ) as $n ) {
		$n->parentNode && $n->parentNode->removeChild( $n );
	}
	$len  = function ( $node ) {
		return mb_strlen( trim( preg_replace( '/\s+/u', ' ', (string) $node->textContent ) ) );
	};
	$main = null;
	// Known content containers of WordPress themes, page builders and WooCommerce.
	foreach ( array(
		'//*[@itemprop="articleBody"]',
		'//*[contains(concat(" ",normalize-space(@class)," ")," entry-content ")]',
		'//*[contains(@class,"post-content") or contains(@class,"article-content") or contains(@class,"single-content") or contains(@class,"content-inner")]',
		'//*[@id="tab-description"]',
		'//*[contains(@class,"woocommerce-Tabs-panel--description")]',
		'//*[contains(@class,"term-description")]',
		'//*[contains(@class,"elementor-widget-theme-post-content")]',
		'//article',
		'//main',
	) as $q ) {
		foreach ( $xp->query( $q ) as $n ) {
			if ( $len( $n ) > 300 ) {
				$main = $n;
				break 2;
			}
		}
	}
	if ( ! $main ) {
		// Fallback: the element holding most paragraph text.
		$best = 0;
		foreach ( $xp->query( '//p' ) as $p ) {
			$parent = $p->parentNode;
			$score  = 0;
			foreach ( $parent->childNodes as $c ) {
				if ( XML_ELEMENT_NODE === $c->nodeType && 'p' === strtolower( $c->nodeName ) ) {
					$score += $len( $c );
				}
			}
			if ( $score > $best ) {
				$best = $score;
				$main = $parent;
			}
		}
	}
	if ( ! $main ) {
		return $out;
	}
	// Lazy-loaded images keep the real address in data-* attributes.
	foreach ( $xp->query( './/img', $main ) as $img ) {
		foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'data-lazy' ) as $att ) {
			$v = trim( $img->getAttribute( $att ) );
			if ( '' !== $v && ( '' === $img->getAttribute( 'src' ) || 0 === strpos( $img->getAttribute( 'src' ), 'data:' ) || $v !== $img->getAttribute( 'src' ) ) ) {
				$img->setAttribute( 'src', WP_Http::make_absolute_url( $v, $url ?: 'https://example.com/' ) );
				break;
			}
		}
		if ( $url && '' !== $img->getAttribute( 'src' ) && 0 !== strpos( $img->getAttribute( 'src' ), 'data:' ) ) {
			$img->setAttribute( 'src', WP_Http::make_absolute_url( $img->getAttribute( 'src' ), $url ) );
		}
	}
	// Remove chrome that lives inside the content area.
	foreach ( $xp->query( './/*[contains(@class,"related") or contains(@class,"share") or contains(@class,"comment") or contains(@class,"breadcrumb") or contains(@class,"author-box") or contains(@class,"toc") or contains(@id,"toc") or contains(@class,"ez-toc")]', $main ) as $n ) {
		$n->parentNode && $n->parentNode->removeChild( $n );
	}
	$blocks = array();
	$block  = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote', 'figure', 'img', 'pre', 'dl' );
	$walk   = function ( $node ) use ( &$walk, &$blocks, $block, $dom, $len ) {
		foreach ( iterator_to_array( $node->childNodes ) as $c ) {
			if ( count( $blocks ) >= 160 ) {
				return;
			}
			if ( XML_TEXT_NODE === $c->nodeType ) {
				$t = trim( $c->textContent );
				if ( mb_strlen( $t ) > 40 ) {
					$blocks[] = array( 'tag' => 'p', 'html' => '<p>' . esc_html( $t ) . '</p>' );
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $c->nodeType ) {
				continue;
			}
			$tag = strtolower( $c->nodeName );
			if ( 'h1' === $tag ) {
				continue; // The page title is edited separately.
			}
			if ( in_array( $tag, $block, true ) ) {
				if ( 'img' !== $tag && 'figure' !== $tag && 0 === $len( $c ) && ! $c->getElementsByTagName( 'img' )->length ) {
					continue;
				}
				$blocks[] = array( 'tag' => $tag, 'html' => $dom->saveHTML( $c ) );
			} else {
				$walk( $c ); // Wrapper (div, section, span…): look inside.
			}
		}
	};
	$walk( $main );
	$allowed = sap_content_allowed_html();
	foreach ( $blocks as $i => $b ) {
		$html = trim( wp_kses( $b['html'], $allowed ) );
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( '<', ' <', $html ) ) ) );
		if ( '' === $html ) {
			continue;
		}
		if ( false !== stripos( $html, '<img' ) ) {
			$text = trim( sap_block_image_label( $html ) . ' ' . $text ); // The AI sees the image and its alt in place.
		}
		$out['blocks'][] = array( 'id' => count( $out['blocks'] ) + 1, 'tag' => $b['tag'], 'html' => $html, 'text' => $text );
	}
	return $out;
}

/**
 * Readable label of the images in a block: «[تصویر · Alt: …]».
 */
function sap_block_image_label( $html ) {
	if ( ! preg_match_all( '/<img\b[^>]*>/i', (string) $html, $m ) ) {
		return '';
	}
	$out = array();
	foreach ( $m[0] as $tag ) {
		$alt   = preg_match( '/\salt\s*=\s*["\']([^"\']*)/i', $tag, $a ) ? trim( html_entity_decode( $a[1], ENT_QUOTES, 'UTF-8' ) ) : '';
		$out[] = '' !== $alt ? '[تصویر · Alt: ' . $alt . ']' : '[تصویر بدون Alt]';
	}
	return implode( ' ', $out );
}
