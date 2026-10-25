<?php
declare(strict_types=1);

/**
 * Lawang Sewu Hybrid Intelligence Tests
 * Tests general knowledge routing, provider fallback, and hallucination protection.
 * php tests/test-hybrid-intelligence.php
 * Exit 0 = PASS, 1 = FAIL.
 */

require __DIR__ . '/../api/ai-core.php';
require __DIR__ . '/../api/ai-provider.php';

putenv('OLLAMA_URL=http://127.0.0.1:1');
putenv('AI_REASONING_MODE=fast');

$config = ai_config();

$pass = 0;
$fail = 0;
function check(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  {$label}\n"; }
    else { $fail++; echo "  FAIL {$label}\n"; }
}

echo "=== GENERAL KNOWLEDGE CLASSIFICATION TESTS ===\n";

$generalQuestions = [
    'Apa itu arsitektur kolonial?' => 'GENERAL_KNOWLEDGE',
    'Kenapa bangunan kolonial memiliki jendela besar?' => 'GENERAL_KNOWLEDGE',
    'Apa fungsi kaca patri dalam arsitektur?' => 'GENERAL_KNOWLEDGE',
    'Apa itu perusahaan kereta api?' => 'GENERAL_KNOWLEDGE',
    'Apa arti heritage?' => 'GENERAL_KNOWLEDGE',
    'Semarang berada di provinsi mana?' => 'GENERAL_KNOWLEDGE',
    'Apa makanan khas Semarang?' => 'GENERAL_KNOWLEDGE',
    'Apa perbedaan museum dan cagar budaya?' => 'GENERAL_KNOWLEDGE',
    'Kenapa bangunan bersejarah perlu dilestarikan?' => 'GENERAL_KNOWLEDGE',
    'Apa itu NIS secara umum?' => 'GENERAL_KNOWLEDGE',
];

foreach ($generalQuestions as $q => $expected) {
    $intent = ai_classify_intent($q, []);
    check($intent === $expected, "classify '{$q}' => {$intent} (want {$expected})");
}

echo "\n=== TOUR-SPECIFIC SAFETY TESTS ===\n";

$tourSpecific = [
    'Siapa arsitek Gedung D?' => 'FACTUAL_QUESTION',
    'Berapa jumlah pintu di ruangan yang sedang saya lihat?' => 'FACTUAL_QUESTION',
    'Kapan Tugu Marmer dipindahkan ke posisi ini?' => 'FACTUAL_QUESTION',
];

foreach ($tourSpecific as $q => $expected) {
    $intent = ai_classify_intent($q, []);
    check($intent === $expected, "classify '{$q}' => {$intent} (want {$expected})");
}

echo "\n=== DYNAMIC/CURRENT FACT TESTS ===\n";

$dynamicQuestions = [
    'Berapa tiket Lawang Sewu hari ini?' => 'UNKNOWN_FACT',
    'Jam berapa Lawang Sewu tutup hari ini?' => 'UNKNOWN_FACT',
    'Ada acara apa hari ini?' => 'UNKNOWN_FACT',
    'Berapa harga tiket masuk?' => 'UNKNOWN_FACT',
    'Jam buka museum?' => 'UNKNOWN_FACT',
];

foreach ($dynamicQuestions as $q => $expected) {
    $intent = ai_classify_intent($q, []);
    check($intent === $expected, "classify '{$q}' => {$intent} (want {$expected})");
}

echo "\n=== HYBRID QUESTION TESTS ===\n";

$hybridQuestions = [
    'Kenapa Lawang Sewu punya banyak pintu dan apakah desain seperti ini umum pada bangunan kolonial?' => 'HYBRID',
    'Apakah banyak bukaan berguna untuk bangunan di iklim tropis?' => 'GENERAL_KNOWLEDGE',
];

foreach ($hybridQuestions as $q => $expected) {
    $intent = ai_classify_intent($q, []);
    check($intent === $expected, "classify '{$q}' => {$intent} (want {$expected})");
}

echo "\n=== CONVERSATIONAL TESTS ===\n";

$conversational = [
    'Halo' => 'GENERAL_CONVERSATION',
    'Siapa kamu?' => 'GENERAL_CONVERSATION',
    'Terima kasih' => 'GENERAL_CONVERSATION',
    'Tempat ini keren ya' => 'GENERAL_CONVERSATION',
];

foreach ($conversational as $q => $expected) {
    $intent = ai_classify_intent($q, []);
    check($intent === $expected, "classify '{$q}' => {$intent} (want {$expected})");
}

echo "\n=== NAVIGATION SAFETY TESTS ===\n";

