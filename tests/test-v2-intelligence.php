<?php

declare(strict_types=1);

/**
 * test-v2-intelligence.php
 * V2 PLACE-AWARE INTENT-FIRST regression suite.
 *
 * Run: php tests/test-v2-intelligence.php
 *
 * Covers the 12-intent pipeline deterministically (provider OFF):
 *  - intent classification (12 intents, ordered rules)
 *  - place context (scene -> placeId/placeName via place-map.json)
 *  - entity resolver priority (exact > alias > overview > fuzzy; never scene-first)
 *  - grounding ranking (exact entity > current-place EXACT > LIKELY > tour-wide; graph excluded)
 *  - navigation isolation (entity mention != navigate; only explicit nav verbs)
 *  - unknown fact policy (ticket/opening hours: honest, no disambiguation, no numbers)
 *  - description replies: no panorama framing, no auto graph-exit list
 *  - conversation continuity (FOLLOWUP_NAVIGATION resolves lastNavigableSceneId)
 */

$root = dirname(__DIR__);
require_once $root . '/api/ai-core.php';

putenv('AI_REASONING_MODE=fast');
putenv('OLLAMA_URL=http://127.0.0.1:1');

$tour = ai_canonical_tour([
    'scenes' => [
        ['id' => 'node51', 'name' => 'Main Siang', 'rawName' => 'Main Siang', 'displayName' => 'Main Siang', 'aliases' => [], 'description' => '', 'highlights' => [], 'facilities' => [], 'whoFor' => []],
        ['id' => 'node50', 'name' => 'Main Malam', 'rawName' => 'Main Malam', 'displayName' => 'Main Malam', 'aliases' => [], 'description' => '', 'highlights' => [], 'facilities' => [], 'whoFor' => []],
        ['id' => 'node61', 'name' => 'Hall Siang', 'rawName' => 'Hall Siang', 'displayName' => 'Hall Siang', 'aliases' => [], 'description' => '', 'highlights' => [], 'facilities' => [], 'whoFor' => []],
        ['id' => 'node45', 'name' => 'Gedung D', 'rawName' => 'Gedung D', 'displayName' => 'Gedung D', 'aliases' => [], 'description' => '', 'highlights' => [], 'facilities' => [], 'whoFor' => []],
        ['id' => 'node64', 'name' => 'Kereta Siang', 'rawName' => 'Kereta Siang', 'displayName' => 'Kereta Siang', 'aliases' => [], 'description' => '', 'highlights' => [], 'facilities' => [], 'whoFor' => []],
        ['id' => 'node57', 'name' => 'Pintu Masuk', 'rawName' => 'Pintu Masuk', 'displayName' => 'Pintu Masuk', 'aliases' => [], 'description' => '', 'highlights' => [], 'facilities' => [], 'whoFor' => []],
    ],
    'hotspots' => [
        ['scene' => 'node51', 'title' => 'Hall', 'target' => 'node61'],
        ['scene' => 'node51', 'title' => 'Kereta', 'target' => 'node64'],
        ['scene' => 'node61', 'title' => 'Gedung D', 'target' => 'node45'],
        ['scene' => 'node45', 'title' => 'Pintu', 'target' => 'node57'],
        ['scene' => 'node57', 'title' => 'Hall', 'target' => 'node61'],
    ],
    'routes' => [],
    'video360' => [],
    'ui' => [],
]);

$tests = 0; $passed = 0; $failures = [];

function t(string $name, bool $ok, string $detail = ''): void {
    global $tests, $passed, $failures;
    $tests++;
    if ($ok) { $passed++; return; }
    $failures[] = $name;
    fwrite(STDOUT, "FAIL: $name" . ($detail !== '' ? " ($detail)" : '') . "\n");
}

