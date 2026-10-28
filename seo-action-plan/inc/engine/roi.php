<?php
/**
 * ROI forecast per task: extra clicks a month if the work succeeds, their
 * value, the cost of the hours, and the return over three months. Built from
 * the page's own Search Console data, the CTR curve and what this kind of work
 * actually achieved (this project first, then the pooled playbook), with the
 * basis written out so nobody has to trust a black box.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Money settings of a project: value of one organic click and cost of one hour (Toman).
 */
function sap_roi_money( $project ) {
	$o = (array) $project['options'];
	return array(
		'click' => (float) ( $o['click_value'] ?? 0 ),
		'hour'  => (float) ( $o['hourly_cost'] ?? 0 ) ?: (float) sap_setting( 'hourly_cost', 0 ),
	);
}

/**
 * Default expectation of a kind of work when no measured history exists.
 *
 * @return array {pos: positions gained, ctr: CTR multiplier at the same position}
 */
function sap_roi_default( $context ) {
	$d = array(
		'onpage'         => array( 'pos' => 0.5, 'ctr' => 1.15 ),
		'content_update' => array( 'pos' => 3, 'ctr' => 1.0 ),
		'internal_links' => array( 'pos' => 1.5, 'ctr' => 1.0 ),
		'offpage'        => array( 'pos' => 2, 'ctr' => 1.0 ),
		'technical'      => array( 'pos' => 1, 'ctr' => 1.0 ),
		'cro'            => array( 'pos' => 0, 'ctr' => 1.05 ),
	);
	return $d[ $context ] ?? array( 'pos' => 1, 'ctr' => 1.0 );
}

/**
 * Search Console facts of a page (and keyword) from the stored 28-day data.
 *
 * @return array {impr, clicks, pos}
 */
function sap_roi_gsc( $project_id, $url, $keyword = '' ) {
	static $qp = array();
	if ( ! isset( $qp[ $project_id ] ) ) {
		$qp[ $project_id ] = array();
		foreach ( (array) ( sap_dataset_payload( $project_id, 'gsc_qp' )['rows'] ?? array() ) as $r ) {
			$qp[ $project_id ][ sap_norm_url( $r['page'] ) ][] = $r;
		}
	}
	$rows = $qp[ $project_id ][ sap_norm_url( $url ) ] ?? array();
	if ( '' !== $keyword ) {
		$k  = sap_norm_text( $keyword );
		$kr = array_values( array_filter( $rows, function ( $r ) use ( $k ) {
			return sap_norm_text( $r['query'] ) === $k;
		} ) );
		$rows = $kr ?: $rows;
	}
	$impr = array_sum( array_column( $rows, 'impressions' ) );
	$pos  = $impr ? array_sum( array_map( function ( $r ) {
		return $r['position'] * $r['impressions'];
	}, $rows ) ) / $impr : null;
	return array( 'impr' => (int) $impr, 'clicks' => (int) array_sum( array_column( $rows, 'clicks' ) ), 'pos' => null === $pos ? null : round( $pos, 1 ) );
}

/**
 * Forecast for one task (or finding / idea shaped like one).
 *
 * @param array $t {url, keyword, context, effort}
 * @return array|null {clicks, value, cost, roi, confidence, basis[]}
 */
