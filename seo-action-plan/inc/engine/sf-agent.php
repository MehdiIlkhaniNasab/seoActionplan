<?php
/**
 * Screaming Frog connection wizard: a ready agent script per project (Windows
 * PowerShell / macOS-Linux bash) with the endpoint and key built in, a
 * connection test (the agent pings Sokan and reports whether the SEO Spider
 * CLI was found), and a focused crawl scope.
 *
 * Quick mode (default) crawls only the site's important URLs in list mode
 * (--crawl-list): home, focus pages, mapped pages, categories / brands, the
 * most visible products and posts, pages missing from the sitemap. A full
 * crawl of a big shop takes hours; the important pages take minutes and are
 * what the technical checks need. Full mode stays available for a periodic
 * deep crawl (broken links, orphan pages).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wizard settings of a project.
 */
function sap_sf_settings( $project ) {
	return wp_parse_args( (array) ( $project['options']['sf'] ?? array() ), array(
		'mode'       => 'quick',
		'limit'      => 300,
		'installed'  => '',
		'configured' => '',
		'downloaded' => '',
		'os'         => 'win',
		'ping'       => array(),
	) );
}

function sap_sf_save( $project_id, $data ) {
	$p = sap_project( $project_id );
	sap_project_set_option( $project_id, 'sf', array_merge( sap_sf_settings( $p ), $data ) );
}

/**
 * Important URLs to crawl in quick mode, most important first.
 *
 * @return string[]
 */
function sap_sf_important_urls( $project, $limit = 300 ) {
	$pid  = $project['id'];
	$urls = array();
	$add  = function ( $u ) use ( &$urls ) {
		$u = esc_url_raw( (string) $u );
		if ( '' !== $u && preg_match( '#^https?://#', $u ) ) {
			$urls[ sap_norm_url( $u ) ] = $u;
		}
	};
	$add( (string) ( $project['options']['site_url'] ?? '' ) ?: ( $project['site_url'] ?: 'https://' . sap_project_domain( $project ) . '/' ) );
	foreach ( sap_pages_to_read( $project, 60 ) as $u ) {
		$add( $u );
	}
	$impr = array();
	foreach ( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$impr[ sap_norm_url( $r['page'] ) ] = (int) $r['impressions'];
	}
	$map = sap_keymap( $pid );
	uksort( $map, function ( $a, $b ) use ( $impr ) {
		return ( $impr[ $b ] ?? 0 ) <=> ( $impr[ $a ] ?? 0 );
	} );
	foreach ( $map as $m ) {
		$add( $m['url'] );
	}
	// From WordPress: every product category / brand, then the most visible products, posts and pages.
	$inv = sap_site_inventory( $pid );
	foreach ( $inv as $k => $it ) {
		if ( in_array( $it['ctype'], array( 'product_cat', 'brand' ), true ) && empty( $it['noindex'] ) ) {
			$add( $it['url'] );
		}
	}
	$rest = array_filter( $inv, function ( $it ) {
		return in_array( $it['ctype'], array( 'product', 'post', 'page', 'post_cat' ), true ) && empty( $it['noindex'] );
	} );
	uksort( $rest, function ( $a, $b ) use ( $impr ) {
		return ( $impr[ $b ] ?? 0 ) <=> ( $impr[ $a ] ?? 0 );
	} );
	foreach ( $rest as $it ) {
		$add( $it['url'] );
	}
	foreach ( (array) ( sap_dataset_payload( $pid, 'sitemap_check' )['missing'] ?? array() ) as $m ) {
		$add( $m['url'] );
	}
	foreach ( sap_sort( (array) ( sap_dataset_payload( $pid, 'gsc_pages' )['cur'] ?? array() ), 'impressions' ) as $r ) {
		$add( $r['page'] );
	}
	return array_slice( array_values( $urls ), 0, max( 20, min( 5000, (int) $limit ) ) );
}

/**
 * Agent script for a project.
 *
 * @param string $os win|sh
 */
