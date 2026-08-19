<?php
declare(strict_types=1);

/**
 * Lawang Sewu — Explore Menu Knowledge Harvester (CLI / build-time only).
 *
 * Fetches the Jelajah (Discover) menu from the live webview endpoint,
 * extracts all visitor-facing place entities, and outputs:
 *   data/explore-menu-knowledge.json
 *
 * Usage:
 *   php tools/harvest-explore-menu.php [--dry-run] [--report-only]
 *
 * Exit codes: 0 ok, 1 error, 3 fetch failure.
 */

const LS_ROOT          = __DIR__ . '/..';
const LS_DISCOVER_URL  = 'https://semarang.govirtual.id/webview/discover.php?disc=6&lang=id';
const LS_OUTPUT_FILE   = LS_ROOT . '/data/explore-menu-knowledge.json';
const LS_REPORT_FILE   = LS_ROOT . '/explore-menu-harvest-report.json';
const LS_PLACE_REGISTRY = LS_ROOT . '/place-registry.json';
const LS_KNOWLEDGE_FILE = LS_ROOT . '/knowledge.json';
const LS_FETCH_TIMEOUT = 15;
const LS_BASE_URL      = 'https://semarang.govirtual.id';

$dryRun     = in_array('--dry-run', $argv);
$reportOnly = in_array('--report-only', $argv);

echo "=== Lawang Sewu Explore Menu Harvester ===\n";
echo "Source: " . LS_DISCOVER_URL . "\n";
echo "Mode: " . ($dryRun ? 'DRY RUN' : ($reportOnly ? 'REPORT ONLY' : 'WRITE')) . "\n\n";

// --- Fetch discover.php ---
$html = @file_get_contents(LS_DISCOVER_URL, false, stream_context_create([
    'http' => ['timeout' => LS_FETCH_TIMEOUT, 'user_agent' => 'LawangSewuHarvester/1.0'],
    'ssl'  => ['verify_peer' => false],
]));

if ($html === false) {
    echo "ERROR: Failed to fetch discover.php\n";
    exit(3);
}

// --- Extract ITEMS JSON from script ---
if (!preg_match('/const\s+ITEMS\s*=\s*(\[.*?\]);/s', $html, $m)) {
    echo "ERROR: Could not extract ITEMS array from HTML\n";
    exit(1);
}

$items = json_decode($m[1], true);
if (!is_array($items)) {
    echo "ERROR: Invalid JSON in ITEMS array\n";
    exit(1);
}

echo "Found " . count($items) . " explore menu items.\n\n";

// --- Load place registry for scene mapping ---
$placeRegistry = json_decode(file_get_contents(LS_PLACE_REGISTRY), true);
$places = $placeRegistry['places'] ?? [];

// --- Load existing knowledge for cross-reference ---
$knowledge = json_decode(file_get_contents(LS_KNOWLEDGE_FILE), true);
$knowledgeEntries = $knowledge['entries'] ?? [];

// --- Build scene mapping (title -> placeId -> sceneIds) ---
$titleToPlace = [];
foreach ($places as $placeId => $place) {
    $titleToPlace[strtolower($place['name'])] = [
        'placeId' => $placeId,
        'sceneIds' => $place['sceneIds'] ?? [],
        'aliases' => $place['aliases'] ?? [],
    ];
    foreach ($place['aliases'] ?? [] as $alias) {
        $titleToPlace[strtolower($alias)] = [
            'placeId' => $placeId,
            'sceneIds' => $place['sceneIds'] ?? [],
            'aliases' => $place['aliases'] ?? [],
        ];
    }
}

// --- Build knowledge cross-reference ---
function findRelatedKnowledge(string $title, string $description, array $knowledgeEntries): array {
    $related = [];
    $titleNorm = strtolower(trim($title));
    $descWords = array_filter(explode(' ', strtolower($description)), fn($w) => strlen($w) > 3);

    foreach ($knowledgeEntries as $entry) {
        $entryTitle = strtolower($entry['title'] ?? '');
        $entryContent = strtolower($entry['content'] ?? '');
        $entryAliases = array_map('strtolower', $entry['aliases'] ?? []);

        // Match by title similarity
        $allTitles = array_merge([$entryTitle], $entryAliases);
        foreach ($allTitles as $t) {
            if (strlen($t) < 3) continue;
            if (str_contains($titleNorm, $t) || str_contains($t, $titleNorm)) {
                $related[] = $entry['id'];
                continue 2;
            }
        }

        // Match by description keyword overlap
        $hits = 0;
        foreach ($descWords as $word) {
            if (str_contains($entryTitle . ' ' . $entryContent, $word)) $hits++;
        }
        if (count($descWords) > 0 && $hits / count($descWords) >= 0.4) {
            $related[] = $entry['id'];
        }
    }
    return array_unique($related);
}

// --- Process each item ---
$entries = [];
$report = [
    'source' => LS_DISCOVER_URL,
    'generatedAt' => date('c'),
    'itemCount' => count($items),
    'items' => [],
    'sceneMappings' => 0,
    'knowledgeLinks' => 0,
    'unmapped' => [],
];

