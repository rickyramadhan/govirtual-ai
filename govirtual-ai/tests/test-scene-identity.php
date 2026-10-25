<?php

declare(strict_types=1);

/**
 * test-scene-identity.php
 * Verify scene-identity.json + place-registry.json consistency and PHP/JS resolvers.
 *
 * Run: php tests/test-scene-identity.php
 */

$root = dirname(__DIR__);

$failures = [];

function check(string $name, bool $ok, string $detail = ''): void {
    global $failures;
    if (!$ok) $failures[] = $name . ($detail !== '' ? " — $detail" : '');
}

$identity = json_decode((string)@file_get_contents($root . '/scene-identity.json'), true);
check('scene-identity.json parses', $identity !== null);
if (!$identity) { fwrite(STDERR, "FATAL: scene-identity.json broken\n"); exit(1); }

$registry = json_decode((string)@file_get_contents($root . '/place-registry.json'), true);
check('place-registry.json parses', $registry !== null);
if (!$registry) { fwrite(STDERR, "FATAL: place-registry.json broken\n"); exit(1); }

$placeMap = json_decode((string)@file_get_contents($root . '/place-map.json'), true);
check('place-map.json parses', $placeMap !== null);
if (!$placeMap) { fwrite(STDERR, "FATAL: place-map.json broken\n"); exit(1); }

$tourJs = file_get_contents(dirname(__DIR__) . '/data/tour-data.js');
check('tour-data.js readable', $tourJs !== false);
if ($tourJs === false) { fwrite(STDERR, "FATAL: tour-data.js unreadable\n"); exit(1); }

// ---- scene-identity.json coverage ----
$identScenes = $identity['scenes'] ?? [];
$mapScenesRaw = $placeMap['scenes'] ?? [];
// Index mapScenes by sceneId for lookups
$mapScenes = [];
foreach ($mapScenesRaw as $ms) { $mapScenes[$ms['sceneId']] = $ms; }
check('identity covers all 35 scenes', count($identScenes) === 35, 'got ' . count($identScenes));
check('identity covers every place-map scene', count(array_diff(array_keys($mapScenes), array_keys($identScenes))) === 0);
$identPlaces = $identity['places'] ?? [];
$regPlaces = $registry['places'] ?? [];
check('registry has all identity places', count(array_diff(array_keys($identPlaces), array_keys($regPlaces))) === 0);

// Every identity scene: placeId resolves in places, placeName non-empty, no raw technical label leak.
$rawToPlace = [];
foreach ($identScenes as $sid => $def) {
    $pid = $def['placeId'] ?? '';
    $pn = $def['placeName'] ?? '';
    $rn = $def['rawName'] ?? '';
    check("scene $sid has placeId", $pid !== '');
    check("scene $sid has placeName", $pn !== '');
    check("scene $sid placeId exists in places", isset($identPlaces[$pid]));
    $expectedName = $identPlaces[$pid]['name'] ?? '';
    check("scene $sid placeName matches place name", $expectedName === $pn, "expected '$expectedName' got '$pn'");
    check("scene $sid in place sceneIds", in_array($sid, $identPlaces[$pid]['sceneIds'] ?? [], true));
    // No nodeX in any visitor-facing name
    check("scene $sid placeName has no node id", !preg_match('/node\d+/i', $pn));
    if ($rn !== '' && $rn !== $pn) $rawToPlace[$rn] = $pn;
}

// Registry ↔ identity place consistency (names + sceneIds)
foreach ($regPlaces as $pid => $p) {
    $expectedName = $identPlaces[$pid]['name'] ?? '';
    check("registry place $pid name matches identity", $expectedName === $p['name'], "got '{$p['name']}'");
    check("registry place $pid sceneIds match identity", ($identPlaces[$pid]['sceneIds'] ?? []) === $p['sceneIds']);
}

// Raw technical names must be mappable (every rawName that differs has a visitor label)
foreach ($mapScenes as $scene) {
    $sid = $scene['sceneId'];
    $rn = $scene['rawName'] ?? '';
    $idDef = $identScenes[$sid] ?? null;
    check("identity entry for $sid", $idDef !== null);
    if ($idDef) {
        $pn = $idDef['placeName'] ?? '';
        check("scene $sid rawName differs -> visitor label exists", $rn === $pn || $pn !== '');
        $mapped = $rawToPlace[$rn] ?? '';
        check("scene $sid rawName never equals a visitor place name of another place", $mapped === $pn || !isset($rawToPlace[$rn]));
    }
}

