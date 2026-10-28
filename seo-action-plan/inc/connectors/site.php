<?php
/**
 * Client site connection through the connector plugin (SeoReport Connector
 * 1.2+, «سکان» section) installed on the client's WordPress.
 *
 *   - change log: every change made on the client site (title, meta, focus
 *     keyword, content, slug, categories, product attributes, publishing…) is
 *     pulled into the activity log with its before / after values;
 *   - content: the raw content and SEO fields of a page, for the content audit;
 *   - apply: approved changes become a draft with a preview link on the client
 *     site, are published on approval, and can be rolled back.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connection details of a project: {url, key} or null.
 */
function sap_site_conn( $project ) {
	$key = sap_decrypt( (string) ( $project['options']['site_key'] ?? '' ) );
	if ( '' === $key ) {
		return null;
	}
	$url = (string) ( $project['options']['site_url'] ?? '' ) ?: ( $project['site_url'] ?: 'https://' . sap_project_domain( $project ) . '/' );
	return array( 'url' => trailingslashit( esc_url_raw( $url ) ), 'key' => $key );
}

function sap_site_connected( $project ) {
	return null !== sap_site_conn( $project );
}

/**
 * Request to the connector's Sokan API.
 *
 * @return array|WP_Error
 */
function sap_site_request( $project, $method, $route, $params = array(), $timeout = 40 ) {
	$c = sap_site_conn( $project );
	if ( ! $c ) {
		return new WP_Error( 'sap_site', 'اتصال به سایت (افزونه رابط) برای این پروژه تنظیم نشده.' );
	}
	$path = 'seoreport-connector/v1/sokan/' . ltrim( $route, '/' );
	$args = array(
		'method'  => $method,
		'timeout' => $timeout,
		'headers' => array( 'X-Sokan-Key' => $c['key'], 'Accept' => 'application/json' ),
	);
	if ( 'GET' === $method ) {
		$urls = array( add_query_arg( $params, $c['url'] . 'wp-json/' . $path ), add_query_arg( array_merge( $params, array( 'rest_route' => '/' . $path ) ), $c['url'] ) );
	} else {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $params );
		$urls                            = array( $c['url'] . 'wp-json/' . $path, add_query_arg( 'rest_route', '/' . $path, $c['url'] ) );
	}
	$res = null;
	foreach ( $urls as $i => $u ) {
		$res = sap_http( $u, $args );
		// Sites without pretty permalinks answer /wp-json/ with 404: try ?rest_route=.
		if ( is_wp_error( $res ) || 404 !== (int) wp_remote_retrieve_response_code( $res ) || false !== strpos( (string) wp_remote_retrieve_body( $res ), 'srcon_' ) ) {
			break;
		}
	}
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'sap_site_http', 'اتصال به سایت کارفرما ناموفق بود: ' . sap_net_error( $res ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( preg_replace( '/^(?:\xEF\xBB\xBF|\s)+/', '', (string) wp_remote_retrieve_body( $res ) ), true ); // A BOM from a theme / plugin file breaks the JSON.
	if ( $code >= 400 ) {
		$msg = (string) ( $data['message'] ?? ( 'HTTP ' . $code ) );
		if ( 403 === $code && false !== strpos( $msg, 'Invalid Sokan key' ) ) {
			$msg = 'کلید سکان نامعتبر است (افزونه رابط › تنظیمات › کلید سکان).';
		} elseif ( 404 === $code && 'rest_no_route' === ( $data['code'] ?? '' ) ) {
			$msg = 'افزونه رابط روی سایت کارفرما نصب نیست یا قدیمی است؛ نسخه ۱٫۲ به بالا لازم است.';
		}
		return new WP_Error( 'sap_site_' . $code, $msg, array( 'status' => $code ) );
	}
	return is_array( $data ) ? $data : new WP_Error( 'sap_site_json', 'پاسخ افزونه رابط قابل خواندن نبود.' );
}

