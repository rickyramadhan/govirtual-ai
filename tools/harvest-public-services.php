<?php
declare(strict_types=1);

/**
 * Public Service Catalog Harvester (CLI / build-time only).
 *
 * Fetches https://semarang.govirtual.id/webview/publicservice.php?&lang=id,
 * parses service cards, normalizes names and contact fields,
 * deduplicates, and writes data/public-services.json.
 *
 * Usage:
 *   php tools/harvest-public-services.php [--dry-run] [--no-fetch] [--report-only]
 *
 * Exit codes: 0 ok, 1 error.
 */

const PUBLIC_SERVICE_URL = 'https://semarang.govirtual.id/webview/publicservice.php?&lang=id';
const OUTPUT_FILE = __DIR__ . '/../data/public-services.json';
const REPORT_FILE = __DIR__ . '/../PUBLIC-SERVICE-HARVEST-REPORT.md';
const FETCH_TIMEOUT = 15;

$opts = getopt('', ['dry-run', 'no-fetch', 'report-only']);
$dryRun    = isset($opts['dry-run']);
$noFetch   = isset($opts['no-fetch']);
$reportOnly = isset($opts['report-only']);

echo "=== Public Service Catalog Harvester ===\n\n";

$html = '';
$cachedFile = __DIR__ . '/../api/cache/publicservice.html';

if ($noFetch && file_exists($cachedFile)) {
    echo "Using cached HTML: $cachedFile\n";
    $html = file_get_contents($cachedFile);
} else {
    echo "Fetching: " . PUBLIC_SERVICE_URL . "\n";
    $ctx = stream_context_create(['http' => ['timeout' => FETCH_TIMEOUT, 'user_agent' => 'GoVirtual-Harvester/1.0']]);
    $html = @file_get_contents(PUBLIC_SERVICE_URL, false, $ctx);
    if ($html === false) {
        fwrite(STDERR, "ERROR: Failed to fetch publicservice.php\n");
        exit(1);
    }
    if (!$dryRun && !$reportOnly) {
        @mkdir(dirname($cachedFile), 0777, true);
        file_put_contents($cachedFile, $html);
        echo "Cached HTML saved.\n";
    }
}

echo "HTML length: " . strlen($html) . " bytes\n\n";

// Parse services from <a> and <button> cards
$services = [];
$seenNames = [];

// Pattern: <a href="..." ... > ... <p class="text-xs...">CATEGORY</p> <h2 class="...">NAME</h2> ... </a>
// Or: <button ... > ... <p class="text-xs...">CATEGORY</p> <h2 class="...">NAME</h2> ... </button>
$cardPattern = '/<(a|button)\b[^>]*href="([^"]*)"[^>]*>.*?<p\s+class="text-xs[^"]*text-gray-300[^"]*"[^>]*>([^<]+)<\/p>\s*<h2\s+class="[^"]*"[^>]*>([^<]+)<\/h2>.*?<\/(?:a|button)>/si';

if (preg_match_all($cardPattern, $html, $matches)) {
    for ($i = 0; $i < count($matches[0]); $i++) {
        $tag = $matches[1][$i];
        $href = trim($matches[2][$i]);
        $category = trim($matches[3][$i]);
        $name = trim($matches[4][$i]);

        // Skip duplicates
        $normalizedName = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
        if (isset($seenNames[$normalizedName])) continue;
        $seenNames[$normalizedName] = true;

        // Extract contact fields from href
        $phone = null;
        $website = null;
        $contactUrl = null;

        if (preg_match('/^tel:(.+)$/', $href, $telMatch)) {
            $phone = trim($telMatch[1]);
        } elseif (preg_match('/^https?:\/\//', $href)) {
            $website = $href;
            // WhatsApp links are contact URLs
            if (preg_match('/wa\.me\//', $href)) {
                $contactUrl = $href;
                $phone = preg_replace('/^https:\/\/wa\.me\/(\d+).*$/', '+$1', $href);
            }
        }

        // Generate ID from name
        $id = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $id = trim($id, '-');

        // Generate aliases
        $aliases = generateAliases($name, $category);

        $services[] = [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'aliases' => $aliases,
            'description' => $name . ' — layanan ' . strtolower($category) . ' Kota Semarang.',
            'phone' => $phone,
            'emergencyNumber' => in_array($phone, ['112', '110']) ? $phone : null,
            'address' => null,
            'website' => $website,
            'contactUrl' => $contactUrl,
            'sourceType' => 'semarang-public-service',
        ];
    }
}

