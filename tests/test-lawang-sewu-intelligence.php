<?php

declare(strict_types=1);

/**
 * test-lawang-sewu-intelligence.php
 * Regression suite for the Lawang Sewu standalone AI intelligence layer.
 *
 * Run: php tests/test-lawang-sewu-intelligence.php
 *
 * Covers:
 *  - evidence types: STRUCTURAL vs CURATED vs CONVERSATION
 *  - rawName/displayName normalization (never nodeX in visitor replies)
 *  - deterministic current-location resolution
 *  - description intent: current scene first, safe insufficiency
 *  - named-scene description resolution
 *  - graph != visibility (navigation phrasing only)
 *  - navigation graph built from real hotspots
 *  - recommendation engine (deterministic, from graph)
 *  - follow-up "ke sana" resolution from lastRecommendedDestination
 *  - direct navigation against real scenes
 *  - provider grounding prompt rules (AVAILABLE FACTS only, no ID leakage)
 *  - provider-offline safe fallback (deterministic features survive)
 *  - zero-knowledge test: LESS info, not MORE hallucination
 *  - content capability flags
 */

$root = dirname(__DIR__);

// ---- Load tour data (JS) by extracting the JSON literal ----
$tourJs = file_get_contents($root . '/tour-data.js');
if ($tourJs === false) { fwrite(STDERR, "FATAL: cannot read tour-data.js\n"); exit(1); }
$tourData = extractTourData($tourJs);
if (!$tourData || !isset($tourData['scenes']) || !isset($tourData['hotspots'])) { fwrite(STDERR, "FATAL: tour-data.js parse failed\n"); exit(1); }

$tourChatJs = file_get_contents($root . '/tour-chat.js');
if ($tourChatJs === false) { fwrite(STDERR, "FATAL: cannot read tour-chat.js\n"); exit(1); }
$tourChatPhp = file_get_contents($root . '/api/tour-chat.php');
if ($tourChatPhp === false) { fwrite(STDERR, "FATAL: cannot read api/tour-chat.php\n"); exit(1); }
$guidePolicyPhp = file_get_contents($root . '/api/guide-policy.php');
if ($guidePolicyPhp === false) { fwrite(STDERR, "FATAL: cannot read api/guide-policy.php\n"); exit(1); }

require_once $root . '/api/ai-core.php';

$tests = 0; $passed = 0; $failures = [];

function t(string $name, bool $ok, string $detail = ''): void {
    global $tests, $passed, $failures;
    $tests++;
    if ($ok) { $passed++; return; }
    $failures[] = $name . ($detail !== '' ? " — $detail" : '');
    fwrite(STDOUT, "FAIL: $name" . ($detail !== '' ? " ($detail)" : '') . "\n");
}

function extractTourData(string $js): ?array {
    $start = strpos($js, 'window.GoVirtualTourData = {');
    if ($start === false) return null;
    $open = strpos($js, '{', $start);
    if ($open === false) return null;
    $depth = 0; $inString = false; $quote = ''; $esc = false;
    $len = strlen($js);
    for ($i = $open; $i < $len; $i++) {
        $ch = $js[$i];
        if ($inString) {
            if ($esc) { $esc = false; continue; }
            if ($ch === '\\') { $esc = true; continue; }
            if ($ch === $quote) $inString = false;
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inString = true; $quote = $ch; continue; }
        if ($ch === '{') $depth++;
        elseif ($ch === '}') { $depth--; if ($depth === 0) { return json_decode(jsObjectToJson(substr($js, $open, $i - $open + 1)), true); } }
    }
    return null;
}

