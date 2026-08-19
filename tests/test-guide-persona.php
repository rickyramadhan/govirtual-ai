<?php

/**
 * test-guide-persona.php
 * Persona quality tests for the Lawang Sewu virtual guide.
 *
 * Run: php tests/test-guide-persona.php
 *
 * Tests:
 *  - descriptive answers are natural (not one-line graph dumps)
 *  - unknown fact answers are courteous
 *  - technical names are absent
 *  - visitor-facing identity present
 *  - unrelated questions politely redirected
 *  - responses do not expose source details
 *  - no forced slogan every response
 *  - conversation continuity works
 *  - navigation still deterministic
 *  - entity questions stay informational
 *  - exhibition tone is professional
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/ai-core.php';

function t(string $name, bool $ok, string $detail = ''): void
{
    global $tests, $passed, $failures;
    $tests++;
    if ($ok) { $passed++; return; }
    $failures[] = $name . ($detail !== '' ? " — $detail" : '');
    fwrite(STDOUT, "FAIL: $name" . ($detail !== '' ? " ($detail)" : '') . "\n");
}

$tests = 0; $passed = 0; $failures = [];

/* ================================================================
 * Helper: simulate a minimal tour context for testing
 * ================================================================ */
function makeTourContext(array $scenes, array $hotspots = [], array $places = [], array $routes = []): array
{
    return [
        'id' => 'lawang-sewu',
        'name' => 'Lawang Sewu',
        'scenes' => $scenes,
        'hotspots' => $hotspots,
        'places' => $places,
        'routes' => $routes,
        'ui' => [],
    ];
}

function makeScene(string $id, string $rawName, string $displayName, string $placeId, string $placeName, string $placeDisplayName = ''): array
{
    return [
        'id' => $id,
        'rawName' => $rawName,
        'displayName' => $displayName,
        'placeId' => $placeId,
        'placeName' => $placeName,
        'placeDisplayName' => $placeDisplayName,
        'name' => $displayName,
        'aliases' => [],
        'description' => '',
        'facts' => [],
        'highlights' => [],
        'type' => 'test',
        'variant' => null,
        'confidence' => 'EXACT',
        'evidence' => 'test',
        'sceneIds' => [],
    ];
}

function makePlace(string $placeId, string $name, array $sceneIds = [], array $variantGroup = []): array
{
    return [
        'placeId' => $placeId,
        'name' => $name,
        'sceneIds' => $sceneIds,
        'variantGroup' => $variantGroup,
        'placeDisplayName' => $name,
        'description' => '',
    ];
}

/* ================================================================
 * Test 1: Descriptive answers are natural (not one-line graph dumps)
 * ================================================================ */