// Special handling for modal-based services (Libas app)
// The Libas button doesn't have a href, it opens a modal
if (strpos($html, 'Pelaporan Kriminal') !== false && !isset($seenNames['pelaporankriminal'])) {
    $services[] = [
        'id' => 'libas-pelaporan-kriminal',
        'name' => 'Libas — Pelaporan Kriminal',
        'category' => 'Kepolisian',
        'aliases' => ['libas', 'pelaporan kriminal', 'lapor kriminal', 'criminal report'],
        'description' => 'Aplikasi Libas untuk pelaporan kriminal Kota Semarang. Tersedia di Google Play dan App Store.',
        'phone' => null,
        'emergencyNumber' => null,
        'address' => null,
        'website' => 'https://play.google.com/store/apps/details?id=com.cancreative.psis_masyarakat',
        'contactUrl' => null,
        'sourceType' => 'semarang-public-service',
    ];
}

echo "Services found: " . count($services) . "\n";
foreach ($services as $s) {
    echo "  - [{$s['category']}] {$s['name']}";
    if ($s['phone']) echo " (phone: {$s['phone']})";
    if ($s['website']) echo " (web: {$s['website']})";
    echo "\n";
}
echo "\n";

// Stats
$withPhone = count(array_filter($services, fn($s) => $s['phone'] !== null));
$withEmergency = count(array_filter($services, fn($s) => $s['emergencyNumber'] !== null));
$withWebsite = count(array_filter($services, fn($s) => $s['website'] !== null));
$withAddress = count(array_filter($services, fn($s) => $s['address'] !== null));

echo "Stats:\n";
echo "  Total services: " . count($services) . "\n";
echo "  With phone: $withPhone\n";
echo "  With emergency number: $withEmergency\n";
echo "  With website: $withWebsite\n";
echo "  With address: $withAddress\n\n";

