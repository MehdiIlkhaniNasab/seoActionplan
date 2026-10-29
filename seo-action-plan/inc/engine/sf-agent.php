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
$base = @("--headless", "--output-folder", $out, "--export-tabs", "Internal:All", "--overwrite")
if ($Mode -eq "full") {
  $base = @("--crawl", $Site) + $base
} else {
  $list = Join-Path $out "urls.txt"
  Invoke-WebRequest -Uri "$Base/urls" -Headers $Headers -OutFile $list -UseBasicParsing
  Write-Host "[Sokan] Important URLs: $((Get-Content $list).Count)"
  $base = @("--crawl-list", $list) + $base
}
if ($Config) { $base += @("--config", $Config) }
# Extra exports: every internal link with its position (body / menu / footer / sidebar) and the redirects report.
# Names differ between SEO Spider versions, so older names are tried when a version rejects the new ones.
$extras = @(
  @("--bulk-export", "Links:All Inlinks", "--save-report", "Redirects:All Redirects"),
  @("--bulk-export", "All Inlinks", "--save-report", "Redirect & Canonical Chains"),
  @()
)
Send-Ping "crawling"
$csv = Join-Path $out "internal_all.csv"
foreach ($ex in $extras) {
  Write-Host "[Sokan] Crawling ($Mode) $($ex -join ' ') ..."
  $sfArgs = $base + $ex
  & $SpiderPath @sfArgs
  if (Test-Path $csv) { break }
  Write-Warning "SEO Spider did not produce internal_all.csv with these export names; trying the next set."
}
if (-not (Test-Path $csv)) { Send-Ping "failed" "internal_all.csv was not produced"; throw "internal_all.csv was not produced in $out" }
Write-Host "[Sokan] CSV header: $((Get-Content $csv -TotalCount 1).Substring(0, [Math]::Min(200, (Get-Content $csv -TotalCount 1).Length)))"
Write-Host "[Sokan] Uploading the crawl ..."
$response = & curl.exe -sS -X POST "$Base" -H "X-SAP-Key: $Key" -F "file=@$csv;type=text/csv" -F "agent=sokan-sf-agent.ps1" -F "start_url=$Site" -F "mode=$Mode"
Write-Host $response

