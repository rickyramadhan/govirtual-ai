<?php
declare(strict_types=1);
/**
 * Stateful 10-turn conversation test.
 * Simulates sequential browser conversation with shared state.
 * Run: php tests/test-stateful-conversation.php
 */
require_once __DIR__ . '/../api/ai-core.php';
require_once __DIR__ . '/../api/ai-provider.php';
require_once __DIR__ . '/../api/guide-policy.php';

putenv('OLLAMA_URL=http://127.0.0.1:1');
putenv('AI_REASONING_MODE=fast');

$config = ai_config();

// Load tour data from tour-data.js
$js = (string) file_get_contents(__DIR__ . '/../tour-data.js');
$json = trim((string) preg_replace('/^.*?\bwindow\.GoVirtualTourData\s*=\s*/s', '', $js));
$json = (string) preg_replace('/;\s*$/s', '', $json);
$raw = json_decode($json, true);
$tour = ai_canonical_tour(is_array($raw) ? $raw : []);

// Shared session state (simulates browser conversationState)
$session = [
    'lastSceneId' => 'node51',
    'lastIntent' => null,
    'lastEntity' => null,
    'lastReferencedDestination' => null,
    'lastNavigableSceneId' => null,
    'lastRecommendedSceneId' => null,
    'lastRecommendedDestination' => null,
    'visitorProfile' => ['transport' => null, 'travelingWithChildren' => null, 'accessibilityNeeds' => [], 'servicePreference' => null, 'interests' => [], 'destinationPreference' => null],
];

$turns = [
    ['q' => 'Saya sekarang berada di area mana?', 'wantIntent' => 'CURRENT_LOCATION', 'wantAction' => 'none', 'desc' => 'Q1: current location'],
    ['q' => 'Ceritakan area ini.', 'wantIntent' => 'PLACE_DESCRIPTION', 'wantAction' => 'none', 'desc' => 'Q2: place description'],
    ['q' => 'Apa yang menarik dari Lawang Sewu?', 'wantIntent' => 'TOUR_DESCRIPTION', 'wantAction' => 'none', 'desc' => 'Q3: tour highlights'],
    ['q' => 'Kenapa tempat ini disebut Lawang Sewu?', 'wantIntent' => 'ENTITY_DESCRIPTION', 'wantAction' => 'none', 'desc' => 'Q4: name origin'],
    ['q' => 'Ceritakan tentang Gedung A.', 'wantIntent' => 'ENTITY_DESCRIPTION', 'wantAction' => 'none', 'desc' => 'Q5: entity description'],
    ['q' => 'Apa itu Tugu Marmer dan apa yang menarik darinya?', 'wantIntent' => 'ENTITY_DESCRIPTION', 'wantAction' => 'none', 'desc' => 'Q6: entity description'],
    ['q' => 'Setelah dari sini, area mana yang sebaiknya saya kunjungi?', 'wantIntent' => 'ROUTE_RECOMMENDATION', 'wantAction' => 'none', 'desc' => 'Q7: recommendation'],
    ['q' => 'Apa itu arsitektur kolonial?', 'wantIntent' => 'GENERAL_KNOWLEDGE', 'wantAction' => 'none', 'desc' => 'Q8: general knowledge AFTER recommendation'],
    ['q' => 'Oke, bawa saya ke sana.', 'wantIntent' => 'FOLLOWUP_NAVIGATION', 'wantAction' => 'none', 'desc' => 'Q9: follow-up navigation'],
    ['q' => 'Berapa harga tiket Lawang Sewu hari ini?', 'wantIntent' => 'UNKNOWN_FACT', 'wantAction' => 'none', 'desc' => 'Q10: dynamic fact'],
];

$pass = 0; $fail = 0;
foreach ($turns as $i => $turn) {
    $pipeline = ai_v2_pipeline($turn['q'], $session, $tour, $config);
    $result = $pipeline['result'];
    $intent = $result['intent'] ?? 'UNKNOWN';
    $action = $result['action'] ?? 'none';
    $reply = $result['reply'] ?? '';
    $entities = array_column($result['entities'] ?? [], 'name');
    $subject = $entities[0] ?? null;

    // Update session state (simulates browser updateConversationState)
    $session['lastIntent'] = $intent;
    if (!empty($result['conversation_update']['referenced_entity_id'])) {
        $session['lastEntity'] = $result['conversation_update']['referenced_entity_id'];
    }
    if (!empty($result['conversation_update']['referenced_scene_id'])) {
        $session['lastReferencedDestination'] = $result['conversation_update']['referenced_scene_id'];
        $session['lastNavigableSceneId'] = $result['conversation_update']['referenced_scene_id'];
        $session['lastRecommendedSceneId'] = $result['conversation_update']['referenced_scene_id'];
    }
    if (!empty($result['recommendation_target'])) {
        $session['lastRecommendedDestination'] = $result['recommendation_target'];
    }

    $intentOk = $intent === $turn['wantIntent'];
    $actionOk = $action === $turn['wantAction'];
    $ok = $intentOk && $actionOk;

    $num = $i + 1;
    if ($ok) {
        $pass++;
        echo "  T{$num} OK  [{$turn['desc']}] intent={$intent} action={$action}";
    } else {
        $fail++;
        echo "  T{$num} FAIL [{$turn['desc']}]";
        if (!$intentOk) echo " intent={$intent} (want={$turn['wantIntent']})";
        if (!$actionOk) echo " action={$action} (want={$turn['wantAction']})";
    }
    if ($subject) echo " subject={$subject}";
    echo "\n";
}

echo "\n{$pass}/" . count($turns) . " passed\n";
if ($fail > 0) { echo "STATEFUL CONVERSATION: NOT READY\n"; exit(1); }
echo "STATEFUL CONVERSATION: PASS\n";
exit(0);