// ---- tour-data.js _sceneRegistry ----
preg_match('/_sceneRegistry:\s*\{.*?\}\s*\n\s*\}/s', $tourJs, $m);
check('tour-data.js has _sceneRegistry', isset($m[0]));
$registryCount = 0;
if (isset($m[0])) {
    preg_match_all('/node\d+:\s*\{placeId:\'[^\']+\',placeName:\'[^\']+\'\}/', $m[0], $entries);
    $registryCount = count($entries[0]);
    check('_sceneRegistry covers all 35 scenes', $registryCount === 35, "got $registryCount");
    foreach ($entries[0] as $entry) {
        preg_match('/^node(\d+):\s*\{placeId:\'([^\']+)\',placeName:\'([^\']+)\'\}/', $entry, $em);
        $sid = 'node' . $em[1];
        $pid = $em[2];
        $pn = $em[3];
        $idDef = $identScenes[$sid] ?? null;
        check("_sceneRegistry $sid placeName matches identity", $idDef !== null && $pn === ($idDef['placeName'] ?? ''), "got '$pn'");
        check("_sceneRegistry $sid placeId matches identity", $idDef !== null && $pid === ($idDef['placeId'] ?? ''), "got '$pid'");
    }
}

// ---- PHP resolvers ----
require_once $root . '/api/ai-core.php';
$visitor = ai_get_visitor_label('node51');
check('ai_get_visitor_label node51 -> Area Utama', $visitor === 'Area Utama Lawang Sewu', "got '$visitor'");
$visitor2 = ai_get_visitor_label('node61');
check('ai_get_visitor_label node61 -> Aula Lawang Sewu', $visitor2 === 'Aula Lawang Sewu', "got '$visitor2'");
$visitor3 = ai_get_visitor_label('node73');
check('ai_get_visitor_label node73 -> Galeri Museum Kereta Api', $visitor3 === 'Galeri Museum Kereta Api', "got '$visitor3'");
$leak = ai_technical_label_check('Lanjut ke Main Siang sekarang.', 'node51');
check('ai_technical_label_check strips Main Siang', $leak === 'Lanjut ke Area Utama Lawang Sewu sekarang.', "got '$leak'");
$leak2 = ai_technical_label_check('Anda berada di Default - Day.', 'node53');
check('ai_technical_label_check strips Default - Day', $leak2 === 'Anda berada di Balkon.', "got '$leak2'");
$clean = ai_technical_label_check('Balkon adalah area yang tenang.', 'node53');
check('ai_technical_label_check leaves clean reply', $clean === 'Balkon adalah area yang tenang.', "got '$clean'");

// ---- visitor label never raw technical ----
foreach (array_keys($mapScenes) as $sid) {
    $label = ai_get_visitor_label($sid);
    check("ai_get_visitor_label $sid non-empty", $label !== '');
    check("ai_get_visitor_label $sid has no node id", !preg_match('/node\d+/i', $label));
}

// ---- enriched scene identity wiring ----
$loaded = ai_load_scene_identity();
check('ai_load_scene_identity loads', $loaded['version'] !== '', "version=" . ($loaded['version'] ?? ''));
check('ai_load_scene_identity has 35 scenes', count($loaded['scenes']) === 35, 'got ' . count($loaded['scenes']));
check('ai_load_scene_identity has 29 places', count($loaded['places']) === 29, 'got ' . count($loaded['places']));

// Specific scene descriptions exist
$node39 = ai_get_scene_identity('node39');
check('node39 scene identity found', $node39 !== null);
check('node39 has description', ($node39['description'] ?? '') !== '', 'empty');
$node61 = ai_get_scene_identity('node61');
check('node61 scene identity found', $node61 !== null);
check('node61 has description', ($node61['description'] ?? '') !== '', 'empty');
$node67 = ai_get_scene_identity('node67');
check('node67 scene identity found', $node67 !== null);
check('node67 has description', ($node67['description'] ?? '') !== '', 'empty');
$node71 = ai_get_scene_identity('node71');
check('node71 scene identity found', $node71 !== null);
check('node71 has description', ($node71['description'] ?? '') !== '', 'empty');