# All Inlinks can be millions of rows: keep body (Content) links and every link that is not 200, compress, upload.
$filterSrc = @'
using System; using System.IO; using System.IO.Compression; using System.Text; using System.Collections.Generic; using Microsoft.VisualBasic.FileIO;
public static class SokanLinks {
  static string Q(string s) { return "\"" + (s ?? "").Replace("\"", "\"\"") + "\""; }
  static string At(string[] f, int i) { return i >= 0 && i < f.Length ? f[i] : ""; }
  public static long Filter(string inPath, string outPath) {
    long kept = 0, seen = 0;
    using (TextFieldParser p = new TextFieldParser(inPath, Encoding.UTF8)) {
      p.TextFieldType = FieldType.Delimited; p.SetDelimiters(","); p.HasFieldsEnclosedInQuotes = true;
      string[] h = p.ReadFields();
      if (h != null && h.Length < 3 && !p.EndOfData) { h = p.ReadFields(); }
      int iType = Array.IndexOf(h, "Type"), iSrc = Array.IndexOf(h, "Source"), iDst = Array.IndexOf(h, "Destination");
      int iAnc = Array.IndexOf(h, "Anchor"), iAlt = Array.IndexOf(h, "Alt Text"), iCode = Array.IndexOf(h, "Status Code");
      int iFol = Array.IndexOf(h, "Follow"), iPos = Array.IndexOf(h, "Link Position");
      if (iSrc < 0 || iDst < 0) { throw new Exception("Source / Destination columns not found"); }
      int[] keep = new int[] { iType, iSrc, iDst, iAnc, iAlt, iCode, iFol, iPos };
      Dictionary<string, int> nav = new Dictionary<string, int>();
      using (FileStream fs = File.Create(outPath))
      using (GZipStream gz = new GZipStream(fs, CompressionMode.Compress))
      using (StreamWriter w = new StreamWriter(gz, new UTF8Encoding(false))) {
        w.WriteLine("\"Type\",\"Source\",\"Destination\",\"Anchor\",\"Alt Text\",\"Status Code\",\"Follow\",\"Link Position\"");
        while (!p.EndOfData) {
          string[] f;
          try { f = p.ReadFields(); } catch (MalformedLineException) { continue; }
          if (f == null) { continue; }
          seen++;
          if (seen % 500000 == 0) { Console.WriteLine("[Sokan] links read: " + seen + ", kept: " + kept); }
          string type = iType >= 0 ? At(f, iType) : "Hyperlink";
          string code = At(f, iCode), pos = At(f, iPos);
          bool body = iPos < 0 || pos == "" || pos.Equals("Content", StringComparison.OrdinalIgnoreCase);
          bool problem = code != "" && code != "200";
          bool link = type.Equals("Hyperlink", StringComparison.OrdinalIgnoreCase) || type.Equals("AHREF", StringComparison.OrdinalIgnoreCase);
          bool img = type.Equals("Image", StringComparison.OrdinalIgnoreCase) || type.Equals("IMG", StringComparison.OrdinalIgnoreCase);
          if (link && !body && !problem) {
            string d = At(f, iDst); int c; nav.TryGetValue(d, out c); nav[d] = c + 1;
            continue;
          }
          if (!((link && (body || problem)) || (img && problem))) { continue; }
          StringBuilder line = new StringBuilder();
          for (int k = 0; k < keep.Length; k++) { if (k > 0) { line.Append(','); } line.Append(Q(At(f, keep[k]))); }
          w.WriteLine(line.ToString());
          kept++;
        }
        // Menu / header / footer / sidebar links that work: one summary row per page.
        foreach (KeyValuePair<string, int> kv in nav) { w.WriteLine("\"NavCount\",\"\"," + Q(kv.Key) + "," + Q(kv.Value.ToString()) + ",\"\",\"200\",\"true\",\"Navigation\""); }
      }
    }
    return kept;
  }
}
'@
$extraFiles = Get-ChildItem -Path $out -Filter *.csv -Recurse | Where-Object { $_.Name -ne "internal_all.csv" -and ($_.Name -match "inlink|redirect") }
foreach ($f in $extraFiles) {
  try {
    if ($f.Name -match "inlink") {
      Write-Host "[Sokan] Filtering $($f.Name) ($([Math]::Round($f.Length / 1MB, 1)) MB) ..."
      if (-not ("SokanLinks" -as [type])) { Add-Type -TypeDefinition $filterSrc -ReferencedAssemblies Microsoft.VisualBasic }
      $gzPath = $f.FullName + ".sokan.gz"
      $kept = [SokanLinks]::Filter($f.FullName, $gzPath)
      Write-Host "[Sokan] Kept $kept links (body links + links that are not 200)."
    } else {
      $gzPath = $f.FullName + ".gz"
      $in = [IO.File]::OpenRead($f.FullName); $o = [IO.File]::Create($gzPath)
      $z = New-Object IO.Compression.GZipStream($o, [IO.Compression.CompressionMode]::Compress)
      $in.CopyTo($z); $z.Dispose(); $o.Dispose(); $in.Dispose()
    }
    Write-Host "[Sokan] Uploading $($f.Name) ..."
    $r = & curl.exe -sS -X POST "$Base/extra" -H "X-SAP-Key: $Key" -F "file=@$gzPath;type=application/gzip" -F "mode=$Mode"
    Write-Host $r
  } catch { Write-Warning "Could not send $($f.Name): $($_.Exception.Message)" }
}
if (-not $extraFiles) { Write-Warning "No All Inlinks / redirects export was produced; Sokan works with internal_all.csv only." }
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
CSV="$OUT/internal_all.csv"
# Extra exports (links with position + redirects report); older SEO Spider versions use older names.
for EXTRA in "Links:All Inlinks|Redirects:All Redirects" "All Inlinks|Redirect & Canonical Chains" ""; do
  EX=()
  [[ -n "$EXTRA" ]] && EX=(--bulk-export "${EXTRA%%|*}" --save-report "${EXTRA##*|}")
  echo "[Sokan] Crawling ($MODE) ${EX[*]:-} ..."
  "$SPIDER" "${ARGS[@]}" ${EX[@]+"${EX[@]}"} || echo "[Sokan] SEO Spider exited with $?" >&2
  [[ -f "$CSV" ]] && break
