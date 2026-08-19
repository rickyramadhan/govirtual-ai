<?php
declare(strict_types=1);

/**
 * Demo Test — 30 Golden Questions (simulates full API flow).
 *
 * Simulates the tour-chat.php endpoint logic:
 * 1. Intent classification
 * 2. Deterministic intents → V2 pipeline
 * 3. Curated answers → curated layer
 * 4. PUBLIC_SERVICE → ai_resolve_public_service()
 * 5. Informational → entity resolution + evidence (provider needed for reply)
 *
 * Usage: php tests/test-demo-30-questions.php [--verbose]
 */

const LS_ROOT = __DIR__ . '/..';
require_once LS_ROOT . '/api/ai-core.php';

$verbose = in_array('--verbose', $argv);

// Load tour data
$tour = json_decode((string)@file_get_contents(LS_ROOT . '/api/cache/tour-data.json'), true);
if (!$tour || !isset($tour['scenes'])) {
    echo "ERROR: Failed to load tour data. Run: node -e \"...\"\n";
    exit(1);
}

$session = [
    'lastSceneId' => 'node51',
    'history' => [],
    'visitorProfile' => ['transport' => null, 'travelingWithChildren' => null, 'accessibilityNeeds' => [], 'servicePreference' => null, 'interests' => [], 'destinationPreference' => null],
    'lastIntent' => null,
    'lastEntity' => null,
    'lastReply' => null,
];

/**
 * Simulate the full API flow from tour-chat.php
 */
function simulateApi(string $message, array $session, array $tour): array {
    $intent = ai_classify_intent($message, $session);
    
    // 1. Deterministic intents
    $deterministicIntents = ['CURRENT_LOCATION', 'UNKNOWN_FACT', 'NAVIGATION_REQUEST', 'FOLLOWUP_NAVIGATION', 'ROUTE_RECOMMENDATION'];
    if (in_array($intent, $deterministicIntents, true)) {
        $pipeline = ai_v2_pipeline($message, $session, $tour);
        return $pipeline['result'] + ['_source' => 'v2-deterministic', '_intent' => $intent];
    }
    
    // 2. Curated demo answers
    $curatedMatch = ai_curated_match($message, $session);
    if ($curatedMatch) {
        $curatedResult = ai_curated_response($curatedMatch['id'], $message, $session, $tour);
        if ($curatedResult) {
            return $curatedResult + ['_source' => 'curated', '_intent' => $intent];
        }
    }
    
    // 3. PUBLIC_SERVICE domain
    if ($intent === 'PUBLIC_SERVICE') {
        $publicResult = ai_resolve_public_service($message, $session);
        if ($publicResult) {
            return $publicResult + ['_source' => 'public-service', '_intent' => $intent];
        }
    }
    
    // 4. Informational intents (need provider for reply, but check entity resolution)
    $pipeline = ai_v2_pipeline($message, $session, $tour);
    $result = $pipeline['result'];
    $result['_source'] = 'v2-provider-needed';
    $result['_intent'] = $intent;
    
    // Check if entity was resolved
    $entity = $result['entities'][0] ?? null;
    $hasRetrieval = !empty($result['_retrieval']['items']);
    
    // If entity resolved, provide a deterministic fallback reply
    if ($entity && !empty($result['reply'])) {
        // Reply already set by V2 pipeline
    } elseif ($entity && $entity['type'] === 'explore-place' && !empty($entity['content'])) {
        $result['reply'] = $entity['content'];
    } elseif ($hasRetrieval) {
        // Use first retrieval item as fallback
        $items = $result['_retrieval']['items'];
        if (!empty($items[0]['entry']['content'])) {
            $result['reply'] = $items[0]['entry']['content'];
        }
    }
    
    return $result;
}

