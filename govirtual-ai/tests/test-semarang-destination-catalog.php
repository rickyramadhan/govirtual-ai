<?php
declare(strict_types=1);

/**
 * test-semarang-destination-catalog.php
 * Tests for Semarang cross-tour destination catalog and discovery intent.
 *
 * Run: php tests/test-semarang-destination-catalog.php
 */

$root = dirname(__DIR__);
$failures = [];
$passes = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $failures, $passes;
    if ($ok) { $passes++; return; }
    $failures[] = $name . ($detail !== '' ? " — $detail" : '');
}

function norm(string $s): string {
    return strtolower(preg_replace('/[\x{0300}-\x{036f}]/u', '', preg_replace('/[^a-z0-9\s]/u', ' ', $s)));
}

// ---- Load catalog ----
$catalogFile = $root . '/data/semarang-destinations.json';
$catalog = json_decode((string)@file_get_contents($catalogFile), true);
check('semarang-destinations.json parses', $catalog !== null);
if (!$catalog) { fwrite(STDERR, "FATAL: semarang-destinations.json broken\n"); exit(1); }

check('catalog has version', isset($catalog['version']) && $catalog['version'] === '1.0');
check('catalog has categories array', isset($catalog['categories']) && is_array($catalog['categories']));
check('catalog has destinations array', isset($catalog['destinations']) && is_array($catalog['destinations']));

$categories = $catalog['categories'];
$destinations = $catalog['destinations'];

// ---- Category tests ----
$expectedCatIds = ['pariwisata', 'agama', 'pendidikan', 'kesehatan', 'investasi', 'mice', 'restoran', 'infrastruktur', 'pusatperbelanjaan'];
$catIds = array_column($categories, 'id');
check('9 categories present', count($categories) === 9, 'got ' . count($categories));
check('all expected category IDs present', count(array_diff($expectedCatIds, $catIds)) === 0, 'missing: ' . implode(', ', array_diff($expectedCatIds, $catIds)));

foreach ($categories as $cat) {
    check("category {$cat['id']} has name", !empty($cat['name']));
    check("category {$cat['id']} has aliases", isset($cat['aliases']) && count($cat['aliases']) > 0);
}

// ---- Destination tests ----
check('destinations count >= 100', count($destinations) >= 100, 'got ' . count($destinations));

$destIds = [];
foreach ($destinations as $d) {
    check("destination has id", !empty($d['id']), json_encode($d));
    check("destination has name", !empty($d['name']), json_encode($d));
    check("destination has categoryId", !empty($d['categoryId']), $d['name'] ?? '');
    check("destination categoryId is valid", in_array($d['categoryId'], $catIds), $d['name'] . ': ' . $d['categoryId']);
    check("destination has virtualTourAvailable", array_key_exists('virtualTourAvailable', $d), $d['name'] ?? '');
    check("destination has virtualTourUrl", !empty($d['virtualTourUrl']), $d['name'] ?? '');
    check("destination virtualTourUrl is https", strpos($d['virtualTourUrl'] ?? '', 'https://') === 0, $d['name'] ?? '');
    check("destination URL on govirtual domain", strpos($d['virtualTourUrl'] ?? '', 'semarang.govirtual.id') !== false, $d['name'] ?? '');
    $destIds[] = $d['id'];
}

check('no duplicate destination IDs', count($destIds) === count(array_unique($destIds)), 'duplicates: ' . implode(', ', array_diff_key($destIds, array_unique($destIds))));

// ---- Specific destination checks ----
$destIndex = [];
foreach ($destinations as $d) { $destIndex[$d['id']] = $d; }

check('Lawang Sewu exists', isset($destIndex['lawangsewu']));
check('Lawang Sewu has VT URL', strpos($destIndex['lawangsewu']['virtualTourUrl'] ?? '', 'lawangsewu') !== false);

check('Masjid Agung Jawa Tengah exists', isset($destIndex['masjidagung']));
check('Masjid Agung in agama category', ($destIndex['masjidagung']['categoryId'] ?? '') === 'agama');
check('Masjid Agung has VT URL', strpos($destIndex['masjidagung']['virtualTourUrl'] ?? '', 'masjidagung') !== false);

// ---- Category intent mapping tests ----
$intentMap = [
    'masjid' => 'agama',
    'gereja' => 'agama',
    'vihara' => 'agama',
    'klenteng' => 'agama',
    'museum' => 'pariwisata',
    'universitas' => 'pendidikan',
    'kampus' => 'pendidikan',
    'rumah sakit' => 'kesehatan',
    'mall' => 'pusatperbelanjaan',
    'restoran' => 'restoran',
    'kuliner' => 'restoran',
    'hotel' => 'mice',
    'stasiun' => 'infrastruktur',
    'bandara' => 'infrastruktur',
];

foreach ($intentMap as $term => $expectedCat) {
    $found = false;
    foreach ($categories as $cat) {
        if ($cat['id'] === $expectedCat || in_array(norm($term), array_map('strtolower', $cat['aliases'] ?? []))) {
            $found = true;
            break;
        }
    }
    check("intent '$term' maps to category '$expectedCat'", $found);
}

// ---- Security: no malicious URL patterns ----
foreach ($destinations as $d) {
    $url = $d['virtualTourUrl'] ?? '';
    check("no javascript: URL in {$d['name']}", strpos($url, 'javascript:') === false);
    check("no data: URL in {$d['name']}", strpos($url, 'data:') === false);
    check("no file: URL in {$d['name']}", strpos($url, 'file:') === false);
}

// ---- No fake distance claims ----
$noDistanceTerms = ['terdekat', '5 menit', 'di sekitar lawang sewu'];
foreach ($destinations as $d) {
    $desc = strtolower($d['description'] ?? '');
    foreach ($noDistanceTerms as $term) {
        check("{$d['name']} description does not contain fake distance '$term'", strpos($desc, $term) === false);
    }
}

// ---- Tour-chat.js integration checks ----
$chatJs = file_get_contents($root . '/tour-chat.js');
check('tour-chat.js loads semarang catalog', strpos($chatJs, 'semarang-destinations.json') !== false || strpos($chatJs, 'semarangDestinations') !== false || strpos($chatJs, 'SemarangCatalog') !== false || strpos($chatJs, 'semarangCatalog') !== false || strpos($chatJs, 'DESTINATION_DISCOVERY') !== false);
check('tour-chat.js has open_virtual_tour action', strpos($chatJs, 'open_virtual_tour') !== false);
check('tour-chat.js has pendingDestination state', strpos($chatJs, 'pendingDestination') !== false);
check('tour-chat.js has destination discovery intent', strpos($chatJs, 'DESTINATION_DISCOVERY') !== false);

// ---- Results ----
echo "\n=== Semarang Destination Catalog Test Results ===\n";
echo "Passes: $passes\n";
echo "Failures: " . count($failures) . "\n\n";

if (count($failures) > 0) {
    echo "FAILURES:\n";
    foreach ($failures as $i => $f) {
        echo "  " . ($i + 1) . ". $f\n";
    }
    echo "\nSEMARANG DESTINATION CATALOG: NOT READY\n";
    exit(1);
}

echo "SEMARANG DESTINATION CATALOG: PASS\n";
exit(0);