// ============================================================
// 1. Intent classification
// ============================================================
t('classify CURRENT_LOCATION', ai_classify_intent('Di mana saya sekarang?') === 'CURRENT_LOCATION');
t('classify PLACE_DESCRIPTION', ai_classify_intent('Ceritakan area ini') === 'PLACE_DESCRIPTION');
t('classify HIGHLIGHTS deictic', ai_classify_intent('Apa yang menarik di sini?') === 'HIGHLIGHTS');
t('classify ENTITY_DESCRIPTION gedung', ai_classify_intent('Ceritakan Gedung D') === 'ENTITY_DESCRIPTION');
t('classify TOUR_DESCRIPTION', ai_classify_intent('Ceritakan tentang Lawang Sewu') === 'TOUR_DESCRIPTION');
t('classify FACTUAL_QUESTION', ai_classify_intent('Kapan Lawang Sewu dibangun?') === 'ENTITY_DESCRIPTION' || ai_classify_intent('Kapan Lawang Sewu dibangun?') === 'FACTUAL_QUESTION');
t('classify HIGHLIGHTS spot foto', ai_classify_intent('Spot foto bagus di mana?') === 'HIGHLIGHTS');
t('classify FACILITY', ai_classify_intent('Di mana toilet?') === 'FACILITY');
t('classify NAVIGATION_REQUEST', ai_classify_intent('Ke Gedung D') === 'NAVIGATION_REQUEST');
t('classify NAVIGATION_REQUEST bawa', ai_classify_intent('Bawa saya ke Gedung D') === 'NAVIGATION_REQUEST');
t('classify ROUTE_RECOMMENDATION', ai_classify_intent('Rekomendasikan rute wisata') === 'ROUTE_RECOMMENDATION');
t('classify FOLLOWUP_NAVIGATION', ai_classify_intent('Ke sana') === 'FOLLOWUP_NAVIGATION');
t('classify UNKNOWN_FACT tiket', ai_classify_intent('Berapa harga tiket masuk hari ini?') === 'UNKNOWN_FACT');
t('classify UNKNOWN_FACT jam', ai_classify_intent('Jam buka museum?') === 'UNKNOWN_FACT');
t('classify GENERAL_CONVERSATION', ai_classify_intent('Halo') === 'GENERAL_CONVERSATION');

// ============================================================
// 2. Place context
// ============================================================
$placeMain = ai_place_context('node51', $tour);
t('place context node51 -> main-area', $placeMain['currentPlaceId'] === 'main-area' && str_starts_with($placeMain['currentPlaceName'], 'Area Utama'));
t('place context node51 rawName kept', $placeMain['currentRawSceneName'] === 'Main Siang');
$placeHall = ai_place_context('node62', $tour);
t('place context node62 -> hall (variant)', $placeHall['currentPlaceId'] === 'hall' && $placeHall['currentPlaceName'] === 'Aula Lawang Sewu');

// ============================================================
// 3. Entity resolver priority (knowledge-first, never scene-first)
// ============================================================
$entGedungD = ai_resolve_entity('Ceritakan Gedung D');
t('resolve Gedung D to knowledge entity', $entGedungD !== null && $entGedungD['title'] === 'Gedung D');
$entTugu = ai_resolve_entity('Ceritakan Tugu Marmer');
t('resolve Tugu Marmer alias', $entTugu !== null && $entTugu['title'] === 'Tugu Marmer');
$entRoot = ai_resolve_entity('Ceritakan Lawang Sewu');
t('resolve Lawang Sewu to root overview entity', $entRoot !== null && $entRoot['type'] === 'overview');
$entNone = ai_resolve_entity('Berapa harga tiket?');
t('resolve unknown does not match scene', $entNone === null);

// ============================================================
// 4. Grounding: exact entity first, graph excluded from factual ranking
// ============================================================
$grounding = ai_retrieve_v2('Ceritakan Gedung D', $placeMain);
t('retrieve_v2 ranks Gedung D first', ($grounding['items'][0]['entry']['title'] ?? '') === 'Gedung D');
$groundingArea = ai_retrieve_v2('Apa yang menarik di sini?', $placeMain);
t('retrieve_v2 area query returns main-area mapped knowledge', ($groundingArea['items'][0]['entry']['title'] ?? '') === 'Selamat Datang di Lawang Sewu');

// ============================================================
// 5. Pipeline: description does NOT navigate, no panorama, no auto-exits
// ============================================================
$pipelineDesc = ai_v2_pipeline('Ceritakan Gedung D', ['lastSceneId' => 'node51'], $tour, ai_config());
t('pipeline Gedung D -> entity knowledge', $pipelineDesc['result']['intent'] === 'ENTITY_DESCRIPTION' && $pipelineDesc['result']['entities'][0]['name'] === 'Gedung D');
t('pipeline Gedung D does NOT navigate', $pipelineDesc['result']['action'] === 'none' && empty($pipelineDesc['result']['actions']));
t('pipeline Gedung D reply has no panorama framing', strpos($pipelineDesc['result']['reply'], 'panorama') === false);

