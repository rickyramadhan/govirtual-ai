<?php
/**
 * Demo test script for Lawang Sewu chatbot - 10 sample questions
 * Pipes JSON to api/tour-chat.php via CLI (no HTTP needed).
 * Run: C:\xampp\php\php.exe test-questions.php
 */

// Extract scene IDs from tour-data.js (JS format, not JSON)
$js = file_get_contents(__DIR__ . '/tour-data.js');
if ($js === false) { fwrite(STDERR, "ERROR: Could not load tour-data.js\n"); exit(1); }

preg_match_all("/\{ id: '([^']+)'/", $js, $m);
$sceneIds = $m[1] ?? [];
if (!$sceneIds) { fwrite(STDERR, "ERROR: No scenes found\n"); exit(1); }

$scenes = array_map(function ($id) {
    return ['id' => $id, 'name' => $id, 'description' => '', 'facts' => [], 'highlights' => [], 'facilities' => [], 'visibleObjects' => [], 'suggestions' => [], 'curatedQA' => []];
}, $sceneIds);

$tourData = ['name' => 'Lawang Sewu', 'scenes' => $scenes, 'hotspots' => [], 'routes' => [], 'video360' => [], 'ui' => []];

// 10 demo questions
$questions = [
    1 => ['text' => 'Saya sekarang berada di area mana?', 'expected_intent' => 'CURRENT_LOCATION'],
    2 => ['text' => 'Ceritakan area yang sedang saya lihat ini.', 'expected_intent' => 'PLACE_DESCRIPTION'],
    3 => ['text' => 'Apa yang menarik dari Lawang Sewu?', 'expected_intent' => 'TOUR_DESCRIPTION'],
    4 => ['text' => 'Kenapa tempat ini disebut Lawang Sewu?', 'expected_intent' => 'ENTITY_DESCRIPTION'],
    5 => ['text' => 'Ceritakan tentang Gedung A.', 'expected_intent' => 'ENTITY_DESCRIPTION'],
    6 => ['text' => 'Apa itu Tugu Marmer dan apa yang menarik darinya?', 'expected_intent' => 'ENTITY_DESCRIPTION'],
    7 => ['text' => 'Setelah dari sini, area mana yang sebaiknya saya kunjungi?', 'expected_intent' => 'ROUTE_RECOMMENDATION'],
    8 => ['text' => 'Oke, bawa saya ke sana.', 'expected_intent' => 'FOLLOWUP_NAVIGATION'],
    9 => ['text' => 'Bawa saya ke Gedung D.', 'expected_intent' => 'NAVIGATION_REQUEST'],
    10 => ['text' => 'Berapa harga tiket Lawang Sewu hari ini?', 'expected_intent' => 'UNKNOWN_FACT'],
    11 => ['text' => 'drone', 'expected_intent' => 'NAVIGATION_REQUEST']
];

$apiScript = __DIR__ . '/api/tour-chat.php';
$phpBin = PHP_BINARY;
$pass = 0; $fail = 0;

foreach ($questions as $id => $q) {
    $payload = json_encode([
        'message' => $q['text'],
        'currentScene' => 'node51',
        'history' => [],
        'conversationState' => [
            'lastIntent' => null, 'lastSceneId' => 'node51', 'lastEntity' => null,
            'lastReferencedDestination' => null, 'lastNavigableSceneId' => null,
            'lastRecommendedSceneId' => null, 'lastRecommendedDestination' => null,
            'visitorProfile' => ['transport' => null, 'travelingWithChildren' => null, 'accessibilityNeeds' => [], 'servicePreference' => null, 'interests' => [], 'destinationPreference' => null]
        ],
        'viewContext' => ['language' => 'id', 'visibleHotspots' => []],
        'tourContext' => $tourData
    ]);

    $proc = proc_open(
        [PHP_BINARY, $apiScript],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) { echo "Q{$id}: proc_open failed\n\n"; $fail++; continue; }
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $response = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exitCode = proc_close($proc);

    echo "Q{$id}: {$q['text']}\n";
    echo "  Expected: {$q['expected_intent']}\n";
    if ($exitCode === 0 && $response !== '') {
        $data = json_decode($response, true);
        $reply = $data['reply'] ?? 'No reply';
        $intent = $data['intent'] ?? 'UNKNOWN';
        $action = $data['action'] ?? 'none';
        $scene = $data['scene'] ?? null;
        echo "  Got:      {$intent}";
        if ($scene) echo " | scene={$scene}";
        echo " | action={$action}\n";
        echo "  Reply:    {$reply}\n";
        if ($intent === $q['expected_intent']) { echo "  ✓ PASS\n\n"; $pass++; }
        else { echo "  ✗ FAIL (expected {$q['expected_intent']}, got {$intent})\n\n"; $fail++; }
    } else {
        echo "  → exit {$exitCode}: " . trim($stderr) . "\n\n"; $fail++;
    }
}

echo "========================================\n";
echo "Results: {$pass} PASS / {$fail} FAIL / " . ($pass + $fail) . " total\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