$questions = [
    ['q' => 'Saya sekarang berada di mana?', 'expect_any' => ['Area Utama', 'Anda sekarang']],
    ['q' => 'Ceritakan area yang sedang saya lihat ini.', 'expect_any' => ['Area Utama', 'Lawang Sewu', 'titik awal']],
    ['q' => 'Apa yang menarik dari Lawang Sewu?', 'expect_any' => ['arsitektur', 'sejarah', 'menarik', 'Lawang Sewu', 'bangunan']],
    ['q' => 'Kenapa tempat ini disebut Lawang Sewu?', 'expect_any' => ['pintu', 'seribu', 'Lawang Sewu', 'nama']],
    ['q' => 'Apa hubungan Lawang Sewu dengan sejarah kereta api di Indonesia?', 'expect_any' => ['kereta', 'NIS', 'perkeretaapian', 'sejarah']],
    ['q' => 'Apa itu Galeri Warisan?', 'expect_any' => ['Galeri Warisan', 'pelestarian', 'warisan', 'arsitektur']],
    ['q' => 'Apa saja yang bisa saya pelajari di Galeri Warisan?', 'expect_any' => ['Galeri Warisan', 'material', 'cetak biru', 'artefak']],
    ['q' => 'Bawa saya ke Galeri Warisan.', 'expect_any' => ['Galeri Warisan', 'mengarahkan', 'ke sana', 'go_to_scene']],
    ['q' => 'Sekarang saya berada di mana?', 'expect_any' => ['Area Utama', 'Anda sekarang']],
    ['q' => 'Apa itu Cetak Biru Lawang Sewu?', 'expect_any' => ['Cetak Biru', 'gambar teknis', 'desain', 'kolonial']],
    ['q' => 'Kenapa cetak biru ini penting untuk memahami Lawang Sewu?', 'expect_any' => ['Cetak Biru', 'arsitektur', 'desain', 'penting']],
    ['q' => 'Ceritakan tentang Ruang Pameran Sejarah.', 'expect_any' => ['Ruang Pameran', 'sejarah', 'perkeretaapian', 'kronologi']],
    ['q' => 'Apa yang bisa saya pelajari tentang sejarah perkeretaapian di sini?', 'expect_any' => ['perkeretaapian', 'kereta', 'sejarah', 'NIS']],
    ['q' => 'Ada lokomotif yang bisa saya lihat di virtual tour ini?', 'expect_any' => ['lokomo', 'kereta', 'DD52', 'C23', 'ESS']],
    ['q' => 'Bawa saya ke area lokomotif.', 'expect_any' => ['lokomo', 'kereta', 'mengarahkan', 'go_to_scene']],
    ['q' => 'Setelah dari sini, sebaiknya saya mengunjungi area mana?', 'expect_any' => ['melanjutkan', 'rekomendasi', 'menarik', 'Aula', 'Gedung']],
    ['q' => 'Oke, bawa saya ke sana.', 'expect_any' => [''], 'allow_empty' => true],
    ['q' => 'Apa itu Tugu Marmer dan apa yang menarik darinya?', 'expect_any' => ['Tugu Marmer', 'marmer', 'monumen', 'tugu']],
    ['q' => 'Apa ciri arsitektur kolonial yang bisa ditemukan di Lawang Sewu?', 'expect_any' => ['arsitektur', 'kolonial', 'bangunan', 'kaca patri']],
    ['q' => 'Kalau saya baru pertama kali berkunjung ke Lawang Sewu, bagian mana saja yang paling menarik untuk dijelajahi?', 'expect_any' => ['menarik', 'Gedung', 'galeri', 'Area', 'kunjungi']],
    ['q' => 'Kalau saya membutuhkan rumah sakit atau layanan kesehatan terdekat, ada rekomendasi?', 'expect_any' => ['rumah sakit', '119', 'kesehatan', 'RS', 'rs']],
    ['q' => 'Ada rumah sakit apa saja di sekitar Lawang Sewu atau pusat Kota Semarang?', 'expect_any' => ['rumah sakit', 'RS', 'rs', 'kesehatan']],
    ['q' => 'Kalau terjadi keadaan darurat, layanan apa yang bisa saya hubungi?', 'expect_any' => ['110', '112', '119', 'darurat', 'polisi']],
    ['q' => 'Di mana kantor polisi yang bisa saya datangi dari kawasan Lawang Sewu?', 'expect_any' => ['polisi', '110', 'kantor']],
    ['q' => 'Apakah ada kantor pemerintahan atau layanan publik di sekitar sini?', 'expect_any' => ['pemerintahan', 'layanan', 'publik', 'kota']],
    ['q' => 'Saya ingin mencari transportasi umum setelah dari Lawang Sewu, ada informasi yang bisa membantu?', 'expect_any' => ['transportasi', 'stasiun', 'bus', 'angkutan', 'kereta']],
    ['q' => 'Di mana stasiun kereta api yang bisa saya kunjungi dari Lawang Sewu?', 'expect_any' => ['stasiun', 'kereta', 'Tawang']],
    ['q' => 'Kalau saya membutuhkan informasi wisata Semarang, layanan apa yang tersedia?', 'expect_any' => ['wisata', 'semarang', 'tur', 'informasi']],
    ['q' => 'Saya kehilangan barang saat berwisata, layanan mana yang sebaiknya saya hubungi?', 'expect_any' => ['polisi', '110', 'kehilangan', 'lapor']],
    ['q' => 'Apa saja layanan publik yang tersedia untuk wisatawan di Semarang?', 'expect_any' => ['layanan', 'publik', 'wisatawan', 'Semarang']],
];

echo "=== Demo 30 Questions (Full API Simulation) ===\n";
echo "Scene: node51 (Area Utama Lawang Sewu) | Tour: " . count($tour['scenes']) . " scenes\n\n";

$passed = 0;
$failed = 0;
$allReplies = [];
$issues = [];