/**
 * Persian label of a logged field.
 */
function sap_site_field_label( $field ) {
	$labels = array(
		'status'           => 'وضعیت انتشار',
		'title'            => 'عنوان',
		'slug'             => 'نامک (URL)',
		'excerpt'          => 'خلاصه',
		'content_words'    => 'محتوا (تعداد کلمات)',
		'parent'           => 'والد',
		'seo_title'        => 'تایتل سئو',
		'meta_description' => 'متا دیسکریپشن',
		'focus_keyword'    => 'کلمه کلیدی کانونی',
		'canonical'        => 'کنونیکال',
		'robots'           => 'ربات‌ها (index/follow)',
		'noindex'          => 'noindex',
		'attributes'       => 'ویژگی‌های محصول',
		'featured_image'   => 'تصویر شاخص',
		'gallery'          => 'گالری تصاویر',
		'term_name'        => 'نام دسته',
		'term_description' => 'توضیحات دسته',
		'created'          => 'ساخت دسته/برچسب',
		'deleted'          => 'حذف دسته/برچسب',
	);
	if ( 0 === strpos( $field, 'terms:' ) ) {
		return 'دسته‌بندی/برچسب (' . substr( $field, 6 ) . ')';
	}
	return $labels[ $field ] ?? $field;
}

/**
 * Work area of a logged field.
 */
function sap_site_field_context( $field, $new ) {
	if ( 'status' === $field ) {
		return 'publish' === $new ? 'content_new' : 'technical';
	}
	if ( in_array( $field, array( 'content_words', 'title', 'excerpt', 'term_description' ), true ) ) {
		return 'content_update';
	}
	if ( in_array( $field, array( 'slug', 'canonical', 'robots', 'noindex', 'parent', 'deleted' ), true ) ) {
		return 'technical';
	}
	return 'onpage';
}

/**
 * Pull the client site's change log into the activity log (incremental).
 *
 * @return array|WP_Error {added: int, last: int}
 */
function sap_site_pull_changes( $project ) {
	$last    = (int) ( $project['options']['site_log_last'] ?? 0 );
	$added   = 0;
	$focuses = sap_focus_context( $project['id'] );
	for ( $page = 0; $page < 10; $page++ ) {
		$res = sap_site_request( $project, 'GET', 'changes', array( 'after_id' => $last, 'limit' => 300 ) );
		if ( is_wp_error( $res ) ) {
			return $added ? array( 'added' => $added, 'last' => $last ) : $res;
		}
		foreach ( (array) ( $res['changes'] ?? array() ) as $c ) {
			$last = max( $last, (int) $c['id'] );
			if ( 'sokan' === ( $c['source'] ?? '' ) ) {
				continue; // Applied from Sokan: already in the log with the approval.
			}
			do_action( 'sap_site_change', $project, $c ); // Alerts watch risky changes made outside Sokan.
			$name  = '' !== (string) $c['title'] ? '«' . $c['title'] . '»' : '';
			$label = sap_site_field_label( (string) $c['field'] );
			if ( 'status' === $c['field'] && 'publish' === $c['new'] ) {
				$title = 'انتشار ' . $name;
			} elseif ( 'status' === $c['field'] ) {
				$title = 'خارج شدن از انتشار ' . $name . ' (' . $c['new'] . ')';
			} elseif ( 'content_words' === $c['field'] ) {
				$title = sprintf( 'بروزرسانی محتوای %s (%s ← %s کلمه)', $name, sap_num( (int) $c['old'] ), $c['new'] );
			} elseif ( 'created' === $c['field'] ) {
				$title = 'ساخت ' . ( false !== strpos( (string) $c['subtype'], 'cat' ) ? 'دسته' : 'برچسب' ) . ' «' . $c['new'] . '»';
			} else {
				$title = 'تغییر ' . $label . ' ' . $name;
			}
			list( $fid ) = sap_focus_match( $focuses, (string) $c['url'] );
			sap_activity_upsert_ref( $project['id'], 'site:' . (int) $c['id'], array(
				'focus_id'     => $fid,
				'category'     => sap_site_field_context( (string) $c['field'], (string) $c['new'] ),
				'action'       => 'site_' . sanitize_key( str_replace( ':', '_', (string) $c['field'] ) ),
				'title'        => trim( $title ),
				'url'          => (string) $c['url'],
				'before_value' => mb_substr( (string) $c['old'], 0, 2000 ),
				'after_value'  => mb_substr( (string) $c['new'], 0, 2000 ),
				'source'       => 'agent',
				'user_id'      => 0,
				'happened_at'  => (string) $c['date'],
				'meta'         => array( 'site_user' => (string) $c['user'], 'field' => (string) $c['field'], 'kind' => (string) $c['kind'], 'object' => (int) $c['object'] ),
			) );
			++$added;
		}
		if ( empty( $res['more'] ) ) {
			break;
		}
	}
	sap_project_set_option( $project['id'], 'site_log_last', $last );
	return array( 'added' => $added, 'last' => $last );
}