function sap_sf_agent_script( $project, $os ) {
	$base = untrailingslashit( rest_url( 'seoactionplan/v1/crawl/' . $project['id'] ) );
	$key  = $project['api_key'];
	$site = (string) ( $project['options']['site_url'] ?? '' ) ?: ( $project['site_url'] ?: 'https://' . sap_project_domain( $project ) . '/' );
	$mode = sap_sf_settings( $project )['mode'];
	if ( 'win' === $os ) {
		return str_replace(
			array( '{{BASE}}', '{{KEY}}', '{{SITE}}', '{{MODE}}', '{{DATE}}' ),
			array( $base, $key, $site, $mode, gmdate( 'Y-m-d' ) ),
			<<<'PS1'
<#
  Sokan (Sokan SEO by Ronagh) - Screaming Frog agent for Windows.  Generated {{DATE}}.

  Test the connection (no crawl):
    powershell -ExecutionPolicy Bypass -File sokan-sf-agent.ps1 -Test
  Crawl and send the result to Sokan:
    powershell -ExecutionPolicy Bypass -File sokan-sf-agent.ps1
  Optional: -Mode full (whole site), -SpiderPath "C:\...\ScreamingFrogSEOSpiderCli.exe", -Config "C:\...\my.seospiderconfig"
#>
param(
  [switch]$Test,
  [string]$Mode = "{{MODE}}",
  [string]$SpiderPath = "",
  [string]$Config = ""
)
$ErrorActionPreference = "Stop"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$Base = "{{BASE}}"
$Key  = "{{KEY}}"
$Site = "{{SITE}}"
$Headers = @{ "X-SAP-Key" = $Key }

if (-not $SpiderPath) {
  $SpiderPath = @(
    "$env:ProgramFiles\Screaming Frog SEO Spider\ScreamingFrogSEOSpiderCli.exe",
    "${env:ProgramFiles(x86)}\Screaming Frog SEO Spider\ScreamingFrogSEOSpiderCli.exe"
  ) | Where-Object { Test-Path $_ } | Select-Object -First 1
}
$Found = [bool]($SpiderPath -and (Test-Path $SpiderPath))

function Send-Ping([string]$Stage, [string]$Note = "") {
  $body = @{ stage = $Stage; sf_found = $Found; sf_path = [string]$SpiderPath; os = "windows"; mode = $Mode; note = $Note; host = $env:COMPUTERNAME } | ConvertTo-Json
  Invoke-RestMethod -Method Post -Uri "$Base/ping" -Headers $Headers -ContentType "application/json; charset=utf-8" -Body $body | Out-Null
}

Write-Host "[Sokan] Connecting to $Base ..."
try { Send-Ping "test" } catch { Write-Error "Cannot reach Sokan: $($_.Exception.Message)"; exit 1 }
Write-Host "[Sokan] Connection OK."
if (-not $Found) { Write-Warning "ScreamingFrogSEOSpiderCli.exe was not found. Install Screaming Frog SEO Spider or pass -SpiderPath." }
else { Write-Host "[Sokan] Screaming Frog found: $SpiderPath" }
if ($Test) { Write-Host "[Sokan] Test finished. Go back to Sokan and press 'Check connection'."; exit 0 }
if (-not $Found) { exit 1 }

$out = Join-Path $env:TEMP ("sokan-crawl-" + (Get-Date -Format "yyyyMMdd-HHmmss"))
New-Item -ItemType Directory -Path $out | Out-Null
$sfArgs = @("--headless", "--output-folder", $out, "--export-tabs", "Internal:All", "--overwrite")
if ($Mode -eq "full") {
  $sfArgs = @("--crawl", $Site) + $sfArgs
} else {
  $list = Join-Path $out "urls.txt"
  Invoke-WebRequest -Uri "$Base/urls" -Headers $Headers -OutFile $list -UseBasicParsing
  Write-Host "[Sokan] Important URLs: $((Get-Content $list).Count)"
  $sfArgs = @("--crawl-list", $list) + $sfArgs
}
if ($Config) { $sfArgs += @("--config", $Config) }
Send-Ping "crawling"
Write-Host "[Sokan] Crawling ($Mode) ..."
& $SpiderPath @sfArgs
$csv = Join-Path $out "internal_all.csv"
if (-not (Test-Path $csv)) { Send-Ping "failed" "internal_all.csv was not produced"; throw "internal_all.csv was not produced in $out" }
Write-Host "[Sokan] CSV header: $((Get-Content $csv -TotalCount 1).Substring(0, [Math]::Min(200, (Get-Content $csv -TotalCount 1).Length)))"
Write-Host "[Sokan] Uploading ..."
$response = & curl.exe -sS -X POST "$Base" -H "X-SAP-Key: $Key" -F "file=@$csv;type=text/csv" -F "agent=sokan-sf-agent.ps1" -F "start_url=$Site"
Write-Host $response
Remove-Item -Recurse -Force $out
PS1
		);
	}
	return str_replace(
		array( '{{BASE}}', '{{KEY}}', '{{SITE}}', '{{MODE}}', '{{DATE}}' ),
		array( $base, $key, $site, $mode, gmdate( 'Y-m-d' ) ),
		<<<'SH'
#!/usr/bin/env bash
# Sokan (Sokan SEO by Ronagh) - Screaming Frog agent for macOS / Linux.  Generated {{DATE}}.
#   Test the connection (no crawl):   bash sokan-sf-agent.sh --test
#   Crawl and send to Sokan:          bash sokan-sf-agent.sh
#   Options: --mode full   --spider /path/to/cli   --config my.seospiderconfig
set -euo pipefail
BASE="{{BASE}}"
KEY="{{KEY}}"
SITE="{{SITE}}"
MODE="{{MODE}}" TEST=0 SPIDER="${SF_SPIDER:-}" CONFIG=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --test) TEST=1; shift ;;
    --mode) MODE="$2"; shift 2 ;;
    --spider) SPIDER="$2"; shift 2 ;;
    --config) CONFIG="$2"; shift 2 ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done