// Write output
if (!$dryRun && !$reportOnly) {
    $output = [
        'version' => '1.0',
        'source' => 'publicservice.php',
        'sourceUrl' => PUBLIC_SERVICE_URL,
        'updatedAt' => date('c'),
        'harvestedBy' => 'tools/harvest-public-services.php',
        'services' => $services,
    ];
    file_put_contents(OUTPUT_FILE, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "Written: " . OUTPUT_FILE . "\n";
}

// Generate report
if (!$dryRun) {
    $report = generateReport($services, $withPhone, $withEmergency, $withWebsite, $withAddress);
    file_put_contents(REPORT_FILE, $report);
    echo "Report: " . REPORT_FILE . "\n";
}

echo "\nDone.\n";
exit(0);

function generateAliases(string $name, string $category): array
{
    $aliases = [];
    $lower = strtolower($name);
    $aliases[] = $lower;

    // Add category-based aliases
    $catLower = strtolower($category);
    $aliases[] = $catLower;

    // Add keyword aliases based on service type
    if (stripos($name, 'polisi') !== false || stripos($name, 'polrestabes') !== false || stripos($category, 'Kepolisian') !== false) {
        $aliases[] = 'polisi';
        $aliases[] = 'kantor polisi';
        $aliases[] = 'kapolrestabes';
    }
    if (stripos($name, 'darurat') !== false || stripos($name, '112') !== false) {
        $aliases[] = 'darurat';
        $aliases[] = 'nomor darurat';
        $aliases[] = 'emergency';
    }
    if (stripos($name, 'pemadam') !== false || stripos($name, 'kebakaran') !== false) {
        $aliases[] = 'pemadam';
        $aliases[] = 'kebakaran';
        $aliases[] = 'damkar';
    }
    if (stripos($name, 'ambulans') !== false || stripos($name, 'medis') !== false || stripos($name, 'kesehatan') !== false) {
        $aliases[] = 'ambulans';
        $aliases[] = 'medis';
        $aliases[] = 'kesehatan';
        $aliases[] = 'rumah sakit';
    }
    if (stripos($name, 'whatsapp') !== false || stripos($name, 'wa') !== false) {
        $aliases[] = 'whatsapp';
        $aliases[] = 'wa';
        $aliases[] = 'chat';
    }
    if (stripos($name, 'lapor') !== false) {
        $aliases[] = 'lapor';
        $aliases[] = 'pengaduan';
        $aliases[] = 'complaint';
    }
    if (stripos($name, 'instagram') !== false) {
        $aliases[] = 'instagram';
        $aliases[] = 'ig';
        $aliases[] = 'sosmed';
    }
    if (stripos($name, 'chatbot') !== false || stripos($name, 'pemkot') !== false) {
        $aliases[] = 'chatbot';
        $aliases[] = 'pemkot';
        $aliases[] = 'info kota';
    }
    if (stripos($name, 'pbb') !== false || stripos($name, 'pajak') !== false) {
        $aliases[] = 'pbb';
        $aliases[] = 'pajak';
        $aliases[] = 'pajak bumi bangunan';
    }
    if (stripos($name, 'dnok') !== false || stripos($name, 'kependudukan') !== false) {
        $aliases[] = 'kependudukan';
        $aliases[] = 'ktp';
        $aliases[] = 'identitas';
    }

    return array_unique($aliases);
}

function generateReport(array $services, int $withPhone, int $withEmergency, int $withWebsite, int $withAddress): string
{
    $categories = [];
    foreach ($services as $s) {
        $cat = $s['category'];
        if (!isset($categories[$cat])) $categories[$cat] = 0;
        $categories[$cat]++;
    }

    $report = "# PUBLIC SERVICE HARVEST REPORT\n\n";
    $report .= "**Source:** " . PUBLIC_SERVICE_URL . "\n";
    $report .= "**Generated:** " . date('c') . "\n";
    $report .= "**Tool:** tools/harvest-public-services.php\n\n";

    $report .= "## Summary\n\n";
    $report .= "| Metric | Count |\n";
    $report .= "|--------|-------|\n";
    $report .= "| Total services | " . count($services) . " |\n";
    $report .= "| With phone | $withPhone |\n";
    $report .= "| With emergency number | $withEmergency |\n";
    $report .= "| With address | $withAddress |\n";
    $report .= "| With website | $withWebsite |\n\n";

    $report .= "## Categories\n\n";
    foreach ($categories as $cat => $count) {
        $report .= "- **$cat**: $count services\n";
    }
    $report .= "\n";

    $report .= "## Services\n\n";
    $report .= "| ID | Name | Category | Phone | Emergency | Website |\n";
    $report .= "|----|------|----------|-------|-----------|---------|\n";
    foreach ($services as $s) {
        $report .= "| {$s['id']} | {$s['name']} | {$s['category']} | " . ($s['phone'] ?? '—') . " | " . ($s['emergencyNumber'] ?? '—') . " | " . ($s['website'] ?? '—') . " |\n";
    }
    $report .= "\n";

    $report .= "## Fields Not Available from Source\n\n";
    $report .= "- **address**: Not present on the page (cards only show name/category/icon)\n";
    $report .= "- **operating hours**: Not present on the page\n";
    $report .= "- **description**: Minimal — only name + category visible on cards\n\n";

    $report .= "## Notes\n\n";
    $report .= "- The page uses `<a>` tags with `href=\"tel:...\"` for phone services\n";
    $report .= "- The page uses `<a>` tags with `href=\"https://wa.me/...\"` for WhatsApp\n";
    $report .= "- The Libas service uses a `<button>` that opens a modal (no direct link)\n";
    $report .= "- No geographic/coordinate data available\n";
    $report .= "- No operating hours available\n";

    return $report;
}
