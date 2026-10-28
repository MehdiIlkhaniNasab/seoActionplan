<?php
/**
 * Findings engine: rule-based detectors over the project's datasets.
 *
 * Every detector reads the analysis context and returns findings:
 *   detector, key (stable id), context (work area), title, detail, url, keyword,
 *   evidence {cols, items}, impact (est. extra clicks / month) or severity (0–100),
 *   confidence (0–1), effort (hours), focus_id, weight.
 * A detector only runs when its datasets exist, and only detectors that ran may
 * auto-resolve their old findings.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detector registry: id => [label, required datasets].
 */
function sap_detectors() {
	return array(
		'striking'       => array( 'کلمات نزدیک صفحه اول (Striking Distance)', array( 'gsc_qp' ) ),
		'low_ctr'        => array( 'CTR پایین در صفحه اول', array( 'gsc_qp' ) ),
		'cannibal'       => array( 'کنیبالیزیشن', array( 'gsc_qp' ) ),
		'kw_conflict'    => array( 'دو صفحه با کلمه اصلی یکسان (نقشه کلمات)', array( 'keymap' ) ),
		'kw_unowned'     => array( 'کلمه پرنمایش بدون صفحه اختصاصی', array( 'gsc_qp', 'keymap' ) ),
		'kw_rank_mismatch' => array( 'صفحه دیگری برای کلمه هدف رتبه بهتری دارد (احتمال کنیبال)', array( 'gsc_qp', 'keymap' ) ),
		'decay'          => array( 'افت ترافیک صفحات', array( 'gsc_pages' ) ),
		'sitemaps'       => array( 'سلامت سایت‌مپ‌ها', array( 'gsc_sitemaps' ) ),
		'cal_todo'       => array( 'محتوای منتشرنشده تقویم', array( 'sheet_calendar' ) ),
		'cal_update'     => array( 'محتوای نیازمند آپدیت (شیت)', array( 'sheet_calendar' ) ),
		'cal_under'      => array( 'مقالات منتشرشده کم‌بازده', array( 'sheet_calendar', 'gsc_qp' ) ),
		'cal_related'    => array( 'کلمات هم‌کانسپت بدون رتبه', array( 'sheet_calendar', 'gsc_qp' ) ),
		'cl_no_page'     => array( 'کلمات کلاستر بدون صفحه', array( 'sheet_clusters', 'gsc_qp' ) ),
		'cl_wrong_page'  => array( 'رتبه با صفحه اشتباه', array( 'sheet_clusters', 'gsc_qp' ) ),
		'cl_target_none' => array( 'صفحه هدف بدون رتبه', array( 'sheet_clusters', 'gsc_qp' ) ),
		'rank_drop'      => array( 'افت رتبه (ردیاب رتبه)', array( 'ss_rank' ) ),
		'gap'            => array( 'گپ کلمات با رقبا', array( 'ss_compare' ) ),
		'worse_than'     => array( 'کلماتی که رقیب بهتر رتبه دارد', array( 'ss_compare' ) ),
		'faq'            => array( 'ایده FAQ از سوالات کاربران', array( 'ss_questions' ) ),
		'crawl_4xx'      => array( 'خطای 4xx', array( 'crawl' ) ),
		'crawl_5xx'      => array( 'خطای 5xx', array( 'crawl' ) ),
		'crawl_3xx'      => array( 'ریدایرکت‌های داخلی', array( 'crawl' ) ),
		'crawl_meta'     => array( 'تایتل / متا / H1', array( 'crawl' ) ),
		'kw_title'       => array( 'کلمه اصلی در تایتل نیست', array( 'crawl', 'gsc_qp' ) ),
		'thin'           => array( 'محتوای کم‌حجم', array( 'crawl', 'gsc_qp' ) ),
		'weak_links'     => array( 'لینک داخلی ضعیف', array( 'crawl', 'gsc_qp' ) ),
		'noindex_impr'   => array( 'صفحات غیرقابل‌ایندکس با نمایش', array( 'crawl', 'gsc_qp' ) ),
		'slow'           => array( 'پاسخ کند سرور', array( 'crawl', 'gsc_qp' ) ),
		'schema'         => array( 'اسکیمای جاافتاده یا خراب', array( 'pages' ) ),
		'img_alt'        => array( 'تصاویر بدون Alt', array( 'pages' ) ),
		'cwv'            => array( 'Core Web Vitals ضعیف', array( 'cwv' ) ),
		'robots'         => array( 'robots.txt', array( 'robots' ) ),
		'sitemap_check'  => array( 'بررسی فایل سایت‌مپ', array( 'sitemap_check' ) ),
		'index_inspect'  => array( 'ایندکس صفحات مهم (URL Inspection)', array( 'gsc_inspect' ) ),
	);
}

/**
 * Expected CTR for an average position (Persian SERPs, conservative curve).
 */
function sap_ctr_expected( $pos ) {
	$pos   = (float) $pos;
	$curve = array( 1 => 0.28, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.055, 6 => 0.045, 7 => 0.035, 8 => 0.03, 9 => 0.025, 10 => 0.02 );
	if ( $pos <= 0 ) {
		return 0;
	}
	if ( $pos <= 10 ) {
		return $curve[ max( 1, (int) round( $pos ) ) ];
	}
	return $pos <= 20 ? 0.01 : 0.003;
}

/**
 * Build the analysis context: datasets + indexes shared by detectors.
 */
function sap_analysis_context( $project ) {
	$pid = $project['id'];
	$ctx = array(
		'project'  => $project,
		'focuses'  => sap_focus_context( $pid ),
		'have'     => array(),
		'settings' => sap_settings(),
	);
	foreach ( array_keys( sap_dataset_labels() ) as $src ) {
		$d = sap_dataset( $pid, $src );
		if ( $d ) {
			$ctx['have'][ $src ] = true;
			$ctx[ $src ]         = $d['payload'];
			$ctx[ $src . '_meta' ] = $d['meta'];
		}
	}
	// Query × page indexes.
	$ctx['by_page']  = array();
	$ctx['by_query'] = array();
	$ctx['page_url'] = array();
	foreach ( (array) ( $ctx['gsc_qp']['rows'] ?? array() ) as $r ) {
		$p                       = sap_norm_url( $r['page'] );
		$q                       = sap_kw_key( $r['query'] );
		$ctx['page_url'][ $p ]   = $r['page'];
		$ctx['by_page'][ $p ][]  = $r;
		$ctx['by_query'][ $q ][] = $r;
	}
	$ctx['page_impr'] = array();
	foreach ( $ctx['by_page'] as $p => $rows ) {
		$ctx['page_impr'][ $p ] = array_sum( array_column( $rows, 'impressions' ) );
	}
	// Volumes from SEO Signal.
	$ctx['volumes'] = (array) ( $ctx['ss_volumes']['volumes'] ?? array() );
	// Crawl by URL.
	$ctx['crawl_by_url'] = array();
	foreach ( (array) ( $ctx['crawl']['rows'] ?? array() ) as $r ) {
		$ctx['crawl_by_url'][ sap_norm_url( $r['url'] ) ] = $r;
	}
	// Keyword map: what each page is meant to rank for.
	$ctx['keymap']    = sap_keymap( $pid );
	$ctx['kw_index']  = sap_keymap_index( $ctx['keymap'] );
	if ( $ctx['keymap'] ) {
		$ctx['have']['keymap'] = true;
	}
	$ctx['has_focus'] = ! empty( $ctx['focuses'] );
	return $ctx;
}

/**
 * Search volume of a keyword (0 when unknown).
 */
function sap_ctx_volume( $ctx, $keyword ) {
	return (int) ( $ctx['volumes'][ sap_kw_key( $keyword ) ]['volume'] ?? 0 );
}

/**
 * Best GSC row of a keyword (most impressions; ties → best position).
 */
function sap_ctx_best( $ctx, $keyword, $page = '' ) {
	$best = null;
	foreach ( (array) ( $ctx['by_query'][ sap_kw_key( $keyword ) ] ?? array() ) as $r ) {
		if ( '' !== $page && sap_norm_url( $r['page'] ) !== sap_norm_url( $page ) ) {
			continue;
		}
		if ( ! $best || $r['impressions'] > $best['impressions'] || ( $r['impressions'] === $best['impressions'] && $r['position'] < $best['position'] ) ) {
			$best = $r;
		}
	}
	return $best;
}

/**
 * Attach focus + weight to a finding.
 */
function sap_tag_focus( $ctx, $f ) {
	if ( array_key_exists( 'focus_id', $f ) ) {
		// The detector already decided (per-focus split lists).
		$fid = (int) $f['focus_id'];
		$w   = $ctx['has_focus'] ? ( ( (float) ( $f['severity'] ?? 0 ) >= 70 ) ? 1.0 : 0.8 ) : 1.0;
		foreach ( $ctx['focuses'] as $fc ) {
			if ( $fc['id'] === $fid ) {
				$w = $fc['weight'];
			}
		}
		$f['focus_id'] = $fid;
		$f['weight']   = $w;
		return $f;
	}
	list( $fid, $w ) = sap_focus_match( $ctx['focuses'], $f['url'] ?? '', $f['keyword'] ?? '' );
	if ( ! $fid && ! empty( $f['evidence']['items'] ) ) {
		// Grouped finding: belongs to a focus when most of its items do.
		$hits = array();
		foreach ( $f['evidence']['items'] as $it ) {
			list( $ifid ) = sap_focus_match( $ctx['focuses'], $it['url'] ?? '', $it['q'] ?? ( $it['kw'] ?? '' ) );
			$hits[ $ifid ] = ( $hits[ $ifid ] ?? 0 ) + 1;
		}
		arsort( $hits );
		$top = (int) array_key_first( $hits );
		if ( $top && $hits[ $top ] * 2 >= count( $f['evidence']['items'] ) ) {
			foreach ( $ctx['focuses'] as $fc ) {
				if ( $fc['id'] === $top ) {
					$fid = $top;
					$w   = $fc['weight'];
				}
			}
		}
	}
	if ( ! $fid && $ctx['has_focus'] ) {
		// Out-of-focus work is deprioritized, except critical site-wide problems.
		$w = ( (float) ( $f['severity'] ?? 0 ) >= 70 ) ? 1.0 : 0.8;
	}
	$f['focus_id'] = $fid;
	$f['weight']   = $w;
	return $f;
}

/**
 * Split list items by focus so each focus gets its own finding
 * ("site-wide problems that affect the focus").
 *
 * @return array focus_id => items
 */
function sap_split_by_focus( $ctx, $items ) {
	$out = array();
	foreach ( $items as $it ) {
		list( $fid ) = sap_focus_match( $ctx['focuses'], $it['url'] ?? '', $it['q'] ?? ( $it['kw'] ?? '' ) );
		$out[ $fid ][] = $it;
	}
	return $out;
}

/**
 * Focus name by id.
 */
function sap_ctx_focus_name( $ctx, $fid ) {
	foreach ( $ctx['focuses'] as $f ) {
		if ( $f['id'] === (int) $fid ) {
			return $f['name'];
		}
	}
	return '';
}

/**
 * Run all applicable detectors.
 *
 * @return array{findings: array, ran: string[], skipped: string[]}
 */
function sap_run_detectors( $project ) {
	$ctx      = sap_analysis_context( $project );
	$findings = array();
	$ran      = array();
	$skipped  = array();
	foreach ( sap_detectors() as $id => $def ) {
		foreach ( $def[1] as $need ) {
			if ( empty( $ctx['have'][ $need ] ) ) {
				$skipped[] = $id;
				continue 2;
			}
		}
		$fn = 'sap_detect_' . $id;
		if ( ! function_exists( $fn ) ) {
			continue;
		}
		foreach ( (array) call_user_func( $fn, $ctx ) as $f ) {
			$f['detector'] = $id;
			$findings[]    = sap_tag_focus( $ctx, $f );
		}
		$ran[] = $id;
	}
	return array( 'findings' => $findings, 'ran' => $ran, 'skipped' => $skipped, 'ctx' => $ctx );
}

