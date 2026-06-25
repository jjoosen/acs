<?php
// Laadt de benodigde content-tabellen uit de MySQL-dump (.sql.gz) in een
// SQLite-bestand, zodat app:migrate ertegen kan draaien (geen MySQL nodig).
// Robuuste tokenizer voor mysqldump INSERT-waarden (escapes, NULL, getallen).

$dump = $argv[1] ?? null;
$out  = $argv[2] ?? '/home/user/acs/var/legacy.db';
if (!$dump || !is_file($dump)) { fwrite(STDERR, "dump niet gevonden\n"); exit(1); }

$targets = [
    'language', 'page', 'page_translation', 'page_block',
    'navigation', 'navigation_translation',
    // media-metadata meenemen (voor latere koppeling)
    'image', 'image_translation', 'file', 'file_translation',
];

@unlink($out);
$db = new PDO('sqlite:' . $out);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF;');

/** Parse mysqldump tu...(values) -> array van rijen (array van scalars). */
function parseValues(string $s): array {
    $rows = []; $i = 0; $n = strlen($s);
    $esc = ['n'=>"\n",'r'=>"\r",'t'=>"\t",'0'=>"\0",'Z'=>"\x1a",'b'=>"\x08",'\\'=>'\\',"'"=>"'",'"'=>'"'];
    while ($i < $n && $s[$i] !== '(') $i++;
    while ($i < $n) {
        if ($s[$i] !== '(') break;
        $i++; $row = [];
        while (true) {
            while ($i < $n && $s[$i] === ' ') $i++;
            if ($i >= $n) break;
            if ($s[$i] === "'") {
                $i++; $val = '';
                while ($i < $n) {
                    $c = $s[$i];
                    if ($c === '\\') { $nx = $s[$i+1] ?? ''; $val .= $esc[$nx] ?? $nx; $i += 2; continue; }
                    if ($c === "'") {
                        if (($s[$i+1] ?? '') === "'") { $val .= "'"; $i += 2; continue; }
                        $i++; break;
                    }
                    $val .= $c; $i++;
                }
                $row[] = $val;
            } else {
                $start = $i;
                while ($i < $n && $s[$i] !== ',' && $s[$i] !== ')') $i++;
                $tok = trim(substr($s, $start, $i - $start));
                $row[] = ($tok === 'NULL') ? null : $tok;
            }
            while ($i < $n && $s[$i] === ' ') $i++;
            if ($i < $n && $s[$i] === ',') { $i++; continue; }
            if ($i < $n && $s[$i] === ')') { $i++; break; }
            break;
        }
        $rows[] = $row;
        while ($i < $n && $s[$i] !== '(') { if ($s[$i] === ';') { return $rows; } $i++; }
    }
    return $rows;
}

$gz = gzopen($dump, 'rb');
if (!$gz) { fwrite(STDERR, "kan dump niet openen\n"); exit(1); }

$curTable = null; $capturingDDL = false; $cols = []; $colTypes = [];
$schemaReady = [];          // table => array of column names
$counts = [];
$insertStmt = [];           // table => PDOStatement

function buildSqlite(PDO $db, string $t, array $cols, array $types): void {
    $defs = [];
    foreach ($cols as $c) {
        $ty = $types[$c] ?? 'TEXT';
        $sty = (stripos($ty, 'int') !== false) ? 'INTEGER' : 'TEXT';
        $defs[] = '"' . $c . '" ' . $sty;
    }
    $db->exec('DROP TABLE IF EXISTS "' . $t . '"');
    $db->exec('CREATE TABLE "' . $t . '" (' . implode(',', $defs) . ')');
}

while (($line = gzgets($gz)) !== false) {
    // CREATE TABLE start
    if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
        $curTable = $m[1];
        $capturingDDL = in_array($curTable, $GLOBALS['targets'], true);
        $cols = []; $colTypes = [];
        continue;
    }
    if ($capturingDDL) {
        if (preg_match('/^\s*`([^`]+)`\s+([a-zA-Z]+)/', $line, $m)) {
            $cols[] = $m[1]; $colTypes[$m[1]] = $m[2];
        } elseif (preg_match('/^\)\s*ENGINE/', $line)) {
            buildSqlite($db, $curTable, $cols, $colTypes);
            $place = implode(',', array_fill(0, count($cols), '?'));
            $colList = '"' . implode('","', $cols) . '"';
            $insertStmt[$curTable] = $db->prepare(
                'INSERT INTO "' . $curTable . '" (' . $colList . ') VALUES (' . $place . ')'
            );
            $schemaReady[$curTable] = $cols;
            $counts[$curTable] = 0;
            $capturingDDL = false;
        }
        continue;
    }
    // INSERT rows
    if (preg_match('/^INSERT INTO `([^`]+)` VALUES /', $line, $m)) {
        $t = $m[1];
        if (!isset($insertStmt[$t])) continue; // niet-target tabel
        $rows = parseValues($line);
        $expected = count($schemaReady[$t]);
        $db->beginTransaction();
        $stmt = $insertStmt[$t];
        foreach ($rows as $r) {
            if (count($r) !== $expected) {
                // skip malformed (defensief)
                continue;
            }
            $stmt->execute($r);
            $counts[$t]++;
        }
        $db->commit();
    }
}
gzclose($gz);

foreach ($targets as $t) {
    echo sprintf("%-24s %d rijen\n", $t, $counts[$t] ?? 0);
}
echo "SQLite: $out\n";