/* ---------------------------------------------------------------------------
 * Content from the client site (exact post_content instead of the page HTML)
 * ------------------------------------------------------------------------- */

add_filter( 'sap_content_source', function ( $pre, $url, $project ) {
	if ( null !== $pre || ! $project || ! sap_site_connected( $project ) ) {
		return $pre;
	}
	$obj = sap_site_page_details( $project, $url, true );
	if ( ! is_array( $obj ) ) {
		return null; // Not found on the site: read the live page instead.
	}
	$details  = (array) ( $obj['details'] ?? array() );
	$rendered = (string) ( $details['rendered'] ?? '' );
	if ( 'elementor' === ( $obj['editor'] ?? '' ) ) {
		if ( '' === trim( $rendered ) ) {
			return null; // Old connector: no rendered HTML, read the live page.
		}
		// Built with Elementor: read the rendered widgets (text and images); edits are copied by hand into Elementor.
		$page = sap_content_blocks_from_html( '<html><body><article class="entry-content">' . $rendered . '</article></body></html>', $url );
		foreach ( $page['blocks'] as &$b ) {
			$b['raw']    = $b['html'];
			$b['locked'] = false;
		}
		unset( $b );
		$page['format'] = 'elementor';
	} elseif ( '' === trim( (string) ( $obj['content'] ?? '' ) ) ) {
		if ( 'term' !== ( $obj['kind'] ?? '' ) ) {
			return null;
		}
		$page = array( 'blocks' => array(), 'format' => 'classic' ); // Empty category description: still a valid source (nothing to rewrite yet).
	} else {
		$page = sap_content_blocks_from_raw( (string) $obj['content'], (string) $obj['editor'] );
	}
	$seo_title = (string) ( $obj['seo']['seo_title'] ?? '' );
	return array_merge( $page, array(
		'title'  => '' !== $seo_title && false === strpos( $seo_title, '%' ) ? $seo_title : (string) $obj['title'],
		'h1'     => (string) $obj['title'],
		'meta'   => (string) ( $obj['seo']['meta_description'] ?? '' ),
		'source' => 'site',
		'site'   => array(
			'kind'    => $obj['kind'],
			'id'      => (int) $obj['id'],
			'type'    => (string) ( $obj['type'] ?? '' ),
			'editor'  => (string) $obj['editor'],
			'seo'     => (array) ( $obj['seo'] ?? array() ),
			'images'  => array_slice( (array) ( $details['images'] ?? array() ), 0, 40 ),
			'product' => $details['product'] ?? null,
			'term'    => $details['term'] ?? null,
		),
	) );
}, 10, 3 );

/**
 * Blocks from raw post_content. Gutenberg blocks keep their serialization so
 * unchanged blocks are written back byte for byte.
 */