function sap_roi_estimate( $project, $t ) {
	$url = (string) ( $t['url'] ?? '' );
	if ( '' === $url ) {
		return null;
	}
	$ctx   = (string) ( $t['context'] ?? 'content_update' );
	$hours = max( 0.25, (float) ( $t['effort'] ?? 1 ) );
	$money = sap_roi_money( $project );
	$g     = sap_roi_gsc( $project['id'], $url, (string) ( $t['keyword'] ?? '' ) );
	$basis = array();
	if ( 'content_new' === $ctx || ! $g['impr'] ) {
		// A page without impressions: the keyword's search volume at a realistic position 8 after three months.
		$vols = (array) ( sap_dataset_payload( $project['id'], 'ss_volumes' )['volumes'] ?? array() );
		$vol  = (int) ( $vols[ sap_kw_key( (string) ( $t['keyword'] ?? '' ) ) ]['volume'] ?? 0 );
		if ( ! $vol ) {
			return null;
		}
		$clicks  = $vol * sap_ctr_at( 8 );
		$basis[] = sprintf( 'صفحه هنوز نمایش ندارد؛ حجم جستجوی «%s» ماهانه %s و فرض رسیدن به جایگاه ۸ در سه ماه (CTR %s٪).', $t['keyword'], sap_num( $vol ), sap_num( sap_ctr_at( 8 ) * 100, 1 ) );
		$conf    = 'low';
	} else {
		$page = sap_learning_page_type( $project['id'], $url );
		$def  = sap_roi_default( $ctx );
		$gain = $def['pos'];
		$mult = $def['ctr'];
		$src  = 'پیش‌فرض محتاطانه سکان';
		$conf = 'low';
		// Measured history of this kind of work: this project first, then the pool.
		$own = sap_impact_summary( $project['id'] )['by_type'][ $ctx ] ?? null;
		$exp = sap_learning_expect( $ctx, $page );
		if ( $own && $own['n'] >= 3 && null !== $own['avg'] ) {
			$mult = max( 0.8, 1 + $own['avg'] / 100 );
			$gain = 0;
			$src  = sprintf( 'نتیجه %s کار مشابه در همین سایت (میانگین %s٪)', sap_num( $own['n'] ), sap_num( $own['avg'] ) );
			$conf = 'high';
		} elseif ( $exp ) {
			if ( null !== $exp['pos'] ) {
				$gain = max( 0, $exp['pos'] );
			} else {
				$mult = max( 0.8, 1 + $exp['median'] / 100 );
				$gain = 0;
			}
			$src  = sprintf( 'میانه %s کار مشابه در پروژه‌های دیگر', sap_num( $exp['n'] ) );
			$conf = 'medium';
		}
		$pos     = (float) $g['pos'];
		$target  = max( 1, $pos - $gain );
		$now_m   = $g['impr'] * sap_ctr_at( $pos ) * 30 / 28;
		$then_m  = $g['impr'] * sap_ctr_at( $target ) * $mult * 30 / 28;
		$clicks  = max( 0, $then_m - $now_m );
		$basis[] = sprintf( 'سرچ کنسول ۲۸ روز: %s نمایش، جایگاه %s، %s کلیک.', sap_num( $g['impr'] ), sap_num( $pos, 1 ), sap_num( $g['clicks'] ) );
		$basis[] = sprintf( 'انتظار: %s%s (%s).', $gain > 0 ? 'جایگاه ' . sap_num( $pos, 1 ) . ' ← ' . sap_num( $target, 1 ) : 'همان جایگاه', abs( $mult - 1 ) > 0.001 ? ' و CTR ×' . sap_num( $mult, 2 ) : '', $src );
		if ( $g['impr'] < 200 && 'high' !== $conf ) {
			$conf = 'low';
		}
	}
	$clicks = (int) round( $clicks );
	$value  = $money['click'] > 0 ? $clicks * $money['click'] : null;
	$cost   = $money['hour'] > 0 ? $hours * $money['hour'] : null;
	$roi    = null !== $value && $cost ? round( ( $value * 3 - $cost ) / $cost * 100 ) : null;
	if ( null !== $value ) {
		$basis[] = sprintf( 'ارزش هر کلیک %s تومان%s.', sap_num( $money['click'] ), null !== $cost ? '، هزینه ' . sap_num( $hours, 2 ) . ' ساعت کار ' . sap_num( $cost ) . ' تومان' : '' );
	}
	return array( 'clicks' => $clicks, 'value' => $value, 'cost' => $cost, 'roi' => $roi, 'confidence' => $conf, 'basis' => $basis );
}

/**
 * One-line badge text of a forecast.
 */
function sap_roi_badge( $e ) {
	if ( ! $e || $e['clicks'] < 1 ) {
		return '';
	}
	$t = '+' . sap_num( $e['clicks'] ) . ' کلیک/ماه';
	if ( null !== $e['value'] ) {
		$t .= ' ≈ ' . sap_money_short( $e['value'] );
	}
	if ( null !== $e['roi'] ) {
		$t .= ' · ROI سه‌ماهه ' . sap_num( $e['roi'] ) . '٪';
	}
	return $t;
}

/**
 * Short Toman amount (۴٫۸ میلیون تومان).
 */
function sap_money_short( $v ) {
	$v = (float) $v;
	if ( $v >= 1e9 ) {
		return sap_num( $v / 1e9, 1 ) . ' میلیارد تومان';
	}
	if ( $v >= 1e6 ) {
		return sap_num( $v / 1e6, 1 ) . ' میلیون تومان';
	}
	return sap_num( $v ) . ' تومان';
}
