<?php
// Statische export van de gemigreerde Sulu-frontend -> platte HTML + assets,
// klaar voor Cloudflare Pages (of elke statische host). Read-only preview.
//
// Gebruik (met draaiende dev-server op $BASE):
//   php tools/static-export.php http://127.0.0.1:8000 var/legacy.db public_export

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$legacy = $argv[2] ?? __DIR__ . '/../var/legacy.db';
$out = $argv[3] ?? __DIR__ . '/../public_export';

@mkdir($out, 0777, true);
$ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true,
    'header' => "User-Agent: acs-export\r\n"]]);

// 1) Pagina-URLs.
$db = new PDO('sqlite:' . $legacy);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rows = $db->query('SELECT p.homepage, t.full_slug
    FROM page p JOIN page_translation t ON t.page_id=p.id AND t.language_id="nl"
    WHERE p.active=1')->fetchAll(PDO::FETCH_ASSOC);

$urls = [];
foreach ($rows as $r) {
    $u = 1 === (int) $r['homepage'] ? '/' : '/' . trim((string) $r['full_slug'], '/');
    $urls[$u] = true;
}
$urls = array_keys($urls);

// 2) Assets kopiëren (build + statische public-bestanden).
function rcopy(string $src, string $dst): void {
    if (!is_dir($src)) { return; }
    @mkdir($dst, 0777, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $target = $dst . '/' . $it->getSubPathName();
        $f->isDir() ? @mkdir($target, 0777, true) : @copy($f, $target);
    }
}
$pub = __DIR__ . '/../public';
rcopy($pub . '/build', $out . '/build');
foreach (['favicon.ico', 'site.webmanifest', 'browserconfig.xml', 'robots.txt'] as $f) {
    if (is_file($pub . '/' . $f)) { @copy($pub . '/' . $f, $out . '/' . $f); }
}

// 3) Pagina's ophalen, debug-toolbar strippen, wegschrijven.
$saved = 0; $fail = 0;
foreach ($urls as $u) {
    $html = @file_get_contents($base . $u, false, $ctx);
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int) $m[1]; }
    }
    if (200 !== $code || !$html) { ++$fail; echo "  FAIL [$code] $u\n"; continue; }

    // Symfony web debug toolbar verwijderen.
    $html = preg_replace('#<!-- START of Symfony Web Debug Toolbar -->.*?<!-- END of Symfony Web Debug Toolbar -->#s', '', $html);

    $rel = '/' === $u ? 'index.html' : ltrim($u, '/') . '.html';
    $path = $out . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $html);
    ++$saved;
}

echo "\nGeexporteerd: $saved pagina's, $fail mislukt.\nUitvoer: $out\n";