/* ---------------------------------------------------------------------------
 * Search Console detectors
 * ------------------------------------------------------------------------- */

/**
 * Queries ranking 4–20 with real impressions, grouped per page.
 */
function sap_detect_striking( $ctx ) {
	$min   = (int) $ctx['settings']['min_impressions'];
	$pages = array();
	foreach ( $ctx['gsc_qp']['rows'] as $r ) {
		if ( $r['position'] < 4 || $r['position'] > 20 || $r['impressions'] < $min || sap_is_brand( $r['query'], $ctx['project'] ) ) {
			continue;
		}
		$pages[ sap_norm_url( $r['page'] ) ][] = $r;
	}
	$out = array();
	foreach ( $pages as $p => $rows ) {
		$rows   = sap_sort( $rows, 'impressions' );
		$impact = 0;
		foreach ( $rows as $r ) {
			$impact += $r['impressions'] * max( 0, sap_ctr_expected( 3 ) - $r['ctr'] );
		}
		$url   = $ctx['page_url'][ $p ];
		$items = array();
		foreach ( array_slice( $rows, 0, 12 ) as $r ) {
			$items[] = array( 'q' => $r['query'], 'pos' => round( $r['position'], 1 ), 'impr' => $r['impressions'], 'clicks' => $r['clicks'] );
		}
		$out[] = array(
			'key'        => $p,
			'context'    => 'content_update',
			'title'      => sprintf( 'بهینه‌سازی %s برای %s کلمه نزدیک صفحه اول', '«' . sap_ctx_title( $ctx, $url ) . '»', sap_num( count( $rows ) ) ),
			'detail'     => 'این کلمات در جایگاه ۴ تا ۲۰ هستند و نمایش واقعی دارند. با افزودن کلمه به H2 و متن، پاسخ کامل‌تر به نیت جستجو، FAQ مرتبط و چند لینک داخلی با انکر همین کلمات، می‌توانند به رتبه‌های ۱ تا ۳ برسند.',
			'url'        => $url,
			'keyword'    => $rows[0]['query'],
			'evidence'   => array( 'cols' => array( 'q' => 'کوئری', 'pos' => 'جایگاه', 'impr' => 'نمایش', 'clicks' => 'کلیک' ), 'items' => $items ),
			'impact'     => $impact,
			'confidence' => 0.7,
			'effort'     => count( $rows ) > 5 ? 3 : 2,
		);
	}
	return array_slice( sap_sort( $out, 'impact' ), 0, 60 );
}

/**
 * Page-one queries whose CTR is far below the expected curve → title/meta rewrite.
 */
function sap_detect_low_ctr( $ctx ) {
	$min   = (int) $ctx['settings']['ctr_min_impressions'];
	$pages = array();
	foreach ( $ctx['gsc_qp']['rows'] as $r ) {
		if ( $r['position'] > 6 || $r['impressions'] < $min || sap_is_brand( $r['query'], $ctx['project'] ) ) {
			continue;
		}
		$exp = sap_ctr_expected( $r['position'] );
		if ( $r['ctr'] < $exp * 0.5 ) {
			$r['exp']                              = $exp;
			$pages[ sap_norm_url( $r['page'] ) ][] = $r;
		}
	}
	$out = array();
	foreach ( $pages as $p => $rows ) {
		$rows   = sap_sort( $rows, 'impressions' );
		$impact = 0;
		$items  = array();
		foreach ( $rows as $r ) {
			$impact += $r['impressions'] * ( $r['exp'] - $r['ctr'] );
			if ( count( $items ) < 10 ) {
				$items[] = array( 'q' => $r['query'], 'pos' => round( $r['position'], 1 ), 'ctr' => sap_pct( $r['ctr'] ), 'exp' => sap_pct( $r['exp'] ), 'impr' => $r['impressions'] );
			}
		}
		$url    = $ctx['page_url'][ $p ];
		$crawl  = $ctx['crawl_by_url'][ $p ] ?? null;
		$detail = 'این صفحه در صفحه اول است ولی CTR آن کمتر از نصف حد انتظار جایگاهش است. تایتل را با کلمه اصلی در ابتدای آن، مزیت روشن (قیمت، ارسال، گارانتی، سال) و متای ترغیب‌کننده بازنویسی کنید؛ بررسی اسکیمای محصول/نظرات هم برای نمایش ستاره مفید است.';
		if ( $crawl && '' !== $crawl['title'] ) {
			$detail .= "\nتایتل فعلی: " . $crawl['title'];
		}
		$out[] = array(
			'key'        => $p,
			'context'    => 'onpage',
			'title'      => sprintf( 'بازنویسی تایتل و متای %s (CTR پایین)', '«' . sap_ctx_title( $ctx, $url ) . '»' ),
			'detail'     => $detail,
			'url'        => $url,
			'keyword'    => $rows[0]['query'],
			'evidence'   => array( 'cols' => array( 'q' => 'کوئری', 'pos' => 'جایگاه', 'ctr' => 'CTR', 'exp' => 'CTR مورد انتظار', 'impr' => 'نمایش' ), 'items' => $items ),
			'impact'     => $impact,
			'confidence' => 0.6,
			'effort'     => 0.5,
		);
	}
	return array_slice( sap_sort( $out, 'impact' ), 0, 40 );
}

/**
 * One query, several pages competing for it.
 */
function sap_detect_cannibal( $ctx ) {
	$min = (int) $ctx['settings']['min_impressions'] * 2;
	$map = $ctx['keymap'];
	$idx = $ctx['kw_index'];
	$out = array();
	$seen_core = array();
	$queries   = array_values( $ctx['by_query'] );
	usort( $queries, function ( $a, $b ) {
		return array_sum( array_column( $b, 'impressions' ) ) <=> array_sum( array_column( $a, 'impressions' ) );
	} );
	foreach ( $queries as $rows ) {
		if ( count( $rows ) < 2 ) {
			continue;
		}
		$total = array_sum( array_column( $rows, 'impressions' ) );
		if ( $total < $min || sap_is_brand( $rows[0]['query'], $ctx['project'] ) ) {
			continue;
		}
		$comp = array_filter( $rows, function ( $r ) use ( $total ) {
			return $r['impressions'] >= 0.1 * $total && $r['position'] <= 30;
		} );
		if ( count( $comp ) < 2 ) {
			continue;
		}
		// Navigational: the home page already ranks top 3 (brand / site-name searches show home + shop).
		$home_top = false;
		foreach ( $comp as $r ) {
			$path = trim( (string) wp_parse_url( $r['page'], PHP_URL_PATH ), '/' );
			if ( '' === $path && $r['position'] <= 3 ) {
				$home_top = true;
			}
		}
		if ( $home_top ) {
			continue;
		}
		$q    = $rows[0]['query'];
		$core = sap_kw_core( $q );
		$comp = sap_sort( array_values( $comp ), 'impressions' );

		// Role of each competing page for this query, from the keyword map:
		// its primary keyword (100), a secondary keyword (50), or a long-tail of
		// its primary keyword (number of words). The most specific page owns it.
		$qw    = explode( ' ', $core );
		$spec  = array();
		$mapped = 0;
		$items = array();
		$roles = array();
		foreach ( $comp as $i => $r ) {
			$k    = sap_norm_url( $r['page'] );
			$role = 'side';
			$sc   = 0;
			if ( isset( $map[ $k ] ) && '' !== $map[ $k ]['core'] ) {
				++$mapped;
				$pw = explode( ' ', $map[ $k ]['core'] );
				if ( $map[ $k ]['core'] === $core ) {
					$role = 'primary';
					$sc   = 100;
				} elseif ( in_array( $k, (array) ( $idx['secondary'][ $core ] ?? array() ), true ) ) {
					$role = 'secondary';
					$sc   = 50;
				} elseif ( ! array_diff( $pw, $qw ) ) {
					$role = 'variant';
					$sc   = count( $pw );
				}
			}
			$spec[ $i ]  = $sc;
			$roles[ $i ] = $role;
			$items[]     = array(
				'url'    => $r['page'],
				'page'   => sap_ctx_title( $ctx, $r['page'] ),
				'kw'     => isset( $map[ $k ] ) ? $map[ $k ]['primary_kw'] : '—',
				'role'   => array( 'primary' => 'کلمه اصلی همین صفحه', 'secondary' => 'کلمه فرعی همین صفحه', 'variant' => 'نسخه بلندتر کلمه اصلی صفحه', 'side' => 'نمایش جانبی' )[ $role ],
				'pos'    => round( $r['position'], 1 ),
				'impr'   => $r['impressions'],
				'clicks' => $r['clicks'],
			);
		}
		$cols = array( 'page' => 'صفحه', 'kw' => 'کلمه اصلی صفحه', 'role' => 'نقش این کوئری', 'pos' => 'جایگاه', 'impr' => 'نمایش', 'clicks' => 'کلیک' );
		$prim = array();
		foreach ( $roles as $i => $role ) {
			if ( 'primary' === $role ) {
				$prim[] = $comp[ $i ];
			}
		}
		$owners = array();
		if ( max( $spec ) > 0 ) {
			$owners[] = $comp[ array_search( max( $spec ), $spec, true ) ];
		}

		if ( count( $prim ) >= 2 ) {
			// Two pages are built for the same keyword: real cannibalization (once per topic).
			if ( isset( $seen_core[ $core ] ) ) {
				continue;
			}
			$seen_core[ $core ] = true;
			$out[] = array(
				'key'        => sap_kw_key( $q ),
				'context'    => 'content_update',
				'title'      => sprintf( 'کنیبالیزیشن واقعی: «%s» کلمه اصلی %s صفحه است', $q, sap_num( count( $prim ) ) ),
				'detail'     => sprintf( 'طبق نقشه کلمات، «%s» کلمه اصلی بیش از یک صفحه است و گوگل نمایش را بینشان تقسیم کرده. یک صفحه را صاحب این کلمه نگه دارید (معمولاً «%s»)، صفحه دیگر را روی کلمه متفاوتی (مثلاً یک زیرموضوع) متمایز کنید یا ادغام و ریدایرکت 301 کنید، و از صفحه دیگر با انکر همین کلمه به صفحه اصلی لینک بدهید.', $q, sap_ctx_title( $ctx, $prim[0]['page'] ) ),
				'url'        => $prim[0]['page'],
				'keyword'    => $q,
				'evidence'   => array( 'cols' => $cols, 'items' => $items, 'kind' => 'same_primary' ),
				'impact'     => $total * 0.04,
				'confidence' => 0.85,
				'effort'     => 2,
			);
			continue;
		}
		if ( $owners ) {
			// One page owns the keyword; the others are double / side results.
			// It is a problem only when another page outranks the owner.
			$own   = $owners[0];
			$rival = null;
			foreach ( $comp as $r ) {
				if ( sap_norm_url( $r['page'] ) !== sap_norm_url( $own['page'] ) && $r['position'] + 1 < $own['position'] && $r['impressions'] >= 0.3 * $total ) {
					$rival = $r;
					break;
				}
			}
			if ( ! $rival || ( $own['position'] <= 10 && $rival['position'] <= 10 ) ) {
				continue; // Double result (both on page one) or the owner leads: fine.
			}
			$out[] = array(
				'key'        => sap_kw_key( $q ),
				'context'    => 'content_update',
				'title'      => sprintf( '«%s»: صفحه «%s» جای صفحه اصلی این کلمه رتبه گرفته', $q, sap_ctx_title( $ctx, $rival['page'] ) ),
				'detail'     => sprintf( 'صاحب این کلمه در نقشه کلمات «%s» است (جایگاه %s)، ولی گوگل «%s» را بالاتر (جایگاه %s) نشان می‌دهد. سیگنال صفحه اصلی را قوی کنید: کلمه در تایتل و H1، پوشش کامل موضوع، و لینک از صفحه رقیب با انکر «%s» به صفحه اصلی. در صفحه رقیب تمرکز را روی کلمه اصلی خودش نگه دارید.', sap_ctx_title( $ctx, $own['page'] ), sap_num( $own['position'], 1 ), sap_ctx_title( $ctx, $rival['page'] ), sap_num( $rival['position'], 1 ), $q ),
				'url'        => $own['page'],
				'keyword'    => $q,
				'evidence'   => array( 'cols' => $cols, 'items' => $items, 'kind' => 'wrong_page' ),
				'impact'     => $total * 0.02,
				'confidence' => 0.7,
				'effort'     => 1.5,
			);
			continue;
		}
		if ( $mapped >= count( $comp ) ) {
			continue; // Every page has a different primary keyword: side impressions, reported by kw_unowned.
		}
		// Pages without a known primary keyword: the old heuristic, with low confidence.
		$out[] = array(
			'key'        => sap_kw_key( $q ),
			'context'    => 'content_update',
			'title'      => sprintf( 'کنیبالیزیشن احتمالی: «%s» بین %s صفحه تقسیم شده', $q, sap_num( count( $comp ) ) ),
			'detail'     => 'چند صفحه برای این کوئری نمایش می‌گیرند ولی کلمه اصلی همه آن‌ها در نقشه کلمات مشخص نیست. اول در تب «نقشه کلمات» کلمه اصلی این صفحات را تأیید کنید تا مشخص شود کنیبال واقعی است یا نمایش جانبی (دابل ریزالت).',
			'url'        => $comp[0]['page'],
			'keyword'    => $q,
			'evidence'   => array( 'cols' => $cols, 'items' => $items, 'kind' => 'unknown' ),
			'impact'     => $total * 0.01,
			'confidence' => 0.4,
			'effort'     => 2,
		);
	}
	return array_slice( sap_sort( $out, 'impact' ), 0, 40 );
}