if [[ -z "$SPIDER" ]]; then
  for c in "/Applications/Screaming Frog SEO Spider.app/Contents/MacOS/ScreamingFrogSEOSpiderLauncher" "$(command -v screamingfrogseospider || true)"; do
    [[ -n "$c" && -x "$c" ]] && { SPIDER="$c"; break; }
  done
fi
FOUND=false; [[ -n "$SPIDER" ]] && FOUND=true
ping_sokan() {
  curl -fsS -X POST "$BASE/ping" -H "X-SAP-Key: $KEY" -H "Content-Type: application/json" \
    -d "{\"stage\":\"$1\",\"sf_found\":$FOUND,\"sf_path\":\"${SPIDER//\"/}\",\"os\":\"$(uname -s)\",\"mode\":\"$MODE\",\"note\":\"${2:-}\",\"host\":\"$(hostname)\"}" >/dev/null
}
echo "[Sokan] Connecting to $BASE ..."
ping_sokan test || { echo "Cannot reach Sokan." >&2; exit 1; }
echo "[Sokan] Connection OK."
$FOUND && echo "[Sokan] Screaming Frog found: $SPIDER" || echo "[Sokan] WARNING: Screaming Frog CLI not found (use --spider PATH)." >&2
[[ $TEST == 1 ]] && { echo "[Sokan] Test finished. Go back to Sokan and press 'Check connection'."; exit 0; }
$FOUND || exit 1
OUT="$(mktemp -d -t sokan-crawl-XXXX)"; trap 'rm -rf "$OUT"' EXIT
ARGS=(--headless --output-folder "$OUT" --export-tabs "Internal:All" --overwrite)
if [[ "$MODE" == "full" ]]; then
  ARGS=(--crawl "$SITE" "${ARGS[@]}")
else
  curl -fsS "$BASE/urls" -H "X-SAP-Key: $KEY" -o "$OUT/urls.txt"
  echo "[Sokan] Important URLs: $(wc -l < "$OUT/urls.txt")"
  ARGS=(--crawl-list "$OUT/urls.txt" "${ARGS[@]}")
fi
[[ -n "$CONFIG" ]] && ARGS+=(--config "$CONFIG")
ping_sokan crawling
echo "[Sokan] Crawling ($MODE) ..."
"$SPIDER" "${ARGS[@]}" || echo "[Sokan] SEO Spider exited with $?" >&2
CSV="$OUT/internal_all.csv"
[[ -f "$CSV" ]] || { ping_sokan failed "internal_all.csv was not produced"; echo "internal_all.csv was not produced" >&2; exit 1; }
echo "[Sokan] CSV header: $(head -c 200 "$CSV" | head -1)"
gzip -c "$CSV" > "$OUT/internal_all.csv.gz"
echo "[Sokan] Uploading ..."
curl -sS -X POST "$BASE" -H "X-SAP-Key: $KEY" -F "file=@$OUT/internal_all.csv.gz" -F "agent=sokan-sf-agent.sh" -F "start_url=$SITE"
echo
SH
	);
}

/**
 * Wizard state: which step is done, which one is current.
 *
 * @return array{steps: array<string,bool>, current: string, ping: array, crawl: array|null}
 */
function sap_sf_state( $project ) {
	$s     = sap_sf_settings( $project );
	$crawl = sap_dataset( $project['id'], 'crawl' );
	$ping  = (array) $s['ping'];
	$steps = array(
		'install'  => (bool) $s['installed'] || ! empty( $ping['sf_found'] ) || (bool) $crawl,
		'scope'    => (bool) $s['configured'] || (bool) $crawl,
		'download' => (bool) $s['downloaded'] || ! empty( $ping ) || (bool) $crawl,
		'test'     => ( ! empty( $ping['at'] ) && ! empty( $ping['sf_found'] ) ) || (bool) $crawl,
		'crawl'    => $crawl && ( empty( $ping['at'] ) || strtotime( $crawl['fetched_at'] ) >= strtotime( $ping['first'] ?? $ping['at'] ) - 60 ),
	);
	$current = '';
	foreach ( $steps as $k => $done ) {
		if ( ! $done ) {
			$current = $k;
			break;
		}
	}
	return array( 'steps' => $steps, 'current' => $current, 'ping' => $ping, 'crawl' => $crawl, 'settings' => $s );
}