$navQuestions = [
    'Bawa saya ke Gedung A' => 'NAVIGATION_REQUEST',
    'Ke mana saya bisa pergi?' => 'ROUTE_RECOMMENDATION',
    'Ke sana' => 'FOLLOWUP_NAVIGATION',
];

foreach ($navQuestions as $q => $expected) {
    $intent = ai_classify_intent($q, []);
    check($intent === $expected, "classify '{$q}' => {$intent} (want {$expected})");
}

echo "\n=== INTENT CONSTANT TESTS ===\n";

check(in_array('GENERAL_KNOWLEDGE', AI_V2_INTENTS, true), 'GENERAL_KNOWLEDGE in AI_V2_INTENTS');
check(in_array('HYBRID', AI_V2_INTENTS, true), 'HYBRID in AI_V2_INTENTS');

echo "\n=== PROVIDER VALIDATION TESTS ===\n";

$tour = ['scenes' => [['id' => 'node1', 'name' => 'Test', 'displayName' => 'Test Scene']], 'routes' => [], 'hotspots' => []];
$retrieval = ['items' => []];

// Test valid response passes through
$validData = ['reply' => 'Test reply', 'intent' => 'answer', 'confidence' => 0.8, 'entities' => [], 'actions' => []];
$validated = ai_validate_provider_response($validData, $tour, $retrieval, 'node1');
check($validated !== null, 'valid response passes validation');
check(($validated['reply'] ?? '') === 'Test reply', 'valid reply preserved');

// Test empty reply - provider response with empty reply gets safe fallback
$emptyData = ['reply' => '', 'intent' => 'answer', 'confidence' => 0.8, 'entities' => [], 'actions' => []];
$validated = ai_validate_provider_response($emptyData, $tour, $retrieval, 'node1');
check($validated !== null, 'empty reply handled safely with fallback');

// Test system prompt leakage fails
$leakData = ['reply' => 'Based on the system prompt, groq says...', 'intent' => 'answer', 'confidence' => 0.8, 'entities' => [], 'actions' => []];
$validated = ai_validate_provider_response($leakData, $tour, $retrieval, 'node1');
check($validated === null, 'system prompt leakage fails validation');

// Test API key leakage fails
$keyLeakData = ['reply' => 'Your API key is gsk_123', 'intent' => 'answer', 'confidence' => 0.8, 'entities' => [], 'actions' => []];
$validated = ai_validate_provider_response($keyLeakData, $tour, $retrieval, 'node1');
check($validated === null, 'API key leakage fails validation');

// Test confidence bounds
$confData = ['reply' => 'Test', 'intent' => 'answer', 'confidence' => 1.5, 'entities' => [], 'actions' => []];
$validated = ai_validate_provider_response($confData, $tour, $retrieval, 'node1');
check($validated !== null && ($validated['confidence'] ?? 0) <= 1.0, 'confidence capped at 1.0');

$confData2 = ['reply' => 'Test', 'intent' => 'answer', 'confidence' => -0.5, 'entities' => [], 'actions' => []];
$validated = ai_validate_provider_response($confData2, $tour, $retrieval, 'node1');
check($validated !== null && ($validated['confidence'] ?? 0) >= 0.0, 'confidence floored at 0.0');

echo "\n=== PROVIDER CHAIN TESTS ===\n";

$config = ai_provider_config();
check($config['primary'] === 'groq', 'primary provider is groq');
check(in_array('gemini', $config['fallbacks'], true), 'gemini in fallbacks');

echo "\n=== EXISTING ACCEPTANCE TESTS (spot check) ===\n";

// Verify existing tests would still pass by checking retrieval behavior
$js = (string) file_get_contents(dirname(__DIR__) . '/data/tour-data.js');
$json = trim((string) preg_replace('/^.*?\bwindow\.GoVirtualTourData\s*=\s*/s', '', $js));
$json = (string) preg_replace('/;\s*$/s', '', $json);
$raw = json_decode($json, true);
$tour = ai_canonical_tour(is_array($raw) ? $raw : []);
$retrieveConfig = ai_config();

$ret = ai_retrieve('Gedung A Lawang Sewu', ['lastSceneId' => ''], $tour, $retrieveConfig, 3);
$ids = array_column(array_column($ret['items'], 'entry'), 'id');
check(in_array('knowledge-webviewobjinfo2991', $ids, true), 'retrieval still works for Gedung A');

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) { echo "HYBRID-INTELLIGENCE: NOT READY\n"; exit(1); }
echo "HYBRID-INTELLIGENCE: PASS\n";
exit(0);