function sap_content_blocks_from_raw( $raw, $editor ) {
	$allowed  = sap_content_allowed_html();
	$editable = array( 'core/paragraph', 'core/heading', 'core/list', 'core/quote', 'core/table' );
	$out      = array( 'blocks' => array(), 'format' => 'gutenberg' === $editor ? 'gutenberg' : 'classic' );
	if ( 'gutenberg' === $editor && function_exists( 'parse_blocks' ) ) {
		foreach ( parse_blocks( $raw ) as $b ) {
			if ( empty( $b['blockName'] ) && '' === trim( (string) $b['innerHTML'] ) ) {
				continue;
			}
			$ser  = serialize_block( $b );
			$html = trim( preg_replace( '/<!--.*?-->/s', '', $ser ) );
			$text = sap_block_text( $html );
			$tag  = preg_match( '/^<([a-z0-9]+)/i', $html, $m ) ? strtolower( $m[1] ) : 'div';
			$out['blocks'][] = array(
				'id'     => count( $out['blocks'] ) + 1,
				'tag'    => $tag,
				'html'   => trim( wp_kses( $html, $allowed ) ),
				'text'   => '' !== $text ? $text : ( sap_block_image_label( $html ) ?: '[' . ( $b['blockName'] ?: 'html' ) . ']' ),
				'raw'    => $ser,
				'name'   => (string) $b['blockName'],
				'locked' => ! in_array( $b['blockName'], $editable, true ),
			);
		}
		return $out;
	}
	// Classic editor: paragraphs come from line breaks; [caption] becomes a figure so the image and its caption stay.
	$raw    = preg_replace( '#\[caption[^\]]*\](.*?)\[/caption\]#is', '<figure>$1</figure>', $raw );
	$raw    = preg_replace( '#<figure>\s*((?:<a[^>]*>)?<img[^>]*>(?:</a>)?)\s*(.*?)</figure>#is', '<figure>$1<figcaption>$2</figcaption></figure>', $raw );
	$parsed = sap_content_blocks_from_html( '<html><body><article>' . wpautop( $raw ) . '</article></body></html>' );
	foreach ( $parsed['blocks'] as $b ) {
		$b['raw']        = $b['html'];
		$out['blocks'][] = $b;
	}
	return $out;
}

/**
 * Final post_content for the client site after the accepted edits, in the
 * post's own format (Gutenberg block markup or classic HTML).
 */
function sap_content_final_raw( $rev ) {
	$format = $rev['original']['format'] ?? 'html';
	if ( 'gutenberg' !== $format ) {
		return sap_content_merge( $rev );
	}
	$by = array();
	foreach ( $rev['edits'] as $e ) {
		if ( ! empty( $e['accept'] ) ) {
			$by[ $e['block'] ][] = $e;
		}
	}
	$out = array();
	foreach ( (array) $rev['original']['blocks'] as $b ) {
		$raw = $b['raw'] ?? $b['html'];
		$add = array();
		foreach ( $by[ $b['id'] ] ?? array() as $e ) {
			if ( 'replace' === $e['op'] ) {
				$raw = sap_gutenberg_wrap( $e['html'] );
			} elseif ( 'delete' === $e['op'] ) {
				$raw = '';
			} else {
				$add[] = sap_gutenberg_wrap( $e['html'] );
			}
		}
		if ( '' !== trim( $raw ) ) {
			$out[] = $raw;
		}
		foreach ( $add as $a ) {
			$out[] = $a;
		}
	}
	return implode( "\n\n", $out );
}

/**
 * Wrap plain HTML (one or more top-level elements) in Gutenberg block comments.
 */
