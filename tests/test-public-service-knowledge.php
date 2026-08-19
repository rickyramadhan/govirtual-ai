<?php
declare(strict_types=1);

/**
 * Public Service Knowledge Test
 *
 * Tests the public service catalog integration.
 */

require_once __DIR__ . '/../api/ai-core.php';

echo "=== PUBLIC SERVICE KNOWLEDGE TEST ===\n\n";

$services = ai_load_public_services();
echo "Services loaded: " . count($services) . "\n\n";

$tests = [
    // Test 1: Discovery
    [
        'query' => 'Ada layanan publik apa saja?',
        'expectedIntent' => 'PUBLIC_SERVICE',
        'shouldFind' => true,
    ],
    // Test 2: Health service
    [
        'query' => 'Ada layanan kesehatan?',
        'expectedIntent' => 'PUBLIC_SERVICE',
        'shouldFind' => true,
    ],
    // Test 3: Fire department
    [
        'query' => 'Nomor pemadam kebakaran berapa?',
        'expectedIntent' => 'PUBLIC_SERVICE',
        'shouldFind' => true,
    ],
    // Test 4: Police
    [
        'query' => 'Saya butuh bantuan polisi.',
        'expectedIntent' => 'PUBLIC_SERVICE',
        'shouldFind' => true,
    ],
    // Test 5: Emergency
    [
        'query' => 'Ada nomor darurat?',
        'expectedIntent' => 'PUBLIC_SERVICE',
        'shouldFind' => true,
    ],
    // Test 6: Exact known service
    [
        'query' => 'Call Center 112',
        'expectedIntent' => 'PUBLIC_SERVICE',
        'shouldFind' => true,
    ],
    // Test 7: Unknown service (should not find)
    [
        'query' => 'Saya butuh tukang ledeng',
        'expectedIntent' => 'GENERAL_CONVERSATION',
        'shouldFind' => false,
    ],
    // Test 8: WhatsApp service
    [
        'query' => 'Nomor WhatsApp Lapor Semar berapa?',
        'expectedIntent' => 'PUBLIC_SERVICE',
        'shouldFind' => true,
    ],
    // Test 9: Not a public service query
    [
        'query' => 'Ceritakan Gedung D',
        'expectedIntent' => 'ENTITY_DESCRIPTION',
        'shouldFind' => false,
    ],
    // Test 10: PBB tax
    [
        'query' => 'Bayar PBB dimana?',
        'expectedIntent' => 'PUBLIC_SERVICE',
        'shouldFind' => true,
    ],
];

$pass = 0;
$fail = 0;

foreach ($tests as $idx => $test) {
    $num = $idx + 1;
    $query = $test['query'];
    $intent = ai_classify_intent($query);
    $expectedIntent = $test['expectedIntent'];

    echo "Test {$num}: \"{$query}\"\n";
    echo "  Classified intent: {$intent}\n";

    if ($intent === $expectedIntent) {
        echo "  Intent: PASS\n";
    } else {
        echo "  Intent: FAIL (expected {$expectedIntent})\n";
    }

    if ($intent === 'PUBLIC_SERVICE') {
        $results = ai_search_public_services($query);
        echo "  Search results: " . count($results) . "\n";

        if ($test['shouldFind'] && count($results) > 0) {
            $best = $results[0]['service'];
            echo "  Best match: {$best['name']} (score: {$results[0]['score']})\n";
            echo "  Lookup: PASS\n";
            $pass++;
        } elseif ($test['shouldFind'] && count($results) === 0) {
            echo "  Lookup: FAIL (expected results)\n";
            $fail++;
        } elseif (!$test['shouldFind'] && count($results) === 0) {
            echo "  Lookup: PASS (no results as expected)\n";
            $pass++;
        } else {
            echo "  Lookup: FAIL (unexpected results)\n";
            $fail++;
        }
    } else {
        if (!$test['shouldFind']) {
            echo "  Skip lookup: PASS\n";
            $pass++;
        } else {
            echo "  Skip lookup: FAIL\n";
            $fail++;
        }
    }
    echo "\n";
}

// Test stateful follow-up
echo "=== STATEFUL FOLLOW-UP TEST ===\n\n";

$session = [];
// First, get some results to store in session
$searchResult = ai_search_public_services('polisi');
$services = array_column($searchResult, 'service');
$session['lastPublicServiceResults'] = $services;

echo "Session has " . count($services) . " services stored\n";

if (count($services) > 0) {
    // Test follow-up: "yang pertama"
    $followupResult = ai_resolve_public_service('yang pertama', $session);
    if ($followupResult) {
        echo "Follow-up 'yang pertama': PASS\n";
        echo "  Response: " . substr($followupResult['reply'], 0, 100) . "...\n";
        $pass++;
    } else {
        echo "Follow-up 'yang pertama': FAIL\n";
        $fail++;
    }

    // Test follow-up: "nomornya berapa?"
    $phoneResult = ai_resolve_public_service('nomornya berapa?', $session);
    if ($phoneResult) {
        echo "Follow-up 'nomornya berapa?': PASS\n";
        echo "  Response: " . substr($phoneResult['reply'], 0, 100) . "...\n";
        $pass++;
    } else {
        echo "Follow-up 'nomornya berapa?': FAIL\n";
        $fail++;
    }
} else {
    echo "No services found for 'polisi' - skipping follow-up tests\n";
    $fail += 2;
}

// Test follow-up without state
$emptySession = [];
$emptyResult = ai_resolve_public_service('yang pertama', $emptySession);
if ($emptyResult === null) {
    echo "Follow-up without state: PASS (returns null)\n";
    $pass++;
} else {
    echo "Follow-up without state: FAIL\n";
    $fail++;
}

echo "\n=== SUMMARY ===\n";
echo "PASS: {$pass}\n";
echo "FAIL: {$fail}\n";
echo "Total: " . ($pass + $fail) . "\n\n";

$acceptance = $fail === 0 ? 'PASS' : 'NOT READY';
echo "PUBLIC SERVICE KNOWLEDGE: {$acceptance}\n";