foreach ($questions as $idx => $item) {
    $q = $item['q'];
    $expectAny = $item['expect_any'] ?? [];
    $allowEmpty = $item['allow_empty'] ?? false;
    
    $result = simulateApi($q, $session, $tour);
    $reply = trim($result['reply'] ?? '');
    $intent = $result['_intent'] ?? $result['intent'] ?? 'UNKNOWN';
    $source = $result['_source'] ?? 'unknown';
    $entities = $result['entities'] ?? [];
    $entityName = $entities[0]['name'] ?? '';
    $entityType = $entities[0]['type'] ?? '';
    
    $num = str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT);
    $status = 'PASS';
    $issueList = [];
    
    // Check 1: Non-empty reply (unless allowed)
    if (!$allowEmpty && $reply === '') {
        $status = 'FAIL';
        $issueList[] = 'EMPTY REPLY';
    }
    
    // Check 2: Content relevance
    if (!$allowEmpty && $reply !== '' && !empty($expectAny)) {
        $found = false;
        foreach ($expectAny as $exp) {
            if ($exp === '' || stripos($reply, $exp) !== false) { $found = true; break; }
        }
        if (!$found) {
            $status = 'FAIL';
            $issueList[] = 'NOT RELEVANT: expected one of [' . implode(', ', $expectAny) . ']';
        }
    }
    
    // Check 3: No leaked instructions
    $leakPatterns = ['pemandu AI sebaiknya', 'AI sebaiknya', 'gunakan highlights', 'Jangan mengarang', 'VERIFIED_FACTS', 'knowledge.json', 'system prompt'];
    foreach ($leakPatterns as $pattern) {
        if (stripos($reply, $pattern) !== false) {
            $status = 'FAIL';
            $issueList[] = "LEAKED: '$pattern'";
        }
    }
    
    // Check 4: No raw node IDs
    if (preg_match('/\bnode\d+\b/i', $reply)) {
        $status = 'FAIL';
        $issueList[] = 'RAW NODE ID';
    }
    
    // Check 5: No duplicate replies across different question types
    // Allow duplicates for same entity (e.g., two questions about same place = same answer)
    $replyHash = md5(strtolower($reply));
    if ($reply !== '' && isset($allReplies[$replyHash])) {
        $prev = $allReplies[$replyHash];
        // Allow same reply if same entity or same intent type
        $sameEntity = ($entityName !== '' && $entityName === ($prev['entity'] ?? ''));
        $sameIntent = ($prev['intent'] === $intent);
        if (!$sameEntity && !$sameIntent) {
            $status = 'FAIL';
            $issueList[] = "DUPLICATE reply with Q" . ($prev['idx'] + 1) . " (intent: {$prev['intent']}, entity: {$prev['entity']})";
        }
    }
    if ($reply !== '') $allReplies[$replyHash] = ['idx' => $idx, 'intent' => $intent, 'entity' => $entityName];
    
    if ($status === 'PASS') {
        $passed++;
        echo "  $num PASS: " . substr($q, 0, 60) . (strlen($q) > 60 ? '...' : '') . "\n";
        if ($verbose) {
            echo "      Source: $source | Intent: $intent\n";
            if ($entityName) echo "      Entity: $entityName ($entityType)\n";
            if ($reply) echo "      Reply: " . substr($reply, 0, 140) . (strlen($reply) > 140 ? '...' : '') . "\n";
            echo "\n";
        }
    } else {
        $failed++;
        $issues[] = ['num' => $num, 'q' => $q, 'issues' => $issueList, 'reply' => $reply, 'intent' => $intent, 'source' => $source, 'entity' => $entityName];
        echo "  $num FAIL: " . substr($q, 0, 60) . (strlen($q) > 60 ? '...' : '') . "\n";
        echo "      Issues: " . implode(', ', $issueList) . "\n";
        echo "      Source: $source | Intent: $intent | Entity: $entityName\n";
        if ($reply) echo "      Reply: " . substr($reply, 0, 250) . "\n";
        echo "\n";
    }
}

echo "\n=== Summary ===\n";
echo "Passed: $passed / " . count($questions) . "\n";
echo "Failed: $failed / " . count($questions) . "\n";

if ($failed > 0) {
    echo "\n=== Failed Questions ===\n";
    foreach ($issues as $issue) {
        echo "Q{$issue['num']}: {$issue['q']}\n";
        echo "  Source: {$issue['source']} | Intent: {$issue['intent']} | Entity: {$issue['entity']}\n";
        foreach ($issue['issues'] as $i) echo "  - $i\n";
        if ($issue['reply']) echo "  Reply: " . substr($issue['reply'], 0, 300) . "\n";
        echo "\n";
    }
}

echo "\nStatus: " . ($failed === 0 ? 'ALL 30 PASS' : 'NEEDS FIXES') . "\n";
exit($failed === 0 ? 0 : 1);