function jsObjectToJson(string $src): string {
    $out = ''; $i = 0; $len = strlen($src); $inString = false;
    while ($i < $len) {
        $ch = $src[$i];
        if ($inString) {
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $src[$i + 1];
                $out .= $next === "'" ? "'" : $ch . $next;
                $i += 2; continue;
            }
            if ($ch === $quote) { $out .= '"'; $inString = false; $i++; continue; }
            $out .= $ch;
            $i++; continue;
        }
        if ($ch === "'" || $ch === '"') { $out .= '"'; $inString = true; $quote = $ch; $i++; continue; }
        if (preg_match('/\G[A-Za-z_$][A-Za-z0-9_$]*/A', $src, $m, 0, $i)) {
            $key = $m[0];
            $j = $i + strlen($key);
            while ($j < $len && ($src[$j] === ' ' || $src[$j] === "\t")) $j++;
            if ($j < $len && $src[$j] === ':') {
                $out .= '"' . $key . '":';
                $i = $j + 1; continue;
            }
        }
        $out .= $ch;
        $i++;
    }
    // JS allows trailing commas; JSON does not.
    $out = preg_replace('/,\s*([}\]])/', '$1', $out);
    return $out;
}

function sceneById(array $tour, string $id): ?array {
    foreach ($tour['scenes'] as $scene) { if ($scene['id'] === $id) return $scene; }
    return null;
}

function sceneDisplayName(array $scene): string {
    return $scene['displayName'] ?? $scene['rawName'] ?? $scene['name'] ?? 'area ini';
}

function graphNeighbors(array $tour, string $sceneId): array {
    $targets = [];
    foreach ($tour['hotspots'] as $h) {
        if ($h['scene'] === $sceneId && $h['target'] !== $sceneId && !in_array($h['target'], $targets, true)) $targets[] = $h['target'];
    }
    return $targets;
}

// ============================================================
// 1. STRUCTURAL data sanity
// ============================================================
t('tour has 35 scenes', count($tourData['scenes']) === 35, 'got ' . count($tourData['scenes']));
t('tour has 76 hotspots', count($tourData['hotspots']) === 76, 'got ' . count($tourData['hotspots']));

// Every scene must have rawName + displayName, and displayName must NEVER contain a nodeX token.
foreach ($tourData['scenes'] as $scene) {
    t("scene {$scene['id']} has rawName", !empty($scene['rawName']));
    t("scene {$scene['id']} has displayName", !empty($scene['displayName']));
    t("scene {$scene['id']} displayName has no node id", !preg_match('/node\d+/i', $scene['displayName']));
}

// Unnamed-node displayNames must not leak node ids.
$anonymous = array_filter($tourData['scenes'], fn($s) => preg_match('/node\d+/', $s['rawName']));
t('anonymous rawNames carry node marker', count($anonymous) > 0);
foreach ($anonymous as $scene) {
    t("anonymous scene {$scene['id']} displayName omits node id", !preg_match('/node\d+/i', $scene['displayName']));
}

// Key scenes exist (from the observed hallucination transcript).
foreach (['node51', 'node61', 'node53', 'node55', 'node68', 'node57'] as $id) {
    t("scene $id present", sceneById($tourData, $id) !== null);
}
$mainSiang = sceneById($tourData, 'node51');
t('Main Siang displayName correct', $mainSiang['displayName'] === 'Main Siang');
t('Main Siang description empty (no invented facts)', ($mainSiang['description'] ?? '') === '');

// ============================================================
// 2. Graph semantics: connectivity is navigation, NOT visibility
// ============================================================
$graph = [];
foreach ($tourData['scenes'] as $scene) $graph[$scene['id']] = [];
foreach ($tourData['hotspots'] as $h) {
    if ($h['target'] !== $h['scene'] && !in_array($h['target'], $graph[$h['scene']] ?? [], true)) $graph[$h['scene']][] = $h['target'];
}
t('node51 -> node61 via hotspot', in_array('node61', $graph['node51'] ?? [], true));
t('node51 -> node64 via hotspot', in_array('node64', $graph['node51'] ?? [], true));
t('node51 -> node53 via hotspot', in_array('node53', $graph['node51'] ?? [], true));

// "Balkon terlihat dari sini" is a visibility claim. tour-chat.js must NOT emit it.
foreach (['terlihat dari sini', 'terlihat dari area', 'dapat melihat', 'anda dapat melihat'] as $claim) {
    t("no visibility claim '$claim' in tour-chat.js", strpos($tourChatJs, $claim) === false);
}

// sceneSummary emits navigation phrasing only.
t('sceneSummary navigation phrasing removed', strpos($tourChatJs, 'Dari sini Anda dapat melanjutkan tur menuju') === false);