/**
 * Keyword map conflicts: two pages assigned the same primary keyword
 * (even before Search Console shows them competing).
 */
function sap_detect_kw_conflict( $ctx ) {
	$out = array();
	foreach ( $ctx['kw_index']['primary'] as $core => $keys ) {
		$keys = array_values( array_filter( $keys, function ( $k ) use ( $ctx ) {
			return 'home' !== $ctx['keymap'][ $k ]['page_type'];
		} ) );
		if ( count( $keys ) < 2 ) {
			continue;
		}
		$items = array();
		foreach ( $keys as $k ) {
			$m       = $ctx['keymap'][ $k ];
			$items[] = array( 'url' => $m['url'], 'page' => sap_ctx_title( $ctx, $m['url'] ), 'kw' => $m['primary_kw'], 'src' => sap_keymap_sources()[ $m['source'] ] ?? $m['source'], 'impr' => (int) ( $ctx['page_impr'][ $k ] ?? 0 ) );
		}
		$items = sap_sort( $items, 'impr' );
		$kw    = $ctx['keymap'][ $keys[0] ]['primary_kw'];
		$out[] = array(
			'key'        => $core,
			'context'    => 'research',
			'title'      => sprintf( 'نقشه کلمات: «%s» کلمه اصلی %s صفحه است', $kw, sap_num( count( $keys ) ) ),
			'detail'     => 'هر کلمه باید فقط یک صفحه صاحب داشته باشد. در تب «نقشه کلمات» برای صفحه‌های دیگر کلمه اصلی متمایز (زیرموضوع، مدل، کاربرد) انتخاب کنید؛ اگر محتوای آن‌ها واقعاً یکی است، ادغام و ریدایرکت را در نظر بگیرید.',
			'url'        => $items[0]['url'],
			'keyword'    => $kw,
			'evidence'   => array( 'cols' => array( 'page' => 'صفحه', 'kw' => 'کلمه اصلی', 'src' => 'منبع', 'impr' => 'نمایش' ), 'items' => $items ),
			'severity'   => 45,
			'confidence' => 0.75,
			'effort'     => 0.5,
		);
	}
	return $out;
}

/**
 * Queries with real impressions that no page owns: pages show up for them only
 * as a side effect (e.g. «کفی» on the «کفی ورزشی» category). Not cannibalization
 * but an opportunity: give the keyword an owner (an existing parent page or a new one).
 */
function sap_detect_kw_unowned( $ctx ) {
	$min = (int) $ctx['settings']['min_impressions'] * 3;
	$out = array();
	foreach ( $ctx['by_query'] as $rows ) {
		$q     = $rows[0]['query'];
		$total = array_sum( array_column( $rows, 'impressions' ) );
		if ( $total < $min || sap_is_brand( $q, $ctx['project'] ) ) {
			continue;
		}
		$core = sap_kw_core( $q );
		if ( isset( $ctx['kw_index']['primary'][ $core ] ) || isset( $ctx['kw_index']['secondary'][ $core ] ) ) {
			continue;
		}
		$best = min( array_column( $rows, 'position' ) );
		if ( $best <= 3 ) {
			continue; // Already ranking well without an owner.
		}
		$rows  = sap_sort( $rows, 'impressions' );
		$items = array();
		foreach ( array_slice( $rows, 0, 4 ) as $r ) {
			$k       = sap_norm_url( $r['page'] );
			$items[] = array( 'url' => $r['page'], 'page' => sap_ctx_title( $ctx, $r['page'] ), 'kw' => $ctx['keymap'][ $k ]['primary_kw'] ?? '—', 'pos' => round( $r['position'], 1 ), 'impr' => $r['impressions'] );
		}
		$broader = false;
		$variant = false;
		$qw      = explode( ' ', $core );
		foreach ( $rows as $r ) {
			$m = $ctx['keymap'][ sap_norm_url( $r['page'] ) ] ?? null;
			if ( ! $m || '' === $m['core'] ) {
				continue;
			}
			$pw = explode( ' ', $m['core'] );
			if ( ! array_diff( $pw, $qw ) ) {
				$variant = true; // «کفی ورزشی نایک» is a long-tail of «کفی ورزشی»: that page owns it.
			} elseif ( ! array_diff( $qw, $pw ) ) {
				$broader = true; // «کفی» is part of «کفی ورزشی»: a broader, parent keyword.
			}
		}
		if ( $variant || ( ! $broader && count( $rows ) < 2 ) ) {
			continue;
		}
		$out[] = array(
			'key'        => sap_kw_key( $q ),
			'context'    => 'research',
			'title'      => sprintf( '«%s» (%s نمایش) صفحه اختصاصی ندارد', $q, sap_num( $total ) ),
			'detail'     => ( $broader
				? sprintf( '«%s» کلمه‌ای کلی‌تر از کلمه اصلی صفحاتی است که برایش نمایش می‌گیرند؛ این کنیبال نیست، نمایش جانبی است. اگر این کلمه برای کسب‌وکار ارزش دارد، صاحب مناسبش را مشخص کنید: دسته والد (اگر هست) یا یک صفحه/مقاله جامع جدید، و آن را در نقشه کلمات ثبت کنید.', $q )
				: sprintf( 'هیچ صفحه‌ای در نقشه کلمات صاحب «%s» نیست و صفحات فعلی فقط به‌صورت جانبی نمایش می‌گیرند (بهترین جایگاه %s). آن را به‌عنوان کلمه فرعی به مرتبط‌ترین صفحه بدهید و در متن پوشش دهید، یا اگر نیت جداگانه‌ای دارد، صفحه جدید بسازید.', $q, sap_num( $best, 1 ) ) ),
			'url'        => '',
			'keyword'    => $q,
			'evidence'   => array( 'cols' => array( 'page' => 'صفحه نمایش‌گرفته', 'kw' => 'کلمه اصلی آن صفحه', 'pos' => 'جایگاه', 'impr' => 'نمایش' ), 'items' => $items ),
			'impact'     => $total * sap_ctr_expected( 5 ) * 0.5,
			'confidence' => 0.55,
			'effort'     => 2,
		);
	}
	return array_slice( sap_sort( $out, 'impact' ), 0, 25 );
}

/**
 * Human title of a page (keyword map → page reader → crawl → short URL).
 */
function sap_ctx_title( $ctx, $url ) {
	$k = sap_norm_url( $url );
	if ( '' !== ( $ctx['keymap'][ $k ]['title'] ?? '' ) ) {
		return $ctx['keymap'][ $k ]['title'];
	}
	$p = $ctx['pages']['pages'][ $k ] ?? ( $ctx['pages'][ $k ] ?? null );
	if ( is_array( $p ) && isset( $p['title'] ) ) {
		$t = sap_clean_title( $p['title'], $p['h1'][0] ?? '' );
		if ( '' !== $t ) {
			return $t;
		}
	}
	$c = $ctx['crawl_by_url'][ $k ] ?? null;
	if ( $c ) {
		$t = sap_clean_title( $c['title'] ?? '', $c['h1'] ?? '' );
		if ( '' !== $t ) {
			return $t;
		}
	}
	return sap_short_url( $url, 60 );
}

/**
 * Pages that lost a large share of their clicks vs the previous 28 days.
 */
function sap_detect_decay( $ctx ) {
	$min  = (int) $ctx['settings']['decay_min_clicks'];
	$drop = (float) $ctx['settings']['decay_drop'] / 100;
	$cur  = array();
	foreach ( (array) ( $ctx['gsc_pages']['cur'] ?? array() ) as $r ) {
		$cur[ sap_norm_url( $r['page'] ) ] = $r;
	}
	$out = array();
	foreach ( (array) ( $ctx['gsc_pages']['prev'] ?? array() ) as $r ) {
		if ( $r['clicks'] < $min ) {
			continue;
		}
		$p   = sap_norm_url( $r['page'] );
		$now = $cur[ $p ] ?? array( 'clicks' => 0, 'impressions' => 0, 'position' => 0 );
		$chg = ( $r['clicks'] - $now['clicks'] ) / max( 1, $r['clicks'] );
		if ( $chg < $drop ) {
			continue;
		}
		$out[] = array(
			'key'        => $p,
			'context'    => 'content_update',
			'title'      => sprintf( 'افت %s٪ کلیک صفحه %s', sap_num( $chg * 100 ), '«' . sap_ctx_title( $ctx, $r['page'] ) . '»' ),
			'detail'     => 'کلیک این صفحه نسبت به ۲۸ روز قبل به‌شدت افت کرده است. نتایج گوگل برای کلمه اصلی را بررسی کنید (رقیب جدید، تغییر نیت جستجو، AI Overview)، تاریخ و اطلاعات محتوا را بروز کنید، ایندکس بودن و تغییرات اخیر تایتل/کنونیکال را چک کنید.',
			'url'        => $r['page'],
			'evidence'   => array(
				'cols'  => array( 'm' => 'شاخص', 'prev' => '۲۸ روز قبل', 'cur' => '۲۸ روز اخیر' ),
				'items' => array(
					array( 'm' => 'کلیک', 'prev' => $r['clicks'], 'cur' => $now['clicks'] ),
					array( 'm' => 'نمایش', 'prev' => $r['impressions'], 'cur' => $now['impressions'] ),
					array( 'm' => 'جایگاه', 'prev' => round( $r['position'], 1 ), 'cur' => round( $now['position'], 1 ) ),
				),
			),
			'impact'     => $r['clicks'] - $now['clicks'],
			'confidence' => 0.75,
			'effort'     => 2,
		);
	}
	return array_slice( sap_sort( $out, 'impact' ), 0, 30 );
}

