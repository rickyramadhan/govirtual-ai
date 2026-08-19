<?php
/**
 * INSTRUCTIONS: Copy this file to debug-gemini.php and replace YOUR_GEMINI_API_KEY with your actual key.
 */
$apiKey = 'YOUR_GEMINI_API_KEY';
$model = 'gemini-3.5-flash-lite';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

echo "=== Gemini API CORS Test ===\n\n";

// Test 1: Direct POST (no Origin header - server-side)
echo "Test 1: Direct POST (no Origin)...\n";
$payload = json_encode([
    'contents' => [['parts' => [['text' => 'Hi, reply with one word.']]]],
    'generationConfig' => ['maxOutputTokens' => 10]
]);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "  HTTP: $code\n";

// Test 2: With Origin header (simulating browser)
echo "\nTest 2: With Origin header (simulating browser)...\n";
$ch2 = curl_init($url);
curl_setopt_array($ch2, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Origin: https://semarang.govirtual.id',
    ],
    CURLOPT_TIMEOUT => 10,
]);
$resp2 = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);
echo "  HTTP: $code2\n";

// Test 3: Check response headers for CORS
echo "\nTest 3: Check CORS headers...\n";
$ch3 = curl_init($url);
curl_setopt_array($ch3, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Origin: https://semarang.govirtual.id',
    ],
    CURLOPT_TIMEOUT => 10,
]);
$resp3 = curl_exec($ch3);
$headerSize = curl_getinfo($ch3, CURLINFO_HEADER_SIZE);
$headers = substr($resp3, 0, $headerSize);
$body = substr($resp3, $headerSize);
curl_close($ch3);

echo "  Headers:\n";
foreach (explode("\n", $headers) as $line) {
    if (stripos($line, 'access-control') !== false || stripos($line, 'origin') !== false) {
        echo "    $line\n";
    }
}
echo "  Body (first 200): " . substr($body, 0, 200) . "\n";

echo "\n=== Conclusion ===\n";
if ($code2 == 200) {
    echo "Gemini API accepts requests from any origin (CORS enabled).\n";
    echo "Client-side fetch from browser WILL work.\n";
} else {
    echo "Gemini API returned $code2. May need proxy.\n";
}