done
[[ -f "$CSV" ]] || { ping_sokan failed "internal_all.csv was not produced"; echo "internal_all.csv was not produced" >&2; exit 1; }
echo "[Sokan] CSV header: $(head -c 200 "$CSV" | head -1)"
gzip -c "$CSV" > "$OUT/internal_all.csv.gz"
echo "[Sokan] Uploading the crawl ..."
curl -sS -X POST "$BASE" -H "X-SAP-Key: $KEY" -F "file=@$OUT/internal_all.csv.gz" -F "agent=sokan-sf-agent.sh" -F "start_url=$SITE" -F "mode=$MODE"
echo
while IFS= read -r -d '' F; do
  NAME="$(basename "$F")"
  [[ "$NAME" == "internal_all.csv" ]] && continue
  if [[ "$NAME" == *nlink* ]] && command -v python3 >/dev/null 2>&1; then
    echo "[Sokan] Filtering $NAME (body links + links that are not 200) ..."
    python3 - "$F" "$F.sokan.gz" <<'PY'
import csv, gzip, sys
csv.field_size_limit(10**8)
src, dst = sys.argv[1], sys.argv[2]
cols = ["Type", "Source", "Destination", "Anchor", "Alt Text", "Status Code", "Follow", "Link Position"]
with open(src, newline="", encoding="utf-8-sig", errors="replace") as fi, gzip.open(dst, "wt", newline="", encoding="utf-8") as fo:
    r = csv.reader(fi)
    h = next(r)
    if len(h) < 3:
        h = next(r)
    ix = {c: (h.index(c) if c in h else -1) for c in cols}
    w = csv.writer(fo, quoting=csv.QUOTE_ALL)
    w.writerow(cols)
    kept = 0
    nav = {}
    for f in r:
        at = lambda c: f[ix[c]] if 0 <= ix[c] < len(f) else ""
        t, code, pos = (at("Type") or "Hyperlink").lower(), at("Status Code"), at("Link Position")
        body = ix["Link Position"] < 0 or pos == "" or pos.lower() == "content"
        problem = code not in ("", "200")
        if t in ("hyperlink", "ahref") and not body and not problem:
            nav[at("Destination")] = nav.get(at("Destination"), 0) + 1
        elif (t in ("hyperlink", "ahref") and (body or problem)) or (t in ("image", "img") and problem):
            w.writerow([at(c) for c in cols]); kept += 1
    for d, n in nav.items():
        w.writerow(["NavCount", "", d, str(n), "", "200", "true", "Navigation"])
print("[Sokan] Kept %d links." % kept)
PY
    UP="$F.sokan.gz"
  else
    gzip -c "$F" > "$F.gz"; UP="$F.gz"
  fi
  echo "[Sokan] Uploading $NAME ..."
  curl -sS -X POST "$BASE/extra" -H "X-SAP-Key: $KEY" -F "file=@$UP" -F "mode=$MODE" || echo "[Sokan] Could not send $NAME" >&2
  echo
done < <(find "$OUT" -name '*.csv' \( -iname '*inlink*' -o -iname '*redirect*' \) -print0)
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
	// Agent → Sokan: All Inlinks export / redirects report (kind detected from the columns).
	register_rest_route( $ns, '/crawl/(?P<project>\d+)/extra', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_rest_key_permission',
		'callback'            => function ( WP_REST_Request $req ) {
			$files = $req->get_file_params();
			if ( empty( $files['file']['tmp_name'] ) ) {
				return new WP_Error( 'sap_empty', 'No file was sent (field «file»).', array( 'status' => 400 ) );
			}
			$r = sap_sf_ingest_file( (int) $req['project'], $files['file']['tmp_name'], (string) $files['file']['name'], 'agent', array( 'mode' => sanitize_key( (string) $req->get_param( 'mode' ) ) ) );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( $r->get_error_code(), $r->get_error_message(), array( 'status' => 400 ) );
			}
			return array( 'ok' => true, 'kind' => $r['kind'], 'rows' => $r['count'], 'message' => 'Sokan received ' . $r['kind'] . ': ' . $r['count'] . ' rows' );
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