$pipelineArea = ai_v2_pipeline('Ceritakan area ini', ['lastSceneId' => 'node51'], $tour, ai_config());
t('pipeline area -> place knowledge, no navigate', $pipelineArea['result']['intent'] === 'PLACE_DESCRIPTION' && $pipelineArea['result']['action'] === 'none');
t('pipeline area debug has currentPlaceName', str_starts_with($pipelineArea['debug']['currentPlaceName'], 'Area Utama'));

$pipelineLoc = ai_v2_pipeline('Di mana saya?', ['lastSceneId' => 'node51'], $tour, ai_config());
t('pipeline location -> place name', $pipelineLoc['result']['intent'] === 'CURRENT_LOCATION' && strpos($pipelineLoc['result']['reply'], 'Area Utama') !== false);
t('pipeline location no raw scene name', strpos($pipelineLoc['result']['reply'], 'node51') === false && strpos($pipelineLoc['result']['reply'], 'Main Siang') === false);

// ============================================================
// 6. Navigation isolation: explicit intent required
// ============================================================
$pipelineNav = ai_v2_pipeline('Ke Gedung D', ['lastSceneId' => 'node51'], $tour, ai_config());
t('pipeline nav Gedung D -> go_to_scene', $pipelineNav['result']['action'] === 'go_to_scene' && $pipelineNav['result']['scene'] === 'node45');
t('pipeline nav debug navigationIntent set', $pipelineNav['debug']['navigationIntent'] === 'node45');

$pipelineFollowup = ai_v2_pipeline('Ke sana', ['lastSceneId' => 'node51', 'lastNavigableSceneId' => 'node45'], $tour, ai_config());
t('pipeline followup ke sana -> stored destination', $pipelineFollowup['result']['action'] === 'go_to_scene' && $pipelineFollowup['result']['scene'] === 'node45');

$pipelineNoNav = ai_v2_pipeline('Ceritakan Gedung D', ['lastSceneId' => 'node51'], $tour, ai_config());
t('pipeline entity mention never navigates', $pipelineNoNav['result']['action'] === 'none');

// ============================================================
// 7. Unknown fact policy
// ============================================================
$pipelineTicket = ai_v2_pipeline('Berapa harga tiket hari ini?', ['lastSceneId' => 'node51'], $tour, ai_config());
t('pipeline ticket -> UNKNOWN_FACT', $pipelineTicket['result']['intent'] === 'UNKNOWN_FACT');
t('pipeline ticket no disambiguation', $pipelineTicket['result']['needs_clarification'] === false && empty($pipelineTicket['result']['clarification_options']));
t('pipeline ticket no invented numbers', preg_match('/\d+\s*(rb|ribu|k|rupiah)/i', $pipelineTicket['result']['reply']) === 0);
t('pipeline ticket honest wording', strpos($pipelineTicket['result']['reply'], 'belum memiliki informasi') !== false);

$pipelineJam = ai_v2_pipeline('Jam buka museum?', ['lastSceneId' => 'node51'], $tour, ai_config());
t('pipeline jam -> UNKNOWN_FACT', $pipelineJam['result']['intent'] === 'UNKNOWN_FACT');
t('pipeline jam no navigation', $pipelineJam['result']['action'] === 'none');

// ============================================================
// 8. Debug trace completeness
// ============================================================
$debugKeys = array_keys($pipelineDesc['debug']);
foreach (['intent', 'subjectType', 'subjectId', 'currentSceneId', 'currentRawSceneName', 'currentPlaceId', 'currentPlaceName', 'resolvedEntityId', 'knowledgeIds', 'retrievalScores', 'navigationIntent', 'responseStrategy'] as $key) {
    t("debug trace includes $key", in_array($key, $debugKeys, true));
}

fwrite(STDOUT, "\n$passed/$tests passed\n");
if ($failures) {
    fwrite(STDOUT, implode("\n", array_map(fn($f) => "  FAIL: $f", $failures)) . "\n");
    exit(1);
}
fwrite(STDOUT, "LAWANG SEWU V2 INTELLIGENCE: PASS\n");
exit(0);