function sap_gutenberg_wrap( $html ) {
	// Structured sections (FAQ accordion, key-points box…) stay one HTML block.
	if ( preg_match( '#<(details|section|aside|figure)\b#i', $html ) ) {
		return "<!-- wp:html -->\n" . trim( $html ) . "\n<!-- /wp:html -->";
	}
	$parts = preg_split( '#(?<=</p>|</h[1-6]>|</ul>|</ol>|</table>|</blockquote>)\s*#i', trim( $html ), -1, PREG_SPLIT_NO_EMPTY );
	$out   = array();
	foreach ( $parts as $p ) {
		$p = trim( $p );
		if ( preg_match( '#^<p\b#i', $p ) ) {
			$out[] = "<!-- wp:paragraph -->\n" . $p . "\n<!-- /wp:paragraph -->";
		} elseif ( preg_match( '#^<h([1-6])\b#i', $p, $m ) ) {
			$p     = preg_replace( '#^<h([1-6])(\s[^>]*)?>#i', '<h$1 class="wp-block-heading">', $p );
			$out[] = '<!-- wp:heading' . ( '2' === $m[1] ? '' : ' {"level":' . (int) $m[1] . '}' ) . " -->\n" . $p . "\n<!-- /wp:heading -->";
		} elseif ( preg_match( '#^<(ul|ol)\b#i', $p, $m ) ) {
			$out[] = '<!-- wp:list' . ( 'ol' === strtolower( $m[1] ) ? ' {"ordered":true}' : '' ) . " -->\n" . $p . "\n<!-- /wp:list -->";
		} elseif ( preg_match( '#^<table\b#i', $p ) ) {
			$out[] = "<!-- wp:table -->\n<figure class=\"wp-block-table\">" . $p . "</figure>\n<!-- /wp:table -->";
		} else {
			$out[] = "<!-- wp:html -->\n" . $p . "\n<!-- /wp:html -->";
		}
	}
	return implode( "\n\n", $out );
}

/**
 * Readable, semantic article output: when the content has an FAQ accordion
 * (<details class="sokan-faq">), add its FAQPage schema and the small style
 * that makes the accordion, tables and key-points box clean on any theme
 * (once per page).
 */
function sap_content_enhance( $html ) {
	$html = (string) $html;
	if ( false !== strpos( $html, 'sokan-enhanced' ) ) {
		return $html;
	}
	$faq = array();
	if ( preg_match_all( '#<details[^>]*class="[^"]*sokan-faq[^"]*"[^>]*>\s*<summary[^>]*>(.*?)</summary>(.*?)</details>#is', $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $q ) {
			$faq[] = array( '@type' => 'Question', 'name' => trim( wp_strip_all_tags( $q[1] ) ), 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => trim( wp_strip_all_tags( $q[2] ) ) ) );
		}
	}
	$has_ui = $faq || false !== strpos( $html, 'sokan-box' );
	if ( ! $has_ui ) {
		return $html;
	}
	$css = '.sokan-faq{border:1px solid #e5e7eb;border-radius:12px;margin:10px 0;background:#fff;overflow:hidden}'
		. '.sokan-faq>summary{cursor:pointer;list-style:none;padding:14px 18px;font-weight:700;display:flex;justify-content:space-between;gap:12px;align-items:center}'
		. '.sokan-faq>summary::-webkit-details-marker{display:none}'
		. '.sokan-faq>summary::after{content:"+";font-size:20px;line-height:1;transition:transform .2s}'
		. '.sokan-faq[open]>summary::after{transform:rotate(45deg)}'
		. '.sokan-faq>*:not(summary){padding:0 18px 14px;margin:0;line-height:2}'
		. '.sokan-box{border-inline-start:4px solid currentColor;background:rgba(0,0,0,.035);border-radius:10px;padding:14px 18px;margin:18px 0}'
		. '.sokan-faq-list{margin:28px 0}';
	// Blocks stay valid for the editor: a style block, the content, then the schema block.
	$out = "<!-- wp:html -->\n<style class=\"sokan-enhanced\">" . $css . "</style>\n<!-- /wp:html -->\n\n" . $html;
	if ( $faq ) {
		$out .= "\n\n<!-- wp:html -->\n<script type=\"application/ld+json\">" . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faq ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n<!-- /wp:html -->";
	}
	return $out;
}
