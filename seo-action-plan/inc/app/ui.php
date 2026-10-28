<?php
/**
 * Shared UI helpers for the app views.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Flash message from a redirect.
 */
function sap_flash() {
	if ( empty( $_GET['sap_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$ok = ! isset( $_GET['sap_ok'] ) || '1' === $_GET['sap_ok']; // phpcs:ignore
	printf(
		'<div class="sap-flash %s">%s</div>',
		$ok ? 'is-ok' : 'is-err',
		esc_html( sanitize_text_field( wp_unslash( $_GET['sap_msg'] ) ) ) // phpcs:ignore
	);
}

/**
 * Display mode of the current user: «simple» (guided, fewer tabs) or «pro».
 */
function sap_ui_mode() {
	$m = get_user_meta( get_current_user_id(), 'sap_mode', true );
	return 'pro' === $m ? 'pro' : 'simple';
}

/**
 * Project tabs. In simple mode only the everyday ones are shown; the others stay
 * reachable by URL and from the links that point to them.
 *
 * @param string $mode simple|pro|all (default: the user's mode).
 */
function sap_project_tabs( $mode = '' ) {
	$mode = $mode ?: sap_ui_mode();
	$all  = sap_project_tabs_all();
	if ( 'simple' !== $mode ) {
		return $all;
	}
	return array_diff_key( $all, array_flip( array( 'findings', 'insights', 'content' ) ) );
}

/**
 * Every project tab.
 */
function sap_project_tabs_all() {
	return array(
		'overview' => array( 'نمای کلی', 'grid' ),
		'alerts'   => array( 'هشدارها', 'bell' ),
		'insights' => array( 'تحلیل هوشمند', 'spark' ),
		'findings' => array( 'ایرادها و فرصت‌ها', 'inbox' ),
		'keywords' => array( 'نقشه کلمات', 'research' ),
		'content'  => array( 'بازنویسی محتوا', 'log' ),
		'plan'     => array( 'اکشن پلن ماهانه', 'list' ),
		'calendar' => array( 'تقویم محتوا', 'clock' ),
		'ideas'    => array( 'ایده‌های محتوا', 'spark' ),
		'activity' => array( 'لاگ اقدامات', 'log' ),
		'impact'   => array( 'ارزیابی اثر اقدامات', 'target' ),
		'focuses'  => array( 'Focus و اهداف', 'target' ),
		'graph'    => array( 'گراف کلمات و صفحات', 'link' ),
		'seosignal' => array( 'سئو سیگنال', 'research' ),
		'technical'   => array( 'چک‌لیست تکنیکال', 'settings' ),
		'audit'       => array( 'تحلیل محتوا', 'log' ),
		'serp'        => array( 'تحلیل نتایج گوگل', 'research' ),
		'offpage'     => array( 'برنامه آف‌پیج', 'link' ),
		'competitors' => array( 'رادار رقبا', 'radar' ),
		'ranks'       => array( 'ردیابی رتبه', 'chart' ),
		'automate'    => array( 'اتوماسیون سایت', 'bolt' ),
		'settings' => array( 'تنظیمات پروژه', 'settings' ),
	);
}

/**
 * Tabs grouped into a few sections (top bar) with sub-tabs (second bar), so the
 * project screen stays readable as features grow.
 *
 * @return array group => [label, icon, tab keys]
 */
function sap_project_groups() {
	return array(
		'home'      => array( 'نمای کلی', 'grid', array( 'overview', 'alerts', 'insights', 'focuses' ) ),
		'technical' => array( 'سئو تکنیکال', 'settings', array( 'technical' ) ),
		'content'   => array( 'سئو محتوا', 'log', array( 'audit', 'serp', 'keywords', 'calendar', 'ideas', 'content' ) ),
		'offpage'   => array( 'آف‌پیج', 'link', array( 'offpage' ) ),
		'keywords'  => array( 'رتبه و رقبا', 'chart', array( 'ranks', 'competitors', 'seosignal', 'graph' ) ),
		'work'      => array( 'برنامه و اجرا', 'list', array( 'plan', 'automate', 'impact', 'findings', 'activity' ) ),
	);
}

/**
 * Group of a tab.
 */
function sap_tab_group( $tab ) {
	foreach ( sap_project_groups() as $g => $def ) {
		if ( in_array( $tab, $def[2], true ) ) {
			return $g;
		}
	}
	return 'settings' === $tab ? 'settings' : 'home';
}

/**
 * Hidden fields for an admin-post form.
 */
function sap_form_fields( $action, $extra = array() ) {
	printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
	wp_nonce_field( $action, '_sap_nonce' );
	foreach ( $extra as $k => $v ) {
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
	}
}

/**
 * Score badge.
 */
function sap_score_badge( $score ) {
	$score = (float) $score;
	$cls   = $score >= 60 ? 'is-high' : ( $score >= 35 ? 'is-mid' : 'is-low' );
	return sprintf( '<span class="sap-score %s" title="امتیاز اولویت">%s</span>', $cls, esc_html( sap_num( $score ) ) );
}

/**
 * Work area chip.
 */
function sap_context_chip( $ctx ) {
	return sprintf( '<span class="sap-chip ctx-%s">%s</span>', esc_attr( $ctx ), esc_html( sap_context_label( $ctx ) ) );
}

/**
 * Render a finding's evidence table.
 */
function sap_evidence_table( $evidence, $project_id = 0 ) {
	$cols  = (array) ( $evidence['cols'] ?? array() );
	$items = (array) ( $evidence['items'] ?? array() );
	if ( ! $cols || ! $items ) {
		return;
	}
	echo '<div class="sap-table-wrap"><table class="sap-table is-compact"><thead><tr>';
	foreach ( $cols as $label ) {
		echo '<th>' . esc_html( $label ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $items as $it ) {
		echo '<tr>';
		foreach ( array_keys( $cols ) as $k ) {
			$v = $it[ $k ] ?? '';
			if ( 'page' === $k && ! empty( $it['url'] ) ) {
				// Older findings stored a short URL here: show the page title instead.
				$label = $project_id && ( '' === (string) $v || preg_match( '#^(/|https?:|[\w.-]+\.[a-z]{2,}/)#i', (string) $v ) ) ? sap_page_title( $project_id, $it['url'] ) : (string) $v;
				echo '<td>' . sap_page_link( $it['url'], $label ) . '</td>'; // phpcs:ignore
			} else {
				echo '<td>' . esc_html( is_numeric( $v ) ? sap_num( $v, floor( (float) $v ) != $v ? 1 : 0 ) : (string) $v ) . '</td>';
			}
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * Hidden inputs that keep a URL's query args in a GET filter form
 * (needed when the site has no pretty permalinks and routes via ?sap_route=).
 *
 * @return string Form action (URL without query).
 */
function sap_filter_form( $url ) {
	$parts = wp_parse_url( $url );
	parse_str( (string) ( $parts['query'] ?? '' ), $q );
	foreach ( $q as $k => $v ) {
		if ( is_scalar( $v ) ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
		}
	}
	return strtok( $url, '?' );
}

/**
 * Toggle switch (checkbox).
 */
function sap_switch( $name, $checked, $label, $hint = '' ) {
	printf(
		'<label class="sap-switch"><input type="checkbox" name="%s" value="1" %s><span class="sap-switch-track" aria-hidden="true"></span><span class="sap-switch-text"><b>%s</b>%s</span></label>',
		esc_attr( $name ),
		checked( (bool) $checked, true, false ),
		esc_html( $label ),
		$hint ? '<small>' . esc_html( $hint ) . '</small>' : ''
	);
}

/**
 * Segmented control (radio group).
 *
 * @param array $options value => label.
 */
function sap_segment( $name, $options, $current ) {
	echo '<div class="sap-segment" role="radiogroup">';
	foreach ( $options as $value => $label ) {
		printf(
			'<label><input type="radio" name="%s" value="%s" %s><span>%s</span></label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( (string) $current, (string) $value, false ),
			esc_html( $label )
		);
	}
	echo '</div>';
}

/**
 * Multi-select chips (checkboxes).
 */
function sap_chip_pick( $name, $options, $selected = array() ) {
	$selected = array_map( 'sap_kw_key', (array) $selected );
	echo '<div class="sap-chip-pick">';
	foreach ( $options as $value => $label ) {
		printf(
			'<label><input type="checkbox" name="%s[]" value="%s" %s><span>%s</span></label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( in_array( sap_kw_key( $value ), $selected, true ), true, false ),
			esc_html( $label )
		);
	}
	echo '</div>';
}

/**
 * Guided path of a project: five steps and the one thing to do now.
 *
 * @return array{steps: array, current: string}
 */
function sap_project_path( $project ) {
	$pid    = $project['id'];
	$period = sap_period_current();
	$tasks  = sap_tasks( array( 'project_id' => $pid, 'period' => $period ) );
	$review = sap_weekly_review( $pid );
	$steps  = array(
		'connect' => array( 'اتصال داده‌ها', '' !== $project['gsc_property'] || '' !== $project['sheet_id'] ),
		'analyze' => array( 'تحلیل', ! empty( $project['options']['last_sync'] ) ),
		'focus'   => array( 'Focus و هدف', (bool) sap_focuses( $pid, true ) || ! empty( $project['options']['focus_skipped'] ) ),
		'plan'    => array( 'اکشن پلن', (bool) $tasks ),
		'run'     => array( 'اجرا و بازبینی', $tasks && ! $review['due'] && ! $review['overdue'] ),
	);
	$current = 'done';
	foreach ( $steps as $k => $s ) {
		if ( ! $s[1] ) {
			$current = $k;
			break;
		}
	}
	return array( 'steps' => $steps, 'current' => $current, 'review' => $review, 'tasks' => $tasks );
}

/**
 * Labels of the AI health areas.
 */
function sap_health_areas() {
	return array(
		'technical' => 'تکنیکال',
		'content'   => 'محتوا',
		'onpage'    => 'سئو داخلی',
		'authority' => 'اعتبار و لینک',
		'focus'     => 'Focus',
	);
}

/**
 * Human title of a page: keyword map → page reader → crawl → short URL.
 */
function sap_page_title( $project_id, $url ) {
	static $idx = array();
	if ( '' === (string) $url ) {
		return '';
	}
	if ( ! isset( $idx[ $project_id ] ) ) {
		$t = array();
		foreach ( (array) ( sap_dataset_payload( $project_id, 'crawl' )['rows'] ?? array() ) as $r ) {
			$v = sap_clean_title( $r['title'] ?? '', $r['h1'] ?? '' );
			if ( '' !== $v ) {
				$t[ sap_norm_url( $r['url'] ) ] = $v;
			}
		}
		foreach ( (array) ( sap_dataset_payload( $project_id, 'pages' )['pages'] ?? array() ) as $k => $p ) {
			$v = sap_clean_title( $p['title'] ?? '', $p['h1'][0] ?? '' );
			if ( '' !== $v ) {
				$t[ $k ] = $v;
			}
		}
		foreach ( function_exists( 'sap_site_inventory' ) ? sap_site_inventory( $project_id ) : array() as $k => $it ) {
			if ( '' !== (string) $it['title'] ) {
				$t[ $k ] = $it['title']; // The title in WordPress itself.
			}
		}
		foreach ( sap_keymap( $project_id ) as $k => $m ) {
			if ( '' !== $m['title'] ) {
				$t[ $k ] = $m['title'];
			}
		}
		$idx[ $project_id ] = $t;
	}
	$k = sap_norm_url( $url );
	if ( isset( $idx[ $project_id ][ $k ] ) ) {
		return $idx[ $project_id ][ $k ];
	}
	return '' === trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ? 'صفحه اصلی' : sap_short_url( $url, 60 );
}

/**
 * Page title with a "view page" button (opens in a new tab).
 *
 * @param string $url   Page URL.
 * @param string $label Title (use sap_page_title()).
 */
function sap_page_link( $url, $label ) {
	if ( '' === (string) $url ) {
		return esc_html( $label );
	}
	return sprintf(
		'<span class="sap-pagelink"><span class="sap-pagelink-t" title="%1$s">%2$s</span><a class="sap-view" href="%1$s" target="_blank" rel="noopener" title="مشاهده صفحه" aria-label="مشاهده صفحه">%3$s</a></span>',
		esc_url( $url ),
		esc_html( $label ),
		sap_icon( 'link', 14 )
	);
}

/**
 * An address shown as text: decoded, isolated left-to-right (a Persian slug no
 * longer scrambles the line) and cut with an ellipsis; the full address on hover.
 */
function sap_url_tag( $url, $max = 70, $link = true ) {
	$url  = (string) $url;
	$full = rawurldecode( $url );
	$text = sap_short_url( $url, $max );
	$tag  = sprintf( '<bdi class="sap-url" dir="ltr" title="%s">%s</bdi>', esc_attr( $full ), esc_html( $text ) );
	return $link && '' !== $url ? sprintf( '<a class="sap-url-a" href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $url ), $tag ) : $tag;
}

/**
 * Absolute URL of a page the AI wrote as a path ("/product-category/x/").
 */
function sap_full_url( $project, $url ) {
	$url = trim( (string) $url );
	if ( '' === $url || preg_match( '#^https?://#i', $url ) ) {
		return $url;
	}
	if ( 'صفحه اصلی' === $url ) {
		$url = '/';
	}
	if ( '/' === $url[0] && function_exists( 'sap_site_inventory' ) ) {
		// A path written by the AI: the real address of that page on the client's WordPress.
		static $paths = array();
		if ( ! isset( $paths[ $project['id'] ] ) ) {
			$paths[ $project['id'] ] = array();
			foreach ( sap_site_inventory( $project['id'] ) as $it ) {
				$paths[ $project['id'] ][ untrailingslashit( rawurldecode( (string) wp_parse_url( $it['url'], PHP_URL_PATH ) ) ) ] = $it['url'];
			}
		}
		$path = untrailingslashit( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( isset( $paths[ $project['id'] ][ $path ] ) ) {
			return $paths[ $project['id'] ][ $path ];
		}
	}
	$base = $project['site_url'] ? untrailingslashit( $project['site_url'] ) : 'https://' . sap_project_domain( $project );
	return '/' === $url[0] ? preg_replace( '#^(https?://[^/]+).*$#i', '$1', $base ) . $url : $url;
}

/**
 * Page link (title + view button) for a URL or path written by the AI.
 */
function sap_page_ref( $project, $url ) {
	$full = sap_full_url( $project, $url );
	return preg_match( '#^https?://#i', $full ) ? sap_page_link( $full, sap_page_title( $project['id'], $full ) ) : esc_html( $url );
}

/**
 * Inline SVG line chart. Series: label => [x => value]; lower is better for
 * positions ($invert). Values may be null (gaps).
 *
 * @param array $series label => array of values (same x keys across series).
 * @param array $opt    width, height, invert, colors, min, max, labels (x labels).
 */
function sap_svg_lines( $series, $opt = array() ) {
	$o      = wp_parse_args( $opt, array( 'width' => 640, 'height' => 180, 'invert' => false, 'colors' => array( '#2563EB', '#F59E0B', '#16A34A', '#9333EA', '#DB2777', '#0891B2' ), 'min' => null, 'max' => null, 'pad' => 22, 'unit' => '' ) );
	$all    = array();
	$keys   = array();
	foreach ( $series as $vals ) {
		foreach ( $vals as $k => $v ) {
			$keys[ $k ] = true;
			if ( null !== $v ) {
				$all[] = (float) $v;
			}
		}
	}
	if ( ! $all ) {
		return '<p class="sap-muted sap-small">هنوز داده‌ای برای نمودار نیست.</p>';
	}
	$keys = array_keys( $keys );
	sort( $keys );
	$n    = count( $keys );
	$min  = null === $o['min'] ? min( $all ) : $o['min'];
	$max  = null === $o['max'] ? max( $all ) : $o['max'];
	if ( $max - $min < 0.001 ) {
		$max = $min + 1;
	}
	$w    = $o['width'];
	$h    = $o['height'];
	$p    = $o['pad'];
	$x    = function ( $i ) use ( $n, $w, $p ) {
		return $n > 1 ? $p + ( $w - 2 * $p ) * $i / ( $n - 1 ) : $w / 2;
	};
	$y    = function ( $v ) use ( $min, $max, $h, $p, $o ) {
		$r = ( $v - $min ) / ( $max - $min );
		return $o['invert'] ? $p + ( $h - 2 * $p ) * $r : $h - $p - ( $h - 2 * $p ) * $r;
	};
	$svg  = '<svg class="sap-chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img">';
	foreach ( array( 0, 0.5, 1 ) as $g ) {
		$gy   = $p + ( $h - 2 * $p ) * $g;
		$gv   = $o['invert'] ? $min + ( $max - $min ) * $g : $max - ( $max - $min ) * $g;
		$svg .= '<line x1="' . $p . '" x2="' . ( $w - $p ) . '" y1="' . round( $gy, 1 ) . '" y2="' . round( $gy, 1 ) . '" class="sap-chart-grid"/><text x="' . ( $w - 2 ) . '" y="' . round( $gy + 4, 1 ) . '" class="sap-chart-axis" text-anchor="end">' . esc_html( sap_num( $gv, $gv < 10 ? 1 : 0 ) . $o['unit'] ) . '</text>';
	}
	$ci = 0;
	foreach ( $series as $label => $vals ) {
		$col  = $o['colors'][ $ci++ % count( $o['colors'] ) ];
		$pts  = array();
		$segs = array();
		foreach ( $keys as $i => $k ) {
			$v = $vals[ $k ] ?? null;
			if ( null === $v ) {
				if ( $pts ) {
					$segs[] = $pts;
				}
				$pts = array();
				continue;
			}
			$pts[] = round( $x( $i ), 1 ) . ',' . round( $y( (float) $v ), 1 );
		}
		if ( $pts ) {
			$segs[] = $pts;
		}
		foreach ( $segs as $s ) {
			$svg .= count( $s ) > 1 ? '<polyline points="' . implode( ' ', $s ) . '" fill="none" stroke="' . $col . '" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"><title>' . esc_html( $label ) . '</title></polyline>' : '<circle cx="' . explode( ',', $s[0] )[0] . '" cy="' . explode( ',', $s[0] )[1] . '" r="2.5" fill="' . $col . '"/>';
		}
	}
	$svg .= '</svg>';
	if ( count( $series ) > 1 ) {
		$svg .= '<p class="sap-chart-legend">';
		$ci   = 0;
		foreach ( array_keys( $series ) as $label ) {
			$svg .= '<span><i style="background:' . $o['colors'][ $ci++ % count( $o['colors'] ) ] . '"></i>' . esc_html( $label ) . '</span>';
		}
		$svg .= '</p>';
	}
	return $svg;
}

/**
 * Tiny position sparkline (lower = better = higher on the line).
 */
function sap_svg_spark( $vals, $invert = true ) {
	$vals = array_values( $vals );
	$nums = array_filter( $vals, function ( $v ) {
		return null !== $v;
	} );
	if ( count( $nums ) < 2 ) {
		return '';
	}
	$min = min( $nums );
	$max = max( $nums );
	$max = $max - $min < 0.5 ? $min + 1 : $max;
	$n   = count( $vals );
	$pts = array();
	foreach ( $vals as $i => $v ) {
		if ( null === $v ) {
			continue;
		}
		$r     = ( $v - $min ) / ( $max - $min );
		$pts[] = round( 2 + 76 * $i / max( 1, $n - 1 ), 1 ) . ',' . round( $invert ? 2 + 18 * $r : 20 - 18 * $r, 1 );
	}
	$up = $invert ? end( $nums ) <= reset( $nums ) : end( $nums ) >= reset( $nums );
	return '<svg class="sap-spark" viewBox="0 0 80 22" aria-hidden="true"><polyline points="' . implode( ' ', $pts ) . '" fill="none" stroke="' . ( $up ? '#16A34A' : '#DC2626' ) . '" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/></svg>';
}

/**
 * Position change badge (positive = improved when lower is better).
 */
function sap_rank_delta( $now, $then ) {
	if ( null === $now || null === $then ) {
		return null === $then && null !== $now ? '<span class="sap-rd is-new">جدید</span>' : '<span class="sap-muted">—</span>';
	}
	$d = round( $then - $now, 1 );
	if ( abs( $d ) < 0.5 ) {
		return '<span class="sap-rd">•</span>';
	}
	return '<span class="sap-rd ' . ( $d > 0 ? 'is-up' : 'is-down' ) . '">' . ( $d > 0 ? '▲' : '▼' ) . esc_html( sap_num( abs( $d ), abs( $d ) < 10 ? 1 : 0 ) ) . '</span>';
}

/**
 * Pager links with a window around the current page (1 … 4 5 [6] 7 8 … 40),
 * so lists of thousands of products stay usable.
 */
function sap_pager_html( $pages, $cur, $base, $key = 'pg', $anchor = '' ) {
	if ( $pages < 2 ) {
		return '';
	}
	$show = array_unique( array_filter( array( 1, $pages, $cur - 2, $cur - 1, $cur, $cur + 1, $cur + 2 ), function ( $i ) use ( $pages ) {
		return $i >= 1 && $i <= $pages;
	} ) );
	sort( $show );
	$out  = '<nav class="sap-pager">';
	$prev = 0;
	foreach ( $show as $i ) {
		if ( $prev && $i - $prev > 1 ) {
			$out .= '<span>…</span>';
		}
		$out .= sprintf( '<a class="%s" href="%s">%s</a>', $i === $cur ? 'is-active' : '', esc_url( add_query_arg( $key, $i, $base ) . $anchor ), esc_html( sap_num( $i ) ) );
		$prev = $i;
	}
	return $out . '</nav>';
}