function testDescriptiveAnswersNatural(): void
{
    global $tests, $passed, $failures;
    $scene = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $place = makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']);
    $tour = makeTourContext([$scene], [], [$place]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    $placeCtx = ai_place_context('node51', $tour);
    $intent = 'PLACE_DESCRIPTION';
    $subject = null;
    $retrieval = ['items' => [], 'mode' => 'place-aware'];
    $navigation = null;
    $result = ai_v2_pipeline('Ceritakan area ini', $session, $tour, []);
    $reply = $result['result']['reply'] ?? '';
    $isNatural = mb_strlen($reply) > 50 && !str_contains($reply, 'node') && !str_contains($reply, 'Main Siang');
    t('Descriptive answers natural', $isNatural, "Reply length: " . mb_strlen($reply) . ". Content: " . $reply);
}

/* ================================================================
 * Test 2: Unknown fact answers are courteous
 * ================================================================ */
function testUnknownFactCourteous(): void
{
    $scene = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $place = makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']);
    $tour = makeTourContext([$scene], [], [$place]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    $result = ai_v2_pipeline('Berapa harga tiket hari ini?', $session, $tour, []);
    $reply = $result['result']['reply'] ?? '';
    $isCourteous = !str_contains($reply, 'tidak ada informasi') && !str_contains($reply, 'tidak tersedia') && !str_contains($reply, 'tidak memiliki informasi') && mb_strlen($reply) > 20;
    t('Unknown fact courteous', $isCourteous, "Reply: " . $reply);
}

/* ================================================================
 * Test 3: Technical names are absent
 * ================================================================ */
function testTechnicalNamesAbsent(): void
{
    $scene = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $place = makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']);
    $tour = makeTourContext([$scene], [], [$place]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    $result = ai_v2_pipeline('Di mana saya?', $session, $tour, []);
    $reply = $result['result']['reply'] ?? '';
    $hasTechnical = str_contains($reply, 'node') || str_contains($reply, 'Main Siang') || str_contains($reply, 'Hall Siang') || str_contains($reply, 'Default - Day');
    t('Technical names absent', !$hasTechnical, "Reply: " . $reply);
}

/* ================================================================
 * Test 4: Visitor-facing identity present
 * ================================================================ */
function testVisitorFacingIdentityPresent(): void
{
    $scene = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $place = makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']);
    $tour = makeTourContext([$scene], [], [$place]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    $result = ai_v2_pipeline('Di mana saya?', $session, $tour, []);
    $reply = $result['result']['reply'] ?? '';
    $hasVisitorIdentity = str_contains($reply, 'Area Utama Lawang Sewu') || str_contains($reply, 'Area Utama');
    t('Visitor-facing identity present', $hasVisitorIdentity, "Reply: " . $reply);
}

/* ================================================================
 * Test 5: Unrelated questions politely redirected
 * ================================================================ */
function testUnrelatedQuestionsRedirected(): void
{
    $scene = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $place = makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']);
    $tour = makeTourContext([$scene], [], [$place]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    $result = ai_v2_pipeline('Tempat wisata apa lagi di Semarang?', $session, $tour, []);
    $reply = $result['result']['reply'] ?? '';
    $isRedirected = mb_strlen($reply) > 30 && str_contains($reply, 'Lawang Sewu');
    t('Unrelated questions redirected', $isRedirected, "Reply: " . $reply);
}

/* ================================================================
 * Test 6: Responses do not expose source details
 * ================================================================ */
function testNoSourceDisclosure(): void
{
    $scene = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $place = makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']);
    $tour = makeTourContext([$scene], [], [$place]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    $result = ai_v2_pipeline('Ceritakan area ini', $session, $tour, []);
    $reply = $result['result']['reply'] ?? '';
    $hasSourceDisclosure = str_contains($reply, 'knowledge.json') || str_contains($reply, 'database') || str_contains($reply, 'Webview') || str_contains($reply, 'retrieval') || str_contains($reply, 'embedding') || str_contains($reply, 'source') || str_contains($reply, 'objinfo');
    t('No source disclosure', !$hasSourceDisclosure, "Reply: " . $reply);
}

/* ================================================================
 * Test 7: No forced slogan every response
 * ================================================================ */
function testNoForcedSlogan(): void
{
    $scene = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $place = makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']);
    $tour = makeTourContext([$scene], [], [$place]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    $result1 = ai_v2_pipeline('Di mana saya?', $session, $tour, []);
    $result2 = ai_v2_pipeline('Ceritakan area ini', $session, $tour, []);
    $result3 = ai_v2_pipeline('Apa yang menarik di sini?', $session, $tour, []);
    $replies = array_filter([$result1['result']['reply'] ?? '', $result2['result']['reply'] ?? '', $result3['result']['reply'] ?? '']);
    $allSame = count(array_unique($replies)) === 1;
    t('No forced slogan every response', !$allSame, "All replies identical: " . implode(' | ', $replies));
}

/* ================================================================
 * Test 8: Conversation continuity works
 * ================================================================ */
function testConversationContinuity(): void
{
    $scene = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $place = makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']);
    $tour = makeTourContext([$scene], [], [$place]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    // First ask about tugu marmer
    $result1 = ai_v2_pipeline('Ceritakan tugu marmer', $session, $tour, []);
    $entity1 = $result1['result']['entities'][0]['name'] ?? null;
    // Then ask "Kenapa itu menarik?"
    $session['lastEntity'] = $entity1;
    $session['lastIntent'] = 'ENTITY_DESCRIPTION';
    $result2 = ai_v2_pipeline('Kenapa itu menarik?', $session, $tour, []);
    $reply2 = $result2['result']['reply'] ?? '';
    $mentionsEntity = $entity1 && (str_contains($reply2, $entity1) || $result2['result']['intent'] === 'FACTUAL_QUESTION' || mb_strlen($reply2) > 30);
    t('Conversation continuity', $mentionsEntity, "Entity: " . ($entity1 ?? 'null') . ". Reply: " . $reply2);
}

/* ================================================================
 * Test 9: Navigation still deterministic
 * ================================================================ */
function testNavigationDeterministic(): void
{
    $scene51 = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $scene57 = makeScene('node57', 'Pintu Masuk', 'Pintu Masuk', 'pintu-masuk', 'Pintu Masuk');
    $tour = makeTourContext([$scene51, $scene57], [], [
        makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']),
        makePlace('pintu-masuk', 'Pintu Masuk', ['node57']),
    ]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    $result = ai_v2_pipeline('Bawa saya ke Pintu Masuk', $session, $tour, []);
    $hasNavigation = $result['result']['action'] === 'go_to_scene' && $result['result']['scene'] === 'node57';
    t('Navigation deterministic', $hasNavigation, "Action: " . ($result['result']['action'] ?? 'null') . ", Scene: " . ($result['result']['scene'] ?? 'null'));
}

/* ================================================================
 * Test 10: Entity questions stay informational
 * =============================================================== */
function testEntityQuestionsInformational(): void
{
    $scene = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $place = makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']);
    $tour = makeTourContext([$scene], [], [$place]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    $result = ai_v2_pipeline('Ceritakan Gedung A', $session, $tour, []);
    $reply = $result['result']['reply'] ?? '';
    $isInfo = mb_strlen($reply) > 30 && !str_contains($reply, 'node') && !str_contains($reply, 'Main Siang');
    t('Entity questions informational', $isInfo, "Reply: " . $reply);
}

/* ================================================================
 * Test 11: Exhibition tone is professional
 * ================================================================ */
function testExhibitionToneProfessional(): void
{
    $scene = makeScene('node51', 'Main Siang', 'Main Siang', 'main-area', 'Area Utama Lawang Sewu');
    $place = makePlace('main-area', 'Area Utama Lawang Sewu', ['node51']);
    $tour = makeTourContext([$scene], [], [$place]);
    $session = ['lastSceneId' => 'node51', 'visitorProfile' => [], 'history' => [], 'lastIntent' => null, 'lastEntity' => null];
    $result = ai_v2_pipeline('Kenapa Lawang Sewu menarik untuk dikunjungi?', $session, $tour, []);
    $reply = $result['result']['reply'] ?? '';
    $isProfessional = mb_strlen($reply) > 50 && str_contains($reply, 'Lawang Sewu') && !str_contains($reply, 'node');
    t('Exhibition tone professional', $isProfessional, "Reply: " . $reply);
}

/* ================================================================
 * Run all tests
 * ================================================================ */

echo "LAWANG SEWU GUIDE PERSONA TEST SUITE\n";
echo "====================================\n\n";

testDescriptiveAnswersNatural();
testUnknownFactCourteous();
testTechnicalNamesAbsent();
testVisitorFacingIdentityPresent();
testUnrelatedQuestionsRedirected();
testNoSourceDisclosure();
testNoForcedSlogan();
testConversationContinuity();
testNavigationDeterministic();
testEntityQuestionsInformational();
testExhibitionToneProfessional();

/* ================================================================
 * Summary
 * ================================================================ */
echo "\nSUMMARY: $passed/$tests passed\n";
if (!empty($failures)) {
    echo "\nFAILURES:\n";
    foreach ($failures as $f) {
        echo "- $f\n";
    }
    exit(1);
}

echo "\nLAWANG SEWU GUIDE PERSONA: PASS\n";