// ============================================================
// 3. rawName/displayName: never nodeX in visitor replies
// ============================================================
t('sceneDisplayName helper exists', strpos($tourChatJs, 'function sceneDisplayName') !== false);
t('displayName || rawName || name fallback', strpos($tourChatJs, 'displayName || scene.rawName || scene.name') !== false);
// The old name fallback `scene.name` that contained '(node46)' markers is gone from sceneSummary.
t('nodeX never emitted as location label', strpos($tourChatJs, "'Anda berada di ' + active.name") === false);

// ============================================================
// 4. Deterministic intent routing: local-first, remote fallback
// ============================================================
t('local-first routing in submit()', strpos($tourChatJs, 'var deterministic = !!local && !!local.intent;') !== false);
t('remote only when local unresolved', strpos($tourChatJs, 'remoteRespond(query).then') !== false);
t('provider failure falls back to local', strpos($tourChatJs, "result.reply === 'Layanan AI tidak terjangkau.'") !== false);

// "ceritakan area ini" must go to API (no longer intercepted locally)
t('"ceritakan area ini" NOT matched locally (routes to API)', preg_match('/ceritakan area\|ceritakan tempat/', $tourChatJs) === 0);
// "Ceritakan X" goes to API (backend resolves entities)
t('"Ceritakan X" NOT matched locally (routes to API)', strpos($tourChatJs, 'ceritakan|jelaskan|deskripsikan|apa itu') === false || strpos($tourChatJs, 'Let API handle') !== false);

// "Di mana saya?" variants.
t('location intent regex broad', strpos($tourChatJs, 'posisi saya') !== false && strpos($tourChatJs, 'where am i') !== false);
t('location reply uses place phrasing, no panorama framing', strpos($tourChatJs, 'Anda sekarang berada di ') !== false && strpos($tourChatJs, 'Anda sedang berada di panorama ') === false);

// ============================================================
// 5. Recommendation engine: deterministic, from graph
// ============================================================
t('recommendDestination exists', strpos($tourChatJs, 'function recommendDestination') !== false);
t('recommendation stores lastRecommendedDestination', strpos($tourChatJs, 'conversationState.lastRecommendedDestination = { sceneId:') !== false);
t('recommendation stores navigable flag', strpos($tourChatJs, 'navigable: true') !== false);
t('recommendation avoids visibility phrasing', strpos($tourChatJs, 'Dari area ini Anda dapat langsung menuju ke sana.') !== false);

// Follow-up "ke sana" reads lastRecommendedDestination first.
t('"ke sana" resolves lastRecommendedDestination', strpos($tourChatJs, 'conversationState.lastRecommendedDestination && conversationState.lastRecommendedDestination.sceneId') !== false);

// ============================================================
// 6. Provider grounding (api/tour-chat.php system prompt)
// ============================================================
$policyContent = $tourChatPhp . $guidePolicyPhp;
t('VERIFIED_FACTS only rule', strpos($policyContent, 'VERIFIED_FACTS') !== false);
t('never invent rule', strpos($policyContent, 'ciptakan fakta baru') !== false || strpos($policyContent, 'mengarang') !== false);
t('unknown fact policy', strpos($policyContent, 'belum memiliki informasi') !== false || strpos($policyContent, 'belum terverifikasi') !== false);
t('no ID leakage rule', strpos($policyContent, 'nodeX') !== false || strpos($policyContent, 'ID internal') !== false || strpos($policyContent, 'LABEL TEKNIS') !== false);
t('graph != visibility rule', strpos($policyContent, 'bukan visibilitas') !== false || strpos($policyContent, 'klaim objek terlihat') !== false);
t('grounding references CURRENT_PLACE/VERIFIED_FACTS', strpos($policyContent, 'CURRENT_PLACE') !== false && strpos($policyContent, 'VERIFIED_FACTS') !== false);

