<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/ai-core.php';
require_once dirname(__DIR__) . '/api/ai-provider.php';

$key = getenv('GROQ_API_KEY') ?: '';
if ($key === '') { fwrite(STDERR, "FATAL: no GROQ_API_KEY\n"); exit(1); }

function callGroq(array $messages, array $extra = []): array {
    global $key;
    $payload = array_merge([
        'model' => 'llama-3.3-70b-versatile',
        'messages' => $messages,
        'max_tokens' => 2048,
    ], $extra);
    $result = ai_http_post_json('https://api.groq.com/openai/v1/chat/completions', $payload, ['Content-Type: application/json', 'Authorization: Bearer ' . $key], 60);
    if ($result['code'] >= 400) {
        echo "HTTP ERROR {$result['code']}: {$result['body']}\n";
        exit(1);
    }
    $decoded = json_decode((string)$result['body'], true);
    $msg = $decoded['choices'][0]['message'] ?? [];
    $finish = $decoded['choices'][0]['finish_reason'] ?? null;
    return ['message' => $msg, 'finish' => $finish, 'raw' => $decoded, 'http' => $result['code']];
}

// Test MCP tool type
$messages = [
    ['role' => 'system', 'content' => 'Anda adalah pemandu virtual wisata. Jawab dalam Bahasa Indonesia. Gunakan data pencarian web untuk pertanyaan tentang tempat di sekitar lokasi.'],
    ['role' => 'user', 'content' => 'Masjid terdekat dari Lawang Sewu Semarang, sebutkan nama dan jaraknya.'],
];

echo "=== Test MCP tool type ===\n";
$r1 = callGroq($messages, ['tools' => [['type' => 'mcp', 'mcp' => ['name' => 'web_search', 'command' => 'echo', 'args' => []]]]]);
echo "HTTP: {$r1['http']}\nFINISH: {$r1['finish']}\n";
echo "CONTENT: " . var_export($r1['message']['content'] ?? null, true) . "\n";
echo "TOOL_CALLS: " . json_encode($r1['message']['tool_calls'] ?? null) . "\n";
echo "RAW: " . json_encode($r1['raw'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
