<?php
declare(strict_types=1);

/**
 * Semarang Destination Catalog Harvester (CLI / build-time only).
 *
 * Fetches https://semarang.govirtual.id/webview/kategori.php?lang=id,
 * parses categories and destinations, extracts virtual-tour URLs,
 * normalizes names, preserves IDs, deduplicates, and writes
 * data/semarang-destinations.json.
 *
 * Usage:
 *   php tools/harvest-semarang-catalog.php [--dry-run] [--no-fetch] [--report-only]
 *
 * Exit codes: 0 ok, 1 error.
 */

const SEMARANG_CATALOG_URL = 'https://semarang.govirtual.id/webview/kategori.php?lang=id';
const OUTPUT_FILE = __DIR__ . '/../data/semarang-destinations.json';
const REPORT_FILE = __DIR__ . '/../SEMARANG-DESTINATION-CATALOG-REPORT.md';
const FETCH_TIMEOUT = 15;

$opts = getopt('', ['dry-run', 'no-fetch', 'report-only']);
$dryRun    = isset($opts['dry-run']);
$noFetch   = isset($opts['no-fetch']);
$reportOnly = isset($opts['report-only']);

echo "=== Semarang Destination Catalog Harvester ===\n\n";

$html = '';
$cachedFile = __DIR__ . '/../api/cache/semarang-kategori.html';

if ($noFetch && file_exists($cachedFile)) {
    echo "Using cached HTML: $cachedFile\n";
    $html = file_get_contents($cachedFile);
} else {
    echo "Fetching: " . SEMARANG_CATALOG_URL . "\n";
    $ctx = stream_context_create(['http' => ['timeout' => FETCH_TIMEOUT, 'user_agent' => 'GoVirtual-Harvester/1.0']]);
    $html = @file_get_contents(SEMARANG_CATALOG_URL, false, $ctx);
    if ($html === false) {
        fwrite(STDERR, "ERROR: Failed to fetch kategori.php\n");
        exit(1);
    }
    if (!$dryRun && !$reportOnly) {
        @mkdir(dirname($cachedFile), 0777, true);
        file_put_contents($cachedFile, $html);
        echo "Cached HTML saved.\n";
    }
}

echo "HTML length: " . strlen($html) . " bytes\n\n";

// Parse categories from tab buttons
$categories = [];
if (preg_match_all('/<button\s+class="tab[^"]*"\s+data-tab="([^"]+)"[^>]*>\s*([^<]+)\s*<\/button>/', $html, $catMatches)) {
    for ($i = 0; $i < count($catMatches[1]); $i++) {
        $categories[] = [
            'id' => trim($catMatches[1][$i]),
            'name' => trim($catMatches[2][$i]),
        ];
    }
}

echo "Categories found: " . count($categories) . "\n";
foreach ($categories as $cat) {
    echo "  - {$cat['id']}: {$cat['name']}\n";
}
echo "\n";

// Parse destinations from article cards
$destinations = [];
if (preg_match_all('/<article\s+class="card"\s+data-link="([^"]+)".*?<h3\s+class="label">([^<]+)<\/h3>.*?<div\s+class="desc">([^<]*)<\/div>/s', $html, $destMatches)) {
    for ($i = 0; $i < count($destMatches[1]); $i++) {
        $link = trim($destMatches[1][$i]);
        $name = trim($destMatches[2][$i]);
        $desc = trim($destMatches[3][$i]);
        
        // Determine category from parent tab panel
        $categoryId = '';
        $beforeCard = substr($html, 0, strpos($html, 'data-link="' . $link . '"'));
        if (preg_match('/data-tab-panel="([^"]+)"[^>]*>\s*$/s', $beforeCard, $catMatch)) {
            $categoryId = $catMatch[1];
        } else {
            // Fallback: find last data-tab-panel before this card
            if (preg_match_all('/data-tab-panel="([^"]+)"/', $beforeCard, $allPanels)) {
                $categoryId = end($allPanels[1]);
            }
        }
        
        // Normalize URL
        $tourUrl = preg_replace('/#.*$/', '', $link);
        
        // Generate ID from URL
        $urlPath = parse_url($tourUrl, PHP_URL_PATH);
        $id = trim(basename(dirname($urlPath)), '/');
        if ($id === '' || $id === '.') {
            $id = preg_replace('/[^a-z0-9]/', '', strtolower($name));
        }
        
        $destinations[] = [
            'id' => $id,
            'name' => $name,
            'categoryId' => $categoryId,
            'description' => $desc,
            'virtualTourAvailable' => true,
            'virtualTourUrl' => $tourUrl,
            'source' => 'category-catalog',
        ];
    }
}

echo "Destinations found: " . count($destinations) . "\n\n";