foreach ($items as $idx => $item) {
    $title = $item['itmtitle'] ?? '';
    $desc = $item['itmdesc'] ?? '';
    $groupId = $item['itmgroupid'] ?? '';
    $link = $item['itmlink'] ?? '';
    $imgAlt = $item['imgalt'] ?? '';
    $itemImage = $item['item_image'] ?? '';
    $bgImage = $item['background_image'] ?? '';

    // Generate ID
    $entityId = strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($title)));
    $entityId = trim($entityId, '-');

    // Find scene mapping
    $sceneIds = [];
    $placeId = null;
    $titleLower = strtolower($title);
    foreach ($titleToPlace as $placeName => $mapping) {
        if (str_contains($titleLower, $placeName) || str_contains($placeName, $titleLower)) {
            $sceneIds = $mapping['sceneIds'];
            $placeId = $mapping['placeId'];
            break;
        }
    }

    // Fuzzy match if no exact match
    if (empty($sceneIds)) {
        $titleTokens = array_filter(explode(' ', $titleLower), fn($w) => strlen($w) > 2);
        foreach ($titleToPlace as $placeName => $mapping) {
            $hits = 0;
            foreach ($titleTokens as $token) {
                if (str_contains($placeName, $token)) $hits++;
            }
            if (count($titleTokens) > 0 && $hits / count($titleTokens) >= 0.5) {
                $sceneIds = $mapping['sceneIds'];
                $placeId = $mapping['placeId'];
                break;
            }
        }
    }

    // Find related knowledge
    $relatedKnowledge = findRelatedKnowledge($title, $desc, $knowledgeEntries);

    // Build aliases
    $aliases = [$titleLower];
    if ($titleLower !== $entityId) $aliases[] = $entityId;
    // Add English-like aliases
    $englishMap = [
        'cetak biru' => ['blue print', 'blueprint', 'blueprints'],
        'galeri warisan' => ['heritage gallery', 'gallery heritage'],
        'lokomotiv' => ['lokomotif', 'locomotive', 'train', 'loco'],
        'ruang pameran sejarah' => ['history exhibition', 'exhibition room', 'sejarah pameran'],
        'toko suvenir' => ['souvenir shop', 'toko souvenir', 'souvenir'],
    ];
    foreach ($englishMap[$titleLower] ?? [] as $en) {
        $aliases[] = $en;
    }

    $entry = [
        'id' => "explore:$entityId",
        'entityId' => $entityId,
        'title' => $title,
        'aliases' => array_unique($aliases),
        'type' => 'place',
        'description' => $desc,
        'sceneIds' => $sceneIds,
        'placeId' => $placeId,
        'relatedKnowledgeIds' => $relatedKnowledge,
        'relatedExploreIds' => [],
        'thumbnail' => "assets/images/discover/thumb/$itemImage.webp",
        'navigationUrl' => LS_BASE_URL . '/' . ltrim($link, '/'),
        'source' => "discover.php?disc=6 (Jelajah menu item $idx)",
        'sourceGroupId' => $groupId,
        'confidence' => $placeId ? 'exact' : 'unmapped',
    ];

    $entries[] = $entry;

    $reportItem = [
        'title' => $title,
        'entityId' => $entityId,
        'placeId' => $placeId,
        'sceneIds' => $sceneIds,
        'relatedKnowledge' => $relatedKnowledge,
        'confidence' => $entry['confidence'],
    ];
    $report['items'][] = $reportItem;

    if ($placeId) $report['sceneMappings']++;
    else $report['unmapped'][] = $title;
    $report['knowledgeLinks'] += count($relatedKnowledge);

    echo "  [$idx] $title\n";
    echo "      placeId: " . ($placeId ?: 'NONE') . "\n";
    echo "      sceneIds: " . (empty($sceneIds) ? 'NONE' : implode(', ', $sceneIds)) . "\n";
    echo "      knowledge: " . (empty($relatedKnowledge) ? 'NONE' : implode(', ', $relatedKnowledge)) . "\n";
    echo "      confidence: {$entry['confidence']}\n\n";
}

// --- Build output ---
$output = [
    'version' => '1.0',
    'tourId' => 'lawang-sewu',
    'sourceType' => 'lawang-sewu-explore-menu',
    'sourceUrl' => LS_DISCOVER_URL,
    'generatedAt' => date('c'),
    'generatedBy' => 'tools/harvest-explore-menu.php',
    'entries' => $entries,
];

// --- Write output ---
if (!$dryRun && !$reportOnly) {
    file_put_contents(LS_OUTPUT_FILE, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nWrote: " . LS_OUTPUT_FILE . "\n";
}

// --- Write report ---
$report['status'] = empty($report['unmapped']) ? 'PASS' : 'PARTIAL';
file_put_contents(LS_REPORT_FILE, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Report: " . LS_REPORT_FILE . "\n";

// --- Summary ---
echo "\n=== Summary ===\n";
echo "Items found:      " . count($entries) . "\n";
echo "Scene mapped:     {$report['sceneMappings']}\n";
echo "Knowledge links:  {$report['knowledgeLinks']}\n";
echo "Unmapped:         " . count($report['unmapped']) . "\n";
if (!empty($report['unmapped'])) {
    echo "Unmapped items:   " . implode(', ', $report['unmapped']) . "\n";
}
echo "Status: {$report['status']}\n";

exit(0);
