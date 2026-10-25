<?php
/**
 * endpoint.php — Single entry point for chat API.
 * Upload ini ke ROOT folder (sejajar index.html).
 * Akses: /lawangsewu-demoai/endpoint.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'CLI') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Accept both GET and POST
$rawInput = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'CLI') === 'POST') {
    $rawInput = file_get_contents('php://input') ?: '';
} elseif (isset($_GET['message'])) {
    $rawInput = json_encode(['message' => $_GET['message'], 'currentScene' => $_GET['currentScene'] ?? '', 'session' => json_decode($_GET['session'] ?? '{}', true) ?? []]);
}

if ($rawInput === '' && PHP_SAPI === 'cli') {
    $rawInput = file_get_contents('php://stdin') ?: '';
}

$input = json_decode($rawInput, true);
if (!is_array($input)) { http_response_code(400); echo json_encode(['error' => 'invalid_json']); exit; }

// Route to tour-chat.php logic
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_POST = [];

// Include the actual logic
require_once __DIR__ . '/ai-core.php';
require_once __DIR__ . '/ai-provider.php';
require_once __DIR__ . '/guide-policy.php';

// Load environment
if (getenv('GEMINI_API_KEY') === false || getenv('GEMINI_API_KEY') === '') {
    require_once __DIR__ . '/config.php';
}

$message = trim((string)($input['message'] ?? ''));
if ($message === '' || mb_strlen($message) > 240) {
    http_response_code(400);
    echo json_encode(['error' => $message === '' ? 'empty_message' : 'message_too_long']);
    exit;
}

$started = microtime(true);
$requestId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['aiRequestId'] ?? '')) ?: bin2hex(random_bytes(8));
$config = ai_config();
$currentScene = (string)($input['currentScene'] ?? '');
$view = (array)($input['viewContext'] ?? []);
$session = (array)($input['conversationState'] ?? []);
$history = (array)($input['history'] ?? []);
$tour = (array)($input['tourContext'] ?? []);

// Build sceneIds
$sceneIds = [];
foreach (($tour['scenes'] ?? []) as $s) { if (isset($s['id'])) $sceneIds[] = $s['id']; }

// Normalize session
if (!isset($session['lastSceneId'])) $session['lastSceneId'] = $currentScene;
if (!isset($session['visitorProfile'])) $session['visitorProfile'] = ['transport' => null, 'travelingWithChildren' => null, 'accessibilityNeeds' => [], 'servicePreference' => null, 'interests' => [], 'destinationPreference' => null];
if (!isset($session['history'])) $session['history'] = $history;

// Classify intent
$intent = ai_classify_intent($message, $session);
$standaloneQuery = ai_standalone_query($message, $session, $history);
$place = ai_place_context($currentScene, $tour);

// --- PROXIMITY QUERIES ---
$isProximityQuery = preg_match('/(sekitar|terdekat|dekat|nearby|around|di sini|sini)/i', $message)
    && preg_match('/(masjid|gereja|rumah sakit|apotek|klinik|hotel|restoran|cafe|bank|atm|spbu|parkir|terminal|stasiun)/i', $message);

// Deterministic intents
$deterministicIntents = ['CURRENT_LOCATION', 'NAVIGATION_REQUEST', 'FOLLOWUP_NAVIGATION', 'ROUTE_RECOMMENDATION'];
$pipeline = ai_v2_pipeline($message, $session, $tour, $config);

if (in_array($intent, $deterministicIntents, true)) {
    $result = $pipeline['result'];
    $result['provider'] = 'v2-state-engine';
    $result['aiRequestId'] = $requestId;
    $result['conversation_update'] = [
        'recommended_route_id' => $result['action'] === 'go_to_scene' ? null : ($session['recommendedRouteId'] ?? null),
        'referenced_scene_id' => $result['scene'] ?? null,
        'referenced_entity_id' => $result['entities'][0]['id'] ?? null,
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Curated answers
$curatedMatch = ai_curated_match($message, $session);
if ($curatedMatch) {
    $curatedResult = ai_curated_response($curatedMatch['id'], $message, $session, $tour);
    if ($curatedResult) {
        $curatedResult['aiRequestId'] = $requestId;
        $curatedResult['conversation_update'] = [
            'recommended_route_id' => null,
            'referenced_scene_id' => $curatedResult['scene'] ?? null,
            'referenced_entity_id' => $curatedResult['entities'][0]['id'] ?? null ?? null,
        ];
        echo json_encode($curatedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Public service
if ($intent === 'PUBLIC_SERVICE') {
    $publicResult = ai_resolve_public_service($message, $session);
    if ($publicResult) {
        $publicResult['aiRequestId'] = $requestId;
        $publicResult['conversation_update'] = [
            'recommended_route_id' => null,
            'referenced_scene_id' => null,
            'referenced_entity_id' => $publicResult['entities'][0]['id'] ?? null,
        ];
        echo json_encode($publicResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// --- INTERNAL KNOWLEDGE FIRST ---
$pipelineEvidence = $pipeline['result']['_retrieval'] ?? ['items' => [], 'mode' => 'place-aware'];
$groundingEvidence = array_map(static function (array $item): array { return $item['entry'] + ['relevance' => $item['score']]; }, $pipelineEvidence['items'] ?? []);
$facet = ai_classify_facet($message, $intent);

$exploreEntity = $pipeline['result']['entities'][0] ?? null;
$internalReply = null;

if (!$isProximityQuery) {
    if ($exploreEntity && ($exploreEntity['type'] ?? '') === 'explore-place' && !empty($exploreEntity['content'])) {
        $internalReply = $exploreEntity['content'];
    }
    if (!$internalReply && !empty($groundingEvidence)) {
        $topEvidence = $groundingEvidence[0];
        if (!empty($topEvidence['content']) && ($topEvidence['type'] ?? '') !== 'overview') {
            $internalReply = $topEvidence['content'];
        }
    }
    if (!$internalReply) {
        $enrichedDesc = (string)($place['enrichedDescription'] ?? '');
        if ($enrichedDesc !== '' && in_array($intent, ['PLACE_DESCRIPTION', 'CURRENT_LOCATION'], true)) {
            $internalReply = $enrichedDesc;
        }
    }
}

if ($internalReply) {
    $result = ai_compose_reply($intent, $internalReply, $place, $exploreEntity ? [$exploreEntity] : []);
    $result['provider'] = 'internal-knowledge';
    $result['aiRequestId'] = $requestId;
    $result['facet'] = $facet;
    $result['conversation_update'] = [
        'recommended_route_id' => null,
        'referenced_scene_id' => $result['scene'] ?? null,
        'referenced_entity_id' => $exploreEntity['id'] ?? null,
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- GEMINI FALLBACK ---
$guideCtx = [
    'intent' => $intent,
    'facet' => $facet,
    'placeName' => (string)($place['currentPlaceName'] ?? ''),
    'sceneId' => $currentScene,
    'subjectName' => (string)($pipeline['result']['entities'][0]['name'] ?? $session['lastEntity'] ?? ''),
    'navTarget' => (string)($pipeline['result']['scene'] ?? ''),
    'sceneIds' => $sceneIds,
    'routes' => (array)($tour['routes'] ?? []),
    'language' => strtolower((string)($view['language'] ?? $session['visitorProfile']['language'] ?? 'id')),
];
$system = ai_build_guide_system_prompt($guideCtx);

$userPromptParts = [
    'QUESTION' => $message,
    'INTENT' => $intent,
    'FACET' => $facet,
    'CURRENT_PLACE' => ['currentSceneId' => $currentScene, 'currentPlaceId' => $place['currentPlaceId'], 'currentPlaceName' => $place['currentPlaceName']],
    'VISITOR_STATE' => $session['visitorProfile'],
    'SESSION_CONTEXT' => ['lastIntent' => $session['lastIntent'] ?? null, 'lastEntity' => $session['lastEntity'] ?? null, 'lastReply' => $session['lastReply'] ?? null],
    'VERIFIED_FACTS' => $groundingEvidence,
];
$userPrompt = json_encode($userPromptParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$providerResult = ai_provider_router([
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => $userPrompt],
], [
    'provider' => getenv('AI_PROVIDER') ?: 'gemini',
    'model' => ai_provider_model(strtolower(trim(getenv('AI_PROVIDER') ?: 'gemini'))),
    'temperature' => $config['temperature'] ?? 0.3,
    'max_tokens' => $config['num_predict'] ?? 600,
    'json_mode' => true,
    'system' => $system,
    'user' => $userPrompt,
    'timeout' => (int)(getenv('AI_REQUEST_TIMEOUT') ?: 25),
]);

$content = (string)($providerResult['content'] ?? '');
$aiData = ai_parse_json($content);

if ($aiData) {
    $validated = ai_validate_provider_response($aiData, $tour, $groundingEvidence, $currentScene);
    if ($validated !== null) {
        $result = $validated;
        $result['intent'] = $intent;
        $result['provider'] = (string)($providerResult['provider'] ?? 'gemini');
        if (!isset($result['conversation_update'])) {
            $result['conversation_update'] = [
                'recommended_route_id' => $result['action'] === 'go_to_scene' ? null : ($session['recommendedRouteId'] ?? null),
                'referenced_scene_id' => $result['scene'] ?? null,
                'referenced_entity_id' => $result['entities'][0]['id'] ?? null,
            ];
        }
    } else {
        // Validation failed — try to extract reply from parsed data, or use raw text
        $fallbackReply = (string)($aiData['reply'] ?? $aiData['text'] ?? $aiData['answer'] ?? '');
        if ($fallbackReply !== '' && mb_strlen($fallbackReply) > 10) {
            $result = ai_compose_reply($intent, $fallbackReply, $place, $exploreEntity ? [$exploreEntity] : []);
            $result['provider'] = (string)($providerResult['provider'] ?? 'unknown') . '-raw';
        } else {
            $rawReply = trim($content);
            if ($rawReply !== '' && mb_strlen($rawReply) > 10) {
                $result = ai_compose_reply($intent, $rawReply, $place, $exploreEntity ? [$exploreEntity] : []);
                $result['provider'] = (string)($providerResult['provider'] ?? 'unknown') . '-raw';
            } else {
                $result = $pipeline['result'];
                $result['provider'] = 'v2-state-engine';
            }
        }
        $result['conversation_update'] = [
            'recommended_route_id' => $result['action'] === 'go_to_scene' ? null : ($session['recommendedRouteId'] ?? null),
            'referenced_scene_id' => $result['scene'] ?? null,
            'referenced_entity_id' => $result['entities'][0]['id'] ?? null,
        ];
    }
} else {
    // Not JSON — use raw text directly
    $rawReply = trim($content);
    if ($rawReply !== '' && mb_strlen($rawReply) > 10) {
        $result = ai_compose_reply($intent, $rawReply, $place, $exploreEntity ? [$exploreEntity] : []);
        $result['provider'] = (string)($providerResult['provider'] ?? 'unknown') . '-raw';
    } else {
        // Last resort: use pipeline result or generic fallback
        $pipelineReply = (string)($pipeline['result']['reply'] ?? '');
        if ($pipelineReply !== '') {
            $result = $pipeline['result'];
            $result['provider'] = 'v2-state-engine';
        } else {
            $result = ai_compose_reply($intent, 'Maaf, saya belum bisa menjawab pertanyaan tersebut. Coba tanyakan tentang sejarah, arsitektur, atau objek yang ada di Lawang Sewu.', $place, $exploreEntity ? [$exploreEntity] : []);
            $result['provider'] = 'fallback-template';
        }
    }
    $result['conversation_update'] = [
        'recommended_route_id' => $result['action'] === 'go_to_scene' ? null : ($session['recommendedRouteId'] ?? null),
        'referenced_scene_id' => $result['scene'] ?? null,
        'referenced_entity_id' => $result['entities'][0]['id'] ?? null,
    ];
}

$result['aiRequestId'] = $requestId;
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