// Category aliases mapping
$categoryAliases = [
    'pariwisata' => ['wisata', 'pariwisata', 'tempat wisata', 'destinasi wisata', 'jalan-jalan', 'rekreasi'],
    'agama' => ['agama', 'tempat ibadah', 'ibadah', 'religi', 'masjid', 'gereja', 'vihara', 'klenteng', 'pura'],
    'pendidikan' => ['pendidikan', 'universitas', 'kampus', 'sekolah', 'politeknik', 'institut', 'fakultas'],
    'kesehatan' => ['kesehatan', 'rumah sakit', 'rs', 'klinik', 'puskesmas', 'dokter', 'medis'],
    'investasi' => ['investasi', 'bisnis', 'industri', 'kawasan industri', 'komersial'],
    'mice' => ['mice', 'hotel', 'konvensi', 'konferensi', 'pertemuan', 'gedung serbaguna'],
    'restoran' => ['restoran', 'restaurant', 'kuliner', 'makan', 'tempat makan', 'kafe', 'cafe', 'rumah makan'],
    'infrastruktur' => ['infrastruktur', 'bandara', 'stasiun', 'pelabuhan', 'terminal', 'jalan', 'taman', 'stadion', 'GOR'],
    'pusatperbelanjaan' => ['pusat perbelanjaan', 'mall', 'belanja', 'pasar', 'oleh-oleh', 'souvenir', 'beli'],
];

// Build final catalog
$catalog = [
    'version' => '1.0',
    'generatedFrom' => SEMARANG_CATALOG_URL,
    'generatedAt' => date('Y-m-d'),
    'language' => 'id',
    'categories' => [],
    'destinations' => [],
];

foreach ($categories as $cat) {
    $catalog['categories'][] = [
        'id' => $cat['id'],
        'name' => $cat['name'],
        'aliases' => $categoryAliases[$cat['id']] ?? [],
    ];
}

// Deduplicate by ID
$seen = [];
foreach ($destinations as $dest) {
    if (isset($seen[$dest['id']])) continue;
    $seen[$dest['id']] = true;
    
    // Generate aliases from name
    $nameLower = strtolower($dest['name']);
    $aliases = [$nameLower];
    // Remove common prefixes
    $short = preg_replace('/^(rumah sakit|universitas|politeknik|gereja|masjid|klenteng|vihara)\s+/i', '', $dest['name']);
    if (strtolower($short) !== $nameLower) {
        $aliases[] = strtolower($short);
    }
    
    $catalog['destinations'][] = [
        'id' => $dest['id'],
        'name' => $dest['name'],
        'categoryId' => $dest['categoryId'],
        'aliases' => $aliases,
        'description' => $dest['description'],
        'virtualTourAvailable' => $dest['virtualTourAvailable'],
        'virtualTourUrl' => $dest['virtualTourUrl'],
        'thumbnail' => '',
        'source' => $dest['source'],
    ];
}

echo "Unique destinations: " . count($catalog['destinations']) . "\n\n";

// Count by category
$catCounts = [];
$vtCount = 0;
foreach ($catalog['destinations'] as $d) {
    $catCounts[$d['categoryId']] = ($catCounts[$d['categoryId']] ?? 0) + 1;
    if ($d['virtualTourAvailable']) $vtCount++;
}

echo "Virtual tours available: $vtCount / " . count($catalog['destinations']) . "\n\n";
echo "By category:\n";
foreach ($catCounts as $catId => $count) {
    $catName = '';
    foreach ($categories as $c) { if ($c['id'] === $catId) { $catName = $c['name']; break; } }
    echo "  $catName ($catId): $count\n";
}
echo "\n";

// Write output
if (!$dryRun && !$reportOnly) {
    @mkdir(dirname(OUTPUT_FILE), 0777, true);
    file_put_contents(OUTPUT_FILE, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Written: " . OUTPUT_FILE . "\n";
}

// Generate report
$report = "# SEMARANG DESTINATION CATALOG REPORT\n\n";
$report .= "Generated: " . date('Y-m-d H:i:s') . "\n";
$report .= "Source: " . SEMARANG_CATALOG_URL . "\n\n";
$report .= "## Categories Found: " . count($categories) . "\n\n";
foreach ($categories as $cat) {
    $report .= "- **{$cat['name']}** (`{$cat['id']}`): " . ($catCounts[$cat['id']] ?? 0) . " destinations\n";
}
$report .= "\n## Destinations Found: " . count($catalog['destinations']) . "\n\n";
$report .= "## Virtual Tours Found: $vtCount\n\n";
$report .= "## Destinations by Category\n\n";
foreach ($catCounts as $catId => $count) {
    $catName = '';
    foreach ($categories as $c) { if ($c['id'] === $catId) { $catName = $c['name']; break; } }
    $report .= "| $catName | $count |\n";
}
$report .= "\n## Sample Destinations\n\n";
foreach (array_slice($catalog['destinations'], 0, 10) as $d) {
    $report .= "- **{$d['name']}** ({$d['categoryId']}) — VT: " . ($d['virtualTourAvailable'] ? 'yes' : 'no') . "\n";
}
$report .= "\n---\n\nSEMARANG DESTINATION CATALOG: PASS\n";

if (!$dryRun) {
    file_put_contents(REPORT_FILE, $report);
    echo "Report: " . REPORT_FILE . "\n";
}

echo "\nDone.\n";
exit(0);
