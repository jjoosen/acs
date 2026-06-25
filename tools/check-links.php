<?php
// Linkcontrole voor de gemigreerde Sulu-site.
// 1) Controleert of alle gemigreerde pagina-URLs (uit legacy.db) renderen (HTTP 200).
// 2) Verzamelt alle <a href> uit die pagina's en controleert interne links;
//    categoriseert extern / media (/upload) / mailto / tel.
//
// Gebruik (met draaiende server op $BASE):
//   php tools/check-links.php [base-url] [pad/naar/legacy.db]

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$legacy = $argv[2] ?? __DIR__ . '/../var/legacy.db';

$ctx = stream_context_create(['http' => [
    'method' => 'GET', 'timeout' => 20, 'ignore_errors' => true,
    'header' => "User-Agent: acs-linkcheck\r\n",
]]);

function statusOf(string $url, $ctx): int {
    $body = @file_get_contents($url, false, $ctx);
    if (!isset($http_response_header[0])) {
        // $http_response_header is gevuld door file_get_contents in lokale scope
    }
    foreach ($GLOBALS['http_response_header'] ?? ($http_response_header ?? []) as $h) {}
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int) $m[1]; }
    }
    return $code;
}

// 1) Verwachte pagina-URLs uit de legacy-data.
$db = new PDO('sqlite:' . $legacy);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rows = $db->query('SELECT p.homepage, t.full_slug
    FROM page p JOIN page_translation t ON t.page_id=p.id AND t.language_id="nl"
    WHERE p.active=1')->fetchAll(PDO::FETCH_ASSOC);

$pageUrls = [];
foreach ($rows as $r) {
    $u = 1 === (int) $r['homepage'] ? '/' : '/' . ltrim((string) $r['full_slug'], '/');
    if ('/' !== $u) { $u = rtrim($u, '/'); }
    $pageUrls[$u] = true;
}
$pageUrls = array_keys($pageUrls);
sort($pageUrls);

echo "== 1) Pagina-render-check (" . count($pageUrls) . " URLs) ==\n";
$pageFail = [];
$bodies = [];
foreach ($pageUrls as $u) {
    $body = @file_get_contents($base . $u, false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int) $m[1]; }
    }
    if (200 !== $code) { $pageFail[$u] = $code; }
    else { $bodies[$u] = $body; }
}
printf("  OK: %d   NIET-200: %d\n", count($bodies), count($pageFail));
foreach ($pageFail as $u => $c) { printf("    [%d] %s\n", $c, $u); }

// 2) Links verzamelen.
$internal = []; $external = []; $media = []; $mailto = []; $tel = [];
foreach ($bodies as $u => $html) {
    if (preg_match_all('/<a\b[^>]*href\s*=\s*"([^"]+)"/i', $html, $m)) {
        foreach ($m[1] as $href) {
            $href = trim($href);
            if ('' === $href || str_starts_with($href, '#')) { continue; }
            if (str_starts_with($href, 'mailto:')) { $mailto[$href] = true; continue; }
            if (str_starts_with($href, 'tel:')) { $tel[$href] = true; continue; }
            if (str_starts_with($href, '/upload') || str_contains($href, '/upload/')) { $media[$href] = true; continue; }
            if (preg_match('#^https?://#i', $href)) {
                if (preg_match('#^https?://(www\.)?127\.0\.0\.1#', $href)) {
                    $p = parse_url($href, PHP_URL_PATH) ?: '/';
                    $internal[$p] = true;
                } else { $external[$href] = true; }
                continue;
            }
            if (str_starts_with($href, '/')) {
                $p = parse_url($href, PHP_URL_PATH) ?: $href;
                $internal[$p] = true;
            }
        }
    }
}

echo "\n== 2) Interne links controleren (" . count($internal) . " uniek) ==\n";
$broken = [];
foreach (array_keys($internal) as $p) {
    $body = @file_get_contents($base . $p, false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int) $m[1]; }
    }
    if (200 !== $code) { $broken[$p] = $code; }
}
printf("  OK: %d   GEBROKEN: %d\n", count($internal) - count($broken), count($broken));
foreach ($broken as $p => $c) { printf("    [%d] %s\n", $c, $p); }

echo "\n== Samenvatting overige links ==\n";
printf("  Externe links      : %d\n", count($external));
printf("  Media-links (/upload): %d  (komen pas live met de upload-map)\n", count($media));
printf("  mailto:            : %d\n", count($mailto));
printf("  tel:               : %d\n", count($tel));

echo "\n-- Externe links (top 20) --\n";
foreach (array_slice(array_keys($external), 0, 20) as $e) { echo "    $e\n"; }
