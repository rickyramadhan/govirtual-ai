<?php
declare(strict_types=1);
/**
 * Live provider test — tests Groq/Gemini with real API calls.
 * Run: php tests/test-live-provider.php
 * Requires: valid GROQ_API_KEY in .env
 */
require_once __DIR__ . '/../api/ai-core.php';
require_once __DIR__ . '/../api/ai-provider.php';
require_once __DIR__ . '/../api/guide-policy.php';

$config = ai_config();

$tests = [
    'Ceritakan Lawang Sewu.',
    'Apa itu arsitektur kolonial?',
    'Kenapa tempat ini disebut Lawang Sewu?',
];

$pass = 0; $fail = 0;
foreach ($tests as $q) {
    echo "Q: {$q}\n";
    $system = ai_build_guide_system_prompt([
        'intent' => 'GENERAL_CONVERSATION',
        'placeName' => 'Area Utama',
        'sceneId' => 'node51',
        'language' => 'id',
    ]);
    $userPrompt = json_encode(['QUESTION' => $q, 'INTENT' => 'GENERAL_KNOWLEDGE', 'VERIFIED_FACTS' => []]);
    $result = ai_provider_router([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userPrompt],
    ], [
        'provider' => getenv('AI_PROVIDER') ?: 'groq',
        'model' => ai_provider_model(strtolower(trim(getenv('AI_PROVIDER') ?: 'groq'))),
        'temperature' => 0.2,
        'max_tokens' => 400,
        'json_mode' => true,
        'system' => $system,
        'user' => $userPrompt,
        'timeout' => 20,
    ]);

    $provider = $result['provider'] ?? 'unknown';
    $fallback = $result['fallback_used'] ?? false;
    $content = $result['content'] ?? '';
    $success = $result['success'] ?? false;
    $error = $result['error'] ?? null;
    $data = ai_parse_json($content);
    $reply = $data['reply'] ?? $content;

    echo "  provider={$provider} fallback=" . ($fallback ? 'yes' : 'no') . " success=" . ($success ? 'yes' : 'no') . "\n";
    if ($error) echo "  error={$error}\n";
    if ($reply) echo "  reply=" . mb_substr($reply, 0, 200) . "\n";

    if ($success && mb_strlen($reply) > 20) { $pass++; echo "  OK\n"; }
    else { $fail++; echo "  FAIL\n"; }
    echo "\n";
}

echo "{$pass}/{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
