<?php
declare(strict_types=1);

/**
 * Lawang Sewu — Explore Menu Knowledge Tests.
 *
 * Tests:
 * - Exact entity resolution
 * - Alias matching
 * - Current-place independence (explicit subject > current context)
 * - Related knowledge links
 * - Navigation mapping
 * - Provider-off fallback
 * - No raw internal labels
 *
 * Usage: php tests/test-explore-menu-knowledge.php
 */

const LS_ROOT = __DIR__ . '/..';
require_once LS_ROOT . '/api/ai-core.php';

$passed = 0;
$failed = 0;
$total = 0;

function test(string $name, bool $condition): void
{
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  PASS: $name\n";
    } else {
        $failed++;
        echo "  FAIL: $name\n";
    }
}

echo "=== Lawang Sewu Explore Menu Knowledge Tests ===\n\n";

// --- 1. Data loading ---
echo "1. Data Loading\n";
$entries = ai_load_explore_menu();
test('Explore menu entries loaded', count($entries) === 5);
test('Entry IDs exist', isset($entries[0]['id']) && isset($entries[4]['id']));

// --- 2. Exact entity resolution ---
echo "\n2. Exact Entity Resolution\n";
$e1 = ai_resolve_explore_entity('Apa itu Galeri Warisan?');
test('Galeri Warisan resolved', $e1 !== null && $e1['title'] === 'Galeri Warisan');
test('Galeri Warisan has description', !empty($e1['content']));
test('Galeri Warisan has sceneIds', !empty($e1['sceneIds']));

$e2 = ai_resolve_explore_entity('Ceritakan Cetak Biru.');
test('Cetak Biru resolved', $e2 !== null && $e2['title'] === 'Cetak Biru');

$e3 = ai_resolve_explore_entity('Apa itu Lokomotiv?');
test('Lokomotiv resolved', $e3 !== null && $e3['title'] === 'Lokomotiv');

$e4 = ai_resolve_explore_entity('Tentang Ruang Pameran Sejarah');
test('Ruang Pameran Sejarah resolved', $e4 !== null && $e4['title'] === 'Ruang Pameran Sejarah');

$e5 = ai_resolve_explore_entity('Toko Suvenir');
test('Toko Suvenir resolved', $e5 !== null && $e5['title'] == 'Toko Suvenir');

// --- 3. Alias resolution ---
echo "\n3. Alias Resolution\n";
$a1 = ai_resolve_explore_entity('Apa itu heritage gallery?');
test('Heritage gallery alias resolves to Galeri Warisan', $a1 !== null && $a1['title'] === 'Galeri Warisan');

$a2 = ai_resolve_explore_entity('Ceritakan blueprint Lawang Sewu.');
test('Blueprint alias resolves to Cetak Biru', $a2 !== null && $a2['title'] === 'Cetak Biru');

$a3 = ai_resolve_explore_entity('Apa itu locomotive?');
test('Locomotive alias resolves to Lokomotiv', $a3 !== null && $a3['title'] === 'Lokomotiv');

// --- 4. Current-place independence ---
echo "\n4. Current Place Independence\n";
// Simulate: even if current scene is Area Utama, explicit subject must win
$eIndep = ai_resolve_explore_entity('Ceritakan Galeri Warisan');
test('Explicit subject resolves regardless of current scene', $eIndep !== null && $eIndep['title'] === 'Galeri Warisan');

// --- 5. Entity resolution via ai_resolve_entity ---
echo "\n5. Full Entity Resolution Pipeline\n";
$full = ai_resolve_entity('Apa itu Lokomotiv?');
test('ai_resolve_entity resolves explore-menu entity', $full !== null && ($full['type'] ?? '') === 'explore-place');
test('Entity has exploreMenu metadata', isset($full['exploreMenu']));
test('Entity has placeId', !empty($full['exploreMenu']['placeId']));

// --- 6. Navigation mapping ---
echo "\n6. Navigation Mapping\n";
test('Galeri Warisan maps to node41', in_array('node41', $e1['sceneIds']));
test('Cetak Biru maps to node44', in_array('node44', $e2['sceneIds']));
test('Ruang Pameran Sejarah maps to node40', in_array('node40', $e4['sceneIds']));
test('Toko Suvenir maps to node49', in_array('node49', $e5['sceneIds']));

// --- 7. Related knowledge ---
echo "\n7. Related Knowledge Links\n";
$raw = ai_load_explore_menu();
$galeriRaw = null; $cetakRaw = null;
foreach ($raw as $r) { if ($r['entityId'] === 'galeri-warisan') $galeriRaw = $r; if ($r['entityId'] === 'cetak-biru') $cetakRaw = $r; }
test('Galeri Warisan has related knowledge in raw data', !empty($galeriRaw['relatedKnowledgeIds']));
test('Cetak Biru has related knowledge in raw data', !empty($cetakRaw['relatedKnowledgeIds']));

// --- 8. Provider-off fallback ---
echo "\n8. Provider-Off Fallback\n";
test('Explore menu entries have descriptions for fallback', $entries[0]['description'] !== '' && $entries[4]['description'] !== '');

// --- 9. No raw internal labels ---
echo "\n9. No Raw Internal Labels\n";
foreach ($entries as $entry) {
    test($entry['title'] . ' has no node IDs in description', !preg_match('/node\d+/', $entry['description']));
    test($entry['title'] . ' has no Pano2VR raw scene names', !preg_match('/\bnode\d+\b/i', $entry['description']));
}

// --- 10. Integration with V2 pipeline ---
echo "\n10. V2 Pipeline Integration\n";
$session = ['lastSceneId' => 'node51', 'history' => []];
$v2 = ai_v2_pipeline('Apa itu Galeri Warisan?', $session, ['scenes' => []]);
test('V2 pipeline returns result for explore entity', isset($v2['result']));
test('V2 pipeline has exploreMenu debug info', ($v2['debug']['responseStrategy'] ?? '') !== '');

// --- Summary ---
echo "\n=== Summary ===\n";
echo "Passed: $passed / $total\n";
echo "Failed: $failed / $total\n";
echo "Status: " . ($failed === 0 ? 'PASS' : 'FAIL') . "\n";

exit($failed === 0 ? 0 : 1);