/* ---- Endpoints --------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	$ns = 'seoactionplan/v1';
	// Agent → Sokan: connection test / progress (project key).
	register_rest_route( $ns, '/crawl/(?P<project>\d+)/ping', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_rest_key_permission',
		'callback'            => function ( WP_REST_Request $req ) {
			$p    = sap_project( (int) $req['project'] );
			$old  = (array) sap_sf_settings( $p )['ping'];
			$ping = array(
				'at'       => sap_now(),
				'first'    => $old['first'] ?? sap_now(),
				'stage'    => sanitize_key( (string) $req->get_param( 'stage' ) ),
				'sf_found' => rest_sanitize_boolean( $req->get_param( 'sf_found' ) ),
				'sf_path'  => sanitize_text_field( (string) $req->get_param( 'sf_path' ) ),
				'os'       => sanitize_text_field( (string) $req->get_param( 'os' ) ),
				'mode'     => sanitize_key( (string) $req->get_param( 'mode' ) ),
				'note'     => sanitize_text_field( (string) $req->get_param( 'note' ) ),
				'host'     => sanitize_text_field( (string) $req->get_param( 'host' ) ),
			);
			sap_sf_save( $p['id'], array( 'ping' => $ping ) );
			return array( 'ok' => true, 'message' => 'Sokan received the ping.' );
		},
	) );
	// Agent → Sokan: the important URLs to crawl in list mode (plain text, one per line).
	register_rest_route( $ns, '/crawl/(?P<project>\d+)/urls', array(
		'methods'             => 'GET',
		'permission_callback' => 'sap_rest_key_permission',
		'callback'            => function ( WP_REST_Request $req ) {
			$p = sap_project( (int) $req['project'] );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo implode( "\n", sap_sf_important_urls( $p, sap_sf_settings( $p )['limit'] ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		},
	) );
	// App: wizard state (polled by «بررسی اتصال») and settings.
	register_rest_route( $ns, '/crawl/(?P<project>\d+)/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$p = sap_project( (int) $req['project'] );
			if ( ! $p ) {
				return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
			}
			$st = sap_sf_state( $p );
			return array(
				'ok'      => true,
				'steps'   => $st['steps'],
				'current' => $st['current'],
				'ping'    => $st['ping'] ? array_merge( $st['ping'], array( 'ago' => human_time_diff( strtotime( $st['ping']['at'] ), strtotime( sap_now() ) ) ) ) : null,
				'crawl'   => $st['crawl'] ? array( 'at' => sap_jdate( $st['crawl']['fetched_at'] ), 'urls' => (int) ( $st['crawl']['meta']['urls'] ?? 0 ) ) : null,
			);
		},
	) );
	register_rest_route( $ns, '/crawl/(?P<project>\d+)/config', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$p = sap_project( (int) $req['project'] );
			if ( ! $p ) {
				return new WP_Error( 'sap_project', 'پروژه یافت نشد.', array( 'status' => 404 ) );
			}
			$set = array();
			if ( null !== $req->get_param( 'installed' ) ) {
				$set['installed'] = sap_now();
			}
			if ( null !== $req->get_param( 'mode' ) ) {
				$set['mode']       = 'full' === $req->get_param( 'mode' ) ? 'full' : 'quick';
				$set['limit']      = max( 20, min( 5000, (int) $req->get_param( 'limit' ) ?: 300 ) );
				$set['configured'] = sap_now();
			}
			if ( null !== $req->get_param( 'reset' ) ) {
				$set = array( 'ping' => array(), 'downloaded' => '' );
			}
			sap_sf_save( $p['id'], $set );
			$n = isset( $set['limit'] ) ? count( sap_sf_important_urls( sap_project( $p['id'] ), $set['limit'] ) ) : 0;
			return array( 'ok' => true, 'msg' => isset( $set['mode'] ) ? ( 'full' === $set['mode'] ? 'خزش کامل سایت ذخیره شد.' : sprintf( 'خزش سریع: %s آدرس مهم ذخیره شد.', sap_num( $n ) ) ) : 'ثبت شد.' );
		},
	) );
} );

/**
 * Download the agent script.
 */
add_action( 'admin_post_sap_sf_agent', function () {
	sap_guard( 'sap_sf_agent' );
	$p  = sap_project( absint( $_POST['project_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$os = 'sh' === ( $_POST['os'] ?? '' ) ? 'sh' : 'win'; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $p ) {
		wp_die( 'پروژه یافت نشد.' );
	}
	sap_sf_save( $p['id'], array( 'downloaded' => sap_now(), 'os' => $os ) );
	$body = sap_sf_agent_script( sap_project( $p['id'] ), $os );
	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Disposition: attachment; filename="sokan-sf-agent.' . ( 'sh' === $os ? 'sh' : 'ps1' ) . '"' );
	echo 'sh' === $os ? $body : str_replace( "\n", "\r\n", $body ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
} );