/**
 * Sitemaps: missing, errors, warnings, not read recently.
 */
function sap_detect_sitemaps( $ctx ) {
	$maps = (array) ( $ctx['gsc_sitemaps']['sitemaps'] ?? array() );
	if ( empty( $maps ) ) {
		return array( array(
			'key'      => 'none',
			'context'  => 'technical',
			'title'    => 'هیچ سایت‌مپی در سرچ کنسول ثبت نشده است',
			'detail'   => 'سایت‌مپ (مثلاً /sitemap_index.xml رنک‌مث) را در بخش Sitemaps سرچ کنسول ثبت کنید تا گوگل صفحات جدید را سریع‌تر پیدا کند.',
			'severity' => 70,
			'effort'   => 0.25,
		) );
	}
	$out = array();
	foreach ( $maps as $m ) {
		$age = $m['last_downloaded'] ? ( time() - strtotime( $m['last_downloaded'] ) ) / DAY_IN_SECONDS : 999;
		if ( $m['errors'] > 0 || $m['warnings'] > 0 ) {
			$out[] = array(
				'key'      => 'err:' . $m['path'],
				'context'  => 'technical',
				'title'    => sprintf( 'سایت‌مپ %s دارای %s خطا و %s هشدار است', sap_short_url( $m['path'], 50 ), sap_num( $m['errors'] ), sap_num( $m['warnings'] ) ),
				'detail'   => 'جزئیات خطا را در بخش Sitemaps سرچ کنسول ببینید (آدرس‌های ریدایرکت‌شده، noindex یا 404 در سایت‌مپ رایج‌ترین علت‌ها هستند) و تنظیمات سایت‌مپ رنک‌مث را اصلاح کنید.',
				'url'      => $m['path'],
				'severity' => $m['errors'] > 0 ? 65 : 35,
				'effort'   => 1,
			);
		}
		if ( $age > 30 ) {
			$out[] = array(
				'key'      => 'stale:' . $m['path'],
				'context'  => 'technical',
				'title'    => sprintf( 'گوگل سایت‌مپ %s را بیش از ۳۰ روز است نخوانده', sap_short_url( $m['path'], 50 ) ),
				'detail'   => 'سایت‌مپ را دوباره در سرچ کنسول ارسال کنید و در دسترس بودن آن (کد 200، بدون بلاک در robots.txt و فایروال) را بررسی کنید.',
				'url'      => $m['path'],
				'severity' => 40,
				'effort'   => 0.5,
			);
		}
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * Google Sheets detectors (content calendar + clustering)
 * ------------------------------------------------------------------------- */

/**
 * Content calendar rows not yet published → produce / follow up.
 */
function sap_detect_cal_todo( $ctx ) {
	list( , $period_end ) = sap_period_range( sap_period_current() );
	$out                  = array();
	foreach ( (array) ( $ctx['sheet_calendar']['rows'] ?? array() ) as $r ) {
		if ( '' !== $r['url'] || in_array( $r['status_key'], array( 'published', 'needs_update' ), true ) ) {
			continue;
		}
		$vol     = sap_ctx_volume( $ctx, $r['keyword'] );
		$due_now = $r['due_date'] && $r['due_date'] <= $period_end;
		$waiting = in_array( $r['status_key'], array( 'ordered', 'writing', 'review' ), true );
		$items   = array(
			array( 'm' => 'وضعیت', 'v' => $r['status'] ?: 'ایده' ),
			array( 'm' => 'کلاستر', 'v' => $r['cluster'] ?? '' ),
			array( 'm' => 'هم‌کانسپت‌ها', 'v' => $r['related'] ?? '' ),
			array( 'm' => 'نوع صفحه', 'v' => $r['page_type'] ?? '' ),
			array( 'm' => 'مسئول', 'v' => $r['owner'] ?? '' ),
			array( 'm' => 'سررسید', 'v' => $r['due_date'] ? sap_jdate( $r['due_date'], 'short' ) : '' ),
			array( 'm' => 'حجم جستجو', 'v' => $vol ? sap_num( $vol ) : '' ),
		);
		$items = array_values( array_filter( $items, function ( $i ) {
			return '' !== (string) $i['v'];
		} ) );
		$f     = array(
			'key'        => sap_kw_key( $r['keyword'] ),
			'context'    => 'content_new',
			'keyword'    => $r['keyword'],
			'evidence'   => array( 'cols' => array( 'm' => 'مورد', 'v' => 'مقدار' ), 'items' => $items, 'sheet_row' => $r['_row'] ),
			'impact'     => $vol * 0.05,
			'severity'   => $due_now ? 55 : 35,
			'confidence' => $due_now ? 0.9 : 0.7,
		);
		if ( $waiting ) {
			$f['title']                    = sprintf( 'پیگیری محتوای «%s» (%s)', $r['keyword'], $r['status'] );
			$f['detail']                   = 'این محتوا سفارش داده شده یا در حال نگارش/بازبینی است. تحویل، بازبینی سئو (کلمه اصلی در تایتل/H1، هم‌کانسپت‌ها در H2، لینک داخلی، تصویر و Alt) و انتشار را پیگیری کنید و لینک را در شیت ثبت کنید.';
			$f['effort']                   = 1;
			$f['evidence']['task_status']  = 'waiting';
			$f['evidence']['waiting_for']  = $r['owner'] ?? '';
		} else {
			$f['title']  = sprintf( 'تولید / سفارش محتوا: «%s»', $r['keyword'] );
			$f['detail'] = 'بریف محتوا را آماده کنید: کلمه اصلی، هم‌کانسپت‌ها، نیت جستجو، ساختار H2 بر اساس رقبای صفحه اول، سوالات متداول و لینک‌های داخلی ورودی/خروجی؛ سپس به نویسنده بسپارید و وضعیت شیت را بروز کنید.';
			$f['effort'] = 4;
		}
		if ( $due_now && $vol <= 0 ) {
			$f['impact'] = 0;
		}
		$out[] = $f;
	}
	return $out;
}

/**
 * Rows the team marked «نیاز به آپدیت».
 */
function sap_detect_cal_update( $ctx ) {
	$out = array();
	foreach ( (array) ( $ctx['sheet_calendar']['rows'] ?? array() ) as $r ) {
		if ( 'needs_update' !== $r['status_key'] ) {
			continue;
		}
		$out[] = array(
			'key'        => sap_kw_key( $r['keyword'] ),
			'context'    => 'content_update',
			'title'      => sprintf( 'آپدیت محتوای «%s» (علامت‌گذاری‌شده در شیت)', $r['keyword'] ),
			'detail'     => 'در تقویم محتوا وضعیت «نیاز به آپدیت» دارد. ' . ( $r['notes'] ? 'یادداشت شیت: ' . $r['notes'] : '' ),
			'url'        => $r['url'],
			'keyword'    => $r['keyword'],
			'evidence'   => array( 'sheet_row' => $r['_row'] ),
			'severity'   => 50,
			'confidence' => 0.9,
			'effort'     => 2,
		);
	}
	return $out;
}

/**
 * Published articles whose main keyword has no impressions or ranks beyond page one.
 */
function sap_detect_cal_under( $ctx ) {
	$out = array();
	foreach ( (array) ( $ctx['sheet_calendar']['rows'] ?? array() ) as $r ) {
		if ( '' === $r['url'] ) {
			continue;
		}
		$age  = $r['published_date'] ? ( time() - strtotime( $r['published_date'] ) ) / DAY_IN_SECONDS : 999;
		$best = sap_ctx_best( $ctx, $r['keyword'], $r['url'] );
		$any  = sap_ctx_best( $ctx, $r['keyword'] );
		$vol  = sap_ctx_volume( $ctx, $r['keyword'] );
		if ( ! $best && $age < 30 ) {
			continue; // Too new to judge.
		}
		if ( $best && $best['position'] <= 10 ) {
			continue;
		}
		if ( ! $best ) {
			$title  = sprintf( 'مقاله «%s» برای کلمه اصلی خودش نمایشی ندارد', $r['keyword'] );
			$detail = 'صفحه منتشرشده برای کلمه اصلی در سرچ کنسول دیده نمی‌شود. ایندکس بودن صفحه، وجود کلمه در تایتل/H1/نامک، کیفیت و تطابق با نیت جستجو و لینک داخلی ورودی را بررسی کنید.';
			if ( $any ) {
				$detail .= sprintf( "\nبرای این کلمه صفحه دیگری رتبه گرفته: %s (جایگاه %s) — احتمال کنیبالیزیشن.", rawurldecode( $any['page'] ), sap_num( $any['position'], 1 ) );
			}
			$impact = $vol * 0.03;
			$sev    = 50;
		} else {
			$title  = sprintf( 'رساندن مقاله «%s» به صفحه اول (جایگاه فعلی %s)', $r['keyword'], sap_num( $best['position'], 1 ) );
			$detail = 'مقاله رتبه دارد ولی خارج از صفحه اول است. محتوا را با رقبای صفحه اول مقایسه کنید (عمق، ساختار، تازگی)، هم‌کانسپت‌ها و FAQ اضافه کنید و از صفحات مرتبط لینک داخلی بدهید.';
			$impact = $vol ? $vol * ( sap_ctr_expected( 5 ) - sap_ctr_expected( $best['position'] ) ) : $best['impressions'] * 0.03;
			$sev    = 40;
		}
		$out[] = array(
			'key'        => sap_norm_url( $r['url'] ),
			'context'    => 'content_update',
			'title'      => $title,
			'detail'     => $detail,
			'url'        => $r['url'],
			'keyword'    => $r['keyword'],
			'evidence'   => array( 'sheet_row' => $r['_row'] ),
			'impact'     => $impact,
			'severity'   => $sev,
			'confidence' => 0.65,
			'effort'     => 2,
		);
	}
	return $out;
}

/**
 * Related (co-concept) keywords of a published article with no impressions on it.
 */
function sap_detect_cal_related( $ctx ) {
	$out = array();
	foreach ( (array) ( $ctx['sheet_calendar']['rows'] ?? array() ) as $r ) {
		if ( '' === $r['url'] || empty( $r['related_list'] ) || empty( $ctx['by_page'][ sap_norm_url( $r['url'] ) ] ) ) {
			continue;
		}
		$missing = array();
		foreach ( $r['related_list'] as $kw ) {
			if ( ! sap_ctx_best( $ctx, $kw, $r['url'] ) ) {
				$missing[] = array( 'kw' => $kw, 'vol' => sap_ctx_volume( $ctx, $kw ) );
			}
		}
		if ( ! $missing ) {
			continue;
		}
		$vol   = array_sum( array_column( $missing, 'vol' ) );
		$out[] = array(
			'key'        => sap_norm_url( $r['url'] ),
			'context'    => 'content_update',
			'title'      => sprintf( 'افزودن %s کلمه هم‌کانسپت به مقاله «%s»', sap_num( count( $missing ) ), $r['keyword'] ),
			'detail'     => 'این کلمات هم‌کانسپت در شیت برای مقاله تعریف شده‌اند ولی صفحه برایشان هیچ نمایشی ندارد. آن‌ها را در H2/H3، پاراگراف‌ها، Alt تصاویر یا بخش FAQ به کار ببرید.',
			'url'        => $r['url'],
			'keyword'    => $r['keyword'],
			'evidence'   => array( 'cols' => array( 'kw' => 'کلمه', 'vol' => 'حجم جستجو' ), 'items' => $missing, 'sheet_row' => $r['_row'] ),
			'impact'     => $vol * 0.02,
			'severity'   => 30,
			'confidence' => 0.6,
			'effort'     => 1,
		);
	}
	return $out;
}

/**
 * Coverage of every clustering keyword (also written back to the sheet).
 *
 * @return array sheet row => [status, label, best row, volume]
 */
function sap_cluster_coverage( $ctx ) {
	$out = array();
	foreach ( (array) ( $ctx['sheet_clusters']['rows'] ?? array() ) as $r ) {
		$best   = sap_ctx_best( $ctx, $r['keyword'] );
		$target = sap_norm_url( $r['target'] ?? '' );
		$on_tgt = $target && $best ? sap_ctx_best( $ctx, $r['keyword'], $r['target'] ) : null;
		if ( ! $best ) {
			$status = $target ? 'target_none' : 'no_page';
		} elseif ( $target && ! $on_tgt ) {
			$status = 'wrong_page';
		} else {
			$row    = $on_tgt ?: $best;
			$status = $row['position'] <= 10 ? 'covered' : ( $row['position'] <= 30 ? 'opportunity' : 'weak' );
			$best   = $row;
		}
		$labels             = array(
			'covered'     => 'پوشش‌داده‌شده (صفحه اول)',
			'opportunity' => 'فرصت (جایگاه ۱۱ تا ۳۰)',
			'weak'        => 'ضعیف (بعد از ۳۰)',
			'wrong_page'  => 'رتبه با صفحه اشتباه',
			'target_none' => 'صفحه هدف رتبه ندارد',
			'no_page'     => 'بدون صفحه',
		);
		$out[ $r['_row'] ] = array(
			'status' => $status,
			'label'  => $labels[ $status ],
			'best'   => $best,
			'volume' => sap_ctx_volume( $ctx, $r['keyword'] ),
			'row'    => $r,
		);
	}
	return $out;
}

/**
 * Cluster keywords without any ranking page, grouped by cluster / subcluster.
 */
function sap_detect_cl_no_page( $ctx ) {
	$groups = array();
	foreach ( sap_cluster_coverage( $ctx ) as $c ) {
		if ( 'no_page' !== $c['status'] ) {
			continue;
		}
		$r                                                     = $c['row'];
		$g                                                     = $r['cluster'] . ( $r['subcluster'] ? ' › ' . $r['subcluster'] : '' );
		$groups[ $g ][] = array( 'kw' => $r['keyword'], 'vol' => $c['volume'], 'role' => $r['role'] ?? '' );
	}
	$out = array();
	foreach ( $groups as $g => $items ) {
		$items = sap_sort( $items, 'vol' );
		$vol   = array_sum( array_column( $items, 'vol' ) );
		$out[] = array(
			'key'        => sap_kw_key( $g ),
			'context'    => 'content_new',
			'title'      => sprintf( 'ایجاد محتوا برای «%s» (%s کلمه بدون صفحه)', $g, sap_num( count( $items ) ) ),
			'detail'     => 'هیچ صفحه‌ای از سایت برای این کلمات کلاستر رتبه ندارد و صفحه هدفی هم برایشان تعیین نشده. تصمیم بگیرید یک صفحه جدید (مقاله/لندینگ/دسته) بسازید یا آن‌ها را به صفحه موجود اضافه کنید؛ سپس ردیف آن را در تقویم محتوا و ستون «صفحه هدف» کلاسترینگ ثبت کنید.',
			'keyword'    => $items[0]['kw'],
			'evidence'   => array( 'cols' => array( 'kw' => 'کلمه', 'vol' => 'حجم جستجو', 'role' => 'نقش' ), 'items' => array_slice( $items, 0, 25 ) ),
			'impact'     => $vol * 0.05,
			'severity'   => 40,
			'confidence' => 0.6,
			'effort'     => 4,
		);
	}
	return $out;
}

/**
 * Cluster keywords ranking with a different page than the target, per target page.
 */
function sap_detect_cl_wrong_page( $ctx ) {
	$groups = array();
	foreach ( sap_cluster_coverage( $ctx ) as $c ) {
		if ( 'wrong_page' !== $c['status'] ) {
			continue;
		}
		$groups[ sap_norm_url( $c['row']['target'] ) ][] = array(
			'kw'   => $c['row']['keyword'],
			'page' => sap_short_url( $c['best']['page'], 50 ),
			'pos'  => round( $c['best']['position'], 1 ),
			'vol'  => $c['volume'],
			'_t'   => $c['row']['target'],
		);
	}
	$out = array();
	foreach ( $groups as $t => $items ) {
		$target = $items[0]['_t'];
		$items  = array_map( function ( $i ) {
			unset( $i['_t'] );
			return $i;
		}, sap_sort( $items, 'vol' ) );
		$out[]  = array(
			'key'        => $t,
			'context'    => 'internal_links',
			'title'      => sprintf( 'تقویت صفحه هدف %s: %s کلمه با صفحه دیگری رتبه گرفته', '«' . sap_ctx_title( $ctx, $target ) . '»', sap_num( count( $items ) ) ),
			'detail'     => 'برای این کلمات صفحه هدف در شیت کلاسترینگ تعیین شده، ولی گوگل صفحه دیگری را نشان می‌دهد. از صفحه‌ای که الان رتبه دارد با انکر همان کلمه به صفحه هدف لینک بدهید، کلمه را در تایتل/H1 صفحه هدف تقویت کنید و اگر صفحه دیگر بهتر است، صفحه هدف شیت را اصلاح کنید.',
			'url'        => $target,
			'keyword'    => $items[0]['kw'],
			'evidence'   => array( 'cols' => array( 'kw' => 'کلمه', 'page' => 'صفحه رتبه‌گرفته', 'pos' => 'جایگاه', 'vol' => 'حجم' ), 'items' => array_slice( $items, 0, 20 ) ),
			'impact'     => array_sum( array_column( $items, 'vol' ) ) * 0.03,
			'severity'   => 45,
			'confidence' => 0.6,
			'effort'     => 1.5,
		);
	}
	return $out;
}

/**
 * Target pages that do not rank at all for their cluster keywords.
 */
function sap_detect_cl_target_none( $ctx ) {
	$groups = array();
	foreach ( sap_cluster_coverage( $ctx ) as $c ) {
		if ( 'target_none' === $c['status'] ) {
			$groups[ sap_norm_url( $c['row']['target'] ) ][] = array( 'kw' => $c['row']['keyword'], 'vol' => $c['volume'], '_t' => $c['row']['target'] );
		}
	}
	$out = array();
	foreach ( $groups as $t => $items ) {
		$target = $items[0]['_t'];
		$items  = array_map( function ( $i ) {
			unset( $i['_t'] );
			return $i;
		}, sap_sort( $items, 'vol' ) );
		$out[]  = array(
			'key'        => $t,
			'context'    => 'onpage',
			'title'      => sprintf( 'صفحه هدف %s برای %s کلمه کلاستر هیچ رتبه‌ای ندارد', '«' . sap_ctx_title( $ctx, $target ) . '»', sap_num( count( $items ) ) ),
			'detail'     => 'این کلمات به این صفحه اختصاص داده شده‌اند ولی صفحه برای هیچ‌کدام نمایش ندارد. ایندکس بودن صفحه، استفاده از کلمات در تایتل، H1، H2 و متن، و لینک داخلی ورودی با این انکرها را بررسی کنید.',
			'url'        => $target,
			'keyword'    => $items[0]['kw'],
			'evidence'   => array( 'cols' => array( 'kw' => 'کلمه', 'vol' => 'حجم جستجو' ), 'items' => array_slice( $items, 0, 20 ) ),
			'impact'     => array_sum( array_column( $items, 'vol' ) ) * 0.03,
			'severity'   => 40,
			'confidence' => 0.55,
			'effort'     => 1.5,
		);
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * SEO Signal detectors
 * ------------------------------------------------------------------------- */

/**
 * Rank tracker keywords that dropped ≥ 3 positions (or out of top 100) in 30 days.
 */
function sap_detect_rank_drop( $ctx ) {
	$out = array();
	foreach ( (array) ( $ctx['ss_rank']['keywords'] ?? array() ) as $k ) {
		$s = (int) $k['start_rank'];
		$e = (int) $k['end_rank'];
		if ( $s <= 0 || $s > 30 ) {
			continue;
		}
		$lost = $e <= 0 ? 100 : $e;
		if ( $lost - $s < 3 ) {
			continue;
		}
		$out[] = array(
			'key'        => sap_kw_key( $k['keyword'] ),
			'context'    => 'content_update',
			'title'      => sprintf( 'افت رتبه «%s»: از %s به %s', $k['keyword'], sap_num( $s ), $e > 0 ? sap_num( $e ) : 'خارج از ۱۰۰' ),
			'detail'     => 'بر اساس ردیاب رتبه سئو سیگنال در ۳۰ روز اخیر. صفحه رتبه‌گرفته، تغییرات اخیر آن (تایتل، محتوا، کنونیکال)، رقبای جدید صفحه اول و لینک‌های از دست رفته را بررسی کنید.',
			'url'        => $k['target_url'],
			'keyword'    => $k['keyword'],
			'evidence'   => array(
				'cols'  => array( 'm' => 'شاخص', 'v' => 'مقدار' ),
				'items' => array(
					array( 'm' => 'رتبه ابتدای بازه', 'v' => $s ),
					array( 'm' => 'رتبه فعلی', 'v' => $e > 0 ? $e : '—' ),
					array( 'm' => 'بهترین رتبه', 'v' => $k['best_rank'] ),
					array( 'm' => 'حجم جستجو', 'v' => $k['volume'] ),
				),
			),
			'impact'     => $k['volume'] * max( 0, sap_ctr_expected( $s ) - sap_ctr_expected( $lost ) ),
			'severity'   => 50,
			'confidence' => 0.8,
			'effort'     => 1.5,
		);
	}
	return array_slice( sap_sort( $out, 'impact' ), 0, 30 );
}

/**
 * Generic terms ignored when deciding whether a keyword belongs to a focus.
 */
function sap_stop_terms() {
	return array( 'خرید', 'قیمت', 'بهترین', 'ارزان', 'انواع', 'مدل', 'برای', 'فروش', 'نمایندگی', 'آنلاین', 'جدید', 'لیست', 'مشخصات', 'چیست', 'کدام', 'راهنمای', 'راهنما', 'تهران', 'ایران' );
}

/**
 * Core terms of a focus (from its name and clusters).
 */
function sap_focus_terms( $focus ) {
	$terms = array();
	foreach ( array_merge( array( $focus['name'] ), $focus['clusters'] ) as $t ) {
		foreach ( sap_tokens( $t ) as $w ) {
			if ( mb_strlen( $w ) >= 3 && ! in_array( $w, sap_stop_terms(), true ) ) {
				$terms[ $w ] = true;
			}
		}
	}
	return array_keys( $terms );
}

/**
 * Does a keyword contain one of the focus terms?
 */
function sap_kw_in_focus( $keyword, $terms ) {
	$t = ' ' . sap_norm_text( $keyword ) . ' ';
	foreach ( $terms as $term ) {
		if ( false !== mb_strpos( $t, $term ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Known keywords (both sheets) — gap ideas exclude them.
 */
function sap_ctx_known_keywords( $ctx ) {
	$known = array();
	foreach ( (array) ( $ctx['sheet_clusters']['rows'] ?? array() ) as $r ) {
		$known[ sap_kw_key( $r['keyword'] ) ] = true;
	}
	foreach ( (array) ( $ctx['sheet_calendar']['rows'] ?? array() ) as $r ) {
		$known[ sap_kw_key( $r['keyword'] ) ] = true;
		foreach ( $r['related_list'] as $k ) {
			$known[ sap_kw_key( $k ) ] = true;
		}
	}
	return $known;
}

/**
 * Keywords competitors rank for (top 10) and we don't: new keyword ideas.
 */
function sap_detect_gap( $ctx ) {
	$known = sap_ctx_known_keywords( $ctx );
	$out   = array();
	foreach ( (array) ( $ctx['ss_compare']['competitors'] ?? array() ) as $comp => $data ) {
		$rows = array();
		foreach ( (array) ( $data['gap'] ?? array() ) as $r ) {
			$cr = (int) ( $r['rank2'] ?: $r['rank1'] );
			if ( $cr > 0 && $cr <= 10 && $r['volume'] >= 30 && ! isset( $known[ sap_kw_key( $r['word'] ) ] ) ) {
				$rows[] = array( 'kw' => $r['word'], 'vol' => $r['volume'], 'comp' => $cr );
			}
		}
		$rows   = sap_sort( $rows, 'vol' );
		$groups = array( 0 => $rows );
		foreach ( $ctx['focuses'] as $f ) {
			$terms           = sap_focus_terms( $f );
			$groups[ $f['id'] ] = array_values( array_filter( $rows, function ( $r ) use ( $terms ) {
				return sap_kw_in_focus( $r['kw'], $terms );
			} ) );
		}
		foreach ( $groups as $fid => $items ) {
			if ( count( $items ) < 3 ) {
				continue;
			}
			$fname = $fid ? sap_ctx_focus_name( $ctx, $fid ) : '';
			$out[] = array(
				'key'        => $comp . ':' . $fid,
				'context'    => 'research',
				'title'      => $fid ? sprintf( 'ایده کلمات جدید «%s» از گپ رقیب %s (%s کلمه)', $fname, $comp, sap_num( count( $items ) ) ) : sprintf( 'گپ کلمات کلیدی با رقیب %s (%s کلمه)', $comp, sap_num( count( $items ) ) ),
				'detail'     => 'رقیب برای این کلمات در صفحه اول گوگل است و سایت شما رتبه‌ای ندارد (از مقایسه سایت سئو سیگنال). کلمات مرتبط را به شیت کلاسترینگ اضافه کنید، صفحه هدفشان را تعیین کنید و برای کلمات بدون صفحه، ردیف تقویم محتوا بسازید.',
				'keyword'    => $items[0]['kw'],
				'evidence'   => array( 'cols' => array( 'kw' => 'کلمه', 'vol' => 'حجم', 'comp' => 'رتبه رقیب' ), 'items' => array_slice( $items, 0, 30 ), 'focus_hint' => $fid ),
				'impact'     => array_sum( array_column( array_slice( $items, 0, 30 ), 'vol' ) ) * 0.02,
				'severity'   => 35,
				'confidence' => 0.5,
				'effort'     => 2,
				'focus_id'   => $fid,
			);
		}
	}
	return $out;
}

/**
 * Keywords where a competitor outranks us (we rank ≤ 30) — update candidates.
 */
function sap_detect_worse_than( $ctx ) {
	$out = array();
	foreach ( (array) ( $ctx['ss_compare']['competitors'] ?? array() ) as $comp => $data ) {
		$rows = array();
		foreach ( (array) ( $data['worse'] ?? array() ) as $r ) {
			if ( $r['rank1'] > 0 && $r['rank1'] <= 30 && $r['rank2'] > 0 && $r['rank2'] < $r['rank1'] && $r['volume'] >= 30 ) {
				$rows[] = array( 'kw' => $r['word'], 'vol' => $r['volume'], 'ours' => $r['rank1'], 'comp' => $r['rank2'] );
			}
		}
		$rows   = sap_sort( $rows, 'vol' );
		$groups = $ctx['focuses'] ? array() : array( 0 => $rows );
		foreach ( $ctx['focuses'] as $f ) {
			$terms              = sap_focus_terms( $f );
			$groups[ $f['id'] ] = array_values( array_filter( $rows, function ( $r ) use ( $terms ) {
				return sap_kw_in_focus( $r['kw'], $terms );
			} ) );
		}
		foreach ( $groups as $fid => $items ) {
			if ( count( $items ) < 3 ) {
				continue;
			}
			$impact = 0;
			foreach ( array_slice( $items, 0, 20 ) as $i ) {
				$impact += $i['vol'] * max( 0, sap_ctr_expected( $i['comp'] ) - sap_ctr_expected( $i['ours'] ) );
			}
			$out[] = array(
				'key'        => $comp . ':' . $fid,
				'context'    => 'content_update',
				'title'      => sprintf( '%s کلمه%s که رقیب %s بالاتر از ما رتبه دارد', sap_num( count( $items ) ), $fid ? ' «' . sap_ctx_focus_name( $ctx, $fid ) . '»' : '', $comp ),
				'detail'     => 'صفحه رقیب را برای مهم‌ترین کلمات بررسی کنید (عمق محتوا، ساختار، اسکیما، تازگی، لینک داخلی) و صفحه خودتان را بر همان اساس بهبود دهید.',
				'keyword'    => $items[0]['kw'],
				'evidence'   => array( 'cols' => array( 'kw' => 'کلمه', 'vol' => 'حجم', 'ours' => 'رتبه ما', 'comp' => 'رتبه رقیب' ), 'items' => array_slice( $items, 0, 20 ) ),
				'impact'     => $impact,
				'confidence' => 0.6,
				'effort'     => 3,
				'focus_id'   => $fid,
			);
		}
	}
	return $out;
}

/**
 * Real user questions for each focus → FAQ for the focus pillar page.
 */
function sap_detect_faq( $ctx ) {
	$out = array();
	foreach ( (array) ( $ctx['ss_questions']['focuses'] ?? array() ) as $fid => $q ) {
		$items = array();
		foreach ( sap_sort( (array) $q['rows'], 'volume' ) as $r ) {
			if ( $r['volume'] > 0 ) {
				$items[] = array( 'kw' => $r['word'], 'vol' => $r['volume'] );
			}
		}
		if ( count( $items ) < 3 ) {
			continue;
		}
		$target = sap_focus_pillar( $ctx, (int) $fid );
		$out[]  = array(
			'key'        => (string) $fid,
			'context'    => 'onpage',
			'title'      => sprintf( 'افزودن سوالات متداول «%s» (%s سوال واقعی کاربران)', $q['keyword'], sap_num( count( $items ) ) ),
			'detail'     => 'این‌ها سوالاتی هستند که کاربران درباره این موضوع جستجو می‌کنند (سئو سیگنال). ۵ تا ۸ سوال مرتبط را با پاسخ کوتاه و دقیق در بلوک FAQ رنک‌مث به صفحه اصلی Focus اضافه کنید (اسکیمای FAQPage خودکار ساخته می‌شود) و سوالات مفصل‌تر را ایده مقاله جدید کنید. توجه: گوگل از ۲۰۲۳ ریچ‌ریزالت FAQ را برای اغلب سایت‌ها نمایش نمی‌دهد؛ ارزش اصلی، پوشش کوئری‌ها و AI Overview است.',
			'url'        => $target,
			'keyword'    => $q['keyword'],
			'evidence'   => array( 'cols' => array( 'kw' => 'سوال', 'vol' => 'حجم' ), 'items' => array_slice( $items, 0, 15 ) ),
			'impact'     => array_sum( array_column( array_slice( $items, 0, 8 ), 'vol' ) ) * 0.02,
			'severity'   => 30,
			'confidence' => 0.5,
			'effort'     => 1.5,
			'focus_id'   => (int) $fid,
		);
	}
	return $out;
}

/**
 * Main page of a focus: the pillar target in the clustering sheet, else the
 * matching page with most impressions.
 */
function sap_focus_pillar( $ctx, $fid ) {
	$focus = null;
	foreach ( $ctx['focuses'] as $f ) {
		if ( $f['id'] === $fid ) {
			$focus = $f;
		}
	}
	if ( ! $focus ) {
		return '';
	}
	$clusters = array_map( 'sap_kw_key', $focus['clusters'] );
	foreach ( (array) ( $ctx['sheet_clusters']['rows'] ?? array() ) as $r ) {
		if ( '' !== ( $r['target'] ?? '' ) && in_array( sap_kw_key( $r['cluster'] ), $clusters, true ) && false !== mb_strpos( sap_norm_text( $r['role'] ?? '' ), 'پیلار' ) ) {
			return $r['target'];
		}
	}
	$best = '';
	$max  = -1;
	foreach ( $ctx['page_impr'] as $p => $impr ) {
		if ( $impr > $max && sap_url_matches( $ctx['page_url'][ $p ], $focus['url_patterns'] ) ) {
			$best = $ctx['page_url'][ $p ];
			$max  = $impr;
		}
	}
	return $best;
}

/* ---------------------------------------------------------------------------
 * Crawl (Screaming Frog) detectors
 * ------------------------------------------------------------------------- */

/**
 * Indexable HTML 200 pages of the crawl.
 */
function sap_crawl_pages( $ctx ) {
	return array_filter( (array) ( $ctx['crawl']['rows'] ?? array() ), function ( $r ) {
		return 200 === $r['code'] && $r['idx'] && false !== strpos( $r['type'], 'html' );
	} );
}

/**
 * Emit one finding per focus for a list of crawl URLs.
 */
function sap_crawl_list_findings( $ctx, $items, $base ) {
	$out = array();
	foreach ( sap_split_by_focus( $ctx, $items ) as $fid => $list ) {
		$list = sap_sort( $list, 'impr' );
		$f    = $base;
		$name = $fid ? sap_ctx_focus_name( $ctx, $fid ) : '';
		$f['key']      = $base['key'] . ':' . $fid;
		$f['title']    = sprintf( $base['title'], sap_num( count( $list ) ) ) . ( $name ? ' — ' . $name : '' );
		$f['evidence'] = array( 'cols' => $base['cols'], 'items' => array_slice( $list, 0, 40 ) );
		$f['focus_id'] = $fid;
		$f['effort']   = min( 8, max( 0.5, $base['per_item'] * count( $list ) ) );
		unset( $f['cols'], $f['per_item'] );
		$out[] = $f;
	}
	return $out;
}

/**
 * Impressions of a URL in the GSC window.
 */
function sap_ctx_impr( $ctx, $url ) {
	return (int) ( $ctx['page_impr'][ sap_norm_url( $url ) ] ?? 0 );
}

/**
 * Internal 4xx URLs.
 */
function sap_detect_crawl_4xx( $ctx ) {
	$items = array();
	foreach ( (array) ( $ctx['crawl']['rows'] ?? array() ) as $r ) {
		if ( $r['code'] >= 400 && $r['code'] < 500 ) {
			$items[] = array( 'url' => $r['url'], 'page' => sap_short_url( $r['url'], 70 ), 'code' => $r['code'], 'impr' => sap_ctx_impr( $ctx, $r['url'] ) );
		}
	}
	if ( ! $items ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => '4xx',
		'context'  => 'technical',
		'title'    => 'رفع %s آدرس داخلی با خطای 4xx',
		'detail'   => 'لینک‌های داخلی به این آدرس‌ها را اصلاح کنید (در Screaming Frog: Inlinks هر آدرس) و برای آدرس‌هایی که ترافیک یا بک‌لینک داشته‌اند ریدایرکت 301 به مرتبط‌ترین صفحه بگذارید (ماژول Redirections رنک‌مث).',
		'severity' => 70,
		'cols'     => array( 'page' => 'آدرس', 'code' => 'کد', 'impr' => 'نمایش' ),
		'per_item' => 0.1,
	) );
}

/**
 * Internal 5xx URLs.
 */
function sap_detect_crawl_5xx( $ctx ) {
	$items = array();
	foreach ( (array) ( $ctx['crawl']['rows'] ?? array() ) as $r ) {
		if ( $r['code'] >= 500 ) {
			$items[] = array( 'url' => $r['url'], 'page' => sap_short_url( $r['url'], 70 ), 'code' => $r['code'], 'impr' => sap_ctx_impr( $ctx, $r['url'] ) );
		}
	}
	if ( ! $items ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => '5xx',
		'context'  => 'technical',
		'title'    => 'بررسی %s آدرس با خطای سرور 5xx',
		'detail'   => 'خطای سرور مانع خزش و ایندکس می‌شود. لاگ خطای هاست، افزونه‌های کش/امنیتی و محدودیت منابع سرور را بررسی کنید و پس از رفع، آدرس‌ها را دوباره بخزید.',
		'severity' => 85,
		'cols'     => array( 'page' => 'آدرس', 'code' => 'کد', 'impr' => 'نمایش' ),
		'per_item' => 0.2,
	) );
}

/**
 * Internal URLs that redirect (internal links pointing to redirects).
 */
function sap_detect_crawl_3xx( $ctx ) {
	$items = array();
	foreach ( (array) ( $ctx['crawl']['rows'] ?? array() ) as $r ) {
		if ( $r['code'] >= 300 && $r['code'] < 400 ) {
			$items[] = array( 'url' => $r['url'], 'page' => sap_short_url( $r['url'], 55 ), 'to' => sap_short_url( $r['redirect'], 55 ), 'code' => $r['code'], 'impr' => 0 );
		}
	}
	if ( count( $items ) < 5 ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => '3xx',
		'context'  => 'technical',
		'title'    => 'اصلاح لینک‌های داخلی به %s آدرس ریدایرکت‌شده',
		'detail'   => 'لینک‌های داخلی باید مستقیم به آدرس نهایی (کد 200) بروند. در منوها، فوتر و محتوا آدرس مقصد را جایگزین کنید تا بودجه خزش هدر نرود و زنجیره ریدایرکت ایجاد نشود.',
		'severity' => 35,
		'cols'     => array( 'page' => 'آدرس', 'to' => 'مقصد', 'code' => 'کد' ),
		'per_item' => 0.05,
	) );
}

/**
 * Titles, meta descriptions and H1s: missing or duplicated.
 */
function sap_detect_crawl_meta( $ctx ) {
	$pages  = sap_crawl_pages( $ctx );
	$titles = array();
	foreach ( $pages as $r ) {
		if ( '' !== $r['title'] ) {
			$titles[ sap_norm_text( $r['title'] ) ][] = $r;
		}
	}
	$checks = array(
		'no_title' => array( 'صفحه بدون تایتل', 55, 0.15 ),
		'dup'      => array( 'صفحه با تایتل تکراری', 40, 0.1 ),
		'no_meta'  => array( 'صفحه بدون متا دیسکریپشن', 30, 0.1 ),
		'no_h1'    => array( 'صفحه بدون H1', 35, 0.1 ),
		'multi_h1' => array( 'صفحه با بیش از یک H1', 20, 0.05 ),
		'long'     => array( 'صفحه با تایتل طولانی (بیش از ۶۰ کاراکتر / ۵۸۰ پیکسل)', 15, 0.05 ),
	);
	$lists  = array_fill_keys( array_keys( $checks ), array() );
	foreach ( $pages as $r ) {
		$base = array( 'url' => $r['url'], 'page' => sap_ctx_title( $ctx, $r['url'] ), 'title' => mb_substr( $r['title'], 0, 70 ), 'impr' => sap_ctx_impr( $ctx, $r['url'] ) );
		if ( '' === $r['title'] ) {
			$lists['no_title'][] = $base;
		} elseif ( count( $titles[ sap_norm_text( $r['title'] ) ] ) > 1 ) {
			$lists['dup'][] = $base;
		}
		if ( '' === $r['meta'] ) {
			$lists['no_meta'][] = $base;
		}
		if ( '' === $r['h1'] ) {
			$lists['no_h1'][] = $base;
		} elseif ( '' !== $r['h1_2'] ) {
			$lists['multi_h1'][] = $base;
		}
		if ( $r['title_px'] > 580 || ( ! $r['title_px'] && $r['title_len'] > 60 ) ) {
			$lists['long'][] = $base;
		}
	}
	$details = array(
		'no_title' => 'برای هر صفحه تایتل یکتا با کلمه اصلی در ابتدا بنویسید (فیلد عنوان سئو رنک‌مث یا الگوی عنوان نوع محتوا).',
		'dup'      => 'تایتل‌های تکراری باعث سردرگمی گوگل و کنیبالیزیشن می‌شوند. برای صفحات صفحه‌بندی/فیلتر، کنونیکال یا noindex را بررسی کنید و بقیه را یکتا کنید.',
		'no_meta'  => 'متا دیسکریپشن ترغیب‌کننده (۱۲۰ تا ۱۵۵ کاراکتر) با کلمه اصلی و مزیت روشن بنویسید؛ اولویت با صفحات دارای نمایش است.',
		'no_h1'    => 'هر صفحه یک H1 شامل کلمه اصلی داشته باشد؛ در قالب‌های ووکامرس عنوان دسته/محصول معمولاً H1 است — قالب را بررسی کنید.',
		'multi_h1' => 'فقط یک H1 در صفحه باشد؛ بقیه را به H2 تبدیل کنید (معمولاً لوگو یا ویجت‌های قالب H1 گرفته‌اند).',
		'long'     => 'تایتل در نتایج گوگل بریده می‌شود؛ کلمه اصلی و پیام اصلی را در ۵۵ تا ۶۰ کاراکتر اول قرار دهید.',
	);
	$out = array();
	foreach ( $checks as $k => $def ) {
		if ( empty( $lists[ $k ] ) ) {
			continue;
		}
		$out = array_merge( $out, sap_crawl_list_findings( $ctx, $lists[ $k ], array(
			'key'      => $k,
			'context'  => 'onpage',
			'title'    => '%s ' . $def[0],
			'detail'   => $details[ $k ],
			'severity' => $def[1],
			'cols'     => array( 'page' => 'صفحه', 'title' => 'تایتل', 'impr' => 'نمایش' ),
			'per_item' => $def[2],
		) ) );
	}
	return $out;
}

/**
 * The page's main query (most impressions) is not in its title.
 */
function sap_detect_kw_title( $ctx ) {
	$min = (int) $ctx['settings']['min_impressions'];
	$out = array();
	foreach ( sap_crawl_pages( $ctx ) as $r ) {
		$p = sap_norm_url( $r['url'] );
		if ( '' === $r['title'] || empty( $ctx['by_page'][ $p ] ) ) {
			continue;
		}
		$rows = array_values( array_filter( $ctx['by_page'][ $p ], function ( $q ) use ( $ctx ) {
			return ! sap_is_brand( $q['query'], $ctx['project'] );
		} ) );
		if ( ! $rows ) {
			continue;
		}
		$top = sap_sort( $rows, 'impressions' )[0];
		if ( $top['impressions'] < $min ) {
			continue;
		}
		$title_key = sap_kw_key( $r['title'] );
		$missing   = array_filter( sap_tokens( $top['query'] ), function ( $w ) use ( $title_key ) {
			return false === mb_strpos( $title_key, $w );
		} );
		if ( ! $missing ) {
			continue;
		}
		$out[] = array(
			'key'        => $p,
			'context'    => 'onpage',
			'title'      => sprintf( 'افزودن کلمه «%s» به تایتل سئوی صفحه %s', $top['query'], '«' . sap_ctx_title( $ctx, $r['url'] ) . '»' ),
			'detail'     => sprintf( "پرنمایش‌ترین کوئری این صفحه در تایتل آن نیامده است.\nتایتل فعلی: %s\nکلمات غایب: %s", $r['title'], implode( '، ', $missing ) ),
			'url'        => $r['url'],
			'keyword'    => $top['query'],
			'evidence'   => array(
				'cols'  => array( 'm' => 'شاخص', 'v' => 'مقدار' ),
				'items' => array(
					array( 'm' => 'نمایش کوئری', 'v' => $top['impressions'] ),
					array( 'm' => 'جایگاه', 'v' => round( $top['position'], 1 ) ),
					array( 'm' => 'CTR', 'v' => sap_pct( $top['ctr'] ) ),
				),
			),
			'impact'     => $top['impressions'] * 0.01,
			'confidence' => 0.6,
			'effort'     => 0.5,
		);
	}
	return array_slice( sap_sort( $out, 'impact' ), 0, 40 );
}

/**
 * Thin indexable pages that already get impressions.
 */
function sap_detect_thin( $ctx ) {
	$min   = (int) $ctx['settings']['min_impressions'] * 2;
	$items = array();
	foreach ( sap_crawl_pages( $ctx ) as $r ) {
		$impr = sap_ctx_impr( $ctx, $r['url'] );
		if ( $r['words'] > 0 && $r['words'] < 300 && $impr >= $min && '/' !== ( wp_parse_url( $r['url'], PHP_URL_PATH ) ?: '/' ) ) {
			$items[] = array( 'url' => $r['url'], 'page' => sap_short_url( $r['url'], 60 ), 'words' => $r['words'], 'impr' => $impr );
		}
	}
	if ( ! $items ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => 'thin',
		'context'  => 'content_update',
		'title'    => 'تقویت محتوای %s صفحه کم‌حجم که نمایش دارند',
		'detail'   => 'این صفحات کمتر از ۳۰۰ کلمه دارند ولی گوگل آن‌ها را نمایش می‌دهد. برای دسته‌ها متن معرفی و راهنمای خرید، برای محصولات توضیحات یکتا، مشخصات و FAQ اضافه کنید.',
		'severity' => 35,
		'cols'     => array( 'page' => 'صفحه', 'words' => 'تعداد کلمه', 'impr' => 'نمایش' ),
		'per_item' => 0.75,
	) );
}

/**
 * Pages with demand but weak internal linking (≤ 2 unique inlinks or depth ≥ 4).
 */
function sap_detect_weak_links( $ctx ) {
	$min   = (int) $ctx['settings']['min_impressions'] * 2;
	$items = array();
	foreach ( sap_crawl_pages( $ctx ) as $r ) {
		$impr = sap_ctx_impr( $ctx, $r['url'] );
		if ( $impr >= $min && ( $r['inlinks'] <= 2 || $r['depth'] >= 4 ) ) {
			$items[] = array( 'url' => $r['url'], 'page' => sap_short_url( $r['url'], 60 ), 'inlinks' => $r['inlinks'], 'depth' => $r['depth'], 'impr' => $impr );
		}
	}
	if ( ! $items ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => 'weak',
		'context'  => 'internal_links',
		'title'    => 'لینک‌سازی داخلی به %s صفحه پرتقاضا با لینک ورودی کم',
		'detail'   => 'این صفحات نمایش خوبی دارند ولی لینک داخلی کمی می‌گیرند یا عمق آن‌ها زیاد است. از مقالات و دسته‌های مرتبط با انکر کلمه اصلی به آن‌ها لینک بدهید و در صورت امکان در منو/دسته والد قرار دهید.',
		'severity' => 40,
		'cols'     => array( 'page' => 'صفحه', 'inlinks' => 'لینک ورودی', 'depth' => 'عمق', 'impr' => 'نمایش' ),
		'per_item' => 0.25,
	) );
}

/**
 * Non-indexable pages (noindex / canonicalised) that still get impressions.
 */
function sap_detect_noindex_impr( $ctx ) {
	$min   = (int) $ctx['settings']['min_impressions'];
	$items = array();
	foreach ( (array) ( $ctx['crawl']['rows'] ?? array() ) as $r ) {
		if ( 200 !== $r['code'] || $r['idx'] || false === strpos( $r['type'], 'html' ) ) {
			continue;
		}
		$impr = sap_ctx_impr( $ctx, $r['url'] );
		if ( $impr >= $min ) {
			$items[] = array( 'url' => $r['url'], 'page' => sap_short_url( $r['url'], 55 ), 'why' => $r['idx_why'], 'impr' => $impr );
		}
	}
	if ( ! $items ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'        => 'noidx',
		'context'    => 'technical',
		'title'      => 'بررسی %s صفحه غیرقابل‌ایندکس که هنوز در گوگل نمایش دارند',
		'detail'     => 'این صفحات noindex یا کنونیکال‌شده به صفحه دیگری هستند ولی گوگل هنوز نشانشان می‌دهد. اگر عمدی نیست (مثلاً دسته مهمی noindex شده)، تنظیمات رنک‌مث را اصلاح کنید؛ اگر عمدی است، از ایندکس خارج شدن را پیگیری کنید.',
		'severity'   => 40,
		'confidence' => 0.5,
		'cols'       => array( 'page' => 'صفحه', 'why' => 'دلیل', 'impr' => 'نمایش' ),
		'per_item'   => 0.1,
	) );
}

/**
 * Slow server responses (> 1.5 s) on pages with demand.
 */
function sap_detect_slow( $ctx ) {
	$items = array();
	foreach ( sap_crawl_pages( $ctx ) as $r ) {
		$impr = sap_ctx_impr( $ctx, $r['url'] );
		if ( $r['rt'] > 1.5 && $impr > 0 ) {
			$items[] = array( 'url' => $r['url'], 'page' => sap_short_url( $r['url'], 60 ), 'rt' => round( $r['rt'], 2 ), 'impr' => $impr );
		}
	}
	if ( count( $items ) < 3 ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => 'slow',
		'context'  => 'technical',
		'title'    => 'بهبود زمان پاسخ سرور در %s صفحه (بیش از ۱٫۵ ثانیه)',
		'detail'   => 'TTFB بالا روی LCP و خزش اثر می‌گذارد. کش صفحه (LiteSpeed/WP Rocket)، کش آبجکت (Redis)، کوئری‌های سنگین ووکامرس و منابع هاست را بررسی کنید.',
		'severity' => 30,
		'cols'     => array( 'page' => 'صفحه', 'rt' => 'زمان پاسخ (ثانیه)', 'impr' => 'نمایش' ),
		'per_item' => 0.05,
	) );
}


/* ---------------------------------------------------------------------------
 * Page content detectors (page reader)
 * ------------------------------------------------------------------------- */

/**
 * Expected schema types by page kind (from the URL).
 */
function sap_expected_schema( $url ) {
	$path = strtolower( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
	if ( preg_match( '#/(product|shop/[^/]+/[^/]+|p)/[^/]+#', $path ) && false === strpos( $path, 'product-category' ) ) {
		return array( 'Product', 'BreadcrumbList' );
	}
	if ( preg_match( '#/(blog|mag|article|news|\d{4}/\d{2})/#', $path ) ) {
		return array( 'Article', 'BreadcrumbList' );
	}
	if ( false !== strpos( $path, 'product-category' ) || false !== strpos( $path, '/category/' ) ) {
		return array( 'BreadcrumbList' );
	}
	return array();
}

/**
 * Key pages missing the schema their page type needs, or with broken JSON-LD.
 */
function sap_detect_schema( $ctx ) {
	$items = array();
	foreach ( (array) ( $ctx['pages']['pages'] ?? array() ) as $p ) {
		$have    = array_map( 'strtolower', $p['schema'] );
		$missing = array();
		foreach ( sap_expected_schema( $p['url'] ) as $type ) {
			$found = false;
			foreach ( $have as $h ) {
				if ( false !== strpos( $h, strtolower( $type ) ) || ( 'Article' === $type && ( false !== strpos( $h, 'blogposting' ) || false !== strpos( $h, 'newsarticle' ) ) ) ) {
					$found = true;
				}
			}
			if ( ! $found ) {
				$missing[] = $type;
			}
		}
		if ( $missing || $p['schema_errors'] ) {
			$items[] = array( 'url' => $p['url'], 'page' => sap_short_url( $p['url'], 60 ), 'missing' => implode( '، ', $missing ), 'errors' => $p['schema_errors'], 'have' => implode( '، ', array_slice( $p['schema'], 0, 5 ) ), 'impr' => sap_ctx_impr( $ctx, $p['url'] ) );
		}
	}
	if ( ! $items ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => 'schema',
		'context'  => 'onpage',
		'title'    => 'اصلاح اسکیمای %s صفحه کلیدی',
		'detail'   => 'این صفحات اسکیمای لازمِ نوع خودشان را ندارند یا JSON-LD خراب دارند (Product و Offer برای محصول، Article برای مقاله، BreadcrumbList برای همه). در Rank Math › Schema نوع مناسب را تنظیم کنید و با Rich Results Test بررسی کنید.',
		'severity' => 40,
		'cols'     => array( 'page' => 'صفحه', 'missing' => 'اسکیمای لازم', 'have' => 'اسکیمای فعلی', 'errors' => 'خطای JSON-LD' ),
		'per_item' => 0.25,
	) );
}

/**
 * Images without alt text in the content of key pages.
 */
function sap_detect_img_alt( $ctx ) {
	$items = array();
	foreach ( (array) ( $ctx['pages']['pages'] ?? array() ) as $p ) {
		if ( $p['no_alt'] >= 2 ) {
			$items[] = array( 'url' => $p['url'], 'page' => sap_short_url( $p['url'], 60 ), 'no_alt' => $p['no_alt'], 'images' => $p['images'], 'impr' => sap_ctx_impr( $ctx, $p['url'] ) );
		}
	}
	if ( ! $items ) {
		return array();
	}
	return sap_crawl_list_findings( $ctx, $items, array(
		'key'      => 'alt',
		'context'  => 'onpage',
		'title'    => 'افزودن متن Alt به تصاویر %s صفحه کلیدی',
		'detail'   => 'Alt توصیفی با کلمه مرتبط هم برای دسترس‌پذیری و هم برای دیده شدن در جستجوی تصاویر لازم است.',
		'severity' => 20,
		'cols'     => array( 'page' => 'صفحه', 'no_alt' => 'بدون Alt', 'images' => 'کل تصاویر' ),
		'per_item' => 0.25,
	) );
}

/**
 * Key pages whose Core Web Vitals (real users, else lab) are poor.
 */
function sap_detect_cwv( $ctx ) {
	$out = array();
	foreach ( (array) ( $ctx['cwv']['pages'] ?? array() ) as $c ) {
		$field = $c['field'] ?: $c['origin'];
		$bad   = array();
		if ( $field ) {
			foreach ( array( 'lcp' => 'LCP', 'inp' => 'INP', 'cls' => 'CLS' ) as $k => $label ) {
				$m = $field[ $k ] ?? null;
				if ( $m && in_array( $m['cat'], array( 'SLOW', 'AVERAGE' ), true ) ) {
					$bad[] = array( 'm' => $label, 'v' => 'cls' === $k ? $m['p75'] : sap_num( $m['p75'] ) . ' ms', 'cat' => 'SLOW' === $m['cat'] ? 'ضعیف' : 'نیاز به بهبود' );
				}
			}
		} elseif ( null !== $c['score'] && $c['score'] < 50 ) {
			$bad[] = array( 'm' => 'امتیاز Lighthouse', 'v' => $c['score'], 'cat' => 'ضعیف' );
		}
		if ( ! $bad ) {
			continue;
		}
		$poor  = count( array_filter( $bad, function ( $b ) {
			return 'ضعیف' === $b['cat'];
		} ) );
		$out[] = array(
			'key'      => sap_norm_url( $c['url'] ),
			'context'  => 'technical',
			'title'    => sprintf( 'بهبود Core Web Vitals موبایل %s (%s)', '«' . sap_ctx_title( $ctx, $c['url'] ) . '»', implode( '، ', array_column( $bad, 'm' ) ) ),
			'detail'   => 'بر اساس PageSpeed Insights (داده واقعی کاربران کروم در صورت وجود). برای LCP: بهینه‌سازی و preload تصویر اصلی و کش صفحه؛ برای INP: کاهش جاوااسکریپت سنگین افزونه‌ها؛ برای CLS: ابعاد مشخص برای تصاویر و بنرها.',
			'url'      => $c['url'],
			'evidence' => array( 'cols' => array( 'm' => 'شاخص', 'v' => 'مقدار (p75)', 'cat' => 'وضعیت' ), 'items' => $bad ),
			'severity' => $poor ? 55 : 35,
			'effort'   => 3,
		);
	}
	return $out;
}

/**
 * The target page of a keyword is not the page Google ranks best for it:
 * possible cannibalization, or the map points at the wrong page.
 */
function sap_detect_kw_rank_mismatch( $ctx ) {
	$out = array();
	foreach ( sap_keymap_rank_conflicts( $ctx['project'], max( 5, (int) $ctx['settings']['min_impressions'] / 2 ) ) as $c ) {
		$out[] = array(
			'key'        => 'rm:' . sap_kw_key( $c['kw'] ),
			'context'    => 'content_update',
			'title'      => sprintf( 'کلمه «%s»: گوگل به‌جای «%s»، صفحه «%s» را بالاتر نشان می‌دهد', $c['kw'], $c['title'], $c['other_title'] ),
			'detail'     => sprintf( 'صفحه هدف در نقشه کلمات %s، ولی «%s» جایگاه %s با %s نمایش دارد. احتمال کنیبال: سریع بررسی کنید. اگر صفحه دیگر بهتر جواب نیت را می‌دهد، صفحه هدف را در نقشه کلمات عوض کنید؛ وگرنه کلمه را از تایتل/H1 صفحه دیگر بردارید، از آن به صفحه هدف با همین انکر لینک بدهید و محتوای صفحه هدف را تقویت کنید.', null === $c['pos'] ? 'برای این کلمه اصلاً رتبه ندارد' : 'در جایگاه ' . sap_num( $c['pos'], 1 ) . ' است', $c['other_title'], sap_num( $c['other_pos'], 1 ), sap_num( $c['other_impr'] ) ),
			'url'        => $c['url'],
			'keyword'    => $c['kw'],
			'evidence'   => array(
				'cols'    => array( 'page' => 'صفحه', 'role' => 'نقش', 'pos' => 'جایگاه', 'impr' => 'نمایش' ),
				'items'   => array(
					array( 'url' => $c['url'], 'page' => $c['title'], 'role' => 'صفحه هدف (نقشه کلمات)', 'pos' => null === $c['pos'] ? '—' : $c['pos'], 'impr' => $c['impr'] ),
					array( 'url' => $c['other'], 'page' => $c['other_title'], 'role' => 'صفحه رقیب در گوگل', 'pos' => $c['other_pos'], 'impr' => $c['other_impr'] ),
				),
			),
			'severity'   => min( 70, 35 + (int) round( $c['other_impr'] / 20 ) ),
			'confidence' => 0.7,
			'effort'     => 1,
		);
	}
	return $out;
}