// Place-level descriptions
$hallPlace = ai_get_place_identity('hall');
check('hall place identity found', $hallPlace !== null);
check('hall place has description', ($hallPlace['description'] ?? '') !== '', 'empty');
$galeriPlace = ai_get_place_identity('galeri-museum-kereta');
check('galeri-museum-kereta place identity found', $galeriPlace !== null);
check('galeri-museum-kereta place has description', ($galeriPlace['description'] ?? '') !== '', 'empty');
$mainAreaPlace = ai_get_place_identity('main-area');
check('main-area place identity found', $mainAreaPlace !== null);
check('main-area place has description', ($mainAreaPlace['description'] ?? '') !== '', 'empty');
$gedungEPlace = ai_get_place_identity('gedung-e');
check('gedung-e place identity found', $gedungEPlace !== null);
check('gedung-e place has description', ($gedungEPlace['description'] ?? '') !== '', 'empty');

// Enriched description resolver
$enriched = ai_get_enriched_description('node39');
check('ai_get_enriched_description node39 found', $enriched['found'] === true);
check('ai_get_enriched_description node39 has description', $enriched['description'] !== '', 'empty');
check('ai_get_enriched_description node39 placeName', $enriched['placeName'] === 'Area Patung', "got '{$enriched['placeName']}'");
$enrichedHall = ai_get_enriched_description('node61');
check('ai_get_enriched_description node61 found', $enrichedHall['found'] === true);
check('ai_get_enriched_description node61 has description', $enrichedHall['description'] !== '', 'empty');
$enrichedGedungE = ai_get_enriched_description('node67');
check('ai_get_enriched_description node67 found', $enrichedGedungE['found'] === true);
check('ai_get_enriched_description node67 has description', $enrichedGedungE['description'] !== '', 'empty');

// Named place lookup
$foundPlace = ai_find_place_by_visitor_name('Galeri Museum Kereta Api');
check('ai_find_place_by_visitor_name galeri museum', $foundPlace !== null);
check('ai_find_place_by_visitor_name galeri museum name', ($foundPlace['name'] ?? '') === 'Galeri Museum Kereta Api', "got '{$foundPlace['name']}'");
$foundArea = ai_find_place_by_visitor_name('Area Utama Lawang Sewu');
check('ai_find_place_by_visitor_name area utama', $foundArea !== null);
check('ai_find_place_by_visitor_name area utama name', ($foundArea['name'] ?? '') === 'Area Utama Lawang Sewu', "got '{$foundArea['name']}'");
$foundAula = ai_find_place_by_visitor_name('Aula Lawang Sewu');
check('ai_find_place_by_visitor_name aula', $foundAula !== null);
$foundGedungE = ai_find_place_by_visitor_name('Gedung E');
check('ai_find_place_by_visitor_name gedung e', $foundGedungE !== null);

// ai_place_context includes enriched fields
$placeCtx = ai_place_context('node39', ['scenes' => [['id' => 'node39']]]);
check('ai_place_context node39 has enrichedDescription', ($placeCtx['enrichedDescription'] ?? '') !== '', 'empty');
check('ai_place_context node39 has enrichedHighlights', is_array($placeCtx['enrichedHighlights'] ?? null));
$placeCtxHall = ai_place_context('node61', ['scenes' => [['id' => 'node61']]]);
check('ai_place_context node61 has enrichedDescription', ($placeCtxHall['enrichedDescription'] ?? '') !== '', 'empty');

// ---- Frontend tour-chat.js checks ----
// Verify "Belum ada deskripsi" is NOT in sceneSummary
$tourChatJs = file_get_contents($root . '/tour-chat.js');
check('tour-chat.js does not contain "Belum ada deskripsi"', mb_strpos($tourChatJs, 'Belum ada deskripsi') === false);
// Verify "ceritakan" is excluded from local area interception
check('tour-chat.js excludes ceritakan from local area interception', (bool)preg_match('/ceritakan.*area ini|area ini.*ceritakan/', $tourChatJs) || (bool)preg_match('/ceritakan\|jelaskan\|deskripsikan/', $tourChatJs));

// ============================================================
fwrite(STDOUT, count($failures) . " failures\n");
if ($failures) {
    fwrite(STDOUT, implode("\n", array_map(fn($f) => "  FAIL: $f", $failures)) . "\n");
    exit(1);
}
fwrite(STDOUT, "SCENE IDENTITY TEST SUITE: PASS\n");
exit(0);