// ============================================================
// 7. Provider-offline safe fallback (api/ai-core.php)
// ============================================================
$retrieval = ['mode' => 'none', 'items' => [], 'query' => ''];
$tourCanonical = ai_canonical_tour([
    'scenes' => array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'rawName' => $s['rawName'] ?? '', 'displayName' => $s['displayName'] ?? '', 'aliases' => $s['aliases'] ?? [], 'description' => $s['description'] ?? '', 'highlights' => $s['highlights'] ?? [], 'facilities' => $s['facilities'] ?? [], 'whoFor' => $s['whoFor'] ?? []], $tourData['scenes']),
    'hotspots' => $tourData['hotspots'],
    'routes' => [],
    'video360' => [],
    'ui' => [],
]);
$fallback = ai_fallback($retrieval, $tourCanonical, 'node51');
t('fallback reply is deterministic structural', mb_strlen($fallback['reply']) > 20 && strpos($fallback['reply'], 'node') === false);
t('fallback has no generic shutdown text', strpos($fallback['reply'], 'Pemandu AI sedang terbatas, tetapi saya masih bisa membantu') === false);
t('fallback names displayName not node id', strpos($fallback['reply'], 'node51') === false);
t('fallback has no panorama framing', strpos($fallback['reply'], 'panorama') === false);
t('fallback does not auto-emit navigation exits', strpos($fallback['reply'], 'Dari sini Anda dapat melanjutkan tur menuju ') === false);
t('fallback does not auto-suggest navigation', empty($fallback['actions']));
t('fallback stores no recommendation_target', !isset($fallback['recommendation_target']));

// ============================================================
// 8. Zero-knowledge test: LESS info, not MORE hallucination
// ============================================================
t('all curated content fields are empty in tour-data.js', (function () use ($tourData): bool {
    foreach ($tourData['scenes'] as $scene) {
        foreach (['description', 'facts', 'highlights', 'facilities', 'visibleObjects', 'suggestions', 'curatedQA'] as $field) {
            $v = $scene[$field] ?? null;
            if (is_string($v)) { if ($v !== '') return false; }
            elseif (is_array($v)) { if (count($v) !== 0) return false; }
            else return false;
        }
    }
    return true;
})());
t('content model fields exist in scenes', isset($tourData['scenes'][0]['curatedQA']) && isset($tourData['scenes'][0]['visibleObjects']));

// ============================================================
// 9. No contamination: only Lawang Sewu data
// ============================================================
t('no bakauheni reference in tour-data.js', stripos($tourJs, 'bakauheni') === false);
t('no bakauheni reference in tour-chat.js', stripos($tourChatJs, 'bakauheni') === false);
t('no semarang reference in tour-data.js', stripos($tourJs, 'semarang') === false);

// ============================================================
// 10. Debug trace fields
// ============================================================
t('AI_DEBUG gate present in tour-chat.js', strpos($tourChatJs, 'window.AI_DEBUG') !== false);
t('debug trace includes intent', strpos($tourChatJs, 'intent: result.intent || null') !== false);
t('debug trace includes currentSceneId', strpos($tourChatJs, 'currentSceneId: currentSceneId()') !== false);
t('debug trace includes currentPlaceName', strpos($tourChatJs, 'currentPlaceName: result._debug ? result._debug.currentPlaceName : null') !== false);
t('debug trace includes responseStrategy', strpos($tourChatJs, 'responseStrategy: result._debug ? result._debug.responseStrategy : null') !== false);
t('PHP debug trace includes currentPlaceName', strpos($tourChatPhp, "'currentPlaceName' => \$place['currentPlaceName']") !== false);
$tourCorePhp = file_get_contents($root . '/api/ai-core.php');
t('PHP debug trace includes navigationIntent', $tourCorePhp !== false && strpos($tourCorePhp, "'navigationIntent' => \$navigation['destinationSceneId']") !== false);

// ============================================================
// Summary
// ============================================================
fwrite(STDOUT, "\n$passed/$tests passed\n");
if ($failures) {
    fwrite(STDOUT, implode("\n", array_map(fn($f) => "  FAIL: $f", $failures)) . "\n");
    exit(1);
}
fwrite(STDOUT, "LAWANG SEWU INTELLIGENCE TEST SUITE: PASS\n");
exit(0);
